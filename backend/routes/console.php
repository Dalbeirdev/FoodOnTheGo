<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Module 25: recent locations are kept only for the configured retention.
Schedule::command('customer:prune-recent-locations')->daily();

// Module 26: journey contexts expire; expired rows are removed after a grace period.
Schedule::command('journeys:prune')->hourly();
