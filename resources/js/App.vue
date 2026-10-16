<template>
  <div class="min-h-dvh md:flex">
    <!-- Navigation rail (tablet) / drawer (desktop) -->
    <nav
      class="sticky top-0 z-20 hidden h-dvh shrink-0 flex-col border-r border-line bg-surface shadow-[4px_0_24px_-12px_rgb(22_30_50/0.18)] md:flex md:w-20 lg:w-60"
      aria-label="Main"
    >
      <router-link to="/" class="flex h-16 items-center gap-3 px-5 lg:px-5">
        <span class="btn-filled grid h-9 w-9 shrink-0 place-items-center rounded-xl">
          <AppIcon :path="mdiMusicNote" :size="22" />
        </span>
        <span class="hidden text-[0.9375rem] font-semibold leading-tight text-fg lg:block">AudioFetch</span>
      </router-link>

      <div class="flex flex-1 flex-col gap-1 px-3 py-2">
        <router-link
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="group relative flex flex-col items-center gap-1 rounded-full py-2 text-xs font-medium text-fg-muted transition-colors hover:text-fg lg:flex-row lg:gap-3 lg:px-4 lg:py-2.5 lg:text-sm lg:hover:bg-surface-2"
          :class="isActive(item) && '!text-fg lg:!bg-surface lg:!text-primary lg:shadow-[inset_0_1px_0_var(--md-highlight),var(--md-btn-shadow)] lg:ring-1 lg:ring-line'"
        >
          <span
            class="grid h-8 w-14 place-items-center rounded-full transition-colors lg:h-auto lg:w-auto"
            :class="isActive(item) ? 'bg-primary-soft text-on-primary-soft shadow-[inset_0_1px_0_var(--md-highlight),var(--md-btn-shadow)] lg:bg-transparent lg:text-primary lg:shadow-none' : 'group-hover:bg-surface-2 lg:group-hover:bg-transparent'"
          >
            <AppIcon :path="isActive(item) ? item.iconActive : item.icon" :size="22" />
          </span>
          <span>{{ item.label }}</span>
          <span
            v-if="item.to === '/queue' && activeCount"
            class="absolute right-2 top-1 grid h-4 min-w-4 place-items-center rounded-full bg-primary px-1 text-[10px] font-semibold text-on-primary lg:static lg:ml-auto lg:h-5 lg:min-w-5"
          >
            {{ activeCount }}
          </span>
        </router-link>
      </div>

      <div class="flex flex-col items-center gap-1 px-3 pb-4 lg:items-stretch">
        <button
          v-if="ui.installPrompt"
          type="button"
          class="flex items-center justify-center gap-3 rounded-xl py-2.5 text-sm font-medium text-primary hover:bg-surface-2 lg:justify-start lg:px-3"
          aria-label="Install app"
          title="Install as an app"
          @click="installApp"
        >
          <AppIcon :path="mdiMonitorArrowDown" :size="22" />
          <span class="hidden lg:inline">Install app</span>
        </button>
        <button
          type="button"
          class="flex items-center justify-center gap-3 rounded-xl py-2.5 text-sm font-medium text-fg-muted hover:bg-surface-2 hover:text-fg lg:justify-start lg:px-3"
          :aria-label="themeLabel"
          :title="themeLabel"
          @click="toggleTheme"
        >
          <AppIcon :path="ui.theme === 'dark' ? mdiWeatherSunny : mdiWeatherNight" :size="22" />
          <span class="hidden lg:inline">{{ ui.theme === 'dark' ? 'Light mode' : 'Dark mode' }}</span>
        </button>
      </div>
    </nav>

    <div class="flex min-w-0 flex-1 flex-col">
      <!-- Top app bar (phone) -->
      <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-line bg-surface/90 px-3 backdrop-blur md:hidden">
        <router-link to="/" class="flex items-center gap-2.5">
          <span class="btn-filled grid h-8 w-8 place-items-center rounded-lg">
            <AppIcon :path="mdiMusicNote" :size="20" />
          </span>
          <span class="text-[0.9375rem] font-semibold text-fg">AudioFetch</span>
        </router-link>
        <div class="ml-auto flex items-center">
          <button v-if="ui.installPrompt" type="button" class="icon-btn !text-primary" aria-label="Install app" @click="installApp">
            <AppIcon :path="mdiCellphoneArrowDown" />
          </button>
          <button type="button" class="icon-btn" :aria-label="themeLabel" @click="toggleTheme">
            <AppIcon :path="ui.theme === 'dark' ? mdiWeatherSunny : mdiWeatherNight" />
          </button>
          <router-link to="/settings" class="icon-btn" aria-label="Settings" :class="$route.path === '/settings' && '!text-primary'">
            <AppIcon :path="$route.path === '/settings' ? mdiCog : mdiCogOutline" />
          </router-link>
        </div>
      </header>

      <main class="mx-auto w-full max-w-5xl flex-1 px-4 pt-5 sm:px-6 md:pt-8" :class="player.track ? 'pb-52 md:pb-32' : 'pb-28 md:pb-10'">
        <WorkerBanner v-if="workerDown" />
        <router-view v-slot="{ Component }">
          <component :is="Component" />
        </router-view>
      </main>
    </div>

    <!-- Bottom navigation (phone) -->
    <nav
      class="fixed inset-x-0 bottom-0 z-30 flex border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] shadow-[0_-6px_20px_-12px_rgb(22_30_50/0.25)] md:hidden"
      aria-label="Main"
    >
      <router-link
        v-for="item in mobileNavItems"
        :key="item.to"
        :to="item.to"
        class="relative flex flex-1 flex-col items-center gap-0.5 pb-2 pt-2.5 text-[11px] font-medium text-fg-muted"
        :class="isActive(item) && '!text-fg'"
      >
        <span
          class="grid h-7 w-14 place-items-center rounded-full transition-colors"
          :class="isActive(item) && 'bg-primary-soft text-on-primary-soft shadow-[inset_0_1px_0_var(--md-highlight),var(--md-btn-shadow)]'"
        >
          <AppIcon :path="isActive(item) ? item.iconActive : item.icon" :size="22" />
        </span>
        {{ item.label }}
        <span
          v-if="item.to === '/queue' && activeCount"
          class="absolute left-1/2 top-1.5 ml-2 grid h-4 min-w-4 place-items-center rounded-full bg-primary px-1 text-[10px] font-semibold text-on-primary"
        >
          {{ activeCount }}
        </span>
      </router-link>
    </nav>

    <PlayerBar />
    <SnackbarHost :raised="!!player.track" />
    <ConfirmDialog />
    <LockScreen v-if="ui.locked" />
  </div>
