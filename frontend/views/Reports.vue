<!--
  Admin-only 30-day file-activity report. Pulls the same events as the Audit
  Log page (AdminController::auditLog) but for a pinned 30-day window, rolls
  them up by action / user / folder, and exports the full list as CSV.

  SECURITY — this page handles attacker-controlled strings. `user`, `path`,
  `action` and `detail` come from usernames and filenames, and the folder
  rollup keys are SUBSTRINGS of `path`, so they inherit exactly the same
  taint. Three rules, all load-bearing:

   1. Render them ONLY via {{ }} interpolation / slot text, which Vue
      HTML-escapes. NEVER v-html.
   2. Buefy's $toast/$dialog render `message` with v-html (see the comment on
      escapeHtml in mixins/shared.js). Any message containing a username,
      path, detail or derived folder key MUST go through escapeHtml() first.
      This is the sink that actually bites — "don't write v-html" is not
      enough on its own.
   3. Never bind a derived value into :href / :src / :style, and never use a
      folder name as a b-table-column `field` (lodash get() treats "." and
      "[" specially).

  The raw event list is deliberately NEVER rendered — only the rollup is, so
  row count scales with distinct actions (<= 10) / users / folders rather than
  with event count. Distinct folders is still unbounded on a busy deployment,
  which is why the user and folder tables can be paginated.
