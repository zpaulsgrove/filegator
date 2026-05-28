/**
 * Unit tests for frontend/views/partials/UserEdit.vue
 *
 * Stack: Vue 2 + Vuex 3 + @vue/test-utils 1.0.0-beta.29
 *
 * Four things under test:
 *  1. Renders one .folder-row per entry in formFields.homedirs on mount
 *  2. addFolder() appends an empty folder row
 *  3. removeFolder(idx) removes only that row and leaves the others intact
 *  4. save() strips blank rows before handing off to api.updateUser
 *
 * Data-shape notes (from UserEdit.vue source):
 *  - formFields.homedirs is a plain Array<string>.  On init it copies
 *    user.homedirs if that is a non-empty array, otherwise falls back to
 *    [user.homedir || ''].  Plain strings, not comma-separated, not objects.
 *  - addFolder()        → pushes ''.
 *  - removeFolder(idx)  → splices; guards against length <= 1.
 *  - save()             → filters .trim() === '' before building the payload.
 *
 * Mocking strategy (mirrors Menu.spec.js / SelectFolder.spec.js patterns):
 *  - api module: jest.mock with __esModule:true so Babel's interop hands the
 *    `default` value to `import api from '@/api/api'` inside the component.
 *  - Tree component: jest.mock so vue-jest never tries to compile the SFC.
 *  - Vuex: minimal local store (the shared lang mixin reads config.language
 *    from $store; we must provide the store even though lang is also mocked).
 *  - lang / handleError / is: provided via `mocks` mount option.
 *  - $parent.close / $toast.open / $modal.open / $dialog.confirm: stubbed.
 */

import { shallowMount, createLocalVue } from '@vue/test-utils'
import Vuex from 'vuex'

// ── API mock — must be declared BEFORE the component import ───────────────────
// api.js uses `export default api`.  __esModule:true makes the Babel interop
// layer hand the `default` property to `import api from '@/api/api'` inside
// the component, so our spies are the exact objects the component calls.
jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    updateUser: jest.fn().mockResolvedValue({}),
    storeUser:  jest.fn().mockResolvedValue({}),
  },
}))

// ── Tree sub-component mock ───────────────────────────────────────────────────
// UserEdit.vue imports Tree for the folder-picker modal.  Stub it so
// vue-jest does not compile a second SFC during the test run.
jest.mock('@/views/partials/Tree', () => ({
  __esModule: true,
  default: { name: 'Tree', render: h => h('div') },
}))

// Import AFTER jest.mock so we receive the mocked version.
import api       from '@/api/api'
import UserEdit  from '@/views/partials/UserEdit.vue'

// ── Local Vue + Vuex ──────────────────────────────────────────────────────────
const localVue = createLocalVue()
localVue.use(Vuex)

/** Minimal store; the shared lang/is mixins read config.language + user.role. */
function buildStore() {
  return new Vuex.Store({
    state: {
      config: { language: 'english', pagination: [25] },
      user:   { role: 'admin', username: 'admin', permissions: [] },
    },
  })
}

/**
 * Build a minimal user prop that satisfies the component's data() initialiser.
 * @param {string[]} homedirs  Folder rows to seed (default: one row).
 * @param {Object}   extra     Any other user fields to override.
 */
function makeUser(homedirs = ['/home/alice'], extra = {}) {
  return {
    role:        'user',
    name:        'Alice',
    username:    'alice',
    email:       'alice@example.com',
    homedir:     homedirs[0] || '',
    homedirs,
    permissions: ['read'],
    mfa_enabled: false,
    ...extra,
  }
}

/**
 * Mount UserEdit with sensible defaults.
 * @param {Object} user    Value for the `user` prop.
 * @param {string} action  'edit' (default) or 'add'.
 */
function mountComponent(user, action = 'edit') {
  const wrapper = shallowMount(UserEdit, {
    localVue,
    store: buildStore(),
    propsData: { user, action },
    mocks: {
      // Identity translation so lang('X') === 'X' everywhere.
      lang:        s => s,
      is:          jest.fn().mockReturnValue(false),
      handleError: jest.fn(),
      // $toast / $modal / $dialog are safe to provide via mocks because they
      // are not native Vue properties — Vue won't prevent the override.
      $toast:   { open:  jest.fn() },
      $modal:   { open:  jest.fn() },
      $dialog:  { confirm: jest.fn() },
    },
  })

  // $parent IS a native Vue 2 instance property (getter returning the real
  // parent, which is undefined in a shallowMount without a wrapper parent).
  // Vue 2's test-utils mocks option cannot reliably override native $parent.
  // Assign it directly on the vm after mounting so save()'s .then() callback
  // can call this.$parent.close() without throwing.
  wrapper.vm.$parent = { close: jest.fn() }

  return wrapper
}

