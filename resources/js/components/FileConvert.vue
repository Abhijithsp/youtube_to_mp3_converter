<template>
  <div>
    <!-- Converting: live progress of the queued files -->
    <section v-if="downloads.length" class="card overflow-hidden animate-fade-up">
      <div class="flex items-center gap-3 border-b border-line px-4 py-3">
        <span class="tile-icon h-10 w-10 shrink-0" :class="running ? 'bg-primary-soft text-on-primary-soft' : 'bg-success-soft text-success'">
          <AppIcon :path="running ? mdiLoading : mdiCheckAll" :size="22" :spin="running" />
        </span>
        <div class="min-w-0">
          <h2 class="card-title">{{ running ? `Converting ${downloads.length} file${downloads.length === 1 ? '' : 's'}…` : summary }}</h2>
          <p class="text-xs text-fg-muted">Finished songs go to History, next to your YouTube downloads.</p>
        </div>
      </div>
      <div class="divide-y divide-line">
        <DownloadRow v-for="d in downloads" :key="d.id" :download="d">
          <template v-if="d.status === 'completed'" #actions>
            <button type="button" class="icon-btn" :title="isPlaying(d) ? 'Pause' : 'Play'" :aria-label="isPlaying(d) ? 'Pause' : 'Play'" @click="toggle(d, playable)">
              <AppIcon :path="isPlaying(d) ? mdiPauseCircle : mdiPlayCircleOutline" />
            </button>
          </template>
        </DownloadRow>
      </div>
      <div class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3">
        <button v-if="!running && originals.length" type="button" class="btn btn-text btn-sm text-danger" @click="askAboutOriginals">
          <AppIcon :path="mdiFileRemoveOutline" :size="18" />
          Delete originals…
        </button>
        <button type="button" class="btn btn-outlined btn-sm" :disabled="running" @click="resetAll">
          <AppIcon :path="mdiPlus" :size="18" />
          Convert more
        </button>
        <router-link to="/history" class="btn btn-filled btn-sm">
          <AppIcon :path="mdiHistory" :size="18" />
          Open history
        </router-link>
      </div>
    </section>

    <template v-else>
      <!-- Add files -->
      <section
        class="card relative p-4 transition-shadow"
        :class="dragging && 'ring-2 ring-primary'"
        @dragenter.prevent="dragging = true"
        @dragover.prevent="dragging = true"
        @dragleave.self="dragging = false"
        @drop.prevent="onDrop"
      >
        <div class="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed border-line px-4 py-6 text-center">
          <span class="tile-icon h-12 w-12 bg-primary-soft text-on-primary-soft">
            <AppIcon :path="mdiFileUploadOutline" :size="26" />
          </span>
          <div>
            <p class="text-sm font-medium text-fg">Drop video or audio files here</p>
            <p class="mt-1 text-xs text-fg-muted">MP4, MKV, MOV, AVI, WEBM, WAV, FLAC, M4A, OGG, WMA and more · up to {{ maxUploadText }} each</p>
          </div>
          <div class="flex flex-wrap justify-center gap-2">
            <button type="button" class="btn btn-filled btn-sm" @click="$refs.picker.click()">
              <AppIcon :path="mdiPaperclip" :size="18" />
              Choose files
            </button>
            <button v-if="options.local_files" type="button" class="btn btn-tonal btn-sm" @click="pickingLocal = true">
              <AppIcon :path="mdiMonitor" :size="18" />
              From this PC
            </button>
          </div>
          <p v-if="options.local_files" class="text-xs text-fg-muted">
            "From this PC" converts files where they are, with nothing copied first: best for big videos.
          </p>
        </div>
        <input ref="picker" type="file" multiple class="hidden" :accept="accept" @change="onPicked" />
      </section>

      <!-- Files -->
      <section v-if="items.length" class="card mt-3 overflow-hidden animate-fade-up">
        <ul class="divide-y divide-line">
          <li v-for="item in items" :key="item.key" class="px-3 py-2.5 sm:px-4">
            <div class="flex items-center gap-3">
              <span class="tile-icon h-10 w-10 shrink-0" :class="item.status === 'error' ? 'bg-danger-soft text-danger' : 'bg-surface-3 text-fg-muted'">
                <AppIcon :path="item.status === 'error' ? mdiAlertCircle : item.info?.has_video ? mdiFileVideoOutline : mdiFileMusicOutline" :size="22" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-fg" :title="item.name">{{ item.status === 'ready' ? item.tags.title || item.name : item.name }}</p>
                <p v-if="item.status === 'error'" class="mt-0.5 line-clamp-2 text-xs text-danger">{{ item.error }}</p>
                <p v-else class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-fg-muted">
                  <span class="chip shrink-0 !normal-case !tracking-normal">
                    <AppIcon :path="item.kind === 'local' ? mdiMonitor : mdiFileUploadOutline" :size="13" />
                    {{ item.kind === 'local' ? 'On this PC' : 'Upload' }}
                  </span>
                  <span class="truncate">{{ itemMeta(item) }}</span>
                </p>
                <LinearProgress v-if="item.status === 'uploading'" class="mt-2" :value="item.progress" />
              </div>
              <button v-if="item.status === 'ready'" type="button" class="icon-btn" :aria-expanded="item.expanded" title="Edit tags and trim" aria-label="Edit tags and trim" @click="item.expanded = !item.expanded">
                <AppIcon :path="item.expanded ? mdiChevronUp : mdiPencilOutline" />
              </button>
              <button type="button" class="icon-btn" title="Remove" aria-label="Remove" :disabled="submitting" @click="remove(item)">
                <AppIcon :path="mdiClose" />
              </button>
            </div>

            <!-- Tags and trim for this file -->
            <div v-if="item.expanded && item.status === 'ready'" class="mt-3 grid gap-3 animate-fade-up sm:grid-cols-2 sm:pl-[3.25rem]">
              <div class="sm:col-span-2">
                <label :for="`title-${item.key}`" class="field-label">Title (also the file name)</label>
                <input :id="`title-${item.key}`" v-model="item.tags.title" class="field" maxlength="255" />
              </div>
              <div>
                <label :for="`artist-${item.key}`" class="field-label">Artist</label>
                <input :id="`artist-${item.key}`" v-model="item.tags.artist" class="field" maxlength="255" />
              </div>
              <div>
                <label :for="`album-${item.key}`" class="field-label">Album</label>
                <input :id="`album-${item.key}`" v-model="item.tags.album" class="field" maxlength="255" />
              </div>
              <div>
                <label :for="`year-${item.key}`" class="field-label">Year</label>
                <input :id="`year-${item.key}`" v-model.number="item.tags.year" type="number" min="1900" max="2100" class="field" />
              </div>
              <div>
                <label :for="`genre-${item.key}`" class="field-label">Genre</label>
                <input :id="`genre-${item.key}`" v-model="item.tags.genre" class="field" maxlength="100" />
              </div>
              <div>
                <label :for="`start-${item.key}`" class="field-label">Start at</label>
                <input :id="`start-${item.key}`" v-model.trim="item.trim.start" class="field tabular-nums" :class="trimError(item) && 'field-error'" inputmode="numeric" placeholder="0:00" />
              </div>
              <div>
                <label :for="`end-${item.key}`" class="field-label">End at</label>
                <input :id="`end-${item.key}`" v-model.trim="item.trim.end" class="field tabular-nums" :class="trimError(item) && 'field-error'" inputmode="numeric" :placeholder="formatDuration(item.info.duration) || 'end'" />
              </div>
              <p v-if="trimError(item)" class="field-hint !text-danger sm:col-span-2">{{ trimError(item) }}</p>
            </div>
          </li>
        </ul>
        <p v-if="uploading" class="border-t border-line px-4 py-2.5 text-xs text-fg-muted">Keep this page open while files upload.</p>
      </section>

      <!-- Options for all files -->
      <section v-if="items.length" class="card mt-3 space-y-4 p-4 animate-fade-up">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <span class="field-label">Format</span>
            <SegmentedControl v-model="format" label="Format" :options="formatOptions" :disabled="submitting" />
          </div>
          <div>
            <span class="field-label">Bitrate</span>
            <SegmentedControl v-model="quality" label="Bitrate" :options="qualityOptions" :disabled="submitting || format !== 'mp3'" />
            <p v-if="lowSource" class="field-hint">Some files have only {{ lowSource }} kbps audio. A higher bitrate will not make them sound better.</p>
            <p v-else-if="format === 'flac'" class="field-hint">FLAC is lossless, so bitrate does not apply.</p>
            <p v-else-if="format === 'wav'" class="field-hint">WAV is uncompressed 24-bit / 48 kHz. No cover art, large files.</p>
          </div>
        </div>

        <div class="space-y-3">
          <label class="flex cursor-pointer items-start gap-3 text-sm text-fg">
            <input v-model="removeSilence" type="checkbox" class="checkbox mt-0.5" />
            <span>
              Remove silence
              <span class="block text-xs text-fg-muted">Cuts quiet parts at the start and end.</span>
            </span>
          </label>
          <label class="flex cursor-pointer items-start gap-3 text-sm text-fg">
            <input v-model="normalizeVolume" type="checkbox" class="checkbox mt-0.5" />
            <span>
              Even out volume
              <span class="block text-xs text-fg-muted">Plays at the same loudness as your other songs.</span>
            </span>
          </label>
        </div>

        <button type="button" class="btn btn-filled w-full" :disabled="!canConvert" @click="convert">
          <AppIcon :path="submitting ? mdiLoading : mdiSwapHorizontal" :size="18" :spin="submitting" />
          {{ convertLabel }}
        </button>
      </section>
    </template>

    <MediaFilePicker :open="pickingLocal" :max="maxFiles - items.length" @close="pickingLocal = false" @select="addLocal" />
  </div>
