<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the database with admin accounts for local testing.
     *
     * Logins — password is "password" for both:
     *   owner@shop.test  → role: owner (full access)
     *   staff@shop.test  → role: staff (branch-scoped)
     *
     * Members sign in via LINE LIFF, so they are not seeded here.
     * Idempotent (updateOrCreate) — safe to re-run.
     */
    public function run(): void
    {
        $branch = Branch::firstOrCreate(
            ['name' => 'สาขาหลัก'],
            ['is_active' => true],
        );

        $this->seedAdmin('owner@shop.test', 'Owner', UserRole::Owner, $branch->id);
        $this->seedAdmin('staff@shop.test', 'Staff', UserRole::Staff, $branch->id);
    }

    private function seedAdmin(string $email, string $name, UserRole $role, int $branchId): void
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',   // hashed by the User model's `hashed` cast
                'role' => $role,
                'branch_id' => $branchId,
                'is_active' => true,
            ],
        );

        // email_verified_at isn't fillable — set it explicitly so the seeded admin
        // isn't bounced to the email-verification screen.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
