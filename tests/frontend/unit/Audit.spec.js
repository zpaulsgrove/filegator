/**
 * Audit.vue — admin file-activity audit unit tests
 *
 * Covers the client-side logic the e2e spec (audit-log.cy.js) cannot cheaply
 * reach: the filteredEvents computed (search + actionFilter), the actionType()
 * tag-colour map, and load()'s three branches (events, no-events, error).
 *
 * Mocking strategy (matches Users.spec.js / Security.spec.js conventions):
 *   - api module: jest.mock with __esModule:true, default: { auditLog }
 *   - shared mixin methods (lang/capitalize/formatDate) are registered globally
 *     via Vue.mixin(shared) in main.js, NOT imported by Audit.vue — so they must
 *     be supplied as mocks in the mount.
 *   - handleError is likewise a shared mixin method, mocked here.
 *   - $store.state.config.pagination[0] seeds perPage in data().
 *   - Menu / Pagination / b-* components are shallow-stubbed.
 */

import { shallowMount } from '@vue/test-utils'
import Audit from '@/views/Audit.vue'

// ── Helpers ──────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Module mocks ─────────────────────────────────────────────────────────────

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    auditLog: jest.fn(),
  },
}))

const api = require('@/api/api').default

// ── Mount helper ──────────────────────────────────────────────────────────────

function mountAudit() {
  return shallowMount(Audit, {
    mocks: {
      $store: {
        state: {
          config: { pagination: [10, 25, 50] },
        },
      },
      // shared mixin methods (global via Vue.mixin in main.js)
      lang: s => s,
      capitalize: s => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s),
      formatDate: ts => String(ts),
      handleError: jest.fn(),
    },
    stubs: {
      Menu: true,
      Pagination: true,
      'b-table': true,
      'b-table-column': true,
      'b-select': true,
      'b-input': true,
      'b-icon': true,
      'b-tag': true,
    },
  })
}

// A small fixture spanning several actions/users/paths/details.
const SAMPLE_EVENTS = [
  { ts: 3, user: 'alice', role: 'admin', action: 'upload', path: '/docs/report.pdf', detail: '' },
  { ts: 2, user: 'bob', role: 'user', action: 'delete', path: '/photos/cat.jpg', detail: '' },
  { ts: 1, user: 'carol', role: 'user', action: 'rename', path: '/notes.txt', detail: 'to memo.txt' },
]

// ── Reset mocks between tests ─────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks()
  // Default: auditLog resolves empty so mounted()'s load() settles cleanly
  // unless a test overrides it.
  api.auditLog.mockResolvedValue({ events: [] })
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Audit.vue — filteredEvents computed', () => {

  it('empty search returns all events', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = ''

    expect(wrapper.vm.filteredEvents).toHaveLength(3)
  })

  it('whitespace-only search is trimmed and returns all events', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = '   '

    expect(wrapper.vm.filteredEvents).toHaveLength(3)
  })

  it('matches on user (case-insensitive)', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = 'ALICE'

    expect(wrapper.vm.filteredEvents.map(e => e.user)).toEqual(['alice'])
  })

  it('matches on path', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = 'cat.jpg'

    expect(wrapper.vm.filteredEvents.map(e => e.user)).toEqual(['bob'])
  })

  it('matches on action', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = 'rename'

    expect(wrapper.vm.filteredEvents.map(e => e.user)).toEqual(['carol'])
  })

  it('matches on detail', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = 'memo'

    expect(wrapper.vm.filteredEvents.map(e => e.user)).toEqual(['carol'])
  })

  it('search is trimmed before matching (leading/trailing spaces ignored)', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = '  alice  '

    expect(wrapper.vm.filteredEvents.map(e => e.user)).toEqual(['alice'])
  })

  it('non-matching search returns no events', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.search = 'no-such-thing'

    expect(wrapper.vm.filteredEvents).toHaveLength(0)
  })

  it('actionFilter narrows to the exact action', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = SAMPLE_EVENTS
    wrapper.vm.actionFilter = 'delete'

    expect(wrapper.vm.filteredEvents.map(e => e.user)).toEqual(['bob'])
  })

  it('actionFilter is exact — does not match by substring', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = [
      { ts: 1, user: 'a', role: 'user', action: 'upload', path: '/x', detail: '' },
      { ts: 2, user: 'b', role: 'user', action: 'unzip', path: '/y', detail: '' },
    ]
    wrapper.vm.actionFilter = 'zip'

    expect(wrapper.vm.filteredEvents).toHaveLength(0)
  })

  it('search + actionFilter combine with AND', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = [
      { ts: 1, user: 'alice', role: 'user', action: 'delete', path: '/a', detail: '' },
      { ts: 2, user: 'alice', role: 'user', action: 'upload', path: '/b', detail: '' },
      { ts: 3, user: 'bob', role: 'user', action: 'delete', path: '/c', detail: '' },
    ]
    wrapper.vm.search = 'alice'
    wrapper.vm.actionFilter = 'delete'

    // Only the alice+delete row satisfies both predicates.
    expect(wrapper.vm.filteredEvents.map(e => e.path)).toEqual(['/a'])
  })

  it('tolerates rows with missing fields (no throw, treats as empty strings)', () => {
    const wrapper = mountAudit()
    wrapper.vm.events = [
      { ts: 1, action: 'upload' }, // no user/path/detail
    ]
    wrapper.vm.search = 'upload'

    expect(wrapper.vm.filteredEvents).toHaveLength(1)
  })
})

