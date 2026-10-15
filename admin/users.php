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

mysqli_report(MYSQLI_REPORT_OFF);

$ROLE_MAP = [1 => 'admin', 2 => 'kasir'];

$msg = '';
$errorMsg = '';

// ========================================
// 1. TAMBAH USER
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'tambah') {
    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleKode = (int) ($_POST['role'] ?? 2);

    if (empty($nama) || empty($username) || empty($password)) {
        $errorMsg = 'Semua field wajib diisi untuk menambah user.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $koneksi->prepare("INSERT INTO users (nama, username, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $nama, $username, $hash, $roleKode);

        if ($stmt->execute()) {
            $msg = 'User berhasil ditambahkan.';
        } else {
            $errorMsg = 'Gagal menambahkan: Username mungkin sudah dipakai.';
        }
        $stmt->close();
    }
}

// ========================================
// 2. EDIT USER
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'edit') {
    $userIdEdit = (int) $_POST['user_id'];
    $nama = trim($_POST['nama'] ?? '');
    $roleKode = (int) ($_POST['role'] ?? 2);
    $password = $_POST['password'] ?? '';

    if (empty($nama)) {
        $errorMsg = 'Nama lengkap tidak boleh kosong.';
    } else {
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $koneksi->prepare("UPDATE users SET nama = ?, role = ?, password = ? WHERE user_id = ?");
            $stmt->bind_param('sisi', $nama, $roleKode, $hash, $userIdEdit);
        } else {
            $stmt = $koneksi->prepare("UPDATE users SET nama = ?, role = ? WHERE user_id = ?");
            $stmt->bind_param('sii', $nama, $roleKode, $userIdEdit);
        }

        if ($stmt->execute()) {
            $msg = 'Data user berhasil diperbarui.';
        } else {
            $errorMsg = 'Gagal memperbarui data user.';
        }
        $stmt->close();
    }
}

// ========================================
// 3. HAPUS USER
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus') {
    $id = (int) $_POST['user_id'];

    if ($id === (int) $_SESSION['user_id']) {
        $errorMsg = 'Tidak bisa menghapus akun yang sedang digunakan untuk login saat ini.';
    } elseif ($id > 0) {
        $stmt = $koneksi->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $msg = 'User berhasil dihapus.';
            } else {
                $errorMsg = 'Data user tidak ditemukan.';
            }
        } else {
            $errorMsg = 'Gagal menghapus: User ini memiliki riwayat data transaksi di sistem.';
        }
        $stmt->close();
    }
}

// AMBIL SEMUA LIST USER
$list = $koneksi->query("SELECT * FROM users ORDER BY user_id DESC");

$pageTitle = 'Kelola User / Kasir';
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

<h1>Kelola User &amp; Hak Akses</h1>

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

<!-- HEADER DAFTAR USER + BUTTON TAMBAH -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
    <h2 style="margin: 0;">Daftar User</h2>
    
    <button type="button" onclick="bukaModalTambah()" style="background-color: #2f6fed; color: #ffffff; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;">
        + Tambah User
    </button>
</div>

