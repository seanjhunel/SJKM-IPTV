<?php
/**
 * Database Migration for Technician Portal
 * Run this script to update database schema
 */

require_once 'includes/config.php';
require_once 'includes/db.php';

echo "<h2>🛠️ Migrasi Database Portal Teknisi</h2>";

try {
    $pdo = getDB();
    
    // 1. Create technician_users table
    echo "1. Membuat tabel technician_users... ";
    $sql1 = "CREATE TABLE IF NOT EXISTS technician_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        status ENUM('active', 'inactive') DEFAULT 'active',
        last_login DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql1);
    echo "<span style='color:green'>OK</span><br>";
    
    // 2. Add technician_id to trouble_tickets
    echo "2. Update tabel trouble_tickets... ";
    // Check if column exists first
    $check2 = $pdo->query("SHOW COLUMNS FROM trouble_tickets LIKE 'technician_id'");
    if ($check2->rowCount() == 0) {
        $sql2 = "ALTER TABLE trouble_tickets 
                 ADD COLUMN technician_id INT DEFAULT NULL,
                 ADD CONSTRAINT fk_ticket_technician FOREIGN KEY (technician_id) REFERENCES technician_users(id) ON DELETE SET NULL";
        $pdo->exec($sql2);
        echo "<span style='color:green'>OK</span><br>";
    } else {
        echo "<span style='color:orange'>Sudah ada</span><br>";
    }
    
    // 3. Add installation columns to customers
    echo "3. Update tabel customers... ";
    $check3 = $pdo->query("SHOW COLUMNS FROM customers LIKE 'installed_by'");
    if ($check3->rowCount() == 0) {
        $sql3 = "ALTER TABLE customers
                 ADD COLUMN installed_by INT DEFAULT NULL,
                 ADD COLUMN installation_date DATETIME DEFAULT NULL,
                 ADD CONSTRAINT fk_customer_technician FOREIGN KEY (installed_by) REFERENCES technician_users(id) ON DELETE SET NULL";
        $pdo->exec($sql3);
        echo "<span style='color:green'>OK</span><br>";
    } else {
        echo "<span style='color:orange'>Sudah ada</span><br>";
    }

    // 4. Add photo proof column to trouble_tickets
    echo "4. Update tabel trouble_tickets (foto bukti)... ";
    $check4 = $pdo->query("SHOW COLUMNS FROM trouble_tickets LIKE 'photo_proof'");
    if ($check4->rowCount() == 0) {
        $sql4 = "ALTER TABLE trouble_tickets ADD COLUMN photo_proof VARCHAR(255) DEFAULT NULL";
        $pdo->exec($sql4);
        echo "<span style='color:green'>OK</span><br>";
    } else {
        echo "<span style='color:orange'>Sudah ada</span><br>";
    }
    
    // 5. Add photo proof column to customers (PSB)
    echo "5. Update tabel customers (foto bukti instalasi)... ";
    $check5 = $pdo->query("SHOW COLUMNS FROM customers LIKE 'installation_photo'");
    if ($check5->rowCount() == 0) {
        $sql5 = "ALTER TABLE customers ADD COLUMN installation_photo VARCHAR(255) DEFAULT NULL";
        $pdo->exec($sql5);
        echo "<span style='color:green'>OK</span><br>";
    } else {
        echo "<span style='color:orange'>Sudah ada</span><br>";
    }

    // 6. Create ODPs table
    echo "6. Membuat tabel odps... ";
    $sql6 = "CREATE TABLE IF NOT EXISTS odps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        code VARCHAR(50) UNIQUE,
        lat DECIMAL(11,8),
        lng DECIMAL(11,8),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sql6);
    echo "<span style='color:green'>OK</span><br>";

    // 7. Create ONU Locations table
    echo "7. Membuat tabel onu_locations... ";
    $sql7 = "CREATE TABLE IF NOT EXISTS onu_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        serial_number VARCHAR(100) UNIQUE,
        lat DECIMAL(11,8),
        lng DECIMAL(11,8),
        odp_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (odp_id) REFERENCES odps(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sql7);
    echo "<span style='color:green'>OK</span><br>";

    // 8. Create ODP Links table
    echo "8. Membuat tabel odp_links... ";
    $sql8 = "CREATE TABLE IF NOT EXISTS odp_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_odp_id INT NOT NULL,
        to_odp_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (from_odp_id) REFERENCES odps(id) ON DELETE CASCADE,
        FOREIGN KEY (to_odp_id) REFERENCES odps(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sql8);
    echo "<span style='color:green'>OK</span><br>";

    echo "<hr><h3>✅ Migrasi Selesai!</h3>";
    echo "<p>Silakan hapus file ini jika sudah tidak diperlukan.</p>";
    echo "<a href='admin/dashboard.php'>Kembali ke Dashboard</a>";

} catch (PDOException $e) {
    echo "<hr><h3 style='color:red'>❌ Error: " . $e->getMessage() . "</h3>";
}
