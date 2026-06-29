/**
 * api.js — request-contract unit tests.
 *
 * Every other spec jest.mock()s the api module, so these ~33 methods (the
 * URL/body builders, the CSRF-header side effect, path encoding, the
 * conditional step-up params, and the differing resolve shapes) had zero unit
 * coverage. axios is mocked here so each method's wire contract is pinned.
 */

import { Base64 } from 'js-base64'

jest.mock('axios', () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
    defaults: { headers: { common: {} } },
  },
}))

const axios = require('axios').default
const api = require('@/api/api').default

// Default happy response: {data:{data:...}} envelope + a csrf header.
function resolveWith(body = 'RESULT', headers = { 'x-csrf-token': 'tok-123' }) {
  const res = { data: { data: body }, headers }
  axios.get.mockResolvedValue(res)
  axios.post.mockResolvedValue(res)
  return res
}

beforeEach(() => {
  jest.clearAllMocks()
  axios.defaults.headers.common = {}
  resolveWith()
})

describe('api.js — resolve shapes', () => {
  it('most methods unwrap res.data.data', async () => {
    resolveWith('UNWRAPPED')
    await expect(api.listUsers()).resolves.toBe('UNWRAPPED')
    await expect(api.getDir({ dir: '/' })).resolves.toBe('UNWRAPPED')
  })

  it('getConfig resolves the WHOLE response (not res.data.data)', async () => {
    const res = resolveWith('X')
    await expect(api.getConfig()).resolves.toBe(res)
  })

  it('downloadItem and saveContent resolve res.data (raw body, not .data.data)', async () => {
    axios.get.mockResolvedValue({ data: 'RAW-BYTES' })
    await expect(api.downloadItem({ path: '/a.txt' })).resolves.toBe('RAW-BYTES')
    axios.post.mockResolvedValue({ data: 'SAVED' })
    await expect(api.saveContent({ name: 'a.txt', content: 'x' })).resolves.toBe('SAVED')
  })

  it('rejects propagate the axios error', async () => {
    const err = new Error('boom')
    axios.get.mockRejectedValue(err)
    await expect(api.listUsers()).rejects.toBe(err)
  })
})

describe('api.js — getUser CSRF side effect', () => {
  it('stores the x-csrf-token header into axios defaults and resolves res.data.data', async () => {
    resolveWith('ME', { 'x-csrf-token': 'fresh-token' })
    const me = await api.getUser()
    expect(me).toBe('ME')
    expect(axios.get).toHaveBeenCalledWith('getuser')
    expect(axios.defaults.headers.common['x-csrf-token']).toBe('fresh-token')
  })
})

describe('api.js — request URLs and bodies', () => {
  it('login posts username + password', async () => {
    await api.login({ username: 'u', password: 'p' })
    expect(axios.post).toHaveBeenCalledWith('login', { username: 'u', password: 'p' })
  })

  it('logout / loginMfaCancel / mfaBeginEnroll post with no body', async () => {
    await api.logout(); expect(axios.post).toHaveBeenCalledWith('logout')
    await api.loginMfaCancel(); expect(axios.post).toHaveBeenCalledWith('login/mfa/cancel')
    await api.mfaBeginEnroll(); expect(axios.post).toHaveBeenCalledWith('mfa/enroll/begin')
  })

  it('file ops post their documented bodies', async () => {
    await api.changeDir({ to: '/x' })
    expect(axios.post).toHaveBeenCalledWith('changedir', { to: '/x' })

    await api.selectFolder({ homedir: '/h' })
    expect(axios.post).toHaveBeenCalledWith('selectfolder', { homedir: '/h' })

    await api.copyItems({ destination: '/d', items: [1] })
    expect(axios.post).toHaveBeenCalledWith('copyitems', { destination: '/d', items: [1] })

    await api.moveItems({ destination: '/d', items: [1] })
    expect(axios.post).toHaveBeenCalledWith('moveitems', { destination: '/d', items: [1] })

    await api.renameItem({ from: 'a', to: 'b', destination: '/d' })
    expect(axios.post).toHaveBeenCalledWith('renameitem', { from: 'a', to: 'b', destination: '/d' })

    await api.zipItems({ name: 'z.zip', items: [1], destination: '/d' })
    expect(axios.post).toHaveBeenCalledWith('zipitems', { name: 'z.zip', items: [1], destination: '/d' })

    await api.unzipItem({ item: '/z.zip', destination: '/d' })
    expect(axios.post).toHaveBeenCalledWith('unzipitem', { item: '/z.zip', destination: '/d' })

    await api.removeItems({ items: [1, 2] })
    expect(axios.post).toHaveBeenCalledWith('deleteitems', { items: [1, 2] })

    await api.createNew({ type: 'file', name: 'n', destination: '/d' })
    expect(axios.post).toHaveBeenCalledWith('createnew', { type: 'file', name: 'n', destination: '/d' })

    await api.chmodItems({ permissions: '0755', items: [1], recursive: 'all' })
    expect(axios.post).toHaveBeenCalledWith('chmoditems', { permissions: '0755', items: [1], recursive: 'all' })

    await api.batchDownload({ items: [1] })
    expect(axios.post).toHaveBeenCalledWith('batchdownload', { items: [1] })
  })
})

