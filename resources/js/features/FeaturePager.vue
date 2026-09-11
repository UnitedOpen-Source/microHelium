<script setup>
defineProps({ meta: Object, loading: Boolean });
defineEmits(['page']);
</script>
<template>
    <nav v-if="meta && meta.last_page > 1" aria-label="Paginação" class="feature-actions">
        <button type="button" class="button-secondary" :disabled="loading || meta.current_page <= 1" @click="$emit('page', meta.current_page - 1)">Anterior</button>
        <span role="status">Página {{ meta.current_page }} de {{ meta.last_page }}</span>
        <button type="button" class="button-secondary" :disabled="loading || meta.current_page >= meta.last_page" @click="$emit('page', meta.current_page + 1)">Próxima</button>
    </nav>
</template>
