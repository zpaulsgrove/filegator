#!/usr/bin/env node
/*
 * E2E orchestration entrypoint.
 *
 * Swaps the test-only seam config (fixtures/configuration.e2e.php) into
 * place as ./configuration.php for the duration of the inner command,
 * then restores whatever was there before — so a developer's real
 * configuration.php is never clobbered, and a clean checkout (CI) is a
 * no-op restore.
 *
 * Usage (from an npm script, which puts node_modules/.bin on PATH):
 *   node tests/frontend/e2e/support/with-e2e-config.js <cmd> [args...]
 *
 * The inner command is run with FILEGATOR_E2E=1 so the seam config's
 * runtime guard allows boot. Restore runs on normal exit AND on
 * SIGINT/SIGTERM, so Ctrl-C during an interactive run still cleans up.
 * A hard SIGKILL cannot be trapped — if that happens, restore your
 * config from configuration.php.bak.
 *
 * Localhost / CI only.
 */

const fs = require('fs')
const path = require('path')
const { spawn } = require('child_process')

const repoRoot = path.resolve(__dirname, '..', '..', '..', '..')
const target = path.join(repoRoot, 'configuration.php')
const backup = path.join(repoRoot, 'configuration.php.bak')
const seam = path.join(repoRoot, 'tests', 'frontend', 'e2e', 'fixtures', 'configuration.e2e.php')

const hadConfig = fs.existsSync(target)
let restored = false

function restore() {
  if (restored) return
  restored = true
  try {
    if (hadConfig) {
      if (fs.existsSync(backup)) {
        fs.copyFileSync(backup, target)
        fs.unlinkSync(backup)
      }
    } else if (fs.existsSync(target)) {
      // No pre-existing config (clean checkout / CI): remove the seam copy.
      fs.unlinkSync(target)
    }
  } catch (err) {
    // Best-effort — surface but don't mask the inner command's exit code.
    process.stderr.write(`[with-e2e-config] restore failed: ${err.message}\n`)
  }
}

function swapIn() {
  if (!fs.existsSync(seam)) {
    throw new Error(`seam config not found: ${seam}`)
  }
  if (hadConfig) {
    fs.copyFileSync(target, backup)
  }
  fs.copyFileSync(seam, target)
}

const [cmd, ...args] = process.argv.slice(2)
if (!cmd) {
  process.stderr.write('[with-e2e-config] no inner command provided\n')
  process.exit(2)
}

swapIn()

const child = spawn(cmd, args, {
  stdio: 'inherit',
  shell: false,
  env: { ...process.env, FILEGATOR_E2E: '1' },
})

// Restore synchronously on our own exit (covers normal completion).
process.on('exit', restore)
// Forward signals so the child tears down, then restore via 'exit'.
;['SIGINT', 'SIGTERM'].forEach(sig => {
  process.on(sig, () => {
    try { child.kill(sig) } catch (e) { /* already gone */ }
  })
})

child.on('exit', (code, signal) => {
  restore()
  if (signal) {
    process.kill(process.pid, signal)
  } else {
    process.exit(code === null ? 1 : code)
  }
})

child.on('error', err => {
  process.stderr.write(`[with-e2e-config] failed to start "${cmd}": ${err.message}\n`)
  restore()
  process.exit(1)
})
