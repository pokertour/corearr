<?php

use Carbon\Carbon;
use Livewire\Component;

new class extends Component {
    public bool $qbitConfigured = false;

    /** @var array<int, array<string, mixed>> */
    public array $speedHistory = [];

    public function formatSize($bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[(int) $i];
    }
};

?>

<div class="xl:col-span-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.ops_speed_24h_title') }}</h4>
        <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ __('messages.ops_period_24h') }}</span>
    </div>
    <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_qbit_cache') }}</p>
    @if ($qbitConfigured && ! empty($speedHistory))
        @php
            $graphWidth = 340;
            $graphHeight = 90;
            $dlValues = array_map(fn ($sample) => (int) ($sample['dl'] ?? 0), $speedHistory);
            $ulValues = array_map(fn ($sample) => (int) ($sample['ul'] ?? 0), $speedHistory);
            $maxValue = max(max($dlValues), max($ulValues), 1);
            $buildPolyline = function (array $values, int $height = 90, int $width = 340) use ($maxValue): string {
                $count = max(count($values) - 1, 1);
                $points = [];
                foreach ($values as $index => $value) {
                    $x = round(($index / $count) * $width, 2);
                    $y = round($height - (($value / $maxValue) * $height), 2);
                    $points[] = "{$x},{$y}";
                }

                return implode(' ', $points);
            };
            $buildPoints = function (array $values, int $height = 90, int $width = 340) use ($maxValue): array {
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
                    'time' => Carbon::createFromTimestamp($timestamp)->format('H:i'),
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
