/**
 * Security.vue — characterization tests (Workstream 0)
 *
 * These tests pin the CURRENT behavior of Security.vue before Workstream 7
 * refactors it. They are intentionally written against behavior that will
 * change — comments call out exactly which assertions Workstream 7 must flip.
 *
 * Lines characterized in Security.vue:
 *   - L54-68  : MFA-enabled branch rendering (state.enabled = true)
 *   - L277-296: performManage() — catches ALL errors with a generic
 *               "Verification failed" toast (the swallow bug)
 *   - L268-275: onReauthInput() / toggleReauthBackup() — uppercase + mode switch
 *   - L215-229: changePassword() — CANONICAL inline cpErrors pattern
 *
 * Mocking strategy (matches Menu.spec.js / Login.spec.js conventions):
 *   - api module: jest.mock with __esModule:true, default: { … }
 *   - $store: plain object stub (no full Vuex) — Security.vue only reads
 *     $store.state.user.name / role; it does NOT read mfa_enabled from the
 *     store — MFA state comes from api.mfaState() resolved in mounted().
 *   - lang / handleError: mocked via mocks option
 *   - $toast: { open: jest.fn() } — Security.vue uses this.$toast.open(…)
 *   - Buefy stubs: shallowMount stubs everything except what we inspect
 *   - qrcode: mocked to prevent canvas errors in jsdom
 */

import { shallowMount } from '@vue/test-utils'
import Security from '@/views/Security.vue'

// ── Helpers ──────────────────────────────────────────────────────────────────

// Replicate flush-promises without the package.
function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Module mocks ─────────────────────────────────────────────────────────────

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    mfaState: jest.fn(),
    mfaDisable: jest.fn(),
    mfaRegenerateBackupCodes: jest.fn(),
    mfaBeginEnroll: jest.fn(),
    mfaConfirmEnroll: jest.fn(),
    changePassword: jest.fn(),
    updateMyEmail: jest.fn(),
  },
}))

// QRCode.toCanvas is called in drawQr() inside $nextTick; stub it to prevent
// canvas-API errors in jsdom.
jest.mock('qrcode', () => ({
  toCanvas: jest.fn((canvas, uri, opts, cb) => cb && cb()),
}))

// Import AFTER jest.mock so we get the mocked versions.
const api = require('@/api/api').default

// ── Default mfaState response (MFA enabled, not role-required) ────────────────

const MFA_ENABLED_STATE = {
  enabled: true,
  required_by_role: false,
  backup_codes_remaining: 3,
  email: 'test@example.com',
}

const MFA_DISABLED_STATE = {
  enabled: false,
  required_by_role: false,
  backup_codes_remaining: 0,
  email: 'test@example.com',
}

// ── Mount helper ──────────────────────────────────────────────────────────────

/**
 * Mount Security.vue with all external dependencies stubbed.
 *
 * Security.vue calls api.mfaState() in mounted(); callers should set
 * api.mfaState to a resolved value before calling mountSecurity so that
 * `this.state` is populated after flushPromises().
 */
function mountSecurity() {
  return shallowMount(Security, {
    mocks: {
      $store: {
        state: {
          user: { name: 'Test User', role: 'user' },
        },
        getters: { hasPermissions: () => false },
        commit: jest.fn(),
      },
      $router: { push: jest.fn() },
      $route: {},
      // shared mixin stubs
      lang: s => s,
      handleError: jest.fn(),
      can: () => false,
      $toast: { open: jest.fn() },
    },
    stubs: {
      Menu: true,
      MfaStepUpForm: true,
      'b-field': true,
      'b-input': true,
      'b-modal': true,
      'b-icon': true,
    },
  })
}

