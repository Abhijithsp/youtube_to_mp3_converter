<?php

namespace App\Http\Controllers;

use App\Jobs\ConvertLocalFileJob;
use App\Models\Download;
use App\Services\LocalFileService;
use App\Support\LocalFileAccess;
use App\Support\UploadStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Convert your own video and audio files: uploaded from the browser, or picked
 * from a folder on the computer running the app.
 */
class FileConvertController extends Controller
{
    /**
     * Most files queued in one go
     */
    private const MAX_FILES = 50;

    public function __construct(
        private LocalFileService $localFiles,
        private UploadStore $uploads
    ) {}

    /**
     * What this browser may do, for the Files tab
     */
    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'local_files' => LocalFileAccess::allowed($request),
            'max_upload_mb' => $this->maxUploadMb(),
            'chunk_bytes' => UploadStore::CHUNK_BYTES,
            'max_files' => self::MAX_FILES,
            'extensions' => LocalFileService::EXTENSIONS,
        ]);
    }

    /**
     * Start an upload: { name, size }
     */
    public function startUpload(Request $request): JsonResponse
    {
        $maxBytes = $this->maxUploadMb() * 1024 * 1024;
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'size' => "required|integer|min:1|max:{$maxBytes}",
        ], [
            'size.max' => 'Files can be at most ' . $this->maxUploadMb() . ' MB.',
        ]);

        return response()->json([
            'id' => $this->uploads->create($data['name'], (int) $data['size']),
            'chunk_bytes' => UploadStore::CHUNK_BYTES,
        ], 201);
    }

    /**
     * One piece of an upload, as the raw request body; ?offset= is where it starts.
     * After the last piece the file is checked and described.
     */
    public function uploadChunk(Request $request, string $upload): JsonResponse
    {
        if (!$this->uploads->exists($upload)) {
            return response()->json(['message' => 'This upload has expired. Add the file again.'], 404);
        }

        try {
            $received = $this->uploads->append($upload, max(0, (int) $request->query('offset', 0)), $request->getContent(true));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'received' => filesize($this->uploads->path($upload))], 409);
        }

        $meta = $this->uploads->meta($upload);

        if ($received < $meta['size']) {
            return response()->json(['received' => $received, 'done' => false]);
        }

        $file = $this->localFiles->inspect($this->uploads->path($upload), $meta['name']);

        if ($file['error']) {
            $this->uploads->delete($upload);

            return response()->json(['message' => $file['error'], 'file' => $file], 422);
        }

        return response()->json(['received' => $received, 'done' => true, 'file' => $file]);
    }

    public function cancelUpload(string $upload): JsonResponse
    {
        // Uploads already queued for conversion are removed with their download
        if ($this->uploads->exists($upload) && !$this->uploads->meta($upload)['used']) {
            $this->uploads->delete($upload);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Describe files on this computer: { paths: [...] }
     */
    public function inspect(Request $request): JsonResponse
    {
        $this->ensureLocalFiles($request);

        $data = $request->validate([
            'paths' => 'required|array|min:1|max:' . self::MAX_FILES,
            'paths.*' => 'required|string|max:2000',
        ]);

        $files = array_map(
            fn (string $path) => ['path' => $path] + $this->localFiles->inspect($path),
            $data['paths']
        );

        return response()->json(['files' => $files]);
    }

    /**
     * Queue conversions. Each item is an upload (upload_id) or a file on this computer
     * (path), with optional tags and trim; format, bitrate and audio edits apply to all.
     */
    public function convert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:' . self::MAX_FILES,
            'items.*.upload_id' => 'nullable|required_without:items.*.path|string',
            'items.*.path' => 'nullable|required_without:items.*.upload_id|string|max:2000',
            'items.*.title' => 'nullable|string|max:255',
            'items.*.artist' => 'nullable|string|max:255',
            'items.*.album' => 'nullable|string|max:255',
            'items.*.year' => 'nullable|integer|min:1900|max:2100',
            'items.*.genre' => 'nullable|string|max:100',
            'items.*.trim_start' => 'nullable|integer|min:0',
            'items.*.trim_end' => 'nullable|integer|min:1',
            'format' => ['required', Rule::in(Download::FORMATS)],
            'quality' => 'required|in:192,256,320',
            'remove_silence' => 'nullable|boolean',
            'normalize_volume' => 'nullable|boolean',
        ], [
            'items.max' => 'Convert at most ' . self::MAX_FILES . ' files at a time.',
        ]);

        // Check every item first, so nothing is queued when one of them is wrong
        $sources = [];
        foreach ($data['items'] as $index => $item) {
            $sources[$index] = $this->sourceFor($request, $item, $index);

            if (in_array($sources[$index][1], array_column(array_slice($sources, 0, -1), 1), true)) {
                throw ValidationException::withMessages(["items.{$index}" => $sources[$index][2] . ' is listed twice.']);
            }

            if (!empty($item['trim_end']) && (int) $item['trim_end'] <= (int) ($item['trim_start'] ?? 0)) {
                throw ValidationException::withMessages(["items.{$index}.trim_end" => 'The end time must be after the start time.']);
            }
        }

        $queued = [];
        foreach ($data['items'] as $index => $item) {
            [$source, $path, $name] = $sources[$index];
            $suggested = $this->localFiles->suggestTags($name, []);

            $download = Download::create([
                'source' => $source,
                'source_path' => $path,
                'source_name' => $name,
                'title' => trim((string) ($item['title'] ?? '')) ?: $suggested['title'],
                'artist' => trim((string) ($item['artist'] ?? '')) ?: null,
                'album' => trim((string) ($item['album'] ?? '')) ?: null,
                'year' => $item['year'] ?? null,
                'genre' => trim((string) ($item['genre'] ?? '')) ?: null,
                'format' => $data['format'],
                'quality' => (string) $data['quality'],
                'trim_start' => !empty($item['trim_start']) ? (int) $item['trim_start'] : null,
                'trim_end' => !empty($item['trim_end']) ? (int) $item['trim_end'] : null,
                'remove_silence' => $request->boolean('remove_silence'),
                'normalize_volume' => $request->boolean('normalize_volume'),
                'status' => 'pending',
                'status_text' => 'Waiting to convert...',
            ]);

            if ($source === Download::SOURCE_UPLOAD) {
                $this->uploads->markUsed($item['upload_id']);
            }

            ConvertLocalFileJob::dispatch($download);
            $queued[] = $download;
        }

        return response()->json([
            'success' => true,
            'message' => 'Converting ' . count($queued) . ' file' . (count($queued) === 1 ? '' : 's') . '.',
            'downloads' => $queued,
        ], 201);
    }

    /**
     * [source, path on disk, file name] for one item, or a validation error
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function sourceFor(Request $request, array $item, int $index): array
    {
        if (!empty($item['upload_id'])) {
            $id = $item['upload_id'];

            if (!$this->uploads->isComplete($id) || $this->uploads->meta($id)['used']) {
                throw ValidationException::withMessages(["items.{$index}.upload_id" => 'This upload is missing or unfinished. Add the file again.']);
            }

            return [Download::SOURCE_UPLOAD, $this->uploads->path($id), $this->uploads->meta($id)['name']];
        }

        $this->ensureLocalFiles($request);
        $path = realpath($item['path']);

        if (!$path || !is_file($path) || !is_readable($path)) {
            throw ValidationException::withMessages(["items.{$index}.path" => basename($item['path']) . ' is no longer there.']);
        }

        return [Download::SOURCE_LOCAL, $path, basename($path)];
    }

    private function ensureLocalFiles(Request $request): void
    {
        abort_unless(LocalFileAccess::allowed($request), 403, LocalFileAccess::DENIED);
    }

    private function maxUploadMb(): int
    {
        return max(1, (int) config('youtube-converter.max_upload_mb', 4096));
    }
}
