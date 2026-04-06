<?php

use Livewire\Volt\Component;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Support\Facades\Http;

new class extends Component {
    public ?array $media = null;
    public string $service = '';
    public bool $isOpen = false;
    
    public array $history = [];
    public array $files = [];
    public array $qualityProfiles = [];
    public string $activeTab = 'info';
    public bool $loadingDetails = false;

    protected $listeners = ['openMediaDetails' => 'open'];

    public function open(array $media, string $service)
    {
        $this->media = $media; // Start with partial data
        $this->service = $service;
        $this->isOpen = true;
        $this->activeTab = 'info';
        $this->loadingDetails = true;
        
        $this->history = [];
        $this->files = [];
        
        $this->loadDetails();
    }

    public function loadDetails()
    {
        if (!$this->media) return;
        
        $this->loadingDetails = true;
        $service = new MediaStackService();
        
        // Fetch full media object (replaces partial data)
        $fullMedia = $service->getMedia($this->service, $this->media['id']);
        if ($fullMedia) {
             $this->media = $fullMedia;
        }

        $this->history = $service->getHistory($this->service, $this->media['id']);
        $this->files = $service->getFiles($this->service, $this->media['id']);
        $this->qualityProfiles = $service->getQualityProfiles($this->service);
        
        $this->loadingDetails = false;
    }

    public function close()
    {
        $this->isOpen = false;
    }

    public function triggerSearch()
    {
        if (!$this->media) return;

        $service = new MediaStackService();
        $commandName = $this->service === 'radarr' ? 'MoviesSearch' : 'SeriesSearch';
        $paramName = $this->service === 'radarr' ? 'movieIds' : 'seriesIds';
        
        $success = $service->triggerBgCommand($this->service, $commandName, [
            $paramName => [$this->media['id']]
        ]);

        if ($success) {
            $this->dispatch('toast', message: "La recherche pour {$this->media['title']} a été envoyée en arrière-plan.", type: 'success');
            $this->close();
        }
    }

    public function getPoster(string $type = 'poster'): string
    {
        if (!$this->media) return '';
        $path = collect($this->media['images'] ?? [])->firstWhere('coverType', $type)['url'] ?? null;
        if (!$path) $path = "/MediaCover/{$this->media['id']}/$type.jpg";
        
        return (new MediaStackService())->getPosterUrl($this->service, $path);
    }

    public function getFallbackPoster(string $type = 'poster'): string
    {
        if (!$this->media) return '';
        return collect($this->media['images'] ?? [])->firstWhere('coverType', $type)['remoteUrl'] ?? '';
    }

    public function toggleMonitored()
    {
        if (!$this->media) return;
        
        $this->media['monitored'] = !($this->media['monitored'] ?? false);
        
        $service = new MediaStackService();
        $service->updateMedia($this->service, $this->media);
        
        $this->dispatch('media-updated');
        $this->dispatch('toast', 
            message: $this->media['monitored'] ? 'Série/Film désormais surveillé.' : 'Surveillance arrêtée.', 
            type: 'success'
        );
    }

    public function deleteMedia(bool $deleteFiles = false)
    {
        if (!$this->media) return;
        
        $service = new MediaStackService();
        $service->deleteMedia($this->service, $this->media['id'], $deleteFiles);
        
        $this->isOpen = false;
        $this->dispatch('media-deleted');
        $this->dispatch('toast', message: 'Le média a été retiré de la bibliothèque.', type: 'info');
    }
};

?>

