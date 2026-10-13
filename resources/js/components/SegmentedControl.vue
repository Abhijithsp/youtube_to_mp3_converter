<template>
  <div class="seg" role="group" :aria-label="label">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      :aria-pressed="String(option.value) === String(modelValue)"
      :disabled="disabled || option.disabled"
      @click="$emit('update:modelValue', option.value)"
    >
      <AppIcon v-if="String(option.value) === String(modelValue)" :path="mdiCheck" :size="16" />
      {{ option.label }}
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
    options: { type: Array, required: true }, // [{ value, label, disabled? }]
    label: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  data() {
    return { mdiCheck };
  },
};
</script>
