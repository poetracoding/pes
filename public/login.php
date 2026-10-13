<?php
declare(strict_types=1);
require __DIR__ . '/../app/web.php';

if (is_admin()) {
    redirect('/admin/index.php');
}

$setup = !admin_exists();   // belum ada admin -> halaman pembuatan akun pertama
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($setup) {
        $confirm = (string) ($_POST['confirm'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
            $error = 'Username 3–30 karakter: huruf, angka, titik, minus, atau garis bawah.';
        } elseif (strlen($password) < 8) {
            $error = 'Password minimal 8 karakter.';
        } elseif ($password !== $confirm) {
            $error = 'Konfirmasi password tidak sama.';
        } else {
            db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int) db()->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $id;
            flash('Akun admin dibuat. Silakan mulai menambahkan tim.');
            redirect('/admin/tim.php');
        }
    } elseif (login_blocked()) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    } else {
        $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([$username]);
        $admin = $st->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            redirect('/admin/index.php');
        }
        login_failed();
        $error = 'Username atau password salah.';
    }
}

page_header($setup ? 'Buat Akun Admin' : 'Login Admin', 'login');
?>
<div class="card narrow">
  <h1><?= $setup ? 'Buat Akun Admin' : 'Login Admin' ?></h1>
  <?php if ($setup): ?>
    <p class="muted">Ini pertama kali aplikasi dipakai. Buat akun admin untuk mengatur tim dan skor.</p>
  <?php endif; ?>
  <?php if ($error): ?><div class="flash err" role="alert"><?= h($error) ?></div><?php endif; ?>

  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= h($_POST['username'] ?? '') ?>" required autofocus autocomplete="username">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>">
      <?php if ($setup): ?><div class="hint">Minimal 8 karakter.</div><?php endif; ?>
    </div>
    <?php if ($setup): ?>
      <div class="field">
        <label for="confirm">Ulangi password</label>
        <input type="password" id="confirm" name="confirm" required autocomplete="new-password">
      </div>
    <?php endif; ?>
    <button><?= $setup ? 'Buat Akun & Masuk' : 'Masuk' ?></button>
  </form>
</div>
<?php page_footer();
