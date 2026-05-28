<template>
  <div class="modal-card">
    <header class="modal-card-head">
      <p class="modal-card-title">{{ actionDescription }}</p>
    </header>
    <section class="modal-card-body">
      <!-- Danger warning (only when dangerWarning prop is non-null AND not locked out).
           Rendered with {{ }} interpolation ONLY — never v-html.
           XSS hardening: usernames flow through props from user data.
           Hidden during lockout to avoid the confusing "this action permanently
           removes / Too many attempts" co-render that implies a partial effect. -->
      <b-notification
        v-if="dangerWarning && !lockedOut"
        type="is-warning"
        :closable="false"
        role="alert"
      >
        {{ dangerWarning }}
      </b-notification>

      <p v-if="!lockedOut" style="margin-bottom: 1em">
        {{ confirmInstructions }}
      </p>

      <!-- 429 lockout banner — replaces form when triggered -->
      <b-notification
        v-if="lockedOut"
        type="is-danger"
        :closable="false"
        role="alert"
      >
        {{ lang('Too many attempts. Try again in a few minutes.') }}
      </b-notification>

      <!-- Generic 422 string error -->
      <b-notification
        v-if="genericError && !lockedOut"
        type="is-danger"
        :closable="false"
        role="alert"
      >
        {{ genericError }}
      </b-notification>

      <!-- The shared form -->
      <MfaStepUpForm
        v-if="!lockedOut"
        v-model="form"
        :show-code="mfaEnabled"
        :errors="errors"
        :autofocus="true"
        @clear-error="onClearError"
      />
    </section>
    <footer class="modal-card-foot" style="justify-content: flex-end">
      <button class="button" type="button" @click="cancel" :disabled="submitting">
        {{ lang('Cancel') }}
      </button>
      <button
        class="button is-primary"
        type="button"
        @click="confirm"
        :disabled="submitting || lockedOut || !canSubmit"
        :class="{ 'is-loading': submitting }"
      >
        {{ lang('Confirm') }}
      </button>
    </footer>
  </div>
</template>

<script>
import MfaStepUpForm from './MfaStepUpForm.vue'

export default {
  name: 'StepUpDialog',
  components: { MfaStepUpForm },
  props: {
    actionDescription: { type: String, required: true },
    dangerWarning: { type: String, default: null },
    mfaEnabled: { type: Boolean, required: true },
    onConfirm: { type: Function, required: true },
  },
  data() {
    return {
      form: { password: '', code: '', useBackup: false },
      errors: { password: null, code: null },
      genericError: null,
      submitting: false,
      lockedOut: false,
      // `settled` flips true once one of confirmed/cancel/error has been
      // emitted, so the beforeDestroy fallback (R-1) doesn't double-emit on
      // a normal lifecycle close.
      settled: false,
    }
  },
  beforeDestroy() {
    // R-1: Buefy b-modal has close paths besides our Confirm/Cancel buttons —
    // backdrop click, Escape key, programmatic close, parent unmount. None of
    // those emit our events on their own. Without this hook, the helper's
    // outer Promise hangs forever and the caller's .then/.catch never fires.
    // Emit cancel-as-default so the helper always settles.
    if (!this.settled) {
      this.settled = true
      this.$emit('cancel')
    }
  },
  computed: {
    confirmInstructions() {
      if (this.mfaEnabled) {
        return this.lang('Enter your password and a current 6-digit code or backup code to confirm.')
      }
      return this.lang('Enter your password to confirm.')
    },
    canSubmit() {
      const hasPassword = this.form.password.length > 0
      const hasCode = !this.mfaEnabled || this.form.code.length > 0
      return hasPassword && hasCode
    },
  },
  methods: {
    onClearError(name) {
      this.errors = { ...this.errors, [name]: null }
    },
    cancel() {
      if (this.submitting) return
      // Emit 'cancel' so workstream 5 (withStepUp.js) can catch it and throw
      // the sentinel rejection — keeping the sentinel contract out of this component.
      this.settled = true
      this.$emit('cancel')
      this.$parent.close()
    },
    async confirm() {
      if (this.submitting || this.lockedOut || !this.canSubmit) return
      this.submitting = true
      this.errors = { password: null, code: null }
      this.genericError = null
      try {
        const stepUpFields = {
          stepup_password: this.form.password,
          stepup_code: this.form.code,
          stepup_use_backup: this.form.useBackup,
        }
        const result = await this.onConfirm(stepUpFields)
        // Success — emit and close.
        this.settled = true
        this.$emit('confirmed', result)
        this.$parent.close()
      } catch (err) {
        this.handleSubmitError(err)
      } finally {
        this.submitting = false
      }
    },
    handleSubmitError(err) {
      const status = err && err.response ? err.response.status : null
      const body = err && err.response && err.response.data ? err.response.data.data : null

      if (status === 429) {
        this.lockedOut = true
        return
      }

      if (status === 422 && body && typeof body === 'object' && !Array.isArray(body)) {
        let mappedAny = false
        if (typeof body.password === 'string') {
          this.errors.password = body.password
          this.form.password = ''
          mappedAny = true
        }
        if (typeof body.code === 'string') {
          this.errors.code = body.code
          this.form.code = ''
          mappedAny = true
        }
        if (mappedAny) {
          return
        }
      }

      if (status === 422 && typeof body === 'string') {
        this.genericError = body
        return
      }

      // Any other error: emit and close so caller's existing error toast fires.
      // Synthesise a real Error when err is falsy so callers' handleError(undefined)
      // doesn't render an opaque "Unknown error" toast with no diagnostic. ADV-006.
      const out = err || new Error('Step-up failed (no error details)')
      this.settled = true
      this.$emit('error', out)
      this.$parent.close()
    },
  },
}
</script>
