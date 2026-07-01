<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Exceptions\LineAuthException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side verification of a LINE LIFF ID token.
 *
 * The Vue front-end obtains an ID token in the browser via the LIFF SDK
 * (`liff.getIDToken()`) and POSTs it to us. A token from the client must NEVER
 * be trusted as-is; we re-verify it against LINE's official verify endpoint,
 * which validates the signature and `exp`, and returns the decoded payload:
 *
 *   POST https://api.line.me/oauth2/v2.1/verify
 *   (application/x-www-form-urlencoded) id_token, client_id
 *
 * @see https://developers.line.biz/en/reference/line-login/#verify-id-token
 * @see https://developers.line.biz/en/docs/liff/using-user-profile/#getting-id-token
 */
final class LiffVerifyService
{
    /**
     * LINE's ID-token verification endpoint.
     */
    private const VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';

    /**
     * The only valid issuer for a LINE-issued ID token.
     */
    private const EXPECTED_ISSUER = 'https://access.line.me';

    /**
     * Verify a LIFF ID token and return the trusted profile claims.
     *
     * LINE's endpoint validates the JWT signature and `exp` for us and 400s an
     * expired/forged token; we additionally assert `aud` (the audience must be
     * OUR login channel) and `iss` (must be LINE) so a token minted for a
     * different channel cannot be replayed against this app, then re-check that
     * `exp` is present as defence-in-depth.
     *
     * @param  string  $idToken  The raw ID token from `liff.getIDToken()`.
     * @return array{line_user_id: string, name: string|null, picture: string|null}
     *
     * @throws LineAuthException When the request fails or the payload's
     *                           issuer/audience/expiry do not validate.
     *
     * @see https://developers.line.biz/en/reference/line-login/#verify-id-token
     */
    public function verify(string $idToken): array
    {
        $channelId = config('services.line.login_channel_id');

        // Fail CLOSED if the channel isn't configured — otherwise the audience
        // check below degrades to `null !== null` and silently accepts tokens
        // minted for ANY channel (a config slip must never become an auth bypass).
        if (! is_string($channelId) || $channelId === '') {
            // Config slip, not a token problem — no request was even sent to LINE.
            Log::warning('LINE LIFF verification failed.', [
                'event' => 'line_liff_verify_failed',
                'reason' => 'channel_unconfigured',
            ]);

            throw new LineAuthException('LINE login channel is not configured.');
        }

        $response = Http::asForm()->post(self::VERIFY_URL, [
            'id_token' => $idToken,
            'client_id' => $channelId,
        ]);

        if ($response->failed()) {
            // LINE returns 400 with {error, error_description} for an invalid,
            // expired, or channel-mismatched token. Do not leak the body.
            //
            // error/error_description are LINE's own diagnostic strings (e.g.
            // "IdToken expired.", "invalid channel id") and carry no member PII;
            // they are the single most useful signal for triaging this failure.
            Log::warning('LINE LIFF verification failed.', [
                'event' => 'line_liff_verify_failed',
                'reason' => 'line_rejected',
                'status' => $response->status(),
                'line_error' => $response->json('error'),
                'line_error_description' => $response->json('error_description'),
            ]);

            throw new LineAuthException(
                'LINE ID token verification request failed.'
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        // Audience must be our login channel — rejects tokens minted for a
        // different LINE channel being replayed against this app.
        if (($payload['aud'] ?? null) !== $channelId) {
            // aud is a channel id, not member PII — safe to log both sides so a
            // token-minted-for-another-channel misconfiguration is obvious.
            Log::warning('LINE LIFF verification failed.', [
                'event' => 'line_liff_verify_failed',
                'reason' => 'aud_mismatch',
                'expected_aud' => $channelId,
                'actual_aud' => $payload['aud'] ?? null,
            ]);

            throw new LineAuthException('LINE ID token audience mismatch.');
        }

        // Issuer must be LINE.
        if (($payload['iss'] ?? null) !== self::EXPECTED_ISSUER) {
            // iss identifies LINE, not the member — safe to log the actual value.
            Log::warning('LINE LIFF verification failed.', [
                'event' => 'line_liff_verify_failed',
                'reason' => 'iss_mismatch',
                'actual_iss' => $payload['iss'] ?? null,
            ]);

            throw new LineAuthException('LINE ID token issuer mismatch.');
        }

        // `exp` is validated by LINE's endpoint; re-assert its presence as a
        // structural guard against a malformed/short payload slipping through.
        if (! isset($payload['exp'])) {
            // Structural anomaly — no claim value logged (nothing here is PII-free
            // AND useful; the reason slug alone identifies the failure).
            Log::warning('LINE LIFF verification failed.', [
                'event' => 'line_liff_verify_failed',
                'reason' => 'missing_exp',
            ]);

            throw new LineAuthException('LINE ID token is missing an expiry.');
        }

        // `sub` (the stable LINE user id) is required to identify the member.
        if (! isset($payload['sub']) || ! is_string($payload['sub']) || $payload['sub'] === '') {
            // NEVER log the sub itself (it is the member's stable LINE id = PII);
            // the reason slug is enough to flag a malformed payload.
            Log::warning('LINE LIFF verification failed.', [
                'event' => 'line_liff_verify_failed',
                'reason' => 'missing_sub',
            ]);

            throw new LineAuthException('LINE ID token is missing the subject.');
        }

        return [
            'line_user_id' => $payload['sub'],
            'name' => isset($payload['name']) && is_string($payload['name'])
                ? $payload['name']
                : null,
            'picture' => isset($payload['picture']) && is_string($payload['picture'])
                ? $payload['picture']
                : null,
        ];
    }
}
