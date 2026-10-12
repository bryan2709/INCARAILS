import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createSSRApp } from 'vue';
import { createPinia } from 'pinia';
import { renderToString } from 'vue/server-renderer';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createServer } from 'vite';

let server;
let api;
let originalGet;
let useSession;
let useImportNotice;
const views = {};
const restrictedMessage = 'The resource you are looking for is restricted and apparently not within your permissions. Please check your subscription.';
const restrictedImport = { id: 55, status: 'failed', error_code: 'SOURCE_ACCESS_DENIED', error_message: 'Solicitud externa fallida (HTTP 403). ' + restrictedMessage };
const unavailableCompetition = { competition: { id: 2145, name: 'Competición #2145' }, snapshot: null, import: restrictedImport };
const team = { id: 610, name: 'Galatasaray SK', squad: [{ id: 81187, name: 'Jugador de prueba' }], runningCompetitions: [{ id: 2145, name: 'Restricted cup' }] };
const payloads = {
  '/competitions': [{ id: 2001, name: 'UEFA Champions League' }],
  '/competitions/2001': {
    competition: { id: 2001, name: 'UEFA Champions League', import_status: 'published' },
    snapshot: { id: 17, teams: [{ ...team, players: team.squad }] },
  },
  '/team': [team],
  '/team/610': { team, participations: [] },
  '/players': team.squad,
  '/players/81187': { player: team.squad[0], participations: [] },
  '/competitions/2145': unavailableCompetition,
};

before(async () => {
  server = await createServer({ server: { middlewareMode: true, watch: null }, appType: 'custom', logLevel: 'silent' });
  views.App = (await server.ssrLoadModule('/src/App.vue')).default;
  for (const name of ['Competitions', 'CompetitionDetail', 'Teams', 'TeamDetail', 'Players', 'PlayerDetail']) {
    views[name] = (await server.ssrLoadModule('/src/views/' + name + '.vue')).default;
  }
  ({ api } = await server.ssrLoadModule('/src/lib/api.js'));
  originalGet = api.get;
  ({ useSession } = await server.ssrLoadModule('/src/stores/session.js'));
  ({ useImportNotice } = await server.ssrLoadModule('/src/stores/importNotice.js'));
});

after(async () => {
  if (api) api.get = originalGet;
  await server?.close();
});

async function navigation(start, overrides = {}) {
  const calls = [];
  const responses = { ...payloads, ...overrides };
  api.get = async path => {
    calls.push(path);
    assert.ok(path in responses, 'Unexpected API request: ' + path);
    if (typeof responses[path] === 'function') return responses[path]();
    return { data: responses[path], meta: { total: 1 } };
  };
  const states = [];
  const latestState = {};
  const view = (name, listing = false) => ({
    ...views[name],
    async setup(props, context) {
      const state = views[name].setup(props, context);
      if (listing) await state.load();
      else await Promise.resolve();
      states.push(state);
      latestState[name] = state;
      return state;
    },
  });
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/competitions', component: view('Competitions', true) },
      { path: '/competitions/:id', component: view('CompetitionDetail') },
      { path: '/team', component: view('Teams', true) },
      { path: '/team/:id', component: view('TeamDetail') },
      { path: '/players', component: view('Players', true) },
      { path: '/players/:id', component: view('PlayerDetail') },
      { path: '/:pathMatch(.*)*', component: { render: () => null } },
    ],
  });
  const pinia = createPinia();
  useSession(pinia).apply({ abilities: [{ action: 'read', subject: 'Football' }] });
  await router.push(start);
  await router.isReady();
  const render = async () => {
    const html = await renderToString(createSSRApp(views.App).use(pinia).use(router));
    await new Promise(resolve => setImmediate(resolve));
    for (const state of states.splice(0)) state.stopPolling?.();
    return html;
  };
  return { router, render, calls, notices: useImportNotice(pinia), state: name => latestState[name] };
}

function hrefTo(html, path) {
  const hrefs = [...html.matchAll(/<a\b[^>]*href="([^"]+)"/g)].map(match => match[1].replaceAll('&amp;', '&'));
  const href = hrefs.find(value => value.split('?')[0] === path);
  assert.ok(href, 'Missing link to ' + path);
  return href;
}

