<?php

declare(strict_types=1);

// Phase 5 — RedemptionService (the REVENUE core, architecture.md §3.7–§3.8, §5.2,
// §5.3, §5.5, §5.7, §6.3). Calls the service DIRECTLY (no HTTP) to prove the
// atomic, lock-protected FIFO consumption contract:
//   - FIFO across lots: dated soonest-first, never-expiring LAST, id tiebreak.
//   - each decrement writes EXACTLY one redeem ledger row (delta=-take, reason=redeem,
//     balance_after == qty_remaining AFTER), staff_id set, booking_id null.
//   - the §5.2 invariant holds after redemption: qty_remaining == qty_total + Σ delta.
//   - entitlement → used_up at 0; lot → used_up when ALL its entitlements are used_up (§5.7).
//   - redeem_group coupling: same-lot siblings decremented best-effort (a run-out
//     sibling never blocks the primary); independent (null group) lines untouched.
//   - branch eligibility (§5.5): staff branch scopes lots; owner (null branch) unscoped.
//   - atomicity: insufficient balance throws RedemptionException and writes ZERO rows.

use App\Enums\EntitlementStatus;
use App\Enums\ItemType;
use App\Enums\LedgerReason;
use App\Enums\UserRole;
use App\Exceptions\RedemptionException;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\EntitlementLedger;
use App\Models\Member;
use App\Models\MemberPackage;
use App\Models\User;
use App\Services\Redemption\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Active, verified operator (owner or branch-scoped staff) → ledger.staff_id. */
function redeemStaff(UserRole $role = UserRole::Staff, ?int $branchId = null): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    if ($branchId !== null) {
        $user->forceFill(['branch_id' => $branchId])->save();
    }

    return $user;
}

/** A plain active member. */
function redeemMember(): Member
{
    return Member::create([
        'name' => 'Redeem Customer',
        'phone' => '0830000000',
        'is_active' => true,
    ]);
}

/**
 * Mint a lot + its entitlements directly (bypassing PurchaseService so tests can
 * pin expires_at / branch / group precisely). Writes the purchase ledger row per
 * entitlement so the §5.2 invariant chain starts correct.
 *
 * @param  list<array{item_code: string, item_name?: string, item_type?: ItemType, qty: int, redeem_group?: string|null}>  $lines
 */
function redeemLot(Member $member, ?\Carbon\CarbonInterface $expiresAt, array $lines, ?int $branchId = null): MemberPackage
{
    $lot = MemberPackage::create([
        'member_id' => $member->id,
        'package_id' => null,
        'branch_id' => $branchId,
        'purchased_at' => now(),
        'expires_at' => $expiresAt,
        'price_paid' => '0.00',
        'status' => EntitlementStatus::Active,
    ]);

    foreach ($lines as $line) {
        $qty = $line['qty'];
        $ent = Entitlement::create([
            'member_package_id' => $lot->id,
            'member_id' => $member->id,
            'item_code' => $line['item_code'],
            'item_name' => $line['item_name'] ?? $line['item_code'],
            'item_type' => $line['item_type'] ?? ItemType::Service,
            'qty_total' => $qty,
            'qty_remaining' => $qty,
            'redeem_group' => $line['redeem_group'] ?? null,
            'expires_at' => $expiresAt,
            'status' => EntitlementStatus::Active,
        ]);

        $ent->ledgerEntries()->create([
            'member_id' => $member->id,
            'delta' => $qty,
            'reason' => LedgerReason::Purchase,
            'balance_after' => $qty,
            'booking_id' => null,
            'staff_id' => null,
            'note' => null,
        ]);
    }

    return $lot;
}

