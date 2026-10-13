<template>
  <div class="mx-auto max-w-3xl">
    <PageHeader title="Settings" subtitle="Storage, YouTube access and system checks.">
      <button type="button" class="btn btn-filled btn-sm" :disabled="saving || !dirty" @click="save">
        <AppIcon :path="saving ? mdiLoading : mdiContentSave" :size="18" :spin="saving" />
        {{ saving ? 'Saving…' : 'Save' }}
      </button>
    </PageHeader>

    <div class="space-y-3">
      <!-- Storage -->
      <section class="card p-4">
        <SectionTitle :icon="mdiFolderOutline" title="Storage" />
        <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_12rem]">
          <div>
            <label for="download_path" class="field-label">Download folder</label>
            <input id="download_path" v-model="form.download_path" class="field font-mono !text-sm" :class="errors.download_path && 'field-error'" />
            <p v-if="errors.download_path" class="field-hint !text-danger">{{ errors.download_path }}</p>
            <p v-else class="field-hint">Absolute path, or relative to the project folder.</p>
          </div>
          <div>
            <label for="min_disk" class="field-label">Keep free (MB)</label>
            <input id="min_disk" v-model.number="form.min_disk_space_mb" type="number" min="100" step="100" class="field" :class="errors.min_disk_space_mb && 'field-error'" />
            <p v-if="errors.min_disk_space_mb" class="field-hint !text-danger">{{ errors.min_disk_space_mb }}</p>
            <p v-else class="field-hint">Downloads stop below this.</p>
          </div>
        </div>

        <div class="mt-4 rounded-xl bg-surface-2 p-3">
          <div class="mb-2 flex items-center justify-between text-xs">
            <span class="font-medium text-fg">Disk usage</span>
            <span class="text-fg-muted">{{ disk.total_mb ? `${formatMb(disk.free_mb)} free of ${formatMb(disk.total_mb)}` : '—' }}</span>
          </div>
          <LinearProgress :value="usedPercent" :tone="usedPercent > 90 ? 'danger' : usedPercent > 75 ? 'warning' : 'primary'" />
        </div>
      </section>

      <!-- YouTube access -->
      <section class="card p-4">
        <SectionTitle :icon="mdiCookieOutline" title="YouTube access" />
        <p class="mt-1 text-sm text-fg-muted">
          If YouTube says <em>“Sign in to confirm you’re not a bot”</em>, let yt-dlp use the cookies of a browser where you are signed in to YouTube.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <label for="browser" class="field-label">Browser cookies</label>
            <select id="browser" v-model="form.ytdlp_cookies_browser" class="field" :class="errors.ytdlp_cookies_browser && 'field-error'">
              <option value="">Don’t use cookies</option>
              <option v-for="b in browsers" :key="b" :value="b">{{ browserLabel(b) }}</option>
            </select>
            <p class="field-hint">Firefox works best. Chrome-based browsers must be closed while downloading.</p>
          </div>
          <div>
            <label for="cookies_file" class="field-label">or cookies.txt file</label>
            <input
              id="cookies_file"
              v-model.trim="form.ytdlp_cookies_file"
              class="field font-mono !text-sm"
              :class="errors.ytdlp_cookies_file && 'field-error'"
              placeholder="C:\path\to\cookies.txt"
            />
            <p v-if="errors.ytdlp_cookies_file" class="field-hint !text-danger">{{ errors.ytdlp_cookies_file }}</p>
            <p v-else class="field-hint">Netscape format, e.g. from a “Get cookies.txt” extension. Used instead of the browser.</p>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
          <button type="button" class="btn btn-tonal btn-sm" :disabled="checkingYoutube || dirty" @click="checkYoutube">
            <AppIcon :path="checkingYoutube ? mdiLoading : mdiAccessPointNetwork" :size="18" :spin="checkingYoutube" />
            Test connection
          </button>
          <span v-if="dirty" class="text-xs text-fg-muted">Save first to test the new settings.</span>
        </div>
        <div
          v-if="youtubeCheck"
          class="mt-3 flex items-start gap-2 rounded-xl px-3 py-2.5 text-sm animate-fade-up"
          :class="youtubeCheck.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
        >
          <AppIcon :path="youtubeCheck.ok ? mdiCheckCircle : mdiAlertCircle" :size="18" class="mt-px" />
          <span class="whitespace-pre-line break-words">{{ youtubeCheck.message }}</span>
        </div>
        <p class="mt-3 text-xs text-fg-muted">
          If cookies cannot be read, downloads continue without them. They can also be set in <code class="font-mono">.env</code>
          (<code class="font-mono">YTDLP_COOKIES_FROM_BROWSER</code>, <code class="font-mono">YTDLP_COOKIES_FILE</code>).
        </p>
      </section>

      <!-- Playlists -->
      <section class="card p-4">
        <SectionTitle :icon="mdiPlaylistMusicOutline" title="Playlists" />
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <label for="max_playlist" class="field-label">Max videos per import</label>
            <input id="max_playlist" v-model.number="form.max_playlist_size" type="number" min="1" max="1000" class="field" :class="errors.max_playlist_size && 'field-error'" />
            <p v-if="errors.max_playlist_size" class="field-hint !text-danger">{{ errors.max_playlist_size }}</p>
          </div>
          <div>
            <label for="rate_limit" class="field-label">Delay between downloads (s)</label>
            <input id="rate_limit" v-model.number="form.rate_limit_seconds" type="number" min="0" max="300" class="field" :class="errors.rate_limit_seconds && 'field-error'" />
            <p v-if="errors.rate_limit_seconds" class="field-hint !text-danger">{{ errors.rate_limit_seconds }}</p>
            <p v-else class="field-hint">Spacing playlist downloads out avoids YouTube rate limits.</p>
          </div>
        </div>
      </section>

      <!-- System -->
      <section class="card p-4">
        <div class="flex items-center justify-between">
          <SectionTitle :icon="mdiToolboxOutline" title="System" />
          <button type="button" class="btn btn-text btn-sm -mr-2" :disabled="checkingSystem" @click="checkSystem">
            <AppIcon :path="mdiRefresh" :size="18" :spin="checkingSystem" />
            Recheck
          </button>
        </div>
        <ul class="mt-3 divide-y divide-line">
          <li v-for="item in systemItems" :key="item.name" class="flex items-center gap-3 py-2.5">
            <span
              class="grid h-8 w-8 shrink-0 place-items-center rounded-full"
              :class="item.ok === null ? 'bg-surface-2 text-fg-muted' : item.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
            >
              <AppIcon :path="item.ok === null ? mdiHelpCircleOutline : item.ok ? mdiCheck : mdiClose" :size="18" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-fg">{{ item.name }}</p>
              <p class="truncate text-xs text-fg-muted">{{ item.detail }}</p>
            </div>
          </li>
        </ul>
        <p class="mt-2 text-xs text-fg-muted">
          Downloads failing with “HTTP Error 403” usually mean yt-dlp is outdated. Update it with
          <code class="font-mono">yt-dlp -U</code> (or <code class="font-mono">winget upgrade yt-dlp</code>).
        </p>
      </section>
    </div>
  </div>
