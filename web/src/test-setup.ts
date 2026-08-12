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
