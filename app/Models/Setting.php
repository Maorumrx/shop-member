<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Shop brand settings — a SINGLETON model (the row is always id=1). Holds the
 * owner-editable shop name + logo surfaced on every page via the shared `shop`
 * Inertia prop (see HandleInertiaRequests::share). Pure presentation config:
 * no relations, no business rules.
 *
 * @property int $id
 * @property string|null $shop_name
 * @property string|null $logo_path  relative path on the `public` disk
 */
class Setting extends Model
{
    /**
     * Cache key for the brand payload shared on every Inertia request. Declared
     * here so the key name lives in ONE place — the controller busts it via
     * Setting::forgetCache() after every save.
     */
    public const CACHE_KEY = 'app.shop_brand';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shop_name',
        'logo_path',
    ];

    /**
     * The single settings row. firstOrCreate(id=1) self-creates the row with
     * null defaults on first access, so no seeder is required.
     */
    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1], []);
    }

    /**
     * Public URL for the uploaded logo, or null when none is set. Built from the
     * stored relative path via the `public` disk (requires `storage:link`).
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }

    /**
     * Bust the shared brand cache so the sidebar reflects a save immediately.
     * Centralizes the cache key so callers never hardcode it.
     */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
