<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageRequest;
use App\Models\Branch;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin catalog — Packages + nested package_lines (architecture.md §3.4, §3.5).
 * Owner-only (gated at the route via `role:owner`).
 *
 * GOLDEN RULE: editing the catalog NEVER touches sold lots/entitlements — those
 * are value-copied snapshots taken at purchase (§5.1). Every mutation here only
 * changes the catalog definition; sold data is frozen.
 */
class PackageController extends Controller
{
    /**
     * Paginated package list (20/page), newest first.
     *
     * N+1 guard: eager-load `branch` and count `lines` so the table renders the
     * branch name + line count without a query per row (§6.4).
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Packages/Index', [
            'packages' => Package::query()
                ->with('branch:id,name')
                ->withCount('lines')
                ->orderByDesc('id')
                ->paginate(20),
            // Active branches for an optional client-side filter dropdown.
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Show the create form with the active-branch picker.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Packages/Create', [
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Create a package and its lines atomically.
     */
    public function store(StorePackageRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $lines = $data['lines'];
        unset($data['lines']);

        DB::transaction(function () use ($data, $lines): void {
            $package = Package::create($data);

            // createMany stamps package_id on each; `id` never appears on create.
            $package->lines()->createMany(array_map(
                static fn (array $line): array => self::lineAttributes($line),
                $lines,
            ));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('สร้างแพ็คเกจแล้ว')]);

        return to_route('packages.index');
    }

    /**
     * Show the edit form with the package, its lines, and the branch picker.
     *
     * N+1 guard: eager-load `lines` so the editor doesn't lazy-load them (§6.4).
     */
    public function edit(Package $package): Response
    {
        $package->load('lines');

        return Inertia::render('Admin/Packages/Edit', [
            'package' => $package,
            // Active branches PLUS the package's current branch even if it has
            // since been deactivated, so the existing scope stays visible/keepable.
            'branches' => Branch::query()
                ->where(function ($q) use ($package): void {
                    $q->where('is_active', true);

                    if ($package->branch_id !== null) {
                        $q->orWhere('id', $package->branch_id);
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Update a package and REPLACE its lines (architecture.md §3.5): the existing
     * lines are deleted and recreated from the payload inside one transaction.
     *
     * A full replace (rather than an id-by-id sync) is simpler AND avoids a
     * transient unique-constraint collision on `(package_id, item_code)` when two
     * kept lines swap their item_code mid-update. It is safe because package_lines
     * have NO dependents — sold entitlements are value-copied snapshots with no FK
     * back here (§5.1), so no member's balance is ever touched. (Line `id`s in the
     * payload are ignored.)
     */
    public function update(UpdatePackageRequest $request, Package $package): RedirectResponse
    {
        $data = $request->validated();
        $lines = $data['lines'];
        unset($data['lines']);

        DB::transaction(function () use ($package, $data, $lines): void {
            $package->update($data);

            // Replace the whole line set — delete then recreate (collision-proof).
            $package->lines()->delete();

            $package->lines()->createMany(array_map(
                static fn (array $line): array => self::lineAttributes($line),
                $lines,
            ));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('อัปเดตแพ็คเกจแล้ว')]);

        return to_route('packages.index');
    }

    /**
     * Delete a package. `package_lines` CASCADE; `member_packages.package_id` is
     * SET NULL (§3.5, §3.6) so sold lots survive as provenance-less records — no
     * RESTRICT here, so no try/catch is needed.
     */
    public function destroy(Package $package): RedirectResponse
    {
        $package->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ลบแพ็คเกจแล้ว')]);

        return to_route('packages.index');
    }

    /**
     * Flip `is_active` (soft hide-from-sale without delete, §3.4). Kept separate
     * from update/destroy so the index can toggle inline with a single PATCH.
     */
    public function toggle(Package $package): RedirectResponse
    {
        $package->update(['is_active' => ! $package->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $package->is_active ? __('เปิดขายแพ็คเกจแล้ว') : __('ปิดขายแพ็คเกจแล้ว'),
        ]);

        return back();
    }

    /**
     * Whitelist the line columns written to package_lines. Drops `id` (handled by
     * the sync) and any stray keys, and normalizes empty redeem_group → null
     * (independent line, §5.3).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function lineAttributes(array $line): array
    {
        return [
            'item_code' => $line['item_code'],
            'item_name' => $line['item_name'],
            'item_type' => $line['item_type'],
            'qty' => (int) $line['qty'],
            'redeem_group' => ($line['redeem_group'] ?? '') === '' ? null : $line['redeem_group'],
        ];
    }
}
