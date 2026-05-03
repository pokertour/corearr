<?php

use Livewire\Component;

new class extends Component {
    public bool $jellyseerrConfigured = false;

    /** @var array<string, int> */
    public array $requestPipelineStats = [];
};

?>

<div class="xl:col-span-1 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-sm">
    <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.ops_request_pipeline_title') }}</h4>
    <p class="text-[11px] text-zinc-500 mb-3">{{ __('messages.ops_source_jellyseerr_live') }}</p>
    @if ($jellyseerrConfigured && ! empty($requestPipelineStats))
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
