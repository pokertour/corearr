<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Services\MediaStack\MediaStackService;
use App\Services\MediaStack\JellyseerrService;
use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Cache;

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
    public array $configuredServices = [];
    public array $speedHistory = [];
    public array $torrentStateStats = [];
    public array $requestPipelineStats = [];
    public array $indexerHealthStats = [];
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
        'ops_speed_24h' => 'messages.dashboard_widget_ops_speed_24h',
        'ops_torrent_states' => 'messages.dashboard_widget_ops_torrent_states',
        'ops_request_pipeline' => 'messages.dashboard_widget_ops_request_pipeline',
        'ops_indexer_health' => 'messages.dashboard_widget_ops_indexer_health',
    ];

    public function mount(MediaStackService $service, JellyseerrService $jellyseerr)
    {
        $downServices = [];

        $this->configuredServices = ServiceSetting::query()
            ->whereIn('service_name', ['qbittorrent', 'radarr', 'sonarr', 'prowlarr', 'jellyseerr', 'emby', 'jellyfin'])
            ->where('is_active', true)
            ->pluck('is_active', 'service_name')
            ->map(fn ($isActive) => (bool) $isActive)
            ->all();

        $this->qbitConfigured = $this->isServiceConfigured('qbittorrent');
        $this->arrConfigured = $this->isServiceConfigured('sonarr') || $this->isServiceConfigured('radarr');
        $this->jellyseerrConfigured = $this->isServiceConfigured('jellyseerr');
        $this->mediaServicesConfigured = $this->isServiceConfigured('jellyseerr')
            || $this->isServiceConfigured('emby')
            || $this->isServiceConfigured('jellyfin');

        $this->dashboardPreferences = auth()->user()?->dashboard_preferences ?? ['widgets' => [], 'order' => []];
        $this->ensureWidgetOrder();

        if ($this->qbitConfigured) {
            $qbit = $service->getQbitData();
            $this->stats['dl_speed'] = $qbit['server_state']['dl_info_speed'] ?? 0;
            $this->stats['up_speed'] = $qbit['server_state']['up_info_speed'] ?? 0;
            $this->stats['count'] = count($qbit['torrents'] ?? []);
            $this->stats['total_size'] = collect($qbit['torrents'] ?? [])->sum('size');
            $this->recordSpeedSample($this->stats['dl_speed'], $this->stats['up_speed']);
            $this->speedHistory = $this->buildSpeedHistory();
            $this->torrentStateStats = $this->buildTorrentStateStats($qbit['torrents'] ?? []);

            if (empty($qbit)) {
                $downServices[] = 'qBittorrent';
            }
        }

        $this->arrStats = $service->getArrStats();

        if ($this->jellyseerrConfigured) {
            $this->jellyStats = $jellyseerr->getRequestCounts();
            $this->requestPipelineStats = $this->buildRequestPipelineStats();
            if (empty($this->jellyStats)) {
                $downServices[] = 'Jellyseerr';
            }
        }

        if ($this->isServiceConfigured('prowlarr')) {
            $indexers = $service->getIndexers();
            $this->indexerHealthStats = $this->buildIndexerHealthStats($indexers);

            if (empty($indexers)) {
                $downServices[] = 'Prowlarr';
            }
        }

        if ($this->isServiceConfigured('radarr') && ! isset($this->arrStats['radarr'])) {
            $downServices[] = 'Radarr';
        }
        if ($this->isServiceConfigured('sonarr') && ! isset($this->arrStats['sonarr'])) {
            $downServices[] = 'Sonarr';
        }

        $downServices = array_values(array_unique($downServices));
        if (! empty($downServices)) {
            $this->dispatch(
                'notify',
                title: __('messages.service_down_notification_title'),
                message: __('messages.service_down_notification_message', ['services' => implode(', ', $downServices)]),
                type: 'warning'
            );
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
            'arr_radarr' => isset($this->arrStats['radarr']) && $this->isServiceConfigured('radarr'),
            'arr_sonarr' => isset($this->arrStats['sonarr']) && $this->isServiceConfigured('sonarr'),
            'arr_prowlarr' => isset($this->arrStats['prowlarr']) && $this->isServiceConfigured('prowlarr'),
            'arr_calendar' => $this->arrConfigured,
            'jellyseerr_total', 'jellyseerr_movies', 'jellyseerr_series', 'jellyseerr_processing', 'jellyseerr_completed' => $this->jellyseerrConfigured,
            'media_users', 'media_top_users' => $this->mediaServicesConfigured,
            'ops_speed_24h', 'ops_torrent_states' => $this->qbitConfigured,
            'ops_request_pipeline' => $this->jellyseerrConfigured,
            'ops_indexer_health' => $this->isServiceConfigured('prowlarr'),
            default => false,
        };
    }

    protected function isServiceConfigured(string $service): bool
    {
        return (bool) ($this->configuredServices[$service] ?? false);
    }

    protected function recordSpeedSample(int $downloadSpeed, int $uploadSpeed): void
    {
        $key = 'dashboard:qbit_speed_samples';
        $samples = Cache::get($key, []);
        $cutoff = now()->subDay()->timestamp;

        $samples = array_values(array_filter($samples, fn ($sample) => ($sample['ts'] ?? 0) >= $cutoff));
        $samples[] = [
            'ts' => now()->timestamp,
            'dl' => max(0, $downloadSpeed),
            'ul' => max(0, $uploadSpeed),
        ];

        if (count($samples) > 288) {
            $samples = array_slice($samples, -288);
        }

        Cache::put($key, $samples, now()->addHours(30));
    }

    protected function buildSpeedHistory(): array
    {
        $samples = Cache::get('dashboard:qbit_speed_samples', []);
        if (empty($samples)) {
            return [];
        }

        return array_slice($samples, -24);
    }

    protected function buildTorrentStateStats(array $torrents): array
    {
        $stats = [
            'downloading' => 0,
            'seeding' => 0,
            'paused' => 0,
            'stalled' => 0,
            'other' => 0,
        ];

        foreach ($torrents as $torrent) {
            $state = strtolower((string) ($torrent['state'] ?? ''));

            if (str_contains($state, 'downloading') || str_contains($state, 'meta')) {
                $stats['downloading']++;
            } elseif (str_contains($state, 'stalledup')) {
                $stats['seeding']++;
            } elseif (str_contains($state, 'stalleddl')) {
                $stats['stalled']++;
            } elseif (str_contains($state, 'upload') || str_contains($state, 'seed')) {
                $stats['seeding']++;
            } elseif (str_contains($state, 'pause')) {
                $stats['paused']++;
            } else {
                $stats['other']++;
            }
        }

        return $stats;
    }

    protected function buildRequestPipelineStats(): array
    {
        return [
            'pending' => (int) ($this->jellyStats['processing'] ?? 0),
            'available' => (int) ($this->jellyStats['available'] ?? 0),
            'completed' => (int) ($this->jellyStats['completed'] ?? 0),
            'total' => (int) ($this->jellyStats['total'] ?? 0),
        ];
    }

    protected function buildIndexerHealthStats(array $indexers): array
    {
        $enabled = 0;
        $degraded = 0;
        $disabled = 0;

        foreach ($indexers as $indexer) {
            $isEnabled = (bool) ($indexer['enable'] ?? false);
            $hasError = ! empty($indexer['lastError']) || ! empty($indexer['message']);

            if (! $isEnabled) {
                $disabled++;
            } elseif ($hasError) {
                $degraded++;
            } else {
                $enabled++;
            }
        }

        return [
            'enabled' => $enabled,
            'degraded' => $degraded,
            'disabled' => $disabled,
            'total' => count($indexers),
        ];
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
                    @if (isset($arrStats[$s]) && $this->isServiceConfigured($s))
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
                        'arr_radarr' => ['service' => 'radarr', 'label' => __('messages.movies'), 'iconBgClass' => 'bg-indigo-500/10', 'iconTextClass' => 'text-indigo-500', 'hoverBorderClass' => 'hover:border-indigo-500/50', 'icon' => 'M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z'],
                        'arr_sonarr' => ['service' => 'sonarr', 'label' => __('messages.series'), 'iconBgClass' => 'bg-yellow-500/10', 'iconTextClass' => 'text-yellow-500', 'hoverBorderClass' => 'hover:border-yellow-500/50', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                        'arr_prowlarr' => ['service' => 'prowlarr', 'label' => __('messages.indexers'), 'iconBgClass' => 'bg-pink-500/10', 'iconTextClass' => 'text-pink-500', 'hoverBorderClass' => 'hover:border-pink-500/50', 'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
                    ];
                @endphp

                @if (isset($statCards[$widgetKey]))
                    @php
                        $card = $statCards[$widgetKey];
                        $isCorePrimary = $card['color'] === 'core-primary';
                        $iconBgClass = $isCorePrimary ? 'bg-core-primary/10' : 'bg-' . $card['color'] . '-500/10';
                        $iconTextClass = $isCorePrimary ? 'text-core-primary' : 'text-' . $card['color'] . '-500';
                        $hoverBorderClass = $isCorePrimary ? 'hover:border-core-primary/50' : 'hover:border-' . $card['color'] . '-500/50';
                    @endphp
                    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-5 rounded-2xl shadow-sm {{ $hoverBorderClass }} transition duration-300 relative group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl {{ $iconBgClass }} flex items-center justify-center {{ $iconTextClass }} group-hover:scale-110 transition-transform">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $card['icon'] }}" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-[10px] font-black text-zinc-400 uppercase tracking-widest">{{ $card['source'] }}</span>
                                    <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $card['label'] }}</h4>
                                </div>
                            </div>
                            <div class="flex flex-col items-end">
                                <span class="text-2xl font-black text-zinc-900 dark:text-white">{{ $card['value'] }}</span>
                            </div>
                        </div>
                    </div>
                @elseif (isset($arrCards[$widgetKey]))
                    @php $cfg = $arrCards[$widgetKey]; $serviceId = $cfg['service']; @endphp
                    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-5 rounded-2xl shadow-sm {{ $cfg['hoverBorderClass'] }} transition duration-300 relative group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl {{ $cfg['iconBgClass'] }} flex items-center justify-center {{ $cfg['iconTextClass'] }} group-hover:scale-110 transition-transform">
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
                @elseif ($widgetKey === 'ops_speed_24h')
                    <div class="xl:col-span-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.ops_speed_24h_title') }}</h4>
                            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ __('messages.ops_period_24h') }}</span>
                        </div>
                        <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_qbit_cache') }}</p>
                        @if ($qbitConfigured && !empty($speedHistory))
                            @php
                                $graphWidth = 340;
                                $graphHeight = 90;
                                $dlValues = array_map(fn ($sample) => (int) ($sample['dl'] ?? 0), $speedHistory);
                                $ulValues = array_map(fn ($sample) => (int) ($sample['ul'] ?? 0), $speedHistory);
                                $maxValue = max(max($dlValues), max($ulValues), 1);
                                $buildPolyline = function (array $values, int $height = 90, int $width = 340) use ($maxValue) {
                                    $count = max(count($values) - 1, 1);
                                    $points = [];
                                    foreach ($values as $index => $value) {
                                        $x = round(($index / $count) * $width, 2);
                                        $y = round($height - (($value / $maxValue) * $height), 2);
                                        $points[] = "{$x},{$y}";
                                    }
                                    return implode(' ', $points);
                                };
                                $buildPoints = function (array $values, int $height = 90, int $width = 340) use ($maxValue) {
                                    $count = max(count($values) - 1, 1);
                                    $points = [];
                                    foreach ($values as $index => $value) {
                                        $x = round(($index / $count) * $width, 2);
                                        $y = round($height - (($value / $maxValue) * $height), 2);
                                        $points[] = ['x' => $x, 'y' => $y];
                                    }
                                    return $points;
                                };
                                $dlPoints = $buildPoints($dlValues, $graphHeight, $graphWidth);
                                $ulPoints = $buildPoints($ulValues, $graphHeight, $graphWidth);
                                $hoverSamples = array_map(function ($sample) {
                                    $timestamp = (int) ($sample['ts'] ?? now()->timestamp);
                                    return [
                                        'time' => \Carbon\Carbon::createFromTimestamp($timestamp)->format('H:i'),
                                        'dl' => $this->formatSize((int) ($sample['dl'] ?? 0)) . '/s',
                                        'ul' => $this->formatSize((int) ($sample['ul'] ?? 0)) . '/s',
                                    ];
                                }, $speedHistory);
                            @endphp
                            <div
                                class="relative mb-3"
                                x-data="{
                                    width: {{ $graphWidth }},
                                    dlPoints: @js($dlPoints),
                                    ulPoints: @js($ulPoints),
                                    samples: @js($hoverSamples),
                                    activeIndex: null,
                                    setActive(event) {
                                        const rect = event.currentTarget.getBoundingClientRect();
                                        const x = Math.max(0, Math.min(this.width, ((event.clientX - rect.left) / rect.width) * this.width));
                                        let nearest = 0;
                                        let minDistance = Infinity;
                                        this.dlPoints.forEach((point, index) => {
                                            const distance = Math.abs(point.x - x);
                                            if (distance < minDistance) {
                                                minDistance = distance;
                                                nearest = index;
                                            }
                                        });
                                        this.activeIndex = nearest;
                                    }
                                }"
                                @mousemove="setActive($event)"
                                @mouseleave="activeIndex = null"
                            >
                                <svg viewBox="0 0 {{ $graphWidth }} {{ $graphHeight }}" preserveAspectRatio="none" class="w-full h-28">
                                    <polyline fill="none" stroke="rgb(59 130 246)" stroke-width="2.5" points="{{ $buildPolyline($dlValues, $graphHeight, $graphWidth) }}" />
                                    <polyline fill="none" stroke="rgb(20 184 166)" stroke-width="2.5" points="{{ $buildPolyline($ulValues, $graphHeight, $graphWidth) }}" />

                                    <template x-if="activeIndex !== null">
                                        <g>
                                            <line x1="0" y1="0" x2="0" y2="{{ $graphHeight }}" stroke="rgb(148 163 184)" stroke-width="1" stroke-dasharray="3 3"
                                                  :x1="dlPoints[activeIndex].x" :x2="dlPoints[activeIndex].x"></line>
                                            <circle r="4" fill="rgb(59 130 246)" stroke="white" stroke-width="1.5"
                                                    :cx="dlPoints[activeIndex].x" :cy="dlPoints[activeIndex].y"></circle>
                                            <circle r="4" fill="rgb(20 184 166)" stroke="white" stroke-width="1.5"
                                                    :cx="ulPoints[activeIndex].x" :cy="ulPoints[activeIndex].y"></circle>
                                        </g>
                                    </template>
                                </svg>

                                <template x-if="activeIndex !== null">
                                    <div
                                        class="absolute z-10 -translate-y-full mb-1 px-2.5 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white/95 dark:bg-zinc-900/95 shadow text-[10px] font-bold text-zinc-700 dark:text-zinc-200 whitespace-nowrap pointer-events-none"
                                        :style="`left: calc(${(dlPoints[activeIndex].x / width) * 100}% - 45px); top: 0;`"
                                    >
                                        <p class="text-zinc-500" x-text="samples[activeIndex].time"></p>
                                        <p class="text-blue-600 dark:text-blue-400">DL: <span x-text="samples[activeIndex].dl"></span></p>
                                        <p class="text-teal-600 dark:text-teal-400">UL: <span x-text="samples[activeIndex].ul"></span></p>
                                    </div>
                                </template>
                            </div>
                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div class="rounded-xl border border-blue-200/60 dark:border-blue-500/20 bg-blue-50/60 dark:bg-blue-500/5 p-3">
                                    <p class="font-bold text-blue-600 dark:text-blue-400 uppercase tracking-widest">DL</p>
                                    <p class="text-zinc-900 dark:text-zinc-100 font-black">{{ $this->formatSize(end($dlValues) ?: 0) }}/s</p>
                                </div>
                                <div class="rounded-xl border border-teal-200/60 dark:border-teal-500/20 bg-teal-50/60 dark:bg-teal-500/5 p-3">
                                    <p class="font-bold text-teal-600 dark:text-teal-400 uppercase tracking-widest">UL</p>
                                    <p class="text-zinc-900 dark:text-zinc-100 font-black">{{ $this->formatSize(end($ulValues) ?: 0) }}/s</p>
                                </div>
                            </div>
                        @else
                            <p class="text-sm text-zinc-500">{{ __('messages.qbit_not_configured') }}</p>
                        @endif
                    </div>
                @elseif ($widgetKey === 'ops_torrent_states')
                    <div class="xl:col-span-1 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
                        <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.ops_torrent_states_title') }}</h4>
                        <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_qbit_live') }}</p>
                        @if ($qbitConfigured && !empty($torrentStateStats))
                            @php
                                $totalStates = max(array_sum($torrentStateStats), 1);
                                $stateRows = [
                                    ['key' => 'downloading', 'label' => __('messages.ops_downloading'), 'color' => 'bg-blue-500'],
                                    ['key' => 'seeding', 'label' => __('messages.ops_seeding'), 'color' => 'bg-teal-500'],
                                    ['key' => 'paused', 'label' => __('messages.ops_paused'), 'color' => 'bg-yellow-500'],
                                    ['key' => 'stalled', 'label' => __('messages.ops_stalled'), 'color' => 'bg-orange-500'],
                                    ['key' => 'other', 'label' => __('messages.ops_other'), 'color' => 'bg-zinc-500'],
                                ];
                            @endphp
                            <div class="space-y-3">
                                @foreach ($stateRows as $row)
                                    @php
                                        $value = (int) ($torrentStateStats[$row['key']] ?? 0);
                                        $percent = round(($value / $totalStates) * 100, 1);
                                    @endphp
                                    <div>
                                        <div class="flex justify-between text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 mb-1">
                                            <span>{{ $row['label'] }}</span>
                                            <span>{{ $value }} ({{ $percent }}%)</span>
                                        </div>
                                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                            <div class="h-full {{ $row['color'] }}" style="width: {{ $percent }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-zinc-500">{{ __('messages.qbit_not_configured') }}</p>
                        @endif
                    </div>
                @elseif ($widgetKey === 'ops_request_pipeline')
                    <div class="xl:col-span-1 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
                        <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.ops_request_pipeline_title') }}</h4>
                        <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_jellyseerr_live') }}</p>
                        @if ($jellyseerrConfigured && !empty($requestPipelineStats))
                            @php
                                $pipelineTotal = max((int) ($requestPipelineStats['total'] ?? 0), 1);
                                $pipelineRows = [
                                    ['key' => 'pending', 'label' => __('messages.pending'), 'color' => 'bg-orange-500'],
                                    ['key' => 'available', 'label' => __('messages.available'), 'color' => 'bg-blue-500'],
                                    ['key' => 'completed', 'label' => __('messages.completed'), 'color' => 'bg-green-500'],
                                ];
                            @endphp
                            <div class="space-y-3">
                                @foreach ($pipelineRows as $row)
                                    @php
                                        $value = (int) ($requestPipelineStats[$row['key']] ?? 0);
                                        $percent = round(($value / $pipelineTotal) * 100, 1);
                                    @endphp
                                    <div>
                                        <div class="flex justify-between text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 mb-1">
                                            <span>{{ $row['label'] }}</span>
                                            <span>{{ $value }} ({{ $percent }}%)</span>
                                        </div>
                                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                            <div class="h-full {{ $row['color'] }}" style="width: {{ $percent }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-zinc-500">{{ __('messages.not_configured_title', ['service' => 'Jellyseerr']) }}</p>
                        @endif
                    </div>
                @elseif ($widgetKey === 'ops_indexer_health')
                    <div class="xl:col-span-1 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
                        <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.ops_indexer_health_title') }}</h4>
                        <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_prowlarr_live') }}</p>
                        @if ($this->isServiceConfigured('prowlarr') && !empty($indexerHealthStats))
                            @php
                                $totalIndexers = max((int) ($indexerHealthStats['total'] ?? 0), 1);
                                $indexerRows = [
                                    ['key' => 'enabled', 'label' => __('messages.enabled'), 'color' => 'bg-green-500'],
                                    ['key' => 'degraded', 'label' => __('messages.ops_degraded'), 'color' => 'bg-yellow-500'],
                                    ['key' => 'disabled', 'label' => __('messages.disabled'), 'color' => 'bg-zinc-500'],
                                ];
                            @endphp
                            <div class="space-y-3">
                                @foreach ($indexerRows as $row)
                                    @php
                                        $value = (int) ($indexerHealthStats[$row['key']] ?? 0);
                                        $percent = round(($value / $totalIndexers) * 100, 1);
                                    @endphp
                                    <div>
                                        <div class="flex justify-between text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 mb-1">
                                            <span>{{ $row['label'] }}</span>
                                            <span>{{ $value }} ({{ $percent }}%)</span>
                                        </div>
                                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                            <div class="h-full {{ $row['color'] }}" style="width: {{ $percent }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-zinc-500">{{ __('messages.not_configured_title', ['service' => 'Prowlarr']) }}</p>
                        @endif
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
