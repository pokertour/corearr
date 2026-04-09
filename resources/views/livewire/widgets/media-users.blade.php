<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Lazy;
use App\Services\MediaStack\JellyseerrService;
use App\Services\MediaStack\MediaServerService;

new #[Lazy] class extends Component {
    public array $activeSessions = [];
    public array $recentRequests = [];
    public bool $isConfigured = false;
    public string $serverType = '';

    public function mount()
    {
        $jellyseerr = app(JellyseerrService::class);
        $mediaServer = app(MediaServerService::class);
        
        $mediaServerConfigured = $mediaServer->isConfigured();
        $jellyseerrConfigured = $jellyseerr->isConfigured();
        
        $this->isConfigured = $mediaServerConfigured || $jellyseerrConfigured;

        if ($mediaServerConfigured) {
            $this->serverType = ucfirst($mediaServer->getServerType());
            $this->activeSessions = $mediaServer->getActiveSessions();
        }

        if ($jellyseerrConfigured) {
            $requests = $jellyseerr->getRequests(5);
            $rawResults = $requests['results'] ?? [];
            
            // Fetch detailed metadata for posters and titles
            foreach ($rawResults as &$req) {
                $media = $req['media'] ?? [];
                $tmdbId = $media['tmdbId'] ?? '';
                if ($tmdbId) {
                    $details = $jellyseerr->getMediaDetails($tmdbId, $req['type']);
                    if ($details) {
                        $req['media']['title'] = $details['title'] ?? $details['name'] ?? $media['title'] ?? $media['name'] ?? 'Unknown';
                        $req['media']['posterPath'] = $details['posterPath'] ?? $media['posterPath'] ?? null;
                    }
                }
            }
            $this->recentRequests = $rawResults;
        }
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden flex flex-col h-full animate-pulse">
            <div class="p-5 border-b border-zinc-100 dark:border-zinc-800 h-16 bg-zinc-50/50 dark:bg-zinc-900/50"></div>
            <div class="p-5 space-y-4">
                <div class="h-12 bg-zinc-200 dark:bg-zinc-800 rounded-xl w-full"></div>
                <div class="h-12 bg-zinc-200 dark:bg-zinc-800 rounded-xl w-full"></div>
            </div>
        </div>
        HTML;
    }
};

?>

<div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden flex flex-col h-full">
    <div class="p-5 border-b border-zinc-100 dark:border-zinc-800 flex justify-between items-center bg-zinc-50/50 dark:bg-zinc-900/50">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-500">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('messages.recent_users') }} & {{ __('messages.latest_requests') }}</h3>
                <p class="text-[11px] text-zinc-500">
                    {{ $serverType ? $serverType . ' & ' : '' }} Jellyseerr
                </p>
            </div>
        </div>
        <a href="/cleanup" wire:navigate class="text-xs font-bold text-core-primary hover:text-core-primary/80 transition uppercase tracking-wider">
            {{ __('messages.cleanup') }}
        </a>
    </div>

    <div class="p-5 flex-1 relative overflow-hidden flex flex-col gap-6">
        @if(!$isConfigured)
            <div class="flex-1 flex flex-col items-center justify-center py-8">
                <p class="text-zinc-500 text-sm mb-4">{{ __('messages.no_services_configured') }}</p>
            </div>
        @else
            <!-- Active Sessions -->
            @if(count($activeSessions) > 0)
                <div>
                    <h4 class="text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                        Active on {{ $serverType }}
                    </h4>
                    <ul class="space-y-3">
                        @foreach($activeSessions as $session)
                            <li class="flex items-center gap-3 p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800">
                                <div class="w-10 h-10 rounded-lg bg-zinc-200 dark:bg-zinc-700 font-bold flex items-center justify-center text-zinc-600 dark:text-zinc-300">
                                    {{ substr($session['UserName'] ?? 'U', 0, 1) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-white truncate">
                                        {{ $session['NowPlayingItem']['Name'] ?? 'Unknown' }}
                                    </p>
                                    <p class="text-xs text-zinc-500 truncate">
                                        {{ $session['UserName'] ?? 'Unknown User' }} • {{ $session['Client'] ?? 'Web' }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Recent Requests -->
            <div>
                <h4 class="text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-3">
                    {{ __('messages.latest_requests') }}
                </h4>
                @if(count($recentRequests) > 0)
                    <ul class="space-y-3">
                        @foreach($recentRequests as $request)
                            <li class="flex items-center gap-3 p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800">
                                @if(isset($request['media']['posterPath']))
                                    <img src="https://image.tmdb.org/t/p/w92{{ $request['media']['posterPath'] }}" class="w-10 h-14 object-cover rounded shadow-sm" alt="Poster">
                                @else
                                    <div class="w-10 h-14 rounded bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                        <svg class="w-5 h-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-white truncate">
                                        {{ $request['media']['name'] ?? $request['media']['title'] ?? 'Unknown' }}
                                    </p>
                                    <p class="text-xs text-zinc-500 truncate">
                                        Req by {{ $request['requestedBy']['displayName'] ?? $request['requestedBy']['email'] ?? 'Unknown' }}
                                    </p>
                                </div>
                                <div>
                                    @if(($request['media']['status'] ?? 0) === 5)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">Available</span>
                                    @elseif(($request['media']['status'] ?? 0) === 3)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">Processing</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-400">Pending</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-zinc-500 italic">No recent requests.</p>
                @endif
            </div>
        @endif
    </div>
</div>
