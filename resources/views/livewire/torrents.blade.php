<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

new #[Layout('components.layouts.app')] #[Title('messages.torrents')] class extends Component {
    public bool $isConfigured = false;
    public array $torrents = [];
    public ?string $confirmingDeletion = null;
    
    // Filtering & Sorting
    public string $search = '';
    public string $filterState = 'all';
    public string $sortBy = 'added_on';
    public string $sortDir = 'desc';

    public array $columns = [
        'name' => true,
        'size' => true,
        'progress' => true,
        'status' => true,
        'speed' => true,
        'eta' => true,
        'ratio' => true,
        'tracker' => true,
        'added' => true,
    ];

    public function mount(MediaStackService $service)
    {
        $this->isConfigured = \App\Models\ServiceSetting::where('service_name', 'qbittorrent')->where('is_active', true)->exists();
        
        // Load persisted columns
        if (Session::has('torrents_columns')) {
            $this->columns = array_merge($this->columns, Session::get('torrents_columns'));
        }

        if ($this->isConfigured) {
            $this->refreshTorrents($service);
        }
    }

    public function refreshTorrents(MediaStackService $service)
    {
        $data = $service->getQbitData();
        $rawTorrents = $data['torrents'] ?? [];
        
        // Inject the hash (the associative key) into each torrent object
        $this->torrents = collect($rawTorrents)->map(function($t, $hash) {
            $t['hash'] = $hash ?: ($t['infohash_v1'] ?? ($t['infohash_v2'] ?? ''));
            return $t;
        })->all();
    }

    public function getFilteredTorrentsProperty()
    {
        $filtered = collect($this->torrents);

        // Filter by search
        if ($this->search) {
            $filtered = $filtered->filter(fn($t) => str_contains(strtolower($t['name'] ?? ''), strtolower($this->search)));
        }

        // Filter by state
        if ($this->filterState !== 'all') {
            $filtered = $filtered->filter(function($t) {
                $state = strtolower($t['state'] ?? '');
                return match($this->filterState) {
                    'downloading' => str_contains($state, 'download') || str_contains($state, 'metadata'),
                    'paused' => str_contains($state, 'pause') || str_contains($state, 'stop') || str_contains($state, 'stalled'),
                    'seeding' => str_contains($state, 'upload') || str_contains($state, 'seed'),
                    'completed' => ($t['progress'] ?? 0) >= 1,
                    default => true,
                };
            });
        }

        // Sort
        return $filtered->sortBy(function($t) {
            return match($this->sortBy) {
                'name' => strtolower($t['name'] ?? ''),
                'size' => $t['size'] ?? 0,
                'progress' => $t['progress'] ?? 0,
                'added_on' => $t['added_on'] ?? 0,
                'speed' => ($t['dlspeed'] ?? 0) + ($t['upspeed'] ?? 0),
                'ratio' => $t['ratio'] ?? 0,
                default => $t[$this->sortBy] ?? 0,
            };
        }, SORT_REGULAR, $this->sortDir === 'desc')->all();
    }

    public function setSort(string $field)
    {
        if ($this->sortBy === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDir = 'desc';
        }
    }

    public function pauseTorrent(string $hash, MediaStackService $service)
    {
        if ($service->performQbitAction('pause', $hash)) {
            $this->dispatch('toast', message: __('messages.torrent_paused'), type: 'success');
            usleep(250000); // Wait 250ms for qBit to update state
            $this->refreshTorrents($service);
        } else {
            $this->dispatch('toast', message: __('messages.error_performing_action'), type: 'error');
        }
    }

    public function resumeTorrent(string $hash, MediaStackService $service)
    {
        if ($service->performQbitAction('resume', $hash)) {
            $this->dispatch('toast', message: __('messages.torrent_resumed'), type: 'success');
            usleep(250000); // Wait 250ms for qBit to update state
            $this->refreshTorrents($service);
        } else {
            $this->dispatch('toast', message: __('messages.error_performing_action'), type: 'error');
        }
    }

    public function deleteTorrent(MediaStackService $service)
    {
        if (!$this->confirmingDeletion) return;
        
        if ($service->performQbitAction('delete', $this->confirmingDeletion)) {
            $this->dispatch('toast', message: __('messages.torrent_deleted'), type: 'success');
            $this->confirmingDeletion = null;
            usleep(250000); // Wait 250ms for qBit to update state
            $this->refreshTorrents($service);
        } else {
            $this->dispatch('toast', message: __('messages.error_performing_action'), type: 'error');
        }
    }

    public function confirmDelete(string $hash)
    {
        $this->confirmingDeletion = $hash;
    }

    public function toggleColumn(string $column)
    {
        if (isset($this->columns[$column])) {
            $this->columns[$column] = !$this->columns[$column];
            Session::put('torrents_columns', $this->columns);
        }
    }

    public function formatSize($bytes)
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    public function formatSpeed($bytes)
    {
        return $this->formatSize($bytes) . '/s';
    }

    public function formatEta($seconds)
    {
        if ($seconds >= 8640000 || $seconds < 0) return '∞';
        if ($seconds < 60) return $seconds . 's';
        
        $minutes = floor($seconds / 60);
        if ($minutes < 60) return $minutes . 'm';
        
        $hours = floor($minutes / 60);
        return $hours . 'h ' . ($minutes % 60) . 'm';
    }
};

