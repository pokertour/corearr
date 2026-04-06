<?php

use Livewire\Volt\Component;
use App\Services\MediaStack\MediaStackService;

new class extends Component {
    public ?array $media = null;
    public string $service = '';
    public bool $isOpen = false;
    public array $releases = [];
    public bool $loading = false;

    protected $listeners = ['openInteractiveSearch' => 'open'];

    public function open(array $media, string $service)
    {
        $this->media = $media;
        $this->service = $service;
        $this->isOpen = true;
        $this->releases = [];
        $this->loadReleases();
    }

    public function loadReleases()
    {
        $this->loading = true;
        $service = new MediaStackService();
        
        // Trigger a fresh search command in background if needed? 
        // For now we fetch cached/current releases
        $this->releases = $service->getReleases($this->service, $this->media['id']);
        
        // Sort by quality and score (Arr services usually return them with a score)
        usort($this->releases, fn($a, $b) => ($b['seeders'] ?? 0) <=> ($a['seeders'] ?? 0));
        
        $this->loading = false;
    }

    public function download(string $guid, int $indexerId)
    {
        $service = new MediaStackService();
        $success = $service->downloadRelease($this->service, $guid, $indexerId);
        
        if ($success) {
            $this->dispatch('toast', message: "Téléchargement lancé !", type: 'success');
            $this->isOpen = false;
        } else {
            $this->dispatch('toast', message: "Échec du lancement.", type: 'error');
        }
    }

    public function formatSize($bytes)
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }
};

?>

<div x-data="{ open: @entangle('isOpen') }" 
     x-show="open" 
     class="fixed inset-0 z-100 overflow-hidden" 
     x-cloak>
    
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-zinc-950/40 backdrop-blur-sm transition-opacity" 
         x-show="open" 
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="open = false"></div>

    <!-- Modal Content -->
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-zinc-950 w-full max-w-6xl max-h-[90vh] rounded-4xl shadow-2xl flex flex-col overflow-hidden border border-zinc-200 dark:border-zinc-800"
             x-show="open"
             x-transition:enter="ease-out duration-300 transform"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="ease-in duration-200 transform"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">
            
            <!-- Header -->
            <div class="px-8 py-6 border-b border-zinc-100 dark:border-zinc-900 flex items-center justify-between shrink-0 bg-zinc-50/50 dark:bg-zinc-900/50">
                <div>
                    <h3 class="text-xl font-black text-zinc-900 dark:text-white uppercase tracking-tight">Recherche Interactive</h3>
                    <p class="text-sm text-zinc-500 font-medium">{{ $media['title'] ?? '' }} ({{ $media['year'] ?? '' }})</p>
                </div>
                <div class="flex items-center gap-4">
                    <button wire:click="loadReleases" class="p-2 text-zinc-400 hover:text-zinc-900 dark:hover:text-white transition">
                        <svg class="w-5 h-5 {{ $loading ? 'animate-spin' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button @click="open = false" class="p-2 bg-zinc-100 dark:bg-zinc-800 rounded-full text-zinc-500 hover:text-zinc-900 dark:hover:text-white transition">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <!-- List -->
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                @if($loading && empty($releases))
                    <div class="h-64 flex flex-col items-center justify-center text-zinc-500 space-y-4">
                        <div class="w-10 h-10 border-4 border-core-primary border-t-transparent rounded-full animate-spin"></div>
                        <p class="font-bold animate-pulse">Scan des indexeurs en cours...</p>
                    </div>
                @elseif(empty($releases))
                    <div class="h-64 flex flex-col items-center justify-center text-zinc-500 italic">
                        Aucune release trouvée. Essayez de forcer une recherche.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-[13px]">
                            <thead class="sticky top-0 bg-white dark:bg-zinc-950 z-10 border-b border-zinc-100 dark:border-zinc-900">
                                <tr class="text-zinc-400 font-black uppercase tracking-widest text-[10px]">
                                    <th class="px-4 py-3">Indexer</th>
                                    <th class="px-4 py-3">Release Name</th>
                                    <th class="px-4 py-3">Size</th>
                                    <th class="px-4 py-3">Peers</th>
                                    <th class="px-4 py-3">Quality</th>
                                    <th class="px-4 py-3 text-right">Score</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-50 dark:divide-zinc-900">
                                @foreach($releases as $release)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/30 transition group">
                                        <td class="px-4 py-4 font-bold text-zinc-500 truncate max-w-[120px]">
                                            {{ $release['indexer'] ?? 'Unknown' }}
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="font-bold text-zinc-900 dark:text-zinc-100 line-clamp-1" title="{{ $release['title'] }}">
                                                {{ $release['title'] }}
                                            </div>
                                            <div class="flex gap-2 mt-1">
                                                @if($release['protocol'] === 'torrent')
                                                    <span class="text-[9px] font-black uppercase text-green-500">Torrent</span>
                                                @else
                                                    <span class="text-[9px] font-black uppercase text-blue-500">Usenet</span>
                                                @endif
                                                <span class="text-[9px] text-zinc-400">{{ \Carbon\Carbon::parse($release['publishDate'])->diffForHumans() }}</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 font-medium text-zinc-500 uppercase whitespace-nowrap">
                                            {{ $this->formatSize($release['size']) }}
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-1 font-bold">
                                                <span class="text-green-500 font-black">{{ $release['seeders'] ?? 0 }}</span>
                                                <span class="text-zinc-300 dark:text-zinc-700">/</span>
                                                <span class="text-zinc-500">{{ $release['leechers'] ?? 0 }}</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4">
                                            <span class="px-2 py-0.5 rounded-md bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 font-bold border border-zinc-200 dark:border-zinc-700">
                                                {{ $release['quality']['quality']['name'] ?? 'N/A' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 text-right">
                                            @php 
                                                $score = 0;
                                                // Simplified score logic for display
                                                if(!empty($release['rejections'])) $score = -100;
                                            @endphp
                                            <span class="font-black {{ $score < 0 ? 'text-red-500' : 'text-core-primary' }}">
                                                {{ $score < 0 ? 'REJETÉ' : '+' . ($release['customScore'] ?? 0) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 text-right">
                                            <button wire:click="download('{{ $release['guid'] }}', {{ $release['indexerId'] }})" 
                                                    class="cursor-pointer p-2 bg-zinc-100 dark:bg-zinc-800 hover:bg-core-primary hover:text-white rounded-xl transition-all shadow-sm group-hover:scale-110">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <!-- Footer -->
            <div class="px-8 py-4 border-t border-zinc-100 dark:border-zinc-900 bg-zinc-50/30 dark:bg-zinc-900/30 flex justify-end shrink-0">
                <button @click="open = false" class="px-6 py-2 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 font-bold rounded-xl transition hover:opacity-90">Fermer</button>
            </div>
        </div>
    </div>
</div>
