<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Process;

$process = Process::timeout(600)->run('yt-dlp --newline -x --audio-format mp3 -o test.mp3 https://www.youtube.com/shorts/iKXh0Fgzau0', function (string $type, string $output) {
    echo "RECEIVED: " . $output;
});
