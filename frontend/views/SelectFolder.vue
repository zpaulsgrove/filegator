<template>
  <div id="select-folder" class="columns is-centered">
    <div class="column is-narrow">
      <div class="box" style="max-width: 480px">
        <div class="has-text-centered">
          <img :src="$store.state.config.logo" class="logo">
        </div>
        <h3 class="is-size-5" style="margin: 1em 0">
          {{ lang('Select a folder') }}
        </h3>
        <p>{{ lang('Your account has access to multiple folders. Choose the one you want to open.') }}</p>
        <br>

        <div v-if="folders.length === 0">
          <p class="has-text-danger">
            {{ lang('No folders available') }}
          </p>
        </div>

        <div v-else data-test="folder-picker">
          <button
            v-for="path in folders"
            :key="path"
            type="button"
            class="button is-fullwidth is-primary is-light folder-button"
            data-test="folder-button"
            :data-test-path="path"
            :disabled="busy"
            @click="pick(path)"
          >
            <span class="folder-path">{{ path }}</span>
          </button>
        </div>

        <div v-if="error" class="login-error">
          <code>{{ error }}</code>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../api/api'
import { needsFolderPicker } from '../mixins/postLogin'

export default {
  name: 'SelectFolder',
  data() {
    return {
      busy: false,
      error: '',
    }
  },
  computed: {
    folders() {
      const user = this.$store.state.user
      return (user && Array.isArray(user.homedirs)) ? user.homedirs : []
    },
  },
  mounted() {
    // Defensive guard: bounce single-folder or already-selected users
    // straight to the file browser. Uses the same predicate as the
    // router guard and routeAfterLogin so the three stay in sync.
    if (!needsFolderPicker(this.$store.state.user)) {
      this.$router.push('/').catch(() => {})
    }
  },
  methods: {
    pick(path) {
      if (this.busy) return
      this.busy = true
      this.error = ''

      api.selectFolder({ homedir: path })
        .then(() => {
          this.$store.commit('setActiveHomedir', path)
          // Reset cwd before navigating into the browser so a stale
          // path from a previous session doesn't pre-fill.
          this.$store.commit('resetCwd')
          // Honor a deep link that survived the trip through login: drop the
          // user into the bookmarked subfolder of the folder they just picked.
          // Browser.vue's $route watcher loads it. Consume the stash either way.
          const cd = this.$store.state.pendingCd
          this.$store.commit('setPendingCd', null)
          this.$store.commit('setPendingFolder', null)
          if (cd) {
            this.$router.push({ path: '/', query: { cd, folder: path } }).catch(() => {})
          } else {
            this.$router.push('/').catch(() => {})
          }
        })
        .catch(error => {
          this.busy = false
          if (error && error.response && error.response.status === 422) {
            this.error = this.lang('Folder no longer available; please contact us.')
            // Refresh the user record — admin may have removed this folder.
            api.getUser().then(user => this.$store.commit('setUser', user)).catch(() => {})
          } else {
            this.handleError(error)
          }
        })
    },
  },
}
</script>

<style scoped>
.logo {
  width: 300px;
  display: inline-block;
}
.box {
  padding: 30px;
}
#select-folder {
  padding: 120px 20px;
}
.folder-button {
  margin-top: 0.5em;
  justify-content: flex-start;
  text-align: left;
}
.folder-path {
  font-family: monospace;
}
.login-error {
  margin-top: 0.75em;
  text-align: center;
}
</style>
