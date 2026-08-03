// Reports.vue — the admin 30-day file-activity report.
//
// Follows the Audit.spec.js convention: shallowMount, api mocked at module
// level, shared-mixin methods supplied as `mocks` (they are registered
// globally via Vue.mixin(shared) in main.js, which unit mounts never run), and
// assertions driven against computeds/methods rather than the DOM.
//
// The CSV block carries the most weight here. The escaping rules are pure
// functions, so this is where they belong — an e2e spec could only smoke-test
// that a file reaches disk, which is a far weaker signal.

import { shallowMount } from '@vue/test-utils'
import Reports from '@/views/Reports.vue'

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

function mountReports(mocks = {}) {
  return shallowMount(Reports, {
    mocks: Object.assign({
      $store: {
        state: {
          // The shipped default is ['', 5, 10, 15], i.e. perPage === '' and
          // pagination OFF. Mirror that here so the rollups are exercised in
          // the configuration the app actually ships with.
          config: { pagination: ['', 5, 10, 15] },
        },
      },
      $dialog: { confirm: jest.fn() },
      $toast: { open: jest.fn() },
      // shared mixin methods (global via Vue.mixin in main.js)
      lang: (s, ...args) => args.reduce((acc, a, i) => acc.replace('{' + i + '}', a), s),
      capitalize: s => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s),
      formatDate: ts => 'D' + ts,
      handleError: jest.fn(),
      escapeHtml: s => s,
      saveBlob: jest.fn(),
    }, mocks),
    stubs: {
      Menu: true,
      Pagination: true,
      'b-table': true,
      'b-table-column': true,
      'b-icon': true,
      'b-tag': true,
    },
  })
}

// Fixture with realistic shapes: paths are always root-relative and
// "/"-prefixed (RecordsAuditEvents::auditNormalize guarantees it), and
// `detail` is a prefixed human string, never a bare path.
const SAMPLE_EVENTS = [
  { ts: 500, user: 'alice', role: 'admin', action: 'upload', path: '/clientA/2026/return.pdf', detail: null },
  { ts: 400, user: 'alice', role: 'admin', action: 'upload', path: '/clientA/2026/w2.pdf', detail: null },
  { ts: 300, user: 'bob', role: 'user', action: 'move', path: '/clientB/moved.pdf', detail: 'from /clientA/2026/old.pdf' },
  { ts: 200, user: 'bob', role: 'user', action: 'chmod', path: '/clientB/moved.pdf', detail: 'mode 0755' },
  { ts: 100, user: 'carol', role: 'user', action: 'delete', path: '/top.txt', detail: 'folder' },
]

const FIXED_MS = 1770000000000 // fixed wall clock for window assertions
const FIXED_S = Math.floor(FIXED_MS / 1000)

beforeEach(() => {
  jest.clearAllMocks()
  api.auditLog.mockResolvedValue({ events: [] })
})

afterEach(() => {
  if (Date.now.mockRestore) Date.now.mockRestore()
})

// ── The request window ────────────────────────────────────────────────────────

