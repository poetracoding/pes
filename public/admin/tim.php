<?php
declare(strict_types=1);
require __DIR__ . '/../../app/web.php';
require_admin();

$pdo = db();

function team_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM teams WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function group_count(string $g, int $exceptId = 0): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM teams WHERE grp = ? AND id <> ?');
    $st->execute([$g, $exceptId]);
    return (int) $st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $back   = '/admin/tim.php';

    try {
        if ($action === 'add' || $action === 'update') {
            $player = trim((string) ($_POST['player'] ?? ''));
            $name   = trim((string) ($_POST['name'] ?? ''));
            $grp    = (string) ($_POST['grp'] ?? '');
            $old    = $action === 'update' ? team_by_id($id) : null;

            if ($action === 'update' && !$old) {
                throw new RuntimeException('Tim tidak ditemukan.');
            }
            if ($player === '' || mb_strlen($player) > 60) {
                throw new RuntimeException('Nama pemain wajib diisi (maksimal 60 karakter).');
            }
            if ($name === '' || mb_strlen($name) > 40) {
                throw new RuntimeException('Nama tim wajib diisi (maksimal 40 karakter).');
            }
            if (!in_array($grp, GROUPS, true)) {
                throw new RuntimeException('Pilih grup A–H.');
            }
            if (group_count($grp, $id) >= GROUP_SIZE) {
                throw new RuntimeException("Grup $grp sudah penuh (" . GROUP_SIZE . ' tim).');
            }
            if ($old && $old['grp'] !== $grp && (group_locked($old['grp']) || group_locked($grp))) {
                throw new RuntimeException('Tim tidak bisa dipindah grup karena sudah ada skor di grup terkait. Hapus skor grup itu dulu.');
            }

            $logo = handle_logo_upload($_FILES['logo'] ?? []);

            if ($action === 'add') {
                $pdo->prepare('INSERT INTO teams (player, name, logo, grp) VALUES (?, ?, ?, ?)')->execute([$player, $name, $logo, $grp]);
                rebuild_group($grp);
                flash("Tim \"$name\" ditambahkan ke Grup $grp.");
            } else {
                $newLogo = $old['logo'];
                if ($logo !== null) {
                    delete_logo($old['logo']);
                    $newLogo = $logo;
                } elseif (!empty($_POST['remove_logo'])) {
                    delete_logo($old['logo']);
                    $newLogo = null;
                }
                $pdo->prepare('UPDATE teams SET player = ?, name = ?, logo = ?, grp = ? WHERE id = ?')->execute([$player, $name, $newLogo, $grp, $id]);
                if ($old['grp'] !== $grp) {
                    rebuild_group($old['grp']);
                    rebuild_group($grp);
                }
                flash("Tim \"$name\" diperbarui.");
            }
            sync_knockout();
        } elseif ($action === 'delete') {
            $team = team_by_id($id);
            if (!$team) {
                throw new RuntimeException('Tim tidak ditemukan.');
            }
            if (group_locked($team['grp'])) {
                throw new RuntimeException('Tim tidak bisa dihapus karena Grup ' . $team['grp'] . ' sudah punya skor. Hapus skor grup itu dulu.');
            }
            $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
            delete_logo($team['logo']);
            rebuild_group($team['grp']);
            sync_knockout();
            flash('Tim "' . $team['name'] . '" dihapus.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        if ($action === 'update') {
            $back .= '?edit=' . $id;
        }
    }
    redirect($back);
}

$teams   = $pdo->query('SELECT * FROM teams ORDER BY grp, id')->fetchAll();
$counts  = [];
foreach ($teams as $t) {
    $counts[$t['grp']] = ($counts[$t['grp']] ?? 0) + 1;
}
$editing = isset($_GET['edit']) ? team_by_id((int) $_GET['edit']) : null;
$total   = count($teams);

function group_options(array $counts, ?string $selected, ?array $editing): string
{
    $html = '<option value="">— pilih grup —</option>';
    foreach (GROUPS as $g) {
        $n    = $counts[$g] ?? 0;
        $mine = $editing && $editing['grp'] === $g;
        $full = $n - ($mine ? 1 : 0) >= GROUP_SIZE;
        $html .= '<option value="' . h($g) . '"' . ($selected === $g ? ' selected' : '') . ($full ? ' disabled' : '') . '>'
              . 'Grup ' . h($g) . ' (' . $n . '/' . GROUP_SIZE . ')' . ($full && !$mine ? ' – penuh' : '') . '</option>';
    }
    return $html;
}

page_header('Data Tim', 'admin-tim');
?>
<div class="head-row">
  <div>
    <h1>Data Tim</h1>
    <div class="muted small"><?= $total ?>/32 tim terdaftar. Jadwal grup dibuat otomatis begitu sebuah grup berisi 4 tim.</div>
  </div>
</div>

<section class="card" id="form">
  <h2><?= $editing ? 'Ubah Tim' : 'Tambah Tim' ?></h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field">
        <label for="player">Nama pemain</label>
        <input type="text" id="player" name="player" maxlength="60" required value="<?= h($editing['player'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="name">Nama tim</label>
        <input type="text" id="name" name="name" maxlength="40" required value="<?= h($editing['name'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="grp">Grup</label>
        <select id="grp" name="grp" required><?= group_options($counts, $editing['grp'] ?? null, $editing) ?></select>
      </div>
      <div class="field">
        <label for="logo">Logo <span class="muted">(opsional)</span></label>
        <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
        <div class="hint">PNG/JPG/GIF/WEBP, maksimal 2 MB.</div>
      </div>
    </div>
    <?php if ($editing && $editing['logo']): ?>
      <div class="field" style="margin-top:12px">
        <label style="display:flex;align-items:center;gap:8px;font-weight:500">
          <?= logo_html($editing) ?>
          <input type="checkbox" name="remove_logo" value="1" style="width:auto"> Hapus logo saat ini
        </label>
      </div>
    <?php endif; ?>
    <div class="actions" style="margin-top:14px">
      <button><?= $editing ? 'Simpan Perubahan' : 'Tambah Tim' ?></button>
      <?php if ($editing): ?><a class="btn ghost" href="<?= h(BASE . '/admin/tim.php') ?>">Batal</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="card">
  <h2>Daftar Tim</h2>
  <?php if (!$teams): ?>
    <p class="muted">Belum ada tim.</p>
  <?php else: ?>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th class="l">Grup</th><th class="l">Tim</th><th class="l">Pemain</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($teams as $t): $locked = group_locked($t['grp']); ?>
      <tr>
        <td class="l"><b><?= h($t['grp']) ?></b></td>
        <td class="l"><div class="tm"><?= logo_html($t) ?><div class="t"><b><?= h($t['name']) ?></b></div></div></td>
        <td class="l"><?= h($t['player']) ?></td>
        <td>
          <div class="actions" style="justify-content:flex-end">
            <a class="btn ghost small" href="?edit=<?= (int) $t['id'] ?>#form">Ubah</a>
            <?php if (!$locked): ?>
              <form method="post" onsubmit="return confirm('Hapus tim <?= h(addslashes($t['name'])) ?>?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="danger small">Hapus</button>
              </form>
            <?php else: ?>
              <span class="muted small" title="Grup sudah punya skor">terkunci</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php page_footer();
