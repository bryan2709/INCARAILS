import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { Ability } from '@casl/ability';
import { api, prepareCsrf, onSessionExpired } from '../lib/api';

export const ability = new Ability([]);

export const useSession = defineStore('session', () => {
  const user = ref(null);
  const signedIn = computed(() => Boolean(user.value));
  const initialized = ref(false);
  let restoring = null;

  function apply(value) {
    user.value = value;
    initialized.value = true;
    ability.update(value?.abilities || []);
  }

  async function restore() {
    if (initialized.value) return user.value;
    if (!restoring) {
      restoring = api.get('/auth/me', { skipSessionExpired: true })
        .then(result => { apply(result.data); return user.value; })
        .catch(failure => {
          if (![401, 403].includes(failure.status)) throw failure;
          apply(null);
          return null;
        })
        .finally(() => { restoring = null; });
    }
    return restoring;
  }

  async function login(email, password) {
    await prepareCsrf();
    const result = await api.post('/auth/login', { email, password });
    apply(result.data.user);
    return result.data;
  }

  async function logout() {
    try {
      await api.post('/auth/logout');
    } catch (failure) {
      if (failure.status !== 401) throw failure;
    }
    apply(null);
  }

  onSessionExpired(() => {
    if (!user.value) return;
    apply(null);
    if (typeof window !== 'undefined') window.location.assign('/login?expired=1');
  });

  const permissions = {
    'football.view': ['read', 'Football'],
    'football.manage': ['manage', 'Football'],
    'administration.manage': ['manage', 'Administration'],
  };

  return {
    user, signedIn, initialized, restore, login, logout, apply,
    can(code) {
      if (!user.value) return false;
      const rule = permissions[code];
      return rule ? ability.can(rule[0], rule[1]) : false;
    },
  };
});
