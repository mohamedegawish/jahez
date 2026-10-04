<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove API tokens that expired more than a day ago (ADR-003). Needs the scheduler running.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Ask the payment gateway about payments still pending after 15 minutes, in case a
// callback was lost (ADR-017). Does nothing while no gateway is configured (OQ-16).
Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping();