?>

<div class="space-y-6">
    @if(!$isConfigured)
        <div class="flex flex-col items-center justify-center py-20 bg-white dark:bg-zinc-900 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-3xl text-center">
            <div class="w-16 h-16 bg-blue-500/10 flex items-center justify-center text-blue-500 rounded-2xl mb-4">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            </div>
            <h3 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">{{ __('messages.qbit_not_configured') }}</h3>
            <p class="text-zinc-500 mb-6 max-w-sm">{{ __('messages.torrent_subtitle') }}</p>
            <a href="/settings" wire:navigate class="px-6 py-2.5 bg-core-primary text-white font-bold rounded-xl shadow-lg shadow-core-primary/20 hover:bg-core-primary/90 transition">
                {{ __('messages.go_to_settings') }}
            </a>
        </div>
    @else
        <!-- Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('messages.torrent_management') }}</h2>
                <p class="text-sm text-zinc-500">{{ __('messages.torrent_subtitle') }}</p>
            </div>

            <div class="flex items-center gap-2">
                <button wire:click="refreshTorrents" class="cursor-pointer p-2.5 bg-core-primary text-white rounded-xl hover:bg-core-primary/90 transition shadow-lg shadow-core-primary/20">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>
        </div>

        <!-- Filters Bar -->
        <div class="flex flex-col md:flex-row gap-4 bg-white dark:bg-zinc-900 p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="relative flex-1">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-zinc-400">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <input wire:model.live.debounce.300ms="search" 
                       type="text" 
                       placeholder="{{ __('messages.search_placeholder') }}" 
                       class="w-full pl-10 pr-4 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-core-primary outline-none transition">
            </div>

            <div class="flex items-center gap-4">
                <select wire:model.live="filterState" class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl px-4 py-2 text-sm focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                    <option value="all">{{ __('messages.all_statuses') }}</option>
                    <option value="downloading">{{ __('messages.downloads') }}</option>
                    <option value="paused">{{ __('messages.paused') }}</option>
                    <option value="seeding">{{ __('messages.seeding') }}</option>
                    <option value="completed">{{ __('messages.completed') }}</option>
                </select>

                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="cursor-pointer px-4 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                        {{ __('messages.columns') }}
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xl z-20 p-2">
                        @foreach($columns as $col => $visible)
                            <label class="flex items-center gap-3 px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded-lg cursor-pointer transition">
                                <input type="checkbox" wire:click="toggleColumn('{{ $col }}')" {{ $visible ? 'checked' : '' }} class="rounded border-zinc-300 text-core-primary focus:ring-core-primary">
                                <span class="text-[11px] font-bold text-zinc-700 dark:text-zinc-300 uppercase tracking-tighter">{{ __('messages.' . $col) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[800px]">
                <thead>
                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                        @if($columns['name']) 
                            <th wire:click="setSort('name')" class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider cursor-pointer hover:text-zinc-900 dark:hover:text-white transition">
                                {{ __('messages.name') }} @if($sortBy === 'name') {{ $sortDir === 'asc' ? '↑' : '↓' }} @endif
                            </th> 
                        @endif
                        @if($columns['size']) 
                            <th wire:click="setSort('size')" class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider cursor-pointer hover:text-zinc-900 dark:hover:text-white transition text-center">
                                {{ __('messages.size') }} @if($sortBy === 'size') {{ $sortDir === 'asc' ? '↑' : '↓' }} @endif
                            </th> 
                        @endif
                        @if($columns['progress']) 
                            <th wire:click="setSort('progress')" class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider cursor-pointer hover:text-zinc-900 dark:hover:text-white transition text-center">
                                {{ __('messages.progress') }} @if($sortBy === 'progress') {{ $sortDir === 'asc' ? '↑' : '↓' }} @endif
                            </th> 
                        @endif
                        @if($columns['status']) <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider text-center">{{ __('messages.status') }}</th> @endif
                        @if($columns['speed']) <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider text-center">{{ __('messages.download_speed') }}/{{ __('messages.upload_speed') }}</th> @endif
                        @if($columns['eta']) <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider text-center">ETA</th> @endif
                        @if($columns['ratio']) <th wire:click="setSort('ratio')" class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider cursor-pointer hover:text-zinc-900 dark:hover:text-white transition text-center">{{ __('messages.ratio') }}</th> @endif
                        @if($columns['tracker']) <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider">{{ __('messages.tracker') }}</th> @endif
                        @if($columns['added']) 
                            <th wire:click="setSort('added_on')" class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider cursor-pointer hover:text-zinc-900 dark:hover:text-white transition text-right">
                                {{ __('messages.added') }} @if($sortBy === 'added_on') {{ $sortDir === 'asc' ? '↑' : '↓' }} @endif
                            </th> 
                        @endif
                        <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider text-right">{{ __('messages.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($this->filteredTorrents as $torrent)
                        @php $hash = $torrent['hash'] ?? ''; @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition">
                            @if($columns['name'])
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate max-w-xs" title="{{ $torrent['name'] ?? '' }}">
                                        {{ $torrent['name'] ?? __('messages.unknown') }}
                                    </div>
                                    <div class="text-[10px] text-zinc-400 mt-0.5 uppercase tracking-tighter">{{ $torrent['category'] ?: __('messages.no_category') }}</div>
                                </td>
                            @endif
                            @if($columns['size'])
                                <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-400 text-center whitespace-nowrap">
                                    {{ $this->formatSize($torrent['size'] ?? 0) }}
                                </td>
                            @endif
                            @if($columns['progress'])
                                <td class="px-6 py-4 min-w-[150px]">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1 h-1.5 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                            <div class="h-full bg-core-primary rounded-full transition-all duration-500" style="width: {{ ($torrent['progress'] ?? 0) * 100 }}%"></div>
                                        </div>
                                        <span class="text-xs font-bold text-zinc-500">{{ round(($torrent['progress'] ?? 0) * 100, 1) }}%</span>
                                    </div>
                                </td>
                            @endif
                            @if($columns['status'])
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2 py-0.5 text-[9px] font-black rounded-lg uppercase tracking-widest border
                                        {{ str_contains($torrent['state'], 'download') ? 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-800' : 
                                           (str_contains($torrent['state'], 'pause') || str_contains($torrent['state'], 'stop') ? 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-zinc-800/40 dark:text-zinc-400 dark:border-zinc-700' : 'bg-green-100 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-400 dark:border-green-800') }}">
                                        {{ str_replace('_', ' ', $torrent['state']) }}
                                    </span>
                                </td>
                            @endif
                            @if($columns['speed'])
                                <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-400 font-mono text-center">
                                    <div class="flex flex-col items-center">
                                        <span class="text-blue-500 text-[10px]">↓ {{ $this->formatSpeed($torrent['dlspeed'] ?? 0) }}</span>
                                        <span class="text-teal-500 text-[10px]">↑ {{ $this->formatSpeed($torrent['upspeed'] ?? 0) }}</span>
                                    </div>
                                </td>
                            @endif
                            @if($columns['eta'])
                                <td class="px-6 py-4 text-xs text-zinc-500 text-center uppercase tracking-tighter">
                                    {{ $this->formatEta($torrent['eta'] ?? -1) }}
                                </td>
                            @endif
                            @if($columns['ratio'])
                                <td class="px-6 py-4 text-xs font-bold text-zinc-500 text-center">
                                    {{ round($torrent['ratio'] ?? 0, 2) }}
                                </td>
                            @endif
                            @if($columns['tracker'])
                                <td class="px-6 py-4 text-[10px] text-zinc-500 truncate max-w-[150px] font-bold uppercase tracking-tighter" title="{{ $torrent['tracker'] ?? '' }}">
                                    {{ parse_url($torrent['tracker'] ?? '', PHP_URL_HOST) ?: ($torrent['tracker'] ?? 'N/A') }}
                                </td>
                            @endif
                            @if($columns['added'])
                                <td class="px-6 py-4 text-[10px] text-zinc-500 text-right uppercase tracking-tighter">
                                    {{ \Carbon\Carbon::createFromTimestamp($torrent['added_on'] ?? 0)->translatedFormat('d M Y') }}
                                </td>
                            @endif
                            <td class="px-6 py-4 text-right space-x-1">
                                 @if(str_contains($torrent['state'], 'pause') || str_contains($torrent['state'], 'stop'))
                                    <button wire:click="resumeTorrent('{{ $hash }}')" class="cursor-pointer p-2 text-zinc-400 hover:bg-green-100 dark:hover:bg-green-900/30 hover:text-green-500 rounded-xl transition-all">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </button>
                                 @else
                                    <button wire:click="pauseTorrent('{{ $hash }}')" class="cursor-pointer p-2 text-zinc-400 hover:bg-yellow-100 dark:hover:bg-yellow-900/30 hover:text-yellow-500 rounded-xl transition-all">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </button>
                                 @endif
                                 <button wire:click="confirmDelete('{{ $hash }}')" 
                                         class="cursor-pointer p-2 text-zinc-400 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 rounded-xl transition-all">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                 </button>
                            </td>
                        </tr>
                     @empty
                        <tr>
                            <td colspan="12" class="px-6 py-12 text-center text-zinc-500">
                                <div class="flex flex-col items-center">
                                    <svg class="w-12 h-12 text-zinc-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                    <p class="text-lg font-medium text-zinc-900 dark:text-zinc-100 uppercase tracking-tight">{{ __('messages.no_torrents_found') }}</p>
                                    <p class="text-sm">{{ __('messages.adjust_filters') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <!-- Delete Confirmation Modal -->
    <div x-data="{ open: @entangle('confirmingDeletion') }" 
         x-show="open" 
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-950/60 backdrop-blur-sm"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         style="display: none;"
         x-cloak>
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-4xl shadow-2xl max-w-md w-full p-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-14 h-14 bg-red-500/10 rounded-2xl flex items-center justify-center text-red-500 shrink-0">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div>
                    <h3 class="text-xl font-black text-zinc-900 dark:text-white uppercase tracking-tight">{{ __('messages.confirm_delete_title') }}</h3>
                    <p class="text-xs text-zinc-500 font-bold uppercase tracking-widest">{{ __('messages.confirm_delete_subtitle') }}</p>
                </div>
            </div>
            
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-8 leading-relaxed font-medium">
                {{ __('messages.confirm_delete_description') }}
            </p>

            <div class="flex gap-3">
                <button @click="confirmingDeletion = null" class="flex-1 px-4 py-3 bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 font-bold rounded-2xl transition hover:bg-zinc-200 dark:hover:bg-zinc-700 cursor-pointer">
                    {{ __('messages.cancel') }}
                </button>
                <button wire:click="deleteTorrent" class="flex-1 px-4 py-3 bg-red-500 text-white font-bold rounded-2xl transition hover:bg-red-600 shadow-xl shadow-red-500/20 cursor-pointer">
                    {{ __('messages.delete') }}
                </button>
            </div>
        </div>
    </div>
</div>
