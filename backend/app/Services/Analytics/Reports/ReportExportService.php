<?php

namespace App\Services\Analytics\Reports;

use App\Auth\Principal;
use App\Exceptions\ApiException;
use App\Jobs\Analytics\GenerateReportExportJob;
use App\Models\AdminUser;
use App\Models\ReportExport;
use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Audit\AuditRecorder;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report exports (Module 38): asked for, built in the background, kept privately for a while, handed to the person
 * who asked — and to nobody else.
 *
 *   ask       a row QUEUED and a job; the request never waits for the file
 *   generate  the job: PROCESSING → the CSV on the private disk → READY with an expiry (or FAILED with a code)
 *   download  the requester only (anybody else: "not found"), still permitted, not expired; streamed, never a public link
 *   expire    the file is deleted when its time is up; the row stays as the record that it existed
 *
 * Asking and downloading are audited: who, which report, which period and scope, when.
 */
final class ReportExportService
{
    public function __construct(private readonly ReportBuilder $builder, private readonly AuditRecorder $audit, private readonly AnalyticsMetrics $metrics) {}

    /**
     * @param  array<string, mixed>  $extra  what else the answer's scope says (names for a person to read)
     */
    public function ask(string $audience, Principal $requester, string $type, AnalyticsPeriod $period, AnalyticsScope $scope, ?int $marketId, ?int $organizationId, array $extra = []): ReportExport
    {
        $mine = $audience === ReportExport::ADMIN ? 'requested_by_admin_id' : 'requested_by_restaurant_user_id';
        $pending = ReportExport::query()->where($mine, $requester->getKey())->whereIn('status', [ReportExport::QUEUED, ReportExport::PROCESSING])->count();
        if ($pending >= (int) config('analytics.exports.max_pending_per_requester')) {
            throw ApiException::conflict('too_many_exports', 'Wait for your exports that are being prepared before asking for another.', ['pending' => $pending]);
        }
        $export = DB::transaction(function () use ($audience, $requester, $type, $period, $scope, $marketId, $organizationId, $mine, $extra): ReportExport {
            $export = new ReportExport;
            $export->forceFill([
                $mine => $requester->getKey(), 'audience' => $audience, 'report_type' => $type, 'status' => ReportExport::QUEUED, 'market_id' => $marketId, 'organization_id' => $organizationId,
                // Exactly what the server decided the report covers. The job reads this — never a request.
                'filters' => ['from' => $period->fromDate(), 'to' => $period->toDate(), 'timezone' => $period->timezone, 'preset' => $period->preset, 'market_ids' => $scope->marketIds, 'location_ids' => $scope->locationIds,
                    'includes_unassigned' => $scope->includesUnassigned, 'finance' => $scope->finance, 'support' => $scope->support, 'currency' => $scope->currency, 'scope' => $scope->label + $extra],
            ])->save();
            $this->audit->record('analytics.export_requested', $export, $requester, ['export' => ['from' => null, 'to' => $type], 'period' => ['from' => null, 'to' => $period->fromDate().'..'.$period->toDate()],
                'markets' => ['from' => null, 'to' => $scope->marketIds], 'locations' => ['from' => null, 'to' => $scope->locationIds]], null, $marketId);

            return $export;
        });
        $this->metrics->record('export_requested');
        GenerateReportExportJob::dispatch((int) $export->getKey());

        return $export->refresh();
    }

