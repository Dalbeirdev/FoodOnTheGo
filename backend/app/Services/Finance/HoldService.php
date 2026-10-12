<?php

namespace App\Services\Finance;

use App\Enums\DisputeStatus;
use App\Enums\HoldReason;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\FinancialHold;
use App\Models\PaymentDispute;
use App\Models\Refund;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayableItem;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Financial holds (Module 36): a restaurant's money explicitly kept out of settlements and payouts — one payable line,
 * or everything of a location. A hold changes nothing in the ledger and takes nothing away: what is held is still the
 * restaurant's, and is settled when the hold is released.
 *
 * The system places two kinds, both switchable policy (config/finance.php, PENDING BUSINESS APPROVAL): an unsettled
 * earning whose order has an open refund, and one whose payment has an open dispute. They are released by the system
 * when the refund or the dispute is over. Nothing is held for a number of days. A person places and releases the
 * others, each time with a reason, each time audited.
 */
final class HoldService
{
    public function __construct(private readonly FinanceMetrics $metrics, private readonly AuditRecorder $audit) {}

    /**
     * An open refund holds the order's earning — when that earning exists and is not settled yet.
     */
    public function placeForRefund(Refund $refund): ?FinancialHold
    {
        if (! (bool) config('finance.holds.refund_pending') || $refund->order_id === null || in_array($refund->status, [RefundStatus::Refunded, RefundStatus::Cancelled], true)) {
            return null;
        }

        return $this->placeOnEarning((int) $refund->order_id, HoldReason::RefundPending, 'refund:'.$refund->public_id);
    }

    public function placeForDispute(PaymentDispute $dispute): ?FinancialHold
    {
        if (! (bool) config('finance.holds.dispute') || $dispute->order_id === null || ! $dispute->status->isOpen()) {
            return null;
        }

        return $this->placeOnEarning((int) $dispute->order_id, HoldReason::Dispute, 'dispute:'.$dispute->public_id);
    }

    /**
     * Releases what the system held for a refund or a dispute that is over. Returns how many holds were released.
     */
    public function releaseSystem(string $sourceKey, string $why): int
    {
        $released = 0;
        foreach (FinancialHold::query()->where('source', FinancialHold::SOURCE_SYSTEM)->where('status', FinancialHold::STATUS_ACTIVE)->where('source_key', $sourceKey)->get() as $hold) {
            $hold->forceFill(['status' => FinancialHold::STATUS_RELEASED, 'released_at' => now(), 'release_reason' => $why, 'version' => (int) $hold->version + 1])->save();
            $this->audit->record('finance.hold_released', $hold, null, ['status' => ['from' => FinancialHold::STATUS_ACTIVE, 'to' => FinancialHold::STATUS_RELEASED]], $why, $hold->market_id);
            $this->metrics->record('hold_released');
            $released++;
        }

        return $released;
    }

    /**
     * System holds whose refund or dispute ended without anybody telling: released. The sweep's part.
     */
    public function sync(): int
    {
        $released = 0;
        $active = FinancialHold::query()->where('source', FinancialHold::SOURCE_SYSTEM)->where('status', FinancialHold::STATUS_ACTIVE)->whereNotNull('source_key')->limit(500)->pluck('source_key')->unique();
        foreach ($active as $key) {
            [$kind, $id] = array_pad(explode(':', (string) $key, 2), 2, '');
            $over = match ($kind) {
                'refund' => ! Refund::query()->where('public_id', $id)->whereNotIn('status', [RefundStatus::Refunded->value, RefundStatus::Cancelled->value])->exists(),
                'dispute' => ! PaymentDispute::query()->where('public_id', $id)->whereIn('status', DisputeStatus::openValues())->exists(),
                default => false,
            };
            if ($over) {
                $released += $this->releaseSystem((string) $key, $kind === 'refund' ? 'The refund is over.' : 'The dispute is over.');
            }
        }

        return $released;
    }

