<template>
  <div
    class="flex items-center gap-3 px-3 py-2.5 sm:px-4"
    :class="selectable && ['cursor-pointer select-none transition-colors', selected ? 'bg-primary-soft/60' : 'hover:bg-surface-2']"
    :role="selectable ? 'checkbox' : undefined"
    :aria-checked="selectable ? String(selected) : undefined"
    :tabindex="selectable ? 0 : undefined"
    @click="selectable && $emit('toggle')"
    @keydown.space.prevent="selectable && $emit('toggle')"
    @keydown.enter.prevent="selectable && $emit('toggle')"
  >
    <input
      v-if="selectable"
      type="checkbox"
      class="checkbox pointer-events-none shrink-0"
      :checked="selected"
      tabindex="-1"
      aria-hidden="true"
    />
    <div class="relative h-11 w-[4.5rem] shrink-0 overflow-hidden rounded-lg bg-surface-3 shadow-[0_2px_6px_-1px_rgb(22_30_50/0.25)] ring-1 ring-black/5 sm:h-12 sm:w-20">
      <img
        v-if="download.thumbnail_url && !thumbFailed"
        :src="download.thumbnail_url"
        alt=""
        loading="lazy"
        class="h-full w-full object-cover"
        @error="thumbFailed = true"
      />
      <div v-else class="grid h-full w-full place-items-center text-fg-muted">
        <AppIcon :path="mdiMusicNote" :size="20" />
      </div>
      <span
        v-if="download.duration"
        class="absolute bottom-0.5 right-0.5 rounded bg-black/70 px-1 text-[10px] font-medium leading-4 text-white"
      >
        {{ formatDuration(download.duration) }}
      </span>
    </div>

    <div class="min-w-0 flex-1">
      <p class="line-clamp-2 break-words text-sm font-medium leading-snug text-fg sm:line-clamp-1" :title="download.title">{{ download.title }}</p>

      <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-fg-muted">
        <StatusChip v-if="showStatus" :status="download.status" :text="download.status_text || ''" class="shrink-0" />
        <span class="chip shrink-0">{{ download.format }}<template v-if="download.format === 'mp3'"> · {{ download.quality }}k</template></span>
        <!-- Where it came from: a YouTube video, or a file the user converted -->
        <span class="chip shrink-0 !normal-case !tracking-normal" :title="source.title">
          <AppIcon :path="source.icon" :size="13" :class="source.iconClass" />
          <span class="hidden sm:inline">{{ source.label }}</span>
        </span>
        <span class="truncate">{{ meta }}</span>
      </div>

      <template v-if="active">
        <LinearProgress class="mt-2" :value="download.progress" />
      </template>
      <p
        v-else-if="download.status === 'failed' && download.error_message"
        class="mt-1 line-clamp-2 text-xs text-danger"
        :title="download.error_message"
      >
        {{ download.error_message }}
      </p>
    </div>

    <div v-if="$slots.actions && !selectable" class="flex shrink-0 items-center gap-0.5">
      <slot name="actions" />
    </div>
  </div>
</template>

<script>
import { mdiFileMusicOutline, mdiMusicNote, mdiYoutube } from '@mdi/js';
import AppIcon from './AppIcon.vue';
import LinearProgress from './LinearProgress.vue';
import StatusChip from './StatusChip.vue';
import { formatBytes, formatDuration, formatRelative } from '../ui';

export default {
  name: 'DownloadRow',
  components: { AppIcon, LinearProgress, StatusChip },
  props: {
    download: { type: Object, required: true },
    showStatus: { type: Boolean, default: true },
    // Selection mode (History → Select): the whole row toggles a checkbox
    selectable: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
  },
  emits: ['toggle'],
  data() {
    return { mdiMusicNote, thumbFailed: false };
  },
  computed: {
    source() {
      const d = this.download;
      if (d.source === 'upload' || d.source === 'local') {
        return {
          label: 'Local file',
          title: d.source_name ? `Converted from ${d.source_name}` : 'Converted from a file',
          icon: mdiFileMusicOutline,
          iconClass: 'text-primary',
        };
      }
      return { label: 'YouTube', title: 'Downloaded from YouTube', icon: mdiYoutube, iconClass: 'text-danger' };
    },
    active() {
      return ['pending', 'processing'].includes(this.download.status);
    },
    meta() {
      const d = this.download;
      if (this.active) {
        const text = d.status_text || (d.status === 'pending' ? 'Waiting in queue…' : 'Starting…');
        return d.progress ? `${d.progress}% · ${text}` : text;
      }
      const parts = [];
      if (d.artist) parts.push(d.artist);
      if (d.file_size) parts.push(formatBytes(d.file_size));
      parts.push(formatRelative(d.completed_at || d.updated_at));
      return parts.join(' · ');
    },
  },
  methods: { formatDuration },
};
</script>
