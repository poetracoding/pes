<?php
declare(strict_types=1);
require __DIR__ . '/../../app/web.php';
require_admin();

$st = db()->prepare('SELECT * FROM admins WHERE id = ?');
$st->execute([$_SESSION['admin_id']]);
$admin = $st->fetch();
if (!$admin) {
    redirect('/login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['new'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    if (!password_verify($current, $admin['password_hash'])) {
        $error = 'Password saat ini salah.';
    } elseif (strlen($new) < 8) {
        $error = 'Password baru minimal 8 karakter.';
    } elseif ($new !== $confirm) {
        $error = 'Konfirmasi password baru tidak sama.';
    } else {
        db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        session_regenerate_id(true);
        flash('Password diperbarui.');
        redirect('/admin/akun.php');
    }
}

page_header('Akun', 'admin-akun');
?>
<div class="card narrow">
  <h1>Akun Admin</h1>
  <p class="muted">Login sebagai <b><?= h($admin['username']) ?></b>.</p>
  <?php if ($error): ?><div class="flash err" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label for="current">Password saat ini</label>
      <input type="password" id="current" name="current" required autocomplete="current-password"></div>
    <div class="field"><label for="new">Password baru</label>
      <input type="password" id="new" name="new" required autocomplete="new-password"><div class="hint">Minimal 8 karakter.</div></div>
    <div class="field"><label for="confirm">Ulangi password baru</label>
      <input type="password" id="confirm" name="confirm" required autocomplete="new-password"></div>
    <button>Ganti Password</button>
  </form>
</div>
<?php page_footer();
