import { api } from './api';

let methodsPromise = null;

/**
 * Which ways the shop verifies a customer (GET /auth/methods), fetched once
 * per page load. Resolves to null when the server can't be reached, and the
 * next call tries again.
 */
export function loadVerificationMethods() {
  if (!methodsPromise) {
    methodsPromise = api.get('/auth/methods').then((r) => r.data).catch(() => {
      methodsPromise = null;
      return null;
    });
  }
  return methodsPromise;
}
