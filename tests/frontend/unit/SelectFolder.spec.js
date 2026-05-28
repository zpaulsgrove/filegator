// Defensive mock: api.js is imported by SelectFolder.vue directly.
jest.mock('@/api/api', () => ({ __esModule: true, default: { selectFolder: jest.fn() } }))

// Mock the postLogin mixin so the mounted() guard (needsFolderPicker) doesn't
// fire an unwanted router.push('/') before we can assert anything. Default:
// return true so the picker renders. Individual tests can override if needed.
jest.mock('@/mixins/postLogin', () => ({
  __esModule: true,
  needsFolderPicker: jest.fn(() => true),
  routeAfterLogin: jest.fn(),
}))

import { shallowMount } from '@vue/test-utils'
import SelectFolder from '@/views/SelectFolder.vue'

const api = require('@/api/api').default
const postLogin = require('@/mixins/postLogin')

// ── Helpers ───────────────────────────────────────────────────────────────────

function flushPromises() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

// ── Mount helper ──────────────────────────────────────────────────────────────

function mountSelectFolder(homedirs = ['/projects', '/personal', '/archive']) {
  const store = {
    state: {
      user: {
        homedirs,
        active_homedir: null,
      },
      config: {
        logo: '/logo.png',
      },
    },
    commit: jest.fn(),
  }

  return shallowMount(SelectFolder, {
    mocks: {
      $store: store,
      $router: { push: jest.fn() },
      $route: { path: '/select-folder', query: {} },
      lang: (s, ...rest) => rest.length ? s + ' ' + rest.join(' ') : s,
      handleError: jest.fn(),
    },
  })
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks()
  // Re-arm the guard to return true (picker should render) before each test.
  postLogin.needsFolderPicker.mockReturnValue(true)
})

describe('SelectFolder.vue', () => {

  // 1. Renders one folder card per homedir
  it('renders one folder card per homedir', () => {
    const wrapper = mountSelectFolder(['/projects', '/personal', '/archive'])
    const buttons = wrapper.findAll('.folder-button')
    expect(buttons.length).toBe(3)
    expect(buttons.at(0).find('.folder-path').text()).toBe('/projects')
    expect(buttons.at(1).find('.folder-path').text()).toBe('/personal')
    expect(buttons.at(2).find('.folder-path').text()).toBe('/archive')
  })

  // 2. Clicking a folder posts to api.selectFolder with the chosen path
  it('clicking a folder posts to api.selectFolder with the chosen path', async () => {
    api.selectFolder.mockResolvedValue({})
    const wrapper = mountSelectFolder(['/projects', '/personal', '/archive'])

    // Call the method directly (same as the reference specs use vm.remove / vm.save)
    wrapper.vm.pick('/personal')

    expect(api.selectFolder).toHaveBeenCalledTimes(1)
    expect(api.selectFolder).toHaveBeenCalledWith({ homedir: '/personal' })
  })

  // 3. Successful pick routes to '/' and commits setActiveHomedir (and resetCwd)
  it('successful pick routes to "/" and commits setActiveHomedir', async () => {
    api.selectFolder.mockResolvedValue({})
    const wrapper = mountSelectFolder(['/projects', '/personal', '/archive'])

    wrapper.vm.pick('/personal')
    await flushPromises()

    expect(wrapper.vm.$store.commit).toHaveBeenCalledWith('setActiveHomedir', '/personal')
    expect(wrapper.vm.$router.push).toHaveBeenCalledWith('/')
  })
})
