<?php

namespace App\Services\MediaStack;

use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Get real-time data for qBittorrent
     */
    public function getQbitData(): array
    {
        $settings = ServiceSetting::where('service_name', 'qbittorrent')->first();
        if (! $settings || ! $settings->base_url) {
            return [];
        }

        $url = rtrim($settings->base_url, '/');

        // Use cookie session if possible, or login again
        $response = Http::asForm()->post($url.'/api/v2/auth/login', [
            'username' => $settings->username,
            'password' => $settings->password,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $cookie = $response->header('Set-Cookie');

        $mainData = Http::withHeaders(['Cookie' => $cookie])->get($url.'/api/v2/sync/maindata');

        return $mainData->successful() ? $mainData->json() : [];
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
     * Get system health and overall stats for Arr services
     */
    public function getArrStats(): array
    {
        $services = ServiceSetting::whereIn('service_name', ['sonarr', 'radarr', 'prowlarr'])->where('is_active', true)->get()->keyBy('service_name');

        $stats = [
            'radarr' => ['count' => 0, 'disk' => null, 'health' => 'OK'],
            'sonarr' => ['count' => 0, 'disk' => null, 'health' => 'OK'],
            'prowlarr' => ['count' => 0, 'health' => 'OK'],
        ];

        foreach ($services as $name => $s) {
            $baseUrl = rtrim($s->base_url, '/');
            $apiKey = $s->api_key;

            if ($name === 'radarr' || $name === 'sonarr') {
                $v = '/api/v3';

                // Count
                $countRes = Http::withHeaders(['X-Api-Key' => $apiKey])->get($baseUrl."$v/".($name === 'radarr' ? 'movie' : 'series'));
                if ($countRes->successful()) {
                    $stats[$name]['count'] = count($countRes->json());
                }

                // Disk
                $diskRes = Http::withHeaders(['X-Api-Key' => $apiKey])->get($baseUrl."$v/diskspace");
                if ($diskRes->successful() && ! empty($diskRes->json())) {
                    $mainDisk = collect($diskRes->json())->first();
                    $stats[$name]['disk'] = [
                        'free' => $mainDisk['freeSpace'] ?? 0,
                        'total' => $mainDisk['totalSpace'] ?? 0,
                        'path' => $mainDisk['path'] ?? '/',
                    ];
                }

                // Episodes count for Sonarr
                if ($name === 'sonarr' && $countRes->successful()) {
                    $items = $countRes->json();
                    $stats[$name]['episodes'] = [
                        'total' => collect($items)->sum('statistics.episodeCount'),
                        'downloaded' => collect($items)->sum('statistics.episodeFileCount'),
                    ];
                }

                // Movies count for Radarr
                if ($name === 'radarr' && $countRes->successful()) {
                    $items = $countRes->json();
                    $stats[$name]['movies'] = [
                        'total' => count($items),
                        'downloaded' => collect($items)->where('hasFile', true)->count(),
                    ];
                }
            }

            if ($name === 'prowlarr') {
                $indexerRes = Http::withHeaders(['X-Api-Key' => $apiKey])->get($baseUrl.'/api/v1/indexer');
                if ($indexerRes->successful()) {
                    $stats['prowlarr']['count'] = count($indexerRes->json());
                }
            }

            // Health check for all (simplified)
            $healthUrl = ($name === 'prowlarr') ? '/api/v1/health' : '/api/v3/health';
            $healthRes = Http::withHeaders(['X-Api-Key' => $apiKey])->get($baseUrl.$healthUrl);
            if ($healthRes->successful() && ! empty($healthRes->json())) {
                $stats[$name]['health'] = 'Warning';
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
        $settings = ServiceSetting::where('service_name', $service)->first();
        if (! $settings) {
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
        $param = $service === 'radarr' ? 'movieId' : 'seriesId';
        $endpoint = "release?$param=$mediaId";

        if ($service === 'sonarr') {
            if ($episodeId) {
                $endpoint = "release?episodeId=$episodeId";
            } elseif ($seasonNumber !== null) {
                $endpoint .= "&seasonNumber=$seasonNumber";
            }
        }

        return $this->request($service, 'GET', $endpoint);
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

        // Ensure authentication
        $authResponse = Http::asForm()->post($url.'/api/v2/auth/login', [
            'username' => $settings->username,
            'password' => $settings->password,
        ]);

        if ($authResponse->successful()) {
            $cookie = $authResponse->header('Set-Cookie');
            if (! $cookie) {
                Log::warning('qBittorrent: Aucun cookie SID trouvé dans la réponse de login.');

                return false;
            }

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
}