it('decrements one lot and writes exactly one redeem ledger row with balance_after == qty_remaining', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    $lot = redeemLot($member, now()->addDays(30), [
        ['item_code' => 'MASSAGE_60', 'qty' => 10],
    ]);

    $result = app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 3, $staff, null);

    $ent = Entitlement::where('item_code', 'MASSAGE_60')->sole();
    expect($ent->qty_remaining)->toBe(7);
    expect($ent->status)->toBe(EntitlementStatus::Active);

    // Exactly one redeem ledger row, delta -3, balance_after == remaining (7).
    $redeemRows = EntitlementLedger::where('reason', LedgerReason::Redeem)->get();
    expect($redeemRows)->toHaveCount(1);
    $row = $redeemRows->first();
    expect($row->delta)->toBe(-3);
    expect($row->balance_after)->toBe(7);
    expect($row->staff_id)->toBe($staff->id);
    expect($row->member_id)->toBe($member->id);
    expect($row->booking_id)->toBeNull();

    // §5.2 invariant: qty_remaining == Σ delta over ALL ledger rows. The purchase
    // row already contributes +qty_total (+10), so we must NOT add qty_total again:
    // +10 (purchase) − 3 (redeem) = 7 == qty_remaining.
    $sumDelta = (int) EntitlementLedger::where('entitlement_id', $ent->id)->sum('delta');
    expect($sumDelta)->toBe($ent->qty_remaining);

    // Result describes the single movement.
    expect($result->movements)->toHaveCount(1);
    expect($result->movements[0]->itemCode)->toBe('MASSAGE_60');
    expect($result->movements[0]->taken)->toBe(3);
    expect($result->movements[0]->remainingAfter)->toBe(7);
    expect($result->movements[0]->wasCoupled)->toBeFalse();
    expect($lot->id)->toBe($result->movements[0]->memberPackageId);
});

it('consumes lots FIFO — dated soonest first, never-expiring last', function () {
    $staff = redeemStaff();
    $member = redeemMember();

    // Three lots: soon (5d), later (30d), never (null). Each has 2 units.
    $never = redeemLot($member, null, [['item_code' => 'MASSAGE_60', 'qty' => 2]]);
    $later = redeemLot($member, now()->addDays(30), [['item_code' => 'MASSAGE_60', 'qty' => 2]]);
    $soon = redeemLot($member, now()->addDays(5), [['item_code' => 'MASSAGE_60', 'qty' => 2]]);

    // Redeem 5: should empty soon (2), empty later (2), take 1 from never.
    app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 5, $staff, null);

    $soonEnt = $soon->entitlements()->first();
    $laterEnt = $later->entitlements()->first();
    $neverEnt = $never->entitlements()->first();

    expect($soonEnt->qty_remaining)->toBe(0);
    expect($soonEnt->status)->toBe(EntitlementStatus::UsedUp);
    expect($laterEnt->qty_remaining)->toBe(0);
    expect($laterEnt->status)->toBe(EntitlementStatus::UsedUp);
    expect($neverEnt->qty_remaining)->toBe(1);
    expect($neverEnt->status)->toBe(EntitlementStatus::Active);
});

it('flips entitlement and lot to used_up when fully consumed (§5.7 rollup)', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    $lot = redeemLot($member, now()->addDays(30), [
        ['item_code' => 'MASSAGE_60', 'qty' => 2],
    ]);

    app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 2, $staff, null);

    $ent = $lot->entitlements()->first();
    expect($ent->qty_remaining)->toBe(0);
    expect($ent->status)->toBe(EntitlementStatus::UsedUp);

    // The lot rolls up to used_up because its only entitlement is used_up.
    expect($lot->fresh()->status)->toBe(EntitlementStatus::UsedUp);
});

it('does NOT roll up a lot to used_up while a sibling entitlement still has qty', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    // One lot, two DIFFERENT items (independent — no redeem_group).
    $lot = redeemLot($member, now()->addDays(30), [
        ['item_code' => 'MASSAGE_60', 'qty' => 2],
        ['item_code' => 'FOOT_30', 'qty' => 2],
    ]);

    // Redeem only the massage fully; the foot line still has 2 left.
    app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 2, $staff, null);

    expect($lot->fresh()->status)->toBe(EntitlementStatus::Active);
});

it('decrements coupled redeem_group siblings in the same lot (best-effort)', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    // Service primary + a bound Addon sibling share GRP_HOTSTONE in the same lot.
    // item_type is explicit (NOT redeemLot's `?? Service` default): the primary is
    // a Service so coupling fires, and the pulled sibling is a genuine Addon.
    $lot = redeemLot($member, now()->addDays(30), [
        ['item_code' => 'MASSAGE_60', 'item_type' => ItemType::Service, 'qty' => 5, 'redeem_group' => 'GRP_HOTSTONE'],
        ['item_code' => 'HOT_STONE', 'item_type' => ItemType::Addon, 'qty' => 5, 'redeem_group' => 'GRP_HOTSTONE'],
        // Independent add-on (null group) must NOT be touched.
        ['item_code' => 'TEA', 'item_type' => ItemType::Addon, 'qty' => 5, 'redeem_group' => null],
    ]);

    $result = app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 2, $staff, null);

    $massage = Entitlement::where('item_code', 'MASSAGE_60')->sole();
    $hotStone = Entitlement::where('item_code', 'HOT_STONE')->sole();
    $tea = Entitlement::where('item_code', 'TEA')->sole();

    expect($massage->qty_remaining)->toBe(3);   // primary -2
    expect($hotStone->qty_remaining)->toBe(3);   // coupled -2
    expect($tea->qty_remaining)->toBe(5);        // independent untouched

    // Two redeem ledger rows (primary + coupled sibling), each balance_after == its remaining.
    $redeemRows = EntitlementLedger::where('reason', LedgerReason::Redeem)->get();
    expect($redeemRows)->toHaveCount(2);

    // Result has a primary + a coupled movement.
    expect($result->movements)->toHaveCount(2);
    $coupled = collect($result->movements)->firstWhere('wasCoupled', true);
    expect($coupled->itemCode)->toBe('HOT_STONE');
    expect($coupled->taken)->toBe(2);
});

