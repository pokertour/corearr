<?php

use App\Models\ServiceSetting;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        'corearr:arr_diskspace_panels:v1',
        'corearr:arr_queue_overview:v1',
        'corearr:arr_monitored_summary:v1',
    ] as $key) {
        Cache::forget($key);
    }
});

test('arr diskspace panels aggregate volumes per service', function (): void {
    ServiceSetting::query()->create([
        'service_name' => 'radarr',
        'base_url' => 'http://radarr.test',
        'api_key' => 'k',
        'is_active' => true,
        'username' => null,
        'password' => null,
    ]);

    Http::fake(function ($request) {
        return str_contains($request->url(), '/diskspace')
            ? Http::response([
                ['path' => '/media/a', 'label' => 'Array A', 'freeSpace' => 100 * 1024 ** 3, 'totalSpace' => 400 * 1024 ** 3],
            ])
            : Http::response([], 404);
    });

    $panels = app(MediaStackService::class)->getArrDiskspacePanels();

    expect($panels['radarr'])->toHaveCount(1)
        ->and($panels['radarr'][0]['used_percent'])->toBeGreaterThan(0)
        ->and($panels['sonarr'])->toBe([]);
});

test('arr queue overview counts records and detects warnings', function (): void {
    ServiceSetting::query()->create([
        'service_name' => 'radarr',
        'base_url' => 'http://radarr.test',
        'api_key' => 'k',
        'is_active' => true,
        'username' => null,
        'password' => null,
    ]);

    Http::fake(function ($request) {
        return str_contains($request->url(), '/queue')
            ? Http::response([
                'totalRecords' => 2,
                'records' => [
                    ['movie' => ['title' => 'OK Movie'], 'status' => 'downloading'],
                    ['movie' => ['title' => 'Bad Movie'], 'errorMessage' => 'Disk full'],
                ],
            ])
            : Http::response([], 404);
    });

    $overview = app(MediaStackService::class)->getArrQueueOverview()['radarr'];

    expect($overview['configured'])->toBeTrue()
        ->and($overview['total'])->toBe(2)
        ->and($overview['warnings'])->toBe(1);
});

test('arr monitored summary splits movies', function (): void {
    ServiceSetting::query()->create([
        'service_name' => 'radarr',
        'base_url' => 'http://radarr.test',
        'api_key' => 'k',
        'is_active' => true,
        'username' => null,
        'password' => null,
    ]);

    Http::fake(function ($request) {
        $path = parse_url((string) $request->url(), PHP_URL_PATH) ?: '';

        return str_contains($path, '/api/v3/movie') && ! str_contains($path, '/movie/')
            ? Http::response([
                ['title' => 'A', 'monitored' => true],
                ['title' => 'B', 'monitored' => true],
                ['title' => 'C', 'monitored' => false],
            ])
            : Http::response([], 404);
    });

    $radarr = app(MediaStackService::class)->getArrMonitoredSummary()['radarr'];

    expect($radarr['configured'])->toBeTrue()
        ->and($radarr['monitored'])->toBe(2)
        ->and($radarr['unmonitored'])->toBe(1);
});
