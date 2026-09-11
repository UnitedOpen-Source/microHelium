<script setup>
defineProps({ loading: Boolean, error: Object, busy: Boolean, notice: String, actionError: Object });
defineEmits(['retry']);
</script>
<template>
    <p v-if="loading" role="status" class="feature-message">Carregando informações…</p>
    <div v-if="error" class="surface feature-empty" role="alert">
        <h2>{{ error.status === 403 ? 'Acesso restrito' : 'Não foi possível carregar' }}</h2>
        <p>{{ error.message }}</p>
        <a v-if="error.status === 401" href="/login" class="button-primary">Entrar novamente</a>
        <button v-else-if="error.status !== 403" class="button-secondary" type="button" @click="$emit('retry')">Tentar novamente</button>
        <a v-else href="/ajuda" class="button-secondary">Consultar ajuda</a>
    </div>
    <div v-if="actionError" data-feature-action-error tabindex="-1" class="feature-message feature-error" role="alert">
        <p>{{ actionError.message }}</p>
        <ul v-if="Object.keys(actionError.errors || {}).length"><li v-for="(messages, field) in actionError.errors" :key="field">{{ Array.isArray(messages) ? messages.join(' ') : messages }}</li></ul>
    </div>
    <p v-if="busy" role="status" class="feature-message">Enviando… Aguarde a confirmação.</p>
    <p v-if="notice" role="status" class="feature-message feature-success">{{ notice }}</p>
</template>
