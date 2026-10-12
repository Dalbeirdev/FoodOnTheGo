<?php

namespace App\Services\Finance;

use App\Enums\DifferenceStatus;
use App\Enums\DifferenceType;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\ReconciliationDifference;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliation differences (Module 36): found by a reconciliation, kept until closed with a reason.
 *
 *   - the same finding is ONE row (its dedupe key): found again, it is seen again — not opened again;
 *   - a difference is closed automatically only by the deterministic rule that found it (the two records agree now);
 *     nothing hides a difference because it is small or old;
 *   - a person closes one as RESOLVED (corrected, an adjustment was posted, the provider corrected it, explained) or
 *     as IGNORED_WITH_REASON — always with a note, always audited;
 *   - a difference a person resolved that is found AGAIN is open again: it was not resolved. One a person ignored
 *     stays ignored.
 */
final class DifferenceService
{
    public function __construct(private readonly FinanceMetrics $metrics, private readonly AuditRecorder $audit) {}

    /**
     * @param  array{provider?: string|null, provider_reference?: string|null, subject_type?: string|null, subject_id?: int|null, subject_public_id?: string|null, subject_reference?: string|null, market_id?: int|null, location_id?: int|null, currency?: string|null, expected_minor?: int|null, actual_minor?: int|null, detail?: array<string, mixed>|null}  $facts
     */
    public function report(DifferenceType $type, string $dedupeKey, array $facts = []): ReconciliationDifference
    {
        $now = now();
        $existing = ReconciliationDifference::query()->where('dedupe_key', $dedupeKey)->first();
        if ($existing !== null) {
            $reopen = $existing->status === DifferenceStatus::Resolved;
            $existing->forceFill([
                'last_seen_at' => $now,
                'seen_count' => (int) $existing->seen_count + 1,
                ...self::amounts($facts),
                ...($reopen ? ['status' => DifferenceStatus::Open, 'resolution' => null, 'resolution_note' => null, 'resolved_at' => null, 'resolved_by_admin_id' => null, 'version' => (int) $existing->version + 1] : []),
            ])->save();
            if ($reopen) {
                $this->metrics->record('difference_opened');
                Log::warning('finance.difference_reopened', ['difference' => $existing->public_id, 'type' => $type->value]);
            }

            return $existing;
        }
        try {
            $difference = DB::transaction(function () use ($type, $dedupeKey, $facts, $now): ReconciliationDifference {
                $difference = new ReconciliationDifference;
                $difference->forceFill([
                    'dedupe_key' => $dedupeKey,
                    'source' => $type->isInternal() ? ReconciliationDifference::SOURCE_INTERNAL : ReconciliationDifference::SOURCE_PROVIDER,
                    'type' => $type,
                    'severity' => $type->severity(),
                    'status' => DifferenceStatus::Open,
                    'provider' => $facts['provider'] ?? null,
                    'provider_reference' => $facts['provider_reference'] ?? null,
                    'subject_type' => $facts['subject_type'] ?? null,
                    'subject_id' => $facts['subject_id'] ?? null,
                    'subject_public_id' => $facts['subject_public_id'] ?? null,
                    'subject_reference' => $facts['subject_reference'] ?? null,
                    'market_id' => $facts['market_id'] ?? null,
                    'location_id' => $facts['location_id'] ?? null,
                    'currency' => $facts['currency'] ?? null,
                    ...self::amounts($facts),
                    'detail' => $facts['detail'] ?? null,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ])->save();

                return $difference;
            });
        } catch (UniqueConstraintViolationException $e) {
            return ReconciliationDifference::query()->where('dedupe_key', $dedupeKey)->first() ?? throw $e;
        }
        $this->metrics->record('difference_opened');
        Log::log(in_array($difference->severity, ['CRITICAL', 'HIGH'], true) ? 'error' : 'warning', 'finance.difference_opened', [
            'difference' => $difference->public_id, 'type' => $type->value, 'severity' => $difference->severity,
            'expected_minor' => $difference->expected_minor, 'actual_minor' => $difference->actual_minor, 'currency' => $difference->currency,
        ]);

        return $difference;
    }

