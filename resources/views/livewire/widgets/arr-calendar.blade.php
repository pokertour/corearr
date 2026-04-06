<?php

use Livewire\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Lazy] class extends Component {
    public bool $isConfigured = false;
    public array $upcoming = [];

    public function mount()
    {
        $this->isConfigured = ServiceSetting::whereIn('service_name', ['sonarr', 'radarr'])->exists();
    }

    public function loadData(MediaStackService $service)
    {
        $entries = $service->getCalendarEntries();
        $this->upcoming = array_slice($entries, 0, 8);
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="h-96 flex flex-col items-center justify-center bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl animate-pulse">
            <div class="w-10 h-10 bg-zinc-100 dark:bg-zinc-800 rounded-full mb-3"></div>
            <div class="w-1/3 h-4 bg-zinc-100 dark:bg-zinc-800 rounded mb-2"></div>
            <div class="w-1/4 h-3 bg-zinc-100 dark:bg-zinc-800 rounded mb-8"></div>
            <div class="w-2/3 h-12 bg-zinc-50 dark:bg-zinc-800 rounded-xl mb-3"></div>
            <div class="w-2/3 h-12 bg-zinc-50 dark:bg-zinc-800 rounded-xl"></div>
        </div>
        HTML;
    }

    public function formatDate($date)
    {
        return \Carbon\Carbon::parse($date)->translatedFormat('d M');
    }
};

?>

<div wire:init="loadData" class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm h-full overflow-hidden">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-teal-500/10 flex items-center justify-center text-teal-500">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Calendrier</h3>
        </div>
    </div>

    @if(!$isConfigured)
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <p class="text-sm text-zinc-500 mb-4">Connectez Sonarr/Radarr.</p>
            <a href="/settings" wire:navigate class="px-4 py-2 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 text-sm font-medium rounded-lg shadow-sm">Réglages</a>
        </div>
    @elseif(empty($upcoming))
        <div class="flex flex-col items-center justify-center py-16 text-center text-zinc-500">
            <p class="text-sm">Rien de prévu pour le moment.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($upcoming as $item)
                <div class="flex items-center gap-3 group">
                    <div class="flex flex-col items-center justify-center w-12 h-12 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/30">
                        <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-tighter">{{ \Carbon\Carbon::parse($item['airDate'] ?? $item['physicalRelease'] ?? 'now')->translatedFormat('M') }}</span>
                        <span class="text-sm font-black text-zinc-900 dark:text-zinc-100 leading-none">{{ \Carbon\Carbon::parse($item['airDate'] ?? $item['physicalRelease'] ?? 'now')->format('d') }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                         <div class="flex items-center gap-2 mb-0.5">
                             <span class="px-1.5 py-0.5 text-[8px] font-black uppercase rounded {{ $item['_source'] === 'sonarr' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-500' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400' }}">
                                 {{ $item['_source'] }}
                             </span>
                             <p class="text-[13px] font-bold text-zinc-900 dark:text-zinc-100 truncate group-hover:text-core-primary transition cursor-help" title="{{ $item['title'] }}">
                                 @if($item['_source'] === 'sonarr')
                                     <span class="text-zinc-500 font-medium">{{ $item['series']['title'] ?? 'The Rookie' }} -</span>
                                 @endif
                                 {{ $item['title'] }}
                             </p>
                         </div>
                         <p class="text-[10px] text-zinc-500 truncate">
                             @if($item['_source'] === 'sonarr')
                                 S{{ str_pad($item['seasonNumber'], 2, '0', STR_PAD_LEFT) }}E{{ str_pad($item['episodeNumber'], 2, '0', STR_PAD_LEFT) }} &bull; {{ $item['series']['title'] ?? '' }}
                             @else
                                 {{ $item['status'] ?? 'Release' }}
                             @endif
                         </p>
                    </div>
                </div>
            @endforeach
            
            <a href="/media" wire:navigate class="block text-center py-2 text-xs font-semibold text-teal-600 hover:text-teal-700 transition">
                Accéder à la médiathèque &rarr;
            </a>
        </div>
    @endif
</div>
