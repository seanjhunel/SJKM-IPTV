<?php
/**
 * GEMBOK Simple Installer
 * Wizard-based installer for easy hosting deployment
 */

session_start();

// Installer steps
$steps = [
    1 => ['name' => 'Server Check', 'file' => 'step1_server.php'],
    2 => ['name' => 'Database Setup', 'file' => 'step2_database.php'],
    3 => ['name' => 'Admin Setup', 'file' => 'step3_admin.php'],
    4 => ['name' => 'Finish', 'file' => 'step6_finish.php']
];

// Get current step
$currentStep = $_GET['step'] ?? 1;
$currentStep = max(1, min(4, (int)$currentStep));

// Check if already installed
if (file_exists('includes/config.php') && file_exists('includes/installed.lock')) {
    $installed = true;
} else {
    $installed = false;
}

// Prevent re-installation via POST if already installed
if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    die("Application is already installed. Please remove includes/installed.lock if you want to reinstall.");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($currentStep === 1) {
        if (!isset($_POST['relay_consent'])) {
            $error = 'Untuk melanjutkan, Anda harus menyetujui lanjutkan instalasi.';
        } else {
            $_SESSION['relay_consent'] = '1';
            header("Location: install.php?step=2");
            exit;
        }
    }
    if ($currentStep === 2) {
        // Save database config
        $_SESSION['db_config'] = [
            'host' => $_POST['db_host'],
            'name' => $_POST['db_name'],
            'user' => $_POST['db_user'],
            'pass' => $_POST['db_pass']
        ];
        
        // Test connection
        try {
                $pdo = new PDO(
                    "mysql:host={$_POST['db_host']};dbname={$_POST['db_name']}",
                    $_POST['db_user'],
                    $_POST['db_pass']
                );
                $pdo->exec("SET sql_mode = ''");
                $_SESSION['db_connected'] = true;
            header("Location: install.php?step=3");
            exit;
        } catch (PDOException $e) {
            $error = "Koneksi database gagal: " . $e->getMessage();
        }
    }
    
    if ($currentStep === 3) {
        // Save admin config
        $_SESSION['admin_config'] = [
            'username' => $_POST['admin_username'],
            'password' => password_hash($_POST['admin_password'], PASSWORD_DEFAULT),
            'email' => $_POST['admin_email']
        ];
        header("Location: install.php?step=4");
        exit;
    }

    if ($currentStep === 4) {
        // Final installation
        installApplication();
    }
}

function installApplication() {
    global $error;
    try {
        // Create required directories
        $directories = ['logs', 'uploads'];
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new Exception("Gagal membuat direktori {$dir}/. Cek permission.");
                }
            }
            // Create .htaccess to protect directories
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }
        
        // Create config.php
        $configContent = createConfigFile();
        if (file_put_contents('includes/config.php', $configContent) === false) {
            throw new Exception('Gagal menulis includes/config.php. Cek permission folder includes.');
        }
        
        // Create database tables
        createDatabaseTables();
        
        // Insert default data
        insertDefaultData();
        
        // Create installed.lock
        if (file_put_contents('includes/installed.lock', date('Y-m-d H:i:s')) === false) {
            throw new Exception('Gagal membuat includes/installed.lock. Cek permission folder includes.');
        }
        try {
            $consent = isset($_SESSION['relay_consent']) && (string) $_SESSION['relay_consent'] === '1';
            if ($consent) {
                $relayUrl = 'https://alijaya.net/webhooks/install_relay.php';
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
                $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])) : '';
                $scriptDir = preg_replace('#/(admin|api|portal|cron|webhooks|install_steps|includes|sales|templates|technician)$#', '', (string) $scriptDir);
                $scriptDir = rtrim((string) $scriptDir, '/');
                $appUrl = $domain !== 'unknown' ? ($protocol . '://' . $domain . $scriptDir) : '';

                $payload = [
                    'app' => 'gembok-simple',
                    'version' => '2.0.6',
                    'domain' => $domain,
                    'app_url' => $appUrl,
                    'installed_at' => date('c'),
                    'install_id' => bin2hex(random_bytes(16))
                ];
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json !== false) {
                    if (function_exists('curl_init')) {
                        $ch = curl_init($relayUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HEADER, false);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                        curl_exec($ch);
                        unset($ch);
                    } else {
                        @file_get_contents($relayUrl, false, stream_context_create([
                            'http' => [
                                'method' => 'POST',
                                'header' => "Content-Type: application/json\r\n",
                                'content' => $json,
                                'timeout' => 8
                            ]
                        ]));
                    }
                }
            }
        } catch (Exception $e) {
        }
        
        // Clear session
        unset($_SESSION['db_config']);
        unset($_SESSION['admin_config']);
        unset($_SESSION['relay_consent']);
        
        // Redirect to login
        header("Location: admin/login.php");
        exit;
    } catch (Exception $e) {
        $error = "Installation failed: " . $e->getMessage();
    }
}

