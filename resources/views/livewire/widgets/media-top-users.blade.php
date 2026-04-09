<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\JellyseerrService;

new #[Lazy] class extends Component {
    public array $topUsers = [];
    public bool $isConfigured = false;
    public int $totalUsers = 0;

    public function mount()
    {
        $jellyseerr = app(JellyseerrService::class);
        $this->isConfigured = $jellyseerr->isConfigured();
        
        if ($this->isConfigured) {
            // Fetch more users and sort by requestCount in PHP to avoid Jellyseerr 400 errors
            $response = $jellyseerr->getUsers(50);
            $users = $response['results'] ?? (is_array($response) && !isset($response['results']) ? $response : []);
            
            $this->topUsers = collect($users)
                ->sortByDesc('requestCount')
                ->take(5)
                ->values()
                ->toArray();

            $this->totalUsers = $response['pageInfo']['results'] ?? count($users);
        }
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden flex flex-col h-full animate-pulse">
            <div class="p-5 border-b border-zinc-100 dark:border-zinc-800 h-16 bg-zinc-50/50 dark:bg-zinc-900/50"></div>
            <div class="p-5 space-y-4">
                <div class="h-10 bg-zinc-200 dark:bg-zinc-800 rounded-xl w-full"></div>
                <div class="h-10 bg-zinc-200 dark:bg-zinc-800 rounded-xl w-full"></div>
                <div class="h-10 bg-zinc-200 dark:bg-zinc-800 rounded-xl w-full"></div>
            </div>
        </div>
        HTML;
    }
};
?>

<div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden flex flex-col h-full">
    <div class="p-5 border-b border-zinc-100 dark:border-zinc-800 flex justify-between items-center bg-zinc-50/50 dark:bg-zinc-900/50">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-orange-500/10 flex items-center justify-center text-orange-500">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5V4H2v16h5m10 0v-5H7v5m10 0H7" />
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Top Utilisateurs</h3>
                <p class="text-[11px] text-zinc-500">
                    Les plus actifs sur Jellyseerr
                </p>
            </div>
        </div>
        @if($totalUsers > 0)
            <div class="text-xs font-bold text-zinc-400 bg-zinc-100 dark:bg-zinc-800 px-2 py-1 rounded-md">
                {{ $totalUsers }} Users
            </div>
        @endif
    </div>

    <div class="p-5 flex-1 relative flex flex-col">
        @if(!$isConfigured)
            <div class="flex-1 flex flex-col items-center justify-center py-8">
                <p class="text-zinc-500 text-sm mb-4">{{ __('messages.no_services_configured') }}</p>
            </div>
        @else
            @if(count($topUsers) > 0)
                <ul class="space-y-3 mb-4">
                    @foreach($topUsers as $index => $user)
                        <li class="flex items-center gap-3 p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800 relative overflow-hidden">
                            <!-- Rank Badge -->
                            <div class="absolute left-0 top-0 bottom-0 w-1 flex flex-col items-center justify-center 
                                {{ $index === 0 ? 'bg-yellow-400' : ($index === 1 ? 'bg-zinc-400' : ($index === 2 ? 'bg-amber-600' : 'bg-zinc-200 dark:bg-zinc-700')) }}">
                            </div>
                            
                            @if(isset($user['avatar']))
                                <img src="{{ $user['avatar'] }}" alt="Avatar" class="w-10 h-10 rounded-full object-cover shadow-sm ml-2" />
                            @else
                                <div class="w-10 h-10 rounded-full bg-zinc-200 dark:bg-zinc-700 font-bold flex items-center justify-center text-zinc-600 ml-2">
                                    {{ substr($user['displayName'] ?? $user['email'] ?? 'U', 0, 1) }}
                                </div>
                            @endif
                            
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-zinc-900 dark:text-white truncate">
                                    {{ $user['displayName'] ?? 'Unknown' }}
                                </p>
                                <p class="text-[11px] text-zinc-500 truncate">
                                    {{ $user['email'] ?? 'No email' }}
                                </p>
                            </div>
                            
                            <div class="flex flex-col items-end">
                                <span class="text-lg font-black text-core-primary">{{ $user['requestCount'] ?? 0 }}</span>
                                <span class="text-[9px] uppercase font-bold tracking-widest text-zinc-400">Demandes</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-auto pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <a href="/cleanup" wire:navigate class="flex items-center justify-center w-full py-2.5 rounded-xl bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-sm font-bold text-zinc-700 dark:text-zinc-300 transition">
                        Voir la gestion des utilisateurs
                    </a>
                </div>
            @else
                <p class="text-sm text-zinc-500 italic text-center py-8">Aucun utilisateur trouvé.</p>
            @endif
        @endif
    </div>
</div>
