<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$ROLE_MAP = [1 => 'admin', 2 => 'kasir'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $levelPilihan = isset($_POST['level']) ? (int) $_POST['level'] : 0; // 1 atau 2

    if ($username === '' || $password === '' || $levelPilihan === 0) {
        $error = 'Username, password, dan level wajib diisi.';
    } else {
        $stmt = $koneksi->prepare("SELECT user_id, nama, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Username atau password salah.';
        } elseif ((int) $user['role'] !== $levelPilihan) {
            $error = 'Level yang dipilih tidak sesuai dengan akun ini.';
        } else {
            $roleName = $ROLE_MAP[(int) $user['role']] ?? 'kasir';

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['nama']    = $user['nama'];
            $_SESSION['role']    = $roleName; // tetap string 'admin'/'kasir' dipakai di seluruh halaman lain

            if ($roleName === 'admin') {
                header('Location: admin/dashboard.php');
            } else {
                header('Location: kasir/dashboard.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Login - Kasir Coffee Shop</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
    <div class="login-box">
        <h1>☕ Coffee Shop</h1>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <label>Level</label>
            <select name="level" required>
                <option value="">-- Pilih Level --</option>
                <option value="1" <?= ((int) ($_POST['level'] ?? 0) === 1) ? 'selected' : '' ?>>Admin</option>
                <option value="2" <?= ((int) ($_POST['level'] ?? 0) === 2) ? 'selected' : '' ?>>Kasir</option>
            </select>
            <label>Username</label>
            <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            <label>Password</label>
            <input type="password" name="password" required>
            <button type="submit">Masuk</button>
        </form>
    </div>
</body>
</html>
