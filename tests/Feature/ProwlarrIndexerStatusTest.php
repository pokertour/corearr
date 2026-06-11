<?php

use App\Models\ServiceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ServiceSetting::query()->create([
        'service_name' => 'prowlarr',
        'base_url' => 'http://prowlarr.test',
        'api_key' => 'k',
        'is_active' => true,
    ]);

    $this->actingAs(User::factory()->create());
});

test('indexer in failure status is flagged unavailable', function (): void {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/indexerstatus')) {
            return Http::response([
                [
                    'indexerId' => 12,
                    'disabledTill' => '2026-06-12T08:52:52Z',
                    'mostRecentFailure' => '2026-06-11T08:52:52Z',
                    'initialFailure' => '2026-05-19T06:30:58Z',
                ],
            ]);
        }

        if (str_contains($request->url(), '/indexer')) {
            return Http::response([
                ['id' => 12, 'name' => 'Failing Indexer', 'enable' => true, 'priority' => 1],
                ['id' => 34, 'name' => 'Healthy Indexer', 'enable' => true, 'priority' => 2],
            ]);
        }

        return Http::response([], 404);
    });

    $component = Volt::test('prowlarr');

    $failing = collect($component->get('indexers'))->firstWhere('id', 12);
    $healthy = collect($component->get('indexers'))->firstWhere('id', 34);

    expect($component->get('indexerStatuses'))->toHaveKey(12)
        ->and($component->instance()->isIndexerUnavailable($failing))->toBeTrue()
        ->and($component->instance()->getIndexerFailure($failing)['disabledTill'])->toBe('2026-06-12T08:52:52Z')
        ->and($component->instance()->isIndexerUnavailable($healthy))->toBeFalse()
        ->and($component->instance()->getIndexerFailure($healthy))->toBeNull();

    $component->assertSee('Failing Indexer')->assertSee(__('messages.unavailable'));
});

test('no failure statuses leaves all indexers available', function (): void {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/indexerstatus')) {
            return Http::response([]);
        }

        if (str_contains($request->url(), '/indexer')) {
            return Http::response([
                ['id' => 34, 'name' => 'Healthy Indexer', 'enable' => true, 'priority' => 2],
            ]);
        }

        return Http::response([], 404);
    });

    $component = Volt::test('prowlarr');

    $healthy = collect($component->get('indexers'))->firstWhere('id', 34);

    expect($component->get('indexerStatuses'))->toBe([])
        ->and($component->instance()->isIndexerUnavailable($healthy))->toBeFalse();
});
