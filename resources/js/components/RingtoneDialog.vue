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
      <section
        class="card w-full min-w-0 max-w-xl !rounded-b-none p-5 animate-fade-up sm:!rounded-2xl"
        style="box-shadow: var(--md-elev-2)"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ringtone-title"
      >
        <div class="flex items-start gap-3">
          <span class="btn-filled grid h-11 w-11 shrink-0 place-items-center rounded-xl">
            <AppIcon :path="mdiBellRingOutline" :size="22" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 id="ringtone-title" class="text-base font-semibold text-fg">Make a ringtone</h2>
            <p class="truncate text-xs text-fg-muted" :title="download.title">{{ download.title }}</p>
          </div>
          <button type="button" class="icon-btn -mr-2 -mt-1" aria-label="Close" @click="close">
            <AppIcon :path="mdiClose" />
          </button>
        </div>

        <!-- Waveform with the chosen part highlighted; drag or click to move it -->
        <div class="mt-4">
          <div class="relative h-20 select-none overflow-hidden rounded-xl bg-surface-2" style="box-shadow: var(--md-inset)">
            <svg v-if="bars.length" class="absolute inset-0 h-full w-full" :viewBox="`0 0 ${bars.length * 3} 100`" preserveAspectRatio="none" aria-hidden="true">
              <rect
                v-for="(bar, i) in bars"
                :key="i"
                :x="i * 3 + 0.5"
                :y="50 - bar / 2"
                width="2"
                :height="Math.max(2, bar)"
                rx="1"
                :style="{ fill: inSelection(i) ? 'var(--md-primary)' : 'var(--md-line-strong)' }"
              />
            </svg>
            <div v-else class="absolute inset-0 grid place-items-center text-fg-muted">
              <AppIcon :path="mdiLoading" spin />
            </div>

            <!-- Selection frame -->
            <div
              class="pointer-events-none absolute inset-y-1 rounded-lg border-2 border-primary bg-primary/10"
              :style="{ left: pct(start) + '%', width: pct(length) + '%' }"
            />
            <!-- Preview position -->
            <div v-if="previewing" class="pointer-events-none absolute inset-y-0 w-0.5 bg-fg" :style="{ left: pct(previewTime) + '%' }" />

            <input
              class="absolute inset-0 h-full w-full cursor-grab opacity-0 active:cursor-grabbing"
              type="range"
              min="0"
              :max="maxStart"
              step="0.5"
              :value="start"
              aria-label="Ringtone start"
              :aria-valuetext="`Starts at ${formatTime(start)}`"
              @input="setStart(+$event.target.value)"
            />
          </div>
          <div class="mt-1.5 flex items-center justify-between text-xs tabular-nums text-fg-muted">
            <span>0:00</span>
            <span class="font-medium text-fg">{{ formatTime(start) }} – {{ formatTime(start + length) }}</span>
            <span>{{ formatTime(duration) }}</span>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-3">
          <div>
            <span class="field-label">Length</span>
            <SegmentedControl v-model="length" label="Length" :options="lengthOptions" compact />
          </div>
          <label class="mt-5 flex cursor-pointer items-center gap-2 text-sm text-fg">
            <input v-model="fade" type="checkbox" class="checkbox" />
            Fade in and out
          </label>
        </div>

        <p class="mt-3 text-xs text-fg-muted">
          Saved as MP3. Android: copy it to the <em>Ringtones</em> folder or open it and choose “Set as ringtone”. iPhone ringtones can be up to 40 seconds.
        </p>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <button type="button" class="btn btn-tonal" :disabled="!duration" @click="togglePreview">
            <AppIcon :path="previewing ? mdiStop : mdiPlay" :size="18" />
            {{ previewing ? 'Stop' : 'Preview' }}
          </button>
          <button type="button" class="btn btn-filled" :disabled="!duration" @click="save">
            <AppIcon :path="mdiTrayArrowDown" :size="18" />
            Save ringtone (MP3)
          </button>
        </div>
      </section>
    </div>
  </Transition>
</template>

<script>
import { mdiBellRingOutline, mdiClose, mdiLoading, mdiPlay, mdiStop, mdiTrayArrowDown } from '@mdi/js';
import AppIcon from './AppIcon.vue';
import SegmentedControl from './SegmentedControl.vue';
import { pause, player } from '../player';
import { downloadAPI } from '../services/api';
import { notify } from '../ui';

const LENGTHS = [15, 20, 30, 40];

