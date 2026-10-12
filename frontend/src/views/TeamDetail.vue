<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useFootballDetail } from '../composables/useFootballDetail';
import { useImportRedirect } from '../composables/useImportRedirect';
import { useSession } from '../stores/session';
import UpdateInfo from '../components/UpdateInfo.vue';
import { formatContractMonth, formatDate, playerAge, positionLabel, websiteUrl } from '../lib/playerProfile';
import { publicationSnapshots } from '../lib/publicationInfo';
import { positionGroup, selectedTeam, squadGroups, teamCompetitions, teamProfile } from '../lib/teamProfile';
import StateBox from '../components/StateBox.vue';

const route = useRoute();
const { back, detailLink, returnToOrigin } = useImportRedirect('/team', 'el equipo', 'El proveedor no devolvió información de este equipo.');
const session = useSession();
const { data, loading, error, job, refreshing, load, refreshInformation, poll, stopPolling } = useFootballDetail(
  () => route.path === '/team/' + route.params.id ? '/team/' + route.params.id : null,
  {
    isAvailable: value => value?.has_data !== false && Boolean(value?.team || value?.id),
    onUnavailable: returnToOrigin,
  },
);
const selectedRoster = ref('');
const search = ref('');
const position = ref('');
const profile = computed(() => data.value ? teamProfile(data.value) : null);
const snapshots = computed(() => publicationSnapshots(data.value?.participations));
const roster = computed(() => profile.value?.rosters.find(item => item.key === selectedRoster.value) || profile.value?.rosters[0]);
const team = computed(() => profile.value ? selectedTeam(profile.value, roster.value) : null);
const competitions = computed(() => profile.value ? teamCompetitions(profile.value, team.value) : []);
const coach = computed(() => team.value?.coach);
const clubWebsite = computed(() => websiteUrl(team.value?.website));
const players = computed(() => roster.value?.players || []);
const groups = computed(() => squadGroups.map(group => ({
  ...group, count: players.value.filter(player => positionGroup(player.position) === group.id).length,
})).filter(group => group.id !== 'other' || group.count));
const visiblePlayers = computed(() => {
  const term = search.value.trim().toLocaleLowerCase();
  return players.value.filter(player =>
    (!position.value || positionGroup(player.position) === position.value)
    && (!term || [player.name, player.nationality].some(value => value?.toLocaleLowerCase().includes(term))),
  );
});

function rosterLabel(item) {
  if (!item.competition) return 'Plantilla actual del equipo';
  return item.competition.name + (item.season?.start_date ? ' · ' + formatDate(item.season.start_date) : '');
}

function clearFilters() {
  search.value = '';
  position.value = '';
}

watch(data, () => { selectedRoster.value = ''; clearFilters(); });
watch(selectedRoster, clearFilters);
</script>

