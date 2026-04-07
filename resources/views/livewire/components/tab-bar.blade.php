<?php

use Livewire\Component;

new class extends Component {};

?>

@php
    $items = [
        [
            'route' => 'dashboard',
            'href' => '/dashboard',
            'label' => __('messages.dashboard'),
            'icon' =>
                'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        ],
        [
            'route' => 'torrents',
            'href' => '/torrents',
            'label' => __('messages.torrents'),
            'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
        ],
        [
            'route' => 'media',
            'href' => '/media',
            'label' => __('messages.media'),
            'icon' =>
                'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
        ],
        [
            'route' => 'settings',
            'href' => '/settings',
            'label' => __('messages.settings'),
            'icon' =>
                'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        ],
        [
            'route' => 'profile',
            'href' => '/profile',
            'label' => __('messages.profile'),
            'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        ],
    ];
@endphp

<nav
    class="lg:hidden fixed bottom-0 inset-x-0 backdrop-blur-xl border-t z-50 pb-safe
            bg-white/90 dark:bg-zinc-950/90 border-zinc-200 dark:border-zinc-800/80">
    <div class="flex items-center justify-around h-16">
        @foreach ($items as $item)
            @php $active = request()->routeIs($item['route']); @endphp
            <a href="{{ $item['href'] }}" wire:navigate
                class="flex flex-col items-center justify-center w-full h-full space-y-1 transition-colors
                      {{ $active ? 'text-core-primary' : 'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300' }}">
                @if ($active)
                    <span class="absolute top-0 w-8 h-0.5 bg-core-primary rounded-full"></span>
                @endif
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="{{ $active ? '2.5' : '2' }}"
                        d="{{ $item['icon'] }}" />
                </svg>
                <span class="text-[7px] font-{{ $active ? 'semibold' : 'medium' }}">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>
</nav>

<style>
    .pb-safe {
        padding-bottom: env(safe-area-inset-bottom);
    }
</style>
