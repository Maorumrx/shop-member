<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update shop brand settings (shop name + optional logo upload). Authorization
 * is handled by the route middleware (`role:owner`), so authorize() returns
 * true. The logo is validated strictly (image mimes + max size); it is OPTIONAL
 * — omitting it keeps the existing logo, sending one replaces it.
 */
class UpdateShopSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'max:120'],
            // Strict: a RASTER image (no SVG — it can carry inline <script> and we
            // serve logos from the public symlink without CSP, so SVG would be a
            // same-origin stored-XSS sink). Max 2048 KB (2 MB). Nullable so a
            // metadata-only save (rename without re-uploading) is valid.
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
