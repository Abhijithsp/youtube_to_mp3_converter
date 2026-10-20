<?php

namespace App\Http\Controllers;

use App\Models\Download;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\DiskSpaceService;

class DashboardController extends Controller
{
    public function __construct(
        protected DiskSpaceService $diskSpaceService
    ) {}

    public function stats(Request $request)
    {
        // "Clear" on the Home page only hides what was there: newer downloads still show.
        // The browser remembers the moment; nothing is deleted.
        $request->validate(['recent_after' => 'nullable|date']);

        $totalDownloads = Download::completed()->count();
        $totalStorageBytes = Download::completed()->sum('file_size'); // Assuming file_size is in bytes
        
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        
        // Convert storage to human readable
        $totalStorage = $this->formatBytes($totalStorageBytes);
        
        // Get recent downloads
        $recentDownloads = Download::completed()
            ->when($request->filled('recent_after'), fn ($query) => $query->where('completed_at', '>', Carbon::parse($request->recent_after)))
            ->orderBy('completed_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'total_downloads' => $totalDownloads,
            'total_storage' => $totalStorage,
            'queue_pending' => $pendingJobs,
            'jobs_failed' => $failedJobs,
            'downloads_failed' => Download::failed()->count(),
            'disk_space_free' => $this->formatBytes($this->diskSpaceService->checkAvailableSpace() * 1024 * 1024),
            'recent_downloads' => $recentDownloads
        ]);
    }
    
    private function formatBytes($bytes, $precision = 2) { 
        if ($bytes < 0) return '0 B';
        
        $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
        
        $bytes = max($bytes, 0); 
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
        $pow = min($pow, count($units) - 1); 
        
        $bytes /= pow(1024, $pow); 
        
        return round($bytes, $precision) . ' ' . $units[$pow]; 
    }
}
