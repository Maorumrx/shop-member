<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\MemberLineLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member routes (LINE LIFF auth on the `members` guard)
|--------------------------------------------------------------------------
|
| Loaded under the `web` middleware group from bootstrap/app.php so sessions
| and CSRF apply. Kept entirely separate from the admin `web`/`users` guard
| (architecture.md §3.3, §5.4). All authenticated member pages sit behind
| `auth:members`.
|
*/

// Public: the LIFF entry page. Runs liff.init + liff.login client-side, then
// POSTs the verified ID token to member.line.login. Must be reachable while
// unauthenticated (this is where members arrive from inside LINE).
Route::inertia('member', 'Member/Login')->name('member.login');

// Public: the Vue LIFF page POSTs its verified ID token here to sign in.
// Throttled by IP — unauthenticated and each hit calls LINE's verify endpoint.
Route::post('member/line/login', [MemberLineLoginController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('member.line.login');

// Authenticated member area (the `members` guard).
Route::middleware('auth:members')->group(function () {
    Route::post('member/logout', [MemberLineLoginController::class, 'destroy'])
        ->name('member.logout');

    // Member dashboard — entitlement cards land here in Phase 6.
    Route::inertia('member/dashboard', 'Member/Dashboard')->name('member.dashboard');
});
