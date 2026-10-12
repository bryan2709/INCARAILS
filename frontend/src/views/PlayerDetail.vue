<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useFootballDetail } from '../composables/useFootballDetail';
import { useImportRedirect } from '../composables/useImportRedirect';
import { useSession } from '../stores/session';
import UpdateInfo from '../components/UpdateInfo.vue';
import { formatContractMonth, formatDate, playerAge, playerProfile, positionLabel, websiteUrl } from '../lib/playerProfile';
import { publicationSnapshots } from '../lib/publicationInfo';
import StateBox from '../components/StateBox.vue';

const route = useRoute();
const { back, detailLink, returnToOrigin } = useImportRedirect('/players', 'el jugador', 'El proveedor no devolvió información de este jugador.');
const session = useSession();
const { data, loading, error, job, refreshing, load, refreshInformation, poll, stopPolling } = useFootballDetail(
  () => route.path === '/players/' + route.params.id ? '/players/' + route.params.id : null,
  {
    isAvailable: value => value?.has_data !== false && Boolean(value?.player || value?.id),
    onUnavailable: returnToOrigin,
  },
);
const profile = computed(() => data.value ? playerProfile(data.value) : null);
const snapshots = computed(() => publicationSnapshots(data.value?.participations));
const player = computed(() => profile.value?.player);
const age = computed(() => playerAge(player.value?.date_of_birth));
const club = computed(() => profile.value?.currentTeam);
const clubWebsite = computed(() => websiteUrl(club.value?.website));

function seasonLabel(season) {
  if (!season?.start_date) return 'Temporada no especificada';
  return formatDate(season.start_date) + (season.end_date ? ' — ' + formatDate(season.end_date) : '');
}

</script>

