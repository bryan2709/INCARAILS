import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { after, before, test } from 'node:test';
import { createSSRApp } from 'vue';
import { createPinia } from 'pinia';
import { renderToString } from 'vue/server-renderer';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createServer } from 'vite';

let server;
let component;
let api;
let originalGet;
let originalPost;
let useSession;
let providerTeam;
let galatasaray;

before(async () => {
  providerTeam = JSON.parse(await readFile(new URL('./fixtures/blackburn-team.json', import.meta.url), 'utf8'));
  galatasaray = JSON.parse(await readFile(new URL('./fixtures/galatasaray-team.json', import.meta.url), 'utf8'));
  server = await createServer({ server: { middlewareMode: true, watch: null }, appType: 'custom', logLevel: 'silent' });
  component = (await server.ssrLoadModule('/src/views/TeamDetail.vue')).default;
  ({ api } = await server.ssrLoadModule('/src/lib/api.js'));
  originalGet = api.get;
  originalPost = api.post;
  ({ useSession } = await server.ssrLoadModule('/src/stores/session.js'));
});

after(async () => {
  if (api) api.get = originalGet;
  if (api) api.post = originalPost;
  await server?.close();
});

async function page(payload, manage = false) {
  const calls = [];
  api.get = async path => { calls.push(path); return { data: payload }; };
  let state;
  const view = {
    ...component,
    async setup(props, context) {
      if (!state) { state = component.setup(props, context); await Promise.resolve(); }
      return state;
    },
  };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/team/:id', component: { render: () => null } },
      { path: '/:pathMatch(.*)*', component: { render: () => null } },
    ],
  });
  await router.push('/team/59');
  await router.isReady();
  const pinia = createPinia();
  useSession(pinia).apply({ abilities: [{ action: manage ? 'manage' : 'read', subject: 'Football' }] });
  const render = () => renderToString(createSSRApp(view).use(pinia).use(router));
  const html = await render();
  state.stopPolling();
  return { html, state, calls, render };
}

const localTeam = () => ({
  team: { id: 59, name: 'Blackburn Rovers FC', short_name: 'Blackburn', tla: 'BLA', crest: providerTeam.crest, area: providerTeam.area },
  participations: [{
    name: 'Blackburn Rovers FC', crest: providerTeam.crest,
    competition: { id: 2016, name: 'Championship', code: 'ELC', type: 'LEAGUE', emblem: providerTeam.runningCompetitions[0].emblem },
    season: { id: 2509, start_date: '2026-08-14', end_date: '2027-05-01' },
    snapshot: { id: 12, published_at: '2026-10-11T15:00:00Z' },
    profile: { venue: 'Ewood Park', founded: 1874, club_colors: 'Blue / White', coach: { name: 'Tony Mowbray', date_of_birth: '1963-11-22', nationality: 'England' } },
    players: [{ id: 4204, name: 'Aynsley Pears', position: 'Goalkeeper', date_of_birth: '1998-04-23', nationality: 'England', shirt_number: 0 }],
  }],
});

test('the supplied team payload renders club, coach, competitions and all 24 squad members', async () => {
  const { html, state, calls } = await page(providerTeam);
  assert.match(html, /Blackburn Rovers FC/);
  assert.match(html, /Ewood Park Blackburn BB2 4JF/);
  assert.match(html, /1874/);
  assert.match(html, /Blue \/ White/);
  assert.match(html, /Tony Mowbray/);
  assert.match(html, /22 de noviembre de 1963/);
  assert.match(html, /href="\/competitions\/2139\?from=\/team\/59"/);
  assert.match(html, /href="\/players\/4204\?from=\/team\/59"/);
  assert.match(html, /href="http:\/\/www.rovers.co.uk\/"/);
  assert.equal(state.players.value.length, 24);
  assert.deepEqual(state.groups.value.map(group => [group.id, group.count]), [['goalkeeper', 2], ['defence', 9], ['midfield', 7], ['offence', 6]]);
  assert.deepEqual(calls, ['/team/59']);
});

