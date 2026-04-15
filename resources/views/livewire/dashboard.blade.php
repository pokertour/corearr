<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Services\MediaStack\MediaStackService;
use App\Services\MediaStack\JellyseerrService;
use App\Models\ServiceSetting;

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
    public bool $jellyseerrConfigured = false;
    public bool $mediaServicesConfigured = false;
    public bool $showCustomizePanel = false;
    public array $jellyStats = [];
    public array $dashboardPreferences = [
        'widgets' => [],
        'order' => [],
    ];
    public array $availableWidgets = [
        'qbit_downloads_count' => 'messages.dashboard_widget_qbit_downloads_count',
        'qbit_download_speed' => 'messages.dashboard_widget_qbit_download_speed',
        'qbit_upload_speed' => 'messages.dashboard_widget_qbit_upload_speed',
        'qbit_total_volume' => 'messages.dashboard_widget_qbit_total_volume',
        'arr_radarr' => 'messages.dashboard_widget_arr_radarr',
        'arr_sonarr' => 'messages.dashboard_widget_arr_sonarr',
        'arr_prowlarr' => 'messages.dashboard_widget_arr_prowlarr',
        'jellyseerr_total' => 'messages.dashboard_widget_jellyseerr_total',
        'jellyseerr_movies' => 'messages.dashboard_widget_jellyseerr_movies',
        'jellyseerr_series' => 'messages.dashboard_widget_jellyseerr_series',
        'jellyseerr_processing' => 'messages.dashboard_widget_jellyseerr_processing',
        'jellyseerr_completed' => 'messages.dashboard_widget_jellyseerr_completed',
        'qbit_downloads' => 'messages.dashboard_widget_qbit_downloads',
        'arr_calendar' => 'messages.dashboard_widget_arr_calendar',
        'media_users' => 'messages.dashboard_widget_media_users',
        'media_top_users' => 'messages.dashboard_widget_media_top_users',
    ];

    public function mount(MediaStackService $service, JellyseerrService $jellyseerr)
    {
        $this->qbitConfigured = ServiceSetting::where('service_name', 'qbittorrent')->where('is_active', true)->exists();
        $this->arrConfigured = ServiceSetting::whereIn('service_name', ['sonarr', 'radarr'])
            ->where('is_active', true)
            ->exists();
        $this->jellyseerrConfigured = ServiceSetting::where('service_name', 'jellyseerr')->where('is_active', true)->exists();
        $this->mediaServicesConfigured = ServiceSetting::whereIn('service_name', ['jellyseerr', 'emby', 'jellyfin'])
            ->where('is_active', true)
            ->exists();

        $this->dashboardPreferences = auth()->user()?->dashboard_preferences ?? ['widgets' => [], 'order' => []];
        $this->ensureWidgetOrder();

        if ($this->qbitConfigured) {
            $qbit = $service->getQbitData();
            $this->stats['dl_speed'] = $qbit['server_state']['dl_info_speed'] ?? 0;
            $this->stats['up_speed'] = $qbit['server_state']['up_info_speed'] ?? 0;
            $this->stats['count'] = count($qbit['torrents'] ?? []);
            $this->stats['total_size'] = collect($qbit['torrents'] ?? [])->sum('size');
        }

        $this->arrStats = $service->getArrStats();

        if ($this->jellyseerrConfigured) {
            $this->jellyStats = $jellyseerr->getRequestCounts();
        }
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

    public function toggleWidget(string $widget): void
    {
        if (! array_key_exists($widget, $this->availableWidgets)) {
            return;
        }

        $current = $this->isWidgetVisible($widget);
        data_set($this->dashboardPreferences, "widgets.{$widget}", ! $current);

        $this->persistDashboardPreferences();
    }

    public function resetDashboardPreferences(): void
    {
        $this->dashboardPreferences = ['widgets' => [], 'order' => array_keys($this->availableWidgets)];
        $this->persistDashboardPreferences();
    }

    public function isWidgetVisible(string $widget): bool
    {
        return (bool) data_get($this->dashboardPreferences, "widgets.{$widget}", true);
    }

    public function hasVisibleDashboardContent(): bool
    {
        foreach ($this->getOrderedWidgets() as $widgetKey) {
            if ($this->isWidgetVisible($widgetKey) && $this->isWidgetAvailable($widgetKey)) {
                return true;
            }
        }

        return false;
    }

    public function moveWidgetUp(string $widget): void
    {
        $this->moveWidget($widget, -1);
    }

    public function moveWidgetDown(string $widget): void
    {
        $this->moveWidget($widget, 1);
    }

    public function getOrderedWidgets(): array
    {
        $this->ensureWidgetOrder();

        return data_get($this->dashboardPreferences, 'order', array_keys($this->availableWidgets));
    }

    public function getWidgetOrderPosition(string $widget): int
    {
        $order = $this->getOrderedWidgets();
        $index = array_search($widget, $order, true);

        return $index === false ? 999 : $index + 1;
    }

    protected function moveWidget(string $widget, int $direction): void
    {
        if (! array_key_exists($widget, $this->availableWidgets)) {
            return;
        }

        $order = $this->getOrderedWidgets();
        $index = array_search($widget, $order, true);

        if ($index === false) {
            return;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= count($order)) {
            return;
        }

        $temp = $order[$target];
        $order[$target] = $order[$index];
        $order[$index] = $temp;

        data_set($this->dashboardPreferences, 'order', $order);
        $this->persistDashboardPreferences();
    }

    protected function ensureWidgetOrder(): void
    {
        $savedOrder = data_get($this->dashboardPreferences, 'order', []);
        $validKeys = array_keys($this->availableWidgets);
        $normalized = array_values(array_intersect($savedOrder, $validKeys));

        foreach ($validKeys as $widgetKey) {
            if (! in_array($widgetKey, $normalized, true)) {
                $normalized[] = $widgetKey;
            }
        }

        data_set($this->dashboardPreferences, 'order', $normalized);
    }

    protected function isWidgetAvailable(string $widget): bool
    {
        return match ($widget) {
            'qbit_downloads_count', 'qbit_download_speed', 'qbit_upload_speed', 'qbit_total_volume', 'qbit_downloads' => $this->qbitConfigured,
            'arr_radarr' => isset($this->arrStats['radarr']) && ServiceSetting::where('service_name', 'radarr')->exists(),
            'arr_sonarr' => isset($this->arrStats['sonarr']) && ServiceSetting::where('service_name', 'sonarr')->exists(),
            'arr_prowlarr' => isset($this->arrStats['prowlarr']) && ServiceSetting::where('service_name', 'prowlarr')->exists(),
            'arr_calendar' => $this->arrConfigured,
            'jellyseerr_total', 'jellyseerr_movies', 'jellyseerr_series', 'jellyseerr_processing', 'jellyseerr_completed' => $this->jellyseerrConfigured,
            'media_users', 'media_top_users' => $this->mediaServicesConfigured,
            default => false,
        };
    }

    protected function persistDashboardPreferences(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $user->dashboard_preferences = $this->dashboardPreferences;
        $user->save();
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

        <div class="w-full md:w-auto">
            <div class="flex flex-wrap items-center gap-2 md:justify-end">
                <button type="button" wire:click="$toggle('showCustomizePanel')"
                    class="cursor-pointer px-3 py-2 bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    {{ __('messages.customize_dashboard') }}
                </button>
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
    </div>

    @if ($showCustomizePanel)
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-4 shadow-sm space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.dashboard_widgets') }}</p>
                <button type="button" wire:click="resetDashboardPreferences"
                    class="cursor-pointer text-xs font-bold uppercase tracking-wider text-core-primary hover:text-core-primary/80 transition">
                    {{ __('messages.reset_dashboard_layout') }}
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach ($this->getOrderedWidgets() as $widgetKey)
                    <div
                        class="flex items-center justify-between gap-3 px-3 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="min-w-0 space-y-1">
                            <span class="block text-sm text-zinc-700 dark:text-zinc-200 truncate">{{ __($availableWidgets[$widgetKey]) }}</span>
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="moveWidgetUp('{{ $widgetKey }}')"
                                    class="cursor-pointer text-zinc-500 hover:text-core-primary disabled:opacity-40 disabled:cursor-not-allowed"
                                    @disabled($loop->first)>
                                    ↑
                                </button>
                                <button type="button" wire:click="moveWidgetDown('{{ $widgetKey }}')"
                                    class="cursor-pointer text-zinc-500 hover:text-core-primary disabled:opacity-40 disabled:cursor-not-allowed"
                                    @disabled($loop->last)>
                                    ↓
                                </button>
                            </div>
                        </div>
                        <input type="checkbox" wire:click="toggleWidget('{{ $widgetKey }}')"
                            @checked($this->isWidgetVisible($widgetKey))
                            @disabled(!$this->isWidgetAvailable($widgetKey))
                            class="rounded border-zinc-300 text-core-primary focus:ring-core-primary">
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        @foreach ($this->getOrderedWidgets() as $widgetKey)
            @if ($this->isWidgetVisible($widgetKey) && $this->isWidgetAvailable($widgetKey))
                @php
                    $statCards = [
                        'qbit_downloads_count' => ['source' => 'qbittorrent', 'label' => __('messages.downloads'), 'value' => $stats['count'], 'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4', 'color' => 'blue'],
                        'qbit_download_speed' => ['source' => 'qbittorrent', 'label' => __('messages.download_speed'), 'value' => $this->formatSize($stats['dl_speed']) . '/s', 'icon' => 'M13 10V3L4 14h7v7l9-11h-7z', 'color' => 'yellow'],
                        'qbit_upload_speed' => ['source' => 'qbittorrent', 'label' => __('messages.upload_speed'), 'value' => $this->formatSize($stats['up_speed']) . '/s', 'icon' => 'M8 7l4-4m0 0l4 4m-4-4v18', 'color' => 'teal'],
                        'qbit_total_volume' => ['source' => 'qbittorrent', 'label' => __('messages.total_volume'), 'value' => $this->formatSize($stats['total_size']), 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'color' => 'purple'],
                        'jellyseerr_total' => ['source' => __('messages.jellyseerr'), 'label' => __('messages.total_requests'), 'value' => $jellyStats['total'] ?? 0, 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2', 'color' => 'core-primary'],
                        'jellyseerr_movies' => ['source' => __('messages.jellyseerr'), 'label' => __('messages.movies'), 'value' => $jellyStats['movie'] ?? 0, 'icon' => 'M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z', 'color' => 'blue'],
                        'jellyseerr_series' => ['source' => __('messages.jellyseerr'), 'label' => __('messages.series'), 'value' => $jellyStats['tv'] ?? 0, 'icon' => 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z', 'color' => 'purple'],
                        'jellyseerr_processing' => ['source' => __('messages.jellyseerr'), 'label' => __('messages.pending'), 'value' => $jellyStats['processing'] ?? 0, 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', 'color' => 'orange'],
                        'jellyseerr_completed' => ['source' => __('messages.jellyseerr'), 'label' => __('messages.completed'), 'value' => $jellyStats['completed'] ?? 0, 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => 'green'],
                    ];
                    $arrCards = [
                        'arr_radarr' => ['service' => 'radarr', 'label' => __('messages.movies'), 'color' => 'indigo', 'icon' => 'M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z'],
                        'arr_sonarr' => ['service' => 'sonarr', 'label' => __('messages.series'), 'color' => 'yellow', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                        'arr_prowlarr' => ['service' => 'prowlarr', 'label' => __('messages.indexers'), 'color' => 'pink', 'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
                    ];
                @endphp

                @if (isset($statCards[$widgetKey]))
                    @php $card = $statCards[$widgetKey]; @endphp
                    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-4 rounded-2xl shadow-sm transition duration-300 group">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-{{ $card['color'] }}-500/10 flex items-center justify-center text-{{ $card['color'] }}-500 group-hover:scale-110 transition-transform">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $card['icon'] }}" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-[9px] font-black text-zinc-400 uppercase tracking-widest block leading-none">{{ $card['source'] }}</span>
                                <span class="text-[11px] font-bold text-zinc-500 uppercase tracking-tight">{{ $card['label'] }}</span>
                            </div>
                        </div>
                        <div class="text-xl font-black text-zinc-900 dark:text-zinc-100 italic tracking-tighter">{{ $card['value'] }}</div>
                    </div>
                @elseif (isset($arrCards[$widgetKey]))
                    @php $cfg = $arrCards[$widgetKey]; $serviceId = $cfg['service']; @endphp
                    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-5 rounded-2xl shadow-sm hover:border-{{ $cfg['color'] }}-500/50 transition duration-300 relative group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-{{ $cfg['color'] }}-500/10 flex items-center justify-center text-{{ $cfg['color'] }}-500 group-hover:scale-110 transition-transform">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $cfg['icon'] }}" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-[10px] font-black text-zinc-400 uppercase tracking-widest">{{ $serviceId }}</span>
                                    <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $cfg['label'] }}</h4>
                                </div>
                            </div>
                            <div class="flex flex-col items-end">
                                <span class="text-2xl font-black text-zinc-900 dark:text-white">{{ $arrStats[$serviceId]['count'] }}</span>
                                <span class="text-[9px] font-bold {{ ($arrStats[$serviceId]['health'] ?? 'OK') === 'OK' ? 'text-green-500' : 'text-yellow-500' }} uppercase tracking-tighter">{{ $arrStats[$serviceId]['health'] ?? 'OK' }}</span>
                            </div>
                        </div>
                    </div>
                @elseif ($widgetKey === 'qbit_downloads')
                    <div class="xl:col-span-3">
                        <livewire:widgets.qbit-downloads />
                    </div>
                @elseif ($widgetKey === 'arr_calendar')
                    <div class="xl:col-span-1">
                        <livewire:widgets.arr-calendar />
                    </div>
                @elseif ($widgetKey === 'media_users')
                    <div class="xl:col-span-2">
                        <livewire:widgets.media-users />
                    </div>
                @elseif ($widgetKey === 'media_top_users')
                    <div class="xl:col-span-2">
                        <livewire:widgets.media-top-users />
                    </div>
                @endif
            @endif
        @endforeach

        @if (
            !$qbitConfigured &&
                !$arrConfigured &&
                !$mediaServicesConfigured)
            <div
                class="col-span-full flex flex-col items-center justify-center py-20 bg-zinc-50 dark:bg-zinc-900/50 rounded-3xl border-2 border-dashed border-zinc-200 dark:border-zinc-800">
                <p class="text-zinc-500 font-medium mb-4 text-center px-6">{{ __('messages.no_services_configured') }}
                </p>
                <a href="/settings" wire:navigate
                    class="px-6 py-2.5 bg-core-primary text-white font-bold rounded-xl shadow-lg shadow-core-primary/20 hover:bg-core-primary/90 transition">
                    {{ __('messages.go_to_settings') }}
                </a>
            </div>
        @elseif (!$this->hasVisibleDashboardContent())
            <div
                class="col-span-full flex flex-col items-center justify-center py-20 bg-zinc-50 dark:bg-zinc-900/50 rounded-3xl border-2 border-dashed border-zinc-200 dark:border-zinc-800">
                <p class="text-zinc-500 font-medium mb-4 text-center px-6">{{ __('messages.no_dashboard_widgets_selected') }}
                </p>
                <button type="button" wire:click="resetDashboardPreferences"
                    class="px-6 py-2.5 bg-core-primary text-white font-bold rounded-xl shadow-lg shadow-core-primary/20 hover:bg-core-primary/90 transition cursor-pointer">
                    {{ __('messages.reset_dashboard_layout') }}
                </button>
            </div>
        @endif
    </div>
</div>
