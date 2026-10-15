<template>
  <div
    ref="root"
    class="waveform group relative w-full select-none"
    :style="{ height: height + 'px' }"
    @pointermove="onHover"
    @pointerleave="hoverX = null"
  >
    <svg
      v-if="width"
      class="absolute inset-0 overflow-visible"
      :width="width"
      :height="height"
      :viewBox="`0 0 ${width} ${height}`"
      aria-hidden="true"
    >
      <defs>
        <linearGradient :id="`${uid}-played`" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" style="stop-color: color-mix(in srgb, var(--md-primary) 65%, white)" />
          <stop offset="0.5" style="stop-color: var(--md-primary)" />
          <stop offset="1" style="stop-color: color-mix(in srgb, var(--md-primary) 65%, white)" />
        </linearGradient>
        <linearGradient :id="`${uid}-rest`" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" style="stop-color: var(--md-line-strong)" />
          <stop offset="0.5" style="stop-color: color-mix(in srgb, var(--md-line-strong) 75%, var(--md-fg) 25%)" />
          <stop offset="1" style="stop-color: var(--md-line-strong)" />
        </linearGradient>
        <clipPath :id="`${uid}-clip`">
          <rect x="0" y="0" :width="playedWidth" :height="height" />
        </clipPath>
      </defs>

      <!-- Unplayed bars, then the same bars in the accent colour clipped to the played part.
           Bars are mirrored around the centre line; silent parts draw nothing. -->
      <g :fill="`url(#${uid}-rest)`">
        <rect v-for="bar in bars" :key="`r${bar.i}`" :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h" :rx="bar.w / 2" />
      </g>
      <g :fill="`url(#${uid}-played)`" :clip-path="`url(#${uid}-clip)`" class="waveform-played">
        <rect v-for="bar in bars" :key="`p${bar.i}`" :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h" :rx="bar.w / 2" />
      </g>

      <!-- Hover preview -->
      <line
        v-if="hoverX !== null && !disabled"
        :x1="hoverX"
        :x2="hoverX"
        y1="0"
        :y2="height"
        stroke-width="1"
        style="stroke: var(--md-fg); opacity: 0.35"
      />

      <!-- Playhead -->
      <rect
        :x="Math.min(width - 2, Math.max(0, playedWidth - 1))"
        y="0"
        width="2"
        :height="height"
        rx="1"
        class="waveform-head"
        style="fill: var(--md-primary)"
      />
    </svg>

    <span
      v-if="hoverX !== null && !disabled && duration"
      class="pointer-events-none absolute -top-6 -translate-x-1/2 rounded-md px-1.5 py-0.5 text-[10px] font-medium tabular-nums shadow"
      style="background: var(--md-fg); color: var(--md-bg)"
      :style="{ left: hoverX + 'px' }"
    >
      {{ formatTime((hoverX / width) * duration) }}
    </span>

    <!-- Real (invisible) slider: click, drag, touch and keyboard seeking -->
    <input
      class="absolute inset-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-default"
      type="range"
      min="0"
      :max="duration || 0"
      step="0.1"
      :value="currentTime"
      :disabled="disabled || !duration"
      aria-label="Seek"
      :aria-valuetext="`${formatTime(currentTime)} of ${formatTime(duration)}`"
      @input="$emit('seek', +$event.target.value)"
    />
  </div>
</template>

<script>
let counter = 0;

/**
 * Seek bar drawn as the song's loudness outline (peaks are 0-100, computed once by
 * ffmpeg on the server), with the played part in the accent colour.
 */
export default {
  name: 'WaveformSeek',
  props: {
    peaks: { type: Array, required: true },
    currentTime: { type: Number, default: 0 },
    duration: { type: Number, default: 0 },
    height: { type: Number, default: 34 },
    disabled: { type: Boolean, default: false },
  },
  emits: ['seek'],
  data() {
    return {
      uid: `wf${++counter}`,
      width: 0,
      hoverX: null,
      barWidth: 2.5,
      gap: 1.5,
      observer: null,
    };
  },
  computed: {
    bars() {
      const source = this.peaks;
      if (!source.length || !this.width) return [];

      // Spread the bars over the exact width so bar positions match the playhead's time
      const count = Math.max(1, Math.floor((this.width + this.gap) / (this.barWidth + this.gap)));
      const slot = this.width / count;
      const w = Math.min(this.barWidth, slot * 0.7);
      const middle = this.height / 2;

      const bars = [];
      for (let i = 0; i < count; i++) {
        // Highest value of the source points in this bar's time span
        const from = Math.floor((i * source.length) / count);
        const to = Math.max(from + 1, Math.floor(((i + 1) * source.length) / count));
        let level = 0;
        for (let j = from; j < to; j++) level = Math.max(level, source[j] || 0);

        // Silence stays empty instead of becoming a flat line
        if (level < 1) continue;

        const h = Math.max(w, (level / 100) * (this.height - 2));
        bars.push({ i, x: i * slot + (slot - w) / 2, y: middle - h / 2, w, h });
      }
      return bars;
    },
    playedWidth() {
      return this.duration ? Math.min(1, this.currentTime / this.duration) * this.width : 0;
    },
  },
  mounted() {
    this.measure();
    this.observer = new ResizeObserver(this.measure);
    this.observer.observe(this.$refs.root);
  },
  beforeUnmount() {
    this.observer?.disconnect();
  },
  methods: {
    measure() {
      this.width = this.$refs.root?.clientWidth || 0;
    },
    onHover(event) {
      const rect = this.$refs.root.getBoundingClientRect();
      this.hoverX = Math.min(rect.width, Math.max(0, event.clientX - rect.left));
    },
    formatTime(seconds) {
      if (!seconds || !Number.isFinite(seconds)) return '0:00';
      const m = Math.floor(seconds / 60);
      const s = Math.floor(seconds % 60).toString().padStart(2, '0');
      return `${m}:${s}`;
    },
  },
};
</script>

<style scoped>
.waveform-played {
  filter: drop-shadow(0 1px 1.5px color-mix(in srgb, var(--md-primary) 45%, transparent));
}
.waveform-head {
  filter: drop-shadow(0 0 3px var(--md-primary));
}
/* Keyboard focus on the invisible slider outlines the whole waveform */
.waveform:has(input:focus-visible) {
  outline: 2px solid var(--md-primary);
  outline-offset: 3px;
  border-radius: 6px;
}
</style>
