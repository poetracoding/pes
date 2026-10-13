<?php
declare(strict_types=1);
require __DIR__ . '/../../app/web.php';
require_admin();

// Filter: f = A..H | KO | all
$f = (string) ($_GET['f'] ?? ($_POST['f'] ?? 'A'));
if (!in_array($f, array_merge(['all', 'KO'], GROUPS), true)) {
    $f = 'A';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id  = (int) ($_POST['id'] ?? 0);
    $err = save_match_score(
        $id,
        (string) ($_POST['hs'] ?? ''),
        (string) ($_POST['as'] ?? ''),
        (string) ($_POST['hp'] ?? ''),
        (string) ($_POST['ap'] ?? '')
    );
    flash($err ?? 'Skor disimpan.', $err ? 'err' : 'ok');
    redirect('/admin/skor.php?f=' . urlencode($f) . '#m' . $id);
}

$teams   = teams_all();
$matches = matches_all();
$sections = [];
foreach ($matches as $m) {
    if ($f === 'KO' && $m['stage'] === 'G') continue;
    if (in_array($f, GROUPS, true) && !($m['stage'] === 'G' && $m['grp'] === $f)) continue;

    $key = $m['stage'] === 'G' ? 'Grup ' . $m['grp'] . ' · Matchday ' . $m['round'] : STAGE_LABEL[$m['stage']];
    $sections[$key][] = $m;
}

page_header('Input Skor', 'admin-skor');
?>
<div class="head-row">
  <div>
    <h1>Input Skor</h1>
    <div class="muted small">Isi skor lalu klik Simpan. Kosongkan kedua kolom skor lalu Simpan untuk menghapus hasil. Babak knockout yang seri wajib diisi skor penalti.</div>
  </div>
</div>

<div class="filters">
  <?php foreach (GROUPS as $g): ?>
    <a class="chip <?= $f === $g ? 'on' : '' ?>" href="?f=<?= $g ?>">Grup <?= $g ?></a>
  <?php endforeach; ?>
  <a class="chip <?= $f === 'KO' ? 'on' : '' ?>" href="?f=KO">Knockout</a>
  <a class="chip <?= $f === 'all' ? 'on' : '' ?>" href="?f=all">Semua</a>
</div>

<?php if ($f === 'KO'): ?>
  <p class="muted small">Mengubah hasil yang sudah dipakai babak berikutnya akan mereset skor laga lanjutan jika pemenangnya berubah.</p>
<?php endif; ?>

<?php if (!$sections): ?>
  <div class="card empty-state muted">
    Belum ada jadwal untuk grup ini. Jadwal dibuat otomatis setelah grup berisi 4 tim — <a href="<?= h(BASE . '/admin/tim.php') ?>">tambahkan tim</a>.
  </div>
<?php endif; ?>

<?php foreach ($sections as $title => $list): ?>
  <section class="card">
    <h3><?= h($title) ?></h3>
    <?php foreach ($list as $m):
        $home = $teams[(int) $m['home_id']] ?? null;
        $away = $teams[(int) $m['away_id']] ?? null;
        $ready = $home && $away;
        $isKo  = $m['stage'] !== 'G';
        $played = match_played($m);
    ?>
      <form method="post" class="score-row" id="m<?= (int) $m['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
        <input type="hidden" name="f" value="<?= h($f) ?>">

        <div class="t home">
          <span><?= $home ? h($home['name']) . '<small class="muted" style="display:block;font-size:.78rem;font-weight:400">' . h($home['player']) . '</small>' : '<em class="tbd">' . h(slot_label($m, 'home')) . '</em>' ?></span>
          <?= logo_html($home, 'logo sm') ?>
        </div>

        <div class="inputs">
          <input type="number" name="hs" min="0" max="99" inputmode="numeric" aria-label="Skor <?= h($home ? $home['name'] . ' (' . $home['player'] . ')' : 'tuan rumah') ?>"
                 value="<?= $played ? (int) $m['home_score'] : '' ?>" <?= $ready ? '' : 'disabled' ?>>
          <span class="muted">–</span>
          <input type="number" name="as" min="0" max="99" inputmode="numeric" aria-label="Skor <?= h($away ? $away['name'] . ' (' . $away['player'] . ')' : 'tamu') ?>"
                 value="<?= $played ? (int) $m['away_score'] : '' ?>" <?= $ready ? '' : 'disabled' ?>>
        </div>

        <div class="t">
          <?= logo_html($away, 'logo sm') ?>
          <span><?= $away ? h($away['name']) . '<small class="muted" style="display:block;font-size:.78rem;font-weight:400">' . h($away['player']) . '</small>' : '<em class="tbd">' . h(slot_label($m, 'away')) . '</em>' ?></span>
        </div>

        <div class="save-cell">
          <button class="small<?= $played ? ' ghost' : '' ?>" <?= $ready ? '' : 'disabled' ?>><?= $played ? 'Ubah' : 'Simpan' ?></button>
        </div>

        <?php if ($isKo && $ready): ?>
          <div class="pens">
            Jika seri, skor penalti:
            <input type="number" name="hp" min="0" max="99" inputmode="numeric" aria-label="Penalti <?= h($home ? $home['name'] . ' (' . $home['player'] . ')' : '') ?>" value="<?= $m['home_pen'] !== null ? (int) $m['home_pen'] : '' ?>">
            –
            <input type="number" name="ap" min="0" max="99" inputmode="numeric" aria-label="Penalti <?= h($away ? $away['name'] . ' (' . $away['player'] . ')' : '') ?>" value="<?= $m['away_pen'] !== null ? (int) $m['away_pen'] : '' ?>">
          </div>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
<?php page_footer();
