/**
 * Browser.vue — pure-logic unit tests.
 *
 * The 828-line main file-manager screen had no jest spec; its sort/filter/
 * permission/preview-routing logic was only partially e2e-touched. These mount
 * with stubbed deps (can() => false so mounted() no-ops) and exercise the pure
 * methods/computed directly.
 */

import { shallowMount } from '@vue/test-utils'

// Editor pulls in PrismJS + its CSS, which jest cannot transform; Gallery and
// the other partials are irrelevant to Browser's pure logic. Mock them as
// lightweight named components so the import chain stays JS-only (the preview
// test still asserts the routed component by name).
jest.mock('@/views/partials/Editor', () => ({ __esModule: true, default: { name: 'Editor' } }))
jest.mock('@/views/partials/Gallery', () => ({ __esModule: true, default: { name: 'Gallery' } }))
jest.mock('@/views/partials/Search', () => ({ __esModule: true, default: { name: 'Search' } }))
jest.mock('@/views/partials/Tree', () => ({ __esModule: true, default: { name: 'Tree' } }))
jest.mock('@/views/partials/Permissions', () => ({ __esModule: true, default: { name: 'Permissions' } }))

import Browser from '@/views/Browser.vue'

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    getDir: jest.fn(() => Promise.resolve({ files: [], location: '/' })),
    changeDir: jest.fn(() => Promise.resolve({ files: [], location: '/' })),
    batchDownload: jest.fn(() => Promise.resolve({ uniqid: 'ZIP1' })),
    downloadBlob: jest.fn(() => Promise.resolve(new Blob(['x'], { type: 'application/pdf' }))),
  },
}))

const api = require('@/api/api').default

function mountBrowser(opts = {}) {
  const user = opts.user || { role: 'user', username: 'u', mfa_enabled: true, homedirs: ['/h'], active_homedir: '/h' }
  const cwd = opts.cwd || { location: '', content: [] }
  const config = Object.assign({ pagination: [10], filter_entries: [] }, opts.config || {})

  return shallowMount(Browser, {
    mocks: {
      $store: { state: { user, config, cwd }, commit: jest.fn() },
      $route: opts.route || { query: {} },
      $router: { push: jest.fn(() => ({ catch: () => {} })) },
      $modal: { open: jest.fn() },
      $dialog: { alert: jest.fn(), confirm: jest.fn(), prompt: jest.fn() },
      $toast: { open: jest.fn() },
      lang: s => s,
      escapeHtml: s => s,
      can: opts.can || (() => false), // false => mounted() returns early
      handleError: jest.fn(),
      hasPreview: () => true,
      isImage: opts.isImage || (() => false),
      isText: opts.isText || (() => false),
      getDownloadLink: p => '/download' + p,
    },
    stubs: {
      Menu: true, Pagination: true, Upload: true,
      'b-table': true, 'b-table-column': true, 'b-icon': true, 'b-tag': true,
      'b-dropdown': true, 'b-dropdown-item': true, 'b-upload': true,
      'b-field': true, 'b-tooltip': true, 'b-input': true, 'b-checkbox': true,
    },
  })
}

describe('Browser.vue — customSort', () => {
  const back = { type: 'back', name: '..' }
  const dirA = { type: 'dir', name: 'aaa', size: 0, time: 100 }
  const dirB = { type: 'dir', name: 'bbb', size: 0, time: 50 }
  const fileA = { type: 'file', name: 'a.txt', size: 10, time: 200 }
  const fileB = { type: 'file', name: 'b.txt', size: 30, time: 100 }

  let vm
  beforeEach(() => { vm = mountBrowser().vm })

  it('always sorts the "back" row first', () => {
    expect(vm.customSort(back, fileA, false, 'name')).toBe(-1)
    expect(vm.customSort(fileA, back, false, 'name')).toBe(1)
  })

  it('sorts directories before files regardless of param', () => {
    expect(vm.customSort(dirA, fileA, false, 'size')).toBe(-1)
    expect(vm.customSort(fileA, dirA, false, 'size')).toBe(1)
  })

  it('orders same-type by name ascending, flips with order', () => {
    expect(vm.customSort(fileA, fileB, false, 'name')).toBeLessThan(0)
    expect(vm.customSort(fileA, fileB, true, 'name')).toBeGreaterThan(0)
  })

  it('orders numeric params (size/time) and flips with order', () => {
    expect(vm.customSort(fileA, fileB, false, 'size')).toBe(-1) // 10 < 30
    expect(vm.customSort(fileA, fileB, true, 'size')).toBe(1)
  })

  it('breaks ties on equal param by falling back to name', () => {
    const x = { type: 'file', name: 'a', size: 5 }
    const y = { type: 'file', name: 'b', size: 5 }
    expect(vm.customSort(x, y, true, 'size')).toBeLessThan(0) // tie -> name asc, order ignored
  })

  it('sortByName/Size/Time invert order and delegate to customSort', () => {
    const spy = jest.spyOn(vm, 'customSort')
    vm.sortByName(dirA, dirB, true)
    expect(spy).toHaveBeenCalledWith(dirA, dirB, false, 'name')
    vm.sortBySize(fileA, fileB, false)
    expect(spy).toHaveBeenCalledWith(fileA, fileB, true, 'size')
    vm.sortByTime(fileA, fileB, false)
    expect(spy).toHaveBeenCalledWith(fileA, fileB, true, 'time')
  })
})

