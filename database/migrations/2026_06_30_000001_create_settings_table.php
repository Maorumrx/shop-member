<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop brand settings — a SINGLETON table (row id=1) holding the owner-editable
 * shop name + logo that replace the hardcoded starter-kit name/logo in the
 * sidebar. No FKs and no business data: pure presentation config, self-created
 * on first read via Setting::current() (firstOrCreate id=1) so no seeder is
 * needed. Date prefix sorts AFTER the entitlement migrations (100000-100007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // Display name; null/empty falls back to config('app.name') in the share.
            $table->string('shop_name', 120)->nullable();
            // Relative path on the `public` disk (NOT a full URL); URL computed via Storage::url.
            $table->string('logo_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
