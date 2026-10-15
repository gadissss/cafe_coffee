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
cekAdmin();

$totalProduk    = $koneksi->query("SELECT COUNT(*) c FROM produk")->fetch_assoc()['c'];
$totalPelanggan = $koneksi->query("SELECT COUNT(*) c FROM pelanggan")->fetch_assoc()['c'];
$totalPenjualanHariIni = $koneksi->query("SELECT COALESCE(SUM(total_harga),0) t FROM penjualan WHERE tanggal_penjualan = CURDATE()")->fetch_assoc()['t'];
$totalTransaksi = $koneksi->query("SELECT COUNT(*) c FROM penjualan")->fetch_assoc()['c'];
$totalPendapatan = $koneksi->query("SELECT COALESCE(SUM(total_harga),0) t FROM penjualan")->fetch_assoc()['t'];
$stokMenipis = $koneksi->query("SELECT nama_produk, Stok FROM produk WHERE Stok <= 10 ORDER BY Stok ASC");

$pageTitle = 'Dashboard Admin';
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
<h1>Dashboard Admin</h1>
<div class="cards">
    <div class="card"><h3>Total Produk</h3><p><?= $totalProduk ?></p></div>
    <div class="card"><h3>Total Pelanggan</h3><p><?= $totalPelanggan ?></p></div>
    <div class="card"><h3>Penjualan Hari Ini</h3><p>Rp <?= number_format($totalPenjualanHariIni,0,',','.') ?></p></div>
    <div class="card"><h3>Total Transaksi</h3><p><?= $totalTransaksi ?></p></div>
    <div class="card"><h3>Total Pendapatan</h3><p>Rp <?= number_format($totalPendapatan,0,',','.') ?></p></div>
</div>

<h2>Stok Menipis (≤ 10)</h2>
<table>
    <tr><th>Produk</th><th>Stok</th></tr>
    <?php if ($stokMenipis->num_rows === 0): ?>
        <tr><td colspan="2">Semua stok aman.</td></tr>
    <?php endif; ?>
    <?php while ($row = $stokMenipis->fetch_assoc()): ?>
        <tr><td><?= htmlspecialchars($row['nama_produk']) ?></td><td><?= $row['Stok'] ?></td></tr>
    <?php endwhile; ?>
</table>

