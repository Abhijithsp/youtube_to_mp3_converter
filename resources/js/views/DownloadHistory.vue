<template>
  <div class="mx-auto max-w-4xl">
    <PageHeader title="History" :subtitle="total ? `${total} finished download${total === 1 ? '' : 's'}` : 'Finished downloads'">
      <button v-if="total" type="button" class="btn btn-text btn-sm text-danger" @click="clearHistory">
        <AppIcon :path="mdiDeleteSweepOutline" :size="18" />
        Clear
      </button>
    </PageHeader>

    <!-- Filters -->
    <div class="mb-3 flex flex-col gap-2 sm:flex-row">
      <div class="relative flex-1">
        <AppIcon :path="mdiMagnify" :size="20" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-fg-muted" />
        <input
          v-model="search"
          type="search"
          class="field pl-10"
          placeholder="Search title or artist"
          aria-label="Search"
          @input="debouncedFetch"
        />
      </div>
      <div class="flex gap-2">
        <select v-model="format" class="field sm:w-36" aria-label="Format" @change="fetchHistory(1)">
          <option value="">All formats</option>
          <option value="mp3">MP3</option>
          <option value="flac">FLAC</option>
        </select>
        <select v-model="sort" class="field sm:w-40" aria-label="Sort" @change="fetchHistory(1)">
          <option value="completed_at:desc">Newest</option>
          <option value="completed_at:asc">Oldest</option>
          <option value="title:asc">Title A–Z</option>
          <option value="file_size:desc">Largest</option>
          <option value="duration:desc">Longest</option>
        </select>
      </div>
    </div>

    <section class="card overflow-hidden">
      <div v-if="loading && !downloads.length" class="flex justify-center py-12 text-fg-muted">
        <AppIcon :path="mdiLoading" :size="28" spin />
      </div>

      <EmptyState
        v-else-if="!downloads.length"
        :icon="search || format ? mdiMagnifyClose : mdiHistory"
        :title="search || format ? 'No matches' : 'No downloads yet'"
        :text="search || format ? 'Try a different search or filter.' : 'Finished downloads are listed here.'"
      />

      <div v-else class="divide-y divide-line" :class="loading && 'opacity-60'">
        <DownloadRow v-for="d in downloads" :key="d.id" :download="d" :show-status="false">
          <template #actions>
            <button
              type="button"
              class="icon-btn"
              :class="isPlaying(d) && '!text-primary'"
              :title="isPlaying(d) ? 'Pause' : 'Play'"
              :aria-label="isPlaying(d) ? 'Pause' : 'Play'"
              @click="toggle(d)"
            >
              <AppIcon :path="isPlaying(d) ? mdiPauseCircle : mdiPlayCircleOutline" />
            </button>
            <a :href="d.download_url" class="icon-btn" title="Save file" aria-label="Save file">
              <AppIcon :path="mdiTrayArrowDown" />
            </a>
            <button type="button" class="icon-btn" title="Delete" aria-label="Delete" @click="remove(d)">
              <AppIcon :path="mdiDeleteOutline" />
            </button>
          </template>
        </DownloadRow>
      </div>

      <div v-if="lastPage > 1" class="flex items-center justify-between border-t border-line px-3 py-2">
        <button type="button" class="icon-btn" :disabled="page <= 1" aria-label="Previous page" @click="fetchHistory(page - 1)">
          <AppIcon :path="mdiChevronLeft" />
        </button>
        <span class="text-xs text-fg-muted">Page {{ page }} of {{ lastPage }}</span>
        <button type="button" class="icon-btn" :disabled="page >= lastPage" aria-label="Next page" @click="fetchHistory(page + 1)">
          <AppIcon :path="mdiChevronRight" />
        </button>
      </div>
    </section>
  </div>
</template>

<script>
import {
  mdiChevronLeft,
  mdiChevronRight,
  mdiDeleteOutline,
  mdiDeleteSweepOutline,
  mdiHistory,
  mdiLoading,
  mdiMagnify,
  mdiMagnifyClose,
  mdiPauseCircle,
  mdiPlayCircleOutline,
  mdiTrayArrowDown,
} from '@mdi/js';
import AppIcon from '../components/AppIcon.vue';
import DownloadRow from '../components/DownloadRow.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { downloadAPI, historyAPI } from '../services/api';
import { forget, isCurrent, player, stop, toggle } from '../player';
import { confirmDialog, errorMessage, notify } from '../ui';

export default {
  name: 'DownloadHistory',
  components: { AppIcon, DownloadRow, EmptyState, PageHeader },
  data() {
    return {
      downloads: [],
      loading: true,
      search: '',
      format: '',
      sort: 'completed_at:desc',
      page: 1,
      lastPage: 1,
      total: 0,
      searchTimer: null,
      player,
      mdiChevronLeft,
      mdiChevronRight,
      mdiDeleteOutline,
      mdiDeleteSweepOutline,
      mdiHistory,
      mdiLoading,
      mdiMagnify,
      mdiMagnifyClose,
      mdiPauseCircle,
      mdiPlayCircleOutline,
      mdiTrayArrowDown,
    };
  },
  mounted() {
    this.fetchHistory();
  },
  beforeUnmount() {
    clearTimeout(this.searchTimer);
  },
  methods: {
    async fetchHistory(page = 1) {
      this.loading = true;
      const [sortBy, sortDir] = this.sort.split(':');
      try {
        const { data } = await historyAPI.getAll({
          page,
          search: this.search || undefined,
          format: this.format || undefined,
          sort_by: sortBy,
          sort_dir: sortDir,
          per_page: 25,
        });
        this.downloads = data.data;
        this.page = data.current_page;
        this.lastPage = data.last_page;
        this.total = data.total;
      } catch (error) {
        notify(errorMessage(error, 'Could not load history'), 'error');
      } finally {
        this.loading = false;
      }
    },

    debouncedFetch() {
      clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => this.fetchHistory(1), 350);
    },

    toggle,

    isPlaying(download) {
      return isCurrent(download) && player.playing;
    },

    async remove(download) {
      const ok = await confirmDialog({
        title: 'Delete download?',
        message: `"${download.title}" and its audio file will be deleted.`,
        confirmLabel: 'Delete',
        danger: true,
      });
      if (!ok) return;
      try {
        forget(download);
        await downloadAPI.delete(download.id);
        notify('Deleted.');
        this.fetchHistory(this.downloads.length === 1 && this.page > 1 ? this.page - 1 : this.page);
      } catch (error) {
        notify(errorMessage(error, 'Could not delete'), 'error');
      }
    },

    async clearHistory() {
      const result = await confirmDialog({
        title: 'Clear history?',
        message: 'All finished downloads are removed from the list.',
        checkbox: 'Also delete the audio files from disk',
        confirmLabel: 'Clear history',
        danger: true,
      });
      if (!result) return;
      try {
        stop();
        await historyAPI.clear(!result.checked);
        notify(result.checked ? 'History and files deleted.' : 'History cleared. Files were kept.', 'success');
        this.fetchHistory(1);
      } catch (error) {
        notify(errorMessage(error, 'Could not clear history'), 'error');
      }
    },
  },
};
</script>
