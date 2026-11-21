# Production Monitoring (Cutting)

Dashboard monitoring hasil produksi: import Excel per shift / per hari, grafik harian–mingguan–bulanan,
deteksi data janggal (downtime vs capaian), dan perbandingan yield SAP vs data lapangan.

Laravel 13 · MySQL 8 · Tailwind 4 · Alpine.js · ApexCharts · OpenSpout (pembaca Excel hemat memori)

## Menjalankan (Laragon)

```bash
composer install
npm install && npm run build
cp .env.example .env            # lalu isi DB_DATABASE=report_system
php artisan key:generate
php artisan migrate --seed      # tabel + master mesin, jenis reject, alasan downtime, akun awal
php artisan serve               # buka http://127.0.0.1:8000
```

Login memakai **username** (atau email jika diisi). Semua akun awal berpassword `password` dan
**wajib ganti password saat login pertama**.

| Username | Role | Keterangan |
|---|---|---|
| `admin` | Admin Master | Akses penuh, kelola karyawan & akun, log aktivitas, boleh mengganti data lama |
| `KA044`, `KA072`, … (12 karu) | Karu | Kode karu tanpa spasi. Isi Form Produksi & Form Evaluasi |
| `admin1`, `admin2`, `admin3` | Admin Produksi | Akun uji, boleh dinonaktifkan |

## Role & hak akses

| Role | Menu |
|---|---|
| Admin Master | Semua menu + Karyawan & Akun + Log Aktivitas |
| Karu | Form Produksi (rutin), Form Evaluasi (saat ada masalah), Dashboard |
| Admin Produksi | Import, Export, Tabel Data, lihat semua form karu, Dashboard |
| Viewer | Dashboard saja (lihat) |

## Audit trail

- Setiap tambah / edit / hapus (karyawan, akun, form karu, import data) tercatat di `activity_logs`:
  siapa (nama, role, jabatan), aksi, waktu, dan isi sebelum → sesudah. Klik nama siapa pun untuk melihat kartu karyawannya.
- Hapus = **soft delete**. Form karu dan baris import yang diganti tetap tersimpan (kolom `deleted_at` / `deleted_by`).
- Import ulang tanggal + shift yang sama menampilkan **baris yang berubah** sebelum disimpan. Waktu & orang yang
  pertama kali menginput tetap disimpan (`created_at` / `created_by`), revisi tercatat di `updated_at` / `updated_by`.

## Form Karu (dibuka di HP)

- **Form Produksi**: tanggal (boleh mundur), shift, area; banyak mesin per form. Per mesin: jenis mesin → opsi DT
  menyesuaikan (Laser / EXS / umum), Set Up & DT dengan menit, std/act output, OK, 2nd grade, NC, foto.
- **Form Evaluasi** (PDR/PP/13): Pengecekan #1, #2, … (mesin, customer, ukuran OD/D1–D4/tebal/tinggi IB/panjang,
  JIG, Visual, Kontrol Kepatuhan, actual cutting, problem, banyak foto).
- Setiap dropdown punya "Lainnya (isi manual)". Daftar opsi ada di `config/production.php` (`form_options`).
- Foto diperkecil di HP sebelum diunggah (±20–200 KB per foto), disimpan di `storage/app/private/evidence`.
- Dashboard bagian **Kontrol Karu** membandingkan isian karu dengan data import admin (tanggal + shift + mesin).

## Import data historis (file besar)

File besar lebih cepat diimport lewat terminal:

```bash
php artisan report:import docs/daily-operation.xlsx --baseline   # ±2,5 menit untuk 27 rb baris
php artisan report:import docs/mentahan.xlsx                     # export SAP: Order | GI kg | GR %
```

`--baseline` menghitung std output otomatis (median kecepatan historis per mesin + material).
Kalau std output resmi sudah ada, isi tabel `machine_standards` dengan `source = manual`, lalu jalankan
`php artisan report:rebuild`.

## Template import

Unduh dari menu **Import Data → Template import** (atau pakai salinan di `docs/`):

