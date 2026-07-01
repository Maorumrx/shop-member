<?php

declare(strict_types=1);

// Phase 2 auth — LiffVerifyService unit-ish tests (resolved via the container).
// Exercises the server-side verification of a LINE LIFF id-token against LINE's
// official verify endpoint, which is ALWAYS Http::fake'd — never hit the network.
// See App\Services\Line\LiffVerifyService.

use App\Exceptions\LineAuthException;
use App\Services\Line\LiffVerifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// Namespaced unique names — every Pest file shares one process, so top-level
// const/function declarations must not collide across the suite.
const SVC_VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';
const SVC_ISSUER = 'https://access.line.me';
const SVC_CHANNEL_ID = '1234567890';

/**
 * Build a valid decoded id-token payload, allowing per-test overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function svcLinePayload(array $overrides = []): array
{
    return array_merge([
        'sub' => 'U1111111111111111111111111111111',
        'name' => 'Somchai',
        'picture' => 'https://line.example/pic.jpg',
        'aud' => SVC_CHANNEL_ID,
        'iss' => SVC_ISSUER,
        'exp' => now()->addHour()->getTimestamp(),
    ], $overrides);
}

/**
 * Configure the expected login channel id (the audience the verifier asserts).
 */
function svcSetLineChannel(?string $id = SVC_CHANNEL_ID): void
{
    config()->set('services.line.login_channel_id', $id);
}

function svcLiff(): LiffVerifyService
{
    return app(LiffVerifyService::class);
}

it('returns trusted profile claims for a valid token', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(svcLinePayload(), 200),
    ]);

    $result = svcLiff()->verify('valid-id-token');

    expect($result)->toBe([
        'line_user_id' => 'U1111111111111111111111111111111',
        'name' => 'Somchai',
        'picture' => 'https://line.example/pic.jpg',
    ]);

    // The request was made to LINE with the form-encoded id_token + client_id.
    Http::assertSent(function ($request) {
        return $request->url() === SVC_VERIFY_URL
            && $request['id_token'] === 'valid-id-token'
            && $request['client_id'] === SVC_CHANNEL_ID;
    });
});

it('returns null name/picture when LINE omits them', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(
            svcLinePayload(['name' => null, 'picture' => null]),
            200
        ),
    ]);

    $result = svcLiff()->verify('valid-id-token');

    expect($result['name'])->toBeNull();
    expect($result['picture'])->toBeNull();
    expect($result['line_user_id'])->toBe('U1111111111111111111111111111111');
});

it('fails closed and makes NO http call when the channel id is null', function () {
    svcSetLineChannel(null);
    Http::fake();

    expect(fn () => svcLiff()->verify('any-token'))
        ->toThrow(LineAuthException::class);

    // A config slip must never degrade into an auth bypass — nothing leaves us.
    Http::assertNothingSent();
});

it('fails closed and makes NO http call when the channel id is an empty string', function () {
    svcSetLineChannel('');
    Http::fake();

    expect(fn () => svcLiff()->verify('any-token'))
        ->toThrow(LineAuthException::class);

    Http::assertNothingSent();
});

it('throws when the audience does not match our channel', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(svcLinePayload(['aud' => 'some-other-channel']), 200),
    ]);

    expect(fn () => svcLiff()->verify('replayed-token'))
        ->toThrow(LineAuthException::class);
});

it('throws when the issuer is not LINE', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(svcLinePayload(['iss' => 'https://evil.example']), 200),
    ]);

    expect(fn () => svcLiff()->verify('forged-iss-token'))
        ->toThrow(LineAuthException::class);
});

it('throws when exp is missing from the payload', function () {
    svcSetLineChannel();
    $payload = svcLinePayload();
    unset($payload['exp']);

    Http::fake([
        SVC_VERIFY_URL => Http::response($payload, 200),
    ]);

    expect(fn () => svcLiff()->verify('no-exp-token'))
        ->toThrow(LineAuthException::class);
});

