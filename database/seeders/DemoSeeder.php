<?php

namespace Database\Seeders;

use App\Enums\CreditSource;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use App\Models\Member;
use App\Models\Service;
use App\Models\TopupOffer;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;

/**
 * DemoSeeder — mock catalog + sample members/wallet activity for a customer demo
 * (the money-wallet reframe: services price list + top-up presets replace the
 * dropped package catalog).
 *
 * Run:  php artisan db:seed --class=DemoSeeder
 * (Run DatabaseSeeder first for the owner/staff login.)
 *
 * Idempotent: branches/services/offers use firstOrCreate; the sample-member wallet
 * block runs once (guarded on a demo member existing) so re-running won't duplicate
 * credit lots or double-charge.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── สาขา ─────────────────────────────────────────────────────────────
        $siam = Branch::firstOrCreate(['name' => 'สาขาสยาม'], ['is_active' => true]);
        $thonglor = Branch::firstOrCreate(['name' => 'สาขาทองหล่อ'], ['is_active' => true]);

        // เปิดจองคิว (Phase 7) ทั้ง 2 สาขา demo — capacity 2, ช่องละ 60 นาที, 10:00–20:00,
        // จองล่วงหน้าได้ 30 วัน. updateOrCreate = idempotent.
        foreach ([$siam, $thonglor] as $branch) {
            BranchBookingSetting::updateOrCreate(
                ['branch_id' => $branch->id],
                [
                    'is_bookable' => true,
                    'slot_capacity' => 2,
                    'slot_length_minutes' => 60,
                    'open_time' => '10:00:00',
                    'close_time' => '20:00:00',
                    'max_advance_days' => 30,
                ],
            );
        }

        // ── บริการ (services price list) — idempotent by item_code ─────────────
        // item_code is GLOBALLY UNIQUE; the debit path resolves item_code → price.
        // [item_code, name, price] — price is a decimal-2 STRING (§5.6).
        $this->service('THAI_60', 'นวดไทย 60 นาที', '350.00');
        $this->service('OIL_90', 'นวดน้ำมันอโรมา 90 นาที', '500.00');
        $this->service('FOOT_45', 'นวดเท้า 45 นาที', '300.00');
        $this->service('NECK_30', 'นวดคอ บ่า ไหล่ 30 นาที', '250.00');
        $this->service('THAI_90', 'นวดไทย 90 นาที', '500.00');

        // ── แพ็กเกจเติมเครดิต (top-up presets) — idempotent by name ────────────
        // [name, amount, bonus, sort_order] — "pay amount → get amount + bonus".
        $this->offer('เติม 10,000 (แถม 1,000)', '10000.00', '1000.00', 1);
        $this->offer('เติม 5,000 (แถม 500)', '5000.00', '500.00', 2);
        $this->offer('เติม 2,000 (แถม 100)', '2000.00', '100.00', 3);

        // ── สมาชิกตัวอย่าง + การเติม/หักเครดิต (รันครั้งเดียว) ─────────────────
        if (Member::where('name', 'คุณสมหญิง ใจดี')->exists()) {
            return;
        }

        $staff = User::where('role', UserRole::Owner)->first() ?? User::first();
        if ($staff === null) {
            $this->command?->warn('DemoSeeder: no user found — run DatabaseSeeder first for the owner/staff. Skipped sample wallet activity.');

            return;
        }

        /** @var WalletService $wallet */
        $wallet = app(WalletService::class);

        $somying = Member::create(['name' => 'คุณสมหญิง ใจดี', 'phone' => '0812345678', 'is_active' => true]);
        $wichai = Member::create(['name' => 'คุณวิชัย มั่งมี', 'phone' => '0898765432', 'is_active' => true]);
        $napa = Member::create(['name' => 'คุณนภา สุขสันต์', 'phone' => '0623456789', 'is_active' => true]);

        // เติมเครดิต (สร้าง credit_lot + ledger reason=topup [+bonus]) — expiry OFF (null).
        $wallet->topUp($somying, '10000.00', '1000.00', CreditSource::Topup, $staff, $siam->id);
        $wallet->topUp($wichai, '5000.00', '500.00', CreditSource::Topup, $staff, $siam->id);
        $wallet->topUp($napa, '2000.00', '100.00', CreditSource::Topup, $staff, $thonglor->id);

        // หักเครดิตตามราคาบริการ → มีประวัติการใช้ให้ demo (bonus ถูกใช้ก่อน paid).
        $wallet->chargeService($somying, 'THAI_60', $staff, $siam->id); // -350
        $wallet->chargeService($somying, 'FOOT_45', $staff, $siam->id); // -300
        $wallet->chargeService($wichai, 'OIL_90', $staff, $siam->id);   // -500
    }

    /**
     * Create a service price-list row (idempotent by item_code). Money is a
     * decimal-2 STRING (§5.6).
     */
    private function service(string $itemCode, string $name, string $price): Service
    {
        return Service::firstOrCreate(
            ['item_code' => $itemCode],
            ['name' => $name, 'price' => $price, 'is_active' => true],
        );
    }

    /**
     * Create a top-up preset (idempotent by name). Money is a decimal-2 STRING (§5.6).
     */
    private function offer(string $name, string $amount, string $bonus, int $sortOrder): TopupOffer
    {
        return TopupOffer::firstOrCreate(
            ['name' => $name],
            ['amount' => $amount, 'bonus' => $bonus, 'is_active' => true, 'sort_order' => $sortOrder],
        );
    }
}
