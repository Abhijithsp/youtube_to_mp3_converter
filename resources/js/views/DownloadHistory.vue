<template>
  <div class="mx-auto max-w-4xl">
    <PageHeader title="History" :subtitle="total ? `${total} finished download${total === 1 ? '' : 's'}` : 'Finished downloads'">
      <button v-if="total" type="button" class="btn btn-filled btn-sm" :disabled="startingAll" @click="playEverything">
        <AppIcon :path="mdiPlay" :size="18" />
        Play all
      </button>
      <button v-if="total && !selecting" type="button" class="btn btn-tonal btn-sm" @click="startSelecting">
        <AppIcon :path="mdiCheckboxMultipleMarkedOutline" :size="18" />
        Select
      </button>
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
          <option value="wav">WAV</option>
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

    <!-- Selection bar (History → Select) -->
    <div
      v-if="selecting"
      class="card sticky top-16 z-10 mb-3 flex items-center gap-1 px-2 py-1.5 animate-fade-up md:top-3 sm:gap-2"
      style="box-shadow: var(--md-elev-2)"
    >
      <button type="button" class="icon-btn" title="Stop selecting" aria-label="Stop selecting" @click="stopSelecting">
        <AppIcon :path="mdiClose" />
      </button>
      <span class="min-w-0 flex-1 truncate text-sm font-medium text-fg" aria-live="polite">
        {{ selectedCount ? `${selectedCount} selected` : 'Tap songs to select' }}
      </span>
      <button type="button" class="btn btn-text btn-sm" :disabled="!downloads.length" @click="togglePage">
        <AppIcon :path="pageSelected ? mdiCheckboxMultipleBlankOutline : mdiCheckboxMultipleMarkedOutline" :size="18" />
        <span class="hidden sm:inline">{{ pageSelected ? 'Unselect page' : 'Select page' }}</span>
      </button>
      <button type="button" class="btn btn-filled btn-sm" :disabled="!selectedCount" @click="downloadZip">
        <AppIcon :path="mdiFolderZipOutline" :size="18" />
        <span class="hidden sm:inline">Download ZIP</span>
        <span class="sm:hidden">ZIP</span>
      </button>
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
        <DownloadRow
          v-for="d in downloads"
          :key="d.id"
          :download="d"
          :show-status="false"
          :selectable="selecting"
          :selected="!!selected[d.id]"
          @toggle="toggleSelected(d)"
        >
          <template #actions>
            <button
              type="button"
              class="icon-btn"
              :class="isPlaying(d) && '!text-primary'"
              :title="isPlaying(d) ? 'Pause' : 'Play'"
              :aria-label="isPlaying(d) ? 'Pause' : 'Play'"
              @click="toggle(d, downloads)"
            >
              <AppIcon :path="isPlaying(d) ? mdiPauseCircle : mdiPlayCircleOutline" />
            </button>
            <button type="button" class="icon-btn" title="Edit tags" aria-label="Edit tags" @click="editing = d">
              <AppIcon :path="mdiPencilOutline" />
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

    <TagEditorDialog :download="editing" @close="editing = null" @saved="onSaved" />
  </div>
</template>

<script>
import {
  mdiCheckboxMultipleBlankOutline,
  mdiCheckboxMultipleMarkedOutline,
  mdiChevronLeft,
  mdiChevronRight,
  mdiClose,
  mdiFolderZipOutline,
  mdiDeleteOutline,
  mdiPencilOutline,
  mdiDeleteSweepOutline,
  mdiHistory,
  mdiPlay,
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
import TagEditorDialog from '../components/TagEditorDialog.vue';
import { downloadAPI, historyAPI } from '../services/api';
import { forget, isCurrent, player, playAll, stop, toggle } from '../player';
import { confirmDialog, errorMessage, notify } from '../ui';

export default {
  name: 'DownloadHistory',
  components: { AppIcon, DownloadRow, EmptyState, PageHeader, TagEditorDialog },
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
      startingAll: false,
      editing: null,
      // Selection for the ZIP download: id → true, kept across pages and searches
      selecting: false,
      selected: {},
      mdiCheckboxMultipleBlankOutline,
      mdiCheckboxMultipleMarkedOutline,
      mdiChevronLeft,
      mdiChevronRight,
      mdiClose,
      mdiFolderZipOutline,
      mdiDeleteOutline,
      mdiPencilOutline,
      mdiDeleteSweepOutline,
      mdiHistory,
      mdiPlay,
      mdiLoading,
      mdiMagnify,
      mdiMagnifyClose,
      mdiPauseCircle,
      mdiPlayCircleOutline,
      mdiTrayArrowDown,
    };
  },
  computed: {
    selectedCount() {
      return Object.keys(this.selected).length;
    },
    pageSelected() {
      return this.downloads.length > 0 && this.downloads.every((d) => this.selected[d.id]);
    },
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

    startSelecting() {
      this.selecting = true;
      this.selected = {};
    },

    stopSelecting() {
      this.selecting = false;
      this.selected = {};
    },

    toggleSelected(download) {
      const { [download.id]: wasSelected, ...rest } = this.selected;
      this.selected = wasSelected ? rest : { ...this.selected, [download.id]: true };
    },

    togglePage() {
      const selected = { ...this.selected };
      const select = !this.pageSelected;
      for (const d of this.downloads) {
        if (select) selected[d.id] = true;
        else delete selected[d.id];
      }
      this.selected = selected;
    },

    /** The browser saves the ZIP itself, like the single-file download */
    downloadZip() {
      const ids = Object.keys(this.selected);
      if (!ids.length) return;
      const link = document.createElement('a');
      link.href = downloadAPI.getZipUrl(ids);
      link.download = '';
      document.body.appendChild(link);
      link.click();
      link.remove();
      notify(`Preparing a ZIP of ${ids.length} song${ids.length === 1 ? '' : 's'}… it will download in a moment.`, 'success');
      this.stopSelecting();
    },

    /** Play everything matching the current search/filter, not just this page */
    async playEverything() {
      this.startingAll = true;
      const [sortBy, sortDir] = this.sort.split(':');
      try {
        const { data } = await historyAPI.getAll({
          search: this.search || undefined,
          format: this.format || undefined,
          sort_by: sortBy,
          sort_dir: sortDir,
          per_page: 100,
        });
        playAll(data.data);
      } catch (error) {
        notify(errorMessage(error, 'Could not load the songs'), 'error');
      } finally {
        this.startingAll = false;
      }
    },

    isPlaying(download) {
      return isCurrent(download) && player.playing;
    },

    onSaved(updated) {
      this.editing = null;
      this.downloads = this.downloads.map((d) => (d.id === updated.id ? updated : d));
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
