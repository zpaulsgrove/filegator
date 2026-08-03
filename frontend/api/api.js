import axios from 'axios'
import { Base64 } from 'js-base64'

const api = {
  getConfig() {
    return new Promise((resolve, reject) => {
      axios.get('getconfig')
        .then(res => resolve(res))
        .catch(error => reject(error))
    })
  },
  getUser() {
    return new Promise((resolve, reject) => {
      axios.get('getuser')
        .then(res => {
          // set/update csrf token
          axios.defaults.headers.common['x-csrf-token'] = res.headers['x-csrf-token']
          resolve(res.data.data)
        })
        .catch(error => reject(error))
    })
  },
  login(params) {
    return new Promise((resolve, reject) => {
      axios.post('login', {
        username: params.username,
        password: params.password,
      })
        .then(
          res => {
            resolve(res.data.data)
          },
          error => reject(error))
    })
  },
  logout() {
    return new Promise((resolve, reject) => {
      axios.post('logout')
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  changeDir(params) {
    return new Promise((resolve, reject) => {
      axios.post('changedir', {
        to: params.to,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  selectFolder(params) {
    // Multi-folder users call this before any file-op request will
    // succeed. Single-folder users have it auto-seeded server-side at
    // login but can still call it as a no-op identity check.
    return new Promise((resolve, reject) => {
      axios.post('selectfolder', {
        homedir: params.homedir,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  getDir(params) {
    return new Promise((resolve, reject) => {
      axios.post('getdir', {
        dir: params.dir,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  copyItems(params) {
    return new Promise((resolve, reject) => {
      axios.post('copyitems', {
        destination: params.destination,
        items: params.items,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  moveItems(params) {
    return new Promise((resolve, reject) => {
      axios.post('moveitems', {
        destination: params.destination,
        items: params.items,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  renameItem(params) {
    return new Promise((resolve, reject) => {
      axios.post('renameitem', {
        from: params.from,
        to: params.to,
        destination: params.destination,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  batchDownload (params) {
    return new Promise((resolve, reject) => {
      axios.post('batchdownload', {
        items: params.items,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  zipItems(params) {
    return new Promise((resolve, reject) => {
      axios.post('zipitems', {
        name: params.name,
        items: params.items,
        destination: params.destination,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  unzipItem(params) {
    return new Promise((resolve, reject) => {
      axios.post('unzipitem', {
        item: params.item,
        destination: params.destination,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  chmodItems(params) {
    return new Promise((resolve, reject) => {
      axios.post('chmoditems', {
        permissions: params.permissions,
        items: params.items,
        recursive: params.recursive,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  removeItems(params) {
    return new Promise((resolve, reject) => {
      axios.post('deleteitems', {
        items: params.items,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  createNew(params) {
    return new Promise((resolve, reject) => {
      axios.post('createnew', {
        type: params.type,
        name: params.name,
        destination: params.destination,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  listUsers() {
    return new Promise((resolve, reject) => {
      axios.get('listusers')
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  folderAccessAudit(params = {}) {
    // Admin-only. Without `path` returns every assigned folder with its
    // users; with `path` returns just that folder (browse-tree inspect).
    return new Promise((resolve, reject) => {
      const config = params.path ? { params: { path: params.path } } : {}
      axios.get('admin/folder-access-audit', config)
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  auditLog(params = {}) {
    // Admin-only. Returns recent file-activity events ({events: [...]}).
    // Optional filters: action, user, from, to (unix-epoch bounds).
    return new Promise((resolve, reject) => {
      axios.get('admin/audit-log', { params })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  monthlyReports() {
    // Admin-only. Metadata for the reports written by the cron job
    // (`php bin/filegator report:monthly`) — period, event count, coverage,
    // size. Never event data; the CSV itself only comes from the download call
    // below, which is authenticated, step-up gated and logged.
    return new Promise((resolve, reject) => {
      axios.get('admin/reports')
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  downloadMonthlyReport(params) {
    // POST, not GET, and deliberately so: the backend route is a POST because
    // GET is CSRF-exempt and SameSite=Lax cookies ride a top-level cross-site
    // navigation, which would let another site force this download into an
    // admin's Downloads folder.
    //
    // Identified by PERIOD rather than a filename or id — user input never
    // reaches the server's filesystem.
    return new Promise((resolve, reject) => {
      const body = { period: params.period }
      if (params.stepup_password !== undefined) body.stepup_password = params.stepup_password
      if (params.stepup_code !== undefined) body.stepup_code = params.stepup_code
      if (params.stepup_use_backup !== undefined) body.stepup_use_backup = params.stepup_use_backup

      axios.post('admin/reports/download', body, { responseType: 'blob' })
        .then(res => resolve(res.data))
        // responseType 'blob' applies to ERROR bodies too, so a 422 from the
        // step-up gate arrives as a Blob and `error.response.data.data` — which
        // StepUpDialog reads to show "Invalid code" and let the user retry — is
        // undefined. Without this the dialog reports a generic "Unknown error"
        // and closes on a simple typo. Decode it back to the normal JSON shape
        // before rejecting, so the blob stays only for the success path.
        .catch(error => {
          const payload = error && error.response && error.response.data
          if (!(payload instanceof Blob)) return reject(error)

          // FileReader rather than Blob.text(): the latter is absent in older
          // Safari (and in jsdom), and this runs on the error path where
          // failing to decode would hide the very message the user needs.
          const reader = new FileReader()
          reader.onload = () => {
            try {
              error.response.data = JSON.parse(reader.result)
            } catch (e) {
              // Not JSON (a proxy error page, say) — leave the original error
              // untouched rather than inventing a shape.
            }
            reject(error)
          }
          reader.onerror = () => reject(error)
          reader.readAsText(payload)
        })
    })
  },
  deleteUser(params) {
    return new Promise((resolve, reject) => {
      const body = {}
      if (params.stepup_password !== undefined) body.stepup_password = params.stepup_password
      if (params.stepup_code !== undefined) body.stepup_code = params.stepup_code
      if (params.stepup_use_backup !== undefined) body.stepup_use_backup = params.stepup_use_backup
      axios.post('deleteuser/'+params.username, body)
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  storeUser(params) {
    return new Promise((resolve, reject) => {
      const body = {
        role: params.role,
        name: params.name,
        username: params.username,
        email: params.email,
        // Both keys during the rollout transition. Backend prefers
        // `homedirs` via normaliseHomedirsInput; the legacy `homedir`
        // scalar is the back-compat fallback Phase 10 removes.
        homedirs: params.homedirs,
        homedir: params.homedir,
        password: params.password,
        permissions: params.permissions,
      }
      if (params.stepup_password !== undefined) body.stepup_password = params.stepup_password
      if (params.stepup_code !== undefined) body.stepup_code = params.stepup_code
      if (params.stepup_use_backup !== undefined) body.stepup_use_backup = params.stepup_use_backup
      axios.post('storeuser', body)
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  updateUser(params) {
    return new Promise((resolve, reject) => {
      const body = {
        role: params.role,
        name: params.name,
        username: params.username,
        email: params.email,
        homedirs: params.homedirs,
        homedir: params.homedir,
        password: params.password,
        permissions: params.permissions,
      }
      if (params.stepup_password !== undefined) body.stepup_password = params.stepup_password
      if (params.stepup_code !== undefined) body.stepup_code = params.stepup_code
      if (params.stepup_use_backup !== undefined) body.stepup_use_backup = params.stepup_use_backup
      axios.post('updateuser/'+params.key, body)
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  changePassword(params) {
    return new Promise((resolve, reject) => {
      axios.post('changepassword', {
        oldpassword: params.oldpassword,
        newpassword: params.newpassword,
        // Step-up second factor; ignored by the backend when the user has no
        // MFA enrolled. oldpassword doubles as the step-up password.
        code: params.code,
        use_backup: !!params.useBackup,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  loginMfa(params) {
    return new Promise((resolve, reject) => {
      axios.post('login/mfa', {
        code: params.code,
        use_backup: !!params.useBackup,
        mfa_nonce: params.nonce,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  loginMfaSetup(params) {
    return new Promise((resolve, reject) => {
      axios.post('login/mfa/setup', {
        code: params.code,
        mfa_nonce: params.nonce,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  loginMfaCancel() {
    return new Promise((resolve, reject) => {
      axios.post('login/mfa/cancel')
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  mfaState() {
    return new Promise((resolve, reject) => {
      axios.get('mfa/state')
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  mfaBeginEnroll() {
    return new Promise((resolve, reject) => {
      axios.post('mfa/enroll/begin')
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  mfaConfirmEnroll(params) {
    return new Promise((resolve, reject) => {
      axios.post('mfa/enroll/confirm', { code: params.code })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  mfaDisable(params) {
    return new Promise((resolve, reject) => {
      axios.post('mfa/disable', {
        password: params.password,
        code: params.code,
        use_backup: !!params.useBackup,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  mfaRegenerateBackupCodes(params) {
    return new Promise((resolve, reject) => {
      axios.post('mfa/backup_codes/regenerate', {
        password: params.password,
        code: params.code,
        use_backup: !!params.useBackup,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  updateMyEmail(params) {
    return new Promise((resolve, reject) => {
      axios.post('me/email', {
        email: params.email,
        // Step-up credentials; ignored by the backend when the user has no
        // MFA enrolled.
        password: params.password,
        code: params.code,
        use_backup: !!params.useBackup,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  adminResetMfa(params) {
    return new Promise((resolve, reject) => {
      const body = {}
      if (params.stepup_password !== undefined) body.stepup_password = params.stepup_password
      if (params.stepup_code !== undefined) body.stepup_code = params.stepup_code
      if (params.stepup_use_backup !== undefined) body.stepup_use_backup = params.stepup_use_backup
      axios.post('admin/users/' + encodeURIComponent(params.username) + '/reset_mfa', body)
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  requestPasswordReset(params) {
    return new Promise((resolve, reject) => {
      axios.post('password/forgot', { email: params.email })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  validateResetToken(token) {
    return new Promise((resolve, reject) => {
      axios.post('password/reset/validate', { token })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  confirmPasswordReset(params) {
    return new Promise((resolve, reject) => {
      axios.post('password/reset', {
        token: params.token,
        new_password: params.newPassword,
      })
        .then(res => resolve(res.data.data))
        .catch(error => reject(error))
    })
  },
  downloadUrl (path) {
    return 'download&path='+encodeURIComponent(Base64.encode(path))
  },
  downloadItem (params) {
    return new Promise((resolve, reject) => {
      axios.get(this.downloadUrl(params.path),
        {
          transformResponse: [data => data],
        })
        .then(res => resolve(res.data))
        .catch(error => reject(error))
    })
  },
  downloadBlob (params) {
    // X-Requested-With makes the backend return a 4xx (not a redirect) on
    // failure, so the promise rejects and the caller can report the error.
    return axios.get(this.downloadUrl(params.path),
      { responseType: 'blob', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(res => res.data)
  },
  saveContent (params) {
    return new Promise((resolve, reject) => {
      axios.post('savecontent', {
        name: params.name,
        content: params.content,
      })
        .then(res => resolve(res.data))
        .catch(error => reject(error))
    })
  },
}

export default api
