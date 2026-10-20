<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Support\FileExplorer;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    /**
     * Get download history with filtering and sorting
     */
    public function index(Request $request)
    {
        $query = Download::completed();

        // Search by title or artist
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('artist', 'like', "%{$search}%");
            });
        }

        // Filter by format
        if ($request->has('format') && $request->format) {
            $query->where('format', $request->format);
        }

        // Filter by where it came from: YouTube, or converted files
        if ($request->input('source') === 'youtube') {
            $query->where('source', Download::SOURCE_YOUTUBE);
        } elseif ($request->input('source') === 'files') {
            $query->whereIn('source', [Download::SOURCE_UPLOAD, Download::SOURCE_LOCAL]);
        }

        // Sort
        $sortField = $request->input('sort_by', 'completed_at');
        $sortDir = $request->input('sort_dir', 'desc');
        
        $allowedSorts = ['completed_at', 'title', 'file_size', 'duration'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('completed_at', 'desc');
        }

        $downloads = $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        // The folder button only shows when the browser runs on this computer
        return response()->json($downloads->toArray() + [
            'can_open_folder' => FileExplorer::available($request),
        ]);
    }

    /**
     * Clear all history (keeping files)
     */
    public function clear(Request $request)
    {
        // Only delete records, keep files? Or maybe a flag?
        // User request says "Clear history (keep files option)"
        
        // boolean() parses query-string values such as "false" / "0"
        $keepFiles = $request->boolean('keep_files', true);
        
        // If not keeping files, we need to iterate and delete
        if (!$keepFiles) {
            Download::completed()->chunk(100, function ($downloads) {
                foreach ($downloads as $download) {
                    if ($download->file_path && file_exists($download->file_path)) {
                        @unlink($download->file_path);
                    }
                }
            });
        }
        
        Download::completed()->delete();
        
        return response()->json(['message' => 'History cleared']);
    }
}
