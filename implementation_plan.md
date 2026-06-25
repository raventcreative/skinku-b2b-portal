# Rework Desain Skinku B2B Portal

Rework penuh UI seluruh halaman Skinku B2B Portal dengan arah desain **clean & minimal**, warna dasar **merah (red)**, menggunakan **Tailwind CSS** via CDN yang sudah ada.

---

## Design System

### Color Palette
- **Primary:** `red-700` / `red-800` (sidebar, button utama, aksen)
- **Background:** `gray-50` / `white` (clean, terang)
- **Text:** `gray-900` (heading), `gray-500` (muted), `gray-400` (placeholder)
- **Border:** `gray-200` (subtle)
- **Accent positif:** `emerald-500` (success, pertumbuhan)
- **Accent warning:** `amber-500` (pending)
- **Accent danger:** `red-500` (error, stok rendah)

### Typography
- Font: **Inter** (Google Fonts) — modern, clean, sangat readable
- Heading halaman: `text-lg font-semibold text-gray-900`
- Label: `text-xs font-medium text-gray-500 uppercase tracking-wide`
- Value utama: `text-2xl font-bold text-gray-900`

### Component Language
- Card: `bg-white rounded-xl border border-gray-200 shadow-sm`
- Button primary: `bg-red-700 hover:bg-red-800 text-white rounded-lg px-4 py-2 text-sm font-medium`
- Badge: `rounded-full px-2.5 py-0.5 text-xs font-medium`
- Table: clean, border-bawah saja, hover `bg-gray-50`
- Input: `border-gray-300 rounded-lg focus:ring-red-500 focus:border-red-500`

---

## Proposed Changes

### 1. Layout Utama

#### [MODIFY] [app.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/layouts/app.blade.php)
- Tambah Google Fonts Inter di `<head>`
- Update Tailwind config: tambah font Inter sebagai default sans
- **Sidebar** rework: background `white`, border kanan `gray-200`, aksen merah hanya pada active state & logo
- **Sidebar aktif nav item:** left border merah + background `red-50` + text `red-700`
- **User info card** di sidebar: avatar inisial dengan bg `red-100 text-red-700`
- **Top header:** height `h-14`, border bawah clean, tambah breadcrumb area
- **Footer:** lebih compact, text lebih kecil

---

### 2. Dashboard

#### [MODIFY] [index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/dashboard/index.blade.php)
- **Stat cards:** tambah ikon SVG per card, colored left-border accent, value besar, label kecil
- **Sales trend chart:** update warna ke `red-600` dengan fill `red-50`
- **PO Status doughnut:** update palet warna lebih bersih
- **Recent PO table:** style tabel clean, badge status berwarna sesuai status
- **Low stock list:** item dengan indikator merah yang lebih jelas

---

### 3. Halaman Auth

#### [MODIFY] [login.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/auth/login.blade.php)
- Layout split: kiri branding (merah solid), kanan form
- Form lebih bersih dengan label, input dengan focus ring merah
- Button login dengan gaya bold merah

#### [MODIFY] [forgot-password.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/auth/forgot-password.blade.php)
#### [MODIFY] [reset-password.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/auth/reset-password.blade.php)
#### [MODIFY] [change-password.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/auth/change-password.blade.php)

---

### 4. Purchase Orders

#### [MODIFY] [index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/purchase_orders/index.blade.php)
- Header dengan tombol "Buat PO" merah
- Filter bar inline
- Tabel dengan badge status berwarna

#### [MODIFY] [create.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/purchase_orders/create.blade.php)
- Form layout 2 kolom, section header, input fields clean

#### [MODIFY] [show.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/purchase_orders/show.blade.php)
- Detail view dengan card-based layout, timeline status

---

### 5. Halaman Lainnya

#### [MODIFY] [inventory/index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/inventory/index.blade.php)
#### [MODIFY] [products/index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/products/index.blade.php)
#### [MODIFY] [users/index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/users/index.blade.php)
#### [MODIFY] [users/_fields.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/users/_fields.blade.php)
#### [MODIFY] [reports/index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/reports/index.blade.php)
#### [MODIFY] [stock_movements/index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/stock_movements/index.blade.php)
#### [MODIFY] [audit_logs/index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/audit_logs/index.blade.php)
#### [MODIFY] [settings/index.blade.php](file:///home/reyfuu/skinku-b2b-portal/resources/views/settings/index.blade.php)

---

## Urutan Eksekusi

```
1. layouts/app.blade.php        ← fondasi semua halaman
2. dashboard/index.blade.php    ← halaman utama
3. auth/*.blade.php             ← login & auth pages
4. purchase_orders/*.blade.php  ← fitur inti
5. inventory, products, users   ← management pages
6. reports, stock_movements     ← reporting
7. audit_logs, settings         ← admin pages
```

---

## Open Questions

> [!IMPORTANT]
> **Perlu konfirmasi sebelum eksekusi:**
> 1. Apakah sidebar warnanya **putih** (clean sidebar, aksen merah) atau tetap **merah solid** seperti sekarang?
> 2. Apakah `welcome.blade.php` (halaman landing) juga ikut di-rework?

---

## Verification Plan

### Manual Verification
- Jalankan `php artisan serve` dan akses setiap halaman
- Cek responsivitas di berbagai ukuran layar
- Pastikan semua Blade directive & variabel tetap berfungsi
