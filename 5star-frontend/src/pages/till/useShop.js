import { useEffect, useState } from 'react';
import { api } from '../../lib/api';

let shopPromise = null;

/** Shop details + UPI ID for the till (GET /admin/pos/shop, fetched once per page). */
export function useShop() {
  const [shop, setShop] = useState(null);
  useEffect(() => {
    let live = true;
    if (!shopPromise) {
      shopPromise = api.get('/admin/pos/shop').then((r) => r.data).catch(() => {
        shopPromise = null;
        return null;
      });
    }
    shopPromise.then((data) => { if (live) setShop(data); });
    return () => { live = false; };
  }, []);
  return shop;
}
