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

$userId = $_SESSION['user_id'];

$tanggalDari = $_GET['tanggal_dari'] ?? '';
$tanggalSampai = $_GET['tanggal_sampai'] ?? '';

if (isset($_POST['hapus_semua'])) {

    // Ambil semua penjualan milik kasir ini
    $stmt = $koneksi->prepare("
        SELECT penjualan_id
        FROM penjualan
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $resultHapus = $stmt->get_result();

    $jumlahDihapus = $resultHapus->num_rows;

    while ($row = $resultHapus->fetch_assoc()) {
        $penjualanId = $row['penjualan_id'];

        // Hapus transaksi yang terkait
        $stmtTransaksi = $koneksi->prepare("
            DELETE FROM transaksi
            WHERE penjualan_id = ?
        ");
        $stmtTransaksi->bind_param('i', $penjualanId);
        $stmtTransaksi->execute();
        $stmtTransaksi->close();

        // Jika ada tabel detail penjualan,
        // hapus detailnya juga.
        $stmtDetail = $koneksi->prepare("
            DELETE FROM detail_penjualan
            WHERE penjualan_id = ?
        ");
        $stmtDetail->bind_param('i', $penjualanId);
        $stmtDetail->execute();
        $stmtDetail->close();
    }

    // Hapus semua penjualan milik kasir
    $stmtDelete = $koneksi->prepare("
        DELETE FROM penjualan
        WHERE user_id = ?
    ");
    $stmtDelete->bind_param('i', $userId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if ($jumlahDihapus > 0) {
        catatLog(
            $koneksi,
            'Hapus Transaksi',
            sprintf('Menghapus %d transaksi miliknya sendiri (hapus semua)', $jumlahDihapus)
        );
    }

    setFlash('success', $jumlahDihapus . ' transaksi berhasil dihapus oleh ' . ($_SESSION['nama'] ?? '-') . '.');

    header('Location: transaksi.php');
    exit;
}

$sql = "
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
    WHERE pj.user_id = ?
";

$params = [$userId];
$types = 'i';

if (!empty($tanggalDari)) {
    $sql .= " AND DATE(pj.tanggal_penjualan) >= ?";
    $params[] = $tanggalDari;
    $types .= 's';
}

if (!empty($tanggalSampai)) {
    $sql .= " AND DATE(pj.tanggal_penjualan) <= ?";
    $params[] = $tanggalSampai;
    $types .= 's';
}

$sql .= " ORDER BY pj.penjualan_id DESC";

$stmt = $koneksi->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result();

$pageTitle = 'Transaksi Saya';
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

<h1>Transaksi</h1>

<!-- FILTER TANGGAL -->
<form method="GET" style="margin-bottom: 20px;">

    <label>
        Dari tanggal:
        <input 
            type="date" 
            name="tanggal_dari"
            value="<?= htmlspecialchars($tanggalDari) ?>"
        >
    </label>

    <label style="margin-left: 10px;">
        Sampai tanggal:
        <input 
            type="date" 
            name="tanggal_sampai"
            value="<?= htmlspecialchars($tanggalSampai) ?>"
        >
    </label>

    <button type="submit">
        Filter
    </button>

    <a href="transaksi.php">
        <button type="button">
            Reset
        </button>
    </a>

</form>


<!-- TOMBOL HAPUS SEMUA -->
<form method="POST" 
      onsubmit="return confirm('Yakin ingin menghapus SEMUA transaksi Anda? Data yang sudah dihapus tidak dapat dikembalikan.');"
      style="margin-bottom: 20px;">

    <button 
        type="submit" 
        name="hapus_semua"
        style="background:#dc3545;color:white;border:none;padding:10px 15px;border-radius:5px;cursor:pointer;"
    >
        Hapus Semua Transaksi
    </button>

</form>


<table>
    <tr>
        <th>No.</th>
        <th>Tanggal</th>
        <th>Pelanggan</th>
        <th>Total</th>
        <th>Metode</th>
        <th>Status</th>
        <th>Aksi</th>
    </tr>

    <?php if ($list->num_rows === 0): ?>

        <tr>
            <td colspan="7">
                Tidak ada transaksi.
            </td>
        </tr>

    <?php endif; ?>


    <?php while ($r = $list->fetch_assoc()): ?>

        <tr>
            <td>
                #<?= $r['penjualan_id'] ?>
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

            <td>
                <a href="struk.php?id=<?= $r['penjualan_id'] ?>">
                    Lihat / Cetak
                </a>
            </td>
        </tr>

    <?php endwhile; ?>

</table>


