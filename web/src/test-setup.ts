// Pins the test environment's language so i18n/index.ts's own
// navigator.language fallback (used only when no locale is stored yet)
// resolves deterministically - jsdom/Node's own default tracks the host's
// OS/CI locale (English on GitHub Actions runners), and several tests
// assert hardcoded Spanish text against the very first render, before any
// test gets a chance to store an explicit locale itself. Found running
// the suite locally (Spanish Windows) right after this fallback was
// added - it still passed here, but would have broken in CI.
Object.defineProperty(window.navigator, 'language', {
  value: 'es-ES',
  configurable: true,
})

// jsdom doesn't implement matchMedia - anything that touches useTheme.ts
// (even transitively, e.g. mounting App.vue or PoweredByBgg.vue) crashes
// without this, since its initial theme detection calls it at module load
// time, before any test gets a chance to mock it itself.
if (!window.matchMedia) {
  window.matchMedia = (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })
}
