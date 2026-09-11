<script setup lang="ts">
// Placeholder shown while a game grid's first load is in flight, sized to
// match GameCard.vue exactly (same min-height, radius, shadow) so the real
// cards don't visibly jump into a different layout once they arrive -
// only the content inside changes.
withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })
</script>

<template>
  <div class="skeleton-cover" :class="{ compact }">
    <div class="skeleton-lines">
      <span class="skeleton-line title" />
      <span class="skeleton-line meta" />
    </div>
  </div>
</template>

<style scoped>
.skeleton-cover {
  position: relative;
  height: 100%;
  min-height: 190px;
  border-radius: var(--radius);
  box-shadow: var(--shadow-card);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: var(--space-3);
  gap: var(--space-2);
  background: var(--color-surface-hover);
}

/* The moving highlight is a separate absolutely-positioned layer
animated with `transform` (compositor-only, no layout/paint per frame)
instead of animating `background-position` on the card itself - with up
to 8 of these shimmering at once during a real load, background-position
repaints every frame and was measurably competing with the browser for
the same main thread that's decoding the real cover photos arriving
behind it, making them visibly slower to appear (found live: the actual
API response was already back fast, only the photos lagged). */
.skeleton-cover::after {
  content: '';
  position: absolute;
  inset: 0;
  width: 60%;
  background: linear-gradient(
    100deg,
    transparent 0%,
    var(--color-border-strong) 50%,
    transparent 100%
  );
  transform: translateX(-150%);
  animation: skeleton-shimmer 1.4s ease-in-out infinite;
  will-change: transform;
}

.skeleton-cover.compact {
  min-height: 120px;
}

.skeleton-lines {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

/* The shimmer lines are opaque against the shimmering background behind
them (not another gradient of their own) - two moving gradients at
different speeds read as flickering, not as a calm "still loading". */
.skeleton-line {
  display: block;
  height: 10px;
  border-radius: 4px;
  background: var(--color-surface);
  opacity: 0.6;
}

.skeleton-line.title {
  width: 65%;
}

.skeleton-line.meta {
  width: 40%;
  height: 8px;
}

@keyframes skeleton-shimmer {
  0% {
    transform: translateX(-150%);
  }
  100% {
    transform: translateX(250%);
  }
}

@media (prefers-reduced-motion: reduce) {
  .skeleton-cover::after {
    animation: none;
    display: none;
  }
}
</style>
