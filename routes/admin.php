<?php

use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\MemberWalletController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ShopSettingController;
use App\Http\Controllers\Admin\TopupController;
use App\Http\Controllers\Admin\TopupOfferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin catalog routes — Branches + Services + Top-up offers
|--------------------------------------------------------------------------
|
| Catalog management is OWNER-ONLY (architecture.md §3.2): every route sits
| behind ['auth', 'verified', 'role:owner']. `auth` authenticates on the
| default (`web`/`users`) admin guard, `role:owner` (EnsureUserRole) gates by
| role. Loaded under the `web` middleware group from bootstrap/app.php so
| sessions + CSRF + Inertia sharing apply, mirroring how member.php is wired.
|
| The credit-wallet reframe replaced the Package catalog with two catalogs:
| `services.*` (the baht price list the debit path consumes) and
| `topup-offers.*` (sell-screen presets). Owner-only, mirroring the old
| `packages.*` authorization.
|
*/

Route::middleware(['auth', 'verified', 'role:owner'])->group(function () {
    // Branches — minimal resource (no dedicated create/edit pages; the index
    // manages rows inline via modals on the Vue side).
    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    Route::patch('branches/{branch}/toggle', [BranchController::class, 'toggle'])->name('branches.toggle');
    // Per-branch booking config (Phase 7) — upsert the branch_booking_settings row
    // (is_bookable, capacity, slot length, open/close hours, advance window). Owner-only
    // like the rest of the catalog; the index manages it inline via a modal.
    Route::put('branches/{branch}/booking-settings', [BranchController::class, 'updateBookingSettings'])->name('branches.booking-settings.update');

    // Services — the baht price list (mirrors the old packages resource shape):
    // full CRUD + an is_active toggle. `item_code` is globally unique.
    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/create', [ServiceController::class, 'create'])->name('services.create');
    Route::post('services', [ServiceController::class, 'store'])->name('services.store');
    Route::get('services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
    Route::patch('services/{service}/toggle', [ServiceController::class, 'toggle'])->name('services.toggle');

    // Top-up offers — sell-screen presets managed inline on the index (no dedicated
    // create/edit pages), plus an is_active toggle.
    Route::get('topup-offers', [TopupOfferController::class, 'index'])->name('topup-offers.index');
    Route::post('topup-offers', [TopupOfferController::class, 'store'])->name('topup-offers.store');
    Route::put('topup-offers/{topupOffer}', [TopupOfferController::class, 'update'])->name('topup-offers.update');
    Route::delete('topup-offers/{topupOffer}', [TopupOfferController::class, 'destroy'])->name('topup-offers.destroy');
    Route::patch('topup-offers/{topupOffer}/toggle', [TopupOfferController::class, 'toggle'])->name('topup-offers.toggle');

    // Wallet ADJUST is OWNER-ONLY (the highest-trust wallet action): a signed manual
    // correction. Sits in the owner group; charge/refund are in the owner+staff group.
    Route::post('members/{member}/wallet/adjust', [MemberWalletController::class, 'adjust'])->name('members.wallet.adjust');

    // Shop brand settings — owner-editable shop name + logo that replace the
    // hardcoded starter-kit name/logo in the sidebar. Logo upload is multipart,
    // so the save is a POST (not PUT). Lives in the owner-only group so staff
    // cannot reach it.
    Route::get('settings/shop', [ShopSettingController::class, 'edit'])->name('shop.edit');
    Route::post('settings/shop', [ShopSettingController::class, 'update'])->name('shop.update');
    Route::delete('settings/shop/logo', [ShopSettingController::class, 'destroyLogo'])->name('shop.logo.destroy');
});

/*
|--------------------------------------------------------------------------
| Admin sales routes — Members + Top-ups + Wallet actions
|--------------------------------------------------------------------------
|
| Selling + member management is OWNER AND STAFF (architecture.md §3.2): staff
| are front-desk operators who perform top-ups/charges. This group sits behind
| ['auth', 'verified', 'role:owner,staff'] — the `role` alias (EnsureUserRole)
| accepts a CSV allow-list. Kept SEPARATE from the owner-only catalog group above
| so the catalog stays locked to owners while the counter can sell.
|
| Member detail (members.show) renders the wallet balance + active credit lots and
| is where a top-up/charge is initiated. Top-up POSTs to members.topups.store
| (mints a credit_lot + opening ledger rows via WalletService::topUp); a manual
| charge/refund POSTs to members.wallet.charge/refund (WalletService debit/refund).
| Wallet ADJUST is owner-only and lives in the catalog group above.
|
*/

Route::middleware(['auth', 'verified', 'role:owner,staff'])->group(function () {
    // Members — list/search (?q= on name|phone), admin-create, edit, detail.
    // No destroy: members are soft-deleted only (§5.4); deactivate via update.
    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::post('members', [MemberController::class, 'store'])->name('members.store');
    Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
    Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::patch('members/{member}/toggle', [MemberController::class, 'toggle'])->name('members.toggle');

    // Generate a LINE claim code for an unlinked member (Phase 8, member-line-linking
    // §4.2). Staff-only surface (owner+staff group); returns the one-off plaintext
    // code flashed back to Members/Show. Blocked (flash error) for a member that is
    // already LINE-linked / inactive / deleted.
    Route::post('members/{member}/link-code', [MemberController::class, 'generateLinkCode'])->name('members.link-code');

    // Sell credit to a member (the top-up core). Accepts a preset (topup_offer_id) OR
    // a custom amount_paid + bonus_amount; atomic mint via WalletService::topUp.
    Route::post('members/{member}/topups', [TopupController::class, 'store'])->name('members.topups.store');

    // Manual wallet actions on a member (owner+staff): charge the price of a service
    // (WalletService::chargeService) and refund PAID credit (WalletService::refund).
    // A domain failure (insufficient / unpriced / over-refund) returns a 422. Wallet
    // ADJUST is owner-only (members.wallet.adjust, in the catalog group above).
    Route::post('members/{member}/wallet/charge', [MemberWalletController::class, 'charge'])->name('members.wallet.charge');
    Route::post('members/{member}/wallet/refund', [MemberWalletController::class, 'refund'])->name('members.wallet.refund');

    // Bookings (จองคิว, Phase 7 — counter/day-view). Owner sees any branch; staff
    // are pinned to their home branch (§5.5). Check-in charges the wallet via
    // WalletService (stamping booking_id) and completes the booking; insufficient
    // balance rolls back and asks staff to top up first (§7).
    //   - index    day view: bookings for a branch + date + the slot availability.
    //   - store    staff books on behalf of a member (created_via=staff).
    //   - checkIn  charge wallet + complete (POST).
    //   - noShow   confirmed → no_show (POST).
    //   - cancel   confirmed → cancelled (DELETE).
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::post('bookings/{booking}/check-in', [BookingController::class, 'checkIn'])->name('bookings.check-in');
    Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.no-show');
    Route::delete('bookings/{booking}', [BookingController::class, 'cancel'])->name('bookings.cancel');
});