<template>
  <RouterLink class="back" :to="back.to">← {{ back.label }}</RouterLink>
  <StateBox :loading="loading" :error="error" />
  <button v-if="error && !loading" class="btn" @click="load">Volver a intentar</button>

  <template v-if="team">
    <header class="team-hero">
      <div class="team-identity">
        <div class="crest-frame"><img v-if="team.crest" :src="team.crest" alt="" /><span v-else aria-hidden="true">⚽</span></div>
        <div>
          <p class="eyebrow">FICHA DEL EQUIPO · #{{ team.id }}</p>
          <h1>{{ team.name }}</h1>
          <p class="team-country"><img v-if="team.area?.flag" :src="team.area.flag" alt="" />{{ [team.tla || team.short_name, team.area?.name].filter(Boolean).join(' · ') || 'Club de fútbol' }}</p>
        </div>
      </div>
      <dl class="team-highlights">
        <div><dt>Fundación</dt><dd>{{ team.founded || 'No disponible' }}</dd></div>
        <div><dt>Jugadores en plantilla</dt><dd>{{ roster ? players.length : 'No disponible' }}</dd></div>
        <div><dt>Competiciones</dt><dd>{{ competitions.length }}</dd></div>
      </dl>
    </header>

    <UpdateInfo
      :last-updated="roster?.last_updated_at || data.last_updated_at || team.last_updated_at"
      :source-updated="team.source_updated_at"
      :job="job"
      :snapshots="snapshots"
      :busy="loading || refreshing"
      :can-refresh="session.can('football.manage')"
      @refresh="refreshInformation"
      @reload="load"
    />

    <div v-if="profile.rosters.length > 1" class="roster-selector">
      <label for="team-roster">Participación y temporada</label>
      <select id="team-roster" :value="roster.key" @change="selectedRoster = $event.target.value">
        <option v-for="item in profile.rosters" :key="item.key" :value="item.key">{{ rosterLabel(item) }}</option>
      </select>
    </div>

    <div class="club-layout">
      <section class="panel club-details">
        <p class="eyebrow">EL CLUB</p>
        <h2>Información del equipo</h2>
        <dl class="club-fields">
          <div><dt>Estadio</dt><dd>{{ team.venue || 'No disponible' }}</dd></div>
          <div><dt>Colores del club</dt><dd>{{ team.club_colors || 'No disponible' }}</dd></div>
          <div><dt>Nombre corto</dt><dd>{{ team.short_name || 'No disponible' }}</dd></div>
          <div><dt>Dirección</dt><dd>{{ team.address || 'No disponible' }}</dd></div>
        </dl>
        <a v-if="clubWebsite" class="btn club-website" :href="clubWebsite" target="_blank" rel="noopener noreferrer">Sitio oficial del club ↗</a>
      </section>

      <section class="panel coach-details">
        <p class="eyebrow">CUERPO TÉCNICO</p>
        <h2>Entrenador</h2>
        <template v-if="coach?.name">
          <div class="coach-heading"><span class="coach-avatar" aria-hidden="true">DT</span><div><h3>{{ coach.name }}</h3><p class="sub">{{ coach.nationality || 'Nacionalidad no disponible' }}</p></div></div>
          <dl class="coach-fields">
            <div><dt>Nacimiento</dt><dd>{{ formatDate(coach.date_of_birth) }}</dd></div>
            <div v-if="playerAge(coach.date_of_birth) !== null"><dt>Edad</dt><dd>{{ playerAge(coach.date_of_birth) }} años</dd></div>
            <div><dt>Inicio del contrato</dt><dd>{{ formatContractMonth(coach.contract?.start) }}</dd></div>
            <div><dt>Fin del contrato</dt><dd>{{ formatContractMonth(coach.contract?.until) }}</dd></div>
          </dl>
        </template>
        <p v-else class="sub">No hay información del entrenador para esta participación.</p>
      </section>
    </div>

    <section v-if="competitions.length" class="panel">
      <div class="panel-head"><h2>Competiciones del club</h2><span class="metric">{{ competitions.length }} {{ competitions.length === 1 ? 'competición' : 'competiciones' }}</span></div>
      <div class="competition-list">
        <RouterLink v-for="competition in competitions" :key="competition.id" class="competition-entry" :to="detailLink('/competitions/' + competition.id)">
          <img v-if="competition.emblem" :src="competition.emblem" alt="" loading="lazy" />
          <span v-else class="glyph" aria-hidden="true">🏆</span>
          <span><strong>{{ competition.name }}</strong><small>{{ [competition.code, competition.type === 'CUP' ? 'Copa' : competition.type === 'LEAGUE' ? 'Liga' : null].filter(Boolean).join(' · ') }}</small></span>
          <span class="arrow" aria-hidden="true">→</span>
        </RouterLink>
      </div>
    </section>

    <section class="panel squad-panel">
      <div class="panel-head squad-heading"><div><p class="eyebrow">JUGADORES DEL EQUIPO</p><h2>{{ roster?.competition ? 'Plantilla publicada' : 'Plantilla' }}</h2></div><span v-if="roster" class="metric">{{ players.length }} jugadores</span></div>
      <p v-if="roster?.competition" class="sub"><RouterLink :to="detailLink('/competitions/' + roster.competition.id)">{{ roster.competition.name }}</RouterLink> · {{ formatDate(roster.season.start_date) }}<template v-if="roster.season.end_date"> — {{ formatDate(roster.season.end_date) }}</template></p>
      <p v-else-if="roster?.key === 'current'" class="sub">Plantilla actual informada por el equipo.</p>
      <template v-if="players.length">
        <dl class="squad-summary"><div v-for="group in groups" :key="group.id"><dt>{{ group.label }}</dt><dd>{{ group.count }}</dd></div></dl>
        <div class="squad-filters">
          <input v-model="search" type="search" aria-label="Buscar jugador o nacionalidad" placeholder="Buscar jugador o nacionalidad…" />
          <select v-model="position" aria-label="Filtrar por posición"><option value="">Todas las posiciones</option><option v-for="group in groups" :key="group.id" :value="group.id">{{ group.label }}</option></select>
          <button v-if="search || position" class="btn muted" @click="clearFilters">Limpiar filtros</button>
        </div>
        <p class="tip" aria-live="polite">Mostrando {{ visiblePlayers.length }} de {{ players.length }} jugadores.</p>
        <div v-if="visiblePlayers.length" class="table-scroll">
          <table class="data-table">
            <caption class="sr-only">Plantilla de {{ team.name }}</caption>
            <thead><tr><th scope="col">Jugador</th><th scope="col">Posición</th><th scope="col">Nacionalidad</th><th scope="col">Edad</th><th scope="col">Dorsal</th></tr></thead>
            <tbody>
              <tr v-for="player in visiblePlayers" :key="player.id">
                <td><RouterLink class="player-link" :to="detailLink('/players/' + player.id)">{{ player.name }}<span aria-hidden="true">↗</span></RouterLink></td>
                <td><span class="pill">{{ positionLabel(player.position) }}</span></td>
                <td>{{ player.nationality || 'No disponible' }}</td>
                <td>{{ playerAge(player.date_of_birth) ?? 'No disponible' }}</td>
                <td><span v-if="player.shirt_number !== null" class="shirt-number">{{ player.shirt_number }}</span><span v-else class="missing">No disponible</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <StateBox :empty="!visiblePlayers.length" empty-text="No hay jugadores que coincidan con los filtros." />
      </template>
      <p v-else class="sub">{{ roster ? 'No hay jugadores disponibles en esta plantilla.' : 'Sin participaciones actualmente publicadas.' }}</p>
    </section>
  </template>
