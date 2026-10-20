import axios from "axios";
import { ui } from "../ui";

const api = axios.create({
    baseURL: "/api",
    headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
    },
});

// A server with APP_PASSWORD answers 401 until this browser unlocks the app
api.interceptors.response.use(undefined, (error) => {
    if (error.response?.status === 401 && error.response.data?.locked) {
        ui.locked = true;
    }
    return Promise.reject(error);
});

// Password lock (servers only)
export const lockAPI = {
    status() {
        return api.get("/lock");
    },

    unlock(password) {
        return api.post("/lock/unlock", { password });
    },

    lock() {
        return api.post("/lock");
    },
};

// Downloads
export const downloadAPI = {
    // Fetch video info without creating download
    fetchInfo(url) {
        return api.post("/downloads/fetch-info", { youtube_url: url });
    },

    // Get all downloads
    getAll(params = {}) {
        return api.get("/downloads", { params });
    },

    // Get single download
    get(id) {
        return api.get(`/downloads/${id}`);
    },

    // Create new download
    create(data) {
        return api.post("/downloads", data);
    },

    // Queue several links at once: { urls: [...], format, quality }
    bulk(data) {
        return api.post("/downloads/bulk", data);
    },

    // Loudness outline for the player's seek bar (created on first request if missing)
    waveform(id) {
        return api.get(`/downloads/${id}/waveform`);
    },

    // Lyrics for the player (looked up online the first time)
    lyrics(id, refresh = false) {
        return api.get(`/downloads/${id}/lyrics`, { params: refresh ? { refresh: 1 } : {} });
    },

    // MP3 ringtone from part of a song: { start, length, fade }
    getRingtoneUrl(id, { start, length, fade }) {
        const params = new URLSearchParams({ start: String(start), length: String(length), fade: fade ? "1" : "0" });
        return `/api/downloads/${id}/ringtone?${params}`;
    },

    // Open the file's folder on the computer running the app
    reveal(id) {
        return api.post(`/downloads/${id}/reveal`);
    },

    // Edit tags of a finished download (re-tags and renames the file)
    update(id, data) {
        return api.patch(`/downloads/${id}`, data);
    },

    // Delete download
    delete(id) {
        return api.delete(`/downloads/${id}`);
    },

    // Retry failed download
    retry(id) {
        return api.post(`/downloads/${id}/retry`);
    },

    // Cancel download
    cancel(id) {
        return api.post(`/downloads/${id}/cancel`);
    },

    // Get download URL
    getDownloadUrl(id) {
        return `/api/downloads/${id}/stream`;
    },

    // Delete the original of a converted file on this computer (after asking the user)
    deleteOriginal(id) {
        return api.delete(`/downloads/${id}/original`);
    },

    // Several finished downloads in one ZIP file
    getZipUrl(ids) {
        return `/api/downloads/zip?ids=${ids.join(",")}`;
    },
};

// Convert your own files: uploads from the browser, or files on this computer
export const filesAPI = {
    options() {
        return api.get("/files/options");
    },

    // Folders and video/audio files in a folder on this computer
    browse(path = "") {
        return api.get("/files/browse", { params: path ? { path } : {} });
    },

    inspect(paths) {
        return api.post("/files/inspect", { paths });
    },

    startUpload(name, size) {
        return api.post("/files/uploads", { name, size });
    },

    // One piece of the file as the raw request body
    uploadChunk(id, offset, blob, { onUploadProgress, signal } = {}) {
        return api.post(`/files/uploads/${id}/chunk`, blob, {
            params: { offset },
            headers: { "Content-Type": "application/octet-stream" },
            onUploadProgress,
            signal,
        });
    },

    cancelUpload(id) {
        return api.delete(`/files/uploads/${id}`);
    },

    // { items: [{ upload_id | path, title, artist, ... }], format, quality, remove_silence, normalize_volume }
    convert(data) {
        return api.post("/files/convert", data);
    },
};

// Search YouTube by text
export const searchAPI = {
    search(q) {
        return api.get("/search", { params: { q } });
    },
};

// Queue
export const queueAPI = {
    // Get queue status
    getStatus() {
        return api.get("/queue/status");
    },

    // Get all active jobs
    getJobs() {
        return api.get("/queue/jobs");
    },

    // Get all failed jobs
    getFailedJobs() {
        return api.get("/queue/failed");
    },

    // Cancel specific job
    cancelJob(id) {
        return api.post(`/queue/jobs/${id}/cancel`);
    },

    // Clear all failed jobs
    clearFailed() {
        return api.post("/queue/clear-failed");
    },

    // Retry all failed jobs
    retryAllFailed() {
        return api.post("/queue/retry-all");
    },

    // Retry specific failed job
  retryJob(id) {
    return api.post(`/queue/failed/${id}/retry`);
  }
};

// Playlists
export const playlistAPI = {
  // Fetch info without saving
  fetchInfo(url) {
    return api.post('/playlists/fetch-info', { url });
  },

  // Create/Import playlist
  create(data) {
    return api.post('/playlists', data);
  },

  // List playlists
  getAll(params = {}) {
    return api.get('/playlists', { params });
  },

  // Get specific playlist
  get(id) {
    return api.get(`/playlists/${id}`);
  },

  // Delete playlist
  delete(id) {
    return api.delete(`/playlists/${id}`);
  }
};

// History
export const historyAPI = {
  getAll(params = {}) {
    return api.get('/history', { params });
  },
  
  clear(keepFiles = true) {
    return api.delete('/history', { params: { keep_files: keepFiles } });
  }
};

// Dashboard
export const dashboardAPI = {
  // recentAfter: hide recent downloads finished before this time (Home → Clear)
  getStats(recentAfter = null) {
    return api.get('/dashboard/stats', { params: recentAfter ? { recent_after: recentAfter } : {} });
  }
};

// Settings
export const settingsAPI = {
  get() {
    return api.get('/settings');
  },
  
  update(data) {
    return api.put('/settings', data);
  },
  
  checkDiskSpace() {
    return api.get('/settings/disk-space');
  },
  
  testDependencies() {
    return api.get('/settings/test-dependencies');
  },

  testYoutube() {
    return api.get('/settings/test-youtube');
  },

  ytDlpVersion() {
    return api.get('/settings/yt-dlp');
  },

  updateYtDlp() {
    return api.post('/settings/yt-dlp/update');
  },

  setupChecks(fresh = false) {
    return api.get('/settings/setup-checks', { params: fresh ? { fresh: 1 } : {} });
  },

  // Folders on the machine the app runs on (empty path: around the download folder)
  folders(path = '') {
    return api.get('/settings/folders', { params: path ? { path } : {} });
  },

  createFolder(path, name) {
    return api.post('/settings/folders', { path, name });
  }
};

export default api;
