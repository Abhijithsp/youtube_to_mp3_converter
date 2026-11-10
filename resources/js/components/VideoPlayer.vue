<template>
  <Transition
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
    enter-active-class="transition duration-150"
    leave-active-class="transition duration-100"
  >
    <div
      v-if="ui.video"
      class="fixed inset-0 z-50 grid place-items-center bg-black/80 p-2 sm:p-6"
      @click.self="closeVideo"
    >
      <section class="w-full max-w-5xl animate-fade-up" role="dialog" aria-modal="true" :aria-label="`Playing ${ui.video.title}`">
        <div class="mb-2 flex items-center gap-2 text-white">
          <p class="min-w-0 flex-1 truncate text-sm font-medium" :title="ui.video.title">{{ ui.video.title }}</p>
          <a :href="ui.video.download_url" class="icon-btn !text-white" title="Save file" aria-label="Save file">
            <AppIcon :path="mdiTrayArrowDown" />
          </a>
          <button type="button" class="icon-btn !text-white" title="Close" aria-label="Close" @click="closeVideo">
            <AppIcon :path="mdiClose" />
          </button>
        </div>

        <!-- Not every browser can play every codec, e.g. AV1 on older devices -->
        <div v-if="failed" class="card p-6 text-center">
          <p class="text-sm font-medium text-fg">This video cannot be played here</p>
          <p class="mt-1 text-sm text-fg-muted">The file may have been moved, or this browser may not support its format. Save it and open it in a player such as VLC.</p>
        </div>
        <video
          v-else
          ref="video"
          :src="ui.video.download_url"
          :poster="ui.video.thumbnail_url || undefined"
          class="max-h-[85vh] w-full rounded-xl bg-black"
          controls
          autoplay
          playsinline
          @error="failed = true"
        />
      </section>
    </div>
  </Transition>
</template>

<script>
import { mdiClose, mdiTrayArrowDown } from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { pause } from '../player';
import { closeVideo, ui } from '../ui';

export default {
  name: 'VideoPlayer',
  components: { AppIcon },
  data() {
    return { ui, failed: false, mdiClose, mdiTrayArrowDown };
  },
  watch: {
    'ui.video'(video) {
      if (video) {
        // Music and video should not play over each other
        pause();
        this.failed = false;
        window.addEventListener('keydown', this.onKeydown);
        // Keyboard control (space, arrows) works straight away
        this.$nextTick(() => this.$refs.video?.focus());
      } else {
        window.removeEventListener('keydown', this.onKeydown);
        this.release();
      }
    },
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onKeydown);
  },
  methods: {
    closeVideo,

    onKeydown(event) {
      // In fullscreen, Esc belongs to the browser and only leaves fullscreen
      if (event.key === 'Escape' && !document.fullscreenElement) closeVideo();
    },

    /** Stop loading now: an open file on Windows could not be deleted or moved */
    release() {
      const video = this.$refs.video;
      if (!video) return;
      video.pause();
      video.removeAttribute('src');
      video.load();
    },
  },
};
</script>
