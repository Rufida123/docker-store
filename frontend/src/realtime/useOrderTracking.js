import { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { getQueryData, invalidate, setQueryData } from '../hooks/useQuery';
import { openChannel } from './stompClient';

/**
 * Live status of one order. Call it once the order was loaded over REST.
 * Returns { status, connected }: status is the latest status received live (null until one arrives);
 * the cached order (useOrder) is patched too, so the page updates by itself with no refresh.
 */
export function useOrderTracking(orderId, { enabled = true } = {}) {
  const { user } = useAuth();
  const userId = user?.id;
  const [status, setStatus] = useState(null);
  const [connected, setConnected] = useState(false);

  useEffect(() => {
    setStatus(null);
    if (!enabled || !userId || !orderId) return undefined;

    const close = openChannel(userId, `/exchange/store.realtime/order.${orderId}`, {
      // Messages sent while the page was closed are not replayed: load the order again on every (re)connect.
      onConnect: () => {
        setConnected(true);
        invalidate('order');
      },
      onDown: () => setConnected(false),
      onMessage: (data) => {
        if (data.type !== 'order.status_changed' || data.orderId !== orderId || !data.status) return;
        setStatus(data.status);
        const cached = getQueryData(['order', orderId]);
        if (cached) setQueryData(['order', orderId], { ...cached, status: data.status });
        invalidate('order', 'orders'); // also brings the new history entry
      },
    });

    return () => {
      close();
      setConnected(false);
    };
  }, [enabled, userId, orderId]);

  return { status, connected };
}