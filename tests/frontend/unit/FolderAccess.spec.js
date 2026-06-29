/**
 * FolderAccess.vue — admin folder-access audit screen. Had no unit and no e2e
 * coverage despite being an admin/permission surface. Covers the filteredFolders
 * search, load()/inspect() data flow, and the permissions/hasRead helpers.
 */

import { shallowMount } from '@vue/test-utils'

jest.mock('@/views/partials/Tree', () => ({ __esModule: true, default: { name: 'Tree' } }))
jest.mock('@/api/api', () => ({
  __esModule: true,
  default: { folderAccessAudit: jest.fn(() => Promise.resolve({ folders: [] })) },
}))

import FolderAccess from '@/views/FolderAccess.vue'

const api = require('@/api/api').default

const flush = () => new Promise(r => setTimeout(r, 0))

function mountFA() {
  return shallowMount(FolderAccess, {
    mocks: {
      $store: { state: { config: { pagination: [10] } } },
      lang: (s, ...a) => (a.length ? s + ' ' + a.join(',') : s),
      capitalize: s => s,
      handleError: jest.fn(),
      $modal: { open: jest.fn() },
      $toast: { open: jest.fn() },
    },
    stubs: {
      Menu: true, Pagination: true,
      'b-table': true, 'b-table-column': true, 'b-tag': true, 'b-input': true, 'b-icon': true,
    },
  })
}

beforeEach(() => {
  jest.clearAllMocks()
  api.folderAccessAudit.mockResolvedValue({ folders: [] })
})

describe('FolderAccess.vue — load()', () => {
  it('fetches folders on mount and defaults inspected=false', async () => {
    api.folderAccessAudit.mockResolvedValue({
      folders: [{ path: '/clientA', user_count: 1, access: [{ username: 'john', name: 'John' }] }],
    })
    const wrapper = mountFA()
    await flush()

    expect(api.folderAccessAudit).toHaveBeenCalledWith()
    expect(wrapper.vm.folders).toHaveLength(1)
    expect(wrapper.vm.folders[0].inspected).toBe(false)
  })

  it('routes a failed load through handleError and clears loading', async () => {
    const err = new Error('nope')
    api.folderAccessAudit.mockRejectedValue(err)
    const wrapper = mountFA()
    await flush()

    expect(wrapper.vm.handleError).toHaveBeenCalledWith(err)
    expect(wrapper.vm.isLoading).toBe(false)
  })
})

describe('FolderAccess.vue — filteredFolders', () => {
  it('returns all rows for an empty search', () => {
    const wrapper = mountFA()
    wrapper.vm.folders = [{ path: '/a', access: [] }, { path: '/b', access: [] }]
    wrapper.vm.search = '   '
    expect(wrapper.vm.filteredFolders).toHaveLength(2)
  })

  it('matches on folder path (case-insensitive)', () => {
    const wrapper = mountFA()
    wrapper.vm.folders = [{ path: '/ClientA', access: [] }, { path: '/other', access: [] }]
    wrapper.vm.search = 'clienta'
    expect(wrapper.vm.filteredFolders.map(f => f.path)).toEqual(['/ClientA'])
  })

  it('matches on an accessing user username or name', () => {
    const wrapper = mountFA()
    wrapper.vm.folders = [
      { path: '/a', access: [{ username: 'alice@x', name: 'Alice' }] },
      { path: '/b', access: [{ username: 'bob@x', name: 'Bob' }] },
    ]
    wrapper.vm.search = 'alice'
    expect(wrapper.vm.filteredFolders.map(f => f.path)).toEqual(['/a'])

    wrapper.vm.search = 'Bob'
    expect(wrapper.vm.filteredFolders.map(f => f.path)).toEqual(['/b'])
  })
})

describe('FolderAccess.vue — inspect()', () => {
  it('merges the inspected folder, marks it, and resets the filter', async () => {
    const wrapper = mountFA()
    await flush()
    wrapper.vm.folders = [{ path: '/a', access: [], inspected: false }]
    wrapper.vm.search = 'stale'

    api.folderAccessAudit.mockResolvedValue({ folders: [{ path: '/a', user_count: 2, access: [{ username: 'x', name: 'X' }] }] })
    wrapper.vm.inspect('/a')
    await flush()

    expect(api.folderAccessAudit).toHaveBeenLastCalledWith({ path: '/a' })
    const row = wrapper.vm.folders.find(f => f.path === '/a')
    expect(row.inspected).toBe(true)
    expect(row.user_count).toBe(2)
    expect(wrapper.vm.search).toBe('')
  })
})

describe('FolderAccess.vue — helpers', () => {
  it('permissions joins with commas; hasRead checks the read perm', () => {
    const wrapper = mountFA()
    expect(wrapper.vm.permissions(['read', 'write'])).toBe('read, write')
    expect(wrapper.vm.hasRead({ permissions: ['read', 'write'] })).toBe(true)
    expect(wrapper.vm.hasRead({ permissions: ['write'] })).toBe(false)
  })

  it('browse() opens the Tree picker modal', () => {
    const wrapper = mountFA()
    wrapper.vm.browse()
    expect(wrapper.vm.$modal.open).toHaveBeenCalled()
  })
})
