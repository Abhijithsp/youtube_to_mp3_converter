<template>
  <div class="seg" :class="[compact && 'seg-compact', large && 'seg-lg']" role="group" :aria-label="label">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      :aria-pressed="String(option.value) === String(modelValue)"
      :disabled="disabled || option.disabled"
      @click="$emit('update:modelValue', option.value)"
    >
      <AppIcon v-if="option.icon" :path="option.icon" :size="large ? 20 : 16" />
      <AppIcon v-else-if="!compact && String(option.value) === String(modelValue)" :path="mdiCheck" :size="16" />
      <span>{{ option.label }}</span>
    </button>
  </div>
</template>

<script>
import { mdiCheck } from '@mdi/js';
import AppIcon from './AppIcon.vue';

export default {
  name: 'SegmentedControl',
  components: { AppIcon },
  props: {
    modelValue: { type: [String, Number], required: true },
    options: { type: Array, required: true }, // [{ value, label, icon?, disabled? }]
    label: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    // Small, content-width version without the check mark
    compact: { type: Boolean, default: false },
    // Bigger tabs for switching between modes (icon above the label on phones)
    large: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  data() {
    return { mdiCheck };
  },
};
</script>
