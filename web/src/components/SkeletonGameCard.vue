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

  background: linear-gradient(
    100deg,
    var(--color-surface-hover) 30%,
    var(--color-border-strong) 50%,
    var(--color-surface-hover) 70%
  );
  background-size: 200% 100%;
  animation: skeleton-shimmer 1.4s ease-in-out infinite;
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
    background-position: 150% 0;
  }
  100% {
    background-position: -50% 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .skeleton-cover {
    animation: none;
    background-position: 50% 0;
  }
}
</style>
