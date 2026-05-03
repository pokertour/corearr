<?php

use App\Jobs\RecordQbittorrentSpeedSample;
use App\Models\QbittorrentSpeedSample;
use App\Models\ServiceSetting;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('job skips fetching when qbittorrent is not configured', function () {
    $mediaStack = Mockery::mock(MediaStackService::class);
    $mediaStack->shouldNotReceive('getQbitTransferInfo');

    (new RecordQbittorrentSpeedSample)->handle($mediaStack);
});

test('job records speeds and deletes samples older than retention', function () {
    ServiceSetting::query()->create([
        'service_name' => 'qbittorrent',
        'base_url' => 'http://127.0.0.1:9090',
        'api_key' => null,
        'username' => 'u',
        'password' => 'p',
        'is_active' => true,
    ]);

    QbittorrentSpeedSample::query()->insert([
        'sampled_at' => now()->subHours(48),
        'download_speed' => 1,
        'upload_speed' => 2,
    ]);

    $mediaStack = Mockery::mock(MediaStackService::class);
    $mediaStack->shouldReceive('getQbitTransferInfo')->once()->andReturn([
        'dl_info_speed' => 12_288,
        'up_info_speed' => 6144,
    ]);

    (new RecordQbittorrentSpeedSample)->handle($mediaStack);

    $rows = QbittorrentSpeedSample::query()->orderBy('sampled_at')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->download_speed)->toBe(12288)
        ->and($rows->first()->upload_speed)->toBe(6144);
});

test('dashboard chart samples respects retention window ordering', function () {
    QbittorrentSpeedSample::factory()->create([
        'sampled_at' => now()->subMinutes(90),
        'download_speed' => 100,
        'upload_speed' => 50,
    ]);
    QbittorrentSpeedSample::factory()->create([
        'sampled_at' => now()->subMinutes(30),
        'download_speed' => 200,
        'upload_speed' => 100,
    ]);

    $samples = QbittorrentSpeedSample::chartSamplesWithinHours(1);

    expect($samples)->toHaveCount(1)
        ->and($samples[0]['dl'])->toBe(200);
});
