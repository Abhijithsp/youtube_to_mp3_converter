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
      <!-- Phone: format and source side by side, sort below -->
      <div class="grid grid-cols-2 gap-2 sm:flex">
        <select v-model="format" class="field sm:w-36" aria-label="Format" @change="fetchHistory(1)">
          <option value="">All formats</option>
          <option value="mp3">MP3</option>
          <option value="flac">FLAC</option>
          <option value="wav">WAV</option>
        </select>
        <select v-model="source" class="field sm:w-40" aria-label="Source" @change="fetchHistory(1)">
          <option value="">All sources</option>
          <option value="youtube">YouTube</option>
          <option value="files">Local files</option>
        </select>
        <select v-model="sort" class="field col-span-2 sm:w-40" aria-label="Sort" @change="fetchHistory(1)">
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
        :icon="filtered ? mdiMagnifyClose : mdiHistory"
        :title="filtered ? 'No matches' : 'No downloads yet'"
        :text="filtered ? 'Try a different search or filter.' : 'Finished downloads are listed here.'"
      />

      <div v-else class="song-list" :class="loading && 'opacity-60'">
        <DownloadRow
          v-for="d in downloads"
          :key="d.id"
          :download="d"
          :show-status="false"
          :selectable="selecting"
          :selected="!!selected[d.id]"
          @toggle="toggleSelected(d)"
          @contextmenu="!selecting && openActions(d, $event)"
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
            <!-- Play and Save stay in view; on phones Save moves into the sheet so titles keep their room -->
            <a :href="d.download_url" class="icon-btn !hidden sm:!inline-flex" title="Save file" aria-label="Save file">
              <AppIcon :path="mdiTrayArrowDown" />
            </a>
            <button
              type="button"
              class="icon-btn"
              :class="actionsFor?.id === d.id && '!bg-surface-3'"
              title="More actions"
              aria-label="More actions"
              aria-haspopup="menu"
              :aria-expanded="actionsFor?.id === d.id"
              @click="openActions(d, $event)"
            >
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

    <!-- Song actions: a menu next to the button on wider screens, a bottom sheet on phones -->
    <Transition
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
      enter-active-class="transition duration-150"
      leave-active-class="transition duration-100"
    >
      <div
        v-if="actionsFor"
        class="fixed inset-0 z-50"
        :class="menuAt ? '' : 'grid place-items-end bg-black/50'"
        @click.self="closeActions"
        @contextmenu.self.prevent="closeActions"
      >
        <section
          ref="actionsMenu"
          :class="menuAt
            ? 'card fixed w-56 !rounded-xl p-1 !shadow-[var(--md-elev-2)]'
            : 'card w-full !rounded-b-none px-2 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-2 animate-fade-up'"
          :style="menuAt && { top: `${menuAt.top}px`, left: `${menuAt.left}px` }"
          role="menu"
          :aria-label="`Actions for ${actionsFor.title}`"
          tabindex="-1"
          @keydown.esc="closeActions"
          @keydown.down.prevent="moveFocus(1)"
          @keydown.up.prevent="moveFocus(-1)"
        >
          <template v-if="!menuAt">
            <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-line-strong" aria-hidden="true" />
            <p class="truncate px-3 pb-2 text-sm font-semibold text-fg">{{ actionsFor.title }}</p>
          </template>
          <template v-for="action in sheetActions" :key="action.label">
            <div v-if="action.divider" class="mx-2 my-1 border-t border-line" role="separator" />
            <button
              type="button"
              role="menuitem"
              class="flex w-full items-center text-left text-sm text-fg hover:bg-surface-2 focus-visible:bg-surface-2 focus-visible:outline-none"
              :class="[menuAt ? 'gap-3 rounded-lg px-3 py-2' : 'gap-4 rounded-xl px-3 py-3', action.danger && '!text-danger']"
              @click="runSheetAction(action)"
            >
              <AppIcon :path="action.icon" :size="menuAt ? 20 : 22" :class="action.danger ? 'text-danger' : 'text-fg-muted'" />
              {{ action.label }}
            </button>
          </template>
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
  mdiFileRemoveOutline,
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
      // '' | youtube | files (converted from the user's own files)
      source: '',
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
      // Song whose actions are open; menuAt is where the desktop menu sits (null: phone sheet)
      actionsFor: null,
      menuAt: null,
      // Selection for the ZIP download: id → true, kept across pages and searches
      selecting: false,
      selected: {},
      mdiBellRingOutline,
      mdiCheckboxMultipleBlankOutline,
      mdiCheckboxMultipleMarkedOutline,
      mdiChevronLeft,
      mdiChevronRight,
      mdiClose,
      mdiFileRemoveOutline,
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
    filtered() {
      return !!(this.search || this.format || this.source);
    },
    selectedCount() {
      return Object.keys(this.selected).length;
    },
    sheetActions() {
      return [
        { label: 'Edit tags', icon: mdiPencilOutline, run: (d) => (this.editing = d) },
        { label: 'Make ringtone', icon: mdiBellRingOutline, run: (d) => (this.ringtoneFor = d) },
        // On wider screens Save has its own button in the row
        ...(this.menuAt ? [] : [{ label: 'Save file', icon: mdiTrayArrowDown, run: (d) => window.location.assign(d.download_url) }]),
        ...(this.canOpenFolder ? [{ label: 'Show in folder', icon: mdiFolderOpenOutline, run: (d) => this.reveal(d) }] : []),
        // Deleting comes last, set apart, so it is not hit by accident
        ...(this.actionsFor?.original_available ? [{ label: 'Delete original file', icon: mdiFileRemoveOutline, danger: true, divider: true, run: (d) => this.deleteOriginal(d) }] : []),
        { label: 'Delete', icon: mdiDeleteOutline, danger: true, divider: !this.actionsFor?.original_available, run: (d) => this.remove(d) },
      ];
    },
    pageSelected() {
      return this.downloads.length > 0 && this.downloads.every((d) => this.selected[d.id]);
    },
  },
  created() {
    // The ⋮ button that opened the actions; not reactive, only used to give focus back
    this.opener = null;
  },
  mounted() {
    this.fetchHistory();
  },
  beforeUnmount() {
    clearTimeout(this.searchTimer);
    this.closeActions();
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
          source: this.source || undefined,
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

    /** From the ⋮ button, or a right-click on the row (wider screens only, phones keep the long-press menu) */
    async openActions(download, event) {
      const wide = window.matchMedia('(min-width: 640px)').matches;
      if (event.type === 'contextmenu') {
        if (!wide) return;
        event.preventDefault();
      }

      const opener = event.type === 'contextmenu' ? null : event.currentTarget;
      // Where the menu hangs from: the ⋮ button, or the pointer for a right-click
      const anchor = opener
        ? opener.getBoundingClientRect()
        : { top: event.clientY, bottom: event.clientY, right: event.clientX + 224 };

      this.closeActions();
      this.opener = opener;
      this.actionsFor = download;

      if (wide) {
        this.menuAt = { top: anchor.bottom + 4, left: anchor.right - 224 };
        window.addEventListener('resize', this.closeActions);
        window.addEventListener('scroll', this.closeActions, true);
      }

      await this.$nextTick();
      const menu = this.$refs.actionsMenu;
      if (!menu) return;

      if (this.menuAt) {
        // Keep it on screen: open upwards when there is no room below, never past the edges
        const margin = 8;
        const { offsetWidth: width, offsetHeight: height } = menu;
        let { top, left } = this.menuAt;
        if (top + height > window.innerHeight - margin) top = anchor.top - height - 4;
        this.menuAt = {
          top: Math.max(margin, top),
          left: Math.min(Math.max(margin, left), window.innerWidth - width - margin),
        };
      }

      menu.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
    },

    closeActions() {
      window.removeEventListener('resize', this.closeActions);
      window.removeEventListener('scroll', this.closeActions, true);
      if (!this.actionsFor) return;
      this.actionsFor = null;
      this.menuAt = null;
      // Back to the ⋮ button, so keyboard users keep their place
      this.opener?.focus?.({ preventScroll: true });
      this.opener = null;
    },

    moveFocus(step) {
      const items = [...(this.$refs.actionsMenu?.querySelectorAll('[role="menuitem"]') ?? [])];
      if (!items.length) return;
      const index = items.indexOf(document.activeElement);
      items[(index + step + items.length) % items.length].focus();
    },

    runSheetAction(action) {
      const download = this.actionsFor;
      this.closeActions();
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
          source: this.source || undefined,
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

    /** The file a conversion was made from; only deleted when the user asks */
    async deleteOriginal(download) {
      const ok = await confirmDialog({
        title: 'Delete the original file?',
        message: `"${download.source_name}" will be deleted from this computer. The converted ${download.format.toUpperCase()} stays in History.`,
        confirmLabel: 'Delete original',
        cancelLabel: 'Keep it',
        danger: true,
      });
      if (!ok) return;
      try {
        const { data } = await downloadAPI.deleteOriginal(download.id);
        notify(data.message, 'success');
        this.downloads = this.downloads.map((d) => (d.id === download.id ? data.download : d));
      } catch (error) {
        notify(errorMessage(error, 'Could not delete the original'), 'error');
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
