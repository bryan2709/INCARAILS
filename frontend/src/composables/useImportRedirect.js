import { useRoute, useRouter } from 'vue-router';
import { useDetailNavigation } from './useDetailNavigation';
import { useImportNotice } from '../stores/importNotice';

export function useImportRedirect(fallback, resource, emptyMessage) {
  const route = useRoute();
  const router = useRouter();
  const { back, detailLink } = useDetailNavigation(fallback, true);
  const imports = useImportNotice();

  async function returnToOrigin(failure) {
    const returnTo = back.value.to;
    imports.show({
      returnTo,
      resource: resource + ' #' + route.params.id,
      importId: failure?.id,
      code: failure?.error_code || failure?.code,
      message: failure?.error_message || failure?.message || emptyMessage,
    });
    await router.replace(returnTo);
  }

  return { back, detailLink, returnToOrigin };
}
