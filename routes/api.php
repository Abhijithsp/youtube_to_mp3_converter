<?php

use App\Http\Controllers\DownloadController;
use App\Http\Controllers\FileConvertController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\LockController;
use App\Http\Middleware\RequireAppPassword;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
|
*/

// Password lock (only active when APP_PASSWORD is set)
Route::prefix('lock')->group(function () {
    Route::get('/', [LockController::class, 'status']);
    Route::post('/unlock', [LockController::class, 'unlock'])->middleware('throttle:10,1');
    Route::post('/', [LockController::class, 'lock']);
});

Route::middleware(RequireAppPassword::class)->group(function () {
    // Download routes
    Route::prefix('downloads')->group(function () {
        Route::post('/fetch-info', [DownloadController::class, 'fetchInfo']);
        Route::get('/', [DownloadController::class, 'index']);
        Route::post('/', [DownloadController::class, 'store']);
        Route::post('/bulk', [DownloadController::class, 'bulk']);
        Route::get('/zip', [DownloadController::class, 'zip']);
        Route::get('/{download}', [DownloadController::class, 'show']);
        Route::patch('/{download}', [DownloadController::class, 'update']);
        Route::delete('/{download}', [DownloadController::class, 'destroy']);
        Route::post('/{download}/retry', [DownloadController::class, 'retry']);
        Route::post('/{download}/cancel', [DownloadController::class, 'cancel']);
        Route::get('/{download}/waveform', [DownloadController::class, 'waveform']);
        Route::get('/{download}/lyrics', [DownloadController::class, 'lyrics']);
        Route::get('/{download}/ringtone', [DownloadController::class, 'ringtone']);
        Route::post('/{download}/reveal', [DownloadController::class, 'reveal']);
        Route::get('/{download}/cover', [DownloadController::class, 'cover']);
        Route::delete('/{download}/original', [DownloadController::class, 'destroyOriginal']);
        Route::get('/{download}/stream', [DownloadController::class, 'stream'])->name('downloads.stream');
    });

    // Convert your own files: uploaded from the browser, or from a folder on this computer
    Route::prefix('files')->group(function () {
        Route::get('/options', [FileConvertController::class, 'options']);
        Route::get('/browse', [FolderController::class, 'files']);
        Route::post('/inspect', [FileConvertController::class, 'inspect']);
        Route::post('/uploads', [FileConvertController::class, 'startUpload']);
        Route::post('/uploads/{upload}/chunk', [FileConvertController::class, 'uploadChunk']);
        Route::delete('/uploads/{upload}', [FileConvertController::class, 'cancelUpload']);
        Route::post('/convert', [FileConvertController::class, 'convert']);
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
        Route::post('/move-songs', [App\Http\Controllers\SettingsController::class, 'moveSongs']);
        Route::get('/disk-space', [App\Http\Controllers\SettingsController::class, 'checkDiskSpace']);
        Route::get('/test-dependencies', [App\Http\Controllers\SettingsController::class, 'testDependencies']);
        Route::get('/test-youtube', [App\Http\Controllers\SettingsController::class, 'testYoutube']);
        Route::get('/yt-dlp', [App\Http\Controllers\SettingsController::class, 'ytDlpVersion']);
        Route::post('/yt-dlp/update', [App\Http\Controllers\SettingsController::class, 'updateYtDlp']);
        Route::get('/setup-checks', [App\Http\Controllers\SettingsController::class, 'setupChecks']);
        Route::get('/folders', [FolderController::class, 'index']);
        Route::post('/folders', [FolderController::class, 'store']);
    });
});
