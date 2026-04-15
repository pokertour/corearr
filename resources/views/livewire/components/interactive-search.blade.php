<?php

use App\Services\MediaStack\MediaStackService;
use Livewire\Volt\Component;

new class extends Component
{
    public ?array $media = null;

    public string $service = '';

    public bool $isOpen = false;

    public array $releases = [];

    public bool $loading = false;

    public ?int $seasonNumber = null;

    public ?int $episodeId = null;

    protected $listeners = ['openInteractiveSearch' => 'open'];

    public function open(int $mediaId, string $mediaTitle, string $service, ?int $seasonNumber = null, ?int $episodeId = null)
    {
        $this->media = [
            'id' => $mediaId,
            'title' => $mediaTitle,
        ];
        $this->service = $service;
        $this->seasonNumber = $seasonNumber;
        $this->episodeId = $episodeId;
        $this->isOpen = true;
        $this->releases = [];
        $this->loadReleases();
    }

    public function loadReleases()
    {
        $this->loading = true;
        $service = new MediaStackService;

        $releases = $service->getReleases(
            $this->service,
            $this->media['id'],
            $this->seasonNumber,
            $this->episodeId
        );
        $releases = $this->filterReleasesForTarget($releases);

        $ranked = collect($releases)
            ->map(function (array $release): array {
                $release['_relevance'] = $this->computeRelevanceScore($release);

                return $release;
            });

        $relevant = $ranked
            ->filter(fn (array $release): bool => ($release['_relevance'] ?? 0) >= 20)
            ->values();

        $source = $relevant->count() >= 3 ? $relevant : $ranked;

        $this->releases = $source
            ->sort(function (array $a, array $b): int {
                $approvedA = (bool) ($a['approved'] ?? false);
                $approvedB = (bool) ($b['approved'] ?? false);
                if ($approvedA !== $approvedB) {
                    return $approvedB <=> $approvedA;
                }

                $allowedA = (bool) ($a['downloadAllowed'] ?? false);
                $allowedB = (bool) ($b['downloadAllowed'] ?? false);
                if ($allowedA !== $allowedB) {
                    return $allowedB <=> $allowedA;
                }

                $scoreA = (int) ($a['_relevance'] ?? 0);
                $scoreB = (int) ($b['_relevance'] ?? 0);

                if ($scoreA !== $scoreB) {
                    return $scoreB <=> $scoreA;
                }

                return ((int) ($b['seeders'] ?? 0)) <=> ((int) ($a['seeders'] ?? 0));
            })
            ->map(function (array $release): array {
                unset($release['_relevance']);

                return $release;
            })
            ->values()
            ->all();

        $this->loading = false;
        $this->dispatch('interactiveSearchLoaded');
    }

    protected function filterReleasesForTarget(array $releases): array
    {
        $mediaId = (int) ($this->media['id'] ?? 0);
        if ($mediaId <= 0 || empty($releases)) {
            return $releases;
        }

        $filtered = collect($releases)->filter(function (array $release) use ($mediaId): bool {
            if ($this->service === 'radarr') {
                $mappedMovieId = (int) ($release['mappedMovieId'] ?? 0);
                $movieId = (int) ($release['movieId'] ?? 0);
                $movieRequested = (bool) ($release['movieRequested'] ?? false);

                return $mappedMovieId === $mediaId || $movieId === $mediaId || $movieRequested;
            }

            if ($this->service === 'sonarr') {
                $mappedSeriesId = (int) ($release['mappedSeriesId'] ?? 0);
                $seriesId = (int) ($release['seriesId'] ?? 0);
                $seriesRequested = (bool) ($release['seriesRequested'] ?? false);
                $matchesSeries = $mappedSeriesId === $mediaId || $seriesId === $mediaId || $seriesRequested;

                if (! $matchesSeries) {
                    return false;
                }

                if ($this->episodeId !== null) {
                    $releaseEpisodeId = (int) ($release['episodeId'] ?? $release['mappedEpisodeId'] ?? 0);
                    $episodeIds = $release['episodeIds'] ?? [];
                    $episodeIds = is_array($episodeIds) ? array_map('intval', $episodeIds) : [];

                    return $releaseEpisodeId === $this->episodeId || in_array((int) $this->episodeId, $episodeIds, true);
                }

                if ($this->seasonNumber !== null && isset($release['seasonNumber'])) {
                    return (int) $release['seasonNumber'] === (int) $this->seasonNumber;
                }

                return true;
            }

            return true;
        })->values();

        // Strict mode: never fallback to unrelated raw results.
        return $filtered->all();
    }

    protected function computeRelevanceScore(array $release): int
    {
        $releaseTitle = $this->normalize((string) ($release['title'] ?? ''));
        $mediaTitle = $this->normalize((string) ($this->media['title'] ?? ''));

        if ($releaseTitle === '' || $mediaTitle === '') {
            return 0;
        }

        $score = 0;

        if (str_contains($releaseTitle, $mediaTitle)) {
            $score += 80;
        }

        $tokens = $this->extractTokens($mediaTitle);
        $matchedTokens = 0;

        foreach ($tokens as $token) {
            if (str_contains($releaseTitle, $token)) {
                $matchedTokens++;
            }
        }

        $score += $matchedTokens * 12;

        if ($this->service === 'sonarr' && $this->seasonNumber !== null) {
            $seasonTag = 's'.str_pad((string) $this->seasonNumber, 2, '0', STR_PAD_LEFT);
            if (str_contains($releaseTitle, $seasonTag)) {
                $score += 20;
            }
        }

        return $score;
    }

    protected function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim($value);
    }

    protected function extractTokens(string $value): array
    {
        $stopWords = ['the', 'a', 'an', 'de', 'la', 'le', 'les', 'and', 'of'];

        return collect(explode(' ', $value))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }

    public function download(string $guid, int $indexerId)
    {
        $service = new MediaStackService;
        $success = $service->downloadRelease($this->service, $guid, $indexerId);

        if ($success) {
            $this->dispatch('toast', message: 'Téléchargement lancé !', type: 'success');
            $this->isOpen = false;
        } else {
            $this->dispatch('toast', message: 'Échec du lancement.', type: 'error');
        }
    }

    public function formatSize($bytes)
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
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
        <div class="bg-white dark:bg-zinc-950 w-full max-w-[92rem] max-h-[94vh] rounded-4xl shadow-2xl flex flex-col overflow-hidden border border-zinc-200 dark:border-zinc-800"
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
                    <p class="text-sm text-zinc-500 font-medium">
                        {{ $media['title'] ?? '' }} 
                        @if($seasonNumber !== null)
                            &bull; Saison {{ $seasonNumber }}
                        @endif
                        @if($episodeId)
                            &bull; Épisode spécifique
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <button wire:click="loadReleases"
                            wire:loading.attr="disabled"
                            wire:target="loadReleases"
                            class="p-2 text-zinc-400 hover:text-zinc-900 dark:hover:text-white transition disabled:opacity-50">
                        <svg wire:loading.remove wire:target="loadReleases" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <svg wire:loading wire:target="loadReleases" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
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
                    <div class="-mx-4 lg:-mx-8 px-4 lg:px-8">
                        <table class="w-full table-fixed text-left border-collapse text-[12px]">
                            <thead class="sticky top-0 bg-white dark:bg-zinc-950 z-10 border-b border-zinc-100 dark:border-zinc-900">
                                <tr class="text-zinc-400 font-black uppercase tracking-widest text-[10px]">
                                    <th class="px-2 py-3 w-[13%]">Indexer</th>
                                    <th class="px-2 py-3 w-[37%]">Release</th>
                                    <th class="px-2 py-3 w-[10%]">Size</th>
                                    <th class="px-2 py-3 w-[9%]">Peers</th>
                                    <th class="px-2 py-3 w-[12%]">Qualité</th>
                                    <th class="px-2 py-3 w-[8%]">Langue</th>
                                    <th class="px-2 py-3 w-[8%] text-right">Score</th>
                                    <th class="px-2 py-3 w-[4%] text-center">Rejet</th>
                                    <th class="px-2 py-3 w-[3%] text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-50 dark:divide-zinc-900">
                                @foreach($releases as $release)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/30 transition group">
                                        <td class="px-2 py-3 font-bold text-zinc-500 truncate">
                                            {{ $release['indexer'] ?? 'Unknown' }}
                                        </td>
                                        <td class="px-2 py-3">
                                            <div class="font-bold text-zinc-900 dark:text-zinc-100 truncate" title="{{ $release['title'] }}">
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
                                        <td class="px-2 py-3 font-medium text-zinc-500 uppercase">
                                            {{ $this->formatSize($release['size']) }}
                                        </td>
                                        <td class="px-2 py-3">
                                            <div class="flex items-center gap-1 font-bold">
                                                <span class="text-green-500 font-black">{{ $release['seeders'] ?? 0 }}</span>
                                                <span class="text-zinc-300 dark:text-zinc-700">/</span>
                                                <span class="text-zinc-500">{{ $release['leechers'] ?? 0 }}</span>
                                            </div>
                                        </td>
                                        <td class="px-2 py-3">
                                            <span class="px-1.5 py-0.5 rounded-md bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 font-bold border border-zinc-200 dark:border-zinc-700 text-[10px]">
                                                {{ $release['quality']['quality']['name'] ?? 'N/A' }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-3">
                                            @php
                                                $language = collect($release['languages'] ?? [])->pluck('name')->filter()->join(', ');
                                            @endphp
                                            <span class="text-[10px] font-bold text-zinc-500 dark:text-zinc-400 truncate block" title="{{ $language !== '' ? $language : 'N/A' }}">
                                                {{ $language !== '' ? $language : 'N/A' }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-3 text-right">
                                            @php
                                                $score = (int) ($release['customFormatScore'] ?? $release['customScore'] ?? 0);
                                            @endphp
                                            <span class="font-black {{ $score >= 0 ? 'text-core-primary' : 'text-red-500' }}">
                                                {{ $score >= 0 ? '+' : '' }}{{ $score }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-3 text-center">
                                            @php
                                                $isRejected = (bool) ($release['rejected'] ?? false);
                                                $rejectionReason = collect($release['rejections'] ?? [])->take(2)->implode(' | ');
                                            @endphp
                                            @if ($isRejected)
                                                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-red-500/15 text-red-500 font-black text-[10px]"
                                                    title="{{ $rejectionReason !== '' ? $rejectionReason : 'Release rejetée' }}">
                                                    !
                                                </span>
                                            @else
                                                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-500/15 text-green-500 font-black text-[10px]"
                                                    title="Release approuvée">
                                                    ✓
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-3 text-right">
                                            <button wire:click="download('{{ $release['guid'] }}', {{ $release['indexerId'] }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="download"
                                                    class="cursor-pointer p-2 bg-zinc-100 dark:bg-zinc-800 hover:bg-core-primary hover:text-white rounded-xl transition-all shadow-sm group-hover:scale-110 disabled:opacity-50">
                                                <svg wire:loading.remove wire:target="download" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                <svg wire:loading wire:target="download" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
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
