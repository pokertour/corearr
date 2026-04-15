<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Services\MediaStack\JellyseerrService;
use App\Services\MediaStack\MediaServerService;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

new #[Layout('components.layouts.app')] #[Title('messages.cleanup')] class extends Component {
    use WithPagination;

    public bool $readyToLoad = false;
    public array $requests = [];
    public array $filteredRequests = [];

    // Filters
    public bool $filterWatched = false;
    public string $filterAge = 'all'; // all, 30, 60, 90
    public float $filterRating = 0; // 0 to 10
    public ?int $filterReleaseYear = null;
    public string $search = '';

    // Selection
    public array $selectedIds = [];
    public bool $selectAll = false;
    public bool $confirmingDelete = false;

    // Mapping
    public array $mediaServerUsers = [];
    public array $userMappings = []; // Jellyseerr User ID => Media Server User ID

    public bool $isConfigured = false;

    public function loadData()
    {
        try {
            $jellyseerr = app(JellyseerrService::class);
            $mediaServer = app(MediaServerService::class);

            $this->isConfigured = $jellyseerr->isConfigured() && $mediaServer->isConfigured();

            if ($this->isConfigured) {
                $msUsers = $mediaServer->getUsers();
                $this->mediaServerUsers = $msUsers;

                $this->refreshData($jellyseerr, $mediaServer);
            }
        } catch (\Exception $e) {
            Log::error('Cleanup loadData failed: ' . $e->getMessage());
        }

        $this->readyToLoad = true;
    }

    // Sorting
    public string $sortField = 'createdAt';
    public string $sortDirection = 'desc';

    public function mount()
    {
        // Initial state
    }

    public function updated($property)
    {
        if (in_array($property, ['filterWatched', 'filterAge', 'filterRating', 'filterReleaseYear', 'search'])) {
            $this->resetPage();
            $this->applyFilters();
        }
    }

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
        $this->applyFilters();
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedIds = array_column($this->filteredRequests, 'id');
        } else {
            $this->selectedIds = [];
        }
    }

    public function applyFilters()
    {
        $filtered = array_filter($this->requests, function ($req) {
            // Search
            if ($this->search && !str_contains(strtolower($req['title']), strtolower($this->search))) {
                return false;
            }

            // Watched Sync
            if ($this->filterWatched && !$req['isWatched']) {
                return false;
            }

            // Age
            if ($this->filterAge !== 'all') {
                $days = (int) $this->filterAge;
                $createdAt = strtotime($req['createdAt']);
                $diffDays = (time() - $createdAt) / 86400;
                if ($diffDays < $days) {
                    return false;
                }
            }

            // Rating
            if ($this->filterRating > 0 && $req['rating'] > $this->filterRating) {
                return false;
            }

            // Release Year
            if ($this->filterReleaseYear && $req['releaseYear'] && $req['releaseYear'] != $this->filterReleaseYear) {
                return false;
            }

            return true;
        });

        usort($filtered, function ($a, $b) {
            $valA = $a[$this->sortField] ?? null;
            $valB = $b[$this->sortField] ?? null;

            if ($this->sortField === 'title' || $this->sortField === 'reqUserDisplay') {
                $valA = strtolower($valA ?? '');
                $valB = strtolower($valB ?? '');
            }

            if ($valA === $valB) return 0;

            if ($this->sortDirection === 'asc') {
                return $valA <=> $valB;
            } else {
                return $valB <=> $valA;
            }
        });

        $this->filteredRequests = array_values($filtered);

        // Reset selection if hidden
        $this->selectedIds = array_values(array_intersect($this->selectedIds, array_column($this->filteredRequests, 'id')));
    }

    public function refreshData(JellyseerrService $jellyseerr, MediaServerService $mediaServer)
    {
        // Get all available requests
        $reqsResponse = $jellyseerr->getRequests(100, 0, 'available');
        $rawRequests = $reqsResponse['results'] ?? [];

        // Prepare batch metadata fetching
        $metaToFetch = [];
        foreach ($rawRequests as $req) {
            $tmdbId = $req['media']['tmdbId'] ?? null;
            if ($tmdbId) {
                $metaToFetch[] = ['tmdbId' => $tmdbId, 'type' => $req['type']];
            }
        }
        $metadata = $jellyseerr->getBulkMediaDetails($metaToFetch);

        // First pass: Resolve which users we need mappings for
        $usersToMap = [];
        foreach ($rawRequests as &$req) {
            $jsUser = $req['requestedBy'] ?? [];
            $jsUserId = $jsUser['id'] ?? null;

            $msUserId = Cache::get("js_mapping_{$jsUserId}");
            if (!$msUserId) {
                // Auto-map logic from session if possible
                $jfId = $jsUser['jellyfinUserId'] ?? '';
                if ($jfId) {
                    foreach ($this->mediaServerUsers as $mu) {
                        if (($mu['Id'] ?? '') == $jfId) {
                            $msUserId = $mu['Id'];
                            break;
                        }
                    }
                }
            }

            if ($msUserId) {
                $usersToMap[$msUserId] = true;
                $req['_msUserId'] = $msUserId; // Temporary store for next pass
            }
        }
        unset($req);

        // Fetch all needed library mappings in one go per user
        $userMappings = [];
        foreach (array_keys($usersToMap) as $msUserId) {
            $userMappings[$msUserId] = $mediaServer->getLibraryMapping($msUserId);
        }

        $processed = [];
        foreach ($rawRequests as $req) {
            $tmdbId = (string) ($req['media']['tmdbId'] ?? '');
            $msUserId = $req['_msUserId'] ?? null;
            $meta = $metadata["{$req['type']}_{$tmdbId}"] ?? null;

            $watchData = [
                'isWatched' => false,
                'rating' => 0,
                'releaseYear' => null,
            ];

            if ($msUserId && isset($userMappings[$msUserId][$tmdbId])) {
                $watchData = $userMappings[$msUserId][$tmdbId];
            }

            // Fallback metadata from Jellyseerr if media server search failed or returned defaults
            $titleStr = $meta['title'] ?? ($meta['name'] ?? ($req['media']['title'] ?? 'Unknown'));
            $posterStr = $meta['posterPath'] ?? ($req['media']['posterPath'] ?? null);

            // If media server has better metadata (matching real play status), prefer it
            $rating = $watchData['rating'] > 0 ? $watchData['rating'] : $meta['voteAverage'] ?? 0;
            $releaseYear = $watchData['releaseYear'] ?? (isset($meta['releaseDate']) ? (int) substr($meta['releaseDate'], 0, 4) : (isset($meta['firstAirDate']) ? (int) substr($meta['firstAirDate'], 0, 4) : null));

            $seasonsLabel = null;
            if ($req['type'] === 'tv' || $req['type'] === 'series') {
                $seasonCount = $req['seasonCount'] ?? 0;
                $totalSeasons = isset($meta['seasons']) ? count(array_filter($meta['seasons'], fn($s) => ($s['seasonNumber'] ?? 0) > 0)) : 0;

                if ($seasonCount >= $totalSeasons && $totalSeasons > 0) {
                    $seasonsLabel = 'Série complète';
                } else {
                    $nums = array_column($req['seasons'] ?? [], 'seasonNumber');
                    sort($nums);
                    $seasonsLabel = count($nums) > 0 ? 'Saisons : ' . implode(', ', $nums) : 'Saisons inconnues';
                }
            }

            $isDeleting = \Illuminate\Support\Facades\Cache::has("deleting_media_{$req['id']}");

            $processed[] = [
                'id' => $req['id'],
                'type' => $req['type'],
                'mediaType' => $req['media']['mediaType'] ?? $req['type'],
                'title' => $titleStr,
                'poster' => $posterStr,
                'releaseYear' => $releaseYear,
                'rating' => $rating,
                'createdAt' => $req['createdAt'],
                'jsUserId' => $req['requestedBy']['id'] ?? null,
                'reqUserDisplay' => $req['requestedBy']['displayName'] ?? 'Unknown',
                'isMapped' => $msUserId !== null,
                'isWatched' => $watchData['isWatched'],
                'seasonsLabel' => $seasonsLabel,
                'mediaExternalId' => $req['media']['externalServiceId'] ?? null,
                'jellyseerrMediaId' => $req['media']['id'] ?? null,
                'tmdbId' => $req['media']['tmdbId'] ?? null,
                'service' => $req['type'] === 'tv' ? 'sonarr' : 'radarr',
                'isDeleting' => $isDeleting,
            ];

            if ($req['requestedBy']['id'] !== null && $msUserId) {
                $this->userMappings[$req['requestedBy']['id']] = $msUserId;
            }
        }

        $this->requests = $processed;
        $this->applyFilters();
    }

    public function saveUserMapping($jsUserId, $msUserId, JellyseerrService $jellyseerr, MediaServerService $mediaServer)
    {
        if ($msUserId) {
            Cache::forever("js_mapping_{$jsUserId}", $msUserId);
            $this->userMappings[$jsUserId] = $msUserId;
        } else {
            Cache::forget("js_mapping_{$jsUserId}");
            $this->userMappings[$jsUserId] = null;
        }
        $this->refreshData($jellyseerr, $mediaServer);
    }

    #[Computed]
    public function paginatedRequests()
    {
        $page = $this->getPage();
        $perPage = 15;
        $offset = ($page - 1) * $perPage;
        
        $itemsForCurrentPage = array_slice($this->filteredRequests, $offset, $perPage);
        
        return new LengthAwarePaginator(
            $itemsForCurrentPage,
            count($this->filteredRequests),
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );
    }

    public function confirmBulkDelete()
    {
        if (count($this->selectedIds) === 0) {
            return;
        }
        $this->confirmingDelete = true;
    }

    public function confirmSingleDelete($id)
    {
        $this->selectedIds = [$id];
        $this->confirmingDelete = true;
    }

    public function executeBulkDelete(JellyseerrService $jellyseerr, MediaStackService $arrService, MediaServerService $mediaServer)
    {
        $queuedCount = 0;

        foreach ($this->selectedIds as $id) {
            $req = collect($this->requests)->firstWhere('id', $id);
            if (!$req || $req['isDeleting']) {
                continue;
            }

            // Mark as deleting in cache immediately for UI feedback
            \Illuminate\Support\Facades\Cache::put("deleting_media_{$id}", true, now()->addMinutes(5));

            // Dispatch background job
            \App\Jobs\DeleteMediaJob::dispatch((int) $id, $req['service'], (int) $req['mediaExternalId'], $req['title'], isset($req['tmdbId']) ? (int) $req['tmdbId'] : null, isset($req['jellyseerrMediaId']) ? (int) $req['jellyseerrMediaId'] : null);

            $queuedCount++;
        }

        $this->confirmingDelete = false;
        $this->selectedIds = [];
        $this->selectAll = false;

        $this->refreshData($jellyseerr, $mediaServer);

        $this->dispatch('notify', title: 'Suppression programmée', message: "{$queuedCount} élément(s) ajouté(s) à la file d'attente de suppression.", type: 'success');
    }

    public function deleteOne($id, JellyseerrService $jellyseerr, MediaServerService $mediaServer)
    {
        // This method is now legacy as confirmSingleDelete + executeBulkDelete are used
    }

    public function cancelDelete()
    {
        $this->confirmingDelete = false;
        $this->selectedIds = [];
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="space-y-6 animate-pulse">
            <div class="h-8 bg-zinc-200 dark:bg-zinc-800 rounded w-1/4"></div>
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl h-16 shadow-sm"></div>
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden h-96"></div>
        </div>
        HTML;
    }
};