</template>

<script>
import {
  mdiCog,
  mdiCogOutline,
  mdiDownloadCircle,
  mdiDownloadCircleOutline,
  mdiHistory,
  mdiHome,
  mdiHomeOutline,
  mdiMusicNote,
  mdiPlaylistMusic,
  mdiPlaylistMusicOutline,
  mdiTrayArrowDown,
  mdiTrayFull,
  mdiCellphoneArrowDown,
  mdiMonitorArrowDown,
  mdiWeatherNight,
  mdiWeatherSunny,
} from '@mdi/js';
import AppIcon from './components/AppIcon.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';
import LockScreen from './components/LockScreen.vue';
import PlayerBar from './components/PlayerBar.vue';
import SnackbarHost from './components/SnackbarHost.vue';
import WorkerBanner from './components/WorkerBanner.vue';
import { player } from './player';
import { checkFinishedDownloads } from './notifications';
import { lockAPI, queueAPI } from './services/api';
import { installApp, toggleTheme, ui } from './ui';

const NAV = [
  { to: '/', label: 'Home', icon: mdiHomeOutline, iconActive: mdiHome },
  { to: '/download', label: 'Download', icon: mdiDownloadCircleOutline, iconActive: mdiDownloadCircle },
  { to: '/playlist', label: 'Playlist', icon: mdiPlaylistMusicOutline, iconActive: mdiPlaylistMusic },
  { to: '/queue', label: 'Queue', icon: mdiTrayArrowDown, iconActive: mdiTrayFull },
  { to: '/history', label: 'History', icon: mdiHistory, iconActive: mdiHistory },
  { to: '/settings', label: 'Settings', icon: mdiCogOutline, iconActive: mdiCog },
];

export default {
  name: 'App',
  components: { AppIcon, ConfirmDialog, LockScreen, PlayerBar, SnackbarHost, WorkerBanner },
  data() {
    return {
      ui,
      player,
      navItems: NAV,
      mobileNavItems: NAV.filter((item) => item.to !== '/settings'),
      activeCount: 0,
      workerDown: false,
      timer: null,
      mdiCog,
      mdiCogOutline,
      mdiMusicNote,
      mdiCellphoneArrowDown,
      mdiMonitorArrowDown,
      mdiWeatherNight,
      mdiWeatherSunny,
    };
  },
  computed: {
    themeLabel() {
      return ui.theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
    },
  },
  mounted() {
    this.checkLock();
    this.refreshQueue();
    this.timer = setInterval(this.refreshQueue, 5000);
  },
  beforeUnmount() {
    clearInterval(this.timer);
  },
  methods: {
    installApp,
    toggleTheme,
    isActive(item) {
      return item.to === '/' ? this.$route.path === '/' : this.$route.path.startsWith(item.to);
    },
    async checkLock() {
      try {
        const { data } = await lockAPI.status();
        ui.lockEnabled = data.enabled;
        ui.locked = !data.unlocked;
      } catch {
        // Older server or offline: API calls report problems themselves
      }
    },
    async refreshQueue() {
      if (ui.locked) return;
      try {
        const { data } = await queueAPI.getStatus();
        // Rows in the jobs table: waiting jobs plus the ones a worker is running
        this.activeCount = data.pending;
        // Only warn when something is actually waiting for a worker
        this.workerDown = data.worker_running === false && data.pending > 0;
        checkFinishedDownloads(data.pending).catch(() => {});
      } catch {
        // The current page reports API errors itself
      }
    },
  },
};
</script>
