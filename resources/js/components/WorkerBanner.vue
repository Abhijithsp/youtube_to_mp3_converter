<template>
  <div
    class="mb-4 flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-soft px-4 py-3 text-sm text-warning animate-fade-up"
    role="alert"
  >
    <AppIcon :path="mdiAlert" :size="20" class="mt-px" />
    <div class="min-w-0 flex-1">
      <p class="font-medium">The queue worker is not running</p>
      <p class="mt-0.5 opacity-90">
        Downloads stay queued until it runs. Start it with
        <code class="rounded bg-black/10 px-1 font-mono text-xs dark:bg-white/10">composer dev</code>
        or
        <code class="rounded bg-black/10 px-1 font-mono text-xs dark:bg-white/10">php artisan queue:work</code>.
      </p>
    </div>
  </div>
</template>

<script>
import { mdiAlert } from '@mdi/js';
import AppIcon from './AppIcon.vue';

export default {
  name: 'WorkerBanner',
  components: { AppIcon },
  data() {
    return { mdiAlert };
  },
};
</script>
