import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createSSRApp } from 'vue';
import { createPinia } from 'pinia';
import { renderToString } from 'vue/server-renderer';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createServer } from 'vite';
import { formatDate, playerAge, playerProfile, websiteUrl } from '../src/lib/playerProfile.js';

let server;
let component;
let api;
let originalGet;
let originalPost;
let useSession;

before(async () => {
  server = await createServer({
    server: { middlewareMode: true, watch: null },
    appType: 'custom',
    logLevel: 'silent',
  });
  component = (await server.ssrLoadModule('/src/views/PlayerDetail.vue')).default;
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

const providerPlayer = () => ({
  id: 4204,
  name: 'Aynsley Pears',
  firstName: 'Aynsley',
  lastName: 'Pears',
  dateOfBirth: '1998-04-23',
  nationality: 'England',
  section: 'Goalkeeper',
  position: 'Goalkeeper',
  shirtNumber: 1,
  lastUpdated: '2023-07-30T19:41:14Z',
  currentTeam: {
    area: { id: 2072, name: 'England', code: 'ENG', flag: 'https://crests.football-data.org/770.svg' },
    id: 59,
    name: 'Blackburn Rovers FC',
    shortName: 'Blackburn',
    tla: 'BLA',
    crest: 'https://crests.football-data.org/59.png',
    address: 'Ewood Park Blackburn BB2 4JF',
    website: 'http://www.rovers.co.uk',
    founded: 1874,
    clubColors: 'Blue / White',
    venue: 'Ewood Park',
    runningCompetitions: [
      { id: 2016, name: 'Championship', code: 'ELC', type: 'LEAGUE', emblem: 'https://crests.football-data.org/ELC.png' },
      { id: 2139, name: 'Football League Cup', code: 'FLC', type: 'CUP', emblem: 'https://crests.football-data.org/carabao.png' },
    ],
    contract: { start: '2020-10', until: '2027-06' },
  },
});

const localPlayer = () => ({
  player: {
    id: 4204, name: 'Aynsley Pears', first_name: null, last_name: null,
    date_of_birth: '1998-04-23', nationality: 'England', source_updated_at: null,
  },
  participations: [{
    team: { id: 59, name: 'Blackburn Rovers FC', tla: 'BLA', crest: 'https://crests.football-data.org/59.png' },
    competition: { id: 2016, name: 'Championship', emblem: 'https://crests.football-data.org/ELC.png' },
    season: { id: 2509, start_date: '2026-08-14', end_date: '2027-05-01' },
    snapshot: { id: 12, published_at: '2026-10-11T15:00:00Z' },
    position: 'Goalkeeper', shirt_number: null,
  }],
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
      { path: '/players/:id', component: { render: () => null } },
      { path: '/:pathMatch(.*)*', component: { render: () => null } },
    ],
  });
  const pinia = createPinia();
  useSession(pinia).apply({ abilities: [{ action: manage ? 'manage' : 'read', subject: 'Football' }] });
  await router.push('/players/4204');
  await router.isReady();
  const render = () => renderToString(createSSRApp(view).use(pinia).use(router));
  const html = await render();
  state.stopPolling();
  return { html, state, calls, render };
}

test('player refresh loads the individual profile and displays when it was updated', async () => {
  const { state, render } = await page(localPlayer(), true);
  api.post = async path => {
    assert.equal(path, '/players/4204/refresh');
    return { data: { id: 41, status: 'pending' } };
  };
  await state.refreshInformation();
  state.stopPolling();
  api.get = async path => ({ data: path.startsWith('/imports/') ? { id: 41, status: 'completed' } : { player: providerPlayer(), last_updated_at: '2026-10-11T15:00:00Z', import: { id: 41, status: 'completed' } } });
  await state.poll();
  state.stopPolling();
  const html = await render();
  assert.match(html, /Refrescar información/);
  assert.match(html, /Última actualización/);
  assert.match(html, /10:00/);
  assert.match(html, /Blackburn Rovers FC/);
  assert.equal(state.player.value.shirt_number, 1);
});

