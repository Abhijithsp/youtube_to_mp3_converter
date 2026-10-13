<template>
  <div>
    <div class="flex items-center justify-between gap-3">
      <div class="flex min-w-0 items-center gap-3">
        <span
          class="tile-icon h-10 w-10 shrink-0"
          :class="loading ? 'bg-surface-2 text-fg-muted' : ready && !warnings ? 'bg-success-soft text-success' : ready ? 'bg-warning-soft text-warning' : 'bg-danger-soft text-danger'"
        >
          <AppIcon :path="loading ? mdiLoading : ready ? mdiCheckDecagram : mdiAlertDecagram" :size="22" :spin="loading" />
        </span>
        <div class="min-w-0">
          <h2 class="card-title">{{ heading }}</h2>
          <p class="truncate text-xs text-fg-muted">{{ subheading }}</p>
        </div>
      </div>
      <div class="flex shrink-0 items-center gap-1">
        <button type="button" class="btn btn-text btn-sm" :disabled="loading" @click="load(true)">
          <AppIcon :path="mdiRefresh" :size="18" :spin="loading" />
          <span class="hidden sm:inline">Recheck</span>
        </button>
        <button v-if="dismissible" type="button" class="icon-btn" aria-label="Hide" title="Hide" @click="$emit('dismiss', failingKeys)">
          <AppIcon :path="mdiClose" />
        </button>
      </div>
    </div>

    <ul v-if="checks.length" class="mt-3 divide-y divide-line">
      <li v-for="check in visibleChecks" :key="check.key" class="flex items-start gap-3 py-2.5">
        <span
          class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full"
          :class="check.ok ? 'bg-success-soft text-success' : check.required ? 'bg-danger-soft text-danger' : 'bg-warning-soft text-warning'"
        >
          <AppIcon :path="check.ok ? mdiCheck : check.required ? mdiClose : mdiExclamation" :size="15" />
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-medium text-fg">
            {{ check.label }}
            <span v-if="!check.ok && !check.required" class="ml-1 text-xs font-normal text-fg-muted">recommended</span>
          </p>
          <p class="break-words text-xs text-fg-muted">{{ check.detail }}</p>
          <div v-if="!check.ok && check.fix" class="mt-1.5 flex items-center gap-1">
            <code class="min-w-0 flex-1 truncate rounded-lg bg-surface-2 px-2 py-1 font-mono text-xs text-fg" style="box-shadow: var(--md-inset)">{{ check.fix }}</code>
            <button
              v-if="isCommand(check.fix)"
              type="button"
              class="icon-btn !h-7 !w-7"
              :title="copied === check.key ? 'Copied' : 'Copy command'"
              aria-label="Copy command"
              @click="copy(check)"
            >
              <AppIcon :path="copied === check.key ? mdiCheck : mdiContentCopy" :size="16" />
            </button>
          </div>
        </div>
      </li>
    </ul>

    <button
      v-if="onlyProblems && hiddenCount"
      type="button"
      class="btn btn-text btn-sm -ml-3 mt-1"
      @click="showAll = !showAll"
    >
      {{ showAll ? 'Show problems only' : `Show all ${checks.length} checks` }}
    </button>
  </div>
</template>

<script>
import {
  mdiAlertDecagram,
  mdiCheck,
  mdiCheckDecagram,
  mdiClose,
  mdiContentCopy,
  mdiExclamation,
  mdiLoading,
  mdiRefresh,
} from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { settingsAPI } from '../services/api';
import { errorMessage, notify } from '../ui';

export default {
  name: 'SetupChecklist',
  components: { AppIcon },
  props: {
    // On Home only the failing checks are listed
    onlyProblems: { type: Boolean, default: false },
    dismissible: { type: Boolean, default: false },
  },
  emits: ['dismiss', 'loaded'],
  data() {
    return {
      checks: [],
      ready: false,
      loading: true,
      showAll: false,
      copied: null,
      mdiAlertDecagram,
      mdiCheck,
      mdiCheckDecagram,
      mdiClose,
      mdiContentCopy,
      mdiExclamation,
      mdiLoading,
      mdiRefresh,
    };
  },
  computed: {
    failing() {
      return this.checks.filter((c) => !c.ok);
    },
    failingKeys() {
      return this.failing.map((c) => c.key);
    },
    warnings() {
      return this.failing.filter((c) => !c.required).length;
    },
    visibleChecks() {
      return this.onlyProblems && !this.showAll ? this.failing : this.checks;
    },
    hiddenCount() {
      return this.checks.length - this.failing.length;
    },
    heading() {
      if (this.loading) return 'Checking your setup…';
      if (!this.ready) return 'Finish setting up';
      return this.warnings ? 'Ready, with suggestions' : 'Everything is set up';
    },
    subheading() {
      if (this.loading) return 'yt-dlp, FFmpeg, queue worker and storage';
      const required = this.failing.filter((c) => c.required).length;
      if (required) return `${required} required item${required === 1 ? '' : 's'} missing: downloads will not work yet.`;
      if (this.warnings) return `${this.warnings} recommended item${this.warnings === 1 ? '' : 's'} to improve reliability.`;
      return 'Downloads and conversions are ready to go.';
    },
  },
  mounted() {
    this.load();
  },
  methods: {
    async load(fresh = false) {
      this.loading = true;
      try {
        const { data } = await settingsAPI.setupChecks(fresh);
        this.checks = data.checks;
        this.ready = data.ready;
        this.$emit('loaded', { ready: data.ready, failing: this.failingKeys });
      } catch (error) {
        notify(errorMessage(error, 'Could not check the setup'), 'error');
      } finally {
        this.loading = false;
      }
    },
    isCommand(fix) {
      return !/^(Settings|Pick|Free up)/.test(fix);
    },
    async copy(check) {
      try {
        // Copy only the command, not the "(or ...)" alternative
        await navigator.clipboard.writeText(check.fix.split('  (')[0].trim());
        this.copied = check.key;
        setTimeout(() => (this.copied = null), 1500);
      } catch {
        notify('Copy failed. Select the command and copy it manually.');
      }
    },
  },
};
</script>
