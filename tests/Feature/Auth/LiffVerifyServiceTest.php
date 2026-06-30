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
