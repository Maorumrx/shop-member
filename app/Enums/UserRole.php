<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Admin-guard authorization role for `users.role` (architecture.md §3.2).
 *
 * - owner: full access, unscoped (branch_id may be null).
 * - staff: branch-scoped operator; performs sales & redemptions, recorded as
 *   `entitlement_ledger.staff_id`.
 */
enum UserRole: string
{
    case Owner = 'owner';
    case Staff = 'staff';
}
