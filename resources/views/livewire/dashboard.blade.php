<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Http;

new #[Layout('components.layouts.app')] #[Title('messages.dashboard')] class extends Component {
    public array $stats = [
        'dl_speed' => 0,
        'up_speed' => 0,
        'count' => 0,
        'total_size' => 0,
    ];

    public array $arrStats = [];
    public bool $qbitConfigured = false;
    public bool $arrConfigured = false;

    public function mount(MediaStackService $service)
    {
        $this->qbitConfigured = ServiceSetting::where('service_name', 'qbittorrent')->where('is_active', true)->exists();
        $this->arrConfigured = ServiceSetting::whereIn('service_name', ['sonarr', 'radarr'])
            ->where('is_active', true)
            ->exists();

        if ($this->qbitConfigured) {
            $qbit = $service->getQbitData();
            $this->stats['dl_speed'] = $qbit['server_state']['dl_info_speed'] ?? 0;
            $this->stats['up_speed'] = $qbit['server_state']['up_info_speed'] ?? 0;
            $this->stats['count'] = count($qbit['torrents'] ?? []);
            $this->stats['total_size'] = collect($qbit['torrents'] ?? [])->sum('size');
        }

        $this->arrStats = $service->getArrStats();
    }

    public function formatSize($bytes)
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }
};

?>