function backLink(html) {
  const match = html.match(/<a\b(?=[^>]*class="back")[^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/);
  assert.ok(match, 'Missing back link');
  return { href: match[1].replaceAll('&amp;', '&'), label: match[2] };
}

test('competition team cards return to their source competition, including after reopening the URL', async () => {
  const page = await navigation('/competitions/2001');
  const teamUrl = hrefTo(await page.render(), '/team/610');
  await page.router.push(teamUrl);
  assert.equal(backLink(await page.render()).href, '/competitions/2001');
  assert.match(backLink(await page.render()).label, /Volver a la competición/);

  const reopened = await navigation(teamUrl);
  const back = backLink(await reopened.render());
  await reopened.router.push(back.href);
  assert.equal(reopened.router.currentRoute.value.path, '/competitions/2001');
});

test('team listing opens a team that returns to the teams listing', async () => {
  const page = await navigation('/team');
  await page.router.push(hrefTo(await page.render(), '/team/610'));
  const back = backLink(await page.render());
  assert.equal(back.href, '/team');
  assert.match(back.label, /Volver a equipos/);
  await page.router.push(back.href);
  assert.match(await page.render(), /<h1>Equipos<\/h1>/);
});

test('a squad player returns to its team and preserves the team return to the competition', async () => {
  const page = await navigation('/competitions/2001');
  const teamUrl = hrefTo(await page.render(), '/team/610');
  await page.router.push(teamUrl);
  const playerUrl = hrefTo(await page.render(), '/players/81187');
  await page.router.push(playerUrl);
  const back = backLink(await page.render());
  assert.equal(back.href, teamUrl);
  assert.match(back.label, /Volver al equipo/);
  await page.router.push(back.href);
  assert.equal(backLink(await page.render()).href, '/competitions/2001');

  const reopened = await navigation(playerUrl);
  assert.equal(backLink(await reopened.render()).href, teamUrl);
  assert.deepEqual(reopened.calls, ['/players/81187']);
});

test('players listing opens a player that returns to the players listing', async () => {
  const page = await navigation('/players');
  await page.router.push(hrefTo(await page.render(), '/players/81187'));
  const back = backLink(await page.render());
  assert.equal(back.href, '/players');
  assert.match(back.label, /Volver a jugadores/);
  await page.router.push(back.href);
  assert.match(await page.render(), /<h1>Jugadores<\/h1>/);
});

test('direct links and invalid origins return to the corresponding local listing', async () => {
  for (const [path, fallback] of [['/team/610', '/team'], ['/players/81187', '/players']]) {
    for (const origin of [null, 'https://example.com', '//example.com', '/login', path, ['/team', '/players']]) {
      const page = await navigation({ path, query: origin === null ? {} : { from: origin } });
      assert.equal(backLink(await page.render()).href, fallback);
    }
  }
});

test('restricted competition returns to the source team with its context and the complete import notice', async () => {
  const origin = '/team/610?from=/competitions/2001';
  const page = await navigation(origin);
  const competitionUrl = hrefTo(await page.render(), '/competitions/2145');
  await page.router.push(competitionUrl);
  await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, origin);
  const html = await page.render();
  assert.match(html, /Galatasaray SK/);
  assert.match(html, /Resultado de importación/);
  assert.match(html, /SOURCE_ACCESS_DENIED/);
  assert.ok(html.includes(restrictedMessage));
  assert.match(html, /Importación #55/);
  assert.doesNotMatch(html, /No query results|App\\Models/);
  page.notices.clear();
  assert.doesNotMatch(await page.render(), /Resultado de importación|Please check your subscription/);
});

test('restricted competition returns to the source player and renders the provider message there', async () => {
  const origin = '/players/81187?from=/team/610';
  const page = await navigation(origin, { '/players/81187': { player: { ...team.squad[0], currentTeam: team }, participations: [] } });
  await page.router.push(hrefTo(await page.render(), '/competitions/2145'));
  await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, origin);
  const html = await page.render();
  assert.match(html, /FICHA DEL JUGADOR/);
  assert.ok(html.includes(restrictedMessage));
});

test('pending first import stays on the competition until a failed poll redirects with the import message', async () => {
  const pending = { ...unavailableCompetition, import: { id: 55, status: 'pending' } };
  const page = await navigation('/competitions/2145', {
    '/competitions/2145': pending, '/imports/55': restrictedImport,
  });
  const html = await page.render();
  assert.equal(page.router.currentRoute.value.path, '/competitions/2145');
  assert.match(html, /Actualizando en segundo plano/);
  const state = page.state('CompetitionDetail');
  await state.poll(state.scope);
  assert.equal(page.router.currentRoute.value.path, '/competitions');
  assert.deepEqual(page.calls, ['/competitions/2145', '/imports/55']);
  assert.ok((await page.render()).includes(restrictedMessage));
});

test('404, 403 and 503 without published data return to a safe origin and keep the API message', async () => {
  for (const status of [404, 403, 503]) {
    const page = await navigation('/competitions/2145?from=/team', {
      '/competitions/2145': () => { throw Object.assign(new Error('Detailed API diagnosis ' + status), { status, code: 'SOURCE_ERROR' }); },
    });
    await page.render();
    assert.equal(page.router.currentRoute.value.path, '/team');
    assert.match(await page.render(), new RegExp('Detailed API diagnosis ' + status));
  }
});

