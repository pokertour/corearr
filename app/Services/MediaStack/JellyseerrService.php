<?php

namespace App\Services\MediaStack;

use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            Log::error('Jellyseerr getUsers failed: '.$response->status());

            return [];
        }

        return $response->json();
    }

    /**
     * Delete a request.
     */
    public function deleteRequest(int $requestId): bool
    {
        Log::info('JellyseerrService->deleteRequest started', ['id' => $requestId]);

        if (! $this->isConfigured()) {
            Log::error('JellyseerrService: Service not configured');

            return false;
        }

        $url = $this->getUrl("request/{$requestId}");
        $response = Http::withHeaders($this->getHeaders())->delete($url);

        Log::info('Jellyseerr Delete Request Sent', [
            'url' => $url,
            'status' => $response->status(),
        ]);

        if (! $response->successful()) {
            Log::error("Jellyseerr Delete Request Failed (ID {$requestId}): ".$response->status().' - '.$response->body());

            return false;
        }

        return true;
    }

    /**
     * Delete a media object (purges from library and removes all associated requests).
     */
    public function deleteMedia(int $mediaId): bool
    {
        Log::info('JellyseerrService->deleteMedia started', ['id' => $mediaId]);

        if (! $this->isConfigured()) {
            Log::error('JellyseerrService: Service not configured');

            return false;
        }

        $url = $this->getUrl("media/{$mediaId}");
        $response = Http::withHeaders($this->getHeaders())->delete($url);

        Log::info('Jellyseerr Delete Media Sent', [
            'url' => $url,
            'status' => $response->status(),
        ]);

        if (! $response->successful()) {
            Log::error("Jellyseerr Delete Media Failed (ID {$mediaId}): ".$response->status().' - '.$response->body());

            return false;
        }

        return true;
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

        if (! $response->successful()) {
            Log::error('Jellyseerr getRequestCounts failed: '.$response->status());

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

    /**
     * Get multiple media details in parallel with chunking and uniqueness.
     */
    public function getBulkMediaDetails(array $items): array
    {
        if (! $this->isConfigured() || empty($items)) {
            return [];
        }

        // Unique by tmdbId + type to avoid duplicate requests
        $uniqueRequested = [];
        foreach ($items as $item) {
            $key = "{$item['type']}_{$item['tmdbId']}";
            $uniqueRequested[$key] = $item;
        }

        $headers = $this->getHeaders();
        $results = [];

        // Process in chunks of 15 to avoid overwhelming the server or timing out
        $chunks = array_chunk(array_values($uniqueRequested), 15);

        foreach ($chunks as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk, $headers) {
                foreach ($chunk as $item) {
                    $tmdbId = $item['tmdbId'];
                    $urlType = ($item['type'] === 'tv' || $item['type'] === 'series') ? 'tv' : 'movie';
                    $pool->as("{$urlType}_{$tmdbId}")
                        ->withHeaders($headers)
                        ->timeout(3)
                        ->get($this->getUrl("{$urlType}/{$tmdbId}"));
                }
            });

            foreach ($responses as $key => $response) {
                if ($response->successful()) {
                    $results[$key] = $response->json();
                }
            }
        }

        return $results;
    }
}
