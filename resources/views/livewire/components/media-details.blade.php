<?php

use App\Services\MediaStack\MediaStackService;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public ?array $media = null;

    public string $service = '';

    public bool $isOpen = false;

    public array $qualityProfiles = [];

    public string $activeTab = 'info';

    public bool $loadingDetails = false;
    public bool $interactiveSearchLoading = false;

    protected $listeners = [
        'openMediaDetails' => 'open',
        'interactiveSearchLoaded' => 'stopInteractiveSearchLoading',
    ];

    public function open(array $media, string $service)
    {
        // Clear caches for computed properties to prevent "inheritance" of old data
        unset($this->history);
        unset($this->files);
        unset($this->episodes);

        $this->media = $media;
        $this->service = $service;
        $this->isOpen = true;
        $this->activeTab = 'info';
        $this->loadingDetails = true;

        $this->loadDetails();
    }

    public function loadDetails()
    {
        if (! $this->media) {
            return;
        }

        $this->loadingDetails = true;
        $service = new MediaStackService;

        // Fetch full media object (replaces partial data)
        $fullMedia = $service->getMedia($this->service, $this->media['id']);
        if ($fullMedia) {
            $this->media = $fullMedia;
        }

        $this->qualityProfiles = $service->getQualityProfiles($this->service);

        $this->loadingDetails = false;
    }

    #[Computed]
    public function history(): array
    {
        if ($this->activeTab !== 'history' || ! $this->media) {
            return [];
        }

        return (new MediaStackService)->getHistory($this->service, $this->media['id']);
    }

    #[Computed]
    public function files(): array
    {
        if (! $this->media) {
            return [];
        }

        return (new MediaStackService)->getFiles($this->service, $this->media['id']);
    }

    #[Computed]
    public function episodes(): array
    {
        if ($this->activeTab !== 'seasons' || ! $this->media || $this->service !== 'sonarr') {
            return [];
        }

        return (new MediaStackService)->getEpisodes($this->service, $this->media['id']);
    }

    public function close()
    {
        $this->isOpen = false;
    }

    public function triggerSearch()
    {
        if (! $this->media) {
            return;
        }

        $service = new MediaStackService;
        $commandName = $this->service === 'radarr' ? 'MoviesSearch' : 'SeriesSearch';
        $paramName = $this->service === 'radarr' ? 'movieIds' : 'seriesIds';

        $success = $service->triggerBgCommand($this->service, $commandName, [
            $paramName => [$this->media['id']],
        ]);

        if ($success) {
            $this->dispatch('toast', message: __('messages.media_details_search_sent', ['title' => $this->media['title']]), type: 'success');
            $this->close();
        }
    }

    public function openInteractiveSearchGlobal(): void
    {
        if (! $this->media) {
            return;
        }

        $this->interactiveSearchLoading = true;
        $this->dispatch('openInteractiveSearch',
            mediaId: $this->media['id'],
            mediaTitle: $this->media['title'],
            service: $this->service
        );
    }

    public function openInteractiveSearchSeason(int $seasonNumber): void
    {
        if (! $this->media) {
            return;
        }

        $this->interactiveSearchLoading = true;
        $this->dispatch('openInteractiveSearch',
            mediaId: $this->media['id'],
            mediaTitle: $this->media['title'],
            service: $this->service,
            seasonNumber: $seasonNumber
        );
    }

    public function openInteractiveSearchEpisode(int $episodeId): void
    {
        if (! $this->media) {
            return;
        }

        $this->interactiveSearchLoading = true;
        $this->dispatch('openInteractiveSearch',
            mediaId: $this->media['id'],
            mediaTitle: $this->media['title'],
            service: $this->service,
            episodeId: $episodeId
        );
    }

    public function stopInteractiveSearchLoading(): void
    {
        $this->interactiveSearchLoading = false;
    }

    public function getPoster(string $type = 'poster'): string
    {
        if (! $this->media) {
            return '';
        }
        $path = collect($this->media['images'] ?? [])->firstWhere('coverType', $type)['url'] ?? null;
        if (! $path) {
            $path = "/MediaCover/{$this->media['id']}/$type.jpg";
        }

        return (new MediaStackService)->getPosterUrl($this->service, $path);
    }

    public function getFallbackPoster(string $type = 'poster'): string
    {
        if (! $this->media) {
            return '';
        }

        return collect($this->media['images'] ?? [])->firstWhere('coverType', $type)['remoteUrl'] ?? '';
    }

    public function toggleMonitored()
    {
        if (! $this->media) {
            return;
        }

        $this->media['monitored'] = ! ($this->media['monitored'] ?? false);

        $service = new MediaStackService;
        $service->updateMedia($this->service, $this->media);

        $this->dispatch('media-updated');
        $this->dispatch('toast',
            message: $this->media['monitored'] ? __('messages.media_details_monitored_on') : __('messages.media_details_monitored_off'),
            type: 'success'
        );
    }

    public function deleteMedia(bool $deleteFiles = false)
    {
        if (! $this->media) {
            return;
        }

        $service = new MediaStackService;
        $service->deleteMedia($this->service, $this->media['id'], $deleteFiles);

        $this->isOpen = false;
        $this->dispatch('media-deleted');
        $this->dispatch('toast', message: __('messages.media_details_removed'), type: 'info');
    }

    public function getQualitySummary(): string
    {
        if (! $this->media) {
            return '';
        }

        $files = $this->files();
        if (empty($files)) {
            return '';
        }

        // For series, we pick a file to show representative quality
        $file = $files[0];
        $resolution = $file['mediaInfo']['resolution'] ?? '';
        $codec = $file['mediaInfo']['videoCodec'] ?? '';

        if (! $resolution && ! $codec) {
            return '';
        }

        $resLabel = $resolution;
        if (str_contains($resolution, 'x')) {
            $h = explode('x', $resolution)[1] ?? '';
            $resLabel = $h ? $h.'p' : $resolution;
        }

        return trim($resLabel.($codec ? ' • '.strtoupper($codec) : ''));
    }

    public function getMediaInfo(): array
    {
        $files = $this->files;
        if (empty($files)) {
            return [];
        }

        return $files[0]['mediaInfo'] ?? [];
    }

    public function getQualityProfileName(): string
    {
        if (! $this->media || empty($this->qualityProfiles)) {
            return __('messages.media_details_auto');
        }

        $id = $this->media['qualityProfileId'] ?? null;
        if (! $id) {
            return __('messages.media_details_auto');
        }

        return collect($this->qualityProfiles)->firstWhere('id', $id)['name'] ?? __('messages.media_details_auto');
    }
};

