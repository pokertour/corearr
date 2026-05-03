<?php

use Livewire\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Lazy] class extends Component {
    /** @var array{radarr: array<int, array<string, mixed>>, sonarr: array<int, array<string, mixed>>} */
    public array $panels = ['radarr' => [], 'sonarr' => []];

    public bool $isConfigured = false;

    public function mount(): void
    {
        $this->isConfigured = ServiceSetting::query()
            ->whereIn('service_name', ['radarr', 'sonarr'])
            ->where('is_active', true)
            ->whereNotNull('base_url')
            ->exists();
    }

    public function loadData(MediaStackService $service): void
    {
        $this->panels = $service->getArrDiskspacePanels();
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="xl:col-span-2 h-64 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl animate-pulse"></div>
        HTML;
    }
};

?>

<div wire:init="loadData" class="xl:col-span-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.dashboard_arr_disk_title') }}</h4>
            <p class="text-[11px] text-zinc-500 mt-0.5">{{ __('messages.dashboard_arr_disk_subtitle') }}</p>
        </div>
    </div>

    @if (!$isConfigured)
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_configure_arr') }}</p>
    @elseif (empty($panels['radarr']) && empty($panels['sonarr']))
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_arr_disk_empty') }}</p>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @foreach (['radarr' => __('messages.dashboard_arr_disk_radarr'), 'sonarr' => __('messages.dashboard_arr_disk_sonarr')] as $key => $label)
                @if (!empty($panels[$key]))
                    <div>
                        <h5 class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-3">{{ $label }}</h5>
                        <div class="space-y-4 max-h-56 overflow-y-auto pr-1">
                            @foreach ($panels[$key] as $vol)
                                @php
                                    $usedPct = (float) ($vol['used_percent'] ?? 0);
                                    $barColor = $usedPct >= 92 ? 'bg-red-500' : ($usedPct >= 82 ? 'bg-amber-500' : 'bg-emerald-500');
                                @endphp
                                <div>
                                    <div class="flex justify-between text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 mb-1 gap-2">
                                        <span class="truncate min-w-0" title="{{ $vol['path'] ?? '' }}">{{ $vol['label'] ?? ($vol['path'] ?? '/') }}</span>
                                        <span class="shrink-0 tabular-nums">{{ round($usedPct, 1) }}%</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden mb-1">
                                        <div class="h-full {{ $barColor }} transition-all" style="width: {{ min(100, $usedPct) }}%"></div>
                                    </div>
                                    <p class="text-[10px] text-zinc-500">
                                        {{ __('messages.dashboard_arr_disk_used_free', ['used' => $this->formatBytes((int) ($vol['used'] ?? 0)), 'free' => $this->formatBytes((int) ($vol['free'] ?? 0))]) }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</div>
