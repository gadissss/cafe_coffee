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

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'tambah') {
    $stmt = $koneksi->prepare("INSERT INTO produk (nama_produk, harga, Stok) VALUES (?, ?, ?)");
    $stmt->bind_param('sdi', $_POST['nama_produk'], $_POST['harga'], $_POST['stok']);
    $stmt->execute();
    $msg = 'Produk berhasil ditambahkan.';
}

// EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'edit') {
    $stmt = $koneksi->prepare("UPDATE produk SET nama_produk=?, harga=?, Stok=? WHERE produk_id=?");
    $stmt->bind_param('sdii', $_POST['nama_produk'], $_POST['harga'], $_POST['stok'], $_POST['produk_id']);
    $stmt->execute();
    $msg = 'Produk berhasil diperbarui.';
}

// HAPUS
if (isset($_GET['hapus'])) {
    $id = (int) $_GET['hapus'];
    $stmt = $koneksi->prepare("DELETE FROM produk WHERE produk_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $msg = 'Produk berhasil dihapus.';
}

$editData = null;
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $koneksi->prepare("SELECT * FROM produk WHERE produk_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}

$cari = trim($_GET['cari'] ?? '');
if ($cari !== '') {
    $stmt = $koneksi->prepare("SELECT * FROM produk WHERE nama_produk LIKE ? ORDER BY produk_id DESC");
    $like = '%' . $cari . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $produkList = $stmt->get_result();
} else {
    $produkList = $koneksi->query("SELECT * FROM produk ORDER BY produk_id DESC");
}

$pageTitle = 'Kelola Produk';
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
<h1>Kelola Produk</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
    <h2 style="margin: 0;">Daftar Produk</h2>
    <button type="button" class="btn-tambah" onclick="bukaModalProduk()">
        <span class="icon-plus">+</span> Tambah Produk
    </button>
</div>

<form method="GET" class="filter-form">
    <input type="text" name="cari" placeholder="Cari nama produk..." value="<?= htmlspecialchars($cari) ?>">
    <button type="submit">Cari</button>
    <?php if ($cari !== ''): ?><a href="produk.php" class="btn-cancel">Reset</a><?php endif; ?>
</form>
<table>
    <tr><th>ID</th><th>Nama</th><th>Harga</th><th>Stok</th><th>Aksi</th></tr>
    <?php while ($p = $produkList->fetch_assoc()): ?>
    <tr>
        <td><?= $p['produk_id'] ?></td>
        <td><?= htmlspecialchars($p['nama_produk']) ?></td>
        <td>Rp <?= number_format($p['harga'],0,',','.') ?></td>
        <td><?= $p['Stok'] ?></td>
        <td>
            <a href="?edit=<?= $p['produk_id'] ?>">Edit</a> |
            <a href="?hapus=<?= $p['produk_id'] ?>" onclick="return confirm('Hapus produk ini?')">Hapus</a>
        </td>
    </tr>
    <?php endwhile; ?>
</table>

<!-- MODAL TAMBAH / EDIT PRODUK -->
<div id="modalProduk" class="modal-overlay" style="display: <?= $editData ? 'flex' : 'none' ?>;">
    <div class="modal-box">
        <h2 id="modalProdukTitle"><?= $editData ? 'Edit Produk' : 'Tambah Produk' ?></h2>
        <form method="POST">
            <input type="hidden" name="aksi" value="<?= $editData ? 'edit' : 'tambah' ?>">
            <?php if ($editData): ?>
                <input type="hidden" name="produk_id" value="<?= $editData['produk_id'] ?>">
            <?php endif; ?>
            <label>Nama Produk</label>
            <input type="text" name="nama_produk" required value="<?= htmlspecialchars($editData['nama_produk'] ?? '') ?>">
            <label>Harga</label>
            <input type="number" step="0.01" name="harga" required value="<?= $editData['harga'] ?? '' ?>">
            <label>Stok</label>
            <input type="number" name="stok" required value="<?= $editData['Stok'] ?? '' ?>">
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px;">
                <?php if ($editData): ?>
                    <a href="produk.php" class="btn-cancel">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn-cancel-modal" onclick="tutupModalProduk()">Batal</button>
                <?php endif; ?>
                <button type="submit"><?= $editData ? 'Simpan Perubahan' : 'Tambah' ?></button>
            </div>
        </form>
    </div>
</div>

<style>
.btn-tambah {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(180deg, var(--brand) 0%, var(--brand-mid) 100%);
    color: #fff; border: none; padding: 10px 18px; border-radius: 8px;
    cursor: pointer; font-size: 14px; font-weight: 600; box-shadow: var(--shadow-md);
}
.btn-tambah:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
.icon-plus {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,.25);
    font-size: 14px; line-height: 1;
}
.modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(49,46,129,.35); justify-content: center; align-items: center; z-index: 9999;
}
.modal-box {
    background: #fff; padding: 26px; border-radius: 14px; width: 400px; max-width: 90%;
    box-shadow: var(--shadow-lg); border-top: 3px solid var(--brand);
}
.modal-box h2 { margin-top: 0; color: var(--brand-dark); }
.btn-cancel-modal {
    background: #fff; color: var(--brand-dark); border: 1px solid #d9deef; padding: 11px 16px;
    border-radius: 8px; cursor: pointer; font-size: 14px;
}
</style>

<script>
function bukaModalProduk() {
    document.getElementById('modalProduk').style.display = 'flex';
}
function tutupModalProduk() {
    document.getElementById('modalProduk').style.display = 'none';
}
</script>

