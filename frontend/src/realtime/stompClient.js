// Real-time channel to RabbitMQ Web-STOMP (the broker checks every subscription with the backend).
// One STOMP client per logged-in session, shared by every hook that listens:
//   const close = openChannel(userId, '/exchange/store.realtime/admin.orders', { onMessage, onConnect, onDown });
// The page keeps working over normal REST when this channel is unavailable.
import { Client } from '@stomp/stompjs';
import { getStoredAuth } from '../api/client';

// ws://<current host>:15674/ws (wss:// when the page is served over https). Override at build time with VITE_REALTIME_URL.
const scheme = window.location.protocol === 'https:' ? 'wss' : 'ws';
export const REALTIME_URL = import.meta.env.VITE_REALTIME_URL || `${scheme}://${window.location.hostname}:15674/ws`;

let session = null; // { userId, client, connected, channels: Set<channel> }

function subscribeChannel(s, ch) {
  try {
    ch.sub = s.client.subscribe(ch.destination, (frame) => {
      let msg;
      try {
        msg = JSON.parse(frame.body);
      } catch {
        return; // not one of ours
      }
      ch.onMessage?.(msg?.data || {}, msg);
    });
  } catch {
    ch.sub = null; // not connected right now: the next (re)connect subscribes again
  }
}

// The connection ended (broker error frame, refused subscription or plain close).
function down(s) {
  if (session !== s) return;
  s.connected = false;
  s.channels.forEach((ch) => {
    ch.sub = null;
    ch.onDown?.();
  });
}

function createSession(userId) {
  const s = { userId, client: null, connected: false, channels: new Set() };
  s.client = new Client({
    brokerURL: REALTIME_URL,
    reconnectDelay: 5000,
    heartbeatIncoming: 10000,
    heartbeatOutgoing: 10000,
    // Runs before every (re)connect: always send the CURRENT access token (it expires every few minutes).
    beforeConnect: (client) => {
      const auth = getStoredAuth();
      if (!auth?.token || (auth.user?.id && auth.user.id !== userId)) {
        client.deactivate(); // signed out, or another user signed in: never reuse this connection
        return;
      }
      client.connectHeaders = { login: userId, passcode: auth.token };
    },
    onConnect: () => {
      if (session !== s) return;
      s.connected = true;
      s.channels.forEach((ch) => subscribeChannel(s, ch));
      s.channels.forEach((ch) => ch.onConnect?.());
    },
    onStompError: () => down(s),
    onWebSocketClose: () => down(s),
  });
  s.client.activate();
  return s;
}

/**
 * Listens on one destination. Returns a function that stops listening.
 * onMessage(data, message)  data = message.data ({ type, orderId, status? })
 * onConnect()               every time the channel is (re)connected: reload what was missed over REST
 * onDown()                  connection lost or refused: show "live updates unavailable"
 */
export function openChannel(userId, destination, { onMessage, onConnect, onDown } = {}) {
  if (session && session.userId !== userId) closeRealtime();
  if (!session) session = createSession(userId);
  const s = session;
  const ch = { destination, onMessage, onConnect, onDown, sub: null };
  s.channels.add(ch);
  if (s.connected) {
    subscribeChannel(s, ch);
    onConnect?.();
  }
  return () => {
    s.channels.delete(ch);
    try {
      ch.sub?.unsubscribe();
    } catch {
      /* connection already gone */
    }
    ch.sub = null;
    if (session === s && s.channels.size === 0) closeRealtime(); // last listener left
  };
}

/** Closes the shared connection (logout, session expired, last page left). */
export function closeRealtime() {
  const s = session;
  session = null;
  if (!s) return;
  s.connected = false;
  s.channels.forEach((ch) => {
    ch.sub = null;
    ch.onDown?.();
  });
  s.channels.clear();
  s.client.deactivate();
}