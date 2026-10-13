<template>
  <div class="mx-auto max-w-2xl">
    <PageHeader title="Download" subtitle="Convert a YouTube video to MP3 or FLAC with tags and cover art." />

    <!-- Link -->
    <form class="card p-4" @submit.prevent="fetchVideoInfo">
      <label for="url" class="field-label">YouTube link</label>
      <div class="flex gap-2">
        <div class="relative flex-1">
          <input
            id="url"
            ref="url"
            v-model.trim="url"
            type="url"
            inputmode="url"
            autocomplete="off"
            class="field pr-10"
            :class="urlError && 'field-error'"
            placeholder="https://www.youtube.com/watch?v=…"
            :disabled="busy"
            @input="onUrlInput"
          />
          <button
            v-if="url && !busy"
            type="button"
            class="icon-btn absolute right-1 top-1/2 -translate-y-1/2"
            aria-label="Clear"
            @click="reset"
          >
            <AppIcon :path="mdiClose" :size="18" />
          </button>
        </div>
        <button
          v-if="canPaste && !url"
          type="button"
          class="btn btn-tonal shrink-0"
          @click="paste"
        >
          <AppIcon :path="mdiContentPaste" :size="18" />
          <span class="hidden sm:inline">Paste</span>
        </button>
      </div>
      <p v-if="urlError" class="mt-2 flex items-start gap-1.5 whitespace-pre-line text-sm text-danger">
        <AppIcon :path="mdiAlertCircle" :size="16" class="mt-0.5" />
        <span>{{ urlError }}</span>
      </p>

      <!-- Loading preview -->
      <div v-if="fetching" class="mt-4 flex gap-3">
        <div class="aspect-video w-36 animate-pulse rounded-lg bg-surface-3 sm:w-44" />
        <div class="flex-1 space-y-2 py-1">
          <div class="h-4 w-4/5 animate-pulse rounded bg-surface-3" />
          <div class="h-3 w-1/3 animate-pulse rounded bg-surface-3" />
          <p class="pt-1 text-xs text-fg-muted">Looking up the video…</p>
        </div>
      </div>

      <!-- Preview -->
      <div v-else-if="video" class="mt-4 flex gap-3 animate-fade-up">
        <div class="relative aspect-video w-36 shrink-0 overflow-hidden rounded-lg bg-surface-3 sm:w-44">
          <img v-if="video.thumbnail" :src="video.thumbnail" alt="" class="h-full w-full object-cover" />
          <span v-if="video.duration" class="absolute bottom-1 right-1 rounded bg-black/75 px-1 text-[11px] font-medium text-white">
            {{ formatDuration(video.duration) }}
          </span>
        </div>
        <div class="min-w-0 py-0.5">
          <p class="line-clamp-2 text-sm font-medium text-fg">{{ video.title }}</p>
          <p v-if="video.artist" class="mt-1 truncate text-xs text-fg-muted">{{ video.artist }}</p>
        </div>
      </div>
    </form>

    <!-- Options -->
    <section v-if="video && !download" class="card mt-3 space-y-4 p-4 animate-fade-up">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <span class="field-label">Format</span>
          <SegmentedControl v-model="format" label="Format" :options="formatOptions" :disabled="submitting" />
        </div>
        <div>
          <span class="field-label">Bitrate</span>
          <SegmentedControl v-model="quality" label="Bitrate" :options="qualityOptions" :disabled="submitting || format === 'flac'" />
          <p v-if="format === 'flac'" class="field-hint">FLAC is lossless, so bitrate does not apply.</p>
        </div>
      </div>

      <div>
        <button
          type="button"
          class="flex items-center gap-1 text-sm font-medium text-primary"
          :aria-expanded="showTags"
          @click="showTags = !showTags"
        >
          <AppIcon :path="showTags ? mdiChevronUp : mdiChevronDown" :size="20" />
          Edit tags
        </button>
        <div v-if="showTags" class="mt-3 grid gap-3 sm:grid-cols-2 animate-fade-up">
          <div class="sm:col-span-2">
            <label for="title" class="field-label">Title</label>
            <input id="title" v-model="tags.title" class="field" maxlength="255" />
          </div>
          <div>
            <label for="artist" class="field-label">Artist</label>
            <input id="artist" v-model="tags.artist" class="field" maxlength="255" />
          </div>
          <div>
            <label for="album" class="field-label">Album</label>
            <input id="album" v-model="tags.album" class="field" maxlength="255" />
          </div>
        </div>
      </div>

      <button type="button" class="btn btn-filled w-full" :disabled="submitting" @click="submit">
        <AppIcon :path="submitting ? mdiLoading : mdiTrayArrowDown" :size="18" :spin="submitting" />
        {{ submitting ? 'Starting…' : `Download ${format.toUpperCase()}` }}
      </button>
    </section>

    <!-- Progress / result -->
    <section v-if="download" class="card mt-3 overflow-hidden animate-fade-up">
      <DownloadRow :download="download" />

      <div class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3">
        <button v-if="isActive" type="button" class="btn btn-outlined btn-sm" @click="cancel">
          <AppIcon :path="mdiStopCircleOutline" :size="18" />
          Cancel
        </button>
        <button v-if="canRetry" type="button" class="btn btn-tonal btn-sm" @click="retry">
          <AppIcon :path="mdiRefresh" :size="18" />
          Retry
        </button>
        <button v-if="!isActive" type="button" class="btn btn-outlined btn-sm" @click="reset">
          <AppIcon :path="mdiPlus" :size="18" />
          Another
        </button>
        <button v-if="download.status === 'completed'" type="button" class="btn btn-tonal btn-sm" @click="toggle(download)">
          <AppIcon :path="isPlaying ? mdiPause : mdiPlay" :size="18" />
          {{ isPlaying ? 'Pause' : 'Play' }}
        </button>
        <a v-if="download.status === 'completed'" :href="download.download_url" class="btn btn-filled btn-sm">
          <AppIcon :path="mdiTrayArrowDown" :size="18" />
          Save file
        </a>
      </div>
    </section>
  </div>
