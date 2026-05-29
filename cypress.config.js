const { defineConfig } = require('cypress')

module.exports = defineConfig({
  // FileGator E2E config for Cypress 14+.
  //
  // Migrated from cypress.json + plugins/index.js in May 2026. The old config
  // used integrationFolder/pluginsFile/supportFile keys that Cypress removed
  // in v10; everything now lives under the `e2e` block with the
  // setupNodeEvents hook replacing plugins/index.js.
  video: false,
  // Bulma's navbar collapses behind the hamburger below 1024px. The
  // default Cypress viewport (1000x660) lands in mobile mode, which hides
  // the navbar items (logout, folder-switcher) our specs assert on. Pin a
  // desktop viewport so the navbar renders as a real admin sees it.
  viewportWidth: 1280,
  viewportHeight: 800,
  fixturesFolder: 'tests/frontend/e2e/fixtures',
  screenshotsFolder: 'tests/frontend/e2e/screenshots',
  videosFolder: 'tests/frontend/e2e/videos',
  downloadsFolder: 'tests/frontend/e2e/downloads',

  e2e: {
    baseUrl: 'http://localhost:8081',
    // The forced-admin-MFA-setup run sets FILEGATOR_E2E_MFA_REQUIRED=1 and
    // exercises only specs-mfa-required/ (which lives outside specs/ so the
    // default run never picks it up). Selecting specs here — rather than via a
    // CLI --spec glob — keeps the globbing inside Cypress; a shell-passed
    // `**` glob gets mangled by non-globstar shells and matches nothing.
    specPattern: process.env.FILEGATOR_E2E_MFA_REQUIRED === '1'
      ? 'tests/frontend/e2e/specs-mfa-required/**/*.cy.{js,jsx,ts,tsx}'
      : 'tests/frontend/e2e/specs/**/*.cy.{js,jsx,ts,tsx}',
    supportFile: 'tests/frontend/e2e/support/e2e.js',
    setupNodeEvents(on, config) {
      // No custom tasks yet. Keep the hook in place — node-side helpers
      // (db reset, fixture seeding) hook in here when we need them.
      return config
    },
  },
})
