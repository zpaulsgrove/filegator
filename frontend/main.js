import Vue from 'vue'
import App from './App.vue'
import router from './router'
import store from './store'
import Buefy from 'buefy'
import shared from './mixins/shared'
import axios from 'axios'
import api from './api/api'
import VueLazyload from 'vue-lazyload'
import { routeAfterLogin, needsFolderPicker } from './mixins/postLogin'
import '@fortawesome/fontawesome-free/css/all.css'
import '@fortawesome/fontawesome-free/css/fontawesome.css'

//TODO: import './registerServiceWorker'

Vue.config.productionTip = false

/* eslint-disable-next-line */
Vue.config.baseURL = process.env.VUE_APP_API_ENDPOINT ? process.env.VUE_APP_API_ENDPOINT : window.location.origin+window.location.pathname+'?r='

axios.defaults.withCredentials = true
axios.defaults.baseURL = Vue.config.baseURL

axios.defaults.headers['Content-Type'] = 'application/json'

Vue.use(Buefy, {
  defaultIconPack: 'fas',
})

Vue.use(VueLazyload, {
  preLoad: 1.3,
})


Vue.mixin(shared)

new Vue({
  router,
  store,
  created: function() {

    api.getConfig()
      .then(ret => {
        this.$store.commit('setConfig', ret.data.data)
        api.getUser()
          .then((user) => {
            this.$store.commit('initialize')
            this.$store.commit('setUser', user)

            // Preserve deep links into public auth routes (email reset link,
            // bookmarked forgot-password page) — otherwise the bootstrap push
            // to / would kick the user back to the login screen before the
            // intended route can render.
            const preservedRoutes = ['forgot-password', 'reset-password']
            if (preservedRoutes.includes(this.$route.name)) {
              return
            }

            const u = this.$store.state.user
            const onBrowser = this.$route.name === 'browser'
            const cd = onBrowser ? this.$route.query.cd : null
            const folder = onBrowser ? this.$route.query.folder : null
            const isGuest = !u || u.role === 'guest'
            const homedirs = (u && Array.isArray(u.homedirs)) ? u.homedirs : []

            if (!isGuest && onBrowser) {
              // A folder hint that names a *different* valid folder than the
              // session's active one must be honored (cross-session bookmark
              // into a folder the session isn't currently pointed at).
              const honorFolder = folder
                && homedirs.indexOf(folder) !== -1
                && folder !== u.active_homedir
              if (!needsFolderPicker(u) && !honorFolder) {
                // Active folder is known and matches the URL (or none was
                // named). Leave the route untouched so Browser.mounted can
                // restore `cd` itself — this is the common reload case
                // (single-folder, and multi-folder with a valid selection).
                // NB: relies on App.vue gating render on `initialized`, so
                // Browser mounts only after the user is loaded (can('read')
                // true, query.cd present). See Browser.mounted.
                return
              }
              // Either the picker is required (multi-folder, no active) —
              // possibly with a folder hint that lets routeAfterLogin bypass
              // it — or the URL names a different valid folder. Stash the
              // intent and let routeAfterLogin resolve it.
              this.$store.commit('setPendingCd', cd)
              this.$store.commit('setPendingFolder', folder)
              routeAfterLogin(u, this.$router, this.$store)
              return
            }

            if (isGuest && onBrowser && cd) {
              // Logged-out deep link: stash it so it survives the trip through
              // login, then route the guest normally. routeAfterLogin's guest
              // branch deliberately leaves the stash intact for the eventual
              // post-login call (Login.vue) to consume.
              this.$store.commit('setPendingCd', cd)
              this.$store.commit('setPendingFolder', folder)
              routeAfterLogin(u, this.$router, this.$store)
              return
            }

            // Everything else: guest/authenticated off the browser route, or
            // a browser route with nothing to preserve.
            routeAfterLogin(u, this.$router, this.$store)
          })
          .catch(() => {
            this.$notification.open({
              message: this.lang('Something went wrong'),
              type: 'is-danger',
              queue: false,
              indefinite: true,
            })
          })
      })
      .catch(() => {
        this.$notification.open({
          message: this.lang('Something went wrong'),
          type: 'is-danger',
          queue: false,
          indefinite: true,
        })
      })
  },
  render: h => h(App),
}).$mount('#app')
