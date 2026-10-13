<?php
declare(strict_types=1);
require __DIR__ . '/../app/web.php';

$teams   = teams_all();
$matches = matches_all();

// Filter: f = all | A..H | KO ; s = all | done | todo
$f = (string) ($_GET['f'] ?? 'all');
$s = (string) ($_GET['s'] ?? 'all');
if (!in_array($f, array_merge(['all', 'KO'], GROUPS), true)) {
    $f = 'all';
}
if (!in_array($s, ['all', 'done', 'todo'], true)) {
    $s = 'all';
}

$sections = [];
$played = 0;
foreach ($matches as $m) {
    $isPlayed = match_played($m);
    $played  += $isPlayed ? 1 : 0;

    if ($f === 'KO' && $m['stage'] === 'G') continue;
    if ($f !== 'all' && $f !== 'KO' && !($m['stage'] === 'G' && $m['grp'] === $f)) continue;
    if ($s === 'done' && !$isPlayed) continue;
    if ($s === 'todo' && $isPlayed) continue;

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
    <div class="muted small"><?= $played ?> dari <?= $total ?> laga selesai · <?= $goals ?> gol tercipta</div>
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
  <?php foreach (['all' => 'Semua status', 'done' => 'Sudah main', 'todo' => 'Belum main'] as $k => $label): ?>
    <a class="chip <?= $s === $k ? 'on' : '' ?>" href="<?= h(filter_url($f, $k)) ?>"><?= $label ?></a>
  <?php endforeach; ?>
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
