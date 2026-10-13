<?php
declare(strict_types=1);
require __DIR__ . '/../app/web.php';

$teams   = teams_all();
$matches = matches_all();

// Filter: f = all | A..H | KO ; s = all | live | done | todo | wo
$f = (string) ($_GET['f'] ?? 'all');
$s = (string) ($_GET['s'] ?? 'all');
if (!in_array($f, array_merge(['all', 'KO'], GROUPS), true)) {
    $f = 'all';
}
if (!in_array($s, ['all', 'live', 'done', 'todo', 'wo'], true)) {
    $s = 'all';
}

$liveCount = (int) db()->query('SELECT COUNT(*) FROM matches WHERE is_live = 1')->fetchColumn();
$woCount   = (int) db()->query("SELECT COUNT(*) FROM matches WHERE wo_side <> ''")->fetchColumn();
$sections = [];
$played = 0;
foreach ($matches as $m) {
    $isPlayed = match_played($m);
    $played  += $isPlayed ? 1 : 0;

    if ($f === 'KO' && $m['stage'] === 'G') continue;
    if ($f !== 'all' && $f !== 'KO' && !($m['stage'] === 'G' && $m['grp'] === $f)) continue;
    if ($s === 'live' && empty($m['is_live'])) continue;
    if ($s === 'wo' && empty($m['wo_side'])) continue;
    if ($s === 'done' && !$isPlayed) continue;
    if ($s === 'todo' && ($isPlayed || !empty($m['is_live']))) continue;

    $key = $m['stage'] === 'G'
        ? 'Grup ' . $m['grp'] . ' · Matchday ' . $m['round']
        : STAGE_LABEL[$m['stage']];
    $sections[$key][] = $m;
}

$goals = (int) db()->query('SELECT COALESCE(SUM(COALESCE(home_score,0) + COALESCE(away_score,0)), 0) FROM matches')->fetchColumn();
$total = count($matches);

function filter_url(string $f, string $s): string
{
    $q = ['f' => $f, 's' => $s];
    if (isset($_GET['monitor'])) {
        $q['monitor'] = 1;
    }
    return '?' . http_build_query($q);
}

page_header('Pertandingan', 'pertandingan');
?>
<div class="head-row">
  <div>
    <h1>Monitor Pertandingan</h1>
    <div class="muted small">
      <?= $played ?> dari <?= $total ?> laga selesai · <?= $goals ?> gol tercipta
      <?php if ($liveCount > 0): ?>
        · <span class="badge-live" style="margin-left:6px"><span class="dot-live"></span><?= $liveCount ?> sedang main</span>
      <?php endif; ?>
    </div>
  </div>
  <?= monitor_link() ?>
</div>

<div class="filters">
  <a class="chip <?= $f === 'all' ? 'on' : '' ?>" href="<?= h(filter_url('all', $s)) ?>">Semua</a>
  <?php foreach (GROUPS as $g): ?>
    <a class="chip <?= $f === $g ? 'on' : '' ?>" href="<?= h(filter_url($g, $s)) ?>">Grup <?= $g ?></a>
  <?php endforeach; ?>
  <a class="chip <?= $f === 'KO' ? 'on' : '' ?>" href="<?= h(filter_url('KO', $s)) ?>">Knockout</a>
</div>
<div class="filters" style="margin-bottom:16px">
  <a class="chip <?= $s === 'all' ? 'on' : '' ?>" href="<?= h(filter_url($f, 'all')) ?>">Semua status</a>
  <a class="chip chip-live <?= $s === 'live' ? 'on' : '' ?>" href="<?= h(filter_url($f, 'live')) ?>">🔴 Sedang main <?= $liveCount > 0 ? "($liveCount)" : '' ?></a>
  <a class="chip <?= $s === 'done' ? 'on' : '' ?>" href="<?= h(filter_url($f, 'done')) ?>">Sudah main</a>
  <a class="chip <?= $s === 'todo' ? 'on' : '' ?>" href="<?= h(filter_url($f, 'todo')) ?>">Belum main</a>
  <?php if ($woCount > 0): ?>
    <a class="chip chip-wo <?= $s === 'wo' ? 'on' : '' ?>" href="<?= h(filter_url($f, 'wo')) ?>">⚠️ WO (<?= $woCount ?>)</a>
  <?php endif; ?>
</div>

<?php if (!$sections): ?>
  <div class="card empty-state muted">Tidak ada pertandingan untuk filter ini.</div>
<?php endif; ?>

<?php foreach ($sections as $title => $list): ?>
  <section class="card">
    <h3><?= h($title) ?></h3>
    <?php foreach ($list as $m) echo match_line($m, $teams); ?>
  </section>
<?php endforeach; ?>
<?php page_footer();
