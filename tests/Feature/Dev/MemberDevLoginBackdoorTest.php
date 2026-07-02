<?php

declare(strict_types=1);

// Security hardening — the passwordless /member/dev-login backdoor is gated FOUR
// ways (defence in depth) so a single misconfig can't reopen the auth bypass:
//   L1 routes/dev.php is loaded by bootstrap/app.php ONLY in the local env;
//   L2 guard() re-checks app()->environment('local');
//   L3 guard() re-checks config('app.dev_login_enabled') (default false);
//   L4 guard() host-allowlists the RAW HTTP_HOST and 404s on any forwarded-host.
//
// Part B (below) is the load-bearing regression test: on a NON-local env (the
// test env is `testing`) the routes are never registered, so the URLs 404 with no
// handler. Part C unit-exercises the controller guard() layers directly — the
// routes can't be reached over HTTP off-local, so we invoke the (private) guard
// via reflection with crafted Requests, after forcing the app env to `local` so
// L1/L2 are satisfied and L3/L4 become the unit under test.
//
// See App\Http\Controllers\Dev\MemberDevLoginController + routes/dev.php.

use App\Http\Controllers\Dev\MemberDevLoginController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Part B — L1: the backdoor routes do NOT exist off the local environment.
// The suite runs under APP_ENV=testing (phpunit.xml), so bootstrap/app.php never
// requires routes/dev.php and these URLs resolve to a plain 404 (no controller,
// no guard even consulted). This is the primary proof the backdoor can't be hit.
// ---------------------------------------------------------------------------

it('404s GET /member/dev-login because the route is unregistered off local', function () {
    // Sanity: we are genuinely NOT in local (otherwise this proves nothing).
    expect(app()->environment('local'))->toBeFalse();

    $this->get('/member/dev-login')->assertNotFound();
});

it('404s GET /member/dev-login/{member} because the route is unregistered off local', function () {
    expect(app()->environment('local'))->toBeFalse();

    // Bare id — the route isn't registered, so we 404 before any model binding.
    $this->get('/member/dev-login/1')->assertNotFound();
});

it('has no named dev-login routes registered off local', function () {
    // Belt-and-braces on L1: the route names themselves are absent.
    expect(Illuminate\Support\Facades\Route::has('member.dev-login'))->toBeFalse();
    expect(Illuminate\Support\Facades\Route::has('member.dev-login.index'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Part C — controller guard() layers (L3 flag + L4 host), unit-invoked.
//
// guard() is private and its first line is abort_unless(environment('local')).
// To make L3/L4 the unit under test we force the app env to `local` for these
// tests (detectEnvironment is the supported public setter) — that is exactly the
// misconfig the deeper layers must survive (2026 incident: prod ran APP_ENV=local).
// A fresh app is booted per test, so this env override does not leak into Part B.
// ---------------------------------------------------------------------------

/**
 * Force the WORST-CASE misconfig for the deeper guards: env regressed to `local`
 * AND the opt-in flag ON — so any 404 below is proven to come from L4 (host),
 * not from L1/L2/L3 masking it.
 */
function devForceLocalWithFlagOn(): void
{
    app()->detectEnvironment(fn () => 'local');
    config()->set('app.dev_login_enabled', true);
}

/**
 * Build a Request whose RAW Host header (the HTTP_HOST *server var* — the exact
 * value guard() reads, NOT $request->getHost()) is $rawHost, plus optional extra
 * headers. $rawHost === null models a request with NO Host header at all.
 *
 * NOTE: Request::create() derives HTTP_HOST from the URI (Symfony overwrites any
 * HTTP_HOST passed in $server, and validates/normalises URI hosts). So we build a
 * host-less URI and set HTTP_HOST on the server bag AFTERWARDS — this is the one
 * place the value is authoritative and mirrors how PHP-FPM populates $_SERVER.
 *
 * @param  array<string, string>  $headers
 */
function devRequest(?string $rawHost, array $headers = []): Request
{
    // Path-only URI → Symfony does not parse/override a host from it.
    $request = Request::create('/member/dev-login', 'GET');

    if ($rawHost === null) {
        $request->server->remove('HTTP_HOST');
    } else {
        $request->server->set('HTTP_HOST', $rawHost);
    }

    foreach ($headers as $name => $value) {
        // guard() checks $request->headers->has(...) — set the HeaderBag directly.
        $request->headers->set($name, $value);
    }

    return $request;
}

/**
 * Invoke the private guard() with reflection and report whether it 404'd.
 */
function devGuardThrows(Request $request): bool
{
    $controller = app(MemberDevLoginController::class);
    // Since PHP 8.1 reflection can invoke a private method without setAccessible()
    // (which is a no-op on 8.1+ and deprecated on 8.5); invoke it directly.
    $method = new ReflectionMethod($controller, 'guard');

    try {
        $method->invoke($controller, $request);

        return false; // did NOT abort — guard let the request through
    } catch (NotFoundHttpException) {
        return true; // 404 — guard blocked it
    }
}

it('guard PASSES for a recognised local host when env=local and the flag is on', function () {
    // The one intended-open path: local env + flag on + a real local host.
    devForceLocalWithFlagOn();

    expect(devGuardThrows(devRequest('localhost')))->toBeFalse();
    expect(devGuardThrows(devRequest('127.0.0.1')))->toBeFalse();
    expect(devGuardThrows(devRequest('myshop.test')))->toBeFalse(); // *.test (Herd/Valet)
    expect(devGuardThrows(devRequest('localhost:8000')))->toBeFalse(); // :port stripped
});

it('guard 404s on the production host even when env=local and the flag is on (L4 allowlist fails closed)', function () {
    devForceLocalWithFlagOn();

    expect(devGuardThrows(devRequest('bansuan-thaimassage.com')))->toBeTrue();
    expect(devGuardThrows(devRequest('www.bansuan-thaimassage.com')))->toBeTrue();
});

it('guard 404s when the config flag is off, even on a local host (L3)', function () {
    app()->detectEnvironment(fn () => 'local');
    config()->set('app.dev_login_enabled', false); // opt-in flag OFF

    expect(devGuardThrows(devRequest('localhost')))->toBeTrue();
});

it('guard 404s when HTTP_HOST is missing (fails closed)', function () {
    devForceLocalWithFlagOn();

    expect(devGuardThrows(devRequest(null)))->toBeTrue();
});

// THE most important assertion: the host allowlist must NOT be satisfiable via a
// forwarded header. Behind a trusted proxy $request->getHost() would return the
// attacker-controlled X-Forwarded-Host, so guard() 404s outright the moment any
// forwarded-host header is present — regardless of the (spoofed) value.
it('guard 404s when X-Forwarded-Host is present, even claiming a local host (proxy-spoof guard)', function () {
    devForceLocalWithFlagOn();

    // Real Host is prod; forwarded header lies "localhost". Must STILL 404.
    expect(devGuardThrows(devRequest(
        'bansuan-thaimassage.com',
        ['X-Forwarded-Host' => 'localhost'],
    )))->toBeTrue();

    // Even if the RAW host is itself localhost, the mere PRESENCE of a
    // forwarded-host header means "not a bare local request" → 404.
    expect(devGuardThrows(devRequest(
        'localhost',
        ['X-Forwarded-Host' => 'localhost'],
    )))->toBeTrue();
});

it('guard 404s when a RFC 7239 Forwarded header is present (proxy-spoof guard)', function () {
    devForceLocalWithFlagOn();

    expect(devGuardThrows(devRequest(
        'localhost',
        ['Forwarded' => 'host=localhost'],
    )))->toBeTrue();
});