function createConfigFile() {
    $db = $_SESSION['db_config'];
    $notifyRelay = isset($_SESSION['relay_consent']) && (string) $_SESSION['relay_consent'] === '1' ? '1' : '0';
    
    // Escape all credentials to prevent syntax errors with special characters
    $dbHost = addslashes($db['host']);
    $dbName = addslashes($db['name']);
    $dbUser = addslashes($db['user']);
    $dbPass = addslashes($db['pass']);
    $mkHost = '';
    $mkUser = '';
    $mkPass = '';
    $mkPort = 8728;
    $waUrl = '';
    $waToken = '';
    $tpApiKey = '';
    $tpPrivKey = '';
    $tpMerchant = '';
    $tgToken = '';
    $tgChatId = '';
    $notifyTg = '0';
    $relayUrl = 'https://alijaya.net/webhooks/install_relay.php';
    
    // Generate a static encryption key (persisted in config, not regenerated)
    $encryptionKey = bin2hex(random_bytes(32));
    $generatedDate = date('Y-m-d H:i:s');
    
    return <<<PHP
<?php
/**
 * GEMBOK Configuration File
 * Generated by installer on: {$generatedDate}
 */

// Database Configuration
define('DB_HOST', '{$dbHost}');
define('DB_NAME', '{$dbName}');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');

// MikroTik Configuration
define('MIKROTIK_HOST', '{$mkHost}');
define('MIKROTIK_USER', '{$mkUser}');
define('MIKROTIK_PASS', '{$mkPass}');
define('MIKROTIK_PORT', {$mkPort});

// Application Configuration
define('APP_NAME', 'GEMBOK');
if (php_sapi_name() !== 'cli' && isset(\$_SERVER['HTTP_HOST'])) {
    \$protocol = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    \$scriptDir = str_replace('\\\\', '/', dirname(\$_SERVER['SCRIPT_NAME']));
    \$scriptDir = preg_replace('#/(admin|api|portal|cron|webhooks|install_steps|includes|sales|templates|technician)$#', '', \$scriptDir);
    \$scriptDir = rtrim(\$scriptDir, '/');
    define('APP_URL', \$protocol . '://' . \$_SERVER['HTTP_HOST'] . \$scriptDir);
} else {
    define('APP_URL', 'http://localhost');
}
define('APP_VERSION', '2.0.6');
define('GEMBOK_UPDATE_VERSION_URL', 'https://raw.githubusercontent.com/alijayanet/gembok-simple/main/version.txt');

// Pagination
define('ITEMS_PER_PAGE', 20);
define('INVOICE_PREFIX', 'INV');
define('INVOICE_START', 1);

// Security
define('ENCRYPTION_KEY', '{$encryptionKey}');

// WhatsApp Configuration
define('WHATSAPP_API_URL', '{$waUrl}');
define('WHATSAPP_TOKEN', '{$waToken}');

// Tripay Configuration
define('TRIPAY_API_KEY', '{$tpApiKey}');
define('TRIPAY_PRIVATE_KEY', '{$tpPrivKey}');
define('TRIPAY_MERCHANT_CODE', '{$tpMerchant}');

// Telegram Configuration
define('TELEGRAM_BOT_TOKEN', '{$tgToken}');
define('TELEGRAM_CHAT_ID', '{$tgChatId}');
define('INSTALL_NOTIFY_TELEGRAM', '{$notifyTg}');
define('INSTALL_RELAY_URL', '{$relayUrl}');
define('INSTALL_NOTIFY_RELAY', '{$notifyRelay}');

// GenieACS Configuration
define('GENIEACS_URL', 'http://localhost:7557');
define('GENIEACS_USERNAME', '');
define('GENIEACS_PASSWORD', '');
PHP;
}

