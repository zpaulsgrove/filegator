<template>
  <div class="container">
    <Menu />
    <div style="padding: 2em 1em; max-width: 720px; margin: 0 auto">
      <h1 class="title is-4">
        {{ lang('Profile') }}
      </h1>

      <!-- Email -->
      <section class="box">
        <h2 class="subtitle is-5">
          {{ lang('Email address') }}
        </h2>
        <p>{{ lang('Used to recover your password if you forget it.') }}</p>
        <br>
        <b-field>
          <b-input v-model="email" type="email" :placeholder="lang('you@example.com')" data-test="security-email-input" />
          <p class="control">
            <button class="button is-primary" @click="saveEmail" :disabled="saving" data-test="security-email-save">
              {{ lang('Save') }}
            </button>
          </p>
        </b-field>
      </section>

      <!-- Change password -->
      <section class="box">
        <h2 class="subtitle is-5">
          {{ lang('Change password') }}
        </h2>
        <b-field :label="lang('Current password')" :type="cpErrors.oldpassword ? 'is-danger' : ''" :message="cpErrors.oldpassword">
          <b-input v-model="oldPw" type="password" password-reveal data-test="security-oldpassword-input" />
        </b-field>
        <b-field :label="lang('New password')" :type="cpErrors.newpassword ? 'is-danger' : ''" :message="cpErrors.newpassword">
          <b-input v-model="newPw" type="password" password-reveal data-test="security-newpassword-input" />
        </b-field>
        <!-- Second factor required when MFA is enrolled; the current-password
             field above doubles as the step-up password. -->
        <MfaStepUpForm
          v-if="state && state.enabled"
          v-model="cpStepUp"
          :show-password="false"
          :show-code="true"
          :errors="cpErrors"
          testid="security-stepup"
          @clear-error="cpErrors = { ...cpErrors, [$event]: null }"
        />
        <div class="is-flex is-justify-content-end">
          <button class="button is-primary" @click="changePassword" data-test="security-password-update">
            {{ lang('Update password') }}
          </button>
        </div>
      </section>

      <!-- MFA -->
      <section class="box">
        <h2 class="subtitle is-5">
          {{ lang('Multi-factor authentication') }}
        </h2>

        <div v-if="state === null">
          {{ lang('Loading…') }}
        </div>

        <div v-else-if="state.enabled">
          <p>{{ lang('MFA is enabled on your account.') }} <strong>{{ state.backup_codes_remaining }}</strong> {{ lang('backup code(s) remaining.') }}</p>
          <br>
          <div class="buttons">
            <button class="button" @click="openManage('regenerate')" data-test="security-mfa-regenerate">
              {{ lang('Regenerate backup codes') }}
            </button>
            <button class="button is-danger is-light" v-if="!state.required_by_role" @click="openManage('disable')" data-test="security-mfa-disable">
              {{ lang('Disable MFA') }}
            </button>
            <span v-else class="tag is-info is-light" style="align-self: center; margin-left: .5em">
              {{ lang('Required by your role') }}
            </span>
          </div>
        </div>

        <div v-else-if="enrollment">
          <div class="content" data-test="security-enroll-instructions">
            <p>{{ lang('To finish enabling MFA:') }}</p>
            <ol>
              <li>{{ lang('Download an authenticator app onto your smartphone, such as Microsoft Authenticator, Google Authenticator, Authy, or 1Password.') }}</li>
              <li>{{ lang('Open the app and scan the QR code below.') }}</li>
              <li>{{ lang('Enter the 6-digit code the app shows into the field below, then click Verify.') }}</li>
            </ol>
          </div>
          <div class="has-text-centered" style="margin: 1em 0">
            <canvas ref="qrCanvas" />
          </div>
          <p class="has-text-grey is-size-7">
            {{ lang("Can't scan? Enter this key into the app manually:") }}
          </p>
          <p style="font-family: monospace; word-break: break-all; font-size: 0.9em">
            {{ lang('Manual key') }}: <span data-test="security-enroll-secret">{{ enrollment.secret }}</span>
          </p>
          <br>
          <b-field :label="lang('6-digit code')">
            <b-input v-model="enrollCode" placeholder="123456" data-test="security-enroll-code" />
          </b-field>
          <div class="buttons is-right" style="margin-top: 1em; margin-bottom: 0">
            <button class="button" @click="cancelEnroll" data-test="security-enroll-cancel">
              {{ lang('Cancel') }}
            </button>
            <button class="button is-primary" @click="confirmEnroll" data-test="security-enroll-verify">
              {{ lang('Verify') }}
            </button>
          </div>
        </div>

        <div v-else>
          <p>{{ lang('Add a second factor with a TOTP authenticator app.') }}</p>
          <p class="has-text-grey is-size-7" data-test="security-mfa-about">
            {{ lang('Multi-factor authentication adds a one-time code from your phone on top of your password, so your account stays protected even if your password is leaked.') }}
          </p>
          <br>
          <button class="button is-primary" @click="beginEnroll" data-test="security-enable-mfa">
            {{ lang('Enable MFA') }}
          </button>
        </div>

        <!--
          Backup codes are shown OUTSIDE the v-if/v-else-if chain so they
          survive the state transition. confirmEnroll succeeds → state.enabled
          flips to true → the parent chain switches branches → without this
          being top-level, the codes would unmount before the user could read
          them.
        -->
        <div v-if="backupCodes" class="notification is-warning" style="margin-top: 1em" data-test="security-backup-codes">
          <p><strong>{{ lang('Save these backup codes') }}</strong></p>
          <p>{{ lang('Each can be used once if you lose access to your authenticator. They will not be shown again.') }}</p>
          <ul style="font-family: monospace; margin-top: 0.5em">
            <li v-for="c in backupCodes" :key="c">
              {{ c }}
            </li>
          </ul>
          <div class="buttons is-right" style="margin-top: 1em; margin-bottom: 0">
            <button class="button is-primary" @click="dismissBackupCodes" data-test="security-backup-codes-dismiss">
              {{ lang("I've saved them") }}
            </button>
          </div>
        </div>
      </section>

      <!-- Step-up modal for changing email (MFA-enrolled users only) -->
      <b-modal :active.sync="emailStepUpOpen" has-modal-card>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title">
              {{ lang('Confirm email change') }}
            </p>
          </header>
          <section class="modal-card-body">
            <MfaStepUpForm
              v-model="emailStepUpForm"
              :show-code="true"
              :errors="emailStepUpErrors"
              testid="security-email-stepup"
              @clear-error="emailStepUpErrors = { ...emailStepUpErrors, [$event]: null }"
            />
          </section>
          <footer class="modal-card-foot">
            <button class="button" @click="emailStepUpOpen = false" :disabled="saving" data-test="security-email-stepup-cancel">
              {{ lang('Cancel') }}
            </button>
            <button class="button is-primary" @click="performEmailStepUp" :disabled="saving" :class="{ 'is-loading': saving }" data-test="security-email-stepup-continue">
              {{ lang('Continue') }}
            </button>
          </footer>
        </div>
      </b-modal>

      <!-- Re-auth modal for disable / regenerate -->
      <b-modal :active.sync="manageOpen" has-modal-card>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title">
              {{ manageMode === 'disable' ? lang('Disable MFA') : lang('Regenerate backup codes') }}
            </p>
          </header>
          <section class="modal-card-body">
            <MfaStepUpForm
              v-model="manageForm"
              :show-code="true"
              :errors="manageFormErrors"
              testid="security-manage-stepup"
              @clear-error="manageFormErrors = { ...manageFormErrors, [$event]: null }"
            />
          </section>
          <footer class="modal-card-foot">
            <button class="button" @click="manageOpen = false" :disabled="managing" data-test="security-manage-cancel">
              {{ lang('Cancel') }}
            </button>
            <button class="button is-primary" @click="performManage" :disabled="managing" :class="{ 'is-loading': managing }" data-test="security-manage-continue">
              {{ lang('Continue') }}
            </button>
          </footer>
        </div>
      </b-modal>
    </div>
  </div>
