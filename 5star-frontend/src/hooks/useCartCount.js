import { useCart } from './useCart';

/**
 * Kept for the pages that only need the header count and a way to refresh
 * it; backed by the shared cart so every view stays in step.
 */
export function useCartCount() {
  const { count, refresh } = useCart();
  return { count, refresh };
}
