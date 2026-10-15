<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Folder picker for the download folder: lists folders on the machine the app
 * runs on (your PC, or the server when hosted).
 */
class FolderController extends Controller
{
    private const MAX_FOLDERS = 500;

    public function index(Request $request): JsonResponse
    {
        $requested = trim((string) $request->query('path', ''));
        $path = Setting::absolutePath($requested !== '' ? $requested : Setting::downloadPath());

        // A folder that doesn't exist yet (e.g. the default before the first download): show its nearest parent
        while (!is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        if (!is_dir($path)) {
            $path = $this->places()[0]['path'];
        }

        $path = $this->normalise(realpath($path) ?: $path);
        $parent = dirname($path);

        return response()->json([
            'path' => $path,
            'parent' => $parent !== $path ? $this->normalise($parent) : null,
            'writable' => is_writable($path),
            'folders' => $this->folders($path),
            'places' => $this->places(),
            'separator' => DIRECTORY_SEPARATOR,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string',
            'name' => ['required', 'string', 'max:120', 'not_regex:/[<>:"\/\\\\|?*\x00-\x1F]/', 'not_in:.,..'],
        ], [
            'name.not_regex' => 'A folder name cannot contain < > : " / \\ | ? or *.',
            'name.not_in' => 'Choose another folder name.',
        ]);

        $parent = Setting::absolutePath($data['path']);
        $name = rtrim(trim($data['name']), '. ');

        if (!is_dir($parent) || $name === '') {
            throw ValidationException::withMessages(['name' => 'The folder could not be created here.']);
        }

        $folder = $this->join($parent, $name);

        if (!is_dir($folder) && !@mkdir($folder, 0755, true)) {
            throw ValidationException::withMessages(['name' => 'The folder could not be created here (no permission?).']);
        }

        return response()->json(['path' => $this->normalise(realpath($folder) ?: $folder)], 201);
    }

    /**
     * Sub-folders of $path, sorted by name; hidden and system folders are left out
     */
    private function folders(string $path): array
    {
        $names = @scandir($path) ?: [];
        $folders = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.') || str_starts_with($name, '$')) {
                continue;
            }

            $full = $this->join($path, $name);
            if (@is_dir($full)) {
                $folders[] = ['name' => $name, 'path' => $full];
            }

            if (count($folders) >= self::MAX_FOLDERS) {
                break;
            }
        }

        usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $folders;
    }

    /**
     * Shortcuts: the current and default download folders, home, Music and the drives / root
     */
    private function places(): array
    {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: null;

        $candidates = [
            ['label' => 'Download folder', 'path' => Setting::downloadPath()],
            ['label' => 'App storage', 'path' => Setting::absolutePath(config('youtube-converter.download_path'))],
            ['label' => 'Home', 'path' => $home],
            ['label' => 'Music', 'path' => $home ? $this->join($home, 'Music') : null],
            ['label' => 'Docker volume', 'path' => '/downloads'],
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (range('A', 'Z') as $letter) {
                $candidates[] = ['label' => "{$letter}:", 'path' => "{$letter}:\\", 'drive' => true];
            }
        } else {
            $candidates[] = ['label' => 'Root', 'path' => '/', 'drive' => true];
        }

        $places = [];
        $seen = [];
        foreach ($candidates as $place) {
            if (!$place['path'] || !@is_dir($place['path'])) {
                continue;
            }

            $path = $this->normalise(realpath($place['path']) ?: $place['path']);
            if (isset($seen[strtolower($path)])) {
                continue;
            }

            $seen[strtolower($path)] = true;
            $places[] = ['label' => $place['label'], 'path' => $path, 'drive' => $place['drive'] ?? false];
        }

        return $places;
    }

    private function join(string $parent, string $name): string
    {
        return rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Native separators, no trailing one (except for a drive or the root)
     */
    private function normalise(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);

        if ($trimmed === '') {
            return DIRECTORY_SEPARATOR;
        }

        return preg_match('/^[A-Za-z]:$/', $trimmed) ? $trimmed . DIRECTORY_SEPARATOR : $trimmed;
    }
}
