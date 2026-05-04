<?php

test('docker supervisord config runs laravel schedule worker', function (): void {
    $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'docker'.DIRECTORY_SEPARATOR.'supervisord.conf';

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->not->toBeFalse()
        ->and($contents)->toContain('[program:schedule-worker]')
        ->and($contents)->toContain('artisan schedule:work');
});
