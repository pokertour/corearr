<?php

use App\Models\ServiceSetting;
use App\Services\MediaStack\MediaStackService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

new #[Layout('components.layouts.app')] #[Title('messages.media')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $viewMode = 'grid';

    #[Url]
    public string $sortBy = 'added_desc';

    #[Url]
    public string $filterMonitored = 'all';

    #[Url]
    public string $filterStatus = 'all';

    #[Url]
    public string $filterGenre = 'all';

    #[Url]
    public string $filterQuality = 'all';

    public array $movies = [];

    public array $series = [];

    public string $activeTab = 'movies';

    public int $perPage = 18;

    public bool $radarrConfigured = false;

    public bool $sonarrConfigured = false;

    public string $searchQuery = '';

    public array $searchResults = [];

    public bool $isSearching = false;

    public array $availableGenres = [];

    public array $qualityProfiles = [];

    public function mount(MediaStackService $service)
    {
        // Restore from session
        $this->sortBy = session('media_sortBy', 'added_desc');
        $this->filterMonitored = session('media_filterMonitored', 'all');
        $this->filterStatus = session('media_filterStatus', 'all');
        $this->filterGenre = session('media_filterGenre', 'all');
        $this->filterQuality = session('media_filterQuality', 'all');

        $this->radarrConfigured = ServiceSetting::where('service_name', 'radarr')->where('is_active', true)->exists();
        $this->sonarrConfigured = ServiceSetting::where('service_name', 'sonarr')->where('is_active', true)->exists();

        $this->loadMedia($service);
    }

    public function updated($name, $value)
    {
        if (in_array($name, ['sortBy', 'filterMonitored', 'filterStatus', 'filterGenre', 'filterQuality'])) {
            session(['media_'.$name => $value]);
            $this->resetPage();
        }
    }

    public function loadMedia(MediaStackService $service)
    {
        if ($this->radarrConfigured) {
            $this->movies = $this->fetchFromArr('radarr', '/api/v3/movie');
            $this->qualityProfiles['radarr'] = $service->getQualityProfiles('radarr');
        }
        if ($this->sonarrConfigured) {
            $this->series = $this->fetchFromArr('sonarr', '/api/v3/series');
            $this->qualityProfiles['sonarr'] = $service->getQualityProfiles('sonarr');
        }

        $this->updateAvailableGenres();
    }

    private function updateAvailableGenres()
    {
        $all = $this->activeTab === 'movies' ? $this->movies : $this->series;
        $this->availableGenres = collect($all)
            ->flatMap(fn ($item) => $item['genres'] ?? [])
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    private function fetchFromArr(string $service, string $endpoint): array
    {
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
            return [];
        }

        try {
            $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])->get(rtrim($settings->base_url, '/').$endpoint);

            if (! $response->successful()) {
                return [];
            }

            // Prevent PayloadTooLargeException: Only keep essential info for the list/grid
            return collect($response->json())
                ->map(function ($item) {
                    $statistics = $item['statistics'] ?? [];
                    $episodeCount = (int) ($statistics['episodeCount'] ?? $statistics['totalEpisodeCount'] ?? 0);
                    $episodeFileCount = (int) ($statistics['episodeFileCount'] ?? 0);
                    $percentOfEpisodes = (float) ($statistics['percentOfEpisodes'] ?? 0);
                    $sizeOnDisk = (int) ($statistics['sizeOnDisk'] ?? 0);

                    $hasSeriesFiles = $episodeFileCount > 0
                        && ($episodeCount === 0 || $episodeFileCount >= $episodeCount || $percentOfEpisodes >= 99.9);

                    $hasFile = $item['hasFile']
                        ?? ($sizeOnDisk > 0 || $hasSeriesFiles);

                    return [
                        'id' => $item['id'],
                        'title' => $item['title'],
                        'year' => $item['year'] ?? ($item['firstAired'] ? Carbon::parse($item['firstAired'])->year : ''),
                        'images' => $item['images'] ?? [],
                        'monitored' => $item['monitored'] ?? false,
                        'hasFile' => (bool) $hasFile,
                        'qualityProfileId' => $item['qualityProfileId'] ?? 0,
                        'studio' => $item['studio'] ?? ($item['network'] ?? ''),
                        'overview' => $item['overview'] ?? '',
                        'added' => $item['added'] ?? '',
                        'genres' => $item['genres'] ?? [],
                    ];
                })
                ->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    public function getFilteredMedia(): array
    {
        $data = $this->activeTab === 'movies' ? $this->movies : $this->series;

        return collect($data)
            ->filter(function ($item) {
                // Search (Internal)
                if ($this->searchQuery && ! str_contains(strtolower($item['title']), strtolower($this->searchQuery))) {
                    return false;
                }

                // Monitored
                if ($this->filterMonitored !== 'all') {
                    $isMonitored = $this->filterMonitored === 'monitored';
                    if ($item['monitored'] !== $isMonitored) {
                        return false;
                    }
                }

                // Status
                if ($this->filterStatus !== 'all') {
                    $hasFile = $this->filterStatus === 'available';
                    if ($item['hasFile'] !== $hasFile) {
                        return false;
                    }
                }

                // Genre
                if ($this->filterGenre !== 'all') {
                    if (! in_array($this->filterGenre, $item['genres'] ?? [])) {
                        return false;
                    }
                }

                // Quality
                if ($this->filterQuality !== 'all') {
                    if ($item['qualityProfileId'] != $this->filterQuality) {
                        return false;
                    }
                }

                return true;
            })
            ->sort(function ($a, $b) {
                switch ($this->sortBy) {
                    case 'title_asc':
                        return strcmp($a['title'], $b['title']);
                    case 'title_desc':
                        return strcmp($b['title'], $a['title']);
                    case 'year_asc':
                        return ($a['year'] <=> $b['year']) ?: strcmp($a['title'], $b['title']);
                    case 'year_desc':
                        return ($b['year'] <=> $a['year']) ?: strcmp($a['title'], $b['title']);
                    case 'added_asc':
                        return strcmp($a['added'], $b['added']);
                    case 'added_desc':
                    default:
                        return strcmp($b['added'] ?? '', $a['added'] ?? '');
                }
            })
            ->values()
            ->toArray();
    }

    public function updatedActiveTab()
    {
        $this->updateAvailableGenres();
        $this->filterGenre = 'all';
        $this->filterQuality = 'all';
        $this->resetPage();
    }

    public function clearFilters()
    {
        $this->sortBy = 'added_desc';
        $this->filterMonitored = 'all';
        $this->filterStatus = 'all';
        $this->filterGenre = 'all';
        $this->filterQuality = 'all';
        $this->searchQuery = '';

        session()->forget(['media_sortBy', 'media_filterMonitored', 'media_filterStatus', 'media_filterGenre', 'media_filterQuality']);
        $this->resetPage();
    }

    public function toggleView()
    {
        $this->viewMode = $this->viewMode === 'grid' ? 'list' : 'grid';
    }

    public function getPaginatedMediaProperty(): LengthAwarePaginator
    {
        $filtered = collect($this->getFilteredMedia());
        $page = $this->getPage();

        return new LengthAwarePaginator(
            $filtered->forPage($page, $this->perPage)->values(),
            $filtered->count(),
            $this->perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    public function search()
    {
        if (strlen($this->searchQuery) < 3) {
            $this->searchResults = [];

            return;
        }

        $this->isSearching = true;
        $service = $this->activeTab === 'movies' ? 'radarr' : 'sonarr';
        $endpoint = $this->activeTab === 'movies' ? '/api/v3/movie/lookup' : '/api/v3/series/lookup';

        $settings = ServiceSetting::where('service_name', $service)->first();
        if ($settings) {
            $res = Http::withHeaders(['X-Api-Key' => $settings->api_key])->get(rtrim($settings->base_url, '/').$endpoint.'?term='.urlencode($this->searchQuery));

            $this->searchResults = $res->successful() ? array_slice($res->json(), 0, 8) : [];
        }

        $this->isSearching = false;
    }

    public function clearSearch()
    {
        $this->searchQuery = '';
        $this->searchResults = [];
    }

    public function addMedia(array $item)
    {
        $service = $this->activeTab === 'movies' ? 'radarr' : 'sonarr';
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
            return;
        }

        $endpoint = $this->activeTab === 'movies' ? '/api/v3/movie' : '/api/v3/series';

        $payload = [
            'title' => $item['title'],
            'qualityProfileId' => 1,
            'titleSlug' => $item['titleSlug'] ?? '',
            'monitored' => true,
            'addOptions' => ['searchForMissingEpisodes' => true],
        ];

        if ($this->activeTab === 'movies') {
            $payload['tmdbId'] = $item['tmdbId'];
            $payload['rootFolderPath'] = '/movies';
        } else {
            $payload['tvdbId'] = $item['tvdbId'];
            $payload['rootFolderPath'] = '/tv';
        }

        try {
            $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])->post(rtrim($settings->base_url, '/').$endpoint, $payload);

            if ($response->successful()) {
                $this->dispatch('notify', title: __('messages.media_added_title'), message: __('messages.media_added_message', ['title' => $item['title']]), type: 'success');
                $this->clearSearch();
                $this->loadMedia(new MediaStackService);
            } else {
                $this->dispatch('notify', title: __('messages.error'), message: $response->json()['message'] ?? __('messages.media_add_error_message'), type: 'error');
            }
        } catch (Exception $e) {
            $this->dispatch('notify', title: __('messages.error'), message: $e->getMessage(), type: 'error');
        }
    }

    public function getPoster(string $service, array $item): string
    {
        $posterPath = collect($item['images'] ?? [])->firstWhere('coverType', 'poster')['url'] ?? null;
        if (! $posterPath) {
            return '';
        }

        return new MediaStackService()->getPosterUrl($service, $posterPath);
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                {{ __('messages.media_management') }}</h2>
            <div class="flex items-center gap-4 mt-1">
                <button wire:click="$set('activeTab', 'movies')"
                    class="text-sm font-medium transition {{ $activeTab === 'movies' ? 'text-core-primary underline underline-offset-8' : 'text-zinc-500 hover:text-zinc-700' }}">{{ __('messages.movies') }}</button>
                <button wire:click="$set('activeTab', 'series')"
                    class="text-sm font-medium transition {{ $activeTab === 'series' ? 'text-core-primary underline underline-offset-8' : 'text-zinc-500 hover:text-zinc-700' }}">{{ __('messages.series') }}</button>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="relative group">
                <input type="text" wire:model.live.debounce.500ms="searchQuery" wire:keydown.enter="search"
                    placeholder="{{ __('messages.search_media_placeholder') }}"
                    class="w-64 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-core-primary outline-none transition" />
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-zinc-400" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>

                @if (!empty($searchResults))
                    <div
                        class="absolute right-0 top-full mt-2 w-[400px] bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-2xl z-30 p-2 space-y-1 overflow-hidden">
                        @foreach ($searchResults as $result)
                            <div
                                class="flex items-center gap-3 p-2 hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded-xl transition cursor-pointer">
                                <div class="w-10 h-14 bg-zinc-100 dark:bg-zinc-800 rounded-md overflow-hidden shrink-0">
                                    @php
                                        $poster =
                                            collect($result['images'] ?? [])
                                                ->where('remoteUrl')
                                                ->first() ?:
                                            collect($result['images'] ?? [])->first();
                                    @endphp
                                    @if ($poster)
                                        <img src="{{ $poster['remoteUrl'] ?? '' }}" class="w-full h-full object-cover">
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 truncate">
                                        {{ $result['title'] }}</p>
                                    <p class="text-[10px] text-zinc-500 line-clamp-1">{{ $result['year'] }}</p>
                                </div>
                                <button wire:click="addMedia({{ json_encode($result) }})"
                                    class="p-2 text-core-primary hover:bg-core-primary/10 rounded-lg transition">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 4v16m8-8H4" />
                                    </svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <button wire:click="search"
                wire:loading.attr="disabled"
                wire:target="search"
                class="cursor-pointer px-3 py-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg text-zinc-500 hover:text-core-primary disabled:opacity-50 transition">
                <svg wire:loading.remove wire:target="search" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <svg wire:loading wire:target="search" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </button>

            <button wire:click="toggleView"
                class="p-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg text-zinc-500 hover:text-zinc-900 dark:hover:text-white transition">
                @if ($viewMode === 'grid')
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                    </svg>
                @else
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg>
                @endif
            </button>
        </div>
    </div>

    <!-- Filter & Sort Bar -->
    <div class="flex flex-wrap items-center gap-3 p-4 bg-zinc-50 dark:bg-zinc-800/40 rounded-2xl border border-zinc-200 dark:border-zinc-800">
        <!-- Sort -->
        <div class="flex items-center gap-2">
            <span class="text-[10px] font-black text-zinc-400 uppercase tracking-widest ml-1">{{ __('messages.sort_by') }}</span>
            <select wire:model.live="sortBy" class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg px-3 py-1.5 text-xs font-bold text-zinc-700 dark:text-zinc-300 focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                <option value="added_desc">{{ __('messages.newest_added') }}</option>
                <option value="added_asc">{{ __('messages.oldest_added') }}</option>
                <option value="title_asc">{{ __('messages.title_az') }}</option>
                <option value="title_desc">{{ __('messages.title_za') }}</option>
                <option value="year_desc">{{ __('messages.year_newest') }}</option>
                <option value="year_asc">{{ __('messages.year_oldest') }}</option>
            </select>
        </div>

        <div class="h-6 w-px bg-zinc-200 dark:bg-zinc-700 hidden sm:block mx-1"></div>

        <!-- Monitored -->
        <select wire:model.live="filterMonitored" class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg px-3 py-1.5 text-xs font-bold text-zinc-700 dark:text-zinc-300 focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
            <option value="all">{{ __('messages.all_tracking') }}</option>
            <option value="monitored">{{ __('messages.tracked') }}</option>
            <option value="unmonitored">{{ __('messages.ignored') }}</option>
        </select>

        <!-- Status -->
        <select wire:model.live="filterStatus" class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg px-3 py-1.5 text-xs font-bold text-zinc-700 dark:text-zinc-300 focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
            <option value="all">{{ __('messages.all_status') }}</option>
            <option value="available">{{ __('messages.available') }}</option>
            <option value="missing">{{ __('messages.missing') }}</option>
        </select>

        <!-- Genre -->
        @if(!empty($availableGenres))
            <select wire:model.live="filterGenre" class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg px-3 py-1.5 text-xs font-bold text-zinc-700 dark:text-zinc-300 focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                <option value="all">{{ __('messages.all_genres') }}</option>
                @foreach($availableGenres as $genre)
                    <option value="{{ $genre }}">{{ $genre }}</option>
                @endforeach
            </select>
        @endif

        <!-- Quality -->
        <select wire:model.live="filterQuality" class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg px-3 py-1.5 text-xs font-bold text-zinc-700 dark:text-zinc-300 focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
            <option value="all">{{ __('messages.all_qualities') }}</option>
            @foreach($qualityProfiles[$activeTab === 'movies' ? 'radarr' : 'sonarr'] ?? [] as $profile)
                <option value="{{ $profile['id'] }}">{{ $profile['name'] }}</option>
            @endforeach
        </select>

        @if($sortBy !== 'added_desc' || $filterMonitored !== 'all' || $filterStatus !== 'all' || $filterGenre !== 'all' || $filterQuality !== 'all' || $searchQuery !== '')
            <button wire:click="clearFilters" class="ml-auto text-[10px] font-black text-core-primary uppercase tracking-widest hover:underline transition">
                {{ __('messages.clear_filters') }}
            </button>
        @endif
    </div>

    @php
        $list = $this->paginatedMedia;
        $serviceName = $activeTab === 'movies' ? 'radarr' : 'sonarr';
        $configured = $activeTab === 'movies' ? $radarrConfigured : $sonarrConfigured;
    @endphp

    @if (!$configured)
        <div
            class="flex flex-col items-center justify-center py-20 bg-white dark:bg-zinc-900 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-3xl text-center">
            <div
                class="w-16 h-16 bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-500 rounded-2xl mb-4">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
            </div>
            <h3 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">
                {{ __('messages.not_configured_title', ['service' => ucfirst($serviceName)]) }}</h3>
            <p class="text-zinc-500 mb-6 max-w-sm">
                {{ __('messages.not_configured_subtitle', ['service' => ucfirst($serviceName), 'type' => $activeTab === 'movies' ? __('messages.films') : __('messages.series')]) }}
            </p>
            <a href="/settings" wire:navigate
                class="px-6 py-2.5 bg-core-primary text-white font-bold rounded-xl shadow-lg shadow-core-primary/20 hover:bg-core-primary/90 transition">
                {{ __('messages.go_to_settings') }}
            </a>
        </div>
    @else
        @if ($viewMode === 'grid')
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-6">
                @foreach ($list as $item)
                    <div wire:click="$dispatch('openMediaDetails', { media: {{ json_encode($item) }}, service: '{{ $serviceName }}' })"
                        class="group relative bg-white dark:bg-zinc-900 rounded-2xl overflow-hidden shadow-sm border border-zinc-200 dark:border-zinc-800 hover:border-core-primary transition-all duration-300 cursor-pointer">
                        <div class="aspect-2/3 bg-zinc-100 dark:bg-zinc-800 overflow-hidden relative">
                            <img src="{{ $this->getPoster($serviceName, $item) }}"
                                onerror="this.onerror=null; this.src='{{ collect($item['images'] ?? [])->firstWhere('remoteUrl')['remoteUrl'] ?? '' }}';"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                loading="lazy" />

                            <div class="absolute top-2 right-2">
                                <span
                                    class="px-2 py-0.5 text-[9px] font-black uppercase tracking-tighter rounded-full {{ $item['monitored'] ?? false ? 'bg-core-primary text-white' : 'bg-zinc-900/80 text-zinc-400' }}">
                                    {{ $item['monitored'] ?? false ? __('messages.tracked') : __('messages.ignored') }}
                                </span>
                            </div>
                        </div>
                        <div class="p-3">
                            <h4 class="text-xs font-bold text-zinc-900 dark:text-zinc-100 truncate">
                                {{ $item['title'] }}</h4>
                            <div class="flex items-center justify-between mt-1">
                                <span class="text-[10px] text-zinc-500">{{ $item['year'] ?? '' }}</span>
                                <div
                                    class="w-2 h-2 rounded-full {{ $item['hasFile'] ?? false ? 'bg-green-500' : 'bg-yellow-500' }}">
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div
                class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden">
                <table class="w-full text-left">
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @foreach ($list as $item)
                            <tr wire:click="$dispatch('openMediaDetails', { media: {{ json_encode($item) }}, service: '{{ $serviceName }}' })"
                                class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition cursor-pointer">
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-4">
                                        <img src="{{ $this->getPoster($serviceName, $item) }}"
                                            onerror="this.onerror=null; this.src='{{ collect($item['images'] ?? [])->firstWhere('remoteUrl')['remoteUrl'] ?? '' }}';"
                                            class="w-8 h-12 bg-zinc-100 rounded object-cover shadow-sm">
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                            {{ $item['title'] }}</p>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-center">
                                    <span
                                        class="px-2 py-0.5 text-[9px] font-black uppercase tracking-tighter rounded-full {{ $item['monitored'] ?? false ? 'bg-core-primary/10 text-core-primary' : 'bg-zinc-100 text-zinc-400' }}">
                                        {{ $item['monitored'] ?? false ? __('messages.tracked') : __('messages.ignored') }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-center">
                                    <div class="inline-flex items-center gap-1.5">
                                        <div
                                            class="w-1.5 h-1.5 rounded-full {{ $item['hasFile'] ?? false ? 'bg-green-500' : 'bg-yellow-500' }}">
                                        </div>
                                        <span
                                            class="text-[10px] font-bold text-zinc-500">{{ $item['hasFile'] ?? false ? __('messages.available') : __('messages.missing') }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-right text-xs text-zinc-400 font-medium tracking-tight">
                                    {{ $item['year'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-6">
            <flux:pagination :paginator="$this->paginatedMedia" class="[&>nav]:text-sm [&_button]:text-sm [&_button]:min-h-10 [&_button]:min-w-10 [&_a]:text-sm [&_a]:min-h-10 [&_a]:min-w-10" />
        </div>
    @endif

    @livewire('components.media-details')
</div>
