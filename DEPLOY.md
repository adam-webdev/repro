# Deploy ke aaPanel

Alur kerja: ubah kode di laptop → `git push` ke GitHub → di server jalankan `bash deploy.sh`.
Database **tidak pernah dikosongkan** oleh skrip; pindah data dilakukan lewat export/import.

## A. Persiapan sekali saja di server

1. **Software** (aaPanel → App Store):
   - Nginx, MySQL 8, **PHP 8.3**, phpMyAdmin
   - **Node.js** (untuk build tampilan) dan **Git** (biasanya sudah ada)
   - Composer: `curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer`
2. **PHP 8.3 → Install extensions**: `fileinfo`, `zip`, `gd`, `intl`, `redis` (opsional).
   **PHP 8.3 → Disabled functions**: hapus `putenv`, `proc_open`, `symlink`, `exec` dari daftar (dibutuhkan composer & artisan).
   **PHP 8.3 → Configuration**: `upload_max_filesize = 64M`, `post_max_size = 64M`,
   `memory_limit = 512M`, `max_execution_time = 300` (file Excel bulanan bisa belasan MB).
3. **Website → Add site**: domain/IP, PHP 8.3, buat database MySQL (catat nama, user, password).
4. **Ambil kode** (terminal aaPanel, ganti `/www/wwwroot/repro` dengan folder site Anda):
   ```bash
   cd /www/wwwroot
   rm -rf repro && git clone https://github.com/adam-webdev/repro.git repro
   cd repro
   cp .env.example .env
   ```
   Repo private? Pakai Personal Access Token GitHub:
   `git clone https://<TOKEN>@github.com/adam-webdev/repro.git repro`
5. **Isi `.env`** (`nano .env`):
   ```
   APP_NAME="Production Monitoring"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://domain-anda
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=nama_db
   DB_USERNAME=user_db
   DB_PASSWORD=password_db
   ```
   lalu `php artisan key:generate` (sekali saja — jangan diulang setelah ada data/login).
6. **Website → site → Settings**:
   - **Site directory → Running directory**: `/public`; matikan **Anti-XSS (open_basedir)**.
   - **URL rewrite**: pilih template `laravel5`, atau isi:
     ```
     location / { try_files $uri $uri/ /index.php?$query_string; }
     ```
   - **SSL**: Let's Encrypt (bila pakai domain).
7. **Database**
   - Pindah data dari laptop: export `report_system` dari HeidiSQL/phpMyAdmin Laragon
     → import ke database server lewat phpMyAdmin aaPanel. Setelah itu langsung ke langkah 8.
   - Atau mulai kosong: `php artisan migrate --seed --force` (membuat akun `admin` + 12 karu, password `password`).
8. **Deploy pertama**: `bash deploy.sh`
9. Folder upload (foto form karu, file import) ada di `storage/app/private`. Bila pindah dari laptop,
   salin isi folder itu juga (zip → upload lewat File Manager aaPanel).

## B. Setiap ada pembaruan

Di laptop:
```bash
git add -A
git commit -m "keterangan perubahan"
git push
```
Di server (terminal aaPanel):
```bash
cd /www/wwwroot/repro
bash deploy.sh
```
Yang dilakukan `deploy.sh`: mode maintenance → `git pull` → `composer install` → `npm run build`
→ **backup database** ke `storage/backups/` (10 terakhir disimpan) → `migrate` (hanya menambah perubahan
tabel; data tidak dihapus) → cache ulang → hak akses folder → online lagi.

Pilihan:
- `bash deploy.sh --no-migrate` — tabel database tidak disentuh sama sekali.
- PHP bukan default? `PHP_BIN=/www/server/php/83/bin/php bash deploy.sh`

## C. Backup & pindah data

- Backup otomatis tiap deploy: `storage/backups/*.sql.gz`.
- Backup manual: phpMyAdmin → database → Export, atau aaPanel → Database → Backup (bisa dijadwalkan di Cron).
- Kembalikan backup: `gunzip < storage/backups/<file>.sql.gz | mysql -u user_db -p nama_db`

## D. Jangan

- Jangan jalankan `migrate:fresh`, `migrate:refresh`, `db:wipe` atau `db:seed` di server yang sudah berisi data.
- Jangan edit file kode langsung di server; `deploy.sh` mengembalikan kode ke versi GitHub.
- Jangan commit `.env` atau data Excel asli ke GitHub (sudah dicegah oleh `.gitignore`).

## E. Kosongkan data import untuk uji ulang

Untuk menguji dari nol (import Excel harian lalu cocokkan grafik), data hasil import bisa dikosongkan
tanpa menyentuh akun:

```bash
php artisan produksi:reset            # tanya konfirmasi, backup otomatis ke storage/backups/
php artisan produksi:reset --forms    # sekalian hapus Form Produksi, Form Evaluasi & fotonya
```

| Dihapus | Tetap |
| --- | --- |
| Hasil produksi, downtime, reject, ringkasan dashboard | Akun pengguna & karyawan |
| Order, customer, material, data SAP | Master mesin & area |
| Riwayat import + file Excel yang pernah diupload | Daftar alasan downtime & jenis reject |
| Std output otomatis | Pilihan form karu, std output manual |
| (dengan `--forms`) Form Produksi, Form Evaluasi, foto | Log aktivitas (reset ikut tercatat) |

Backup memakai `mysqldump`. Bila tidak ada di PATH (mis. Laragon), isi di `.env`:
`MYSQLDUMP_PATH="C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe"`.
Setelah reset, import ulang dari menu **Import data** — sistem menghitung ulang semua grafik dari file itu.

Tanpa SSH, Admin Master bisa memakai tombol **Kosongkan data produksi** di menu **Import data** (isi sama dengan perintah di atas tanpa `--forms`;
Form Produksi & Form Evaluasi tetap). Backup otomatis dari tombol ini butuh `proc_open` aktif dan `mysqldump` tersedia
(di aaPanel biasanya `/www/server/mysql/bin/mysqldump`; isi `MYSQLDUMP_PATH` bila tidak ada di PATH). Bila backup gagal, tombol menolak
dengan pesan, kecuali dicentang **Lanjut tanpa backup** — sebaiknya export dulu dari phpMyAdmin.
