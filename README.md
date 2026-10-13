# Turnamen PES (PHP + SQLite)

Aplikasi turnamen PES sederhana: 32 tim, 8 grup, fase grup sekali bertemu, lalu knockout sampai final.
Tanpa framework dan tanpa composer — cukup PHP.

## Kebutuhan
- PHP 8.1+ dengan ekstensi `pdo_sqlite` dan `fileinfo` (aktif secara default di XAMPP/Laragon).
- Folder `data/` dan `public/uploads/` harus bisa ditulis oleh web server.

## Menjalankan
**Cara cepat (tanpa Apache):**
```
php -S localhost:8000 -t public
```
Buka http://localhost:8000

**XAMPP / Laragon:** taruh folder ini di `htdocs` (atau `www`), lalu buka
`http://localhost/pes-turnamen/public/`.

Saat pertama dibuka, klik **Login Admin** — aplikasi akan meminta Anda membuat akun admin
(tidak ada password bawaan). Lupa password? `php tools/reset-admin.php <username> <password-baru>`

## Cara pakai
1. **Admin → Data Tim**: isi nama pemain, nama tim, grup (A–H), dan logo (opsional). Setiap grup 4 tim.
   Begitu sebuah grup berisi 4 tim, jadwal 6 laga (3 matchday) dibuat otomatis.
   (Di Panel Admin ada tombol "Isi 32 tim contoh" untuk mencoba.)
2. **Admin → Input Skor**: masukkan skor tiap laga. Kosongkan kedua skor untuk menghapus hasil.
3. **Admin → Ekspor / Impor**: amankan data dengan mengunduh cadangan lengkap (.zip berisi database dan logo), JSON, atau SQLite. Bisa diimpor kembali kapan saja jika ingin memulihkan atau memindahkan turnamen ke komputer lain.
4. Halaman publik: **Klasemen** (monitor posisi), **Pertandingan** (monitor laga, ada filter grup/status),
   **Bagan** (skema knockout). Tombol **Mode layar** = tampilan besar yang refresh otomatis tiap 10 detik (cocok untuk TV/proyektor).

## Aturan
- Menang 3 poin, seri 1, kalah 0.
- Urutan klasemen: **Poin → Selisih Gol → Gol Memasukkan → Nama Tim** (bukan head to head).
- Juara & runner-up tiap grup lolos ke 16 besar:
  1A–2B, 1C–2D, 1E–2F, 1G–2H, 1B–2A, 1D–2C, 1F–2E, 1H–2G.
  Lalu perempat final, semi final, final. (Tidak ada perebutan juara 3.)
- Knockout satu laga. Jika seri, isi skor adu penalti (wajib, tidak boleh sama).
- Bagan terisi otomatis: slot 16 besar terisi begitu grupnya selesai, babak berikutnya terisi saat laga sebelumnya selesai.
  Jika hasil lama diubah dan peserta suatu laga berubah, skor laga lanjutan direset otomatis.
- Tim tidak bisa dipindah/dihapus jika grupnya sudah punya skor (hapus skor grup itu dulu).

## Struktur
```
app/        db.php (skema SQLite), tournament.php (logika), web.php (session, CSRF, tampilan)
public/     halaman web (arahkan web server ke folder ini), admin/, assets/, uploads/
data/       file database pes.sqlite (dibuat otomatis, di luar folder public)
tools/      reset-admin.php
```
Cadangan data = salin `data/pes.sqlite` dan `public/uploads/`.
