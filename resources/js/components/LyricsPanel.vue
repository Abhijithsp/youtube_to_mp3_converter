<template>
  <section
    class="card pointer-events-auto mx-auto mb-2 flex max-h-[42dvh] max-w-5xl flex-col !rounded-2xl animate-fade-up md:max-h-80"
    style="box-shadow: var(--md-elev-2)"
    aria-label="Lyrics"
  >
    <header class="flex items-center gap-2 border-b border-line px-4 py-2">
      <AppIcon :path="mdiMicrophoneVariant" :size="18" class="text-primary" />
      <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-fg">
        Lyrics <span class="font-normal text-fg-muted">· {{ track.title }}</span>
      </h2>
      <span v-if="lines.length" class="chip !bg-primary-soft !text-on-primary-soft">Synced</span>
      <button type="button" class="icon-btn !h-8 !w-8" title="Close lyrics" aria-label="Close lyrics" @click="$emit('close')">
        <AppIcon :path="mdiClose" :size="18" />
      </button>
    </header>

    <div ref="scroller" class="lyrics-scroll min-h-24 flex-1 overflow-y-auto px-4 py-3" @wheel.passive="userScrolled" @touchmove.passive="userScrolled">
      <div v-if="state === 'loading'" class="grid h-24 place-items-center text-fg-muted">
        <AppIcon :path="mdiLoading" spin />
      </div>

      <!-- Timed lines: the current one is highlighted; click a line to jump there -->
      <ol v-else-if="lines.length" class="space-y-1 py-[30%]">
        <li v-for="(line, i) in lines" :key="i" :ref="(el) => (lineEls[i] = el)">
          <button
            type="button"
            class="w-full rounded-lg px-2 py-0.5 text-left text-base font-semibold leading-snug transition-[color,opacity,transform] duration-300 hover:bg-surface-2 md:text-lg"
            :class="i === activeIndex ? 'scale-[1.02] text-primary' : i < activeIndex ? 'text-fg-muted opacity-60' : 'text-fg-muted'"
            :title="`Play from ${formatTime(line.time)}`"
            @click="$emit('seek', line.time)"
          >
            {{ line.text || '♪' }}
          </button>
        </li>
      </ol>

      <p v-else-if="state === 'found'" class="whitespace-pre-line text-sm leading-relaxed text-fg">{{ data.lyrics }}</p>

      <div v-else class="flex flex-col items-center gap-2 py-6 text-center text-sm text-fg-muted">
        <p>{{ emptyText }}</p>
        <button v-if="state !== 'instrumental'" type="button" class="btn btn-tonal btn-sm" :disabled="loading" @click="load(true)">
          <AppIcon :path="mdiRefresh" :size="18" />
          Search again
        </button>
      </div>
    </div>

    <footer class="px-4 pb-2 text-[10px] text-fg-muted">Lyrics from <a href="https://lrclib.net" target="_blank" rel="noopener" class="underline">LRCLIB</a></footer>
  </section>
</template>

<script>
import { mdiClose, mdiLoading, mdiMicrophoneVariant, mdiRefresh } from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { downloadAPI } from '../services/api';
import { errorMessage } from '../ui';

// Lyrics already loaded this visit: download id → { status, lyrics, synced }
const cache = new Map();

/** "[01:23.45] text" lines (a line may carry several times) → [{ time, text }] sorted by time */
export function parseLrc(lrc) {
  const lines = [];
  for (const raw of String(lrc || '').split(/\r?\n/)) {
    const times = [...raw.matchAll(/\[(\d+):(\d+(?:\.\d+)?)\]/g)];
    if (!times.length) continue;
    const text = raw.replace(/\[[^\]]*\]/g, '').trim();
    for (const [, m, s] of times) lines.push({ time: Number(m) * 60 + Number(s), text });
  }
  return lines.sort((a, b) => a.time - b.time);
}

export default {
  name: 'LyricsPanel',
  components: { AppIcon },
  props: {
    track: { type: Object, required: true },
    currentTime: { type: Number, default: 0 },
  },
  emits: ['close', 'seek'],
  data() {
    return {
      data: null,
      error: '',
      loading: false,
      lineEls: [],
      // Auto-scroll pauses for a moment after the user scrolls by hand
      manualUntil: 0,
      mdiClose,
      mdiLoading,
      mdiMicrophoneVariant,
      mdiRefresh,
    };
  },
  computed: {
    state() {
      if (this.loading && !this.data) return 'loading';
      if (this.error) return 'error';
      return this.data?.status || 'loading';
    },
    lines() {
      return this.state === 'found' ? parseLrc(this.data?.synced) : [];
    },
    activeIndex() {
      // Slightly ahead, so the line lights up as it is sung
      const now = this.currentTime + 0.3;
      let index = -1;
      for (let i = 0; i < this.lines.length && this.lines[i].time <= now; i++) index = i;
      return index;
    },
    emptyText() {
      if (this.state === 'error') return this.error;
      if (this.state === 'instrumental') return 'This is an instrumental track.';
      return 'No lyrics found for this song.';
    },
  },
  watch: {
    'track.id': {
      handler() {
        this.lineEls = [];
        this.load();
      },
      immediate: true,
    },
    activeIndex(index) {
      if (index < 0 || Date.now() < this.manualUntil) return;
      this.lineEls[index]?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    },
  },
  methods: {
    async load(refresh = false) {
      const id = this.track.id;
      this.error = '';

      // Downloads include their lyrics once they have been looked up
      if (!refresh && this.track.lyrics_status) {
        this.data = { status: this.track.lyrics_status, lyrics: this.track.lyrics, synced: this.track.synced_lyrics };
        return;
      }
      if (!refresh && cache.has(id)) {
        this.data = cache.get(id);
        return;
      }

      this.data = null;
      this.loading = true;
      try {
        const { data } = await downloadAPI.lyrics(id, refresh);
        cache.set(id, data);
        if (id === this.track.id) this.data = data;
      } catch (error) {
        if (id === this.track.id) this.error = errorMessage(error, 'Could not load lyrics.');
      } finally {
        this.loading = false;
      }
    },
    userScrolled() {
      this.manualUntil = Date.now() + 4000;
    },
    formatTime(seconds) {
      const m = Math.floor(seconds / 60);
      const s = Math.floor(seconds % 60).toString().padStart(2, '0');
      return `${m}:${s}`;
    },
  },
};
</script>

<style scoped>
/* Lines fade out at the top and bottom edges */
.lyrics-scroll {
  mask-image: linear-gradient(transparent, #000 12%, #000 88%, transparent);
}
</style>
