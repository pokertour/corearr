<?php

use App\Http\Controllers\MediaProxyController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');
    Route::livewire('/torrents', 'torrents')->name('torrents');
    Route::livewire('/media', 'media')->name('media');
    Route::livewire('/settings', 'settings')->name('settings');
    Route::livewire('/profile', 'profile')->name('profile');
    Route::livewire('/prowlarr', 'prowlarr')->name('prowlarr');
    Route::livewire('/cleanup', 'cleanup')->name('cleanup');
    Route::livewire('/about', 'about')->name('about');
});

// Image Proxy for Arr Services
Route::get('/media-proxy/{service}/{path}', MediaProxyController::class)
    ->where('path', '.*')
    ->name('media.proxy');
