<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Support\Facades\Http;
use App\Models\ServiceSetting;

new #[Layout('components.layouts.app')] #[Title('Médias')] class extends Component {
    #[Url]
    public string $viewMode = 'grid';
    
    public array $movies = [];
    public array $series = [];
    public string $activeTab = 'movies';
    
    public int $perPage = 18;
    public int $movieLimit = 18;
    public int $seriesLimit = 18;

    public bool $radarrConfigured = false;
    public bool $sonarrConfigured = false;

    public string $searchQuery = '';
    public array $searchResults = [];
    public bool $isSearching = false;

    public function mount(MediaStackService $service)
    {
        $this->radarrConfigured = ServiceSetting::where('service_name', 'radarr')->where('is_active', true)->exists();
        $this->sonarrConfigured = ServiceSetting::where('service_name', 'sonarr')->where('is_active', true)->exists();
        
        $this->loadMedia($service);
    }

    public function loadMedia(MediaStackService $service)
    {
        if ($this->radarrConfigured) {
            $this->movies = $this->fetchFromArr('radarr', '/api/v3/movie');
        }
        if ($this->sonarrConfigured) {
            $this->series = $this->fetchFromArr('sonarr', '/api/v3/series');
        }
    }

    private function fetchFromArr(string $service, string $endpoint): array
    {
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (!$settings) return [];

        try {
            $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])
                ->get(rtrim($settings->base_url, '/') . $endpoint);

            if (!$response->successful()) return [];

            // Prevent PayloadTooLargeException: Only keep essential info for the list/grid
            return collect($response->json())->map(function($item) use ($service) {
                return [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'year' => $item['year'] ?? ($item['firstAired'] ? \Carbon\Carbon::parse($item['firstAired'])->year : ''),
                    'images' => $item['images'] ?? [],
                    'monitored' => $item['monitored'] ?? false,
                    'hasFile' => $item['hasFile'] ?? ($item['statistics']['percentOfEpisodes'] === 100 ? true : false),
                    'qualityProfileId' => $item['qualityProfileId'] ?? 0,
                    'studio' => $item['studio'] ?? $item['network'] ?? '',
                    'overview' => $item['overview'] ?? '',
                ];
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function loadMore()
    {
        if ($this->activeTab === 'movies') {
            $this->movieLimit += $this->perPage;
        } else {
            $this->seriesLimit += $this->perPage;
        }
    }

    public function toggleView()
    {
        $this->viewMode = $this->viewMode === 'grid' ? 'list' : 'grid';
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
            $res = Http::withHeaders(['X-Api-Key' => $settings->api_key])
                ->get(rtrim($settings->base_url, '/') . $endpoint . '?term=' . urlencode($this->searchQuery));
            
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
        if (!$settings) return;

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
            $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])
                ->post(rtrim($settings->base_url, '/') . $endpoint, $payload);

            if ($response->successful()) {
                $this->dispatch('notify', title: 'Média ajouté !', message: $item['title'] . " a été ajouté.", type: 'success');
                $this->clearSearch();
                $this->loadMedia(new MediaStackService());
            } else {
                $this->dispatch('notify', title: 'Erreur', message: $response->json()['message'] ?? 'Impossible d\'ajouter.', type: 'error');
            }
        } catch (\Exception $e) {
            $this->dispatch('notify', title: 'Erreur', message: $e->getMessage(), type: 'error');
        }
    }

    public function getPoster(string $service, array $item): string
    {
        $posterPath = collect($item['images'] ?? [])->firstWhere('coverType', 'poster')['url'] ?? null;
        if (!$posterPath) return '';

        return (new MediaStackService())->getPosterUrl($service, $posterPath);
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Gestion des Médias</h2>
            <div class="flex items-center gap-4 mt-1">
                 <button wire:click="$set('activeTab', 'movies')" class="text-sm font-medium transition {{ $activeTab === 'movies' ? 'text-core-primary underline underline-offset-8' : 'text-zinc-500 hover:text-zinc-700' }}">Films</button>
                 <button wire:click="$set('activeTab', 'series')" class="text-sm font-medium transition {{ $activeTab === 'series' ? 'text-core-primary underline underline-offset-8' : 'text-zinc-500 hover:text-zinc-700' }}">Séries</button>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="relative group">
                <input type="text" 
                       wire:model.live.debounce.500ms="searchQuery" 
                       wire:keydown.enter="search"
                       placeholder="Rechercher..." 
                       class="w-64 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-core-primary outline-none transition" />
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                
                @if(!empty($searchResults))
                    <div class="absolute right-0 top-full mt-2 w-[400px] bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-2xl z-30 p-2 space-y-1 overflow-hidden">
                        @foreach($searchResults as $result)
                            <div class="flex items-center gap-3 p-2 hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded-xl transition cursor-pointer">
                                <div class="w-10 h-14 bg-zinc-100 dark:bg-zinc-800 rounded-md overflow-hidden shrink-0">
                                     @php $poster = collect($result['images'] ?? [])->where('remoteUrl')->first() ?: collect($result['images'] ?? [])->first(); @endphp
                                     @if($poster) <img src="{{ $poster['remoteUrl'] ?? '' }}" class="w-full h-full object-cover"> @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 truncate">{{ $result['title'] }}</p>
                                    <p class="text-[10px] text-zinc-500 line-clamp-1">{{ $result['year'] }}</p>
                                </div>
                                <button wire:click="addMedia({{ json_encode($result) }})" class="p-2 text-core-primary hover:bg-core-primary/10 rounded-lg transition">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <button wire:click="toggleView" class="p-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg text-zinc-500 hover:text-zinc-900 dark:hover:text-white transition">
                @if($viewMode === 'grid')
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                @else
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                @endif
            </button>
        </div>
    </div>

    @php 
        $all = $activeTab === 'movies' ? $movies : $series;
        $limit = $activeTab === 'movies' ? $movieLimit : $seriesLimit;
        $list = array_slice($all, 0, $limit);
        $hasMore = count($all) > $limit;
        $serviceName = $activeTab === 'movies' ? 'radarr' : 'sonarr';
        $configured = $activeTab === 'movies' ? $radarrConfigured : $sonarrConfigured;
    @endphp

    @if(!$configured)
        <div class="flex flex-col items-center justify-center py-20 bg-white dark:bg-zinc-900 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-3xl text-center">
            <div class="w-16 h-16 bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-500 rounded-2xl mb-4">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            </div>
            <h3 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">{{ ucfirst($serviceName) }} non configuré</h3>
            <p class="text-zinc-500 mb-6 max-w-sm">Veuillez configurer {{ ucfirst($serviceName) }} pour afficher vos {{ $activeTab === 'movies' ? 'films' : 'séries' }}.</p>
            <a href="/settings" wire:navigate class="px-6 py-2.5 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 font-bold rounded-xl transition">Configuration</a>
        </div>
    @else
        @if($viewMode === 'grid')
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-6">
                @foreach($list as $item)
                    <div wire:click="$dispatch('openMediaDetails', { media: {{ json_encode($item) }}, service: '{{ $serviceName }}' })" 
                         class="group relative bg-white dark:bg-zinc-900 rounded-2xl overflow-hidden shadow-sm border border-zinc-200 dark:border-zinc-800 hover:border-core-primary transition-all duration-300 cursor-pointer">
                        <div class="aspect-2/3 bg-zinc-100 dark:bg-zinc-800 overflow-hidden relative">
                            <img src="{{ $this->getPoster($serviceName, $item) }}" 
                                 onerror="this.onerror=null; this.src='{{ collect($item['images'] ?? [])->firstWhere('remoteUrl')['remoteUrl'] ?? '' }}';"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                 loading="lazy" />
                            
                            <div class="absolute top-2 right-2">
                                 <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-tighter rounded-full {{ ($item['monitored'] ?? false) ? 'bg-core-primary text-white' : 'bg-zinc-900/80 text-zinc-400' }}">
                                     {{ ($item['monitored'] ?? false) ? 'Tracké' : 'Ignoré' }}
                                 </span>
                            </div>
                        </div>
                        <div class="p-3">
                            <h4 class="text-xs font-bold text-zinc-900 dark:text-zinc-100 truncate">{{ $item['title'] }}</h4>
                            <div class="flex items-center justify-between mt-1">
                                 <span class="text-[10px] text-zinc-500">{{ $item['year'] ?? '' }}</span>
                                 <div class="w-2 h-2 rounded-full {{ ($item['hasFile'] ?? false) ? 'bg-green-500' : 'bg-yellow-500' }}"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden">
                 <table class="w-full text-left">
                     <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @foreach($list as $item)
                            <tr wire:click="$dispatch('openMediaDetails', { media: {{ json_encode($item) }}, service: '{{ $serviceName }}' })"
                                class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition cursor-pointer">
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-4">
                                         <img src="{{ $this->getPoster($serviceName, $item) }}" 
                                              onerror="this.onerror=null; this.src='{{ collect($item['images'] ?? [])->firstWhere('remoteUrl')['remoteUrl'] ?? '' }}';"
                                              class="w-8 h-12 bg-zinc-100 rounded object-cover shadow-sm">
                                         <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $item['title'] }}</p>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-center">
                                     <span class="px-2 py-0.5 text-[9px] font-black uppercase tracking-tighter rounded-full {{ ($item['monitored'] ?? false) ? 'bg-core-primary/10 text-core-primary' : 'bg-zinc-100 text-zinc-400' }}">
                                         {{ ($item['monitored'] ?? false) ? 'Tracké' : 'Ignoré' }}
                                     </span>
                                </td>
                                <td class="px-6 py-3 text-center">
                                     <div class="inline-flex items-center gap-1.5">
                                         <div class="w-1.5 h-1.5 rounded-full {{ ($item['hasFile'] ?? false) ? 'bg-green-500' : 'bg-yellow-500' }}"></div>
                                         <span class="text-[10px] font-bold text-zinc-500">{{ ($item['hasFile'] ?? false) ? 'Dispo' : 'Manquant' }}</span>
                                     </div>
                                </td>
                                <td class="px-6 py-3 text-right text-xs text-zinc-400 font-medium tracking-tight">{{ $item['year'] }}</td>
                            </tr>
                        @endforeach
                     </tbody>
                 </table>
            </div>
        @endif

        @if($hasMore)
            <div class="flex justify-center pt-8">
                <button wire:click="loadMore" class="px-8 py-3 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl text-sm font-bold text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition shadow-sm">
                    Charger plus de {{ $activeTab === 'movies' ? 'films' : 'séries' }}
                </button>
            </div>
        @endif
    @endif

    @livewire('components.media-details')
</div>
