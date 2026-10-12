import axios from 'axios';

export const api = axios.create({ baseURL: '/api', timeout: 25000, headers: { Accept: 'application/json' } });
let token = null;
let expired = null;

export function setToken(value) { token = value; }
export function onSessionExpired(fn) { expired = fn; }

api.interceptors.request.use(config => {
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(response => response.data, error => {
  if (error.response?.status === 401 && token) {
    token = null;
    expired?.();
  }
  const body = error.response?.data;
  const failure = new Error(body?.message || error.message || 'Error de conexión');
  failure.status = error.response?.status || 0;
  failure.code = body?.error?.code || null;
  failure.details = body?.error?.details || null;
  return Promise.reject(failure);
});