<div class="space-y-8">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                {{ __('messages.dashboard_title') }}</h2>
            <p class="text-sm text-zinc-500">{{ __('messages.dashboard_subtitle') }}</p>
        </div>

        <div class="flex items-center gap-2">
            @foreach (['radarr', 'sonarr', 'prowlarr'] as $s)
                @if (isset($arrStats[$s]) && \App\Models\ServiceSetting::where('service_name', $s)->exists())
                    <div
                        class="flex items-center gap-1.5 px-3 py-1.5 bg-zinc-100 dark:bg-zinc-800/50 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div
                            class="w-1.5 h-1.5 rounded-full {{ ($arrStats[$s]['health'] ?? 'OK') === 'OK' ? 'bg-green-500' : 'bg-yellow-500' }}">
                        </div>
                        <span
                            class="text-[10px] font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-tighter">{{ $s }}</span>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    <!-- Stats Overview Cards (Conditional) -->
    @if ($qbitConfigured)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([['label' => __('messages.downloads'), 'value' => $stats['count'], 'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4', 'color' => 'blue'], ['label' => __('messages.download_speed'), 'value' => $this->formatSize($stats['dl_speed']) . '/s', 'icon' => 'M13 10V3L4 14h7v7l9-11h-7z', 'color' => 'yellow'], ['label' => __('messages.upload_speed'), 'value' => $this->formatSize($stats['up_speed']) . '/s', 'icon' => 'M8 7l4-4m0 0l4 4m-4-4v18', 'color' => 'teal'], ['label' => __('messages.total_volume'), 'value' => $this->formatSize($stats['total_size']), 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'color' => 'purple']] as $stat)
                <div
                    class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-4 rounded-2xl shadow-sm hover:border-{{ $stat['color'] }}-500/50 transition duration-300 group">
                    <div class="flex items-center gap-3 mb-2">
                        <div
                            class="w-8 h-8 rounded-lg bg-{{ $stat['color'] }}-500/10 flex items-center justify-center text-{{ $stat['color'] }}-500 group-hover:scale-110 transition-transform">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="{{ $stat['icon'] }}" />
                            </svg>
                        </div>
                        <div>
                            <span
                                class="text-[9px] font-black text-zinc-400 uppercase tracking-widest block leading-none">qbittorrent</span>
                            <span
                                class="text-[11px] font-bold text-zinc-500 uppercase tracking-tight">{{ $stat['label'] }}</span>
                        </div>
                    </div>
                    <div class="text-xl font-black text-zinc-900 dark:text-zinc-100 italic tracking-tighter">
                        {{ $stat['value'] }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Arr System Cards (Unified Design) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach (['radarr' => ['label' => __('messages.movies'), 'color' => 'indigo', 'icon' => 'M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z'], 'sonarr' => ['label' => __('messages.series'), 'color' => 'yellow', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'], 'prowlarr' => ['label' => __('messages.indexers'), 'color' => 'pink', 'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z']] as $id => $cfg)
            @if (isset($arrStats[$id]) && \App\Models\ServiceSetting::where('service_name', $id)->exists())
                <div
                    class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-5 rounded-2xl shadow-sm hover:border-{{ $cfg['color'] }}-500/50 transition duration-300 relative group">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-xl bg-{{ $cfg['color'] }}-500/10 flex items-center justify-center text-{{ $cfg['color'] }}-500 group-hover:scale-110 transition-transform">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="{{ $cfg['icon'] }}" />
                                </svg>
                            </div>
                            <div>
                                <span
                                    class="text-[10px] font-black text-zinc-400 uppercase tracking-widest">{{ $id }}</span>
                                <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $cfg['label'] }}</h4>
                            </div>
                        </div>
                        <div class="flex flex-col items-end">
                            <span
                                class="text-2xl font-black text-zinc-900 dark:text-white">{{ $arrStats[$id]['count'] }}</span>
                            <span
                                class="text-[9px] font-bold {{ $arrStats[$id]['health'] === 'OK' ? 'text-green-500' : 'text-yellow-500' }} uppercase tracking-tighter">{{ $arrStats[$id]['health'] }}</span>
                        </div>
                    </div>

                    @if (isset($arrStats[$id]['disk']))
                        <div class="mt-4 space-y-3">
                            <div class="h-1.5 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                @php $percent = ($arrStats[$id]['disk']['total'] > 0) ? (1 - ($arrStats[$id]['disk']['free'] / $arrStats[$id]['disk']['total'])) * 100 : 0; @endphp
                                <div class="h-full bg-{{ $cfg['color'] }}-500 rounded-full"
                                    style="width: {{ $percent }}%"></div>
                            </div>
                            <div
                                class="flex justify-between text-[10px] font-bold uppercase tracking-widest text-zinc-500">
                                <span>{{ __('messages.storage_free') }}:
                                    {{ $this->formatSize($arrStats[$id]['disk']['free']) }}</span>
                                <span>{{ round($percent) }}% {{ __('messages.storage_used') }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($id === 'radarr' && isset($arrStats['radarr']['movies']))
                        <div
                            class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                            <span
                                class="text-[10px] font-black text-zinc-400 uppercase tracking-widest">{{ __('messages.movies') }}</span>
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-xs font-black text-zinc-900 dark:text-white">{{ $arrStats['radarr']['movies']['downloaded'] }}</span>
                                <div class="w-16 h-1 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                    @php $movPercent = ($arrStats['radarr']['movies']['total'] > 0) ? ($arrStats['radarr']['movies']['downloaded'] / $arrStats['radarr']['movies']['total']) * 100 : 0; @endphp
                                    <div class="h-full bg-indigo-500" style="width: {{ $movPercent }}%"></div>
                                </div>
                                <span class="text-[10px] font-bold text-zinc-400">/
                                    {{ $arrStats['radarr']['movies']['total'] }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($id === 'sonarr' && isset($arrStats['sonarr']['episodes']))
                        <div
                            class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                            <span
                                class="text-[10px] font-black text-zinc-400 uppercase tracking-widest">{{ __('messages.episodes') }}</span>
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-xs font-black text-zinc-900 dark:text-white">{{ $arrStats['sonarr']['episodes']['downloaded'] }}</span>
                                <div class="w-16 h-1 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                    @php $epPercent = ($arrStats['sonarr']['episodes']['total'] > 0) ? ($arrStats['sonarr']['episodes']['downloaded'] / $arrStats['sonarr']['episodes']['total']) * 100 : 0; @endphp
                                    <div class="h-full bg-yellow-500" style="width: {{ $epPercent }}%"></div>
                                </div>
                                <span class="text-[10px] font-bold text-zinc-400">/
                                    {{ $arrStats['sonarr']['episodes']['total'] }}</span>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        @endforeach
    </div>

    @if (\App\Models\ServiceSetting::whereIn('service_name', ['jellyseerr'])->where('is_active', true)->exists())
        <livewire:widgets.media-stats />
    @endif

    <!-- Main Grid (Conditional) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @if ($qbitConfigured)
            <div class="lg:col-span-2">
                <livewire:widgets.qbit-downloads />
            </div>
        @endif

        @if ($arrConfigured)
            <div class="col-span-1">
                <livewire:widgets.arr-calendar />
            </div>
        @endif

        @if (\App\Models\ServiceSetting::whereIn('service_name', ['jellyseerr', 'emby', 'jellyfin'])->where('is_active', true)->exists())
            <div class="lg:col-span-3 xl:col-span-1">
                <livewire:widgets.media-users />
            </div>
            
            <div class="lg:col-span-3 xl:col-span-1">
                <livewire:widgets.media-top-users />
            </div>
        @endif

        @if (!$qbitConfigured && !$arrConfigured && !\App\Models\ServiceSetting::whereIn('service_name', ['jellyseerr', 'emby', 'jellyfin'])->where('is_active', true)->exists())
            <div
                class="lg:col-span-3 flex flex-col items-center justify-center py-20 bg-zinc-50 dark:bg-zinc-900/50 rounded-3xl border-2 border-dashed border-zinc-200 dark:border-zinc-800">
                <p class="text-zinc-500 font-medium mb-4 text-center px-6">{{ __('messages.no_services_configured') }}
                </p>
                <a href="/settings" wire:navigate
                    class="px-6 py-2.5 bg-core-primary text-white font-bold rounded-xl shadow-lg shadow-core-primary/20 hover:bg-core-primary/90 transition">
                    {{ __('messages.go_to_settings') }}
                </a>
            </div>
        @endif
    </div>
</div>
