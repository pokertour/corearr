<?php

use Livewire\Component;

new class extends Component {};

?>

@php
    $mainItems = [
        [
            'route' => 'dashboard',
            'href' => '/dashboard',
            'label' => __('messages.dashboard'),
            'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
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
            'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
        ]
    ];

    $secondaryItems = [
        ['route' => 'cleanup', 'href' => '/cleanup', 'label' => __('messages.cleanup'), 'icon' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16'],
        ['route' => 'prowlarr', 'href' => '/prowlarr', 'label' => __('messages.indexers'), 'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
        ['route' => 'settings', 'href' => '/settings', 'label' => __('messages.settings'), 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
        ['route' => 'profile', 'href' => '/profile', 'label' => __('messages.profile'), 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
    ];

    $isSecondaryActive = collect($secondaryItems)->contains(fn($item) => request()->routeIs($item['route']));
@endphp

<nav x-data="{ menuOpen: false }"
    class="lg:hidden fixed bottom-0 inset-x-0 backdrop-blur-xl border-t z-50 pb-safe
            bg-white/90 dark:bg-zinc-950/90 border-zinc-200 dark:border-zinc-800/80">
    
    <!-- Secondary Menu Popup -->
    <div x-show="menuOpen" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         @click.away="menuOpen = false"
         class="absolute bottom-20 right-4 w-48 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xl overflow-hidden py-2" style="display: none;">
        
        @foreach($secondaryItems as $item)
            @php $active = request()->routeIs($item['route']); @endphp
            <a href="{{ $item['href'] }}" wire:navigate @click="menuOpen = false"
               class="flex items-center gap-3 px-4 py-3 {{ $active ? 'bg-core-primary/10 text-core-primary' : 'text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800' }} transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="{{ $active ? '2.5' : '2' }}" d="{{ $item['icon'] }}" />
                </svg>
                <span class="text-sm font-semibold">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>

    <!-- Main Tabbar -->
    <div class="flex items-center justify-around h-16 px-2">
        @foreach ($mainItems as $item)
            @php $active = request()->routeIs($item['route']); @endphp
            <a href="{{ $item['href'] }}" wire:navigate
                class="flex flex-col items-center justify-center w-full h-full space-y-1 transition-colors relative
                      {{ $active ? 'text-core-primary' : 'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300' }}">
                @if ($active)
                    <span class="absolute top-0 w-8 h-0.5 bg-core-primary rounded-full"></span>
                @endif
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="{{ $active ? '2.5' : '2' }}"
                        d="{{ $item['icon'] }}" />
                </svg>
                <span class="text-[9px] font-{{ $active ? 'bold' : 'medium' }}">{{ $item['label'] }}</span>
            </a>
        @endforeach

        <!-- More Button -->
        <button @click="menuOpen = !menuOpen"
                class="flex flex-col items-center justify-center w-full h-full space-y-1 transition-colors relative
                      {{ $isSecondaryActive ? 'text-core-primary' : 'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300' }}">
            @if ($isSecondaryActive)
                <span class="absolute top-0 w-8 h-0.5 bg-core-primary rounded-full"></span>
            @endif
            <svg class="w-6 h-6 transition-transform duration-200" :class="menuOpen ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="{{ $isSecondaryActive ? '2.5' : '2' }}" d="M5 12h.01M12 12h.01M19 12h.01" />
            </svg>
            <span class="text-[9px] font-{{ $isSecondaryActive ? 'bold' : 'medium' }}">{{ __('messages.more') }}</span>
        </button>
    </div>
</nav>

<style>
    .pb-safe {
        padding-bottom: env(safe-area-inset-bottom);
    }
</style>
