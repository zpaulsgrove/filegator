<!--
  Admin-only file-activity audit. Shows recent write-mutations across all
  users and folders, served by AdminController::auditLog.

  SECURITY: `user`, `path`, `action`, and `detail` are attacker-controlled
  (filenames, etc.). They are rendered ONLY via {{ }} interpolation / slot
  text, which Vue HTML-escapes. NEVER switch any of these to v-html — that
  would turn a malicious filename into stored XSS in the admin's session.
-->
<template>
  <div class="container">
    <Menu />

    <section class="actions is-flex is-justify-between">
      <div class="is-flex">
        <b-select
          v-model="actionFilter"
          size="is-small"
          data-test="audit-action-filter"
          style="margin-right: 1rem"
        >
          <option value="">
            {{ lang('All actions') }}
          </option>
          <option v-for="a in actions" :key="a" :value="a">
            {{ lang(capitalize(a)) }}
          </option>
        </b-select>
        <b-input
          v-model="search"
          :placeholder="lang('Filter activity')"
          size="is-small"
          type="search"
          icon="search"
          data-test="audit-filter"
        />
      </div>
      <div class="is-flex">
        <a @click="load" data-test="audit-refresh" style="margin-right: 1rem">
          <b-icon icon="redo" size="is-small" /> {{ lang('Refresh') }}
        </a>
        <Pagination :perpage="perPage" @selected="perPage = $event" />
      </div>
    </section>

    <b-table
      :data="filteredEvents"
      :default-sort="defaultSort"
      :paginated="perPage > 0"
      :per-page="perPage"
      :current-page.sync="currentPage"
      :hoverable="true"
      :loading="isLoading"
      data-test="audit-table"
    >
      <template slot-scope="props">
        <b-table-column :label="lang('Time')" field="ts" sortable>
          {{ formatDate(props.row.ts) }}
        </b-table-column>

        <b-table-column :label="lang('User')" field="user" sortable>
          {{ props.row.user }}
          <b-tag size="is-small" type="is-light">
            {{ lang(capitalize(props.row.role)) }}
          </b-tag>
        </b-table-column>

        <b-table-column :label="lang('Action')" field="action" sortable>
          <b-tag :type="actionType(props.row.action)" size="is-small">
            {{ lang(capitalize(props.row.action)) }}
          </b-tag>
        </b-table-column>

        <b-table-column :label="lang('Path')" field="path" sortable>
          <code>{{ props.row.path }}</code>
          <span v-if="props.row.detail" class="has-text-grey is-size-7"> ({{ props.row.detail }})</span>
        </b-table-column>
      </template>

      <template slot="empty">
        <section class="section">
          <div class="content has-text-grey has-text-centered">
            {{ isLoading ? lang('Loading') : lang('No activity') }}
          </div>
        </section>
      </template>
    </b-table>
  </div>
</template>

<script>
import Menu from './partials/Menu'
import Pagination from './partials/Pagination'
import api from '../api/api'
import _ from 'lodash'

export default {
  name: 'Audit',
  components: { Menu, Pagination },
  data() {
    return {
      perPage: this.$store.state.config.pagination[0],
      currentPage: 1,
      isLoading: false,
      defaultSort: ['ts', 'desc'],
      events: [],
      search: '',
      actionFilter: '',
      // Mirror of AuditLog::ACTIONS (backend is the source of truth); used
      // for the filter dropdown and tag colouring only.
      actions: ['upload', 'create', 'copy', 'move', 'rename', 'delete', 'zip', 'unzip', 'chmod', 'save'],
    }
  },
  computed: {
    filteredEvents() {
      const q = this.search.trim().toLowerCase()
      return _.filter(this.events, e => {
        if (this.actionFilter && e.action !== this.actionFilter) return false
        if (! q) return true
        return ((e.user || '') + ' ' + (e.path || '') + ' ' + (e.action || '') + ' ' + (e.detail || ''))
          .toLowerCase().includes(q)
      })
    },
  },
  mounted() {
    this.load()
  },
  methods: {
    load() {
      this.isLoading = true
      api.auditLog()
        .then(ret => {
          this.events = (ret && ret.events) || []
        })
        .catch(error => this.handleError(error))
        .finally(() => {
          this.isLoading = false
        })
    },
    // Map an action to a Buefy tag type. Safe: keyed off the fixed action
    // vocabulary, never interpolated as HTML.
    actionType(action) {
      switch (action) {
        case 'delete': return 'is-danger'
        case 'upload':
        case 'create': return 'is-success'
        case 'move':
        case 'rename': return 'is-warning'
        case 'copy':
        case 'zip':
        case 'unzip': return 'is-info'
        default: return 'is-light'
      }
    },
  },
}
</script>

<style scoped>
.actions {
  margin: 50px 0 30px 0;
}
</style>
