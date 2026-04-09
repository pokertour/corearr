<?php

use Livewire\Volt\Component;
use App\Services\MediaStack\JellyseerrService;

new class extends Component {
    public array $stats = [];
    public bool $isConfigured = false;

    public function mount(JellyseerrService $jellyseerr)
    {
        $this->isConfigured = $jellyseerr->isConfigured();
        
        if ($this->isConfigured) {
            $this->stats = $jellyseerr->getRequestCounts();
        }
    }
};
?>

@if($isConfigured && !empty($stats))
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <!-- Total Requests -->
    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-core-primary/10 text-core-primary flex items-center justify-center">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-widest font-bold text-zinc-500">Demandes Totales</p>
            <p class="text-2xl font-black text-zinc-900 dark:text-white">{{ $stats['total'] ?? 0 }}</p>
        </div>
    </div>

    <!-- Films Requests -->
    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-500 flex items-center justify-center">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z" />
            </svg>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-widest font-bold text-zinc-500">Films</p>
            <p class="text-2xl font-black text-zinc-900 dark:text-white">{{ $stats['movie'] ?? 0 }}</p>
        </div>
    </div>

    <!-- Series Requests -->
    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-500 flex items-center justify-center">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-widest font-bold text-zinc-500">Séries</p>
            <p class="text-2xl font-black text-zinc-900 dark:text-white">{{ $stats['tv'] ?? 0 }}</p>
        </div>
    </div>

    <!-- Available -->
    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-4 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-green-500/10 text-green-500 flex items-center justify-center">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-widest font-bold text-zinc-500">Disponibles</p>
            <p class="text-2xl font-black text-zinc-900 dark:text-white">{{ $stats['available'] ?? 0 }}</p>
        </div>
    </div>
</div>
@endif
