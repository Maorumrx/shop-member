<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Models\Branch;
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
     * Paginated branch list (20/page), alphabetical.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Branches/Index', [
            'branches' => Branch::orderBy('name')->paginate(20),
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
}
