<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useImportRedirect } from '../composables/useImportRedirect';
import { api } from '../lib/api';
import { useSession } from '../stores/session';
import StateBox from '../components/StateBox.vue';
import UpdateInfo from '../components/UpdateInfo.vue';

const route = useRoute();
const { back, detailLink, returnToOrigin: redirectUnavailable } = useImportRedirect('/competitions', 'la competición', 'El proveedor no devolvió información de esta competición.');
const session = useSession();
const entry = ref(null);
const job = ref(null);
const loading = ref(true);
const error = ref('');
const feedback = ref('');
const search = ref('');
const actionBusy = ref(false);
const activeStatuses = ['pending', 'processing', 'waiting_worker', 'waiting_rate_limit'];
let timer = null;
let polling = false;
let scope = 0;
let controller = null;

const competition = computed(() => entry.value?.competition);
const snapshot = computed(() => entry.value?.snapshot);
const teamsWithoutPlayers = computed(() =>
  (snapshot.value?.quality_summary?.missing_squads_count || 0)
  + (snapshot.value?.quality_summary?.empty_squads_count || 0),
);
const canManage = computed(() => session.can('football.manage'));
const importing = computed(() => activeStatuses.includes(job.value?.status));
const teams = computed(() => {
  const term = search.value.trim().toLocaleLowerCase();
  return (snapshot.value?.teams || []).filter(team =>
    !term || [team.name, team.short_name, team.tla].some(value => value?.toLocaleLowerCase().includes(term)),
  );
});

function stopPolling() {
  polling = false;
  if (timer !== null) clearTimeout(timer);
  timer = null;
}

function schedulePoll(context) {
  if (!polling || context !== scope || timer !== null) return;
  timer = setTimeout(() => poll(context), 3000);
}

function startPolling(context) {
  if (context !== scope) return;
  polling = true;
  schedulePoll(context);
}

async function returnToOrigin(failure) {
  stopPolling();
  await redirectUnavailable(failure);
}

async function fetchData(context) {
  loading.value = true;
  error.value = '';
  try {
    const result = await api.get('/competitions/' + route.params.id, { signal: controller.signal });
    if (context !== scope) return;
    entry.value = result.data;
    job.value = result.data?.import;
    if (!snapshot.value && job.value?.status === 'failed') {
      await returnToOrigin(job.value);
      return;
    }
    if (!competition.value && !importing.value) {
      await returnToOrigin();
      return;
    }
    if (importing.value) startPolling(context);
    else stopPolling();
  } catch (failure) {
    if (context !== scope) return;
    if (!snapshot.value && [403, 404, 503].includes(failure.status)) await returnToOrigin(failure);
    else error.value = failure.message;
  } finally {
    if (context === scope) loading.value = false;
  }
}

async function poll(context) {
  timer = null;
  try {
    if (context !== scope || !job.value) return;
    const result = await api.get('/imports/' + job.value.id, { signal: controller.signal });
    if (context !== scope) return;
    job.value = result.data;
    feedback.value = '';
    if (['completed', 'failed'].includes(job.value.status)) {
      stopPolling();
      if (job.value.status === 'failed' && !snapshot.value) {
        await returnToOrigin(job.value);
        return;
      }
      await fetchData(context);
      return;
    }
  } catch (failure) {
    if (context === scope) feedback.value = failure.message;
  }
  schedulePoll(context);
}

async function mutateImport(path) {
  if (actionBusy.value || importing.value) return;
  const context = scope;
  actionBusy.value = true;
  error.value = '';
  feedback.value = '';
  try {
    const result = await api.post(path, {}, { signal: controller.signal });
    if (context !== scope) return;
    job.value = result.data;
    startPolling(context);
  } catch (failure) {
    if (context === scope) error.value = failure.message;
  } finally {
    if (context === scope) actionBusy.value = false;
  }
}

watch(() => route.params.id, () => {
  controller?.abort();
  stopPolling();
  scope += 1;
  controller = new AbortController();
  entry.value = null;
  job.value = null;
  search.value = '';
  feedback.value = '';
  actionBusy.value = false;
  if (route.path === '/competitions/' + route.params.id) fetchData(scope);
}, { immediate: true });

