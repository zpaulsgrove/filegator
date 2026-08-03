// The JS half of the shared CSV contract.
//
// tests/fixtures/csv-contract.json is consumed by BOTH this spec and
// tests/backend/Unit/ActivityCsvTest.php. Two producers of one format is a
// drift hazard, so a change to either implementation without the other fails
// the other language's suite.
//
// Expectations in the fixture derive from the ECMA-262 \s set, not from either
// implementation — neither side gets to define what "correct" is.
//
// Encoding: the fixture stores UTF-8 BYTES as hex. PHP sanitises byte strings;
// JS sanitises UTF-16 code-unit strings, so these must be decoded as UTF-8
// here. Decoding as latin1 splits multi-byte characters and the guard appears
// to miss. Vectors flagged `php_only` hold invalid UTF-8, which a JS string
// cannot represent at all; they exist to pin PHP against a fail-open that JS
// is structurally incapable of, and are skipped below.

import { shallowMount } from '@vue/test-utils'
import Reports from '@/views/Reports.vue'
import contract from '../../fixtures/csv-contract.json'

jest.mock('@/api/api', () => ({
  __esModule: true,
  default: {
    auditLog: jest.fn(),
    // mounted() also loads the server-generated monthly reports; without these
    // the mount itself throws before any contract assertion runs.
    monthlyReports: jest.fn(),
    downloadMonthlyReport: jest.fn(),
  },
}))

const APOS = String.fromCharCode(39)
const decode = hex => Buffer.from(hex, 'hex').toString('utf8')
const encode = str => Buffer.from(str, 'utf8').toString('hex')

function mountReports() {
  return shallowMount(Reports, {
    mocks: {
      $store: { state: { config: { pagination: ['', 5, 10, 15] } } },
      $dialog: { confirm: jest.fn() },
      $toast: { open: jest.fn() },
      lang: s => s,
      capitalize: s => s,
      formatDate: ts => 'D' + ts,
      handleError: jest.fn(),
      escapeHtml: s => s,
      saveBlob: jest.fn(),
    },
    stubs: {
      Menu: true, Pagination: true, 'b-table': true,
      'b-table-column': true, 'b-icon': true, 'b-field': true,
      'b-select': true, 'b-input': true, 'b-tag': true,
    },
  })
}

describe('CSV contract (shared with backend/Services/Audit/ActivityCsv)', () => {
  let vm

  beforeEach(() => {
    // mounted() calls load() and loadMonthlyReports(); without resolved
    // promises the mount throws.
    const api = require('@/api/api').default
    api.auditLog.mockResolvedValue({ events: [] })
    api.monthlyReports.mockResolvedValue({ reports: [] })
    vm = mountReports().vm
  })

  // Tripwires — these are what stop the fixture being quietly neutered.
  it('matches the contract version the implementations pin', () => {
    expect(contract.contract_version).toBe(1)
  })

  // Asserted against the header the component actually emits, not against an
  // exported constant — that way a change to the emitted format is caught even
  // if the constant is left alone.
  it('emits the fixture column list, BOM and CRLF', () => {
    const header = vm.buildCsvChunks()[0]
    expect(Buffer.from(header, 'utf8').slice(0, 3).toString('hex')).toBe(contract.bom_hex.toLowerCase())
    expect(header.endsWith(decode(contract.eol_hex))).toBe(true)
    expect(header.slice(1).replace(/\r\n$/, '').split(',')).toEqual(contract.columns)
  })

  it('carries at least the recorded number of vectors in every section', () => {
    expect(contract.sanitize.length).toBeGreaterThanOrEqual(contract.counts.sanitize)
    expect(contract.field.length).toBeGreaterThanOrEqual(contract.counts.field)
    expect(contract.folder_of.length).toBeGreaterThanOrEqual(contract.counts.folder_of)
    expect(contract.codepoints.length).toBeGreaterThanOrEqual(contract.counts.codepoints)
  })

  it('neutralises every formula sigil vector', () => {
    contract.sanitize.filter(v => ! v.php_only).forEach(v => {
      expect({ name: v.name, out: encode(vm.sanitizeCsvValue(decode(v.in_hex))) })
        .toEqual({ name: v.name, out: v.out_hex.toLowerCase() })
    })
  })

  it('quotes per RFC 4180, after sanitising', () => {
    contract.field.filter(v => ! v.php_only).forEach(v => {
      expect({ name: v.name, out: encode(vm.csvField(decode(v.in_hex))) })
        .toEqual({ name: v.name, out: v.out_hex.toLowerCase() })
    })
  })

  it('resolves the parent folder of every path vector', () => {
    contract.folder_of.forEach(v => {
      expect({ in: v.in, out: vm.folderOf(v.in) }).toEqual({ in: v.in, out: v.out })
    })
  })

  // This is the vector that pins the localeCompare -> code-unit change. With
  // localeCompare these keys order ['_','a','B','C']; PHP's strcmp gives
  // ['B','C','_','a']. Only a code-unit compare makes the two agree.
  it('orders rollup ties by code unit, not locale', () => {
    contract.sorted_rows.forEach(v => {
      expect(vm.toSortedRows(v.counts).map(r => r.key)).toEqual(v.out.map(r => r.key))
    })
  })

  // Exhaustive over the interesting alphabet, so "spot check" becomes "proof".
  it('guards exactly the ECMA-262 whitespace set, and nothing else', () => {
    const wrong = contract.codepoints.filter(v => {
      const input = String.fromCodePoint(v.cp) + '=1'
      // Compare against the whole expected string: inferring "guarded" from a
      // leading apostrophe is ambiguous when the INPUT starts with one.
      return (vm.sanitizeCsvValue(input) === APOS + input) !== v.guarded
    })
    expect(wrong.map(v => 'U+' + v.cp.toString(16).toUpperCase())).toEqual([])
  })
})
