import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';

/** Mirrors ui.js's refreshCartCount: counts items that aren't saved-for-later. */
export function useCartCount(refreshKey) {
  const [count, setCount] = useState(0);

  const refresh = useCallback(() => {
    api
      .get('/cart')
      .then((response) => {
        const items = (response.data.items || []).filter((item) => !item.is_saved_for_later);
        setCount(items.reduce((total, item) => total + Number(item.quantity || 0), 0));
      })
      .catch(() => setCount(0));
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh, refreshKey]);

  return { count, refresh };
}