</template>

<script>
import { markRaw } from 'vue';
import {
  mdiAlertCircle,
  mdiCheckAll,
  mdiChevronUp,
  mdiClose,
  mdiFileMusicOutline,
  mdiFileRemoveOutline,
  mdiFileUploadOutline,
  mdiFileVideoOutline,
  mdiHistory,
  mdiLoading,
  mdiMonitor,
  mdiPaperclip,
  mdiPauseCircle,
  mdiPencilOutline,
  mdiPlayCircleOutline,
  mdiPlus,
  mdiSwapHorizontal,
} from '@mdi/js';
import AppIcon from './AppIcon.vue';
import DownloadRow from './DownloadRow.vue';
import LinearProgress from './LinearProgress.vue';
import MediaFilePicker from './MediaFilePicker.vue';
import SegmentedControl from './SegmentedControl.vue';
import { isCurrent, player, toggle } from '../player';
import { downloadAPI, filesAPI } from '../services/api';
import { confirmDialog, errorMessage, formatBytes, formatDuration, notify, parseTime, readStorage, writeStorage } from '../ui';

let nextKey = 1;

// ffprobe codec names → what people know them as
function codecName(codec) {
  if (codec.startsWith('pcm_')) return 'PCM';
  if (codec.startsWith('wmav')) return 'WMA';
  return { vorbis: 'Vorbis', opus: 'Opus', alac: 'ALAC', ac3: 'AC-3', eac3: 'E-AC-3' }[codec] || codec.toUpperCase();
}

