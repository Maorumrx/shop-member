# LIFF Live Test — Runbook (ทดสอบฝั่งลูกค้าบน LINE จริง)

ด่านปิดโปรเจกต์: ทดสอบ round-trip ฝั่ง member (LINE LIFF) บนมือถือจริง — login → dashboard →
จองคิว → เชื่อมบัญชี (needs_link) โดยไม่ต้องพึ่ง dev-login

> ch7 บล็อก cloudflared (port 7844) → ใช้ **VS Code Dev Tunnel** (ออกทาง 443, ได้ HTTPS ฟรี) แทน

---

## 0. เตรียมของก่อน (ในคอนเทนเนอร์)
```bash
docker compose exec workspace php artisan migrate          # ถ้ายังไม่รัน
docker compose exec workspace php artisan db:seed --class=DemoSeeder   # สาขา+แพ็ค+สมาชิก+เปิดจอง 2 สาขา
docker compose exec workspace npm run build                # assets ฝั่ง member ต้อง build แล้ว
```
- `.env`: มี `LINE_LOGIN_CHANNEL_ID`, `LINE_LOGIN_CHANNEL_SECRET`, `LINE_LIFF_ID=2010556567-MMXvxEIe` ครบ
- `SESSION_DOMAIN` = **ปล่อยว่าง (null)** — ไม่งั้น cookie จะไม่ทำงานบนโดเมน tunnel

## 1. ใช้ nginx ที่มีอยู่แล้ว (อย่ารัน `php artisan serve`)
แอปเสิร์ฟผ่าน **nginx (sm-nginx) ที่ host `localhost:8080`** อยู่แล้ว (ตัวเดียวกับที่ใช้ dev-login).
`php artisan serve` รันในคอนเทนเนอร์ workspace ที่ **ไม่ได้เปิด port** เลยเข้าไม่ถึง — ข้ามไปเลย.
> shell `node /workspace` ไม่มี php (php อยู่ในคอนเทนเนอร์เท่านั้น) — นั่นคือเหตุที่ `php: command not found`

## 2. เอา `localhost:8080` ออกเน็ตแบบ HTTPS (เลือก 1 วิธี)

**วิธี A — ngrok (แนะนำ, ไม่ยุ่งกับ VS Code)** — รันบน terminal ของ **Mac** (ไม่ใช่ในคอนเทนเนอร์):
```bash
ngrok http 8080
```
→ ได้ `https://xxxx.ngrok-free.app` (ถ้ายังไม่มี ngrok: `brew install ngrok` + สมัคร authtoken ฟรี)

**วิธี B — VS Code Port Forward** (ถ้า VS Code รันบน Mac host ไม่ใช่ attach เข้า container):
- VS Code → แท็บ **PORTS** → Forward Port `8080` → คลิกขวา → **Public**
- คัดลอก URL `https://xxxx.devtunnels.ms`
- ⚠️ ถ้า VS Code attach อยู่ใน container (sm-workspace) จะ forward 8080 ไม่ถึง nginx → ใช้วิธี A แทน

**วิธี C — ถ้าทั้ง ngrok และ dev tunnel โดน ch7 บล็อก:** ทดสอบผ่าน Wi-Fi มือถือ/เน็ตบ้าน หรือ hotspot แทนเน็ต ch7

## 3. ผูก URL เข้ากับแอป + LINE
- `.env` → ตั้ง `APP_URL=https://xxxxxxxx-8000.asse.devtunnels.ms` แล้ว
  ```bash
  docker compose exec workspace php artisan config:clear
  ```
- **LINE Developers console** → LIFF app (ID `2010556567-MMXvxEIe`) →
  **Endpoint URL** = `https://xxxxxxxx-8000.asse.devtunnels.ms/member`
  (Scope: `profile`, `openid`; Size แล้วแต่ — Full/Tall ก็ได้)

## 4. เปิดบนมือถือ (ใน LINE)
- เปิด `https://liff.line.me/2010556567-MMXvxEIe` ในแอป LINE (หรือสแกน QR ของ LIFF)
- จะเด้ง in-app browser → `liff.init` → `liff.login` → POST `/member/line/login`

---

## 5. เช็กลิสต์ทดสอบ (ครบ flow)

**A. ลูกค้าใหม่ (LINE ที่ยังไม่เคยผูก)**
- [ ] เปิด LIFF → เจอหน้า **"needs_link"** (มีรหัสจากร้าน / ฉันเป็นลูกค้าใหม่)
- [ ] **ทดสอบเชื่อมบัญชี:** ที่ admin (desktop) เปิดสมาชิก "คุณสมหญิง ใจดี" → กด "สร้างรหัสเชื่อม LINE" → ได้เลข 6 หลัก → เอาไปกรอกในจอ needs_link → เข้า dashboard **เห็นแพ็ค/ยอดของคุณสมหญิง** (จาก DemoSeeder)
- [ ] หรือกด "ฉันเป็นลูกค้าใหม่" → ได้บัญชีใหม่ว่าง ๆ

**B. Dashboard**
- [ ] แสดงชื่อ/รูป LINE, สิทธิ์คงเหลือแยกประเภท, ล็อต+วันหมดอายุ, ประวัติการใช้
- [ ] ปุ่ม "จองคิว" กดแล้วไปหน้าจองได้

**C. จองคิว (member)**
- [ ] เลือกสาขา (สยาม/ทองหล่อ — DemoSeeder เปิดจองไว้แล้ว) → เลือกวัน → เห็นช่องเวลา "เหลือ N คิว" → เลือกช่อง → เลือกบริการ → ยืนยัน
- [ ] โผล่ใน "การจองของฉัน" + ยกเลิกได้

**D. เช็คอิน + ตัดสิทธิ์ (admin)**
- [ ] admin หน้า "การจอง" → เลือกวันที่จอง → เห็น booking → กด **เช็คอิน**
- [ ] กลับมา dev-login/LINE เป็นคนเดิม → dashboard ยอดลด + ประวัติเพิ่ม "ใช้บริการ"

---

## Gotchas
- **URL dev tunnel เปลี่ยนทุกครั้งที่เปิดใหม่** → ต้องอัปเดต `APP_URL` + LIFF Endpoint + `config:clear` ทุกรอบ (หรือทำ tunnel แบบ persistent)
- **HTTPS บังคับ** สำหรับ LIFF — dev tunnel ให้มาแล้ว
- ถ้า `liff.init` fail → เช็ก Endpoint URL ตรงเป๊ะ + channel published + LIFF ID ถูก
- ถ้า login แล้วเด้งกลับ/ค้าง → เช็ก `SESSION_DOMAIN` ว่าง + `config:clear` แล้ว + APP_URL = โดเมน tunnel เป๊ะ
- ถ้าเห็นหน้าเปล่า/asset ไม่ขึ้น → ยังไม่ได้ `npm run build`
- ถ้า login แล้ว redirect ไป `http://` หรือ cookie ไม่ติด (เพราะแอปอยู่หลัง tunnel→nginx → Laravel มองเป็น http) → บอกผม เดี๋ยวเพิ่ม `->trustProxies(at: '*')` ใน bootstrap/app.php ให้ Laravel เชื่อ `X-Forwarded-Proto: https`
