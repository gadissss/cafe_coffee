<?php
require '../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function ensureSchema(mysqli $koneksi): void
{
    static $sudahDicek = false;
    if ($sudahDicek) {
        return; 
    }
    $sudahDicek = true;

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

$produkResult = $koneksi->query(
    "SELECT produk_id, nama_produk, harga, Stok FROM produk WHERE Stok > 0 ORDER BY nama_produk ASC"
);
$produkArr = [];
while ($p = $produkResult->fetch_assoc()) {
    $produkArr[] = [
        'id'    => (int) $p['produk_id'],
        'nama'  => $p['nama_produk'],
        'harga' => (float) $p['harga'],
        'stok'  => (int) $p['Stok'],
    ];
}

$pageTitle = 'Kasir - POS';$assetPath = '../';

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

<h1>Buat Pesanan</h1>

<div class="transaksi-card">
    <form id="formTransaksi" action="proses_transaksi.php" method="POST">
        <label for="nama_pelanggan">Nama Pelanggan</label>
        <input
            type="text"
            id="nama_pelanggan"
            name="nama_pelanggan"
            class="input-pelanggan"
            placeholder="Masukkan nama pelanggan"
            autocomplete="off"
        >

        <label>Produk Pembelian</label>
        <div id="produkRows"></div>

        <button type="button" class="btn-tambah-produk" onclick="tambahBarisProduk()">+ Tambah Produk</button>

        <div class="total-bar">
            <span>Total Harga</span>
            <strong id="totalHarga">Rp 0</strong>
        </div>

        <div class="pembayaran-card">
            <h2>Pembayaran</h2>

            <label for="metode_pembayaran">Metode Pembayaran</label>
            <select id="metode_pembayaran" name="metode_pembayaran" required onchange="toggleTunai()">
                <option value="" disabled selected>Pilih Pembayaran</option>
                <option value="Tunai">Tunai</option>
            </select>

            <label for="uang_bayar">Uang Dibayar</label>
            <input
                type="number"
                id="uang_bayar"
                name="uang_bayar"
                min="0"
                step="500"
                placeholder="Masukkan jumlah uang dari pelanggan"
                oninput="hitungKembalian()"
            >

            <div class="kembalian-box" id="kembalianBox">
                <div class="baris">
                    <span>Uang Dibayar</span>
                    <strong id="ringkasBayar">Rp 0</strong>
                </div>
                <div class="baris kembali-nilai" id="barisKembali">
                    <span>Kembalian</span>
                    <strong id="ringkasKembali">Rp 0</strong>
                </div>
            </div>

            <label for="status_transaksi">Status Transaksi</label>
            <select id="status_transaksi" name="status_transaksi" required>
                <option value="Lunas">Selesai</option>
            </select>
        </div>

        <input type="hidden" name="keranjang_json" id="keranjangJson">

        <button type="submit" id="btnBayar" class="btn-proses" disabled>
            Proses Pembayaran
        </button>
    </form>
</div>

<script>
const produkList = <?= json_encode($produkArr, JSON_UNESCAPED_UNICODE) ?>;

let baris = [{ produkId: '', qty: 1 }];

function formatRupiah(angka) {
    return 'Rp ' + Math.round(angka).toLocaleString('id-ID');
}

function cariProduk(id) {
    return produkList.find(p => String(p.id) === String(id));
}

function tambahBarisProduk() {
    baris.push({ produkId: '', qty: 1 });
    render();
}

function hapusBaris(i) {
    baris.splice(i, 1);
    if (baris.length === 0) {
        baris.push({ produkId: '', qty: 1 });
    }
    render();
}

function updateRowProduk(i, val) {
    baris[i].produkId = val;
    baris[i].qty = 1;
    render();
}

function ubahQtyBaris(i, delta) {
    const p = cariProduk(baris[i].produkId);
    const stokMax = p ? p.stok : 999;
    let qty = baris[i].qty + delta;
    if (qty < 1) qty = 1;
    if (qty > stokMax) {
        qty = stokMax;
        alert('Stok tidak mencukupi.');
    }
    baris[i].qty = qty;
    render();
}

function render() {
    const cont = document.getElementById('produkRows');
    cont.innerHTML = baris.map((row, i) => {
        const opsi = produkList.map(p =>
            `<option value="${p.id}" ${String(row.produkId) === String(p.id) ? 'selected' : ''}>${p.nama} - ${formatRupiah(p.harga)}</option>`
        ).join('');

        return `
        <div class="produk-row">
            <select onchange="updateRowProduk(${i}, this.value)">
                <option value="" disabled ${row.produkId === '' ? 'selected' : ''}>Pilih Produk</option>
                ${opsi}
            </select>
            <div class="qty-stepper">
                <button type="button" onclick="ubahQtyBaris(${i}, -1)">&minus;</button>
                <span>${row.qty}</span>
                <button type="button" onclick="ubahQtyBaris(${i}, 1)">&plus;</button>
            </div>
            <button type="button" class="btn-hapus-baris" onclick="hapusBaris(${i})" title="Hapus produk ini">&times;</button>
        </div>`;
    }).join('');

    hitungTotal();
}

function getTotal() {
    let total = 0;
    baris.forEach(row => {
        const p = cariProduk(row.produkId);
        if (p) total += p.harga * row.qty;
    });
    return total;
}

function hitungTotal() {
    document.getElementById('totalHarga').innerText = formatRupiah(getTotal());
    hitungKembalian();
}

function toggleTunai() {
    const metode = document.getElementById('metode_pembayaran').value;
    const isTunai = metode === 'Tunai';
    document.getElementById('uang_bayar').disabled = !isTunai;
    document.getElementById('kembalianBox').style.display = isTunai ? 'block' : 'none';
    hitungKembalian();
}

function hitungKembalian() {
    const total = getTotal();
    const metode = document.getElementById('metode_pembayaran').value;
    const isTunai = metode === 'Tunai';
    const bayarInput = document.getElementById('uang_bayar');
    const bayar = parseFloat(bayarInput.value) || 0;
    const kembali = bayar - total;

    document.getElementById('ringkasBayar').innerText = formatRupiah(bayar);

    const barisKembali = document.getElementById('barisKembali');
    const ringkasKembali = document.getElementById('ringkasKembali');

    if (kembali < 0) {
        barisKembali.classList.add('kurang');
        barisKembali.querySelector('span').innerText = 'Kurang';
        ringkasKembali.innerText = formatRupiah(Math.abs(kembali));
    } else {
        barisKembali.classList.remove('kurang');
        barisKembali.querySelector('span').innerText = 'Kembalian';
        ringkasKembali.innerText = formatRupiah(kembali);
    }

    const adaBarang = baris.some(row => row.produkId !== '' && row.qty > 0);
    const cukupBayar = !isTunai || (bayar > 0 && kembali >= 0);
    document.getElementById('btnBayar').disabled = !adaBarang || !cukupBayar;
}

document.getElementById('formTransaksi').addEventListener('submit', function (e) {
    const metode = document.getElementById('metode_pembayaran').value;
    const total = getTotal();
    const bayar = parseFloat(document.getElementById('uang_bayar').value) || 0;

    const payload = baris
        .filter(row => row.produkId !== '' && row.qty > 0)
        .map(row => {
            const p = cariProduk(row.produkId);
            return { produk_id: row.produkId, qty: row.qty, harga: p ? p.harga : 0 };
        });

    if (payload.length === 0) {
        e.preventDefault();
        alert('Pilih minimal satu produk terlebih dahulu.');
        return;
    }

    if (metode === 'Tunai' && bayar < total) {
        e.preventDefault();
        alert('Uang yang dibayarkan kurang dari total belanja.');
        return;
    }

    document.getElementById('keranjangJson').value = JSON.stringify(payload);
});

render();
</script>

