<?php

use Livewire\Volt\Component;
use App\Services\MediaStack\MediaStackService;
use App\Models\ServiceSetting;

new #[Livewire\Attributes\Layout('components.layouts.app')] #[Livewire\Attributes\Title('Indexeurs')] class extends Component {
    public array $indexers = [];
    public bool $isConfigured = false;
    public bool $loading = false;
    
    // Modal states
    public bool $showEditModal = false;
    public ?array $editingIndexer = null;

    public function mount()
    {
        $this->isConfigured = ServiceSetting::where('service_name', 'prowlarr')->where('is_active', true)->exists();
        if ($this->isConfigured) {
            $this->loadIndexers();
        }
    }

    public function loadIndexers()
    {
        $this->loading = true;
        $service = new MediaStackService();
        $this->indexers = $service->getIndexers();
        $this->loading = false;
    }

    public function testIndexer(int $id)
    {
        $service = new MediaStackService();
        $success = $service->testIndexer($id);
        
        $this->dispatch('toast', 
            message: $success ? "Test réussi pour l'indexeur." : "Échec du test pour l'indexeur.",
            type: $success ? 'success' : 'error'
        );
        
        $this->loadIndexers();
    }

    public function deleteIndexer(int $id)
    {
        $service = new MediaStackService();
        $service->deleteIndexer($id);
        $this->dispatch('toast', message: "Indexeur supprimé.", type: 'success');
        $this->loadIndexers();
    }

    public function toggleIndexer(int $id)
    {
        $indexer = collect($this->indexers)->firstWhere('id', $id);
        if (!$indexer) return;

        $indexer['enable'] = !($indexer['enable'] ?? false);
        
        $service = new MediaStackService();
        $service->saveIndexer($indexer);
        
        $this->dispatch('toast', message: $indexer['enable'] ? "Indexeur activé." : "Indexeur désactivé.", type: 'success');
        $this->loadIndexers();
    }
    
    public function testAll()
    {
        $this->loading = true;
        $service = new MediaStackService();
        $service->triggerBgCommand('prowlarr', 'TestAllIndexers');
        
        $this->dispatch('toast', message: "Test de tous les indexeurs lancé en arrière-plan.", type: 'info');
        $this->loading = false;
    }

    public function updatePriority(int $id, int $priority)
    {
        $service = new MediaStackService();
        $success = $service->updateIndexerPriority($id, $priority);
        
        if ($success) {
            $this->dispatch('toast', message: "Priorité mise à jour.", type: 'success');
            // Update local state without full reload if possible, but reload is safer for consistence
            $this->loadIndexers();
        } else {
            $this->dispatch('toast', message: "Erreur lors de la mise à jour.", type: 'error');
        }
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Gestion des Indexeurs</h2>
            <p class="text-sm text-zinc-500">Gérez vos trackers et indexeurs Prowlarr.</p>
        </div>

        <div class="flex items-center gap-3">
            <button wire:click="testAll" class="cursor-pointer px-4 py-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl text-sm font-bold text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Tout tester
            </button>
            <button wire:click="loadIndexers" class="cursor-pointer p-2 bg-core-primary text-white rounded-xl hover:bg-core-primary/90 transition shadow-lg shadow-core-primary/20">
                <svg class="w-5 h-5 {{ $loading ? 'animate-spin' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
        </div>
    </div>

    @if(!$isConfigured)
        <div class="flex flex-col items-center justify-center py-20 bg-white dark:bg-zinc-900 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-3xl text-center">
            <div class="w-16 h-16 bg-pink-500/10 flex items-center justify-center text-pink-500 rounded-2xl mb-4">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">Prowlarr non configuré</h3>
            <p class="text-zinc-500 mb-6 max-w-sm">Connectez votre instance Prowlarr dans les paramètres pour gérer vos indexeurs ici.</p>
            <a href="/settings" wire:navigate class="px-6 py-2.5 bg-core-primary text-white font-bold rounded-xl shadow-lg hover:bg-core-primary/90 transition">
                Aller aux paramètres
            </a>
        </div>
    @else
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800">
                            <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider">Indexeur</th>
                            <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider">Priorité</th>
                            <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider">Protocole</th>
                            <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider">Tags</th>
                            <th class="px-6 py-4 text-xs font-bold text-zinc-500 uppercase tracking-wider text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($indexers as $indexer)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center font-bold text-zinc-400 text-[10px]">
                                            {{ substr($indexer['name'], 0, 2) }}
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $indexer['name'] }}</div>
                                            <div class="text-[10px] text-zinc-500">{{ parse_url($indexer['fields'][0]['value'] ?? '', PHP_URL_HOST) ?? 'Indexer' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <input type="number" 
                                               value="{{ $indexer['priority'] ?? 25 }}"
                                               wire:change="updatePriority({{ $indexer['id'] }}, $event.target.value)"
                                               class="w-16 px-2 py-1 bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded text-xs font-bold text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-core-primary outline-none transition" />
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-700 uppercase">
                                        {{ $indexer['protocol'] ?? 'Torrent' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 rounded-full {{ ($indexer['enable'] ?? false) ? 'bg-green-500' : 'bg-zinc-400' }}"></div>
                                        <span class="text-xs font-medium {{ ($indexer['enable'] ?? false) ? 'text-zinc-700 dark:text-zinc-300' : 'text-zinc-400' }}">
                                            {{ ($indexer['enable'] ?? false) ? 'Activé' : 'Désactivé' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($indexer['tags'] ?? [] as $tag)
                                            <span class="px-1.5 py-0.5 text-[9px] font-bold bg-blue-500/10 text-blue-500 rounded">{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button wire:click="toggleIndexer({{ $indexer['id'] }})" class="cursor-pointer p-1.5 text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 transition" title="{{ ($indexer['enable'] ?? false) ? 'Désactiver' : 'Activer' }}">
                                        @if($indexer['enable'] ?? false)
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        @else
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/></svg>
                                        @endif
                                    </button>
                                    <button wire:click="testIndexer({{ $indexer['id'] }})" class="cursor-pointer p-1.5 text-zinc-400 hover:text-green-500 transition" title="Tester">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </button>
                                    <button wire:click="deleteIndexer({{ $indexer['id'] }})" 
                                            wire:confirm="Supprimer cet indexeur ?"
                                            class="cursor-pointer p-1.5 text-zinc-400 hover:text-red-500 transition" title="Supprimer">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-zinc-500">
                                    Aucun indexeur trouvé.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div> 
