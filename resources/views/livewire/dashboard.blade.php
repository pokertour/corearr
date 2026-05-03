<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Services\MediaStack\MediaStackService;
use App\Services\MediaStack\JellyseerrService;
use App\Models\ServiceSetting;
use App\Models\QbittorrentSpeedSample;

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
        'lib_codec_chart' => 'messages.dashboard_widget_lib_codec_chart',
        'lib_quality_chart' => 'messages.dashboard_widget_lib_quality_chart',
        'arr_diskspace' => 'messages.dashboard_widget_arr_diskspace',
        'arr_queue' => 'messages.dashboard_widget_arr_queue',
        'arr_monitored' => 'messages.dashboard_widget_arr_monitored',
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
            $this->speedHistory = QbittorrentSpeedSample::chartSamplesWithinHours(
                max(1, min(168, (int) config('corearr.qbittorrent_speed_sample_retention_hours', 24)))
            );
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
            'lib_codec_chart', 'lib_quality_chart', 'arr_diskspace', 'arr_queue', 'arr_monitored' => $this->arrConfigured,
            default => false,
        };
    }

    protected function isServiceConfigured(string $service): bool
    {
        return (bool) ($this->configuredServices[$service] ?? false);
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

    /**
     * @return array{source: string, label: string, value: string|int|float, icon: string, color: string}|null
     */
    public function statCardPayload(string $widgetKey): ?array
    {
        return match ($widgetKey) {
            'qbit_downloads_count' => [
                'source' => 'qbittorrent',
                'label' => __('messages.downloads'),
                'value' => $this->stats['count'],
                'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
                'color' => 'blue',
            ],
            'qbit_download_speed' => [
                'source' => 'qbittorrent',
                'label' => __('messages.download_speed'),
                'value' => $this->formatSize($this->stats['dl_speed']) . '/s',
                'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
                'color' => 'yellow',
            ],
            'qbit_upload_speed' => [
                'source' => 'qbittorrent',
                'label' => __('messages.upload_speed'),
                'value' => $this->formatSize($this->stats['up_speed']) . '/s',
                'icon' => 'M8 7l4-4m0 0l4 4m-4-4v18',
                'color' => 'teal',
            ],
            'qbit_total_volume' => [
                'source' => 'qbittorrent',
                'label' => __('messages.total_volume'),
                'value' => $this->formatSize($this->stats['total_size']),
                'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                'color' => 'purple',
            ],
            'jellyseerr_total' => [
                'source' => __('messages.jellyseerr'),
                'label' => __('messages.total_requests'),
                'value' => $this->jellyStats['total'] ?? 0,
                'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2',
                'color' => 'core-primary',
            ],
            'jellyseerr_movies' => [
                'source' => __('messages.jellyseerr'),
                'label' => __('messages.movies'),
                'value' => $this->jellyStats['movie'] ?? 0,
                'icon' => 'M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z',
                'color' => 'blue',
            ],
            'jellyseerr_series' => [
                'source' => __('messages.jellyseerr'),
                'label' => __('messages.series'),
                'value' => $this->jellyStats['tv'] ?? 0,
                'icon' => 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
                'color' => 'purple',
            ],
            'jellyseerr_processing' => [
                'source' => __('messages.jellyseerr'),
                'label' => __('messages.pending'),
                'value' => $this->jellyStats['processing'] ?? 0,
                'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                'color' => 'orange',
            ],
            'jellyseerr_completed' => [
                'source' => __('messages.jellyseerr'),
                'label' => __('messages.completed'),
                'value' => $this->jellyStats['completed'] ?? 0,
                'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'color' => 'green',
            ],
            default => null,
        };
    }

    /**
     * @return array{service: string, label: string, iconBgClass: string, iconTextClass: string, hoverBorderClass: string, icon: string, count: int|string, health: string}|null
     */
    public function arrCardPayload(string $widgetKey): ?array
    {
        $configs = [
            'arr_radarr' => [
                'service' => 'radarr',
                'label' => __('messages.movies'),
                'iconBgClass' => 'bg-indigo-500/10',
                'iconTextClass' => 'text-indigo-500',
                'hoverBorderClass' => 'hover:border-indigo-500/50',
                'icon' => 'M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z',
            ],
            'arr_sonarr' => [
                'service' => 'sonarr',
                'label' => __('messages.series'),
                'iconBgClass' => 'bg-yellow-500/10',
                'iconTextClass' => 'text-yellow-500',
                'hoverBorderClass' => 'hover:border-yellow-500/50',
                'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
            ],
            'arr_prowlarr' => [
                'service' => 'prowlarr',
                'label' => __('messages.indexers'),
                'iconBgClass' => 'bg-pink-500/10',
                'iconTextClass' => 'text-pink-500',
                'hoverBorderClass' => 'hover:border-pink-500/50',
                'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
            ],
        ];

        if (! isset($configs[$widgetKey])) {
            return null;
        }

        $cfg = $configs[$widgetKey];
        $serviceId = $cfg['service'];
        $arr = $this->arrStats[$serviceId] ?? [];

        return [
            ...$cfg,
            'count' => $arr['count'] ?? 0,
            'health' => (string) ($arr['health'] ?? 'OK'),
        ];
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
                @if (($statCard = $this->statCardPayload($widgetKey)))
                    <livewire:widgets.dashboard-stat-card
                        wire:key="dashboard-stat-{{ $widgetKey }}"
                        :source="$statCard['source']"
                        :label="$statCard['label']"
                        :value-display="$statCard['value']"
                        :icon-path="$statCard['icon']"
                        :color="$statCard['color']"
                    />
                @elseif (($arrCard = $this->arrCardPayload($widgetKey)))
                    <livewire:widgets.arr-stat-card
                        wire:key="dashboard-arr-{{ $widgetKey }}"
                        :service-id="$arrCard['service']"
                        :label="$arrCard['label']"
                        :icon-path="$arrCard['icon']"
                        :icon-bg-class="$arrCard['iconBgClass']"
                        :icon-text-class="$arrCard['iconTextClass']"
                        :hover-border-class="$arrCard['hoverBorderClass']"
                        :count="$arrCard['count']"
                        :health="$arrCard['health']"
                    />
                @elseif ($widgetKey === 'qbit_downloads')
                    <div class="xl:col-span-3">
                        <livewire:widgets.qbit-downloads wire:key="widgets-qbit-downloads" />
                    </div>
                @elseif ($widgetKey === 'arr_calendar')
                    <div class="xl:col-span-1">
                        <livewire:widgets.arr-calendar wire:key="widgets-arr-calendar" />
                    </div>
                @elseif ($widgetKey === 'media_users')
                    <div class="xl:col-span-2">
                        <livewire:widgets.media-users wire:key="widgets-media-users" />
                    </div>
                @elseif ($widgetKey === 'media_top_users')
                    <div class="xl:col-span-2">
                        <livewire:widgets.media-top-users wire:key="widgets-media-top-users" />
                    </div>
                @elseif ($widgetKey === 'ops_speed_24h')
                    <livewire:widgets.ops-speed-24h
                        wire:key="widgets-ops-speed-24h"
                        :qbit-configured="$qbitConfigured"
                        :speed-history="$speedHistory"
                    />
                @elseif ($widgetKey === 'ops_torrent_states')
                    <livewire:widgets.ops-torrent-states
                        wire:key="widgets-ops-torrent-states"
                        :qbit-configured="$qbitConfigured"
                        :torrent-state-stats="$torrentStateStats"
                    />
                @elseif ($widgetKey === 'ops_request_pipeline')
                    <livewire:widgets.ops-request-pipeline
                        wire:key="widgets-ops-request-pipeline"
                        :jellyseerr-configured="$jellyseerrConfigured"
                        :request-pipeline-stats="$requestPipelineStats"
                    />
                @elseif ($widgetKey === 'ops_indexer_health')
                    <livewire:widgets.ops-indexer-health
                        wire:key="widgets-ops-indexer-health"
                        :prowlarr-configured="$this->isServiceConfigured('prowlarr')"
                        :indexer-health-stats="$indexerHealthStats"
                    />
                @elseif ($widgetKey === 'lib_codec_chart')
                    <livewire:widgets.library-codec-chart wire:key="widgets-library-codec-chart" />
                @elseif ($widgetKey === 'lib_quality_chart')
                    <livewire:widgets.library-quality-chart wire:key="widgets-library-quality-chart" />
                @elseif ($widgetKey === 'arr_diskspace')
                    <livewire:widgets.arr-diskspace wire:key="widgets-arr-diskspace" />
                @elseif ($widgetKey === 'arr_queue')
                    <livewire:widgets.arr-queue-overview wire:key="widgets-arr-queue" />
                @elseif ($widgetKey === 'arr_monitored')
                    <livewire:widgets.arr-monitored wire:key="widgets-arr-monitored" />
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
