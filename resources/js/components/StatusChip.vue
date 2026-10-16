<template>
  <span class="chip" :class="style.class">
    <AppIcon :path="style.icon" :size="13" :spin="status === 'processing'" />
    {{ label }}
  </span>
</template>

<script>
import { mdiAlertCircle, mdiCancel, mdiCheckCircle, mdiClockOutline, mdiLoading } from '@mdi/js';
import AppIcon from './AppIcon.vue';

const STYLES = {
  pending: { label: 'Queued', icon: mdiClockOutline, class: '!bg-warning-soft !text-warning' },
  processing: { label: 'Processing', icon: mdiLoading, class: '!bg-primary-soft !text-on-primary-soft' },
  completed: { label: 'Done', icon: mdiCheckCircle, class: '!bg-success-soft !text-success' },
  failed: { label: 'Failed', icon: mdiAlertCircle, class: '!bg-danger-soft !text-danger' },
  cancelled: { label: 'Cancelled', icon: mdiCancel, class: '' },
};

// The job's status text ("Converting to mp3... 40%") → the step it is on
const STEPS = [
  [/fetching video|video info/i, 'Getting info'],
  [/download/i, 'Downloading'],
  [/conver/i, 'Converting'],
  [/metadata|tag|cover/i, 'Tagging'],
  [/lyrics/i, 'Finding lyrics'],
  [/splitting/i, 'Splitting'],
  [/waveform/i, 'Finishing'],
];

export default {
  name: 'StatusChip',
  components: { AppIcon },
  props: {
    status: { type: String, required: true },
    // The download's status_text, used to name the current step while it runs
    text: { type: String, default: '' },
  },
  computed: {
    style() {
      return STYLES[this.status] || STYLES.pending;
    },
    label() {
      if (this.status === 'processing' && this.text) {
        // "Download complete. Preparing for conversion..." is conversion, so test it first
        if (/preparing for conversion/i.test(this.text)) return 'Converting';
        const step = STEPS.find(([pattern]) => pattern.test(this.text));
        if (step) return step[1];
      }
      return this.style.label;
    },
  },
};
</script>
