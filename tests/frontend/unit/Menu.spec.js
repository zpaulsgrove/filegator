/**
 * Menu.vue — folder-switcher dropdown tests
 *
 * Mocking strategy:
 *   - api module: jest.mock so selectFolder/changeDir return controllable promises
 *   - Vuex: local Vuex.Store with only the state/mutations Menu.vue touches
 *   - lang/is/handleError: provided via `mocks` mount option; `lang` is a
 *     passthrough, `is` delegates to the store's role, `handleError` is a spy
 *   - Buefy components: shallowMount stubs them as <b-dropdown-stub> etc.
 *   - $router/$route: provided via `mocks`
 */

import { shallowMount, createLocalVue } from '@vue/test-utils'
import Vuex from 'vuex'
import Menu from '@/views/partials/Menu.vue'

// ---------------------------------------------------------------------------
// Mock the api module BEFORE anything imports it.
// ---------------------------------------------------------------------------
jest.mock('@/api/api', () => ({
  selectFolder: jest.fn(),
  changeDir: jest.fn(),
}))

// Import after mock so we get the mocked version.
import api from '@/api/api'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const localVue = createLocalVue()
localVue.use(Vuex)

/** Build a minimal store with only the fields Menu.vue reads/commits. */
function makeStore(overrides = {}) {
  const userDefaults = {
    role: 'user',
    permissions: [],
    name: 'Test User',
    username: 'testuser',
    homedirs: [],
    active_homedir: null,
  }
  const state = {
    config: { logo: '' },
    user: { ...userDefaults, ...overrides.user },
    cwd: { location: '/', content: [] },
    tree: {},
  }

  const mutations = {
    setActiveHomedir(state, path) {
      state.user.active_homedir = path
    },
    resetCwd(state) {
      state.cwd = { location: '/', content: [] }
    },
    setCwd(state, data) {
      state.cwd.location = data.location
      state.cwd.content = data.content
    },
  }

  return new Vuex.Store({ state, mutations })
}

/** Standard mount options wired up for a given store. */
function mountMenu(store, routePath = '/') {
  return shallowMount(Menu, {
    localVue,
    store,
    // Explicitly stub the Buefy components. This ensures shallowMount renders
    // them as <b-dropdown-item-stub> etc. regardless of global registration.
    stubs: {
      'b-dropdown': { template: '<div class="folder-switcher"><slot name="trigger"/><slot/></div>' },
      'b-dropdown-item': { template: '<div class="b-dropdown-item-stub"><slot/></div>' },
      'b-icon': { template: '<span/>' },
    },
    mocks: {
      lang: (s) => s,
      is: (role) => store.state.user.role === role,
      handleError: jest.fn(),
      $router: { push: jest.fn() },
      $route: { path: routePath },
      $toast: { open: jest.fn() },
    },
  })
}

// ---------------------------------------------------------------------------
// Reset mocks between tests.
// ---------------------------------------------------------------------------
beforeEach(() => {
  jest.clearAllMocks()
})

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('Menu.vue — folder-switcher dropdown', () => {
  // -------------------------------------------------------------------------
  // 1. Single-folder user — switcher must not be rendered at all.
  // -------------------------------------------------------------------------
  it('hides switcher for single-folder user', () => {
    const store = makeStore({ user: { homedirs: ['/projects'] } })
    const wrapper = mountMenu(store)

    // `hasMultipleFolders` is false for a length-1 array → v-if removes the
    // wrapping b-dropdown which carries the .folder-switcher class.
    expect(wrapper.find('.folder-switcher').exists()).toBe(false)
  })

  // -------------------------------------------------------------------------
  // 2. Multi-folder user — switcher exists and renders one item per homedir.
  // -------------------------------------------------------------------------
  it('shows switcher for multi-folder user', () => {
    const homedirs = ['/projects', '/personal']
    const store = makeStore({ user: { homedirs, active_homedir: '/projects' } })
    const wrapper = mountMenu(store)

    // The b-dropdown has class folder-switcher → should exist.
    expect(wrapper.find('.folder-switcher').exists()).toBe(true)

    // The b-dropdown-item stub renders as <div class="b-dropdown-item-stub">.
    // There is 1 "custom" header item + 1 per homedir = 3 total.
    const items = wrapper.findAll('.b-dropdown-item-stub')
    expect(items.length).toBe(1 + homedirs.length)
  })

  // -------------------------------------------------------------------------
  // 3. Clicking a folder calls api.selectFolder and commits store mutations.
  // -------------------------------------------------------------------------
  it('clicking a folder calls api.selectFolder and commits store mutations', async () => {
    const homedirs = ['/projects', '/personal']
    const store = makeStore({ user: { homedirs, active_homedir: '/projects' } })

    // api.selectFolder resolves immediately.
    api.selectFolder.mockResolvedValue({})

    const wrapper = mountMenu(store)
    const vm = wrapper.vm

    // Invoke switchFolder directly (same as clicking the dropdown item).
    vm.switchFolder('/personal')

    // Allow promise microtasks to flush.
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()

    // api.selectFolder should have been called with the correct homedir.
    expect(api.selectFolder).toHaveBeenCalledTimes(1)
    expect(api.selectFolder).toHaveBeenCalledWith({ homedir: '/personal' })

    // The store mutation setActiveHomedir should have been committed.
    expect(store.state.user.active_homedir).toBe('/personal')
  })

  // -------------------------------------------------------------------------
  // 4. Clicking the currently-active folder is a no-op.
  // -------------------------------------------------------------------------
  it('clicking the currently-active folder is a no-op', async () => {
    const homedirs = ['/projects', '/personal']
    const store = makeStore({ user: { homedirs, active_homedir: '/projects' } })

    api.selectFolder.mockResolvedValue({})

    const wrapper = mountMenu(store)

    wrapper.vm.switchFolder('/projects')

    await wrapper.vm.$nextTick()

    expect(api.selectFolder).not.toHaveBeenCalled()
  })
})
