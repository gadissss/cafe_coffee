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

$status = $_GET['status'] ?? '';
$cari   = trim($_GET['cari'] ?? '');

// HAPUS TRANSAKSI (per baris) + catat siapa yang menghapus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_transaksi'])) {
    $idHapus = (int) $_POST['hapus_transaksi'];

    $stmt = $koneksi->prepare("
        SELECT pj.total_harga, COALESCE(pl.nama_pelanggan, 'Umum') AS nama_pelanggan
        FROM penjualan pj
        LEFT JOIN pelanggan pl ON pj.pelanggan_id = pl.pelanggan_id
        WHERE pj.penjualan_id = ?
    ");
    $stmt->bind_param('i', $idHapus);
    $stmt->execute();
    $dataHapus = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dataHapus) {
        $koneksi->begin_transaction();
        try {
            $s1 = $koneksi->prepare("DELETE FROM transaksi WHERE penjualan_id = ?");
            $s1->bind_param('i', $idHapus);
            $s1->execute();
            $s1->close();

            $s2 = $koneksi->prepare("DELETE FROM detail_penjualan WHERE penjualan_id = ?");
            $s2->bind_param('i', $idHapus);
            $s2->execute();
            $s2->close();

            $s3 = $koneksi->prepare("DELETE FROM penjualan WHERE penjualan_id = ?");
            $s3->bind_param('i', $idHapus);
            $s3->execute();
            $s3->close();

            $koneksi->commit();

            catatLog(
                $koneksi,
                'Hapus Transaksi',
                sprintf(
                    'Menghapus transaksi #%d (Rp %s, pelanggan: %s)',
                    $idHapus,
                    number_format($dataHapus['total_harga'], 0, ',', '.'),
                    $dataHapus['nama_pelanggan']
                )
            );

            setFlash('success', 'Transaksi #' . $idHapus . ' berhasil dihapus oleh ' . ($_SESSION['nama'] ?? '-') . '.');
        } catch (Exception $e) {
            $koneksi->rollback();
            setFlash('error', 'Gagal menghapus transaksi #' . $idHapus . '.');
        }
    } else {
        setFlash('error', 'Transaksi tidak ditemukan.');
    }

    header('Location: transaksi.php');
    exit;
}

// Ambil log penghapusan terbaru untuk ditampilkan sebagai notifikasi riwayat
$logHapus = $koneksi->query("
    SELECT nama_user, role, keterangan, waktu
    FROM log_aktivitas
    WHERE aksi = 'Hapus Transaksi'
    ORDER BY waktu DESC, log_id DESC
    LIMIT 10
");

$sql = "
    SELECT pj.penjualan_id, pj.tanggal_penjualan, pj.total_harga,
           COALESCE(pl.nama_pelanggan, 'Umum') AS nama_pelanggan,
           COALESCE(u.nama, '-') AS nama_kasir,
           t.metode_pembayaran, t.status_transaksi
    FROM penjualan pj
    LEFT JOIN pelanggan pl ON pj.pelanggan_id = pl.pelanggan_id
    LEFT JOIN users u ON pj.user_id = u.user_id
    LEFT JOIN transaksi t ON t.penjualan_id = pj.penjualan_id
    WHERE 1=1
";
$params = [];
$types = '';

if ($status !== '') {
    $sql .= " AND t.status_transaksi = ?";
    $params[] = $status;
    $types .= 's';
}
if ($cari !== '') {
    $sql .= " AND (pl.nama_pelanggan LIKE ? OR pj.penjualan_id = ?)";
    $params[] = '%' . $cari . '%';
    $params[] = is_numeric($cari) ? (int) $cari : 0;
    $types .= 'si';
}
$sql .= " ORDER BY pj.penjualan_id DESC";

$stmt = $koneksi->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$list = $stmt->get_result();

$pageTitle = 'Daftar Transaksi';
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
<h1>Daftar Transaksi</h1>

<form method="GET" class="filter-form">
    <input type="text" name="cari" placeholder="Cari No. Transaksi / nama pelanggan..." value="<?= htmlspecialchars($cari) ?>">
    <select name="status">
        <option value="">Semua Status</option>
        <option value="Lunas" <?= $status === 'Lunas' ? 'selected' : '' ?>>Lunas</option>
    </select>
    <button type="submit">Filter</button>
    <?php if ($cari !== '' || $status !== ''): ?><a href="transaksi.php" class="btn-cancel">Reset</a><?php endif; ?>
</form>

<table>
    <tr><th>No.</th><th>Tanggal</th><th>Pelanggan</th><th>Kasir</th><th>Total</th><th>Metode</th><th>Status</th><th>Aksi</th></tr>
    <?php if ($list->num_rows === 0): ?>
        <tr><td colspan="8">Tidak ada transaksi.</td></tr>
    <?php endif; ?>
    <?php while ($r = $list->fetch_assoc()): ?>
    <tr>
        <td>#<?= $r['penjualan_id'] ?></td>
        <td><?= $r['tanggal_penjualan'] ?></td>
        <td><?= htmlspecialchars($r['nama_pelanggan']) ?></td>
        <td><?= htmlspecialchars($r['nama_kasir']) ?></td>
        <td>Rp <?= number_format($r['total_harga'],0,',','.') ?></td>
        <td><?= htmlspecialchars($r['metode_pembayaran'] ?? '-') ?></td>
        <td><?= htmlspecialchars($r['status_transaksi'] ?? '-') ?></td>
        <td>
            <a href="../kasir/struk.php?id=<?= $r['penjualan_id'] ?>">Lihat / Cetak</a>
            &nbsp;|&nbsp;
            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus transaksi #<?= $r['penjualan_id'] ?>? Tindakan ini akan tercatat dan tidak dapat dibatalkan.');">
                <input type="hidden" name="hapus_transaksi" value="<?= $r['penjualan_id'] ?>">
                <button type="submit" class="btn-danger">Hapus</button>
            </form>
        </td>
    </tr>
    <?php endwhile; ?>
</table>

