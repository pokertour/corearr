<?php

use Livewire\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Lazy] class extends Component {
    public bool $isConfigured = false;
    public array $torrents = [];
    public array $stats = [];

    public function mount()
    {
        $this->isConfigured = ServiceSetting::where('service_name', 'qbittorrent')->exists();
    }

    public function loadData(MediaStackService $service)
    {
        $data = $service->getQbitData();
        $this->torrents = collect($data['torrents'] ?? [])
            ->sortByDesc('added_on')
            ->take(5)
            ->all();
        $this->stats = $data['server_state'] ?? [];
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="h-64 flex flex-col items-center justify-center bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl animate-pulse">
            <div class="w-10 h-10 bg-zinc-200 dark:bg-zinc-800 rounded-full mb-3 text-zinc-300 flex items-center justify-center">
                 <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
            </div>
            <div class="w-1/3 h-4 bg-zinc-100 dark:bg-zinc-800 rounded mb-2"></div>
            <div class="w-1/4 h-3 bg-zinc-100 dark:bg-zinc-800 rounded"></div>
        </div>
        HTML;
    }

    public function formatSize($bytes)
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    public function ratioColorStyle(float $ratio): string
    {
        if ($ratio < 1.0) {
            return '';
        }

        $progress = min(max(($ratio - 1.0) / 4.0, 0), 1);
        $hue = 40 + (100 * $progress);

        return "color: hsl({$hue}, 85%, 45%);";
    }
};

?>

<div wire:init="loadData" class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm overflow-hidden h-full text-zinc-900 dark:text-zinc-100">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-500">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
            </div>
            <h3 class="text-lg font-semibold">{{ __('messages.downloads') }}</h3>
        </div>
        
        @if($isConfigured && !empty($stats))
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-1.5 px-2 py-1 bg-green-500/5 rounded-lg border border-green-500/10">
                    <div class="w-2 h-2 rounded-full bg-green-500"></div>
                    <span class="text-[10px] font-bold text-green-700 dark:text-green-500 uppercase">{{ $this->formatSize($stats['dl_info_speed'] ?? 0) }}/s</span>
                </div>
            </div>
        @endif
    </div>

    @if(!$isConfigured)
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <p class="text-sm text-zinc-500 mb-4">{{ __('messages.qbit_not_configured') }}</p>
            <a href="/settings" wire:navigate class="px-4 py-2 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 text-sm font-medium rounded-lg shadow-sm">{{ __('messages.configure') }}</a>
        </div>
    @elseif(empty($torrents))
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <svg class="w-12 h-12 text-zinc-200 dark:text-zinc-800 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            <p class="text-sm text-zinc-500">{{ __('messages.no_active_downloads') }}</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($torrents as $hash => $torrent)
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/40 rounded-xl border border-zinc-100 dark:border-zinc-800/50 hover:border-blue-500/30 transition">
                    <div class="flex justify-between items-start mb-2">
                        <div class="min-w-0 pr-4">
                            <p class="text-xs font-semibold truncate" title="{{ $torrent['name'] }}">
                                {{ $torrent['name'] }}
                            </p>
                            <div class="flex items-center gap-2 mt-0.5">
                                @php $ratio = (float) ($torrent['ratio'] ?? 0); @endphp
                                <span class="text-[9px] font-bold uppercase tracking-tighter {{ $ratio < 1.0 ? 'text-red-500 dark:text-red-400' : '' }}"
                                      style="{{ $this->ratioColorStyle($ratio) }}">
                                    {{ __('messages.ratio') }}: {{ round($ratio, 2) }}
                                </span>
                                @if(($torrent['upspeed'] ?? 0) > 0)
                                    <span class="w-0.5 h-0.5 bg-zinc-300 dark:bg-zinc-700 rounded-full"></span>
                                    <span class="text-[9px] font-bold text-teal-600 dark:text-teal-400 uppercase tracking-tighter">
                                        UP: {{ $this->formatSize($torrent['upspeed']) }}/s
                                    </span>
                                @endif
                            </div>
                        </div>
                        <span class="text-[10px] text-blue-600 dark:text-blue-400 font-black italic shrink-0">
                            @if(($torrent['dlspeed'] ?? 0) > 0)
                                {{ $this->formatSize($torrent['dlspeed']) }}/s
                            @else
                                --
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                         <div class="flex-1 h-1.5 bg-zinc-200 dark:bg-zinc-800 rounded-full overflow-hidden">
                             <div class="h-full bg-blue-500 rounded-full transition-all duration-700" style="width: {{ ($torrent['progress'] ?? 0) * 100 }}%"></div>
                         </div>
                         <span class="text-[10px] font-bold text-zinc-500">{{ round(($torrent['progress'] ?? 0) * 100, 1) }}%</span>
                    </div>
                </div>
            @endforeach
            
            <a href="/torrents" wire:navigate class="block text-center py-2 text-xs font-semibold text-blue-500 hover:text-blue-600 transition">
                {{ __('messages.view_all') }} &rarr;
            </a>
        </div>
    @endif
</div>
