import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createPinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createServer } from 'vite';

let server;
let component;
let api;
let useSession;
let originalGet;

before(async () => {
  server = await createServer({
    server: { middlewareMode: true, watch: null },
    appType: 'custom',
    logLevel: 'silent',
  });
  component = (await server.ssrLoadModule('/src/views/CompetitionDetail.vue')).default;
  ({ api } = await server.ssrLoadModule('/src/lib/api.js'));
  ({ useSession } = await server.ssrLoadModule('/src/stores/session.js'));
  originalGet = api.get;
});

after(async () => {
  if (api) api.get = originalGet;
  await server?.close();
});

const publishedData = () => ({
  competition: {
    id: 2013,
    name: 'Campeonato Brasileiro Série A',
    type: 'LEAGUE',
    code: 'BSA',
    season: { id: 2026, start_date: '2026-01-28', end_date: '2026-12-02' },
    snapshot_id: 9,
    source_updated_at: '2026-10-10T15:00:00Z',
    import_status: 'published',
    needs_refresh: false,
  },
  import: { id: 9, status: 'completed' },
  snapshot: {
    id: 9,
    published_at: '2026-10-11T15:00:00Z',
    season: { id: 2026, start_date: '2026-01-28', end_date: '2026-12-02' },
    teams_count: 2,
    players_count: 2,
    teams: [
      { id: 1765, name: 'Fluminense FC', tla: 'FLU', players: [{ id: 1001, name: 'Jugador publicado', position: null, shirt_number: null }] },
      { id: 1770, name: 'Botafogo FR', tla: 'BOT', players: [{ id: 1002, name: 'Segundo jugador', position: 'Midfield', shirt_number: 0 }] },
    ],
  },
});

async function page(data, manage = false) {
  const calls = [];
  api.get = async path => {
    calls.push(path);
    return { data };
  };
  let state;
  const view = {
    ...component,
    async setup(props, context) {
      if (!state) {
        state = component.setup(props, context);
        await Promise.resolve();
      }
      return state;
    },
  };
  const pinia = createPinia();
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/competitions/:id', component: { render: () => null } },
      { path: '/:pathMatch(.*)*', component: { render: () => null } },
    ],
  });
  const render = () => renderToString(createSSRApp(view).use(pinia).use(router));
  useSession(pinia).apply({ abilities: [{ action: manage ? 'manage' : 'read', subject: 'Football' }] });
  await router.push('/competitions/2013');
  await router.isReady();
  const html = await render();
  state.stopPolling();
  return { render, state, html, calls };
}

test('published competition links team cards directly to their external-ID profiles for readonly users', async () => {
  const { html, calls } = await page(publishedData());
  assert.match(html, /2 equipos · 2 jugadores/);
  assert.match(html, /Fluminense FC/);
  assert.match(html, /Botafogo FR/);
  assert.match(html, /<a\b[^>]*href="\/team\/1765\?from=\/competitions\/2013"[^>]*>[\s\S]*?Fluminense FC[\s\S]*?<\/a>/);
  assert.match(html, /<a\b[^>]*href="\/team\/1770\?from=\/competitions\/2013"[^>]*>[\s\S]*?Botafogo FR[\s\S]*?<\/a>/);
  assert.match(html, /FLU · 1 jugador/);
  assert.doesNotMatch(html, /<(?:select|option|details|summary|table)\b/);
  assert.doesNotMatch(html, /Actualizar competición/);
  assert.deepEqual(calls, ['/competitions/2013']);
});

test('team search filters the published teams and gives an explicit empty state', async () => {
  const { render, state } = await page(publishedData());
  state.search.value = 'flu';
  const filtered = await render();
  assert.match(filtered, /Fluminense FC/);
  assert.doesNotMatch(filtered, /Botafogo FR/);
  state.search.value = 'equipo inexistente';
  assert.match(await render(), /No hay equipos que coincidan con la búsqueda/);
});

