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

// Filter tanggal
$dari = $_GET['dari'] ?? '';
$sampai = $_GET['sampai'] ?? '';

// Query transaksi
if ($dari !== '' && $sampai !== '') {

    $stmt = $koneksi->prepare("
        SELECT 
            pj.penjualan_id,
            pj.tanggal_penjualan,
            pj.total_harga,
            COALESCE(pl.nama_pelanggan, 'Umum') AS nama_pelanggan,
            t.metode_pembayaran,
            t.status_transaksi
        FROM penjualan pj
        LEFT JOIN pelanggan pl 
            ON pj.pelanggan_id = pl.pelanggan_id
        LEFT JOIN transaksi t 
            ON t.penjualan_id = pj.penjualan_id
        WHERE pj.tanggal_penjualan BETWEEN ? AND ?
        ORDER BY pj.tanggal_penjualan DESC, pj.penjualan_id DESC
    ");

    $stmt->bind_param('ss', $dari, $sampai);
    $stmt->execute();

    $hasil = $stmt->get_result();

    // Total pendapatan sesuai tanggal
    $totalStmt = $koneksi->prepare("
        SELECT COALESCE(SUM(total_harga), 0) AS t
        FROM penjualan
        WHERE tanggal_penjualan BETWEEN ? AND ?
    ");

    $totalStmt->bind_param('ss', $dari, $sampai);
    $totalStmt->execute();

} else {

    // Jika belum memilih tanggal, tampilkan semua transaksi
    $stmt = $koneksi->prepare("
        SELECT 
            pj.penjualan_id,
            pj.tanggal_penjualan,
            pj.total_harga,
            COALESCE(pl.nama_pelanggan, 'Umum') AS nama_pelanggan,
            t.metode_pembayaran,
            t.status_transaksi
        FROM penjualan pj
        LEFT JOIN pelanggan pl 
            ON pj.pelanggan_id = pl.pelanggan_id
        LEFT JOIN transaksi t 
            ON t.penjualan_id = pj.penjualan_id
        ORDER BY pj.tanggal_penjualan DESC, pj.penjualan_id DESC
    ");

    $stmt->execute();

    $hasil = $stmt->get_result();

    // Total semua pendapatan
    $totalStmt = $koneksi->prepare("
        SELECT COALESCE(SUM(total_harga), 0) AS t
        FROM penjualan
    ");

    $totalStmt->execute();
}

$grandTotal = $totalStmt->get_result()->fetch_assoc()['t'];

$pageTitle = 'Laporan Penjualan';
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

<h1 class="no-print">Laporan Penjualan</h1>

<form method="GET" class="filter-form no-print">

    <label for="dari">
        Tanggal Dari
    </label>

    <input
        type="date"
        name="dari"
        id="dari"
        value="<?= htmlspecialchars($dari) ?>"
    >

    <label for="sampai">
        Sampai
    </label>

    <input
        type="date"
        name="sampai"
        id="sampai"
        value="<?= htmlspecialchars($sampai) ?>"
    >

    <button type="submit">
        Tampilkan
    </button>

    <a href="laporan_penjualan.php">
        <button type="button">
            Reset
        </button>
    </a>

    <button type="button" onclick="window.print()">
        Cetak Laporan
    </button>

</form>

<!-- Kop nota, hanya tampil saat dicetak -->
<div class="nota-kop">
    <h1>☕ COFFEE SHOP</h1>
    <p>Jl. Contoh Alamat No. 123, Kota &middot; 0812-0000-0000</p>
    <div class="nota-title">NOTA LAPORAN PENJUALAN</div>
    <div class="nota-meta">
        <span>
            Periode:
            <strong>
                <?php if ($dari !== '' && $sampai !== ''): ?>
                    <?= htmlspecialchars($dari) ?> s/d <?= htmlspecialchars($sampai) ?>
                <?php else: ?>
                    Semua Transaksi
                <?php endif; ?>
            </strong>
        </span>
        <span>Dicetak: <strong><?= date('d/m/Y H:i') ?></strong></span>
        <span>Oleh: <strong><?= htmlspecialchars($_SESSION['nama'] ?? '-') ?></strong></span>
    </div>
</div>

<p class="grand-total no-print">

    <?php if ($dari !== '' && $sampai !== ''): ?>

        Periode:
        <strong>
            <?= htmlspecialchars($dari) ?>
            s/d
            <?= htmlspecialchars($sampai) ?>
        </strong>

    <?php else: ?>

        Periode:
        <strong>Semua Transaksi</strong>

    <?php endif; ?>

    &nbsp;|&nbsp;

    Total Pendapatan:
    <strong>
        Rp <?= number_format($grandTotal, 0, ',', '.') ?>
    </strong>

    &nbsp;|&nbsp;

    Jumlah Transaksi:
    <strong>
        <?= $hasil->num_rows ?>
    </strong>

</p>

<h2>Detail Transaksi</h2>

<table>

    <tr>
        <th>ID</th>
        <th>Tanggal</th>
        <th>Pelanggan</th>
        <th>Total</th>
        <th>Metode Bayar</th>
        <th>Status</th>
    </tr>

    <?php if ($hasil->num_rows === 0): ?>

        <tr>
            <td colspan="6">
                Tidak ada transaksi pada periode tersebut.
            </td>
        </tr>

    <?php endif; ?>

    <?php while ($r = $hasil->fetch_assoc()): ?>

        <tr>

            <td>
                <?= htmlspecialchars($r['penjualan_id']) ?>
            </td>

            <td>
                <?= htmlspecialchars($r['tanggal_penjualan']) ?>
            </td>

            <td>
                <?= htmlspecialchars($r['nama_pelanggan']) ?>
            </td>

            <td>
                Rp <?= number_format($r['total_harga'], 0, ',', '.') ?>
            </td>

            <td>
                <?= htmlspecialchars($r['metode_pembayaran'] ?? '-') ?>
            </td>

            <td>
                <?= htmlspecialchars($r['status_transaksi'] ?? '-') ?>
            </td>

        </tr>

    <?php endwhile; ?>

</table>

<!-- Ringkasan & tanda tangan nota, hanya tampil saat dicetak -->
<div class="nota-ringkasan">
    <div class="nota-ringkasan-baris">
        <span>Jumlah Transaksi</span>
        <strong><?= $hasil->num_rows ?></strong>
    </div>
    <div class="nota-ringkasan-baris total">
        <span>TOTAL PENDAPATAN</span>
        <strong>Rp <?= number_format($grandTotal, 0, ',', '.') ?></strong>
    </div>
</div>

<div class="nota-ttd">
    <div class="nota-ttd-kolom">
        <span>Mengetahui,</span>
        <div class="nota-ttd-garis"></div>
        <strong>Admin</strong>
    </div>
    <div class="nota-ttd-kolom">
        <span>Dibuat oleh,</span>
        <div class="nota-ttd-garis"></div>
        <strong><?= htmlspecialchars($_SESSION['nama'] ?? '-') ?></strong>
    </div>
</div>

<style>
.nota-kop { display: none; }
.nota-ringkasan, .nota-ttd { display: none; }

@media print {
    .no-print, .sidebar, .layout > .sidebar { display: none !important; }
    .content { padding: 0 !important; }
    body { background: #fff !important; }

    .nota-kop {
        display: block; text-align: center; margin-bottom: 14px;
        border-bottom: 3px double #333; padding-bottom: 12px;
    }
    .nota-kop h1 { margin: 0 0 4px; font-size: 22px; color: #333; }
    .nota-kop p { margin: 2px 0; font-size: 11px; color: #555; }
    .nota-title {
        margin-top: 10px; font-weight: bold; font-size: 14px; letter-spacing: 1px;
        text-decoration: underline;
    }
    .nota-meta {
        display: flex; justify-content: space-between; margin-top: 10px; font-size: 11px; color: #333;
    }

    table { box-shadow: none !important; border: 1px solid #333 !important; border-radius: 0 !important; }
    table th, table td { border: 1px solid #333 !important; font-size: 11px; }
    table th { background: #eee !important; }
    table tr:nth-child(even) { background: #fff !important; }

    .nota-ringkasan {
        display: block; margin-top: 10px; width: 280px; margin-left: auto; font-size: 12px;
    }
    .nota-ringkasan-baris { display: flex; justify-content: space-between; padding: 4px 8px; border: 1px solid #333; border-top: none; }
    .nota-ringkasan-baris.total { font-weight: bold; font-size: 14px; border-top: 2px solid #333; }

    .nota-ttd {
        display: flex; justify-content: space-between; margin-top: 50px; font-size: 12px; text-align: center;
    }
    .nota-ttd-kolom { width: 200px; }
    .nota-ttd-garis { height: 50px; border-bottom: 1px solid #333; margin: 4px 0; }
}
</style>


