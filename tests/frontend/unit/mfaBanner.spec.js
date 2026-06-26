import {
  isMfaNudgeDismissed,
  markMfaNudgeDismissed,
  resetMfaNudgeDismissals,
} from '@/utils/mfaBanner'

// jsdom provides a working window.localStorage; clear it between tests.
beforeEach(() => {
  window.localStorage.clear()
})

describe('mfaBanner dismissal state', () => {
  it('reports not-dismissed for a user with nothing stored', () => {
    expect(isMfaNudgeDismissed('alice')).toBe(false)
  })

  it('round-trips a per-user dismissal', () => {
    markMfaNudgeDismissed('alice')
    expect(isMfaNudgeDismissed('alice')).toBe(true)
    // Dismissal is scoped to the user that closed it.
    expect(isMfaNudgeDismissed('bob')).toBe(false)
  })

  it('treats a missing username the same as an empty one', () => {
    markMfaNudgeDismissed(undefined)
    expect(isMfaNudgeDismissed(undefined)).toBe(true)
    expect(isMfaNudgeDismissed('')).toBe(true)
  })

  it('reset clears every stored dismissal so the banner returns next login', () => {
    markMfaNudgeDismissed('alice')
    markMfaNudgeDismissed('bob')

    resetMfaNudgeDismissals()

    expect(isMfaNudgeDismissed('alice')).toBe(false)
    expect(isMfaNudgeDismissed('bob')).toBe(false)
  })

  it('reset leaves unrelated localStorage keys untouched', () => {
    window.localStorage.setItem('locale', 'en')
    markMfaNudgeDismissed('alice')

    resetMfaNudgeDismissals()

    expect(window.localStorage.getItem('locale')).toBe('en')
  })

  it('degrades gracefully when localStorage is unavailable', () => {
    const original = window.localStorage
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      get() { throw new Error('SecurityError: storage disabled') },
    })

    // Show the banner (not-dismissed) and never throw.
    expect(isMfaNudgeDismissed('alice')).toBe(false)
    expect(() => markMfaNudgeDismissed('alice')).not.toThrow()
    expect(() => resetMfaNudgeDismissals()).not.toThrow()

    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      value: original,
    })
  })
})
