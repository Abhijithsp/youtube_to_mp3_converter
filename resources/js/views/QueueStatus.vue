<template>
  <div class="mx-auto max-w-3xl">
    <PageHeader title="Queue" subtitle="Downloads in progress, waiting, or needing attention." />

    <!-- Tabs -->
    <div class="mb-3 flex items-center gap-2">
      <div class="seg !w-auto" role="tablist">
        <button
          v-for="t in tabs"
          :key="t.value"
          type="button"
          role="tab"
          :aria-pressed="tab === t.value"
          :aria-selected="tab === t.value"
          class="!px-4"
          @click="setTab(t.value)"
        >
          {{ t.label }}
          <span
            v-if="t.count"
            class="grid h-5 min-w-5 place-items-center rounded-full px-1 text-[11px] font-semibold"
            :class="t.value === 'failed' ? 'bg-danger text-[color:var(--md-surface)]' : 'bg-primary text-on-primary'"
          >
            {{ t.count }}
          </span>
        </button>
      </div>

      <div v-if="tab === 'failed' && failed.length" class="ml-auto flex gap-1">
        <button type="button" class="btn btn-text btn-sm" @click="clearFailed">
          <AppIcon :path="mdiDeleteSweepOutline" :size="18" />
          <span class="hidden sm:inline">Clear</span>
        </button>
        <button type="button" class="btn btn-tonal btn-sm" @click="retryAll">
          <AppIcon :path="mdiRefresh" :size="18" />
          Retry all
        </button>
      </div>
    </div>

    <section class="card overflow-hidden">
      <div v-if="loading" class="flex justify-center py-12 text-fg-muted">
        <AppIcon :path="mdiLoading" :size="28" spin />
      </div>

      <template v-else-if="tab === 'active'">
        <EmptyState
          v-if="!active.length"
          :icon="mdiCheckAll"
          title="Nothing in the queue"
          text="New downloads appear here while they run."
        >
          <router-link to="/download" class="btn btn-tonal btn-sm">
            <AppIcon :path="mdiPlus" :size="18" />
            New download
          </router-link>
        </EmptyState>
        <div v-else class="divide-y divide-line">
          <DownloadRow v-for="d in active" :key="d.id" :download="d">
            <template #actions>
              <button type="button" class="icon-btn" title="Cancel" aria-label="Cancel" @click="cancel(d)">
                <AppIcon :path="mdiStopCircleOutline" />
              </button>
            </template>
          </DownloadRow>
        </div>
      </template>

      <template v-else>
        <EmptyState
          v-if="!failed.length"
          :icon="mdiShieldCheckOutline"
          title="No failed downloads"
          text="Everything that ran finished successfully."
        />
        <div v-else class="divide-y divide-line">
          <DownloadRow v-for="d in failed" :key="d.id" :download="d">
            <template #actions>
              <button type="button" class="icon-btn" title="Retry" aria-label="Retry" @click="retry(d)">
                <AppIcon :path="mdiRefresh" />
              </button>
              <button type="button" class="icon-btn" title="Remove" aria-label="Remove" @click="remove(d)">
                <AppIcon :path="mdiDeleteOutline" />
              </button>
            </template>
          </DownloadRow>
        </div>
      </template>
    </section>
  </div>
</template>

<script>
import {
  mdiCheckAll,
  mdiDeleteOutline,
  mdiDeleteSweepOutline,
  mdiLoading,
  mdiPlus,
  mdiRefresh,
  mdiShieldCheckOutline,
  mdiStopCircleOutline,
} from '@mdi/js';
import AppIcon from '../components/AppIcon.vue';
import DownloadRow from '../components/DownloadRow.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { downloadAPI, queueAPI } from '../services/api';
import { confirmDialog, errorMessage, notify } from '../ui';

export default {
  name: 'QueueStatus',
  components: { AppIcon, DownloadRow, EmptyState, PageHeader },
  data() {
    return {
      tab: this.$route.query.tab === 'failed' ? 'failed' : 'active',
      active: [],
      failed: [],
      loading: true,
      timer: null,
      mdiCheckAll,
      mdiDeleteOutline,
      mdiDeleteSweepOutline,
      mdiLoading,
      mdiPlus,
      mdiRefresh,
      mdiShieldCheckOutline,
      mdiStopCircleOutline,
    };
  },
  computed: {
    tabs() {
      return [
        { value: 'active', label: 'Active', count: this.active.length },
        { value: 'failed', label: 'Failed', count: this.failed.length },
      ];
    },
  },
  mounted() {
    this.refresh();
    this.timer = setInterval(this.refresh, 1000);
  },
  beforeUnmount() {
    clearInterval(this.timer);
  },
  methods: {
    setTab(value) {
      this.tab = value;
      this.$router.replace({ query: value === 'failed' ? { tab: 'failed' } : {} });
    },

    async refresh() {
      try {
        const [active, failed] = await Promise.all([
          downloadAPI.getAll({ status: 'processing,pending', per_page: 100 }),
          downloadAPI.getAll({ status: 'failed,cancelled', per_page: 100 }),
        ]);
        // Running downloads first, then the oldest waiting one (next in line)
        this.active = active.data.data.sort((a, b) =>
          a.status === b.status ? a.id - b.id : a.status === 'processing' ? -1 : 1,
        );
        this.failed = failed.data.data;
      } catch (error) {
        clearInterval(this.timer);
        notify(errorMessage(error, 'Could not load the queue'), 'error');
      } finally {
        this.loading = false;
      }
    },

    async cancel(download) {
      try {
        await downloadAPI.cancel(download.id);
        notify('Download cancelled.');
        this.refresh();
      } catch (error) {
        notify(errorMessage(error, 'Could not cancel'), 'error');
      }
    },

    async retry(download) {
      try {
        await downloadAPI.retry(download.id);
        this.refresh();
      } catch (error) {
        notify(errorMessage(error, 'Could not retry'), 'error');
      }
    },

    async retryAll() {
      const results = await Promise.allSettled(this.failed.map((d) => downloadAPI.retry(d.id)));
      const ok = results.filter((r) => r.status === 'fulfilled').length;
      notify(`Retrying ${ok} download${ok === 1 ? '' : 's'}.`, 'success');
      this.setTab('active');
      this.refresh();
    },

    async remove(download) {
      try {
        await downloadAPI.delete(download.id);
        this.failed = this.failed.filter((d) => d.id !== download.id);
      } catch (error) {
        notify(errorMessage(error, 'Could not remove'), 'error');
      }
    },

    async clearFailed() {
      const ok = await confirmDialog({
        title: 'Clear failed downloads?',
        message: `Removes ${this.failed.length} failed or cancelled downloads from the list.`,
        confirmLabel: 'Clear',
        danger: true,
      });
      if (!ok) return;
      const ids = this.failed.map((d) => d.id);
      await Promise.allSettled(ids.map((id) => downloadAPI.delete(id)));
      // Also drop the failed_jobs records of these downloads, and only those
      await queueAPI.clearFailed(ids).catch(() => {});
      this.refresh();
    },
  },
};
</script>