-->
<template>
  <div class="container">
    <Menu />

    <section class="actions is-flex is-justify-between">
      <div>
        <h1 class="title is-5" style="margin-bottom: 0.25rem">
          {{ lang('File Activity Report') }}
        </h1>
        <p class="has-text-grey is-size-7" data-test="report-period">
          {{ periodLabel }}
        </p>
      </div>
      <div class="is-flex">
        <!-- The by-user and by-folder tables are bounded by distinct users and
             folders, not by event count — but "distinct folders touched" is
             still unbounded on a busy deployment, and the shipped
             config.pagination default is '' (i.e. paging OFF). Offer the same
             control the Audit Log page does so an admin can cap the rows. -->
        <Pagination :perpage="perPage" @selected="perPage = $event" />
        <a
          style="margin-right: 1rem; margin-left: 1rem"
          data-test="report-refresh"
          @click="load"
        >
          <b-icon icon="redo" size="is-small" /> {{ lang('Refresh') }}
        </a>
        <!-- Anchors ignore `disabled`, so the empty case is handled in
             confirmDownload() (which toasts instead of exporting) and only
             signalled visually here. -->
        <a
          :class="{ 'has-text-grey-light': !totalEvents }"
          data-test="report-download-csv"
          @click="confirmDownload"
        >
          <b-icon icon="download" size="is-small" /> {{ lang('Download CSV') }}
        </a>
      </div>
    </section>

    <section data-test="report-summary" class="summary">
      <div class="level">
        <div class="level-item has-text-centered">
          <div>
            <p class="heading">
              {{ lang('Total events') }}
            </p>
            <p class="title is-4" data-test="report-total">
              {{ totalEvents }}
            </p>
          </div>
        </div>
        <div class="level-item has-text-centered">
          <div>
            <p class="heading">
              {{ lang('Most active user') }}
            </p>
            <p class="title is-6" data-test="report-top-user">
              {{ topUser ? topUser.key + ' (' + topUser.count + ')' : '—' }}
            </p>
          </div>
        </div>
        <div class="level-item has-text-centered">
          <div>
            <p class="heading">
              {{ lang('Busiest folder') }}
            </p>
            <p class="title is-6" data-test="report-top-folder">
              {{ topFolder ? topFolder.key + ' (' + topFolder.count + ')' : '—' }}
            </p>
          </div>
        </div>
      </div>

      <p v-if="oldestEventTs" class="has-text-grey is-size-7" data-test="report-oldest">
        {{ lang('Oldest event: {0}', formatDate(oldestEventTs)) }}
        <span v-if="coverageLimited" data-test="report-coverage-limited">
          — {{ lang('the log holds less than the full period; older activity has been purged or predates the log.') }}
        </span>
      </p>
      <p v-if="!totalEvents && !isLoading" class="has-text-grey is-size-7" data-test="report-unconfigured-note">
        {{ lang('If the audit log service is not configured, no activity is recorded.') }}
      </p>
    </section>

    <div class="columns">
      <div class="column">
        <h2 class="subtitle is-6">
          {{ lang('Events by action') }}
        </h2>
        <b-table :data="byAction" :hoverable="true" :loading="isLoading" data-test="report-by-action">
          <template slot-scope="props">
            <b-table-column :label="lang('Action')" field="key">
              <b-tag size="is-small">
                {{ lang(capitalize(props.row.key)) }}
              </b-tag>
            </b-table-column>
            <b-table-column :label="lang('Events')" field="count" numeric>
              {{ props.row.count }}
            </b-table-column>
          </template>
        </b-table>
      </div>

      <div class="column">
        <h2 class="subtitle is-6">
          {{ lang('Events by user') }}
        </h2>
        <b-table
          :data="byUser"
          :paginated="perPage > 0"
          :per-page="perPage"
          :current-page.sync="userPage"
          :hoverable="true"
          :loading="isLoading"
          data-test="report-by-user"
        >
          <template slot-scope="props">
            <b-table-column :label="lang('User')" field="key">
              {{ props.row.key }}
            </b-table-column>
            <b-table-column :label="lang('Events')" field="count" numeric>
              {{ props.row.count }}
            </b-table-column>
          </template>
          <template slot="empty">
            <div class="content has-text-grey has-text-centered">
              {{ isLoading ? lang('Loading') : lang('No activity in the last 30 days') }}
            </div>
          </template>
        </b-table>
      </div>
    </div>

    <h2 class="subtitle is-6">
      {{ lang('Events by folder') }}
    </h2>
    <b-table
      :data="byFolder"
      :paginated="perPage > 0"
      :per-page="perPage"
      :current-page.sync="folderPage"
      :hoverable="true"
      :loading="isLoading"
      data-test="report-by-folder"
    >
      <template slot-scope="props">
        <b-table-column :label="lang('Folder')" field="key">
          <code>{{ props.row.key }}</code>
        </b-table-column>
        <b-table-column :label="lang('Events')" field="count" numeric>
          {{ props.row.count }}
        </b-table-column>
      </template>
      <template slot="empty">
        <div class="content has-text-grey has-text-centered">
          {{ isLoading ? lang('Loading') : lang('No activity in the last 30 days') }}
        </div>
      </template>
    </b-table>

    <p class="has-text-grey is-size-7 footnote">
      {{ lang('Folder counts use the destination path of each action.') }}
    </p>

    <!--
      Monthly reports generated by the cron job. Unlike the rollup above, these
      are built server-side, stored encrypted, and downloaded through an
      authenticated, step-up gated, logged POST — the CSV is never emailed.
      Only metadata is listed here; nothing in this table comes from inside a
      report.
    -->
    <h2 class="title is-6 monthly-heading">
      {{ lang('Monthly reports') }}
    </h2>
    <p class="has-text-grey is-size-7 footnote">
      {{ lang('Generated on the server and stored encrypted. Downloading one is recorded in the log.') }}
    </p>

    <b-table
      :data="monthlyReports"
      :loading="monthlyLoading"
      :mobile-cards="false"
      data-test="monthly-reports"
    >
      <template slot-scope="props">
        <b-table-column :label="lang('Period')" field="period">
          {{ props.row.period }}
        </b-table-column>
        <b-table-column :label="lang('Events')" field="events" numeric>
          {{ props.row.events }}
        </b-table-column>
        <b-table-column :label="lang('Coverage')" field="coverage">
          <b-tag :type="props.row.coverage === 'complete' ? 'is-light' : 'is-warning'">
            {{ props.row.coverage }}
          </b-tag>
        </b-table-column>
        <b-table-column :label="lang('Generated')" field="generated_at">
          {{ formatDate(props.row.generated_at) }}
        </b-table-column>
        <b-table-column>
          <button
            class="button is-small"
            :disabled="downloadingPeriod === props.row.period"
            :data-test="'download-monthly-' + props.row.period"
            @click="confirmMonthlyDownload(props.row)"
          >
            {{ downloadingPeriod === props.row.period ? lang('Loading') : lang('Download CSV') }}
          </button>
        </b-table-column>
      </template>
      <template slot="empty">
        <div class="content has-text-grey has-text-centered">
          {{ monthlyLoading ? lang('Loading') : lang('No monthly reports yet') }}
        </div>
      </template>
    </b-table>
  </div>
</template>

<script>
import moment from 'moment'
import Menu from './partials/Menu'
import Pagination from './partials/Pagination'
import api from '../api/api'
import withStepUp from '../utils/withStepUp'

// The report window. Kept as a constant so the label, the request and the
// tests all agree on one number.
const WINDOW_DAYS = 30

// Above this many events the CSV build is heavy enough to be worth warning
// about before we spend a second of main-thread time on it.
const LARGE_EXPORT_THRESHOLD = 50000