    /**
     * The rule that found the difference sees the records agree now: closed, saying so. Only an open difference.
     */
    public function settled(string $dedupeKey, string $note): bool
    {
        $difference = ReconciliationDifference::query()->where('dedupe_key', $dedupeKey)->unresolved()->first();
        if ($difference === null) {
            return false;
        }
        $difference->forceFill([
            'status' => DifferenceStatus::Resolved, 'resolution' => ReconciliationDifference::RESOLUTION_AUTO, 'resolution_note' => mb_substr($note, 0, 500),
            'resolved_at' => now(), 'version' => (int) $difference->version + 1,
        ])->save();
        $this->audit->record('finance.difference_resolved', $difference, null, ['status' => ['from' => 'OPEN', 'to' => DifferenceStatus::Resolved->value], 'resolution' => ['from' => null, 'to' => ReconciliationDifference::RESOLUTION_AUTO]], $note, $difference->market_id);
        $this->metrics->record('difference_auto_resolved');

        return true;
    }

    public function investigate(ReconciliationDifference $difference, AdminUser $admin, ?string $note): ReconciliationDifference
    {
        return $this->change($difference, $admin, function (ReconciliationDifference $locked) use ($admin, $note): array {
            if ($locked->status !== DifferenceStatus::Open) {
                throw ApiException::conflict('difference_not_open', 'Only an open difference can be taken up.');
            }

            return [['status' => DifferenceStatus::Investigating, 'assigned_admin_id' => $admin->getKey()], 'finance.difference_investigating', $note];
        });
    }

    public function resolve(ReconciliationDifference $difference, AdminUser $admin, string $resolution, string $note): ReconciliationDifference
    {
        return $this->change($difference, $admin, function (ReconciliationDifference $locked) use ($admin, $resolution, $note): array {
            if (! $locked->status->isOpen()) {
                throw ApiException::conflict('difference_closed', 'This difference is already closed.');
            }
            $this->metrics->record('difference_resolved');

            return [['status' => DifferenceStatus::Resolved, 'resolution' => $resolution, 'resolution_note' => mb_substr($note, 0, 500), 'resolved_at' => now(), 'resolved_by_admin_id' => $admin->getKey()], 'finance.difference_resolved', $note];
        });
    }

    public function ignore(ReconciliationDifference $difference, AdminUser $admin, string $note): ReconciliationDifference
    {
        return $this->change($difference, $admin, function (ReconciliationDifference $locked) use ($admin, $note): array {
            if (! $locked->status->isOpen()) {
                throw ApiException::conflict('difference_closed', 'This difference is already closed.');
            }
            if ($locked->severity === 'CRITICAL') {
                throw ApiException::conflict('difference_cannot_be_ignored', 'A critical difference cannot be ignored: it has to be resolved.');
            }
            $this->metrics->record('difference_resolved');

            return [['status' => DifferenceStatus::IgnoredWithReason, 'resolution' => 'IGNORED', 'resolution_note' => mb_substr($note, 0, 500), 'resolved_at' => now(), 'resolved_by_admin_id' => $admin->getKey()], 'finance.difference_ignored', $note];
        });
    }

    /**
     * @param  callable(ReconciliationDifference): array{0: array<string, mixed>, 1: string, 2: string|null}  $decide
     */
    private function change(ReconciliationDifference $difference, AdminUser $admin, callable $decide): ReconciliationDifference
    {
        return DB::transaction(function () use ($difference, $admin, $decide): ReconciliationDifference {
            $locked = ReconciliationDifference::query()->whereKey($difference->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status->value;
            [$attributes, $action, $note] = $decide($locked);
            $locked->forceFill([...$attributes, 'version' => (int) $locked->version + 1])->save();
            $this->audit->record($action, $locked, $admin, ['status' => ['from' => $from, 'to' => $locked->status->value], 'resolution' => ['from' => null, 'to' => $locked->resolution]], $note, $locked->market_id);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, int|null>
     */
    private static function amounts(array $facts): array
    {
        $expected = $facts['expected_minor'] ?? null;
        $actual = $facts['actual_minor'] ?? null;

        return array_key_exists('expected_minor', $facts) || array_key_exists('actual_minor', $facts)
            ? ['expected_minor' => $expected, 'actual_minor' => $actual, 'difference_minor' => $expected !== null && $actual !== null ? $actual - $expected : null]
            : [];
    }
}
