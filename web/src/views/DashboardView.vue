<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

onMounted(() => {
  if (!auth.user) {
    auth.fetchCurrentUser()
  }
})
</script>

<template>
  <div>
    <h1>Tu colección</h1>
    <p v-if="auth.user">Hola, {{ auth.user.name }}. Aquí irá tu inventario de juegos.</p>
  </div>
</template>