<!-- TABEL DAFTAR USER -->
<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr style="background-color: #f8f9fa; border-bottom: 2px solid #dee2e6;">
            <th style="padding: 10px; text-align: left;">ID</th>
            <th style="padding: 10px; text-align: left;">Nama</th>
            <th style="padding: 10px; text-align: left;">Username</th>
            <th style="padding: 10px; text-align: left;">Role</th>
            <th style="padding: 10px; text-align: center; width: 180px;">Aksi</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($list && $list->num_rows > 0): ?>
            <?php while ($u = $list->fetch_assoc()): 
                $roleKode = (int) $u['role'];
                $roleNama = $ROLE_MAP[$roleKode] ?? 'kasir';
            ?>
                <tr style="border-bottom: 1px solid #dee2e6;">
                    <td style="padding: 10px;"><?= $u['user_id'] ?></td>
                    <td style="padding: 10px;"><?= htmlspecialchars($u['nama'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding: 10px;"><?= htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding: 10px;">
                        <span class="badge badge-<?= $roleNama ?>">
                            <?= ucfirst($roleNama) ?> (<?= $roleKode ?>)
                        </span>
                    </td>
                    <td style="padding: 10px; text-align: center;">
                        <!-- CONTAINER SEJAJAR PRESISI DENGAN INLINE-FLEX -->
                        <div style="display: inline-flex; flex-direction: row; align-items: center; justify-content: center; gap: 6px; width: 100%;">
                            <!-- BUTTON EDIT -->
                            <button 
                                type="button" 
                                onclick="bukaModalEdit(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['nama'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>', <?= $roleKode ?>)"
                                style="background-color: #2f6fed; color: #ffffff; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; line-height: 1.2; display: inline-block; white-space: nowrap;"
                            >
                                Edit
                            </button>

                            <!-- BUTTON HAPUS DENGAN FORM DIBUAT INLINE -->
                            <form method="POST" action="users.php" onsubmit="return confirm('Apakah Anda yakin ingin menghapus user ini?');" style="display: inline-block; margin: 0; padding: 0;">
                                <input type="hidden" name="aksi" value="hapus">
                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                <button 
                                    type="submit" 
                                    style="background-color: #523826; color: #ffffff; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; line-height: 1.2; display: inline-block; white-space: nowrap;"
                                >
                                    Hapus
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 15px;">Belum ada data user.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- MODAL POP-UP 1: TAMBAH USER -->
<div id="modalTambah" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 9999;">
    <div style="background: #ffffff; padding: 25px; border-radius: 8px; width: 400px; max-width: 90%; box-shadow: 0 4px 10px rgba(0,0,0,0.2);">
        <h2 style="margin-top: 0; color: #2f6fed;">Tambah User Baru</h2>
        
        <form method="POST" action="users.php">
            <input type="hidden" name="aksi" value="tambah">

            <label for="tambah_nama">Nama Lengkap</label>
            <input type="text" id="tambah_nama" name="nama" placeholder="Masukkan nama lengkap" required style="width: 100%; margin-bottom: 10px;">

            <label for="tambah_username">Username</label>
            <input type="text" id="tambah_username" name="username" placeholder="Masukkan username" required style="width: 100%; margin-bottom: 10px;">

            <label for="tambah_password">Password</label>
            <input type="password" id="tambah_password" name="password" placeholder="Masukkan password" required style="width: 100%; margin-bottom: 10px;">

            <label for="tambah_role">Role / Hak Akses</label>
            <select id="tambah_role" name="role" required style="width: 100%; margin-bottom: 15px;">
                <option value="2">Kasir (2)</option>
                <option value="1">Admin (1)</option>
            </select>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="tutupModalTambah()" style="background: #6c757d; color: #ffffff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">
                    Batal
                </button>
                <button type="submit" style="background: #2f6fed; color: #ffffff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">
                    Simpan User
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modalEdit" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 9999;">
    <div style="background: #ffffff; padding: 25px; border-radius: 8px; width: 400px; max-width: 90%; box-shadow: 0 4px 10px rgba(0,0,0,0.2);">
        <h2 style="margin-top: 0; color: #2f6fed;">Edit Data User</h2>
        
        <form method="POST" action="users.php">
            <input type="hidden" name="aksi" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">

            <label for="edit_nama">Nama Lengkap</label>
            <input type="text" id="edit_nama" name="nama" required style="width: 100%; margin-bottom: 10px;">

            <p style="margin: 8px 0; font-size: 13px; color: #666666;">
                <strong>Username:</strong> <span id="edit_username_text"></span> <em>(Tidak dapat diubah)</em>
            </p>

            <label for="edit_password">Password Baru</label>
            <input type="password" id="edit_password" name="password" placeholder="Kosongkan jika tidak diubah" style="width: 100%; margin-bottom: 10px;">

            <label for="edit_role">Role / Hak Akses</label>
            <select id="edit_role" name="role" required style="width: 100%; margin-bottom: 15px;">
                <option value="1">Admin (1)</option>
                <option value="2">Kasir (2)</option>
            </select>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="tutupModalEdit()" style="background: #6c757d; color: #ffffff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">
                    Batal
                </button>
                <button type="submit" style="background: #2f6fed; color: #ffffff; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function bukaModalTambah() {
    document.getElementById('modalTambah').style.display = 'flex';
}
function tutupModalTambah() {
    document.getElementById('modalTambah').style.display = 'none';
}

function bukaModalEdit(id, nama, username, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_nama').value = nama;
    document.getElementById('edit_username_text').innerText = username;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_password').value = '';
    
    document.getElementById('modalEdit').style.display = 'flex';
}
function tutupModalEdit() {
    document.getElementById('modalEdit').style.display = 'none';
}
</script>

