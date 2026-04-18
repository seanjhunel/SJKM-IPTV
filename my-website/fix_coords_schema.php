<?php
/**
 * Fix Coordinates Schema Script
 * Updates latitude and longitude columns to DECIMAL(11,8) to support full coordinate range.
 * 
 * Run this script once on your server.
 */

require_once 'includes/config.php';
require_once 'includes/db.php';

echo "<h2>🛠️ Perbaikan Schema Koordinat (Lat/Lng)</h2>";
echo "<p>Script ini akan mengubah tipe kolom latitude dan longitude menjadi <code>DECIMAL(11,8)</code> agar mendukung koordinat Indonesia (95-141 BT).</p><hr>";

try {
    $pdo = getDB();
    
    // 1. Update table customers
    echo "1. Memperbaiki tabel <b>customers</b>... ";
    try {
        // Check if table exists
        $check = $pdo->query("SHOW TABLES LIKE 'customers'");
        if ($check->rowCount() > 0) {
            $pdo->exec("ALTER TABLE customers MODIFY lat DECIMAL(11,8), MODIFY lng DECIMAL(11,8)");
            echo "<span style='color:green'>SUKSES</span><br>";
        } else {
            echo "<span style='color:orange'>Tabel tidak ditemukan</span><br>";
        }
    } catch (PDOException $e) {
        echo "<span style='color:red'>GAGAL: " . $e->getMessage() . "</span><br>";
    }

    // 2. Update table odps
    echo "2. Memperbaiki tabel <b>odps</b>... ";
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'odps'");
        if ($check->rowCount() > 0) {
            $pdo->exec("ALTER TABLE odps MODIFY lat DECIMAL(11,8), MODIFY lng DECIMAL(11,8)");
            echo "<span style='color:green'>SUKSES</span><br>";
        } else {
            echo "<span style='color:orange'>Tabel tidak ditemukan</span><br>";
        }
    } catch (PDOException $e) {
        echo "<span style='color:red'>GAGAL: " . $e->getMessage() . "</span><br>";
    }

    // 3. Update table onu_locations
    echo "3. Memperbaiki tabel <b>onu_locations</b>... ";
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'onu_locations'");
        if ($check->rowCount() > 0) {
            $pdo->exec("ALTER TABLE onu_locations MODIFY lat DECIMAL(11,8), MODIFY lng DECIMAL(11,8)");
            echo "<span style='color:green'>SUKSES</span><br>";
        } else {
            echo "<span style='color:orange'>Tabel tidak ditemukan</span><br>";
        }
    } catch (PDOException $e) {
        echo "<span style='color:red'>GAGAL: " . $e->getMessage() . "</span><br>";
    }

    echo "<hr><h3>✅ Perbaikan Selesai!</h3>";
    echo "<p>Sekarang Anda bisa menyimpan titik koordinat dengan akurat di server hosting.</p>";
    echo "<p>Silakan hapus file ini demi keamanan.</p>";
    echo "<a href='admin/dashboard.php'>Kembali ke Dashboard</a>";

} catch (PDOException $e) {
    echo "<hr><h3 style='color:red'>❌ Error Koneksi Database: " . $e->getMessage() . "</h3>";
}
