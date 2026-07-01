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
php artisan route:cache
php artisan view:cache
```
⚠️ After ANY `.env` change, re-run `php artisan config:cache` (cached config ignores raw .env).

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

## Gotchas
- **HTTPS behind Plesk's nginx proxy**: if login redirects to `http://` or cookies don't stick, Laravel isn't
  seeing the proxy's `X-Forwarded-Proto`. Fix: add `->trustProxies(at: '*')` in `bootstrap/app.php`'s
  `withMiddleware(...)`. (Tell me and I'll add it.)
- **`config:cache` + .env**: cached config ignores later .env edits → re-run `config:cache` after changes.
- **Cron PHP binary**: use the exact PHP 8.4 path Plesk provides (not a system `php` that might be older).
- **File permissions**: `storage/` and `bootstrap/cache/` must be writable by the `maorulabs` system user.
- **No Docker in prod** — dev's `docker-compose.yml` / `docker/` are dev-only; ignore them on the server.
