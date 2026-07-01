<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * DEV-ONLY passwordless member login — LOCAL ENVIRONMENT ONLY.
 *
 * Lets a developer browse the member LIFF UI (dashboard, booking) in a normal
 * browser WITHOUT a real device or a LINE login. The route is registered only
 * when `app()->environment('local')` (routes/member.php) AND every action here
 * re-guards with `abort_unless(app()->environment('local'), 404)`, so it can
 * NEVER be reached in staging/production.
 *
 * ⚠️ This is an authentication bypass. Do NOT relax the environment guard, and
 * do NOT register the route outside the local block.
 */
class MemberDevLoginController extends Controller
{
    /**
     * A minimal picker: active members, each with a "log in as" link. Plain HTML
     * (no Inertia/Vue) because it's a throwaway dev utility. Names/phones are
     * HTML-escaped.
     */
    public function index(): Response
    {
        abort_unless(app()->environment('local'), 404);

        $rows = Member::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->map(function (Member $m): string {
                $url = route('member.dev-login', $m);

                return '<li style="margin:.4rem 0"><a href="'.e($url).'">'
                    .e($m->name).'</a> <small style="color:#8a7e73">'
                    .e($m->phone ?? '—').'</small></li>';
            })
            ->implode('');

        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Dev member login</title></head>'
            .'<body style="font-family:sans-serif;max-width:32rem;margin:2rem auto;padding:0 1rem;color:#4a4039">'
            .'<p style="background:#f7e6c8;padding:.5rem .75rem;border-radius:.5rem">'
            .'⚠️ DEV เท่านั้น (local) — เข้าหน้า member โดยไม่ต้องล็อกอิน LINE</p>'
            .'<h1>เลือกสมาชิกเพื่อเข้าสู่ระบบ</h1>'
            .'<ul style="list-style:none;padding:0">'.$rows.'</ul>'
            .'</body></html>';

        return response($html);
    }

    /**
     * Log the chosen member into the `members` guard (mirrors the real LINE login:
     * guard login + session regenerate), then land on the member dashboard.
     */
    public function login(Request $request, Member $member): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        Auth::guard('members')->login($member);
        $request->session()->regenerate();

        return redirect()->route('member.dashboard');
    }
}
