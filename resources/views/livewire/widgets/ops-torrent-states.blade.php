<?php

use Livewire\Component;

new class extends Component {
    public bool $qbitConfigured = false;

    /** @var array<string, int> */
    public array $torrentStateStats = [];
};

?>

<div class="xl:col-span-1 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.ops_torrent_states_title') }}</h4>
    <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_qbit_live') }}</p>
    @if ($qbitConfigured && ! empty($torrentStateStats))
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
