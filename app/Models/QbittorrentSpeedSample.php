<?php

namespace App\Models;

use Database\Factories\QbittorrentSpeedSampleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QbittorrentSpeedSample extends Model
{
    /** @use HasFactory<QbittorrentSpeedSampleFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'sampled_at',
        'download_speed',
        'upload_speed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'download_speed' => 'integer',
            'upload_speed' => 'integer',
        ];
    }

    /**
     * Points for dashboard graph: last N hours ordered by time.
     *
     * @return array<int, array{ts: int, dl: int, ul: int}>
     */
    public static function chartSamplesWithinHours(int $hours): array
    {
        return self::query()
            ->where('sampled_at', '>=', now()->subHours($hours))
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'download_speed', 'upload_speed'])
            ->map(fn (self $row) => [
                'ts' => $row->sampled_at->getTimestamp(),
                'dl' => $row->download_speed,
                'ul' => $row->upload_speed,
            ])
            ->all();
    }

    /**
     * @return Builder<static>
     */
    public static function expiredBeforeRetention(int $hours): Builder
    {
        return static::query()->where(
            'sampled_at',
            '<',
            now()->subHours($hours)
        );
    }
}
