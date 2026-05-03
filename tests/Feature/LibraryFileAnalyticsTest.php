<?php

use App\Models\ServiceSetting;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget('corearr:library_file_analytics:v1');
});

test('library analytics aggregates codecs and qualities from radarr movie files', function (): void {
    ServiceSetting::query()->create([
        'service_name' => 'radarr',
        'base_url' => 'http://radarr.test',
        'api_key' => 'secret-key',
        'is_active' => true,
        'username' => null,
        'password' => null,
    ]);

    Http::fake(function ($request) {
        if (! str_contains($request->url(), 'radarr.test')) {
            return Http::response([], 404);
        }

        if (str_contains($request->url(), '/api/v3/movieFile')) {
            return Http::response([
                [
                    'id' => 1,
                    'mediaInfo' => ['videoCodec' => 'HEVC'],
                    'quality' => ['quality' => ['name' => 'Bluray-2160p']],
                ],
                [
                    'id' => 2,
                    'mediaInfo' => ['videoCodec' => 'x264'],
                    'quality' => ['quality' => ['name' => 'WEBRip-720p']],
                ],
                [
                    'id' => 3,
                    'mediaInfo' => ['videoCodec' => ''],
                    'quality' => ['quality' => ['name' => 'WEBRip-720p']],
                ],
                [
                    'id' => 4,
                    'mediaInfo' => ['videoCodec' => 'VP9'],
                    'quality' => ['quality' => ['name' => '']],
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $data = app(MediaStackService::class)->getLibraryFileAnalytics();

    expect($data['configured'])->toBeTrue()
        ->and($data['total_files'])->toBe(4)
        ->and($data['codec']['hevc'])->toBe(1)
        ->and($data['codec']['h264'])->toBe(1)
        ->and($data['codec']['unknown'])->toBe(1)
        ->and($data['codec']['other'])->toBe(1);

    $labels = array_column($data['quality_rows'], 'label');

    expect($labels)->toContain('Bluray-2160p')
        ->and($labels)->toContain('WEBRip-720p')
        ->and($labels)->toContain(__('messages.dashboard_lib_quality_unknown'));
});

test('falls back to per-movie movieFile when bulk movieFile is empty', function (): void {
    ServiceSetting::query()->create([
        'service_name' => 'radarr',
        'base_url' => 'http://radarr.test',
        'api_key' => 'secret-key',
        'is_active' => true,
        'username' => null,
        'password' => null,
    ]);

    Http::fake(function ($request) {
        if (! str_contains($request->url(), 'radarr.test')) {
            return Http::response([], 404);
        }

        if (str_contains($request->url(), '/api/v3/movieFile')) {
            return Http::response([]);
        }

        if (str_contains($request->url(), '/api/v3/movie')) {
            return Http::response([
                [
                    'id' => 9,
                    'movieFile' => [
                        'id' => 99,
                        'mediaInfo' => ['videoCodec' => 'h264'],
                        'quality' => ['quality' => ['name' => 'HDTV-1080p']],
                    ],
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $data = app(MediaStackService::class)->getLibraryFileAnalytics();

    expect($data['total_files'])->toBe(1)
        ->and($data['codec']['h264'])->toBe(1);
});

test('library codec widget bucket labels honor app locale', function (): void {
    app()->setLocale('fr');

    Livewire::test('widgets.library-codec-chart')
        ->assertSuccessful()
        ->tap(function ($component): void {
            expect($component->instance()->codecBucketLabel('hevc'))->toBe(__('messages.dashboard_lib_codec_hevc'));
        });
});

test('library codec row percent string is defined for en and fr', function (): void {
    app()->setLocale('en');
    expect(__('messages.dashboard_lib_codec_row_pct', ['count' => 2, 'pct' => '50.0']))->toBe('2 (50.0%)');

    app()->setLocale('fr');
    expect(__('messages.dashboard_lib_codec_row_pct', ['count' => 2, 'pct' => '50.0']))->toBe('2 (50.0 %)');
});