<template>
  <RouterLink class="back" :to="back.to">← {{ back.label }}</RouterLink>
  <StateBox :loading="loading" :error="error" />
  <button v-if="error && !loading" class="btn" @click="load">Volver a intentar</button>

  <template v-if="profile">
    <header class="player-hero">
      <div class="player-identity">
        <div class="player-avatar" aria-hidden="true">{{ profile.initials }}</div>
        <div>
          <p class="eyebrow">FICHA DEL JUGADOR · #{{ player.id }}</p>
          <h1>{{ player.name }}</h1>
          <p class="player-nationality">{{ player.nationality || 'Nacionalidad no disponible' }}</p>
        </div>
      </div>
      <dl class="player-highlights">
        <div><dt>Posición</dt><dd>{{ profile.multiplePositions ? 'Según competición' : positionLabel(player.position) }}</dd></div>
        <div><dt>Edad</dt><dd>{{ age === null ? 'No disponible' : age + ' años' }}</dd></div>
        <div><dt>Dorsal</dt><dd>{{ profile.multipleShirts ? 'Según competición' : player.shirt_number ?? 'No disponible' }}</dd></div>
      </dl>
    </header>

    <UpdateInfo
      :last-updated="data.last_updated_at || player.last_updated_at"
      :source-updated="player.source_updated_at"
      :job="job"
      :snapshots="snapshots"
      :busy="loading || refreshing"
      :can-refresh="session.can('football.manage')"
      @refresh="refreshInformation"
      @reload="load"
    />

    <div class="player-layout">
      <section class="panel personal-details">
        <p class="eyebrow">PERFIL</p>
        <h2>Datos personales</h2>
        <dl class="profile-fields">
          <div v-if="player.first_name"><dt>Nombre</dt><dd>{{ player.first_name }}</dd></div>
          <div v-if="player.last_name"><dt>Apellido</dt><dd>{{ player.last_name }}</dd></div>
          <div><dt>Fecha de nacimiento</dt><dd>{{ formatDate(player.date_of_birth) }}</dd></div>
          <div><dt>Nacionalidad</dt><dd>{{ player.nationality || 'No disponible' }}</dd></div>
        </dl>
      </section>

      <section v-if="club" class="panel club-details">
        <p class="eyebrow">EQUIPO ACTUAL</p>
        <div class="club-heading">
          <img v-if="club.crest" :src="club.crest" alt="" class="club-crest" />
          <div>
            <h2><RouterLink :to="detailLink('/team/' + club.id)">{{ club.name }}</RouterLink></h2>
            <p class="sub club-country"><img v-if="club.area?.flag" :src="club.area.flag" alt="" />{{ [club.tla, club.area?.name].filter(Boolean).join(' · ') }}</p>
          </div>
        </div>
        <dl class="profile-fields club-fields">
          <div v-if="club.venue"><dt>Estadio</dt><dd>{{ club.venue }}</dd></div>
          <div v-if="club.founded"><dt>Fundación</dt><dd>{{ club.founded }}</dd></div>
          <div v-if="club.club_colors"><dt>Colores</dt><dd>{{ club.club_colors }}</dd></div>
          <div v-if="club.address"><dt>Dirección</dt><dd>{{ club.address }}</dd></div>
        </dl>
        <div v-if="club.contract?.start || club.contract?.until" class="contract">
          <strong>Contrato</strong>
          <dl class="contract-dates">
            <div><dt>Desde</dt><dd>{{ formatContractMonth(club.contract.start) }}</dd></div>
            <div><dt>Hasta</dt><dd>{{ formatContractMonth(club.contract.until) }}</dd></div>
          </dl>
        </div>
        <div v-if="club.running_competitions.length" class="club-competitions">
          <h3>Competiciones del equipo</h3>
          <RouterLink v-for="competition in club.running_competitions" :key="competition.id" class="competition-tag" :to="detailLink('/competitions/' + competition.id)">
            <img v-if="competition.emblem" :src="competition.emblem" alt="" />{{ competition.name }}
          </RouterLink>
        </div>
        <div class="actions">
          <RouterLink class="btn primary" :to="detailLink('/team/' + club.id)">Ver equipo →</RouterLink>
          <a v-if="clubWebsite" class="btn" :href="clubWebsite" target="_blank" rel="noopener noreferrer">Sitio del club ↗</a>
        </div>
      </section>

      <section v-else class="panel club-details">
        <p class="eyebrow">EQUIPOS</p>
        <h2>Equipos con participación publicada</h2>
        <div class="team-list">
          <RouterLink v-for="team in profile.teams" :key="team.id" class="team-card" :to="detailLink('/team/' + team.id)">
            <img v-if="team.crest" :src="team.crest" alt="" class="club-crest" />
            <span v-else class="glyph" aria-hidden="true">⚽</span>
            <span><strong>{{ team.name }}</strong><small>{{ team.tla || team.short_name || 'Ver ficha del equipo' }}</small></span>
            <span class="arrow" aria-hidden="true">→</span>
          </RouterLink>
        </div>
        <p v-if="!profile.teams.length" class="sub">No hay un equipo asociado a una participación publicada.</p>
      </section>
    </div>

    <section class="panel participations">
      <div class="panel-head">
        <div><p class="eyebrow">TRAYECTORIA EN COMPETICIONES</p><h2>Participaciones publicadas</h2></div>
        <span class="metric">{{ profile.participations.length }} {{ profile.participations.length === 1 ? 'participación' : 'participaciones' }}</span>
      </div>
      <div v-if="profile.participations.length" class="table-scroll">
        <table class="data-table">
          <caption class="sr-only">Equipos, competiciones y temporadas de {{ player.name }}</caption>
          <thead><tr><th scope="col">Equipo</th><th scope="col">Competición</th><th scope="col">Temporada</th><th scope="col">Posición</th></tr></thead>
          <tbody>
            <tr v-for="participation in profile.participations" :key="participation.competition.id + ':' + participation.season.id + ':' + participation.team.id">
              <td><RouterLink class="entity-link" :to="detailLink('/team/' + participation.team.id)"><img v-if="participation.team.crest" :src="participation.team.crest" alt="" loading="lazy" />{{ participation.team.name }}</RouterLink></td>
              <td><RouterLink class="entity-link" :to="detailLink('/competitions/' + participation.competition.id)"><img v-if="participation.competition.emblem" :src="participation.competition.emblem" alt="" loading="lazy" />{{ participation.competition.name }}</RouterLink></td>
              <td class="season-cell">{{ seasonLabel(participation.season) }}</td>
              <td><span class="pill">{{ positionLabel(participation.position) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="sub">Sin participaciones actualmente publicadas.</p>
    </section>
  </template>
</template>

<style scoped>
.player-hero { padding: 30px; border-radius: 22px; background: linear-gradient(115deg, #0c2b48, #175d87); color: white; }
.player-identity { display: flex; align-items: center; gap: 24px; }
.player-hero .eyebrow { color: #a0daf8; }
.player-avatar { display: grid; place-items: center; width: 96px; height: 96px; flex-shrink: 0; border-radius: 24px; background: #ffffff17; border: 1px solid #ffffff38; font-size: 2.2rem; font-weight: 800; }
.player-nationality { color: #d0e4f1; margin: 10px 0 0; }
.player-highlights { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin: 28px 0 0; padding-top: 22px; border-top: 1px solid #ffffff26; }
.player-highlights dt { color: #b4d0e4; font-size: .78rem; margin-bottom: 7px; }
.player-highlights dd { margin: 0; font-weight: 700; font-size: 1.15rem; }
.player-layout { display: grid; grid-template-columns: minmax(230px, 1fr) minmax(0, 2fr); gap: 18px; align-items: start; }
.profile-fields { margin: 22px 0 0; }
.profile-fields > div { padding: 12px 0; border-bottom: 1px solid #edf1f6; }
.profile-fields dt, .contract-dates dt { color: #728199; font-size: .78rem; margin-bottom: 6px; }
.profile-fields dd, .contract-dates dd { margin: 0; font-weight: 600; font-size: .9rem; overflow-wrap: anywhere; }
.club-heading { display: flex; align-items: center; gap: 16px; }
.club-heading h2 { margin-bottom: 0; font-size: 1.2rem; }
.club-crest { width: 56px; height: 56px; object-fit: contain; flex-shrink: 0; }
.club-country { display: flex; align-items: center; gap: 8px; }
.club-country img { width: 20px; height: 14px; object-fit: contain; }
.club-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
.contract { background: #edf5fc; padding: 16px; border-radius: 12px; margin-top: 20px; color: #245b86; }
.contract > strong { font-size: .84rem; }
.contract-dates { display: flex; gap: 40px; flex-wrap: wrap; margin: 14px 0 0; }
.club-competitions { margin-top: 22px; }
.club-competitions h3 { font-size: .84rem; margin-bottom: 12px; }
.competition-tag { display: inline-flex; align-items: center; gap: 8px; margin: 0 8px 8px 0; padding: 8px 10px; background: #f3f7fb; border-radius: 9px; font-size: .8rem; }
.competition-tag img { width: 22px; height: 22px; object-fit: contain; }
.team-list { display: grid; gap: 10px; margin-top: 18px; }
.team-card { display: flex; align-items: center; gap: 15px; border: 1px solid #e1e8f1; border-radius: 12px; padding: 14px; color: #263951; }
.team-card:hover { border-color: #a7cfec; background: #f8fbfe; }
.team-card strong, .team-card small { display: block; }
.team-card small { color: #728199; margin-top: 5px; font-size: .78rem; }
.participations .panel-head { flex-wrap: wrap; gap: 16px; margin-bottom: 14px; }
.participations .panel-head h2 { margin-bottom: 0; }
.table-scroll { overflow-x: auto; }
.entity-link { display: inline-flex; align-items: center; gap: 9px; min-width: 145px; }
.entity-link img { width: 25px; height: 25px; object-fit: contain; flex-shrink: 0; }
.season-cell { min-width: 180px; line-height: 1.5; color: #526984; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
@media (max-width: 1000px) { .player-layout { grid-template-columns: 1fr; gap: 0; } }
@media (max-width: 600px) {
  .player-hero { padding: 22px 18px; }
  .player-identity { gap: 14px; align-items: flex-start; }
  .player-avatar { width: 62px; height: 62px; border-radius: 17px; font-size: 1.4rem; }
  .player-identity h1 { font-size: 1.7rem; }
  .player-highlights { gap: 12px; }
  .player-highlights dd { font-size: .95rem; }
  .club-fields { grid-template-columns: 1fr; }
}
</style>
