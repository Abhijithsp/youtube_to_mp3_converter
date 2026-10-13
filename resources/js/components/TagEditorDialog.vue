<template>
  <Transition
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
    enter-active-class="transition duration-150"
    leave-active-class="transition duration-100"
  >
    <div
      v-if="download"
      class="fixed inset-0 z-50 grid place-items-end bg-black/50 sm:place-items-center sm:p-4"
      @click.self="close"
      @keydown.esc="close"
    >
      <form
        class="card w-full max-w-lg !rounded-b-none p-5 animate-fade-up sm:!rounded-2xl"
        style="box-shadow: var(--md-elev-2)"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tag-editor-title"
        @submit.prevent="save"
      >
        <div class="flex items-start gap-3">
          <div class="h-12 w-12 shrink-0 overflow-hidden rounded-xl bg-surface-3 shadow-md">
            <img v-if="download.thumbnail_url" :src="download.thumbnail_url" alt="" class="h-full w-full object-cover" />
          </div>
          <div class="min-w-0 flex-1">
            <h2 id="tag-editor-title" class="text-base font-semibold text-fg">Edit tags</h2>
            <p class="truncate text-xs text-fg-muted" :title="fileName">{{ fileName }}</p>
          </div>
          <button type="button" class="icon-btn -mr-2 -mt-1" aria-label="Close" @click="close">
            <AppIcon :path="mdiClose" />
          </button>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label for="tag-title" class="field-label">Title</label>
            <input id="tag-title" ref="title" v-model="form.title" class="field" :class="errors.title && 'field-error'" maxlength="255" required />
            <p v-if="errors.title" class="field-hint !text-danger">{{ errors.title }}</p>
          </div>
          <div>
            <label for="tag-artist" class="field-label">Artist</label>
            <input id="tag-artist" v-model="form.artist" class="field" maxlength="255" />
          </div>
          <div>
            <label for="tag-album" class="field-label">Album</label>
            <input id="tag-album" v-model="form.album" class="field" maxlength="255" />
          </div>
          <div>
            <label for="tag-year" class="field-label">Year</label>
            <input id="tag-year" v-model.number="form.year" type="number" min="1900" max="2100" class="field" :class="errors.year && 'field-error'" />
            <p v-if="errors.year" class="field-hint !text-danger">{{ errors.year }}</p>
          </div>
          <div>
            <label for="tag-genre" class="field-label">Genre</label>
            <input id="tag-genre" v-model="form.genre" class="field" maxlength="100" />
          </div>
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-3 text-sm text-fg">
          <input v-model="form.rename" type="checkbox" class="checkbox mt-0.5" />
          <span>
            Rename the file to match the title
            <span v-if="form.rename && newFileName !== fileName" class="block truncate text-xs text-fg-muted" :title="newFileName">→ {{ newFileName }}</span>
          </span>
        </label>

        <p v-if="form.artist && songTitle !== form.title" class="mt-2 text-xs text-fg-muted">
          Music players will show “{{ songTitle }}” by “{{ form.artist }}”.
        </p>

        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="btn btn-text" @click="close">Cancel</button>
          <button type="submit" class="btn btn-filled" :disabled="saving || !form.title.trim()">
            <AppIcon :path="saving ? mdiLoading : mdiContentSave" :size="18" :spin="saving" />
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </form>
    </div>
  </Transition>
</template>

<script>
import { mdiClose, mdiContentSave, mdiLoading } from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { isCurrent, stop } from '../player';
import { downloadAPI } from '../services/api';
import { errorMessage, notify } from '../ui';

/** Mirrors App\Support\FileName on the server, for the preview only */
function safeFileName(title) {
  const name = String(title || '')
    .replace(/[<>:"/\\|?*\u0000-\u001F\u007F]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/[. ]+$/, '');
  return name || 'download';
}

export default {
  name: 'TagEditorDialog',
  components: { AppIcon },
  props: {
    // The download to edit; null closes the dialog
    download: { type: Object, default: null },
  },
  emits: ['close', 'saved'],
  data() {
    return {
      form: { title: '', artist: '', album: '', year: null, genre: '', rename: true },
      errors: {},
      saving: false,
      mdiClose,
      mdiContentSave,
      mdiLoading,
    };
  },
  computed: {
    fileName() {
      return String(this.download?.file_path || '').split(/[\\/]/).pop();
    },
    newFileName() {
      return `${safeFileName(this.form.title)}.${this.download?.format}`;
    },
    songTitle() {
      // Same rule as the server: "Artist - Song" by "Artist" is tagged as "Song"
      const prefix = `${this.form.artist.trim()} - `;
      return this.form.title.toLowerCase().startsWith(prefix.toLowerCase())
        ? this.form.title.slice(prefix.length).trim()
        : this.form.title;
    },
  },
  watch: {
    download: {
      handler(download) {
        if (!download) return;
        this.form = {
          title: download.title || '',
          artist: download.artist || '',
          album: download.album || '',
          year: download.year || null,
          genre: download.genre || '',
          rename: true,
        };
        this.errors = {};
        this.$nextTick(() => this.$refs.title?.focus());
      },
      immediate: true,
    },
  },
  methods: {
    close() {
      if (!this.saving) this.$emit('close');
    },

    async save() {
      this.saving = true;
      this.errors = {};

      // A file that is playing is locked on Windows and cannot be rewritten
      if (isCurrent(this.download)) stop();

      try {
        const { data } = await downloadAPI.update(this.download.id, {
          title: this.form.title.trim(),
          artist: this.form.artist.trim() || null,
          album: this.form.album.trim() || null,
          year: this.form.year || null,
          genre: this.form.genre.trim() || null,
          rename: this.form.rename,
        });
        notify('Tags updated.', 'success');
        this.$emit('saved', data.download);
      } catch (error) {
        const errors = error.response?.data?.errors;
        if (errors) this.errors = Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]));
        notify(errorMessage(error, 'Could not update the tags'), 'error');
      } finally {
        this.saving = false;
      }
    },
  },
};
</script>
