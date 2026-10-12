<?php

namespace App\Http\Resources\Finance;

use App\Enums\SettlementStatus;
use App\Models\AdminUser;
use App\Models\CommissionPlan;
use App\Models\FinanceRun;
use App\Models\FinancialAdjustment;
use App\Models\FinancialHold;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\OrderFinancialAllocation;
use App\Models\ProviderSettlement;
use App\Models\ProviderSettlementItem;
use App\Models\ReconciliationDifference;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantPayout;
use App\Models\RestaurantPayoutProfile;
use App\Models\RestaurantSettlement;
use App\Models\RestaurantSettlementLine;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * How finance records leave the API (Module 36). Two audiences, two shapes:
 *
 *   a RESTAURANT sees its own earnings, statements and payout states — order numbers, never a customer; no
 *   administrator's name, no internal note, no provider reference, no ledger account;
 *   an ADMINISTRATOR sees the records and what was decided, by whom and why.
 *
 * Money is {"amount": minor units, "currency": ISO}. A payout destination is the provider's mask of it — the token
 * behind it never leaves the backend. Internal bigint ids never appear.
 */
final class FinancePresenter
{
    /**
     * @return array{amount: int, currency: string}
     */
    public static function money(int $minor, string $currency): array
    {
        return (new Money($minor, $currency))->toArray();
    }

    /* ------------------------------------------------------------------ commercial terms */

    /**
     * @return array<string, mixed>
     */
    public function plan(CommissionPlan $plan): array
    {
        return [
            'id' => (string) $plan->public_id,
            'name' => (string) $plan->name,
            'scope' => (string) $plan->scope_type,
            'market' => $plan->market?->slug,
            'organization' => $plan->organization === null ? null : ['id' => (string) $plan->organization->public_id, 'name' => (string) $plan->organization->display_name],
            'location' => $plan->location === null ? null : ['id' => (string) $plan->location->public_id, 'name' => (string) $plan->location->name],
            'type' => (string) $plan->type,
            'rate_bps' => (int) $plan->rate_bps,
            'base_policy' => $plan->base_policy->value,
            'effective_from' => self::utc($plan->effective_from),
            'effective_until' => self::utc($plan->effective_until),
            'state' => $plan->stateAt(now()),
            // TEST = a development fixture: never a production commission.
            'approval' => (string) $plan->approval,
            'approval_reference' => $plan->approval_reference,
            'source' => (string) $plan->source,
            'reason' => (string) $plan->reason,
            'created_by' => self::admin($plan->createdBy),
            'withdrawn_at' => self::utc($plan->withdrawn_at),
            'withdrawn_reason' => $plan->withdrawn_reason,
            'version' => (int) $plan->version,
            'created_at' => self::utc($plan->created_at),
        ];
    }

