<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Branch;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin catalog — the `services` baht PRICE LIST (the money-wallet reframe of the
 * dropped Package catalog). Owner-only (gated at the route via `role:owner`),
 * mirroring the old `packages.*` authorization.
 *
 * GOLDEN RULE: editing the price list NEVER rewrites past debits — every
 * credit_ledger debit row froze the baht taken at the time (§5.6). Every mutation
 * here only changes the live catalog definition; sold/consumed history is frozen.
 */
class ServiceController extends Controller
{
    /**
     * Paginated service list (20/page), newest first.
     *
     * N+1 guard: eager-load `branch:id,name` so the table renders the branch scope
     * without a query per row (§6.4).
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Services/Index', [
            'services' => Service::query()
                ->with('branch:id,name')
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
        return Inertia::render('Admin/Services/Create', [
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Create a service price-list row. `item_code` uniqueness is enforced by the
     * request (and the DB).
     */
    public function store(StoreServiceRequest $request): RedirectResponse
    {
        Service::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('เพิ่มบริการแล้ว')]);

        return to_route('services.index');
    }

    /**
     * Show the edit form with the service + the branch picker.
     */
    public function edit(Service $service): Response
    {
        return Inertia::render('Admin/Services/Edit', [
            'service' => $service,
            // Active branches PLUS the service's current branch even if it has since
            // been deactivated, so the existing scope stays visible/keepable.
            'branches' => Branch::query()
                ->where(function ($q) use ($service): void {
                    $q->where('is_active', true);

                    if ($service->branch_id !== null) {
                        $q->orWhere('id', $service->branch_id);
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Update a service. Editing the price never touches past debits (§5.6).
     */
    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('อัปเดตบริการแล้ว')]);

        return to_route('services.index');
    }

    /**
     * Delete a service. `services.branch_id` is SET NULL, and past credit_ledger
     * debit rows keep their frozen amounts (services carries no dependent FK back),
     * so no RESTRICT and no try/catch is needed.
     */
    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ลบบริการแล้ว')]);

        return to_route('services.index');
    }

    /**
     * Flip `is_active` (hide-from-list without delete). Kept separate so the index
     * can toggle inline with a single PATCH.
     */
    public function toggle(Service $service): RedirectResponse
    {
        $service->update(['is_active' => ! $service->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $service->is_active ? __('เปิดใช้งานบริการแล้ว') : __('ปิดใช้งานบริการแล้ว'),
        ]);

        return back();
    }
}
