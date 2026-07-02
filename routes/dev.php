<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\MemberDevLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DEV-ONLY routes (LOCAL ENVIRONMENT ONLY)
|--------------------------------------------------------------------------
|
| Loaded under the `web` middleware group from bootstrap/app.php, but ONLY when
| `app()->environment('local')`. In staging/production this file is never
| required, so the routes below are never registered — a request to them 404s
| with no handler at all. This is layer 1 of the dev-login defence-in-depth
| (2026 prod incident: prod was left on APP_ENV=local, exposing this backdoor).
|
| The controller re-guards every action with the `local` env check, an opt-in
| `config('app.dev_login_enabled')` flag, AND a request-host allowlist, so even
| a future APP_ENV regression can NOT reopen the passwordless login.
|
| ⚠️ Do NOT move these routes into routes/member.php, and do NOT load this file
| outside the `local` block in bootstrap/app.php.
|
*/

// Passwordless member login for browser testing without a real LINE device.
// Visit GET /member/dev-login to pick a member, then browse the member UI as them.
Route::get('member/dev-login', [MemberDevLoginController::class, 'index'])->name('member.dev-login.index');
Route::get('member/dev-login/{member}', [MemberDevLoginController::class, 'login'])->name('member.dev-login');
