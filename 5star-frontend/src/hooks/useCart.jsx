import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../lib/api';

/**
 * One shared cart for the whole storefront: the header count, the Add /
 * − qty + controls on every product card, the cart page and checkout all read
 * the same state, so they never disagree. The server stays the source of
 * truth — every change is a cart API call followed by a refresh.
 */
const CartContext = createContext(null);

const TOAST_MS = 2600;

export function CartProvider({ children, refreshKey }) {
  const [cart, setCart] = useState(null);
  const [toast, setToast] = useState(null);
  const [busy, setBusy] = useState(false);
  const toastTimer = useRef(null);
  const variantCache = useRef(new Map());

  const refresh = useCallback(async () => {
    try {
      const response = await api.get('/cart');
      setCart(response.data);
      return response.data;
    } catch {
      setCart(null);
      return null;
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh, refreshKey]);

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  const showToast = useCallback((text, kind = 'added') => {
    clearTimeout(toastTimer.current);
    setToast({ text, kind });
    toastTimer.current = setTimeout(() => setToast(null), kind === 'error' ? 4000 : TOAST_MS);
  }, []);

  const lines = useMemo(
    () => ((cart && cart.items) || []).filter((item) => !item.is_saved_for_later),
    [cart],
  );

  const count = useMemo(() => lines.reduce((total, item) => total + Number(item.quantity || 0), 0), [lines]);

  /** First cart line for a product — what a product card's stepper controls. */
  const lineForProduct = useCallback(
    (productUuid) => lines.find((item) => item.product && item.product.uuid === productUuid) || null,
    [lines],
  );

  const add = useCallback(
    async (variantUuid, quantity = 1, label = 'Item') => {
      setBusy(true);
      try {
        await api.post('/cart/items', { variant_uuid: variantUuid, quantity });
        await refresh();
        showToast(`${label} added`);
        return true;
      } catch (err) {
        showToast(err.message || 'Could not add that to your cart.', 'error');
        return false;
      } finally {
        setBusy(false);
      }
    },
    [refresh, showToast],
  );

  /**
   * Adds a product from a list card, which only knows the product. The card
   * shows the smallest pack and its price, so that is the pack added; it is
   * looked up once and remembered. A product that needs a size / colour
   * choice is never added here: it returns 'choose' so the caller can open
   * the product page.
   */
  const addProduct = useCallback(
    async (slug, label) => {
      let variantUuid = variantCache.current.get(slug);
      if (!variantUuid) {
        try {
          const response = await api.get(`/products/${encodeURIComponent(slug)}`);
          const variants = response.data.product.variants || [];
          // Never pick a size / colour for the shopper: send them to choose.
          if (response.data.product.requires_choice && variants.length > 1) {
            showToast('Please choose a size first.');
            return 'choose';
          }
          const chosen = [...variants].sort(
            (a, b) => Number(a.effective_price) - Number(b.effective_price),
          )[0];
          if (!chosen) throw new Error('This product has no pack to buy yet.');
          variantUuid = chosen.uuid;
          variantCache.current.set(slug, variantUuid);
        } catch (err) {
          showToast(err.message || 'Could not add that to your cart.', 'error');
          return false;
        }
      }
      return add(variantUuid, 1, label);
    },
    [add, showToast],
  );

  const setQuantity = useCallback(
    async (itemUuid, quantity) => {
      setBusy(true);
      try {
        if (quantity <= 0) {
          await api.delete(`/cart/items/${itemUuid}`);
        } else {
          await api.patch(`/cart/items/${itemUuid}`, { quantity });
        }
        await refresh();
      } catch (err) {
        showToast(err.message || 'Could not update your cart.', 'error');
        await refresh();
      } finally {
        setBusy(false);
      }
    },
    [refresh, showToast],
  );

  const value = useMemo(
    () => ({ cart, lines, count, busy, toast, refresh, add, addProduct, setQuantity, lineForProduct, showToast }),
    [cart, lines, count, busy, toast, refresh, add, addProduct, setQuantity, lineForProduct, showToast],
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const context = useContext(CartContext);
  if (!context) throw new Error('useCart must be used inside <CartProvider>.');
  return context;
}