?>

<div wire:init="loadData" @if (collect($requests)->contains('isDeleting', true)) wire:poll.5s="refreshData" @endif class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('messages.cleanup') }}</h2>
            <p class="text-sm text-zinc-500">{{ __('messages.cleanup_subtitle') }}</p>
        </div>
    </div>
    @if (!$readyToLoad)
        <!-- Loading Placeholder -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 animate-pulse">
            @for ($i = 0; $i < 4; $i++)
                <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl h-64">
                </div>
            @endfor
        </div>
    @elseif(!$isConfigured)
        <div
            class="bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-10 text-center">
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-2">
                {{ __('messages.not_configured_title', ['service' => 'Jellyseerr/Media Server']) }}</h3>
            <p class="text-zinc-500 mb-6">
                {{ __('messages.not_configured_subtitle', ['service' => 'Cleanup', 'type' => 'médias']) }}</p>
            <a href="/settings"
                class="inline-flex items-center px-6 py-2.5 bg-core-primary text-white text-sm font-bold rounded-xl hover:bg-core-primary/90 transition shadow-lg shadow-core-primary/20"
                wire:navigate>
                {{ __('messages.go_to_settings') }}
            </a>
        </div>
    @else
        <!-- Filters Toolbar -->
        <div x-data="{ mobileFiltersOpen: false }" wire:ignore.self class="bg-white dark:bg-zinc-900 p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm flex flex-col gap-4">
            
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <!-- Search and Mobile Toggle -->
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <div class="relative flex-1 md:w-64">
                         <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-zinc-400">
                             <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                         </div>
                         <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search title..."
                             class="w-full pl-10 pr-4 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-core-primary outline-none transition text-zinc-900 dark:text-white">
                    </div>
                    
                    <button type="button" x-on:click="mobileFiltersOpen = !mobileFiltersOpen" x-bind:aria-expanded="mobileFiltersOpen.toString()" aria-controls="cleanup-mobile-filters" class="md:hidden p-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-zinc-600 dark:text-zinc-400 transition hover:bg-zinc-100 dark:hover:bg-zinc-700">
                        <svg class="w-5 h-5 cursor-pointer" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6h18M6 12h12m-9 6h6"/>
                        </svg>
                    </button>
                    
                    <button type="button" wire:click="confirmBulkDelete" @if (count($selectedIds) === 0) disabled @endif
                        class="md:hidden px-4 py-2 bg-red-500 text-white text-sm font-medium rounded-xl hover:bg-red-600 transition shadow-md shadow-red-500/20 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </button>
                </div>

                <!-- Desktop Bulk Actions -->
                <div class="hidden md:flex items-center gap-3">
                    <span class="text-sm font-semibold text-zinc-500">{{ __('messages.selected', ['count' => count($selectedIds)]) }}</span>
                    <button type="button" wire:click="confirmBulkDelete" @if (count($selectedIds) === 0) disabled @endif
                        class="px-4 py-2 bg-red-500 text-white text-sm font-medium rounded-xl hover:bg-red-600 transition shadow-md shadow-red-500/20 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                        {{ __('messages.bulk_delete') }}
                    </button>
                </div>
            </div>

            <!-- Mobile Collapsible Filters -->
            <div id="cleanup-mobile-filters" x-cloak x-show="mobileFiltersOpen" class="md:hidden flex flex-col gap-5 w-full overflow-x-hidden border-t border-zinc-100 dark:border-zinc-800/50 pt-5 mt-2">
                
                <!-- Sorting -->
                <div class="flex items-center gap-2 w-full">
                    <select wire:change="sortBy($event.target.value)" class="flex-1 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-4 py-2 text-sm focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer text-zinc-900 dark:text-white">
                        <option value="title" @if($sortField === 'title') selected @endif>{{ __('messages.media_column') }}</option>
                        <option value="rating" @if($sortField === 'rating') selected @endif>{{ __('messages.rating_column') }}</option>
                        <option value="reqUserDisplay" @if($sortField === 'reqUserDisplay') selected @endif>{{ __('messages.requested_by_column') }}</option>
                        <option value="createdAt" @if($sortField === 'createdAt') selected @endif>{{ __('messages.age_column') }}</option>
                        <option value="isWatched" @if($sortField === 'isWatched') selected @endif>{{ __('messages.status_column') }}</option>
                    </select>

                    <button type="button" wire:click="$set('sortDirection', '{{ $sortDirection === 'asc' ? 'desc' : 'asc' }}'); applyFilters();" 
                            class="p-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-zinc-500 hover:text-core-primary hover:border-core-primary transition cursor-pointer">
                        @if($sortDirection === 'asc')
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                        @else
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-1l4 4m0 0l4-4m-4 4V10"/></svg>
                        @endif
                    </button>
                </div>

                <!-- Attributes -->
                <div class="flex flex-col gap-5 w-full">
                    <div class="flex gap-3.5 w-full">
                        <select wire:model.live="filterAge"
                            class="flex-1 min-w-0 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-3 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                            <option value="all">{{ __('messages.requested_all_time') }}</option>
                            <option value="30">{{ __('messages.requested_days', ['days' => 30]) }}</option>
                            <option value="60">{{ __('messages.requested_days', ['days' => 60]) }}</option>
                            <option value="90">{{ __('messages.requested_days', ['days' => 90]) }}</option>
                        </select>
    
                        <select wire:model.live="filterRating"
                            class="flex-1 min-w-0 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-3 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                            <option value="0">{{ __('messages.any_rating') }}</option>
                            <option value="5">{{ __('messages.rating_below', ['rating' => 5]) }}</option>
                            <option value="7">{{ __('messages.rating_below', ['rating' => 7]) }}</option>
                            <option value="8">{{ __('messages.rating_below', ['rating' => 8]) }}</option>
                        </select>
                    </div>

                    <div class="flex gap-3.5 items-center w-full">
                        <input type="number" wire:model.live.debounce.300ms="filterReleaseYear" placeholder="Année (ex: 2006)"
                            class="flex-1 min-w-0 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-3 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" 
                            min="1900" max="{{ date('Y') }}">
                            
                        <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300 font-medium cursor-pointer shrink-0">
                            <input type="checkbox" wire:model.live="filterWatched"
                                class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-zinc-50 dark:bg-zinc-900">
                            {{ __('messages.watch_status') }}
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Desktop Filters -->
            <div class="hidden md:flex flex-row gap-4 w-full">
                <select wire:model.live="filterAge"
                    class="flex-none w-48 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-4 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                    <option value="all">{{ __('messages.requested_all_time') }}</option>
                    <option value="30">{{ __('messages.requested_days', ['days' => 30]) }}</option>
                    <option value="60">{{ __('messages.requested_days', ['days' => 60]) }}</option>
                    <option value="90">{{ __('messages.requested_days', ['days' => 90]) }}</option>
                </select>

                <select wire:model.live="filterRating"
                    class="flex-none w-48 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-4 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                    <option value="0">{{ __('messages.any_rating') }}</option>
                    <option value="5">{{ __('messages.rating_below', ['rating' => 5]) }}</option>
                    <option value="7">{{ __('messages.rating_below', ['rating' => 7]) }}</option>
                    <option value="8">{{ __('messages.rating_below', ['rating' => 8]) }}</option>
                </select>

                <input type="number" wire:model.live.debounce.300ms="filterReleaseYear" placeholder="Année (ex: 2006)"
                    class="flex-none w-40 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-4 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" 
                    min="1900" max="{{ date('Y') }}">
                    
                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300 font-medium cursor-pointer flex-none">
                    <input type="checkbox" wire:model.live="filterWatched"
                        class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-zinc-50 dark:bg-zinc-900">
                    {{ __('messages.watch_status') }}
                </label>
            </div>
            
        </div>

        <!-- Content List -->
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden flex flex-col">
            
            <!-- Mobile Layout -->
            <div class="block md:hidden divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($this->paginatedRequests as $req)
                    <div class="p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                        <div class="flex items-start gap-4">
                            <div class="pt-1 flex-shrink-0">
                                @if ($req['isDeleting'])
                                    <svg class="animate-spin h-4 w-4 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                @else
                                    <input type="checkbox" value="{{ $req['id'] }}" wire:model.live="selectedIds" class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-white dark:bg-zinc-900">
                                @endif
                            </div>
                            <div class="flex-shrink-0">
                                @if ($req['poster'])
                                    <img src="https://image.tmdb.org/t/p/w92{{ $req['poster'] }}" class="w-14 h-20 rounded object-cover shadow-sm" alt="Poster">
                                @else
                                    <div class="w-14 h-20 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between items-start gap-2">
                                    <h4 class="font-bold text-zinc-900 dark:text-white line-clamp-2 leading-tight">{{ $req['title'] }}</h4>
                                    @if (!$req['isDeleting'])
                                        <button wire:click="confirmSingleDelete({{ $req['id'] }})" class="p-1.5 -mr-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors cursor-pointer flex-shrink-0">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 mt-1 flex-wrap">
                                    <span class="text-[10px] uppercase font-bold tracking-widest text-zinc-400">{{ $req['mediaType'] }}</span>
                                    @if ($req['releaseYear'])
                                        <span class="text-[10px] font-bold text-zinc-500">• {{ $req['releaseYear'] }}</span>
                                    @endif
                                    @if ($req['rating'] > 0)
                                        <span class="text-[10px] font-bold text-yellow-600 dark:text-yellow-500 flex items-center gap-0.5"><svg class="w-2.5 h-2.5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>{{ number_format($req['rating'], 1) }}</span>
                                    @endif
                                </div>
                                <div class="mt-2 text-xs text-zinc-500">
                                    Demandé par: <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ $req['reqUserDisplay'] }}</span> 
                                    • {{ floor((time() - strtotime($req['createdAt'])) / 86400) }} jours
                                </div>
                                <div class="mt-2 text-xs flex justify-between items-center">
                                    <div>
                                        @if (!$req['isMapped'])
                                            <span class="text-red-500 font-medium tracking-tight">{{ __('messages.requires_mapping') }}</span>
                                            <select wire:change="saveUserMapping('{{ $req['jsUserId'] }}', $event.target.value)" class="mt-1 block w-full bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded p-1 text-xs text-zinc-900 dark:text-white">
                                                <option value="">{{ __('messages.map_user_option') }}</option>
                                                @foreach ($mediaServerUsers as $mu)
                                                    <option value="{{ $mu['Id'] }}">{{ $mu['Name'] }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            @if($req['isWatched'])
                                                <span class="px-2 py-1 bg-green-500/10 text-green-500 rounded font-bold uppercase text-[10px]">Vu</span>
                                            @else
                                                <span class="px-2 py-1 bg-zinc-100 dark:bg-zinc-800 text-zinc-500 rounded font-bold uppercase text-[10px]">En attente</span>
                                            @endif
                                        @endif
                                    </div>
                                    @if ($req['seasonsLabel'])
                                        <span class="text-[10px] bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 px-1.5 py-0.5 rounded font-black uppercase tracking-tighter">
                                            {{ $req['seasonsLabel'] }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-zinc-500">
                        {{ __('messages.no_results') }}
                    </div>
                @endforelse
            </div>

            <!-- Desktop Table Layout -->
            <div class="hidden md:block overflow-x-auto w-full">
                <table class="w-full text-left text-sm text-zinc-500 dark:text-zinc-400">
                    <thead class="text-xs text-zinc-700 dark:text-zinc-300 uppercase bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800">
                        <tr>
                            <th scope="col" class="p-4 w-4">
                                <input type="checkbox" wire:model.live="selectAll"
                                    class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-white dark:bg-zinc-900">
                            </th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-700 transition select-none" wire:click="sortBy('title')">
                                <div class="flex items-center gap-1">
                                    {{ __('messages.media_column') }}
                                    @if($sortField === 'title')
                                        <svg class="w-3 h-3 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    @else
                                        <svg class="w-3 h-3 opacity-0 group-hover:opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider text-center cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-700 transition select-none" wire:click="sortBy('rating')">
                                <div class="flex items-center justify-center gap-1">
                                    {{ __('messages.rating_column') }}
                                    @if($sortField === 'rating')
                                        <svg class="w-3 h-3 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-700 transition select-none" wire:click="sortBy('reqUserDisplay')">
                                <div class="flex items-center gap-1">
                                    {{ __('messages.requested_by_column') }}
                                    @if($sortField === 'reqUserDisplay')
                                        <svg class="w-3 h-3 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-700 transition select-none" wire:click="sortBy('createdAt')">
                                <div class="flex items-center gap-1">
                                    {{ __('messages.age_column') }}
                                    @if($sortField === 'createdAt')
                                        <svg class="w-3 h-3 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider text-center cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-700 transition select-none" wire:click="sortBy('isWatched')">
                                <div class="flex items-center justify-center gap-1">
                                    {{ __('messages.status_column') }}
                                    @if($sortField === 'isWatched')
                                        <svg class="w-3 h-3 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->paginatedRequests as $req)
                            <tr
                                class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                                <td class="p-4 text-center">
                                    @if ($req['isDeleting'])
                                        <svg class="animate-spin h-4 w-4 text-zinc-400 mx-auto"
                                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                                stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                    @else
                                        <input type="checkbox" value="{{ $req['id'] }}"
                                            wire:model.live="selectedIds"
                                            class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-white dark:bg-zinc-900">
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if ($req['poster'])
                                            <img src="https://image.tmdb.org/t/p/w92{{ $req['poster'] }}"
                                                class="w-10 h-14 rounded object-cover shadow-sm" alt="Poster">
                                        @else
                                            <div class="w-10 h-14 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                                        @endif
                                        <div>
                                            <div class="font-bold text-zinc-900 dark:text-white line-clamp-1">
                                                {{ $req['title'] }}</div>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span
                                                    class="text-[10px] uppercase font-bold tracking-widest text-zinc-400">{{ $req['mediaType'] }}</span>
                                                @if ($req['releaseYear'])
                                                    <span class="text-[10px] font-bold text-zinc-500">•
                                                        {{ $req['releaseYear'] }}</span>
                                                @endif
                                            </div>
                                            @if ($req['seasonsLabel'])
                                                <div class="mt-1">
                                                    <span
                                                        class="text-[10px] bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 px-1.5 py-0.5 rounded font-black uppercase tracking-tighter">
                                                        {{ $req['seasonsLabel'] }}
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if ($req['rating'] > 0)
                                        <div
                                            class="inline-flex items-center gap-1 px-2 py-0.5 bg-yellow-400/10 text-yellow-600 dark:text-yellow-500 rounded text-[11px] font-black">
                                            <svg class="w-2.5 h-2.5 fill-current" viewBox="0 0 20 20">
                                                <path
                                                    d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z">
                                                </path>
                                            </svg>
                                            {{ number_format($req['rating'], 1) }}
                                        </div>
                                    @else
                                        <span class="text-zinc-400 text-[10px] font-bold">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-200">
                                        {{ $req['reqUserDisplay'] }}</div>
                                    @if (!$req['isMapped'])
                                        <div class="mt-2 text-xs">
                                            <span
                                                class="text-red-500 font-medium tracking-tight">{{ __('messages.requires_mapping') }}</span>
                                            <select
                                                wire:change="saveUserMapping('{{ $req['jsUserId'] }}', $event.target.value)"
                                                class="mt-1 block w-full bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded p-1 text-xs text-zinc-900 dark:text-white">
                                                <option value="">{{ __('messages.map_user_option') }}</option>
                                                @foreach ($mediaServerUsers as $mu)
                                                    <option value="{{ $mu['Id'] }}">{{ $mu['Name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @else
                                        <div
                                            class="mt-1 text-[10px] text-green-500 font-bold uppercase tracking-wider">
                                            {{ __('messages.user_mapped') }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $diffDays = floor((time() - strtotime($req['createdAt'])) / 86400);
                                    @endphp
                                    <span
                                        class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('messages.days_plural', ['count' => $diffDays]) }}</span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex flex-col items-center">
                                        @if ($req['isDeleting'])
                                            <div
                                                class="inline-flex items-center gap-2 px-3 py-1 bg-red-500/10 text-red-500 rounded-full text-[10px] font-black uppercase tracking-widest animate-pulse border border-red-500/20">
                                                <span class="relative flex h-2 w-2">
                                                    <span
                                                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                                    <span
                                                        class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                                                </span>
                                                {{ __('messages.deleting') }}...
                                            </div>
                                        @elseif($req['isWatched'])
                                            <div
                                                class="inline-flex items-center justify-center p-2 rounded-xl bg-green-500/10 text-green-500">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </div>
                                            <div
                                                class="text-[10px] font-bold text-green-500 tracking-wider uppercase mt-1">
                                                {{ __('messages.watched') }}</div>
                                        @else
                                            <div
                                                class="inline-flex items-center justify-center p-2 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-400">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </div>
                                            <div
                                                class="text-[10px] font-bold text-zinc-400 tracking-wider uppercase mt-1">
                                                {{ __('messages.pending') }}</div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if (!$req['isDeleting'])
                                        <button wire:click="confirmSingleDelete({{ $req['id'] }})"
                                            class="p-2.5 bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white rounded-xl transition-all duration-200 group cursor-pointer"
                                            title="Supprimer définitivement">
                                            <svg class="w-5 h-5 transition-transform group-hover:scale-110"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-zinc-500">
                                    {{ __('messages.no_results') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>

        <!-- Pagination -->
        <div class="mt-6">
            <flux:pagination :paginator="$this->paginatedRequests" />
        </div>
    @endif

    <!-- Confirmation Modal -->
    @if ($confirmingDelete)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div
                class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl p-6 relative overflow-hidden">
                <div class="absolute inset-x-0 top-0 h-1 bg-red-500"></div>
                <div class="text-center mb-6">
                    <div
                        class="w-16 h-16 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-black text-zinc-900 dark:text-white uppercase tracking-tighter mb-2">
                        {{ __('messages.confirm_delete_subtitle') }}</h3>
                    <p class="text-sm text-zinc-500 px-4">
                        {{ __('messages.confirm_delete_description') }} ({{ count($selectedIds) }}).
                    </p>
                    <div
                        class="mt-4 px-4 py-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-xl border border-red-200 dark:border-red-800 text-xs font-black uppercase tracking-widest">
                        {{ __('messages.nuke_caution') }}
                    </div>
                    <ul
                        class="text-left bg-zinc-50 dark:bg-zinc-900/40 text-zinc-600 dark:text-zinc-400 text-xs rounded-xl p-4 my-4 space-y-3 font-medium border border-zinc-100 dark:border-zinc-800">
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {{ __('messages.delete_warning_jellyseerr') }}
                        </li>
                        <li class="flex items-center gap-2 text-red-800 dark:text-red-300">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            {{ __('messages.delete_warning_arr') }}
                        </li>
                    </ul>
                </div>

                <div class="flex items-center gap-3">
                    <button wire:click="cancelDelete"
                        class="flex-1 px-4 py-2 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-sm font-bold rounded-xl hover:bg-zinc-200 dark:hover:bg-zinc-700 transition cursor-pointer">
                        {{ __('messages.cancel') }}
                    </button>
                    <button wire:click="executeBulkDelete" wire:loading.attr="disabled"
                        class="flex-1 px-4 py-2 bg-red-500 text-white text-sm font-black rounded-xl hover:bg-red-600 transition shadow-lg shadow-red-500/20 flex justify-center cursor-pointer">
                        <span wire:loading.remove wire:target="executeBulkDelete">{{ __('messages.delete') }}</span>
                        <svg wire:loading wire:target="executeBulkDelete" class="animate-spin h-5 w-5 text-white"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
