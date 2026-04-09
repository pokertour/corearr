<?php

namespace App\Services\MediaStack;

use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Http;

class JellyseerrService
{
    private ?ServiceSetting $settings;

    public function __construct()
    {
        $this->settings = ServiceSetting::where('service_name', 'jellyseerr')->where('is_active', true)->first();
    }

    private function getUrl(string $endpoint): string
    {
        if (! $this->settings) {
            return '';
        }

        return rtrim($this->settings->base_url, '/').'/api/v1/'.ltrim($endpoint, '/');
    }

    private function getHeaders(): array
    {
        if (! $this->settings) {
            return [];
        }

        return [
            'X-Api-Key' => $this->settings->api_key,
            'Accept' => 'application/json',
        ];
    }

    public function isConfigured(): bool
    {
        return $this->settings !== null;
    }

    /**
     * Get recent requests, optionally filtered.
     */
    public function getRequests(int $take = 10, int $skip = 0, string $filter = 'all', string $sort = 'added'): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl("request?take={$take}&skip={$skip}&filter={$filter}&sort={$sort}"));

        return $response->successful() ? $response->json() : [];
    }

    /**
     * Get a list of users configured in Jellyseerr.
     */
    public function getUsers(int $take = 100, int $skip = 0): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $url = "user?take={$take}&skip={$skip}";

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl($url));

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::error("Jellyseerr getUsers failed: " . $response->status());
            return [];
        }

        return $response->json();
    }

    /**
     * Delete a request.
     */
    public function deleteRequest(int $requestId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $response = Http::withHeaders($this->getHeaders())
            ->delete($this->getUrl("request/{$requestId}"));

        return $response->successful();
    }

    /**
     * Get Jellyseerr status overview.
     */
    public function getStatus(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl('status'));

        return $response->successful() ? $response->json() : [];
    }

    /**
     * Get request counts from Jellyseerr.
     */
    public function getRequestCounts(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl('request/count'));

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::error("Jellyseerr getRequestCounts failed: " . $response->status());
            return [];
        }

        return $response->json();
    }

    /**
     * Get detailed info for a specific media to get poster and title.
     */
    public function getMediaDetails(int $tmdbId, string $type = 'movie'): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // Jellyseerr uses 'tv' internally for series
        $urlType = $type === 'tv' || $type === 'series' ? 'tv' : 'movie';

        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getUrl("{$urlType}/{$tmdbId}"));

        return $response->successful() ? $response->json() : null;
    }

}
