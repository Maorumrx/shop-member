<?php

declare(strict_types=1);

// Phase 1 staged test — copied to tests/Feature/ and run AFTER scaffold via docker/phase1.sh.
// Covers Member soft delete (architecture.md §3.3, §5.4) — members are never hard-deleted.

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('sets deleted_at and excludes the member from default queries on delete', function () {
    $member = Member::create(['name' => 'Soft Delete Me', 'is_active' => true]);

    $member->delete();

    // Soft-deleted: trashed flag set, deleted_at populated.
    expect($member->trashed())->toBeTrue();
    expect($member->deleted_at)->not->toBeNull();

    // Excluded from default Eloquent queries.
    expect(Member::query()->find($member->id))->toBeNull();
    expect(Member::query()->count())->toBe(0);
});

it('keeps the row physically in the database after a soft delete', function () {
    $member = Member::create(['name' => 'Still In DB', 'is_active' => true]);

    $member->delete();

    // The row is NOT physically removed — confirm via a raw count bypassing the global scope.
    $rawCount = DB::table('members')->where('id', $member->id)->count();
    expect($rawCount)->toBe(1);
});

it('finds a soft-deleted member with withTrashed()', function () {
    $member = Member::create(['name' => 'Find Me Trashed', 'is_active' => true]);
    $member->delete();

    $found = Member::withTrashed()->find($member->id);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($member->id);
    expect($found->trashed())->toBeTrue();
});

it('returns a soft-deleted member only via onlyTrashed()', function () {
    $live = Member::create(['name' => 'Live Member', 'is_active' => true]);
    $gone = Member::create(['name' => 'Gone Member', 'is_active' => true]);
    $gone->delete();

    $trashedIds = Member::onlyTrashed()->pluck('id')->all();

    expect($trashedIds)->toContain($gone->id);
    expect($trashedIds)->not->toContain($live->id);
});

it('restores a soft-deleted member', function () {
    $member = Member::create(['name' => 'Restore Me', 'is_active' => true]);
    $member->delete();
    expect(Member::query()->find($member->id))->toBeNull();

    $member->restore();

    expect(Member::query()->find($member->id))->not->toBeNull();
    expect($member->fresh()->deleted_at)->toBeNull();
});