    /**
     * A restaurant's OWN terms: what applies, since when. Not who decided it, why, or its approval reference.
     *
     * @return array<string, mixed>
     */
    public function planForRestaurant(CommissionPlan $plan): array
    {
        return [
            'type' => (string) $plan->type,
            'rate_bps' => (int) $plan->rate_bps,
            'base_policy' => $plan->base_policy->value,
            'applies_to' => $plan->scope_type === CommissionPlan::SCOPE_MARKET ? 'MARKET_STANDARD' : 'YOUR_RESTAURANT',
            'effective_from' => self::utc($plan->effective_from),
            'effective_until' => self::utc($plan->effective_until),
            'test_fixture' => $plan->approval !== CommissionPlan::APPROVAL_APPROVED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function allocation(OrderFinancialAllocation $a): array
    {
        $c = (string) $a->currency;
        $m = fn (?int $v): ?array => $v === null ? null : self::money($v, $c);

        return [
            'id' => (string) $a->public_id,
            'order' => ['id' => (string) $a->order_public_id, 'number' => (string) $a->order_number],
            'restaurant' => $a->location === null ? null : ['id' => (string) $a->location->public_id, 'name' => (string) $a->location->name],
            'market' => $a->market?->slug,
            'status' => $a->status->value,
            'review_reason' => $a->review_reason,
            'currency' => $c,
            'customer_total' => $m((int) $a->customer_total_minor),
            'merchandise_gross' => $m((int) $a->merchandise_gross_minor),
            'discount' => $m((int) $a->discount_minor),
            'promotion' => ['code' => $a->promotion_code, 'funding' => (string) $a->promotion_funding, 'funding_basis' => (string) $a->promotion_funding_basis, 'restaurant_funded' => $m((int) $a->restaurant_discount_minor), 'platform_funded' => $m((int) $a->platform_discount_minor)],
            'tax' => ['total' => $m((int) $a->tax_minor), 'included_in_prices' => $m((int) $a->tax_included_minor), 'owner' => (string) $a->tax_owner, 'passed_to_restaurant' => $m((int) $a->tax_restaurant_minor), 'held' => $m((int) $a->tax_held_minor)],
            'fees' => ['total' => $m((int) $a->fee_minor), 'owner' => (string) $a->fee_owner, 'restaurant' => $m((int) $a->fee_restaurant_minor), 'platform' => $m((int) $a->fee_platform_minor)],
            'commission' => $a->commission_minor === null ? null : [
                'source' => (string) $a->commission_source, 'scope' => $a->commission_scope, 'plan' => $a->commission_plan_public_id, 'plan_version' => $a->commission_plan_version,
                'rate_bps' => (int) $a->commission_rate_bps, 'base_policy' => $a->commission_base_policy?->value, 'base' => $m((int) $a->commission_base_minor), 'amount' => $m((int) $a->commission_minor),
            ],
            'restaurant_net' => $m($a->restaurant_net_minor),
            'platform_net' => $m($a->platform_net_minor),
            'decision_reason' => $a->decision_reason,
            'placed_at' => self::utc($a->placed_at),
            'recognized_at' => self::utc($a->recognized_at),
            'voided_at' => self::utc($a->voided_at),
            'version' => (int) $a->version,
        ];
    }

    /* ------------------------------------------------------------------ earnings and statements */

    /**
     * One line of a restaurant's earnings. $state is AVAILABLE, HELD, IN_SETTLEMENT, SETTLED or PAID.
     *
     * @return array<string, mixed>
     */
    public function earning(RestaurantPayableItem $item, string $state, bool $admin = false): array
    {
        $c = (string) $item->currency;

        return [
            'id' => (string) $item->public_id,
            'type' => (string) $item->type,
            'description' => (string) $item->description_code,
            'order_number' => $item->order_number,
            'currency' => $c,
            'gross_sales' => self::money((int) $item->gross_minor, $c),
            'restaurant_discount' => self::money((int) $item->restaurant_discount_minor, $c),
            'commission' => self::money((int) $item->commission_minor, $c),
            'tax_passed' => self::money((int) $item->tax_passed_minor, $c),
            'fee_passed' => self::money((int) $item->fee_passed_minor, $c),
            'amount' => self::money((int) $item->amount_minor, $c),
            'state' => $state,
            'settlement' => $item->settlement === null ? null : ['id' => (string) $item->settlement->public_id, 'reference' => (string) $item->settlement->reference],
            'available_at' => self::utc($item->available_at),
            ...($admin ? ['restaurant' => $item->location === null ? null : ['id' => (string) $item->location->public_id, 'name' => (string) $item->location->name]] : []),
        ];
    }

    public static function earningState(RestaurantPayableItem $item, bool $held): string
    {
        if ($item->status === RestaurantPayableItem::STATUS_AVAILABLE) {
            return $held ? 'HELD' : 'AVAILABLE';
        }

        return match ($item->settlement?->status) {
            SettlementStatus::Paid => 'PAID',
            SettlementStatus::Closed => 'SETTLED',
            default => 'IN_SETTLEMENT',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function settlement(RestaurantSettlement $s, bool $admin = false): array
    {
        $c = (string) $s->currency;
        $paid = $s->status === SettlementStatus::Paid ? (int) $s->paid_minor : 0;

        return [
            'id' => (string) $s->public_id,
            'reference' => (string) $s->reference,
            'restaurant' => ['id' => $s->location === null ? null : (string) $s->location->public_id, 'name' => (string) $s->restaurant_name],
            'currency' => $c,
            // A restaurant reads its own words; the finance team's internal steps stay with the administrators.
            'status' => $admin ? $s->status->value : $s->status->forRestaurant(),
            'period' => ['start' => self::utc($s->period_start), 'end' => self::utc($s->period_end), 'timezone' => (string) $s->timezone, 'cadence' => (string) $s->cadence],
            'summary' => [
                'completed_orders' => (int) $s->order_count,
                'lines' => (int) $s->line_count,
                'gross_sales' => self::money((int) $s->gross_sales_minor, $c),
                'restaurant_discounts' => self::money((int) $s->restaurant_discount_minor, $c),
                'commission' => self::money((int) $s->commission_minor, $c),
                'tax_passed' => self::money((int) $s->tax_passed_minor, $c),
                'fees_passed' => self::money((int) $s->fee_passed_minor, $c),
                'refund_adjustments' => self::money((int) $s->refund_adjustments_minor, $c),
                'other_adjustments' => self::money((int) $s->other_adjustments_minor, $c),
                'net_payable' => self::money((int) $s->net_payable_minor, $c),
                'amount_paid' => self::money($paid, $c),
                'amount_pending' => self::money(in_array($s->status, [SettlementStatus::Cancelled, SettlementStatus::Closed], true) ? 0 : (int) $s->net_payable_minor - $paid, $c),
            ],
            // A statement of what is owed. Not money sent, and not a tax invoice.
            'document' => ['kind' => 'SETTLEMENT_STATEMENT', 'tax_invoice' => false],
            'generated_at' => self::utc($s->generated_at),
            'approved_at' => self::utc($s->approved_at),
            'paid_at' => self::utc($s->paid_at),
            ...($admin ? [
                'market' => $s->market?->slug,
                'organization' => $s->organization === null ? null : ['id' => (string) $s->organization->public_id, 'name' => (string) $s->organization->display_name],
                'review_reason' => $s->review_reason,
                'generated_by' => ['type' => (string) $s->generated_by_type, 'admin' => self::admin($s->generatedBy)],
                'approved_by' => self::admin($s->approvedBy),
                'approval_note' => $s->approval_note,
                'cancelled_at' => self::utc($s->cancelled_at),
                'cancelled_by' => self::admin($s->cancelledBy),
                'cancelled_reason' => $s->cancelled_reason,
                'version' => (int) $s->version,
            ] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settlementLine(RestaurantSettlementLine $line): array
    {
        $c = (string) $line->currency;

        return [
            'line' => (int) $line->line_no,
            'type' => (string) $line->type,
            'description' => (string) $line->description_code,
            'order_number' => $line->order_number,
            'source' => $line->source_public_id,
            'gross_sales' => self::money((int) $line->gross_minor, $c),
            'restaurant_discount' => self::money((int) $line->restaurant_discount_minor, $c),
            'commission' => self::money((int) $line->commission_minor, $c),
            'tax_passed' => self::money((int) $line->tax_passed_minor, $c),
            'fee_passed' => self::money((int) $line->fee_passed_minor, $c),
            'amount' => self::money((int) $line->amount_minor, $c),
            'occurred_at' => self::utc($line->occurred_at),
        ];
    }

    /* ------------------------------------------------------------------ payouts */

    /**
     * @return array<string, mixed>
     */
    public function payout(RestaurantPayout $p, bool $admin = false): array
    {
        return [
            'id' => (string) $p->public_id,
            'reference' => $p->supportReference(),
            'settlement' => $p->settlement === null ? null : ['id' => (string) $p->settlement->public_id, 'reference' => (string) $p->settlement->reference],
            'restaurant' => ['id' => $p->location === null ? null : (string) $p->location->public_id, 'name' => (string) $p->restaurant_name],
            'amount' => self::money((int) $p->amount_minor, (string) $p->currency),
            'currency' => (string) $p->currency,
            'status' => $admin ? $p->status->value : $p->status->forRestaurant(),
            'method' => (string) $p->method,
            // Only ever the provider's mask of the destination.
            'destination' => $p->destination_mask,
            'requested_at' => self::utc($p->requested_at),
            'completed_at' => self::utc($p->completed_at),
            'failed_at' => self::utc($p->failed_at),
            ...($admin ? [
                'market' => $p->market?->slug,
                'provider' => (string) $p->provider,
                'provider_mode' => $p->provider_mode,
                'test_only' => $p->method === RestaurantPayout::METHOD_PROVIDER && $p->provider_mode !== 'live',
                'provider_payout_reference' => $p->provider_payout_reference,
                'provider_status' => $p->provider_status,
                'needs_review' => $p->status->value === 'REVIEW_REQUIRED' || $p->status->value === 'UNKNOWN',
                'review_reason' => $p->review_reason,
                'failure' => $p->failure_code === null ? null : ['code' => (string) $p->failure_code, 'message' => $p->failure_message],
                'external' => $p->method === RestaurantPayout::METHOD_EXTERNAL ? ['reference' => $p->external_reference, 'paid_on' => $p->external_paid_on?->toDateString(), 'evidence_reference' => $p->evidence_reference] : null,
                'note' => $p->note,
                'requested_by' => self::admin($p->requestedBy),
                'submit_attempts' => (int) $p->submit_attempts,
                'submitted_at' => self::utc($p->submitted_at),
                'processing_at' => self::utc($p->processing_at),
                'last_reconciled_at' => self::utc($p->last_reconciled_at),
                'next_action_at' => self::utc($p->next_action_at),
                'version' => (int) $p->version,
            ] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payoutProfile(?RestaurantPayoutProfile $profile): array
    {
        return $profile === null
            ? ['status' => RestaurantPayoutProfile::NOT_CONFIGURED, 'provider' => null, 'destination' => null, 'verified_at' => null, 'test_only' => null]
            : ['status' => (string) $profile->status, 'provider' => (string) $profile->provider, 'destination' => $profile->destination_mask, 'verified_at' => self::utc($profile->verified_at), 'test_only' => $profile->provider === 'fake'];
    }

    /* ------------------------------------------------------------------ holds and adjustments */

    /**
     * @return array<string, mixed>
     */
    public function hold(FinancialHold $h): array
    {
        return [
            'id' => (string) $h->public_id,
            'scope' => (string) $h->scope,
            'restaurant' => $h->location === null ? null : ['id' => (string) $h->location->public_id, 'name' => (string) $h->location->name],
            'market' => $h->market?->slug,
            'line' => $h->payableItem === null ? null : ['id' => (string) $h->payableItem->public_id, 'order_number' => $h->payableItem->order_number, 'amount' => self::money((int) $h->payableItem->amount_minor, (string) $h->payableItem->currency)],
            'reason' => $h->reason->value,
            'status' => (string) $h->status,
            'source' => (string) $h->source,
            'note' => $h->note,
            'placed_by' => self::admin($h->placedBy),
            'placed_at' => self::utc($h->placed_at),
            'released_by' => self::admin($h->releasedBy),
            'released_at' => self::utc($h->released_at),
            'release_reason' => $h->release_reason,
            'version' => (int) $h->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adjustment(FinancialAdjustment $a): array
    {
        $c = (string) $a->currency;

        return [
            'id' => (string) $a->public_id,
            'kind' => (string) $a->kind,
            'status' => (string) $a->status,
            'restaurant' => $a->location === null ? null : ['id' => (string) $a->location->public_id, 'name' => (string) $a->location->name],
            'market' => $a->market?->slug,
            'order_number' => $a->order_number,
            // A refund is named by its own id — the refund screens show the rest.
            'source' => $a->refund !== null ? ['type' => 'REFUND', 'id' => (string) $a->refund->public_id] : ($a->dispute !== null ? ['type' => 'DISPUTE', 'id' => (string) $a->dispute->public_id] : null),
            'currency' => $c,
            'amount' => self::money((int) $a->amount_minor, $c),
            'direction' => $a->direction,
            'liability' => (string) $a->liability,
            'restaurant_share' => $a->restaurant_share_minor === null ? null : self::money((int) $a->restaurant_share_minor, $c),
            'platform_share' => $a->platform_share_minor === null ? null : self::money((int) $a->platform_share_minor, $c),
            'policy_source' => $a->policy_source,
            'reason_code' => (string) $a->reason_code,
            'reason' => $a->reason,
            'supporting_reference' => $a->supporting_reference,
            'created_by' => self::admin($a->createdBy),
            'decided_by' => self::admin($a->decidedBy),
            'decided_at' => self::utc($a->decided_at),
            'posted_at' => self::utc($a->posted_at),
            'ledger_transaction' => $a->ledgerTransaction === null ? null : (string) $a->ledgerTransaction->public_id,
            'created_at' => self::utc($a->created_at),
            'version' => (int) $a->version,
        ];
    }

    /* ------------------------------------------------------------------ provider settlements and differences */

    /**
     * @return array<string, mixed>
     */
    public function providerSettlement(ProviderSettlement $s): array
    {
        $c = (string) $s->currency;

        return [
            'id' => (string) $s->public_id,
            'provider' => (string) $s->provider,
            'provider_mode' => $s->provider_mode,
            'reference' => (string) $s->provider_settlement_reference,
            'currency' => $c,
            'status' => (string) $s->status,
            'amount' => self::money((int) $s->amount_minor, $c),
            'fees' => self::money((int) $s->fees_minor, $c),
            'tax_on_fees' => self::money((int) $s->tax_minor, $c),
            'bank_reference' => $s->bank_reference,
            'lines' => ['count' => (int) $s->item_count, 'credits' => self::money((int) $s->items_credit_minor, $c), 'debits' => self::money((int) $s->items_debit_minor, $c), 'fees' => self::money((int) $s->items_fee_minor, $c), 'tax_on_fees' => self::money((int) $s->items_tax_minor, $c)],
            'reconciliation_status' => (string) $s->reconciliation_status,
            'posted_to_ledger' => $s->ledger_transaction_id !== null,
            'provider_created_at' => self::utc($s->provider_created_at),
            'settled_at' => self::utc($s->settled_at),
            'reconciled_at' => self::utc($s->reconciled_at),
            'imported_at' => self::utc($s->imported_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function providerSettlementItem(ProviderSettlementItem $i): array
    {
        $c = (string) $i->currency;

        return [
            'id' => (string) $i->public_id,
            'type' => (string) $i->type,
            'reference' => (string) $i->entity_reference,
            'payment_reference' => $i->payment_reference,
            'currency' => $c,
            'amount' => self::money((int) $i->amount_minor, $c),
            'credit' => self::money((int) $i->credit_minor, $c),
            'debit' => self::money((int) $i->debit_minor, $c),
            // The provider's own numbers. No fee is ever calculated here.
            'fee' => self::money((int) $i->fee_minor, $c),
            'tax_on_fee' => self::money((int) $i->tax_minor, $c),
            'settled' => (bool) $i->settled,
            'match_status' => (string) $i->match_status,
            'matched_payment' => $i->paymentAttempt === null ? null : (string) $i->paymentAttempt->public_id,
            'matched_refund' => $i->refund === null ? null : (string) $i->refund->public_id,
            'fee_posted' => $i->fee_transaction_id !== null,
            'settled_at' => self::utc($i->settled_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function difference(ReconciliationDifference $d, bool $detail = false): array
    {
        $c = $d->currency;
        $m = fn (?int $v): ?array => $v === null || $c === null ? null : self::money($v, (string) $c);

        return [
            'id' => (string) $d->public_id,
            'source' => (string) $d->source,
            'type' => $d->type->value,
            'severity' => (string) $d->severity,
            'status' => $d->status->value,
            'provider' => $d->provider,
            'provider_reference' => $d->provider_reference,
            'subject' => $d->subject_type === null ? null : ['type' => (string) $d->subject_type, 'id' => $d->subject_public_id, 'reference' => $d->subject_reference],
            'market' => $d->market?->slug,
            'restaurant' => $d->location === null ? null : ['id' => (string) $d->location->public_id, 'name' => (string) $d->location->name],
            'currency' => $c,
            'expected' => $m($d->expected_minor),
            'actual' => $m($d->actual_minor),
            'difference' => $m($d->difference_minor),
            'first_seen_at' => self::utc($d->first_seen_at),
            'last_seen_at' => self::utc($d->last_seen_at),
            'seen_count' => (int) $d->seen_count,
            'resolution' => $d->resolution,
            'resolution_note' => $d->resolution_note,
            'assigned_to' => self::admin($d->assignedTo),
            'resolved_by' => self::admin($d->resolvedBy),
            'resolved_at' => self::utc($d->resolved_at),
            'version' => (int) $d->version,
            ...($detail ? ['detail' => $d->detail] : []),
        ];
    }

    /* ------------------------------------------------------------------ ledger (read-only) */

    /**
     * @return array<string, mixed>
     */
    public function account(LedgerAccount $a, int $debits, int $credits): array
    {
        $balance = $a->normal_side === 'D' ? $debits - $credits : $credits - $debits;

        return [
            'id' => (string) $a->public_id,
            'code' => $a->code->value,
            'owner' => (string) $a->owner_type,
            'restaurant' => $a->location === null ? null : ['id' => (string) $a->location->public_id, 'name' => (string) $a->location->name],
            'provider' => $a->provider,
            'currency' => (string) $a->currency,
            'normal_side' => $a->normal_side === 'D' ? 'DEBIT' : 'CREDIT',
            'debits' => self::money($debits, (string) $a->currency),
            'credits' => self::money($credits, (string) $a->currency),
            'balance' => self::money($balance, (string) $a->currency),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction(LedgerTransaction $t, bool $entries = false): array
    {
        $c = (string) $t->currency;

        return [
            'id' => (string) $t->public_id,
            'posting_key' => (string) $t->posting_key,
            'type' => $t->type->value,
            'source' => ['type' => (string) $t->source_type, 'id' => $t->source_public_id, 'reference' => $t->source_reference],
            'currency' => $c,
            'amount' => self::money((int) $t->amount_minor, $c),
            'market' => $t->market?->slug,
            'restaurant' => $t->location === null ? null : ['id' => (string) $t->location->public_id, 'name' => (string) $t->location->name],
            'memo' => $t->memo,
            'reverses' => $t->reverses_transaction_id === null ? null : $t->reverses?->public_id,
            'occurred_at' => self::utc($t->occurred_at),
            'posted_at' => self::utc($t->posted_at),
            ...($entries ? [
                'detail' => $t->detail,
                'entries' => $t->entries->map(fn ($e): array => [
                    'line' => (int) $e->line_no,
                    'account' => ['id' => (string) $e->account->public_id, 'code' => $e->account->code->value, 'owner' => (string) $e->account->owner_type, 'provider' => $e->account->provider],
                    'direction' => $e->direction === 'D' ? 'DEBIT' : 'CREDIT',
                    'amount' => self::money((int) $e->amount_minor, $c),
                    'description' => (string) $e->description_code,
                ])->all(),
            ] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(FinanceRun $r): array
    {
        return [
            'id' => (string) $r->public_id,
            'kind' => (string) $r->kind,
            'status' => (string) $r->status,
            'triggered_by' => (string) $r->triggered_by,
            'dry_run' => (bool) $r->dry_run,
            'parameters' => $r->parameters,
            'result' => $r->result,
            'problems' => (int) $r->problems,
            'started_at' => self::utc($r->started_at),
            'finished_at' => self::utc($r->finished_at),
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private static function admin(?AdminUser $admin): ?array
    {
        return $admin === null ? null : ['id' => (string) $admin->public_id, 'name' => (string) $admin->name];
    }

    public static function utc(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->toIso8601String();
    }
}
