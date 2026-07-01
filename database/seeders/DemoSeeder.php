<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Package;
use App\Models\User;
use App\Services\Purchase\PurchaseService;
use App\Services\Redemption\RedemptionService;
use Illuminate\Database\Seeder;

/**
 * DemoSeeder — mock catalog + sample members/sales for a customer demo.
 *
 * Run:  php artisan db:seed --class=DemoSeeder
 * (Run DatabaseSeeder first for the owner/staff login.)
 *
 * Idempotent: branches/packages use firstOrCreate; the sample-member sales block
 * runs once (guarded on a demo member existing) so re-running won't duplicate lots.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── สาขา ─────────────────────────────────────────────────────────────
        Branch::firstOrCreate(['name' => 'สาขาสยาม'], ['is_active' => true]);
        $thonglor = Branch::firstOrCreate(['name' => 'สาขาทองหล่อ'], ['is_active' => true]);

        // ── แพ็คเกจ (idempotent by name; lines สร้างเฉพาะตอน create ครั้งแรก) ──
        // [item_code, item_name, item_type, qty, redeem_group]
        // group เดียวกันบน service + add-on = ตัดคู่กัน (ตัดนวด → ตัดประคบตาม)
        $this->package('นวดไทย 10 ครั้ง (แถมประคบ)', '2900.00', 180, null, [
            ['THAI_60', 'นวดไทย 60 นาที', ItemType::Service, 10, 'GRP_THAI10'],
            ['HERBAL', 'ประคบสมุนไพร', ItemType::Addon, 3, 'GRP_THAI10'],
        ]);
        $this->package('นวดน้ำมันอโรมา 5 ครั้ง', '3500.00', 120, null, [
            ['OIL_90', 'นวดน้ำมันอโรมา 90 นาที', ItemType::Service, 5, null],
        ]);
        $this->package('นวดเท้า 20 ครั้ง', '3900.00', 365, null, [
            ['FOOT_45', 'นวดเท้า 45 นาที', ItemType::Service, 20, null],
        ]);
        $this->package('นวดคอ บ่า ไหล่ 8 ครั้ง', '1900.00', 90, null, [
            ['NECK_30', 'นวดคอ บ่า ไหล่ 30 นาที', ItemType::Service, 8, null],
        ]);
        $this->package('โปรโมชั่นทดลอง นวดไทย 1 ครั้ง', '299.00', 30, null, [
            ['THAI_60', 'นวดไทย 60 นาที', ItemType::Service, 1, null],
        ]);
        // แพ็คเกจเฉพาะสาขาทองหล่อ (branch-scoped) — โชว์ cross-branch rule
        $this->package('แพ็คเกจ VIP ทองหล่อ', '9900.00', 365, $thonglor->id, [
            ['OIL_90', 'นวดน้ำมันอโรมา 90 นาที', ItemType::Service, 8, 'GRP_VIP'],
            ['THAI_90', 'นวดไทย 90 นาที', ItemType::Service, 4, null],
            ['SCRUB', 'สครับผิวขัดผิว', ItemType::Addon, 6, 'GRP_VIP'],
        ]);

        // ── สมาชิกตัวอย่าง + การขาย/ตัดสิทธิ์ (รันครั้งเดียว) ─────────────────
        if (Member::where('name', 'คุณสมหญิง ใจดี')->exists()) {
            return;
        }

        $staff = User::where('role', UserRole::Owner)->first() ?? User::first();
        if ($staff === null) {
            $this->command?->warn('DemoSeeder: no user found — run DatabaseSeeder first for the owner/staff. Skipped sample sales.');

            return;
        }

        $purchase = app(PurchaseService::class);
        $redeem = app(RedemptionService::class);

        $somying = Member::create(['name' => 'คุณสมหญิง ใจดี', 'phone' => '0812345678', 'is_active' => true]);
        $wichai = Member::create(['name' => 'คุณวิชัย มั่งมี', 'phone' => '0898765432', 'is_active' => true]);
        $napa = Member::create(['name' => 'คุณนภา สุขสันต์', 'phone' => '0623456789', 'is_active' => true]);

        $thai10 = Package::where('name', 'นวดไทย 10 ครั้ง (แถมประคบ)')->firstOrFail();
        $oil5 = Package::where('name', 'นวดน้ำมันอโรมา 5 ครั้ง')->firstOrFail();
        $foot20 = Package::where('name', 'นวดเท้า 20 ครั้ง')->firstOrFail();

        // ขายแพ็ค (สร้าง lot + entitlements + ledger reason=purchase)
        $purchase->purchase($somying, $thai10, $thai10->price, $staff);
        $purchase->purchase($somying, $foot20, $foot20->price, $staff);
        $purchase->purchase($wichai, $oil5, $oil5->price, $staff);
        $purchase->purchase($napa, $thai10, $thai10->price, $staff);

        // ตัดสิทธิ์บางส่วน → มีประวัติการใช้ให้ demo (owner = branch null = unscoped)
        $redeem->redeem($somying, 'THAI_60', 2, $staff, null); // ตัดนวด 2 → ประคบ 2 (คู่)
        $redeem->redeem($somying, 'FOOT_45', 3, $staff, null);
        $redeem->redeem($wichai, 'OIL_90', 1, $staff, null);
    }

    /**
     * Create a package + its lines (idempotent by name; lines only on first create).
     *
     * @param  list<array{0:string,1:string,2:ItemType,3:int,4:string|null}>  $lines
     */
    private function package(string $name, string $price, ?int $validDays, ?int $branchId, array $lines): Package
    {
        $package = Package::firstOrCreate(
            ['name' => $name],
            ['price' => $price, 'valid_days' => $validDays, 'branch_id' => $branchId, 'is_active' => true],
        );

        if ($package->wasRecentlyCreated) {
            foreach ($lines as [$code, $itemName, $type, $qty, $group]) {
                $package->lines()->create([
                    'item_code' => $code,
                    'item_name' => $itemName,
                    'item_type' => $type,
                    'qty' => $qty,
                    'redeem_group' => $group,
                ]);
            }
        }

        return $package;
    }
}
