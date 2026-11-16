<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/icons.php';
require_login();

// 1. MENGAMBIL DATA TERBARU DARI DATABASE
$conn = new mysqli("localhost", "root", "", "cekapi");
$data_sensor = null;

if (!$conn->connect_error) {
    $sql = "SELECT * FROM data_sensor ORDER BY id_log DESC LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $data_sensor = $result->fetch_assoc();
    }
}

// 2. MENYIAPKAN VARIABEL KARTU
$suhu = $data_sensor['suhu'] ?? '--';
$asap = $data_sensor['kadar_asap'] ?? '--';
$gas = $data_sensor['kadar_gas'] ?? '--';
$status_api = (isset($data_sensor['status_api']) && $data_sensor['status_api'] == 1) ? 'TERDETEKSI' : 'Aman';
$status_sistem = $data_sensor['status_sistem'] ?? '-';

$state_class = strtolower($status_sistem); 
$api_class = ($status_api == 'TERDETEKSI') ? 'bahaya' : 'aman';

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
    <h1 class="page-title">Pusat Pemantauan </h1>
    <span class="conn" id="conn" style="color: #2e7d32;">Menghubungkan sensor (Tunggu...)...</span>

    <h2 class="sub">Kartu Indikator </h2>
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
        </div>
      </section>
    </div>
  </main>
<!-- ============================================== -->
  <!-- JAVASCRIPT MQTT UNTUK KARTU REALTIME -->
  <!-- ============================================== -->
  <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
  <script>
  document.addEventListener("DOMContentLoaded", function() {
      const MQTT_BROKER_URL = "wss://broker.hivemq.com:8884/mqtt";
      const MQTT_TOPIC = "cekapi/sensor/realtime/1";
      
      // Buat ClientID unik acak agar tidak saling memutus jika dibuka 2 layar bersamaan
      const clientId = 'web-cekapi-' + Math.random().toString(16).substr(2, 8);
      const client = mqtt.connect(MQTT_BROKER_URL, { clientId: clientId });

      client.on('connect', function () {
          document.getElementById('conn').innerText = "Alat Online (Real-Time AKTIF)";
          client.subscribe(MQTT_TOPIC, function (err) {
              if (err) console.error("Gagal subscribe MQTT", err);
          });
      });

      client.on('message', function (topic, message) {
          try {
              const data = JSON.parse(message.toString());
              
              document.querySelector('#card-suhu [data-val]').innerText = data.suhu;
              document.querySelector('#card-asap [data-val]').innerText = data.kadar_asap;
              document.querySelector('#card-gas [data-val]').innerText = parseFloat(data.kadar_gas).toFixed(1);

              const apiStateText = (data.status_api === 1) ? 'TERDETEKSI' : 'Aman';
              document.querySelector('#card-api .state').innerText = apiStateText;
              
              const sistemClass = data.status_sistem.toLowerCase();
              document.getElementById('card-suhu').setAttribute('data-state', sistemClass);
              document.getElementById('card-asap').setAttribute('data-state', sistemClass);
              document.getElementById('card-gas').setAttribute('data-state', sistemClass);
              document.getElementById('card-api').setAttribute('data-state', (data.status_api === 1) ? 'bahaya' : 'aman');

              document.querySelector('#card-suhu .state').innerText = "Status: " + ucfirst(data.status_sistem);
              document.querySelector('#card-asap .state').innerText = "Status: " + ucfirst(data.status_sistem);
              document.querySelector('#card-gas .state').innerText = "Status: " + ucfirst(data.status_sistem);

          } catch(e) {
              console.error("Gagal membaca data JSON:", e);
          }
      });

      function ucfirst(str) {
          if (!str) return '';
          return str.charAt(0).toUpperCase() + str.slice(1);
      }
  });
  </script>

<?php include __DIR__ . '/partials/footer.php'; ?>