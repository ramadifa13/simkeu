<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loans:refresh-penalties')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('wa:remind-due')->dailyAt('08:00')->withoutOverlapping();
