<script setup>
import { computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useImportNotice } from '../stores/importNotice';

const route = useRoute();
const imports = useImportNotice();
const notice = computed(() => imports.notice?.returnTo === route.fullPath ? imports.notice : null);

watch(() => route.fullPath, (path, previous) => {
  if (previous === imports.notice?.returnTo && path !== previous) imports.clear();
});
</script>

<template>
  <section v-if="notice" class="alert import-notice" role="alert" aria-label="Resultado de importación">
    <div>
      <strong>No se pudo cargar {{ notice.resource }}</strong>
      <p><template v-if="notice.code">{{ notice.code }} · </template>{{ notice.message }}</p>
      <small v-if="notice.importId">Importación #{{ notice.importId }}</small>
    </div>
    <button class="btn muted" aria-label="Cerrar mensaje de importación" @click="imports.clear">Cerrar</button>
  </section>
</template>

<style scoped>
.import-notice { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
.import-notice > div { min-width: 0; overflow-wrap: anywhere; }
.import-notice p { margin: 8px 0; }
.import-notice button { flex-shrink: 0; }
@media (max-width: 560px) { .import-notice { flex-direction: column; } }
</style>
