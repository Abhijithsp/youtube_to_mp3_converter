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

      <!-- Titles -->
      <section class="card p-4">
        <SectionTitle :icon="mdiFormatTitle" title="Titles" />
        <label class="mt-3 flex cursor-pointer items-start gap-3">
          <input v-model="form.clean_titles" type="checkbox" class="checkbox mt-0.5" />
          <span>
            <span class="block text-sm font-medium text-fg">Clean up titles</span>
            <span class="block text-xs text-fg-muted">
              Removes “(Official Music Video)”, “[HD]”, “| Lyrics” and similar from titles and file names, and
              takes the artist from “Artist - Song” titles. “D-Sturb - LADY (Official Music Video)” becomes
              “D-Sturb - LADY”, tagged as “LADY” by “D-Sturb”.
            </span>
          </span>
        </label>
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

      <!-- yt-dlp -->
      <section class="card p-4">
        <SectionTitle :icon="mdiYoutube" title="yt-dlp" />
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <div class="min-w-0 flex-1">
            <p class="text-sm text-fg">
              <template v-if="ytdlp === null">Checking version…</template>
              <template v-else-if="!ytdlp.installed">Not installed</template>
              <template v-else>
                Version <span class="font-medium tabular-nums">{{ ytdlp.installed }}</span>
                <span v-if="ytdlp.update_available" class="chip ml-1 !bg-warning-soft !text-warning">{{ ytdlp.latest }} available</span>
                <span v-else-if="ytdlp.latest" class="chip ml-1 !bg-success-soft !text-success">Latest</span>
              </template>
            </p>
            <p class="text-xs text-fg-muted">YouTube changes often. Most “HTTP Error 403” failures are fixed by updating.</p>
          </div>
          <button type="button" class="btn btn-sm" :class="ytdlp?.update_available ? 'btn-filled' : 'btn-tonal'" :disabled="updating || !ytdlp?.installed" @click="updateYtDlp">
            <AppIcon :path="updating ? mdiLoading : mdiUpdate" :size="18" :spin="updating" />
            {{ updating ? 'Updating…' : ytdlp?.update_available ? 'Update now' : 'Check for update' }}
          </button>
        </div>
        <div
          v-if="updateResult"
          class="mt-3 rounded-xl px-3 py-2.5 text-sm animate-fade-up"
          :class="updateResult.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
        >
          <p class="whitespace-pre-line break-words">{{ updateResult.message }}</p>
        </div>
      </section>

      <!-- Setup checklist -->
      <section class="card p-4">
        <SetupChecklist ref="checklist" />
      </section>
    </div>
  </div>
</template>

<script>
import {
  mdiAccessPointNetwork,
  mdiAlertCircle,
  mdiCheckCircle,
  mdiContentSave,
  mdiCookieOutline,
  mdiFolderOutline,
  mdiFormatTitle,
  mdiLoading,
  mdiPlaylistMusicOutline,
  mdiUpdate,
  mdiYoutube,
} from '@mdi/js';
import { h } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import LinearProgress from '../components/LinearProgress.vue';
import PageHeader from '../components/PageHeader.vue';
import SetupChecklist from '../components/SetupChecklist.vue';
import { settingsAPI } from '../services/api';
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
  components: { AppIcon, LinearProgress, PageHeader, SectionTitle, SetupChecklist },
  data() {
    return {
      form: {
        download_path: '',
        min_disk_space_mb: 500,
        max_playlist_size: 50,
        rate_limit_seconds: 2,
        ytdlp_cookies_browser: '',
        ytdlp_cookies_file: '',
        clean_titles: true,
      },
      saved: '',
      browsers: [],
      errors: {},
      disk: { free_mb: 0, total_mb: 0 },
      ytdlp: null,
      updateResult: null,
      youtubeCheck: null,
      saving: false,
      updating: false,
      checkingYoutube: false,
      mdiAccessPointNetwork,
      mdiAlertCircle,
      mdiCheckCircle,
      mdiContentSave,
      mdiCookieOutline,
      mdiFolderOutline,
      mdiFormatTitle,
      mdiLoading,
      mdiPlaylistMusicOutline,
      mdiUpdate,
      mdiYoutube,
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
  },
  mounted() {
    this.load();
    this.loadYtDlp();
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

    async loadYtDlp() {
      try {
        const { data } = await settingsAPI.ytDlpVersion();
        this.ytdlp = data;
      } catch {
        this.ytdlp = { installed: null, latest: null, update_available: false };
      }
    },

    async updateYtDlp() {
      this.updating = true;
      this.updateResult = null;
      try {
        const { data } = await settingsAPI.updateYtDlp();
        this.updateResult = data;
        if (data.updated) notify(data.message, 'success');
        await this.loadYtDlp();
        this.$refs.checklist?.load(true);
      } catch (error) {
        this.updateResult = { ok: false, message: errorMessage(error, 'The update failed') };
      } finally {
        this.updating = false;
      }
    },
  },
};
</script>
