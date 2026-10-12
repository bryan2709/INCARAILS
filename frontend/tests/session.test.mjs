import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createPinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createServer } from 'vite';
import { sessionGuard } from '../src/lib/sessionGuard.js';

let server;
let api;
let onSessionExpired;
let useSession;
let originalAdapter;
const person = { id: 7, name: 'Session user', abilities: [{ action: 'read', subject: 'Football' }] };

before(async () => {
  server = await createServer({ server: { middlewareMode: true, watch: null }, appType: 'custom', logLevel: 'silent' });
  ({ api, onSessionExpired } = await server.ssrLoadModule('/src/lib/api.js'));
  ({ useSession } = await server.ssrLoadModule('/src/stores/session.js'));
  originalAdapter = api.defaults.adapter;
});

after(async () => {
  if (api) api.defaults.adapter = originalAdapter;
  await server?.close();
});

function respond(config, data) {
  return { data: { success: true, data, message: 'OK', meta: {}, error: null }, status: 200, statusText: 'OK', headers: {}, config };
}

function fail(config, status, message = 'API failure', code = 'UNAUTHENTICATED') {
  throw Object.assign(new Error(message), { config, response: { status, data: { message, error: { code } } } });
}

function session() { return useSession(createPinia()); }

function routerFor(state) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', component: {}, meta: { public: true } },
      { path: '/competitions', component: {}, meta: { permission: 'football.view' } },
      { path: '/team/:id', component: {}, meta: { permission: 'football.view' } },
    ],
  });
  router.beforeEach(sessionGuard(() => state));
  return router;
}

test('a reloaded page restores the cookie session before navigating and preserves the full detail URL', async () => {
  const calls = [];
  api.defaults.adapter = async config => {
    calls.push(config.url);
    assert.equal(config.withCredentials, true);
    assert.equal(config.headers.get('Authorization'), undefined);
    return respond(config, person);
  };
  const state = session();
  assert.equal(state.signedIn, false);
  const router = routerFor(state);
  await router.push('/team/610?from=/competitions/2001');
  await router.isReady();
  assert.equal(router.currentRoute.value.fullPath, '/team/610?from=/competitions/2001');
  assert.equal(state.signedIn, true);
  assert.equal(state.can('football.view'), true);
  await router.push('/competitions');
  assert.deepEqual(calls, ['/auth/me']);
});

test('a missing cookie restores an anonymous state and redirects to login without an expiry notice', async () => {
  const state = session();
  let expired = 0;
  onSessionExpired(() => { expired += 1; });
  api.defaults.adapter = async config => fail(config, 401);
  const router = routerFor(state);
  await router.push('/team/610');
  assert.equal(router.currentRoute.value.fullPath, '/login');
  assert.equal(state.initialized, true);
  assert.equal(state.signedIn, false);
  assert.equal(expired, 0);
});

test('login prepares CSRF before posting credentials and requires no access token in the JSON response', async () => {
  const calls = [];
  api.defaults.adapter = async config => {
    calls.push([config.method, config.url]);
    assert.equal(config.headers.get('Authorization'), undefined);
    if (config.url === '/auth/login') {
      assert.deepEqual(JSON.parse(config.data), { email: 'cookie@test.local', password: 'password-demo-123' });
      return respond(config, { user: person, expires_in: 7200 });
    }
    return respond(config, null);
  };
  const state = session();
  const result = await state.login('cookie@test.local', 'password-demo-123');
  assert.deepEqual(calls, [['get', '/auth/csrf-cookie'], ['post', '/auth/login']]);
  assert.equal(result.access_token, undefined);
  assert.equal(state.signedIn, true);
  assert.equal(state.initialized, true);
});

test('an expired CSRF token is renewed once before retrying the rejected mutation', async () => {
  const calls = [];
  let posts = 0;
  api.defaults.adapter = async config => {
    calls.push(config.url);
    if (config.url === '/auth/csrf-cookie') return respond(config, null);
    posts += 1;
    if (posts === 1) fail(config, 419, 'Session protection expired.', 'CSRF_TOKEN_MISMATCH');
    assert.equal(config.csrfRetried, true);
    assert.equal(config.headers.get('X-CSRF-TOKEN'), undefined);
    return respond(config, { id: 55, status: 'pending' });
  };
  const result = await api.post('/team/610/refresh', {}, { headers: { 'X-CSRF-TOKEN': 'stale' } });
  assert.equal(result.data.id, 55);
  assert.deepEqual(calls, ['/team/610/refresh', '/auth/csrf-cookie', '/team/610/refresh']);
});

test('persistent CSRF rejection stops after one retry and exposes the public diagnosis', async () => {
  let prepares = 0;
  let posts = 0;
  api.defaults.adapter = async config => {
    if (config.url === '/auth/csrf-cookie') { prepares += 1; return respond(config, null); }
    posts += 1;
    fail(config, 419, 'Session protection expired.', 'CSRF_TOKEN_MISMATCH');
  };
  await assert.rejects(api.post('/team/610/refresh', {}), failure => failure.status === 419 && failure.code === 'CSRF_TOKEN_MISMATCH');
  assert.equal(prepares, 1);
  assert.equal(posts, 2);
});

test('an expired authenticated session clears capabilities and redirects to login only once', async () => {
  const previous = globalThis.window;
  const redirects = [];
  globalThis.window = { location: { assign: path => redirects.push(path) } };
  try {
    const state = session();
    state.apply(person);
    api.defaults.adapter = async config => fail(config, 401);
    await assert.rejects(api.get('/team/610'));
    await assert.rejects(api.get('/team/610'));
    assert.equal(state.signedIn, false);
    assert.equal(state.can('football.view'), false);
    assert.deepEqual(redirects, ['/login?expired=1']);
  } finally {
    if (previous === undefined) delete globalThis.window;
    else globalThis.window = previous;
  }
});

test('logout clears the user after server confirmation and retains the session on network failure', async () => {
  const state = session();
  state.apply(person);
  api.defaults.adapter = async config => { throw Object.assign(new Error('Network unavailable'), { config }); };
  await assert.rejects(state.logout(), /Network unavailable/);
  assert.equal(state.signedIn, true);
  api.defaults.adapter = async config => respond(config, null);
  await state.logout();
  assert.equal(state.signedIn, false);
  assert.equal(state.can('football.view'), false);
});

test('a network failure during restoration stays retryable and routes to an explanatory login state', async () => {
  const state = session();
  api.defaults.adapter = async config => { throw Object.assign(new Error('Network unavailable'), { config }); };
  const router = routerFor(state);
  await router.push('/team/610');
  assert.equal(router.currentRoute.value.path, '/login');
  assert.equal(router.currentRoute.value.query.unavailable, '1');
  assert.equal(state.initialized, false);
  api.defaults.adapter = async config => respond(config, person);
  await state.restore();
  assert.equal(state.signedIn, true);
});

test('concurrent restoration shares one request to the server', async () => {
  let requests = 0;
  api.defaults.adapter = async config => {
    requests += 1;
    await new Promise(resolve => setImmediate(resolve));
    return respond(config, person);
  };
  const state = session();
  await Promise.all([state.restore(), state.restore(), state.restore()]);
  assert.equal(requests, 1);
  assert.equal(state.signedIn, true);
});
