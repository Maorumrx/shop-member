#!/usr/bin/env bash
#
# Phase 0 — Scaffold Laravel 13 (Vue + Inertia) into this repo. Idempotent / re-runnable.
# รันใน container workspace:  docker compose exec workspace bash docker/scaffold.sh
#
# ปลอดภัยกับ repo ที่ไม่ว่าง (docker/, docs/, .gitignore) + เป็น git repo อยู่แล้ว:
# scaffold ลง temp → ย้ายขึ้นแบบเตือนเมื่อชน → ตั้ง .env ชี้ MariaDB → รอ db ready → migrate.
#
set -euo pipefail

ROOT="$(pwd)"
TMP="_laravel_tmp"                          # โฟลเดอร์ชั่วคราว — ย้ายไฟล์ออกแล้วลบทิ้ง (ไม่ใช่ชื่อ project)
PROJECT_NAME="${APP_NAME:-shop-member}"     # ชื่อแอป = APP_NAME ใน .env (เปลี่ยนเป็น sm-project ได้)
DB_NAME="${DB_DATABASE:-ms}"               # ให้ตรงกับ docker-compose ${DB_DATABASE:-ms}
DB_PASS="${DB_ROOT_PASSWORD:-rootsecret}"  # ให้ตรงกับ ${DB_ROOT_PASSWORD:-rootsecret}

echo "==> [0/7] pre-flight: PHP / Composer"
php -v 2>/dev/null | head -1 || true        # || true: กัน SIGPIPE (141) ทำ pipefail abort
PHP_OK="$(php -r 'echo version_compare(PHP_VERSION, "8.3.0", ">=") ? "yes" : "no";')"
[ "$PHP_OK" = "yes" ] || { echo "!! PHP < 8.3 — Laravel 13 ต้องการ 8.3+ (แก้ docker/workspace/Dockerfile)"; exit 1; }
command -v composer >/dev/null || { echo "!! ไม่พบ composer"; exit 1; }

# set_env: แทนที่ key ใน .env (รวมที่ comment ไว้) ไม่มีก็ append; escape RHS ของ sed
# สมมติ .env ไม่มี key ซ้ำ (Laravel .env มาตรฐานไม่ซ้ำ)
set_env () { # key value
  local key="$1" val="$2" esc
  esc=$(printf '%s' "$val" | sed -e 's/[&|\\]/\\&/g')
  if grep -qE "^#?[[:space:]]*${key}=" .env; then
    sed -i -E "s|^#?[[:space:]]*${key}=.*|${key}=${esc}|" .env
  else
    printf '%s=%s\n' "$key" "$val" >> .env
  fi
}

# ---- scaffold (เฉพาะถ้ายังไม่มี artisan) ----
if [ ! -f "$ROOT/artisan" ]; then
  echo "==> [1/7] เตรียม Laravel installer"
  command -v laravel >/dev/null || composer global require laravel/installer
  export PATH="$PATH:$(composer global config bin-dir --absolute -q)"

  echo "==> [2/7] scaffold Vue starter kit ลง $TMP (sqlite ตอน scaffold ให้ installer migrate ผ่านแบบ offline)"
  rm -rf "$TMP"
  # installer 5.28 ไม่มี --no-node; ไม่ส่ง --npm/--pnpm/.. → ข้าม node ตอนนี้ (build จริงในสเต็ป 7)
  laravel new "$TMP" --vue --database=sqlite --pest --no-interaction

  echo "==> [3/7] ย้ายไฟล์ขึ้น root (เตือนถ้าชนของเดิม — mv -n ไม่ทับ)"
  (
    shopt -s dotglob nullglob
    for f in "$TMP"/*; do
      base="$(basename "$f")"
      if [ -e "$ROOT/$base" ]; then
        echo "   !! ชนของเดิม: เก็บตัวเดิมไว้ ตัวที่ generate ไม่ถูกย้าย -> $base" >&2
      fi
      mv -n "$f" "$ROOT"/
    done
  )
  rm -rf "$TMP"
  rm -f "$ROOT/database/database.sqlite"
  [ -f "$ROOT/artisan" ] || { echo "!! scaffold ล้มเหลว: ไม่พบ artisan หลังย้าย"; exit 1; }
else
  echo "==> [1-3/7] พบ artisan แล้ว — ข้าม scaffold (resume/fresh-clone mode)"
  # fresh clone: โค้ด commit แล้วแต่ deps/.env ยังไม่มา → เติมให้ครบ
  [ -d vendor ] || { echo "    composer install (ไม่พบ vendor/)"; composer install; }
  [ -f .env ]   || { echo "    สร้าง .env จาก .env.example"; cp .env.example .env; }
fi

echo "==> [4/7] ตั้งค่า .env (ชื่อแอป + ชี้ MariaDB service 'db') (idempotent)"
set_env APP_NAME      "$PROJECT_NAME"
set_env DB_CONNECTION mariadb
set_env DB_HOST       db
set_env DB_PORT       3306
set_env DB_DATABASE   "$DB_NAME"
set_env DB_USERNAME   root
set_env DB_PASSWORD   "$DB_PASS"

echo "==> [5/7] app key (เฉพาะถ้ายังไม่มี — กัน regenerate ทับของเดิม)"
grep -qE '^APP_KEY=base64:' .env || php artisan key:generate

echo "==> [6/7] รอ MariaDB (db) พร้อมรับ connection แล้ว migrate"
for i in $(seq 1 30); do
  if php -r 'new PDO("mysql:host=db;port=3306", "root", $argv[1]);' "$DB_PASS" 2>/dev/null; then
    echo "   db พร้อม"; break
  fi
  if [ "$i" -eq 30 ]; then
    echo "!! db ไม่พร้อมใน ~60s — เช็คว่า sm-db healthy (docker compose ps)"; exit 1
  fi
  sleep 2
done
php artisan migrate --force

echo "==> [7/7] ติดตั้ง + build frontend (Vue/Inertia ผ่าน Vite) — resume ได้ถ้า step นี้ fail"
npm install
npm run build

echo ""
echo "✅ เสร็จ — Laravel 13 + Vue/Inertia พร้อม"
echo "   แอป:  http://localhost:8080   ·   pma:  http://localhost:8081"
echo "   ถัดไป: Phase 1 migration (entitlement) หลัง review ERD ใน docs/architecture.md"
