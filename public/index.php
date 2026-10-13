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

// Siapkan data riwayat pertandingan untuk semua tim (dibuka saat nama peserta diklik)
$allMatches    = matches_all();
$allTeams      = teams_all();
$teamHistories = [];

foreach ($allTeams as $tid => $t) {
    $teamHistories[$tid] = [
        'id'        => (int) $t['id'],
        'name'      => (string) $t['name'],
        'player'    => (string) $t['player'],
        'grp'       => (string) $t['grp'],
        'logo_html' => logo_html($t, 'logo lg'),
        'stats'     => null,
        'matches'   => [],
    ];
}

foreach ($allMatches as $m) {
    $hid    = (int) $m['home_id'];
    $aid    = (int) $m['away_id'];
    $done   = match_played($m);
    $win    = match_winner($m);
    $isLive = !empty($m['is_live']);
    $isWo   = !empty($m['wo_side']);

    $stageTitle = ($m['stage'] === 'G')
        ? 'Grup ' . $m['grp'] . ' · Matchday ' . $m['round']
        : (STAGE_LABEL[$m['stage']] ?? $m['stage']);

    if ($hid && isset($teamHistories[$hid])) {
        $opp = $aid && isset($allTeams[$aid]) ? $allTeams[$aid] : null;
        $pen = ($m['home_pen'] !== null && $m['away_pen'] !== null) ? ' (' . $m['home_pen'] . '–' . $m['away_pen'] . ' pen)' : '';
        $scoreText = $done
            ? $m['home_score'] . ' – ' . $m['away_score'] . ($isWo ? ' (WO)' : $pen)
            : ($isLive ? (int) $m['home_score'] . ' – ' . (int) $m['away_score'] . ' (LIVE)' : 'vs');

        $result = 'todo';
        if ($isLive) $result = 'live';
        elseif ($done) {
            if ($isWo) {
                $result = ($m['wo_side'] === 'home') ? 'wo_loss' : 'wo_win';
            } elseif ($win === $hid) {
                $result = 'win';
            } elseif ($win === $aid) {
                $result = 'loss';
            } else {
                $result = 'draw';
            }
        }

        $teamHistories[$hid]['matches'][] = [
            'stage'      => $stageTitle,
            'side'       => 'home',
            'opp_name'   => $opp ? $opp['name'] : slot_label($m, 'away'),
            'opp_player' => $opp ? $opp['player'] : '',
            'opp_logo'   => logo_html($opp, 'logo sm'),
            'done'       => $done,
            'is_live'    => $isLive,
            'result'     => $result,
            'score_text' => $scoreText,
            'is_wo'      => $isWo,
        ];
    }

    if ($aid && isset($teamHistories[$aid])) {
        $opp = $hid && isset($allTeams[$hid]) ? $allTeams[$hid] : null;
        $pen = ($m['home_pen'] !== null && $m['away_pen'] !== null) ? ' (' . $m['away_pen'] . '–' . $m['home_pen'] . ' pen)' : '';
        $scoreText = $done
            ? $m['away_score'] . ' – ' . $m['home_score'] . ($isWo ? ' (WO)' : $pen)
            : ($isLive ? (int) $m['away_score'] . ' – ' . (int) $m['home_score'] . ' (LIVE)' : 'vs');

        $result = 'todo';
        if ($isLive) $result = 'live';
        elseif ($done) {
            if ($isWo) {
                $result = ($m['wo_side'] === 'away') ? 'wo_loss' : 'wo_win';
            } elseif ($win === $aid) {
                $result = 'win';
            } elseif ($win === $hid) {
                $result = 'loss';
            } else {
                $result = 'draw';
            }
        }

        $teamHistories[$aid]['matches'][] = [
            'stage'      => $stageTitle,
            'side'       => 'away',
            'opp_name'   => $opp ? $opp['name'] : slot_label($m, 'home'),
            'opp_player' => $opp ? $opp['player'] : '',
            'opp_logo'   => logo_html($opp, 'logo sm'),
            'done'       => $done,
            'is_live'    => $isLive,
            'result'     => $result,
            'score_text' => $scoreText,
            'is_wo'      => $isWo,
        ];
    }
}

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
    <span class="muted" style="margin-left:auto"><small>💡 Klik nama peserta untuk melihat riwayat pertandingan</small></span>
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
            if (isset($teamHistories[(int) $r['id']])) {
                $teamHistories[(int) $r['id']]['stats'] = [
                    'p'   => $r['p'],
                    'w'   => $r['w'],
                    'd'   => $r['d'],
                    'l'   => $r['l'],
                    'gf'  => $r['gf'],
                    'ga'  => $r['ga'],
                    'gd'  => $r['gd'] > 0 ? '+' . $r['gd'] : (string) $r['gd'],
                    'pts' => $r['pts'],
                ];
            }
        ?>
          <tr class="<?= ($i < 2 ? 'q ' : '') . ($isTeamLive ? 'live ' : '') ?>">
            <td class="pos"><?= $i + 1 ?></td>
            <td class="l">
              <div class="tm team-clickable" onclick="openTeamModal(<?= (int) $r['id'] ?>)" title="Klik untuk melihat riwayat pertandingan <?= h($r['player']) ?>">
                <?= logo_html($r) ?>
                <div class="t">
                  <b>
                    <?= h($r['name']) ?>
                    <?php if ($isTeamLive): ?>
                      <span class="badge-live-mini" title="Sedang bertanding"><span class="dot-live"></span>LIVE</span>
                    <?php endif; ?>
                  </b>
                  <small class="pname-full <?= player_font_class($r['player']) ?>" title="<?= h($r['player']) ?>"><?= h($r['player']) ?></small>
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

  <!-- Modal Riwayat Pertandingan Peserta -->
  <div id="teamModal" class="modal-backdrop" style="display:none;" onclick="if(event.target===this)closeTeamModal()">
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-team-brand">
          <div id="modalLogo"></div>
          <div>
            <h2 id="modalTeamName"></h2>
            <div id="modalPlayerName" class="modal-player"></div>
            <span id="modalGroupBadge" class="badge"></span>
          </div>
        </div>
        <button type="button" class="btn ghost small close-btn" onclick="closeTeamModal()" aria-label="Tutup">✕</button>
      </div>

      <div class="modal-stats" id="modalStats"></div>

      <div class="modal-section-title">Riwayat Pertandingan</div>
      <div class="modal-match-list" id="modalMatchList"></div>
    </div>
  </div>

  <script>
  const TEAM_DATA = <?= json_encode($teamHistories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  function openTeamModal(teamId) {
    const t = TEAM_DATA[teamId];
    if (!t) return;

    document.getElementById('modalLogo').innerHTML = t.logo_html;
    document.getElementById('modalTeamName').textContent = t.name;
    document.getElementById('modalPlayerName').textContent = t.player;
    document.getElementById('modalGroupBadge').textContent = 'Grup ' + t.grp;

    // Stats
    let statsHtml = '';
    if (t.stats) {
      statsHtml = `
        <div class="mstat"><b>${t.stats.p}</b><span>Main</span></div>
        <div class="mstat"><b>${t.stats.w}</b><span>Menang</span></div>
        <div class="mstat"><b>${t.stats.d}</b><span>Seri</span></div>
        <div class="mstat"><b>${t.stats.l}</b><span>Kalah</span></div>
        <div class="mstat"><b>${t.stats.gf}-${t.stats.ga}</b><span>Gol</span></div>
        <div class="mstat"><b>${t.stats.pts}</b><span>Poin</span></div>
      `;
    }
    document.getElementById('modalStats').innerHTML = statsHtml;

    // Matches
    let listHtml = '';
    if (!t.matches || t.matches.length === 0) {
      listHtml = '<div class="muted small" style="text-align:center;padding:20px;">Belum ada jadwal pertandingan.</div>';
    } else {
      t.matches.forEach(m => {
        let badgeClass = 'res-todo';
        let badgeText = 'Belum Main';
        if (m.is_live) {
          badgeClass = 'res-live';
          badgeText = '● LIVE';
        } else if (m.done) {
          if (m.result === 'wo_win') { badgeClass = 'res-win res-wo'; badgeText = 'MENANG (WO)'; }
          else if (m.result === 'wo_loss') { badgeClass = 'res-loss res-wo'; badgeText = 'KALAH (WO)'; }
          else if (m.result === 'win') { badgeClass = 'res-win'; badgeText = 'MENANG'; }
          else if (m.result === 'loss') { badgeClass = 'res-loss'; badgeText = 'KALAH'; }
          else { badgeClass = 'res-draw'; badgeText = 'SERI'; }
        }

        listHtml += `
          <div class="hist-row ${m.is_live ? 'is-live' : ''}">
            <div class="hist-stage">${m.stage}</div>
            <div class="hist-match">
              <div class="hist-opp">
                ${m.opp_logo}
                <div>
                  <b>vs ${m.opp_name}</b>
                  ${m.opp_player ? `<small class="muted" style="display:block;font-size:0.78rem;">${m.opp_player}</small>` : ''}
                </div>
              </div>
              <div class="hist-result">
                <span class="hist-score">${m.score_text}</span>
                <span class="hist-badge ${badgeClass}">${badgeText}</span>
              </div>
            </div>
          </div>
        `;
      });
    }
    document.getElementById('modalMatchList').innerHTML = listHtml;

    const modal = document.getElementById('teamModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function closeTeamModal() {
    const modal = document.getElementById('teamModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeTeamModal();
  });
  </script>
<?php endif; ?>
<?php page_footer();
