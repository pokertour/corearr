<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\MediaStack\JellyseerrService;
use Illuminate\Contracts\Console\Kernel;

$jellyseerr = app(JellyseerrService::class);
$reqs = $jellyseerr->getRequests(5, 0, 'available');
echo json_encode($reqs, JSON_PRETTY_PRINT);
