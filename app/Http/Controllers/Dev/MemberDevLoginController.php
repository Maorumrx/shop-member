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
 * browser WITHOUT a real device or a LINE login. Defended in depth so that a
 * single misconfiguration (e.g. the 2026 prod incident where APP_ENV was left
 * as `local`) can NOT reopen this authentication bypass:
 *
 *   1. Route registration — the two routes live in routes/dev.php, which
 *      bootstrap/app.php loads ONLY under `app()->environment('local')`, so in
 *      staging/prod they are never registered at all (a 404 with no handler).
 *   2. Environment guard — every action re-checks `app()->environment('local')`.
 *   3. Opt-in config flag — every action re-checks `config('app.dev_login_enabled')`
 *      (env DEV_LOGIN_ENABLED, default FALSE). APP_ENV=local ALONE is not enough.
 *   4. Host allowlist — every action 404s unless the RAW client Host header is a
 *      known local dev host, AND 404s outright if any proxy/forwarded-host header
 *      is present. This keys on the literal `HTTP_HOST` server var (NOT
 *      `$request->getHost()`), so it stays closed on the production domain even if
 *      APP_ENV/flags regress — and cannot be spoofed via `X-Forwarded-Host` behind
 *      a trusted proxy (deploy runs trustProxies:'*'; Symfony's getHost() would
 *      return the forwarded value BEFORE the trusted-host check, HTTP_HOST does not).
 *
 * ⚠️ This is an authentication bypass. Do NOT relax any guard, and do NOT
 * register the routes outside the local block in bootstrap/app.php.
 */
class MemberDevLoginController extends Controller
{
    /**
     * Hosts on which the dev-login backdoor may EVER answer. An ALLOWLIST (not a
     * denylist of the prod domain) so it fails CLOSED: any host that is not an
     * explicit local dev host — including bansuan-thaimassage.com and any future
     * prod domain — gets a 404. `*.test` (Herd/Valet) is matched by suffix below.
     *
     * @var list<string>
     */
    private const DEV_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * Multi-layer guard shared by both actions. Aborts 404 (never 403 — we do not
     * even hint the route exists) unless ALL of: local env, opt-in flag on, no
     * proxy/forwarded-host header, and a recognised RAW client Host. See the class
     * docblock for the rationale.
     */
    private function guard(Request $request): void
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless(config('app.dev_login_enabled'), 404);

        // Legitimate dev-login runs on a developer's machine with NO proxy in front,
        // so any forwarded-host header means "not a real local request" → 404. This
        // also pre-empts the X-Forwarded-Host spoofing vector below.
        abort_if(
            $request->headers->has('X-Forwarded-Host') || $request->headers->has('Forwarded'),
            404,
        );

        // Derive the host from the RAW `Host` header (HTTP_HOST), NOT from
        // $request->getHost(): behind a trusted proxy (deploy uses trustProxies:'*')
        // getHost() returns the attacker-controlled X-Forwarded-Host value BEFORE the
        // trusted-host check, defeating this allowlist. HTTP_HOST is the literal client
        // Host header and is never rewritten by the trusted-proxy branch. Fail closed
        // (404) when it is missing. Strip any :port and lowercase (RFC 952/2181).
        $rawHost = $request->server->get('HTTP_HOST');
        abort_if($rawHost === null || $rawHost === '', 404);

        $host = strtolower((string) preg_replace('/:\d+$/', '', trim((string) $rawHost)));
        abort_unless(
            in_array($host, self::DEV_HOSTS, true) || str_ends_with($host, '.test'),
            404,
        );
    }

    /**
     * A minimal picker: active members, each with a "log in as" link. Plain HTML
     * (no Inertia/Vue) because it's a throwaway dev utility. Names/phones are
     * HTML-escaped.
     */
    public function index(Request $request): Response
    {
        $this->guard($request);

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
        $this->guard($request);

        Auth::guard('members')->login($member);
        $request->session()->regenerate();

        return redirect()->route('member.dashboard');
    }
}
