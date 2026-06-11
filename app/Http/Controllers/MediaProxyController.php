<?php

namespace App\Http\Controllers;

use App\Models\ServiceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MediaProxyController extends Controller
{
    /**
     * Proxy image requests to Arr services (authenticated via X-Api-Key header).
     */
    public function __invoke(string $service, string $path, Request $request)
    {
        $settings = ServiceSetting::where('service_name', $service)->where('is_active', true)->first();

        if (! $settings || ! $settings->base_url) {
            Log::warning("Proxy: Service '$service' non trouvé ou inactif.");
            abort(404, "Service $service non trouvé.");
        }

        // 1. Ensure /api/v3 prefix for Arr services (Radarr, Sonarr, Prowlarr)
        $cleanPath = ltrim($path, '/');
        if (! str_starts_with($cleanPath, 'api/v3/') && in_array($service, ['radarr', 'sonarr', 'prowlarr'])) {
            $cleanPath = 'api/v3/'.$cleanPath;
        }

        // 2. Security: Dual-check for Auth or Valid Signature
        if (! auth()->check() && ! $request->hasValidSignature()) {
            abort(403, 'Accès non autorisé ou lien expiré.');
        }

        // 3. Build Target URL (API key is only sent via the X-Api-Key header, never in the URL)
        $targetUrl = rtrim($settings->base_url, '/').'/'.$cleanPath;

        // 4. Forward any extra query parameters (except apikey)
        foreach ($request->query() as $key => $val) {
            if ($key !== 'apikey') {
                $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?')."$key=".urlencode($val);
            }
        }

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $settings->api_key,
            ])->withOptions([
                'verify' => false,
                'allow_redirects' => true,
            ])->timeout(15)->get($targetUrl);

            if ($response->successful()) {
                $data = $response->body();
                $type = $response->header('Content-Type') ?: 'image/jpeg';

                return response($data)
                    ->header('Content-Type', $type)
                    ->header('Cache-Control', 'public, max-age=86400');
            }

            Log::error("Proxy failure ($service): HTTP {$response->status()} @ $targetUrl");
            abort($response->status(), "Erreur de proxy vers $service.");

        } catch (\Exception $e) {
            Log::error("Proxy exception ($service): ".$e->getMessage());
            abort(502, "Erreur $service : ".$e->getMessage());
        }
    }
}
