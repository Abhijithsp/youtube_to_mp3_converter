<template>
  <div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
      <h2 class="text-4xl font-bold text-white mb-2">Download YouTube Audio</h2>
      <p class="text-gray-400">Convert YouTube videos to high-quality audio files</p>
    </div>

    <!-- Alert Messages -->
    <ErrorAlert 
      :message="alertMessage" 
      :type="alertType"
      @close="alertMessage = ''"
    />

    <!-- Download Form -->
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-8 shadow-2xl transition-all duration-300">
      <form @submit.prevent="submitDownload">
        <!-- YouTube URL Input -->
        <div class="mb-6 relative">
          <label class="block text-sm font-medium text-gray-300 mb-2">
            YouTube URL
          </label>
          <div class="relative">
            <input
              v-model="form.youtube_url"
              @input="onUrlInput"
              type="url"
              placeholder="https://www.youtube.com/watch?v=..."
              class="w-full px-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
              :class="{'border-red-500 focus:ring-red-500': urlError}"
              :disabled="isProcessing || isFetchingInfo"
              required
            />
            <div v-if="isFetchingInfo" class="absolute right-3 top-3">
              <svg class="animate-spin h-6 w-6 text-purple-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </div>
          </div>
          <p v-if="urlError" class="text-red-400 text-sm mt-1">{{ urlError }}</p>
        </div>

        <!-- Video Preview -->
        <div v-if="videoInfo" class="mb-6 animate-slide-down">
          <div class="flex items-start space-x-4 p-4 bg-gray-900/30 rounded-xl border border-gray-700">
            <img 
              v-if="videoInfo.thumbnail" 
              :src="videoInfo.thumbnail" 
              alt="Thumbnail"
              class="w-32 h-24 rounded-lg object-cover shadow-lg"
            />
            <div class="flex-1 min-w-0">
              <h4 class="text-lg font-bold text-white truncate">{{ videoInfo.title }}</h4>
              <p class="text-sm text-gray-400">{{ videoInfo.artist }}</p>
              <div class="mt-2 flex items-center text-xs text-gray-500">
                <span class="mr-3 flex items-center">
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  {{ formatDuration(videoInfo.duration) }}
                </span>
                <span v-if="videoInfo.album" class="flex items-center">
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                  </svg>
                  {{ videoInfo.album }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <div v-show="videoInfo || (!videoInfo && form.youtube_url)">
          <!-- Format Selector -->
          <div class="mb-6">
            <FormatSelector v-model="form.format" />
          </div>

          <!-- Quality Selector -->
          <div class="mb-6">
            <QualitySelector v-model="form.quality" />
          </div>

          <!-- Optional Metadata -->
          <div class="mb-6">
            <button
              type="button"
              @click="showMetadata = !showMetadata"
              class="flex items-center space-x-2 text-gray-400 hover:text-purple-400 transition-colors"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="showMetadata ? 'M19 9l-7 7-7-7' : 'M9 5l7 7-7 7'"></path>
              </svg>
              <span class="text-sm font-medium">Add Custom Metadata (Optional)</span>
            </button>

            <div v-if="showMetadata" class="mt-4 space-y-4 animate-slide-down">
              <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Artist</label>
                <input
                  v-model="form.artist"
                  type="text"
                  placeholder="Artist name"
                  class="w-full px-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                  :disabled="isProcessing"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Album</label>
                <input
                  v-model="form.album"
                  type="text"
                  placeholder="Album name"
                  class="w-full px-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                  :disabled="isProcessing"
                />
              </div>
            </div>
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            :disabled="isProcessing || isFetchingInfo || !videoInfo"
            class="w-full py-4 px-6 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 disabled:from-gray-600 disabled:to-gray-700 disabled:cursor-not-allowed text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 transform hover:scale-[1.02] disabled:hover:scale-100"
          >
            <span v-if="!isProcessing" class="flex items-center justify-center space-x-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
              </svg>
              <span>Start Download</span>
            </span>
            <span v-else class="flex items-center justify-center space-x-2">
              <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span>Processing...</span>
            </span>
          </button>
        </div>
      </form>
    </div>

    <!-- Download Progress -->
    <div v-if="currentDownload" class="mt-8 bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-8 shadow-2xl animate-slide-down">
      <h3 class="text-xl font-semibold text-white mb-4">Download Status</h3>
      
      <div class="space-y-4">
        <!-- Video Info -->
        <div class="flex items-start space-x-4">
          <img 
            v-if="currentDownload.thumbnail_url" 
            :src="currentDownload.thumbnail_url" 
            alt="Thumbnail"
            class="w-24 h-24 rounded-lg object-cover"
          />
          <div class="flex-1">
            <h4 class="text-lg font-medium text-white">{{ currentDownload.title }}</h4>
            <p v-if="currentDownload.artist" class="text-sm text-gray-400">{{ currentDownload.artist }}</p>
            <div class="mt-2 flex items-center space-x-2">
              <span class="px-2 py-1 text-xs font-medium rounded-full uppercase"
                :class="statusClasses[currentDownload.status]">
                {{ currentDownload.status }}
              </span>
              <span class="text-xs text-gray-400">{{ currentDownload.format.toUpperCase() }} • {{ currentDownload.quality }} kbps</span>
            </div>
          </div>
        </div>

        <!-- Progress Bar -->
        <ProgressBar 
          v-if="['pending', 'processing'].includes(currentDownload.status)"
          :progress="currentDownload.progress"
          :label="currentDownload.status_text || 'Preparing download...'"
        />

        <!-- Error Message -->
        <div v-if="currentDownload.status === 'failed'" class="p-4 bg-red-900/30 border border-red-500/50 rounded-lg">
          <p class="text-sm text-red-200">{{ currentDownload.error_message }}</p>
        </div>

        <!-- File Sizes -->
        <div v-if="currentDownload.original_file_size || currentDownload.file_size" class="flex space-x-4 text-sm mt-2 mb-4">
          <div v-if="currentDownload.original_file_size" class="px-4 py-2 bg-gray-900/50 rounded-lg border border-gray-700 text-gray-300">
            <span class="font-semibold text-purple-400">Original Size:</span> {{ formatBytes(currentDownload.original_file_size) }}
          </div>
          <div v-if="currentDownload.file_size" class="px-4 py-2 bg-gray-900/50 rounded-lg border border-gray-700 text-gray-300">
            <span class="font-semibold text-pink-400">Converted Size:</span> {{ formatBytes(currentDownload.file_size) }}
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex space-x-3">
          <!-- Download Button -->
          <a
            v-if="currentDownload.status === 'completed'"
            :href="downloadUrl"
            class="flex-1 py-3 px-6 bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 transform hover:scale-[1.02] text-center"
          >
            <span class="flex items-center justify-center space-x-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
              </svg>
              <span>Download File</span>
            </span>
          </a>

          <!-- Retry Button -->
          <button
            v-if="currentDownload.status === 'failed'"
            @click="retryDownload"
            class="flex-1 py-3 px-6 bg-gradient-to-r from-yellow-600 to-orange-600 hover:from-yellow-700 hover:to-orange-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 transform hover:scale-[1.02]"
          >
            <span class="flex items-center justify-center space-x-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
              </svg>
              <span>Retry Download</span>
            </span>
          </button>

          <!-- Cancel Button -->
          <button
            v-if="['pending', 'processing'].includes(currentDownload.status)"
            @click="cancelDownload"
            class="px-6 py-3 bg-gray-700 hover:bg-gray-600 text-white font-semibold rounded-lg transition-all"
          >
            Cancel
          </button>

          <!-- New Download Button -->
          <button
            v-if="['completed', 'failed', 'cancelled'].includes(currentDownload.status)"
            @click="resetForm"
            class="px-6 py-3 bg-gray-700 hover:bg-gray-600 text-white font-semibold rounded-lg transition-all"
          >
            New Download
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { downloadAPI } from '../services/api';
import ProgressBar from '../components/ProgressBar.vue';
import ErrorAlert from '../components/ErrorAlert.vue';
import FormatSelector from '../components/FormatSelector.vue';
import QualitySelector from '../components/QualitySelector.vue';

// Simple debounce function
const debounce = (fn, delay) => {
  let timeout;
  return (...args) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => fn(...args), delay);
  };
};

