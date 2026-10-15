<?php

namespace App\Jobs\Analytics;

use App\Models\ReportExport;
use App\Services\Analytics\Reports\ReportExportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds one report export off the request path (Module 38). Safe to run twice: only an export that is still
 * waiting is built. A failure marks the export FAILED — the person asks again; nothing is retried blindly.
 */
final class GenerateReportExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Below the queue's retry_after (90 s, config/queue.php), so a slow export is never handed to a second worker while
     * the first is still building it (Module 40). Exports are row-capped; the medium perf dataset builds in seconds.
     */
    public int $timeout = 80;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue((string) config('analytics.exports.queue'));
        // The export row must be visible to the worker: wait for the transaction that wrote it.
        $this->afterCommit();
    }

    public function handle(ReportExportService $exports): void
    {
        $exports->generate($this->exportId);
    }

    /**
     * The worker gave up on it (a timeout kills the process before the service can record anything): the export is
     * closed as FAILED now, so the person sees it and asks again — instead of a row left PROCESSING for a day.
     */
    public function failed(?\Throwable $e): void
    {
        ReportExport::query()->whereKey($this->exportId)->whereIn('status', [ReportExport::QUEUED, ReportExport::PROCESSING])
            ->update(['status' => ReportExport::FAILED, 'failure_code' => 'generation_timed_out', 'completed_at' => CarbonImmutable::now('UTC')]);
    }
}
