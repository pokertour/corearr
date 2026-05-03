<?php

use Livewire\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Lazy] class extends Component {
    /** @var array<string, int|bool> */
    public array $radarr = [];

    /** @var array<string, int|bool> */
    public array $sonarr = [];

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
        $data = $service->getArrMonitoredSummary();
        $this->radarr = $data['radarr'] ?? [];
        $this->sonarr = $data['sonarr'] ?? [];
    }

    /**
     * @param  array<string, int|bool>  $block
     */
    public function barPercents(array $block): array
    {
        $mon = (int) ($block['monitored'] ?? 0);
        $unmon = (int) ($block['unmonitored'] ?? 0);
        $total = max(1, $mon + $unmon);

        return [
            'monitored_pct' => round(($mon / $total) * 100, 1),
            'unmonitored_pct' => round(($unmon / $total) * 100, 1),
        ];
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="h-72 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl animate-pulse"></div>
        HTML;
    }
};

?>

<div wire:init="loadData" class="xl:col-span-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <div class="mb-4">
        <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.dashboard_arr_monitored_title') }}</h4>
        <p class="text-[11px] text-zinc-500 mt-0.5">{{ __('messages.dashboard_arr_monitored_subtitle') }}</p>
    </div>

    @if (!$isConfigured)
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_configure_arr') }}</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            @foreach ([['block' => $radarr, 'sectionLabel' => __('messages.dashboard_arr_monitored_movies')], ['block' => $sonarr, 'sectionLabel' => __('messages.dashboard_arr_monitored_series')]] as $section)
                @php
                    $block = $section['block'];
                    $sectionLabel = $section['sectionLabel'];
                    $perc = ! empty($block['configured']) ? $this->barPercents($block) : ['monitored_pct' => 0, 'unmonitored_pct' => 0];
                    $mon = (int) ($block['monitored'] ?? 0);
                    $unmon = (int) ($block['unmonitored'] ?? 0);
                @endphp
                @if (! empty($block['configured']))
                    <div>
                        <h5 class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-2">{{ $sectionLabel }}</h5>
                        @if (($mon + $unmon) === 0)
                            <p class="text-sm text-zinc-500">{{ __('messages.dashboard_arr_monitored_empty') }}</p>
                        @else
                            <div class="space-y-2">
                                <div>
                                    <div class="flex justify-between text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 mb-1">
                                        <span>{{ __('messages.dashboard_arr_monitored_on') }}</span>
                                        <span class="tabular-nums">{{ $mon }} ({{ $perc['monitored_pct'] }}%)</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                        <div class="h-full bg-emerald-500 transition-all" style="width: {{ $perc['monitored_pct'] }}%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-[11px] font-semibold text-zinc-500 mb-1">
                                        <span>{{ __('messages.dashboard_arr_monitored_off') }}</span>
                                        <span class="tabular-nums">{{ $unmon }} ({{ $perc['unmonitored_pct'] }}%)</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                        <div class="h-full bg-zinc-400 transition-all dark:bg-zinc-600" style="width: {{ $perc['unmonitored_pct'] }}%"></div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
        @php
            $any = ($radarr['configured'] ?? false) || ($sonarr['configured'] ?? false);
        @endphp
        @if (!$any)
            <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_configure_arr') }}</p>
        @endif
    @endif
</div>
