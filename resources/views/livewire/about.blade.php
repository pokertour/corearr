<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('components.layouts.app')] #[Title('About')] class extends Component {
    public string $version;
    public string $repoUrl = 'https://github.com/pokertour/corearr';
    public string $creator = 'Pokertour';

    public function mount()
    {
        $this->version = config('corearr.version', 'local-dev');
    }
};

?>

<div class="max-w-3xl mx-auto py-12">
    <div class="text-center mb-16">
        <div class="inline-flex items-center justify-center w-24 h-24 bg-linear-to-br from-core-primary/20 to-core-secondary/20 rounded-3xl mb-6 shadow-xl shadow-core-primary/10">
            <img src="/assets/logo/logo.svg" alt="CoreArr Logo" class="w-14 h-14 drop-shadow-md" />
        </div>
        <h2 class="text-4xl font-black text-zinc-900 dark:text-white tracking-tight mb-2">CoreArr Management Suite</h2>
        <p class="text-zinc-500 font-medium italic">Une interface premium pour vos services "Arr"</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-12">
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-8 rounded-3xl shadow-sm group hover:border-core-primary/30 transition-colors">
            <h3 class="text-[10px] font-black text-zinc-400 uppercase tracking-widest mb-4">Informations</h3>
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-zinc-500">Version</span>
                    <span class="px-3 py-1 bg-zinc-100 dark:bg-zinc-800 rounded-full text-xs font-black text-zinc-900 dark:text-zinc-100 border border-zinc-200 dark:border-zinc-700">
                        {{ $version }}
                    </span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-zinc-500">Créateur</span>
                    <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $creator }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-8 rounded-3xl shadow-sm group hover:border-core-primary/30 transition-colors flex flex-col justify-between">
            <div>
                <h3 class="text-[10px] font-black text-zinc-400 uppercase tracking-widest mb-4">Open Source</h3>
                <p class="text-sm text-zinc-500 leading-relaxed">
                    CoreArr est un projet open-source conçu pour centraliser vos services média avec une esthétique moderne.
                </p>
            </div>
            <a href="{{ $repoUrl }}" target="_blank" class="mt-6 flex items-center justify-center gap-3 py-3 bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 rounded-2xl font-bold transition hover:opacity-90 active:scale-95 shadow-lg shadow-zinc-900/10">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .5C5.37.5 0 5.87 0 12.5c0 5.3 3.438 9.8 8.205 11.385.6.11.82-.26.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.292 24 17.81 24 12.5 24 5.87 18.627.5 12 .5z"/></svg>
                GitHub Repository
            </a>
        </div>
    </div>

    <div class="text-center">
        <p class="text-xs text-zinc-400 uppercase tracking-widest font-bold">&copy; {{ date('Y') }} CoreArr. Tout droits réservés.</p>
    </div>
</div>
