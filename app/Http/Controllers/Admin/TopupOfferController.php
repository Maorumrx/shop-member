<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTopupOfferRequest;
use App\Http\Requests\Admin\UpdateTopupOfferRequest;
use App\Models\TopupOffer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin catalog — the `topup_offers` presets shown on the sell screen (one-tap
 * "pay `amount` → get `amount + bonus`"). Owner-only (route `role:owner`),
 * mirroring the old catalog authorization.
 *
 * Presets are simple single-row config managed inline on the index (no dedicated
 * create/edit pages, like Branches). Editing a preset NEVER touches already-sold
 * `credit_lots` — a lot snapshots its amounts at sale.
 */
class TopupOfferController extends Controller
{
    /**
     * All presets ordered for the sell screen (sort_order, then id). Not paginated —
     * this is a short quick-pick list.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/TopupOffers/Index', [
            'offers' => TopupOffer::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'amount', 'bonus', 'is_active', 'sort_order']),
        ]);
    }

    /**
     * Create a preset. Money columns are decimal-2 strings (§5.6).
     */
    public function store(StoreTopupOfferRequest $request): RedirectResponse
    {
        TopupOffer::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('เพิ่มแพ็กเกจเติมเครดิตแล้ว')]);

        return to_route('topup-offers.index');
    }

    /**
     * Update a preset. Never touches sold lots.
     */
    public function update(UpdateTopupOfferRequest $request, TopupOffer $topupOffer): RedirectResponse
    {
        $topupOffer->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('อัปเดตแพ็กเกจเติมเครดิตแล้ว')]);

        return to_route('topup-offers.index');
    }

    /**
     * Delete a preset. No dependents (a sold lot snapshots its amounts, no FK back),
     * so no RESTRICT / try-catch needed.
     */
    public function destroy(TopupOffer $topupOffer): RedirectResponse
    {
        $topupOffer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ลบแพ็กเกจเติมเครดิตแล้ว')]);

        return to_route('topup-offers.index');
    }

    /**
     * Flip `is_active` (hide-from-sell without delete). Inline single-PATCH toggle.
     */
    public function toggle(TopupOffer $topupOffer): RedirectResponse
    {
        $topupOffer->update(['is_active' => ! $topupOffer->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $topupOffer->is_active ? __('เปิดใช้งานแพ็กเกจแล้ว') : __('ปิดใช้งานแพ็กเกจแล้ว'),
        ]);

        return back();
    }
}
