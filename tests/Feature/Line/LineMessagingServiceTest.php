<?php

declare(strict_types=1);

// Phase — LINE push notifications. LineMessagingService::pushText() unit-ish tests
// (resolved via the container). Exercises the server-side push to LINE's Messaging
// API, which is ALWAYS Http::fake'd — never hit the network. The service is FAIL-
// SAFE: it NEVER throws and returns false for an unconfigured token / empty
// recipient / non-2xx / transport error; true only on a 2xx.
// See App\Services\Line\LineMessagingService.

use App\Services\Line\LineMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Namespaced unique names — every Pest file shares one process, so top-level
// const/function declarations must not collide across the suite.
const PUSH_URL = 'https://api.line.me/v2/bot/message/push';
const PUSH_TOKEN = 'test-channel-access-token';
const PUSH_USER_ID = 'U3333333333333333333333333333333';

/**
 * Set (or clear) the Messaging API channel access token the service reads from
 * config('services.line.messaging_channel_access_token').
 */
function pushSetToken(?string $token): void
{
    config()->set('services.line.messaging_channel_access_token', $token);
}

function pushService(): LineMessagingService
{
    return app(LineMessagingService::class);
}

it('returns false and makes NO http call when the token is null', function () {
    pushSetToken(null);
    Http::fake();

    $result = pushService()->pushText(PUSH_USER_ID, 'hello');

    expect($result)->toBeFalse();

    // Fail CLOSED — an unconfigured token must never attempt a call.
    Http::assertNothingSent();
});

it('returns false and makes NO http call when the token is an empty string', function () {
    pushSetToken('');
    Http::fake();

    $result = pushService()->pushText(PUSH_USER_ID, 'hello');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

it('pushes to LINE and returns true on a 2xx (bearer + to/text body)', function () {
    pushSetToken(PUSH_TOKEN);
    Http::fake([
        PUSH_URL => Http::response([], 200),
    ]);

    $result = pushService()->pushText(PUSH_USER_ID, 'สวัสดีค่ะ');

    expect($result)->toBeTrue();

    // The request went to LINE's push URL, carried the Bearer token, and shaped the
    // documented { to, messages: [{ type: 'text', text }] } body.
    Http::assertSent(function ($request) {
        return $request->url() === PUSH_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer ' . PUSH_TOKEN)
            && $request['to'] === PUSH_USER_ID
            && $request['messages'][0]['type'] === 'text'
            && $request['messages'][0]['text'] === 'สวัสดีค่ะ';
    });
});

it('returns false and does NOT throw on a 403 (recipient not a friend)', function () {
    pushSetToken(PUSH_TOKEN);
    Http::fake([
        PUSH_URL => Http::response(['message' => 'not a friend'], 403),
    ]);

    // Best-effort: a blocked/not-friend recipient is a silent false, never an
    // exception that could break the triggering action.
    $result = pushService()->pushText(PUSH_USER_ID, 'hello');

    expect($result)->toBeFalse();

    // The call WAS attempted (unlike the token guards) — it's the response that fails.
    Http::assertSent(fn ($request) => $request->url() === PUSH_URL);
});

it('returns false and makes NO http call when the lineUserId is empty', function () {
    pushSetToken(PUSH_TOKEN);
    Http::fake();

    $result = pushService()->pushText('', 'hello');

    expect($result)->toBeFalse();

    // No linked identity → nothing to push to; short-circuits before any call.
    Http::assertNothingSent();
});

it('returns false and does NOT throw on a transport error', function () {
    pushSetToken(PUSH_TOKEN);

    // A DNS/timeout/TLS-style failure surfaces as a ConnectionException from the
    // client; the service must catch it, log, and return false.
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $result = pushService()->pushText(PUSH_USER_ID, 'hello');

    expect($result)->toBeFalse();
});
