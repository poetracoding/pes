<?php
declare(strict_types=1);
require __DIR__ . '/../../app/web.php';
require_admin();

// Filter: f = A..H | KO | live | wo | all
$f = (string) ($_GET['f'] ?? ($_POST['f'] ?? 'A'));
if (!in_array($f, array_merge(['all', 'KO', 'live', 'wo'], GROUPS), true)) {
    $f = 'A';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);

    if (isset($_POST['toggle_live'])) {
        $curr = (int) db()->query("SELECT is_live FROM matches WHERE id = $id")->fetchColumn();
        $newLive = $curr ? 0 : 1;
        set_match_live($id, $newLive);
        flash($newLive ? 'Pertandingan ditandai SEDANG BERTANDING (Live).' : 'Status sedang bertanding dinonaktifkan.', 'ok');
        redirect('/admin/skor.php?f=' . urlencode($f) . '#m' . $id);
    }

    $isLive = isset($_POST['is_live']) ? (int) $_POST['is_live'] : null;
    $woSide = (string) ($_POST['wo'] ?? '');
    $err = save_match_score(
        $id,
        (string) ($_POST['hs'] ?? ''),
        (string) ($_POST['as'] ?? ''),
        (string) ($_POST['hp'] ?? ''),
        (string) ($_POST['ap'] ?? ''),
        $isLive,
        $woSide
    );
    flash($err ?? 'Skor disimpan.', $err ? 'err' : 'ok');
    redirect('/admin/skor.php?f=' . urlencode($f) . '#m' . $id);
}

$teams     = teams_all();
$matches   = matches_all();
$liveTotal = (int) db()->query('SELECT COUNT(*) FROM matches WHERE is_live = 1')->fetchColumn();
$woTotal   = (int) db()->query("SELECT COUNT(*) FROM matches WHERE wo_side <> ''")->fetchColumn();
$sections  = [];

foreach ($matches as $m) {
    if ($f === 'live' && empty($m['is_live'])) continue;
    if ($f === 'wo' && empty($m['wo_side'])) continue;
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
    <div class="muted small">Isi skor lalu klik Simpan. Kosongkan kedua kolom skor lalu Simpan untuk menghapus hasil. Untuk laga WO, pilih opsi WO lalu klik Simpan.</div>
  </div>
</div>

<div class="filters">
  <a class="chip chip-live <?= $f === 'live' ? 'on' : '' ?>" href="?f=live">🔴 Sedang Main (<?= $liveTotal ?>)</a>
  <?php if ($woTotal > 0): ?>
    <a class="chip chip-wo <?= $f === 'wo' ? 'on' : '' ?>" href="?f=wo">⚠️ Status WO (<?= $woTotal ?>)</a>
  <?php endif; ?>
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
    <?= $f === 'live' ? 'Saat ini belum ada pertandingan yang ditandai sedang bertanding.' : ($f === 'wo' ? 'Belum ada pertandingan dengan status WO.' : 'Belum ada jadwal untuk grup ini. Jadwal dibuat otomatis setelah grup berisi 4 tim — <a href="' . h(BASE . '/admin/tim.php') . '">tambahkan tim</a>.') ?>
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
        $isLive = !empty($m['is_live']);
        $woSide = (string) ($m['wo_side'] ?? '');
    ?>
      <form method="post" class="score-row<?= $isLive ? ' is-live' : '' ?><?= $woSide ? ' is-wo' : '' ?>" id="m<?= (int) $m['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
        <input type="hidden" name="f" value="<?= h($f) ?>">

        <div class="t home">
          <span>
            <?= $home ? h($home['name']) . '<small class="muted" style="display:block;font-size:.78rem;font-weight:400">' . h($home['player']) . '</small>' : '<em class="tbd">' . h(slot_label($m, 'home')) . '</em>' ?>
            <?php if ($woSide === 'home'): ?>
              <span class="badge-wo loss" title="Kalah WO">Kalah WO</span>
            <?php elseif ($woSide === 'away'): ?>
              <span class="badge-wo win" title="Menang WO">Menang WO</span>
            <?php endif; ?>
          </span>
          <?= logo_html($home, 'logo sm') ?>
        </div>

        <div class="inputs-wrap">
          <div class="inputs">
            <input type="number" name="hs" min="0" max="99" inputmode="numeric" aria-label="Skor <?= h($home ? $home['name'] . ' (' . $home['player'] . ')' : 'tuan rumah') ?>"
                   value="<?= $played ? (int) $m['home_score'] : '' ?>" <?= $ready ? '' : 'disabled' ?>>
            <span class="muted">–</span>
            <input type="number" name="as" min="0" max="99" inputmode="numeric" aria-label="Skor <?= h($away ? $away['name'] . ' (' . $away['player'] . ')' : 'tamu') ?>"
                   value="<?= $played ? (int) $m['away_score'] : '' ?>" <?= $ready ? '' : 'disabled' ?>>
          </div>
          <?php if ($ready): ?>
            <select name="wo" class="wo-select <?= $woSide ? 'is-wo' : '' ?>" <?= $ready ? '' : 'disabled' ?> title="Status WO">
              <option value="" <?= $woSide === '' ? 'selected' : '' ?>>Normal</option>
              <option value="home" <?= $woSide === 'home' ? 'selected' : '' ?>>Home Kalah WO (0-3)</option>
              <option value="away" <?= $woSide === 'away' ? 'selected' : '' ?>>Away Kalah WO (3-0)</option>
            </select>
          <?php endif; ?>
          <?php if ($isLive): ?>
            <div class="live-pill-inline"><span class="badge-live"><span class="dot-live"></span>LIVE</span></div>
          <?php endif; ?>
        </div>

        <div class="t">
          <?= logo_html($away, 'logo sm') ?>
          <span>
            <?= $away ? h($away['name']) . '<small class="muted" style="display:block;font-size:.78rem;font-weight:400">' . h($away['player']) . '</small>' : '<em class="tbd">' . h(slot_label($m, 'away')) . '</em>' ?>
            <?php if ($woSide === 'away'): ?>
              <span class="badge-wo loss" title="Kalah WO">Kalah WO</span>
            <?php elseif ($woSide === 'home'): ?>
              <span class="badge-wo win" title="Menang WO">Menang WO</span>
            <?php endif; ?>
          </span>
        </div>

        <div class="save-cell">
          <button class="small<?= $played ? ' ghost' : '' ?>" <?= $ready ? '' : 'disabled' ?>><?= $played ? 'Ubah' : 'Simpan' ?></button>
          <?php if ($ready): ?>
            <button type="submit" name="toggle_live" value="1" class="btn small btn-live-toggle <?= $isLive ? 'active' : 'ghost' ?>" title="<?= $isLive ? 'Matikan tanda sedang bertanding' : 'Tandai sedang bertanding' ?>">
              <?= $isLive ? '🔴 LIVE' : 'Mulai' ?>
            </button>
          <?php endif; ?>
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
