<template>
  <div class="mx-auto max-w-3xl">
    <PageHeader title="Playlist" subtitle="Import a playlist and pick the videos you want." />

    <!-- Link -->
    <form class="card p-4" @submit.prevent="fetchPlaylist">
      <label for="playlist-url" class="field-label">Playlist link</label>
      <div class="flex gap-2">
        <input
          id="playlist-url"
          v-model.trim="url"
          type="url"
          inputmode="url"
          autocomplete="off"
          class="field flex-1"
          :class="error && 'field-error'"
          placeholder="https://www.youtube.com/playlist?list=…"
          :disabled="loading"
          required
        />
        <button type="submit" class="btn btn-filled shrink-0" :disabled="loading || !url">
          <AppIcon :path="loading ? mdiLoading : mdiMagnify" :size="18" :spin="loading" />
          <span class="hidden sm:inline">{{ loading ? 'Loading…' : 'Load' }}</span>
        </button>
      </div>
      <p v-if="error" class="mt-2 flex items-start gap-1.5 whitespace-pre-line text-sm text-danger">
        <AppIcon :path="mdiAlertCircle" :size="16" class="mt-0.5" />
        <span>{{ error }}</span>
      </p>
      <p v-else-if="loading" class="mt-2 text-xs text-fg-muted">Reading the playlist… large playlists can take a moment.</p>
    </form>

    <!-- Selection -->
    <section v-if="playlist" class="card mt-3 overflow-hidden animate-fade-up">
      <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3">
        <div class="min-w-0 flex-1">
          <h2 class="card-title truncate">{{ playlist.title }}</h2>
          <p class="text-xs text-fg-muted">{{ selectedCount }} of {{ playlist.videos.length }} selected · {{ formatDuration(selectedDuration) }}</p>
        </div>
        <button type="button" class="btn btn-text btn-sm" @click="toggleAll">
          {{ allSelected ? 'Select none' : 'Select all' }}
        </button>
      </div>

      <ul class="scroll-thin max-h-[22rem] divide-y divide-line overflow-y-auto">
        <li v-for="(video, index) in playlist.videos" :key="video.id">
          <label class="flex cursor-pointer items-center gap-3 px-4 py-2 hover:bg-surface-2">
            <input v-model="video.selected" type="checkbox" class="checkbox" />
            <span class="w-6 shrink-0 text-right text-xs tabular-nums text-fg-muted">{{ index + 1 }}</span>
            <span class="min-w-0 flex-1 truncate text-sm text-fg" :title="video.title">{{ video.title }}</span>
            <span class="shrink-0 text-xs tabular-nums text-fg-muted">{{ formatDuration(video.duration) }}</span>
          </label>
        </li>
      </ul>

      <div class="grid gap-4 border-t border-line p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <div>
          <span class="field-label">Format</span>
          <SegmentedControl v-model="format" label="Format" :options="formatOptions" />
        </div>
        <div>
          <span class="field-label">Bitrate</span>
          <SegmentedControl v-model="quality" label="Bitrate" :options="qualityOptions" :disabled="format !== 'mp3'" />
        </div>
        <button type="button" class="btn btn-filled" :disabled="importing || !selectedCount" @click="importPlaylist">
          <AppIcon :path="importing ? mdiLoading : mdiPlaylistPlus" :size="18" :spin="importing" />
          Import {{ selectedCount }}
        </button>
      </div>
    </section>

    <!-- Imported -->
    <section class="mt-6">
      <h2 class="mb-2 px-1 text-sm font-medium text-fg-muted">Imported playlists</h2>
      <div class="card overflow-hidden">
        <EmptyState
          v-if="!imported.length"
          :icon="mdiPlaylistMusicOutline"
          title="No playlists yet"
          text="Imported playlists and their progress appear here."
        />
        <ul v-else class="divide-y divide-line">
          <li v-for="item in imported" :key="item.id" class="flex items-center gap-3 px-4 py-3">
            <span class="tile-icon h-10 w-10 shrink-0 bg-primary-soft text-on-primary-soft">
              <AppIcon :path="mdiPlaylistMusic" :size="22" />
            </span>
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <p class="truncate text-sm font-medium text-fg">{{ item.title }}</p>
                <span class="chip shrink-0" :class="item.status === 'completed' && '!bg-success-soft !text-success'">
                  {{ item.status === 'completed' ? 'Done' : `${item.processed_videos}/${item.total_videos}` }}
                </span>
              </div>
              <LinearProgress
                v-if="item.status !== 'completed'"
                class="mt-2"
                :value="item.total_videos ? (item.processed_videos / item.total_videos) * 100 : 0"
                :tone="item.status === 'failed' ? 'danger' : 'primary'"
              />
              <p v-else class="text-xs text-fg-muted">{{ item.total_videos }} videos · {{ formatRelative(item.updated_at) }}</p>
            </div>
            <button
              v-if="item.processed_videos"
              type="button"
              class="icon-btn"
              title="Play playlist"
              aria-label="Play playlist"
              @click="playPlaylist(item)"
            >
              <AppIcon :path="mdiPlayCircleOutline" />
            </button>
            <button type="button" class="icon-btn" title="Remove playlist" aria-label="Remove playlist" @click="remove(item)">
              <AppIcon :path="mdiDeleteOutline" />
            </button>
          </li>
        </ul>
      </div>
    </section>
  </div>
