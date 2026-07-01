/**
 * Login.vue — LINE LIFF token-freshness / anti-loop behaviour lock-down.
 *
 * These tests exercise the `authenticate()` flow that runs in `onMounted`, with
 * a laser focus on the ONE-SHOT refresh guard (`refreshAlreadySpent` /
 * `refreshIdToken` / `markRefreshSpent` / `clearRefreshMarkers`) and the token
 * staleness check (`isIdTokenStale`). The load-bearing guarantee is case (c):
 * once `?login_refreshed=1` is on the URL, a still-stale token must NEVER trigger
 * a second `liff.logout()`+`liff.login()` — it must fall through to the error
 * screen. The suite asserts the EXACT number of `liff.login` calls in each case.
 *
 * Everything external is mocked: `@line/liff`, `axios`, `@inertiajs/vue3`
 * (usePage + router.visit), plus `window.location` / `window.history` /
 * `sessionStorage`. MemberLayout and the lucide icons are stubbed so the tests
 * stay pinned to the auth logic (no theme/composable side-effects).
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/* ── Mocks ────────────────────────────────────────────────────────────────── */

// @line/liff is a DEFAULT export; give every method used by the component.
const liffMock = {
    init: vi.fn().mockResolvedValue(undefined),
    isLoggedIn: vi.fn().mockReturnValue(true),
    getIDToken: vi.fn().mockReturnValue('id-token-abc'),
    getDecodedIDToken: vi.fn(),
    logout: vi.fn(),
    login: vi.fn(),
};
vi.mock('@line/liff', () => ({ default: liffMock }));

// axios: the component calls `axios.post`, reads `axios.isAxiosError`, and sets
// `axios.defaults.*` at module scope. Provide all three off the default export.
const axiosPost = vi.fn();
const isAxiosError = vi.fn();
vi.mock('axios', () => {
    const mock = {
        post: axiosPost,
        isAxiosError,
        defaults: {},
    };
    return { default: mock };
});

// Inertia: usePage() supplies the lineLiffId prop; router.visit is the redirect.
const routerVisit = vi.fn();
const pageProps = { lineLiffId: 'liff-1234' as string | undefined };
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    router: { visit: routerVisit },
}));

/* ── Helpers ─────────────────────────────────────────────────────────────── */

/** Build an axios-style 4xx error and make isAxiosError recognise it. */
function axiosError(status: number, data: Record<string, unknown> = {}) {
    const err = Object.assign(new Error(`http ${status}`), {
        response: { status, data },
    });
    isAxiosError.mockImplementation((e: unknown) => e === err);
    return err;
}

/** Decoded id-token whose `exp` is `deltaSeconds` from now (negative = past). */
function decodedTokenExpiringIn(deltaSeconds: number) {
    return { exp: Math.floor(Date.now() / 1000) + deltaSeconds };
}

/** Point window.location at `/member` + the given query string, and make the
 *  URL/href readable so `refreshAlreadySpent()` and URL building work. */
function setLocation(search: string) {
    const href = `https://shop.example/member${search}`;
    Object.defineProperty(window, 'location', {
        configurable: true,
        writable: true,
        value: { href, search, origin: 'https://shop.example' },
    });
}

const replaceState = vi.fn();

/** Mount Login.vue and let onMounted's async authenticate() settle. */
async function mountLogin() {
    // Imported lazily so each test picks up the current mocks/location.
    const Login = (await import('./Login.vue')).default;
    const wrapper = mount(Login, {
        global: {
            stubs: {
                // Render the default slot so phase-driven markup is inspectable,
                // without dragging in the theme composable MemberLayout uses.
                MemberLayout: { template: '<div><slot /></div>' },
                KeyRound: true,
                UserPlus: true,
            },
        },
    });
    // Two flushes: liff.init() resolves, THEN the axios.post() chain resolves.
    await flushPromises();
    await flushPromises();
    return wrapper;
}

/* ── Fixtures reset ──────────────────────────────────────────────────────── */

beforeEach(() => {
    vi.clearAllMocks();

    // Default: signed in, fresh token, matched member.
    liffMock.init.mockResolvedValue(undefined);
    liffMock.isLoggedIn.mockReturnValue(true);
    liffMock.getIDToken.mockReturnValue('id-token-abc');
    liffMock.getDecodedIDToken.mockReturnValue(decodedTokenExpiringIn(3600));
    axiosPost.mockResolvedValue({ data: { ok: true } });
    isAxiosError.mockReturnValue(false);
    pageProps.lineLiffId = 'liff-1234';

    // Fresh, throw-free sessionStorage each test.
    const store = new Map<string, string>();
    const storage = {
        getItem: vi.fn((k: string) => (store.has(k) ? store.get(k)! : null)),
        setItem: vi.fn((k: string, v: string) => void store.set(k, v)),
        removeItem: vi.fn((k: string) => void store.delete(k)),
        clear: vi.fn(() => store.clear()),
        key: vi.fn(),
        length: 0,
    };
    Object.defineProperty(window, 'sessionStorage', {
        configurable: true,
        writable: true,
        value: storage,
    });

    replaceState.mockReset();
    Object.defineProperty(window, 'history', {
        configurable: true,
        writable: true,
        value: { state: null, replaceState },
    });

    setLocation(''); // no ?login_refreshed by default
});

