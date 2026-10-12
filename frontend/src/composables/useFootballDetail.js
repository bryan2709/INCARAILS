import { computed, onUnmounted, ref, watch } from 'vue';
import { api } from '../lib/api';

const activeStatuses = ['pending', 'processing', 'waiting_worker', 'waiting_rate_limit'];

export function useFootballDetail(path, { isAvailable = () => true, onUnavailable } = {}) {
  const data = ref(null);
  const loading = ref(true);
  const error = ref('');
  const job = ref(null);
  const posting = ref(false);
  const refreshing = computed(() => posting.value || activeStatuses.includes(job.value?.status));
  let controller;
  let scope = 0;
  let timer = null;

  async function handleFailure(failure, context) {
    if (context !== scope) return;
    if (onUnavailable && !isAvailable(data.value) && [403, 404, 503].includes(failure.status)) {
      stopPolling();
      await onUnavailable(failure);
    } else error.value = failure.message;
  }

  function stopPolling() {
    if (timer !== null) clearTimeout(timer);
    timer = null;
  }

  function schedulePoll(context) {
    if (context !== scope || !refreshing.value || timer !== null) return;
    timer = setTimeout(() => poll(context), 3000);
  }

  async function read(context) {
    const result = await api.get(path(), { signal: controller.signal });
    if (context !== scope) return;
    data.value = result.data;
    job.value = result.data?.import || null;
    if (onUnavailable && !isAvailable(data.value) && !refreshing.value) {
      stopPolling();
      await onUnavailable(job.value);
      return;
    }
    if (refreshing.value) schedulePoll(context);
    else stopPolling();
  }

  async function load() {
    const context = scope;
    loading.value = true;
    error.value = '';
    try {
      await read(context);
    } catch (failure) {
      await handleFailure(failure, context);
    } finally {
      if (context === scope) loading.value = false;
    }
  }

  async function refreshInformation() {
    if (refreshing.value || loading.value) return;
    const context = scope;
    posting.value = true;
    error.value = '';
    try {
      const result = await api.post(path() + '/refresh', {}, { signal: controller.signal });
      if (context !== scope) return;
      job.value = result.data;
      schedulePoll(context);
    } catch (failure) {
      await handleFailure(failure, context);
    } finally {
      if (context === scope) posting.value = false;
    }
  }

  async function poll(context = scope) {
    if (context !== scope || !job.value) return;
    stopPolling();
    try {
      const result = await api.get('/imports/' + job.value.id, { signal: controller.signal });
      if (context !== scope) return;
      job.value = result.data;
      error.value = '';
      if (job.value.status === 'failed' && onUnavailable && !isAvailable(data.value)) {
        await onUnavailable(job.value);
        return;
      }
      if (['completed', 'failed'].includes(job.value.status)) await read(context);
    } catch (failure) {
      await handleFailure(failure, context);
    }
    schedulePoll(context);
  }

  watch(path, endpoint => {
    controller?.abort();
    controller = new AbortController();
    scope += 1;
    stopPolling();
    data.value = null;
    job.value = null;
    posting.value = false;
    if (endpoint) load();
  }, { immediate: true });

  onUnmounted(() => { scope += 1; controller?.abort(); stopPolling(); });

  return { data, loading, error, job, refreshing, load, refreshInformation, poll, stopPolling };
}