describe('api.js — admin audit queries', () => {
  it('folderAccessAudit omits params with no path, includes them with a path', async () => {
    await api.folderAccessAudit()
    expect(axios.get).toHaveBeenCalledWith('admin/folder-access-audit', {})

    await api.folderAccessAudit({ path: '/clientA' })
    expect(axios.get).toHaveBeenCalledWith('admin/folder-access-audit', { params: { path: '/clientA' } })
  })

  it('auditLog forwards filters as query params', async () => {
    await api.auditLog({ action: 'delete', user: 'alice' })
    expect(axios.get).toHaveBeenCalledWith('admin/audit-log', { params: { action: 'delete', user: 'alice' } })

    await api.auditLog()
    expect(axios.get).toHaveBeenCalledWith('admin/audit-log', { params: {} })
  })
})

describe('api.js — download path encoding', () => {
  it('base64+uri-encodes the path and uses an identity transformResponse', async () => {
    axios.get.mockResolvedValue({ data: 'BYTES' })
    await api.downloadItem({ path: '/a b/é.txt' })

    const expectedUrl = 'download&path=' + encodeURIComponent(Base64.encode('/a b/é.txt'))
    expect(axios.get).toHaveBeenCalledTimes(1)
    const [url, config] = axios.get.mock.calls[0]
    expect(url).toBe(expectedUrl)
    // transformResponse is an identity fn so binary bodies aren't JSON-parsed.
    expect(typeof config.transformResponse[0]).toBe('function')
    expect(config.transformResponse[0]('xyz')).toBe('xyz')
  })
})

describe('api.js — step-up params are included only when defined', () => {
  it('deleteUser sends an empty body when no step-up creds', async () => {
    await api.deleteUser({ username: 'bob' })
    expect(axios.post).toHaveBeenCalledWith('deleteuser/bob', {})
  })

  it('deleteUser includes stepup_* only for defined keys', async () => {
    await api.deleteUser({ username: 'bob', stepup_password: 'pw', stepup_use_backup: true })
    expect(axios.post).toHaveBeenCalledWith('deleteuser/bob', { stepup_password: 'pw', stepup_use_backup: true })
  })

  it('adminResetMfa uri-encodes the username and omits undefined step-up keys', async () => {
    await api.adminResetMfa({ username: 'a@b.com', stepup_code: '123456' })
    expect(axios.post).toHaveBeenCalledWith('admin/users/a%40b.com/reset_mfa', { stepup_code: '123456' })
  })

  it('storeUser sends both homedirs and homedir, plus step-up when present', async () => {
    await api.storeUser({
      role: 'user', name: 'N', username: 'u', email: 'e', homedirs: ['/a'], homedir: '/a',
      password: 'p', permissions: ['read'], stepup_password: 'pw',
    })
    expect(axios.post).toHaveBeenCalledWith('storeuser', expect.objectContaining({
      homedirs: ['/a'], homedir: '/a', stepup_password: 'pw',
    }))
  })

  it('updateUser targets the {key} URL', async () => {
    await api.updateUser({ key: 'olduser', role: 'admin' })
    expect(axios.post).toHaveBeenCalledWith('updateuser/olduser', expect.objectContaining({ role: 'admin' }))
  })
})

describe('api.js — use_backup is coerced to a boolean', () => {
  it('truthy/falsy useBackup becomes true/false', async () => {
    await api.changePassword({ oldpassword: 'o', newpassword: 'n', code: '1', useBackup: 1 })
    expect(axios.post).toHaveBeenCalledWith('changepassword', expect.objectContaining({ use_backup: true }))

    await api.mfaDisable({ password: 'p', code: '1' })
    expect(axios.post).toHaveBeenCalledWith('mfa/disable', expect.objectContaining({ use_backup: false }))

    await api.loginMfa({ code: '1', nonce: 'N', useBackup: 'yes' })
    expect(axios.post).toHaveBeenCalledWith('login/mfa', { code: '1', use_backup: true, mfa_nonce: 'N' })
  })
})

describe('api.js — password reset + mfa enroll bodies', () => {
  it('builds the documented bodies', async () => {
    await api.requestPasswordReset({ email: 'e@x.com' })
    expect(axios.post).toHaveBeenCalledWith('password/forgot', { email: 'e@x.com' })

    await api.validateResetToken('tok')
    expect(axios.post).toHaveBeenCalledWith('password/reset/validate', { token: 'tok' })

    await api.confirmPasswordReset({ token: 'tok', newPassword: 'np' })
    expect(axios.post).toHaveBeenCalledWith('password/reset', { token: 'tok', new_password: 'np' })

    await api.mfaConfirmEnroll({ code: '123456' })
    expect(axios.post).toHaveBeenCalledWith('mfa/enroll/confirm', { code: '123456' })

    await api.updateMyEmail({ email: 'e@x.com', password: 'p', code: '1', useBackup: false })
    expect(axios.post).toHaveBeenCalledWith('me/email', { email: 'e@x.com', password: 'p', code: '1', use_backup: false })

    await api.mfaState()
    expect(axios.get).toHaveBeenCalledWith('mfa/state')
  })
})
