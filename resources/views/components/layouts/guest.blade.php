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
<body class="text-core-text font-display bg-core-bg selection:bg-core-primary selection:text-white">
    {{ $slot }}
    @livewireScripts
    <livewire:components.toast />
</body>
</html>
