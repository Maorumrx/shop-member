# Member ↔ LINE Account Linking — Design (Phase 8)

**Status:** design only — no migrations/code yet. Approve, then implement.
**Stack:** Laravel 13 + MariaDB, Inertia/Vue LIFF.
**Owner-approved direction:** staff-generated **claim code**, entered by the customer in LIFF on first login. NO phone-only self-claim, NO SMS.

---

## 1. The problem, in plain words (for the shop owner)

Right now a customer can end up with **two separate accounts** in the system:

- **Counter account** — staff type the customer's **name + phone** at the shop and sell them a package. All their remaining sessions (their "credits") live on THIS account.
- **LINE account** — later the customer opens the shop's page inside LINE. The system doesn't know it's the same person, so it makes a **brand-new empty account** with their LINE name and photo, **no phone, and no packages**.

So the customer opens LINE, sees "0 sessions", and their real credits are stranded on the counter account. Bad first impression, and staff have to fix it by hand.

**The fix:** when a customer opens LINE for the first time, we ask a simple question — *"Do you have a code from the shop?"*

- **Yes** → they type a short 6-digit code the staff gave them → LINE gets attached to their **real** counter account (name, phone, and packages all intact). One account.
- **No / I'm new** → we create a fresh LINE account for them (a walk-in with no prior purchase).

The **code** is the security: only someone the staff physically handed a code to can grab a counter account. Nobody can steal a stranger's packages just by guessing their phone number.

### The customer's journey (one glance)

```
                 Customer opens the shop page inside LINE
                                  │
                          Logs in with LINE
                                  │
                 ┌────────────────┴─────────────────┐
        LINE already linked?                   First time here?
                 │                                    │
          Normal login                    Show a choice screen:
        (nothing changes)          ┌──────────────────┴───────────────────┐
                            "I have a code            "I'm new / no code"
                             from the shop"                    │
                                   │                    Create fresh LINE
                          Type the 6-digit code            account (walk-in)
                                   │                           │
                     ┌─────────────┴──────────────┐           ▼
                Code good?                   Code bad/expired            Logged in,
                     │                       /used/wrong                 empty account
            Attach LINE to the                     │
            real counter account          "Ask staff for a new code"
            (keeps name/phone/packages)
                     │
                     ▼
             Logged in, all their
             packages are there
```

### What the STAFF do (one glance)

```
Staff open the member's page in admin
        │
        ▼
Member already linked to LINE?  ── yes ──▶ "Generate code" is HIDDEN (nothing to link)
        │ no
        ▼
Click "Generate LINE claim code"
        │
        ▼
Screen shows: 4 8 2 9 1 7   (expires in 24h)
        │
        ▼
Tell / show the customer the code
(they type it in LINE)
```

---

## 2. Where the code lives — decision

**Recommendation: a small dedicated table `member_link_codes` — NOT columns on `members`.**

Both options were considered:

| Option | Verdict |
|---|---|
| **A. Columns on `members`** (`link_code_hash`, `link_code_expires_at`, `link_code_attempts`) | Rejected. Bloats the hot member row; every login/profile read drags dead code fields; no natural place for `consumed_at` / `created_by_user_id` audit; regenerate = mutate the member row; re-issuing while an old one is "live" is awkward. |
| **B. Dedicated `member_link_codes` table** ✅ | Chosen. Clean lifecycle (`consumed_at`), full audit (`created_by_user_id`), attempts counter without touching the member, and easy "single active code per member" via a **partial-style unique on the still-live code**. |

**Why a table wins the specific constraints in the brief:**

- **Single active code per member** — enforced by a unique index that only "sees" un-consumed rows (see §6, I20). Regenerating consumes/replaces the old one; history is retained (soft-consumed, not deleted).
- **Must be hashable** — the code is stored as a **SHA-256 hash**, never plaintext (see §3). A row is the natural home for the hash + salt-free lookup by `member_id`.
- **Must be rate-limitable** — the row carries an `attempts` counter for the brute-force guard, plus we key a Laravel `RateLimiter` on `line_user_id` + member (see §3).
- **Must NOT collide with the `line_user_id` unique index (I12)** — the code lives on its own table keyed by `member_id`, completely independent of `members.line_user_id`. No interaction with I12 at all.

