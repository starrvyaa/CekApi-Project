<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/icons.php';
require_login();

// ==========================================
// 1. MENGAMBIL DATA TERBARU DARI DATABASE
// ==========================================
$conn = new mysqli("localhost", "root", "", "cekapi");
$data_sensor = null;

if (!$conn->connect_error) {
    // Ambil 1 baris data paling akhir/baru berdasarkan id_log
    $sql = "SELECT * FROM data_sensor ORDER BY id_log DESC LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $data_sensor = $result->fetch_assoc();
    }
}

// 2. MENYIAPKAN VARIABEL UNTUK KARTU (Beri nilai default jika database kosong)
$suhu = $data_sensor['suhu'] ?? '--';
$asap = $data_sensor['kadar_asap'] ?? '--';
$gas = $data_sensor['kadar_gas'] ?? '--';
$status_api = (isset($data_sensor['status_api']) && $data_sensor['status_api'] == 1) ? 'TERDETEKSI' : 'Aman';
$status_sistem = $data_sensor['status_sistem'] ?? '-';

// Logika warna background kartu (aman/waspada/bahaya)
$state_class = strtolower($status_sistem); 
$api_class = ($status_api == 'TERDETEKSI') ? 'bahaya' : 'aman';
// ==========================================

$pageTitle = 'Dashboard';
$active    = 'dashboard';
$pageCss   = ['dashboard'];
$pageLibs  = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js'];
$pageJs    = ['dashboard'];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>
  <main class="panel">
    <h1 class="page-title">Pusat Pemantauan <em>Real-Time</em></h1>
    <span class="conn" id="conn" style="color: #2e7d32;">Koneksi ke server stabil</span>

    <h2 class="sub">Kartu Indikator Real-Time</h2>
    <section class="cards" id="cards" aria-label="Indikator sensor">
      
      <!-- KARTU SUHU -->
      <article class="card card--suhu" id="card-suhu" data-state="<?= $state_class ?>">
        <h3>Suhu</h3>
        <div class="row">
          <div class="val"><span data-val><?= $suhu ?></span><small>°C</small></div>
          <div class="pic"><div class="thermo-bar" aria-hidden="true"><i data-bar style="height:0"></i></div></div>
        </div>
        <div class="state" data-state-text>Status: <?= ucfirst($status_sistem) ?></div>
      </article>

      <!-- KARTU API -->
      <article class="card card--api" id="card-api" data-state="<?= $api_class ?>">
        <h3>Api</h3>
        <div class="row"><div class="pic" style="margin:auto"><?= icon('flame', 64) ?></div></div>
        <div class="state" data-state-text><?= $status_api ?></div>
      </article>

      <!-- KARTU ASAP -->
      <article class="card card--asap" id="card-asap" data-state="<?= $state_class ?>">
        <h3>Asap</h3>
        <div class="row">
          <div class="val"><span data-val><?= $asap ?></span> <small>ppm</small></div>
          <div class="pic" style="color:#fff"><?= icon('cloud', 54) ?></div>
        </div>
        <div class="state" data-state-text>Status: <?= ucfirst($status_sistem) ?></div>
      </article>

      <!-- KARTU GAS -->
      <article class="card card--gas" id="card-gas" data-state="<?= $state_class ?>">
        <h3>Gas</h3>
        <div class="row">
          <div class="val"><span data-val><?= $gas ?></span><small>%</small></div>
          <div class="pic" style="color:#d8a6ff"><?= icon('gauge', 54) ?></div>
        </div>
        <div class="state" data-state-text>Status: <?= ucfirst($status_sistem) ?></div>
      </article>

    </section>

    <div class="dash-grid">
      <section aria-labelledby="ttl-grafik">
        <div class="chart-head">
          <div>
            <h2 class="sub" id="ttl-grafik" style="margin-top:0">Grafik Pemantauan</h2>
            <p style="margin:-6px 0 12px;font:400 1.05rem var(--font-head)">Grafik sensor suhu, asap, dan gas</p>
            
            <select id="sensor-filter" style="padding: 6px 14px; border-radius: 999px; border: 1px solid #7c190f; background: #8b1e12; color: #fff; font: 600 0.85rem var(--font-head); cursor: pointer;">
              <option value="all" style="background: #fff; color: #111;">Semua Sensor</option>
              <option value="suhu" style="background: #fff; color: #111;">Suhu (°C)</option>
              <option value="asap" style="background: #fff; color: #111;">Asap (ppm)</option>
              <option value="gas" style="background: #fff; color: #111;">Gas (%)</option>
            </select>
          </div>
          <div class="seg" role="group" aria-label="Rentang grafik">
            <button type="button" data-range="live" aria-pressed="true">Live</button>
            <button type="button" data-range="week" aria-pressed="false">7 Hari</button>
          </div>
        </div>
        <div class="chart-box"><canvas id="chart" aria-label="Grafik suhu, asap, dan gas" role="img"></canvas></div>
      </section>

      <section aria-labelledby="ttl-log">
        <h2 class="sub" id="ttl-log" style="margin-top:0">Log Insiden Aktif</h2>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>Alat</th><th>Waktu Kejadian</th><th>Tingkat Bahaya</th><th>Pesan</th></tr></thead>
            <tbody id="incidents"><tr><td class="empty" colspan="4">Memuat…</td></tr></tbody>
          </table>
          <div class="tbl-footer" style="display: flex; gap: 8px; justify-content: flex-end;">
            <button type="button" id="btn-prev" class="pg-btn">&laquo; Back</button>
            <button type="button" id="btn-next" class="pg-btn">Next &raquo;</button>
          </div>
        </div>
      </section>
    </div>
  </main>
<?php include __DIR__ . '/partials/footer.php'; ?>