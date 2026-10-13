<?php
declare(strict_types=1);
require __DIR__ . '/../app/web.php';

$teams = teams_all();
$ko    = [];
foreach (matches_all() as $m) {
    if ($m['stage'] !== 'G') {
        $ko[$m['stage']][(int) $m['slot']] = $m;
    }
}
$champ = champion();

page_header('Bagan', 'bagan');
?>
<div class="head-row">
  <div>
    <h1>Bagan Knockout</h1>
    <div class="muted small">Peserta terisi otomatis begitu grup selesai dan hasil laga dimasukkan. Angka kecil di samping skor = adu penalti.</div>
  </div>
  <?= monitor_link() ?>
</div>

<?php if ($champ): ?>
  <div class="champ">
    <?= logo_html($champ, 'logo lg') ?>
    <div>
      <div class="lbl">Juara Turnamen</div>
      <div class="nm"><?= h($champ['name']) ?></div>
      <div class="muted"><?= h($champ['player']) ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="bracket-wrap">
  <div class="bracket">
  <?php foreach (KO as $stage => $slots): ?>
    <div class="b-col">
      <h3><?= h(STAGE_LABEL[$stage]) ?></h3>
      <div class="b-body">
      <?php
        $numbers = array_keys($slots);
        // Dua laga dikelompokkan jadi satu pasangan agar garis penghubung sejajar.
        foreach (array_chunk($numbers, 2) as $pair): ?>
        <div class="b-pair">
          <?php foreach ($pair as $slot):
              $m = $ko[$stage][$slot] ?? ['stage' => $stage, 'slot' => $slot, 'home_id' => null, 'away_id' => null,
                  'home_score' => null, 'away_score' => null, 'home_pen' => null, 'away_pen' => null]; ?>
            <div class="b-slot"><?= bracket_card($m, $teams) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php page_footer();
