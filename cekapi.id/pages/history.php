<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/icons.php';
require_login();

$f   = riwayat_filter($_GET);
$pdo = db();

$where = [];
$params = [];

if ($f['from'] !== '') {
    $where[] = 'waktu_rekam >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if ($f['to'] !== '') {
    $where[] = 'waktu_rekam <= ?';
    $params[] = $f['to'] . ' 23:59:59';
}

$sql = 'SELECT suhu, kelembaban, kadar_asap, kadar_gas, status_api,
               status_sistem, waktu_rekam
        FROM data_sensor';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY waktu_rekam DESC, id_log DESC LIMIT 20';

$st = $pdo->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll();

$formatSensor = static function ($value, string $suffix = '') {
    if ($value === null || $value === '') {
        return '-';
    }

    $number = rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    return $number . $suffix;
};

$qs = static function (array $extra = []) use ($f) {
    $q = array_filter(
        ['q' => $f['q'], 'from' => $f['from'], 'to' => $f['to']] + $extra,
        fn($v) => $v !== '' && $v !== null
    );

    return http_build_query($q);
};

$pageTitle = 'Riwayat';
$active    = 'history';
$pageCss   = ['dashboard', 'history'];
$pageJs    = [];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>
<main class="panel">
  <h1 class="page-title">Riwayat Data Sensor &amp; Aktivitas</h1>

  <div class="toolbar">
    <form method="get" id="filterForm" role="search">
      <span class="lbl">Pilih Periode:</span>
      <span class="field-pill">
        <?= icon('calendar', 22) ?>
        <input type="date" name="from" value="<?= h($f['from']) ?>"
               aria-label="Dari tanggal" onchange="this.form.submit()">
        <span>–</span>
        <input type="date" name="to" value="<?= h($f['to']) ?>"
               aria-label="Sampai tanggal" onchange="this.form.submit()">
      </span>
      <button type="submit" class="sr-only">Terapkan</button>
    </form>

    <a class="btn btn-blue"
       href="history-export.php<?= $qs() ? '?' . h($qs()) : '' ?>"
       target="_blank" rel="noopener">
      <?= icon('download', 20) ?>Download PDF
    </a>
  </div>

  <div class="tbl-wrap big">
    <table class="tbl hist sensor-log-table">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Suhu</th>
          <th>Kelembapan</th>
          <th>Asap</th>
          <th>Gas</th>
          <th>Api</th>
          <th>Status Sistem</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr>
            <td class="empty" colspan="7">Belum ada data sensor.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><?= h(fmt_waktu($log['waktu_rekam'])) ?></td>
              <td><?= h($formatSensor($log['suhu'], '°C')) ?></td>
              <td><?= h($formatSensor($log['kelembaban'], '%')) ?></td>
              <td><?= h($formatSensor($log['kadar_asap'])) ?></td>
              <td><?= h($formatSensor($log['kadar_gas'], '%')) ?></td>
              <td><?= (int) $log['status_api'] === 1 ? 'TERDETEKSI' : 'TIDAK TERDETEKSI' ?></td>
              <td><?= h(strtoupper((string) $log['status_sistem'])) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <script>
    window.setInterval(() => {
      const form = document.getElementById('filterForm');
      if (
        document.visibilityState === 'visible' &&
        !form.contains(document.activeElement)
      ) {
        window.location.reload();
      }
    }, 10000);
  </script>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
