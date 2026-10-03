<script setup lang="ts">
import { useTheme, THEME_COLOR } from '@/composables/useTheme'
import { useFeedback } from '@/composables/useFeedback'

const { theme, toggle } = useTheme()
const feedback = useFeedback()

// Barrido circular "amanecer/atardecer" desde el propio botón, en vez de un
// cambio de tema instantáneo.
//
// Dos intentos anteriores usaron la View Transitions API (recorte circular
// de `::view-transition-new(root)` vía Web Animations API) y, probados en
// Android real, resultaron frágiles de dos formas distintas: 1) la API
// tiene que capturar una foto de toda la pantalla por dentro antes de poder
// animar nada, lo que a veces introducía un retraso perceptible donde no
// pasaba nada en absoluto antes del barrido; 2) las coordenadas del centro
// (tanto en píxeles como en vw/vh) no siempre coincidían con las del propio
// botón - el círculo nacía desplazado hacia arriba en una pestaña normal
// de móvil, sin que lograra aislar ni arreglar la causa exacta con certeza
// en dos rondas de cambios.
//
// Esta versión no usa la View Transitions API en absoluto: un <div> normal
// con `clip-path`, sin ninguna captura de pantalla de por medio, usa
// exactamente el mismo sistema de coordenadas que `getBoundingClientRect()`
// del propio botón - cero ambigüedad posible sobre dónde nace el círculo -
// y es instantáneo (solo crea un elemento y lo anima, nada que capturar).
// El círculo es del color sólido del tema AL QUE SE VA (`THEME_COLOR`, el
// mismo origen que ya usa useTheme.ts para `theme-color-override`) y crece
// desde el botón hasta cubrir toda la pantalla; el cambio de tema real se
// aplica justo al terminar, cuando el círculo ya la cubre entera, así que
// el "cambio" por debajo es invisible. Mismo patrón que el repo hermano
// PequeDex.
function onToggle(event: MouseEvent) {
  feedback.theme()

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reducedMotion) {
    toggle()
    return
  }

  const button = event.currentTarget as HTMLElement
  const rect = button.getBoundingClientRect()
  const x = rect.left + rect.width / 2
  const y = rect.top + rect.height / 2
  const endRadius = Math.hypot(
    Math.max(x, window.innerWidth - x),
    Math.max(y, window.innerHeight - y),
  )

  const nextTheme = theme.value === 'dark' ? 'light' : 'dark'
  const overlay = document.createElement('div')
  overlay.style.cssText = `position:fixed;inset:0;z-index:9999;pointer-events:none;background:${THEME_COLOR[nextTheme]}`
  document.body.appendChild(overlay)

  const sweep = overlay.animate(
    { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${endRadius}px at ${x}px ${y}px)`] },
    { duration: 500, easing: 'ease-in' },
  )

  void sweep.finished
    .catch(() => {})
    .then(() => {
      toggle()
      overlay.remove()
    })
}
</script>

<template>
  <button
    type="button"
    class="theme-toggle"
    :aria-label="theme === 'dark' ? $t('theme.toLight') : $t('theme.toDark')"
    :title="theme === 'dark' ? $t('theme.toLight') : $t('theme.toDark')"
    @click="onToggle($event)"
  >
    <!-- Icon shows the mode a click leads to, not the current one - matches
    the aria-label/title above (e.g. in dark mode the label reads "switch to
    light", so the icon shown is the sun, not the moon). Wrapped in a
    Transition so switching reads as sun<->moon actually swapping places
    (rotate + cross-fade) instead of one icon just replacing the other
    mid-frame. -->
    <Transition name="theme-icon" mode="out-in">
      <svg
        v-if="theme === 'dark'"
        key="sun"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
        <path
          stroke-linecap="round"
          d="M12 2v2.5M12 19.5V22M4.22 4.22l1.77 1.77M18.01 18.01l1.77 1.77M2 12h2.5M19.5 12H22M4.22 19.78l1.77-1.77M18.01 5.99l1.77-1.77"
        />
      </svg>
      <svg v-else key="moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9Z" />
      </svg>
    </Transition>
  </button>
</template>

<style scoped>
.theme-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text);
  transition:
    background-color 0.15s ease,
    transform 0.12s ease;
}

.theme-toggle:hover,
.theme-toggle:active {
  background: var(--color-surface-hover);
}

.theme-toggle:active {
  transform: scale(0.9);
}

.theme-toggle svg {
  width: 18px;
  height: 18px;
}

.theme-icon-enter-active,
.theme-icon-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.theme-icon-enter-from {
  opacity: 0;
  transform: rotate(-90deg) scale(0.4);
}

.theme-icon-leave-to {
  opacity: 0;
  transform: rotate(90deg) scale(0.4);
}

@media (prefers-reduced-motion: reduce) {
  .theme-toggle {
    transition: none;
  }

  .theme-toggle:active {
    transform: none;
  }

  .theme-icon-enter-active,
  .theme-icon-leave-active {
    transition: opacity 0.1s ease;
  }

  .theme-icon-enter-from,
  .theme-icon-leave-to {
    transform: none;
  }
}
</style>