// ── Reset mocks between tests ─────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks()
  // Default: mfaState resolves with MFA enabled so most tests start in a
  // sensible state without extra setup.
  api.mfaState.mockResolvedValue(MFA_ENABLED_STATE)
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Security.vue — characterization tests (Workstream 0)', () => {

  // ──────────────────────────────────────────────────────────────────────────
  // 1. Rendering: MFA-enabled branch
  //    Characterizes: Security.vue L54-68
  // ──────────────────────────────────────────────────────────────────────────

  it('renders Disable MFA button for MFA-enrolled user', async () => {
    const wrapper = mountSecurity()
    await flushPromises()

    // state.enabled = true → the v-else-if="state.enabled" branch renders.
    // The "Disable MFA" button appears when !state.required_by_role (L61-63).
    // We target button text since shallowMount stubs Buefy but leaves plain
    // <button> elements as-is.
    const buttons = wrapper.findAll('button')
    const buttonTexts = Array.from({ length: buttons.length }, (_, i) => buttons.at(i).text())
    expect(buttonTexts).toContain('Disable MFA')
  })

  // ──────────────────────────────────────────────────────────────────────────
  // 2. performManage — disable mode calls api.mfaDisable with correct shape
  //    Characterizes: Security.vue L277-296 (happy path)
  // ──────────────────────────────────────────────────────────────────────────

  it('submitting disable with valid creds calls api.mfaDisable with {password, code, useBackup}', async () => {
    api.mfaDisable.mockResolvedValue({})
    const wrapper = mountSecurity()
    await flushPromises()

    // Simulate openManage('disable') then fill fields and call performManage.
    // After Workstream 7, the form fields live under manageForm (consolidated
    // from the prior reauthPassword/reauthCode/useBackupForManage triple).
    wrapper.vm.openManage('disable')
    wrapper.vm.manageForm = { password: 'correct-password', code: '123456', useBackup: false }

    wrapper.vm.performManage()
    await flushPromises()

    expect(api.mfaDisable).toHaveBeenCalledTimes(1)
    expect(api.mfaDisable).toHaveBeenCalledWith({
      password: 'correct-password',
      code: '123456',
      useBackup: false,
    })
  })

  // ──────────────────────────────────────────────────────────────────────────
  // 3. Wrong password 422 → field error mapped inline (FIX VERIFIED)
  //    Replaces the prior BUG PIN: the swallow bug at L294-296 is now fixed.
  //    performManage maps 422 body.password → manageFormErrors.password
  //    (canonical pattern lifted from changePassword's cpErrors path).
  // ──────────────────────────────────────────────────────────────────────────

  it('disable with wrong password maps "Wrong password" inline on manageFormErrors.password', async () => {
    api.mfaDisable.mockRejectedValue({
      response: {
        status: 422,
        data: { data: { password: 'Wrong password' } },
      },
    })
    const wrapper = mountSecurity()
    await flushPromises()

    wrapper.vm.openManage('disable')
    wrapper.vm.manageForm = { password: 'wrong-password', code: '123456', useBackup: false }

    wrapper.vm.performManage()
    await flushPromises()

    // FIX: field error is now mapped inline. No generic toast fires for
    // recognised 422 field errors. The password input was also cleared.
    expect(wrapper.vm.manageFormErrors.password).toBe('Wrong password')
    expect(wrapper.vm.manageForm.password).toBe('')
    expect(wrapper.vm.$toast.open).not.toHaveBeenCalled()
  })

  // ──────────────────────────────────────────────────────────────────────────
  // 4. Wrong code 422 → field error mapped inline (FIX VERIFIED, code variant)
  // ──────────────────────────────────────────────────────────────────────────

  it('disable with wrong code maps "Invalid code" inline on manageFormErrors.code', async () => {
    api.mfaDisable.mockRejectedValue({
      response: {
        status: 422,
        data: { data: { code: 'Invalid code' } },
      },
    })
    const wrapper = mountSecurity()
    await flushPromises()

    wrapper.vm.openManage('disable')
    wrapper.vm.manageForm = { password: 'correct-password', code: '000000', useBackup: false }

    wrapper.vm.performManage()
    await flushPromises()

    expect(wrapper.vm.manageFormErrors.code).toBe('Invalid code')
    expect(wrapper.vm.manageForm.code).toBe('')
    expect(wrapper.vm.$toast.open).not.toHaveBeenCalled()
  })

  // ──────────────────────────────────────────────────────────────────────────
  // 5. Non-422 / non-object-body errors still fall through to generic toast
  //    Pins the fallback path so the fix didn't accidentally swallow other
  //    error categories.
  // ──────────────────────────────────────────────────────────────────────────

  it('disable with non-422 error still surfaces generic toast', async () => {
    api.mfaDisable.mockRejectedValue({
      response: {
        status: 500,
        data: { data: 'Server error' },
      },
    })
    const wrapper = mountSecurity()
    await flushPromises()

    wrapper.vm.openManage('disable')
    wrapper.vm.manageForm = { password: 'correct-password', code: '123456', useBackup: false }

    wrapper.vm.performManage()
    await flushPromises()

    expect(wrapper.vm.$toast.open).toHaveBeenCalledWith({
      message: 'Verification failed',
      type: 'is-danger',
    })
    expect(wrapper.vm.manageFormErrors.password).toBeNull()
    expect(wrapper.vm.manageFormErrors.code).toBeNull()
  })

  // ──────────────────────────────────────────────────────────────────────────
  // 6. performManage — regenerate mode calls api.mfaRegenerateBackupCodes
  //    Characterizes: Security.vue L283-285 and L288-290
  // ──────────────────────────────────────────────────────────────────────────

  it('regenerate backup codes flow calls api.mfaRegenerateBackupCodes with {password, code, useBackup}', async () => {
    const newCodes = ['AAAAA-BBBBB', 'CCCCC-DDDDD']
    api.mfaRegenerateBackupCodes.mockResolvedValue({ backup_codes: newCodes })
    api.mfaState.mockResolvedValue(MFA_ENABLED_STATE)
    const wrapper = mountSecurity()
    await flushPromises()

    wrapper.vm.openManage('regenerate')
    wrapper.vm.manageForm = { password: 'my-password', code: '654321', useBackup: false }

    wrapper.vm.performManage()
    await flushPromises()

    expect(api.mfaRegenerateBackupCodes).toHaveBeenCalledTimes(1)
    expect(api.mfaRegenerateBackupCodes).toHaveBeenCalledWith({
      password: 'my-password',
      code: '654321',
      useBackup: false,
    })

    // On success, backupCodes is populated from the response and modal closes.
    expect(wrapper.vm.backupCodes).toEqual(newCodes)
    expect(wrapper.vm.manageOpen).toBe(false)
  })

  // ──────────────────────────────────────────────────────────────────────────
  // 7. changePassword — CANONICAL inline field-error pattern
  //    Characterizes: Security.vue L215-229
  //
  //    This is the pattern Workstream 7 will copy into performManage to fix the
  //    swallow bug. Pinning it here ensures we don't accidentally break it
  //    during the refactor, and gives Workstream 7 a concrete reference.
  //
  //    When changePassword() receives a 422 with data.data = {oldpassword:
  //    'Wrong password'}, it sets cpErrors = {oldpassword: 'Wrong password'}.
  //    The template binds cpErrors.oldpassword to the b-field's :message and
  //    :type props (L31). This is the correct inline-error path.
  // ──────────────────────────────────────────────────────────────────────────

  it('changePassword maps 422 field errors into cpErrors (CANONICAL inline-error pattern)', async () => {
    api.changePassword.mockRejectedValue({
      response: {
        status: 422,
        data: { data: { oldpassword: 'Wrong password' } },
      },
    })
    const wrapper = mountSecurity()
    await flushPromises()

    wrapper.vm.oldPw = 'wrong-old-password'
    wrapper.vm.newPw = 'new-password'

    wrapper.vm.changePassword()
    await flushPromises()

    // cpErrors is set directly from the field map — inline error, no toast.
    expect(wrapper.vm.cpErrors).toEqual({ oldpassword: 'Wrong password' })
    // No toast should fire for a field-level error.
    expect(wrapper.vm.$toast.open).not.toHaveBeenCalled()
  })
})