it('does not let a run-out coupled add-on block the primary (best-effort coupling)', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    // The add-on has only 1 unit; the service has 5. Same group. The primary is
    // genuinely a Service (so coupling fires) and the sibling a real Addon — NOT
    // relying on redeemLot's `?? Service` default.
    $lot = redeemLot($member, now()->addDays(30), [
        ['item_code' => 'MASSAGE_60', 'item_type' => ItemType::Service, 'qty' => 5, 'redeem_group' => 'GRP_HOTSTONE'],
        ['item_code' => 'HOT_STONE', 'item_type' => ItemType::Addon, 'qty' => 1, 'redeem_group' => 'GRP_HOTSTONE'],
    ]);

    // Redeem 3 massages: primary -3 succeeds; the add-on takes min(3,1)=1 then is
    // exhausted. The shortfall must NOT abort the redemption.
    $result = app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 3, $staff, null);

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(2);

    $addon = Entitlement::where('item_code', 'HOT_STONE')->sole();
    expect($addon->qty_remaining)->toBe(0);
    expect($addon->status)->toBe(EntitlementStatus::UsedUp);

    // Primary fully deducted despite the add-on running out.
    expect($result->totalTakenForRequestedItem())->toBe(3);
});

it('does NOT pull the service when an add-on is redeemed directly (asymmetric coupling)', function () {
    // Coupling is ASYMMETRIC (RedemptionService step (4)): the pull only fires when
    // the PRIMARY redeemed entitlement is an ItemType::Service. Redeeming the bound
    // Addon on its own must touch ONLY the add-on — the service is left intact.
    // This fails under the old symmetric implementation and passes now.
    $staff = redeemStaff();
    $member = redeemMember();
    redeemLot($member, now()->addDays(30), [
        ['item_code' => 'MASSAGE_60', 'item_type' => ItemType::Service, 'qty' => 5, 'redeem_group' => 'GRP_HOTSTONE'],
        ['item_code' => 'HOT_STONE', 'item_type' => ItemType::Addon, 'qty' => 5, 'redeem_group' => 'GRP_HOTSTONE'],
    ]);

    $result = app(RedemptionService::class)->redeem($member, 'HOT_STONE', 2, $staff, null);

    expect(Entitlement::where('item_code', 'HOT_STONE')->sole()->qty_remaining)->toBe(3);
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(5); // untouched

    // Exactly one movement (the add-on) and exactly one redeem ledger row — the
    // service is neither decremented nor ledgered.
    expect($result->movements)->toHaveCount(1);
    expect($result->movements[0]->itemCode)->toBe('HOT_STONE');
    expect($result->movements[0]->wasCoupled)->toBeFalse();
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe(1);
});

it('drains an entitlement to zero then throws on the next redeem without over-drawing (concurrency intent)', function () {
    // Models the double-spend race deterministically end-to-end: redeem the FULL
    // available qty (entitlement → used_up at 0), then a follow-up redeem of the
    // same item must hit the §6.3 lockForUpdate-guarded balance gate and throw —
    // the would-be "second cashier" is rejected, never over-drawing past zero.
    $staff = redeemStaff();
    $member = redeemMember();
    redeemLot($member, now()->addDays(30), [['item_code' => 'MASSAGE_60', 'qty' => 4]]);

    // First redeem drains all 4 to zero.
    app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 4, $staff, null);

    $drained = Entitlement::where('item_code', 'MASSAGE_60')->sole();
    expect($drained->qty_remaining)->toBe(0);
    expect($drained->status)->toBe(EntitlementStatus::UsedUp);

    $redeemAfterDrain = EntitlementLedger::where('reason', LedgerReason::Redeem)->count();

    // Second redeem of 1 more finds nothing redeemable → throws, writes nothing.
    expect(fn () => app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 1, $staff, null))
        ->toThrow(RedemptionException::class);

    // No over-draw: remaining stays pinned at 0 and no extra redeem row slipped in.
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(0);
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe($redeemAfterDrain);

    // NOTE: a `->toSql()` "for update" lock-contract assertion was intentionally
    // omitted — this suite runs on SQLite (:memory:), whose grammar compiles
    // lockForUpdate() to a no-op, so `for update` never appears. The drain-then-
    // reject behavior above IS the observable concurrency contract under test.
});

