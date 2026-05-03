<?php

namespace Database\Factories;

use App\Models\QbittorrentSpeedSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QbittorrentSpeedSample>
 */
class QbittorrentSpeedSampleFactory extends Factory
{
    protected $model = QbittorrentSpeedSample::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sampled_at' => now(),
            'download_speed' => fake()->numberBetween(0, 50_000_000),
            'upload_speed' => fake()->numberBetween(0, 20_000_000),
        ];
    }
}
