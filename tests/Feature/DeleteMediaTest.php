<?php

use App\Jobs\DeleteMediaJob;
use App\Models\ServiceSetting;
use App\Services\MediaStack\JellyseerrService;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('MediaStackService returns false for ID 0 but does not crash', function () {
    $service = new MediaStackService;

    // Create a mock setting
    ServiceSetting::create([
        'service_name' => 'radarr',
        'base_url' => 'http://localhost:7878',
        'api_key' => 'fake-key',
        'is_active' => true,
    ]);

    Log::shouldReceive('warning')->withArgs(fn ($msg) => str_contains($msg, 'invalid ID 0'))->once();

    $result = $service->deleteMedia('radarr', 0);

    expect($result)->toBeFalse();
});

test('DeleteMediaJob skips Arr deletion if ID is 0 and not found by TMDB', function () {
    Http::fake();

    // Mock Services
    $arrService = Mockery::mock(MediaStackService::class);
    $jellyseerr = Mockery::mock(JellyseerrService::class);

    // Should try to find by TMDB
    $arrService->shouldReceive('findMediaByTmdbId')->with('radarr', 12345)->once()->andReturn(null);

    // Should NOT call deleteMedia because ID is 0 and fallback failed
    $arrService->shouldNotReceive('deleteMedia');

    // Should still delete Jellyseerr request
    $jellyseerr->shouldReceive('deleteRequest')->with(1)->once()->andReturn(true);

    Log::shouldReceive('info')->withArgs(fn ($msg) => str_contains($msg, 'Skipping Arr deletion'))->once();

    $job = new DeleteMediaJob(1, 'radarr', 0, 'Test Movie', 12345);
    $job->handle($arrService, $jellyseerr);

    expect(Cache::get('deleting_media_1'))->toBeNull();
});

test('DeleteMediaJob uses fallback ID if external ID is 0', function () {
    Http::fake();

    // Mock Services
    $arrService = Mockery::mock(MediaStackService::class);
    $jellyseerr = Mockery::mock(JellyseerrService::class);

    // Should find by TMDB
    $arrService->shouldReceive('findMediaByTmdbId')->with('radarr', 12345)->once()->andReturn(999);

    // Should call deleteMedia with the found ID
    $arrService->shouldReceive('deleteMedia')->with('radarr', 999, true)->once()->andReturn(true);

    // Should delete Jellyseerr request
    $jellyseerr->shouldReceive('deleteRequest')->with(1)->once()->andReturn(true);

    $job = new DeleteMediaJob(1, 'radarr', 0, 'Test Movie', 12345);
    $job->handle($arrService, $jellyseerr);
});
