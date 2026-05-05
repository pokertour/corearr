<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('torrents component can sort by status eta and tracker', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('torrents')
        ->set('isConfigured', true)
        ->set('torrents', [
            [
                'name' => 'Torrent C',
                'state' => 'uploading',
                'eta' => 300,
                'tracker' => 'https://ztracker.example/announce',
                'category' => '',
                'size' => 0,
                'progress' => 0,
                'dlspeed' => 0,
                'upspeed' => 0,
                'ratio' => 0,
                'added_on' => 0,
                'hash' => 'hash-c',
            ],
            [
                'name' => 'Torrent A',
                'state' => 'pausedDL',
                'eta' => 120,
                'tracker' => 'https://atracker.example/announce',
                'category' => '',
                'size' => 0,
                'progress' => 0,
                'dlspeed' => 0,
                'upspeed' => 0,
                'ratio' => 0,
                'added_on' => 0,
                'hash' => 'hash-a',
            ],
            [
                'name' => 'Torrent B',
                'state' => 'downloading',
                'eta' => 240,
                'tracker' => 'https://mtracker.example/announce',
                'category' => '',
                'size' => 0,
                'progress' => 0,
                'dlspeed' => 0,
                'upspeed' => 0,
                'ratio' => 0,
                'added_on' => 0,
                'hash' => 'hash-b',
            ],
        ]);

    $component->set('sortBy', 'state')->set('sortDir', 'asc');
    $sortedByState = collect($component->get('filteredTorrents')->items())->pluck('name')->all();
    expect($sortedByState)->toBe(['Torrent B', 'Torrent A', 'Torrent C']);

    $component->set('sortBy', 'eta')->set('sortDir', 'asc');
    $sortedByEta = collect($component->get('filteredTorrents')->items())->pluck('name')->all();
    expect($sortedByEta)->toBe(['Torrent A', 'Torrent B', 'Torrent C']);

    $component->set('sortBy', 'tracker')->set('sortDir', 'asc');
    $sortedByTracker = collect($component->get('filteredTorrents')->items())->pluck('name')->all();
    expect($sortedByTracker)->toBe(['Torrent A', 'Torrent B', 'Torrent C']);
});
