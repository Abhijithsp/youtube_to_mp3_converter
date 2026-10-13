<template>
  <Transition
    enter-from-class="opacity-0 translate-y-4"
    leave-to-class="opacity-0 translate-y-4"
    enter-active-class="transition duration-200 ease-out"
    leave-active-class="transition duration-150 ease-in"
  >
    <div
      v-if="player.track"
      class="pointer-events-none fixed inset-x-0 z-40 px-2 bottom-[calc(4.25rem+env(safe-area-inset-bottom))] sm:px-4 md:bottom-4 md:left-20 lg:left-60"
    >
      <section
        class="card pointer-events-auto mx-auto max-w-5xl !rounded-2xl px-3 py-2.5 sm:px-4"
        style="box-shadow: var(--md-elev-2)"
        aria-label="Audio player"
      >
        <div class="flex items-center gap-3">
          <!-- Track -->
          <div class="flex min-w-0 flex-1 items-center gap-3 md:w-60 md:flex-none lg:w-72">
            <div class="relative h-11 w-11 shrink-0 overflow-hidden rounded-xl bg-surface-3 shadow-md sm:h-12 sm:w-12">
              <img v-if="track.thumbnail_url" :src="track.thumbnail_url" alt="" class="h-full w-full object-cover" />
              <div v-else class="grid h-full w-full place-items-center text-fg-muted">
                <AppIcon :path="mdiMusicNote" />
              </div>
              <div v-if="player.playing" class="absolute inset-0 flex items-end justify-center gap-[3px] bg-black/35 pb-2">
                <span v-for="n in 3" :key="n" class="eq-bar" :style="{ animationDelay: `${n * 0.15}s` }" />
              </div>
            </div>
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-fg" :title="track.title">{{ track.title }}</p>
              <p class="truncate text-xs text-fg-muted">
                <span v-if="player.queue.length > 1">{{ player.index + 1 }} of {{ player.queue.length }} · </span>{{ track.artist || 'Unknown artist' }}
              </p>
            </div>
          </div>

          <!-- Controls + seek (tablet/desktop) -->
          <div class="hidden min-w-0 flex-1 flex-col items-center gap-1 md:flex">
            <div class="flex items-center gap-1">
              <button
                type="button"
                class="icon-btn"
                :class="player.shuffle && '!text-primary'"
                :aria-pressed="player.shuffle"
                :title="player.shuffle ? 'Shuffle on' : 'Shuffle off'"
                aria-label="Shuffle"
                @click="toggleShuffle"
              >
                <AppIcon :path="player.shuffle ? mdiShuffleVariant : mdiShuffleDisabled" />
              </button>
              <button type="button" class="icon-btn" title="Previous" aria-label="Previous" @click="previous">
                <AppIcon :path="mdiSkipPrevious" />
              </button>
              <button
                type="button"
                class="btn btn-filled !h-10 !w-10 !p-0"
                :title="player.playing ? 'Pause' : 'Play'"
                :aria-label="player.playing ? 'Pause' : 'Play'"
                @click="toggle()"
              >
                <AppIcon :path="player.loading ? mdiLoading : player.playing ? mdiPause : mdiPlay" :size="24" :spin="player.loading" />
              </button>
              <button type="button" class="icon-btn" title="Next" aria-label="Next" :disabled="!hasNext()" @click="next()">
                <AppIcon :path="mdiSkipNext" />
              </button>
              <button
                type="button"
                class="icon-btn"
                :class="player.repeat !== 'off' && '!text-primary'"
                :title="repeatLabel"
                :aria-label="repeatLabel"
                @click="cycleRepeat"
              >
                <AppIcon :path="player.repeat === 'one' ? mdiRepeatOnce : player.repeat === 'all' ? mdiRepeat : mdiRepeatOff" />
              </button>
            </div>
            <div class="flex w-full items-center gap-2">
              <span class="w-10 text-right text-[11px] tabular-nums text-fg-muted">{{ formatTime(player.currentTime) }}</span>
              <input
                class="range flex-1"
                type="range"
                min="0"
                :max="duration || 0"
                step="0.1"
                :value="player.currentTime"
                :style="{ '--pct': percent + '%' }"
                aria-label="Seek"
                :disabled="!duration"
                @input="seek(+$event.target.value)"
              />
              <span class="w-10 text-[11px] tabular-nums text-fg-muted">{{ formatTime(duration) }}</span>
            </div>
          </div>

          <!-- Stop + volume (desktop) -->
          <div class="hidden items-center gap-1 lg:flex lg:w-44">
            <button type="button" class="icon-btn" title="Stop" aria-label="Stop" @click="stopToStart">
              <AppIcon :path="mdiStop" />
            </button>
            <button type="button" class="icon-btn" :aria-label="player.muted ? 'Unmute' : 'Mute'" :title="player.muted ? 'Unmute' : 'Mute'" @click="toggleMute">
              <AppIcon :path="volumeIcon" />
            </button>
            <input
              class="range flex-1"
              type="range"
              min="0"
              max="1"
              step="0.01"
              :value="player.muted ? 0 : player.volume"
              :style="{ '--pct': (player.muted ? 0 : player.volume * 100) + '%' }"
              aria-label="Volume"
              @input="setVolume(+$event.target.value)"
            />
          </div>

          <!-- Phone controls -->
          <div class="flex items-center md:hidden">
            <button type="button" class="icon-btn" aria-label="Previous" @click="previous">
              <AppIcon :path="mdiSkipPrevious" />
            </button>
            <button
              type="button"
              class="btn btn-filled !h-10 !w-10 !p-0"
              :aria-label="player.playing ? 'Pause' : 'Play'"
              @click="toggle()"
            >
              <AppIcon :path="player.loading ? mdiLoading : player.playing ? mdiPause : mdiPlay" :size="24" :spin="player.loading" />
            </button>
            <button type="button" class="icon-btn" aria-label="Next" :disabled="!hasNext()" @click="next()">
              <AppIcon :path="mdiSkipNext" />
            </button>
          </div>

          <button type="button" class="icon-btn" title="Close player" aria-label="Close player" @click="stop">
            <AppIcon :path="mdiClose" />
          </button>
        </div>

        <!-- Seek (phone) -->
        <div class="mt-1 flex items-center gap-2 md:hidden">
          <span class="text-[10px] tabular-nums text-fg-muted">{{ formatTime(player.currentTime) }}</span>
          <input
            class="range flex-1"
            type="range"
            min="0"
            :max="duration || 0"
            step="0.1"
            :value="player.currentTime"
            :style="{ '--pct': percent + '%' }"
            aria-label="Seek"
            :disabled="!duration"
            @input="seek(+$event.target.value)"
          />
          <span class="text-[10px] tabular-nums text-fg-muted">{{ formatTime(duration) }}</span>
        </div>
      </section>
    </div>
  </Transition>