// Rows per Blob part. Passing the Blob an array of chunks avoids
// materialising one multi-megabyte string twice.
const CSV_CHUNK_ROWS = 1000

const CSV_COLUMNS = [
  'timestamp_unix',
  'timestamp_iso',
  'timestamp_local',
  'user',
  'role',
  'action',
  'path',
  'folder',
  'detail',
]

// Spreadsheet formula injection: a cell whose first meaningful character is
// = + - or @ is evaluated as a formula by Excel / LibreOffice / Sheets, so a
// crafted value becomes code execution on the machine that opens the export.
// Leading whitespace (and a BOM or NUL) is stripped by the spreadsheet BEFORE
// it looks for the sigil, so the guard has to skip it too — this is a
// superset of the OWASP [=+\-@\t\r] list and additionally closes \n, which
// matters because a POSIX filename may legally contain a newline.
//
// Deliberately NOT included: "|" is only dangerous following "=" (the DDE
// form "=cmd|...", already caught by the "=" branch) and "%" introduces no
// formula in any engine, so adding either would only mangle valid values.
//
// Reachability today: every audit path is "/"-prefixed by
// RecordsAuditEvents::auditNormalize, so `path` and the derived `folder`
// cannot start with a sigil, and `detail`/`action`/`role`/the timestamps
// cannot either. The one genuinely reachable column is `user`, since
// storeUser validates the username as 'required' only. The guard is applied
// to every column regardless — it is nearly free, and it is what keeps this
// safe if path normalisation or the storage separator ever changes.
// JS \s already spans space, \t \n \v \f \r, NBSP, the U+2000-200A run,
// the line/paragraph separators, U+202F, U+205F, U+3000 and the BOM —
// everything a spreadsheet strips before looking for the sigil. NUL is the
// one gap, so it is added explicitly — the control character in this class is
// deliberate, hence the rule exemption.
// eslint-disable-next-line no-control-regex
const CSV_SIGIL = /^[\s\u0000]*[=+\-@]/

