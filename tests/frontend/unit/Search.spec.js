/**
 * Search.vue — recursive in-folder search modal. Had no unit or e2e coverage.
 * Covers the term-match filtering in getDir() and select() emitting the chosen
 * item. (The debounce + concurrency-limited recursion are timing machinery left
 * to e2e.)
 */

import { shallowMount } from '@vue/test-utils'

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: { getDir: jest.fn() },
}))

import Search from '@/views/partials/Search.vue'

const api = require('@/api/api').default

const flush = () => new Promise(r => setTimeout(r, 0))

function mountSearch() {
  return shallowMount(Search, {
    mocks: {
      $store: { state: { config: { search_simultaneous: 5 } } },
      lang: s => s,
      handleError: jest.fn(),
    },
    stubs: {
      // Stub b-input as a real component exposing focus() so mounted()'s
      // $refs.input.focus() works.
      'b-input': { name: 'b-input', render: h => h('input'), methods: { focus() {} } },
      'b-loading': true,
    },
  })
}

beforeEach(() => {
  jest.clearAllMocks()
})

describe('Search.vue — getDir term matching', () => {
  it('keeps only files whose name contains the search term (case-insensitive)', async () => {
    api.getDir.mockResolvedValue({
      files: [
        { name: 'Apple.txt', type: 'file', path: '/Apple.txt' },
        { name: 'banana.txt', type: 'file', path: '/banana.txt' },
      ],
    })
    const wrapper = mountSearch()
    wrapper.vm.term = 'apple'

    wrapper.vm.getDir('/')
    await flush()

    expect(api.getDir).toHaveBeenCalledWith({ dir: '/' })
    expect(wrapper.vm.results).toHaveLength(1)
    expect(wrapper.vm.results[0].file.name).toBe('Apple.txt')
    expect(wrapper.vm.results[0].dir).toBe('/')
  })

  it('routes a failed getDir through handleError', async () => {
    const err = new Error('boom')
    api.getDir.mockRejectedValue(err)
    const wrapper = mountSearch()
    wrapper.vm.term = 'x'

    wrapper.vm.getDir('/')
    await flush()

    expect(wrapper.vm.handleError).toHaveBeenCalledWith(err)
  })
})

describe('Search.vue — select', () => {
  it('emits the chosen item and closes the modal', () => {
    const wrapper = mountSearch()
    wrapper.vm.$parent.close = jest.fn()
    const item = { file: { path: '/a/b.txt' }, dir: '/a' }

    wrapper.vm.select(item)

    expect(wrapper.emitted('selected')).toEqual([[item]])
    expect(wrapper.vm.$parent.close).toHaveBeenCalled()
  })
})
