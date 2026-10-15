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
        aria-labelledby="folder-picker-title"
      >
        <!-- Header -->
        <div class="flex items-center gap-2 px-4 pb-2 pt-4">
          <h2 id="folder-picker-title" class="flex-1 text-base font-semibold text-fg">Choose download folder</h2>
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
          <span v-if="current && !writable" class="chip shrink-0 !bg-warning-soft !text-warning">Read-only</span>
        </div>

        <!-- Folders -->
        <div class="mt-2 min-h-48 flex-1 overflow-y-auto px-2" :aria-busy="loading">
          <div v-if="loading && !folders.length" class="grid h-40 place-items-center text-fg-muted">
            <AppIcon :path="mdiLoading" spin />
          </div>
          <p v-else-if="error" class="px-2 py-6 text-center text-sm text-danger">{{ error }}</p>
          <p v-else-if="!folders.length" class="px-2 py-6 text-center text-sm text-fg-muted">No folders here.</p>
          <ul v-else>
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
          </ul>
        </div>

        <!-- New folder -->
        <form v-if="creating" class="mx-4 mt-2 flex items-start gap-2" @submit.prevent="createFolder">
          <div class="flex-1">
            <input
              ref="newName"
              v-model="newName"
              class="field !h-9 !text-sm"
              :class="createError && 'field-error'"
              placeholder="New folder name"
              aria-label="New folder name"
              maxlength="120"
            />
            <p v-if="createError" class="field-hint !text-danger">{{ createError }}</p>
          </div>
          <button type="submit" class="btn btn-tonal btn-sm !h-9" :disabled="!newName.trim() || busy">Create</button>
          <button type="button" class="btn btn-text btn-sm !h-9" @click="creating = false">Cancel</button>
        </form>

        <!-- Actions -->
        <div class="mt-2 flex items-center gap-1 border-t border-line px-3 py-3 sm:gap-2 sm:px-4">
          <button v-if="!creating" type="button" class="btn btn-text btn-sm" :disabled="!current || !writable" @click="startCreate">
            <AppIcon :path="mdiFolderPlusOutline" :size="18" />
            <span class="hidden sm:inline">New folder</span>
            <span class="sm:hidden">New</span>
          </button>
          <span class="flex-1" />
          <button type="button" class="btn btn-text" @click="$emit('close')">Cancel</button>
          <button type="button" class="btn btn-filled" :disabled="!current || !writable" :title="writable ? '' : 'The app cannot write to this folder'" @click="$emit('select', current)">
            <AppIcon :path="mdiCheck" :size="18" />
            Use this folder
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
  mdiChevronRight,
  mdiClose,
  mdiFolder,
  mdiFolderDownloadOutline,
  mdiFolderHomeOutline,
  mdiFolderMusicOutline,
  mdiFolderOutline,
  mdiFolderPlusOutline,
  mdiHarddisk,
  mdiLoading,
} from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { settingsAPI } from '../services/api';
import { errorMessage } from '../ui';

/**
 * Browses folders on the machine the app runs on (your PC, or the server when hosted)
 */
export default {
  name: 'FolderPicker',
  components: { AppIcon },
  props: {
    open: { type: Boolean, default: false },
    // Folder to start in
    start: { type: String, default: '' },
  },
  emits: ['close', 'select'],
  data() {
    return {
      current: '',
      parent: null,
      writable: true,
      folders: [],
      places: [],
      loading: false,
      error: '',
      creating: false,
      newName: '',
      createError: '',
      busy: false,
      mdiArrowUp,
      mdiCheck,
      mdiChevronRight,
      mdiClose,
      mdiFolder,
      mdiFolderPlusOutline,
      mdiLoading,
    };
  },
  watch: {
    open(open) {
      if (open) this.go(this.start);
    },
  },
  methods: {
    async go(path) {
      this.loading = true;
      this.error = '';
      this.creating = false;
      try {
        const { data } = await settingsAPI.folders(path || '');
        Object.assign(this, {
          current: data.path,
          parent: data.parent,
          writable: data.writable,
          folders: data.folders,
          places: data.places,
        });
      } catch (error) {
        this.error = errorMessage(error, 'Could not open this folder');
      } finally {
        this.loading = false;
      }
    },

    startCreate() {
      this.creating = true;
      this.newName = '';
      this.createError = '';
      this.$nextTick(() => this.$refs.newName?.focus());
    },

    async createFolder() {
      this.busy = true;
      this.createError = '';
      try {
        const { data } = await settingsAPI.createFolder(this.current, this.newName.trim());
        await this.go(data.path);
      } catch (error) {
        this.createError = errorMessage(error, 'Could not create the folder');
      } finally {
        this.busy = false;
      }
    },

    samePath(a, b) {
      return String(a).toLowerCase() === String(b).toLowerCase();
    },

    placeIcon(place) {
      if (place.drive) return mdiHarddisk;
      return {
        Home: mdiFolderHomeOutline,
        Music: mdiFolderMusicOutline,
        'Download folder': mdiFolderDownloadOutline,
      }[place.label] || mdiFolderOutline;
    },
  },
};
</script>
