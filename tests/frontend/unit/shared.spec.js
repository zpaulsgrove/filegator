// shared.js mixin — pure formatting/predicate helpers and role/permission gates.

import shared from '@/mixins/shared'
import store from '@/store'

const m = shared.methods

describe('shared mixin helpers', () => {

  describe('formatBytes', () => {
    it('formats zero', () => {
      expect(m.formatBytes(0)).toBe('0 Bytes')
    })
    it('formats KB/MB/GB with default 2 decimals', () => {
      expect(m.formatBytes(1024)).toBe('1 KB')
      expect(m.formatBytes(1536)).toBe('1.5 KB')
      expect(m.formatBytes(1048576)).toBe('1 MB')
      expect(m.formatBytes(1073741824)).toBe('1 GB')
    })
    it('honours the decimals argument', () => {
      expect(m.formatBytes(1536, 0)).toBe('2 KB')
    })
  })

  describe('hasExtension', () => {
    it('matches case-insensitively and escapes dots', () => {
      expect(m.hasExtension('photo.JPG', ['.jpg', '.png'])).toBe(true)
      expect(m.hasExtension('notes.txt', ['.jpg', '.png'])).toBe(false)
    })
    it('does not treat a dot as a wildcard', () => {
      // The dot in the extension list must be escaped, so "axtxt" (where the
      // "." would otherwise match "x") is NOT a match for ".txt".
      expect(m.hasExtension('axtxt', ['.txt'])).toBe(false)
    })
    it('returns false for an empty extension list', () => {
      expect(m.hasExtension('a.txt', [])).toBe(false)
    })
  })

  describe('isText / isImage (driven by config.editable)', () => {
    beforeEach(() => {
      store.commit('setConfig', { editable: ['.txt', '.md', '.js'] })
    })
    it('isText uses the configured editable extensions', () => {
      expect(m.isText.call(m, 'readme.md')).toBe(true)
      expect(m.isText.call(m, 'photo.png')).toBe(false)
    })
    it('isImage recognises common raster/vector extensions', () => {
      expect(m.isImage.call(m, 'photo.PNG')).toBe(true)
      expect(m.isImage.call(m, 'diagram.svg')).toBe(true)
      expect(m.isImage.call(m, 'readme.md')).toBe(false)
    })
    it('hasPreview is true when either text or image', () => {
      expect(m.hasPreview.call(m, 'a.txt')).toBe(true)
      expect(m.hasPreview.call(m, 'a.png')).toBe(true)
      expect(m.hasPreview.call(m, 'a.bin')).toBe(false)
    })
  })

  describe('capitalize', () => {
    it('upper-cases only the first character', () => {
      expect(m.capitalize('hello')).toBe('Hello')
      expect(m.capitalize('World')).toBe('World')
    })
    it('returns empty string for empty/null/undefined without throwing', () => {
      // Audit rows render capitalize(role)/capitalize(action); a partial log
      // line with a missing field must not crash the whole table.
      expect(m.capitalize('')).toBe('')
      expect(m.capitalize(null)).toBe('')
      expect(m.capitalize(undefined)).toBe('')
    })
  })

  describe('is / can (role & permission gates)', () => {
    it('is() compares against the store user role', () => {
      const ctx = { $store: { state: { user: { role: 'admin' } } } }
      expect(m.is.call(ctx, 'admin')).toBe(true)
      expect(m.is.call(ctx, 'user')).toBe(false)
    })
    it('can() delegates to the hasPermissions getter', () => {
      const ctx = { $store: { getters: { hasPermissions: jest.fn(() => true) } } }
      expect(m.can.call(ctx, ['read'])).toBe(true)
      expect(ctx.$store.getters.hasPermissions).toHaveBeenCalledWith(['read'])
    })
  })
})
