<template>
  <div class="mfa-step-up-form">
    <!-- Password (always visible) -->
    <b-field
      :label="lang('Your password')"
      :type="errors.password ? 'is-danger' : ''"
      :message="errors.password || ''"
    >
      <b-input
        :value="value.password"
        @input="updateField('password', $event)"
        type="password"
        autocomplete="current-password"
        password-reveal
        :ref="autofocus ? 'pw' : null"
      />
    </b-field>

    <!-- Code (only when showCode=true) -->
    <template v-if="showCode">
      <b-field
        :label="value.useBackup ? lang('Backup code') : lang('6-digit code')"
        :type="errors.code ? 'is-danger' : ''"
        :message="errors.code || ''"
      >
        <b-input
          :value="value.code"
          @input="onCodeInput"
          type="text"
          :placeholder="value.useBackup ? 'XXXXX-XXXXX' : '123456'"
          :style="value.useBackup
            ? 'font-family: monospace; font-size: 1.1em; letter-spacing: 0.05em; text-transform: uppercase'
            : 'font-family: monospace; font-size: 1.2em; letter-spacing: 0.15em'"
          autocomplete="one-time-code"
        />
      </b-field>
      <p class="step-up-toggle">
        <a @click="toggleBackup">
          {{ value.useBackup ? lang('Use authenticator code') : lang('Use a backup code instead') }}
        </a>
      </p>
    </template>
  </div>
</template>

<script>
export default {
  name: 'MfaStepUpForm',
  props: {
    value: {
      type: Object,
      default: () => ({ password: '', code: '', useBackup: false }),
    },
    showCode: {
      type: Boolean,
      default: true,
    },
    errors: {
      type: Object,
      default: () => ({ password: null, code: null }),
    },
    autofocus: {
      type: Boolean,
      default: false,
    },
  },
  mounted() {
    if (this.autofocus) {
      this.$nextTick(() => this.$refs.pw && this.$refs.pw.focus())
    }
  },
  methods: {
    updateField(name, val) {
      this.$emit('input', { ...this.value, [name]: val })
      // Clear the corresponding error on next keystroke
      if (this.errors[name]) {
        this.$emit('clear-error', name)
      }
    },
    onCodeInput(val) {
      // Backup codes uppercase as user types — matches Login.vue:233-239
      const normalised = this.value.useBackup && val ? val.toUpperCase() : val
      this.updateField('code', normalised)
    },
    toggleBackup() {
      this.$emit('input', { ...this.value, useBackup: !this.value.useBackup, code: '' })
    },
  },
}
</script>

<style scoped>
/* Mirror Login.vue's .login-link: small, blue, hover underline */
.step-up-toggle {
  margin-top: 0.25em;
  margin-bottom: 0.75em;
}

.step-up-toggle a {
  font-size: 0.9em;
  color: #3273dc;
  cursor: pointer;
  text-decoration: none;
}

.step-up-toggle a:hover {
  color: #363636;
  text-decoration: underline;
}
</style>
