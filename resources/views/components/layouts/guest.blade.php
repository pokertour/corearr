@inject('mediaStack', 'App\Services\MediaStack\MediaStackService')

@php
    $backdropUrl = $mediaStack->getRandomBackdropUrl();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <title>{{ $title ?? 'Authentification' }} &mdash; CoreArr</title>

    {{-- Thème : appliqué sur <html>, résistant aux swaps wire:navigate (morphdom) --}}
    <script>
        function applyCoreArrTheme() {
            var isDark = localStorage.getItem('corearr-theme') !== 'light';
            document.documentElement.classList.toggle('dark', isDark);
        }
        applyCoreArrTheme();
        document.addEventListener('livewire:navigated', applyCoreArrTheme);
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-layouts.pwa-head />
    @livewireStyles
</head>

<body class="text-core-text font-display bg-core-bg selection:bg-core-primary selection:text-white relative min-h-screen overflow-x-hidden">
    @if ($backdropUrl)
        <div class="fixed inset-0 z-0 overflow-hidden pointer-events-none">
            <img src="{{ $backdropUrl }}" class="w-full h-full object-cover opacity-60 dark:opacity-45" alt="Background Backdrop">
            <div class="absolute inset-0 bg-linear-to-b from-core-bg/20 via-core-bg/70 to-core-bg"></div>
            <div class="absolute inset-0 backdrop-blur-[2px]"></div>
        </div>
    @endif

    <div class="relative z-10">
        {{ $slot }}
    </div>

    @livewireScripts
    <livewire:components.toast />
</body>

</html>
