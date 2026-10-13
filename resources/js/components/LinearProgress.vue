<template>
  <div
    class="relative h-2 w-full overflow-hidden rounded-full bg-surface-3"
    style="box-shadow: var(--md-inset)"
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-valuenow="indeterminate ? undefined : clamped"
  >
    <div
      v-if="indeterminate"
      class="absolute inset-y-0 w-2/5 rounded-full"
      :class="barClass"
      style="animation: indeterminate 1.4s ease-in-out infinite"
    />
    <div
      v-else
      class="h-full rounded-full shadow-[inset_0_1px_0_rgb(255_255_255/0.35)] transition-[width] duration-500 ease-out"
      :class="barClass"
      :style="{ width: clamped + '%' }"
    />
  </div>
</template>

<script>
export default {
  name: 'LinearProgress',
  props: {
    value: { type: Number, default: 0 },
    indeterminate: { type: Boolean, default: false },
    tone: { type: String, default: 'primary' }, // primary | danger | warning | success
  },
  computed: {
    clamped() {
      return Math.min(100, Math.max(0, Math.round(this.value || 0)));
    },
    barClass() {
      return {
        primary: 'bg-primary',
        danger: 'bg-danger',
        warning: 'bg-warning',
        success: 'bg-success',
      }[this.tone];
    },
  },
};
</script>
