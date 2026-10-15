<?php
require '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ================== schema.php (disatukan) ==================
/**
 * ensureSchema()
 * Memastikan kolom/tabel tambahan yang dibutuhkan fitur baru sudah ada,
 * tanpa perlu import ulang database secara manual.
 * - transaksi.uang_bayar, transaksi.kembalian  -> untuk fitur kembalian otomatis
 * - log_aktivitas                              -> untuk notifikasi "siapa menghapus apa"
 */
function ensureSchema(mysqli $koneksi): void
{
    static $sudahDicek = false;
    if ($sudahDicek) {
        return; // cukup dicek sekali per request
    }
    $sudahDicek = true;

    // --- kolom uang_bayar & kembalian di tabel transaksi ---
    $kolom = $koneksi->query("SHOW COLUMNS FROM transaksi");
    if ($kolom) {
        $ada = [];
        while ($k = $kolom->fetch_assoc()) {
            $ada[$k['Field']] = true;
        }

        if (!isset($ada['uang_bayar'])) {
            @$koneksi->query("ALTER TABLE transaksi ADD COLUMN uang_bayar DECIMAL(12,2) NULL AFTER metode_pembayaran");
        }
        if (!isset($ada['kembalian'])) {
            @$koneksi->query("ALTER TABLE transaksi ADD COLUMN kembalian DECIMAL(12,2) NULL AFTER uang_bayar");
        }
    }

    // --- tabel log aktivitas (khusus notifikasi hapus transaksi) ---
    @$koneksi->query("
        CREATE TABLE IF NOT EXISTS log_aktivitas (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            nama_user VARCHAR(100) NOT NULL,
            role VARCHAR(20) NOT NULL,
            aksi VARCHAR(50) NOT NULL,
            keterangan VARCHAR(255) NOT NULL,
            waktu DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * catatLog()
 * Menyimpan satu baris log aktivitas (dipakai untuk notifikasi hapus transaksi)
 * dan otomatis menyiapkan pesan flash yang akan tampil di halaman berikutnya.
 */
function catatLog(mysqli $koneksi, string $aksi, string $keterangan): void
{
    ensureSchema($koneksi);

    $userId = $_SESSION['user_id'] ?? null;
    $nama   = $_SESSION['nama'] ?? 'Tidak diketahui';
    $role   = $_SESSION['role'] ?? '-';

    $stmt = $koneksi->prepare(
        "INSERT INTO log_aktivitas (user_id, nama_user, role, aksi, keterangan) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $userId, $nama, $role, $aksi, $keterangan);
    $stmt->execute();
    $stmt->close();
}

/**
 * setFlash() / ambilFlash()
 * Notifikasi singkat yang tampil sekali setelah redirect (mis. setelah hapus transaksi).
 */
function setFlash(string $tipe, string $pesan): void
{
    $_SESSION['flash'] = ['tipe' => $tipe, 'pesan' => $pesan];
}

function ambilFlash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ================== auth.php (disatukan) ==================
function cekLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function cekAdmin() {
    cekLogin();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: /kasir/dashboard.php');
        exit;
    }
}

function cekKasir() {
    cekLogin();
    
    if (!in_array($_SESSION['role'], ['kasir', 'admin'])) {
        header('Location: /login.php');
        exit;
    }
}

cekKasir();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $koneksi->prepare(
    "SELECT pj.*, pl.nama_pelanggan, t.metode_pembayaran, t.status_transaksi, t.uang_bayar, t.kembalian, u.nama AS nama_kasir
     FROM penjualan pj
     LEFT JOIN pelanggan pl ON pj.pelanggan_id = pl.pelanggan_id
     LEFT JOIN transaksi t ON t.penjualan_id = pj.penjualan_id
     LEFT JOIN users u ON pj.user_id = u.user_id
     WHERE pj.penjualan_id = ?
     LIMIT 1"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$penjualan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$penjualan) {
    die('Transaksi tidak ditemukan.');
}

if ($_SESSION['role'] === 'kasir' && (int) $penjualan['user_id'] !== (int) $_SESSION['user_id']) {
    die('Anda tidak berhak melihat struk transaksi ini.');
}

$stmt = $koneksi->prepare(
    "SELECT dp.jumlah_produk, dp.subtotal, p.nama_produk, p.harga
     FROM detail_penjualan dp
     JOIN produk p ON dp.produk_id = p.produk_id
     WHERE dp.penjualan_id = ?
     ORDER BY dp.detail_id ASC"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$detail = $stmt->get_result();
$stmt->close();

$pageTitle = 'Struk Pembayaran';
$assetPath = '../';

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $pageTitle ?? 'Kasir Coffee Shop' ?></title>
<link rel="stylesheet" href="<?= $assetPath ?? '' ?>assets/style.css">
</head>
<body>
<div class="layout">
<?php $role = $_SESSION['role'] ?? ''; $base = $assetPath ?? ''; ?>
<nav class="sidebar">
    <h2>☕ Coffee POS</h2>
    <p class="user-info"><?= htmlspecialchars($_SESSION['nama'] ?? '') ?><br><small><?= ucfirst($role) ?></small></p>
    <ul>
        <?php if ($role === 'admin'): ?>
            <li><a href="<?= $base ?>admin/dashboard.php">Dashboard</a></li>
            <li><a href="<?= $base ?>admin/produk.php">Produk</a></li>
            <li><a href="<?= $base ?>admin/transaksi.php">Transaksi</a></li>
            <li><a href="<?= $base ?>admin/laporan.php">Laporan</a></li>
            <li><a href="<?= $base ?>admin/users.php">Manajemen User</a></li>
        <?php elseif ($role === 'kasir'): ?>
            <li><a href="<?= $base ?>kasir/dashboard.php">Kasir</a></li>
            <li><a href="<?= $base ?>admin/pelanggan.php">Pelanggan</a></li>
            <li><a href="<?= $base ?>kasir/transaksi.php">Transaksi</a></li>
        <?php endif; ?>
        <li><a href="<?= $base ?>logout.php" class="logout">Keluar</a></li>
    </ul>
</nav>
<main class="content">
<?php $flash = function_exists('ambilFlash') ? ambilFlash() : null; ?>
<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['tipe'] === 'error' ? 'error' : 'success' ?> alert-flash">
        <?= htmlspecialchars($flash['pesan'], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="struk-page">
    <div class="struk-box">
        <div class="struk-header">
            <h1>☕ COFFEE SHOP</h1>
            <p>Fresh Coffee & Delicious Food</p>
            <p class="struk-alamat">Jl. Contoh Alamat No. 123, Kota &middot; 0812-0000-0000</p>
        </div>

        <div class="struk-info">
            <div>
                <span>No. Transaksi</span>
                <strong>#<?= $penjualan['penjualan_id'] ?></strong>
            </div>
            <div>
                <span>Tanggal</span>
                <strong><?= htmlspecialchars($penjualan['tanggal_penjualan'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div>
                <span>Kasir</span>
                <strong><?= htmlspecialchars($penjualan['nama_kasir'] ?? 'Kasir', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div>
                <span>Pelanggan</span>
                <strong><?= htmlspecialchars($penjualan['nama_pelanggan'] ?? 'Umum', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>

        <div class="struk-line">----------------------------------------</div>

        <table class="struk-items">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Qty</th>
                    <th>Harga</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($d = $detail->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['nama_produk'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $d['jumlah_produk'] ?></td>
                        <td>Rp <?= number_format($d['harga'], 0, ',', '.') ?></td>
                        <td>Rp <?= number_format($d['subtotal'], 0, ',', '.') ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <div class="struk-line">----------------------------------------</div>

        <div class="struk-total">
            <span>TOTAL</span>
            <strong>Rp <?= number_format($penjualan['total_harga'], 0, ',', '.') ?></strong>
        </div>

        <div class="struk-payment">
            <div>
                <span>Pembayaran</span>
                <strong><?= htmlspecialchars($penjualan['metode_pembayaran'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div>
                <span>Status</span>
                <strong><?= htmlspecialchars($penjualan['status_transaksi'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>

        <div class="struk-line">----------------------------------------</div>

        <div class="struk-footer">
            <p>Barang yang sudah dibeli/dibayar tidak dapat ditukar tanpa struk ini.</p>
            <p>Terima kasih atas kunjungan Anda ☕</p>
        </div>

        <div class="struk-actions">
            <button type="button" onclick="window.print()">Cetak Struk</button>
            <a href="dashboard.php" class="btn-cancel">Transaksi Baru</a>
        </div>
    </div>
</div>

<style>
.struk-page {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    min-height: 100vh;
    padding: 30px;
    box-sizing: border-box;
}

.struk-box {
    width: 420px;
    background: linear-gradient(180deg, #ffffff 0%, #fdfaf4 100%);
    padding: 28px;
    box-sizing: border-box;
    border-radius: 14px;
    box-shadow: 0 12px 34px rgba(30, 58, 138, 0.22), 0 3px 8px rgba(30, 58, 138, 0.12);
    color: #222222;
    font-family: Arial, sans-serif;
    border: 1px solid #dde1f5;
    border-top: 4px solid #2557c7;
    position: relative;
}

.struk-header {
    text-align: center;
    margin-bottom: 14px;
}

.struk-header h1 {
    margin: 0 0 8px;
    font-size: 24px;
    text-shadow: 0 1px 0 rgba(0,0,0,.05);
}

.struk-header p {
    margin: 4px 0;
    font-size: 12px;
    color: #666666;
}

.struk-alamat {
    font-size: 11px !important;
    color: #999999 !important;
}

.struk-info {
    font-size: 12px;
}

.struk-info div {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 7px;
}

.struk-info span {
    color: #666666;
}

.struk-info strong {
    text-align: right;
}

.struk-line {
    margin: 12px 0;
    text-align: center;
    color: #888888;
    overflow: hidden;
    white-space: nowrap;
}

.struk-items {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    font-size: 11px;
}

.struk-items th:nth-child(1), .struk-items td:nth-child(1) { width: 38%; }
.struk-items th:nth-child(2), .struk-items td:nth-child(2) { width: 12%; }
.struk-items th:nth-child(3), .struk-items td:nth-child(3) { width: 24%; }
.struk-items th:nth-child(4), .struk-items td:nth-child(4) { width: 26%; }

.struk-items th {
    padding-bottom: 8px;
    border-bottom: 1px dashed #999999;
    text-align: left;
}

.struk-items td {
    padding: 7px 3px;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
}

.struk-items th:nth-child(2),
.struk-items td:nth-child(2) {
    text-align: center;
}

.struk-items th:nth-child(3),
.struk-items td:nth-child(3),
.struk-items th:nth-child(4),
.struk-items td:nth-child(4) {
    text-align: right;
}

.struk-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 15px 0;
    font-size: 18px;
}

.struk-total strong {
    font-size: 20px;
}

.struk-payment {
    border-top: 1px dashed #999999;
    padding-top: 12px;
    font-size: 12px;
}

.struk-payment div {
    display: flex;
    justify-content: space-between;
    margin-bottom: 7px;
}

.struk-footer {
    text-align: center;
    margin-top: 6px;
    padding-top: 4px;
    font-size: 11px;
    color: #666666;
}

.struk-footer p {
    margin: 5px 0;
}

.struk-actions {
    display: flex;
    gap: 10px;
    margin-top: 25px;
}

.struk-actions button,
.struk-actions a {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 6px;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
}

.struk-actions button {
    background: #2f6fed;
    color: #ffffff;
}

.struk-actions .btn-cancel {
    background: #eeeeee;
    color: #333333;
}

@media print {
    * {
        box-shadow: none !important;
    }

    html, body {
        width: 100%;
        height: auto;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
    }

    body * {
        visibility: hidden !important;
    }

    .struk-page, .struk-page * {
        visibility: visible !important;
    }

    .struk-page {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        display: block !important;
        background: #ffffff !important;
    }

    .struk-box {
        width: 80mm !important;
        max-width: 80mm !important;
        margin: 0 auto !important;
        padding: 5mm !important;
        background: #ffffff !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }

    .struk-actions {
        display: none !important;
    }

    .struk-header h1 {
        font-size: 18px;
    }

    .struk-items {
        font-size: 10px;
    }

    .struk-total {
        font-size: 15px;
    }

    @page {
        size: 80mm auto;
        margin: 0;
    }
}
</style>

