<template>
  <div id="login" class="columns is-centered">
    <div class="column is-narrow">
      <form @submit.prevent="submit" v-if="!sent">
        <div class="box" style="max-width: 480px">
          <div class="has-text-centered">
            <img :src="$store.state.config.logo" class="logo">
          </div>
          <h3 class="is-size-5" style="margin: 1em 0">
            {{ lang('Reset your password') }}
          </h3>
          <p>{{ lang('Enter the email address associated with your account. If it matches an address on file, we will send you a password reset link shortly.') }}</p>
          <p style="margin-top: 0.75em">{{ lang('If you do not receive the email within a few minutes, check your spam folder or contact us for help.') }}</p>
          <br>
          <b-field :label="lang('Email')" :type="error ? 'is-danger' : ''" :message="error">
            <b-input v-model="email" type="email" name="email" autocomplete="email" @input="error = ''" ref="email" data-test="forgot-email" />
          </b-field>
          <div class="login-actions">
            <button type="button" @click="$router.push('/login').catch(() => {})" class="login-link">{{ lang('Back to login') }}</button>
            <button class="button is-primary" data-test="forgot-submit">
              {{ lang('Send reset link') }}
            </button>
          </div>
        </div>
      </form>

      <div class="box username-help" v-if="!sent">
        <p><strong>{{ lang('Forgot your username?') }}</strong></p>
        <p>
          {{ lang('Your username is typically your first and last name in all lowercase, with no spaces or punctuation — for example, johnsmith.') }}
          {{ lang('You can also search your inbox for the message we sent when we first set up your portal access.') }}
          {{ lang("If you're still stuck,") }} <a href="mailto:staff@elliffcpa.com">{{ lang('email us') }}</a> {{ lang('and we will help.') }}
        </p>
      </div>

      <div class="box" v-if="sent" style="max-width: 480px" data-test="forgot-sent">
        <h3 class="is-size-5">
          {{ lang('Check your inbox') }}
        </h3>
        <p>{{ lang('If that email address is the email address we used to establish your login information in this portal, we have sent a reset link. Please check your inbox, including your spam folder.') }} <span v-if="ttlMinutes">{{ lang('The link will expire in') }} {{ ttlMinutes }} {{ ttlMinutes === 1 ? lang('minute') : lang('minutes') }}.</span></p>
        <p style="margin-top: 0.75em">{{ lang('Still do not see it? Contact us for help.') }}</p>
        <br>
        <button class="button is-primary" @click="$router.push('/login').catch(() => {})">
          {{ lang('Back to login') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../api/api'

export default {
  name: 'ForgotPassword',
  data() {
    return {
      email: '',
      sent: false,
      error: '',
    }
  },
  computed: {
    ttlMinutes() {
      const ttl = this.$store.state.config.password_reset_token_ttl
      if (!ttl || typeof ttl !== 'number') return null
      return Math.max(1, Math.round(ttl / 60))
    },
  },
  mounted() {
    this.$refs.email && this.$refs.email.focus()
  },
  methods: {
    submit() {
      // Validate in JS rather than with a native `required` on the input, for
      // the same reason as the login form (commit 5daa0ae): a `required` field
      // makes Buefy pop the browser's "please fill out this field" message the
      // moment the field is blurred — e.g. when the user clicks the adjacent
      // "Back to login" button — and the inserted message shifts the row enough
      // to swallow that click. The inline error covers the empty case instead.
      this.error = ''
      if (!this.email) {
        this.error = this.lang('Please enter your email address.')
        this.$nextTick(() => this.$refs.email && this.$refs.email.focus())
        return
      }
      api.requestPasswordReset({ email: this.email })
        .then(() => {
          this.sent = true
        })
        .catch(error => {
          if (error.response && error.response.status === 429) {
            this.$toast.open({ message: this.lang('Too many requests'), type: 'is-warning' })
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
#login {
  padding: 120px 20px;
}
.login-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem 1.5rem;
  margin-top: 0.75em;
}
.login-link {
  background: none;
  border: none;
  padding: 0;
  margin: 0;
  font-family: inherit;
  font-size: 0.9em;
  line-height: inherit;
  white-space: nowrap;
  color: #3273dc;
  cursor: pointer;
  text-decoration: none;
}
.login-link:hover {
  color: #363636;
  text-decoration: underline;
}
.login-link:focus {
  outline: none;
}
.login-link:focus-visible {
  outline: 2px solid rgba(50, 115, 220, 0.35);
  outline-offset: 2px;
  border-radius: 2px;
}
.username-help {
  max-width: 480px;
  margin-top: 1em;
}
</style>
