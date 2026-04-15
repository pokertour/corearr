<?php

use App\Models\User;
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
