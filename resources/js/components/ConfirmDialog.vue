<template>
  <Transition
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
    enter-active-class="transition duration-150"
    leave-active-class="transition duration-100"
  >
    <div
      v-if="ui.dialog"
      class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
      @click.self="closeDialog(false)"
      @keydown.esc="closeDialog(false)"
    >
      <div
        ref="panel"
        class="card w-full max-w-sm p-5 animate-fade-up"
        role="alertdialog"
        aria-modal="true"
        :aria-labelledby="'dialog-title'"
        tabindex="-1"
      >
        <h2 id="dialog-title" class="text-base font-semibold text-fg">{{ ui.dialog.title }}</h2>
        <p v-if="ui.dialog.message" class="mt-2 text-sm text-fg-muted">{{ ui.dialog.message }}</p>

        <label v-if="ui.dialog.checkbox" class="mt-4 flex cursor-pointer items-center gap-3 text-sm text-fg">
          <input v-model="ui.dialog.checked" type="checkbox" class="checkbox" />
          {{ ui.dialog.checkbox }}
        </label>

        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="btn btn-text" @click="closeDialog(false)">Cancel</button>
          <button
            ref="confirm"
            type="button"
            class="btn"
            :class="ui.dialog.danger ? 'btn-danger' : 'btn-filled'"
            @click="closeDialog(true)"
          >
            {{ ui.dialog.confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script>
import { closeDialog, ui } from '../ui';

export default {
  name: 'ConfirmDialog',
  data() {
    return { ui };
  },
  watch: {
    'ui.dialog'(dialog) {
      if (dialog) this.$nextTick(() => this.$refs.confirm?.focus());
    },
  },
  methods: { closeDialog },
};
</script>
