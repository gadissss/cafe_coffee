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

// Matikan exception otomatis agar kita bisa menangkap error MySQL secara manual
mysqli_report(MYSQLI_REPORT_OFF);

$msg = '';
$errorMsg = '';

// ========================================
// 1. PROSES HAPUS SEMUA PELANGGAN
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_semua'])) {
    // Lepas dulu relasi pelanggan_id di tabel penjualan menjadi NULL
    $koneksi->query("UPDATE penjualan SET pelanggan_id = NULL");

    // Hapus seluruh pelanggan
    if ($koneksi->query("DELETE FROM pelanggan")) {
        $msg = 'Semua data pelanggan berhasil dihapus.';
    } else {
        $errorMsg = 'Gagal menghapus semua pelanggan: ' . $koneksi->error;
    }
}

// ========================================
// 2. PROSES HAPUS SATU PELANGGAN
// ========================================
if (isset($_GET['hapus'])) {
    $id = (int) $_GET['hapus'];
    
    if ($id > 0) {
        // Opik/Solusi: Lepas relasi di penjualan terlebih dahulu agar tidak gagal Foreign Key
        $stmtUpdate = $koneksi->prepare("UPDATE penjualan SET pelanggan_id = NULL WHERE pelanggan_id = ?");
        $stmtUpdate->bind_param('i', $id);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        // Eksekusi Hapus Pelanggan
        $stmt = $koneksi->prepare("DELETE FROM pelanggan WHERE pelanggan_id = ?");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $msg = 'Pelanggan berhasil dihapus.';
            } else {
                $errorMsg = 'Data pelanggan dengan ID tersebut tidak ditemukan.';
            }
        } else {
            $errorMsg = 'Gagal menghapus: ' . $stmt->error;
        }
        $stmt->close();
    }
}

// ========================================
// 3. AMBIL DATA SETELAH PROSES HAPUS
// ========================================
$cari = trim($_GET['cari'] ?? '');

if ($cari !== '') {
    $stmt = $koneksi->prepare("SELECT * FROM pelanggan WHERE nama_pelanggan LIKE ? ORDER BY pelanggan_id DESC");
    $like = '%' . $cari . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
} else {
    $list = $koneksi->query("SELECT * FROM pelanggan ORDER BY pelanggan_id DESC");
}

$pageTitle = 'Kelola Pelanggan';
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

<h1>Kelola Pelanggan</h1>

<?php if ($msg): ?>
    <div style="background-color: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-radius: 4px; border: 1px solid #c3e6cb;">
        <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div style="background-color: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-radius: 4px; border: 1px solid #f5c6cb;">
        <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<h2>Daftar Pelanggan</h2>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
    <form method="GET" class="filter-form filter-form-rect" style="margin: 0;">
        <input type="text" name="cari" placeholder="Cari nama pelanggan..." value="<?= htmlspecialchars($cari, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Cari</button>
        <?php if ($cari !== ''): ?>
            <a href="pelanggan.php" class="btn-cancel">Reset</a>
        <?php endif; ?>
    </form>

    <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin MENGHAPUS SEMUA data pelanggan?');" style="margin: 0;">
        <button type="submit" name="hapus_semua" style="background-color: #dc3545; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">
            Hapus Semua Pelanggan
        </button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Nama Pelanggan</th>
            <th>Aksi</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($list && $list->num_rows > 0): ?>
            <?php while ($p = $list->fetch_assoc()): ?>
                <tr>
                    <td><?= $p['pelanggan_id'] ?></td>
                    <td><?= htmlspecialchars($p['nama_pelanggan'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <a href="pelanggan.php?hapus=<?= $p['pelanggan_id'] ?>" onclick="return confirm('Hapus pelanggan ini?')">Hapus</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="3" style="text-align: center;">Tidak ada data pelanggan.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

