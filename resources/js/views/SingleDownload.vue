<template>
  <div class="mx-auto max-w-2xl">
    <PageHeader title="Download" subtitle="Paste a YouTube link, or search, and save it as MP3, FLAC or WAV.">
      <SegmentedControl v-model="mode" label="Mode" compact :options="modeOptions" :disabled="busy" />
    </PageHeader>

    <!-- Several links at once -->
    <template v-if="mode === 'bulk'">
      <section v-if="!bulkResult" class="card space-y-4 p-4 animate-fade-up">
        <div>
          <label for="bulk-links" class="field-label">YouTube links</label>
          <textarea
            id="bulk-links"
            ref="bulk"
            v-model="bulkText"
            rows="7"
            class="field scroll-thin !h-auto resize-y py-3 font-mono !text-sm leading-relaxed"
            placeholder="Paste one or more links, one per line (or separated by spaces)…"
            spellcheck="false"
          />
          <p class="field-hint">
            <template v-if="bulkLinks.length">
              {{ bulkLinks.length }} link{{ bulkLinks.length === 1 ? '' : 's' }} found<template v-if="bulkLinks.length > bulkLimit">, only the first {{ bulkLimit }} are queued</template>.
              Playlist links are skipped; use the Playlist page for those.
            </template>
            <template v-else>Up to {{ bulkLimit }} links. Anything that is not a YouTube link is ignored.</template>
          </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <span class="field-label">Format</span>
            <SegmentedControl v-model="format" label="Format" :options="formatOptions" :disabled="submitting" />
          </div>
          <div>
            <span class="field-label">Bitrate</span>
            <SegmentedControl v-model="quality" label="Bitrate" :options="qualityOptions" :disabled="submitting || format !== 'mp3'" />
          </div>
        </div>

        <button type="button" class="btn btn-filled w-full" :disabled="submitting || !bulkLinks.length" @click="submitBulk">
          <AppIcon :path="submitting ? mdiLoading : mdiPlaylistPlus" :size="18" :spin="submitting" />
          {{ submitting ? 'Queueing…' : `Queue ${Math.min(bulkLinks.length, bulkLimit) || ''} download${bulkLinks.length === 1 ? '' : 's'}` }}
        </button>
      </section>

      <section v-else class="card overflow-hidden animate-fade-up">
        <div class="flex items-center gap-3 border-b border-line px-4 py-3">
          <span class="tile-icon h-10 w-10 shrink-0" :class="bulkResult.queued.length ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'">
            <AppIcon :path="bulkResult.queued.length ? mdiCheckAll : mdiAlertCircle" :size="22" />
          </span>
          <div class="min-w-0">
            <h2 class="card-title">{{ bulkResult.queued.length }} queued<template v-if="bulkResult.skipped.length">, {{ bulkResult.skipped.length }} skipped</template></h2>
            <p class="text-xs text-fg-muted">Downloads run one after another; follow them in the queue.</p>
          </div>
        </div>
        <ul v-if="bulkResult.skipped.length" class="divide-y divide-line">
          <li v-for="item in bulkResult.skipped" :key="item.url" class="flex items-center gap-3 px-4 py-2.5">
            <AppIcon :path="mdiSkipNextCircleOutline" :size="20" class="text-fg-muted" />
            <span class="min-w-0 flex-1 truncate font-mono text-xs text-fg" :title="item.url">{{ item.url }}</span>
            <span class="chip shrink-0 !normal-case !tracking-normal">{{ item.reason }}</span>
          </li>
        </ul>
        <div class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3">
          <button type="button" class="btn btn-outlined btn-sm" @click="resetBulk">
            <AppIcon :path="mdiPlus" :size="18" />
            Add more
          </button>
          <router-link v-if="bulkResult.queued.length" to="/queue" class="btn btn-filled btn-sm">
            <AppIcon :path="mdiTrayFull" :size="18" />
            Open queue
          </router-link>
        </div>
      </section>
    </template>

    <template v-else>
    <!-- Link or search -->
    <form class="card p-4" @submit.prevent="submitInput">
      <label for="url" class="field-label">YouTube link or search</label>
      <div class="flex gap-2">
        <div class="relative flex-1">
          <AppIcon
            :path="isUrl ? mdiLink : mdiMagnify"
            :size="20"
            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-fg-muted"
          />
          <input
            id="url"
            ref="url"
            v-model.trim="input"
            type="text"
            inputmode="search"
            enterkeyhint="search"
            autocomplete="off"
            class="field pl-10 pr-10"
            :class="inputError && 'field-error'"
            placeholder="Paste a link, or type a song name"
            :disabled="busy"
            @input="onInput"
          />
          <button
            v-if="input && !busy"
            type="button"
            class="icon-btn absolute right-1 top-1/2 -translate-y-1/2"
            aria-label="Clear"
            @click="reset"
          >
            <AppIcon :path="mdiClose" :size="18" />
          </button>
        </div>
        <button v-if="input && !isUrl" type="submit" class="btn btn-filled shrink-0" :disabled="searching || busy">
          <AppIcon :path="searching ? mdiLoading : mdiMagnify" :size="18" :spin="searching" />
          <span class="hidden sm:inline">Search</span>
        </button>
        <button v-else-if="canPaste && !input" type="button" class="btn btn-tonal shrink-0" @click="paste">
          <AppIcon :path="mdiContentPaste" :size="18" />
          <span class="hidden sm:inline">Paste</span>
        </button>
      </div>
      <p v-if="inputError" class="mt-2 flex items-start gap-1.5 whitespace-pre-line text-sm text-danger">
        <AppIcon :path="mdiAlertCircle" :size="16" class="mt-0.5" />
        <span>{{ inputError }}</span>
      </p>
      <p v-else-if="input && !isUrl && !results.length && !searching" class="mt-2 text-xs text-fg-muted">Press Enter to search YouTube.</p>

      <!-- Loading preview -->
      <div v-if="fetching" class="mt-4 flex gap-3">
        <div class="aspect-video w-36 animate-pulse rounded-lg bg-surface-3 sm:w-44" />
        <div class="flex-1 space-y-2 py-1">
          <div class="h-4 w-4/5 animate-pulse rounded bg-surface-3" />
          <div class="h-3 w-1/3 animate-pulse rounded bg-surface-3" />
          <p class="pt-1 text-xs text-fg-muted">Looking up the video…</p>
        </div>
      </div>

      <!-- Preview -->
      <div v-else-if="video" class="mt-4 animate-fade-up">
        <div class="flex gap-3">
          <div class="relative aspect-video w-36 shrink-0 overflow-hidden rounded-lg bg-surface-3 shadow-md sm:w-44">
            <img v-if="video.thumbnail" :src="video.thumbnail" alt="" class="h-full w-full object-cover" />
            <span v-if="video.duration" class="absolute bottom-1 right-1 rounded bg-black/75 px-1 text-[11px] font-medium text-white">
              {{ formatDuration(video.duration) }}
            </span>
          </div>
          <div class="min-w-0 py-0.5">
            <p class="line-clamp-2 text-sm font-medium text-fg">{{ video.title }}</p>
            <p v-if="video.uploader" class="mt-1 truncate text-xs text-fg-muted">{{ video.uploader }}</p>
            <button v-if="results.length && !download" type="button" class="btn btn-text btn-sm -ml-3 mt-1" @click="backToResults">
              <AppIcon :path="mdiArrowLeft" :size="16" />
              Back to results
            </button>
          </div>
        </div>
      </div>
    </form>

    <!-- Search results -->
    <section v-if="!video && !fetching && (searching || results.length)" class="card mt-3 overflow-hidden animate-fade-up">
      <div v-if="searching" class="divide-y divide-line">
        <div v-for="n in 4" :key="n" class="flex items-center gap-3 px-4 py-3">
          <div class="aspect-video w-28 animate-pulse rounded-lg bg-surface-3" />
          <div class="flex-1 space-y-2">
            <div class="h-3 w-3/4 animate-pulse rounded bg-surface-3" />
            <div class="h-3 w-1/3 animate-pulse rounded bg-surface-3" />
          </div>
        </div>
      </div>
      <ul v-else class="divide-y divide-line">
        <li v-for="result in results" :key="result.id">
          <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-surface-2 sm:px-4" @click="choose(result)">
            <div class="relative aspect-video w-28 shrink-0 overflow-hidden rounded-lg bg-surface-3 shadow-sm sm:w-32">
              <img :src="result.thumbnail" alt="" loading="lazy" class="h-full w-full object-cover" />
              <span v-if="result.duration" class="absolute bottom-0.5 right-0.5 rounded bg-black/75 px-1 text-[10px] font-medium text-white">
                {{ formatDuration(result.duration) }}
              </span>
            </div>
            <div class="min-w-0 flex-1">
              <p class="line-clamp-2 text-sm font-medium text-fg">{{ result.title }}</p>
              <p class="mt-0.5 truncate text-xs text-fg-muted">
                {{ result.channel }}<template v-if="result.views"> · {{ formatViews(result.views) }} views</template>
              </p>
            </div>
            <AppIcon :path="mdiChevronRight" class="text-fg-muted" />
          </button>
        </li>
      </ul>
    </section>

    <!-- Options -->
    <section v-if="video && !download" class="card mt-3 space-y-4 p-4 animate-fade-up">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <span class="field-label">Format</span>
          <SegmentedControl v-model="format" label="Format" :options="formatOptions" :disabled="submitting" />
        </div>
        <div>
          <span class="field-label">Bitrate</span>
          <SegmentedControl v-model="quality" label="Bitrate" :options="qualityOptions" :disabled="submitting || format !== 'mp3'" />
          <p v-if="format === 'flac'" class="field-hint">FLAC is lossless, so bitrate does not apply.</p>
          <p v-else-if="format === 'wav'" class="field-hint">WAV is uncompressed 24-bit / 48 kHz. No cover art, large files.</p>
        </div>
      </div>

      <!-- Trim / silence -->
      <Disclosure v-model="showEdit" label="Edit audio" :badge="editSummary">
        <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <div>
            <label for="trim-start" class="field-label">Start at</label>
            <input
              id="trim-start"
              v-model.trim="trim.start"
              class="field tabular-nums"
              :class="trimError && 'field-error'"
              inputmode="numeric"
              placeholder="0:00"
            />
          </div>
          <div>
            <label for="trim-end" class="field-label">End at</label>
            <input
              id="trim-end"
              v-model.trim="trim.end"
              class="field tabular-nums"
              :class="trimError && 'field-error'"
              inputmode="numeric"
              :placeholder="formatDuration(video.duration) || 'end'"
            />
          </div>
          <label class="flex h-11 cursor-pointer items-center gap-3 rounded-xl bg-surface-2 px-3 text-sm text-fg" style="box-shadow: var(--md-inset)">
            <input v-model="removeSilence" type="checkbox" class="checkbox" />
            Remove silence
          </label>
        </div>
        <p v-if="trimError" class="field-hint !text-danger">{{ trimError }}</p>
        <p v-else class="field-hint">
          Times as m:ss (e.g. 0:15). Silence removal cuts quiet parts at the start and end.
          <template v-if="keptLength"> The file will be about {{ formatDuration(keptLength) }} long.</template>
        </p>
      </Disclosure>

      <!-- Tags -->
      <Disclosure v-model="showTags" label="Edit tags" :badge="tagsSummary">
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label for="title" class="field-label">Title (also the file name)</label>
            <input id="title" v-model="tags.title" class="field" maxlength="255" />
          </div>
          <div>
            <label for="artist" class="field-label">Artist</label>
            <input id="artist" v-model="tags.artist" class="field" maxlength="255" :placeholder="video.uploader || ''" />
          </div>
          <div>
            <label for="album" class="field-label">Album</label>
            <input id="album" v-model="tags.album" class="field" maxlength="255" />
          </div>
          <div>
            <label for="year" class="field-label">Year</label>
            <input id="year" v-model.number="tags.year" type="number" min="1900" max="2100" class="field" />
          </div>
          <div>
            <label for="genre" class="field-label">Genre</label>
            <input id="genre" v-model="tags.genre" class="field" maxlength="100" placeholder="e.g. Hardstyle" />
          </div>
        </div>
      </Disclosure>

      <button type="button" class="btn btn-filled w-full" :disabled="submitting || !!trimError" @click="submit">
        <AppIcon :path="submitting ? mdiLoading : mdiTrayArrowDown" :size="18" :spin="submitting" />
        {{ submitting ? 'Starting…' : `Download ${format.toUpperCase()}` }}
      </button>
    </section>

    <!-- Progress / result -->
    <section v-if="download" class="card mt-3 overflow-hidden animate-fade-up">
      <DownloadRow :download="download" />

      <div class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3">
        <button v-if="isActive" type="button" class="btn btn-outlined btn-sm" @click="cancel">
          <AppIcon :path="mdiStopCircleOutline" :size="18" />
          Cancel
        </button>
        <button v-if="canRetry" type="button" class="btn btn-tonal btn-sm" @click="retry">
          <AppIcon :path="mdiRefresh" :size="18" />
          Retry
        </button>
        <button v-if="!isActive" type="button" class="btn btn-outlined btn-sm" @click="reset">
          <AppIcon :path="mdiPlus" :size="18" />
          Another
        </button>
        <button v-if="download.status === 'completed'" type="button" class="btn btn-tonal btn-sm" @click="toggle(download)">
          <AppIcon :path="isPlaying ? mdiPause : mdiPlay" :size="18" />
          {{ isPlaying ? 'Pause' : 'Play' }}
        </button>
        <a v-if="download.status === 'completed'" :href="download.download_url" class="btn btn-filled btn-sm">
          <AppIcon :path="mdiTrayArrowDown" :size="18" />
          Save file
        </a>
      </div>
    </section>
    </template>
  </div>
