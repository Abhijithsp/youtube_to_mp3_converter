<template>
  <Transition
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
    enter-active-class="transition duration-150"
    leave-active-class="transition duration-100"
  >
    <div
      v-if="open"
      class="fixed inset-0 z-50 grid place-items-end bg-black/50 sm:place-items-center sm:p-4"
      @click.self="$emit('close')"
      @keydown.esc="$emit('close')"
    >
      <section
        class="card flex max-h-[85dvh] w-full min-w-0 max-w-xl flex-col !rounded-b-none animate-fade-up sm:!rounded-2xl"
        style="box-shadow: var(--md-elev-2)"
        role="dialog"
        aria-modal="true"
        aria-labelledby="media-picker-title"
      >
        <!-- Header -->
        <div class="flex items-center gap-2 px-4 pb-2 pt-4">
          <h2 id="media-picker-title" class="flex-1 text-base font-semibold text-fg">Choose files on this computer</h2>
          <button type="button" class="icon-btn -mr-2" aria-label="Close" @click="$emit('close')">
            <AppIcon :path="mdiClose" />
          </button>
        </div>

        <!-- Places -->
        <div v-if="places.length" class="flex gap-1.5 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:flex-wrap">
          <button
            v-for="place in places"
            :key="place.path"
            type="button"
            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border border-line px-3 text-xs font-medium text-fg-muted transition-colors hover:bg-surface-2 hover:text-fg"
            :class="samePath(place.path, current) && '!border-transparent !bg-primary-soft !text-on-primary-soft'"
            :title="place.path"
            @click="go(place.path)"
          >
            <AppIcon :path="placeIcon(place)" :size="16" />
            {{ place.label }}
          </button>
        </div>

        <!-- Current path -->
        <div class="mx-4 flex items-center gap-1 rounded-xl bg-surface-2 py-1 pl-1 pr-3">
          <button type="button" class="icon-btn !h-8 !w-8" :disabled="!parent || loading" aria-label="Up one folder" title="Up one folder" @click="go(parent)">
            <AppIcon :path="mdiArrowUp" :size="18" />
          </button>
          <p class="min-w-0 flex-1 truncate font-mono text-xs text-fg" :title="current" dir="rtl">
            <bdi>{{ current || '…' }}</bdi>
          </p>
        </div>

        <!-- Folders and files -->
        <div class="mt-2 min-h-48 flex-1 overflow-y-auto px-2" :aria-busy="loading">
          <div v-if="loading && !folders.length && !files.length" class="grid h-40 place-items-center text-fg-muted">
            <AppIcon :path="mdiLoading" spin />
          </div>
          <p v-else-if="error" class="px-2 py-6 text-center text-sm text-danger">{{ error }}</p>
          <p v-else-if="!folders.length && !files.length" class="px-2 py-6 text-center text-sm text-fg-muted">No folders or video/audio files here.</p>
          <ul v-else :class="loading && 'opacity-60'">
            <li v-for="folder in folders" :key="folder.path">
              <button
                type="button"
                class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left text-sm text-fg hover:bg-surface-2"
                @click="go(folder.path)"
              >
                <AppIcon :path="mdiFolder" :size="20" class="shrink-0 text-primary" />
                <span class="min-w-0 flex-1 truncate">{{ folder.name }}</span>
                <AppIcon :path="mdiChevronRight" :size="18" class="shrink-0 text-fg-muted" />
              </button>
            </li>
            <li v-for="file in files" :key="file.path">
              <label class="flex w-full cursor-pointer items-center gap-3 rounded-xl px-2 py-2 text-left text-sm text-fg hover:bg-surface-2">
                <input v-model="picked" type="checkbox" class="checkbox shrink-0" :value="file.path" :disabled="!picked.includes(file.path) && picked.length >= max" />
                <AppIcon :path="file.kind === 'video' ? mdiFileVideoOutline : mdiFileMusicOutline" :size="20" class="shrink-0 text-fg-muted" />
                <span class="min-w-0 flex-1 truncate" :title="file.name">{{ file.name }}</span>
                <span class="shrink-0 text-xs tabular-nums text-fg-muted">{{ formatBytes(file.size) }}</span>
              </label>
            </li>
          </ul>
        </div>

        <!-- Actions -->
        <div class="mt-2 flex items-center gap-1 border-t border-line px-3 py-3 sm:gap-2 sm:px-4">
          <button v-if="files.length" type="button" class="btn btn-text btn-sm" @click="toggleAll">
            <AppIcon :path="allPicked ? mdiCheckboxMultipleBlankOutline : mdiCheckboxMultipleMarkedOutline" :size="18" />
            <span class="hidden sm:inline">{{ allPicked ? 'Unselect all' : 'Select all here' }}</span>
            <span class="sm:hidden">{{ allPicked ? 'None' : 'All' }}</span>
          </button>
          <span class="flex-1" />
          <button type="button" class="btn btn-text" @click="$emit('close')">Cancel</button>
          <button type="button" class="btn btn-filled" :disabled="!picked.length" @click="$emit('select', picked)">
            <AppIcon :path="mdiCheck" :size="18" />
            {{ picked.length ? `Add ${picked.length}` : 'Add' }}
          </button>
        </div>
      </section>
    </div>
  </Transition>