### Proposed `member_link_codes` schema (design, not final SQL)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `member_id` | FK → `members` | **RESTRICT** on delete (members are soft-deleted only, §5.4 convention). The counter member this code claims. |
| `code_hash` | char(64) | **SHA-256 hex** of the 6-digit code. Never store plaintext. Looked up by `member_id`, then `hash_equals`-compared. |
| `expires_at` | datetime | Proposed **24h** window (owner-tunable, §8). |
| `attempts` | unsignedTinyInteger, default 0 | Failed-entry counter for the per-code brute-force cap (§3). |
| `consumed_at` | datetime, nullable | Set when the code is successfully used **or** superseded by a regenerate. Non-null = dead. |
| `consumed_by_line_user_id` | varchar(64), nullable | The LINE `sub` that redeemed it — audit + "who claimed this". |
| `created_by_user_id` | FK → `users`, nullable | Staff who generated it. **SET NULL** on staff delete (keep the audit row), mirroring the bookings `*_by_user_id` convention. |
| `created_at` / `updated_at` | timestamps | |

**No SoftDeletes on this table** — it is a short-lived credential/audit log, not member/financial data. Rows are either live, consumed, or expired; we retain them (don't hard-delete) for the audit trail, but the "single active" invariant is about `consumed_at IS NULL AND expires_at > now()`, not about a `deleted_at`.

**Note on the 6-digit space + hashing:** 6 digits = 1,000,000 values. A plain unsalted SHA-256 over a 1M space is *offline*-guessable if the DB leaks — but this hash is **never exposed** (no client ever receives it) and entry is **rate-limited at 5 attempts per code** (§3), so the online guess probability is ~5/1,000,000 per code before lockout. That is the real threat model here (online guessing), and it is well covered. We deliberately keep SHA-256 (fast, deterministic, no per-row salt needed for an *equality* lookup by `member_id`) rather than bcrypt, because we look the code up by `member_id` and compare one hash — not search by the code itself.

---

## 3. Security design

| Requirement | How it's met |
|---|---|
| **Store hashed, never plaintext** | Only `code_hash = hash('sha256', $code)` is persisted. The plaintext 6 digits exist only (a) momentarily at generation to show staff, and (b) in the customer's submit request. |
| **Expiry** | `expires_at` (proposed 24h). Submit checks `expires_at > now()`. |
| **Single-use** | On success we set `consumed_at` in the SAME transaction that attaches `line_user_id`. A consumed code can never validate again. |
| **Invalidated on regenerate** | Generating a new code marks any existing live code for that member `consumed_at = now()` (superseded) **inside one transaction**, then inserts the new one. Guarantees the "single active code per member" invariant. |
| **Per-code brute-force guard** | `attempts` increments on every wrong entry against a live code; at **5** failures the code is burned (`consumed_at = now()`, reason superseded) — the customer must ask staff for a fresh one. 6 digits ⇒ ≤5/1,000,000 guess odds per code. |
| **Per-caller rate limit** | A Laravel `RateLimiter` on the submit-code endpoint keyed by `line_user_id` (from the verified LIFF token, so un-spoofable) — e.g. **10 attempts / 10 min**, independent of which code. Stops one attacker spraying codes across many member rows. |
| **Already-linked member can't be claimed** | A member with a non-null `line_user_id` is **never** eligible: (a) admin "Generate code" is hidden/blocked for linked members; (b) submit-code re-checks the target member still has `line_user_id IS NULL` inside the claim transaction and aborts if not. |
| **Inactive / soft-deleted member can't be claimed** | Both generate and submit require `is_active = true AND deleted_at IS NULL` on the target member. A code can't even be minted for a disabled/deleted member, and submit re-verifies. |
| **What stops a SECOND LINE account claiming the same member** | Three layers: (1) after the first claim the member has `line_user_id` set, so it fails the "must be unlinked" check; (2) the code is `consumed_at`-stamped and single-use; (3) the DB `line_user_id` UNIQUE index (I12) is the final hard backstop against two members ending up with the same LINE id. The claim runs in a transaction with a **row lock** on the member (`SELECT ... FOR UPDATE`), so two concurrent submits serialize — the first wins, the second sees the now-linked row and is rejected. |

**Threat we explicitly reject:** phone-only self-claim. If a customer could type a phone number to claim, anyone could enter a stranger's phone and steal their packages. The code — a secret physically handed over by staff — is the whole point.

---

## 4. The login-flow change (Phase-2 impact)

### 4.1 Core change: **defer** auto-create — never mint an empty row on first login

Today `MemberLineLoginController@store` does `Member::createOrFirst([...])` for any unmatched `line_user_id`, which is exactly what strands packages and litters empty rows. The new behaviour:

**Matched path (UNCHANGED):** if `Member::withTrashed()->firstWhere('line_user_id', $sub)` finds a row → run the *existing* logic verbatim (reject trashed, reject inactive, backfill empty name/avatar, `Auth::guard('members')->login(remember: true)`, `session()->regenerate()`, return `{ ok: true }`).

**Unmatched path (NEW):** if NO member has this `line_user_id` → **do not create anything**. Return a distinct "needs decision" state:

```json
{ "ok": false, "state": "needs_link", "link_token": "<short-lived signed token>" }
```

- **No member is logged in during the pending state.** The customer is authenticated to *LINE* (we trust the `sub`), but there is no Member yet, so there is nothing to log into the `members` guard. Login happens **only after** link or create succeeds.
- To carry the verified `sub` safely from this response into the follow-up submit-code / create-new call **without re-verifying the id_token each time and without trusting the browser**, the response includes a **`link_token`**: a short-lived (e.g. 10 min) signed/stateful token that server-side maps to the verified `line_user_id` (+ name/picture snapshot). The follow-up endpoints accept `link_token` instead of re-sending `id_token`. (Equivalently, stash the verified profile in the session under a `pending_line_link` key — either is fine; the token keeps it stateless-friendly for Inertia.)

The Vue LIFF page, on seeing `state: needs_link`, renders the **link-or-create choice screen** (§1) instead of dropping the user on an empty dashboard.

### 4.2 New endpoints

| Endpoint | Guard / who | Purpose |
|---|---|---|
| `POST /admin/members/{member}/link-code` | `web` (users) — **owner + staff** | **Generate.** Mint a fresh 6-digit code for an unlinked, active, non-deleted member. Supersedes any live code for that member. Returns the **plaintext** code ONCE (to show staff) + `expires_at`. Blocked (409/422) if the member is already linked / inactive / deleted. |
| `POST /member/line/submit-code` | LIFF pending state (carries `link_token`, not the `members` guard yet) | **Link.** Customer submits the 6-digit code. Validates (live? not expired? attempts left?), then in one locked transaction: attaches `line_user_id` (+avatar backfill) to the target member, consumes the code, logs the member in, regenerates session. Returns `{ ok: true }`. |
| `POST /member/line/create-new` | LIFF pending state (carries `link_token`) | **Create.** Customer chose "I'm new". NOW create the LINE-linked member (this is the *only* place auto-create still happens, and only on explicit choice). Then log in + regenerate session. Returns `{ ok: true }`. |

`store` (the existing `POST /member/line/login`) keeps its route; only its *unmatched* branch changes (from auto-create → return `needs_link`).

### 4.3 Sequence (link path)

```
LIFF ──id_token──▶ POST /member/line/login
                        │ verify token → sub has no member
                        ▼
         { needs_link, link_token }  (NOT logged in)
                        │
LIFF shows choice → "I have a code" → customer types 482917
                        │
LIFF ──link_token + code──▶ POST /member/line/submit-code
                        │ RateLimiter(sub) ok?
                        │ find live code by hash → member (FOR UPDATE)
                        │ member unlinked + active + not-deleted?
                        ▼ (one transaction)
        attach line_user_id + avatar; consume code;
        login(members, remember); session regenerate
                        │
                        ▼
                  { ok: true } → dashboard shows real packages
```

---

## 5. Edge cases

| Case | Design decision |
|---|---|
| **Customer already did a LINE-first login (empty row exists), THEN wants a counter code** | **Prevented by construction.** Because we **defer** auto-create (§4.1), a first LINE login no longer makes an empty row — it lands in `needs_link`. So when they later get a code, their `line_user_id` still isn't attached to anything, and the code cleanly links it to the counter row. **There is nothing to merge or discard.** (Legacy empty rows created by the *old* auto-create code, if any exist pre-launch, are handled by a one-time cleanup/merge tool run once at deploy — out of scope for this flow, noted for the owner in §8.) |
| **Two counter rows share a phone** | Irrelevant to claiming — claiming is by **code**, not phone. Each row has its own code; the code identifies exactly one `member_id`. (Phone duplication is a separate admin hygiene concern; phone is a non-unique lookup index I11, intentionally.) |
| **Code entered for a member that meanwhile got linked** | Submit re-checks `line_user_id IS NULL` on the locked member row inside the transaction. If it's now linked, abort with "This code is no longer valid — ask staff." The code is also consumed the moment the member was linked by another path. |
| **Code entered for a member that got disabled/deleted meanwhile** | Same re-check: `is_active AND NOT trashed`. Fail closed with a generic "code no longer valid". |
| **Concurrency — two devices enter the SAME code** | The claim transaction takes `SELECT ... FOR UPDATE` on the member row (and reads the code row live). The first commits: attaches `line_user_id`, sets `consumed_at`. The second blocks, then reads a consumed code / already-linked member and is rejected. Exactly one wins; no duplicate link, no double-login mix-up. |
| **Regenerate while an old code is still live** | Generate supersedes: old live code → `consumed_at = now()` (reason: superseded), new code inserted, all in one transaction. Only the newest code ever validates. |
| **Customer fat-fingers the code 5×** | Per-code `attempts` hits the cap → code burned. Plus the per-`sub` RateLimiter throttles rapid spraying. Customer asks staff to regenerate. |
| **Same LINE `sub` tries to claim, but is mid-token-expiry** | The `link_token` (10 min) gates the pending window; if it expires the customer just logs in again (re-verifies LINE) and re-enters the code. No state corruption. |

---

## 6. Indexes / constraints (continuing I-numbering; current max is I19)

| # | Table | Index / constraint | Why |
|---|---|---|---|
| **I20** | `member_link_codes` | **UNIQUE** on the *live* code per member. MariaDB has no true partial unique index, so we implement it as a **generated `active_member_id` column** = `member_id` when `consumed_at IS NULL`, else `NULL`, with `UNIQUE(active_member_id)` — many-NULLs semantics give "one live code per member" while consumed rows drop out. (Alternative if a generated column is undesirable: enforce single-active in the generate transaction under the member row lock; keep I20 as a plain index. **Recommend the generated-column unique** for a hard DB guarantee.) |
| **I21** | `member_link_codes` | INDEX `(member_id, consumed_at, expires_at)` | The submit-code lookup: "the live code(s) for this member" → then `hash_equals`. Also the generate-time supersede scan. |
| **I22** | `member_link_codes` | FK auto-index on `created_by_user_id` | From `foreignId()->constrained('users')->nullOnDelete()` — audit joins. (`member_id` FK auto-index is covered as the leading col of I21 / the I20 unique; the redundant auto FK index is harmless.) |

**CHECK constraint** (MariaDB-guarded, `DB::getDriverName() !== 'sqlite'`, mirroring the entitlements/bookings pattern):

```
chk_link_codes_attempts:  CHECK (attempts >= 0 AND attempts <= 5)
```

**What the two hot lookups need:**

- **Login lookup** (unchanged, existing **I12** `UNIQUE(line_user_id)`): `WHERE line_user_id = ?` — resolves matched vs unmatched. No new index; the whole flow deliberately reuses I12.
- **Code lookup** (new **I21**): `WHERE member_id = ? AND consumed_at IS NULL AND expires_at > now()` — but note the customer submits a *code*, not a `member_id`. So the actual submit resolution is: **hash the submitted code → find the live row by `code_hash`.** That means we ALSO need a lookup by hash:

> **Correction/refinement:** submit-code doesn't know the `member_id` up front (the customer only types digits). So the primary submit lookup is **by `code_hash` among live rows**. Therefore make **I21 lead with `code_hash`**: INDEX `(code_hash, consumed_at)` — `WHERE code_hash = ? AND consumed_at IS NULL AND expires_at > now()`. Keep a second index `(member_id, consumed_at)` for the generate-time supersede scan. Renumbering to be explicit:

| # | Table | Index | Query it serves |
|---|---|---|---|
| **I20** | `member_link_codes` | UNIQUE `active_member_id` (generated: `member_id` while live, else NULL) | Single **live** code per member — hard DB guarantee. |
| **I21** | `member_link_codes` | INDEX `(code_hash, consumed_at)` | **Submit-code**: resolve the code the customer typed → its live row → `member_id`. |
| **I22** | `member_link_codes` | INDEX `(member_id, consumed_at)` | **Generate**: find/supersede this member's existing live code; admin "has a live code?" badge. |
| — | `member_link_codes` | FK auto-index on `created_by_user_id` | audit; not separately numbered (matches how I17 handled bookings FK auto-indexes). |

---

## 7. Minimal optional complement (mention only)

Let a **LINE-first member set their phone** in their LIFF profile, so staff can later find them by phone at the counter (phone is already the I11 counter-lookup index). This is a nice-to-have that *reduces* how often a code is even needed (a walk-in who bought nothing yet, then buys at the counter, could be found by the phone they self-entered).

**Verdict: deferrable — NOT needed for v1.** The claim-code flow fully solves the stated gap on its own. Add this later as a small profile-edit feature; it introduces no schema change (the `phone` column and I11 already exist). One caveat for whenever it's built: a self-entered member phone is **unverified** and must NEVER become a self-claim path — it's only a staff-side search hint.

---

## 8. Open questions / risks for the owner

1. **Code length & expiry defaults.** Proposal: **6 digits**, **24h** expiry, **single-use**, **5 wrong-attempts** then burn. Is 6 digits comfortable to read aloud/print? Is 24h the right window (longer = friendlier if the customer opens LINE next day; shorter = safer)? Should a code auto-expire sooner (e.g. 1h) for higher security shops?
2. **Staff-side linking from admin.** Should staff *also* be able to link from the admin side by **picking an already-existing LINE row** and merging it into a counter row (for the messy case where a customer LINE-first-registered before we deferred auto-create, or on legacy data)? This is a heavier "merge two members" tool (moves packages, retires one row). Recommend: **not in v1**; handle legacy empties with a one-time cleanup script, and rely on deferred-auto-create going forward so the case stops occurring.
3. **How the code reaches the customer.** Printed on the receipt vs read aloud by staff vs shown on the counter screen? Printed-on-receipt is most reliable but means the code sits on paper for 24h (mitigated by single-use + expiry + burn-on-5-fails). Read-aloud is most secret but error-prone. Owner's call — the design supports all three (staff just needs the plaintext once at generation).
4. **Legacy empty rows.** If the current auto-create has already produced empty LINE-only rows in production, we need a **one-time cleanup/merge** at deploy (out of scope here). How many such rows exist? (Query: LINE-linked members with zero `member_packages`.)
5. **Regenerate visibility.** When staff regenerate, the old code dies immediately. Confirm staff understand "the last code shown is the only one that works" (UI will state this).

---

## 9. Convention checklist (matches existing migrations)

- `declare(strict_types=1)` in model/controller code (per existing files).
- New table follows the `foreignId()->constrained()->restrictOnDelete()` (member) / `->nullOnDelete()` (staff user) FK-on-delete conventions used by `bookings`/`entitlements`.
- CHECK constraint added via raw `DB::statement`, **guarded** by `DB::getDriverName() !== 'sqlite'`, dropped in `down()` — same shape as `chk_ent_qty` / `chk_bookings_range`.
- Enum-style status is modeled as lifecycle timestamps (`consumed_at`) rather than a status enum, matching the bookings "creation IS confirmation" simplification (no redundant status column).
- I-numbering continued from I19 → **I20, I21, I22**.
- `line_user_id` UNIQUE (**I12**) is reused untouched as the final DB backstop; the new table does not interact with it.
- Migration filename would sort after Phase-7: e.g. `2026_07_02_100000_create_member_link_codes_table.php`.
```