describe('Browser.vue — permissions formatting', () => {
  it('convertToSymbolic maps octal to rwx with a dir/file prefix', () => {
    const vm = mountBrowser().vm
    expect(vm.convertToSymbolic(755, 'dir')).toBe('drwxr-xr-x')
    expect(vm.convertToSymbolic(644, 'file')).toBe('-rw-r--r--')
    expect(vm.convertToSymbolic(-1, 'file')).toBe('')
  })

  it('formatPermissions returns numeric or symbolic per the toggle', () => {
    const vm = mountBrowser().vm
    expect(vm.formatPermissions(755, 'dir')).toBe('755')
    vm.showSymbolic = true
    expect(vm.formatPermissions(755, 'dir')).toBe('[drwxr-xr-x]')
    expect(vm.formatPermissions(-1, 'file')).toBeUndefined()
  })
})

describe('Browser.vue — filterEntries', () => {
  it('hides entries matching a glob and flags hasFilteredEntries', () => {
    const vm = mountBrowser({ config: { filter_entries: ['*.txt'] } }).vm
    const files = [
      { type: 'file', name: 'secret.txt', path: '/secret.txt' },
      { type: 'file', name: 'keep.jpg', path: '/keep.jpg' },
    ]
    const out = vm.filterEntries(files)
    expect(out.map(f => f.name)).toEqual(['keep.jpg'])
    expect(vm.hasFilteredEntries).toBe(true)
  })

  it('matches directory filters (trailing slash) by name', () => {
    const vm = mountBrowser({ config: { filter_entries: ['cache/'] } }).vm
    const files = [
      { type: 'dir', name: 'cache', path: '/cache' },
      { type: 'dir', name: 'data', path: '/data' },
    ]
    expect(vm.filterEntries(files).map(f => f.name)).toEqual(['data'])
  })

  it('showAllEntries bypasses filtering', () => {
    const vm = mountBrowser({ config: { filter_entries: ['*.txt'] } }).vm
    vm.showAllEntries = true
    const files = [{ type: 'file', name: 'x.txt', path: '/x.txt' }]
    expect(vm.filterEntries(files)).toHaveLength(1)
  })

  it('returns all entries when no filters configured', () => {
    const vm = mountBrowser({ config: { filter_entries: [] } }).vm
    const files = [{ type: 'file', name: 'a', path: '/a' }]
    expect(vm.filterEntries(files)).toBe(files)
  })
})

describe('Browser.vue — computed', () => {
  it('breadcrumbs splits the cwd location with cumulative paths', () => {
    const vm = mountBrowser({ cwd: { location: 'a/b', content: [] } }).vm
    expect(vm.breadcrumbs).toEqual([
      { name: 'Home', path: '/' },
      { name: 'a', path: 'a/' },
      { name: 'b', path: 'a/b/' },
    ])
  })

  it('totalCount counts only file/dir entries', () => {
    const content = [{ type: 'file' }, { type: 'dir' }, { type: 'back' }]
    const vm = mountBrowser({ cwd: { location: '/', content } }).vm
    expect(vm.totalCount).toBe(2)
  })

  it('showMfaBanner targets a logged-in non-admin with mfa_enabled === false', () => {
    expect(mountBrowser({ user: { role: 'user', username: 'u', mfa_enabled: false } }).vm.showMfaBanner).toBe(true)
    // mfa_enabled absent (non-MFA adapter) -> stay silent
    expect(mountBrowser({ user: { role: 'user', username: 'u' } }).vm.showMfaBanner).toBe(false)
    // guest -> silent
    expect(mountBrowser({ user: { role: 'guest', username: 'g', mfa_enabled: false } }).vm.showMfaBanner).toBe(false)
  })
})