?>

<div x-data="{ open: @entangle('isOpen') }" 
     x-show="open" 
     class="fixed inset-0 z-60 overflow-hidden" 
     style="display: none;">
    
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity" 
         @click="open = false"
         x-show="open"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex">
        <div class="w-screen max-w-lg"
             x-show="open"
             x-transition:enter="transform transition ease-in-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in-out duration-300"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">
            
            <div class="h-full flex flex-col bg-white dark:bg-zinc-950 shadow-2xl overflow-hidden">
                @if($media)
                    <!-- Header with Poster Background -->
                    <div class="relative h-72 shrink-0">
                        <img src="{{ $this->getPoster('fanart') }}" 
                             onerror="this.onerror=null; this.src='{{ $this->getFallbackPoster('fanart') }}';"
                             class="w-full h-full object-cover blur-[2px] opacity-40">
                        <div class="absolute inset-0 bg-linear-to-t from-white dark:from-zinc-950 via-transparent to-transparent"></div>
                        
                        <div class="absolute top-6 right-6 flex gap-2 z-20">
                             <button wire:click="toggleMonitored" class="p-2.5 bg-white/40 dark:bg-black/20 backdrop-blur-md rounded-xl text-zinc-900 dark:text-white hover:bg-white/60 transition group cursor-pointer" title="{{ __('messages.media_details_monitoring') }}">
                                 <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 {{ ($media['monitored'] ?? false) ? 'text-green-500 fill-current' : 'text-zinc-500' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
                             </button>
                             <button x-on:click="if(confirm('{{ __('messages.media_details_confirm_delete') }}')) $wire.deleteMedia(confirm('{{ __('messages.media_details_confirm_delete_files') }}'))" class="p-2.5 bg-white/40 dark:bg-black/20 backdrop-blur-md rounded-xl text-red-500 hover:bg-red-500/20 transition cursor-pointer" title="{{ __('messages.delete') }}">
                                 <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                             </button>
                        </div>

                        <button @click="open = false" class="absolute top-6 left-6 p-2 bg-white/20 hover:bg-white/40 dark:bg-black/20 dark:hover:bg-black/40 backdrop-blur-md rounded-full text-zinc-900 dark:text-white transition z-20 cursor-pointer">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        <div class="absolute bottom-0 left-0 right-0 p-8 flex gap-6 items-end">
                            <div class="w-32 h-48 rounded-xl shadow-2xl overflow-hidden border-2 border-white dark:border-zinc-800 shrink-0">
                                <img src="{{ $this->getPoster('poster') }}" 
                                     onerror="this.onerror=null; this.src='{{ $this->getFallbackPoster('poster') }}';"
                                     class="w-full h-full object-cover">
                            </div>
                            <div class="pb-2">
                                <h2 class="text-2xl font-black text-zinc-900 dark:text-white line-clamp-2 leading-tight">
                                    {{ $media['title'] }}
                                </h2>
                                <div class="flex flex-col gap-1 mt-1">
                                    <div class="flex items-center gap-2">
                                        <p class="text-zinc-500 font-bold text-sm">{{ $media['year'] }}</p>
                                        @php $summary = $this->getQualitySummary(); @endphp
                                        @if($summary)
                                            <span class="px-2 py-0.5 bg-zinc-100 dark:bg-zinc-800 text-[9px] font-black text-zinc-500 dark:text-zinc-400 rounded-md border border-zinc-200 dark:border-zinc-700 uppercase tracking-tighter">
                                                {{ $summary }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 mt-0.5">
                                        @if(!empty($media['ratings']))
                                            @foreach($media['ratings'] as $type => $rating)
                                                @if(($rating['value'] ?? 0) > 0)
                                                    @php 
                                                        $ratingKey = strtolower($type);
                                                        $ratingColor = match($ratingKey) {
                                                            'imdb' => 'text-[#F5C518]',
                                                            'tmdb' => 'text-[#01B4E4]',
                                                            'rottentomatoes' => 'text-[#FA320A]',
                                                            'sonarr' => 'text-core-primary',
                                                            default => 'text-core-primary'
                                                        };
                                                        $label = match($ratingKey) {
                                                            'rottentomatoes' => 'RT',
                                                            'sonarr' => 'Score',
                                                            default => strtoupper($type)
                                                        };
                                                    @endphp
                                                    <div class="flex items-center gap-1">
                                                        <span class="text-[9px] font-black uppercase text-zinc-400">{{ $label }}</span>
                                                        <span class="text-[10px] font-black {{ $ratingColor }}">{{ number_format($rating['value'], 1) }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Tabs -->
                    <div class="px-8 border-b border-zinc-100 dark:border-zinc-900 flex gap-6 overflow-x-auto no-scrollbar">
                        <button wire:click="$set('activeTab', 'info')" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'info', 'border-transparent text-zinc-400': $wire.activeTab !== 'info' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight shrink-0">{{ __('messages.media_details_tab_info') }}</button>
                        @if($service === 'sonarr')
                            <button wire:click="$set('activeTab', 'seasons')" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'seasons', 'border-transparent text-zinc-400': $wire.activeTab !== 'seasons' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight shrink-0">{{ __('messages.media_details_tab_seasons') }}</button>
                        @endif
                        <button wire:click="$set('activeTab', 'files')" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'files', 'border-transparent text-zinc-400': $wire.activeTab !== 'files' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight shrink-0">{{ __('messages.media_details_tab_files') }}</button>
                        <button wire:click="$set('activeTab', 'history')" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'history', 'border-transparent text-zinc-400': $wire.activeTab !== 'history' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight shrink-0">{{ __('messages.media_details_tab_history') }}</button>
                        <button wire:click="$set('activeTab', 'edit')" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'edit', 'border-transparent text-zinc-400': $wire.activeTab !== 'edit' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight shrink-0">{{ __('messages.media_details_tab_edit') }}</button>
                    </div>

                    <!-- Content Area -->
                    <div class="flex-1 px-8 pt-8 pb-32 lg:pb-8 overflow-y-auto relative" x-cloak>
                        <!-- Loading Overlay for Tabs -->
                        <div wire:loading.delay class="absolute inset-0 bg-white/50 dark:bg-zinc-950/50 backdrop-blur-[1px] z-50 flex flex-col items-center justify-center">
                            <div class="flex flex-col items-center space-y-3">
                                <div class="w-8 h-8 border-3 border-core-primary border-t-transparent rounded-full animate-spin"></div>
                                <p class="text-[10px] font-black text-core-primary uppercase tracking-widest animate-pulse">{{ __('messages.loading') }}</p>
                            </div>
                        </div>

                        <!-- Tab: Info -->
                        @if ($activeTab === 'info')
                            <div wire:key="tab-info-{{ $media['id'] }}" class="space-y-6">
                                <div>
                                    <h4 class="text-[10px] font-black text-zinc-400 uppercase tracking-widest mb-2">{{ __('messages.media_details_synopsis') }}</h4>
                                    <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">{{ $media['overview'] ?? __('messages.media_details_no_description') }}</p>
                                </div>

                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 pt-4">
                                    <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800">
                                        <span class="text-[10px] font-bold text-zinc-400 uppercase block mb-1">{{ __('messages.media_details_profile') }}</span>
                                        <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $this->getQualityProfileName() }}</span>
                                    </div>
                                    <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800">
                                        <span class="text-[10px] font-bold text-zinc-400 uppercase block mb-1">Statut</span>
                                        @if($service === 'sonarr')
                                            @php
                                                $regularSeasons = collect($media['seasons'] ?? [])
                                                    ->filter(fn ($season) => (int) ($season['seasonNumber'] ?? -1) > 0)
                                                    ->values();

                                                $totalRegularSeasons = $regularSeasons->count();
                                                $completedRegularSeasons = $regularSeasons->filter(function ($season) {
                                                    $seasonStats = $season['statistics'] ?? [];
                                                    $seasonTotalEpisodes = (int) ($seasonStats['totalEpisodeCount'] ?? 0);
                                                    $seasonDownloadedEpisodes = (int) ($seasonStats['episodeFileCount'] ?? 0);

                                                    return $seasonTotalEpisodes > 0 && $seasonDownloadedEpisodes >= $seasonTotalEpisodes;
                                                })->count();

                                                $stats = $media['statistics'] ?? [];
                                                $downloadedEpisodes = (int) ($stats['episodeFileCount'] ?? 0);
                                                $totalEpisodes = (int) ($stats['episodeCount'] ?? $stats['totalEpisodeCount'] ?? 0);

                                                $isCompleteSeries = $totalRegularSeasons > 0 && $completedRegularSeasons >= $totalRegularSeasons;
                                                $isPartialSeries = ! $isCompleteSeries && ($completedRegularSeasons > 0 || $downloadedEpisodes > 0);
                                                $statusColor = $isCompleteSeries ? 'text-green-500' : ($isPartialSeries ? 'text-yellow-500' : 'text-red-500');
                                                $statusLabel = $isCompleteSeries ? __('messages.media_details_complete') : ($isPartialSeries ? __('messages.media_details_partial') : __('messages.media_details_missing'));
                                            @endphp
                                            <span class="text-sm font-bold {{ $statusColor }}">
                                                {{ $statusLabel }}
                                            </span>
                                            <p class="text-[10px] text-zinc-500 mt-1">
                                                {{ __('messages.media_details_seasons_without_specials', ['done' => $completedRegularSeasons, 'total' => $totalRegularSeasons > 0 ? $totalRegularSeasons : '?']) }}
                                            </p>
                                            <p class="text-[10px] text-zinc-500">
                                                {{ $downloadedEpisodes }} / {{ $totalEpisodes > 0 ? $totalEpisodes : '?' }} épisodes
                                            </p>
                                        @else
                                            <span class="text-sm font-bold {{ ($media['hasFile'] ?? false) ? 'text-green-500' : 'text-yellow-500' }}">
                                                {{ ($media['hasFile'] ?? false) ? __('messages.available') : __('messages.missing') }}
                                            </span>
                                        @endif
                                    </div>
                                    @if($service === 'sonarr')
                                        <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800">
                                            <span class="text-[10px] font-bold text-zinc-400 uppercase block mb-1">{{ __('messages.media_details_tab_seasons') }}</span>
                                            <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ count($media['seasons'] ?? []) }}</span>
                                        </div>
                                    @endif
                                </div>

                                @php $info = $this->getMediaInfo(); @endphp
                                @if(!empty($info))
                                    <div class="space-y-3 pt-2">
                                        <h4 class="text-[10px] font-black text-zinc-400 uppercase tracking-widest">{{ __('messages.media_details_technical_details') }}</h4>
                                        <div class="grid grid-cols-2 gap-y-4 gap-x-8">
                                            <div class="flex flex-col">
                                                <span class="text-[9px] font-bold text-zinc-400 uppercase">{{ __('messages.media_details_resolution') }}</span>
                                                <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ $info['resolution'] ?? __('messages.media_details_na') }}</span>
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="text-[9px] font-bold text-zinc-400 uppercase">{{ __('messages.media_details_video_codec') }}</span>
                                                <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ strtoupper($info['videoCodec'] ?? __('messages.media_details_na')) }} {{ $info['videoBitDepth'] ? $info['videoBitDepth'] . 'bits' : '' }}</span>
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="text-[9px] font-bold text-zinc-400 uppercase">{{ __('messages.media_details_audio_codec') }}</span>
                                                <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ strtoupper($info['audioCodec'] ?? __('messages.media_details_na')) }} ({{ $info['audioChannels'] ?? __('messages.media_details_na') }})</span>
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="text-[9px] font-bold text-zinc-400 uppercase">{{ __('messages.media_details_file_quality') }}</span>
                                                <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ $this->getQualitySummary() ?: __('messages.media_details_na') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if($service !== 'sonarr')
                                    <button wire:click="openInteractiveSearchGlobal"
                                            wire:loading.attr="disabled"
                                            wire:target="openInteractiveSearchGlobal"
                                            @disabled($interactiveSearchLoading)
                                            class="w-full flex items-center justify-center gap-2 py-4 bg-core-primary/10 hover:bg-core-primary/20 text-core-primary rounded-2xl font-black text-sm transition group uppercase tracking-widest border border-core-primary/20 disabled:opacity-50">
                                        <svg wire:loading.remove wire:target="openInteractiveSearchGlobal" class="w-5 h-5 group-hover:animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                        <svg wire:loading wire:target="openInteractiveSearchGlobal" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        @if($interactiveSearchLoading)
                                            <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            <span>{{ __('messages.media_details_search_in_progress') }}</span>
                                        @else
                                            <span wire:loading.remove wire:target="openInteractiveSearchGlobal">{{ __('messages.media_details_interactive_search_global') }}</span>
                                            <span wire:loading wire:target="openInteractiveSearchGlobal">{{ __('messages.media_details_search_in_progress') }}</span>
                                        @endif
                                    </button>
                                @endif
                            </div>
                        @endif

                        <!-- Tab: Saisons -->
                        @if ($service === 'sonarr' && $activeTab === 'seasons')
                            <div wire:key="tab-seasons-{{ $media['id'] }}" class="space-y-4">
                                @php
                                    $groupedEpisodes = collect($this->episodes)->groupBy('seasonNumber');
                                @endphp

                                @forelse(collect($media['seasons'] ?? [])->sortByDesc('seasonNumber') as $season)
                                    {{-- On affiche toutes les saisons, y compris les spéciales si elles existent --}}
                                    
                                    <div x-data="{ expanded: false }" class="bg-zinc-100/50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 rounded-2xl overflow-hidden transition-all">
                                        <div class="flex items-center justify-between p-4">
                                            <div @click="expanded = !expanded" class="flex-1 flex items-center gap-4 cursor-pointer group">
                                                <div class="w-10 h-10 bg-core-primary/10 flex items-center justify-center rounded-xl text-core-primary font-black text-sm group-hover:bg-core-primary group-hover:text-white transition">
                                                    {{ $season['seasonNumber'] ?: 'SP' }}
                                                </div>
                                                <div>
                                                    <p class="text-[13px] font-black text-zinc-900 dark:text-white uppercase tracking-tight">
                                                        {{ $season['seasonNumber'] == 0 ? __('messages.media_details_specials') : __('messages.media_details_season_number', ['number' => $season['seasonNumber']]) }}
                                                    </p>
                                                    <p class="text-[11px] text-zinc-500 font-bold">
                                                        {{ __('messages.media_details_episodes_ratio', ['done' => $season['statistics']['episodeFileCount'] ?? 0, 'total' => $season['statistics']['totalEpisodeCount'] ?? 0]) }}
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button wire:click="openInteractiveSearchSeason({{ $season['seasonNumber'] }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openInteractiveSearchSeason"
                                                        @disabled($interactiveSearchLoading)
                                                        class="p-2 bg-white dark:bg-zinc-800 rounded-xl text-core-primary hover:bg-core-primary hover:text-white transition shadow-sm border border-zinc-200 dark:border-zinc-700 disabled:opacity-50" title="{{ __('messages.media_details_interactive_search_season') }}">
                                                    @if($interactiveSearchLoading)
                                                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                    @else
                                                        <svg wire:loading.remove wire:target="openInteractiveSearchSeason" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                                        <svg wire:loading wire:target="openInteractiveSearchSeason" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                    @endif
                                                </button>
                                                <div class="hidden sm:flex flex-col items-end px-2">
                                                    <div class="w-20 h-1.5 bg-zinc-200 dark:bg-zinc-800 rounded-full overflow-hidden">
                                                        <div class="h-full bg-core-primary transition-all duration-500" style="width: {{ $season['statistics']['percentOfEpisodes'] ?? 0 }}%"></div>
                                                    </div>
                                                </div>
                                                <button @click="expanded = !expanded" class="p-2 text-zinc-400 hover:text-zinc-900 dark:hover:text-white transition">
                                                    <svg class="w-5 h-5 transition-transform duration-300" :class="{ 'rotate-180': expanded }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                </button>
                                            </div>
                                        </div>

                                        <div x-show="expanded" x-collapse>
                                            <div class="px-4 pb-4 space-y-2">
                                                @forelse($groupedEpisodes[$season['seasonNumber']] ?? [] as $episode)
                                                    <div class="flex items-center gap-3 p-3 bg-white dark:bg-zinc-950 border border-zinc-100 dark:border-zinc-800 rounded-xl">
                                                        <span class="text-[10px] font-black text-zinc-400 w-6">{{ $episode['episodeNumber'] }}</span>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-xs font-bold text-zinc-900 dark:text-white truncate">{{ $episode['title'] }}</p>
                                                            <p class="text-[9px] text-zinc-500">{{ ($episode['airDate'] ?? null) ? \Carbon\Carbon::parse($episode['airDate'])->translatedFormat('d M Y') : __('messages.media_details_unknown_date') }}</p>
                                                        </div>
                                                        <div class="w-2 h-2 rounded-full {{ $episode['hasFile'] ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.4)]' : 'bg-yellow-500 shadow-[0_0_8px_rgba(234,179,8,0.4)]' }}"></div>
                                                        <button wire:click="openInteractiveSearchEpisode({{ $episode['id'] }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="openInteractiveSearchEpisode"
                                                                @disabled($interactiveSearchLoading)
                                                                class="p-1.5 text-zinc-400 hover:text-core-primary transition ml-1 disabled:opacity-50" title="{{ __('messages.media_details_interactive_search_episode') }}">
                                                            @if($interactiveSearchLoading)
                                                                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                            @else
                                                                <svg wire:loading.remove wire:target="openInteractiveSearchEpisode" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                                                <svg wire:loading wire:target="openInteractiveSearchEpisode" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                            @endif
                                                        </button>
                                                    </div>
                                                @empty
                                                    <p class="text-center py-4 text-xs text-zinc-500 italic">{{ __('messages.media_details_no_episodes_found') }}</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-center py-12 text-zinc-500 italic text-sm">{{ __('messages.media_details_no_seasons_found') }}</p>
                                @endforelse
                            </div>
                        @endif

                        <!-- Tab: Fichiers -->
                        @if ($activeTab === 'files')
                            <div wire:key="tab-files-{{ $media['id'] }}" class="space-y-4">
                                @forelse($this->files as $file)
                                    <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800">
                                        <div class="flex justify-between items-start gap-4">
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[11px] font-bold text-zinc-900 dark:text-white line-clamp-2 break-all leading-tight">{{ $file['relativePath'] ?? 'Nom inconnu' }}</p>
                                                <div class="flex flex-wrap gap-2 mt-2">
                                                    <span class="text-[9px] font-black bg-zinc-200 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 px-1.5 py-0.5 rounded uppercase">{{ $file['quality']['quality']['name'] ?? __('messages.media_details_na') }}</span>
                                                    @if(isset($file['mediaInfo']['videoCodec']))
                                                        <span class="text-[9px] font-black bg-core-primary/10 text-core-primary px-1.5 py-0.5 rounded uppercase">{{ $file['mediaInfo']['videoCodec'] }}</span>
                                                    @endif
                                                    @if(isset($file['mediaInfo']['resolution']))
                                                        <span class="text-[9px] font-black bg-zinc-100 dark:bg-zinc-800 text-zinc-500 px-1.5 py-0.5 rounded uppercase">{{ $file['mediaInfo']['resolution'] }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <span class="text-[10px] font-black text-zinc-400 block">{{ round(($file['size'] ?? 0) / (1024*1024*1024), 2) }} GB</span>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="flex flex-col items-center justify-center py-12 text-zinc-500">
                                        <svg class="w-12 h-12 mb-4 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                        <p class="italic text-sm">{{ __('messages.media_details_no_files_found') }}</p>
                                    </div>
                                @endforelse
                            </div>
                        @endif

                        <!-- Tab: Historique -->
                        @if ($activeTab === 'history')
                            <div wire:key="tab-history-{{ $media['id'] }}" class="space-y-4">
                                @forelse($this->history as $event)
                                    <div class="flex gap-4 group">
                                        <div class="flex flex-col items-center shrink-0">
                                            <div class="w-1.5 h-1.5 rounded-full mt-1.5 {{ $event['eventType'] === 'grabbed' ? 'bg-core-primary' : 'bg-zinc-300' }}"></div>
                                            <div class="flex-1 w-px bg-zinc-200 dark:bg-zinc-800 group-last:hidden"></div>
                                        </div>
                                        <div class="pb-6">
                                            <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-tighter">{{ \Carbon\Carbon::parse($event['date'])->translatedFormat('d M H:i') }}</p>
                                            <p class="text-sm font-bold text-zinc-900 dark:text-zinc-100 leading-none">{{ ucfirst($event['eventType']) }}</p>
                                            @if(isset($event['sourceTitle']))
                                                <p class="text-[11px] text-zinc-500 mt-1 line-clamp-1 italic">{{ $event['sourceTitle'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-center py-12 text-zinc-500 italic text-sm">{{ __('messages.media_details_empty_history') }}</p>
                                @endforelse
                            </div>
                        @endif

                        <!-- Tab: Modifier -->
                        @if ($activeTab === 'edit')
                            <div wire:key="tab-edit-{{ $media['id'] }}" class="space-y-6">
                                 <div>
                                     <h4 class="text-[10px] font-black text-zinc-400 uppercase tracking-widest mb-2">{{ __('messages.media_details_quality_profile') }}</h4>
                                     <select wire:model="media.qualityProfileId" class="w-full bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-core-primary outline-none transition">
                                         @foreach($qualityProfiles as $profile)
                                             <option value="{{ $profile['id'] }}">{{ $profile['name'] }}</option>
                                         @endforeach
                                     </select>
                                 </div>
                                 
                                 <div class="pt-4">
                                     <button wire:click="toggleMonitored" class="w-full flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 group hover:border-core-primary/50 transition">
                                         <div class="text-left">
                                             <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('messages.media_details_monitoring') }}</span>
                                             <p class="text-[10px] text-zinc-500">{{ __('messages.media_details_monitoring_hint') }}</p>
                                         </div>
                                         <div class="w-10 h-6 {{ ($media['monitored'] ?? false) ? 'bg-core-primary' : 'bg-zinc-300' }} rounded-full relative transition-colors">
                                             <div class="absolute top-1 {{ ($media['monitored'] ?? false) ? 'right-1' : 'left-1' }} w-4 h-4 bg-white rounded-full transition-all"></div>
                                         </div>
                                     </button>
                                 </div>

                                 <div class="pt-8">
                                     <button wire:click="toggleMonitored" class="w-full py-3 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 font-bold rounded-xl hover:opacity-90 transition">
                                         {{ __('messages.media_details_save_changes') }}
                                     </button>
                                 </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
    
    <livewire:components.interactive-search />
</div>
