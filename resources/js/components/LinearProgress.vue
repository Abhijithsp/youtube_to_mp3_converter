<template>
  <div
    class="relative h-2 w-full overflow-hidden rounded-full bg-surface-3"
    style="box-shadow: var(--md-inset)"
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-valuenow="clamped"
  >
    <!-- Glides between updates (about one per second) so the bar moves steadily -->
    <div
      class="h-full rounded-full shadow-[inset_0_1px_0_rgb(255_255_255/0.35)] transition-[width] duration-1000 ease-linear"
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
