<template>
  <div class="flex flex-col items-center justify-center px-6 py-10 text-center">
    <div class="tile-icon mb-3 h-12 w-12 !rounded-full bg-surface-2 text-fg-muted">
      <AppIcon :path="icon" :size="24" />
    </div>
    <p class="text-sm font-medium text-fg">{{ title }}</p>
    <p v-if="text" class="mt-1 max-w-xs text-sm text-fg-muted">{{ text }}</p>
    <div v-if="$slots.default" class="mt-4">
      <slot />
    </div>
  </div>
</template>

<script>
import AppIcon from './AppIcon.vue';

export default {
  name: 'EmptyState',
  components: { AppIcon },
  props: {
    icon: { type: String, required: true },
    title: { type: String, required: true },
    text: { type: String, default: '' },
  },
};
</script>
