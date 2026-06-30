<template>
  <div id="dropzone" class="container"
       @dragover="dropZone = can('upload') && ! isLoading ? true : false"
       @dragleave="dropZone = false"
       @drop="dropZone = false"
  >
    <div v-if="isLoading" id="loading" />

    <Upload v-if="can('upload')" v-show="dropZone == false" :files="files" :drop-zone="dropZone" />

    <b-upload v-if="dropZone && ! isLoading" multiple drag-drop>
      <b class="drop-info">{{ lang('Drop files to upload') }}</b>
    </b-upload>

    <div v-if="!dropZone" class="container">
      <Menu />

      <b-notification
        v-if="showMfaBanner"
        type="is-info"
        has-icon
        :closable="true"
        class="mfa-banner"
        @close="dismissMfaBanner"
      >
        <strong>{{ lang('Protect your account with Multi-Factor Authentication') }}</strong>
        <p>
          {{ lang('If you have not already set up Multi-Factor Authentication, we highly suggest doing so now to protect your private information. To set up MFA, install an authenticator app on your phone (for example, Microsoft Authenticator), then select your name at the top right of this screen and follow the instructions.') }}
        </p>
        <p style="margin-top: 0.5em">
          <router-link to="/security" class="has-text-weight-semibold">
            {{ lang('Set up MFA now') }}
          </router-link>
        </p>
        <!-- TODO: add a "Here is a video of Jacob showing how to set up MFA" link once recorded. -->
      </b-notification>

      <div id="browser">
        <div v-if="can('read')" class="is-flex is-justify-between">
          <div class="breadcrumb" aria-label="breadcrumbs">
            <ul>
              <li v-for="(item, index) in breadcrumbs" :key="index">
                <a @click="goTo(item.path)">{{ item.name }}</a>
              </li>
            </ul>
          </div>
          <div>
            <a id="search" class="search-btn" @click="search">
              <b-icon icon="search" size="is-small" />
            </a>
            <a id="sitemap" class="is-paddingless" @click="selectDir">
              <b-icon icon="sitemap" size="is-small" />
            </a>
          </div>
        </div>

        <section id="multi-actions" class="is-flex is-justify-between">
          <div>
            <template v-if="can('upload') && ! checked.length">
              <b-field v-for="opt in uploadOptions" :key="opt.label" class="file is-inline-block">
                <b-upload multiple native v-bind="opt.attrs" @input="files = $event">
                  <a class="is-inline-block">
                    <b-icon :icon="opt.icon" size="is-small" /> {{ lang(opt.label) }}
                  </a>
                </b-upload>
              </b-field>
            </template>
            <a v-if="can(['read', 'write']) && ! checked.length" class="add-new is-inline-block" data-test="new-menu" @click="createFolder">
              <b-icon icon="plus" size="is-small" /> {{ lang('New Folder') }}
            </a>
            <a v-if="can('batchdownload') && checked.length" class="is-inline-block" data-test="batch-download" @click="batchDownload">
              <b-icon icon="download" size="is-small" /> {{ lang('Download') }}
            </a>
            <a v-if="can('write') && checked.length" class="is-inline-block" data-test="copy-selected" @click="copy">
              <b-icon icon="copy" size="is-small" /> {{ lang('Copy') }}
            </a>
            <a v-if="can('write') && checked.length" class="is-inline-block" data-test="move-selected" @click="move">
              <b-icon icon="external-link-square-alt" size="is-small" /> {{ lang('Move') }}
            </a>
            <a v-if="can(['write', 'zip']) && checked.length" class="is-inline-block" data-test="zip-selected" @click="zip">
              <b-icon icon="file-archive" size="is-small" /> {{ lang('Zip') }}
            </a>
            <a v-if="can('write') && checked.length" class="is-inline-block" data-test="delete-selected" @click="remove">
              <b-icon icon="trash-alt" size="is-small" /> {{ lang('Delete') }}
            </a>
          </div>
          <div id="pagination" v-if="can('read')">
            <Pagination :perpage="perPage" @selected="perPage = $event" />
          </div>
        </section>

        <b-table v-if="can('read')"
                 :data="content"
                 :default-sort="defaultSort"
                 :paginated="perPage > 0"
                 :per-page="perPage"
                 :current-page.sync="currentPage"
                 :hoverable="true"
                 :is-row-checkable="(row) => row.type != 'back'"
                 :row-class="(row) => 'file-row type-'+row.type"
                 :checked-rows.sync="checked"
                 :loading="isLoading"
                 :checkable="can('batchdownload') || can('write') || can('zip')"
                 @contextmenu="rightClick"
        >
          <template slot-scope="props">
            <b-table-column :label="lang('Name')" :custom-sort="sortByName" field="data.name" sortable>
              <a class="is-block name" @click="itemClick(props.row)">
                {{ props.row.name }}
              </a>
            </b-table-column>

            <b-table-column v-if="can(['write', 'chmod'])" :label="lang('Permissions')" field="data.permissions" sortable width="130">
            <span @click="togglePermissionsView" :title="showSymbolic ? lang('Hide symbolic format') : lang('Show symbolic format')" style="font-family: monospace;cursor: pointer;">
              {{ formatPermissions(props.row.permissions, props.row.type) }}
            </span>
            </b-table-column>

            <b-table-column :label="lang('Size')" :custom-sort="sortBySize" field="data.size" sortable numeric width="150">
              {{ props.row.type == 'back' || props.row.type == 'dir' ? lang('Folder') : formatBytes(props.row.size) }}
            </b-table-column>

            <b-table-column :label="lang('Time')" :custom-sort="sortByTime" field="data.time" sortable numeric width="200">
              {{ props.row.time ? formatDate(props.row.time) : '' }}
            </b-table-column>

            <b-table-column id="single-actions" width="51">
              <b-dropdown v-if="props.row.type != 'back'" :disabled="checked.length > 0" aria-role="list" position="is-bottom-left">
                <button :ref="'ref-single-action-button-'+props.row.path" slot="trigger" class="button is-small" data-test="row-menu">
                  <b-icon icon="ellipsis-h" size="is-small" />
                </button>

                <b-dropdown-item v-if="props.row.type == 'file' && can('download')" aria-role="listitem" @click="download(props.row)">
                  <b-icon icon="download" size="is-small" /> {{ lang('Download') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="props.row.type == 'file' && can(['download']) && hasPreview(props.row.path)" aria-role="listitem" data-test="row-view" @click="preview(props.row)">
                  <b-icon icon="file-alt" size="is-small" /> {{ lang('View') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="copy($event, props.row)">
                  <b-icon icon="copy" size="is-small" /> {{ lang('Copy') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" @click="move($event, props.row)">
                  <b-icon icon="external-link-square-alt" size="is-small" /> {{ lang('Move') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" data-test="row-rename" @click="rename($event, props.row)">
                  <b-icon icon="file-signature" size="is-small" /> {{ lang('Rename') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can(['write', 'zip']) && isArchive(props.row)" aria-role="listitem" data-test="row-unzip" @click="unzip($event, props.row)">
                  <b-icon icon="file-archive" size="is-small" /> {{ lang('Unzip') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can(['write', 'zip']) && ! isArchive(props.row)" aria-role="listitem" @click="zip($event, props.row)">
                  <b-icon icon="file-archive" size="is-small" /> {{ lang('Zip') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="can(['write', 'chmod']) && props.row.permissions !== -1" aria-role="listitem" data-test="row-permissions" @click="chmod($event, props.row)">
                  <b-icon icon="lock" size="is-small" /> {{ lang('Permissions') }} ({{ props.row.permissions }})
                </b-dropdown-item>
                <b-dropdown-item v-if="can('write')" aria-role="listitem" data-test="row-delete" @click="remove($event, props.row)">
                  <b-icon icon="trash-alt" size="is-small" /> {{ lang('Delete') }}
                </b-dropdown-item>
                <b-dropdown-item v-if="props.row.type == 'file' && can('download')" v-clipboard:copy="getDownloadLink(props.row.path)" aria-role="listitem">
                  <b-icon icon="clipboard" size="is-small" /> {{ lang('Copy link') }}
                </b-dropdown-item>
              </b-dropdown>
            </b-table-column>
          </template>
        </b-table>

        <section id="bottom-info" class="is-flex is-justify-between">
          <div>
            <span>{{ lang('Selected', checked.length, totalCount) }}</span>
          </div>
          <div v-if="(showAllEntries || hasFilteredEntries) ">
            <input type="checkbox" id="checkbox" @click="toggleHidden">
            <label for="checkbox"> {{ lang('Show hidden') }}</label>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script>
import Vue from 'vue'
import Menu from './partials/Menu'
import Tree from './partials/Tree'
import Permissions from './partials/Permissions'
import Editor from './partials/Editor'
import Gallery from './partials/Gallery'
import Search from './partials/Search'
import Pagination from './partials/Pagination'
import Upload from './partials/Upload'
import api from '../api/api'
import VueClipboard from 'vue-clipboard2'
import { needsFolderPicker } from '../mixins/postLogin'
import { isMfaNudgeDismissed, markMfaNudgeDismissed } from '../utils/mfaBanner'
import _ from 'lodash'

Vue.use(VueClipboard)

export default {
  name: 'Browser',
  components: { Menu, Pagination, Upload },
  data() {
    return {
      dropZone: false,
      perPage: this.$store.state.config.pagination[0],
      currentPage: 1,
      checked: [],
      isLoading: false,
      defaultSort: ['data.name', 'asc'],
      files: [],
      hasFilteredEntries: false,
      showAllEntries: false,
      showSymbolic: false,
      // Holds a `?cd=` value whose load is deferred until active_homedir
      // catches up — set on mount for a cross-folder deep link that
      // routeAfterLogin is still resolving (async selectFolder). null when
      // nothing is pending, so the active_homedir watcher below stays inert
      // for ordinary in-session folder switches.
      deferredCd: null,
      // MFA nudge banner: dismissed per login session, not permanently. The
      // dismissal is cleared on each login (see resetMfaNudgeDismissals in
      // Login.vue), so an unenrolled user is nudged again every login until
      // they actually set up MFA.
      mfaBannerDismissed: isMfaNudgeDismissed(this.$store.state.user && this.$store.state.user.username),
    }
  },
  computed: {
    // The two upload controls differ only by the directory attributes and the
    // icon/label, so drive both from one config instead of duplicated markup.
    uploadOptions() {
      return [
        { label: 'Add files', icon: 'upload', attrs: {} },
        { label: 'Upload folder', icon: 'folder-open', attrs: { webkitdirectory: true, directory: true } },
      ]
    },
    showMfaBanner() {
      const user = this.$store.state.user
      // Only logged-in portal users without MFA. /getuser carries mfa_enabled
      // ONLY for MFA-capable auth adapters; it is omitted otherwise. Use a
      // strict `=== false` so we nudge a capable-but-unenrolled user, but stay
      // silent when the field is absent (adapter can't do MFA, or its state
      // couldn't be read) — otherwise we'd advertise a setup flow that doesn't
      // exist. Admins are force-enrolled, so this targets unenrolled clients.
      return !!user
        && user.role !== 'guest'
        && user.mfa_enabled === false
        && !this.mfaBannerDismissed
    },
    breadcrumbs() {
      let path = ''
      let breadcrumbs = [{name: this.lang('Home'), path: '/'}]

      _.forEach(_.split(this.$store.state.cwd.location, '/'), (dir) => {
        path += dir + '/'
        breadcrumbs.push({
          name: dir,
          path: path,
        })
      })

      return _.filter(breadcrumbs, o => o.name)
    },
    content() {
      return this.$store.state.cwd.content
    },
    totalCount() {
      return _.filter(this.$store.state.cwd.content, (o) => {
        return o.type == 'file' || o.type == 'dir'
      }).length
    },
  },
  watch: {
    '$route' (to) {
      this.loadDir(to.query.cd)
    },
    // Resolve a deferred cross-folder deep link: once the homedir the link
    // names becomes active (routeAfterLogin's async selectFolder resolved),
    // load the stashed cd. Guarded by deferredCd so ordinary in-session
    // folder switches (Menu.switchFolder) never trigger a duplicate load.
    '$store.state.user.active_homedir' (active) {
      if (this.deferredCd !== null && active && active === this.$route.query.folder) {
        const cd = this.deferredCd
        this.deferredCd = null
        cd ? this.loadDir(cd) : this.loadFiles()
      }
    },
  },
  mounted() {
    // Restore a `?cd=` deep link on a cold load (reload / bookmark). This
    // works because App.vue gates the whole tree on `initialized`, so this
    // component only mounts AFTER the bootstrap getUser resolves — meaning
    // can('read') is accurate and $route.query.cd is available here. If that
    // gate is ever removed, this restore would silently no-op (mount would
    // run before the user loads) and reloads would bounce to the root again.
    if (!this.can('read')) {
      return
    }
    const user = this.$store.state.user
    const active = user.active_homedir
    const folder = this.$route.query.folder
    const homedirs = Array.isArray(user.homedirs) ? user.homedirs : []
    if (folder && folder !== active && homedirs.indexOf(folder) !== -1) {
      // Cross-folder deep link: the named (valid) homedir isn't active yet.
      // routeAfterLogin is switching to it via selectFolder; defer the load
      // (its corrective navigation is a no-op duplicate of this very route,
      // so the active_homedir watcher above is what completes the load).
      this.deferredCd = this.$route.query.cd || ''
      return
    }
    if (needsFolderPicker(user)) {
      // Multi-folder, no active selection: the bootstrap/guard is about to
      // move us to the picker. Don't load against a missing active folder.
      return
    }
    if (this.$route.query.cd) {
      this.loadDir(this.$route.query.cd)
    } else {
      this.loadFiles()
    }
  },
  methods: {
    toggleHidden() {
      this.showAllEntries = !this.showAllEntries
      this.loadFiles()
      this.checked = []
    },
    togglePermissionsView() {
    this.showSymbolic = !this.showSymbolic
  },
  formatPermissions(permissions, type) {
    if (permissions === -1) return
    const numeric = permissions.toString()
    if (this.showSymbolic) {
      const symbolic = this.convertToSymbolic(permissions, type)
      return `[${symbolic}]`
    }
    return numeric
  },
    convertToSymbolic(permissions, type) {
      if (permissions === -1) return ''
        const symbols = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx']
        const owner = symbols[Math.floor(permissions / 100) % 10]
        const group = symbols[Math.floor(permissions / 10) % 10]
        const others = symbols[permissions % 10]
        const prefix = type === 'dir' ? 'd' : '-'
      return `${prefix}${owner}${group}${others}`
    },
    filterEntries(files){
      var filter_entries = this.$store.state.config.filter_entries
      this.hasFilteredEntries = false
      if (!this.showAllEntries && typeof filter_entries !== 'undefined' && filter_entries.length > 0){
        let filteredFiles = []
        _.forEach(files, (file) => {
          let filterContinue = false
          _.forEach(filter_entries, (ffilter_Entry) => {
            if (typeof ffilter_Entry !== 'undefined' && ffilter_Entry.length > 0){
              let filter_Entry = ffilter_Entry
              let filterEntry_type = filter_Entry.endsWith('/')? 'dir':'file'
              filter_Entry = filter_Entry.replace(/\/$/, '')
              let filterEntry_isFullPath = filter_Entry.startsWith('/')
              let filterEntry_tmpName  = filterEntry_isFullPath? '/'+file.path : file.name
              filter_Entry             = filterEntry_isFullPath? '/'+filter_Entry : filter_Entry
              filter_Entry = filter_Entry.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.$&')
              let thisRegex = new RegExp('^'+filter_Entry+'$', 'iu')
              if(file.type == filterEntry_type && thisRegex.test(filterEntry_tmpName))
              {
                filterContinue = true
                this.hasFilteredEntries = true
                return false
              }
            }
          })
          if(!filterContinue){
            filteredFiles.push(file)
          }
        })
        return filteredFiles
      }
      return files
    },
    loadFiles() {
      api.getDir({
        to: '',
      })
        .then(ret => {
          this.$store.commit('setCwd', {
            content: this.filterEntries(ret.files),
            location: ret.location,
          })
        })
        .catch(error => this.handleError(error))
    },
    // Load a specific directory by its homedir-relative path (the `?cd=`
    // deep-link value). Uses changeDir, which also WRITES the session CWD
    // so later createNew/upload land in this folder — unlike loadFiles(),
    // which only READS the session CWD. The two are NOT interchangeable.
    loadDir(cd) {
      this.isLoading = true
      this.checked = []
      this.currentPage = 1
      api.changeDir({
        to: cd,
      })
        .then(ret => {
          this.$store.commit('setCwd', {
            content: this.filterEntries(ret.files),
            location: ret.location,
          })
          this.isLoading = false
        })
        .catch(error => {
          this.isLoading = false
          this.handleError(error)
        })
    },
    goTo(path) {
      // Self-describing deep link: multi-folder users also encode which
      // homedir `cd` belongs to, so a cross-session bookmark can bypass the
      // picker (see postLogin.routeAfterLogin). Single-folder users keep
      // clean `/#/?cd=` URLs.
      const query = { cd: path }
      const user = this.$store.state.user
      if (user && Array.isArray(user.homedirs) && user.homedirs.length > 1 && user.active_homedir) {
        query.folder = user.active_homedir
      }
      this.$router.push({ name: 'browser', query }).catch(() => {})
    },
    getSelected() {
      return _.reduce(this.checked, function(result, value) {
        result.push(value)
        return result
      }, [])
    },
    itemClick(item) {
      if (item.type == 'dir' || item.type == 'back') {
        this.goTo(item.path)
      } else if (this.can(['download']) && this.hasPreview(item.path)) {
        this.preview(item)
      } else if (this.can(['download'])) {
        this.download(item)
      }
    },
    rightClick(row, event) {
      if (row.type == 'back') {
        return
      }
      event.preventDefault()
      this.$refs['ref-single-action-button-'+row.path].click()
    },
    selectDir() {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Tree,
        events: {
          selected: dir => {
            this.goTo(dir.path)
          }
        },
      })
    },
    copy(event, item) {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Tree,
        events: {
          selected: dir => {
            this.isLoading = true
            api.copyItems({
              destination: dir.path,
              items: item ? [item] : this.getSelected(),
            })
              .then(() => {
                this.isLoading = false
                this.loadFiles()
              })
              .catch(error => {
                this.isLoading = false
                this.handleError(error)
              })
            this.checked = []
          }
        },
      })
    },
    move(event, item) {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Tree,
        events: {
          selected: dir => {
            this.isLoading = true
            api.moveItems({
              destination: dir.path,
              items: item ? [item] : this.getSelected(),
            })
              .then(() => {
                this.isLoading = false
                this.loadFiles()
              })
              .catch(error => {
                this.isLoading = false
                this.handleError(error)
              })
            this.checked = []
          }
        },
      })
    },
    batchDownload() {
      let items = this.getSelected()

      // Small, all-file selections download individually instead of being wrapped in a
      // zip. Folders can't be streamed raw, and selections beyond the threshold are zipped,
      // so both of those fall through to the archiver below. The threshold is a FILE COUNT
      // from config (default 5); a single file always streams directly so inline types
      // (e.g. a PDF) keep previewing instead of forcing a save.
      const raw = this.$store.state.config.zip_threshold
      const threshold = (Number.isInteger(raw) && raw >= 1) ? raw : 5
      if (raw !== undefined && raw !== threshold) {
        console.warn('filegator: ignoring invalid zip_threshold config value', raw, '— using', threshold)
      }
      const allFiles = items.length > 0 && items.every(i => i.type === 'file')

      if (this.can('download') && allFiles && items.length <= threshold) {
        if (items.length === 1) {
          this.download(items[0])
          this.checked = []
          return
        }
        if (this.supportsMultiDownload()) {
          this.downloadEach(items)
          this.checked = []
          return
        }
        // Safari / iOS: fall through to the server zip — they drop files in a download burst.
      }

      this.isLoading = true
      api.batchDownload({
        items: items,
      })
        .then(ret => {
          this.isLoading = false
          this.$dialog.alert({
            message: this.lang('Your file is ready'),
            confirmText: this.lang('Download'),
            onConfirm: () => {
              window.open(Vue.config.baseURL+'/batchdownload&uniqid='+ret.uniqid, '_blank')
            }
          })
        })
        .catch(error => {
          this.isLoading = false
          this.handleError(error)
        })
    },
    download(item) {
      window.open(this.getDownloadLink(item.path), '_blank')
    },
    // Sequential blob fetch so per-file failures can be surfaced — native downloads
    // (window.open / <a download>) give no success/failure callback. The backend returns
    // a 4xx for XHR on failure (see api.downloadBlob), so a rejected fetch is the failure
    // signal. Each file is independent: one failure doesn't abort the rest; the names that
    // failed are collected and reported together at the end.
    async downloadEach(items) {
      this.isLoading = true
      const failed = []
      for (const item of items) {
        try {
          const blob = await api.downloadBlob({ path: item.path })
          this.saveBlob(blob, item.name)
        } catch (e) {
          failed.push(item.name)
        }
      }
      this.isLoading = false
      if (failed.length) {
        this.$toast.open({
          message: this.escapeHtml(this.lang('Could not download') + ': ' + failed.join(', ')),
          type: 'is-danger',
          duration: 5000,
        })
      }
    },
    saveBlob(blob, filename) {
      const url = URL.createObjectURL(blob)
      try {
        const a = document.createElement('a')
        a.href = url
        a.download = filename
        a.style.display = 'none'
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
      } finally {
        URL.revokeObjectURL(url)
      }
    },
    // Safari (desktop) and any iOS browser can't reliably trigger several programmatic
    // downloads in a row (a download fired after an await loses the user-gesture and is
    // blocked), so callers route those to the server-side zip instead.
    supportsMultiDownload() {
      const ua = navigator.userAgent || ''
      const isiOS = /iP(ad|hone|od)/.test(ua)
      const isDesktopSafari = /Safari/.test(ua) && !/Chrome|Chromium|Android|CriOS|FxiOS|Edg|OPR/.test(ua)
      return !(isiOS || isDesktopSafari)
    },
    dismissMfaBanner() {
      const user = this.$store.state.user
      this.mfaBannerDismissed = true
      markMfaNudgeDismissed(user && user.username)
    },
    search() {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Search,
        events: {
          selected: item => {
            this.goTo(item.dir)
          }
        },
      })
    },
    preview(item) {
      let modal = null
      if (this.isImage(item.path)) {
        modal = Gallery
      }
      if (this.isText(item.path)) {
        modal = Editor
      }
      this.$modal.open({
        parent: this,
        props: { item: item },
        hasModalCard: true,
        component: modal,
      })
    },
    isArchive(item) {
      return item.type == 'file' && item.name.split('.').pop() == 'zip'
    },
    unzip(event, item) {
      this.$dialog.confirm({
        message: this.lang('Are you sure you want to do this?'),
        type: 'is-danger',
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Unzip'),
        onConfirm: () => {
          this.isLoading = true
          api.unzipItem({
            item: item.path,
            destination: this.$store.state.cwd.location,
          })
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    zip(event, item) {
      this.$dialog.prompt({
        message: this.lang('Name'),
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Create'),
        inputAttrs: {
          value: this.$store.state.config.default_archive_name,
          placeholder: this.$store.state.config.default_archive_name,
          maxlength: 100,
          required: false,
        },
        onConfirm: (value) => {
          if (! value) {
            return
          }
          this.isLoading = true
          api.zipItems({
            name: value,
            items: item ? [item] : this.getSelected(),
            destination: this.$store.state.cwd.location,
          })
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    chmod(event, item) {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Permissions,
        props: {
          name: item.name,
          permissions: item.permissions,
          isDir: item.type == 'dir',
        },
        events: {
          saved: (permissions, recursive = null) => {
            this.isLoading = true
            api.chmodItems({
              items: item ? [item] : this.getSelected(),
              permissions: permissions,
              recursive: recursive,
            })
              .then(() => {
                this.isLoading = false
                this.loadFiles()
              })
              .catch(error => {
                this.isLoading = false
                this.handleError(error)
              })
            this.checked = []
          }
        },
      })
    },
    rename(event, item) {
      this.$dialog.prompt({
        message: this.lang('New name'),
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Rename'),
        inputAttrs: {
          value: item ? item.name : this.getSelected()[0].name,
          maxlength: 100,
          required: false,
        },
        onConfirm: (value) => {
          this.isLoading = true
          api.renameItem({
            from: item.name,
            to: value,
            destination: this.$store.state.cwd.location,
          })
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    createFolder() {
      this.$dialog.prompt({
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Create'),
        inputAttrs: {
          placeholder: 'MyFolder',
          maxlength: 100,
          required: false,
        },
        onConfirm: (value) => {
          this.isLoading = true
          api.createNew({
            type: 'dir',
            name: value,
            destination: this.$store.state.cwd.location,
          })
          // TODO: cors is triggering this too early?
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    remove(event, item) {
      this.$dialog.confirm({
        message: this.lang('Are you sure you want to do this?'),
        type: 'is-danger',
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Delete'),
        onConfirm: () => {
          this.isLoading = true
          api.removeItems({
            items: item ? [item] : this.getSelected(),
          })
            .then(() => {
              this.isLoading = false
              this.loadFiles()
            })
            .catch(error => {
              this.isLoading = false
              this.handleError(error)
            })
          this.checked = []
        }
      })
    },
    sortByName(a, b, order) {
      return this.customSort(a, b, !order, 'name')
    },
    sortBySize(a, b, order) {
      return this.customSort(a, b, !order, 'size')
    },
    sortByTime(a, b, order) {
      return this.customSort(a, b, !order, 'time')
    },
    customSort(a, b, order, param) {
      if (a.type == 'back') return -1
      if (b.type == 'back') return 1

      if (a.type == 'dir' && b.type != 'dir') return -1
      if (b.type == 'dir' && a.type != 'dir') return 1

      if (b.type == a.type) {
        if (a[param] === b[param]) return this.customSort(a, b, false, 'name')

        if (_.isString(a[param])) return (a[param].localeCompare(b[param])) * (order ? -1 : 1)
        else return ((a[param] < b[param]) ? -1 : 1) * (order ? -1 : 1)
      }
    },
  }
}
</script>

<style scoped>
#loading {
  width: 100%;
  height: 100%;
  position: fixed;
  z-index: 1000;
  top: 0;
  left: 0;
  user-drag: none;
  user-select: none;
  -moz-user-select: none;
  -webkit-user-drag: none;
  -webkit-user-select: none;
  -ms-user-select: none;
}
#dropzone {
  padding: 0;
}
#browser {
  margin: 50px auto 100px auto;
}
.breadcrumb a {
  font-weight: bold;
}
#multi-actions {
  min-height: 55px;
}
#multi-actions a {
  margin: 0 15px 15px 0;
}
#bottom-info {
  padding: 15px 0;
}
.file-row a {
  color: #373737;
}
.file-row a.name {
  word-break: break-all;
}
.file-row.type-dir a.name {
  font-weight: bold
}
#single-actions {
  padding: 6px 12px;
}
.drop-info {
  margin: 20% auto;
}
.search-btn {
  margin-right: 10px;
}
</style>