</template>

<script>
import {
  mdiArrowUp,
  mdiCheck,
  mdiCheckboxMultipleBlankOutline,
  mdiCheckboxMultipleMarkedOutline,
  mdiChevronRight,
  mdiClose,
  mdiFileMusicOutline,
  mdiFileVideoOutline,
  mdiFolder,
  mdiFolderDownloadOutline,
  mdiFolderHomeOutline,
  mdiFolderMusicOutline,
  mdiFolderOutline,
  mdiFolderPlayOutline,
  mdiHarddisk,
  mdiLoading,
} from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { filesAPI } from '../services/api';
import { errorMessage, formatBytes, readStorage, writeStorage } from '../ui';

/**
 * Picks video and audio files on the computer running the app, to convert them
 * where they are (nothing is uploaded). The last folder is remembered.
 */
export default {
  name: 'MediaFilePicker',
  components: { AppIcon },
  props: {
    open: { type: Boolean, default: false },
    // Most files that can be picked at once
    max: { type: Number, default: 50 },
  },
  emits: ['close', 'select'],
  data() {
    return {
      current: '',
      parent: null,
      folders: [],
      files: [],
      places: [],
      picked: [],
      loading: false,
      error: '',
      mdiArrowUp,
      mdiCheck,
      mdiCheckboxMultipleBlankOutline,
      mdiCheckboxMultipleMarkedOutline,
      mdiChevronRight,
      mdiClose,
      mdiFileMusicOutline,
      mdiFileVideoOutline,
      mdiFolder,
      mdiLoading,
    };
  },
  computed: {
    allPicked() {
      return this.files.length > 0 && this.files.every((f) => this.picked.includes(f.path));
    },
  },
  watch: {
    open(open) {
      if (open) {
        this.picked = [];
        this.go(readStorage('convert-folder') || '');
      }
    },
  },
  methods: {
    formatBytes,

    async go(path) {
      this.loading = true;
      this.error = '';
      try {
        const { data } = await filesAPI.browse(path || '');
        Object.assign(this, {
          current: data.path,
          parent: data.parent,
          folders: data.folders,
          files: data.files,
          places: data.places,
        });
        writeStorage('convert-folder', data.path);
      } catch (error) {
        this.error = errorMessage(error, 'Could not open this folder');
      } finally {
        this.loading = false;
      }
    },

    toggleAll() {
      const here = this.files.map((f) => f.path);
      this.picked = this.allPicked
        ? this.picked.filter((p) => !here.includes(p))
        : [...new Set([...this.picked, ...here])].slice(0, this.max);
    },

    samePath(a, b) {
      return String(a).toLowerCase() === String(b).toLowerCase();
    },

    placeIcon(place) {
      if (place.drive) return mdiHarddisk;
      return {
        Home: mdiFolderHomeOutline,
        Music: mdiFolderMusicOutline,
        Videos: mdiFolderPlayOutline,
        Downloads: mdiFolderDownloadOutline,
        'Download folder': mdiFolderDownloadOutline,
      }[place.label] || mdiFolderOutline;
    },
  },
};
</script>