</template>

<script>
import {
  mdiClose,
  mdiLoading,
  mdiMusicNote,
  mdiPause,
  mdiPlay,
  mdiRepeat,
  mdiRepeatOff,
  mdiRepeatOnce,
  mdiShuffleDisabled,
  mdiShuffleVariant,
  mdiSkipNext,
  mdiSkipPrevious,
  mdiStop,
  mdiVolumeHigh,
  mdiVolumeLow,
  mdiVolumeMedium,
  mdiVolumeOff,
} from '@mdi/js';
import AppIcon from './AppIcon.vue';
import {
  cycleRepeat,
  hasNext,
  next,
  pause,
  player,
  previous,
  seek,
  setVolume,
  stop,
  toggle,
  toggleMute,
  toggleShuffle,
} from '../player';

export default {
  name: 'PlayerBar',
  components: { AppIcon },
  data() {
    return {
      player,
      mdiClose,
      mdiLoading,
      mdiMusicNote,
      mdiPause,
      mdiPlay,
      mdiRepeat,
      mdiRepeatOff,
      mdiRepeatOnce,
      mdiShuffleDisabled,
      mdiShuffleVariant,
      mdiSkipNext,
      mdiSkipPrevious,
      mdiStop,
    };
  },
  computed: {
    track() {
      return player.track || {};
    },
    duration() {
      return player.duration || player.track?.duration || 0;
    },
    percent() {
      return this.duration ? Math.min(100, (player.currentTime / this.duration) * 100) : 0;
    },
    repeatLabel() {
      return { off: 'Repeat off', all: 'Repeat all', one: 'Repeat this song' }[player.repeat];
    },
    volumeIcon() {
      if (player.muted || player.volume === 0) return mdiVolumeOff;
      if (player.volume < 0.35) return mdiVolumeLow;
      if (player.volume < 0.7) return mdiVolumeMedium;
      return mdiVolumeHigh;
    },
  },
  methods: {
    cycleRepeat,
    hasNext,
    next,
    previous,
    seek,
    setVolume,
    stop,
    toggle,
    toggleMute,
    toggleShuffle,
    /** Stop playback but keep the track loaded, back at the start */
    stopToStart() {
      pause();
      seek(0);
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
.eq-bar {
  width: 3px;
  height: 14px;
  border-radius: 2px;
  background: #fff;
  transform-origin: bottom;
  animation: eq 0.9s ease-in-out infinite;
}
</style>