- `template_import_produksi.xlsx`: kosong, siap diisi.
- `template_import_produksi_CONTOH.xlsx`: berisi 3 baris contoh (tanggal hari ini, shift 1) untuk uji coba.

Isinya sheet **Hasil Produksi**, **DT**, **Petunjuk** (aturan pengisian), **Master Mesin**, dan **Daftar Kolom**.
Template dibuat dari `app/Services/Import/TemplateBuilder.php`. `tests/Unit/ImportTemplateTest.php` memastikan setiap
kolom template dikenali pembaca Excel.

## Alur import harian (menu Import Data)

1. Admin upload Excel (format sama: sheet hasil + sheet DT). Isinya boleh 1 shift, 1 hari, atau beberapa hari.
2. Muncul **preview** per tanggal + shift, berisi status **Baru** atau **Ganti** (data lama yang akan ditimpa), beserta error dan peringatan.
3. Klik **Simpan**. Data tanggal + shift yang sama selalu diganti, jadi **tidak pernah dobel**. Back date bisa dilakukan.
4. Data yang lebih lama dari `PRODUCTION_LOCK_DAYS` (default 7 hari) hanya bisa diganti oleh supervisor.

5. **Hapus** seluruh data satu tanggal + shift: buka Tabel Data, filter 1 tanggal + 1 shift, lalu klik "Hapus data
   tanggal & shift ini" (wajib isi alasan). Data ditandai terhapus (tetap tersimpan), dashboard & akumulasi order dihitung ulang.

Aturan pengaman:
- Baris yang **isinya sama persis** dengan baris lain di file yang sama ditolak sebagai baris dobel. Order yang sama di
  mesin & jam yang sama tapi bahan baku berbeda (panjang / jumlah lain) tetap diimport, karena pola ini wajar di data.
- Hanya satu proses simpan / hapus berjalan dalam satu waktu (klik ganda / 2 admin bersamaan tidak membuat data dobel).
- Jika data tanggal + shift berubah setelah preview dibuat (ada import / hapus lain), simpan ditolak dan file harus
  diupload ulang supaya perbandingan yang ditampilkan akurat.

Uji skenario import (`tests/Feature/ImportScenarioTest.php`) memakai database MySQL `report_system_test`
(buat sekali: `CREATE DATABASE report_system_test`). Jalankan semua test: `php artisan test`.

Header Excel dibaca **berdasarkan nama kolom**, bukan posisi. Pemetaan kolom reject dan downtime ada di
`config/production.php`.

## Struktur penting

| Bagian | File |
|---|---|
| Pembaca Excel (Sheet hasil + DT dipasangkan, export SAP) | `app/Services/Import/WorkbookParser.php` |
| Preview & simpan (ganti per tanggal+shift) | `app/Services/Import/ImportService.php` |
| Tabel ringkasan harian untuk dashboard | `app/Services/SummaryBuilder.php` |
| Query dashboard (dengan cache) | `app/Services/DashboardService.php` |
| Grafik | `resources/js/app.js` |
| Katalog mesin, reject, downtime, batas data janggal | `config/production.php` |

Dashboard **hanya membaca tabel ringkasan** (`daily_*_summaries`, ±55 baris per hari), bukan data mentah.
Hasilnya di-cache dan otomatis diperbarui setiap kali ada import.

## Rumus

- Total cutting = Baik + NC + Reject
- Cutting time = Jam kerja − total downtime (sheet DT)
- Yield SAP = (Baik + NC) × panjang hasil ÷ (panjang bahan baku × jumlah bahan baku)
- Yield baik = Baik × panjang hasil ÷ GI
- Capaian = total cutting ÷ (std output × jam kerja)

## Tahap berikutnya

- Field tambahan Form Produksi (spesifikasi masih TODO)
- Toleransi ukur (OD, D1–D4, tebal, panjang) supaya hasil di luar toleransi ditandai otomatis
- Std output resmi, target yield
- Konfirmasi arti kode material (OD / tebal)
