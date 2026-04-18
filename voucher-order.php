<?php
if (!file_exists(__DIR__ . '/includes/installed.lock')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ensurePublicVoucherTables();

$appName = getSetting('app_name', 'GEMBOK');
$defaultGateway = strtolower((string) getSetting('DEFAULT_PAYMENT_GATEWAY', 'tripay'));
if (!in_array($defaultGateway, ['tripay', 'midtrans', 'duitku', 'xendit'], true)) {
    $defaultGateway = 'tripay';
}

$paymentMethods = [];
if ($defaultGateway === 'tripay') {
    $paymentMethods = [
        ['code' => 'QRIS', 'name' => 'QRIS'],
        ['code' => 'BCAVA', 'name' => 'BCA Virtual Account'],
        ['code' => 'BRIVA', 'name' => 'BRI Virtual Account'],
        ['code' => 'MANDIRIVA', 'name' => 'Mandiri Virtual Account'],
        ['code' => 'BNIVA', 'name' => 'BNI Virtual Account'],
        ['code' => 'OVO', 'name' => 'OVO'],
        ['code' => 'DANA', 'name' => 'DANA'],
        ['code' => 'LINKAJA', 'name' => 'LinkAja'],
        ['code' => 'SHOPEEPAY', 'name' => 'ShopeePay'],
        ['code' => 'ALFAMART', 'name' => 'Alfamart'],
        ['code' => 'INDOMARET', 'name' => 'Indomaret']
    ];
} elseif ($defaultGateway === 'midtrans') {
    $paymentMethods = [
        ['code' => 'qris', 'name' => 'QRIS'],
        ['code' => 'gopay', 'name' => 'GoPay'],
        ['code' => 'bca_va', 'name' => 'BCA Virtual Account'],
        ['code' => 'bri_va', 'name' => 'BRI Virtual Account'],
        ['code' => 'mandiri_va', 'name' => 'Mandiri Virtual Account'],
        ['code' => 'bni_va', 'name' => 'BNI Virtual Account'],
        ['code' => 'permata_va', 'name' => 'Permata Virtual Account']
    ];
} elseif ($defaultGateway === 'duitku') {
    $paymentMethods = [
        ['code' => 'AUTO', 'name' => 'Otomatis'],
        ['code' => 'QR', 'name' => 'QRIS'],
        ['code' => 'VC', 'name' => 'Virtual Account'],
        ['code' => 'DA', 'name' => 'E-Wallet']
    ];
} else {
    $paymentMethods = [
        ['code' => 'AUTO', 'name' => 'Otomatis']
    ];
}

$catalog = getPublicVoucherCatalog();
$errorMessage = '';
$oldName = '';
$oldPhone = '';
$oldProfile = '';
$oldMethod = '';
$oldTos = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Token keamanan tidak valid.';
    } else {
        $oldName = trim((string) ($_POST['customer_name'] ?? ''));
        $oldPhone = trim((string) ($_POST['customer_phone'] ?? ''));
        $oldProfile = trim((string) ($_POST['profile_name'] ?? ''));
        $oldMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $oldTos = isset($_POST['agree_tos']);

        if ($oldName === '' || $oldPhone === '' || $oldProfile === '') {
            $errorMessage = 'Semua field wajib diisi.';
        } elseif (!$oldTos) {
            $errorMessage = 'Anda wajib menyetujui Syarat & Ketentuan.';
        } else {
            $selected = findPublicVoucherPackage($catalog, $oldProfile);
            if (!$selected) {
                $errorMessage = 'Paket voucher tidak valid.';
            } else {
                $orderResult = createPublicVoucherOrder([
                    'customer_name' => $oldName,
                    'customer_phone' => $oldPhone,
                    'profile_name' => $selected['profile_name'],
                    'amount' => (int) $selected['price'],
                    'payment_gateway' => $defaultGateway,
                    'payment_method' => $oldMethod
                ]);
                if ($orderResult['success'] ?? false) {
                    $usePrettySetting = (string) getSetting('USE_PRETTY_URLS', '1') === '1';
                    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
                    $canPretty = $usePrettySetting && preg_match('~^/voucher(/|$)~', (string) $requestPath);
                    $statusUrl = rtrim(APP_URL, '/') . ($canPretty
                        ? ('/voucher/status/' . rawurlencode($orderResult['order_number']))
                        : ('/voucher-status.php?order=' . rawurlencode($orderResult['order_number']))
                    );
                    header('Location: ' . $statusUrl);
                    exit;
                }
                $errorMessage = $orderResult['message'] ?? 'Gagal membuat order voucher.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Order Voucher Hotspot - <?php echo htmlspecialchars($appName); ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        .container { max-width: 860px; margin: 0 auto; padding: 24px; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .title { font-size: 28px; margin: 0 0 8px; color: #67e8f9; }
        .subtitle { color: #94a3b8; margin: 0 0 20px; }
        .label { display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 14px; }
        .input, .select { width: 100%; box-sizing: border-box; background: #0b1220; color: #e2e8f0; border: 1px solid #334155; border-radius: 8px; padding: 12px; font-size: 14px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .btn { width: 100%; border: 0; border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 600; color: #0f172a; background: #67e8f9; cursor: pointer; }
        .error { background: #7f1d1d; border: 1px solid #ef4444; color: #fecaca; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
        .helper { color: #94a3b8; font-size: 13px; margin-top: 8px; }
        .empty { background: #7c2d12; border: 1px solid #fb923c; color: #ffedd5; padding: 12px; border-radius: 8px; }
        .voucher-packages { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 8px; }
        .package-card { position: relative; display: block; border: 1px solid #334155; border-radius: 10px; background: #0b1220; padding: 12px; cursor: pointer; transition: all 0.2s; }
        .package-card:hover { border-color: #67e8f9; transform: translateY(-1px); }
        .package-card input { position: absolute; opacity: 0; pointer-events: none; }
        .package-title { font-size: 14px; font-weight: 700; color: #e2e8f0; margin: 0 0 6px; }
        .package-price { font-size: 13px; color: #67e8f9; font-weight: 600; margin: 0 0 4px; }
        .package-validity { font-size: 12px; color: #94a3b8; margin: 0; }
        .package-card.selected { border-color: #67e8f9; box-shadow: 0 0 0 1px rgba(103, 232, 249, 0.5) inset; }
        .tos-toggle { margin-top: 14px; border: 1px solid #334155; border-radius: 8px; background: #0b1220; }
        .tos-toggle summary { list-style: none; cursor: pointer; padding: 12px; color: #cbd5e1; font-size: 13px; font-weight: 600; }
        .tos-toggle summary::-webkit-details-marker { display: none; }
        .tos-box { border-top: 1px solid #334155; padding: 12px; line-height: 1.5; color: #cbd5e1; font-size: 13px; max-height: 220px; overflow: auto; }
        .tos-check { display: flex; align-items: flex-start; gap: 10px; margin-top: 10px; color: #cbd5e1; font-size: 13px; }
        .tos-check input { margin-top: 2px; }
        @media (max-width: 720px) { .grid { grid-template-columns: 1fr; } .voucher-packages { grid-template-columns: repeat(2, minmax(0, 1fr)); } .package-card { padding: 10px; } }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px;">
            <a href="<?php echo htmlspecialchars(rtrim(APP_URL, '/') . '/index.php'); ?>" style="color:#94a3b8;text-decoration:none;font-size:13px;">← Kembali</a>
            <a href="<?php echo htmlspecialchars(rtrim(APP_URL, '/') . '/index.php#packages'); ?>" style="color:#67e8f9;text-decoration:none;font-size:13px;">Lihat Paket</a>
        </div>
        <h1 class="title">Voucher Hotspot</h1>
        <p class="subtitle">Voucher akan dikirim ke WhatsApp.</p>
        <?php if ($errorMessage !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <?php if (empty($catalog)): ?>
            <div class="empty">Paket voucher belum tersedia. Pastikan profile hotspot di MikroTik memiliki harga jual.</div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="grid">
                    <div>
                        <label class="label">Nama Pembeli</label>
                        <input class="input" type="text" name="customer_name" required value="<?php echo htmlspecialchars($oldName); ?>" placeholder="Nama lengkap">
                    </div>
                    <div>
                        <label class="label">Nomor WhatsApp</label>
                        <input class="input" type="text" name="customer_phone" required value="<?php echo htmlspecialchars($oldPhone); ?>" placeholder="08xxxxxxxxxx">
                    </div>
                </div>
                <div style="margin-top: 14px;">
                    <label class="label">Paket Voucher</label>
                    <div class="voucher-packages">
                        <?php foreach ($catalog as $index => $item): ?>
                            <?php $checked = $oldProfile === $item['profile_name']; ?>
                            <label class="package-card <?php echo $checked ? 'selected' : ''; ?>">
                                <input type="radio" name="profile_name" value="<?php echo htmlspecialchars($item['profile_name']); ?>" <?php echo $checked ? 'checked' : ''; ?> <?php echo $index === 0 ? 'required' : ''; ?>>
                                <p class="package-title"><?php echo htmlspecialchars($item['display_name']); ?></p>
                                <p class="package-price"><?php echo htmlspecialchars(formatCurrency($item['price'])); ?></p>
                                <p class="package-validity">Masa aktif: <?php echo htmlspecialchars($item['validity']); ?></p>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="margin-top: 14px;">
                    <label class="label">Metode Pembayaran (<?php echo strtoupper(htmlspecialchars($defaultGateway)); ?>)</label>
                    <select class="select" name="payment_method">
                        <option value="">Pilih metode otomatis</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <?php $selected = $oldMethod === $method['code'] ? 'selected' : ''; ?>
                            <option value="<?php echo htmlspecialchars($method['code']); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($method['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <details class="tos-toggle">
                    <summary>Lihat Syarat & Ketentuan (TOS)</summary>
                    <div class="tos-box">
                        Dengan melakukan order, pembeli menyetujui bahwa transaksi diproses melalui payment gateway pihak ketiga dan data transaksi disimpan untuk keperluan verifikasi pembayaran.<br><br>
                        Voucher hanya akan digenerate setelah status pembayaran dinyatakan lunas. Kode voucher dikirim ke nomor WhatsApp yang didaftarkan dan juga ditampilkan di halaman status order.<br><br>
                        Pembeli wajib memastikan nomor WhatsApp aktif dan benar. Kesalahan input nomor menjadi tanggung jawab pembeli.<br><br>
                        Voucher yang sudah terbit dianggap terkirim dan tidak dapat dibatalkan/refund kecuali ada gangguan sistem yang dapat dibuktikan pada sisi penjual.
                    </div>
                </details>
                <label class="tos-check">
                    <input type="checkbox" name="agree_tos" value="1" <?php echo $oldTos ? 'checked' : ''; ?> required>
                    <span>Saya menyetujui Syarat & Ketentuan pembelian voucher hotspot.</span>
                </label>
                <p class="helper">Setelah submit, Anda akan diarahkan ke halaman status order dengan tombol bayar.</p>
                <button class="btn" type="submit">Buat Order Voucher</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<script>
document.querySelectorAll('.package-card input[name="profile_name"]').forEach(function (input) {
    input.addEventListener('change', function () {
        document.querySelectorAll('.package-card').forEach(function (card) {
            card.classList.remove('selected');
        });
        if (input.checked) {
            input.closest('.package-card')?.classList.add('selected');
        }
    });
});
</script>
</body>
</html>
