import axios from 'axios';

export const api = axios.create({
  baseURL: '/api', timeout: 25000, withCredentials: true, withXSRFToken: true,
  headers: { Accept: 'application/json' },
});
let expired = null;
let csrfRequest = null;

export function onSessionExpired(fn) { expired = fn; }

export function prepareCsrf() {
  if (!csrfRequest) {
    csrfRequest = api.get('/auth/csrf-cookie', { skipSessionExpired: true })
      .finally(() => { csrfRequest = null; });
  }
  return csrfRequest;
}

api.interceptors.response.use(response => response.data, async error => {
  const config = error.config;
  if (error.response?.status === 419 && config && !config.csrfRetried && config.url !== '/auth/csrf-cookie') {
    await prepareCsrf();
    config.csrfRetried = true;
    delete config.headers['X-XSRF-TOKEN'];
    delete config.headers['X-CSRF-TOKEN'];
    return api.request(config);
  }
  if (error.response?.status === 401 && !config?.skipSessionExpired && config?.url !== '/auth/login') {
    expired?.();
  }
  const body = error.response?.data;
  const failure = new Error(body?.message || error.message || 'Error de conexión');
  failure.status = error.response?.status || 0;
  failure.code = body?.error?.code || null;
  failure.details = body?.error?.details || null;
  return Promise.reject(failure);
});