test('local player shows personal data, translated position, crests and participation links', async () => {
  const { html, calls } = await page(localPlayer());
  assert.match(html, /Aynsley Pears/);
  assert.match(html, /23 de abril de 1998/);
  assert.match(html, /Portero/);
  assert.match(html, /Equipos con participación publicada/);
  assert.match(html, /href="\/team\/59\?from=\/players\/4204"/);
  assert.match(html, /href="\/competitions\/2016\?from=\/players\/4204"/);
  assert.match(html, /src="https:\/\/crests.football-data.org\/59.png"/);
  assert.match(html, /1 de mayo de 2027/);
  assert.match(html, /Snapshot publicado #12/);
  assert.match(html, /Estado de importación/);
  assert.deepEqual(calls, ['/players/4204']);
});

test('provider payload shows current team, contract, venue and running competitions', async () => {
  const { html } = await page(providerPlayer());
  assert.match(html, /EQUIPO ACTUAL/);
  assert.match(html, /Ewood Park/);
  assert.match(html, /Blue \/ White/);
  assert.match(html, /1874/);
  assert.match(html, /octubre de 2020/);
  assert.match(html, /junio de 2027/);
  assert.match(html, /href="\/competitions\/2139\?from=\/players\/4204"/);
  assert.match(html, /href="http:\/\/www.rovers.co.uk\/"/);
  assert.match(html, /<dt[^>]*>Dorsal<\/dt><dd[^>]*>1<\/dd>/);
});

test('missing profile fields and historical identities have explicit empty states', async () => {
  const data = localPlayer();
  data.player.date_of_birth = null;
  data.player.nationality = null;
  data.participations = [];
  const { html } = await page(data);
  assert.match(html, /No disponible/);
  assert.match(html, /Sin participaciones actualmente publicadas/);
  assert.doesNotMatch(html, /Invalid Date|NaN|EQUIPO ACTUAL|Contrato/);
});

test('different participation positions are displayed in their own contexts', async () => {
  const data = localPlayer();
  data.participations[0].shirt_number = 0;
  data.participations.push({
    ...data.participations[0],
    team: { id: 60, name: 'Another team' },
    competition: { id: 2021, name: 'Premier League' },
    snapshot: { id: 13, published_at: '2026-10-11T16:00:00Z' },
    position: 'Defence', shirt_number: 5,
  });
  const { html } = await page(data);
  assert.match(html, /Según competición/);
  assert.match(html, /href="\/team\/60\?from=\/players\/4204"/);
  assert.match(html, /Defensa/);
});

test('refreshed individual fields do not reuse a missing dorsal or position from a published participation', async () => {
  const data = localPlayer();
  data.player.last_updated_at = '2026-10-11T15:00:00Z';
  data.player.shirt_number = null;
  data.player.position = null;
  data.participations[0].shirt_number = 5;
  const profile = playerProfile(data);
  assert.equal(profile.player.shirt_number, null);
  assert.equal(profile.player.position, null);
  assert.equal(profile.participations[0].shirt_number, 5);
  const { html } = await page(data);
  assert.match(html, /<dt[^>]*>Dorsal<\/dt><dd[^>]*>No disponible<\/dd>/);
});

test('birth dates have correct ages before and on the birthday and reject impossible dates', () => {
  assert.equal(playerAge('1998-04-23', new Date(2026, 3, 22)), 27);
  assert.equal(playerAge('1998-04-23', new Date(2026, 3, 23)), 28);
  assert.equal(playerAge('2027-01-01', new Date(2026, 3, 23)), null);
  assert.equal(playerAge('1998-02-30'), null);
  assert.equal(formatDate('invalid'), 'No disponible');
  assert.equal(formatDate('1998-04-23'), '23 de abril de 1998');
});

test('profile adapter keeps a zero shirt number and blocks unsafe club links', async () => {
  const data = providerPlayer();
  data.shirtNumber = 0;
  data.currentTeam.website = 'javascript:alert(1)';
  assert.equal(playerProfile(data).player.shirt_number, 0);
  assert.equal(websiteUrl(data.currentTeam.website), null);
  const { html } = await page(data);
  assert.match(html, /<dt[^>]*>Dorsal<\/dt><dd[^>]*>0<\/dd>/);
  assert.doesNotMatch(html, /Sitio del club|href="javascript:/);
});
