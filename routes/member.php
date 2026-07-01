<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\MemberLineLoginController;
use App\Http\Controllers\Dev\MemberDevLoginController;
use App\Http\Controllers\Member\BookingController;
use App\Http\Controllers\Member\DashboardController;
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

// Public: the FIRST-LOGIN "needs_link" follow-ups (docs/member-line-linking-design.md
// §4.2). Both are reachable WHILE UNAUTHENTICATED on the members guard — they act on
// the verified LINE identity parked in the session (`pending_line`) by the login above,
// and only THEN log the member in. Throttled per the design's per-caller guard (§3).
//   - submit-code  attach the pending LINE identity to a counter member via a
//                  staff-issued 6-digit claim code (MemberLinkService::claim).
//   - create-new   the "I'm new / no code" choice — the only remaining auto-create.
Route::post('member/line/submit-code', [MemberLineLoginController::class, 'submitCode'])
    ->middleware('throttle:link-claim')
    ->name('member.line.submit-code');
Route::post('member/line/create-new', [MemberLineLoginController::class, 'createNew'])
    ->middleware('throttle:link-claim')
    ->name('member.line.create-new');

// Authenticated member area (the `members` guard).
Route::middleware('auth:members')->group(function () {
    Route::post('member/logout', [MemberLineLoginController::class, 'destroy'])
        ->name('member.logout');

    // Member dashboard — the LINE-LIFF member home (Phase 6): balance hero,
    // active lots, and recent history from the shared MemberEntitlementQuery.
    Route::get('member/dashboard', [DashboardController::class, 'index'])->name('member.dashboard');

    // Member booking (จองคิว, Phase 7) — the LIFF self-booking surface. Every
    // action is FOR the authenticated member ($request->user('members')); a member
    // never touches another member's bookings. Booking holds no entitlement —
    // redemption runs at staff CHECK-IN (docs/phase7-booking-design.md §6–§7).
    //   - index         Inertia page: upcoming/recent + branch & service pickers.
    //   - availability  JSON slot grid for a chosen branch + date.
    //   - store         create a `confirmed` booking (created_via=member).
    //   - cancel        DELETE — cancel the member's OWN, still-confirmed booking.
    Route::get('member/bookings', [BookingController::class, 'index'])->name('member.bookings.index');
    Route::get('member/bookings/availability', [BookingController::class, 'availability'])->name('member.bookings.availability');
    Route::post('member/bookings', [BookingController::class, 'store'])->name('member.bookings.store');
    Route::delete('member/bookings/{booking}', [BookingController::class, 'cancel'])->name('member.bookings.cancel');
});

// ── DEV ONLY: passwordless member login for browser testing without LINE ──────
// Registered ONLY in the local environment, so it can NEVER exist in staging/prod
// (the controller re-guards with abort_unless(local) as defence-in-depth). Visit
// GET /member/dev-login to pick a member, then browse the member UI as them.
if (app()->environment('local')) {
    Route::get('member/dev-login', [MemberDevLoginController::class, 'index'])->name('member.dev-login.index');
    Route::get('member/dev-login/{member}', [MemberDevLoginController::class, 'login'])->name('member.dev-login');
}
