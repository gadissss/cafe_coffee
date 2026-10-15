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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$keranjang = json_decode($_POST['keranjang_json'] ?? '[]', true);
$namaPelanggan = trim($_POST['nama_pelanggan'] ?? '');
$metode = $_POST['metode_pembayaran'] ?? 'Tunai';
$uangBayarInput = isset($_POST['uang_bayar']) && $_POST['uang_bayar'] !== ''
    ? (float) $_POST['uang_bayar']
    : null;

$statusInput = in_array($_POST['status_transaksi'] ?? '', ['Lunas', 'Pending'], true)
    ? $_POST['status_transaksi']
    : 'Lunas';

$userId = (int) $_SESSION['user_id'];

if (!is_array($keranjang) || empty($keranjang)) {
    die('Keranjang kosong.');
}

$koneksi->begin_transaction();

try {
    $pelangganId = null;

    if ($namaPelanggan !== '') {
        $stmt = $koneksi->prepare(
            "SELECT pelanggan_id 
             FROM pelanggan 
             WHERE nama_pelanggan = ? 
             LIMIT 1"
        );
        $stmt->bind_param('s', $namaPelanggan);
        $stmt->execute();
        $result = $stmt->get_result();
        $pelanggan = $result->fetch_assoc();
        $stmt->close();

        if ($pelanggan) {
            $pelangganId = (int) $pelanggan['pelanggan_id'];
        } else {
            $stmt = $koneksi->prepare(
                "INSERT INTO pelanggan (nama_pelanggan) 
                 VALUES (?)"
            );
            $stmt->bind_param('s', $namaPelanggan);
            $stmt->execute();
            $pelangganId = $koneksi->insert_id;
            $stmt->close();
        }
    }

    $total = 0;

    foreach ($keranjang as $item) {
        $pid = (int) ($item['produk_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);

        if ($pid <= 0 || $qty <= 0) {
            throw new Exception('Data produk atau jumlah produk tidak valid.');
        }

        $stmt = $koneksi->prepare(
            "SELECT harga, Stok 
             FROM produk 
             WHERE produk_id = ? 
             FOR UPDATE"
        );
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $result = $stmt->get_result();
        $produk = $result->fetch_assoc();
        $stmt->close();

        if (!$produk) {
            throw new Exception('Produk tidak ditemukan.');
        }

        if ((int) $produk['Stok'] < $qty) {
            throw new Exception('Stok tidak mencukupi untuk salah satu produk.');
        }

        $total += (float) $produk['harga'] * $qty;
    }

    $uangBayar = null;
    $kembalian = null;

    if ($metode === 'Tunai') {
        if ($uangBayarInput === null || $uangBayarInput < $total) {
            throw new Exception('Uang yang dibayarkan kurang dari total belanja.');
        }
        $uangBayar = $uangBayarInput;
        $kembalian = $uangBayarInput - $total;
    }

    $stmt = $koneksi->prepare(
        "INSERT INTO penjualan (tanggal_penjualan, total_harga, pelanggan_id, user_id) 
         VALUES (CURDATE(), ?, ?, ?)"
    );
    $stmt->bind_param('dii', $total, $pelangganId, $userId);
    $stmt->execute();
    $penjualanId = $koneksi->insert_id;
    $stmt->close();

    foreach ($keranjang as $item) {
        $pid = (int) $item['produk_id'];
        $qty = (int) $item['qty'];

        $stmt = $koneksi->prepare(
            "SELECT harga 
             FROM produk 
             WHERE produk_id = ?"
        );
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $result = $stmt->get_result();
        $produk = $result->fetch_assoc();
        $stmt->close();

        $harga = (float) $produk['harga'];
        $subtotal = $harga * $qty;

        $stmt = $koneksi->prepare(
            "INSERT INTO detail_penjualan (penjualan_id, produk_id, jumlah_produk, subtotal) 
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('iiid', $penjualanId, $pid, $qty, $subtotal);
        $stmt->execute();
        $stmt->close();

        $stmt = $koneksi->prepare(
            "UPDATE produk 
             SET Stok = Stok - ? 
             WHERE produk_id = ?"
        );
        $stmt->bind_param('ii', $qty, $pid);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $koneksi->prepare(
        "INSERT INTO transaksi (penjualan_id, tanggal_transaksi, metode_pembayaran, status_transaksi, uang_bayar, kembalian) 
         VALUES (?, CURDATE(), ?, ?, ?, ?)"
    );
    $stmt->bind_param('issdd', $penjualanId, $metode, $statusInput, $uangBayar, $kembalian);
    $stmt->execute();
    $stmt->close();

    $koneksi->commit();

    header('Location: struk.php?id=' . $penjualanId);
    exit;

} catch (Exception $e) {
    $koneksi->rollback();

    die('Transaksi gagal: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}