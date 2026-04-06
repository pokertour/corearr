<?php

use Livewire\Component;

new class extends Component {};

?>

@php
    $links = [
        ['route' => 'dashboard', 'href' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['route' => 'torrents', 'href' => '/torrents', 'label' => 'Torrents', 'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4'],
        ['route' => 'media', 'href' => '/media', 'label' => 'Médias', 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
        ['route' => 'prowlarr', 'href' => '/prowlarr', 'label' => 'Indexeurs', 'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
        ['route' => 'settings', 'href' => '/settings', 'label' => 'Paramètres', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    ];
@endphp

<aside class="hidden lg:flex flex-col w-64 h-full border-r border-zinc-200 dark:border-zinc-800/50 py-6 px-4"
       style="background: var(--sidebar-bg);">

    <!-- Logo area -->
    <div class="flex items-center gap-3 px-2 mb-10">
        <div class="w-10 h-10 flex items-center justify-center">
            <img src="/assets/logo/logo.svg" alt="CoreArr Logo" class="w-10 h-10 drop-shadow-sm" />
        </div>
        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white">CoreArr</span>
    </div>

    <!-- Navigation List -->
    <nav class="flex-1 space-y-1">
        @foreach($links as $link)
            @php $active = request()->routeIs($link['route']); @endphp
            <a href="{{ $link['href'] }}" wire:navigate
               class="flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium transition-all duration-150
                      {{ $active
                          ? 'bg-core-primary/10 text-core-primary dark:bg-core-primary/20 dark:text-core-primary'
                          : 'text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800/60 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
                @if($active)
                    <span class="absolute left-0 w-1 h-6 bg-core-primary rounded-r-full"></span>
                @endif
                <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $link['icon'] }}"/>
                </svg>
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    <!-- User Footer -->
    @php $profileActive = request()->routeIs('profile'); @endphp
    <div class="mt-auto pt-6 border-t border-zinc-200 dark:border-zinc-800/50">
        <a href="/profile" wire:navigate
           class="flex items-center gap-3 px-2 py-2 rounded-xl transition-all
                  {{ $profileActive ? 'bg-core-primary/10' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800/60' }}">
            <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0
                        {{ $profileActive ? 'bg-core-primary text-white' : 'bg-zinc-200 dark:bg-zinc-800' }}">
                <svg class="w-5 h-5 {{ $profileActive ? 'text-white' : 'text-zinc-500' }}" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="flex-1 overflow-hidden">
                <p class="text-sm font-medium text-zinc-900 dark:text-zinc-200 truncate">{{ auth()->user()->name ?? 'Admin' }}</p>
                <p class="text-xs {{ $profileActive ? 'text-core-primary' : 'text-zinc-500' }} truncate">Gérer le profil</p>
            </div>
        </a>

        @php $aboutActive = request()->routeIs('about'); @endphp
        <a href="/about" wire:navigate
           class="flex items-center gap-3 px-3 py-2 mt-2 rounded-xl text-[10px] uppercase font-black tracking-widest transition-all
                  {{ $aboutActive ? 'text-core-primary bg-core-primary/5' : 'text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            About
        </a>
    </div>
</aside>