    /**
     * A person holds a location's money, or one line of it.
     */
    public function place(RestaurantLocation $location, ?RestaurantPayableItem $item, HoldReason $reason, string $note, AdminUser $admin): FinancialHold
    {
        return DB::transaction(function () use ($location, $item, $reason, $note, $admin): FinancialHold {
            if ($item !== null && ((int) $item->location_id !== (int) $location->getKey() || $item->status !== RestaurantPayableItem::STATUS_AVAILABLE)) {
                throw ApiException::conflict('hold_not_possible', 'Only a line that is not settled yet can be held.');
            }
            $duplicate = FinancialHold::query()->where('status', FinancialHold::STATUS_ACTIVE)->where('source', FinancialHold::SOURCE_ADMIN)->where('location_id', $location->getKey())
                ->where('reason', $reason->value)->when($item === null, fn ($q) => $q->where('scope', FinancialHold::SCOPE_LOCATION), fn ($q) => $q->where('payable_item_id', $item->getKey()))->exists();
            if ($duplicate) {
                throw ApiException::conflict('hold_exists', 'The same hold is already active.');
            }
            $hold = new FinancialHold;
            $hold->forceFill([
                'scope' => $item === null ? FinancialHold::SCOPE_LOCATION : FinancialHold::SCOPE_ITEM,
                'organization_id' => $location->organization_id,
                'location_id' => $location->getKey(),
                'market_id' => $location->market_id,
                'payable_item_id' => $item?->getKey(),
                'reason' => $reason,
                'status' => FinancialHold::STATUS_ACTIVE,
                'source' => FinancialHold::SOURCE_ADMIN,
                'note' => mb_substr($note, 0, 500),
                'placed_by_admin_id' => $admin->getKey(),
                'placed_at' => now(),
            ])->save();
            $this->audit->record('finance.hold_placed', $hold, $admin, ['status' => ['from' => null, 'to' => FinancialHold::STATUS_ACTIVE], 'scope' => ['from' => null, 'to' => $hold->scope], 'reason' => ['from' => null, 'to' => $reason->value]], $note, $location->market_id);
            $this->metrics->record('hold_placed');

            return $hold;
        });
    }

    public function release(FinancialHold $hold, AdminUser $admin, string $reason): FinancialHold
    {
        return DB::transaction(function () use ($hold, $admin, $reason): FinancialHold {
            $locked = FinancialHold::query()->whereKey($hold->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== FinancialHold::STATUS_ACTIVE) {
                return $locked;
            }
            $locked->forceFill(['status' => FinancialHold::STATUS_RELEASED, 'released_at' => now(), 'released_by_admin_id' => $admin->getKey(), 'release_reason' => mb_substr($reason, 0, 500), 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('finance.hold_released', $locked, $admin, ['status' => ['from' => FinancialHold::STATUS_ACTIVE, 'to' => FinancialHold::STATUS_RELEASED]], $reason, $locked->market_id);
            $this->metrics->record('hold_released');

            return $locked;
        });
    }

    public function locationHeld(int $locationId): bool
    {
        return FinancialHold::query()->where('status', FinancialHold::STATUS_ACTIVE)->where('scope', FinancialHold::SCOPE_LOCATION)->where('location_id', $locationId)->exists();
    }

    private function placeOnEarning(int $orderId, HoldReason $reason, string $sourceKey): ?FinancialHold
    {
        $item = RestaurantPayableItem::query()->where('order_id', $orderId)->where('type', RestaurantPayableItem::TYPE_ORDER_EARNING)->where('status', RestaurantPayableItem::STATUS_AVAILABLE)->first();
        if ($item === null) {
            return null;
        }
        $existing = FinancialHold::query()->where('source', FinancialHold::SOURCE_SYSTEM)->where('status', FinancialHold::STATUS_ACTIVE)->where('source_key', $sourceKey)->where('payable_item_id', $item->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($item, $reason, $sourceKey): FinancialHold {
                $hold = new FinancialHold;
                $hold->forceFill([
                    'scope' => FinancialHold::SCOPE_ITEM,
                    'organization_id' => $item->organization_id,
                    'location_id' => $item->location_id,
                    'market_id' => $item->market_id,
                    'payable_item_id' => $item->getKey(),
                    'reason' => $reason,
                    'status' => FinancialHold::STATUS_ACTIVE,
                    'source' => FinancialHold::SOURCE_SYSTEM,
                    'source_key' => $sourceKey,
                    'placed_at' => now(),
                ])->save();
                $this->audit->record('finance.hold_placed', $hold, null, ['status' => ['from' => null, 'to' => FinancialHold::STATUS_ACTIVE], 'reason' => ['from' => null, 'to' => $reason->value]], 'Placed by policy.', $item->market_id);
                $this->metrics->record('hold_placed');

                return $hold;
            });
        } catch (UniqueConstraintViolationException) {
            return FinancialHold::query()->where('source', FinancialHold::SOURCE_SYSTEM)->where('status', FinancialHold::STATUS_ACTIVE)->where('source_key', $sourceKey)->where('payable_item_id', $item->getKey())->first();
        }
    }
}
