// Service worker for push notifications (Firebase Cloud Messaging).
// The browser runs it even when no tab of the store is open.
// The server sends data-only messages: { title, body, url, order_id, status, notification_id }.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

const windowClients = () => self.clients.matchAll({ type: 'window', includeUncontrolled: true });

self.addEventListener('push', (event) => {
  let data = {};
  try {
    const payload = event.data ? event.data.json() : {};
    data = payload.data || payload; // FCM wraps the fields in "data"
  } catch (_) {
    data = {};
  }

  event.waitUntil(
    (async () => {
      const windows = await windowClients();
      // Every open tab refreshes its bell; a focused tab shows the message itself.
      windows.forEach((w) => w.postMessage({ type: 'push', data }));
      if (windows.some((w) => w.focused)) return;

      await self.registration.showNotification(data.title || 'New notification', {
        body: data.body || '',
        icon: '/notification-icon.png',
        badge: '/notification-icon.png',
        tag: data.notification_id || undefined, // the same notification never shows twice
        data: { url: data.url || '/notifications' },
      });
    })()
  );
});

// Clicking the notification opens the order: in an open tab if there is one, else a new tab.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/notifications';

  event.waitUntil(
    (async () => {
      const windows = await windowClients();
      const tab = windows.find((w) => new URL(w.url).origin === self.location.origin);
      if (tab) {
        await tab.focus();
        tab.postMessage({ type: 'open', url });
        return;
      }
      await self.clients.openWindow(url);
    })()
  );
});
