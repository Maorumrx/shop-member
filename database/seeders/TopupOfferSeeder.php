<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TopupOffer;
use Illuminate\Database\Seeder;

/**
 * แพ็กเกจเติมเครดิต (top-up presets) — the one-tap quick-picks on the sell screen
 * ("pay `amount` → get `amount` + `bonus`"). Standalone + IDEMPOTENT (firstOrCreate
 * by name), so it is safe to run on production WITHOUT wiping any data:
 *
 *   php artisan db:seed --class=TopupOfferSeeder
 *
 * Re-running only fills in any missing preset; it never duplicates or overwrites
 * an offer the owner has since edited. Custom amount + bonus are still entered at
 * the point of sale — these are only the presets. The owner can edit/add/remove
 * them in the UI (/topup-offers) afterwards. Money is a decimal-2 STRING (§5.6).
 */
class TopupOfferSeeder extends Seeder
{
    public function run(): void
    {
        // [name, amount, bonus, sort_order]
        $offers = [
            ['เติม 10,000 (แถม 1,000)', '10000.00', '1000.00', 1],
            ['เติม 5,000 (แถม 500)', '5000.00', '500.00', 2],
            ['เติม 2,000 (แถม 100)', '2000.00', '100.00', 3],
        ];

        foreach ($offers as [$name, $amount, $bonus, $sortOrder]) {
            TopupOffer::firstOrCreate(
                ['name' => $name],
                [
                    'amount' => $amount,
                    'bonus' => $bonus,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }
}