describe('Reports.vue — the 30-day window', () => {
  it('requests exactly 30 days ending now, in epoch SECONDS', async () => {
    jest.spyOn(Date, 'now').mockReturnValue(FIXED_MS)

    const wrapper = mountReports()
    await flushPromises()

    expect(api.auditLog).toHaveBeenCalledTimes(1)
    const { from, to } = api.auditLog.mock.calls[0][0]

    expect(to - from).toBe(30 * 86400)
    // Magnitude matters as much as the delta: if the /1000 is ever dropped,
    // the delta is STILL 2592000 while from/to become year-56000 epochs. The
    // backend (int)-casts them, every event falls outside the range, and the
    // report is permanently empty with a green suite.
    expect(to).toBe(FIXED_S)
    expect(wrapper.vm.windowFrom).toBe(from)
    expect(wrapper.vm.windowTo).toBe(to)
  })

  it('leaves the window untouched when the refresh fails', async () => {
    jest.spyOn(Date, 'now').mockReturnValue(FIXED_MS)
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    const wrapper = mountReports()
    await flushPromises()

    // A failed refresh must not advance the header/filename past the data
    // still on screen — otherwise the export is named for a window that was
    // never successfully fetched.
    Date.now.mockReturnValue(FIXED_MS + 86400000)
    api.auditLog.mockRejectedValue(new Error('offline'))
    wrapper.vm.load()
    await flushPromises()

    expect(wrapper.vm.windowTo).toBe(FIXED_S)
    expect(wrapper.vm.events).toEqual(SAMPLE_EVENTS)
  })

  it('ignores a slow earlier response that lands after a newer one', async () => {
    jest.spyOn(Date, 'now').mockReturnValue(FIXED_MS)
    let resolveFirst
    api.auditLog.mockReturnValueOnce(new Promise(r => { resolveFirst = r }))
    const wrapper = mountReports()

    // Second request resolves first and wins.
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    wrapper.vm.load()
    await flushPromises()
    expect(wrapper.vm.events).toEqual(SAMPLE_EVENTS)

    // The stale first request now lands; it must not clobber the newer data
    // nor flip isLoading while it is the older generation.
    resolveFirst({ events: [] })
    await flushPromises()
    expect(wrapper.vm.events).toEqual(SAMPLE_EVENTS)
  })

  it('re-pins the window on refresh', async () => {
    jest.spyOn(Date, 'now').mockReturnValue(FIXED_MS)
    const wrapper = mountReports()
    await flushPromises()

    Date.now.mockReturnValue(FIXED_MS + 60000)
    wrapper.vm.load()
    await flushPromises()

    expect(wrapper.vm.windowTo).toBe(FIXED_S + 60)
    expect(api.auditLog.mock.calls[1][0].to).toBe(FIXED_S + 60)
  })
})

// ── load() ────────────────────────────────────────────────────────────────────

describe('Reports.vue — load()', () => {
  it('populates events from the response', async () => {
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    const wrapper = mountReports()
    await flushPromises()

    expect(wrapper.vm.events).toEqual(SAMPLE_EVENTS)
    expect(wrapper.vm.isLoading).toBe(false)
  })

  it('treats a payload with no events key as empty', async () => {
    api.auditLog.mockResolvedValue({})
    const wrapper = mountReports()
    await flushPromises()
    expect(wrapper.vm.events).toEqual([])
  })

  it('treats an undefined payload as empty', async () => {
    api.auditLog.mockResolvedValue(undefined)
    const wrapper = mountReports()
    await flushPromises()
    expect(wrapper.vm.events).toEqual([])
  })

  it('routes a rejection to handleError and clears the loading flag', async () => {
    const err = new Error('boom')
    api.auditLog.mockRejectedValue(err)
    const wrapper = mountReports()
    await flushPromises()

    expect(wrapper.vm.handleError).toHaveBeenCalledWith(err)
    expect(wrapper.vm.isLoading).toBe(false)
  })

  it('keeps isLoading true while the request is in flight', () => {
    api.auditLog.mockReturnValue(new Promise(() => {}))
    const wrapper = mountReports()
    expect(wrapper.vm.isLoading).toBe(true)
  })

  it('drops the decrypted events when the view is destroyed', async () => {
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    const wrapper = mountReports()
    await flushPromises()
    expect(wrapper.vm.events.length).toBe(5)

    wrapper.destroy()
    expect(wrapper.vm.events).toEqual([])
  })
})

// ── Rollups ───────────────────────────────────────────────────────────────────

