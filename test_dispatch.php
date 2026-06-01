<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$download = App\Models\Download::create([
    'youtube_url'=>'https://www.youtube.com/watch?v=Ih2YwSilPwc',
    'youtube_id'=>'Ih2YwSilPwc',
    'title'=>'Test',
    'format'=>'mp3',
    'quality'=>'320',
    'status'=>'pending'
]);
App\Jobs\DownloadYoutubeAudioJob::dispatch($download);
echo "Dispatched test job for ID: " . $download->id . "\n";
