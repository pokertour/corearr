<?php

use App\Jobs\RecordQbittorrentSpeedSample;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$qbitSampleEvery = max(1, min(60, (int) config('corearr.qbittorrent_speed_sample_interval_minutes', 5)));
$qbitSampleCron = $qbitSampleEvery === 60 ? '0 * * * *' : '*/'.$qbitSampleEvery.' * * * *';

Schedule::call(static function (): void {
    dispatch_sync(new RecordQbittorrentSpeedSample);
})
    ->name('record-qbittorrent-speed-sample')
    ->cron($qbitSampleCron)
    ->withoutOverlapping(15);
