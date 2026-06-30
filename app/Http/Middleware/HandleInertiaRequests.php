<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // PUBLIC LIFF app id consumed by the Vue member login page to boot the
            // LINE LIFF SDK. The channel secret is NEVER shared — verification of the
            // returned ID token happens server-side in LiffVerifyService.
            'lineLiffId' => config('services.line.liff_id'),
            // Owner-editable shop brand (name + logo) read by the sidebar AppLogo.
            // A closure → lazily evaluated; cached forever so this costs ZERO
            // queries on a cache hit. Busted via Setting::forgetCache() on save
            // (ShopSettingController) so a brand change shows up immediately.
            // Empty shop_name falls back to config('app.name').
            'shop' => fn (): array => Cache::rememberForever(Setting::CACHE_KEY, function (): array {
                $setting = Setting::current();

                return [
                    'name' => $setting->shop_name ?: config('app.name'),
                    'logoUrl' => $setting->logoUrl(),
                ];
            }),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