onUnmounted(() => {
  scope += 1;
  controller?.abort();
  stopPolling();
});
</script>

<template>
  <RouterLink class="back" :to="back.to">← {{ back.label }}</RouterLink>
  <StateBox :loading="loading" :error="error" />
  <template v-if="competition">
    <div class="hero">
      <img v-if="competition.emblem" :src="competition.emblem" alt="" />
      <div>
        <p class="eyebrow">{{ competition.type }} · {{ competition.code }}</p>
        <h1>{{ competition.name }}</h1>
        <p class="sub">ID externo {{ competition.id }} · Temporada actual: {{ competition.season?.start_date || 'No especificada' }} — {{ competition.season?.end_date || '—' }}</p>
      </div>
    </div>

    <UpdateInfo
      :last-updated="snapshot?.published_at || competition.last_updated_at"
      :source-updated="competition.source_updated_at"
      :busy="loading || actionBusy || importing"
      :can-refresh="canManage"
      :job="job"
      :status="competition.import_status"
      :snapshots="snapshot ? [snapshot] : []"
      :feedback="feedback"
      empty-snapshot-text="No existe todavía una versión publicada de esta competición."
      @refresh="mutateImport('/competitions/' + competition.id + '/refresh')"
      @reload="fetchData(scope)"
    >
      <template #actions>
        <button v-if="canManage && job?.status === 'failed'" class="btn" :disabled="actionBusy || loading" @click="mutateImport('/imports/' + job.id + '/retry')">Reintentar importación fallida</button>
      </template>
      <template v-if="competition.needs_refresh || teamsWithoutPlayers" #default>
        <p v-if="competition.needs_refresh" class="alert">Estos datos corresponden a una temporada anterior. Actualiza la competición para importar la temporada actual.</p>
        <p v-if="teamsWithoutPlayers" class="tip">El proveedor no entregó jugadores para {{ teamsWithoutPlayers }} {{ teamsWithoutPlayers === 1 ? 'equipo' : 'equipos' }}. Puedes consultar la información de todos los clubes.</p>
      </template>
    </UpdateInfo>

    <section v-if="snapshot" class="panel" aria-labelledby="competition-teams-heading">
      <div class="panel-head roster-heading">
        <h2 id="competition-teams-heading">Equipos de la competición</h2>
        <span class="metric">{{ teams.length }} equipos</span>
      </div>
      <p class="sub">Abre la ficha de un equipo para ver su información y plantilla.</p>
      <div class="toolbar">
        <input v-model="search" type="search" aria-label="Buscar equipo" placeholder="Buscar equipo…" />
      </div>
      <ul v-if="teams.length" class="cards team-list" aria-labelledby="competition-teams-heading">
        <li v-for="team in teams" :key="team.id">
          <RouterLink :to="detailLink('/team/' + team.id)" class="competition-card team-card">
            <img v-if="team.crest" :src="team.crest" alt="" loading="lazy" />
            <span v-else class="glyph" aria-hidden="true">⚽</span>
            <span class="team-name">
              <strong>{{ team.name }}</strong>
              <small>{{ team.tla || team.short_name || 'Equipo' }} · {{ team.players.length ? team.players.length + ' ' + (team.players.length === 1 ? 'jugador' : 'jugadores') : 'Plantilla no disponible' }}</small>
            </span>
            <span class="arrow" aria-hidden="true">→</span>
          </RouterLink>
        </li>
      </ul>
      <StateBox :empty="!teams.length" :empty-text="search ? 'No hay equipos que coincidan con la búsqueda.' : 'El snapshot publicado no contiene equipos.'" />
    </section>

  </template>
</template>

<style scoped>
.roster-heading { gap: 16px; flex-wrap: wrap; }
.roster-heading h2 { margin-bottom: 0; }
.team-list { list-style: none; padding: 0; margin: 0; }
.team-list > li { min-width: 0; }
.team-card { height: 100%; }
.team-card:focus-visible { outline: 2px solid #1574bf; outline-offset: 3px; }
.team-name { min-width: 0; overflow-wrap: anywhere; }
.team-card .arrow { flex-shrink: 0; }
</style>
