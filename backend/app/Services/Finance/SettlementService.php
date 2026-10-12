<?php

namespace App\Services\Finance;

use App\Enums\DifferenceType;
use App\Enums\PayoutStatus;
use App\Enums\SettlementStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantPayout;
use App\Models\RestaurantSettlement;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * What a person may do to a calculated settlement (Module 36): approve it, or withdraw it. Nothing here sets a total,
 * a line or "paid": the totals are the sums of the lines, the lines are immutable, and a settlement becomes PAID only
 * when its payout is confirmed (PayoutService).
 *
 * APPROVAL re-checks everything against the ledger first — a stale screen or a cached number never authorizes money:
 *   - the lines still add up to the statement's totals;
 *   - every line still belongs to this settlement and has the amount it had;
 *   - the location's payable lines, minus what was paid out, equal the ledger's payable balance.
 * When a check fails the settlement becomes REVIEW_REQUIRED and nobody can approve it.
 *
 * An approved settlement is never edited. A correction is: withdraw it (its lines are free again) and generate a new
 * one — or post an adjustment that the next settlement includes.
 */
final class SettlementService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly HoldService $holds,
        private readonly DifferenceService $differences,
        private readonly FinanceMetrics $metrics,
        private readonly AuditRecorder $audit,
    ) {}

    public function approve(RestaurantSettlement $settlement, AdminUser $admin, ?string $note = null, ?int $expectedVersion = null): RestaurantSettlement
    {
        $problem = null;
        $approved = DB::transaction(function () use ($settlement, $admin, $note, $expectedVersion, &$problem): RestaurantSettlement {
            $locked = RestaurantSettlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === SettlementStatus::Approved) {
                return $locked;   // approved twice is approved once
            }
            if ($locked->status !== SettlementStatus::Calculated) {
                throw ApiException::conflict('settlement_not_approvable', 'Only a calculated settlement can be approved (this one is '.$locked->status->value.').');
            }
            if ($expectedVersion !== null && $expectedVersion !== (int) $locked->version) {
                throw ApiException::conflict('version_conflict', 'This settlement was changed by somebody else. Read it again.');
            }
            if ((bool) config('finance.settlements.four_eyes') && $locked->generated_by_admin_id !== null && (int) $locked->generated_by_admin_id === (int) $admin->getKey()) {
                throw new ApiException(403, 'four_eyes', 'Who generated a settlement cannot approve it.');
            }
            if ($this->holds->locationHeld((int) $locked->location_id)) {
                throw ApiException::conflict('location_held', 'This restaurant has an active financial hold. Release it before approving.');
            }
            if (($problem = $this->verify($locked)) !== null) {
                $locked->forceFill(['status' => SettlementStatus::ReviewRequired, 'review_reason' => $problem, 'version' => (int) $locked->version + 1])->save();
                $this->audit->record('finance.settlement_review_required', $locked, $admin, ['status' => ['from' => SettlementStatus::Calculated->value, 'to' => SettlementStatus::ReviewRequired->value], 'review_reason' => ['from' => null, 'to' => $problem]], null, $locked->market_id);

                return $locked;
            }
            $locked->forceFill(['status' => SettlementStatus::Approved, 'approved_by_admin_id' => $admin->getKey(), 'approved_at' => now(), 'approval_note' => $note === null ? null : mb_substr($note, 0, 500), 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('finance.settlement_approved', $locked, $admin, ['status' => ['from' => SettlementStatus::Calculated->value, 'to' => SettlementStatus::Approved->value], 'net_payable_minor' => ['from' => null, 'to' => (int) $locked->net_payable_minor]], $note, $locked->market_id);
            $this->metrics->record('settlement_approved');

            return $locked;
        });
        if ($problem !== null) {
            $this->differences->report(DifferenceType::PayableMismatch, 'settlement-check:'.$approved->public_id, [
                'subject_type' => 'restaurant_settlement', 'subject_id' => (int) $approved->getKey(), 'subject_public_id' => (string) $approved->public_id, 'subject_reference' => (string) $approved->reference,
                'market_id' => $approved->market_id, 'location_id' => (int) $approved->location_id, 'currency' => (string) $approved->currency, 'detail' => ['check' => $problem],
            ]);
            throw ApiException::conflict('settlement_review_required', 'This settlement no longer matches the ledger ('.$problem.'). It needs a finance review and cannot be approved.');
        }

        return $approved;
    }

    /**
     * Withdraws a settlement that was not paid and has no payout under way: its lines can be settled again.
     */
    public function cancel(RestaurantSettlement $settlement, AdminUser $admin, string $reason): RestaurantSettlement
    {
        return DB::transaction(function () use ($settlement, $admin, $reason): RestaurantSettlement {
            $locked = RestaurantSettlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === SettlementStatus::Cancelled) {
                return $locked;
            }
            if (! in_array($locked->status, SettlementStatus::cancellable(), true)) {
                throw ApiException::conflict('settlement_not_cancellable', 'A settlement that is '.$locked->status->value.' cannot be withdrawn.');
            }
            if ($this->alivePayout($locked) !== null) {
                throw ApiException::conflict('payout_in_progress', 'This settlement has a payout that is not over.');
            }
            $from = $locked->status->value;
            RestaurantPayableItem::query()->where('settlement_id', $locked->getKey())->update(['status' => RestaurantPayableItem::STATUS_AVAILABLE, 'settlement_id' => null, 'settled_at' => null, 'updated_at' => now()]);
            $locked->forceFill(['status' => SettlementStatus::Cancelled, 'cancelled_by_admin_id' => $admin->getKey(), 'cancelled_at' => now(), 'cancelled_reason' => mb_substr($reason, 0, 500), 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('finance.settlement_cancelled', $locked, $admin, ['status' => ['from' => $from, 'to' => SettlementStatus::Cancelled->value]], $reason, $locked->market_id);
            $this->metrics->record('settlement_cancelled');

            return $locked;
        });
    }

    public function alivePayout(RestaurantSettlement $settlement): ?RestaurantPayout
    {
        return RestaurantPayout::query()->where('settlement_id', $settlement->getKey())->whereNotIn('status', [PayoutStatus::Failed->value, PayoutStatus::Cancelled->value])->first();
    }

    /**
     * Null when the settlement agrees with its lines and with the ledger; otherwise the name of the failed check.
     */
    public function verify(RestaurantSettlement $settlement): ?string
    {
        $lines = $settlement->lines()->get();
        $totals = SettlementCalculationService::totals($lines);
        foreach ($totals as $name => $value) {
            if ((int) $settlement->getAttribute($name) !== $value) {
                return 'LINES_DO_NOT_ADD_UP:'.strtoupper($name);
            }
        }
        if ($lines->count() !== (int) $settlement->line_count) {
            return 'LINE_COUNT';
        }
        $items = RestaurantPayableItem::query()->whereKey($lines->pluck('payable_item_id')->all())->get()->keyBy('id');
        foreach ($lines as $line) {
            $item = $items[$line->payable_item_id] ?? null;
            if ($item === null || (int) $item->settlement_id !== (int) $settlement->getKey() || (int) $item->amount_minor !== (int) $line->amount_minor || $item->currency !== $settlement->currency) {
                return 'LINE_NOT_BACKED_BY_ITS_PAYABLE';
            }
        }
        if (RestaurantPayableItem::query()->where('settlement_id', $settlement->getKey())->count() !== $lines->count()) {
            return 'PAYABLES_WITHOUT_LINE';
        }

        return $this->payableAgrees((int) $settlement->location_id, (string) $settlement->currency) ? null : 'PAYABLE_DIFFERS_FROM_LEDGER';
    }

    /**
     * The sub-ledger against the ledger: the location's payable lines, minus the payouts that were paid, are the
     * ledger's balance of its payable account.
     */
    public function payableAgrees(int $locationId, string $currency): bool
    {
        [$expected, $actual] = $this->payableFigures($locationId, $currency);

        return $expected === $actual;
    }

    /**
     * @return array{0: int, 1: int} what the payable lines and paid payouts say, and what the ledger says
     */
    public function payableFigures(int $locationId, string $currency): array
    {
        $lines = (int) RestaurantPayableItem::query()->where('location_id', $locationId)->where('currency', $currency)->sum('amount_minor');
        $paid = (int) RestaurantPayout::query()->where('location_id', $locationId)->where('currency', $currency)->where('status', PayoutStatus::Paid->value)->sum('amount_minor');
        $account = $this->ledger->payableOf($locationId, $currency);

        return [$lines - $paid, $account === null ? 0 : $this->ledger->balance($account)];
    }
}