/**
 * Converts the user's own video and audio files. Uploads go one at a time, in
 * pieces, so the app stays responsive; files on this computer are converted in place.
 */
export default {
  name: 'FileConvert',
  components: { AppIcon, DownloadRow, LinearProgress, MediaFilePicker, SegmentedControl },
  data() {
    return {
      options: { local_files: false, max_upload_mb: 4096, chunk_bytes: 8 * 1024 * 1024, max_files: 50, extensions: [] },
      items: [],
      dragging: false,
      pickingLocal: false,
      format: 'mp3',
      quality: '320',
      removeSilence: false,
      normalizeVolume: readStorage('normalize-volume') === '1',
      submitting: false,
      downloads: [],
      pollTimer: null,
      // Whether the "delete originals?" question was already asked for this batch
      asked: false,
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
      mdiCheckAll,
      mdiChevronUp,
      mdiClose,
      mdiFileMusicOutline,
      mdiFileRemoveOutline,
      mdiFileUploadOutline,
      mdiFileVideoOutline,
      mdiHistory,
      mdiLoading,
      mdiMonitor,
      mdiPaperclip,
      mdiPauseCircle,
      mdiPencilOutline,
      mdiPlayCircleOutline,
      mdiPlus,
      mdiSwapHorizontal,
    };
  },
  computed: {
    maxFiles() {
      return this.options.max_files;
    },
    accept() {
      return ['audio/*', 'video/*', ...this.options.extensions.map((ext) => `.${ext}`)].join(',');
    },
    maxUploadText() {
      const mb = this.options.max_upload_mb;
      return mb >= 1024 ? `${Math.round((mb / 1024) * 10) / 10} GB` : `${mb} MB`;
    },
    ready() {
      return this.items.filter((item) => item.status === 'ready');
    },
    uploading() {
      return this.items.some((item) => item.status === 'uploading' || item.status === 'waiting' || item.status === 'reading');
    },
    canConvert() {
      return !this.submitting && !this.uploading && this.ready.length > 0 && !this.ready.some((item) => this.trimError(item));
    },
    convertLabel() {
      if (this.submitting) return 'Starting…';
      if (this.uploading) return 'Waiting for uploads…';
      const n = this.ready.length;
      return `Convert ${n || ''} file${n === 1 ? '' : 's'} to ${this.format.toUpperCase()}`;
    },
    // Lowest audio bitrate among the files, when it is below the chosen MP3 bitrate
    lowSource() {
      if (this.format !== 'mp3') return null;
      const rates = this.ready.map((item) => item.info.bitrate).filter((rate) => rate && rate < 400);
      const lowest = rates.length ? Math.min(...rates) : null;
      return lowest && lowest < Number(this.quality) - 16 ? lowest : null;
    },
    running() {
      return this.downloads.some((d) => ['pending', 'processing'].includes(d.status));
    },
    playable() {
      return this.downloads.filter((d) => d.status === 'completed');
    },
    // Converted files from this computer whose original is still there
    originals() {
      return this.downloads.filter((d) => d.status === 'completed' && d.original_available);
    },
    summary() {
      const done = this.playable.length;
      const failed = this.downloads.filter((d) => d.status === 'failed').length;
      return `${done} converted${failed ? `, ${failed} failed` : ''}`;
    },
  },
  watch: {
    normalizeVolume(on) {
      writeStorage('normalize-volume', on ? '1' : '0');
    },
  },
  async mounted() {
    try {
      const { data } = await filesAPI.options();
      this.options = data;
    } catch (error) {
      // Defaults: uploads only
    }
  },
  beforeUnmount() {
    clearInterval(this.pollTimer);
    // Leaving the page stops unfinished uploads; finished ones wait a day on the server
    for (const item of [...this.items]) {
      if (item.status === 'uploading' || item.status === 'waiting') this.remove(item);
    }
  },
  methods: {
    formatDuration,
    toggle,

    isPlaying(download) {
      return isCurrent(download) && player.playing;
    },

    itemMeta(item) {
      if (item.status === 'waiting') return `${formatBytes(item.size)} · Waiting to upload…`;
      if (item.status === 'uploading') return `Uploading ${item.progress}% of ${formatBytes(item.size)}`;
      if (item.status === 'reading') return 'Reading file…';
      const info = item.info || {};
      const parts = [];
      if (info.duration) parts.push(formatDuration(info.duration));
      if (info.codec) parts.push(`${codecName(info.codec)}${info.bitrate ? ` ${info.bitrate} kbps` : ''}`);
      if (info.has_video) parts.push('video');
      if (info.audio_tracks > 1) parts.push(`${info.audio_tracks} audio tracks (the main one is used)`);
      if (item.size) parts.push(formatBytes(item.size));
      return parts.join(' · ');
    },

    trimError(item) {
      const start = parseTime(item.trim.start);
      const end = parseTime(item.trim.end);
      if (Number.isNaN(start) || Number.isNaN(end)) return 'Use times like 1:30 or 90.';
      if (end !== null && end <= (start || 0)) return 'The end must be after the start.';
      if (item.info?.duration && (start || 0) >= item.info.duration) return `The file is only ${formatDuration(item.info.duration)} long.`;
      return '';
    },

    newItem(fields) {
      return {
        key: nextKey++,
        progress: 0,
        info: null,
        error: '',
        expanded: false,
        tags: { title: '', artist: '', album: '', year: null, genre: '' },
        trim: { start: '', end: '' },
        ...fields,
      };
    },

    applyInfo(item, info) {
      item.info = info;
      item.size = info.size || item.size;
      item.tags = { title: info.title || '', artist: info.artist || '', album: info.album || '', year: info.year || null, genre: info.genre || '' };
      item.status = info.error ? 'error' : 'ready';
      item.error = info.error || '';
    },

    roomFor(count) {
      const room = this.maxFiles - this.items.length;
      if (count > room) notify(`At most ${this.maxFiles} files at a time; ${count - Math.max(room, 0)} were left out.`);
      return Math.max(room, 0);
    },

    onPicked(event) {
      this.addUploads([...event.target.files]);
      event.target.value = '';
    },

    onDrop(event) {
      this.dragging = false;
      this.addUploads([...(event.dataTransfer?.files || [])]);
    },

    addUploads(files) {
      const maxBytes = this.options.max_upload_mb * 1024 * 1024;
      for (const file of files.slice(0, this.roomFor(files.length))) {
        const tooBig = file.size > maxBytes;
        this.items.push(
          this.newItem({
            kind: 'upload',
            name: file.name,
            size: file.size,
            file: markRaw(file),
            status: tooBig || !file.size ? 'error' : 'waiting',
            error: tooBig ? `Too large: files can be at most ${this.maxUploadText}.` : !file.size ? 'This file is empty.' : '',
          }),
        );
      }
      this.uploadNext();
    },

    async addLocal(paths) {
      this.pickingLocal = false;
      const known = new Set(this.items.map((item) => item.path));
      const fresh = paths.filter((path) => !known.has(path));
      const added = fresh.slice(0, this.roomFor(fresh.length)).map((path) =>
        this.newItem({ kind: 'local', name: path.split(/[\\/]/).pop(), path, size: 0, status: 'reading' }),
      );
      if (!added.length) return;
      this.items.push(...added);

      try {
        const { data } = await filesAPI.inspect(added.map((item) => item.path));
        // Items may have been removed meanwhile, so look them up again
        data.files.forEach((info, i) => {
          const item = this.items.find((it) => it.key === added[i].key);
          if (item) this.applyInfo(item, info);
        });
      } catch (error) {
        for (const item of added) {
          const live = this.items.find((it) => it.key === item.key);
          if (live) Object.assign(live, { status: 'error', error: errorMessage(error, 'Could not read the file') });
        }
      }
    },

    /** Uploads go one at a time; the next starts when one finishes */
    async uploadNext() {
      if (this.items.some((item) => item.status === 'uploading')) return;
      const item = this.items.find((it) => it.status === 'waiting');
      if (!item) return;

      item.status = 'uploading';
      item.abort = new AbortController();
      try {
        const { data } = await filesAPI.startUpload(item.name, item.size);
        item.uploadId = data.id;
        const chunk = data.chunk_bytes || this.options.chunk_bytes;
        let offset = 0;
        let failures = 0;

        while (item.status === 'uploading') {
          const end = Math.min(offset + chunk, item.size);
          try {
            const response = await filesAPI.uploadChunk(item.uploadId, offset, item.file.slice(offset, end), {
              signal: item.abort.signal,
              onUploadProgress: (event) => {
                item.progress = Math.min(99, Math.floor(((offset + (event.loaded || 0)) / item.size) * 100));
              },
            });
            failures = 0;
            offset = response.data.received;
            if (response.data.done) {
              this.applyInfo(item, response.data.file);
              break;
            }
          } catch (error) {
            if (item.abort.signal.aborted) return;
            // The server has a different amount of the file: continue from there
            if (error.response?.status === 409 && Number.isInteger(error.response.data?.received)) {
              offset = error.response.data.received;
              continue;
            }
            // Not a video/audio file, or the upload expired
            if (error.response && error.response.status < 500) {
              Object.assign(item, { status: 'error', error: errorMessage(error, 'The upload failed') });
              break;
            }
            // Network hiccup or busy server: try the same piece again
            if (++failures >= 4) throw error;
            await new Promise((resolve) => setTimeout(resolve, 1000 * failures));
          }
        }
      } catch (error) {
        if (!item.abort?.signal.aborted) {
          Object.assign(item, { status: 'error', error: errorMessage(error, 'The upload failed. Check the connection and add the file again.') });
        }
      } finally {
        item.abort = null;
        this.uploadNext();
      }
    },

    remove(item) {
      item.abort?.abort();
      if (item.uploadId) filesAPI.cancelUpload(item.uploadId).catch(() => {});
      item.status = 'removed';
      this.items = this.items.filter((it) => it.key !== item.key);
      this.uploadNext();
    },

    async convert() {
      this.submitting = true;
      try {
        const items = this.ready.map((item) => {
          const start = parseTime(item.trim.start);
          const end = parseTime(item.trim.end);
          return {
            ...(item.kind === 'upload' ? { upload_id: item.uploadId } : { path: item.path }),
            title: item.tags.title || null,
            artist: item.tags.artist || null,
            album: item.tags.album || null,
            year: item.tags.year || null,
            genre: item.tags.genre || null,
            trim_start: start || null,
            trim_end: end && end < (item.info.duration || Infinity) ? end : null,
          };
        });
        const { data } = await filesAPI.convert({
          items,
          format: this.format,
          quality: this.quality,
          remove_silence: this.removeSilence,
          normalize_volume: this.normalizeVolume,
        });
        this.downloads = data.downloads;
        this.items = [];
        this.asked = false;
        notify(data.message, 'success');
        this.startPolling();
      } catch (error) {
        notify(errorMessage(error, 'Could not start the conversion'), 'error');
      } finally {
        this.submitting = false;
      }
    },

    startPolling() {
      clearInterval(this.pollTimer);
      this.pollTimer = setInterval(this.refresh, 1500);
    },

    async refresh() {
      try {
        const ids = this.downloads.map((d) => d.id);
        const { data } = await downloadAPI.getAll({ ids: ids.join(','), per_page: 100 });
        const byId = Object.fromEntries(data.data.map((d) => [d.id, d]));
        // Downloads deleted meanwhile (e.g. from History) drop out of the list
        this.downloads = this.downloads.filter((d) => byId[d.id]).map((d) => byId[d.id]);

        if (!this.running) {
          clearInterval(this.pollTimer);
          this.pollTimer = null;
          const failed = this.downloads.filter((d) => d.status === 'failed').length;
          notify(failed ? `Conversion finished: ${this.summary}.` : 'Conversion finished.', failed ? 'error' : 'success');
          if (this.originals.length && !this.asked) this.askAboutOriginals();
        }
      } catch (error) {
        // Try again at the next tick (the server may be busy converting)
      }
    },

    /** Originals are only deleted when the user says so, after the conversion */
    async askAboutOriginals() {
      this.asked = true;
      const files = this.originals;
      if (!files.length) return;

      const list = files.slice(0, 8).map((d) => `• ${d.source_name}`).join('\n') + (files.length > 8 ? `\n…and ${files.length - 8} more` : '');
      const ok = await confirmDialog({
        title: files.length === 1 ? 'Delete the original file?' : `Delete the ${files.length} original files?`,
        message: `Converted from this computer:\n${list}\n\nThe converted songs stay in History either way.`,
        confirmLabel: files.length === 1 ? 'Delete original' : 'Delete originals',
        cancelLabel: 'Keep them',
        danger: true,
      });
      if (!ok) return;

      let deleted = 0;
      const problems = [];
      for (const download of files) {
        try {
          const { data } = await downloadAPI.deleteOriginal(download.id);
          this.downloads = this.downloads.map((d) => (d.id === download.id ? data.download : d));
          deleted++;
        } catch (error) {
          problems.push(`${download.source_name}: ${errorMessage(error, 'could not be deleted')}`);
        }
      }
      if (problems.length) notify(problems.join('\n'), 'error');
      if (deleted) notify(`Deleted ${deleted} original file${deleted === 1 ? '' : 's'}.`, 'success');
    },

    resetAll() {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
      this.downloads = [];
      this.items = [];
    },
  },
};
</script>
