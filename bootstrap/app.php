<?php

use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Member (LINE LIFF / `members` guard) routes — loaded under the
            // `web` group so sessions + CSRF apply (architecture.md §3.3).
            Route::middleware('web')->group(base_path('routes/member.php'));

            // Admin routes (Phase 3 catalog = owner-only; Phase 4 members/sales =
            // owner+staff) — loaded under `web` for sessions/CSRF/Inertia; per-route
            // auth (role:owner / role:owner,staff) lives inside routes/admin.php.
            Route::middleware('web')->group(base_path('routes/admin.php'));

            // DEV-ONLY routes (passwordless member dev-login) — loaded under `web`
            // like the others, but ONLY in the local environment, so they are NEVER
            // registered in staging/production. This is layer 1 of the dev-login
            // hardening; the controller adds env + config-flag + host guards on top.
            if (app()->environment('local')) {
                Route::middleware('web')->group(base_path('routes/dev.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust ONLY the two explicit production hosts, rejecting requests with a
        // spoofed/absolute Host header (cache-poisoning, password-reset host
        // injection). Registered unconditionally: Laravel's TrustHosts already
        // skips host enforcement in `local` and during unit tests, so it never
        // 400s local dev, `*.test`, or CI. Do NOT gate this on
        // app()->environment() — this withMiddleware closure runs while the Kernel
        // is resolved, BEFORE the container binds `env`, so calling environment()
        // here throws "Target class [env] does not exist".
        // `subdomains: false` — `www` is listed explicitly; we deliberately do NOT
        // trust all `*.bansuan-thaimassage.com` (a takeover-able subdomain CNAME
        // would otherwise inherit host trust).
        $middleware->trustHosts(
            at: ['bansuan-thaimassage.com', 'www.bansuan-thaimassage.com'],
            subdomains: false,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Admin-guard role gate: `->middleware('role:owner')` (§3.2).
        $middleware->alias([
            'role' => EnsureUserRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
