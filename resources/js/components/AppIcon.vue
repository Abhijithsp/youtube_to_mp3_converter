<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="currentColor"
    aria-hidden="true"
    class="shrink-0"
    :class="{ 'animate-spin': spin }"
  >
    <path :d="path" />
  </svg>
</template>

<script>
// Renders a Material Design Icons path from @mdi/js (only imported icons are bundled)
export default {
  name: 'AppIcon',
  props: {
    path: { type: String, required: true },
    size: { type: [Number, String], default: 20 },
    spin: { type: Boolean, default: false },
  },
};
</script>
