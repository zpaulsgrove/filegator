# E2E tests

Cypress 14 specs targeting a live FileGator backend on `http://localhost:8081`.

## Running

```sh
# Headless (CI mode):
npm run test:e2e

# Interactive (Cypress runner UI):
npm run e2e
```

Both commands wrap [`start-server-and-test`](https://github.com/bahmutov/start-server-and-test):
it boots the PHP dev server (`php -S 0.0.0.0:8081`), waits for the
URL to respond, runs Cypress, then tears the server down.

If you'd rather drive your own server:

```sh
php -S 0.0.0.0:8081 &
npm run cypress:run         # or cypress:open
```

## Layout

```
tests/frontend/e2e/
├── fixtures/        # static JSON used by cy.intercept (legacy)
├── specs/           # *.cy.js — one spec per critical path
├── support/
│   ├── commands.js  # cy.resetBackend, cy.login, cy.adminCreateUser, cy.logoutUi
│   └── e2e.js       # Cypress 10+ entrypoint (renamed from index.js)
└── README.md
```

Config lives in `cypress.config.js` at the repo root.

## Writing a spec

Every spec resets backend state in `beforeEach` via `cy.resetBackend()` —
this restores `private/users.json` from `users.json.blank`, clears MFA
lockout files, and (re)creates the storage roots used by multi-folder
fixtures.

Authentication should go through `cy.login(user, pass)` (programmatic via
`/login`) unless the spec is specifically exercising the login UI.

```js
describe('My feature', () => {
  beforeEach(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
  })

  it('does the thing', () => {
    cy.visit('/')
    // ...
  })
})
```

## Notes on the Cypress 3 → 14 upgrade

This suite was migrated from Cypress 3.8.3 + `@vue/cli-plugin-e2e-cypress`
to Cypress 14 + `start-server-and-test` in May 2026. Notable shifts:

- `cypress.json` → `cypress.config.js` with `defineConfig`.
- `plugins/index.js` folded into `setupNodeEvents` in the config.
- `support/index.js` renamed to `support/e2e.js` (Cypress 10+ convention).
- Specs must end in `.cy.js` (matched by `specPattern`).
- `cy.server()` / `cy.route()` are gone — use `cy.intercept` instead.
- The Vue CLI's `vue-cli-service test:e2e` wrapper is dropped; Cypress
  is invoked directly via the npm scripts above.
