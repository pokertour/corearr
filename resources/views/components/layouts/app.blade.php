<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <title>{{ __($title ?? 'messages.dashboard') }} &mdash; CoreArr</title>

    {{-- Thème : appliqué sur <html>, résistant aux swaps wire:navigate (morphdom) --}}
    <script>
        function applyCoreArrTheme() {
            var isDark = localStorage.getItem('corearr-theme') !== 'light';
            document.documentElement.classList.toggle('dark', isDark);
        }

        // Application initiale (avant le premier rendu CSS)
        applyCoreArrTheme();

        // ⚠️ Morphdom écrase les classes de <html> après chaque swap wire:navigate.
        // livewire:navigated se déclenche APRÈS le swap → on ré-applique dark ici.
        document.addEventListener('livewire:navigated', applyCoreArrTheme);

        // Expose toggleTheme pour le bouton header
        window.toggleCoreArrTheme = function() {
            var isDark = !document.documentElement.classList.contains('dark');
            document.documentElement.classList.toggle('dark', isDark);
            localStorage.setItem('corearr-theme', isDark ? 'dark' : 'light');
        };
    </script>

    <!-- Tailwind v4 & Vite -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- PWA & Mobile Web App Meta -->
    <x-layouts.pwa-head />

    @livewireStyles
</head>
<body class="text-core-text font-display flex h-screen bg-core-bg overflow-hidden selection:bg-core-primary selection:text-white">

    <!-- Desktop Sidebar -->
    <livewire:components.sidebar />

    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden lg:rounded-l-2xl lg:border-l border-zinc-200 dark:border-zinc-800/40 bg-core-bg dark:bg-zinc-950 dark:lg:bg-zinc-900 transition-colors relative z-0">

        <!-- Header -->
        <header class="h-16 px-6 flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800/50">
            <h1 class="text-xl font-bold bg-linear-to-r from-core-primary to-core-secondary bg-clip-text text-transparent">
                {{ __($title ?? 'CoreArr') }}
            </h1>
            <div class="flex items-center gap-4">
                <button onclick="toggleCoreArrTheme()" class="cursor-pointer p-2 rounded-full hover:bg-zinc-800 text-zinc-400 hover:text-white transition outline-none"
                        x-data="{ get isDark() { return document.documentElement.classList.contains('dark'); } }">
                    <svg x-show="!isDark" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                    <svg x-show="isDark" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </button>
            </div>
        </header>

        <!-- Dynamic Content -->
        <div class="flex-1 overflow-y-auto w-full pb-20 lg:pb-0 scroll-smooth">
            <div class="max-w-[1800px] mx-auto p-4 lg:p-8">
                {{ $slot }}
            </div>
        </div>

    </main>

    <!-- Mobile Tab Bar -->
    <livewire:components.tab-bar />

    @livewireScripts
    <livewire:components.toast />
</body>
</html>
