<?php

declare(strict_types=1);

namespace App\Services\Line;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side push via the LINE Messaging API (the shop's Official Account),
 * SEPARATE from the LIFF Login channel ({@see LiffVerifyService}). This sends
 * a message to a member's LINE inbox:
 *
 *   POST https://api.line.me/v2/bot/message/push
 *   Authorization: Bearer <channel access token>
 *   { "to": <lineUserId>, "messages": [ { "type": "text", "text": <text> } ] }
 *
 * A push only reaches a member who (a) has a stored `line_user_id` AND (b) added
 * the OA as a friend. Every push is therefore BEST-EFFORT: this service NEVER
 * throws to the caller. It FAILS SAFE — like {@see LiffVerifyService} fails
 * closed on a missing config — by returning false without side effects whenever
 * the token is unset, the user id is empty, or LINE rejects/errors the request.
 * A blocked or not-friend recipient (HTTP 403) must never break the triggering
 * action (login / booking / redemption); the caller treats false as "not sent"
 * and moves on.
 *
 * @see https://developers.line.biz/en/reference/messaging-api/#send-push-message
 */
final class LineMessagingService
{
    /**
     * LINE's push-message endpoint.
     */
    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    /**
     * Short HTTP timeout (seconds). Pushes run inside a queue worker, but we
     * still cap the wait so a slow/hung LINE endpoint can't stall the worker.
     */
    private const TIMEOUT_SECONDS = 5;

    /**
     * Push a single plain-text message to `$lineUserId`. Returns true only on a
     * 2xx from LINE; false in EVERY other case — and never throws.
     *
     * Fail-safe ladder (any rung returns false, nothing propagates):
     *   1. Token unset/empty  → return false WITHOUT calling LINE (fail closed,
     *      mirroring LiffVerifyService's "not configured" guard).
     *   2. Empty `$lineUserId` → return false WITHOUT calling (member not linked).
     *   3. Transport error (DNS/timeout/TLS) → caught, logged as a warning, false.
     *   4. Non-2xx response (esp. 403 = not a friend / blocked / invalid token,
     *      429 = rate limit) → logged as a warning, false.
     *
     * @param  string  $lineUserId  The member's stable LINE user id (`sub`).
     * @param  string  $text        The message body (already-built Thai copy).
     * @return bool                 True iff LINE accepted the push (2xx).
     */
    public function pushText(string $lineUserId, string $text): bool
    {
        $token = config('services.line.messaging_channel_access_token');

        // Fail CLOSED when the Messaging API channel isn't configured — never
        // attempt a call with an empty bearer (which LINE would 401 anyway). An
        // unconfigured token means "pushes disabled", not an error to the caller.
        if (! is_string($token) || $token === '') {
            return false;
        }

        // No linked LINE identity → nothing to push to. Best-effort no-op.
        if ($lineUserId === '') {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post(self::PUSH_URL, [
                    'to' => $lineUserId,
                    'messages' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ]);
        } catch (Throwable $e) {
            // Transport-level failure (DNS, timeout, TLS, connection refused).
            // Best-effort: log and swallow so the triggering action is untouched.
            Log::warning('LINE push failed (transport error).', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            // Non-2xx. 403 = recipient is not a friend / blocked the OA, or the
            // token is invalid; 429 = rate limited. Log the status (never the
            // token) for diagnosis, but keep it a silent best-effort failure.
            Log::warning('LINE push rejected (non-2xx response).', [
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
