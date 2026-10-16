<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * Shows a downloaded file in the computer's file manager (Explorer, Finder, ...).
 *
 * The window opens on the machine running the app, so this is only offered when
 * the browser is on that same machine: never to phones on the network or to
 * visitors of an app hosted on a server.
 */
class FileExplorer
{
    public static function available(Request $request): bool
    {
        if (!in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return false;
        }

        // Linux servers without a desktop have nothing to open a window on
        return PHP_OS_FAMILY !== 'Linux' || getenv('DISPLAY') || getenv('WAYLAND_DISPLAY');
    }

    /**
     * Open the folder holding $file, with the file selected where the system allows it
     */
    public function reveal(string $file): void
    {
        $file = realpath($file) ?: $file;

        if (PHP_OS_FAMILY === 'Windows') {
            $this->runWindows(self::windowsCommandLine($file));

            return;
        }

        Process::timeout(15)->run(PHP_OS_FAMILY === 'Darwin' ? ['open', '-R', $file] : ['xdg-open', dirname($file)]);
    }

    /**
     * explorer.exe only understands /select,"C:\path\file.mp3" written exactly like this.
     * Quoting the whole argument (what Process does for paths with spaces) makes it
     * ignore the path and open Documents instead. Windows file names cannot contain
     * quotes, so the path can't break out of them.
     */
    public static function windowsCommandLine(string $file): string
    {
        return 'explorer.exe /select,"' . str_replace('/', '\\', $file) . '"';
    }

    /**
     * Passes the command line to Windows as written (no cmd.exe, so "&" in names is harmless)
     */
    protected function runWindows(string $commandLine): void
    {
        $process = proc_open($commandLine, [], $pipes, null, null, ['bypass_shell' => true]);

        // Explorer hands the window to the running desktop and exits straight away
        if (is_resource($process)) {
            proc_close($process);
        }
    }
}
