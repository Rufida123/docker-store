// Sends push notifications through Firebase Cloud Messaging (FCM).
// Optional: without a Firebase service account the API works exactly the same,
// notifications are only stored and shown inside the app.
const fs = require('fs');

// Firebase says these tokens will never work again: the browser unsubscribed,
// the user blocked notifications, or the token expired.
const DEAD_TOKEN_ERRORS = new Set([
  'messaging/registration-token-not-registered',
  'messaging/invalid-registration-token',
]);

let messaging; // undefined = not tried yet, null = disabled

// Reads the service account file once, on first use.
// FIREBASE_SERVICE_ACCOUNT_FILE: path to the JSON key downloaded from the Firebase console.
function getMessagingClient() {
  if (messaging !== undefined) return messaging;
  messaging = null;

  const file = process.env.FIREBASE_SERVICE_ACCOUNT_FILE;
  if (!file || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
    console.log('Push notifications are off: no Firebase service account file (see README).');
    return messaging;
  }

  try {
    const { initializeApp, cert } = require('firebase-admin/app');
    const { getMessaging } = require('firebase-admin/messaging');
    const serviceAccount = JSON.parse(fs.readFileSync(file, 'utf8'));
    const app = initializeApp({ credential: cert(serviceAccount) }, 'push');
    messaging = getMessaging(app);
    console.log(`Push notifications are on (Firebase project: ${serviceAccount.project_id}).`);
  } catch (err) {
    console.error('Push notifications are off: the Firebase service account file is invalid:', err.message);
  }
  return messaging;
}

function isEnabled() {
  return Boolean(getMessagingClient());
}

// Sends one message to every token. All data values must be strings (FCM rule).
// It is a data-only message: the browser's service worker decides how to show it.
// Returns the tokens Firebase rejected for good, so the caller can delete them.
async function sendToTokens(tokens, data) {
  const client = getMessagingClient();
  if (!client || !tokens.length) return { sent: 0, deadTokens: [] };

  const response = await client.sendEachForMulticast({
    tokens,
    data,
    webpush: { headers: { Urgency: 'high', TTL: String(24 * 60 * 60) } },
  });

  const deadTokens = [];
  response.responses.forEach((r, i) => {
    if (!r.success && r.error && DEAD_TOKEN_ERRORS.has(r.error.code)) deadTokens.push(tokens[i]);
  });
  return { sent: response.successCount, deadTokens };
}

module.exports = { isEnabled, sendToTokens };