</template>

<script>
import Menu from './partials/Menu'
import MfaStepUpForm from './partials/MfaStepUpForm'
import api from '../api/api'
import QRCode from 'qrcode'

export default {
  name: 'Security',
  components: { Menu, MfaStepUpForm },
  data() {
    return {
      state: null,
      email: '',
      saving: false,
      enrollment: null,
      enrollCode: '',
      backupCodes: null,
      oldPw: '',
      newPw: '',
      cpErrors: {},
      // Step-up second factor for change-password, shown inline only when MFA
      // is enrolled. The "current password" field doubles as the step-up
      // password, so only code/useBackup are read here.
      cpStepUp: { password: '', code: '', useBackup: false },
      // Step-up modal for changing email (MFA-enrolled users only).
      emailStepUpOpen: false,
      emailStepUpForm: { password: '', code: '', useBackup: false },
      emailStepUpErrors: { password: null, code: null },
      manageOpen: false,
      manageMode: 'disable',
      manageForm: { password: '', code: '', useBackup: false },
      manageFormErrors: { password: null, code: null },
      // R-2: in-flight guard so users can't spam Confirm into a per-IP 429.
      // Mirrors the `submitting` flag on StepUpDialog.
      managing: false,
    }
  },
  mounted() {
    this.refresh()
  },
  methods: {
    refresh() {
      api.mfaState().then(s => {
        this.state = s
        this.email = s.email || ''
      }).catch(e => this.handleError(e))
    },
    saveEmail() {
      // No MFA enrolled → step-up is a backend no-op; submit directly and keep
      // the original one-click UX. MFA enrolled → collect the second factor in
      // the step-up modal first.
      if (!this.state || !this.state.enabled) {
        this.submitEmail({})
        return
      }
      this.emailStepUpForm = { password: '', code: '', useBackup: false }
      this.emailStepUpErrors = { password: null, code: null }
      this.emailStepUpOpen = true
    },
    performEmailStepUp() {
      // R-2 in-flight guard, mirroring performManage.
      if (this.saving) return
      this.emailStepUpErrors = { password: null, code: null }
      this.submitEmail({
        password: this.emailStepUpForm.password,
        code: this.emailStepUpForm.code,
        useBackup: this.emailStepUpForm.useBackup,
      })
    },
    submitEmail(creds) {
      this.saving = true
      api.updateMyEmail({ email: this.email, ...creds })
        .then(() => {
          this.emailStepUpOpen = false
          this.$toast.open({ message: this.lang('Saved'), type: 'is-success' })
        })
        .catch(e => {
          const status = e.response && e.response.status
          const body = e.response && e.response.data && e.response.data.data
          // Map step-up field errors inline while the modal is open.
          if (status === 422 && this.emailStepUpOpen && typeof body === 'object' && body !== null && !Array.isArray(body)) {
            const next = { password: null, code: null }
            if (typeof body.password === 'string') { next.password = body.password; this.emailStepUpForm.password = '' }
            if (typeof body.code === 'string') { next.code = body.code; this.emailStepUpForm.code = '' }
            if (next.password || next.code) {
              this.emailStepUpErrors = next
              return
            }
          }
          // Email-specific validation error (e.g. invalid address).
          if (body && body.email) {
            this.$toast.open({ message: this.lang(body.email), type: 'is-danger' })
            return
          }
          this.handleError(e)
        })
        .finally(() => { this.saving = false })
    },
    changePassword() {
      this.cpErrors = {}
      api.changePassword({
        oldpassword: this.oldPw,
        newpassword: this.newPw,
        code: this.cpStepUp.code,
        useBackup: this.cpStepUp.useBackup,
      })
        .then(() => {
          this.oldPw = ''
          this.newPw = ''
          this.cpStepUp = { password: '', code: '', useBackup: false }
          this.$toast.open({ message: this.lang('Password updated'), type: 'is-success' })
        })
        .catch(errors => {
          if (errors.response && errors.response.data) {
            const d = errors.response.data.data
            if (typeof d === 'object' && d !== null) {
              this.cpErrors = d
              // Clear a failed/consumed code so the user enters a fresh one
              // (a TOTP can't be replayed across attempts).
              if (d.code) this.cpStepUp = { ...this.cpStepUp, code: '' }
            } else {
              this.handleError(errors)
            }
          }
        })
    },
    beginEnroll() {
      api.mfaBeginEnroll().then(data => {
        this.enrollment = data
        this.backupCodes = null
        this.enrollCode = ''
        this.$nextTick(() => this.drawQr())
      }).catch(e => this.handleError(e))
    },
    drawQr() {
      if (!this.$refs.qrCanvas || !this.enrollment) return
      QRCode.toCanvas(this.$refs.qrCanvas, this.enrollment.otpauth_uri, { width: 220 }, () => {})
    },
    confirmEnroll() {
      api.mfaConfirmEnroll({ code: this.enrollCode })
        .then(res => {
          this.backupCodes = res.backup_codes
          this.refresh()
        })
        .catch(err => {
          // Only a genuine 422 means the submitted code was rejected; surface
          // the server's specific message ('Invalid code' / field error).
          // Everything else — 501 not supported, an expired session, a
          // transient network or 5xx error — defers to the shared handler
          // rather than being mislabelled 'Invalid code'.
          if (err && err.response && err.response.status === 422) {
            const body = err.response.data && err.response.data.data
            const message = (body && typeof body === 'object' && typeof body.code === 'string')
              ? body.code
              : this.lang('Invalid code')
            this.$toast.open({ message, type: 'is-danger' })
            return
          }
          this.handleError(err)
        })
    },
    cancelEnroll() {
      this.enrollment = null
      this.backupCodes = null
    },
    dismissBackupCodes() {
      this.backupCodes = null
      this.enrollment = null
    },
    openManage(mode) {
      this.manageMode = mode
      this.manageOpen = true
      this.manageForm = { password: '', code: '', useBackup: false }
      this.manageFormErrors = { password: null, code: null }
      this.managing = false
    },
    performManage() {
      // R-2 in-flight guard. Without this, the user can spam the Confirm
      // button on a slow connection and rack up failed attempts against the
      // per-IP lockout budget.
      if (this.managing) return
      this.managing = true
      const args = {
        password: this.manageForm.password,
        code: this.manageForm.code,
        useBackup: this.manageForm.useBackup,
      }
      const call = this.manageMode === 'disable'
        ? api.mfaDisable(args)
        : api.mfaRegenerateBackupCodes(args)
      // Clear stale field errors before retry.
      this.manageFormErrors = { password: null, code: null }
      call.then(res => {
        this.manageOpen = false
        if (this.manageMode === 'regenerate') {
          this.backupCodes = res.backup_codes
        } else {
          this.$toast.open({ message: this.lang('MFA disabled'), type: 'is-success' })
        }
        this.refresh()
      }).catch(err => {
        // Map 422 field errors inline (canonical pattern; mirrors
        // changePassword at lines 215-229). Replaces the previous bare-catch
        // swallow that surfaced a generic toast for wrong-password and
        // wrong-code alike.
        if (err && err.response && err.response.status === 422) {
          const body = err.response.data && err.response.data.data
          if (typeof body === 'object' && body !== null && !Array.isArray(body)) {
            const next = { password: null, code: null }
            if (typeof body.password === 'string') {
              next.password = body.password
              this.manageForm.password = ''
            }
            if (typeof body.code === 'string') {
              next.code = body.code
              this.manageForm.code = ''
            }
            if (next.password || next.code) {
              this.manageFormErrors = next
              return
            }
          }
        }
        this.$toast.open({ message: this.lang('Verification failed'), type: 'is-danger' })
      }).finally(() => {
        this.managing = false
      })
    },
  },
}
</script>