afterEach(() => {
    vi.resetModules(); // so the lazy `import('./Login.vue')` re-evaluates cleanly
});

/* ── Cases ───────────────────────────────────────────────────────────────── */

describe('Login.vue — LINE LIFF token freshness & anti-loop', () => {
    it('(a) fresh token, matched member: one POST, dashboard visit, ZERO refresh, param cleared', async () => {
        setLocation(''); // already clean

        await mountLogin();

        // Verified the token exactly once.
        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect(axiosPost).toHaveBeenCalledWith('/member/line/login', {
            id_token: 'id-token-abc',
        });

        // Went to the dashboard.
        expect(routerVisit).toHaveBeenCalledWith('/member/dashboard');

        // No refresh dance at all.
        expect(liffMock.logout).not.toHaveBeenCalled();
        expect(liffMock.login).toHaveBeenCalledTimes(0);

        // clearRefreshMarkers() ran (removeItem always fires on a verified POST);
        // no param was present, so replaceState must NOT have been called.
        expect(window.sessionStorage.removeItem).toHaveBeenCalledWith(
            'member-login:token-refreshing',
        );
        expect(replaceState).not.toHaveBeenCalled();
    });

    it('(b) stale token, no param yet: exactly ONE logout+login with redirectUri carrying login_refreshed=1', async () => {
        liffMock.getDecodedIDToken.mockReturnValue(decodedTokenExpiringIn(10)); // inside 30s buffer -> stale
        setLocation('');

        await mountLogin();

        // Proactive refresh fired exactly once.
        expect(liffMock.logout).toHaveBeenCalledTimes(1);
        expect(liffMock.login).toHaveBeenCalledTimes(1);

        const arg = liffMock.login.mock.calls[0][0] as { redirectUri: string };
        expect(arg.redirectUri).toContain('login_refreshed=1');
        expect(arg.redirectUri).toContain('/member');

        // No verify POST, no dashboard — the browser is being redirected out.
        expect(axiosPost).not.toHaveBeenCalled();
        expect(routerVisit).not.toHaveBeenCalled();

        // Best-effort sentinel was written too.
        expect(window.sessionStorage.setItem).toHaveBeenCalledWith(
            'member-login:token-refreshing',
            '1',
        );
    });

    it('(c) ANTI-LOOP: param present + still stale -> NO second refresh, lands on error screen', async () => {
        liffMock.getDecodedIDToken.mockReturnValue(decodedTokenExpiringIn(-5)); // expired -> stale
        setLocation('?login_refreshed=1');

        const wrapper = await mountLogin();

        // The load-bearing guarantee: zero refresh despite a stale token.
        expect(liffMock.logout).not.toHaveBeenCalled();
        expect(liffMock.login).toHaveBeenCalledTimes(0);

        // No POST (we never got past the stale gate) and no dashboard visit.
        expect(axiosPost).not.toHaveBeenCalled();
        expect(routerVisit).not.toHaveBeenCalled();

        // Error screen with a retry button (generic, retryable error).
        expect(wrapper.get('[role="alert"]').text()).toContain(
            'ไม่สามารถเข้าสู่ระบบผ่าน LINE ได้',
        );
        expect(wrapper.find('button').exists()).toBe(true);
    });

    it('(d) ANTI-LOOP: sessionStorage throws + param present + still stale -> STILL no second refresh (param is authoritative)', async () => {
        liffMock.getDecodedIDToken.mockReturnValue(decodedTokenExpiringIn(-5));
        setLocation('?login_refreshed=1');

        // Hostile storage: every access throws.
        Object.defineProperty(window, 'sessionStorage', {
            configurable: true,
            writable: true,
            value: {
                getItem: vi.fn(() => {
                    throw new Error('storage blocked');
                }),
                setItem: vi.fn(() => {
                    throw new Error('storage blocked');
                }),
                removeItem: vi.fn(() => {
                    throw new Error('storage blocked');
                }),
            },
        });

        const wrapper = await mountLogin();

        // URL param alone must hold the line.
        expect(liffMock.logout).not.toHaveBeenCalled();
        expect(liffMock.login).toHaveBeenCalledTimes(0);
        expect(axiosPost).not.toHaveBeenCalled();
        expect(wrapper.get('[role="alert"]').text()).toContain(
            'ไม่สามารถเข้าสู่ระบบผ่าน LINE ได้',
        );
    });

    it('(e) 403 response: error screen, canRetry=false (no retry button), ZERO refresh', async () => {
        axiosPost.mockRejectedValue(
            axiosError(403, { message: 'บัญชีนี้ถูกระงับการใช้งาน' }),
        );
        setLocation('');

        const wrapper = await mountLogin();

        // One POST attempt, then a hard stop — never a refresh.
        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect(liffMock.logout).not.toHaveBeenCalled();
        expect(liffMock.login).toHaveBeenCalledTimes(0);
        expect(routerVisit).not.toHaveBeenCalled();

        const alert = wrapper.get('[role="alert"]');
        expect(alert.text()).toContain('บัญชีนี้ถูกระงับการใช้งาน');
        // canRetry === false -> no retry button rendered.
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('(f1) reactive: fresh-looking token, POST 422, no param yet -> exactly ONE refresh', async () => {
        // Token looks fresh (passes the proactive gate) but verify 422s.
        liffMock.getDecodedIDToken.mockReturnValue(decodedTokenExpiringIn(3600));
        axiosPost.mockRejectedValue(axiosError(422, { message: 'bad token' }));
        setLocation('');

        await mountLogin();

        // We POSTed once, it 422'd, and the reactive one-shot refresh fired.
        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect(liffMock.logout).toHaveBeenCalledTimes(1);
        expect(liffMock.login).toHaveBeenCalledTimes(1);

        const arg = liffMock.login.mock.calls[0][0] as { redirectUri: string };
        expect(arg.redirectUri).toContain('login_refreshed=1');
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('(f2) reactive: fresh-looking token, POST 422, param ALREADY present -> NO refresh, generic error', async () => {
        liffMock.getDecodedIDToken.mockReturnValue(decodedTokenExpiringIn(3600));
        axiosPost.mockRejectedValue(axiosError(422, { message: 'bad token' }));
        setLocation('?login_refreshed=1');

        const wrapper = await mountLogin();

        // POST happened, 422'd, but the spent-refresh guard blocks a second one.
        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect(liffMock.logout).not.toHaveBeenCalled();
        expect(liffMock.login).toHaveBeenCalledTimes(0);

        // Falls through to the generic retryable error.
        const alert = wrapper.get('[role="alert"]');
        expect(alert.text()).toContain('ไม่สามารถเข้าสู่ระบบผ่าน LINE ได้');
        expect(wrapper.find('button').exists()).toBe(true);
    });

    it('(g) needs_link 200: needs_link phase shown, markers cleared', async () => {
        axiosPost.mockResolvedValue({
            data: { ok: false, state: 'needs_link' },
        });
        setLocation('?login_refreshed=1'); // simulate arriving post-refresh

        const wrapper = await mountLogin();

        expect(axiosPost).toHaveBeenCalledTimes(1);

        // needs_link screen (link-or-create) — the welcome heading is rendered.
        expect(wrapper.html()).toContain('ยินดีต้อนรับ');
        expect(wrapper.find('#link-code').exists()).toBe(true);

        // Verified POST => clearRefreshMarkers() ran: sentinel removed AND the URL
        // param stripped via replaceState (it was present this time).
        expect(window.sessionStorage.removeItem).toHaveBeenCalledWith(
            'member-login:token-refreshing',
        );
        expect(replaceState).toHaveBeenCalledTimes(1);
        const replacedUrl = replaceState.mock.calls[0][2] as string;
        expect(replacedUrl).not.toContain('login_refreshed');

        // No dashboard visit on needs_link.
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('(bonus) getDecodedIDToken throws -> treated as stale, one refresh (no param yet)', async () => {
        liffMock.getDecodedIDToken.mockImplementation(() => {
            throw new Error('undecodable');
        });
        setLocation('');

        await mountLogin();

        expect(liffMock.logout).toHaveBeenCalledTimes(1);
        expect(liffMock.login).toHaveBeenCalledTimes(1);
        expect(axiosPost).not.toHaveBeenCalled();
    });

    it('(bonus) not logged in -> plain liff.login() (no redirectUri), no refresh markers', async () => {
        liffMock.isLoggedIn.mockReturnValue(false);
        setLocation('');

        await mountLogin();

        expect(liffMock.login).toHaveBeenCalledTimes(1);
        // Plain bounce to LINE: called with no args (undefined), NOT the refresh form.
        expect(liffMock.login.mock.calls[0][0]).toBeUndefined();
        expect(liffMock.logout).not.toHaveBeenCalled();
        expect(axiosPost).not.toHaveBeenCalled();
    });
});
