import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const destinations = [
  { pattern: /^\/competitions$/, label: 'Volver a competiciones' },
  { pattern: /^\/competitions\/[1-9]\d*$/, label: 'Volver a la competición' },
  { pattern: /^\/team$/, label: 'Volver a equipos' },
  { pattern: /^\/team\/[1-9]\d*$/, label: 'Volver al equipo' },
  { pattern: /^\/players$/, label: 'Volver a jugadores' },
  { pattern: /^\/players\/[1-9]\d*$/, label: 'Volver al jugador' },
];

export function useDetailNavigation(fallback, useHistory = false) {
  const route = useRoute();
  const router = useRouter();
  const defaultBack = { to: fallback, label: destinations.find(item => item.pattern.test(fallback)).label };
  function originDestination(origin) {
    if (typeof origin !== 'string' || !/^\/(?!\/)/.test(origin)) return null;

    const destination = router.resolve(origin);
    const entry = destinations.find(item => item.pattern.test(destination.path));
    if (!entry || destination.path === route.path) return null;

    return { to: destination.fullPath, label: entry.label };
  }
  const back = computed(() => originDestination(route.query.from)
    || (useHistory && originDestination(router.options.history.state.back)) || defaultBack);

  function detailLink(path) {
    if (router.resolve(back.value.to).path === path) return back.value.to;
    return { path, query: { from: route.fullPath } };
  }

  return { back, detailLink };
}
