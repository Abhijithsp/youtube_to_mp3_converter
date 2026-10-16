<template>
  <div class="fixed inset-0 z-[60] grid place-items-center bg-bg px-4">
    <form class="card w-full max-w-sm p-6 text-center animate-fade-up" style="box-shadow: var(--md-elev-2)" @submit.prevent="unlock">
      <span class="btn-filled mx-auto grid h-12 w-12 place-items-center rounded-2xl">
        <AppIcon :path="mdiLockOutline" :size="26" />
      </span>
      <h1 class="mt-4 text-lg font-semibold text-fg">AudioFetch</h1>
      <p class="mt-1 text-sm text-fg-muted">This app is password protected.</p>

      <div class="mt-5 text-left">
        <label for="unlock-password" class="field-label">Password</label>
        <div class="relative">
          <input
            id="unlock-password"
            ref="password"
            v-model="password"
            :type="show ? 'text' : 'password'"
            class="field !pr-11"
            :class="error && 'field-error'"
            autocomplete="current-password"
            required
          />
          <button
            type="button"
            class="icon-btn absolute right-1 top-1/2 -translate-y-1/2"
            :aria-label="show ? 'Hide password' : 'Show password'"
            @click="show = !show"
          >
            <AppIcon :path="show ? mdiEyeOff : mdiEye" :size="20" />
          </button>
        </div>
        <p v-if="error" class="field-hint !text-danger">{{ error }}</p>
        <p v-else class="field-hint">Remembered on this device for 30 days.</p>
      </div>

      <button type="submit" class="btn btn-filled mt-5 w-full" :disabled="busy || !password">
        <AppIcon :path="busy ? mdiLoading : mdiLockOpenVariantOutline" :size="18" :spin="busy" />
        {{ busy ? 'Checking…' : 'Unlock' }}
      </button>
    </form>
  </div>
</template>

<script>
import { mdiEye, mdiEyeOff, mdiLoading, mdiLockOpenVariantOutline, mdiLockOutline } from '@mdi/js';
import AppIcon from './AppIcon.vue';
import { lockAPI } from '../services/api';
import { errorMessage } from '../ui';

export default {
  name: 'LockScreen',
  components: { AppIcon },
  data() {
    return {
      password: '',
      show: false,
      busy: false,
      error: '',
      mdiEye,
      mdiEyeOff,
      mdiLoading,
      mdiLockOpenVariantOutline,
      mdiLockOutline,
    };
  },
  mounted() {
    this.$refs.password?.focus();
  },
  methods: {
    async unlock() {
      this.busy = true;
      this.error = '';
      try {
        await lockAPI.unlock(this.password);
        // Start fresh so every page loads its data now that the API answers
        window.location.reload();
      } catch (error) {
        this.error = error.response?.status === 429
          ? 'Too many attempts. Wait a minute and try again.'
          : errorMessage(error, 'Could not unlock');
        this.busy = false;
        this.$nextTick(() => this.$refs.password?.select());
      }
    },
  },
};
</script>
