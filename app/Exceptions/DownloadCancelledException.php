<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown inside a download job when the user cancelled (or deleted) the download,
 * to abort the running yt-dlp / ffmpeg process.
 */
class DownloadCancelledException extends Exception
{
    //
}
