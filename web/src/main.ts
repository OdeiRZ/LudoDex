// Fuentes servidas con la app (la CSP solo admite font-src 'self'): Inter para
// el texto y Bricolage Grotesque para títulos y nombres de juego.
import '@fontsource-variable/inter'
import '@fontsource-variable/bricolage-grotesque'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { i18n } from './i18n'
import { vPress } from './directives/press'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)
app.directive('press', vPress)

app.mount('#app')

// Registered purely for "Add to Home Screen" installability (see
// public/sw.js) - not for offline support, so failures here are harmless.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {})
  })
}
