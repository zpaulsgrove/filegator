const { defineConfig } = require('cypress')

module.exports = defineConfig({
  // FileGator E2E config for Cypress 14+.
  //
  // Migrated from cypress.json + plugins/index.js in May 2026. The old config
  // used integrationFolder/pluginsFile/supportFile keys that Cypress removed
  // in v10; everything now lives under the `e2e` block with the
  // setupNodeEvents hook replacing plugins/index.js.
  video: false,
  fixturesFolder: 'tests/frontend/e2e/fixtures',
  screenshotsFolder: 'tests/frontend/e2e/screenshots',
  videosFolder: 'tests/frontend/e2e/videos',
  downloadsFolder: 'tests/frontend/e2e/downloads',

  e2e: {
    baseUrl: 'http://localhost:8081',
    specPattern: 'tests/frontend/e2e/specs/**/*.cy.{js,jsx,ts,tsx}',
    supportFile: 'tests/frontend/e2e/support/e2e.js',
    setupNodeEvents(on, config) {
      // No custom tasks yet. Keep the hook in place — node-side helpers
      // (db reset, fixture seeding) hook in here when we need them.
      return config
    },
  },
})
