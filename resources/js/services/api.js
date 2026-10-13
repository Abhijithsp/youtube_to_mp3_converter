import axios from "axios";

const api = axios.create({
    baseURL: "/api",
    headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
    },
});

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
  getStats() {
    return api.get('/dashboard/stats');
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
  }
};

export default api;