</template>

<script>
import {
  mdiAlertCircle,
  mdiChevronDown,
  mdiChevronUp,
  mdiClose,
  mdiContentPaste,
  mdiLoading,
  mdiPause,
  mdiPlay,
  mdiPlus,
  mdiRefresh,
  mdiStopCircleOutline,
  mdiTrayArrowDown,
} from '@mdi/js';
import AppIcon from '../components/AppIcon.vue';
import DownloadRow from '../components/DownloadRow.vue';
import PageHeader from '../components/PageHeader.vue';
import SegmentedControl from '../components/SegmentedControl.vue';
import { isCurrent, player, toggle } from '../player';
import { downloadAPI } from '../services/api';
import { errorMessage, formatDuration, notify, YOUTUBE_URL } from '../ui';

export default {
  name: 'SingleDownload',
  components: { AppIcon, DownloadRow, PageHeader, SegmentedControl },
  data() {
    return {
      url: '',
      urlError: '',
      video: null,
      fetching: false,
      submitting: false,
      format: 'mp3',
      quality: '320',
      showTags: false,
      tags: { title: '', artist: '', album: '' },
      download: null,
      pollTimer: null,
      debounceTimer: null,
      lookupId: 0,
      canPaste: !!navigator.clipboard?.readText,
      formatOptions: [
        { value: 'mp3', label: 'MP3' },
        { value: 'flac', label: 'FLAC' },
      ],
      qualityOptions: [
        { value: '192', label: '192k' },
        { value: '256', label: '256k' },
        { value: '320', label: '320k' },
      ],
      mdiAlertCircle,
      mdiChevronDown,
      mdiChevronUp,
      mdiClose,
      mdiContentPaste,
      mdiLoading,
      mdiPause,
      mdiPlay,
      mdiPlus,
      mdiRefresh,
      mdiStopCircleOutline,
      mdiTrayArrowDown,
    };
  },
  computed: {
    busy() {
      return this.submitting || (this.download && this.isActive);
    },
    isActive() {
      return this.download && ['pending', 'processing'].includes(this.download.status);
    },
    isPlaying() {
      return isCurrent(this.download) && player.playing;
    },
    canRetry() {
      return this.download && ['failed', 'cancelled'].includes(this.download.status);
    },
  },
  mounted() {
    if (this.$route.query.url) {
      this.url = String(this.$route.query.url);
      this.fetchVideoInfo();
    } else {
      this.$refs.url?.focus();
    }
  },
  beforeUnmount() {
    this.stopPolling();
    clearTimeout(this.debounceTimer);
  },
  methods: {
    formatDuration,
    toggle,

    onUrlInput() {
      this.urlError = '';
      this.video = null;
      clearTimeout(this.debounceTimer);
      if (this.url) this.debounceTimer = setTimeout(this.fetchVideoInfo, 700);
    },

    async paste() {
      try {
        this.url = (await navigator.clipboard.readText()).trim();
        this.onUrlInput();
      } catch {
        notify('Clipboard access was blocked. Paste with Ctrl+V instead.');
      }
    },

    async fetchVideoInfo() {
      clearTimeout(this.debounceTimer);
      if (!this.url) return;

      if (!YOUTUBE_URL.test(this.url)) {
        this.urlError = 'This does not look like a YouTube link.';
        return;
      }
      if (/[?&]list=/.test(this.url) && !/[?&]v=|youtu\.be\/|\/shorts\//.test(this.url)) {
        this.$router.push({ path: '/playlist', query: { url: this.url } });
        return;
      }

      // Ignore answers to older lookups if the link changed meanwhile
      const lookupId = ++this.lookupId;
      this.fetching = true;
      this.urlError = '';

      try {
        const { data } = await downloadAPI.fetchInfo(this.url);
        if (lookupId !== this.lookupId) return;
        this.video = data.video;
        this.tags = {
          title: data.video.title || '',
          artist: data.video.artist || '',
          album: data.video.album || '',
        };
      } catch (error) {
        if (lookupId !== this.lookupId) return;
        this.urlError = errorMessage(error, 'Could not load this video.');
      } finally {
        if (lookupId === this.lookupId) this.fetching = false;
      }
    },

    async submit() {
      this.submitting = true;
      try {
        const { data } = await downloadAPI.create({
          youtube_url: this.url,
          format: this.format,
          quality: this.quality,
          title: this.tags.title || this.video.title,
          artist: this.tags.artist || null,
          album: this.tags.album || null,
          thumbnail_url: this.video.thumbnail,
        });
        this.download = data.download;
        if (data.download.status === 'completed') {
          notify('Already downloaded in this format.', 'success');
        } else {
          this.startPolling();
        }
      } catch (error) {
        notify(errorMessage(error, 'Could not start the download'), 'error');
      } finally {
        this.submitting = false;
      }
    },

    startPolling() {
      this.stopPolling();
      this.pollTimer = setInterval(this.refresh, 1500);
    },

    stopPolling() {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    },

    async refresh() {
      try {
        const { data } = await downloadAPI.get(this.download.id);
        this.download = data.download;
        if (!this.isActive) {
          this.stopPolling();
          if (this.download.status === 'completed') notify('Download finished.', 'success');
          if (this.download.status === 'failed') notify('Download failed. See the error below.', 'error');
        }
      } catch (error) {
        this.stopPolling();
        notify(errorMessage(error, 'Lost track of the download'), 'error');
      }
    },

    async retry() {
      try {
        const { data } = await downloadAPI.retry(this.download.id);
        this.download = data.download;
        this.startPolling();
      } catch (error) {
        notify(errorMessage(error, 'Could not retry'), 'error');
      }
    },

    async cancel() {
      try {
        const { data } = await downloadAPI.cancel(this.download.id);
        this.download = data.download;
        this.stopPolling();
        notify('Download cancelled.');
      } catch (error) {
        notify(errorMessage(error, 'Could not cancel'), 'error');
      }
    },

    reset() {
      this.stopPolling();
      clearTimeout(this.debounceTimer);
      this.lookupId++;
      this.url = '';
      this.urlError = '';
      this.video = null;
      this.fetching = false;
      this.download = null;
      this.showTags = false;
      if (this.$route.query.url) this.$router.replace({ query: {} });
      this.$nextTick(() => this.$refs.url?.focus());
    },
  },
};
</script>
