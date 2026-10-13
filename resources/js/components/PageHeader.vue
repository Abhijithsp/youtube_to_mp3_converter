<template>
  <header class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div class="min-w-0">
      <h1 class="text-xl font-semibold tracking-tight text-fg sm:text-2xl">{{ title }}</h1>
      <p v-if="subtitle" class="mt-0.5 text-sm text-fg-muted">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
      <slot />
    </div>
  </header>
</template>

<script>
export default {
  name: 'PageHeader',
  props: {
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
  },
};
</script>
