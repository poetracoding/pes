<?php
declare(strict_types=1);
require __DIR__ . '/../app/web.php';

$teamCount = (int) db()->query('SELECT COUNT(*) FROM teams')->fetchColumn();
$liveCount = (int) db()->query('SELECT COUNT(*) FROM matches WHERE is_live = 1')->fetchColumn();
$champ     = champion();

$liveTeamIds   = db()->query("SELECT home_id FROM matches WHERE is_live = 1 UNION SELECT away_id FROM matches WHERE is_live = 1")->fetchAll(PDO::FETCH_COLUMN);
$liveTeamMap   = array_flip(array_map('intval', array_filter($liveTeamIds)));
$liveGroupList = db()->query("SELECT DISTINCT grp FROM matches WHERE is_live = 1 AND stage = 'G'")->fetchAll(PDO::FETCH_COLUMN);
$liveGroupMap  = array_flip(array_filter($liveGroupList));

page_header('Klasemen', 'klasemen');
?>
<div class="head-row">
  <div>
    <h1>Klasemen Grup</h1>
    <div class="muted small">Urutan: Poin → Selisih Gol → Gol Memasukkan. Dua teratas tiap grup lolos ke babak 16 besar.</div>
  </div>
  <?= monitor_link() ?>
</div>

<?php if ($liveCount > 0): ?>
  <div class="card live-banner">
    <div class="live-banner-info">
      <span class="badge-live"><span class="dot-live"></span>LIVE</span>
      <span><b><?= $liveCount ?> pertandingan</b> sedang berlangsung saat ini!</span>
    </div>
    <div class="actions">
      <a class="btn small" href="<?= h(BASE . '/pertandingan.php?s=live') ?>">Pantau Pertandingan</a>
      <a class="btn ghost small" href="<?= h(BASE . '/bagan.php') ?>">Lihat Bagan</a>
    </div>
  </div>
<?php endif; ?>

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

<?php if ($teamCount === 0): ?>
  <div class="card empty-state">
    <h2>Belum ada tim</h2>
    <p class="muted">Tambahkan 32 tim ke dalam 8 grup lewat panel admin.</p>
    <a class="btn" href="<?= h(BASE . (is_admin() ? '/admin/tim.php' : '/login.php')) ?>"><?= is_admin() ? 'Tambah Tim' : 'Login Admin' ?></a>
  </div>
<?php else: ?>
  <div class="legend small muted">
    <span><span class="dot"></span>Zona lolos (2 teratas)</span>
    <span><span class="dot-live" style="margin-right:4px"></span>Sedang bertanding</span>
    <span>Main · M = Menang · S = Seri · K = Kalah · GM/GK = Gol Memasukkan/Kemasukan · SG = Selisih Gol</span>
  </div>

  <div class="grid g2">
  <?php foreach (GROUPS as $g):
      $table = standings($g);
      [$done, $total] = group_progress($g);
      $complete = group_complete($g);
      $isGrpLive = isset($liveGroupMap[$g]);
  ?>
    <section class="card gcard<?= $isGrpLive ? ' has-live' : '' ?>">
      <h3>
        <span>
          Grup <?= h($g) ?>
          <?= $complete ? '<span class="badge">Selesai</span>' : '' ?>
          <?php if ($isGrpLive): ?>
            <span class="badge-live-mini"><span class="dot-live"></span>LIVE</span>
          <?php endif; ?>
        </span>
        <small><?= $total ? "$done/$total laga" : 'Jadwal menunggu 4 tim' ?></small>
      </h3>
      <div class="tablewrap">
      <table class="tbl">
        <thead>
          <tr><th>#</th><th class="l">Tim</th><th>Main</th><th>M</th><th>S</th><th>K</th><th>GM</th><th>GK</th><th>SG</th><th>Poin</th></tr>
        </thead>
        <tbody>
        <?php foreach ($table as $i => $r):
            $isTeamLive = isset($liveTeamMap[(int) $r['id']]);
        ?>
          <tr class="<?= ($i < 2 ? 'q ' : '') . ($isTeamLive ? 'live ' : '') ?>">
            <td class="pos"><?= $i + 1 ?></td>
            <td class="l">
              <div class="tm"><?= logo_html($r) ?>
                <div class="t">
                  <b>
                    <?= h($r['name']) ?>
                    <?php if ($isTeamLive): ?>
                      <span class="badge-live-mini" title="Sedang bertanding"><span class="dot-live"></span>LIVE</span>
                    <?php endif; ?>
                  </b>
                  <small><?= h($r['player']) ?></small>
                </div>
              </div>
            </td>
            <td><?= $r['p'] ?></td><td><?= $r['w'] ?></td><td><?= $r['d'] ?></td><td><?= $r['l'] ?></td>
            <td><?= $r['gf'] ?></td><td><?= $r['ga'] ?></td>
            <td class="gd"><?= $r['gd'] > 0 ? '+' . $r['gd'] : $r['gd'] ?></td>
            <td class="pts"><?= $r['pts'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php for ($i = count($table); $i < GROUP_SIZE; $i++): ?>
          <tr class="empty"><td class="pos"><?= $i + 1 ?></td><td class="l">Belum ada tim</td><td colspan="8"></td></tr>
        <?php endfor; ?>
        </tbody>
      </table>
      </div>
    </section>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer();