</template>

<script>
import {
  mdiAccessPointNetwork,
  mdiAlertCircle,
  mdiCheck,
  mdiCheckCircle,
  mdiClose,
  mdiContentSave,
  mdiCookieOutline,
  mdiFolderOutline,
  mdiHelpCircleOutline,
  mdiLoading,
  mdiPlaylistMusicOutline,
  mdiRefresh,
  mdiToolboxOutline,
} from '@mdi/js';
import { h } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import LinearProgress from '../components/LinearProgress.vue';
import PageHeader from '../components/PageHeader.vue';
import { queueAPI, settingsAPI } from '../services/api';
import { errorMessage, notify } from '../ui';

const SectionTitle = {
  name: 'SectionTitle',
  props: { icon: String, title: String },
  render() {
    return h('h2', { class: 'flex items-center gap-2 card-title' }, [
      h(AppIcon, { path: this.icon, size: 20, class: 'text-fg-muted' }),
      this.title,
    ]);
  },
};

const BROWSER_LABELS = { edge: 'Microsoft Edge', whale: 'Naver Whale' };

export default {
  name: 'Settings',
  components: { AppIcon, LinearProgress, PageHeader, SectionTitle },
  data() {
    return {
      form: {
        download_path: '',
        min_disk_space_mb: 500,
        max_playlist_size: 50,
        rate_limit_seconds: 2,
        ytdlp_cookies_browser: '',
        ytdlp_cookies_file: '',
      },
      saved: '',
      browsers: [],
      errors: {},
      disk: { free_mb: 0, total_mb: 0 },
      deps: null,
      workerRunning: null,
      youtubeCheck: null,
      saving: false,
      checkingYoutube: false,
      checkingSystem: false,
      mdiAccessPointNetwork,
      mdiAlertCircle,
      mdiCheck,
      mdiCheckCircle,
      mdiClose,
      mdiContentSave,
      mdiCookieOutline,
      mdiFolderOutline,
      mdiHelpCircleOutline,
      mdiLoading,
      mdiPlaylistMusicOutline,
      mdiRefresh,
      mdiToolboxOutline,
    };
  },
  computed: {
    dirty() {
      return this.saved !== '' && JSON.stringify(this.form) !== this.saved;
    },
    usedPercent() {
      const { total_mb: total, free_mb: free } = this.disk;
      return total ? ((total - free) / total) * 100 : 0;
    },
    systemItems() {
      const d = this.deps;
      return [
        {
          name: 'yt-dlp',
          ok: d ? d.yt_dlp.installed : null,
          detail: d ? (d.yt_dlp.installed ? `Version ${d.yt_dlp.version}` : 'Not found on PATH. Install it and restart the app.') : 'Checking…',
        },
        {
          name: 'FFmpeg',
          ok: d ? d.ffmpeg.installed : null,
          detail: d ? (d.ffmpeg.installed ? `Version ${d.ffmpeg.version}` : 'Not found on PATH. Install it and restart the app.') : 'Checking…',
        },
        {
          name: 'Queue worker',
          ok: this.workerRunning,
          detail: this.workerRunning === null ? 'Checking…' : this.workerRunning ? 'Running' : 'Not running. Start it with `composer dev` or `php artisan queue:work`.',
        },
      ];
    },
  },
  mounted() {
    this.load();
    this.checkSystem();
  },
  methods: {
    browserLabel(b) {
      return BROWSER_LABELS[b] || b.charAt(0).toUpperCase() + b.slice(1);
    },

    formatMb(mb) {
      return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`;
    },

    async load() {
      try {
        const [{ data }, disk] = await Promise.all([settingsAPI.get(), settingsAPI.checkDiskSpace()]);
        this.browsers = data.cookie_browsers || [];
        for (const key of Object.keys(this.form)) {
          if (data[key] !== undefined && data[key] !== null) this.form[key] = data[key];
        }
        this.saved = JSON.stringify(this.form);
        this.disk = disk.data;
      } catch (error) {
        notify(errorMessage(error, 'Could not load settings'), 'error');
      }
    },

    async save() {
      this.saving = true;
      this.errors = {};
      try {
        await settingsAPI.update(this.form);
        this.saved = JSON.stringify(this.form);
        this.youtubeCheck = null;
        notify('Settings saved.', 'success');
        settingsAPI.checkDiskSpace().then(({ data }) => (this.disk = data)).catch(() => {});
      } catch (error) {
        const errors = error.response?.data?.errors;
        if (errors) {
          this.errors = Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]));
        }
        notify(errorMessage(error, 'Could not save settings'), 'error');
      } finally {
        this.saving = false;
      }
    },

    async checkYoutube() {
      this.checkingYoutube = true;
      this.youtubeCheck = null;
      try {
        const { data } = await settingsAPI.testYoutube();
        this.youtubeCheck = data;
      } catch (error) {
        this.youtubeCheck = { ok: false, message: errorMessage(error, 'The check failed') };
      } finally {
        this.checkingYoutube = false;
      }
    },

    async checkSystem() {
      this.checkingSystem = true;
      try {
        const [deps, queue] = await Promise.all([settingsAPI.testDependencies(), queueAPI.getStatus()]);
        this.deps = deps.data;
        this.workerRunning = queue.data.worker_running;
      } catch (error) {
        notify(errorMessage(error, 'System check failed'), 'error');
      } finally {
        this.checkingSystem = false;
      }
    },
  },
};
</script>
