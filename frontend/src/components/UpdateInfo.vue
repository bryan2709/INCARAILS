<script setup>
import { computed } from 'vue';
import { formatDateTime } from '../lib/playerProfile';
import { useDetailNavigation } from '../composables/useDetailNavigation';

const { detailLink } = useDetailNavigation('/competitions');

const props = defineProps({
  lastUpdated: String,
  sourceUpdated: String,
  busy: Boolean,
  canRefresh: Boolean,
  job: Object,
  status: String,
  feedback: String,
  snapshots: { type: Array, default: () => [] },
  emptySnapshotText: { type: String, default: 'No hay datos de snapshot disponibles.' },
});
defineEmits(['refresh', 'reload']);

const activeStatuses = ['pending', 'processing', 'waiting_worker', 'waiting_rate_limit'];
const statusLabels = {
  pending: 'Pendiente',
  processing: 'Importando',
  waiting_worker: 'Esperando worker',
  waiting_rate_limit: 'Esperando cuota del proveedor',
  completed: 'Completada',
  published: 'Publicado',
  failed: 'Fallida',
  not_imported: 'Sin importar',
  available: 'Datos disponibles',
};
const importStatus = computed(() => props.job?.status || props.status
  || (props.snapshots.length ? 'published' : props.lastUpdated ? 'available' : 'not_imported'));
const statusLabel = computed(() => statusLabels[importStatus.value] || importStatus.value);
const importing = computed(() => activeStatuses.includes(importStatus.value));
</script>

<template>
  <section class="panel update-info" aria-label="Actualización y publicación">
    <div class="update-heading">
      <h2>Actualización y publicación</h2>
      <div class="update-actions">
        <button class="btn" :disabled="busy" @click="$emit(canRefresh ? 'refresh' : 'reload')">{{ busy ? 'Actualizando…' : canRefresh ? '↻ Refrescar información' : '↻ Recargar información' }}</button>
        <slot name="actions" />
      </div>
    </div>

    <dl class="update-facts">
      <div>
        <dt>Última actualización</dt>
        <dd><time v-if="lastUpdated" :datetime="lastUpdated">{{ formatDateTime(lastUpdated) }}</time><span v-else>No disponible</span></dd>
      </div>
      <div v-if="sourceUpdated">
        <dt>Actualización del proveedor</dt>
        <dd><time :datetime="sourceUpdated">{{ formatDateTime(sourceUpdated) }}</time></dd>
      </div>
      <div>
        <dt>Estado de importación</dt>
        <dd><span class="pill status-pill" :class="{ good: ['completed', 'published', 'available'].includes(importStatus), failed: importStatus === 'failed', pending: importing }" role="status">{{ statusLabel }}</span></dd>
      </div>
    </dl>

    <p v-if="importStatus === 'waiting_worker'" class="tip" role="status">Actualización pendiente; esperando un worker disponible.</p>
    <p v-else-if="importStatus === 'waiting_rate_limit'" class="tip" role="status">Actualización pendiente; esperando la cuota del proveedor.</p>
    <p v-else-if="importing" class="tip" role="status">Actualizando en segundo plano. Los datos aparecerán al finalizar.</p>
    <p v-if="importStatus === 'failed'" class="alert" role="alert"><template v-if="job?.error_code">{{ job.error_code }} · </template>{{ job?.error_message || 'No se pudo actualizar la información. Puedes volver a intentarlo.' }}</p>
    <p v-if="feedback" class="alert" role="alert">{{ feedback }}</p>

    <div class="publication-info">
      <h3>Datos del snapshot</h3>
      <ul v-if="snapshots.length" class="snapshot-list">
        <li v-for="snapshot in snapshots" :key="snapshot.id">
          <div class="snapshot-heading">
            <strong>Snapshot publicado #{{ snapshot.id }}</strong>
            <RouterLink v-if="snapshot.competition" :to="detailLink('/competitions/' + snapshot.competition.id)">{{ snapshot.competition.name }}</RouterLink>
          </div>
          <p v-if="snapshot.season" class="tip">Temporada publicada: {{ snapshot.season.start_date || 'No especificada' }} — {{ snapshot.season.end_date || '—' }}.</p>
          <p v-if="snapshot.published_at" class="tip">Publicado el <time :datetime="snapshot.published_at">{{ formatDateTime(snapshot.published_at) }}</time>.</p>
          <p v-if="snapshot.teams_count != null && snapshot.players_count != null" class="tip">{{ snapshot.teams_count }} equipos · {{ snapshot.players_count }} jugadores</p>
        </li>
      </ul>
      <p v-else class="tip">{{ emptySnapshotText }}</p>
    </div>
    <div v-if="$slots.default" class="publication-notices"><slot /></div>
  </section>
</template>

<style scoped>
.update-info { margin: 22px 0; }
.update-heading, .update-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.update-heading { justify-content: space-between; }
.update-heading h2 { margin: 0; }
.update-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 18px; margin: 22px 0; }
.update-facts dt { color: #728199; font-size: .78rem; margin-bottom: 8px; }
.update-facts dd { margin: 0; font-size: .87rem; font-weight: 600; }
.status-pill { display: inline-block; }
.status-pill.failed { background: #fff1ed; color: #b3402b; }
.status-pill.pending { background: #fff5df; color: #926718; }
.publication-info { border-top: 1px solid #e8edf2; padding-top: 18px; }
.publication-info h3 { font-size: .85rem; margin-bottom: 12px; }
.snapshot-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.snapshot-list li { border: 1px solid #e3eaf0; border-radius: 12px; padding: 14px; background: #f8fbfe; }
.snapshot-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: .82rem; }
.snapshot-heading a { font-size: .78rem; }
.update-info .tip { margin: 6px 0 0; }
.publication-notices { margin-top: 16px; }
@media (max-width: 560px) {
  .update-facts { grid-template-columns: 1fr; }
  .update-actions { width: 100%; }
}
</style>
