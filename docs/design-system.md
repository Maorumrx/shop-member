# Design System — "Warm White Soft / Beauty Aesthetic"

> Token system for the massage-shop member app (Tailwind CSS 4 `@theme`).
> Member = soft/rounded/motion · Admin = denser/scannable. **One palette, two dialects.**
> ทุกคู่สีที่มีตัวอักษร **ผ่าน WCAG AA** — ตัวเลข ratio ด้านล่าง verify ด้วยสูตร WCAG จริง (script) ไม่ใช่กะ
> ฐานที่มา: spec §9 + audit (แก้ `ink-muted` ที่เดิมตก AA + เติม state/focus/border tokens)

## `@theme` block (เอาไปวางใน `resources/css/app.css` ตอน Phase 0)

```css
@theme {
  /* ---- Base surfaces & ink (เดิม ผ่านหมด) ---- */
  --color-bg:            #FAF6F0; /* warm white */
  --color-surface:       #FFFFFF; /* การ์ด/แผ่น */
  --color-border:        #EDE4D9; /* เส้นจางนุ่ม (Member) */
  --color-border-strong: #9C8772; /* ใหม่: เส้น/divider ตาราง Admin — 3.19:1 vs bg */

  --color-ink:           #4A4039; /* ตัวอักษรหลัก  10.08 / 9.36 */
  --color-ink-muted:     #6E6258; /* แก้แล้ว: 5.91 / 5.49 (เดิม #8A7E73 ตก AA) */

  /* ---- Brand (เดิม) ---- */
  --color-primary:        #C2A18C; /* ใช้กับพื้น/ตัวใหญ่เท่านั้น ห้ามตัวขาว */
  --color-primary-strong: #8B6A52; /* ปุ่มตัวขาว (CTA)  4.91:1 */
  --color-accent:         #E8D5C4; /* soft button: พื้น + text-ink  7.08:1 */
  --color-sage:           #9FB0A3; /* ตกแต่ง/ตัวใหญ่เท่านั้น ห้ามตัวขาว */

  /* ---- State: warning (amber อุ่น) — near-expiry ---- */
  --color-warning:         #92652B; /* strong: ตัวขาว 5.10:1 */
  --color-warning-surface: #F7E6C8; /* soft: text-ink 8.21:1 */

  /* ---- State: danger (terracotta หม่น) — expired / สิทธิ์ไม่พอ ---- */
  --color-danger:          #A24B3B; /* strong: ตัวขาว 5.82:1 */
  --color-danger-surface:  #F6DAD2; /* soft: text-ink 7.62:1 */

  /* ---- State: success (เขียวนวล) — ตัดสำเร็จ ---- */
  --color-success:         #4F7A55; /* strong: ตัวขาว 4.94:1 */
  --color-success-surface: #DCE8DC; /* soft: text-ink 7.98:1 */

  /* ---- State: info (ฟ้าหม่น) ---- */
  --color-info:            #4A6E86; /* strong: ตัวขาว 5.43:1 */
  --color-info-surface:    #DCE6EE; /* soft: text-ink 7.97:1 */

  /* ---- a11y / UI ---- */
  --color-focus:         #7A5640; /* focus ring  6.03:1 vs bg */
  --color-disabled-bg:   #EFE9E1;
  --color-disabled-text: #A89C8F; /* จงใจจาง — WCAG 1.4.3 ยกเว้น disabled */

  /* ---- เงาโทนอุ่น (ห้ามดำ) ---- */
  --shadow-soft:  0 2px 8px  rgba(74,64,57,0.08); /* Member */
  --shadow-card:  0 6px 20px rgba(74,64,57,0.10); /* Member ลอย */
  --shadow-admin: 0 1px 2px  rgba(74,64,57,0.14); /* Admin แน่น */
}
```

## กฎการใช้ (concise)