describe('Reports.vue — rollups', () => {
  let wrapper

  beforeEach(async () => {
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    wrapper = mountReports()
    await flushPromises()
  })

  it('counts every action, seeding the inactive ones at zero', () => {
    const rows = wrapper.vm.byAction
    // All ten backend actions are always present, so a reader can see that
    // e.g. nothing was deleted rather than wondering where the row went.
    expect(rows.length).toBe(10)

    const byKey = rows.reduce((acc, r) => Object.assign(acc, { [r.key]: r.count }), {})
    expect(byKey.upload).toBe(2)
    expect(byKey.move).toBe(1)
    expect(byKey.chmod).toBe(1)
    expect(byKey.delete).toBe(1)
    expect(byKey.zip).toBe(0)
    expect(byKey.unzip).toBe(0)
  })

  it('sorts rollups by count desc, then key asc', () => {
    const users = wrapper.vm.byUser
    expect(users.map(r => r.key)).toEqual(['alice', 'bob', 'carol'])
    expect(users.map(r => r.count)).toEqual([2, 2, 1])
    // alice and bob tie at 2; alphabetical order breaks the tie so successive
    // pulls of the same data render identically.
  })

  it('groups folders at the immediate parent directory', () => {
    const byKey = wrapper.vm.byFolder.reduce((acc, r) => Object.assign(acc, { [r.key]: r.count }), {})
    expect(byKey['/clientA/2026']).toBe(2)
    expect(byKey['/clientB']).toBe(2)
    // A file at the storage root groups under "/".
    expect(byKey['/']).toBe(1)
  })

  it('counts a move against its destination folder only', () => {
    // The move's detail is "from /clientA/2026/old.pdf". If the rollup ever
    // reads detail as a path, /clientA/2026 gains a third event and the move
    // is double-counted.
    const byKey = wrapper.vm.byFolder.reduce((acc, r) => Object.assign(acc, { [r.key]: r.count }), {})
    expect(byKey['/clientA/2026']).toBe(2)
    expect(byKey['/clientB']).toBe(2)
  })

  it('reports totals, leaders and the oldest event', () => {
    expect(wrapper.vm.totalEvents).toBe(5)
    expect(wrapper.vm.topUser).toEqual({ key: 'alice', count: 2 })
    expect(wrapper.vm.topFolder.count).toBe(2)
    // The backend sorts newest-first, so the last element is the oldest.
    expect(wrapper.vm.oldestEventTs).toBe(100)
  })

  it('flags when the log covers less than the requested period', async () => {
    // AuditLog::query applies its own max_age_days cutoff, so a deployment
    // retaining 7 days answers a 30-day request with 7. The header and the CSV
    // filename both name the REQUESTED period, so the shortfall has to be
    // stated rather than implied.
    jest.spyOn(Date, 'now').mockReturnValue(FIXED_MS)
    api.auditLog.mockResolvedValue({
      events: [{ ts: FIXED_S - (3 * 86400), user: 'a', role: 'user', action: 'upload', path: '/a.txt' }],
    })
    const w = mountReports()
    await flushPromises()

    expect(w.vm.coverageLimited).toBe(true)
  })

  it('does not flag limited coverage when the window is genuinely covered', async () => {
    jest.spyOn(Date, 'now').mockReturnValue(FIXED_MS)
    api.auditLog.mockResolvedValue({
      events: [{ ts: FIXED_S - (30 * 86400) + 100, user: 'a', role: 'user', action: 'upload', path: '/a.txt' }],
    })
    const w = mountReports()
    await flushPromises()

    expect(w.vm.coverageLimited).toBe(false)
  })

  it('does not flag limited coverage on an empty log', async () => {
    // Nothing to be limited about — the empty-state copy covers this case.
    api.auditLog.mockResolvedValue({ events: [] })
    const w = mountReports()
    await flushPromises()

    expect(w.vm.coverageLimited).toBe(false)
  })

  it('survives an empty log without throwing', async () => {
    api.auditLog.mockResolvedValue({ events: [] })
    const empty = mountReports()
    await flushPromises()

    expect(empty.vm.totalEvents).toBe(0)
    expect(empty.vm.topUser).toBeNull()
    expect(empty.vm.topFolder).toBeNull()
    expect(empty.vm.oldestEventTs).toBeNull()
    expect(empty.vm.byAction.every(r => r.count === 0)).toBe(true)
  })

  it('buckets a missing user under a single "unknown" row', async () => {
    api.auditLog.mockResolvedValue({
      events: [
        { ts: 2, user: '', role: 'user', action: 'upload', path: '/a.txt' },
        { ts: 1, role: 'user', action: 'upload', path: '/b.txt' },
      ],
    })
    const w = mountReports()
    await flushPromises()

    expect(w.vm.byUser).toEqual([{ key: 'unknown', count: 2 }])
  })

  it('is not corrupted by a username that collides with Object.prototype', async () => {
    // A plain {} accumulator silently no-ops on __proto__ and yields NaN for
    // constructor; the counters use a null-prototype map for exactly this.
    api.auditLog.mockResolvedValue({
      events: [
        { ts: 3, user: '__proto__', role: 'user', action: 'upload', path: '/a.txt' },
        { ts: 2, user: '__proto__', role: 'user', action: 'upload', path: '/b.txt' },
        { ts: 1, user: 'constructor', role: 'user', action: 'upload', path: '/c.txt' },
      ],
    })
    const w = mountReports()
    await flushPromises()

    const byKey = w.vm.byUser.reduce((acc, r) => Object.assign(acc, { [r.key]: r.count }), Object.create(null))
    expect(byKey['__proto__']).toBe(2)
    expect(byKey['constructor']).toBe(1)
    expect(({}).polluted).toBeUndefined()
  })

  it('keeps the rollups and the CSV describing the same set of events', async () => {
    // One assertion that kills a whole class of divergence: if any rollup
    // dedupes, or the CSV serialises a filtered array, these stop agreeing.
    const csvRows = wrapper.vm.buildCsv().split('\r\n').filter(l => l !== '')
    const sum = rows => rows.reduce((n, r) => n + r.count, 0)

    expect(csvRows.length - 1).toBe(wrapper.vm.totalEvents)
    expect(sum(wrapper.vm.byAction)).toBe(wrapper.vm.totalEvents)
    expect(sum(wrapper.vm.byUser)).toBe(wrapper.vm.totalEvents)
    expect(sum(wrapper.vm.byFolder)).toBe(wrapper.vm.totalEvents)
  })
})