function createDatabaseTables() {
    require_once 'includes/db.php';

    $pdo = getDB();
    $payloadType = 'JSON';
    $version = '';
    try {
        $version = (string)$pdo->query("SELECT VERSION()")->fetchColumn();
    } catch (Exception $e) {
        unset($e);
        $version = '';
    }
    if ($version !== '') {
        $versionNumber = preg_replace('/[^0-9.].*/', '', $version);
        if ($versionNumber !== '') {
            if (stripos($version, 'mariadb') !== false) {
                $payloadType = version_compare($versionNumber, '10.2.7', '>=') ? 'JSON' : 'LONGTEXT';
            } else {
                $payloadType = version_compare($versionNumber, '5.7.8', '>=') ? 'JSON' : 'LONGTEXT';
            }
        }
    }

    $sql = "
    CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        name VARCHAR(100),
        reset_token VARCHAR(64),
        reset_expiry DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS technician_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        status ENUM('active', 'inactive') DEFAULT 'active',
        last_login DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        profile_normal VARCHAR(50) NOT NULL,
        profile_isolir VARCHAR(50) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        pppoe_username VARCHAR(50) UNIQUE NOT NULL,
        package_id INT,
        router_id INT DEFAULT 0,
        status ENUM('active', 'isolated') DEFAULT 'active',
        auto_isolate TINYINT(1) NOT NULL DEFAULT 1,
        isolation_date INT DEFAULT 20,
        address TEXT,
        lat DECIMAL(11,8),
        lng DECIMAL(11,8),
        portal_password VARCHAR(255),
        installed_by INT DEFAULT NULL,
        installation_date DATETIME DEFAULT NULL,
        installation_photo VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
        FOREIGN KEY (installed_by) REFERENCES technician_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) UNIQUE NOT NULL,
        customer_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status ENUM('unpaid', 'paid', 'cancelled') DEFAULT 'unpaid',
        due_date DATE NOT NULL,
        paid_at DATETIME,
        payment_method VARCHAR(50),
        payment_ref VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS odps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        code VARCHAR(50) UNIQUE,
        lat DECIMAL(11,8),
        lng DECIMAL(11,8),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS onu_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        serial_number VARCHAR(100) UNIQUE,
        lat DECIMAL(11,8),
        lng DECIMAL(11,8),
        odp_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (odp_id) REFERENCES odps(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS odp_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_odp_id INT NOT NULL,
        to_odp_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (from_odp_id) REFERENCES odps(id) ON DELETE CASCADE,
        FOREIGN KEY (to_odp_id) REFERENCES odps(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS trouble_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT,
        description TEXT,
        status ENUM('pending', 'in_progress', 'resolved') DEFAULT 'pending',
        priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
        notes TEXT,
        resolved_at DATETIME,
        technician_id INT DEFAULT NULL,
        photo_proof VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
        FOREIGN KEY (technician_id) REFERENCES technician_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS cron_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        task_type VARCHAR(50),
        schedule_time TIME,
        schedule_days VARCHAR(20),
        is_active BOOLEAN DEFAULT 1,
        last_run DATETIME,
        next_run DATETIME,
        last_status VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS cron_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        schedule_id INT,
        status ENUM('success', 'failed', 'started'),
        output TEXT,
        error_message TEXT,
        execution_time FLOAT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (schedule_id) REFERENCES cron_schedules(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS webhook_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(50),
        payload {$payloadType},
        status_code INT,
        response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS routers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        host VARCHAR(100) NOT NULL,
        username VARCHAR(100) NOT NULL,
        password VARCHAR(100) NOT NULL,
        port INT DEFAULT 8728,
        is_active BOOLEAN DEFAULT 0,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS sales_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        deposit_balance DECIMAL(15,2) DEFAULT 0,
        status ENUM('active', 'inactive') DEFAULT 'active',
        voucher_mode VARCHAR(20) DEFAULT 'mix',
        voucher_length INT DEFAULT 6,
        voucher_type VARCHAR(20) DEFAULT 'upp',
        bill_discount DECIMAL(15,2) DEFAULT 2000,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS sales_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sales_user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        description TEXT,
        related_username VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sales_user_id) REFERENCES sales_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS sales_profile_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sales_user_id INT NOT NULL,
        profile_name VARCHAR(100) NOT NULL,
        base_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        selling_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        voucher_length INT DEFAULT 6,
        is_active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sales_user_id) REFERENCES sales_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS hotspot_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100),
        profile VARCHAR(100),
        price DECIMAL(15,2),
        selling_price DECIMAL(15,2),
        prefix VARCHAR(20),
        sales_user_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sales_user_id) REFERENCES sales_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS hotspot_voucher_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(50) UNIQUE NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        customer_phone VARCHAR(20) NOT NULL,
        profile_name VARCHAR(100) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        payment_gateway VARCHAR(20) NOT NULL DEFAULT 'tripay',
        payment_method VARCHAR(100) DEFAULT NULL,
        payment_link TEXT,
        payment_reference VARCHAR(100) DEFAULT NULL,
        payment_payload LONGTEXT,
        status ENUM('pending','paid','failed','expired') DEFAULT 'pending',
        paid_at DATETIME DEFAULT NULL,
        voucher_username VARCHAR(100) DEFAULT NULL,
        voucher_password VARCHAR(100) DEFAULT NULL,
        voucher_generated_at DATETIME DEFAULT NULL,
        fulfillment_status ENUM('pending','success','failed') DEFAULT 'pending',
        fulfillment_error TEXT,
        whatsapp_status ENUM('pending','sent','failed') DEFAULT 'pending',
        whatsapp_sent_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS site_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function insertDefaultData() {
    require_once 'includes/db.php';
    
    $pdo = getDB();
    
    $admin = $_SESSION['admin_config'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO admin_users (username, password, email, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$admin['username'], $admin['password'], $admin['email']]);
    
    $settings = [
        ['app_name', 'GEMBOK'],
        ['app_version', '2.0.0'],
        ['currency', 'IDR'],
        ['CURRENCY_SYMBOL', 'Rp'],
        ['timezone', 'Asia/Jakarta'],
        ['invoice_prefix', 'INV'],
        ['invoice_start', '1'],
        ['invoice_manager_name', ''],
        ['INVOICE_PAY_TOKEN', ''],
        ['DUITKU_MERCHANT_CODE', ''],
        ['DUITKU_API_KEY', ''],
        ['DUITKU_MODE', 'production'],
        ['DUITKU_EXPIRY_MINUTES', '60'],
        ['XENDIT_SECRET_KEY', ''],
        ['XENDIT_CALLBACK_TOKEN', ''],
        ['XENDIT_INVOICE_DURATION', '3600'],
        ['PUBLIC_VOUCHER_PREFIX', 'VCH-'],
        ['PUBLIC_VOUCHER_LENGTH', '6']
    ];
    
    foreach ($settings as $setting) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute($setting);
    }

    $siteSettings = [
        ['hero_title', 'Internet Cepat <br>Tanpa Batas'],
        ['theme_color', 'neon'],
        ['hero_description', 'Nikmati koneksi internet fiber optic super cepat, stabil, dan unlimited untuk kebutuhan rumah maupun bisnis Anda. Gabung sekarang!'],
        ['contact_phone', '+62 812-3456-7890'],
        ['contact_email', 'info@gembok.net'],
        ['contact_address', 'Jakarta, Indonesia'],
        ['footer_about', 'Penyedia layanan internet terpercaya dengan jaringan fiber optic berkualitas untuk menunjang aktivitas digital Anda.'],
        ['feature_1_title', 'Kecepatan Tinggi'],
        ['feature_1_desc', 'Koneksi fiber optic dengan kecepatan simetris upload dan download.'],
        ['feature_2_title', 'Unlimited Quota'],
        ['feature_2_desc', 'Akses internet sepuasnya tanpa batasan kuota (FUP).'],
        ['feature_3_title', 'Support 24/7'],
        ['feature_3_desc', 'Tim teknis kami siap membantu Anda kapanpun jika terjadi gangguan.'],
        ['social_facebook', '#'],
        ['social_instagram', '#'],
        ['social_twitter', '#'],
        ['social_youtube', '#']
    ];

    foreach ($siteSettings as $ss) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute($ss);
    }

    $cronSchedules = [
        ['Auto Invoice', 'auto_invoice', 'monthly', '00:00', 1],
        ['Auto Isolir', 'auto_isolir', 'daily', '00:00', 1],
        ['Payment Reminder', 'send_reminders', 'daily', '08:00', 1]
    ];
    
    foreach ($cronSchedules as $schedule) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO cron_schedules (name, task_type, schedule_days, schedule_time, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute($schedule);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GEMBOK Installer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: radial-gradient(circle at center, #1a1a2e 0%, #0a0a12 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #ffffff;
        }
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .header h1 { font-size: 2.5rem; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        .progress {
            display: flex;
            justify-content: space-between;
            padding: 20px 40px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px;
            font-size: 0.9rem;
            color: #6c757d;
            position: relative;
        }
        .step.active { color: #667eea; font-weight: 600; }
        .step.completed { color: #28a745; }
        .step::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: #667eea;
            transition: width 0.3s;
        }
        .step.active::after, .step.completed::after { width: 100%; }
        .content {
            padding: 40px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-success { background: #efe; border: 1px solid #cfc; color: #3c3; }
        .alert-info { background: #eef; border: 1px solid #ccf; color: #33c; }
        .check-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .check-item i {
            font-size: 1.5rem;
            margin-right: 15px;
        }
        .check-item.success i { color: #28a745; }
        .check-item.error i { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 GEMBOK Installer</h1>
            <p>ISP Management System - Simple Version</p>
        </div>
        
        <div class="progress">
            <?php foreach ($steps as $num => $step): ?>
                <div class="step <?php echo $num == $currentStep ? 'active' : ($num < $currentStep ? 'completed' : ''); ?>">
                    <?php echo $num . '. ' . $step['name']; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="content">
            <?php if ($installed): ?>
                <div class="alert alert-success">
                    <h3>✅ Sudah Terinstall!</h3>
                    <p>Aplikasi GEMBOK sudah terinstall di server ini.</p>
                    <p>Silakan <a href="admin/login.php">Login ke Admin Panel</a></p>
                </div>
            <?php else: ?>
                <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php
                switch ($currentStep) {
                    case 1: include 'install_steps/step1_server.php'; break;
                    case 2: include 'install_steps/step2_database.php'; break;
                    case 3: include 'install_steps/step3_admin.php'; break;
                    case 4: include 'install_steps/step6_finish.php'; break;
                }
                ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
