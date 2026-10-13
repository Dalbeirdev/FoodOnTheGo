<?php

namespace App\Services\Support;

use App\Enums\DifferenceType;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\RefundStatus;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseSource;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportLinkType;
use App\Enums\SupportRequesterType;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\ReconciliationDifference;
use App\Models\Refund;
use App\Models\RestaurantLocation;
use App\Models\SupportCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cases the system opens by itself (Module 37) when an operational condition needs a person:
 *
 *   - a verified payment that could not become an order (Module 31's recovery came to a stop),
 *   - a refund that needs a person (Module 35's REVIEW_REQUIRED),
 *   - a reconciliation difference of a configured severity (Module 36),
 *   - a provider dispute that is open (Module 35's dispute record).
 *
 * ONCE PER CONDITION: each case carries a key ("refund-review:{refund}") and the database allows one case that is
 * not closed per key — a condition reported again, by the listener and the sweep at once, or by two workers, finds
 * the case that exists. A case here only TELLS people; it recovers, refunds, reconciles and answers nothing. When the
 * condition is gone the case stays until a person resolves it: a case is closed by a person, not by a state elsewhere.
 */
final class SystemCaseService
{
    private const SEVERITIES = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];

    public function __construct(private readonly SupportCaseService $cases, private readonly SupportLinkService $links, private readonly SupportMetrics $metrics) {}

    public function paymentRecovery(PaymentAttempt $attempt, bool $reported = false): ?SupportCase
    {
        if (! (bool) config('support.system_cases.payment_order_recovery') || $attempt->fulfillment_status !== PaymentFulfillmentStatus::RecoveryRequired) {
            return null;
        }
        $reference = $attempt->supportReference();

        return $this->open('payment-recovery:'.$attempt->public_id, [
            'category' => 'PAYMENT', 'subcategory' => 'PAYMENT_CHARGED_NO_ORDER',
            'subject' => 'Payment '.$reference.' is verified and has no order',
        ], SupportCaseSource::PaymentRecovery,
            'The payment '.$reference.' is VERIFIED and no order could be created from it ('.($attempt->fulfillment_reason ?: 'no reason recorded').'). The customer paid '.self::amount((int) $attempt->amount_minor, (string) $attempt->currency).'. '
            .'Nothing was done by opening this case: retry the order creation or ask for a refund in their own screens — each needs its own permission.',
            [[SupportLinkType::Payment, $attempt]],
            ['customer_id' => $attempt->customer_id, 'market_id' => $attempt->market_id] + self::place($attempt->location_id),
            reported: $reported,
        );
    }

    public function refundReview(Refund $refund, bool $reported = false): ?SupportCase
    {
        if (! (bool) config('support.system_cases.refund_review') || $refund->status !== RefundStatus::ReviewRequired) {
            return null;
        }
        $reference = $refund->supportReference();
        $records = [[SupportLinkType::Refund, $refund]];
        if ($refund->order_id !== null && ($order = Order::query()->find($refund->order_id)) !== null) {
            $records[] = [SupportLinkType::Order, $order];
        }
        if (($payment = PaymentAttempt::query()->find($refund->payment_attempt_id)) !== null) {
            $records[] = [SupportLinkType::Payment, $payment];
        }

        return $this->open('refund-review:'.$refund->public_id, [
            'category' => 'REFUND', 'subcategory' => 'REFUND_FAILED',
            'subject' => 'Refund '.$reference.' needs a person',
        ], SupportCaseSource::RefundRecovery,
            'The refund '.$reference.($refund->order_number ? ' of order '.$refund->order_number : '').' is in REVIEW_REQUIRED ('.($refund->review_reason ?: 'no reason recorded').'), amount '.self::amount((int) ($refund->approved_amount_minor ?? $refund->requested_amount_minor), (string) $refund->currency).'. '
            .'It is NOT refunded. Only the refund\'s own screen can reconcile, retry or close it; this case changes nothing about it.',
            $records,
            ['customer_id' => $refund->customer_id, 'market_id' => $refund->market_id] + self::place($refund->location_id),
            reported: $reported,
        );
    }

    public function financeDifference(ReconciliationDifference $difference): ?SupportCase
    {
        $min = config('support.system_cases.finance_difference_min_severity');
        if (! is_string($min) || ! isset(self::SEVERITIES[strtoupper($min)]) || ! $difference->status->isOpen()
            || (self::SEVERITIES[strtoupper((string) $difference->severity)] ?? 0) < self::SEVERITIES[strtoupper($min)]) {
            return null;
        }
        $type = $difference->type instanceof DifferenceType ? $difference->type->value : (string) $difference->type;

        return $this->open('finance-difference:'.$difference->public_id, [
            'category' => 'SETTLEMENT', 'subcategory' => 'RECONCILIATION_DIFFERENCE',
            'subject' => 'Reconciliation difference: '.str_replace('_', ' ', strtolower($type)),
        ], SupportCaseSource::FinanceReconciliation,
            'A reconciliation difference of severity '.$difference->severity.' is open: '.$type.($difference->subject_reference ? ' on '.$difference->subject_reference : '').'. '
            .'It is investigated and closed in the reconciliation queue of Finance; this case changes no financial record.',
            [[SupportLinkType::Difference, $difference]],
            ['market_id' => $difference->market_id] + self::place($difference->location_id),
            (string) $difference->severity === 'CRITICAL' ? SupportCasePriority::Urgent : SupportCasePriority::High,
        );
    }

    public function dispute(PaymentDispute $dispute): ?SupportCase
    {
        if (! (bool) config('support.system_cases.dispute', true) || ! in_array($dispute->status->value, ['OPEN', 'ACTION_REQUIRED', 'UNDER_REVIEW'], true)) {
            return null;
        }
        $records = [[SupportLinkType::Dispute, $dispute]];
        if (($payment = PaymentAttempt::query()->find($dispute->payment_attempt_id)) !== null) {
            $records[] = [SupportLinkType::Payment, $payment];
        }
        if ($dispute->order_id !== null && ($order = Order::query()->find($dispute->order_id)) !== null) {
            $records[] = [SupportLinkType::Order, $order];
        }

        return $this->open('dispute:'.$dispute->public_id, [
            'category' => 'DISPUTE', 'subcategory' => null,
            'subject' => 'Payment dispute '.$dispute->provider_dispute_reference,
        ], SupportCaseSource::FinanceReconciliation,
            'The payment provider reported a dispute ('.$dispute->provider_dispute_reference.', '.$dispute->status->value.') for '.self::amount((int) $dispute->amount_minor, (string) $dispute->currency)
            .($dispute->respond_by_at ? '. The provider expects an answer by '.$dispute->respond_by_at->utc()->toIso8601String() : '').'. '
            .'FoodOnTheGo sends no answer to the provider: evidence is submitted in the provider\'s own dashboard. This case coordinates that work only.',
            $records,
            ['customer_id' => $payment?->customer_id, 'market_id' => $dispute->market_id] + self::place($payment?->location_id),
        );
    }

    /**
     * READINESS: a security matter that needs a person. Nothing opens one automatically yet — which security events
     * deserve a case is a decision nobody has made. Restricted from the start.
     *
     * @param  list<array{0: SupportLinkType, 1: Model}>  $records
     * @param  array<string, mixed>  $columns
     */
    public function security(string $key, string $subject, string $note, array $records = [], array $columns = []): ?SupportCase
    {
        return $this->open('security:'.$key, ['category' => 'SECURITY', 'subcategory' => null, 'subject' => $subject], SupportCaseSource::Security, $note, $records, $columns);
    }

    /**
     * Opens what the listeners missed: an event that was lost, a condition that existed before this module.
     *
     * @return array{payment_recovery: int, refund_review: int, finance_difference: int, dispute: int}
     */
    public function sweep(): array
    {
        $batch = max(1, (int) config('support.system_cases.sweep_batch'));
        // A condition has ONE case, whatever became of it: a case that a person closed or cancelled while the condition
        // still holds is not opened again by the clock (it would come back every minute).
        $without = fn (Builder $q, string $prefix, string $table): Builder => $q->whereNotExists(fn ($s) => $s->from('support_cases')
            ->whereRaw("support_cases.system_key = '".$prefix.":' || ".$table.'.public_id::text'));
        $counts = ['payment_recovery' => 0, 'refund_review' => 0, 'finance_difference' => 0, 'dispute' => 0];
        if ((bool) config('support.system_cases.payment_order_recovery')) {
            foreach ($without(PaymentAttempt::query()->where('fulfillment_status', PaymentFulfillmentStatus::RecoveryRequired->value), 'payment-recovery', 'payment_attempts')->orderBy('id')->limit($batch)->get() as $attempt) {
                $counts['payment_recovery'] += (int) $this->created($this->paymentRecovery($attempt));
            }
        }
        if ((bool) config('support.system_cases.refund_review')) {
            foreach ($without(Refund::query()->where('status', RefundStatus::ReviewRequired->value), 'refund-review', 'refunds')->orderBy('id')->limit($batch)->get() as $refund) {
                $counts['refund_review'] += (int) $this->created($this->refundReview($refund));
            }
        }
        if (is_string($minimum = config('support.system_cases.finance_difference_min_severity')) && isset(self::SEVERITIES[strtoupper($minimum)])) {
            // Only the differences that are serious enough: the others would fill the batch for ever and get no case.
            $serious = array_keys(array_filter(self::SEVERITIES, fn (int $rank): bool => $rank >= self::SEVERITIES[strtoupper($minimum)]));
            foreach ($without(ReconciliationDifference::query()->whereIn('status', ['OPEN', 'INVESTIGATING'])->whereIn('severity', $serious), 'finance-difference', 'reconciliation_differences')->orderBy('id')->limit($batch)->get() as $difference) {
                $counts['finance_difference'] += (int) $this->created($this->financeDifference($difference));
            }
        }
        if ((bool) config('support.system_cases.dispute', true)) {
            foreach ($without(PaymentDispute::query()->whereIn('status', ['OPEN', 'ACTION_REQUIRED', 'UNDER_REVIEW']), 'dispute', 'payment_disputes')->orderBy('id')->limit($batch)->get() as $dispute) {
                $counts['dispute'] += (int) $this->created($this->dispute($dispute));
            }
        }

        return $counts;
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * @param  array{category: string, subcategory: string|null, subject: string}  $input
     * @param  list<array{0: SupportLinkType, 1: Model}>  $records
     * @param  array<string, mixed>  $columns
     */
    private function open(string $key, array $input, SupportCaseSource $source, string $note, array $records, array $columns, ?SupportCasePriority $priority = null, bool $reported = false): ?SupportCase
    {
        $live = fn (): ?SupportCase => SupportCase::query()->where('system_key', $key)->first();
        if (($existing = $live()) !== null) {
            $this->metrics->record('system_case_repeated');
            // The condition was REPORTED again (an event, not the clock) after its case had been closed: the same case
            // is open again, with a note. A cancelled case stays cancelled — a person decided it is not a case.
            if ($reported && $existing->status === SupportCaseStatus::Closed) {
                DB::transaction(function () use ($existing, $note): void {
                    $locked = $this->cases->reopen($existing, SupportActor::system(), 'The condition this case is about was reported again.');
                    $this->cases->systemNote($locked, 'Reported again: '.$note);
                });

                return $live();
            }

            return $existing;
        }
        $records = array_map(fn (array $r): array => [$r[0], $r[1], SupportLinkService::referenceOf($r[0], $r[1])], $records);
        try {
            return $this->cases->create(SupportActor::system(), SupportRequesterType::System, $input, $source, $records, array_filter($columns, fn ($v): bool => $v !== null), $note, $key, $priority);
        } catch (UniqueConstraintViolationException $e) {
            // The listener and the sweep (or two workers) saw the same condition at the same moment: one case.
            if (! str_contains($e->getMessage(), 'support_cases_system_key_unique')) {
                throw $e;
            }
            $this->metrics->record('system_case_repeated');
            Log::info('support.system_case_repeated', ['key' => $key]);

            return $live();
        }
    }

    private function created(?SupportCase $case): bool
    {
        return $case !== null && $case->wasRecentlyCreated;
    }

    /**
     * @return array<string, int|null>
     */
    private static function place(?int $locationId): array
    {
        $location = $locationId === null ? null : RestaurantLocation::query()->find($locationId);

        return $location === null ? [] : ['location_id' => (int) $location->getKey(), 'organization_id' => (int) $location->organization_id];
    }

    private static function amount(int $minor, string $currency): string
    {
        // Formatting money is the clients' work (the minor unit depends on the currency): the note states the stored figure.
        return $minor.' '.$currency.' (minor units)';
    }
}
