<template>
  <div>
    <PageHeader title="Home" subtitle="Paste a link to grab its audio, or check on recent downloads." />

    <!-- Quick start -->
    <form class="card mb-4 flex flex-col gap-2 p-3 sm:flex-row sm:items-center" @submit.prevent="start">
      <div class="relative flex-1">
        <AppIcon :path="mdiLink" :size="20" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-fg-muted" />
        <input
          v-model.trim="url"
          type="url"
          inputmode="url"
          class="field !border-transparent !bg-surface-2 pl-10"
          placeholder="Paste a YouTube video or playlist link"
          aria-label="YouTube link"
        />
      </div>
      <button type="submit" class="btn btn-filled" :disabled="!url">
        <AppIcon :path="mdiArrowRight" :size="18" />
        Continue
      </button>
    </form>

    <!-- Stats -->
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <router-link
        v-for="tile in tiles"
        :key="tile.label"
        :to="tile.to"
        class="card card-lift flex items-center gap-3 p-3.5"
      >
        <span class="tile-icon h-10 w-10 shrink-0" :class="tile.tone">
          <AppIcon :path="tile.icon" :size="22" />
        </span>
        <span class="min-w-0">
          <span class="block truncate text-lg font-semibold leading-tight text-fg">
            <span v-if="loading" class="inline-block h-5 w-10 animate-pulse rounded bg-surface-3 align-middle" />
            <template v-else>{{ tile.value }}</template>
          </span>
          <span class="block truncate text-xs text-fg-muted">{{ tile.label }}</span>
        </span>
      </router-link>
    </div>

    <!-- Recent -->
    <section class="card overflow-hidden">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h2 class="card-title">Recent downloads</h2>
        <router-link to="/history" class="btn btn-text btn-sm -mr-2">View all</router-link>
      </div>

      <div v-if="loading" class="divide-y divide-line">
        <div v-for="n in 3" :key="n" class="flex items-center gap-3 px-4 py-3">
          <div class="h-11 w-[4.5rem] animate-pulse rounded-lg bg-surface-3" />
          <div class="flex-1 space-y-2">
            <div class="h-3 w-2/3 animate-pulse rounded bg-surface-3" />
            <div class="h-3 w-1/3 animate-pulse rounded bg-surface-3" />
          </div>
        </div>
      </div>

      <EmptyState
        v-else-if="!recent.length"
        :icon="mdiMusicNoteOff"
        title="Nothing downloaded yet"
        text="Finished downloads show up here."
      />

      <div v-else class="divide-y divide-line">
        <DownloadRow v-for="download in recent" :key="download.id" :download="download" :show-status="false">
          <template #actions>
            <button
              type="button"
              class="icon-btn"
              :class="isPlaying(download) && '!text-primary'"
              :title="isPlaying(download) ? 'Pause' : 'Play'"
              :aria-label="isPlaying(download) ? 'Pause' : 'Play'"
              @click="toggle(download)"
            >
              <AppIcon :path="isPlaying(download) ? mdiPauseCircle : mdiPlayCircleOutline" />
            </button>
            <a :href="download.download_url" class="icon-btn" title="Save file" aria-label="Save file">
              <AppIcon :path="mdiTrayArrowDown" />
            </a>
          </template>
        </DownloadRow>
      </div>
    </section>
  </div>
</template>

<script>
import {
  mdiAlertCircleOutline,
  mdiArrowRight,
  mdiHarddisk,
  mdiLink,
  mdiMusicBoxMultipleOutline,
  mdiMusicNoteOff,
  mdiPauseCircle,
  mdiPlayCircleOutline,
  mdiTimerSandEmpty,
  mdiTrayArrowDown,
} from '@mdi/js';
import AppIcon from '../components/AppIcon.vue';
import DownloadRow from '../components/DownloadRow.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { isCurrent, player, toggle } from '../player';
import { dashboardAPI } from '../services/api';
import { errorMessage, notify } from '../ui';

export default {
  name: 'Dashboard',
  components: { AppIcon, DownloadRow, EmptyState, PageHeader },
  data() {
    return {
      url: '',
      stats: {},
      loading: true,
      mdiArrowRight,
      mdiLink,
      mdiMusicNoteOff,
      mdiPauseCircle,
      mdiPlayCircleOutline,
      mdiTrayArrowDown,
    };
  },
  computed: {
    recent() {
      return this.stats.recent_downloads || [];
    },
    tiles() {
      const s = this.stats;
      return [
        { label: 'Downloads', value: s.total_downloads ?? 0, icon: mdiMusicBoxMultipleOutline, tone: 'bg-primary-soft text-on-primary-soft', to: '/history' },
        { label: 'Storage used', value: s.total_storage ?? '0 B', icon: mdiHarddisk, tone: 'bg-surface-2 text-fg-muted', to: '/settings' },
        { label: 'In queue', value: s.queue_pending ?? 0, icon: mdiTimerSandEmpty, tone: 'bg-warning-soft text-warning', to: '/queue' },
        { label: 'Failed', value: s.downloads_failed ?? 0, icon: mdiAlertCircleOutline, tone: s.downloads_failed ? 'bg-danger-soft text-danger' : 'bg-surface-2 text-fg-muted', to: '/queue?tab=failed' },
      ];
    },
  },
  mounted() {
    this.fetchStats();
  },
  methods: {
    toggle,

    isPlaying(download) {
      return isCurrent(download) && player.playing;
    },

    async fetchStats() {
      try {
        const { data } = await dashboardAPI.getStats();
        this.stats = data;
      } catch (error) {
        notify(errorMessage(error, 'Could not load the dashboard'), 'error');
      } finally {
        this.loading = false;
      }
    },
    start() {
      // Playlist links (without a video) go to the playlist importer
      const isPlaylist = /[?&]list=/.test(this.url) && !/[?&]v=|youtu\.be\//.test(this.url);
      this.$router.push({ path: isPlaylist ? '/playlist' : '/download', query: { url: this.url } });
    },
  },
};
</script>
