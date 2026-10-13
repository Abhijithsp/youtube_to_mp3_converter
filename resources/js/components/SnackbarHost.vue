<template>
  <div
    class="pointer-events-none fixed inset-x-0 z-50 flex justify-center px-4"
    :class="raised ? 'bottom-[calc(12rem+env(safe-area-inset-bottom))] md:bottom-32' : 'bottom-[calc(5rem+env(safe-area-inset-bottom))] md:bottom-6'"
    aria-live="polite"
  >
    <Transition
      enter-from-class="opacity-0 translate-y-2"
      leave-to-class="opacity-0 translate-y-2"
      enter-active-class="transition duration-200"
      leave-active-class="transition duration-150"
    >
      <div
        v-if="ui.snackbar"
        :key="ui.snackbar.id"
        class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl px-4 py-3 text-sm shadow-lg"
        style="background: var(--md-fg); color: var(--md-bg)"
        role="status"
      >
        <AppIcon :path="icon" :size="20" class="mt-px" :class="iconClass" />
        <p class="flex-1 whitespace-pre-line break-words">{{ ui.snackbar.message }}</p>
        <button type="button" class="-my-1 -mr-2 rounded-full p-1 opacity-70 hover:opacity-100" aria-label="Dismiss" @click="dismissSnackbar">
          <AppIcon :path="mdiClose" :size="18" />
        </button>
      </div>
    </Transition>
  </div>
</template>

<script>
import { mdiAlertCircle, mdiCheckCircle, mdiClose, mdiInformation } from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { dismissSnackbar, ui } from '../ui';

export default {
  name: 'SnackbarHost',
  components: { AppIcon },
  props: {
    // Sit above the audio player while it is open
    raised: { type: Boolean, default: false },
  },
  data() {
    return { ui, mdiClose };
  },
  computed: {
    icon() {
      return { success: mdiCheckCircle, error: mdiAlertCircle }[this.ui.snackbar?.type] || mdiInformation;
    },
    iconClass() {
      // Inverted surface: use the opposite theme's accent tones
      return {
        success: 'text-[color:var(--md-success-soft)]',
        error: 'text-[color:var(--md-danger-soft)]',
      }[this.ui.snackbar?.type] || 'text-[color:var(--md-primary-soft)]';
    },
  },
  methods: { dismissSnackbar },
};
</script>
