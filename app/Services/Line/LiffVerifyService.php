<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Exceptions\LineAuthException;
use Illuminate\Support\Facades\Http;

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
            throw new LineAuthException('LINE login channel is not configured.');
        }

        $response = Http::asForm()->post(self::VERIFY_URL, [
            'id_token' => $idToken,
            'client_id' => $channelId,
        ]);

        if ($response->failed()) {
            // LINE returns 400 with {error, error_description} for an invalid,
            // expired, or channel-mismatched token. Do not leak the body.
            throw new LineAuthException(
                'LINE ID token verification request failed.'
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        // Audience must be our login channel — rejects tokens minted for a
        // different LINE channel being replayed against this app.
        if (($payload['aud'] ?? null) !== $channelId) {
            throw new LineAuthException('LINE ID token audience mismatch.');
        }

        // Issuer must be LINE.
        if (($payload['iss'] ?? null) !== self::EXPECTED_ISSUER) {
            throw new LineAuthException('LINE ID token issuer mismatch.');
        }

        // `exp` is validated by LINE's endpoint; re-assert its presence as a
        // structural guard against a malformed/short payload slipping through.
        if (! isset($payload['exp'])) {
            throw new LineAuthException('LINE ID token is missing an expiry.');
        }

        // `sub` (the stable LINE user id) is required to identify the member.
        if (! isset($payload['sub']) || ! is_string($payload['sub']) || $payload['sub'] === '') {
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
