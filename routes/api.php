<?php

use App\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
|
*/

// Download routes
Route::prefix('downloads')->group(function () {
    Route::post('/fetch-info', [DownloadController::class, 'fetchInfo']);
    Route::get('/', [DownloadController::class, 'index']);
    Route::post('/', [DownloadController::class, 'store']);
    Route::get('/{download}', [DownloadController::class, 'show']);
    Route::delete('/{download}', [DownloadController::class, 'destroy']);
    Route::post('/{download}/retry', [DownloadController::class, 'retry']);
    Route::post('/{download}/cancel', [DownloadController::class, 'cancel']);
    Route::get('/{download}/stream', [DownloadController::class, 'stream'])->name('downloads.stream');
});

// Search YouTube by text
Route::get('/search', [DownloadController::class, 'search']);

// Queue routes
Route::prefix('queue')->group(function () {
    Route::get('/status', [App\Http\Controllers\QueueController::class, 'status']);
    Route::get('/jobs', [App\Http\Controllers\QueueController::class, 'jobs']);
    Route::get('/failed', [App\Http\Controllers\QueueController::class, 'failedJobs']);
    Route::post('/retry-all', [App\Http\Controllers\QueueController::class, 'retryAllFailed']);
    Route::post('/clear-failed', [App\Http\Controllers\QueueController::class, 'clearFailed']);
    Route::post('/jobs/{id}/cancel', [App\Http\Controllers\QueueController::class, 'cancelJob']);
    Route::post('/failed/{id}/retry', [App\Http\Controllers\QueueController::class, 'retryJob']);
});

// Playlist routes
Route::prefix('playlists')->group(function () {
    Route::post('/fetch-info', [App\Http\Controllers\PlaylistController::class, 'fetchInfo']);
    Route::get('/', [App\Http\Controllers\PlaylistController::class, 'index']);
    Route::post('/', [App\Http\Controllers\PlaylistController::class, 'store']);
    Route::get('/{playlist}', [App\Http\Controllers\PlaylistController::class, 'show']);
    Route::delete('/{playlist}', [App\Http\Controllers\PlaylistController::class, 'destroy']);
});

// History routes
Route::prefix('history')->group(function () {
    Route::get('/', [App\Http\Controllers\HistoryController::class, 'index']);
    Route::delete('/', [App\Http\Controllers\HistoryController::class, 'clear']);
});

// Dashboard routes
Route::get('/dashboard/stats', [App\Http\Controllers\DashboardController::class, 'stats']);

// Settings routes
Route::prefix('settings')->group(function () {
    Route::get('/', [App\Http\Controllers\SettingsController::class, 'index']);
    Route::put('/', [App\Http\Controllers\SettingsController::class, 'update']);
    Route::get('/disk-space', [App\Http\Controllers\SettingsController::class, 'checkDiskSpace']);
    Route::get('/test-dependencies', [App\Http\Controllers\SettingsController::class, 'testDependencies']);
    Route::get('/test-youtube', [App\Http\Controllers\SettingsController::class, 'testYoutube']);
    Route::get('/yt-dlp', [App\Http\Controllers\SettingsController::class, 'ytDlpVersion']);
    Route::post('/yt-dlp/update', [App\Http\Controllers\SettingsController::class, 'updateYtDlp']);
    Route::get('/setup-checks', [App\Http\Controllers\SettingsController::class, 'setupChecks']);
});