- **ปุ่มตัวอักษรขาว** → ได้เฉพาะบน `primary-strong`, `warning`, `danger`, `success`, `info` เท่านั้น — **ห้าม**ตัวขาวบน `primary` / `accent` / `sage` (2.39 / — / 2.28 ตกหมด)
- **soft button (default สปา)** → `bg-accent text-ink` (7.08:1)
- **state badge/banner** → `bg: *-surface; color: ink` (≥7.6:1) + ใส่ไอคอน/เส้นซ้ายสีเข้มคู่กัน **อย่าใช้สีอย่างเดียวสื่อความหมาย** (WCAG 1.4.1)
- **near-expiry** → `warning-surface` + ไอคอนนาฬิกา; `warning` strong ใช้กับปุ่ม "ต่ออายุ"
- **expired / สิทธิ์ไม่พอตอนกดตัด** → `danger-surface` banner; `danger` strong = ปุ่ม block/destructive
- **ตัดสำเร็จ** → `success-surface` toast + เครื่องหมายถูก
- **`ink-muted` (#6E6258)** → ตอนนี้ปลอดภัยทุกขนาดบน `bg`/`surface` (ราคา/วันหมดอายุ/แต้ม) — ห้ามวางบนพื้นสี
- **focus** → `outline: 2px solid var(--color-focus); outline-offset: 2px` ทุก interactive (6.03:1)
- **disabled** → `disabled-bg` + `disabled-text` + `cursor:not-allowed` + `aria-disabled`
- **เส้นขอบ** → Member ใช้ `border` (นุ่ม), Admin ตาราง/divider ใช้ `border-strong` (3.19:1 กวาดตาไว)

## ตาราง contrast (verified ด้วย script)

| Foreground | Background | ratio | AA-normal (4.5) | ใช้ |
|---|---|---|---|---|
| ink | surface | 10.08 | ✅ | body |
| ink | bg | 9.36 | ✅ | body |
| **ink-muted #6E6258** | surface | **5.91** | ✅ | ราคา/วันหมดอายุ |
| **ink-muted #6E6258** | bg | **5.49** | ✅ | ราคา/วันหมดอายุ |
| ink | accent | 7.08 | ✅ | soft button |
| white | primary-strong | 4.91 | ✅ | CTA |
| white | primary | 2.39 | ❌ | ห้าม |
| white | sage | 2.28 | ❌ | ห้าม |
| ink | primary | 4.21 | ⚠️ ใหญ่เท่านั้น | badge |
| ink | sage | 4.42 | ⚠️ ใหญ่เท่านั้น | badge |
| white | warning #92652B | 5.10 | ✅ | warning CTA |
| ink | warning-surface | 8.21 | ✅ | warning badge |
| white | danger #A24B3B | 5.82 | ✅ | danger CTA |
| ink | danger-surface | 7.62 | ✅ | danger banner |
| white | success #4F7A55 | 4.94 | ✅ | success CTA |
| ink | success-surface | 7.98 | ✅ | success toast |
| white | info #4A6E86 | 5.43 | ✅ | info CTA |
| ink | info-surface | 7.97 | ✅ | info banner |
| focus #7A5640 | bg | 6.03 | ✅ (UI ≥3) | ring |
| border-strong #9C8772 | bg | 3.19 | ✅ (UI ≥3) | divider |

> หมายเหตุ: strong variants จูนไว้สำหรับ **ตัวขาว** เท่านั้น (อย่าเอา ink ไปวาง), surface variants สำหรับ **ตัว ink** — แยกหน้าที่ชัด

## Member vs Admin (token เดียวกัน คนละ dialect)

- **Member:** radius ใหญ่ (`rounded-2xl`), padding เยอะ, `--shadow-card`, เส้น `border` จาง, motion เบา 150–220ms ease-out (เคารพ `prefers-reduced-motion`)
- **Admin:** `rounded-md`, row แน่น, `--shadow-admin`/ไม่มีเงา, divider `border-strong` ให้ตารางกวาดตาไว
- ทั้งคู่ใช้เงาโทนอุ่นโปร่ง `rgba(74,64,57,…)` เท่านั้น — ไม่มีดำ; state colors เหมือนกันทั้ง 2 ฝั่ง ต่างแค่ density/radius/เส้น

## งานแรกที่ควรทำ (high-impact)
แก้ `--color-ink-muted` `#8A7E73 → #6E6258` ก่อนเลย — บรรทัดเดียว แต่ปลดล็อก AA ให้ตัวรองที่ถี่สุด (ราคา/วันหมดอายุ/แต้ม)
