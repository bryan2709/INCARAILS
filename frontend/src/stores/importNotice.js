import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useImportNotice = defineStore('importNotice', () => {
  const notice = ref(null);
  function show(value) { notice.value = value; }
  function clear() { notice.value = null; }
  return { notice, show, clear };
});