<div x-data="{ open: @entangle('isOpen') }" 
     x-show="open" 
     class="fixed inset-0 z-60 overflow-hidden" 
     style="display: none;">
    
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity" 
         @click="open = false"
         x-show="open"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex">
        <div class="w-screen max-w-lg"
             x-show="open"
             x-transition:enter="transform transition ease-in-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in-out duration-300"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">
            
            <div class="h-full flex flex-col bg-white dark:bg-zinc-950 shadow-2xl overflow-y-auto">
                @if($media)
                    <!-- Header with Poster Background -->
                    <div class="relative h-72 shrink-0">
                        <img src="{{ $this->getPoster('fanart') }}" 
                             onerror="this.onerror=null; this.src='{{ $this->getFallbackPoster('fanart') }}';"
                             class="w-full h-full object-cover blur-[2px] opacity-40">
                        <div class="absolute inset-0 bg-linear-to-t from-white dark:from-zinc-950 via-transparent to-transparent"></div>
                        
                        <div class="absolute top-6 right-6 flex gap-2 z-20">
                             <button wire:click="toggleMonitored" class="p-2.5 bg-white/40 dark:bg-black/20 backdrop-blur-md rounded-xl text-zinc-900 dark:text-white hover:bg-white/60 transition group cursor-pointer" title="Surveillance">
                                 <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 {{ ($media['monitored'] ?? false) ? 'text-green-500 fill-current' : 'text-zinc-500' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
                             </button>
                             <button x-on:click="if(confirm('Supprimer ce média ?')) $wire.deleteMedia(confirm('Supprimer aussi les fichiers ?'))" class="p-2.5 bg-white/40 dark:bg-black/20 backdrop-blur-md rounded-xl text-red-500 hover:bg-red-500/20 transition cursor-pointer" title="Supprimer">
                                 <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                             </button>
                        </div>

                        <button @click="open = false" class="absolute top-6 left-6 p-2 bg-white/20 hover:bg-white/40 dark:bg-black/20 dark:hover:bg-black/40 backdrop-blur-md rounded-full text-zinc-900 dark:text-white transition z-20 cursor-pointer">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        <div class="absolute bottom-0 left-0 right-0 p-8 flex gap-6 items-end">
                            <div class="w-32 h-48 rounded-xl shadow-2xl overflow-hidden border-2 border-white dark:border-zinc-800 shrink-0">
                                <img src="{{ $this->getPoster('poster') }}" 
                                     onerror="this.onerror=null; this.src='{{ $this->getFallbackPoster('poster') }}';"
                                     class="w-full h-full object-cover">
                            </div>
                            <div class="pb-2">
                                <h2 class="text-2xl font-black text-zinc-900 dark:text-white line-clamp-2 leading-tight">
                                    {{ $media['title'] }}
                                </h2>
                                <p class="text-zinc-500 font-medium mt-1">{{ $media['year'] }} &bull; {{ $media['studio'] ?? $media['network'] ?? '' }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Tabs -->
                    <div class="px-8 border-b border-zinc-100 dark:border-zinc-900 flex gap-6">
                        <button @click="$wire.activeTab = 'info'" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'info', 'border-transparent text-zinc-400': $wire.activeTab !== 'info' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight">Info</button>
                        <button @click="$wire.activeTab = 'files'" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'files', 'border-transparent text-zinc-400': $wire.activeTab !== 'files' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight">Fichiers</button>
                        <button @click="$wire.activeTab = 'history'" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'history', 'border-transparent text-zinc-400': $wire.activeTab !== 'history' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight">Historique</button>
                        <button @click="$wire.activeTab = 'edit'" :class="{ 'border-core-primary text-zinc-900 dark:text-white': $wire.activeTab === 'edit', 'border-transparent text-zinc-400': $wire.activeTab !== 'edit' }" class="py-4 border-b-2 font-bold text-sm transition tracking-tight">Modifier</button>
                    </div>

                    <!-- Content Area -->
                    <div class="flex-1 p-8">
                        <div x-show="$wire.activeTab === 'info'" class="space-y-6">
                            <div>
                                <h4 class="text-[10px] font-black text-zinc-400 uppercase tracking-widest mb-2">Synopsis</h4>
                                <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">{{ $media['overview'] ?? 'Pas de description.' }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-4 pt-4">
                                <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800">
                                    <span class="text-[10px] font-bold text-zinc-400 uppercase block mb-1">Qualité</span>
                                    <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $media['qualityProfileId'] ?? 'Auto' }}</span>
                                </div>
                                <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800">
                                    <span class="text-[10px] font-bold text-zinc-400 uppercase block mb-1">Statut</span>
                                    <span class="text-sm font-bold {{ ($media['hasFile'] ?? false) ? 'text-green-500' : 'text-yellow-500' }}">
                                        {{ ($media['hasFile'] ?? false) ? 'Disponible' : 'Manquant' }}
                                    </span>
                                </div>
                            </div>

                            <button wire:click="$dispatch('openInteractiveSearch', { media: {{ json_encode($media) }}, service: '{{ $service }}' })" 
                                    class="w-full flex items-center justify-center gap-2 py-4 bg-core-primary/10 hover:bg-core-primary/20 text-core-primary rounded-2xl font-black text-sm transition group uppercase tracking-widest border border-core-primary/20">
                                <svg class="w-5 h-5 group-hover:animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                Recherche Interactive
                            </button>
                        </div>

                        <div x-show="$wire.activeTab === 'files'" class="space-y-4">
                            @forelse($files as $file)
                                <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800">
                                    <div class="flex justify-between items-start">
                                        <p class="text-xs font-bold text-zinc-900 dark:text-white line-clamp-1 flex-1">{{ $file['relativePath'] ?? 'Nom inconnu' }}</p>
                                        <span class="text-[10px] font-black text-zinc-400 ml-4 shrink-0">{{ round(($file['size'] ?? 0) / (1024*1024*1024), 2) }} GB</span>
                                    </div>
                                    <div class="flex gap-2 mt-2">
                                        <span class="text-[9px] font-bold bg-zinc-200 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 px-1.5 py-0.5 rounded">{{ $file['quality']['quality']['name'] ?? 'N/A' }}</span>
                                    </div>
                                </div>
                            @empty
                                <p class="text-center py-12 text-zinc-500 italic text-sm">Aucun fichier trouvé sur le disque.</p>
                            @endforelse
                        </div>

                        <div x-show="$wire.activeTab === 'history'" class="space-y-4">
                            @forelse($history as $event)
                                <div class="flex gap-4 group">
                                    <div class="flex flex-col items-center shrink-0">
                                    <div class="w-1.5 h-1.5 rounded-full mt-1.5 {{ $event['eventType'] === 'grabbed' ? 'bg-core-primary' : 'bg-zinc-300' }}"></div>
                                    <div class="flex-1 w-px bg-zinc-200 dark:bg-zinc-800 group-last:hidden"></div>
                                </div>
                                <div class="pb-6">
                                    <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-tighter">{{ \Carbon\Carbon::parse($event['date'])->translatedFormat('d M H:i') }}</p>
                                    <p class="text-sm font-bold text-zinc-900 dark:text-zinc-100 leading-none">{{ ucfirst($event['eventType']) }}</p>
                                    @if(isset($event['sourceTitle']))
                                        <p class="text-[11px] text-zinc-500 mt-1 line-clamp-1 italic">{{ $event['sourceTitle'] }}</p>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-center py-12 text-zinc-500 italic text-sm">Historique vide.</p>
                        @endforelse
                    </div>

                    <div x-show="$wire.activeTab === 'edit'" class="space-y-6">
                         <div>
                             <h4 class="text-[10px] font-black text-zinc-400 uppercase tracking-widest mb-2">Profil de Qualité</h4>
                             <select wire:model="media.qualityProfileId" class="w-full bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-core-primary outline-none transition">
                                 @foreach($qualityProfiles as $profile)
                                     <option value="{{ $profile['id'] }}">{{ $profile['name'] }}</option>
                                 @endforeach
                             </select>
                         </div>
                         
                         <div class="pt-4">
                             <button wire:click="toggleMonitored" class="w-full flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 group hover:border-core-primary/50 transition">
                                 <div class="text-left">
                                     <span class="text-sm font-bold text-zinc-900 dark:text-white">Surveillance</span>
                                     <p class="text-[10px] text-zinc-500">Activer/Désactiver le téléchargement automatique</p>
                                 </div>
                                 <div class="w-10 h-6 {{ ($media['monitored'] ?? false) ? 'bg-core-primary' : 'bg-zinc-300' }} rounded-full relative transition-colors">
                                     <div class="absolute top-1 {{ ($media['monitored'] ?? false) ? 'right-1' : 'left-1' }} w-4 h-4 bg-white rounded-full transition-all"></div>
                                 </div>
                             </button>
                         </div>

                         <div class="pt-8">
                             <button wire:click="toggleMonitored" class="w-full py-3 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 font-bold rounded-xl hover:opacity-90 transition">
                                 Enregistrer les modifications
                             </button>
                         </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
    
    <livewire:components.interactive-search />
</div>
