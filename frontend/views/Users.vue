<template>
  <div class="container">
    <Menu />

    <section class="actions is-flex is-justify-between">
      <div>
        <a @click="addUser" data-test="add-user">
          <b-icon icon="plus" size="is-small" /> {{ lang('New') }}
        </a>
      </div>
      <div>
        <Pagination :perpage="perPage" @selected="perPage = $event" />
      </div>
    </section>

    <b-table
      :data="users"
      :default-sort="defaultSort"
      :paginated="perPage > 0"
      :per-page="perPage"
      :current-page.sync="currentPage"
      :hoverable="true"
      :loading="isLoading"
    >
      <template slot-scope="props">
        <b-table-column :label="lang('Name')" field="name" sortable>
          <a @click="editUser(props.row)">
            {{ props.row.name }}
          </a>
        </b-table-column>

        <b-table-column :label="lang('Username')" field="username" sortable>
          <a @click="editUser(props.row)" data-test="user-edit">
            {{ props.row.username }}
          </a>
        </b-table-column>

        <b-table-column :label="lang('Permissions')" field="role">
          {{ permissions(props.row.permissions) }}
        </b-table-column>

        <b-table-column :label="lang('Role')" field="role" sortable>
          {{ lang(capitalize(props.row.role)) }}
        </b-table-column>

        <b-table-column>
          <a v-if="props.row.role != 'guest'" @click="remove(props.row)" data-test="user-delete">
            <b-icon icon="trash-alt" size="is-small" />
          </a>
        </b-table-column>
      </template>
    </b-table>
  </div>
</template>

<script>
import UserEdit from './partials/UserEdit'
import Menu from './partials/Menu'
import Pagination from './partials/Pagination'
import api from '../api/api'
import withStepUp, { isStepUpCancelled } from '../utils/withStepUp'
import _ from 'lodash'

export default {
  name: 'Users',
  components: { Menu, Pagination },
  data() {
    return {
      perPage: this.$store.state.config.pagination[0],
      currentPage: 1,
      isLoading: false,
      defaultSort: ['name', 'desc'],
      users: [],
    }
  },
  mounted() {
    api.listUsers()
      .then(ret => {
        this.users = ret
      })
      .catch(error => this.handleError(error))
  },
  methods: {
    remove(user) {
      withStepUp(this, {
        actionDescription: this.lang('Delete user {0}', user.username),
        dangerWarning: this.lang('This permanently removes the user and all their session state.'),
        action: (creds) => api.deleteUser({ username: user.username, ...creds }),
      })
        .then(() => {
          this.users = _.reject(this.users, u => u.username == user.username)
          this.$toast.open({
            message: this.lang('Deleted'),
            type: 'is-success',
          })
        })
        .catch(err => {
          if (isStepUpCancelled(err)) return
          this.handleError(err)
        })
    },
    permissions(array) {
      return _.join(array, ', ')
    },
    addUser() {
      this.$modal.open({
        parent: this,
        props: { user: { role: 'user'}, action: 'add' },
        hasModalCard: true,
        component: UserEdit,
        events: {
          updated: ret => {
            this.users.push(ret)
          }
        },
      })
    },
    editUser(user) {
      if (! user.username) {
        this.handleError('Missing username')
        return
      }
      this.$modal.open({
        parent: this,
        props: { user: user, action: 'edit' },
        hasModalCard: true,
        component: UserEdit,
        events: {
          updated: ret => {
            this.users.splice(_.findIndex(this.users, {username: ret.username}), 1, ret)
          }
        },
      })
    },
  }
}
</script>

<style scoped>
.actions {
  margin: 50px 0 30px 0;
}
</style>
