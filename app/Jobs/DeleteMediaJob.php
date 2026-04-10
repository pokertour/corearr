<?php

namespace App\Jobs;

use App\Services\MediaStack\JellyseerrService;
use App\Services\MediaStack\MediaStackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

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
        public string $title
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        MediaStackService $arrService,
        JellyseerrService $jellyseerr
    ): void {
        Cache::put("deleting_media_{$this->requestId}", true, now()->addMinutes(10));

        try {
            // 1. Delete from Radarr/Sonarr
            $arrDeleted = $arrService->deleteMedia($this->service, $this->externalId, true);

            if ($arrDeleted) {
                // 2. Delete Request from Jellyseerr
                $jellyseerr->deleteRequest($this->requestId);
            }
        } finally {
            Cache::forget("deleting_media_{$this->requestId}");
        }
    }
}
