<?php

use Livewire\Component;

new class extends Component {
    public bool $prowlarrConfigured = false;

    /** @var array<string, int> */
    public array $indexerHealthStats = [];
};

?>

<div class="xl:col-span-1 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.ops_indexer_health_title') }}</h4>
    <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_prowlarr_live') }}</p>
    @if ($prowlarrConfigured && ! empty($indexerHealthStats))
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