it('throws when sub is missing', function () {
    svcSetLineChannel();
    $payload = svcLinePayload();
    unset($payload['sub']);

    Http::fake([
        SVC_VERIFY_URL => Http::response($payload, 200),
    ]);

    expect(fn () => svcLiff()->verify('no-sub-token'))
        ->toThrow(LineAuthException::class);
});

it('throws when sub is an empty string', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(svcLinePayload(['sub' => '']), 200),
    ]);

    expect(fn () => svcLiff()->verify('empty-sub-token'))
        ->toThrow(LineAuthException::class);
});

it('throws when sub is not a string', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(svcLinePayload(['sub' => 12345]), 200),
    ]);

    expect(fn () => svcLiff()->verify('numeric-sub-token'))
        ->toThrow(LineAuthException::class);
});

it('throws when LINE returns a 400 failed response', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'Invalid IdToken.',
        ], 400),
    ]);

    expect(fn () => svcLiff()->verify('expired-token'))
        ->toThrow(LineAuthException::class);
});

it('does not leak the LINE error body into the exception message', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'super secret leak detail',
        ], 400),
    ]);

    try {
        svcLiff()->verify('expired-token');
        $this->fail('Expected LineAuthException was not thrown.');
    } catch (LineAuthException $e) {
        expect($e->getMessage())->not->toContain('super secret leak detail');
        expect($e->getMessage())->not->toContain('invalid_request');
    }
});

// ---------------------------------------------------------------------------
// Structured warning logging on failure (bugfix chain — observability).
//
// Each of the six failure branches now emits a single Log::warning BEFORE it
// throws, carrying event=line_liff_verify_failed + a distinct reason slug so
// the failure is triageable from logs alone. The exception behaviour above is
// unchanged; these tests lock in the log side-effect AND — critically — that
// the context can never carry the raw id-token or member PII (sub/name/picture).
//
// This suite has no Log::fake() in this Laravel version, so we swap in a
// Mockery spy via Log::spy() (Facade::spy) and assert on what it received.
// ---------------------------------------------------------------------------

/**
 * The ONLY context keys any failure branch is permitted to log. A future edit
 * that adds a key (e.g. dumps the decoded payload) fails the whitelist check
 * below — that is the point of pinning it here.
 *
 * @var list<string>
 */
const SVC_ALLOWED_LOG_KEYS = [
    'event',
    'reason',
    'status',
    'line_error',
    'line_error_description',
    'expected_aud',
    'actual_aud',
    'actual_iss',
];

/**
 * Flatten every scalar VALUE in a (possibly nested) context array into one
 * string so a leak hidden inside a nested array/object is still caught. Used to
 * assert that no secret/PII substring appears ANYWHERE in what we logged.
 *
 * @param  array<string, mixed>  $context
 */
function svcFlattenLogValues(array $context): string
{
    return json_encode(array_values($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
}

it('logs a structured warning (reason=line_rejected) when LINE rejects the token', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'IdToken expired.',
        ], 400),
    ]);

    $log = Log::spy();

    // Behaviour preserved: still throws.
    expect(fn () => svcLiff()->verify('expired-token'))
        ->toThrow(LineAuthException::class);

    // New behaviour: exactly one warning, with the triage context.
    $log->shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'LINE LIFF verification failed.'
                && ($context['event'] ?? null) === 'line_liff_verify_failed'
                && ($context['reason'] ?? null) === 'line_rejected'
                && ($context['status'] ?? null) === 400
                && ($context['line_error'] ?? null) === 'invalid_request'
                && ($context['line_error_description'] ?? null) === 'IdToken expired.';
        });
});

it('logs a structured warning (reason=aud_mismatch) when the audience does not match', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(
            svcLinePayload(['aud' => 'some-other-channel']),
            200
        ),
    ]);

    $log = Log::spy();

    expect(fn () => svcLiff()->verify('replayed-token'))
        ->toThrow(LineAuthException::class);

    $log->shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'LINE LIFF verification failed.'
                && ($context['event'] ?? null) === 'line_liff_verify_failed'
                && ($context['reason'] ?? null) === 'aud_mismatch'
                && ($context['expected_aud'] ?? null) === SVC_CHANNEL_ID
                && ($context['actual_aud'] ?? null) === 'some-other-channel';
        });
});

