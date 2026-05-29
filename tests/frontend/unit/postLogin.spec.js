// Defensive mock: api.js is imported by postLogin.js directly.
jest.mock('@/api/api', () => ({ __esModule: true, default: { selectFolder: jest.fn(() => Promise.resolve()) } }))

import { routeAfterLogin } from '@/mixins/postLogin'

const api = require('@/api/api').default

// ── Helpers ───────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Factory helpers ───────────────────────────────────────────────────────────

function makeStore({ pendingCd = null, pendingFolder = null } = {}) {
  return {
    state: { pendingCd, pendingFolder },
    commit: jest.fn(),
  }
}

function makeRouter() {
  return { push: jest.fn(() => Promise.resolve()) }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks()
  api.selectFolder.mockReturnValue(Promise.resolve())
})

describe('routeAfterLogin', () => {

  // ─────────────────────────────────────────────
  // Guest (homedirs: [])
  // ─────────────────────────────────────────────

  describe('guest (homedirs: [])', () => {
    it('pushes "/" and does NOT clear the pending stash', () => {
      const router = makeRouter()
      const store = makeStore({ pendingCd: '/some/path', pendingFolder: null })
      const user = { homedirs: [] }

      routeAfterLogin(user, router, store)

      expect(router.push).toHaveBeenCalledWith('/')
      // The stash must NOT be consumed for a guest call
      expect(store.commit).not.toHaveBeenCalledWith('setPendingCd', null)
      expect(store.commit).not.toHaveBeenCalledWith('setPendingFolder', null)
    })
  })

  // ─────────────────────────────────────────────
  // Single-folder (homedirs.length === 1)
  // ─────────────────────────────────────────────

  describe('single-folder (homedirs.length === 1)', () => {

    it('active === only + pendingCd set: pushes { path, query } and clears stash', () => {
      const router = makeRouter()
      const store = makeStore({ pendingCd: '/sub' })
      const user = { homedirs: ['/'], active_homedir: '/' }

      routeAfterLogin(user, router, store)

      expect(router.push).toHaveBeenCalledWith({ path: '/', query: { cd: '/sub' } })
      expect(store.commit).toHaveBeenCalledWith('setPendingCd', null)
      expect(store.commit).toHaveBeenCalledWith('setPendingFolder', null)
      // No API call needed — server already has the right active homedir
      expect(api.selectFolder).not.toHaveBeenCalled()
    })

    it('active === only + no pendingCd: pushes "/"', () => {
      const router = makeRouter()
      const store = makeStore()
      const user = { homedirs: ['/'], active_homedir: '/' }

      routeAfterLogin(user, router, store)

      expect(router.push).toHaveBeenCalledWith('/')
      expect(store.commit).toHaveBeenCalledWith('setPendingCd', null)
      expect(store.commit).toHaveBeenCalledWith('setPendingFolder', null)
    })

    it('active !== only + pendingCd set (async): calls api.selectFolder, commits setActiveHomedir, then pushes with cd', async () => {
      const router = makeRouter()
      const store = makeStore({ pendingCd: '/sub' })
      const user = { homedirs: ['/'], active_homedir: null }

      routeAfterLogin(user, router, store)

      // Not yet resolved
      expect(api.selectFolder).toHaveBeenCalledWith({ homedir: '/' })
      expect(router.push).not.toHaveBeenCalled()

      await flushPromises()

      expect(store.commit).toHaveBeenCalledWith('setActiveHomedir', '/')
      expect(router.push).toHaveBeenCalledWith({ path: '/', query: { cd: '/sub' } })
      expect(store.commit).toHaveBeenCalledWith('setPendingCd', null)
      expect(store.commit).toHaveBeenCalledWith('setPendingFolder', null)
    })

  })

  // ─────────────────────────────────────────────
  // Multi-folder (homedirs.length > 1)
  // ─────────────────────────────────────────────

  describe('multi-folder (homedirs.length > 1)', () => {

    it('pendingFolder valid + pendingFolder === active + pendingCd set: pushes with cd+folder and clears stash', () => {
      const router = makeRouter()
      const store = makeStore({ pendingCd: '/deep', pendingFolder: '/personal' })
      const user = { homedirs: ['/projects', '/personal'], active_homedir: '/personal' }

      routeAfterLogin(user, router, store)

      expect(router.push).toHaveBeenCalledWith({ path: '/', query: { cd: '/deep', folder: '/personal' } })
      expect(store.commit).toHaveBeenCalledWith('setPendingCd', null)
      expect(store.commit).toHaveBeenCalledWith('setPendingFolder', null)
      expect(api.selectFolder).not.toHaveBeenCalled()
    })

    it('pendingFolder valid + pendingFolder !== active (async): calls api.selectFolder, commits setActiveHomedir, then pushes with cd+folder', async () => {
      const router = makeRouter()
      const store = makeStore({ pendingCd: '/deep', pendingFolder: '/personal' })
      const user = { homedirs: ['/projects', '/personal'], active_homedir: '/projects' }

      routeAfterLogin(user, router, store)

      expect(api.selectFolder).toHaveBeenCalledWith({ homedir: '/personal' })
      expect(router.push).not.toHaveBeenCalled()

      await flushPromises()

      expect(store.commit).toHaveBeenCalledWith('setActiveHomedir', '/personal')
      expect(router.push).toHaveBeenCalledWith({ path: '/', query: { cd: '/deep', folder: '/personal' } })
      expect(store.commit).toHaveBeenCalledWith('setPendingCd', null)
      expect(store.commit).toHaveBeenCalledWith('setPendingFolder', null)
    })

    it('no pendingFolder + active valid + pendingCd set: pushes with cd+active and clears stash', () => {
      const router = makeRouter()
      const store = makeStore({ pendingCd: '/deep', pendingFolder: null })
      const user = { homedirs: ['/projects', '/personal'], active_homedir: '/personal' }

      routeAfterLogin(user, router, store)

      expect(router.push).toHaveBeenCalledWith({ path: '/', query: { cd: '/deep', folder: '/personal' } })
      expect(store.commit).toHaveBeenCalledWith('setPendingCd', null)
      expect(store.commit).toHaveBeenCalledWith('setPendingFolder', null)
    })

    it('no pendingFolder + no valid active: pushes "/select-folder", drops pendingFolder but KEEPS pendingCd', () => {
      const router = makeRouter()
      const store = makeStore({ pendingCd: '/deep', pendingFolder: null })
      const user = { homedirs: ['/projects', '/personal'], active_homedir: null }

      routeAfterLogin(user, router, store)

      expect(router.push).toHaveBeenCalledWith('/select-folder')
      // pendingFolder is dropped
      expect(store.commit).toHaveBeenCalledWith('setPendingFolder', null)
      // pendingCd is KEPT so SelectFolder.pick() can still restore it
      expect(store.commit).not.toHaveBeenCalledWith('setPendingCd', null)
    })

  })

})