describe('Browser.vue — isArchive', () => {
  it('is true only for .zip files', () => {
    const vm = mountBrowser().vm
    expect(vm.isArchive({ type: 'file', name: 'a.zip' })).toBe(true)
    expect(vm.isArchive({ type: 'file', name: 'a.txt' })).toBe(false)
    expect(vm.isArchive({ type: 'dir', name: 'a.zip' })).toBe(false)
  })
})

describe('Browser.vue — goTo deep links', () => {
  it('single-folder user gets a clean ?cd= link', () => {
    const wrapper = mountBrowser({ user: { role: 'user', username: 'u', homedirs: ['/h'], active_homedir: '/h' } })
    wrapper.vm.goTo('/sub')
    expect(wrapper.vm.$router.push).toHaveBeenCalledWith({ name: 'browser', query: { cd: '/sub' } })
  })

  it('multi-folder user also encodes the active folder', () => {
    const wrapper = mountBrowser({ user: { role: 'user', username: 'u', homedirs: ['/a', '/b'], active_homedir: '/a' } })
    wrapper.vm.goTo('/sub')
    expect(wrapper.vm.$router.push).toHaveBeenCalledWith({ name: 'browser', query: { cd: '/sub', folder: '/a' } })
  })
})

describe('Browser.vue — batchDownload routing', () => {
  beforeEach(() => {
    api.batchDownload.mockClear()
    api.downloadBlob.mockClear()
  })

  it('streams a single file directly (no archive) so inline types keep previewing', () => {
    const wrapper = mountBrowser({ can: () => true })
    const openSpy = jest.spyOn(window, 'open').mockImplementation(() => {})
    wrapper.vm.checked = [{ type: 'file', name: 'a.pdf', path: '/a.pdf' }]

    wrapper.vm.batchDownload()

    expect(api.batchDownload).not.toHaveBeenCalled()
    expect(openSpy).toHaveBeenCalledWith('/download/a.pdf', '_blank')
    openSpy.mockRestore()
  })

  it('downloads a small all-file selection individually (within threshold)', () => {
    const wrapper = mountBrowser({ can: () => true, config: { zip_threshold: 5 } })
    jest.spyOn(wrapper.vm, 'supportsMultiDownload').mockReturnValue(true)
    const eachSpy = jest.spyOn(wrapper.vm, 'downloadEach').mockImplementation(() => {})
    const items = [
      { type: 'file', name: 'a', path: '/a' },
      { type: 'file', name: 'b', path: '/b' },
      { type: 'file', name: 'c', path: '/c' },
    ]
    wrapper.vm.checked = items

    wrapper.vm.batchDownload()

    expect(eachSpy).toHaveBeenCalledWith(items)
    expect(api.batchDownload).not.toHaveBeenCalled()
  })

  it('zips a selection beyond the threshold via the batchDownload API', () => {
    const wrapper = mountBrowser({ can: () => true, config: { zip_threshold: 5 } })
    wrapper.vm.checked = ['a', 'b', 'c', 'd', 'e', 'f'].map(n => ({ type: 'file', name: n, path: '/' + n }))
    wrapper.vm.batchDownload()
    expect(api.batchDownload).toHaveBeenCalledWith({ items: wrapper.vm.checked })
  })

  it('zips when a folder is part of the selection, even within the threshold', () => {
    const wrapper = mountBrowser({ can: () => true, config: { zip_threshold: 5 } })
    wrapper.vm.checked = [
      { type: 'file', name: 'a', path: '/a' },
      { type: 'dir', name: 'd', path: '/d' },
    ]
    wrapper.vm.batchDownload()
    expect(api.batchDownload).toHaveBeenCalledWith({ items: wrapper.vm.checked })
  })

  it('falls back to the zip when the browser cannot multi-download (Safari/iOS)', () => {
    const wrapper = mountBrowser({ can: () => true, config: { zip_threshold: 5 } })
    jest.spyOn(wrapper.vm, 'supportsMultiDownload').mockReturnValue(false)
    wrapper.vm.checked = [
      { type: 'file', name: 'a', path: '/a' },
      { type: 'file', name: 'b', path: '/b' },
    ]
    wrapper.vm.batchDownload()
    expect(api.batchDownload).toHaveBeenCalledWith({ items: wrapper.vm.checked })
  })
})