</template>

<script>
import {
  mdiAlertCircle,
  mdiArrowLeft,
  mdiCheckAll,
  mdiChevronRight,
  mdiClose,
  mdiContentPaste,
  mdiLink,
  mdiLoading,
  mdiMagnify,
  mdiPause,
  mdiPlay,
  mdiPlus,
  mdiRefresh,
  mdiPlaylistPlus,
  mdiSkipNextCircleOutline,
  mdiStopCircleOutline,
  mdiTrayFull,
  mdiTrayArrowDown,
} from '@mdi/js';
import AppIcon from '../components/AppIcon.vue';
import Disclosure from '../components/Disclosure.vue';
import DownloadRow from '../components/DownloadRow.vue';
import PageHeader from '../components/PageHeader.vue';
import SegmentedControl from '../components/SegmentedControl.vue';
import { isCurrent, player, toggle } from '../player';
import { downloadAPI, searchAPI } from '../services/api';
import { errorMessage, extractYoutubeLinks, formatDuration, looksLikeYoutubeUrl, notify, parseTime, YOUTUBE_URL } from '../ui';

export default {
  name: 'SingleDownload',
  components: { AppIcon, Disclosure, DownloadRow, PageHeader, SegmentedControl },
  data() {
    return {
      mode: 'single', // single | bulk
      modeOptions: [
        { value: 'single', label: 'Single link' },
        { value: 'bulk', label: 'Multiple links' },
      ],
      bulkText: '',
      bulkResult: null,
      bulkLimit: 50,
      input: '',
      inputError: '',
      results: [],
      searching: false,
      video: null,
      fetching: false,
      submitting: false,
      format: 'mp3',
      quality: '320',
      showTags: false,
      showEdit: false,
      tags: { title: '', artist: '', album: '', year: null, genre: '' },
      trim: { start: '', end: '' },
      removeSilence: false,
      download: null,
      pollTimer: null,
      debounceTimer: null,
      lookupId: 0,
      canPaste: !!navigator.clipboard?.readText,
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
      mdiArrowLeft,
      mdiCheckAll,
      mdiChevronRight,
      mdiClose,
      mdiContentPaste,
      mdiLink,
      mdiLoading,
      mdiMagnify,
      mdiPause,
      mdiPlay,
      mdiPlus,
      mdiRefresh,
      mdiPlaylistPlus,
      mdiSkipNextCircleOutline,
      mdiStopCircleOutline,
      mdiTrayFull,
      mdiTrayArrowDown,
    };
  },
  computed: {
    bulkLinks() {
      return extractYoutubeLinks(this.bulkText);
    },
    isUrl() {
      return looksLikeYoutubeUrl(this.input);
    },
    busy() {
      return this.submitting || (this.download && this.isActive);
    },
    isActive() {
      return this.download && ['pending', 'processing'].includes(this.download.status);
    },
    isPlaying() {
      return isCurrent(this.download) && player.playing;
    },
    canRetry() {
      return this.download && ['failed', 'cancelled'].includes(this.download.status);
    },
    trimStart() {
      return parseTime(this.trim.start);
    },
    trimEnd() {
      return parseTime(this.trim.end);
    },
    trimError() {
      if (Number.isNaN(this.trimStart) || Number.isNaN(this.trimEnd)) return 'Use times like 1:30 or 90.';
      const duration = this.video?.duration;
      if (this.trimEnd !== null && this.trimEnd <= (this.trimStart || 0)) return 'The end must be after the start.';
      if (duration && (this.trimStart || 0) >= duration) return `The video is only ${formatDuration(duration)} long.`;
      return '';
    },
    keptLength() {
      if (this.trimError || !this.video?.duration || (this.trimStart === null && this.trimEnd === null)) return null;
      return Math.min(this.trimEnd ?? this.video.duration, this.video.duration) - (this.trimStart || 0);
    },
    editSummary() {
      const parts = [];
      if (this.trimStart || this.trimEnd) parts.push(`${formatDuration(this.trimStart || 0)}–${this.trimEnd ? formatDuration(this.trimEnd) : 'end'}`);
      if (this.removeSilence) parts.push('no silence');
      return parts.join(' · ');
    },
    tagsSummary() {
      return this.tags.artist ? `${this.tags.artist}${this.tags.year ? ` · ${this.tags.year}` : ''}` : '';
    },
  },
  watch: {
    mode(mode) {
      if (mode === 'bulk') this.$nextTick(() => this.$refs.bulk?.focus());
    },
    // Opening a search from Home (?q=) or a link (?url=)
    '$route.query': {
      handler(query) {
        if (query.url && query.url !== this.input) {
          this.input = String(query.url);
          this.fetchVideoInfo();
        } else if (query.q && query.q !== this.input) {
          this.input = String(query.q);
          this.search();
        }
      },
      immediate: true,
    },
  },
  mounted() {
    if (!this.input) this.$refs.url?.focus();
  },
  beforeUnmount() {
    this.stopPolling();
    clearTimeout(this.debounceTimer);
  },
  methods: {
    formatDuration,
    toggle,

    formatViews(views) {
      return new Intl.NumberFormat(undefined, { notation: 'compact' }).format(views);
    },

    onInput() {
      this.inputError = '';

      // Several links pasted into the single box: switch to bulk mode with them
      const links = extractYoutubeLinks(this.input);
      if (links.length > 1) {
        this.bulkText = links.join('\n');
        this.input = '';
        this.mode = 'bulk';
        return;
      }

      clearTimeout(this.debounceTimer);
      if (this.isUrl) {
        this.video = null;
        this.debounceTimer = setTimeout(this.fetchVideoInfo, 700);
      }
    },

    submitInput() {
      this.isUrl ? this.fetchVideoInfo() : this.search();
    },

    async paste() {
      try {
        this.input = (await navigator.clipboard.readText()).trim();
        this.onInput();
      } catch {
        notify('Clipboard access was blocked. Paste with Ctrl+V instead.');
      }
    },

    async search() {
      if (this.input.length < 2) return;
      this.searching = true;
      this.inputError = '';
      this.video = null;
      this.results = [];
      try {
        const { data } = await searchAPI.search(this.input);
        this.results = data.results;
        if (!this.results.length) this.inputError = 'No videos found. Try other words.';
      } catch (error) {
        this.inputError = errorMessage(error, 'Search failed.');
      } finally {
        this.searching = false;
      }
    },

    choose(result) {
      this.fetchVideoInfo(result.url);
    },

    backToResults() {
      this.video = null;
    },

    async fetchVideoInfo(url = null) {
      clearTimeout(this.debounceTimer);
      const link = url || this.input;
      if (!link) return;

      if (!YOUTUBE_URL.test(link)) {
        this.inputError = 'This does not look like a YouTube link.';
        return;
      }
      if (/[?&]list=/.test(link) && !/[?&]v=|youtu\.be\/|\/shorts\//.test(link)) {
        this.$router.push({ path: '/playlist', query: { url: link } });
        return;
      }

      // Ignore answers to older lookups if the link changed meanwhile
      const lookupId = ++this.lookupId;
      this.fetching = true;
      this.inputError = '';

      try {
        const { data } = await downloadAPI.fetchInfo(link);
        if (lookupId !== this.lookupId) return;
        this.video = { ...data.video, url: link };
        this.tags = {
          title: data.suggested?.title || data.video.title || '',
          artist: data.suggested?.artist || data.video.artist || '',
          album: data.video.album || '',
          year: data.video.year || null,
          genre: data.video.genre || '',
        };
        this.trim = { start: '', end: '' };
        this.removeSilence = false;
      } catch (error) {
        if (lookupId !== this.lookupId) return;
        this.inputError = errorMessage(error, 'Could not load this video.');
      } finally {
        if (lookupId === this.lookupId) this.fetching = false;
      }
    },

    async submit() {
      this.submitting = true;
      try {
        const { data } = await downloadAPI.create({
          youtube_url: this.video.url,
          format: this.format,
          quality: this.quality,
          title: this.tags.title || this.video.title,
          artist: this.tags.artist || null,
          album: this.tags.album || null,
          year: this.tags.year || null,
          genre: this.tags.genre || null,
          thumbnail_url: this.video.thumbnail,
          trim_start: this.trimStart || null,
          trim_end: this.trimEnd && this.trimEnd < (this.video.duration || Infinity) ? this.trimEnd : null,
          remove_silence: this.removeSilence,
        });
        this.download = data.download;
        if (data.download.status === 'completed') {
          notify('Already downloaded in this format.', 'success');
        } else {
          this.startPolling();
        }
      } catch (error) {
        notify(errorMessage(error, 'Could not start the download'), 'error');
      } finally {
        this.submitting = false;
      }
    },

    startPolling() {
      this.stopPolling();
      this.pollTimer = setInterval(this.refresh, 1500);
    },

    stopPolling() {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    },

    async refresh() {
      try {
        const { data } = await downloadAPI.get(this.download.id);
        this.download = data.download;
        if (!this.isActive) {
          this.stopPolling();
          if (this.download.status === 'completed') notify('Download finished.', 'success');
          if (this.download.status === 'failed') notify('Download failed. See the error below.', 'error');
        }
      } catch (error) {
        this.stopPolling();
        notify(errorMessage(error, 'Lost track of the download'), 'error');
      }
    },

    async retry() {
      try {
        const { data } = await downloadAPI.retry(this.download.id);
        this.download = data.download;
        this.startPolling();
      } catch (error) {
        notify(errorMessage(error, 'Could not retry'), 'error');
      }
    },

    async cancel() {
      try {
        const { data } = await downloadAPI.cancel(this.download.id);
        this.download = data.download;
        this.stopPolling();
        notify('Download cancelled.');
      } catch (error) {
        notify(errorMessage(error, 'Could not cancel'), 'error');
      }
    },

    async submitBulk() {
      this.submitting = true;
      try {
        const { data } = await downloadAPI.bulk({
          urls: this.bulkLinks.slice(0, this.bulkLimit),
          format: this.format,
          quality: this.quality,
        });
        this.bulkResult = data;
        notify(data.message, 'success');
      } catch (error) {
        // 422 with a result means every link was skipped: show why
        if (error.response?.data?.skipped) {
          this.bulkResult = error.response.data;
        } else {
          notify(errorMessage(error, 'Could not queue the links'), 'error');
        }
      } finally {
        this.submitting = false;
      }
    },

    resetBulk() {
      this.bulkResult = null;
      this.bulkText = '';
      this.$nextTick(() => this.$refs.bulk?.focus());
    },

    reset() {
      this.stopPolling();
      clearTimeout(this.debounceTimer);
      this.lookupId++;
      this.input = '';
      this.inputError = '';
      this.results = [];
      this.video = null;
      this.fetching = false;
      this.download = null;
      this.showTags = false;
      this.showEdit = false;
      if (Object.keys(this.$route.query).length) this.$router.replace({ query: {} });
      this.$nextTick(() => this.$refs.url?.focus());
    },
  },
};
</script>
