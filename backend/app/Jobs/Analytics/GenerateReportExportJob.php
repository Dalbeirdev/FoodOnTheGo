<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\Reports\ReportExportService;
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

    public int $timeout = 300;

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
}