describe('Browser.vue — downloadEach', () => {
  beforeEach(() => {
    api.downloadBlob.mockClear()
  })

  it('fetches and saves each file as a blob', async () => {
    const wrapper = mountBrowser({ can: () => true })
    const saveSpy = jest.spyOn(wrapper.vm, 'saveBlob').mockImplementation(() => {})
    const items = [
      { type: 'file', name: 'a', path: '/a' },
      { type: 'file', name: 'b', path: '/b' },
      { type: 'file', name: 'c', path: '/c' },
    ]

    await wrapper.vm.downloadEach(items)

    expect(api.downloadBlob).toHaveBeenCalledTimes(3)
    expect(saveSpy).toHaveBeenCalledTimes(3)
    expect(wrapper.vm.$toast.open).not.toHaveBeenCalled()
  })

  it('saves a successful text/html file instead of treating it as an error', async () => {
    // Regression: a real .html download has Content-Type text/html; it must be saved,
    // not mistaken for the server's HTML error page.
    const wrapper = mountBrowser({ can: () => true })
    api.downloadBlob.mockResolvedValueOnce(new Blob(['<html></html>'], { type: 'text/html' }))
    const saveSpy = jest.spyOn(wrapper.vm, 'saveBlob').mockImplementation(() => {})

    await wrapper.vm.downloadEach([{ type: 'file', name: 'report.html', path: '/report.html' }])

    expect(saveSpy).toHaveBeenCalledTimes(1)
    expect(wrapper.vm.$toast.open).not.toHaveBeenCalled()
  })

  it('continues past a failed file and reports the failed names once', async () => {
    const wrapper = mountBrowser({ can: () => true })
    api.downloadBlob
      .mockResolvedValueOnce(new Blob(['x'], { type: 'text/plain' }))
      .mockRejectedValueOnce(new Error('404'))
      .mockResolvedValueOnce(new Blob(['x'], { type: 'text/plain' }))
    const saveSpy = jest.spyOn(wrapper.vm, 'saveBlob').mockImplementation(() => {})

    await wrapper.vm.downloadEach([
      { type: 'file', name: 'a', path: '/a' },
      { type: 'file', name: 'gone', path: '/gone' },
      { type: 'file', name: 'c', path: '/c' },
    ])

    expect(api.downloadBlob).toHaveBeenCalledTimes(3) // did not abort on the failure
    expect(saveSpy).toHaveBeenCalledTimes(2)          // the two successes were saved
    expect(wrapper.vm.$toast.open).toHaveBeenCalledTimes(1)
    const msg = wrapper.vm.$toast.open.mock.calls[0][0].message
    expect(msg).toContain('gone')
  })
})

describe('Browser.vue — supportsMultiDownload', () => {
  const setUA = ua => Object.defineProperty(window.navigator, 'userAgent', { value: ua, configurable: true })
  const original = window.navigator.userAgent
  afterEach(() => setUA(original))

  it('returns true for Chrome and Firefox, false for desktop Safari and iOS', () => {
    const wrapper = mountBrowser({ can: () => true })
    setUA('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36')
    expect(wrapper.vm.supportsMultiDownload()).toBe(true)
    setUA('Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15) Gecko/20100101 Firefox/121.0')
    expect(wrapper.vm.supportsMultiDownload()).toBe(true)
    setUA('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15')
    expect(wrapper.vm.supportsMultiDownload()).toBe(false)
    setUA('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1')
    expect(wrapper.vm.supportsMultiDownload()).toBe(false)
  })
})

describe('Browser.vue — preview routing', () => {
  it('opens the Gallery for images and the Editor for text', () => {
    const imgWrapper = mountBrowser({ isImage: () => true, isText: () => false })
    imgWrapper.vm.preview({ path: '/a.png' })
    expect(imgWrapper.vm.$modal.open).toHaveBeenCalledWith(expect.objectContaining({ hasModalCard: true }))
    expect(imgWrapper.vm.$modal.open.mock.calls[0][0].component.name).toBe('Gallery')

    const txtWrapper = mountBrowser({ isImage: () => false, isText: () => true })
    txtWrapper.vm.preview({ path: '/a.txt' })
    expect(txtWrapper.vm.$modal.open.mock.calls[0][0].component.name).toBe('Editor')
  })
})
