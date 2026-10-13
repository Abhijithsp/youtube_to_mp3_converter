<template>
  <div>
    <button
      type="button"
      class="flex w-full items-center gap-1 text-left text-sm font-medium text-primary"
      :aria-expanded="modelValue"
      @click="$emit('update:modelValue', !modelValue)"
    >
      <AppIcon :path="modelValue ? mdiChevronUp : mdiChevronDown" :size="20" />
      {{ label }}
      <span v-if="badge && !modelValue" class="chip ml-1 !normal-case !tracking-normal">{{ badge }}</span>
    </button>
    <div v-if="modelValue" class="mt-3 animate-fade-up">
      <slot />
    </div>
  </div>
</template>

<script>
import { mdiChevronDown, mdiChevronUp } from '@mdi/js';
import AppIcon from './AppIcon.vue';

// Expandable section with an optional summary chip shown while collapsed
export default {
  name: 'Disclosure',
  components: { AppIcon },
  props: {
    modelValue: { type: Boolean, default: false },
    label: { type: String, required: true },
    badge: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  data() {
    return { mdiChevronDown, mdiChevronUp };
  },
};
</script>
