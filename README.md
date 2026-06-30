# shop-member — ระบบสมาชิกร้านนวด (Massage Shop Member System)

ระบบจัดการ **สมาชิก + แพ็คเกจ/ตัดสิทธิ์ (entitlement)** สำหรับร้านนวด แยกชัด 2 ฝั่ง: ฝั่งลูกค้า (member — login ด้วย LINE) และฝั่งหลังบ้าน (admin — พนักงาน)

> **หัวใจของระบบ = การตัดสิทธิ์ที่แม่นยำและตรวจสอบย้อนหลังได้** ทุกการเคลื่อนไหวสิทธิ์ถูกบันทึกเป็น **append-only ledger** (ledger คือความจริง, ยอดคงเหลือเป็นแค่ cache ที่สร้างใหม่จาก ledger ได้เสมอ)

---

## ✨ Features

- **แพ็คเกจ + ตัดสิทธิ์** — ตัดแบบ FIFO ตามวันหมดอายุ (ใกล้หมดก่อน) ใน DB transaction + `lockForUpdate` กันตัดชนกัน
- **Append-only ledger** — ทุก purchase / redeem / expire / refund / adjust เป็นแถวที่แก้ไม่ได้ (ตรวจสอบย้อนหลัง 100%)
- **แยก catalog ออกจากของที่ลูกค้าถือ** — แก้ราคา/นิยามแพ็คทีหลังไม่กระทบล็อตที่ขายไปแล้ว (snapshot)
- **Add-on coupling** — add-on ผูกกับบริการ (ตัดคู่กัน) หรือใช้อิสระ ตั้งค่าได้ระดับข้อมูล (`redeem_group`)
- **Multi-branch** — รองรับหลายสาขา + กฎตัดข้ามสาขา
- **2 ฝั่ง 2 guard** — member (LINE) / admin (email+password + role owner|staff)

---

## 🧱 Tech Stack

| ส่วน | เทคโนโลยี |
|---|---|
| Backend | **Laravel 13** (PHP 8.3+) |
| Frontend | **Inertia 3 + Vue 3 + TypeScript + Tailwind CSS 4** (ทั้ง member & admin) |
| Database | **MariaDB 11.4** |
| Auth | Laravel Fortify (admin, incl. passkey + 2FA) · LINE (member) |
| Dev env | **Docker + Docker Compose** |
| Testing | **Pest** |

---

## 📦 Requirements

- **Docker** + **Docker Compose** (ไม่ต้องลง PHP/Composer/Node บนเครื่อง — ทุกอย่างอยู่ใน container)

---

## 🚀 Installation

```bash
# 1) clone
git clone https://github.com/Maorumrx/shop-member.git
cd shop-member

# 2) build + start containers (sm-workspace, sm-app, sm-nginx, sm-db, sm-pma)
docker compose up -d --build

# 3) ติดตั้ง + ตั้งค่าครบในคำสั่งเดียว
#    (composer install → .env → APP_KEY → ชี้ DB → migrate → npm build)
docker compose exec workspace bash docker/scaffold.sh
```

เปิดใช้งาน:

| บริการ | URL |
|---|---|
| แอป (Laravel + Vue) | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |

> **ปรับ port / DB ได้** ผ่านตัวแปรใน `.env` ก่อนรัน: `NGINX_PORT` (8080), `PMA_PORT` (8081), `DB_PORT_EXPOSE` (3306), `DB_DATABASE` (ms), `DB_ROOT_PASSWORD` (rootsecret)

<details>
<summary>หมายเหตุ: หลังเครือข่ายที่ทำ TLS inspection (เช่น proxy องค์กร)</summary>

`docker/workspace/Dockerfile` จะ **ดึง root CA ของ proxy มาติดตั้งให้อัตโนมัติตอน build** (self-bootstrap) เพื่อให้ composer/npm/git ทำงานผ่าน HTTPS ได้ ไม่ต้องตั้งค่าเพิ่ม ถ้า proxy ไม่ส่ง root CA มาในchain ให้วาง cert ที่ `docker/workspace/certs/*.crt` เอง
</details>

---

## 🛠️ Development

```bash
# เข้า shell ของ container
docker compose exec workspace bash

# คำสั่งที่ใช้บ่อย (รันใน container หรือ prefix ด้วย docker compose exec workspace)
php artisan migrate            # รัน migration
php artisan migrate:status     # ดูสถานะ
php artisan test               # รัน Pest tests
php artisan tinker             # REPL
npm run dev                    # Vite dev server (HMR)
npm run build                  # build asset สำหรับ production
```

---

## 🧪 Testing

```bash
docker compose exec workspace php artisan test
```

Tests รันบน **sqlite `:memory:`** (เร็ว) — เทสที่ต้องใช้ฟีเจอร์เฉพาะ MariaDB (CHECK constraint, FK RESTRICT) จะ **skip อัตโนมัติ** บน sqlite

---

## 📂 Project Structure

```
app/
├── Models/      Branch · Member · Package · PackageLine · MemberPackage · Entitlement · EntitlementLedger · User
└── Enums/       UserRole · ItemType · EntitlementStatus · LedgerReason
database/
└── migrations/  schema ระบบ entitlement (branches → ... → entitlement_ledger)
resources/js/    Vue + Inertia (pages/, layouts/, components/)
docker/          Dockerfile (workspace) · nginx · php · scaffold.sh
docs/            architecture.md · design-system.md · tech-stack.md
tests/Feature/   Pest tests
```

---

## 🗺️ Roadmap (build ทีละเฟส)

- [x] **Phase 0** — Foundation (scaffold Laravel 13 + Vue/Inertia + Docker)
- [x] **Phase 1** — Data model (entitlement schema + ledger, migrations/models/enums/tests)
- [ ] **Phase 2** — Auth (LINE member guard / admin email+password+role)
- [ ] **Phase 3** — Package Catalog (admin)
- [ ] **Phase 4** — Purchase + Entitlements
- [ ] **Phase 5** — Redemption (ตัดสิทธิ์ FIFO)
- [ ] **Phase 6** — Member-facing UI
- [ ] **Phase 7** — Booking (optional)

---

## 📚 Documentation

- [`docs/architecture.md`](docs/architecture.md) — data model / ERD + index + design rationale
- [`docs/design-system.md`](docs/design-system.md) — design tokens (warm-soft, WCAG-AA verified)
- [`docs/tech-stack.md`](docs/tech-stack.md) — สรุปสแตก + การตัดสินใจ
