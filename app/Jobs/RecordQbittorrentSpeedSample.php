<?php

namespace App\Jobs;

use App\Models\QbittorrentSpeedSample;
use App\Models\ServiceSetting;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQbittorrentSpeedSample implements ShouldQueue
{
    use Queueable;

    public function handle(MediaStackService $mediaStack): void
    {
        $retentionHours = max(
            1,
            min(168, (int) config('corearr.qbittorrent_speed_sample_retention_hours', 24))
        );

        $qbittorrentActive = ServiceSetting::query()
            ->where('service_name', 'qbittorrent')
            ->where('is_active', true)
            ->whereNotNull('base_url')
            ->exists();

        if ($qbittorrentActive) {
            $info = $mediaStack->getQbitTransferInfo();
            if ($info !== []) {
                QbittorrentSpeedSample::query()->create([
                    'sampled_at' => now(),
                    'download_speed' => max(0, (int) ($info['dl_info_speed'] ?? 0)),
                    'upload_speed' => max(0, (int) ($info['up_info_speed'] ?? 0)),
                ]);
            }
        }

        QbittorrentSpeedSample::expiredBeforeRetention($retentionHours)->delete();
    }
}
