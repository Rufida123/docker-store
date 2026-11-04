<?php

namespace App\Modules\Notifications;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\WebPushConfig;
use Throwable;

/**
 * Sends push notifications through Firebase Cloud Messaging (FCM).
 * Optional: without a Firebase service account the API works exactly the same,
 * notifications are only stored and shown inside the app.
 */
class NotificationsPush
{
    private static bool $resolved = false;

    private static ?object $messaging = null;

    private static string $statusLine = '';

    /**
     * Reads the service account file once per process, on first use.
     * FIREBASE_SERVICE_ACCOUNT_FILE: path to the JSON key downloaded from the
     * Firebase console.
     */
    private static function client(): ?object
    {
        if (self::$resolved) {
            return self::$messaging;
        }
        self::$resolved = true;

        $file = (string) config('store.firebase.service_account_file');
        if ($file === '' || ! is_file($file)) {
            self::$statusLine = 'Push notifications are off: no Firebase service account file (see README).';
            Log::info(self::$statusLine);

            return self::$messaging;
        }

        try {
            $serviceAccount = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::$messaging = (new Factory)->withServiceAccount($file)->createMessaging();
            self::$statusLine = 'Push notifications are on (Firebase project: '.($serviceAccount['project_id'] ?? '').').';
            Log::info(self::$statusLine);
        } catch (Throwable $e) {
            self::$statusLine = 'Push notifications are off: the Firebase service account file is invalid: '.$e->getMessage();
            Log::error(self::$statusLine);
        }

        return self::$messaging;
    }

    public static function isEnabled(): bool
    {
        return self::client() !== null;
    }

    /**
     * The one-line status announced at startup.
     */
    public static function statusLine(): string
    {
        self::client();

        return self::$statusLine;
    }

    /**
     * Sends one message to every token. All data values must be strings (FCM
     * rule). It is a data-only message: the browser's service worker decides how
     * to show it. Returns the tokens Firebase rejected for good, so the caller
     * can delete them.
     *
     * @return array{sent: int, deadTokens: list<string>}
     */
    public static function sendToTokens(array $tokens, array $data): array
    {
        $client = self::client();
        if ($client === null || $tokens === []) {
            return ['sent' => 0, 'deadTokens' => []];
        }

        $message = CloudMessage::new()
            ->withData($data)
            ->withWebPushConfig(WebPushConfig::fromArray([
                'headers' => ['Urgency' => 'high', 'TTL' => (string) (24 * 60 * 60)],
            ]));

        $report = $client->sendMulticast($message, $tokens);

        // Firebase says these tokens will never work again: the browser
        // unsubscribed, the user blocked notifications, or the token expired.
        $deadTokens = [...$report->unknownTokens(), ...$report->invalidTokens()];

        return ['sent' => $report->successes()->count(), 'deadTokens' => array_values(array_unique($deadTokens))];
    }
}