test('empty response and external origins use the competition listing without a redirect loop', async () => {
  const page = await navigation('/competitions/2145?from=https://example.com', { '/competitions/2145': null });
  await page.render();
  assert.equal(page.router.currentRoute.value.path, '/competitions');
  assert.match(await page.render(), /El proveedor no devolvió información de esta competición/);
  assert.equal(page.calls.filter(path => path === '/competitions/2145').length, 1);
});

test('failed refresh keeps a published competition on screen and shows its detailed error', async () => {
  const page = await navigation('/competitions/2001', { '/competitions/2001': { ...payloads['/competitions/2001'], import: restrictedImport } });
  const html = await page.render();
  assert.equal(page.router.currentRoute.value.path, '/competitions/2001');
  assert.ok(html.includes(restrictedMessage));
  assert.match(html, /Snapshot publicado #17/);
  assert.match(html, /Galatasaray SK/);
  assert.equal(page.notices.notice, null);
});

const unavailableTeam = { team: { id: 8772, name: 'Equipo #8772', first_published_at: null }, has_data: false, participations: [], import: restrictedImport };

test('unavailable team returns to the source competition with a team import notice', async () => {
  const data = structuredClone(payloads['/competitions/2001']);
  data.snapshot.teams.push({ id: 8772, name: 'Linked team', players: [] });
  const page = await navigation('/competitions/2001', { '/competitions/2001': data, '/team/8772': unavailableTeam });
  await page.router.push(hrefTo(await page.render(), '/team/8772'));
  await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, '/competitions/2001');
  const html = await page.render();
  assert.match(html, /No se pudo cargar el equipo #8772/);
  assert.match(html, /SOURCE_ACCESS_DENIED/);
  assert.ok(html.includes(restrictedMessage));
  assert.match(html, /Importación #55/);
  assert.match(html, /Snapshot publicado #17/);
  assert.doesNotMatch(html, /App\\Models|No query results/);
});

test('unavailable current team returns to the player that linked it and keeps the original context', async () => {
  const origin = '/players/81187?from=/team/610';
  const playerData = { player: { ...team.squad[0], currentTeam: { id: 8772, name: 'Unpublished club' } }, participations: [] };
  const page = await navigation(origin, { '/players/81187': playerData, '/team/8772': unavailableTeam });
  await page.router.push(hrefTo(await page.render(), '/team/8772'));
  await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, origin);
  const html = await page.render();
  assert.match(html, /FICHA DEL JUGADOR/);
  assert.match(html, /No se pudo cargar el equipo #8772/);
  assert.ok(html.includes(restrictedMessage));
});

test('initial team import stays open while pending and returns to teams when a failed poll completes', async () => {
  const page = await navigation('/team/8772', {
    '/team/8772': { ...unavailableTeam, import: { id: 55, status: 'pending' } }, '/imports/55': restrictedImport,
  });
  const html = await page.render();
  assert.equal(page.router.currentRoute.value.path, '/team/8772');
  assert.match(html, /Actualizando en segundo plano/);
  await page.state('TeamDetail').poll();
  assert.equal(page.router.currentRoute.value.path, '/team');
  assert.deepEqual(page.calls, ['/team/8772', '/imports/55']);
  assert.match(await page.render(), /No se pudo cargar el equipo #8772/);
});

test('unknown team 404 and empty response return to a safe origin with the public diagnosis', async () => {
  for (const failedResponse of [null, () => { throw Object.assign(new Error('No hay información publicada de este equipo.'), { status: 404, code: 'NOT_FOUND' }); }]) {
    const page = await navigation('/team/8772?from=https://example.com', { '/team/8772': failedResponse });
    await page.render();
    assert.equal(page.router.currentRoute.value.path, '/team');
    assert.match(await page.render(), /No se pudo cargar el equipo #8772/);
    assert.equal(page.calls.filter(path => path === '/team/8772').length, 1);
  }
});

test('a failed refresh retains existing team data on its detail page', async () => {
  const page = await navigation('/team/610', { '/team/610': { ...payloads['/team/610'], has_data: true, import: restrictedImport } });
  const html = await page.render();
  assert.equal(page.router.currentRoute.value.path, '/team/610');
  assert.match(html, /Galatasaray SK/);
  assert.match(html, /Jugador de prueba/);
  assert.ok(html.includes(restrictedMessage));
  assert.equal(page.notices.notice, null);
});

const unavailablePlayer = { player: { id: 81187, name: 'Jugador #81187', first_published_at: null }, has_data: false, participations: [], import: restrictedImport };

test('unavailable player returns to the source squad and preserves the team competition context', async () => {
  const origin = '/team/610?from=/competitions/2001';
  const page = await navigation(origin, { '/players/81187': unavailablePlayer });
  await page.router.push(hrefTo(await page.render(), '/players/81187'));
  await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, origin);
  const html = await page.render();
  assert.match(html, /Galatasaray SK/);
  assert.match(html, /No se pudo cargar el jugador #81187/);
  assert.match(html, /SOURCE_ACCESS_DENIED/);
  assert.ok(html.includes(restrictedMessage));
  assert.match(html, /Importación #55/);
  assert.equal(backLink(html).href, '/competitions/2001');
  assert.doesNotMatch(html, /App\\Models|No query results/);
});

test('unavailable player opened from the players listing returns there with the import diagnosis', async () => {
  const page = await navigation('/players', { '/players/81187': unavailablePlayer });
  await page.router.push(hrefTo(await page.render(), '/players/81187'));
  await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, '/players');
  const html = await page.render();
  assert.match(html, /<h1>Jugadores<\/h1>/);
  assert.match(html, /No se pudo cargar el jugador #81187/);
  assert.ok(html.includes(restrictedMessage));
});

test('initial person import stays on the player page until a failed poll returns to players', async () => {
  const page = await navigation('/players/81187', {
    '/players/81187': { ...unavailablePlayer, import: { id: 55, status: 'pending' } }, '/imports/55': restrictedImport,
  });
  const html = await page.render();
  assert.equal(page.router.currentRoute.value.path, '/players/81187');
  assert.match(html, /Actualizando en segundo plano/);
  await page.state('PlayerDetail').poll();
  assert.equal(page.router.currentRoute.value.path, '/players');
  assert.deepEqual(page.calls, ['/players/81187', '/imports/55']);
  assert.ok((await page.render()).includes(restrictedMessage));
});

test('successful first person import reloads the published player and keeps the shirt number', async () => {
  let completed = false;
  const page = await navigation('/players/81187', {
    '/players/81187': () => ({ data: completed
      ? { player: { ...team.squad[0], shirt_number: 5 }, has_data: true, participations: [], import: { id: 55, status: 'completed' } }
      : { ...unavailablePlayer, import: { id: 55, status: 'pending' } } }),
    '/imports/55': () => { completed = true; return { data: { id: 55, status: 'completed' } }; },
  });
  await page.render();
  await page.state('PlayerDetail').poll();
  assert.equal(page.router.currentRoute.value.path, '/players/81187');
  assert.equal(page.state('PlayerDetail').data.value.has_data, true);
  assert.equal(page.state('PlayerDetail').data.value.player.shirt_number, 5);
  assert.deepEqual(page.calls, ['/players/81187', '/imports/55', '/players/81187']);
  const html = await page.render();
  assert.match(html, /<dt[^>]*>Dorsal<\/dt><dd[^>]*>5<\/dd>/);
  assert.equal(page.notices.notice, null);
});

test('unavailable person HTTP errors and empty responses return to a safe local destination', async () => {
  const responses = [null, ...[403, 404, 503].map(status => () => {
    throw Object.assign(new Error('Person API diagnosis ' + status), { status, code: 'SOURCE_ERROR' });
  })];
  for (const response of responses) {
    const page = await navigation('/players/81187?from=https://example.com', { '/players/81187': response });
    await page.render();
    assert.equal(page.router.currentRoute.value.path, '/players');
    const html = await page.render();
    assert.match(html, /No se pudo cargar el jugador #81187/);
    assert.match(html, response ? /Person API diagnosis|SOURCE_ERROR/ : /El proveedor no devolvió información de este jugador/);
    assert.equal(page.calls.filter(path => path === '/players/81187').length, 1);
  }
});

test('failed person refresh keeps the existing player, shirt number and detailed error on screen', async () => {
  const page = await navigation('/players/81187?from=/team/610', {
    '/players/81187': { player: { ...team.squad[0], shirt_number: 5 }, has_data: true, participations: [], import: restrictedImport },
  });
  const html = await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, '/players/81187?from=/team/610');
  assert.match(html, /FICHA DEL JUGADOR/);
  assert.match(html, /Jugador de prueba/);
  assert.match(html, /<dt[^>]*>Dorsal<\/dt><dd[^>]*>5<\/dd>/);
  assert.ok(html.includes(restrictedMessage));
  assert.equal(page.notices.notice, null);
});

test('transient network failure on a player page shows a retry without redirecting', async () => {
  const page = await navigation('/players/81187?from=/team/610', {
    '/players/81187': () => { throw new Error('timeout of 25000ms exceeded'); },
  });
  const html = await page.render();
  assert.equal(page.router.currentRoute.value.fullPath, '/players/81187?from=/team/610');
  assert.match(html, /timeout of 25000ms exceeded/);
  assert.match(html, /Volver a intentar/);
  assert.equal(page.notices.notice, null);
});