</template>

<script>
import {
  mdiAlertCircle,
  mdiDeleteOutline,
  mdiLoading,
  mdiMagnify,
  mdiPlayCircleOutline,
  mdiPlaylistMusic,
  mdiPlaylistMusicOutline,
  mdiPlaylistPlus,
} from '@mdi/js';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import LinearProgress from '../components/LinearProgress.vue';
import PageHeader from '../components/PageHeader.vue';
import SegmentedControl from '../components/SegmentedControl.vue';
import { playAll } from '../player';
import { playlistAPI } from '../services/api';
import { confirmDialog, errorMessage, formatDuration, formatRelative, notify } from '../ui';

export default {
  name: 'PlaylistImport',
  components: { AppIcon, EmptyState, LinearProgress, PageHeader, SegmentedControl },
  data() {
    return {
      url: '',
      error: '',
      loading: false,
      importing: false,
      playlist: null,
      format: 'mp3',
      quality: '320',
      imported: [],
      pollTimer: null,
      formatOptions: [
        { value: 'mp3', label: 'MP3' },
        { value: 'flac', label: 'FLAC' },
        { value: 'wav', label: 'WAV' },
      ],
      qualityOptions: [
        { value: '192', label: '192k' },
        { value: '256', label: '256k' },
        { value: '320', label: '320k' },
      ],
      mdiAlertCircle,
      mdiDeleteOutline,
      mdiLoading,
      mdiMagnify,
      mdiPlayCircleOutline,
      mdiPlaylistMusic,
      mdiPlaylistMusicOutline,
      mdiPlaylistPlus,
    };
  },
  computed: {
    selected() {
      return this.playlist ? this.playlist.videos.filter((v) => v.selected) : [];
    },
    selectedCount() {
      return this.selected.length;
    },
    selectedDuration() {
      return this.selected.reduce((sum, v) => sum + (v.duration || 0), 0);
    },
    allSelected() {
      return this.playlist && this.selectedCount === this.playlist.videos.length;
    },
  },
  mounted() {
    this.loadImported();
    this.pollTimer = setInterval(() => {
      if (this.imported.some((p) => p.status !== 'completed')) this.loadImported();
    }, 4000);

    if (this.$route.query.url) {
      this.url = String(this.$route.query.url);
      this.fetchPlaylist();
    }
  },
  beforeUnmount() {
    clearInterval(this.pollTimer);
  },
  methods: {
    formatDuration,
    formatRelative,

    async fetchPlaylist() {
      this.loading = true;
      this.error = '';
      this.playlist = null;
      try {
        const { data } = await playlistAPI.fetchInfo(this.url);
        if (!data.videos.length) {
          this.error = 'This playlist has no downloadable videos.';
          return;
        }
        data.videos = data.videos.map((v) => ({ ...v, selected: true }));
        this.playlist = data;
      } catch (error) {
        this.error = error.response?.data?.error || errorMessage(error, 'Could not load this playlist.');
      } finally {
        this.loading = false;
      }
    },

    toggleAll() {
      const state = !this.allSelected;
      this.playlist.videos.forEach((v) => (v.selected = state));
    },

    async importPlaylist() {
      this.importing = true;
      try {
        await playlistAPI.create({
          url: this.url,
          title: this.playlist.title,
          items: this.selected.map(({ id, title, url }) => ({ id, title, url })),
          format: this.format,
          quality: this.quality,
        });
        notify(`Queued ${this.selectedCount} downloads.`, 'success');
        this.playlist = null;
        this.url = '';
        this.$router.push('/queue');
      } catch (error) {
        notify(errorMessage(error, 'Could not import the playlist'), 'error');
      } finally {
        this.importing = false;
      }
    },

    async loadImported() {
      try {
        const { data } = await playlistAPI.getAll();
        this.imported = data.data;
      } catch {
        // Not critical for importing; leave the list as it is
      }
    },

    async playPlaylist(item) {
      try {
        const { data } = await playlistAPI.get(item.id);
        const downloads = [...data.items]
          .sort((a, b) => a.position - b.position)
          .map((i) => i.download)
          .filter((d) => d && d.status === 'completed');
        playAll(downloads);
      } catch (error) {
        notify(errorMessage(error, 'Could not load the playlist'), 'error');
      }
    },

    async remove(item) {
      const ok = await confirmDialog({
        title: 'Remove playlist?',
        message: `"${item.title}" is removed from this list. Downloaded files and history are kept.`,
        confirmLabel: 'Remove',
        danger: true,
      });
      if (!ok) return;
      try {
        await playlistAPI.delete(item.id);
        this.imported = this.imported.filter((p) => p.id !== item.id);
      } catch (error) {
        notify(errorMessage(error, 'Could not remove the playlist'), 'error');
      }
    },
  },
};
</script>
