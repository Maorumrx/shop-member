# shop-member — Tech Stack

> เอกสารนี้สรุป "เรากำลังใช้อะไรเป็น backend/frontend" สำหรับโปรเจกต์ `shop-member`
> อ้างอิงจาก Laravel 13 official docs (อ่านเมื่อ 2026-06-29) ไม่ใช่ความจำของ AI
> Sources: [Installation](https://laravel.com/docs/13.x/installation) · [Starter Kits](https://laravel.com/docs/13.x/starter-kits) · [Frontend](https://laravel.com/docs/13.x/frontend) · [Laravel 13 Released (Laravel News)](https://laravel-news.com/laravel-13-released)

---

## 1. Backend — Laravel 13

| หัวข้อ | ค่า |
|---|---|
| Framework | **Laravel 13.x** (released 17 มี.ค. 2026) |
| PHP ขั้นต่ำ | **PHP 8.3** — รองรับ 8.3 / 8.4 / 8.5 (ทิ้ง 8.2 แล้ว) |
| PHP ที่ installer แนะนำ | **8.5** (ผ่าน `php.new`) |
| Asset bundler | **Vite** (มาในตัวทุก starter kit) |
| Node | Node + NPM **หรือ** Bun |
| Default database | **SQLite** (auto-create `database/database.sqlite` + migrate ให้เลย) |
| Database ของเรา | **MariaDB 11.4** ผ่าน Docker (ดู `docker-compose.yml`) |

> ⚠️ **กระทบ Dockerfile ที่จะ build:** image ของ service `workspace`/`app` ต้องเป็น **PHP ≥ 8.3**
> ถ้าตั้งเป็น `php:8.2-*` จะลง Laravel 13 ไม่ได้ — แนะนำ pin **8.3 หรือ 8.4**

### วิธีติดตั้ง (Laravel 13)
```bash
# มี PHP + Composer แล้ว
composer global require laravel/installer

# installer จะถาม: starter kit อะไร / testing framework อะไร / database อะไร
laravel new shop-member

cd shop-member
npm install && npm run build
composer run dev      # รัน dev server + queue worker + Vite พร้อมกันในคำสั่งเดียว
# เปิด http://localhost:8000
```

### Laravel + AI (น่าสนใจสำหรับเรา เพราะใช้ Claude Code)
- **Laravel Boost** — ติดตั้งด้วย `composer require laravel/boost --dev` แล้ว `php artisan boost:install`
  - ให้ AI agent เข้าถึง doc ของ Laravel ตาม **เวอร์ชันที่เราใช้จริง** (17,000+ ชิ้น), query database, รัน Tinker, gen test ฯลฯ
  - รองรับ Laravel 10–13, PHP 8.1+
- เพิ่ม guideline เองได้ที่ `.ai/guidelines/*.md` → จะถูกรวมตอน `boost:install`

---

## 2. Frontend — มี 2 ทางหลัก (ต้องเลือก)

Laravel วางกรอบไว้ชัดว่ามี 2 ทาง เลือกตาม "อยากเขียน frontend ด้วย PHP หรือ JavaScript":

### ทาง A — PHP (Blade + Livewire)  ← สแตกเดิมของเรา
- เขียน UI ด้วย PHP ล้วน ผ่าน Livewire component + Blade
- เหมาะกับทีมที่ถนัด Blade อยู่แล้ว อยากได้ SPA-feel แบบไม่ต้องเขียน JS เยอะ
- ใช้ Alpine.js "โรย" JS เฉพาะจุด
- **Filament** (admin panel ที่เราใช้) อยู่ฝั่งนี้ — เป็น Livewire/Blade

### ทาง B — JavaScript (Inertia + React / Vue / Svelte)  ← ที่กำลังพิจารณา
- **Inertia** = สะพานเชื่อม Laravel backend กับ frontend JS framework
- ยังเขียน **route + controller ปกติของ Laravel** แต่ return เป็น Inertia page แทน Blade:
  ```php
  // app/Http/Controllers/UserController.php
  public function show(string $id): Response
  {
      return Inertia::render('users/show', [
          'user' => User::findOrFail($id),
      ]);
  }
  ```
  ```jsx
  // resources/js/pages/users/show.tsx  (ฝั่ง React/Vue/Svelte)
  export default function Show({ user }) {
      return <h1>Hello {user.name}</h1>;
  }
  ```
- **ข้อดีที่ Laravel เคลม:** ได้พลัง React/Vue เต็ม ๆ + productivity ของ Laravel, **repo เดียว ไม่ต้องทำ API แยก**, ไม่ต้องจัดการ client-side routing / data hydration / auth เอง
- รองรับ **SSR** (`npm run build:ssr`, `composer dev:ssr`)

---

## 3. Starter Kits (Laravel 13) — มี 4 ตัว

ทุกตัวใช้ **Laravel Fortify** จัดการ auth (login, register, password reset, email verify, **2FA** มาให้พร้อม) และมี **Tailwind** + **Vite** ในตัว เลือกตอน `laravel new`

| Starter Kit | Frontend stack | Component library | หมายเหตุ |
|---|---|---|---|
| **React** | Inertia 3 + **React 19** + TypeScript + Tailwind 4 | **shadcn/ui** | type-safe routing ด้วย **Wayfinder** |
| **Vue** | Inertia 3 + **Vue 3** (Composition API) + TypeScript + Tailwind | **shadcn-vue** | type-safe routing ด้วย Wayfinder |
| **Svelte** | Inertia 3 + **Svelte 5** + TypeScript + Tailwind | shadcn-svelte | |
| **Livewire** | **Livewire 4** + Tailwind | **Flux UI** | สแตกเดิมเราตระกูลนี้ (แต่เราใช้ v3 — ตัวนี้ v4 แล้ว) |

ของเสริมที่เลือกได้ตอนสร้าง:
- **WorkOS AuthKit** variant — social login (Google/Microsoft/GitHub/Apple), passkey, Magic Auth, SSO (ฟรีถึง 1M MAU)
- **Teams** — ผู้ใช้อยู่ได้หลายทีม, มีหน้า invite/switch team ให้
- community starter kit: `laravel new my-app --using=vendor/kit`

> หมายเหตุ: **Livewire 4** เป็นของใหม่ — โปรเจกต์อื่นของเรายังเป็น Livewire **v3** ถ้าจะใช้ Livewire starter kit ของ 13 ต้องเผื่อ learning ส่วนต่างของ v4 ด้วย

---

## 4. แมพกับ Docker setup ปัจจุบันของเรา

`docker-compose.yml` ตอนนี้มี: `workspace` (PHP+Composer+Node), `app` (php-fpm), `nginx`, `db` (MariaDB 11.4), `phpmyadmin`

สิ่งที่ต้องเตรียมให้ build ผ่าน (ตอนนี้ยังไม่มี → build fail):
- [ ] `docker/workspace/Dockerfile` — base **PHP ≥ 8.3** (fpm) + Composer + Node/NPM
- [ ] `docker/php/php.ini`
- [ ] `docker/nginx/default.conf` — root ชี้ `public/`, fastcgi → `app:9000`
- [ ] `.env.example` — `DB_*`, `NGINX_PORT`, `DB_PORT_EXPOSE`, `PMA_PORT` ฯลฯ
- [ ] (ถ้าเลือกทาง B) เผื่อ service/cmd สำหรับ **Inertia SSR** ตอน production

> Container ทั้งหมดจะ rename ให้ derive จากชื่อโปรเจกต์ `shop-member` → prefix `sm-` (เช่น `sm-workspace`, `sm-app`, `sm-db`)

---

## 5. Decision — Hybrid (Filament admin + Inertia/Vue front)

**บริบทที่ใช้ตัดสิน (คุยกัน 2026-06-29):**
- shop-member เป็นแอป **หลังบ้าน/CRUD หนัก**
- เจ้าของอยาก **เรียน Vue** (เลือก **Vue** มากกว่า React — ใกล้ Blade/Alpine, เรียนนุ่มกว่า)

**สแตกที่เลือก — รันใน Laravel 13 แอปเดียว:**

| ส่วน | สแตก | เหตุผล |
|---|---|---|
| หลังบ้าน admin (`/admin`) | **Filament** (Livewire) | CRUD หนัก → Filament gen ให้ฟรี, ตรงสกิลเดิม |
| หน้าบ้าน (ลูกค้า/สมาชิก) | **Inertia 3 + Vue 3 + TypeScript + Tailwind + shadcn-vue** | ได้เรียน Vue ตรงจุดที่ UI ควร interactive |

> Filament (route `/admin`, Livewire) กับ Inertia (route หลัก) อยู่ร่วมแอปเดียวได้ ไม่ชนกัน

**แผนเริ่ม:**
1. `laravel new shop-member` → เลือก **Vue starter kit** + database = MySQL/MariaDB
2. `composer require filament/filament` → `php artisan filament:install --panels` เพิ่ม panel หลังบ้าน
3. Docker: pin **PHP 8.3+**, มี Node สำหรับ Vite/Vue

**สถานะ:** _proposed — รอเจ้าของยืนยันก่อนลงมือ scaffold_
