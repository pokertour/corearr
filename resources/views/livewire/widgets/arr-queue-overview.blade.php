<?php

use Livewire\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Lazy] class extends Component {
    /** @var array<string, mixed> */
    public array $radarr = [];

    /** @var array<string, mixed> */
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
        $overview = $service->getArrQueueOverview();
        $this->radarr = $overview['radarr'] ?? [];
        $this->sonarr = $overview['sonarr'] ?? [];
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="xl:col-span-2 h-80 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl animate-pulse"></div>
        HTML;
    }
};

?>

<div wire:init="loadData" class="xl:col-span-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-4">
        <div>
            <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.dashboard_arr_queue_title') }}</h4>
            <p class="text-[11px] text-zinc-500 mt-0.5">{{ __('messages.dashboard_arr_queue_subtitle') }}</p>
        </div>
    </div>

    @if (!$isConfigured)
        <p class="text-sm text-zinc-500">{{ __('messages.dashboard_lib_configure_arr') }}</p>
    @else
        <div class="flex flex-wrap gap-3 mb-4">
            @foreach ([['radarr', $radarr, __('messages.dashboard_arr_queue_radarr')], ['sonarr', $sonarr, __('messages.dashboard_arr_queue_sonarr')]] as $_row)
                @php
                    [, $block, $label] = $_row;
                    $total = (int) ($block['total'] ?? 0);
                    $warn = (int) ($block['warnings'] ?? 0);
                    $ok = ($block['configured'] ?? false) && $total === 0;
                @endphp
                @if ($block['configured'] ?? false)
                    <div class="flex flex-wrap items-center gap-2 px-3 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <span class="text-[11px] font-bold text-zinc-600 dark:text-zinc-300">{{ $label }}</span>
                        @if ($ok)
                            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">{{ __('messages.dashboard_arr_queue_idle') }}</span>
                        @else
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('messages.dashboard_arr_queue_total', ['n' => $total]) }}</span>
                            @if ($warn > 0)
                                <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-lg bg-red-500/15 text-red-600 dark:text-red-400">{{ __('messages.dashboard_arr_queue_warnings', ['n' => $warn]) }}</span>
                            @endif
                        @endif
                    </div>
                @endif
            @endforeach
        </div>

        @php
            $combined = [];
            foreach (['radarr' => $radarr['items'] ?? [], 'sonarr' => $sonarr['items'] ?? []] as $src => $list) {
                foreach ($list as $row) {
                    $combined[] = array_merge($row, ['_src' => $src]);
                    if (count($combined) >= 10) {
                        break 2;
                    }
                }
            }
        @endphp

        @if (empty($combined))
            <p class="text-sm text-zinc-500">{{ __('messages.dashboard_arr_queue_none') }}</p>
        @else
            <ul class="space-y-2 max-h-64 overflow-y-auto pr-1">
                @foreach ($combined as $row)
                    <li class="flex items-start gap-3 p-2.5 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-800/30">
                        <span class="text-[9px] font-black uppercase tracking-tighter shrink-0 mt-0.5 px-1.5 py-0.5 rounded bg-zinc-200/80 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400">
                            {{ ($row['_src'] ?? '') === 'sonarr' ? 'SN' : 'RD' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[12px] font-semibold text-zinc-900 dark:text-white truncate">{{ $row['title'] }}</p>
                            @if (!empty($row['subtitle']))
                                <p class="text-[11px] text-zinc-500 truncate">{{ $row['subtitle'] }}</p>
                            @endif
                        </div>
                        @if (!empty($row['warning']))
                            <span class="shrink-0 w-2 h-2 rounded-full bg-amber-500 mt-2" title="{{ __('messages.dashboard_arr_queue_warn_hint') }}"></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
