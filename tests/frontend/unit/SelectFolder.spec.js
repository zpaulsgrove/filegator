/**
 * Unit tests for frontend/views/SelectFolder.vue
 *
 * Stack: Vue 2 + Vuex 3 + @vue/test-utils 1.0.0-beta.29
 *
 * Three things under test:
 *  1. Renders one .folder-button per entry in store.state.user.homedirs
 *  2. Clicking a folder button calls api.selectFolder({ homedir: path })
 *  3. After the API resolves, $router.push('/') is called
 */

import { shallowMount, createLocalVue } from '@vue/test-utils'
import Vuex from 'vuex'

// ── API mock ──────────────────────────────────────────────────────────────────
// api.js uses `export default api`. With __esModule:true Babel's interop
// hands the `default` value directly to the importing module, so
// `import api from '@/api/api'` inside SelectFolder.vue gets our spy object.
jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    selectFolder: jest.fn().mockResolvedValue({}),
    getUser: jest.fn().mockResolvedValue({}),
  },
}))

// ── postLogin mixin mock ──────────────────────────────────────────────────────
// needsFolderPicker is called in mounted(). Return true so the guard
// does NOT redirect on mount (which would add spurious $router.push calls
// that would pollute the assertion in test #3).
jest.mock('@/mixins/postLogin', () => ({
  __esModule: true,
  needsFolderPicker: jest.fn().mockReturnValue(true),
  routeAfterLogin: jest.fn(),
}))

// Import AFTER jest.mock so we get the mocked version
import api from '@/api/api'
import SelectFolder from '@/views/SelectFolder.vue'

// ── local Vue + Vuex ──────────────────────────────────────────────────────────
const localVue = createLocalVue()
localVue.use(Vuex)

/**
 * Build a minimal Vuex store with the given homedirs list.
 * The component reads state.user.homedirs (via the `folders` computed)
 * and commits setActiveHomedir + resetCwd after a successful pick.
 */
function buildStore(homedirs = []) {
  return new Vuex.Store({
    state: {
      user: {
        role: 'user',
        permissions: [],
        name: 'Test User',
        username: 'testuser',
        homedirs,
        // active_homedir must be null for needsFolderPicker to pass
        active_homedir: null,
      },
      config: {
        logo: '',
        language: 'english',
      },
      cwd: { location: '/', content: [] },
      tree: {},
    },
    mutations: {
      setActiveHomedir(state, path) { state.user.active_homedir = path },
      resetCwd(state) { state.cwd = { location: '/', content: [] } },
    },
  })
}

/** Shared mount helper to reduce boilerplate. */
function mountComponent(store, routerPush = jest.fn()) {
  return shallowMount(SelectFolder, {
    localVue,
    store,
    mocks: {
      // shared mixin methods referenced in the template / component body
      lang: s => s,
      is: jest.fn().mockReturnValue(false),
      // handleError is from the shared mixin; stub so a failed pick
      // doesn't try to open a $toast
      handleError: jest.fn(),
      $router: { push: routerPush },
      $toast: { open: jest.fn() },
    },
  })
}

// ── test suite ────────────────────────────────────────────────────────────────
describe('SelectFolder.vue', () => {

  beforeEach(() => {
    api.selectFolder.mockClear()
    api.selectFolder.mockResolvedValue({})
  })

  // ── Test 1 ──────────────────────────────────────────────────────────────────
  it('renders one card per homedir', () => {
    const homedirs = ['/projects', '/personal', '/archive']
    const store = buildStore(homedirs)
    const wrapper = mountComponent(store)

    const buttons = wrapper.findAll('.folder-button')
    expect(buttons.length).toBe(3)

    // Verify path text is rendered inside each button
    expect(buttons.at(0).text()).toContain('/projects')
    expect(buttons.at(1).text()).toContain('/personal')
    expect(buttons.at(2).text()).toContain('/archive')
  })

  // ── Test 2 ──────────────────────────────────────────────────────────────────
  it('clicking a folder card POSTs to api.selectFolder with the chosen path', async () => {
    const homedirs = ['/projects', '/personal', '/archive']
    const store = buildStore(homedirs)
    const wrapper = mountComponent(store)

    // Click the second button (/personal, index 1)
    const buttons = wrapper.findAll('.folder-button')
    await buttons.at(1).trigger('click')

    expect(api.selectFolder).toHaveBeenCalledTimes(1)
    expect(api.selectFolder).toHaveBeenCalledWith({ homedir: '/personal' })
  })

  // ── Test 3 ──────────────────────────────────────────────────────────────────
  it('successful pick routes to /', async () => {
    const homedirs = ['/projects', '/personal', '/archive']
    const store = buildStore(homedirs)
    const routerPush = jest.fn()
    const wrapper = mountComponent(store, routerPush)

    // Click the second button (/personal)
    const buttons = wrapper.findAll('.folder-button')
    await buttons.at(1).trigger('click')

    // Flush the resolved promise chain (selectFolder resolves → .then fires)
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(routerPush).toHaveBeenCalledWith('/')
  })

})
