import { ref } from 'vue'

const STORAGE_KEY = 'ludodex-collection-density'

export type Density = 'comfortable' | 'compact'

// Unlike useTheme, this is a fresh ref per call rather than a module-level
// singleton: nothing needs it to stay in sync while both views are mounted
// at once (they're separate routes), and reading localStorage on each
// mount is what makes toggling it in one view show up correctly the next
// time the other one loads.
export function useCollectionDensity() {
  const density = ref<Density>(localStorage.getItem(STORAGE_KEY) === 'compact' ? 'compact' : 'comfortable')

  function toggle() {
    density.value = density.value === 'compact' ? 'comfortable' : 'compact'
    localStorage.setItem(STORAGE_KEY, density.value)
  }

  return { density, toggle }
}
