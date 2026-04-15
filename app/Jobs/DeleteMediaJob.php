<?php

namespace App\Jobs;

use App\Services\MediaStack\JellyseerrService;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeleteMediaJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $requestId,
        public string $service,
        public int $externalId,
        public string $title,
        public ?int $tmdbId = null,
        public ?int $jellyseerrMediaId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        MediaStackService $arrService,
        JellyseerrService $jellyseerr
    ): void {
        Log::info("DeleteMediaJob Started: {$this->title} (JS Req: {$this->requestId}, JS Media: {$this->jellyseerrMediaId}, Arr ID: {$this->externalId}, TMDB: {$this->tmdbId})");
        Cache::put("deleting_media_{$this->requestId}", true, now()->addMinutes(10));

        try {
            $effectiveIds = [];
            if ($this->externalId > 0) {
                $effectiveIds[] = $this->externalId;
            }

            // Always try to find all IDs by TMDB to handle duplicates
            if ($this->tmdbId > 0) {
                Log::info("DeleteMediaJob: Searching for all IDs by TMDB {$this->tmdbId}");
                $foundIds = $arrService->findMediaIdsByTmdbId($this->service, $this->tmdbId);
                if (! empty($foundIds)) {
                    Log::info('DeleteMediaJob: Found IDs in '.$this->service.': '.implode(', ', $foundIds));
                    $effectiveIds = array_unique(array_merge($effectiveIds, $foundIds));
                }
            }

            // 1. Delete from Radarr/Sonarr
            $arrDeleted = true; // Assume true, set to false only if a delete fails

            if (! empty($effectiveIds)) {
                foreach ($effectiveIds as $id) {
                    Log::info("DeleteMediaJob: Deleting from {$this->service} (ID: {$id})");
                    $success = $arrService->deleteMedia($this->service, $id, true);
                    Log::info('DeleteMediaJob: '.$this->service." ID {$id} deletion result: ".($success ? 'SUCCESS' : 'FAILED'));
                    if (! $success) {
                        $arrDeleted = false;
                    }
                }

                if ($arrDeleted) {
                    // Quick verification: see if it's still there
                    $stillThere = $arrService->findMediaByTmdbId($this->service, (int) $this->tmdbId);
                    if ($stillThere) {
                        Log::warning("DeleteMediaJob: Post-delete verification FAILED! Media ID {$stillThere} still found in {$this->service} for {$this->title}");
                        $arrDeleted = false;
                    } else {
                        Log::info("DeleteMediaJob: Post-delete verification PASSED! All matching items gone from {$this->service}");
                    }
                }
            } else {
                Log::info("DeleteMediaJob: No Arr IDs found for {$this->title}. Proceeding with Jellyseerr cleanup.");
            }

            if ($arrDeleted) {
                // 2. Delete from Jellyseerr
                if ($this->jellyseerrMediaId) {
                    Log::info("DeleteMediaJob: Deleting Jellyseerr Media object {$this->jellyseerrMediaId}");
                    $jsDeleted = $jellyseerr->deleteMedia($this->jellyseerrMediaId);
                    Log::info('DeleteMediaJob: Jellyseerr Media deletion result: '.($jsDeleted ? 'SUCCESS' : 'FAILED'));
                } else {
                    Log::info("DeleteMediaJob: No Media ID, deleting Jellyseerr request {$this->requestId}");
                    $jsDeleted = $jellyseerr->deleteRequest($this->requestId);
                    Log::info('DeleteMediaJob: Jellyseerr Request deletion result: '.($jsDeleted ? 'SUCCESS' : 'FAILED'));
                }
            } else {
                Log::error("DeleteMediaJob: Aborting Jellyseerr cleanup for {$this->title} because Arr deletion failed or verification failed.");
            }
        } catch (\Exception $e) {
            Log::error('DeleteMediaJob Critical Failure: '.$e->getMessage(), [
                'exception' => $e,
                'title' => $this->title,
                'js_id' => $this->requestId,
            ]);
            throw $e;
        } finally {
            Cache::forget("deleting_media_{$this->requestId}");
        }
    }
}