// ── Reset spies between tests ─────────────────────────────────────────────────
beforeEach(() => {
  jest.clearAllMocks()
})

// ── Test suite ─────────────────────────────────────────────────────────────────
describe('UserEdit.vue — repeatable folder rows', () => {

  // ── Test 1: initial render ──────────────────────────────────────────────────
  it('renders one folder row per homedir on mount', () => {
    const wrapper = mountComponent(makeUser(['/projects', '/personal']))

    // The template v-for renders <div class="folder-row"> for every entry in
    // formFields.homedirs.  shallowMount stubs b-input but keeps the host div.
    expect(wrapper.findAll('.folder-row').length).toBe(2)
  })

  // ── Test 2: add row ─────────────────────────────────────────────────────────
  it('clicking the + button adds an empty folder row', async () => {
    const wrapper = mountComponent(makeUser(['/projects']))

    expect(wrapper.findAll('.folder-row').length).toBe(1)

    // The add button carries class .add-folder-btn.
    await wrapper.find('.add-folder-btn').trigger('click')

    expect(wrapper.findAll('.folder-row').length).toBe(2)
    expect(wrapper.vm.formFields.homedirs).toEqual(['/projects', ''])
  })

  // ── Test 3: remove row ──────────────────────────────────────────────────────
  it('clicking the − button removes that row and leaves the others intact', async () => {
    const wrapper = mountComponent(makeUser(['/projects', '/personal', '/archive']))

    expect(wrapper.findAll('.folder-row').length).toBe(3)

    // Invoke removeFolder() directly on the vm — the × button in the template
    // calls it; we avoid fragile nth-child DOM queries for the stub renders.
    wrapper.vm.removeFolder(1)
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('.folder-row').length).toBe(2)
    expect(wrapper.vm.formFields.homedirs).toEqual(['/projects', '/archive'])
  })

  // Guard: removeFolder should be a no-op when only one row remains.
  it('removeFolder() does nothing when only one row is present', async () => {
    const wrapper = mountComponent(makeUser(['/projects']))

    wrapper.vm.removeFolder(0)
    await wrapper.vm.$nextTick()

    expect(wrapper.vm.formFields.homedirs).toHaveLength(1)
    expect(wrapper.vm.formFields.homedirs[0]).toBe('/projects')
  })

  // ── Test 4: save strips empty rows ─────────────────────────────────────────
  it('save() strips empty rows and sends only non-empty homedirs to the api', async () => {
    // Ensure the mock resolves cleanly for this test (clearAllMocks in
    // beforeEach wipes call history but not implementations in Jest 24;
    // re-asserting here makes the intent explicit and guards against any
    // version drift in that behaviour).
    api.updateUser.mockResolvedValue({})

    // Three rows, middle one is blank.
    const wrapper = mountComponent(makeUser(['/a', '/b']))

    // Inject the empty middle row directly into formFields.
    wrapper.vm.formFields.homedirs = ['/a', '', '/b']

    // Call save() directly (same code path as clicking Save for a non-guest
    // role — confirmSave() would just call save() without the dialog guard).
    wrapper.vm.save()

    // Flush the resolved-promise microtask chain.
    await new Promise(resolve => setTimeout(resolve, 0))

    // action == 'edit' → api.updateUser is selected, not storeUser.
    expect(api.updateUser).toHaveBeenCalledTimes(1)
    expect(api.storeUser).not.toHaveBeenCalled()

    const payload = api.updateUser.mock.calls[0][0]

    // Empty row must be absent.
    expect(payload.homedirs).toEqual(['/a', '/b'])

    // Back-compat scalar: first non-empty folder.
    expect(payload.homedir).toBe('/a')
  })

  // Edge case: action == 'add' routes to storeUser.
  it('save() calls storeUser (not updateUser) when action is "add"', async () => {
    api.storeUser.mockResolvedValue({})

    const wrapper = mountComponent(makeUser(['/home/bob']), 'add')

    wrapper.vm.save()
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(api.storeUser).toHaveBeenCalledTimes(1)
    expect(api.updateUser).not.toHaveBeenCalled()
  })

})
