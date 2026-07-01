<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchBookingSettingRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\BranchBookingSetting;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin catalog — Branches (architecture.md §3.1). Owner-only (gated at the
 * route via `role:owner`). Branches are reference/scoping data, toggled off via
 * `is_active` rather than deleted; a branch with packages bound to it cannot be
 * deleted (FK is ON DELETE RESTRICT, §3.4).
 */
class BranchController extends Controller
{
    /**
     * Paginated branch list (20/page), alphabetical. Each row carries its
     * per-branch booking config (`booking`, null when no settings row exists) so
     * the Vue side can pre-fill the "ตั้งค่าการจอง" dialog and flag which branches
     * are currently bookable. Times are trimmed to 'H:i' for the UI (the column
     * stores 'H:i:s'; the editor re-appends seconds on save).
     */
    public function index(): Response
    {
        $branches = Branch::query()
            ->with('bookingSetting')
            ->orderBy('name')
            ->paginate(20)
            ->through(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_active' => $branch->is_active,
                'booking' => $branch->bookingSetting === null ? null : [
                    'is_bookable' => $branch->bookingSetting->is_bookable,
                    'slot_capacity' => $branch->bookingSetting->slot_capacity,
                    'slot_length_minutes' => $branch->bookingSetting->slot_length_minutes,
                    'open_time' => substr($branch->bookingSetting->open_time, 0, 5),
                    'close_time' => substr($branch->bookingSetting->close_time, 0, 5),
                    'max_advance_days' => $branch->bookingSetting->max_advance_days,
                ],
            ]);

        return Inertia::render('Admin/Branches/Index', [
            'branches' => $branches,
        ]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        Branch::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('เพิ่มสาขาแล้ว')]);

        return to_route('branches.index');
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('อัปเดตสาขาแล้ว')]);

        return to_route('branches.index');
    }

    /**
     * Delete a branch. `packages.branch_id` is ON DELETE RESTRICT (§3.4), so a
     * branch with packages bound to it raises a QueryException at the DB. Catch
     * it and flash a friendly error instead of surfacing a 500. (Inactive
     * branches can still be deleted once no package references them.)
     */
    public function destroy(Branch $branch): RedirectResponse
    {
        try {
            $branch->delete();
        } catch (QueryException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('ลบไม่ได้: มีแพ็คเกจผูกกับสาขานี้'),
            ]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ลบสาขาแล้ว')]);

        return to_route('branches.index');
    }

    /**
     * Flip `is_active` (soft hide-from-selection without delete, §3.1). Kept
     * separate from update/destroy so the index can toggle inline with a single
     * PATCH.
     */
    public function toggle(Branch $branch): RedirectResponse
    {
        $branch->update(['is_active' => ! $branch->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $branch->is_active ? __('เปิดใช้งานสาขาแล้ว') : __('ปิดใช้งานสาขาแล้ว'),
        ]);

        return back();
    }

    /**
     * Upsert the branch's booking config (Phase 7, docs/phase7-booking-design.md
     * §3.1). A branch has at most one `branch_booking_settings` row (1:1), so a
     * single `updateOrCreate` keyed on `branch_id` both creates the first config
     * and edits an existing one. Owner-only (gated at the route via `role:owner`).
     *
     * Times arrive as 'H:i' from the UI and are normalized to the column's
     * 'H:i:s' TIME shape by the request; edits take effect immediately because
     * {@see \App\Services\Booking\BookingService} reads this row live (no cache).
     */
    public function updateBookingSettings(UpdateBranchBookingSettingRequest $request, Branch $branch): RedirectResponse
    {
        BranchBookingSetting::updateOrCreate(
            ['branch_id' => $branch->id],
            $request->settingsData(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('บันทึกการตั้งค่าการจองแล้ว')]);

        return to_route('branches.index');
    }
}
