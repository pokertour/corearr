<?php

namespace App\Services\MediaStack;

use App\Models\ServiceSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class MediaStackService
{
    /**
     * Get aggregate telemetry from configured services using concurrent requests
     */
    public function getAggregateTelemetry(): array
    {
        $services = ServiceSetting::where('is_active', true)->get()->keyBy('service_name');

        if ($services->isEmpty()) {
            return [];
        }

        $responses = Http::pool(function ($pool) use ($services) {
            $poolRequests = [];

            if ($services->has('sonarr') && $services->get('sonarr')->base_url) {
                $poolRequests[] = $pool->as('sonarr')->withHeaders([
                    'X-Api-Key' => $services->get('sonarr')->api_key,
                ])->get(rtrim($services->get('sonarr')->base_url, '/').'/api/v3/calendar');
            }

            if ($services->has('radarr') && $services->get('radarr')->base_url) {
                $poolRequests[] = $pool->as('radarr')->withHeaders([
                    'X-Api-Key' => $services->get('radarr')->api_key,
                ])->get(rtrim($services->get('radarr')->base_url, '/').'/api/v3/calendar');
            }

            if ($services->has('qbittorrent') && $services->get('qbittorrent')->base_url) {
                // Initial stats check - for telemetry we might use a faster endpoint
                $poolRequests[] = $pool->as('qbittorrent')->get(rtrim($services->get('qbittorrent')->base_url, '/').'/api/v2/sync/maindata');
            }

            return $poolRequests;
        });

        $data = [];

        if (isset($responses['sonarr']) && $responses['sonarr']->ok()) {
            $data['sonarr'] = $responses['sonarr']->json();
        }

        if (isset($responses['radarr']) && $responses['radarr']->ok()) {
            $data['radarr'] = $responses['radarr']->json();
        }

        if (isset($responses['qbittorrent']) && $responses['qbittorrent']->ok()) {
            $data['qbittorrent'] = $responses['qbittorrent']->json();
        }

        return $data;
    }

    /**
     * Test connection to a specific service
     */
    public function testConnection(string $service, array $params): array
    {
        $url = rtrim($params['base_url'], '/');
        $endpoint = match ($service) {
            'sonarr', 'radarr' => '/api/v3/system/status',
            'prowlarr' => '/api/v1/system/status',
            'qbittorrent' => '/api/v2/app/version',
            'jellyseerr' => '/api/v1/status',
            'emby', 'jellyfin' => '/System/Info',
            default => null,
        };

        if (! $endpoint) {
            return ['success' => false, 'message' => "Service $service non reconnu."];
        }

        try {
            if ($service === 'qbittorrent') {
                return $this->testQbitConnection($url, $params['username'] ?? '', $params['password'] ?? '');
            }

            // For Emby/Jellyfin
            if ($service === 'emby' || $service === 'jellyfin') {
                $response = Http::timeout(5)->withHeaders([
                    'X-Emby-Token' => $params['api_key'] ?? '',
                ])->get($url.$endpoint);
            } else {
                $response = Http::timeout(5)->withHeaders([
                    'X-Api-Key' => $params['api_key'] ?? '',
                ])->get($url.$endpoint);
            }

            if ($response->successful()) {
                $jsonData = $response->json();
                $info = $jsonData['version'] ?? $jsonData['Version'] ?? $response->body() ?: 'OK';

                return [
                    'success' => true,
                    'message' => "Connexion réussie à $service ($info)",
                ];
            }

            return ['success' => false, 'message' => $this->getHumanError($response)];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Impossible de joindre le serveur : '.$e->getMessage()];
        }
    }

    private function testQbitConnection(string $url, string $username, string $password): array
    {
        $response = Http::asForm()->post($url.'/api/v2/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);

        if ($response->successful() && $response->body() === 'Ok.') {
            return ['success' => true, 'message' => 'Connexion réussie à qBittorrent (Session validée)'];
        }

        return ['success' => false, 'message' => "Échec d'authentification : ".($response->body() ?: 'Vérifiez vos identifiants')];
    }

    private function getHumanError($response): string
    {
        return match ($response->status()) {
            401 => 'Non autorisé (Clé API invalide ?)',
            403 => 'Accès refusé',
            404 => 'Endpoint non trouvé (URL de base correcte ?)',
            default => 'Erreur HTTP '.$response->status(),
        };
    }

    /**
     * Authenticate against qBittorrent, caching the SID cookie to avoid a login round-trip on every call.
     */
    private function getQbitSessionCookie(ServiceSetting $settings, bool $forceRefresh = false): ?string
    {
        $cacheKey = 'corearr:qbit_session_cookie';

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($settings) {
            $response = Http::asForm()->post(rtrim($settings->base_url, '/').'/api/v2/auth/login', [
                'username' => $settings->username,
                'password' => $settings->password,
            ]);

            if (! $response->successful() || ! $response->header('Set-Cookie')) {
                return null;
            }

            return $response->header('Set-Cookie');
        });
    }

    /**
     * Run a qBittorrent GET request with the cached session, re-authenticating once on 403.
     */
    private function qbitGet(ServiceSetting $settings, string $endpoint): ?Response
    {
        $url = rtrim($settings->base_url, '/');
        $cookie = $this->getQbitSessionCookie($settings);

        if (! $cookie) {
            return null;
        }

        $response = Http::withHeaders(['Cookie' => $cookie])->get($url.$endpoint);

        if ($response->status() === 403) {
            $cookie = $this->getQbitSessionCookie($settings, forceRefresh: true);
            if (! $cookie) {
                return null;
            }
            $response = Http::withHeaders(['Cookie' => $cookie])->get($url.$endpoint);
        }

        return $response;
    }

    /**
     * Get real-time data for qBittorrent
     */
    public function getQbitData(): array
    {
        $settings = ServiceSetting::where('service_name', 'qbittorrent')->first();
        if (! $settings || ! $settings->base_url) {
            return [];
        }

        $mainData = $this->qbitGet($settings, '/api/v2/sync/maindata');

        return $mainData && $mainData->successful() ? $mainData->json() : [];
    }

    /**
     * Global transfer speeds only (lighter than {@see getQbitData}).
     *
     * @return array<string, mixed>
     */
    public function getQbitTransferInfo(): array
    {
        $settings = ServiceSetting::query()
            ->where('service_name', 'qbittorrent')
            ->where('is_active', true)
            ->first();

        if (! $settings || ! $settings->base_url) {
            return [];
        }

        $transfer = $this->qbitGet($settings, '/api/v2/transfer/info');

        return $transfer && $transfer->successful() ? ($transfer->json() ?? []) : [];
    }

    /**
     * Get Calendar data from Sonarr/Radarr
     */
    public function getCalendarEntries(): array
    {
        $services = ServiceSetting::whereIn('service_name', ['sonarr', 'radarr'])->where('is_active', true)->get()->keyBy('service_name');
        $entries = [];

        $start = now()->startOfDay()->format('Y-m-d');
        $end = now()->addDays(14)->endOfDay()->format('Y-m-d');

        foreach ($services as $name => $s) {
            $includeSeries = ($name === 'sonarr') ? '&includeSeries=true' : '';
            $response = Http::withHeaders(['X-Api-Key' => $s->api_key])
                ->get(rtrim($s->base_url, '/')."/api/v3/calendar?start=$start&end=$end&unbuffered=true".$includeSeries);

            if ($response->successful()) {
                foreach ($response->json() as $item) {
                    $item['_source'] = $name;
                    $entries[] = $item;
                }
            }
        }

        // Sort by date (asc) - soonest first
        usort($entries, function ($a, $b) {
            $dateA = $a['airDateUtc'] ?? $a['airDate'] ?? $a['physicalRelease'] ?? $a['digitalRelease'] ?? '9999-12-31';
            $dateB = $b['airDateUtc'] ?? $b['airDate'] ?? $b['physicalRelease'] ?? $b['digitalRelease'] ?? '9999-12-31';

            return strcmp($dateA, $dateB);
        });

        return $entries;
    }

    /**
     * Get system health and overall stats for Arr services (concurrent requests, short cache)
     */
    public function getArrStats(): array
    {
        return Cache::remember(
            'corearr:arr_stats:v1',
            now()->addMinutes(2),
            fn () => $this->buildArrStats()
        );
    }

    private function buildArrStats(): array
    {
        $services = ServiceSetting::whereIn('service_name', ['sonarr', 'radarr', 'prowlarr'])->where('is_active', true)->get()->keyBy('service_name');

        $stats = [
            'radarr' => ['count' => 0, 'disk' => null, 'health' => 'OK'],
            'sonarr' => ['count' => 0, 'disk' => null, 'health' => 'OK'],
            'prowlarr' => ['count' => 0, 'health' => 'OK'],
        ];

        if ($services->isEmpty()) {
            return $stats;
        }

        $responses = Http::pool(function ($pool) use ($services) {
            $requests = [];

            foreach ($services as $name => $s) {
                $baseUrl = rtrim($s->base_url, '/');
                $headers = ['X-Api-Key' => $s->api_key];

                if ($name === 'radarr' || $name === 'sonarr') {
                    $requests[] = $pool->as("$name.list")->withHeaders($headers)->get($baseUrl.'/api/v3/'.($name === 'radarr' ? 'movie' : 'series'));
                    $requests[] = $pool->as("$name.disk")->withHeaders($headers)->get($baseUrl.'/api/v3/diskspace');
                    $requests[] = $pool->as("$name.health")->withHeaders($headers)->get($baseUrl.'/api/v3/health');
                }

                if ($name === 'prowlarr') {
                    $requests[] = $pool->as('prowlarr.indexers')->withHeaders($headers)->get($baseUrl.'/api/v1/indexer');
                    $requests[] = $pool->as('prowlarr.health')->withHeaders($headers)->get($baseUrl.'/api/v1/health');
                }
            }

            return $requests;
        });

        $ok = fn (string $key): bool => isset($responses[$key])
            && $responses[$key] instanceof Response
            && $responses[$key]->successful();

        foreach (['radarr', 'sonarr'] as $name) {
            if (! $services->has($name)) {
                continue;
            }

            if ($ok("$name.list")) {
                $items = $responses["$name.list"]->json();
                $stats[$name]['count'] = count($items);

                if ($name === 'sonarr') {
                    $stats[$name]['episodes'] = [
                        'total' => collect($items)->sum('statistics.episodeCount'),
                        'downloaded' => collect($items)->sum('statistics.episodeFileCount'),
                    ];
                }

                if ($name === 'radarr') {
                    $stats[$name]['movies'] = [
                        'total' => count($items),
                        'downloaded' => collect($items)->where('hasFile', true)->count(),
                    ];
                }
            }

            if ($ok("$name.disk") && ! empty($responses["$name.disk"]->json())) {
                $mainDisk = collect($responses["$name.disk"]->json())->first();
                $stats[$name]['disk'] = [
                    'free' => $mainDisk['freeSpace'] ?? 0,
                    'total' => $mainDisk['totalSpace'] ?? 0,
                    'path' => $mainDisk['path'] ?? '/',
                ];
            }

            if ($ok("$name.health") && ! empty($responses["$name.health"]->json())) {
                $stats[$name]['health'] = 'Warning';
            }
        }

        if ($services->has('prowlarr')) {
            if ($ok('prowlarr.indexers')) {
                $stats['prowlarr']['count'] = count($responses['prowlarr.indexers']->json());
            }

            if ($ok('prowlarr.health') && ! empty($responses['prowlarr.health']->json())) {
                $stats['prowlarr']['health'] = 'Warning';
            }
        }

        return $stats;
    }

    /**
     * Build an absolute poster URL via the local proxy
     */
    public function getPosterUrl(string $service, ?string $path): string
    {
        if (! $path) {
            return '';
        }

        // Remove existing apikey if any for cleaner proxy
        $cleanPath = preg_replace('/[?&]apikey=[^&]+/', '', $path);

        return "/media-proxy/$service".(str_starts_with($cleanPath, '/') ? '' : '/').$cleanPath;
    }

    /**
     * Get Media History (Events)
     */
    public function getHistory(string $service, int $id): array
    {
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
            return [];
        }

        $endpoint = $service === 'radarr' ? '/api/v3/history/movie' : '/api/v3/history/series';
        $param = $service === 'radarr' ? 'movieId' : 'seriesId';

        $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])
            ->get(rtrim($settings->base_url, '/')."$endpoint?$param=$id");

        return $response->successful() ? $response->json() : [];
    }

    /**
     * Get Episodes for a series (Sonarr)
     */
    public function getEpisodes(string $service, int $id): array
    {
        if ($service !== 'sonarr') {
            return [];
        }

        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
            return [];
        }

        $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])
            ->get(rtrim($settings->base_url, '/')."/api/v3/episode?seriesId=$id");

        return $response->successful() ? $response->json() : [];
    }

    /**
     * Get Media Files
     */
    public function getFiles(string $service, int $id): array
    {
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
            return [];
        }

        $endpoint = $service === 'radarr' ? '/api/v3/moviefile' : '/api/v3/episodefile';
        $param = $service === 'radarr' ? 'movieId' : 'seriesId';

        $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])
            ->get(rtrim($settings->base_url, '/')."$endpoint?$param=$id");

        return $response->successful() ? $response->json() : [];
    }

    /**
     * Get a fallback poster URL using TMDB/IMDB/TVDB identifiers
     */
    public function getFallbackPoster(array $item): ?string
    {
        // For Movies (Radarr)
        if (isset($item['tmdbId']) && $item['tmdbId'] > 0) {
            // We can't easily guess the poster path on TMDB without an API key,
            // but we can look for 'remoteUrl' in the images array if it exists.
        }

        return collect($item['images'] ?? [])->firstWhere('remoteUrl')['remoteUrl'] ?? null;
    }

    /**
     * Get a random backdrop URL from active media services
     */
    public function getRandomBackdropUrl(): ?string
    {
        // Use Cache to avoid hitting Arr services for every single guest page load
        return Cache::remember('corearr_guest_backdrop', now()->addMinutes(30), function () {
            $services = ServiceSetting::whereIn('service_name', ['radarr', 'sonarr'])
                ->where('is_active', true)
                ->get()
                ->shuffle();

            if ($services->isEmpty()) {
                return null;
            }

            foreach ($services as $service) {
                $endpoint = $service->service_name === 'radarr' ? 'movie' : 'series';
                $items = $this->request($service->service_name, 'GET', $endpoint);

                if (empty($items)) {
                    continue;
                }

                // Filter for items with images and monitored
                $qualifiedItems = collect($items)->filter(fn ($item) => ! empty($item['images']) && ($item['monitored'] ?? true));

                // Fallback to non-monitored if none found
                if ($qualifiedItems->isEmpty()) {
                    $qualifiedItems = collect($items)->filter(fn ($item) => ! empty($item['images']));
                }

                if ($qualifiedItems->isNotEmpty()) {
                    $item = $qualifiedItems->random();

                    // Prefer fanart/backdrop for backgrounds
                    $backdrop = collect($item['images'])->firstWhere('coverType', 'fanart')
                        ?? collect($item['images'])->firstWhere('coverType', 'backdrop')
                        ?? collect($item['images'])->first();

                    if ($backdrop) {
                        // Use local URL if available, otherwise remoteUrl
                        $path = $backdrop['url'] ?? $backdrop['remoteUrl'] ?? null;

                        if ($path) {
                            // Split potential query params (like ?lastWrite=...) to avoid double encoding in signedRoute
                            $urlParts = explode('?', $path);
                            $cleanPath = ltrim($urlParts[0], '/');
                            $params = ['service' => $service->service_name, 'path' => $cleanPath];

                            if (isset($urlParts[1])) {
                                parse_str($urlParts[1], $extraParams);
                                $params = array_merge($params, $extraParams);
                            }

                            return URL::temporarySignedRoute(
                                'media.proxy',
                                now()->addHours(2),
                                $params
                            );
                        }
                    }
                }
            }

            return null;
        });
    }

    /**
     * Get a single media item by ID
     */
    public function getMedia(string $service, int $id): array
    {
        $endpoint = ($service === 'radarr' ? 'movie' : 'series').'/'.$id;
        $media = $this->request($service, 'GET', $endpoint);

        if (empty($media)) {
            return [];
        }

        // Normalize ratings for Sonarr (usually a single object, while Radarr is an associative array of objects)
        if ($service === 'sonarr' && isset($media['ratings'])) {
            if (isset($media['ratings']['value']) && ! isset($media['ratings']['imdb'])) {
                $media['ratings'] = [
                    'Sonarr' => [
                        'value' => $media['ratings']['value'],
                        'votes' => $media['ratings']['votes'] ?? 0,
                        'type' => 'user',
                    ],
                ];
            }
        }

        return $media;
    }

    /**
     * Internal helper to make requests to Arr services
     */
    private function request(string $service, string $method, string $endpoint, array $data = []): array
    {
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
            return [];
        }

        $v = ($service === 'prowlarr') ? '/api/v1' : '/api/v3';
        $url = rtrim($settings->base_url, '/').$v.'/'.ltrim($endpoint, '/');

        $request = Http::withHeaders(['X-Api-Key' => $settings->api_key]);

        // For DELETE requests with query params, avoid sending an empty JSON body if data is empty
        $response = (strtoupper($method) === 'DELETE' && empty($data))
            ? $request->delete($url)
            : $request->{strtolower($method)}($url, $data);

        if (! $response->successful()) {
            Log::error("MediaStack API Error ($service $method $endpoint): ".$response->status().' - '.$response->body());

            return [];
        }

        return $response->json() ?: [];
    }

    /**
     * Get available quality profiles
     */
    public function getQualityProfiles(string $service): array
    {
        return $this->request($service, 'GET', 'qualityprofile');
    }

    /**
     * Library-wide file stats for dashboard charts (Radarr + Sonarr), cached briefly.
     *
     * @return array{configured: bool, total_files: int, codec: array<string, int>, quality_rows: array<int, array{label: string, count: int}>}
     */
    public function getLibraryFileAnalytics(): array
    {
        return Cache::remember(
            'corearr:library_file_analytics:v1',
            now()->addMinutes(5),
            fn () => $this->buildLibraryFileAnalytics()
        );
    }

    /**
     * All disk volumes reported by Radarr/Sonarr (cached a few minutes).
     *
     * @return array{radarr: array<int, array{path: string, label: string, total: int, free: int, used: int, used_percent: float}>, sonarr: array<int, array{path: string, label: string, total: int, free: int, used: int, used_percent: float}>}
     */
    public function getArrDiskspacePanels(): array
    {
        return Cache::remember(
            'corearr:arr_diskspace_panels:v1',
            now()->addMinutes(3),
            fn () => $this->buildArrDiskspacePanels()
        );
    }

    /**
     * Queue activity with warning counts + sample rows for the dashboard (short cache).
     *
     * @return array{radarr: array<string, mixed>, sonarr: array<string, mixed>}
     */
    public function getArrQueueOverview(): array
    {
        return Cache::remember(
            'corearr:arr_queue_overview:v1',
            now()->addSeconds(55),
            fn () => $this->buildArrQueueOverview()
        );
    }

    /**
     * Monitored vs unmonitored library items (movies / series lists).
     *
     * @return array{radarr: array<string, int|bool>, sonarr: array<string, int|bool>}
     */
    public function getArrMonitoredSummary(): array
    {
        return Cache::remember(
            'corearr:arr_monitored_summary:v1',
            now()->addMinutes(5),
            fn () => $this->buildArrMonitoredSummary()
        );
    }

    /**
     * Update media details (e.g., toggle monitored)
     */
    public function updateMedia(string $service, array $data): array
    {
        $endpoint = $service === 'radarr' ? 'movie' : 'series';

        // Sonarr V3 PUT /series endpoint expects an object, Radarr V3 PUT /movie too.
        return $this->request($service, 'PUT', $endpoint, $data);
    }

    /**
     * Delete a media item
     */
    public function deleteMedia(string $service, int $id, bool $deleteFiles = false): bool
    {
        Log::debug('MediaStackService->deleteMedia started', ['service' => $service, 'id' => $id, 'deleteFiles' => $deleteFiles]);

        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings || $id <= 0) {
            if ($id <= 0) {
                Log::warning("MediaStack: Attempted to delete media with invalid ID 0 ($service)");
            } else {
                Log::error("MediaStack: Settings not found for service $service");
            }

            return false;
        }

        $v = '/api/v3';
        $url = rtrim($settings->base_url, '/').$v.'/'.($service === 'radarr' ? 'movie' : 'series').'/'.$id;

        $params = [
            'deleteFiles' => $deleteFiles ? 'true' : 'false',
        ];

        if ($service === 'radarr') {
            $params['addImportExclusion'] = 'false';
        }

        // Use withQueryParameters to ensure these are in the URL string, not the body
        $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])
            ->withQueryParameters($params)
            ->delete($url);

        Log::debug('MediaStack Delete Request Sent', [
            'url' => $url,
            'params' => $params,
            'status' => $response->status(),
        ]);

        if (! $response->successful()) {
            Log::error("MediaStack Delete Media Failed ($service ID $id): ".$response->status().' - '.$response->body());

            return false;
        }

        return true;
    }

    /**
     * Get indexers from Prowlarr
     */
    public function getIndexers(): array
    {
        return $this->request('prowlarr', 'GET', 'indexer');
    }

    /**
     * Get indexers status from Prowlarr
     */
    public function getIndexersStatus(): array
    {
        return $this->request('prowlarr', 'GET', 'indexerstatus');
    }

    /**
     * Save/Update indexer in Prowlarr
     */
    public function saveIndexer(array $data): array
    {
        $id = $data['id'] ?? null;
        $method = $id ? 'PUT' : 'POST';
        $endpoint = 'indexer'.($id ? '/'.$id : '');

        return $this->request('prowlarr', $method, $endpoint, $data);
    }

    /**
     * Delete indexer from Prowlarr
     */
    public function deleteIndexer(int $id): bool
    {
        $this->request('prowlarr', 'DELETE', 'indexer/'.$id);

        return true;
    }

    /**
     * Test a specific indexer
     */
    public function testIndexer(int $id): bool
    {
        // Prowlarr usually tests on save or via a separate test endpoint
        // Many Arr services use the indexer/test endpoint for this
        $response = $this->request('prowlarr', 'POST', 'indexer/test', ['id' => $id]);

        return ! empty($response);
    }

    /**
     * Get releases for a specific media item, season or episode
     */
    public function getReleases(string $service, int $mediaId, ?int $seasonNumber = null, ?int $episodeId = null): array
    {
        $query = $service === 'radarr'
            ? ['movieId' => $mediaId]
            : ['seriesId' => $mediaId];

        if ($service === 'sonarr') {
            if ($episodeId !== null) {
                $query['episodeId'] = $episodeId;
            } elseif ($seasonNumber !== null) {
                $query['seasonNumber'] = $seasonNumber;
            }
        }

        $endpoint = 'release';
        $settings = ServiceSetting::where('service_name', $service)->first();
        $apiPrefix = $service === 'prowlarr' ? '/api/v1' : '/api/v3';
        $baseUrl = $settings
            ? rtrim((string) $settings->base_url, '/').$apiPrefix.'/'.ltrim($endpoint, '/')
            : null;

        if (! $settings || ! $baseUrl) {
            return [];
        }

        $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])->get($baseUrl, $query);

        if (! $response->successful()) {
            Log::error("MediaStack API Error ($service GET release): ".$response->status().' - '.$response->body());

            return [];
        }

        $payload = $response->json() ?: [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * Download a specific release
     */
    public function downloadRelease(string $service, string $guid, int $indexerId): bool
    {
        $response = $this->request($service, 'POST', 'release', [
            'guid' => $guid,
            'indexerId' => $indexerId,
        ]);

        return ! empty($response);
    }

    /**
     * Perform an action on a torrent in qBittorrent
     */
    public function performQbitAction(string $action, string $hash): bool
    {
        $settings = ServiceSetting::where('service_name', 'qbittorrent')->first();
        if (! $settings) {
            return false;
        }

        $url = rtrim($settings->base_url, '/');

        $cookie = $this->getQbitSessionCookie($settings);

        if ($cookie) {
            $endpoint = match ($action) {
                'pause' => '/api/v2/torrents/pause',
                'resume' => '/api/v2/torrents/resume',
                'delete' => '/api/v2/torrents/delete',
                default => null,
            };

            if (! $endpoint) {
                return false;
            }

            if (empty($hash)) {
                Log::warning("qBittorrent: Tentative d'action ($action) sans hash de torrent.");

                return false;
            }

            $payload = ['hashes' => $hash];
            if ($action === 'delete') {
                $payload['deleteFiles'] = 'true';
            }

            // First attempt with legacy/standard endpoint
            $res = Http::withHeaders([
                'Cookie' => $cookie,
                'Referer' => $url,
                'Origin' => $url,
            ])->asForm()->post($url.$endpoint, $payload);

            // Cached session may have expired — re-authenticate once and retry
            if ($res->status() === 403) {
                $cookie = $this->getQbitSessionCookie($settings, forceRefresh: true);
                if (! $cookie) {
                    return false;
                }
                $res = Http::withHeaders([
                    'Cookie' => $cookie,
                    'Referer' => $url,
                    'Origin' => $url,
                ])->asForm()->post($url.$endpoint, $payload);
            }

            // Handle qBittorrent v5.0+ where pause/resume are stop/start
            if ($res->status() === 404 && in_array($action, ['pause', 'resume'])) {
                $altEndpoint = match ($action) {
                    'pause' => '/api/v2/torrents/stop',
                    'resume' => '/api/v2/torrents/start',
                    default => null,
                };

                if ($altEndpoint) {
                    $res = Http::withHeaders([
                        'Cookie' => $cookie,
                        'Referer' => $url,
                        'Origin' => $url,
                    ])->asForm()->post($url.$altEndpoint, $payload);
                }
            }

            if (! $res->successful()) {
                Log::error("qBittorrent Action Failed ($action): ".$res->status().' - '.$res->body());

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Trigger a background command on the Arr service
     */
    public function triggerBgCommand(string $service, string $name, array $payload = []): bool
    {
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
            return false;
        }

        $endpoint = ($service === 'prowlarr') ? '/api/v1/command' : '/api/v3/command';

        $body = array_merge(['name' => $name], $payload);

        // We use afterResponse logic in the caller, but here we just send the async-ish request
        $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])
            ->post(rtrim($settings->base_url, '/').$endpoint, $body);

        return $response->successful();
    }

    /**
     * Update indexer priority in Prowlarr
     */
    public function updateIndexerPriority(int $id, int $priority): bool
    {
        $settings = ServiceSetting::where('service_name', 'prowlarr')->first();
        if (! $settings) {
            return false;
        }

        $url = rtrim($settings->base_url, '/');

        // Fetch current indexer data first to avoid overwriting other settings
        $current = Http::withHeaders(['X-Api-Key' => $settings->api_key])->get("$url/api/v1/indexer/$id");
        if (! $current->successful()) {
            return false;
        }

        $data = $current->json();
        $data['priority'] = $priority;

        $response = Http::withHeaders(['X-Api-Key' => $settings->api_key])->put("$url/api/v1/indexer/$id", $data);

        return $response->successful();
    }

    /**
     * Find all media IDs for a given TMDB ID.
     * CRITICAL: We fetch the full list and filter LOCALLY to ensure we only get
     * the item matching the TMDB ID, as some *arr versions ignore the query param.
     */
    public function findMediaIdsByTmdbId(string $service, int $tmdbId): array
    {
        if ($tmdbId <= 0) {
            return [];
        }

        $endpoint = $service === 'radarr' ? 'movie' : 'series';
        $ids = [];

        // Fetch the full library and filter locally to be 100% safe
        $response = $this->request($service, 'GET', $endpoint);

        if (! empty($response) && is_array($response)) {
            // Some versions return a single object if only one exists (rare but possible)
            if (isset($response['id'])) {
                $response = [$response];
            }

            foreach ($response as $item) {
                // Check tmdbId (Radarr) or tvdbId/tmdbId (Sonarr depends on version)
                $itemTmdbId = $item['tmdbId'] ?? null;

                if ($itemTmdbId == $tmdbId && isset($item['id'])) {
                    $ids[] = $item['id'];
                }
            }
        }

        return array_unique($ids);
    }

    /**
     * Attempt to find a media item by its TMDB ID in Radarr/Sonarr
     * returns the internal ID (movie ID or series ID) or null
     */
    public function findMediaByTmdbId(string $service, int $tmdbId): ?int
    {
        Log::debug('MediaStackService->findMediaByTmdbId started', ['service' => $service, 'tmdbId' => $tmdbId]);

        $ids = $this->findMediaIdsByTmdbId($service, $tmdbId);
        $id = $ids[0] ?? null;

        if ($id) {
            Log::debug("MediaStack Fallback: Found ID $id");
        } else {
            Log::warning("MediaStack Fallback: No media found for TMDB $tmdbId in $service");
        }

        return $id;
    }

    /**
     * @return array{configured: bool, total_files: int, codec: array<string, int>, quality_rows: array<int, array{label: string, count: int}>}
     */
    private function buildLibraryFileAnalytics(): array
    {
        $configured = $this->isArrServiceConfigured('radarr') || $this->isArrServiceConfigured('sonarr');

        $files = array_merge($this->listRadarrMovieFiles(), $this->listSonarrEpisodeFiles());

        $codec = [
            'h264' => 0,
            'hevc' => 0,
            'other' => 0,
            'unknown' => 0,
        ];
        $qualityCounts = [];

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $bucket = $this->categorizeVideoCodecForLibrary($file['mediaInfo']['videoCodec'] ?? null);
            $codec[$bucket]++;

            $label = (string) ($file['quality']['quality']['name'] ?? '');
            if ($label === '') {
                $label = __('messages.dashboard_lib_quality_unknown');
            }
            $qualityCounts[$label] = ($qualityCounts[$label] ?? 0) + 1;
        }

        return [
            'configured' => $configured,
            'total_files' => count($files),
            'codec' => $codec,
            'quality_rows' => $this->rollupQualityRows($qualityCounts),
        ];
    }

    private function isArrServiceConfigured(string $service): bool
    {
        return ServiceSetting::query()
            ->where('service_name', $service)
            ->where('is_active', true)
            ->whereNotNull('base_url')
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listRadarrMovieFiles(): array
    {
        if (! $this->isArrServiceConfigured('radarr')) {
            return [];
        }

        $raw = $this->request('radarr', 'GET', 'movieFile');
        $normalized = $this->normalizeEndpointListPayload($raw);

        if ($normalized !== []) {
            return $normalized;
        }

        $fromMovies = [];
        $movies = $this->request('radarr', 'GET', 'movie');
        foreach ($this->normalizeEndpointListPayload($movies) as $movie) {
            if (! empty($movie['movieFile']) && is_array($movie['movieFile'])) {
                $fromMovies[] = $movie['movieFile'];
            }
        }

        return $fromMovies;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listSonarrEpisodeFiles(): array
    {
        if (! $this->isArrServiceConfigured('sonarr')) {
            return [];
        }

        $raw = $this->request('sonarr', 'GET', 'episodeFile');

        return $this->normalizeEndpointListPayload($raw);
    }

    /**
     * @param  array<mixed>|mixed  $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEndpointListPayload(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        if (isset($raw['id']) && isset($raw['quality'])) {
            return [$raw];
        }

        $out = [];

        foreach ($raw as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Group codecs for high-level dashboards (main buckets: AVC/x264 vs HEVC/x265).
     *
     * @phpstan-return 'h264'|'hevc'|'other'|'unknown'
     */
    private function categorizeVideoCodecForLibrary(?string $codec): string
    {
        $original = strtolower(trim((string) $codec));
        $compact = preg_replace('/[^a-z0-9]/', '', $original) ?? '';

        if ($compact === '' || str_contains($original, 'unknown')) {
            return 'unknown';
        }

        if (str_contains($compact, 'x265') || str_contains($compact, 'h265') || str_contains($compact, 'hevc')) {
            return 'hevc';
        }

        if (str_contains($compact, 'x264') || str_contains($compact, 'h264') || str_contains($compact, 'avc')) {
            return 'h264';
        }

        if (str_contains($compact, 'mpeg4') || str_contains($compact, 'divx') || str_contains($compact, 'xvid')) {
            return 'h264';
        }

        if (str_contains($compact, 'av01') || str_contains($compact, 'av1')) {
            return 'other';
        }

        return 'other';
    }

    /**
     * @param  array<string, int>  $qualityCounts
     * @return array<int, array{label: string, count: int}>
     */
    private function rollupQualityRows(array $qualityCounts): array
    {
        if ($qualityCounts === []) {
            return [];
        }

        arsort($qualityCounts, SORT_NUMERIC);

        $top = 12;
        $rows = [];
        $i = 0;
        $other = 0;

        foreach ($qualityCounts as $label => $count) {
            if ($i < $top) {
                $rows[] = ['label' => $label, 'count' => $count];
                $i++;
            } else {
                $other += $count;
            }
        }

        if ($other > 0) {
            $rows[] = ['label' => __('messages.dashboard_lib_quality_other'), 'count' => $other];
        }

        return $rows;
    }

    /**
     * @return array{radarr: array<int, array{path: string, label: string, total: int, free: int, used: int, used_percent: float}>, sonarr: array<int, array{path: string, label: string, total: int, free: int, used: int, used_percent: float}>}
     */
    private function buildArrDiskspacePanels(): array
    {
        $panels = ['radarr' => [], 'sonarr' => []];

        foreach (['radarr', 'sonarr'] as $svc) {
            if (! $this->isArrServiceConfigured($svc)) {
                continue;
            }

            $raw = $this->request($svc, 'GET', 'diskspace');
            if (! is_array($raw) || $raw === []) {
                continue;
            }

            $rows = array_is_list($raw) ? $raw : [$raw];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $total = (int) ($row['totalSpace'] ?? 0);
                if ($total <= 0) {
                    continue;
                }
                $free = max(0, (int) ($row['freeSpace'] ?? 0));
                $used = max(0, $total - $free);

                $path = (string) ($row['path'] ?? '');
                $panels[$svc][] = [
                    'path' => $path,
                    'label' => (string) ($row['label'] ?? '') ?: $path,
                    'total' => $total,
                    'free' => $free,
                    'used' => $used,
                    'used_percent' => round(($used / $total) * 100, 1),
                ];
            }
        }

        return $panels;
    }

    /**
     * @return array{radarr: array<string, mixed>, sonarr: array<string, mixed>}
     */
    private function buildArrQueueOverview(): array
    {
        return [
            'radarr' => $this->buildServiceQueueSummary('radarr'),
            'sonarr' => $this->buildServiceQueueSummary('sonarr'),
        ];
    }

    /**
     * @return array{configured: bool, total: int, warnings: int, items: array<int, array{title: string, warning: bool, subtitle: string}>}
     */
    private function buildServiceQueueSummary(string $service): array
    {
        if (! $this->isArrServiceConfigured($service)) {
            return ['configured' => false, 'total' => 0, 'warnings' => 0, 'items' => []];
        }

        $payload = $this->request($service, 'GET', 'queue?pageSize=100');

        /** @var list<array<string, mixed>> $records */
        $records = $payload['records'] ?? [];
        if ($records === [] && is_array($payload) && isset($payload[0]) && ! isset($payload['totalRecords'])) {
            $records = array_values(array_filter($payload, 'is_array'));
        }

        if (! is_array($records)) {
            return ['configured' => true, 'total' => 0, 'warnings' => 0, 'items' => []];
        }

        $total = (int) ($payload['totalRecords'] ?? count($records));

        $warnings = 0;
        $items = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            if ($this->queueRecordHasWarning($record)) {
                $warnings++;
            }
            if (count($items) >= 8) {
                continue;
            }
            $parsed = $this->parseQueueRecordForDashboard($record, $service);

            $items[] = [
                'title' => $parsed['title'],
                'subtitle' => $parsed['subtitle'],
                'warning' => $this->queueRecordHasWarning($record),
            ];
        }

        return [
            'configured' => true,
            'total' => $total,
            'warnings' => $warnings,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function queueRecordHasWarning(array $record): bool
    {
        $err = trim((string) ($record['errorMessage'] ?? ''));

        if ($err !== '') {
            return true;
        }

        $status = strtolower((string) ($record['status'] ?? ''));

        if (str_contains($status, 'fail') || str_contains($status, 'error')) {
            return true;
        }

        $state = strtolower((string) ($record['trackedDownloadState'] ?? ''));

        return str_contains($state, 'fail')
            || str_contains($state, 'warning');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{title: string, subtitle: string}
     */
    private function parseQueueRecordForDashboard(array $record, string $service): array
    {
        if ($service === 'radarr') {
            $movie = $record['movie'] ?? [];

            return [
                'title' => (string) (is_array($movie) ? ($movie['title'] ?? '') : '') ?: (string) ($record['title'] ?? __('messages.dashboard_arr_queue_unknown_title')),
                'subtitle' => (string) ($record['status'] ?? ''),
            ];
        }

        $series = is_array($record['series'] ?? null) ? $record['series'] : [];
        $episode = is_array($record['episode'] ?? null) ? $record['episode'] : [];
        $sTitle = (string) ($series['title'] ?? '');
        $season = $episode['seasonNumber'] ?? null;
        $epNum = $episode['episodeNumber'] ?? null;
        $epTitle = (string) ($episode['title'] ?? '');

        $num = '';

        if ($season !== null && $epNum !== null) {
            $num = 'S'.(int) $season.'E'.(int) $epNum;
        }

        return [
            'title' => $sTitle ?: (string) ($record['title'] ?? __('messages.dashboard_arr_queue_unknown_title')),
            'subtitle' => trim($num.($epTitle !== '' ? ' · '.$epTitle : '')),
        ];
    }

    /**
     * @return array{radarr: array<string, int|bool>, sonarr: array<string, int|bool>}
     */
    private function buildArrMonitoredSummary(): array
    {
        $out = [
            'radarr' => ['configured' => false, 'monitored' => 0, 'unmonitored' => 0],
            'sonarr' => ['configured' => false, 'monitored' => 0, 'unmonitored' => 0],
        ];

        if ($this->isArrServiceConfigured('radarr')) {
            $movies = $this->request('radarr', 'GET', 'movie');
            foreach ($this->flattenMediaListPayload($movies) as $movie) {
                if (($movie['monitored'] ?? false) === true) {
                    $out['radarr']['monitored']++;
                } else {
                    $out['radarr']['unmonitored']++;
                }
            }
            $out['radarr']['configured'] = true;
        }

        if ($this->isArrServiceConfigured('sonarr')) {
            $series = $this->request('sonarr', 'GET', 'series');

            foreach ($this->flattenMediaListPayload($series) as $row) {
                if (($row['monitored'] ?? false) === true) {
                    $out['sonarr']['monitored']++;
                } else {
                    $out['sonarr']['unmonitored']++;
                }
            }
            $out['sonarr']['configured'] = true;
        }

        return $out;
    }

    /**
     * Normalize /movie or /series API payloads into a numeric list.
     *
     * @return list<array<string, mixed>>
     */
    private function flattenMediaListPayload(mixed $payload): array
    {
        if (! is_array($payload) || $payload === []) {
            return [];
        }

        if (isset($payload['id']) && (isset($payload['title']) || isset($payload['cleanTitle']) || isset($payload['sortTitle']))) {
            return [$payload];
        }

        if (! array_is_list($payload)) {
            return [];
        }

        $out = [];

        foreach ($payload as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