    /**
     * Builds the file. Safe to run again: an export that is not waiting is left alone.
     */
    public function generate(int $exportId): void
    {
        $claimed = ReportExport::query()->whereKey($exportId)->where('status', ReportExport::QUEUED)->update(['status' => ReportExport::PROCESSING, 'started_at' => CarbonImmutable::now('UTC')]);
        if ($claimed !== 1) {
            return;
        }
        $export = ReportExport::query()->findOrFail($exportId);
        $filters = (array) $export->filters;
        $disk = Storage::disk((string) config('analytics.exports.disk'));
        $path = trim((string) config('analytics.exports.directory'), '/').'/'.$export->public_id.'.csv';
        $stream = null;
        try {
            $period = AnalyticsPeriod::of((string) $filters['from'], (string) $filters['to'], (string) $filters['timezone']);
            $scope = new AnalyticsScope($export->audience, array_map('intval', (array) $filters['market_ids']), $filters['location_ids'] === null ? null : array_map('intval', (array) $filters['location_ids']), (string) $filters['timezone'],
                (string) ($filters['currency'] ?? 'INR'), (array) ($filters['scope'] ?? []), (bool) ($filters['includes_unassigned'] ?? false), (bool) ($filters['finance'] ?? false), (bool) ($filters['support'] ?? false));
            $max = (int) config('analytics.exports.max_rows');
            $stream = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+b');
            // The byte order mark makes a spreadsheet read the file as UTF-8.
            fwrite($stream, "\xEF\xBB\xBF".Csv::line([$this->builder->title($export->report_type, $period, $scope)]).Csv::line($this->builder->header($export->report_type)));
            $rows = 0;
            foreach ($this->builder->rows($export->report_type, $period, $scope) as $row) {
                if (++$rows > $max) {
                    throw new \OverflowException('too_many_rows');
                }
                fwrite($stream, Csv::line($row));
            }
            $size = (int) ftell($stream);
            rewind($stream);
            $disk->put($path, $stream);
            $now = CarbonImmutable::now('UTC');
            $export->forceFill([
                'status' => ReportExport::READY, 'storage_reference' => $path, 'row_count' => $rows, 'size_bytes' => $size, 'completed_at' => $now, 'expires_at' => $now->addHours((int) config('analytics.exports.expiry_hours')),
                'filename' => 'foodonthego-'.str_replace('_', '-', $export->report_type).'-'.$filters['from'].'-to-'.$filters['to'].'.csv',
            ])->save();
            $this->metrics->record('export_ready');
        } catch (\Throwable $e) {
            $disk->delete($path);
            $export->forceFill(['status' => ReportExport::FAILED, 'failure_code' => $e instanceof \OverflowException ? 'too_many_rows' : 'generation_failed', 'completed_at' => CarbonImmutable::now('UTC')])->save();
            $this->metrics->record('export_failed');
            Log::warning('analytics.export_failed', ['export' => $export->public_id, 'report' => $export->report_type, 'error' => $e::class]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * The file, streamed from private storage. The caller has already been checked to be the requester and to hold
     * the permissions still.
     */
    public function download(ReportExport $export, Principal $requester): StreamedResponse
    {
        if ($export->status === ReportExport::READY && $export->isExpired()) {
            $this->expireOne($export);
        }
        if ($export->status === ReportExport::EXPIRED) {
            throw new ApiException(410, 'export_expired', 'This export has expired. Ask for it again.');
        }
        $disk = Storage::disk((string) config('analytics.exports.disk'));
        if ($export->status !== ReportExport::READY || $export->storage_reference === null || ! $disk->exists($export->storage_reference)) {
            throw ApiException::conflict('export_not_ready', 'This export is not ready to download.', ['status' => $export->status]);
        }
        ReportExport::query()->whereKey($export->getKey())->update(['download_count' => DB::raw('download_count + 1'), 'last_downloaded_at' => CarbonImmutable::now('UTC')]);
        $this->audit->record('analytics.export_downloaded', $export, $requester, ['export' => ['from' => null, 'to' => $export->report_type], 'rows' => ['from' => null, 'to' => $export->row_count]], null, $export->market_id);
        $this->metrics->record('export_downloaded');
        $reference = (string) $export->storage_reference;

        return response()->stream(function () use ($disk, $reference): void {
            $stream = $disk->readStream($reference);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $export->filename).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Deletes the files whose time is up and forgets old rows. Returns how many expired.
     */
    public function expire(): int
    {
        $expired = 0;
        foreach (ReportExport::query()->where('status', ReportExport::READY)->where('expires_at', '<', CarbonImmutable::now('UTC'))->limit(500)->get() as $export) {
            $this->expireOne($export);
            $expired++;
        }
        // An export nobody built within a day will not be built: it failed.
        ReportExport::query()->whereIn('status', [ReportExport::QUEUED, ReportExport::PROCESSING])->where('created_at', '<', CarbonImmutable::now('UTC')->subDay())->update(['status' => ReportExport::FAILED, 'failure_code' => 'not_generated']);
        ReportExport::query()->whereIn('status', [ReportExport::EXPIRED, ReportExport::FAILED])->where('created_at', '<', CarbonImmutable::now('UTC')->subDays((int) config('analytics.retention.expired_export_days')))->delete();

        return $expired;
    }

    private function expireOne(ReportExport $export): void
    {
        if ($export->storage_reference !== null) {
            Storage::disk((string) config('analytics.exports.disk'))->delete($export->storage_reference);
        }
        $export->forceFill(['status' => ReportExport::EXPIRED, 'storage_reference' => null])->save();
        $this->metrics->record('export_expired');
    }

    /**
     * What a client may know about an export. Never where the file is.
     *
     * @return array<string, mixed>
     */
    public static function present(ReportExport $export, string $base): array
    {
        $filters = (array) $export->filters;
        $utc = fn ($at): ?string => $at === null ? null : CarbonImmutable::parse((string) $at)->utc()->toIso8601ZuluString();
        $ready = $export->status === ReportExport::READY && ! $export->isExpired();

        return [
            'id' => $export->public_id,
            'report' => $export->report_type,
            'label' => (string) (config('analytics.exports.reports.'.$export->report_type.'.label') ?? $export->report_type),
            'status' => $export->status === ReportExport::READY && $export->isExpired() ? ReportExport::EXPIRED : $export->status,
            'failure_code' => $export->failure_code,
            'period' => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'timezone' => $filters['timezone'] ?? null],
            'scope' => (object) ($filters['scope'] ?? []),
            'format' => 'CSV',
            'row_count' => $export->row_count,
            'size_bytes' => $export->size_bytes,
            'filename' => $ready ? $export->filename : null,
            'download_path' => $ready ? $base.'/'.$export->public_id.'/download' : null,
            'download_count' => (int) $export->download_count,
            'requested_at' => $utc($export->created_at),
            'completed_at' => $utc($export->completed_at),
            'expires_at' => $utc($export->expires_at),
        ];
    }

    public static function belongsTo(ReportExport $export, Principal $requester): bool
    {
        return $requester instanceof AdminUser
            ? $export->audience === ReportExport::ADMIN && (int) $export->requested_by_admin_id === (int) $requester->getKey()
            : $export->audience === ReportExport::RESTAURANT && (int) $export->requested_by_restaurant_user_id === (int) $requester->getKey();
    }
}
