<?php

use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\RedemptionController;
use App\Http\Controllers\Admin\ShopSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin catalog routes (Phase 3) — Branches + Packages
|--------------------------------------------------------------------------
|
| Catalog management is OWNER-ONLY (architecture.md §3.2): every route sits
| behind ['auth', 'verified', 'role:owner']. `auth` authenticates on the
| default (`web`/`users`) admin guard, `role:owner` (EnsureUserRole) gates by
| role. Loaded under the `web` middleware group from bootstrap/app.php so
| sessions + CSRF + Inertia sharing apply, mirroring how member.php is wired.
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

    // Packages — full resource with nested package_lines, plus an is_active toggle.
    Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
    Route::get('packages/create', [PackageController::class, 'create'])->name('packages.create');
    Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
    Route::get('packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
    Route::put('packages/{package}', [PackageController::class, 'update'])->name('packages.update');
    Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
    Route::patch('packages/{package}/toggle', [PackageController::class, 'toggle'])->name('packages.toggle');

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
| Admin sales routes (Phase 4) — Members + Purchases
|--------------------------------------------------------------------------
|
| Selling + member management is OWNER AND STAFF (architecture.md §3.2): staff
| are front-desk operators who perform sales/redemptions. This group sits behind
| ['auth', 'verified', 'role:owner,staff'] — the `role` alias (EnsureUserRole)
| accepts a CSV allow-list. Kept SEPARATE from the owner-only catalog group above
| so the catalog stays locked to owners while the counter can sell.
|
| Member detail (members.show) renders owned lots + the live balance and is also
| where a sale is initiated; the sale POSTs to members.purchases.store, which
| mints the lot + entitlements + purchase ledger rows atomically (PurchaseService).
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

    // Sell a package to a member (the Phase-4 core). Atomic mint via PurchaseService.
    Route::post('members/{member}/purchases', [PurchaseController::class, 'store'])->name('members.purchases.store');

    // Redeem (ตัดสิทธิ์) a member's entitlements (the Phase-5 revenue core). Atomic,
    // lock-protected FIFO consumption via RedemptionService — decrement + one redeem
    // ledger row per touched entitlement, coupled redeem_group siblings, lot rollup.
    // Branch context = the acting staff's home branch (owner = null = unscoped, §5.5).
    Route::post('members/{member}/redemptions', [RedemptionController::class, 'store'])->name('members.redemptions.store');

    // Bookings (จองคิว, Phase 7 — counter/day-view). Owner sees any branch; staff
    // are pinned to their home branch (§5.5). Check-in runs redemption via the
    // existing RedemptionService (stamping booking_id) and completes the booking;
    // insufficient balance rolls back and asks staff to sell a package first (§7).
    //   - index    day view: bookings for a branch + date + the slot availability.
    //   - store    staff books on behalf of a member (created_via=staff).
    //   - checkIn  redeem + complete (POST).
    //   - noShow   confirmed → no_show (POST).
    //   - cancel   confirmed → cancelled (DELETE).
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::post('bookings/{booking}/check-in', [BookingController::class, 'checkIn'])->name('bookings.check-in');
    Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.no-show');
    Route::delete('bookings/{booking}', [BookingController::class, 'cancel'])->name('bookings.cancel');
});
