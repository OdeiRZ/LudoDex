<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const visible = ref(false)

// A real scroll distance, not "any scroll at all" - keeps the button from
// flickering in and out on a page barely taller than the viewport, where a
// couple of scrolled pixels wouldn't actually save the user anything worth
// a dedicated control for.
const SHOW_AFTER_PX = 400

function onScroll() {
  visible.value = window.scrollY > SHOW_AFTER_PX
}

function scrollToTop() {
  window.scrollTo({
    top: 0,
    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
  })
}

onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  onScroll()
})

onUnmounted(() => {
  window.removeEventListener('scroll', onScroll)
})
</script>

<template>
  <Transition name="scroll-to-top">
    <button
      v-if="visible"
      type="button"
      class="scroll-to-top"
      :aria-label="t('common.scrollToTop')"
      :title="t('common.scrollToTop')"
      @click="scrollToTop"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5M5 12l7-7 7 7" />
      </svg>
    </button>
  </Transition>
</template>

<style scoped>
.scroll-to-top {
  position: fixed;
  right: var(--space-4);
  bottom: var(--space-4);
  z-index: 20;
  width: 44px;
  height: 44px;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-pill);
  border: none;
  background: var(--color-primary);
  color: #fff;
  box-shadow: var(--shadow-card);
  cursor: pointer;
  transition:
    background-color 0.15s ease,
    transform 0.12s ease;
}

.scroll-to-top:hover {
  background: var(--color-primary-hover);
}

.scroll-to-top:active {
  transform: scale(0.9);
}

.scroll-to-top svg {
  width: 22px;
  height: 22px;
}

.scroll-to-top-enter-active {
  animation: scroll-to-top-in 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.scroll-to-top-leave-active {
  animation: scroll-to-top-out 0.18s ease forwards;
}

@keyframes scroll-to-top-in {
  from {
    opacity: 0;
    transform: scale(0.5) translateY(8px);
  }
}

@keyframes scroll-to-top-out {
  to {
    opacity: 0;
    transform: scale(0.5) translateY(8px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .scroll-to-top {
    transition: none;
  }

  .scroll-to-top:active {
    transform: none;
  }

  .scroll-to-top-enter-active,
  .scroll-to-top-leave-active {
    animation: none;
  }
}

/* Same env(safe-area-inset-bottom) clearance as any fixed control near the
bottom edge on iOS, so it never sits under the home-indicator area. */
@media (max-width: 480px) {
  .scroll-to-top {
    right: var(--space-3);
    bottom: calc(var(--space-3) + env(safe-area-inset-bottom, 0px));
  }
}
</style>
