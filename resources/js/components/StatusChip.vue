<template>
  <span class="chip" :class="style.class">
    <AppIcon :path="style.icon" :size="13" :spin="status === 'processing'" />
    {{ style.label }}
  </span>
</template>

<script>
import { mdiAlertCircle, mdiCancel, mdiCheckCircle, mdiClockOutline, mdiLoading } from '@mdi/js';
import AppIcon from './AppIcon.vue';

const STYLES = {
  pending: { label: 'Queued', icon: mdiClockOutline, class: '!bg-warning-soft !text-warning' },
  processing: { label: 'Working', icon: mdiLoading, class: '!bg-primary-soft !text-on-primary-soft' },
  completed: { label: 'Done', icon: mdiCheckCircle, class: '!bg-success-soft !text-success' },
  failed: { label: 'Failed', icon: mdiAlertCircle, class: '!bg-danger-soft !text-danger' },
  cancelled: { label: 'Cancelled', icon: mdiCancel, class: '' },
};

export default {
  name: 'StatusChip',
  components: { AppIcon },
  props: {
    status: { type: String, required: true },
  },
  computed: {
    style() {
      return STYLES[this.status] || STYLES.pending;
    },
  },
};
</script>
