<?php

declare(strict_types=1);

// Shop brand settings — owner-editable shop name + logo (Phase 3, owner-only).
// Contracts under test (ShopSettingController + UpdateShopSettingRequest + the
// Setting singleton model + the shared `shop` Inertia prop in HandleInertiaRequests):
//   - Access: routes live in routes/admin.php behind ['auth','verified','role:owner'];
//     loaded under the `web` group with NO uri prefix, so paths are /settings/shop and
//     /settings/shop/logo directly. EnsureUserRole 403s non-owners; `auth` bounces guests.
//   - update: persists shop_name; an uploaded `logo` is stored on the `public` disk under
//     logos/ and the PREVIOUS file is deleted (no orphans). Every save runs
//     Setting::forgetCache(), so the shared `shop.name`/`shop.logoUrl` prop (a
//     Cache::rememberForever closure) reflects the change on the very next request.
//   - destroyLogo: deletes the file, nulls logo_path, busts the cache. Leaves shop_name.
//   - validation: shop_name required; logo nullable but, when present, a RASTER image
//     (image + mimes:jpg,jpeg,png,webp — SVG excluded), max 2048 KB.
//   - Setting::current() is a firstOrCreate(id=1) singleton; logoUrl() is null/`/storage/...`.
// Flash is Inertia::flash('toast', ...) (NOT session flash), so success is asserted via
// redirect + DB state + Storage + the shared Inertia `shop` prop — never the session.

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Active, verified owner — the only role allowed through the shop settings routes. */
function shopSettingOwner(): User
{
    return User::factory()->create([
        'role' => UserRole::Owner,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

/** Active, verified staff — used for the negative (403) gate cases. */
function shopSettingStaff(): User
{
    return User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

// --- Access gate ------------------------------------------------------------

it('redirects a guest from GET /settings/shop to login', function () {
    // `auth` runs first and bounces an unauthenticated request to login.
    $this->get('/settings/shop')->assertRedirect(route('login'));
});

it('forbids a staff user on GET /settings/shop with 403', function () {
    $this->actingAs(shopSettingStaff())
        ->get('/settings/shop')
        ->assertForbidden();
});

it('forbids a staff user POSTing /settings/shop directly with 403 (route gate, not just hidden nav)', function () {
    // Proves the gate is on the route — a staff member who crafts the POST is still 403'd.
    $this->actingAs(shopSettingStaff())
        ->post('/settings/shop', ['shop_name' => 'Sneaky'])
        ->assertForbidden();

    // Nothing was written behind the gate.
    $this->assertDatabaseMissing('settings', ['shop_name' => 'Sneaky']);
});

it('lets an owner view the shop settings page (Inertia settings/Shop with shop.name + shop.logoUrl)', function () {
    $this->actingAs(shopSettingOwner())
        ->get('/settings/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Shop')
            // The page prop is the raw setting (null name when unset); both keys present.
            ->where('shop.name', null)
            ->where('shop.logoUrl', null)
        );
});

// --- Update name (no logo) --------------------------------------------------

it('updates the shop name via POST /settings/shop and redirects to shop.edit', function () {
    Storage::fake('public');

    $this->actingAs(shopSettingOwner())
        ->post('/settings/shop', ['shop_name' => 'ร้านนวดสุข'])
        ->assertRedirect(route('shop.edit'));

    // Singleton row id=1 carries the new name.
    $this->assertDatabaseHas('settings', ['id' => 1, 'shop_name' => 'ร้านนวดสุข']);
});

it('busts the shared brand cache so the new name shows in the shared shop prop on the next request', function () {
    // End-to-end cache-bust proof: the shared `shop` prop is a Cache::rememberForever
    // closure (CACHE_STORE=array in tests persists across requests within this run).
    // If Setting::forgetCache() did NOT run on save, this follow-up GET would still
    // serve the stale (config fallback) name from the array cache.
    Storage::fake('public');
    $owner = shopSettingOwner();

    // Prime the cache with the pre-save value (owner hits the page first).
    $this->actingAs($owner)->get('/settings/shop')->assertOk();

    $this->actingAs($owner)
        ->post('/settings/shop', ['shop_name' => 'ร้านนวดสุข'])
        ->assertRedirect(route('shop.edit'));

    // Next request reflects the new name in the SHARED `shop.name` prop — proving the bust.
    $this->actingAs($owner)
        ->get('/settings/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('shop.name', 'ร้านนวดสุข'));
});

it('rejects an empty shop_name (required)', function () {
    Storage::fake('public');

    $this->actingAs(shopSettingOwner())
        ->post('/settings/shop', ['shop_name' => ''])
        ->assertSessionHasErrors(['shop_name']);
});

it('rejects a missing shop_name (required)', function () {
    Storage::fake('public');

    $this->actingAs(shopSettingOwner())
        ->post('/settings/shop', [])
        ->assertSessionHasErrors(['shop_name']);
});

// --- Logo upload (Storage::fake('public')) ----------------------------------

it('stores an uploaded logo on the public disk under logos/ and exposes logoUrl on the next GET', function () {
    Storage::fake('public');
    $owner = shopSettingOwner();

    $this->actingAs($owner)
        ->post('/settings/shop', [
            'shop_name' => 'ร้านนวดสุข',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])
        ->assertRedirect(route('shop.edit'));

    $path = Setting::current()->logo_path;
    expect($path)->not->toBeNull()->toStartWith('logos/');
    Storage::disk('public')->assertExists($path);

    // The shared/page prop now carries a non-null logoUrl.
    $this->actingAs($owner)
        ->get('/settings/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('shop.logoUrl', fn ($url) => $url !== null));
});

it('replaces the logo on a second upload — old file deleted, new file present, logo_path changed', function () {
    Storage::fake('public');
    $owner = shopSettingOwner();

    // First logo.
    $this->actingAs($owner)->post('/settings/shop', [
        'shop_name' => 'ร้านนวดสุข',
        'logo' => UploadedFile::fake()->image('first.png', 200, 200),
    ])->assertRedirect(route('shop.edit'));

    $oldPath = Setting::current()->logo_path;
    Storage::disk('public')->assertExists($oldPath);

    // Second logo replaces it.
    $this->actingAs($owner)->post('/settings/shop', [
        'shop_name' => 'ร้านนวดสุข',
        'logo' => UploadedFile::fake()->image('second.png', 200, 200),
    ])->assertRedirect(route('shop.edit'));

    $newPath = Setting::current()->logo_path;

    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath); // old file de-orphaned
    Storage::disk('public')->assertExists($newPath);
});

it('deletes the logo via DELETE /settings/shop/logo — file gone, logo_path null, logoUrl null next GET', function () {
    Storage::fake('public');
    $owner = shopSettingOwner();

    // Seed a logo first.
    $this->actingAs($owner)->post('/settings/shop', [
        'shop_name' => 'ร้านนวดสุข',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertRedirect(route('shop.edit'));

    $path = Setting::current()->logo_path;
    Storage::disk('public')->assertExists($path);

    // Destroy it.
    $this->actingAs($owner)
        ->delete('/settings/shop/logo')
        ->assertRedirect(route('shop.edit'));

    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseHas('settings', ['id' => 1, 'logo_path' => null]);

    $this->actingAs($owner)
        ->get('/settings/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('shop.logoUrl', null));
});

// --- Validation (logo) ------------------------------------------------------

it('rejects an .svg logo (image + mimes:jpg,jpeg,png,webp excludes SVG)', function () {
    Storage::fake('public');

    // SVG is intentionally disallowed (stored-XSS sink served from the public symlink).
    $this->actingAs(shopSettingOwner())
        ->post('/settings/shop', [
            'shop_name' => 'ร้านนวดสุข',
            'logo' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors(['logo']);

    $this->assertNull(Setting::current()->logo_path);
});

it('rejects an oversize logo (> 2048 KB)', function () {
    Storage::fake('public');

    // 3000 KB > the 2048 KB (max:2048) ceiling.
    $this->actingAs(shopSettingOwner())
        ->post('/settings/shop', [
            'shop_name' => 'ร้านนวดสุข',
            'logo' => UploadedFile::fake()->create('big.png', 3000, 'image/png'),
        ])
        ->assertSessionHasErrors(['logo']);

    $this->assertNull(Setting::current()->logo_path);
});

it('rejects a non-image logo (e.g. a .txt / .pdf)', function () {
    Storage::fake('public');

    $this->actingAs(shopSettingOwner())
        ->post('/settings/shop', [
            'shop_name' => 'ร้านนวดสุข',
            'logo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors(['logo']);

    $this->assertNull(Setting::current()->logo_path);
});

// --- Model singleton --------------------------------------------------------

it('returns the same id=1 row from Setting::current() on repeated calls (creates once)', function () {
    $first = Setting::current();
    $second = Setting::current();

    expect($first->id)->toBe(1);
    expect($second->id)->toBe(1);
    // firstOrCreate(id=1) self-creates exactly once — never a second row.
    $this->assertDatabaseCount('settings', 1);
});

it('returns null from logoUrl() when logo_path is null, else a /storage/... URL', function () {
    Storage::fake('public');

    $setting = Setting::current();
    expect($setting->logoUrl())->toBeNull();

    $setting->logo_path = 'logos/example.png';
    $setting->save();

    expect($setting->logoUrl())->toContain('/storage/logos/example.png');
});
