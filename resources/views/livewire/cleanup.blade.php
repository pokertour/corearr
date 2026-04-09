<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Services\MediaStack\JellyseerrService;
use App\Services\MediaStack\MediaServerService;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

new #[Layout('components.layouts.app')] #[Title('messages.cleanup')] class extends Component {
    public bool $readyToLoad = false;
    public array $requests = [];
    public array $filteredRequests = [];
    
    // Filters
    public bool $filterWatched = false;
    public string $filterAge = 'all'; // all, 30, 60, 90
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
        $this->readyToLoad = true;
        $jellyseerr = app(JellyseerrService::class);
        $mediaServer = app(MediaServerService::class);
        
        $this->isConfigured = $jellyseerr->isConfigured() && $mediaServer->isConfigured();

        if ($this->isConfigured) {
            $msUsers = $mediaServer->getUsers();
            $this->mediaServerUsers = $msUsers;

            $this->refreshData($jellyseerr, $mediaServer);
        }
    }

    public function mount()
    {
        // Initial state
    }

    public function updatedFilterWatched() { $this->applyFilters(); }
    public function updatedFilterAge() { $this->applyFilters(); }
    public function updatedSearch() { $this->applyFilters(); }

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
        $this->filteredRequests = array_values(array_filter($this->requests, function($req) {
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
                $days = (int)$this->filterAge;
                $createdAt = strtotime($req['createdAt']);
                $diffDays = (time() - $createdAt) / 86400;
                if ($diffDays < $days) return false;
            }

            return true;
        }));

        // Reset selection if hidden
        $this->selectedIds = array_values(array_intersect($this->selectedIds, array_column($this->filteredRequests, 'id')));
    }

    public function refreshData(JellyseerrService $jellyseerr, MediaServerService $mediaServer)
    {
        // Get all available requests
        $reqsResponse = $jellyseerr->getRequests(100, 0, 'available');
        $rawRequests = $reqsResponse['results'] ?? [];

        $processed = [];

        foreach ($rawRequests as $req) {
            $jsUser = $req['requestedBy'] ?? [];
            $jsUserId = $jsUser['id'] ?? null;
            $media = $req['media'] ?? [];
            $tmdbId = $media['tmdbId'] ?? '';
            $mediaType = $req['type'] === 'tv' ? 'Series' : 'Movie';

            // Resolve Mapping (Try cache first)
            $msUserId = Cache::get("js_mapping_{$jsUserId}");
            if (!$msUserId) {
                // Auto-map based on id if possible
                $jfId = $jsUser['jellyfinId'] ?? '';
                if ($jfId) {
                    foreach ($this->mediaServerUsers as $mu) {
                        if (($mu['Id'] ?? '') == $jfId) {
                            $msUserId = $mu['Id'];
                            break;
                        }
                    }
                }
            }

            $isWatched = false;
            if ($msUserId && $tmdbId) {
                // Only check if watched if we have a mapping
                $isWatched = $mediaServer->hasUserWatched($msUserId, $tmdbId, $mediaType);
            }

            // Use the data already in the request for speed
            // Avoid sequential N+1 calls to getMediaDetails in this loop
            $titleStr = $media['title'] ?? $media['name'] ?? $req['title'] ?? $req['name'] ?? 'Unknown';
            $posterStr = $media['posterPath'] ?? null;

            $processed[] = [
                'id' => $req['id'],
                'title' => $titleStr,
                'poster' => $posterStr,
                'mediaType' => $req['type'],
                'status' => $media['status'] ?? 0,
                'createdAt' => $req['createdAt'],
                'jsUserId' => $jsUserId,
                'reqUserDisplay' => $jsUser['displayName'] ?? $jsUser['email'] ?? 'Unknown',
                'isMapped' => $msUserId !== null,
                'isWatched' => $isWatched,
                'mediaExternalId' => $media['externalServiceId'] ?? null,
                'service' => $req['type'] === 'tv' ? 'sonarr' : 'radarr'
            ];

            if ($jsUserId !== null && $msUserId) {
                $this->userMappings[$jsUserId] = $msUserId;
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

    public function confirmBulkDelete()
    {
        if (count($this->selectedIds) === 0) return;
        $this->confirmingDelete = true;
    }

    public function executeBulkDelete(JellyseerrService $jellyseerr, MediaStackService $arrService, MediaServerService $mediaServer)
    {
        $deletedCount = 0;

        foreach ($this->selectedIds as $id) {
            $req = collect($this->requests)->firstWhere('id', $id);
            if (!$req) continue;

            // 1. Delete from Radarr/Sonarr
            if ($req['mediaExternalId']) {
                $arrService->deleteMedia($req['service'], $req['mediaExternalId'], true); // true = deleteFiles
            }

            // 2. Delete Request from Jellyseerr
            $jellyseerr->deleteRequest($id);

            $deletedCount++;
        }

        $this->confirmingDelete = false;
        $this->selectedIds = [];
        $this->selectAll = false;
        
        $this->refreshData($jellyseerr, $mediaServer);

        $this->dispatch('notify', title: 'Cleanup Complete', message: "{$deletedCount} items completely removed.", type: 'success');
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

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('messages.cleanup') }}</h2>
            <p class="text-sm text-zinc-500">{{ __('messages.cleanup_subtitle') }}</p>
        </div>
    </div>

    @if(!$readyToLoad)
        <!-- Loading Placeholder -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 animate-pulse">
            @for($i = 0; $i < 4; $i++)
                <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl h-64"></div>
            @endfor
        </div>
    @elseif(!$isConfigured)
        <div class="bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-10 text-center">
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-2">Service Not Configured</h3>
            <p class="text-zinc-500 mb-6">Ensure Jellyseerr and a Media Server (Emby/Jellyfin) are configured in settings.</p>
            <a href="/settings" class="inline-flex items-center px-6 py-2.5 bg-core-primary text-white text-sm font-bold rounded-xl hover:bg-core-primary/90 transition shadow-lg shadow-core-primary/20" wire:navigate>
                Go to Settings
            </a>
        </div>
    @else
        <!-- Filters Toolbar -->
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-4 shadow-sm flex flex-col md:flex-row gap-4 justify-between items-center">
            <div class="flex flex-col md:flex-row gap-4 w-full md:w-auto">
                <input type="text" wire:model.live="search" placeholder="Search title..." class="bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none">
                
                <select wire:model.live="filterAge" class="bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none">
                    <option value="all">All Time</option>
                    <option value="30">Older than 30 Days</option>
                    <option value="60">Older than 60 Days</option>
                    <option value="90">Older than 90 Days</option>
                </select>

                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300 font-medium cursor-pointer">
                    <input type="checkbox" wire:model.live="filterWatched" class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-zinc-50 dark:bg-zinc-900">
                    {{ __('messages.watch_status') }} Sync Only
                </label>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-zinc-500">{{ count($selectedIds) }} selected</span>
                <button wire:click="confirmBulkDelete" @if(count($selectedIds) === 0) disabled @endif class="px-4 py-2 bg-red-500 text-white text-sm font-medium rounded-lg hover:bg-red-600 transition shadow-md shadow-red-500/20 disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ __('messages.bulk_delete') }}
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-500 dark:text-zinc-400">
                    <thead class="text-xs text-zinc-700 dark:text-zinc-300 uppercase bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th scope="col" class="p-4 w-4">
                                <input type="checkbox" wire:model.live="selectAll" class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-white dark:bg-zinc-900">
                            </th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider">Media</th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider">Requested By</th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider">Age</th>
                            <th scope="col" class="px-6 py-4 font-semibold tracking-wider text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($filteredRequests as $req)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                                <td class="p-4">
                                    <input type="checkbox" value="{{ $req['id'] }}" wire:model.live="selectedIds" class="w-4 h-4 rounded text-core-primary border-zinc-300 focus:ring-core-primary bg-white dark:bg-zinc-900">
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if($req['poster'])
                                            <img src="https://image.tmdb.org/t/p/w92{{ $req['poster'] }}" class="w-10 h-14 rounded object-cover shadow-sm" alt="Poster">
                                        @else
                                            <div class="w-10 h-14 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                                        @endif
                                        <div>
                                            <div class="font-bold text-zinc-900 dark:text-white">{{ $req['title'] }}</div>
                                            <div class="text-[10px] uppercase font-bold tracking-widest text-zinc-400 mt-1">{{ $req['mediaType'] }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-200">{{ $req['reqUserDisplay'] }}</div>
                                    @if(!$req['isMapped'])
                                        <div class="mt-2 text-xs">
                                            <span class="text-red-500 font-medium tracking-tight">Requires Mapping</span>
                                            <select wire:change="saveUserMapping('{{ $req['jsUserId'] }}', $event.target.value)" class="mt-1 block w-full bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded p-1 text-xs text-zinc-900 dark:text-white">
                                                <option value="">Map User...</option>
                                                @foreach($mediaServerUsers as $mu)
                                                    <option value="{{ $mu['Id'] }}">{{ $mu['Name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @else
                                        <div class="mt-1 text-[10px] text-green-500 font-bold uppercase tracking-wider">User Mapped</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $diffDays = floor((time() - strtotime($req['createdAt'])) / 86400);
                                    @endphp
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $diffDays }} day(s)</span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if($req['isWatched'])
                                        <div class="inline-flex items-center justify-center p-2 rounded-xl bg-green-500/10 text-green-500">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                        <div class="text-[10px] font-bold text-green-500 tracking-wider uppercase mt-1">Watched</div>
                                    @else
                                        <div class="inline-flex items-center justify-center p-2 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-400">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <div class="text-[10px] font-bold text-zinc-400 tracking-wider uppercase mt-1">Pending</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-zinc-500">
                                    No requests match your current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Confirmation Modal -->
    @if($confirmingDelete)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl p-6 relative overflow-hidden">
                <div class="absolute inset-x-0 top-0 h-1 bg-red-500"></div>
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-black text-zinc-900 dark:text-white uppercase tracking-tighter mb-2">Destructive Action</h3>
                    <p class="text-sm text-zinc-500 px-4">
                        You are about to completely remove <strong>{{ count($selectedIds) }}</strong> requests. This action will:
                    </p>
                    <ul class="text-left bg-red-50 dark:bg-red-900/10 text-red-700 dark:text-red-400 text-xs rounded-xl p-3 my-4 space-y-2 font-medium">
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Delete the requests from Jellyseerr.
                        </li>
                        <li class="flex items-center gap-2 text-red-800 dark:text-red-300">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            Instruct Radarr/Sonarr to <strong>delete all physical files</strong>.
                        </li>
                    </ul>
                </div>
                
                <div class="flex items-center gap-3">
                    <button wire:click="$set('confirmingDelete', false)" class="flex-1 px-4 py-2 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-sm font-bold rounded-xl hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                        Wait, Go Back
                    </button>
                    <button wire:click="executeBulkDelete" wire:loading.attr="disabled" class="flex-1 px-4 py-2 bg-red-500 text-white text-sm font-black rounded-xl hover:bg-red-600 transition shadow-lg shadow-red-500/20 flex justify-center">
                        <span wire:loading.remove wire:target="executeBulkDelete">Nuke 'em</span>
                        <svg wire:loading wire:target="executeBulkDelete" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
