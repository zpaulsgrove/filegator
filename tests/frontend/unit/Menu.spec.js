/**
 * Menu.vue — folder-switcher dropdown unit tests
 *
 * Pins the visibility toggle (hasMultipleFolders), the dropdown item count
 * for multi-folder users, the happy-path switchFolder() call chain, and the
 * no-op guard when the user clicks their already-active folder.
 */

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    selectFolder: jest.fn(),
    logout: jest.fn(),
    changeDir: jest.fn(),
    getUser: jest.fn(),
  },
}))

import { shallowMount } from '@vue/test-utils'
import Menu from '@/views/partials/Menu.vue'

const api = require('@/api/api').default

// ── Helpers ──────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Stubs ────────────────────────────────────────────────────────────────────

// b-dropdown needs to render its default slot so we can count b-dropdown-item
// children inside it.
const BDropdownStub = {
  name: 'b-dropdown',
  props: ['disabled', 'ariaRole'],
  template: '<div class="b-dropdown-stub"><slot /></div>',
}

// b-dropdown-item renders its slot so text content is accessible.
const BDropdownItemStub = {
  name: 'b-dropdown-item',
  props: ['custom'],
  template: '<div class="b-dropdown-item-stub"><slot /></div>',
}

// ── Mount helper ──────────────────────────────────────────────────────────────

function mountMenu(userOverrides = {}, isFn = role => role !== 'guest') {
  const user = {
    name: 'Alice',
    role: 'user',
    homedirs: ['/projects'],
    active_homedir: '/projects',
    ...userOverrides,
  }

  const store = {
    state: {
      user,
      config: { logo: '/logo.png' },
    },
    commit: jest.fn(),
  }

  return shallowMount(Menu, {
    mocks: {
      lang: s => s,
      handleError: jest.fn(),
      is: isFn,
      $store: store,
      $router: { push: jest.fn() },
      $route: { path: '/' },
    },
    stubs: {
      'b-dropdown': BDropdownStub,
      'b-dropdown-item': BDropdownItemStub,
      'b-icon': true,
    },
  })
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks()
})

describe('Menu.vue — folder-switcher dropdown', () => {

  // 1. Single-folder user — switcher hidden
  it('hides switcher for single-folder user', () => {
    const wrapper = mountMenu({ homedirs: ['/projects'] })
    expect(wrapper.find('.folder-switcher').exists()).toBe(false)
  })

  // 2. Multi-folder user — switcher visible with one item per homedir + header
  it('shows switcher for multi-folder user with correct item count', () => {
    const wrapper = mountMenu({
      homedirs: ['/projects', '/personal'],
      active_homedir: '/projects',
    })

    // The wrapper b-dropdown stub carries the folder-switcher class via Vue
    // binding; find it via the stub root class.
    expect(wrapper.find('.folder-switcher').exists()).toBe(true)

    // 1 header item (custom) + 2 homedir items = 3 b-dropdown-item stubs
    const items = wrapper.findAll('.b-dropdown-item-stub')
    expect(items.length).toBe(3)
  })

  // 3. switchFolder() calls api.selectFolder and commits setActiveHomedir
  it('switchFolder() calls api.selectFolder and commits setActiveHomedir', async () => {
    api.selectFolder.mockResolvedValue({})

    const wrapper = mountMenu({
      homedirs: ['/projects', '/personal'],
      active_homedir: '/projects',
    })

    wrapper.vm.switchFolder('/personal')

    // First flush: releases the api.selectFolder promise resolution
    await flushPromises()
    // Second flush: releases the .then(() => store.commit(...)) chain
    await flushPromises()

    expect(api.selectFolder).toHaveBeenCalledTimes(1)
    expect(api.selectFolder).toHaveBeenCalledWith({ homedir: '/personal' })

    expect(wrapper.vm.$store.commit).toHaveBeenCalledWith('setActiveHomedir', '/personal')
  })

  // 4. Clicking the currently-active folder is a no-op
  it('clicking the currently-active folder does not call api.selectFolder', () => {
    const wrapper = mountMenu({
      homedirs: ['/projects', '/personal'],
      active_homedir: '/projects',
    })

    wrapper.vm.switchFolder('/projects')

    expect(api.selectFolder).not.toHaveBeenCalled()
  })
})

describe('Menu.vue — admin nav links (is(\'admin\') gating)', () => {
  // Faithful is(): compares the requested role to the actual user role.
  it('shows the admin nav links (incl. audit-log) for an admin', () => {
    const wrapper = mountMenu({ role: 'admin' }, role => role === 'admin')

    expect(wrapper.find('[data-test="nav-audit-log"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="nav-reports"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="nav-users"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="nav-folder-access"]').exists()).toBe(true)
  })

  it('hides the admin nav links for a non-admin user', () => {
    const wrapper = mountMenu({ role: 'user' }, role => role === 'user')

    expect(wrapper.find('[data-test="nav-audit-log"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="nav-reports"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="nav-users"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="nav-folder-access"]').exists()).toBe(false)
  })
})