it('logs a structured warning (reason=channel_unconfigured) and sends nothing when the channel is unset', function () {
    svcSetLineChannel(null);
    Http::fake();

    $log = Log::spy();

    expect(fn () => svcLiff()->verify('any-token'))
        ->toThrow(LineAuthException::class);

    // A config slip logs the slug and never calls LINE.
    Http::assertNothingSent();
    $log->shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return ($context['event'] ?? null) === 'line_liff_verify_failed'
                && ($context['reason'] ?? null) === 'channel_unconfigured';
        });
});

it('NEVER logs the raw id-token or member PII in the aud-mismatch context (PII regression guard)', function () {
    svcSetLineChannel();

    // A recognisable token and a payload stuffed with member PII. If ANY of
    // these values reaches the log, the assertions below fail loudly.
    $secretToken = 'SECRET-TOKEN-123';
    Http::fake([
        SVC_VERIFY_URL => Http::response(svcLinePayload([
            'aud' => 'some-other-channel', // force the aud_mismatch branch
            'sub' => 'U-PII-SUBJECT-9999',
            'name' => 'PII Full Name',
            'picture' => 'https://line.example/pii-avatar.jpg',
        ]), 200),
    ]);

    $log = Log::spy();

    expect(fn () => svcLiff()->verify($secretToken))
        ->toThrow(LineAuthException::class);

    $log->shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($secretToken): bool {
            $flat = svcFlattenLogValues($context);

            // 1) No secret token and no member PII anywhere in the context values.
            $noLeak = ! str_contains($flat, $secretToken)
                && ! str_contains($flat, 'U-PII-SUBJECT-9999')
                && ! str_contains($flat, 'PII Full Name')
                && ! str_contains($flat, 'pii-avatar.jpg');

            // 2) Context keys are limited to the safe whitelist (future edits
            //    that add a key must consciously extend SVC_ALLOWED_LOG_KEYS).
            $keysWhitelisted = array_diff(array_keys($context), SVC_ALLOWED_LOG_KEYS) === [];

            return $noLeak && $keysWhitelisted;
        });
});

it('NEVER logs the raw id-token or the LINE error body verbatim on line_rejected (PII regression guard)', function () {
    svcSetLineChannel();

    $secretToken = 'SECRET-TOKEN-123';
    Http::fake([
        SVC_VERIFY_URL => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'IdToken expired.',
        ], 400),
    ]);

    $log = Log::spy();

    expect(fn () => svcLiff()->verify($secretToken))
        ->toThrow(LineAuthException::class);

    $log->shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($secretToken): bool {
            $flat = svcFlattenLogValues($context);

            // The raw id-token must never reach the log, and context keys stay
            // within the whitelist (line_error/line_error_description ARE allowed
            // — they are LINE's own diagnostic strings, not member PII).
            return ! str_contains($flat, $secretToken)
                && array_diff(array_keys($context), SVC_ALLOWED_LOG_KEYS) === [];
        });
});

it('logs NOTHING on the happy path', function () {
    svcSetLineChannel();
    Http::fake([
        SVC_VERIFY_URL => Http::response(svcLinePayload(), 200),
    ]);

    $log = Log::spy();

    $result = svcLiff()->verify('valid-id-token');

    // Behaviour preserved: the trusted profile is returned...
    expect($result)->toBe([
        'line_user_id' => 'U1111111111111111111111111111111',
        'name' => 'Somchai',
        'picture' => 'https://line.example/pic.jpg',
    ]);

    // ...and a successful verification is silent (no warning, no log at all).
    $log->shouldNotHaveReceived('warning');
    $log->shouldNotHaveReceived('error');
    $log->shouldNotHaveReceived('info');
});
