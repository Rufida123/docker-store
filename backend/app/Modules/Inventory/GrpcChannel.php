<?php

namespace App\Modules\Inventory;

use App\Shared\InventoryError;
use App\Shared\InventoryTimeout;
use App\Shared\InventoryUnavailable;

use Google\Protobuf\Internal\Message;
use Illuminate\Support\Facades\Log;

/**
 * Minimal unary gRPC client over curl with HTTP/2 prior knowledge (h2c).
 *
 * A unary gRPC call is one HTTP/2 POST to /<package.Service>/<Method> with
 * content-type application/grpc, "te: trailers" and an optional grpc-timeout
 * header. The body is a 5-byte length-prefixed protobuf frame (1 byte
 * compressed flag + 4 bytes big-endian length + message). The outcome arrives
 * as grpc-status / grpc-message, in the HTTP/2 trailers — or in the headers
 * for a trailers-only error response; curl hands both to the header callback.
 *
 * The official grpc extension has no Windows build for PHP 8.4, which is why
 * this transport exists. Refusals are data in the response message; the
 * exceptions here mean the call itself failed.
 */
class GrpcChannel
{
    private const STATUS_OK = 0;

    private const STATUS_DEADLINE_EXCEEDED = 4;

    private const STATUS_UNAVAILABLE = 14;

    public function __construct(
        private readonly string $address,
        private readonly int $timeoutMs,
        private readonly int $connectTimeoutMs,
    ) {
        if (! (curl_version()['features'] & CURL_VERSION_HTTP2)) {
            throw new InventoryError('curl was built without HTTP/2 support; the inventory gRPC client cannot work');
        }
    }

    /**
     * Performs one unary call, decoding the reply into $response.
     *
     * @param string $fullMethod e.g. "/inventory.v1.Inventory/GetStock"
     */
    public function call(string $fullMethod, Message $request, Message $response): void
    {
        $startedAt = microtime(true);
        $outcome = 'ok';

        try {
            $this->send($fullMethod, $request, $response);
        } catch (\Throwable $e) {
            $outcome = class_basename($e).': '.$e->getMessage();
            throw $e;
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Log::info("inventory {$fullMethod} {$durationMs}ms {$outcome}");
        }
    }

    private function send(string $fullMethod, Message $request, Message $response): void
    {
        $payload = $request->serializeToString();
        $frame = "\x00".pack('N', strlen($payload)).$payload;

        $headers = [];
        $ch = curl_init('http://'.$this->address.$fullMethod);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $frame,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/grpc',
                'TE: trailers',
                'grpc-timeout: '.$this->timeoutMs.'m', // "m" = milliseconds
                'Expect:',
            ],
            // Headers AND HTTP/2 trailers both arrive here.
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new InventoryTimeout("inventory call timed out after {$this->timeoutMs}ms");
            }
            throw new InventoryUnavailable("could not reach the inventory service: {$error}");
        }

        $status = isset($headers['grpc-status']) ? (int) $headers['grpc-status'] : null;
        $message = isset($headers['grpc-message']) ? rawurldecode($headers['grpc-message']) : '';

        if ($status === null) {
            throw new InventoryError('response carried no grpc-status');
        }
        if ($status === self::STATUS_DEADLINE_EXCEEDED) {
            throw new InventoryTimeout($message !== '' ? $message : 'deadline exceeded');
        }
        if ($status === self::STATUS_UNAVAILABLE) {
            throw new InventoryUnavailable($message !== '' ? $message : 'inventory service unavailable');
        }
        if ($status !== self::STATUS_OK) {
            throw new InventoryError("grpc-status {$status}: {$message}");
        }

        if (strlen((string) $body) < 5) {
            throw new InventoryError('gRPC response body is missing its message frame');
        }
        if (ord($body[0]) !== 0) {
            throw new InventoryError('compressed gRPC responses are not supported');
        }
        $length = unpack('N', substr($body, 1, 4))[1];
        $response->mergeFromString(substr($body, 5, $length));
    }
}