test('published team renders a season-specific squad with demographics and a zero shirt number', async () => {
  const { html } = await page(localTeam());
  assert.match(html, /Plantilla publicada/);
  assert.match(html, /14 de agosto de 2026/);
  assert.match(html, /1 de mayo de 2027/);
  assert.match(html, /Portero/);
  assert.match(html, /England/);
  assert.match(html, /<span[^>]*class="shirt-number"[^>]*>0<\/span>/);
  assert.match(html, /Snapshot publicado #12/);
  assert.match(html, /Estado de importación/);
});

test('name, nationality and position filters combine and can be cleared', async () => {
  const { state, render } = await page(providerTeam);
  state.search.value = 'morsy';
  state.position.value = 'midfield';
  assert.deepEqual(state.visiblePlayers.value.map(player => player.name), ['Sam Morsy']);
  assert.match(await render(), /Mostrando 1 de 24 jugadores/);
  state.position.value = 'goalkeeper';
  assert.match(await render(), /No hay jugadores que coincidan con los filtros/);
  state.clearFilters();
  assert.equal(state.visiblePlayers.value.length, 24);
  state.search.value = 'Hungary';
  assert.deepEqual(state.visiblePlayers.value.map(player => player.name), ['Balázs Tóth']);
});

test('switching participation preserves its own club profile and squad', async () => {
  const data = localTeam();
  data.participations.push({
    name: 'Blackburn Rovers FC',
    competition: { id: 2139, name: 'Football League Cup', type: 'CUP' },
    season: { id: 2510, start_date: '2027-08-01', end_date: null },
    profile: { venue: 'Cup venue', coach: { name: 'Cup coach' } },
    players: [{ id: 63304, name: 'Balázs Tóth', position: 'Goalkeeper', nationality: 'Hungary' }],
  });
  const { state, render } = await page(data);
  state.selectedRoster.value = '2139:2510';
  assert.equal(state.team.value.venue, 'Cup venue');
  assert.deepEqual(state.players.value.map(player => player.id), [63304]);
  const html = await render();
  assert.match(html, /Cup coach/);
  assert.match(html, /Cup venue/);
  assert.doesNotMatch(html, /Tony Mowbray|Aynsley Pears/);
});

test('historical identities without published rosters show empty and missing-profile states', async () => {
  const { html } = await page({ team: { id: 59, name: 'Blackburn Rovers FC' }, participations: [] });
  assert.match(html, /Sin participaciones actualmente publicadas/);
  assert.match(html, /No hay información del entrenador/);
  assert.match(html, /No disponible/);
  assert.doesNotMatch(html, /Invalid Date|NaN|undefined/);
});

test('published club without squad players retains its profile and explains the unavailable roster', async () => {
  const data = localTeam();
  data.participations[0].players = [];
  const { html, state } = await page(data);
  assert.equal(state.players.value.length, 0);
  assert.match(html, /Blackburn Rovers FC/);
  assert.match(html, /Ewood Park/);
  assert.match(html, /Plantilla publicada/);
  assert.match(html, /No hay jugadores disponibles en esta plantilla/);
  assert.doesNotMatch(html, /Sin participaciones actualmente publicadas|Invalid Date|NaN|undefined/);
});

test('Galatasaray squad is shown even when its published Champions League participation is empty', async () => {
  const data = {
    team: { ...galatasaray, last_updated_at: '2026-10-11T15:00:00Z' },
    participations: [{ name: galatasaray.name, competition: { id: 2001, name: 'UEFA Champions League' }, season: { id: 2557, start_date: '2026-09-08' }, profile: {}, players: [] }],
  };
  const { html, state } = await page(data, true);
  assert.equal(state.players.value.length, 32);
  assert.match(html, /Uğurcan Çakır|Uğurcan Çakır/);
  assert.match(html, /href="\/players\/9434\?from=\/team\/59"/);
  assert.match(html, /Plantilla actual del equipo/);
  assert.match(html, /Refrescar información/);
  assert.match(html, /Última actualización/);
  assert.match(html, /10:00/);
  assert.match(html, /Actualización del proveedor/);
  state.selectedRoster.value = '2001:2557';
  assert.equal(state.players.value.length, 0);
});

test('refresh fetches only the chosen team and reloads its squad after the background import completes', async () => {
  const data = localTeam();
  data.participations[0].players = [];
  const { state, render } = await page(data, true);
  const posts = [];
  api.post = async path => { posts.push(path); return { data: { id: 40, status: 'pending' } }; };
  await state.refreshInformation();
  await state.refreshInformation();
  state.stopPolling();
  assert.deepEqual(posts, ['/team/59/refresh']);
  assert.match(await render(), /Actualizando en segundo plano/);
  api.get = async path => ({ data: path.startsWith('/imports/') ? { id: 40, status: 'completed' } : { ...data, team: providerTeam, import: { id: 40, status: 'completed' } } });
  await state.poll();
  state.stopPolling();
  assert.equal(state.players.value.length, 24);
  assert.match(await render(), /Aynsley Pears/);
});

test('missing positions are included in the squad and unsafe websites have no link', async () => {
  const data = structuredClone(providerTeam);
  data.website = 'javascript:alert(1)';
  data.squad[0].position = null;
  data.squad[0].dateOfBirth = null;
  const { html, state } = await page(data);
  assert.equal(state.groups.value.find(group => group.id === 'other').count, 1);
  assert.match(html, /No disponible/);
  assert.doesNotMatch(html, /Sitio oficial del club|href="javascript:/);
});

test('failed refresh shows the provider subscription diagnosis while preserving the team roster', async () => {
  const data = localTeam();
  const providerMessage = 'The resource you are looking for is restricted and apparently not within your permissions. Please check your subscription.';
  data.import = {
    id: 42, status: 'failed', error_code: 'SOURCE_ACCESS_DENIED',
    error_message: 'Solicitud externa fallida (HTTP 403). ' + providerMessage,
  };
  const { html, state } = await page(data, true);
  const alert = html.match(/<p\b[^>]*role="alert"[^>]*>([\s\S]*?)<\/p>/)?.[1];
  assert.ok(alert);
  assert.ok(alert.includes(providerMessage));
  assert.match(alert, /SOURCE_ACCESS_DENIED/);
  assert.match(alert, /HTTP 403/);
  assert.equal(state.players.value.length, 1);
  assert.match(html, /Aynsley Pears/);
  assert.match(html, /Snapshot publicado #12/);
});

test('shirtNumber in squad is rendered as a dorsal, including zero, while missing values stay unavailable', async () => {
  const data = structuredClone(providerTeam);
  data.squad[0].shirtNumber = 1;
  data.squad[1].shirtNumber = 0;
  data.squad[2].shirtNumber = null;
  const { html, state } = await page(data);
  const players = new Map(state.players.value.map(player => [player.id, player]));
  assert.equal(players.get(4204).shirt_number, 1);
  assert.equal(players.get(63304).shirt_number, 0);
  assert.equal(players.get(5739).shirt_number, null);
  assert.equal(players.get(5842).shirt_number, null);
  assert.match(html, /<span[^>]*class="shirt-number"[^>]*>1<\/span>/);
  assert.match(html, /<span[^>]*class="shirt-number"[^>]*>0<\/span>/);
});
