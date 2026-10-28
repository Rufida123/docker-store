import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useToast } from './Toast';
import { notificationsChanged } from '../hooks/queries';
import { invalidate } from '../hooks/useQuery';
import { syncPush } from '../push/push';

const POLL_MS = 30_000;

// Mounted once for signed-in users. Renders nothing; it connects the app to push:
//  - re-registers this browser for push if the user already allowed notifications
//  - a push received while a tab is open refreshes the bell (and shows a toast in the focused tab)
//  - clicking a system notification opens the order in this tab
//  - without push, the bell still refreshes every 30 s while the tab is visible
export default function PushBridge() {
  const toast = useToast();
  const navigate = useNavigate();

  useEffect(() => {
    syncPush().catch(() => {}); // silent: the notifications page shows push problems
  }, []);

  useEffect(() => {
    if (!('serviceWorker' in navigator)) return undefined;
    const onMessage = (event) => {
      const msg = event.data || {};
      if (msg.type === 'push') {
        notificationsChanged();
        invalidate('orders', 'order'); // the order's status probably changed too
        if (document.hasFocus() && msg.data?.title) {
          toast(msg.data.body ? `${msg.data.title}: ${msg.data.body}` : msg.data.title, 'info');
        }
      } else if (msg.type === 'open' && typeof msg.url === 'string' && msg.url.startsWith('/')) {
        navigate(msg.url);
      }
    };
    navigator.serviceWorker.addEventListener('message', onMessage);
    return () => navigator.serviceWorker.removeEventListener('message', onMessage);
  }, [toast, navigate]);

  useEffect(() => {
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') notificationsChanged();
    }, POLL_MS);
    return () => clearInterval(timer);
  }, []);

  return null;
}
