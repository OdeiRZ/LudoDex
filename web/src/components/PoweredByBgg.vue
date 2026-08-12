<script setup lang="ts">
import { computed } from 'vue'
import { useTheme } from '@/composables/useTheme'
import logoLight from '@/assets/powered-by-bgg-rgb.svg'
import logoDark from '@/assets/powered-by-bgg-reversed-rgb.svg'

// Required by BGG's XML API terms of use for any public-facing app: the
// logo must link back to BGG and stay legible - the "reversed" (white
// text) variant they provide only reads on a dark background, so it has
// to follow the app's own theme instead of picking one variant forever.
const { theme } = useTheme()
const logo = computed(() => (theme.value === 'dark' ? logoDark : logoLight))
</script>

<template>
  <a href="https://boardgamegeek.com/" target="_blank" rel="noopener noreferrer" class="powered-by-bgg">
    <img :src="logo" alt="Powered by BGG" />
  </a>
</template>

<style scoped>
.powered-by-bgg {
  display: inline-flex;
}

.powered-by-bgg img {
  display: block;
  height: 22px;
  width: auto;
}
</style>
