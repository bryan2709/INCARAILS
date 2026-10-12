export function sessionGuard(getSession) {
  return async to => {
    const session = getSession();
    try {
      await session.restore();
    } catch {
      return to.meta.public ? true : { path: '/login', query: { unavailable: '1' } };
    }
    if (!to.meta.public && !session.signedIn) return '/login';
    if (to.meta.public && session.signedIn) return '/competitions';
    if (to.meta.permission && !session.can(to.meta.permission)) return '/competitions';
  };
}
