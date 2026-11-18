import { useEffect, useMemo, useState } from 'react';
import { api } from '../api/client';
import { useAuth } from '../context/AuthContext';
import { invalidate, setQueryData } from '../hooks/useQuery';
import { openChannel } from './stompClient';

const MAX_LIVE = 50;
const CLOCK_TOLERANCE_MS = 1000; // message timestamps have second precision

/**
 * Admin dashboard feed. Pass the rows loaded over REST (items); get them back merged with what
 * arrived live: { orders, connected }.
 *  - order.created        -> the order is fetched (GET /orders/:id) and prepended
 *  - order.status_changed -> the status of that row is updated
 *  - every (re)connect    -> the list is reloaded over REST
 * accept(order) (optional, keep it stable) decides whether a live order belongs on the current page/filters.
 */
export function useAdminOrdersFeed({ enabled = true, items = [], accept } = {}) {
  const { user } = useAuth();
  const userId = user?.id;
  const [fresh, setFresh] = useState([]); // orders that arrived live
  const [overrides, setOverrides] = useState({}); // orderId -> { status, at }
  const [connected, setConnected] = useState(false);

  useEffect(() => {
    if (!enabled || !userId) return undefined;
    let alive = true;

    const close = openChannel(userId, '/exchange/store.realtime/admin.orders', {
      onConnect: () => {
        setConnected(true);
        invalidate('orders');
      },
      onDown: () => setConnected(false),
      onMessage: (data, msg) => {
        if (!data?.orderId) return;
        if (data.type === 'order.created') {
          api
            .getOrder(data.orderId)
            .then((order) => {
              if (!alive || !order?.id) return;
              setQueryData(['order', order.id], order);
              setFresh((prev) => [order, ...prev.filter((o) => o.id !== order.id)].slice(0, MAX_LIVE));
              invalidate('orders'); // counts / pages
            })
            .catch(() => invalidate('orders')); // fall back to a normal reload
        } else if (data.type === 'order.status_changed' && data.status) {
          const at = Date.parse(msg?.occurred_at) || Date.now();
          setOverrides((prev) => ({ ...prev, [data.orderId]: { status: data.status, at } }));
          invalidate('orders', 'order');
        }
      },
    });

    return () => {
      alive = false;
      close();
      setConnected(false);
    };
  }, [enabled, userId]);

  const orders = useMemo(() => {
    // A live status wins unless the row came back from the server with a clearly newer update.
    const apply = (o) => {
      const ov = overrides[o.id];
      if (!ov || ov.status === o.status) return o;
      const t = Date.parse(o.updated_at);
      if (Number.isFinite(t) && t > ov.at + CLOCK_TOLERANCE_MS) return o;
      return { ...o, status: ov.status };
    };
    const known = new Set(items.map((o) => o.id));
    const added = fresh
      .filter((o) => !known.has(o.id))
      .map(apply)
      .filter((o) => !accept || accept(o));
    return [...added, ...items.map(apply)];
  }, [items, fresh, overrides, accept]);

  return { orders, connected };
}