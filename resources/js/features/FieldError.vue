<script setup>
defineProps({ error: Object, name: String });
</script>
<template><p :hidden="!error?.errors?.[name]" :id="`${name}-error`" class="field-error">{{ error?.errors?.[name]?.join(' ') }}</p></template>