export default {
  name: 'RingtoneDialog',
  components: { AppIcon, SegmentedControl },
  props: {
    // The song to cut; null closes the dialog
    download: { type: Object, default: null },
  },
  emits: ['close'],
  data() {
    return {
      peaks: [],
      start: 0,
      length: 30,
      fade: true,
      audio: null,
      previewing: false,
      previewTime: 0,
      lengthOptions: LENGTHS.map((s) => ({ value: s, label: `${s}s` })),
      mdiBellRingOutline,
      mdiClose,
      mdiLoading,
      mdiPlay,
      mdiStop,
      mdiTrayArrowDown,
    };
  },
  computed: {
    duration() {
      return this.download?.duration || 0;
    },
    maxStart() {
      return Math.max(0, this.duration - this.length);
    },
    // At most ~120 bars, each the loudest point it covers
    bars() {
      const source = this.peaks;
      if (!source.length) return [];
      const count = Math.min(120, source.length);
      return Array.from({ length: count }, (_, i) => {
        const from = Math.floor((i * source.length) / count);
        const to = Math.max(from + 1, Math.floor(((i + 1) * source.length) / count));
        return Math.max(...source.slice(from, to));
      });
    },
  },
  watch: {
    download: {
      async handler(download) {
        this.stopPreview();
        this.audio = null;
        if (!download) return;
        this.peaks = Array.isArray(download.waveform) ? download.waveform : [];
        this.length = this.duration >= 30 ? 30 : LENGTHS[0];
        this.start = this.loudestStart();
        if (!this.peaks.length) {
          try {
            const { data } = await downloadAPI.waveform(download.id);
            if (download === this.download) {
              this.peaks = data.waveform || [];
              this.start = this.loudestStart();
            }
          } catch {
            // The selection still works without a waveform
          }
        }
      },
      immediate: true,
    },
    length() {
      this.setStart(this.start);
    },
  },
  beforeUnmount() {
    this.stopPreview();
  },
  methods: {
    /** Start of the loudest stretch (usually the chorus): a good first guess */
    loudestStart() {
      const peaks = this.peaks;
      if (!peaks.length || !this.duration || this.duration <= this.length) return 0;
      const window = Math.max(1, Math.round((this.length / this.duration) * peaks.length));
      // Skip the very start and end (intros, outros)
      const from = Math.floor(peaks.length * 0.1);
      const to = Math.max(from + 1, peaks.length - window - Math.floor(peaks.length * 0.05));
      let best = from;
      let bestSum = -1;
      for (let i = from; i < to; i++) {
        let sum = 0;
        for (let j = i; j < i + window && j < peaks.length; j++) sum += peaks[j];
        if (sum > bestSum) {
          bestSum = sum;
          best = i;
        }
      }
      return Math.min(this.maxStart, Math.round((best / peaks.length) * this.duration));
    },
    setStart(value) {
      this.start = Math.min(this.maxStart, Math.max(0, value));
      if (this.previewing) this.playPreview();
    },
    inSelection(i) {
      const time = ((i + 0.5) / this.bars.length) * this.duration;
      return time >= this.start && time <= this.start + this.length;
    },
    pct(seconds) {
      return this.duration ? Math.min(100, (seconds / this.duration) * 100) : 0;
    },
    togglePreview() {
      if (this.previewing) this.stopPreview();
      else this.playPreview();
    },
    playPreview() {
      // One thing playing at a time
      if (player.playing) pause();
      if (!this.audio) {
        this.audio = new Audio(this.download.download_url);
        this.audio.addEventListener('timeupdate', () => {
          this.previewTime = this.audio.currentTime;
          if (this.audio.currentTime >= this.start + this.length) this.stopPreview();
        });
      }
      this.audio.currentTime = this.start;
      this.audio.play().catch(() => this.stopPreview());
      this.previewing = true;
    },
    stopPreview() {
      this.audio?.pause();
      this.previewing = false;
    },
    save() {
      this.stopPreview();
      const link = document.createElement('a');
      link.href = downloadAPI.getRingtoneUrl(this.download.id, { start: this.start, length: this.length, fade: this.fade });
      link.download = '';
      document.body.appendChild(link);
      link.click();
      link.remove();
      notify('Making your ringtone… it will download in a moment.', 'success');
    },
    close() {
      this.stopPreview();
      this.audio = null;
      this.$emit('close');
    },
    formatTime(seconds) {
      const m = Math.floor((seconds || 0) / 60);
      const s = Math.floor((seconds || 0) % 60).toString().padStart(2, '0');
      return `${m}:${s}`;
    },
  },
};
</script>
