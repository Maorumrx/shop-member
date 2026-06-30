<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\PackageController;
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

    // Packages — full resource with nested package_lines, plus an is_active toggle.
    Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
    Route::get('packages/create', [PackageController::class, 'create'])->name('packages.create');
    Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
    Route::get('packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
    Route::put('packages/{package}', [PackageController::class, 'update'])->name('packages.update');
    Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
    Route::patch('packages/{package}/toggle', [PackageController::class, 'toggle'])->name('packages.toggle');
});
