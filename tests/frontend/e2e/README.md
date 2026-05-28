# FileGator E2E tests (Cypress)

Two flavours of spec live in this directory:

| Spec | What it does | Backend needed? |
|---|---|---|
| `specs/test.js` | Pre-existing Vue CLI scaffold. Mocks the network with `cy.route()` against fixtures in `fixtures/`. Validates UI shape only. | No |
| `specs/*.spec.js` | Real-backend specs added this round. Drive the full PHP + Vue stack. Catch integration bugs unit tests can't reach. | **Yes** |

## Running real-backend specs locally

```bash
# Terminal 1 — backend + dev server on ports 8081 + 8080
npm run serve

# Terminal 2 — Cypress, headless
npm run test:e2e

# Or interactive (open the Cypress GUI)
npm run e2e
```

Both commands run **all** specs (mock and real). To run only the real-backend specs, pass the glob:

```bash
./node_modules/.bin/cypress run --spec 'tests/frontend/e2e/specs/!(test).spec.js'
```

## State reset between specs

Real-backend specs are stateful — they create users, upload files, enroll MFA. To keep them deterministic, every spec that mutates state calls `cy.resetBackend()` in a `before()` or `beforeEach()` hook. That command shells out to:

- `cp private/users.json.blank private/users.json` — drops users back to admin + guest, no MFA
- `rm -f private/tmp/*.lock` — clears IP lockouts, MFA replay markers, per-username lockouts
- `rm -f private/mfa_encryption.key` — fresh keyfile per spec (regenerated lazily on next enrollment)
- `mkdir -p repository/projects repository/personal` — ensures fixture folders exist for multi-folder tests

The PHP server picks up file changes on its next request — no restart needed.

## Custom commands

Defined in `support/commands.js`:

| Command | Purpose |
|---|---|
| `cy.resetBackend()` | Wipe users/lockfiles/keyfile to baseline. Call in `before()` for any spec that creates users. |
| `cy.login(username, password)` | Drive the UI login form. Does not handle MFA. |
| `cy.logoutUi()` | Click the navbar Log out item. |
| `cy.adminCreateUser(user)` | Open the Add User modal and create a user. Assumes the caller is logged in as a non-MFA admin. |

Future additions (not yet built):

- `cy.adminCreateUserWithStepUp(user, totpSecret)` — for MFA admins; drives the step-up dialog
- `cy.enrollMfa(user)` — drives the MFA setup screen end-to-end

## Known limitations

1. **No file-upload tests yet.** Real upload-via-UI testing needs the `cypress-file-upload` plugin which we haven't added. The `multi-folder.spec.js` isolation test works around it by `cy.exec`-ing the file directly into `repository/`. Adding the plugin is straightforward (`npm i -D cypress-file-upload@^4` for Cypress 3.x) and would let us write proper upload coverage.

2. **No MFA flow tests yet.** The MFA login + step-up specs come in the second PR after this harness is proven. They'll need a `cy.task('totpFor', secret)` to generate codes from the Node plugin layer.

3. **PHP server must be running before specs run.** No automatic boot. Adding `start-server-and-test` as a dev dep would auto-wait, but that's deferred to keep this PR minimal.

4. **Cypress 3.8.3 is old.** Modern Cypress (13+) has a much better DX (`cy.intercept` instead of `cy.route`, automatic typings, parallelisation). Migration is its own follow-up; current specs use the older API to match what's installed.

## Adding a new real-backend spec

```js
describe('Your flow', () => {
  before(() => {
    cy.resetBackend()
    cy.login('admin', 'admin123')
    cy.adminCreateUser({
      username: 'foo',
      password: 'foo123',
      name: 'Foo',
      role: 'user',
      homedirs: ['/foo'],
      permissions: ['read', 'write'],
    })
    cy.logoutUi()
  })

  it('does the thing', () => {
    cy.login('foo', 'foo123')
    cy.contains('Files')
    // ... assertions
  })
})
```

Keep specs independent — each one should resetBackend + seed in its own `before()`. Don't share state across specs (this is the test pattern that bit the multi-tenant Cypress projects we cribbed this design from).
