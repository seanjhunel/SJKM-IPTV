# Panduan Migrasi & Troubleshoot aaPanel

Jika Anda memindahkan aplikasi GEMBOK dari hosting biasa atau XAMPP ke server VPS yang menggunakan **aaPanel**, Anda mungkin akan menemui beberapa kendala database atau sistem. Berikut adalah solusi untuk masalah-masalah paling umum.

## 1. Nonaktifkan MySQL Strict Mode (PENTING)
aaPanel secara default mengaktifkan "Strict Mode" yang sangat ketat terhadap format data. Ini sering menyebabkan query database gagal.

**Solusi:**
1. Masuk ke **aaPanel Dashboard**.
2. Klik menu **App Store** di samping kiri.
3. Cari **MySQL** atau **MariaDB** yang Anda gunakan, klik tombol **Setting**.
4. Klik tab **Configuration**.
5. Cari baris yang berisi `sql-mode` atau `sql_mode`.
6. Ubah nilainya menjadi:
   ```ini
   sql-mode=NO_ENGINE_SUBSTITUTION
   ```
   *Jika baris tersebut tidak ada, Anda bisa menambahkannya di bawah bagian `[mysqld]`.*
7. Klik **Save**, lalu pindah ke tab **Service** dan klik **Restart**.

## 2. Pengaturan Case Sensitivity (Nama Tabel)
Di Linux (aaPanel), nama tabel database bersifat *case-sensitive*. Jika aplikasi Anda memanggil tabel `Settings` padahal di database bernama `settings`, maka akan error.

**Solusi:**
1. Di tab **Configuration** MySQL aaPanel (seperti langkah di atas).
2. Tambahkan baris ini di bawah `[mysqld]`:
   ```ini
   lower_case_table_names=1
   ```
3. Klik **Save** dan **Restart** service MySQL.
   *Catatan: Sangat disarankan untuk melakukan ini SEBELUM mengimport database SQL Anda.*

## 3. Ekstensi PHP yang Dibutuhkan
Aplikasi ini membutuhkan beberapa modul PHP agar berjalan lancar. Pastikan modul berikut terinstall:

1. Klik **App Store** -> Pilih **PHP** yang digunakan (misal PHP 8.1/8.2) -> **Setting**.
2. Klik tab **Install extensions**.
3. Pastikan ekstensi berikut memiliki tanda centang hijau (terinstall):
   - `pdo_mysql` (Wajib untuk database)
   - `mysqli`
   - `curl` (Wajib untuk API MikroTik & WhatsApp)
   - `gd` (Penting untuk pengolahan gambar/captcha)
   - `intl`
   - `fileinfo`

## 4. Izin Folder (Permissions)
Aplikasi perlu menulis file log dan cache. Jika permission salah, aplikasi bisa hang atau blank.

**Solusi:**
Gunakan terminal aaPanel atau File Manager, jalankan perintah berikut di folder root aplikasi:
```bash
# Memberikan izin ke folder logs
chmod -R 775 logs/
chmod -R 775 includes/

# Pastikan owner adalah www (user web server aaPanel)
chown -R www:www .
```

## 5. Cek Error Melalui Log
Jika aplikasi masih error/blank, jangan menebak-nebak. Cek log yang sudah disediakan:

1. **Log Aplikasi:** Cek file `logs/php_error.log` dan `logs/db_error.log`.
2. **Log aaPanel:** Cek di menu **Site** -> Klik nama domain -> **Site logs**.

---
*Dibuat untuk membantu kelancaran migrasi aplikasi GEMBOK ISP Management.*
