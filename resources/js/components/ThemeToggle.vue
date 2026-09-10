<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Sun, Moon } from '@lucide/vue'

const isDark = ref(false)

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark')
    applyTheme()
})

function applyTheme() {
    if (isDark.value) {
        document.documentElement.classList.add('dark')
    } else {
        document.documentElement.classList.remove('dark')
    }
}

function toggleTheme() {
    isDark.value = !isDark.value
    try { localStorage.setItem('theme', isDark.value ? 'dark' : 'light') } catch (_) { /* Theme remains usable without storage. */ }
    applyTheme()
}
</script>

<template>
    <button
        @click="toggleTheme"
        type="button" class="icon-button" :aria-label="isDark ? 'Ativar modo claro' : 'Ativar modo escuro'" :aria-pressed="isDark"
        :title="isDark ? 'Modo Claro' : 'Modo Escuro'"
    >
        <Sun v-if="isDark" class="h-5 w-5" />
        <Moon v-else class="h-5 w-5" />
    </button>
</template>
