<?php

declare(strict_types=1);

// Security hardening — app:assert-production-safe deploy guardrail.
// The command reads the CACHED, resolved config('app.env') / config('app.debug')
// and must FAIL (non-zero exit) unless the app is production-safe, so a deploy
// script can `&&`-chain it and abort a misconfigured go-live. We drive it via
// $this->artisan(...) with config()->set(...) overriding the resolved config, and
// assert ONLY the exit code (the deploy script's contract is the exit status, not
// the wording of the message). See App\Console\Commands\AssertProductionSafe.

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The command asserts on config('app.env') / config('app.debug') (NOT
// app()->environment()) precisely because it runs AFTER `config:cache`; so
// overriding those two config values is exactly the surface it reads.
function assertProdSafeExit(string $env, bool $debug)
{
    config()->set('app.env', $env);
    config()->set('app.debug', $debug);

    return test()->artisan('app:assert-production-safe');
}

it('SUCCEEDS (exit 0) only when env=production and debug=false', function () {
    assertProdSafeExit('production', false)->assertExitCode(0);
});

it('FAILS when env=production but debug=true (debug page leaks secrets)', function () {
    // The 2026 incident: prod served with APP_DEBUG=true. Must be caught.
    assertProdSafeExit('production', true)->assertFailed();
});

it('FAILS when env=local even with debug=false (the backdoor gate)', function () {
    // The 2026 incident: prod served with APP_ENV=local, exposing dev-login. Must
    // be caught even though debug is off — env alone is disqualifying.
    assertProdSafeExit('local', false)->assertFailed();
});

it('FAILS when env=staging even with debug=false (only "production" passes)', function () {
    // Fail CLOSED: any env string other than the literal "production" is unsafe.
    assertProdSafeExit('staging', false)->assertFailed();
});

it('FAILS when env=staging and debug=true (both wrong)', function () {
    assertProdSafeExit('staging', true)->assertFailed();
});

// Belt-and-braces on the failing exit being genuinely NON-ZERO (assertFailed()
// asserts != 0, but pin the "not accidentally 0" contract explicitly too).
it('returns a non-zero exit code on every unsafe combination', function () {
    expect(assertProdSafeExit('local', false)->run())->not->toBe(0);
    expect(assertProdSafeExit('staging', false)->run())->not->toBe(0);
    expect(assertProdSafeExit('production', true)->run())->not->toBe(0);
});
