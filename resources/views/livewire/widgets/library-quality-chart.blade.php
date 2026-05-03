<?php

use Livewire\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Lazy] class extends Component {
    public bool $isConfigured = false;

    /** @var array<int, array{label: string, count: int}> */
    public array $qualityRows = [];

    public int $totalFiles = 0;

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
        $analytics = $service->getLibraryFileAnalytics();
        $this->qualityRows = $analytics['quality_rows'] ?? [];
        $this->totalFiles = (int) ($analytics['total_files'] ?? 0);
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="h-96 flex flex-col items-center justify-center bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl animate-pulse">
            <div class="w-10 h-10 bg-zinc-100 dark:bg-zinc-800 rounded-lg mb-3"></div>
            <div class="w-1/2 h-4 bg-zinc-100 dark:bg-zinc-800 rounded mb-2"></div>
            <div class="w-2/3 h-3 bg-zinc-100 dark:bg-zinc-800 rounded"></div>
        </div>
        HTML;
    }
};

?>

<div wire:init="loadData" class="xl:col-span-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-4">
        <div>
            <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.dashboard_lib_quality_title') }}</h4>
            <p class="text-[11px] text-zinc-500 mt-0.5">{{ __('messages.dashboard_lib_quality_subtitle') }}</p>
        </div>
        @if ($totalFiles > 0)
            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 shrink-0">{{ __('messages.dashboard_lib_files_count', ['count' => $totalFiles]) }}</span>
        @endif
    </div>

    @if (!$isConfigured)
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_configure_arr') }}</p>
    @elseif ($totalFiles === 0 || empty($qualityRows))
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_no_files') }}</p>
    @else
        @php
            $maxCount = max(1, ...array_map(fn ($r) => (int) ($r['count'] ?? 0), $qualityRows));
        @endphp
        <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
            @foreach ($qualityRows as $row)
                @php
                    $value = (int) ($row['count'] ?? 0);
                    $percent = round(($value / $maxCount) * 100, 1);
                    $barPercent = $totalFiles > 0 ? round(($value / $totalFiles) * 100, 1) : 0;
                @endphp
                <div>
                    <div class="flex justify-between text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 mb-1 gap-2">
                        <span class="truncate min-w-0" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <span class="shrink-0 tabular-nums">{{ $value }} ({{ $barPercent }}%)</span>
                    </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                        <div class="h-full bg-core-primary transition-all" style="width: {{ $percent }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