it('throws RedemptionException and writes ZERO rows when the balance is insufficient', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    redeemLot($member, now()->addDays(30), [['item_code' => 'MASSAGE_60', 'qty' => 2]]);

    $redeemBefore = EntitlementLedger::where('reason', LedgerReason::Redeem)->count();
    $ledgerBefore = EntitlementLedger::count(); // purchase rows only at this point

    expect(fn () => app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 5, $staff, null))
        ->toThrow(RedemptionException::class);

    // Nothing changed: no decrement, no redeem ledger row (whole txn rolled back, §5.2).
    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(2);
    expect(EntitlementLedger::where('reason', LedgerReason::Redeem)->count())->toBe($redeemBefore);

    // Stronger: the TOTAL ledger count is unchanged — no orphan row of ANY reason
    // (the purchase rows are all that exist; the failed txn added nothing).
    $this->assertDatabaseCount('entitlement_ledger', $ledgerBefore);
});

it('throws when the member holds nothing redeemable for the item', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    // Holds a DIFFERENT item only.
    redeemLot($member, now()->addDays(30), [['item_code' => 'FOOT_30', 'qty' => 5]]);

    expect(fn () => app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 1, $staff, null))
        ->toThrow(RedemptionException::class);
});

it('ignores expired lots in FIFO selection', function () {
    $staff = redeemStaff();
    $member = redeemMember();
    // An expired lot (past expires_at) holds 5; only a 2-unit live lot is eligible.
    redeemLot($member, now()->subDay(), [['item_code' => 'MASSAGE_60', 'qty' => 5]]);
    redeemLot($member, now()->addDays(30), [['item_code' => 'MASSAGE_60', 'qty' => 2]]);

    // Requesting 3 must fail — only 2 are redeemable (the expired 5 don't count).
    expect(fn () => app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 3, $staff, null))
        ->toThrow(RedemptionException::class);
});

it('scopes a branch-staff redemption to any-branch + their branch lots (§5.5)', function () {
    $branchA = Branch::create(['name' => 'Branch A', 'is_active' => true]);
    $branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);

    $staffA = redeemStaff(UserRole::Staff, $branchA->id);
    $member = redeemMember();

    // Lot at branch B (ineligible for staff A) + an any-branch lot (null, eligible).
    redeemLot($member, now()->addDays(30), [['item_code' => 'MASSAGE_60', 'qty' => 5]], branchId: $branchB->id);
    redeemLot($member, now()->addDays(30), [['item_code' => 'MASSAGE_60', 'qty' => 2]], branchId: null);

    // Staff A may only reach the any-branch 2 + nothing from branch B → 3 fails.
    expect(fn () => app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 3, $staffA, $branchA->id))
        ->toThrow(RedemptionException::class);

    // But 2 succeeds against the any-branch lot.
    app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 2, $staffA, $branchA->id);
    expect((int) Entitlement::where('item_code', 'MASSAGE_60')->where('member_package_id', '!=', null)->get()->sum('qty_remaining'))->toBe(5);
});

it('lets an owner (null branch) redeem any lot regardless of branch scope', function () {
    $branchB = Branch::create(['name' => 'Branch B', 'is_active' => true]);
    $owner = redeemStaff(UserRole::Owner, null);
    $member = redeemMember();

    // Only a branch-B-scoped lot exists. An owner is unscoped → may redeem it.
    redeemLot($member, now()->addDays(30), [['item_code' => 'MASSAGE_60', 'qty' => 5]], branchId: $branchB->id);

    app(RedemptionService::class)->redeem($member, 'MASSAGE_60', 4, $owner, null);

    expect(Entitlement::where('item_code', 'MASSAGE_60')->sole()->qty_remaining)->toBe(1);
});
