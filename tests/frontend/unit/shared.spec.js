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

  describe('saveBlob', () => {
    // saveBlob moved here from Browser.vue so the Reports CSV export could reuse
    // it. Browser.spec.js now mocks it, so this is the only place the real
    // implementation is exercised.
    let createObjectURL
    let revokeObjectURL

    beforeEach(() => {
      createObjectURL = jest.fn(() => 'blob:fake-url')
      revokeObjectURL = jest.fn()
      global.URL.createObjectURL = createObjectURL
      global.URL.revokeObjectURL = revokeObjectURL
      document.body.innerHTML = ''
    })

    it('downloads via a throwaway anchor and cleans up after itself', () => {
      let clicked = null
      const click = jest.fn(function () { clicked = { href: this.href, download: this.download } })
      jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(click)

      m.saveBlob(new Blob(['x']), 'report.csv')

      expect(createObjectURL).toHaveBeenCalledTimes(1)
      expect(clicked).toEqual({ href: 'blob:fake-url', download: 'report.csv' })
      expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake-url')
      // No anchor left behind.
      expect(document.body.querySelector('a[download]')).toBeNull()

      HTMLAnchorElement.prototype.click.mockRestore()
    })

    it('revokes the url and removes the anchor even when click() throws', () => {
      jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {
        throw new Error('blocked')
      })

      expect(() => m.saveBlob(new Blob(['x']), 'report.csv')).toThrow('blocked')
      expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake-url')
      // The anchor must not leak into the DOM on the failure path — this is the
      // bug that existed while saveBlob lived in Browser.vue (removeChild sat
      // inside the try, so a throwing click() skipped it).
      expect(document.body.querySelector('a[download]')).toBeNull()

      HTMLAnchorElement.prototype.click.mockRestore()
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