// ── folderOf ──────────────────────────────────────────────────────────────────

describe('Reports.vue — folderOf', () => {
  let vm
  beforeEach(() => { vm = mountReports().vm })

  it('returns the immediate parent directory', () => {
    expect(vm.folderOf('/a/b/c.txt')).toBe('/a/b')
    expect(vm.folderOf('/a/b.txt')).toBe('/a')
  })

  it('maps a root-level file to "/"', () => {
    expect(vm.folderOf('/x.txt')).toBe('/')
  })

  it('maps a missing or non-string path to "/" instead of throwing', () => {
    expect(vm.folderOf('')).toBe('/')
    expect(vm.folderOf(undefined)).toBe('/')
    expect(vm.folderOf(null)).toBe('/')
  })
})

// ── CSV ───────────────────────────────────────────────────────────────────────

describe('Reports.vue — CSV escaping', () => {
  let vm
  beforeEach(() => { vm = mountReports().vm })

  it('emits the exact column header, CRLF-terminated, behind a single BOM', async () => {
    api.auditLog.mockResolvedValue({ events: [] })
    const w = mountReports()
    await flushPromises()

    const csv = w.vm.buildCsv()
    expect(csv.charCodeAt(0)).toBe(0xFEFF)
    expect(csv.charCodeAt(1)).not.toBe(0xFEFF)
    expect(csv.slice(1)).toBe('timestamp_unix,timestamp_iso,timestamp_local,user,role,action,path,folder,detail\r\n')
  })

  it('omits the source IP entirely', () => {
    // ip is present in the log but has never been rendered in the UI; the CSV
    // must not be the thing that first persists it outside private/.
    const csv = mountReports().vm.buildCsv()
    expect(csv).not.toContain('ip')
  })

  it('quotes fields containing a comma, quote, or newline', () => {
    expect(vm.csvField('a,b')).toBe('"a,b"')
    expect(vm.csvField('a"b')).toBe('"a""b"')
    // A POSIX filename may legally contain a newline; unquoted it would split
    // the CSV row and shift every later column.
    expect(vm.csvField('a\nb')).toBe('"a\nb"')
    expect(vm.csvField('a\rb')).toBe('"a\rb"')
  })

  it('leaves ordinary values untouched', () => {
    expect(vm.csvField('/docs/report.pdf')).toBe('/docs/report.pdf')
    expect(vm.csvField('alice')).toBe('alice')
  })

  it('renders null and undefined as empty, never the string "null"', () => {
    expect(vm.csvField(null)).toBe('')
    expect(vm.csvField(undefined)).toBe('')
  })

  it('neutralises every formula sigil', () => {
    expect(vm.sanitizeCsvValue('=cmd|\'/C calc\'!A0')).toBe('\'=cmd|\'/C calc\'!A0')
    expect(vm.sanitizeCsvValue('+1+1')).toBe('\'+1+1')
    expect(vm.sanitizeCsvValue('-1+1')).toBe('\'-1+1')
    expect(vm.sanitizeCsvValue('@SUM(A1)')).toBe('\'@SUM(A1)')
  })

  it('neutralises a sigil hidden behind leading whitespace', () => {
    // Excel strips leading whitespace BEFORE looking for the sigil, so a
    // first-byte-only check is bypassable. \n is the one the OWASP list omits
    // and the one a crafted filename can actually carry.
    expect(vm.sanitizeCsvValue('\t=1+1')).toBe('\'\t=1+1')
    expect(vm.sanitizeCsvValue('\r=1+1')).toBe('\'\r=1+1')
    expect(vm.sanitizeCsvValue('\n=1+1')).toBe('\'\n=1+1')
    expect(vm.sanitizeCsvValue(' =1+1')).toBe('\' =1+1')
    expect(vm.sanitizeCsvValue('\u00a0=1+1')).toBe('\'\u00a0=1+1')
    expect(vm.sanitizeCsvValue('\ufeff=1+1')).toBe('\'\ufeff=1+1')
  })

  it('does not prefix values that merely contain a sigil', () => {
    expect(vm.sanitizeCsvValue('/reports/q1=final.pdf')).toBe('/reports/q1=final.pdf')
    expect(vm.sanitizeCsvValue('a-b')).toBe('a-b')
    // Every real audit path is "/"-prefixed, so in practice paths never trip
    // the guard at all — it is there for user, and as insurance.
    expect(vm.sanitizeCsvValue('/=1+1.txt')).toBe('/=1+1.txt')
  })

  it('sanitises before quoting so the apostrophe lands inside the quotes', () => {
    // "'=a,b" — the guard must sit on the decoded value, since that is what a
    // spreadsheet evaluates. Emitting '"..." with the apostrophe outside would
    // be invalid CSV and leave the value unguarded.
    expect(vm.csvField('=a,b')).toBe('"\'=a,b"')
  })

  it('applies the guard to the user column, the one reachable vector', async () => {
    api.auditLog.mockResolvedValue({
      events: [{ ts: 7, user: '=cmd|\'/C calc\'!A0', role: 'user', action: 'upload', path: '/a.txt', detail: null }],
    })
    const w = mountReports()
    await flushPromises()

    const row = w.vm.buildCsv().split('\r\n')[1]
    expect(row).toContain('\'=cmd|')
  })
})

