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
     * Get user activity/watch status for a specific media based on TMDB ID
     */
    public function hasUserWatched(string $userId, string $tmdbId, string $mediaType = 'Movie'): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $typeFilter = $mediaType === 'Movie' ? 'Movie' : 'Series';

        // Fetch the item for the user by TMDB ID
        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl("Users/{$userId}/Items"), [
                'AnyProviderIdEquals' => "tmdb.{$tmdbId}",
                'IncludeItemTypes' => $typeFilter,
                'Recursive' => 'true',
            ]);

        if (! $response->successful()) {
            return false;
        }

        $data = $response->json();
        if (empty($data['Items'])) {
            return false;
        }

        $item = $data['Items'][0];

        // If it's a TV show, the "Played" status typically means all episodes are played.
        return $item['UserData']['Played'] ?? false;
    }
}
