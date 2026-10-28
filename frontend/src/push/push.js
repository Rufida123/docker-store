// Web push notifications through Firebase Cloud Messaging (FCM).
// Only active when the Firebase web config was given at build time (VITE_FIREBASE_* in .env).
// The Firebase SDK is loaded on demand, so it costs nothing when push is not used.
import { api } from '../api/client';

const config = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
};
const VAPID_KEY = import.meta.env.VITE_FIREBASE_VAPID_KEY;
const SW_URL = '/firebase-messaging-sw.js';
const TOKEN_KEY = 'orders.pushToken'; // this browser's FCM token, to unregister it on sign out

export const pushConfigured = Boolean(
  config.apiKey && config.projectId && config.messagingSenderId && config.appId && VAPID_KEY
);

const readToken = () => {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
};
const writeToken = (token) => {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    /* storage unavailable: sign out simply won't unregister this browser */
  }
};

/**
 * Where push stands in this browser:
 * 'off'         no Firebase config in this build
 * 'insecure'    page not served over https or localhost (browsers require it)
 * 'unsupported' browser without push support
 * 'default'     not asked yet  |  'granted' allowed  |  'denied' blocked by the user
 */
export async function pushState() {
  if (!pushConfigured) return 'off';
  if (!window.isSecureContext) return 'insecure';
  if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
    return 'unsupported';
  }
  const { isSupported } = await import('firebase/messaging');
  if (!(await isSupported())) return 'unsupported';
  return Notification.permission;
}

let messagingPromise;
function getMessagingInstance() {
  if (!messagingPromise) {
    messagingPromise = Promise.all([import('firebase/app'), import('firebase/messaging')]).then(
      ([{ initializeApp }, { getMessaging }]) => getMessaging(initializeApp(config))
    );
  }
  return messagingPromise;
}

// Registers our service worker, gets this browser's FCM token and gives it to the API.
async function registerThisBrowser() {
  const [{ getToken }, messaging, registration] = await Promise.all([
    import('firebase/messaging'),
    getMessagingInstance(),
    navigator.serviceWorker.register(SW_URL),
  ]);
  const token = await getToken(messaging, { vapidKey: VAPID_KEY, serviceWorkerRegistration: registration });
  if (!token) throw new Error('The browser did not return a push token.');
  await api.registerDevice(token);
  writeToken(token);
}

// The last registration attempt, shared by everything that asks (so the page shows its real result).
let lastAttempt = null;
function register() {
  lastAttempt = registerThisBrowser();
  return lastAttempt;
}

/** Asks for permission (must run from a click), then registers this browser. Returns the permission. */
export async function enablePush() {
  const permission = await Notification.requestPermission();
  if (permission === 'granted') await register();
  return permission;
}

/** After sign-in / on app start: re-registers silently if permission was already given. */
export async function syncPush() {
  if ((await pushState()) === 'granted') await register();
}

/** Like pushState(), but 'granted' only if this browser is really registered ('failed' otherwise). */
export async function pushStatus() {
  const state = await pushState();
  if (state !== 'granted') return state;
  try {
    await (lastAttempt || register());
    return 'granted';
  } catch {
    return 'failed';
  }
}

/** On sign out: this browser stops receiving the account's notifications. Never blocks sign out. */
export function releasePush(authToken) {
  const token = readToken();
  writeToken(null);
  lastAttempt = null; // the next account to sign in registers again
  if (!token || !authToken) return;
  api.unregisterDevice(token, authToken).catch(() => {});
}