export default {
  name: 'SingleDownload',
  components: {
    ProgressBar,
    ErrorAlert,
    FormatSelector,
    QualitySelector,
  },
  data() {
    return {
      form: {
        youtube_url: '',
        format: 'mp3',
        quality: 320,
        artist: '',
        album: '',
      },
      videoInfo: null,
      currentDownload: null,
      isProcessing: false,
      isFetchingInfo: false,
      urlError: '',
      showMetadata: false,
      alertMessage: '',
      alertType: 'error',
      pollingInterval: null,
      statusClasses: {
        pending: 'bg-yellow-900/30 text-yellow-300 border border-yellow-500/50',
        processing: 'bg-blue-900/30 text-blue-300 border border-blue-500/50',
        completed: 'bg-green-900/30 text-green-300 border border-green-500/50',
        failed: 'bg-red-900/30 text-red-300 border border-red-500/50',
        cancelled: 'bg-gray-900/30 text-gray-300 border border-gray-500/50',
      },
    };
  },
  computed: {
    downloadUrl() {
      if (!this.currentDownload || this.currentDownload.status !== 'completed') {
        return '#';
      }
      return downloadAPI.getDownloadUrl(this.currentDownload.id);
    },
  },
  created() {
    // Create debounced version of fetch
    this.debouncedFetchInfo = debounce(this.fetchVideoInfo, 1000);
  },
  methods: {
    onUrlInput() {
      this.urlError = '';
      this.videoInfo = null;
      if (this.form.youtube_url) {
        this.debouncedFetchInfo();
      }
    },

    async fetchVideoInfo() {
      if (!this.form.youtube_url) return;
      
      // Basic validation regex
      const pattern = /^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be|music\.youtube\.com)\/.+$/;
      if (!pattern.test(this.form.youtube_url)) {
        this.urlError = 'Please enter a valid YouTube URL';
        return;
      }

      this.isFetchingInfo = true;
      this.alertMessage = ''; // Clear previous errors

      try {
        const response = await downloadAPI.fetchInfo(this.form.youtube_url);
        if (response.data.success) {
          this.videoInfo = response.data.video;
          // Auto-fill artist/album if detected
          if (this.videoInfo.artist) this.form.artist = this.videoInfo.artist;
          if (this.videoInfo.album) this.form.album = this.videoInfo.album;
        }
      } catch (error) {
        console.error('Fetch info error:', error);
        this.urlError = error.response?.data?.message || 'Failed to fetch video information. Please check the URL.';
      } finally {
        this.isFetchingInfo = false;
      }
    },

    async submitDownload() {
      if (!this.videoInfo) return;

      this.isProcessing = true;
      this.alertMessage = '';

      try {
        // Send form data PLUS video info to avoid re-fetching
        const payload = {
          ...this.form,
          title: this.videoInfo.title,
          thumbnail_url: this.videoInfo.thumbnail
        };

        const response = await downloadAPI.create(payload);
        
        if (response.data.success) {
          this.currentDownload = response.data.download;
          this.alertMessage = response.data.message;
          this.alertType = 'success';
          
          this.startPolling();
        } else {
          this.alertMessage = response.data.message || 'Failed to start download';
          this.alertType = 'error';
        }
      } catch (error) {
        console.error('Download error:', error);
        this.alertMessage = error.response?.data?.message || 'An error occurred while starting the download';
        this.alertType = 'error';
      } finally {
        this.isProcessing = false;
      }
    },

    startPolling() {
      this.pollingInterval = setInterval(async () => {
        if (!this.currentDownload) {
          this.stopPolling();
          return;
        }

        try {
          const response = await downloadAPI.get(this.currentDownload.id);
          this.currentDownload = response.data.download;

          if (['completed', 'failed', 'cancelled'].includes(this.currentDownload.status)) {
            this.stopPolling();
            
            if (this.currentDownload.status === 'completed') {
              this.alertMessage = 'Download completed successfully!';
              this.alertType = 'success';
            } else if (this.currentDownload.status === 'failed') {
              this.alertMessage = 'Download failed. Please try again.';
              this.alertType = 'error';
            }
          }
        } catch (error) {
          console.error('Polling error:', error);
          this.stopPolling();
        }
      }, 2000);
    },

    stopPolling() {
      if (this.pollingInterval) {
        clearInterval(this.pollingInterval);
        this.pollingInterval = null;
      }
    },

    async retryDownload() {
      try {
        const response = await downloadAPI.retry(this.currentDownload.id);
        if (response.data.success) {
          this.currentDownload = response.data.download;
          this.alertMessage = 'Download retry started';
          this.alertType = 'success';
          this.startPolling();
        }
      } catch (error) {
        this.alertMessage = error.response?.data?.message || 'Failed to retry download';
        this.alertType = 'error';
      }
    },

    async cancelDownload() {
      try {
        const response = await downloadAPI.cancel(this.currentDownload.id);
        if (response.data.success) {
          this.currentDownload = response.data.download;
          this.alertMessage = 'Download cancelled';
          this.alertType = 'info';
          this.stopPolling();
        }
      } catch (error) {
        this.alertMessage = error.response?.data?.message || 'Failed to cancel download';
        this.alertType = 'error';
      }
    },

    resetForm() {
      this.form = {
        youtube_url: '',
        format: 'mp3',
        quality: 320,
        artist: '',
        album: '',
      };
      this.videoInfo = null;
      this.currentDownload = null;
      this.showMetadata = false;
      this.alertMessage = '';
      this.urlError = '';
      this.stopPolling();
    },

    formatDuration(seconds) {
      if (!seconds) return 'Unknown';
      const m = Math.floor(seconds / 60);
      const s = seconds % 60;
      return `${m}:${s.toString().padStart(2, '0')}`;
    },
    
    formatBytes(bytes) {
      if (bytes === 0 || !bytes) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
  },
  beforeUnmount() {
    this.stopPolling();
  },
}
</script>

<style scoped>
@keyframes slide-down {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.animate-slide-down {
  animation: slide-down 0.3s ease-out;
}
</style>