describe('Audit.vue — actionType() colour map', () => {

  it('delete -> is-danger', () => {
    const wrapper = mountAudit()
    expect(wrapper.vm.actionType('delete')).toBe('is-danger')
  })

  it('upload and create -> is-success', () => {
    const wrapper = mountAudit()
    expect(wrapper.vm.actionType('upload')).toBe('is-success')
    expect(wrapper.vm.actionType('create')).toBe('is-success')
  })

  it('move and rename -> is-warning', () => {
    const wrapper = mountAudit()
    expect(wrapper.vm.actionType('move')).toBe('is-warning')
    expect(wrapper.vm.actionType('rename')).toBe('is-warning')
  })

  it('copy, zip and unzip -> is-info', () => {
    const wrapper = mountAudit()
    expect(wrapper.vm.actionType('copy')).toBe('is-info')
    expect(wrapper.vm.actionType('zip')).toBe('is-info')
    expect(wrapper.vm.actionType('unzip')).toBe('is-info')
  })

  it('save and unknown actions fall through to is-light', () => {
    const wrapper = mountAudit()
    expect(wrapper.vm.actionType('save')).toBe('is-light')
    expect(wrapper.vm.actionType('chmod')).toBe('is-light')
    expect(wrapper.vm.actionType('something-else')).toBe('is-light')
    expect(wrapper.vm.actionType(undefined)).toBe('is-light')
  })
})

describe('Audit.vue — load()', () => {

  it('populates events from ret.events on resolve', async () => {
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    const wrapper = mountAudit()
    await flushPromises() // mounted() -> load() settles

    expect(api.auditLog).toHaveBeenCalledTimes(1)
    expect(wrapper.vm.events).toEqual(SAMPLE_EVENTS)
    expect(wrapper.vm.isLoading).toBe(false)
  })

  it('sets events to [] when the response has no events key', async () => {
    api.auditLog.mockResolvedValue({})
    const wrapper = mountAudit()
    await flushPromises()

    expect(wrapper.vm.events).toEqual([])
    expect(wrapper.vm.isLoading).toBe(false)
  })

  it('sets events to [] when the response is null/undefined', async () => {
    api.auditLog.mockResolvedValue(undefined)
    const wrapper = mountAudit()
    await flushPromises()

    expect(wrapper.vm.events).toEqual([])
    expect(wrapper.vm.isLoading).toBe(false)
  })

  it('routes a rejection to handleError and resets isLoading', async () => {
    const err = new Error('Network down')
    api.auditLog.mockRejectedValue(err)
    const wrapper = mountAudit()
    await flushPromises()

    expect(wrapper.vm.handleError).toHaveBeenCalledWith(err)
    expect(wrapper.vm.isLoading).toBe(false)
  })

  it('sets isLoading true while the request is in flight', () => {
    api.auditLog.mockReturnValue(new Promise(() => {})) // never resolves
    const wrapper = mountAudit()

    // mounted() called load(); the pending promise leaves isLoading true.
    expect(wrapper.vm.isLoading).toBe(true)
  })
})
