import { ref } from 'vue'

export type Theme = 'light' | 'dark'

const STORAGE_KEY = 'ludodex-theme'

// The inline script in index.html already stamps data-theme on <html>
// before Vue mounts (avoids a flash of the wrong theme) whenever there's an
// explicit stored choice. Without one, data-theme is intentionally absent -
// base.css's prefers-color-scheme default is what's actually rendered, so
// the toggle's initial state must match *that*, not just assume 'dark',
// or the very first click would silently no-op (setting the theme to what
// was already showing) while flipping the icon to the wrong one.
function initialTheme(): Theme {
  const stored = document.documentElement.getAttribute('data-theme')

  if (stored === 'light' || stored === 'dark') {
    return stored
  }

  return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
}

const theme = ref<Theme>(initialTheme())

function apply(value: Theme) {
  theme.value = value
  document.documentElement.setAttribute('data-theme', value)
  localStorage.setItem(STORAGE_KEY, value)
}

export function useTheme() {
  function toggle() {
    apply(theme.value === 'dark' ? 'light' : 'dark')
  }

  return { theme, toggle }
}