describe('Reports.vue — CSV rows', () => {
  it('emits one row per event plus the header, with all nine columns', async () => {
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    const w = mountReports()
    await flushPromises()

    const lines = w.vm.buildCsv().split('\r\n').filter(l => l !== '')
    expect(lines.length).toBe(SAMPLE_EVENTS.length + 1)
    lines.forEach(l => expect(l.split(',').length).toBeGreaterThanOrEqual(9))
  })

  it('carries three time representations for the same instant', async () => {
    api.auditLog.mockResolvedValue({
      events: [{ ts: 1770000000, user: 'a', role: 'user', action: 'upload', path: '/a.txt', detail: null }],
    })
    const w = mountReports()
    await flushPromises()

    const cells = w.vm.buildCsv().split('\r\n')[1].split(',')
    expect(cells[0]).toBe('1770000000')
    // UTC and unambiguous, so the file reads the same for whoever opens it.
    expect(cells[1]).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/)
    // Local, matching what the admin saw on screen (mocked formatDate here).
    expect(cells[2]).toBe('D1770000000')
  })
})

// ── Download ──────────────────────────────────────────────────────────────────

describe('Reports.vue — download', () => {
  let wrapper
  let BlobSpy

  beforeEach(async () => {
    api.auditLog.mockResolvedValue({ events: SAMPLE_EVENTS })
    jest.spyOn(Date, 'now').mockReturnValue(FIXED_MS)
    wrapper = mountReports()
    await flushPromises()
    BlobSpy = jest.fn()
    global.Blob = BlobSpy
  })

  it('hands saveBlob exactly what buildCsv produced', () => {
    const expected = wrapper.vm.buildCsv()
    wrapper.vm.downloadCsv()

    expect(BlobSpy).toHaveBeenCalledTimes(1)
    const [parts, opts] = BlobSpy.mock.calls[0]
    // Chunked to avoid materialising one multi-MB string twice, but the
    // concatenation must still be byte-identical to buildCsv() — otherwise a
    // download could silently diverge from everything asserted above.
    expect(parts.join('')).toBe(expected)
    expect(opts).toEqual({ type: 'text/csv;charset=utf-8;' })

    expect(wrapper.vm.saveBlob).toHaveBeenCalledTimes(1)
    expect(wrapper.vm.saveBlob.mock.calls[0][0]).toBe(BlobSpy.mock.instances[0])
  })

  it('names the file for the pinned window and marks it confidential', () => {
    wrapper.vm.downloadCsv()
    const filename = wrapper.vm.saveBlob.mock.calls[0][1]

    expect(filename).toMatch(/^filegator-activity-CONFIDENTIAL-\d{4}-\d{2}-\d{2}-to-\d{4}-\d{2}-\d{2}\.csv$/)
    // No user-supplied data in the name, so the download attribute can't be
    // poisoned by a crafted filename.
    expect(filename).not.toContain('/')
  })

  it('runs synchronously — the Safari user-gesture invariant', () => {
    // A download fired after an await loses the user gesture and is blocked on
    // Safari/iOS. saveBlob must already have been called by the time
    // downloadCsv returns, and the return value must not be thenable.
    const ret = wrapper.vm.downloadCsv()
    expect(wrapper.vm.saveBlob).toHaveBeenCalledTimes(1)
    expect(ret == null || typeof ret.then !== 'function').toBe(true)
  })

  it('does nothing when there is no activity', async () => {
    api.auditLog.mockResolvedValue({ events: [] })
    const empty = mountReports()
    await flushPromises()

    empty.vm.downloadCsv()
    expect(empty.vm.saveBlob).not.toHaveBeenCalled()
  })

  it('confirms before exporting, naming what leaves the app', () => {
    wrapper.vm.confirmDownload()

    expect(wrapper.vm.$dialog.confirm).toHaveBeenCalledTimes(1)
    const opts = wrapper.vm.$dialog.confirm.mock.calls[0][0]
    expect(opts.message).toContain('unencrypted')
    expect(opts.message).toContain('5')
    // The download fires from the confirm callback, which is itself dispatched
    // from a real click — so the gesture is still live.
    expect(wrapper.vm.saveBlob).not.toHaveBeenCalled()
    opts.onConfirm()
    expect(wrapper.vm.saveBlob).toHaveBeenCalledTimes(1)
  })

  it('warns instead of confirming when there is nothing to export', async () => {
    api.auditLog.mockResolvedValue({ events: [] })
    const empty = mountReports()
    await flushPromises()

    empty.vm.confirmDownload()
    expect(empty.vm.$dialog.confirm).not.toHaveBeenCalled()
    expect(empty.vm.$toast.open).toHaveBeenCalled()
  })
})
