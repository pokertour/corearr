<?php

use Livewire\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Lazy] class extends Component {
    public bool $isConfigured = false;

    /** @var array<string, int> */
    public array $codec = [];

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
        $this->codec = $analytics['codec'] ?? [];
        $this->totalFiles = (int) ($analytics['total_files'] ?? 0);
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="h-72 flex flex-col items-center justify-center bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl animate-pulse">
            <div class="w-12 h-12 rounded-full bg-zinc-100 dark:bg-zinc-800 mb-4"></div>
            <div class="w-1/2 h-4 bg-zinc-100 dark:bg-zinc-800 rounded mb-2"></div>
            <div class="w-1/3 h-3 bg-zinc-100 dark:bg-zinc-800 rounded"></div>
        </div>
        HTML;
    }
};

?>

<div wire:init="loadData" class="xl:col-span-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-4">
        <div>
            <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.dashboard_lib_codec_title') }}</h4>
            <p class="text-[11px] text-zinc-500 mt-0.5">{{ __('messages.dashboard_lib_codec_subtitle') }}</p>
        </div>
        @if ($totalFiles > 0)
            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 shrink-0">{{ __('messages.dashboard_lib_files_count', ['count' => $totalFiles]) }}</span>
        @endif
    </div>

    @if (!$isConfigured)
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_configure_arr') }}</p>
    @elseif ($totalFiles === 0)
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_no_files') }}</p>
    @else
        @php
            $order = ['h264', 'hevc', 'other', 'unknown'];
            $hex = [
                'h264' => 'rgb(59 130 246)',
                'hevc' => 'rgb(20 184 166)',
                'other' => 'rgb(139 92 246)',
                'unknown' => 'rgb(113 113 122)',
            ];
            $total = max(1, array_sum($codec));
            $stops = [];
            $cursor = -90.0;
            foreach ($order as $key) {
                $n = (int) ($codec[$key] ?? 0);
                if ($n <= 0) {
                    continue;
                }
                $slice = ($n / $total) * 360.0;
                $end = $cursor + $slice;
                $stops[] = $hex[$key].' '.$cursor.'deg '.$end.'deg';
                $cursor = $end;
            }
            $cone = count($stops) ? implode(', ', $stops) : 'rgb(229 231 235) -90deg 270deg';
        @endphp
        <div class="flex flex-col sm:flex-row items-center gap-8">
            <div class="relative w-44 h-44 shrink-0">
                <div
                    class="w-full h-full rounded-full shadow-inner border border-zinc-200 dark:border-zinc-700"
                    style="background: conic-gradient(from -90deg, {{ $cone }});">
                </div>
                <div class="absolute inset-5 rounded-full bg-white dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 flex flex-col items-center justify-center text-center px-2">
                    <span class="text-2xl font-black text-zinc-900 dark:text-white tabular-nums">{{ $totalFiles }}</span>
                    <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ __('messages.dashboard_lib_codec_center_label') }}</span>
                </div>
            </div>
            <div class="flex-1 w-full space-y-2.5">
                @foreach ($order as $key)
                    @php $n = (int) ($codec[$key] ?? 0); @endphp
                    @if ($n > 0)
                        <div class="flex items-center justify-between gap-3 text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
                            <span class="inline-flex items-center gap-2 min-w-0">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: {{ $hex[$key] }}"></span>
                                <span class="truncate">{{ __('messages.dashboard_lib_codec_'.$key) }}</span>
                            </span>
                            <span class="tabular-nums shrink-0">{{ $n }} ({{ round(($n / $total) * 100, 1) }}%)</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</div>
