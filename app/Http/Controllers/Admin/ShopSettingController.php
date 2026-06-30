<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateShopSettingRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shop brand settings — owner-editable shop name + logo (gated at the route via
 * `role:owner`). The saved values feed the shared `shop` Inertia prop
 * (HandleInertiaRequests::share) that the sidebar AppLogo reads, so every save
 * busts that cache (Setting::forgetCache) for an immediate update.
 */
class ShopSettingController extends Controller
{
    /**
     * Show the edit form, prefilled with the current name + logo URL. (Uses the
     * raw shop_name here — not the config fallback — so the field shows blank
     * when unset rather than the framework default, letting the owner know it is
     * currently inheriting the fallback.)
     */
    public function edit(): Response
    {
        $setting = Setting::current();

        return Inertia::render('settings/Shop', [
            'shop' => [
                'name' => $setting->shop_name,
                'logoUrl' => $setting->logoUrl(),
            ],
        ]);
    }

    /**
     * Persist the shop name and (optionally) a new logo. When a logo file is
     * present it is stored on the `public` disk and the PREVIOUS file is deleted
     * to avoid orphaning uploads. The shared brand cache is busted after saving.
     */
    public function update(UpdateShopSettingRequest $request): RedirectResponse
    {
        $setting = Setting::current();

        $setting->shop_name = $request->validated()['shop_name'];

        if ($request->hasFile('logo')) {
            // Delete the old file first so replacing the logo doesn't orphan it.
            if ($setting->logo_path) {
                Storage::disk('public')->delete($setting->logo_path);
            }

            $setting->logo_path = $request->file('logo')->store('logos', 'public');
        }

        $setting->save();

        Setting::forgetCache();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('บันทึกแล้ว')]);

        return to_route('shop.edit');
    }

    /**
     * Clear the logo: delete the file from the `public` disk and null the path.
     * Leaves shop_name untouched. Busts the shared brand cache.
     */
    public function destroyLogo(): RedirectResponse
    {
        $setting = Setting::current();

        if ($setting->logo_path) {
            Storage::disk('public')->delete($setting->logo_path);

            $setting->logo_path = null;
            $setting->save();

            Setting::forgetCache();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ลบโลโก้แล้ว')]);

        return to_route('shop.edit');
    }
}