test('competition exposes refresh to managers and local update and provider dates', async () => {
  const { html } = await page(publishedData(), true);
  const panel = html.match(/<section\b[^>]*aria-label="Actualización y publicación"[^>]*>([\s\S]*?)<\/section>/)?.[1];
  assert.ok(panel);
  assert.match(panel, /Refrescar información/);
  assert.match(panel, /Última actualización/);
  assert.match(panel, /10:00/);
  assert.match(panel, /Actualización del proveedor/);
  assert.match(panel, /Estado de importación/);
  assert.match(panel, /Snapshot publicado #9/);
});

test('unpublished competition explains the missing publication without inventing teams', async () => {
  const data = publishedData();
  data.snapshot = null;
  data.competition.snapshot_id = null;
  data.competition.import_status = 'not_imported';
  data.import = null;
  const { html } = await page(data);
  assert.match(html, /No existe todavía una versión publicada/);
  assert.doesNotMatch(html, /Equipos de la competición/);
  assert.doesNotMatch(html, /Fluminense FC/);
});

test('an outdated publication labels its own season while retaining its teams', async () => {
  const data = publishedData();
  data.competition.needs_refresh = true;
  data.competition.season.start_date = '2027-01-28';
  const { html } = await page(data);
  assert.match(html, /Temporada actual: 2027-01-28/);
  assert.match(html, /Temporada publicada: 2026-01-28/);
  assert.match(html, /Estos datos corresponden a una temporada anterior/);
  assert.match(html, /Fluminense FC/);
});

test('malformed squad imports show the provider diagnosis after reload and preserve published team links', async () => {
    const data = publishedData();
    data.import = {
      id: 14,
      status: 'failed',
      error_code: 'SOURCE_SQUAD_INVALID',
      error_message: 'El proveedor devolvió una estructura de plantilla inválida para 1 equipo. No se publicó la importación.',
      quality_summary: { received_teams: 36, invalid_squads_count: 1, players_count: 7 },
    };
    data.competition.import_status = 'failed';
    const { html, calls } = await page(data);
    assert.match(html, /SOURCE_SQUAD_INVALID/);
    assert.match(html, /estructura de plantilla inválida para 1 equipo/);
    const failedState = html.match(/<span\b(?=[^>]*role="status")([^>]*)>Fallida<\/span>/)?.[1];
    assert.ok(failedState);
    assert.match(failedState, /class="[^"]*\bfailed\b[^"]*"/);
    assert.doesNotMatch(failedState, /class="[^"]*\bgood\b[^"]*"/);
    assert.doesNotMatch(html, /Consulta el detalle de la importación/);
    assert.match(html, /href="\/team\/1765\?from=\/competitions\/2013"/);
    assert.deepEqual(calls, ['/competitions/2013']);
});

test('published competition keeps direct club links and explains unavailable squads without an import error', async () => {
  const data = publishedData();
  data.snapshot.teams[0].players = [];
  data.snapshot.players_count = 1;
  data.snapshot.quality_summary = { missing_squads_count: 1, empty_squads_count: 0 };
  const { html, calls } = await page(data);
  assert.match(html, /El proveedor no entregó jugadores para 1 equipo/);
  assert.match(html, /Fluminense FC/);
  assert.match(html, /href="\/team\/1765\?from=\/competitions\/2013"/);
  assert.match(html, /href="\/team\/1770\?from=\/competitions\/2013"/);
  assert.match(html, /FLU · Plantilla no disponible/);
  assert.match(html, /BOT · 1 jugador/);
  assert.match(html, /Completada/);
  assert.doesNotMatch(html, /SOURCE_SQUAD_|No se publicó la importación/);
  assert.deepEqual(calls, ['/competitions/2013']);
});

test('completed import reloads and renders its newly published teams', async () => {
  const data = publishedData();
  data.snapshot = null;
  data.competition.snapshot_id = null;
  data.import.status = 'processing';
  const { state, render } = await page(data);
  const calls = [];
  api.get = async path => {
    calls.push(path);
    return { data: path.startsWith('/imports/') ? { id: 9, status: 'completed' } : publishedData() };
  };
  await state.poll(state.scope);
  state.stopPolling();
  assert.match(await render(), /Fluminense FC/);
  assert.deepEqual(calls, ['/imports/9', '/competitions/2013']);
});
