<template>
  <div class="container">
    <Menu />

    <section class="actions is-flex is-justify-between">
      <div>
        <a @click="browse" data-test="folder-access-browse">
          <b-icon icon="folder-open" size="is-small" /> {{ lang('Browse folder') }}
        </a>
      </div>
      <div class="is-flex">
        <b-input
          v-model="search"
          :placeholder="lang('Filter folders')"
          size="is-small"
          type="search"
          icon="search"
          style="margin-right: 1rem"
          data-test="folder-access-filter"
        />
        <Pagination :perpage="perPage" @selected="perPage = $event" />
      </div>
    </section>

    <b-table
      :data="filteredFolders"
      :default-sort="defaultSort"
      :paginated="perPage > 0"
      :per-page="perPage"
      :current-page.sync="currentPage"
      :hoverable="true"
      :loading="isLoading"
      :row-class="(row) => (row.inspected ? 'is-inspected-row' : '')"
      detailed
      detail-key="path"
      :show-detail-icon="true"
      data-test="folder-access-table"
    >
      <template slot-scope="props">
        <b-table-column :label="lang('Folder')" field="path" sortable>
          <code>{{ props.row.path }}</code>
          <b-tag v-if="props.row.inspected" type="is-info is-light" size="is-small">
            {{ lang('Inspected') }}
          </b-tag>
        </b-table-column>

        <b-table-column :label="lang('Users')" field="user_count" sortable numeric>
          <span v-if="props.row.user_count === 0" class="has-text-grey">
            {{ lang('No users with access') }}
          </span>
          <template v-else>
            <b-tag
              v-for="u in props.row.access.slice(0, 4)"
              :key="u.username"
              :type="u.inherited ? 'is-light' : 'is-primary is-light'"
              size="is-small"
              style="margin: 1px"
            >
              {{ u.username }}
            </b-tag>
            <span v-if="props.row.user_count > 4" class="has-text-grey is-size-7">
              +{{ props.row.user_count - 4 }}
            </span>
          </template>
        </b-table-column>
      </template>

      <template slot="detail" slot-scope="props">
        <div v-if="props.row.access.length === 0" class="has-text-grey">
          {{ lang('No users with access') }}
        </div>
        <table v-else class="table is-fullwidth is-narrow access-detail">
          <thead>
            <tr>
              <th>{{ lang('Name') }}</th>
              <th>{{ lang('Role') }}</th>
              <th>{{ lang('Access') }}</th>
              <th>{{ lang('Permissions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in props.row.access" :key="u.username">
              <td>
                {{ u.name }}
                <small class="has-text-grey">({{ u.username }})</small>
              </td>
              <td>{{ lang(capitalize(u.role)) }}</td>
              <td>
                <b-tag v-if="u.inherited" type="is-warning is-light" size="is-small">
                  {{ lang('Inherited from {0}', u.granted_by) }}
                </b-tag>
                <b-tag v-else type="is-success is-light" size="is-small">
                  {{ lang('Direct') }}
                </b-tag>
              </td>
              <td>
                <span v-if="u.permissions && u.permissions.length">{{ permissions(u.permissions) }}</span>
                <span v-else class="has-text-grey">&mdash;</span>
                <b-tag
                  v-if="!hasRead(u)"
                  type="is-danger is-light"
                  size="is-small"
                  :title="lang('User is scoped here but cannot list it without read permission')"
                >
                  {{ lang('no read') }}
                </b-tag>
              </td>
            </tr>
          </tbody>
        </table>
      </template>

      <template slot="empty">
        <section class="section">
          <div class="content has-text-grey has-text-centered">
            {{ isLoading ? lang('Loading') : lang('No folders') }}
          </div>
        </section>
      </template>
    </b-table>
  </div>
</template>

<script>
import Menu from './partials/Menu'
import Pagination from './partials/Pagination'
import Tree from './partials/Tree'
import api from '../api/api'
import _ from 'lodash'

export default {
  name: 'FolderAccess',
  components: { Menu, Pagination },
  data() {
    return {
      perPage: this.$store.state.config.pagination[0],
      currentPage: 1,
      isLoading: false,
      defaultSort: ['path', 'asc'],
      folders: [],
      search: '',
    }
  },
  computed: {
    filteredFolders() {
      const q = this.search.trim().toLowerCase()
      if (! q) return this.folders
      return _.filter(this.folders, f =>
        f.path.toLowerCase().includes(q) ||
        _.some(f.access, u => (u.username + ' ' + u.name).toLowerCase().includes(q))
      )
    },
  },
  mounted() {
    this.load()
  },
  methods: {
    load() {
      this.isLoading = true
      api.folderAccessAudit()
        .then(ret => {
          // Default `inspected` so it is a reactive, pre-existing key — an
          // in-place Object.assign in inspect() can then toggle it on an
          // already-listed row (Vue 2 won't react to a brand-new key).
          this.folders = (ret.folders || []).map(f => ({ ...f, inspected: false }))
        })
        .catch(error => this.handleError(error))
        .finally(() => {
          this.isLoading = false
        })
    },
    permissions(array) {
      return _.join(array, ', ')
    },
    hasRead(u) {
      return _.includes(u.permissions, 'read')
    },
    browse() {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: Tree,
        events: {
          selected: dir => this.inspect(dir.path),
        },
      })
    },
    inspect(path) {
      this.isLoading = true
      api.folderAccessAudit({ path })
        .then(ret => {
          const folder = ret.folders && ret.folders[0]
          if (! folder) return
          const existing = _.find(this.folders, f => f.path === folder.path)
          if (existing) {
            // `inspected` already exists on the row (defaulted in load), so
            // assigning it here updates reactively and the highlight/tag show.
            Object.assign(existing, folder, { inspected: true })
          } else {
            folder.inspected = true
            this.folders.unshift(folder)
          }
          // Reset the filter so the inspected folder is always visible.
          this.search = ''
          this.currentPage = 1
          this.$toast.open({
            message: this.lang('Showing access for {0}', folder.path),
            type: 'is-success',
          })
        })
        .catch(error => this.handleError(error))
        .finally(() => {
          this.isLoading = false
        })
    },
  },
}
</script>

<style scoped>
.actions {
  margin: 50px 0 30px 0;
}
.access-detail {
  background: transparent;
}
</style>
<style>
.is-inspected-row {
  background-color: #f0f8ff;
}
</style>
