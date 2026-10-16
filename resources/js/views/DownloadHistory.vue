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
            <!-- Tablet/desktop: every action as a button -->
            <div class="hidden items-center gap-0.5 sm:flex">
              <button type="button" class="icon-btn" title="Edit tags" aria-label="Edit tags" @click="editing = d">
                <AppIcon :path="mdiPencilOutline" />
              </button>
              <button type="button" class="icon-btn" title="Make ringtone" aria-label="Make ringtone" @click="ringtoneFor = d">
                <AppIcon :path="mdiBellRingOutline" />
              </button>
              <button v-if="canOpenFolder" type="button" class="icon-btn" title="Show in folder" aria-label="Show in folder" @click="reveal(d)">
                <AppIcon :path="mdiFolderOpenOutline" />
              </button>
              <a :href="d.download_url" class="icon-btn" title="Save file" aria-label="Save file">
                <AppIcon :path="mdiTrayArrowDown" />
              </a>
              <button type="button" class="icon-btn" title="Delete" aria-label="Delete" @click="remove(d)">
                <AppIcon :path="mdiDeleteOutline" />
              </button>
            </div>
            <!-- Phone: the rest behind one button, so titles keep their room -->
            <button type="button" class="icon-btn sm:hidden" aria-label="More actions" aria-haspopup="dialog" @click="actionsFor = d">
              <AppIcon :path="mdiDotsVertical" />
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
    <RingtoneDialog :download="ringtoneFor" @close="ringtoneFor = null" />

    <!-- Phone actions sheet -->
    <Transition
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
      enter-active-class="transition duration-150"
      leave-active-class="transition duration-100"
    >
      <div v-if="actionsFor" class="fixed inset-0 z-50 grid place-items-end bg-black/50" @click.self="actionsFor = null" @keydown.esc="actionsFor = null">
        <section class="card w-full !rounded-b-none px-2 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-2 animate-fade-up" role="dialog" aria-modal="true" :aria-label="`Actions for ${actionsFor.title}`">
          <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-line-strong" aria-hidden="true" />
          <p class="truncate px-3 pb-2 text-sm font-semibold text-fg">{{ actionsFor.title }}</p>
          <button v-for="action in sheetActions" :key="action.label" type="button" class="flex w-full items-center gap-4 rounded-xl px-3 py-3 text-left text-sm text-fg hover:bg-surface-2" :class="action.danger && '!text-danger'" @click="runSheetAction(action)">
            <AppIcon :path="action.icon" :size="22" :class="action.danger ? 'text-danger' : 'text-fg-muted'" />
            {{ action.label }}
          </button>
        </section>
      </div>
    </Transition>
  </div>
</template>

<script>
import {
  mdiBellRingOutline,
  mdiCheckboxMultipleBlankOutline,
  mdiCheckboxMultipleMarkedOutline,
  mdiChevronLeft,
  mdiChevronRight,
  mdiClose,
  mdiFolderOpenOutline,
  mdiFolderZipOutline,
  mdiDeleteOutline,
  mdiDotsVertical,
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
import RingtoneDialog from '../components/RingtoneDialog.vue';
import TagEditorDialog from '../components/TagEditorDialog.vue';
import { downloadAPI, historyAPI } from '../services/api';
import { forget, isCurrent, player, playAll, stop, toggle } from '../player';
import { confirmDialog, errorMessage, notify } from '../ui';

export default {
  name: 'DownloadHistory',
  components: { AppIcon, DownloadRow, EmptyState, PageHeader, RingtoneDialog, TagEditorDialog },
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
      ringtoneFor: null,
      // The server says whether this browser is on the same computer (folder button)
      canOpenFolder: false,
      // Song whose actions sheet is open (phone)
      actionsFor: null,
      // Selection for the ZIP download: id → true, kept across pages and searches
      selecting: false,
      selected: {},
      mdiBellRingOutline,
      mdiCheckboxMultipleBlankOutline,
      mdiCheckboxMultipleMarkedOutline,
      mdiChevronLeft,
      mdiChevronRight,
      mdiClose,
      mdiFolderOpenOutline,
      mdiFolderZipOutline,
      mdiDeleteOutline,
      mdiDotsVertical,
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
    sheetActions() {
      return [
        { label: 'Edit tags', icon: mdiPencilOutline, run: (d) => (this.editing = d) },
        { label: 'Make ringtone', icon: mdiBellRingOutline, run: (d) => (this.ringtoneFor = d) },
        { label: 'Save file', icon: mdiTrayArrowDown, run: (d) => window.location.assign(d.download_url) },
        ...(this.canOpenFolder ? [{ label: 'Show in folder', icon: mdiFolderOpenOutline, run: (d) => this.reveal(d) }] : []),
        { label: 'Delete', icon: mdiDeleteOutline, danger: true, run: (d) => this.remove(d) },
      ];
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
        this.canOpenFolder = !!data.can_open_folder;
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

    async reveal(download) {
      try {
        await downloadAPI.reveal(download.id);
      } catch (error) {
        notify(errorMessage(error, 'Could not open the folder'), 'error');
      }
    },

    runSheetAction(action) {
      const download = this.actionsFor;
      this.actionsFor = null;
      action.run(download);
    },

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
