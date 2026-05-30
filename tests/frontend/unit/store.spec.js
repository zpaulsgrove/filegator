// store.js — user normalisation, permission getter, cwd sort, reset behaviour.

import store from '@/store'

beforeEach(() => {
  // initialize() resets cwd/tree/user and clears the deep-link stash.
  store.commit('initialize')
})

describe('store mutations & getters', () => {

  describe('setUser normalisation', () => {
    it('wraps a legacy `homedir` scalar into the homedirs array', () => {
      store.commit('setUser', { role: 'user', permissions: ['read'], homedir: '/john' })
      expect(store.state.user.homedirs).toEqual(['/john'])
      expect(store.state.user.active_homedir).toBeNull()
    })

    it('passes through an explicit homedirs array unchanged', () => {
      store.commit('setUser', { role: 'user', permissions: [], homedirs: ['/a', '/b'], active_homedir: '/b' })
      expect(store.state.user.homedirs).toEqual(['/a', '/b'])
      expect(store.state.user.active_homedir).toBe('/b')
    })

    it('defaults homedirs to [] when neither key is present', () => {
      store.commit('setUser', { role: 'guest', permissions: [] })
      expect(store.state.user.homedirs).toEqual([])
      expect(store.state.user.active_homedir).toBeNull()
    })
  })

  describe('getters.hasPermissions', () => {
    beforeEach(() => {
      store.commit('setUser', { role: 'user', permissions: ['read', 'write'], homedirs: ['/'] })
    })

    it('returns true when every requested permission is held (array form)', () => {
      expect(store.getters.hasPermissions(['read'])).toBe(true)
      expect(store.getters.hasPermissions(['read', 'write'])).toBe(true)
    })

    it('returns false when any requested permission is missing', () => {
      expect(store.getters.hasPermissions(['read', 'delete'])).toBe(false)
      expect(store.getters.hasPermissions(['delete'])).toBe(false)
    })

    it('supports the scalar form', () => {
      expect(store.getters.hasPermissions('write')).toBe(true)
      expect(store.getters.hasPermissions('delete')).toBe(false)
    })
  })

  describe('setCwd', () => {
    it('sorts content by lower-cased type so dirs and files group deterministically', () => {
      store.commit('setCwd', {
        location: '/',
        content: [
          { type: 'file', name: 'b.txt' },
          { type: 'dir', name: 'a' },
          { type: 'File', name: 'c.txt' },
        ],
      })
      expect(store.state.cwd.location).toBe('/')
      // lexical sort on lowered type: 'dir' < 'file' === 'File'
      expect(store.state.cwd.content.map(o => o.type)).toEqual(['dir', 'file', 'File'])
    })
  })

  describe('destroyUser', () => {
    it('resets the user back to the guest identity', () => {
      store.commit('setUser', { role: 'admin', permissions: ['read'], homedirs: ['/'], username: 'a@b' })
      store.commit('destroyUser')
      expect(store.state.user.role).toBe('guest')
      expect(store.state.user.permissions).toEqual([])
      expect(store.state.user.username).toBe('')
      expect(store.state.user.homedirs).toEqual([])
    })
  })
})
