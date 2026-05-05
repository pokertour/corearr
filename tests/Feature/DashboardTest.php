<?php

use App\Models\ServiceSetting;
use App\Models\User;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('authenticated users can persist dashboard widget preferences', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('dashboard')
        ->call('toggleWidget', 'qbit_downloads_count');

    $user->refresh();
    expect(data_get($user->dashboard_preferences, 'widgets.qbit_downloads_count'))->toBeFalse();

    $component->call('resetDashboardPreferences');

    $user->refresh();
    expect(data_get($user->dashboard_preferences, 'widgets'))->toBe([])
        ->and(data_get($user->dashboard_preferences, 'order'))->toBeArray();
});

test('authenticated users can persist dashboard widget order', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('dashboard')->call('moveWidgetDown', 'qbit_downloads_count');

    $user->refresh();
    $order = data_get($user->dashboard_preferences, 'order', []);

    expect($order[0] ?? null)->toBe('qbit_download_speed')
        ->and($order[1] ?? null)->toBe('qbit_downloads_count');
});

test('dashboard shows an alert when an indexer is unavailable', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ServiceSetting::create([
        'service_name' => 'prowlarr',
        'base_url' => 'http://prowlarr.local',
        'api_key' => 'test-key',
        'is_active' => true,
    ]);

    $this->mock(MediaStackService::class, function ($mock): void {
        $mock->shouldReceive('getArrStats')->once()->andReturn([]);
        $mock->shouldReceive('getIndexers')->once()->andReturn([
            [
                'id' => 1,
                'name' => 'Indexer HS',
                'enable' => true,
                'message' => 'Connection timed out',
            ],
            [
                'id' => 2,
                'name' => 'Indexer OK',
                'enable' => true,
            ],
        ]);
    });

    Livewire::test('dashboard')
        ->assertSee(__('messages.indexer_unavailable_alert_title'))
        ->assertSee('Indexer HS')
        ->assertDontSee('Indexer OK');
});