</template>

<style scoped>
.team-hero { padding: 30px; border-radius: 22px; background: linear-gradient(115deg, #0c2b48, #175d87); color: white; }
.team-identity { display: flex; align-items: center; gap: 24px; }
.team-hero .eyebrow { color: #a0daf8; }
.crest-frame { display: grid; place-items: center; width: 108px; height: 108px; flex-shrink: 0; border-radius: 24px; background: #fff; font-size: 2.5rem; }
.crest-frame img { width: 82px; height: 82px; object-fit: contain; }
.team-country { display: flex; align-items: center; gap: 8px; color: #d0e4f1; margin: 12px 0 0; }
.team-country img { width: 22px; height: 16px; object-fit: contain; }
.team-highlights { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin: 28px 0 0; padding-top: 22px; border-top: 1px solid #ffffff26; }
.team-highlights dt { color: #b4d0e4; font-size: .78rem; margin-bottom: 7px; }
.team-highlights dd { margin: 0; font-weight: 700; font-size: 1.25rem; }
.roster-selector { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 22px; }
.roster-selector label { font-size: .84rem; font-weight: 700; color: #526984; }
select { font: inherit; background: white; border: 1px solid #d9e2ed; border-radius: 11px; padding: 12px 14px; color: #263951; max-width: 100%; }
select:focus-visible { outline: 2px solid #1574bf; outline-offset: 2px; }
.club-layout { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 18px; align-items: start; }
.club-fields, .coach-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; margin: 18px 0 0; }
.club-fields > div, .coach-fields > div { padding: 12px 0; border-bottom: 1px solid #edf1f6; }
.club-fields dt, .coach-fields dt { color: #728199; font-size: .78rem; margin-bottom: 6px; }
.club-fields dd, .coach-fields dd { margin: 0; font-weight: 600; font-size: .9rem; overflow-wrap: anywhere; }
.club-website { display: inline-block; margin-top: 20px; }
.coach-heading { display: flex; align-items: center; gap: 14px; margin-top: 20px; }
.coach-heading h3 { font-size: 1rem; }
.coach-avatar { display: grid; place-items: center; background: #edf4fc; color: #226392; width: 48px; height: 48px; flex-shrink: 0; border-radius: 13px; font-weight: 800; }
.competition-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 14px; }
.competition-entry { display: flex; align-items: center; gap: 14px; padding: 16px; border: 1px solid #e1e8f1; border-radius: 12px; color: #263951; }
.competition-entry:hover { border-color: #a7cfec; background: #f8fbfe; }
.competition-entry img { width: 38px; height: 38px; object-fit: contain; flex-shrink: 0; }
.competition-entry strong, .competition-entry small { display: block; }
.competition-entry strong { font-size: .9rem; }
.competition-entry small { font-size: .76rem; color: #728199; margin-top: 5px; }
.panel-head { gap: 14px; flex-wrap: wrap; }
.squad-heading h2 { margin-bottom: 0; }
.squad-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 10px; margin: 22px 0; }
.squad-summary > div { padding: 14px; background: #f4f8fc; border-radius: 12px; }
.squad-summary dt { color: #728199; font-size: .75rem; }
.squad-summary dd { margin: 7px 0 0; font-size: 1.3rem; font-weight: 800; color: #245b86; }
.squad-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.squad-filters input { flex: 1; min-width: 180px; }
.table-scroll { overflow-x: auto; }
.player-link { display: inline-flex; align-items: center; gap: 12px; min-width: 145px; font-weight: 600; }
.player-link span { font-size: .8rem; color: #a5b5c3; }
.shirt-number { display: inline-grid; place-items: center; min-width: 32px; height: 32px; border-radius: 9px; background: #edf4fc; color: #226392; font-weight: 700; }
.missing { color: #728199; font-size: .8rem; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
@media (max-width: 1000px) { .club-layout { grid-template-columns: 1fr; gap: 0; } }
@media (max-width: 650px) {
  .team-hero { padding: 22px 18px; }
  .team-identity { gap: 14px; align-items: flex-start; }
  .crest-frame { width: 70px; height: 70px; border-radius: 17px; }
  .crest-frame img { width: 54px; height: 54px; }
  .team-identity h1 { font-size: 1.65rem; }
  .team-highlights { gap: 12px; }
  .team-highlights dd { font-size: 1rem; }
  .competition-list, .club-fields, .coach-fields { grid-template-columns: 1fr; }
}
</style>
