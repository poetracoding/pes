<?php
declare(strict_types=1);
require __DIR__ . '/../../app/web.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'demo') {
        flash(seed_demo() ? '32 tim contoh ditambahkan ke 8 grup.' : 'Data contoh hanya bisa diisi saat belum ada tim.', 'ok');
    } elseif ($action === 'reset_scores' || $action === 'reset_all') {
        if (trim((string) ($_POST['confirm'] ?? '')) !== 'RESET') {
            flash('Ketik RESET (huruf besar) untuk mengonfirmasi.', 'err');
        } elseif ($action === 'reset_scores') {
            reset_scores();
            flash('Semua skor dihapus. Tim dan jadwal tetap ada.');
        } else {
            reset_all();
            flash('Semua tim, jadwal, dan skor dihapus.');
        }
    }
    redirect('/admin/index.php');
}

$pdo       = db();
$teamCount = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
$grpDone   = 0;
$groupRows = [];
foreach (GROUPS as $g) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE grp = ?');
    $st->execute([$g]);
    $n = (int) $st->fetchColumn();
    [$done, $total] = group_progress($g);
    $groupRows[$g] = [$n, $done, $total];
}
$gm = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(home_score IS NOT NULL), 0) AS done FROM matches WHERE stage = 'G'")->fetch();
$km = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(home_score IS NOT NULL), 0) AS done FROM matches WHERE stage <> 'G'")->fetch();
$champ = champion();

page_header('Panel Admin', 'admin');
?>
<div class="head-row"><h1>Panel Admin</h1></div>

<?php if ($champ): ?>
  <div class="champ">
    <?= logo_html($champ, 'logo lg') ?>
    <div><div class="lbl">Juara Turnamen</div><div class="nm"><?= h($champ['name']) ?></div><div class="muted"><?= h($champ['player']) ?></div></div>
  </div>
<?php endif; ?>

<div class="grid stats">
  <div class="stat"><b><?= $teamCount ?>/32</b><span>Tim terdaftar</span>
    <div class="bar-track"><div class="bar-fill" style="width:<?= round($teamCount / 32 * 100) ?>%"></div></div></div>
  <div class="stat"><b><?= (int) $gm['done'] ?>/48</b><span>Laga fase grup selesai</span>
    <div class="bar-track"><div class="bar-fill" style="width:<?= round($gm['done'] / 48 * 100) ?>%"></div></div></div>
  <div class="stat"><b><?= (int) $km['done'] ?>/15</b><span>Laga knockout selesai</span>
    <div class="bar-track"><div class="bar-fill" style="width:<?= round($km['done'] / 15 * 100) ?>%"></div></div></div>
</div>

<div class="card" style="margin-top:16px">
  <h2>Status Grup</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th class="l">Grup</th><th>Tim</th><th>Laga selesai</th><th class="l">Status</th></tr></thead>
    <tbody>
    <?php foreach ($groupRows as $g => [$n, $done, $total]): ?>
      <tr>
        <td class="l"><b>Grup <?= h($g) ?></b></td>
        <td><?= $n ?>/<?= GROUP_SIZE ?></td>
        <td><?= $total ? "$done/$total" : '–' ?></td>
        <td class="l">
          <?php if ($n < GROUP_SIZE): ?><span class="badge warn">Kurang <?= GROUP_SIZE - $n ?> tim</span>
          <?php elseif ($done === $total): ?><span class="badge">Selesai</span>
          <?php elseif ($done > 0): ?><span class="muted">Berjalan</span>
          <?php else: ?><span class="muted">Jadwal siap</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="actions" style="margin-top:14px">
    <a class="btn" href="<?= h(BASE . '/admin/tim.php') ?>">Kelola Tim</a>
    <a class="btn ghost" href="<?= h(BASE . '/admin/skor.php') ?>">Input Skor</a>
    <a class="btn ghost" href="<?= h(BASE . '/admin/backup.php') ?>">Ekspor & Impor Data</a>
  </div>
</div>

<?php if ($teamCount === 0): ?>
<div class="card">
  <h2>Coba dengan data contoh</h2>
  <p class="muted">Isi otomatis 32 tim fiktif ke 8 grup untuk mencoba aplikasi. Bisa dihapus kapan saja dengan "Hapus semua data".</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="demo"><button class="ghost">Isi 32 tim contoh</button></form>
</div>
<?php endif; ?>

<div class="card danger-zone">
  <h2>Zona Berbahaya</h2>
  <p class="muted small">Tindakan ini tidak bisa dibatalkan. Ketik <b>RESET</b> untuk melanjutkan.</p>
  <div class="actions">
    <form method="post" class="inline-form" onsubmit="return confirm('Hapus SEMUA skor? Tim dan jadwal tetap ada.')">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_scores">
      <input type="text" name="confirm" placeholder="RESET" autocomplete="off" aria-label="Ketik RESET">
      <button class="danger small">Hapus semua skor</button>
    </form>
    <form method="post" class="inline-form" onsubmit="return confirm('Hapus SEMUA tim, jadwal, dan skor?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_all">
      <input type="text" name="confirm" placeholder="RESET" autocomplete="off" aria-label="Ketik RESET">
      <button class="danger small">Hapus semua data</button>
    </form>
  </div>
</div>
<?php page_footer();
