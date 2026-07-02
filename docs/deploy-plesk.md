# Deploy to Plesk (no Docker) — shop-member

Target: `maorulabs-demo.com` (Plesk, PHP 8.4, Composer, Node.js, Git, Scheduled Tasks, MariaDB,
Let's Encrypt). Doc root already = `httpdocs/public`. This is ALSO the LIFF live-test environment
(real public HTTPS → no tunnel needed).

Run artisan/composer/npm via **Plesk → the domain → Dev Tools** (PHP Composer / Node.js / Git) or SSH.
All paths are inside `httpdocs/` (the Laravel root; `public/` is the web root).

---

## 1. Database (Plesk → Databases)
- Create a MariaDB database + user (note name/user/pass) → used in `.env` below.

## 2. Get the code (Plesk → Git, OR upload)
- **Git**: add repo `https://github.com/Maorumrx/shop-member`, deploy path = `httpdocs`.
  Enable "deploy on push" if you want auto-deploy.
- `.env`, `vendor/`, `node_modules/`, `public/build/` are gitignored → built/created below, not pulled.

## 3. `.env` (Plesk → Files → create `httpdocs/.env`)
Minimum for production:
```
APP_NAME="ระบบสมาชิกร้านนวด"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://maorulabs-demo.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<plesk_db_name>
DB_USERNAME=<plesk_db_user>
DB_PASSWORD=<plesk_db_pass>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
# SESSION_DOMAIN=  <-- leave EMPTY
CACHE_STORE=database
QUEUE_CONNECTION=sync

# LINE (from the LINE Developers console)
LINE_LOGIN_CHANNEL_ID=<...>
LINE_LOGIN_CHANNEL_SECRET=<...>
LINE_LIFF_ID=2010556567-MMXvxEIe

# Mail (Fortify verification/reset) — set to the Plesk mail or an SMTP provider
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@maorulabs-demo.com"
```
(SESSION/CACHE=database → the sessions/cache tables are created by `migrate` in step 5. `file` also works if you prefer.)

## 4. Composer (Plesk → PHP Composer, in httpdocs)
```
composer install --no-dev --optimize-autoloader
```

## 5. Artisan setup (SSH or Plesk scheduled/one-off task, in httpdocs)
```
php artisan key:generate          # writes APP_KEY into .env
php artisan migrate --force       # --force required in production
php artisan storage:link          # logo uploads -> public/storage
php artisan db:seed --force                       # owner@shop.test / staff@shop.test
php artisan db:seed --class=DemoSeeder --force    # demo data (optional, for the demo/LIFF test)
```
> First admin login: `owner@shop.test` / `password` — CHANGE IT immediately in production.

## 6. Front-end assets (Plesk → Node.js, in httpdocs)
```
npm ci
npm run build         # produces public/build (Wayfinder also regenerates via php)
```
(If Plesk's Node.js UI is awkward, build LOCALLY and upload `public/build/` + the `resources/js/actions|routes` — they're what the app serves.)

## 7. Optimize (production caches)
```
php artisan config:cache
php artisan app:assert-production-safe && php artisan route:cache && php artisan view:cache
```
⚠️ After ANY `.env` change, re-run `php artisan config:cache` (cached config ignores raw .env).

### 7a. DEPLOY GUARDRAIL — `app:assert-production-safe` (MANDATORY, do not skip)
`php artisan app:assert-production-safe` runs **right after `config:cache`** so it checks the
*cached, resolved* config the app will actually serve. It exits **non-zero** unless BOTH
`APP_ENV=production` **and** `APP_DEBUG=false`, and prints a red reason why.

The `&&` chain above means a misconfigured deploy **ABORTS here**: `route:cache` / `view:cache`
never run, so the deploy fails LOUDLY instead of silently going live. If it fails:
fix `.env`, re-run `php artisan config:cache`, then re-run the line.

> Why: a confirmed incident had production (`bansuan-thaimassage.com`) running `APP_ENV=local` +
> `APP_DEBUG=true`, which exposed a passwordless dev backdoor (`/member/dev-login`) and leaked
> secrets via the Ignition error page. This guardrail makes that class of misdeploy impossible to
> ship silently. (Composer alias: `composer deploy-check`.)

## 8. Scheduled task (Plesk → Scheduled Tasks)
Add a task running **every minute**:
```
* * * * *   php /var/www/vhosts/maorulabs-demo.com/httpdocs/artisan schedule:run
```
(Use the PHP 8.4 binary path Plesk shows; the scheduler fires `bookings:sweep` hourly = flips no-show bookings.)

## 9. SSL (Plesk → SSL/TLS Certificates)
- Issue a **Let's Encrypt** cert for `maorulabs-demo.com` (free, auto-renew). REQUIRED — LINE LIFF needs HTTPS.
- The dashboard's "Security can be improved" clears once this is set.

## 10. Enable booking + LINE
- Log into `/login` as owner → **สาขา** → "ตั้งค่าการจอง" → enable + set hours/capacity per branch
  (DemoSeeder already enabled its 2 branches).
- **LINE Developers console** → LIFF app `2010556567-MMXvxEIe` → **Endpoint URL** = `https://maorulabs-demo.com/member`.

## 11. LIFF live test (on real device — no tunnel!)
Open `https://liff.line.me/2010556567-MMXvxEIe` in the LINE app, then the checklist in
`docs/liff-live-test.md` §5 (needs_link → dashboard → booking → admin check-in → balance updates).

---

## 12. Post-deploy verification (run AFTER every deploy)
Confirm the guardrail's intent actually holds on the live site:
- [ ] `php artisan config:show app.env app.debug` shows **`app.env => production`** and **`app.debug => false`**.
- [ ] `curl -s -o /dev/null -w "%{http_code}\n" https://bansuan-thaimassage.com/member/dev-login` returns **`404`** (the passwordless dev backdoor is NOT reachable in production).
- [ ] `php artisan app:assert-production-safe` exits **0** (`echo $?` → `0`).
- [ ] Trigger any error page and confirm it shows the generic Laravel error, **not** the Ignition debug page (no stack traces / no secrets).

---

## Gotchas
- **HTTPS behind Plesk's nginx proxy**: if login redirects to `http://` or cookies don't stick, Laravel isn't
  seeing the proxy's `X-Forwarded-Proto`. Fix: in `bootstrap/app.php`'s `withMiddleware(...)` add
  `->trustProxies(at: '127.0.0.1')` — scope it to the ACTUAL upstream (Plesk's nginx sits on loopback;
  use the real proxy IP/CIDR Plesk shows if not `127.0.0.1`). (Tell me and I'll add it.)
  - ⚠️ **Do NOT use `at: '*'`.** Trusting every proxy makes Symfony trust attacker-supplied
    `X-Forwarded-Host` / `X-Forwarded-For` from anyone, which widens `Request::getHost()` — a
    host-spoofing / cache-poisoning risk that can also DEFEAT host-based guards (e.g. the
    `/member/dev-login` backdoor guard). Corollary: the app must **never** rely on `getHost()` for a
    security decision; gate the dev backdoor on `config('app.env')`/`app()->isLocal()`, not the Host header.
- **`config:cache` + .env**: cached config ignores later .env edits → re-run `config:cache` after changes.
- **Cron PHP binary**: use the exact PHP 8.4 path Plesk provides (not a system `php` that might be older).
- **File permissions**: `storage/` and `bootstrap/cache/` must be writable by the `maorulabs` system user.
- **No Docker in prod** — dev's `docker-compose.yml` / `docker/` are dev-only; ignore them on the server.
