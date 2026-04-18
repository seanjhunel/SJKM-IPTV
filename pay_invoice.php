<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment.php';

ensureInvoicesPaymentLinkColumns();

$appName = defined('APP_NAME') ? (string) APP_NAME : 'Pembayaran';
$appUrl = defined('APP_URL') ? (string) APP_URL : '';

function payInvoiceRender($title, $contentHtml)
{
    $safeTitle = htmlspecialchars((string) $title);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . $safeTitle . '</title>';
    echo '<style>
        body{font-family:Segoe UI,Arial,sans-serif;background:#0a0a12;color:#fff;margin:0;padding:20px}
        .wrap{max-width:520px;margin:40px auto}
        .card{background:#161628;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:18px}
        .title{font-size:18px;font-weight:700;margin:0 0 10px}
        .muted{color:#b0b0c0}
        .row{display:flex;justify-content:space-between;gap:10px;margin:6px 0}
        .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}
        .ok{background:rgba(0,255,136,.15);color:#00ff88;border:1px solid rgba(0,255,136,.35)}
        .err{background:rgba(255,71,87,.12);color:#ff4757;border:1px solid rgba(255,71,87,.35)}
        .btn{display:inline-block;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700}
        .btn-primary{background:#00f5ff;color:#000}
        .btn-secondary{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.12)}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
        a{color:#00f5ff}
    </style></head><body><div class="wrap"><div class="card">';
    echo $contentHtml;
    echo '</div></div></body></html>';
}

$invoiceNumber = trim((string) ($_GET['inv'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));

if ($invoiceNumber === '' || $token === '') {
    header('HTTP/1.1 400 Bad Request');
    payInvoiceRender('Link pembayaran tidak valid', '<div class="title">Link pembayaran tidak valid</div><div class="muted">Pastikan Anda membuka link yang benar.</div>');
    exit;
}

$invoice = fetchOne("
    SELECT i.*, c.name as customer_name, c.phone as customer_phone
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.invoice_number = ?
    LIMIT 1
", [$invoiceNumber]);

if (!$invoice) {
    header('HTTP/1.1 404 Not Found');
    payInvoiceRender('Invoice tidak ditemukan', '<div class="title">Invoice tidak ditemukan</div><div class="muted">Invoice sudah dihapus atau nomor invoice tidak valid.</div>');
    exit;
}

$expected = invoicePayToken($invoice['invoice_number']);
if (!hash_equals($expected, $token)) {
    header('HTTP/1.1 403 Forbidden');
    payInvoiceRender('Link tidak valid', '<div class="title">Link pembayaran tidak valid</div><div class="muted">Link ini tidak sesuai atau sudah kadaluarsa.</div>');
    exit;
}

if (($invoice['status'] ?? '') === 'paid') {
    $content = '<div class="title">Invoice sudah lunas <span class="badge ok">Lunas</span></div>';
    $content .= '<div class="row"><span class="muted">Invoice</span><span><strong>' . htmlspecialchars((string) $invoice['invoice_number']) . '</strong></span></div>';
    $content .= '<div class="row"><span class="muted">Nama</span><span>' . htmlspecialchars((string) ($invoice['customer_name'] ?? '-')) . '</span></div>';
    $content .= '<div class="row"><span class="muted">Total</span><span>' . htmlspecialchars(formatCurrency((float) $invoice['amount'])) . '</span></div>';
    $paidAt = (string) ($invoice['paid_at'] ?? '');
    if ($paidAt !== '') {
        $content .= '<div class="row"><span class="muted">Dibayar</span><span>' . htmlspecialchars(formatDate($paidAt, 'd M Y H:i')) . '</span></div>';
    }
    $home = $appUrl !== '' ? rtrim($appUrl, '/') . '/' : '/';
    $content .= '<div class="actions"><a class="btn btn-secondary" href="' . htmlspecialchars($home) . '">Kembali</a></div>';
    payInvoiceRender('Invoice Lunas', $content);
    exit;
}
if (($invoice['status'] ?? '') === 'cancelled') {
    $content = '<div class="title">Invoice dibatalkan <span class="badge err">Batal</span></div>';
    $content .= '<div class="row"><span class="muted">Invoice</span><span><strong>' . htmlspecialchars((string) $invoice['invoice_number']) . '</strong></span></div>';
    $home = $appUrl !== '' ? rtrim($appUrl, '/') . '/' : '/';
    $content .= '<div class="actions"><a class="btn btn-secondary" href="' . htmlspecialchars($home) . '">Kembali</a></div>';
    payInvoiceRender('Invoice Dibatalkan', $content);
    exit;
}

$now = time();
$existingLink = trim((string) ($invoice['payment_link'] ?? ''));
$expiresAt = (string) ($invoice['payment_link_expires_at'] ?? '');
if ($existingLink !== '' && $expiresAt !== '') {
    $expTs = strtotime($expiresAt);
    if ($expTs !== false && $expTs > $now) {
        header('Location: ' . $existingLink);
        exit;
    }
}

$gateway = getSetting('DEFAULT_PAYMENT_GATEWAY', 'tripay');
if (!in_array($gateway, ['tripay', 'midtrans', 'duitku', 'xendit'], true)) {
    $gateway = 'tripay';
}

$defaultMethod = '';
if ($gateway === 'tripay') {
    $defaultMethod = 'QRIS';
} elseif ($gateway === 'midtrans') {
    $defaultMethod = 'qris';
}

$orderId = (string) ($invoice['payment_order_id'] ?? '');
if ($orderId === '') {
    $orderId = (string) $invoice['invoice_number'];
}
$orderId = $orderId . '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$result = generatePaymentLink(
    (string) $invoice['invoice_number'],
    (float) $invoice['amount'],
    (string) ($invoice['customer_name'] ?? '-'),
    (string) ($invoice['customer_phone'] ?? ''),
    (string) $invoice['due_date'],
    $gateway,
    $defaultMethod,
    $orderId
);

if (!($result['success'] ?? false) || trim((string) ($result['link'] ?? '')) === '') {
    header('HTTP/1.1 500 Internal Server Error');
    $content = '<div class="title">Gagal membuat link pembayaran <span class="badge err">Gagal</span></div>';
    $content .= '<div class="muted">Silakan coba lagi beberapa saat. Jika masih gagal, hubungi admin.</div>';
    $home = $appUrl !== '' ? rtrim($appUrl, '/') . '/' : '/';
    $content .= '<div class="actions"><a class="btn btn-secondary" href="' . htmlspecialchars($home) . '">Kembali</a></div>';
    payInvoiceRender('Gagal', $content);
    exit;
}

$link = (string) $result['link'];
$data = $result['data'] ?? null;
$reference = null;
if (is_array($data)) {
    if ($gateway === 'tripay' && isset($data['reference'])) {
        $reference = (string) $data['reference'];
    }
    if ($gateway === 'midtrans' && isset($data['token'])) {
        $reference = (string) $data['token'];
    }
    if ($gateway === 'duitku' && isset($data['reference'])) {
        $reference = (string) $data['reference'];
    }
    if ($gateway === 'xendit') {
        if (isset($data['id'])) {
            $reference = (string) $data['id'];
        } elseif (isset($data['invoice_id'])) {
            $reference = (string) $data['invoice_id'];
        }
    }
}

$expiresTs = $now + (24 * 60 * 60);
$dueTs = strtotime((string) $invoice['due_date']);
if ($dueTs !== false && $dueTs > $now) {
    $expiresTs = min($expiresTs, $dueTs);
}

update('invoices', [
    'payment_gateway' => $gateway,
    'payment_order_id' => $orderId,
    'payment_link' => $link,
    'payment_reference' => $reference,
    'payment_payload' => is_array($data) ? json_encode($data) : null,
    'payment_link_created_at' => date('Y-m-d H:i:s'),
    'payment_link_expires_at' => date('Y-m-d H:i:s', $expiresTs),
    'updated_at' => date('Y-m-d H:i:s')
], 'id = ?', [(int) $invoice['id']]);

logActivity('INVOICE_PAYMENT_LINK', "Invoice: {$invoiceNumber}, Gateway: {$gateway}");

header('Location: ' . $link);
exit;
