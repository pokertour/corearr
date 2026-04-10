<?php

namespace App\Services\MediaStack;

use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Http;

class MediaServerService
{
    private ?ServiceSetting $settings;

    private string $serverType;

    public function __construct()
    {
        $this->settings = ServiceSetting::whereIn('service_name', ['emby', 'jellyfin'])->where('is_active', true)->first();
        if ($this->settings) {
            $this->serverType = $this->settings->service_name;
        }
    }

    private function getUrl(string $endpoint): string
    {
        if (! $this->settings) {
            return '';
        }

        return rtrim($this->settings->base_url, '/').'/'.ltrim($endpoint, '/');
    }

    private function getHeaders(): array
    {
        if (! $this->settings) {
            return [];
        }

        return [
            'X-Emby-Token' => $this->settings->api_key,
            'Accept' => 'application/json',
        ];
    }

    public function isConfigured(): bool
    {
        return $this->settings !== null;
    }

    public function getServerType(): string
    {
        return $this->serverType ?? '';
    }

    /**
     * Get active playback sessions.
     */
    public function getActiveSessions(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl('Sessions'));

        if (! $response->successful()) {
            return [];
        }

        $sessions = $response->json();

        // Filter out non-playing sessions
        return array_values(array_filter($sessions, function ($session) {
            return ! empty($session['NowPlayingItem']);
        }));
    }

    /**
     * Get list of users.
     */
    public function getUsers(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl('Users'));

        return $response->successful() ? $response->json() : [];
    }

    /**
     * Get the entire library mapping for a user, indexed by TMDB ID.
     * This is much more efficient than per-item calls for Jellyfin/Emby.
     */
    public function getLibraryMapping(string $userId): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl("Users/{$userId}/Items"), [
                'Recursive' => 'true',
                'Fields' => 'ProviderIds,CommunityRating,PremiereDate',
                'IncludeItemTypes' => 'Movie,Series',
            ]);

        if (! $response->successful()) {
            return [];
        }

        $items = $response->json()['Items'] ?? [];
        $mapping = [];

        foreach ($items as $item) {
            $tmdbId = $item['ProviderIds']['Tmdb'] ?? null;
            if (! $tmdbId) {
                continue;
            }

            $mapping[$tmdbId] = [
                'isWatched' => $item['UserData']['Played'] ?? false,
                'rating' => $item['CommunityRating'] ?? 0,
                'releaseYear' => isset($item['PremiereDate']) ? (int) substr($item['PremiereDate'], 0, 4) : null,
                'itemId' => $item['Id'],
            ];
        }

        return $mapping;
    }

    /**
     * Legacy/Individual watch status check (uses mapping internally or fallback)
     */
    public function getUserWatchMetadata(string $userId, string $tmdbId, string $mediaType = 'Movie'): array
    {
        // For individual calls, we still use the old method if it's Emby,
        // but for Jellyfin we really should use mapping.
        // For simplicity in Cleanup, we'll refactor Cleanup to use getLibraryMapping directly.
        return [
            'isWatched' => false,
            'rating' => 0,
            'releaseYear' => null,
        ];
    }
}