export default {
  name: 'Reports',
  components: { Menu, Pagination },
  data() {
    return {
      perPage: this.$store.state.config.pagination[0],
      userPage: 1,
      folderPage: 1,
      isLoading: false,
      events: [],
      // Monotonic request counter; see load().
      requestGeneration: 0,
      // Pinned at request time, not derived from Date.now() in a computed, so
      // the header, the rollup and the CSV filename all describe one snapshot
      // instead of drifting apart as the clock moves.
      windowFrom: null,
      windowTo: null,
      // Mirror of AuditLog::ACTIONS (backend is the source of truth). Used to
      // seed the by-action rollup so an action with no activity shows an
      // explicit 0 rather than vanishing from the report.
      actions: ['upload', 'create', 'copy', 'move', 'rename', 'delete', 'zip', 'unzip', 'chmod', 'save'],
      // Server-generated monthly reports. Metadata only — the CSV bodies stay
      // encrypted on the server until an explicit, step-up gated download.
      monthlyReports: [],
      monthlyLoading: false,
      downloadingPeriod: null,
    }
  },
  computed: {
    totalEvents() {
      return this.events.length
    },
    byAction() {
      const counts = Object.create(null)
      this.actions.forEach(a => { counts[a] = 0 })
      this.events.forEach(e => {
        const key = e.action || 'unknown'
        counts[key] = (counts[key] || 0) + 1
      })
      return this.toSortedRows(counts)
    },
    byUser() {
      const counts = Object.create(null)
      this.events.forEach(e => {
        const key = e.user ? String(e.user) : this.lang('unknown')
        counts[key] = (counts[key] || 0) + 1
      })
      return this.toSortedRows(counts)
    },
    byFolder() {
      const counts = Object.create(null)
      this.events.forEach(e => {
        const key = this.folderOf(e.path)
        counts[key] = (counts[key] || 0) + 1
      })
      return this.toSortedRows(counts)
    },
    topUser() {
      return this.byUser.length ? this.byUser[0] : null
    },
    topFolder() {
      return this.byFolder.length ? this.byFolder[0] : null
    },
    // The backend returns events newest-first (AuditLog::query), so the last
    // element is the oldest. Surfaced because the window we ASK for and the
    // window the log can actually answer for are not always the same — on a
    // fresh install, or when the retention purge has run, the honest span is
    // shorter than 30 days and the reader deserves to know.
    oldestEventTs() {
      return this.events.length ? this.events[this.events.length - 1].ts : null
    },
    periodLabel() {
      if (! this.windowFrom || ! this.windowTo) return ''
      return this.lang('Report period: {0} to {1}', this.formatDate(this.windowFrom), this.formatDate(this.windowTo))
    },
    // The 30 days we ASK for is not necessarily the 30 days the log can
    // answer for: AuditLog::query applies its own max_age_days cutoff, so a
    // deployment retaining 7 days silently returns 7. The window label and the
    // CSV filename both describe the REQUESTED period, so say plainly when the
    // data behind them is narrower rather than letting the header imply
    // coverage that does not exist.
    //
    // A day of slack avoids crying wolf on a window whose oldest event simply
    // happens to be recent.
    coverageLimited() {
      if (! this.events.length || ! this.windowFrom) return false
      return this.oldestEventTs > (this.windowFrom + 86400)
    },
  },
  mounted() {
    this.load()
    this.loadMonthlyReports()
  },
  beforeDestroy() {
    // Decrypted paths and usernames should not outlive the view on an
    // unattended workstation.
    this.events = []
    this.monthlyReports = []
  },
  methods: {
    loadMonthlyReports() {
      this.monthlyLoading = true

      return api.monthlyReports()
        .then(ret => {
          this.monthlyReports = (ret && ret.reports) || []
        })
        .catch(error => this.handleError(error))
        .finally(() => { this.monthlyLoading = false })
    },
    // Same posture as the ad-hoc export: downloading turns an encrypted,
    // server-side artifact into a plaintext file on someone's disk, so make it
    // a deliberate act. Step-up is enforced by the backend regardless; this
    // dialog is how the fields are collected.
    confirmMonthlyDownload(row) {
      const period = row.period

      return withStepUp(this, {
        actionDescription: this.lang('download the {0} activity report', period),
        dangerWarning: this.lang('This report contains usernames and full file paths.'),
        action: stepUpFields => this.downloadMonthlyReport(period, stepUpFields),
      }).catch(error => {
        // A cancelled dialog is a normal outcome, not an error to surface.
        if (error && error.stepUpCancelled) return
        this.handleError(error)
      })
    },
    downloadMonthlyReport(period, stepUpFields) {
      this.downloadingPeriod = period

      return api.downloadMonthlyReport(Object.assign({ period }, stepUpFields || {}))
        .then(blob => {
          // The server already names the file (CONFIDENTIAL, and -PARTIAL when
          // the month could not be fully covered). Rebuild the same shape here
          // rather than trusting a header, so a response cannot influence what
          // lands in the download attribute.
          this.saveBlob(blob, 'filegator-activity-CONFIDENTIAL-' + period + '.csv')
        })
        .finally(() => { this.downloadingPeriod = null })
    },
    load() {
      const to = Math.floor(Date.now() / 1000)
      const from = to - (WINDOW_DAYS * 86400)
      // Tag each request so a slow earlier one cannot overwrite a later one's
      // results, and so only the newest request is allowed to clear the
      // loading flag. Two clicks of Refresh otherwise race.
      const generation = ++this.requestGeneration
      this.isLoading = true
      api.auditLog({ from, to })
        .then(ret => {
          if (generation !== this.requestGeneration) return
          // Commit the window only alongside the data it describes. Pinning it
          // up front would leave a failed refresh showing stale events under a
          // header — and exporting a CSV named for — a window that was never
          // successfully fetched.
          this.windowFrom = from
          this.windowTo = to
          this.events = (ret && ret.events) || []
        })
        .catch(error => {
          if (generation !== this.requestGeneration) return
          this.handleError(error)
        })
        .finally(() => {
          if (generation !== this.requestGeneration) return
          this.isLoading = false
        })
    },
    // Count map -> [{key, count}], busiest first, ties broken alphabetically
    // so the report is stable between pulls.
    // Count DESC, then key ASC by CODE UNIT — deliberately not localeCompare.
    // The server-side producer (backend/Services/Audit/ActivityCsv) has to sort
    // identically, and no PHP comparison reproduces localeCompare: it orders
    // ['a','B','_','C'] as ['_','a','B','C'] where strcmp gives ['B','C','_','a'].
    // A code-unit compare is the one ordering both languages agree on, and it
    // drops an ICU dependency from the report's row order. Pinned by the shared
    // vectors in tests/fixtures/csv-contract.json.
    toSortedRows(counts) {
      return Object.keys(counts)
        .map(key => ({ key, count: counts[key] }))
        .sort((a, b) => (b.count - a.count) || (a.key < b.key ? -1 : a.key > b.key ? 1 : 0))
    },
    // Immediate parent directory of a root-relative audit path. Grouping at
    // the parent (rather than the top-level segment) keeps the rollup lossless.
    //
    // Splits on "/" — which is the `separator` in every shipped configuration
    // and every storage-adapter example in the docs, but is a config value
    // (configuration_sample.php) rather than an invariant. If a deployment
    // ever sets a different separator, this is the line to revisit.
    folderOf(path) {
      const p = typeof path === 'string' ? path : ''
      if (! p) return '/'
      const i = p.lastIndexOf('/')
      return i <= 0 ? '/' : p.slice(0, i)
    },
    // Neutralise a leading formula sigil by prefixing an apostrophe. Applied
    // BEFORE quoting, because the spreadsheet evaluates the DECODED cell
    // value — "'=1+1" inside quotes still decodes to '=1+1, which no engine
    // treats as a formula. Prefixing also never introduces a CSV
    // metacharacter, so it cannot invalidate the quoting decision made after.
    sanitizeCsvValue(value) {
      const s = value == null ? '' : String(value)
      return CSV_SIGIL.test(s) ? '\'' + s : s
    },
    // RFC 4180. Newlines matter as much as commas here: a POSIX filename can
    // contain \n, and while the backend's json_encode stops a crafted name
    // forging a LOG line, nothing stops it splitting a CSV ROW.
    csvField(value) {
      const s = this.sanitizeCsvValue(value)
      return /["\n\r,]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s
    },
    csvRow(e) {
      const path = e.path || ''
      return [
        e.ts,
        moment.unix(e.ts).utc().format('YYYY-MM-DDTHH:mm:ss[Z]'),
        this.formatDate(e.ts),
        e.user,
        e.role,
        e.action,
        path,
        this.folderOf(path),
        e.detail,
      ].map(v => this.csvField(v)).join(',')
    },
    // Returns the CSV as an array of Blob parts. The UTF-8 BOM leads, or Excel
    // on Windows mojibakes any non-ASCII filename in the export.
    buildCsvChunks() {
      const chunks = ['﻿' + CSV_COLUMNS.join(',') + '\r\n']
      let rows = []
      this.events.forEach((e, i) => {
        rows.push(this.csvRow(e))
        if (rows.length === CSV_CHUNK_ROWS || i === this.events.length - 1) {
          chunks.push(rows.join('\r\n') + '\r\n')
          rows = []
        }
      })
      return chunks
    },
    buildCsv() {
      return this.buildCsvChunks().join('')
    },
    csvFilename() {
      const stamp = ts => moment.unix(ts).format('YYYY-MM-DD')
      // CONFIDENTIAL is in the name on purpose: the file leaves the app's
      // control the moment it lands in ~/Downloads, and the marker travels
      // with it if it gets forwarded. Contains no user data, so the
      // `download` attribute can't be poisoned.
      return 'filegator-activity-CONFIDENTIAL-' + stamp(this.windowFrom) + '-to-' + stamp(this.windowTo) + '.csv'
    },
    // The export turns an encrypted, 0600, retention-bounded store into a
    // plaintext file that never expires, so make it a deliberate act rather
    // than a stray click.
    confirmDownload() {
      if (! this.totalEvents) {
        this.$toast.open({
          message: this.lang('No activity in the last 30 days'),
          type: 'is-warning',
        })
        return
      }

      let message = this.lang('This exports {0} events, including usernames and full file paths, to an unencrypted file.', this.totalEvents)
      if (this.totalEvents > LARGE_EXPORT_THRESHOLD) {
        message += ' ' + this.lang('This export is large and may take a moment.')
      }

      this.$dialog.confirm({
        title: this.lang('Download CSV'),
        message: message,
        confirmText: this.lang('Download CSV'),
        // downloadCsv runs synchronously inside this callback, which is itself
        // dispatched from the confirm button's click — so the user gesture is
        // still live and Safari/iOS will not block the download.
        onConfirm: () => this.downloadCsv(),
      })
    },
    // MUST stay synchronous. A download fired after an await loses the user
    // gesture and is blocked on Safari/iOS (see the note in Browser.vue). This
    // is precisely why load() pulls the events up front and this method only
    // serialises what is already in memory — never re-fetch here.
    downloadCsv() {
      if (! this.events.length) return
      const blob = new Blob(this.buildCsvChunks(), { type: 'text/csv;charset=utf-8;' })
      this.saveBlob(blob, this.csvFilename())
    },
  },
}
</script>

<style scoped>
.actions {
  margin: 50px 0 20px 0;
}
.summary {
  margin-bottom: 2rem;
}
.footnote {
  margin-top: 0.5rem;
}
</style>
