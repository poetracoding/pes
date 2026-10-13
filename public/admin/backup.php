<?php
declare(strict_types=1);
require __DIR__ . '/../../app/web.php';
require_admin();

// Tangani aksi Ekspor (Download)
$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? ''));

if ($action === 'export_zip') {
    try {
        $path = export_backup_zip();
        $filename = 'pes_backup_lengkap_' . date('Y-m-d_His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($path);
        @unlink($path);
        exit;
    } catch (Throwable $e) {
        flash('Gagal membuat arsip ZIP: ' . $e->getMessage(), 'err');
        redirect('/admin/backup.php');
    }
} elseif ($action === 'export_json') {
    try {
        $json = export_backup_json();
        $filename = 'pes_data_' . date('Y-m-d_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $json;
        exit;
    } catch (Throwable $e) {
        flash('Gagal membuat file JSON: ' . $e->getMessage(), 'err');
        redirect('/admin/backup.php');
    }
} elseif ($action === 'export_sqlite') {
    try {
        $path = export_backup_sqlite();
        $filename = 'pes_database_' . date('Y-m-d_His') . '.sqlite';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($path);
        @unlink($path);
        exit;
    } catch (Throwable $e) {
        flash('Gagal mengunduh file SQLite: ' . $e->getMessage(), 'err');
        redirect('/admin/backup.php');
    }
}

// Tangani aksi Impor (Restore)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import') {
    csrf_verify();
    $keepAdmin = !empty($_POST['keep_admin']);

    try {
        $res = import_backup_file($_FILES['backup_file'] ?? [], $keepAdmin);
        $msg = sprintf(
            'Data berhasil dipulihkan dari cadangan %s! Terimpor: %d tim, %d pertandingan%s.',
            $res['type'],
            $res['teams'],
            $res['matches'],
            $res['logos'] > 0 ? ", {$res['logos']} logo" : ''
        );
        flash($msg, 'ok');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('/admin/backup.php');
}

$stats = backup_stats();
page_header('Ekspor & Impor Data', 'admin-backup');
?>
<div class="head-row">
  <div>
    <h1>Ekspor & Impor Data</h1>
    <div class="muted small">Amankan seluruh data tim, jadwal, skor pertandingan, dan logo dengan mengunduh berkas cadangan secara berkala.</div>
  </div>
</div>

<div class="grid stats" style="margin-bottom:18px">
  <div class="stat">
    <b><?= (int) $stats['teams'] ?>/32</b>
    <span>Tim terdaftar</span>
    <div class="bar-track"><div class="bar-fill" style="width:<?= round($stats['teams'] / 32 * 100) ?>%"></div></div>
  </div>
  <div class="stat">
    <b><?= (int) $stats['played'] ?>/<?= (int) $stats['matches'] ?></b>
    <span>Laga telah selesai</span>
    <div class="bar-track"><div class="bar-fill" style="width:<?= $stats['matches'] > 0 ? round($stats['played'] / $stats['matches'] * 100) : 0 ?>%"></div></div>
  </div>
  <div class="stat">
    <b><?= (int) $stats['logos'] ?></b>
    <span>File logo di uploads</span>
  </div>
  <div class="stat">
    <b><?= h($stats['db_size_text']) ?></b>
    <span>Ukuran database SQLite</span>
  </div>
</div>

<div class="grid g2">
  <!-- BAGIAN EKSPOR -->
  <section class="card">
    <h2>1. Ekspor Data (Backup)</h2>
    <p class="muted small" style="margin-bottom:16px">
      Unduh dan simpan data turnamen ke perangkat Anda. Anda dapat memilih paket lengkap ZIP (disertai logo) atau format data JSON mandiri.
    </p>

    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="background:var(--panel2);border:1px solid var(--line2);border-radius:10px;padding:14px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <b style="color:var(--text);font-size:1rem;display:block">Paket Lengkap (.zip)</b>
            <span class="muted small">Berisi database SQLite, seluruh file gambar logo di uploads, dan data JSON.</span>
          </div>
          <a class="btn" href="?action=export_zip" style="white-space:nowrap">Unduh ZIP</a>
        </div>
      </div>

      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:14px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <b style="color:var(--text);font-size:1rem;display:block">Data Format JSON (.json)</b>
            <span class="muted small">Data terstruktur teks portabel (termasuk gambar logo dalam base64).</span>
          </div>
          <a class="btn ghost small" href="?action=export_json" style="white-space:nowrap">Unduh JSON</a>
        </div>
      </div>

      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:14px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <b style="color:var(--text);font-size:1rem;display:block">File Database Mentah (.sqlite)</b>
            <span class="muted small">Snapshot langsung dari database SQLite aktif.</span>
          </div>
          <a class="btn ghost small" href="?action=export_sqlite" style="white-space:nowrap">Unduh SQLite</a>
        </div>
      </div>
    </div>
  </section>

  <!-- BAGIAN IMPOR -->
  <section class="card">
    <h2>2. Impor Data (Restore)</h2>
    <p class="muted small" style="margin-bottom:14px">
      Pulihkan data dari file cadangan yang pernah Anda unduh sebelumnya. Sistem mendukung format <b>.zip</b>, <b>.json</b>, maupun <b>.sqlite</b>.
    </p>

    <div style="background:rgba(245, 158, 11, .12);border:1px solid rgba(245, 158, 11, .35);border-radius:9px;padding:10px 12px;margin-bottom:16px;color:#fcd34d;font-size:.85rem;line-height:1.4">
      ⚠️ <b>Perhatian:</b> Memulihkan cadangan akan mengganti data tim, jadwal laga, dan skor yang ada saat ini. Pastikan Anda telah mengunduh cadangan data terbaru sebelum melakukan impor.
    </div>

    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('PERINGATAN: Memulihkan cadangan akan menimpa seluruh tim, jadwal, dan skor saat ini dengan data dari file cadangan. Lanjutkan?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">

      <div class="field" style="margin-bottom:14px">
        <label for="backup_file">Pilih Berkas Cadangan (.zip / .json / .sqlite)</label>
        <input type="file" id="backup_file" name="backup_file" accept=".zip,.json,.sqlite,.db" required>
        <div class="hint">Maksimal 60 MB. Format: file ZIP hasil ekspor, data JSON, atau file SQLite.</div>
      </div>

      <div class="field" style="margin-bottom:16px">
        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer">
          <input type="checkbox" name="keep_admin" value="1" checked style="width:auto">
          <span>Pertahankan akun admin saat ini <span class="muted small">(Direkomendasikan agar tidak terkunci)</span></span>
        </label>
      </div>

      <div class="actions">
        <button class="btn" style="background:var(--blue);color:#031d28">Pulihkan Data Cadangan</button>
      </div>
    </form>
  </section>
</div>

<div class="card" style="margin-top:16px">
  <h2>Panduan Pengamanan Data</h2>
  <ul style="margin:8px 0 0;padding-left:20px;color:var(--muted);font-size:.9rem;line-height:1.6">
    <li><b>Cadangan Berkala:</b> Disarankan untuk mengunduh <b>Paket Lengkap (.zip)</b> setiap kali selesai menginput pendaftaran tim atau menyelesaikan satu putaran pertandingan.</li>
    <li><b>Pindah Perangkat:</b> Jika ingin memindahkan turnamen ke komputer/laptop lain, cukup unduh berkas <b>.zip</b> di komputer lama, lalu gunakan fitur <b>Impor Data</b> di komputer baru.</li>
    <li><b>Cadangan Manual:</b> Di server fisik, Anda juga bisa menyalin langsung folder <code>data/pes.sqlite</code> dan folder <code>public/uploads/</code> kapan saja saat web server berjalan.</li>
  </ul>
</div>

<?php page_footer();
