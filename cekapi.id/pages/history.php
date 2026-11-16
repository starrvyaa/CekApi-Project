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

// --- LOGIKA PAGINATION ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

$limit  = 8; // Menampilkan maksimal 8 data per halaman
$offset = ($page - 1) * $limit;

// Menghitung total data untuk mengetahui total halaman
$countSql = 'SELECT COUNT(*) FROM data_sensor';
if ($where) {
    $countSql .= ' WHERE ' . implode(' AND ', $where);
}
$countSt = $pdo->prepare($countSql);
$countSt->execute($params);
$totalRecords = (int) $countSt->fetchColumn();

$totalPages = ceil($totalRecords / $limit);
if ($totalPages < 1) {
    $totalPages = 1;
}
// --------------------------

$sql = 'SELECT suhu, kelembaban, kadar_asap, kadar_gas, status_api,
               status_sistem, waktu_rekam
        FROM data_sensor';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
// Menambahkan LIMIT dan OFFSET untuk pagination
$sql .= " ORDER BY waktu_rekam DESC, id_log DESC LIMIT $limit OFFSET $offset";

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
    // Memastikan `page` yang baru menimpa parameter yang ada
    $q = array_filter(
        ['q' => $f['q'] ?? null, 'from' => $f['from'] ?? null, 'to' => $f['to'] ?? null] + $extra,
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
           <?php 
              $teksStatus = strtoupper(trim((string) $log['status_sistem'])); 
              
              // Menentukan class warna berdasarkan teks status
              $classWarna = 'badge-default';
              if ($teksStatus === 'AMAN') {
                  $classWarna = 'badge-aman';
              } elseif ($teksStatus === 'WASPADA') {
                  $classWarna = 'badge-waspada';
              } elseif ($teksStatus === 'BAHAYA') {
                  $classWarna = 'badge-bahaya';
              }
            ?>
            <td>
              <span class="status-badge <?= $classWarna ?>">
                <?= h($teksStatus) ?>
              </span>
            </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Boks Pagination Angka -->
  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <!-- Tombol Prev -->
      <?php if ($page > 1): ?>
        <a href="?<?= h($qs(['page' => $page - 1])) ?>" class="btn-page">&laquo; Prev</a>
      <?php endif; ?>

      <!-- Tombol Angka -->
      <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
      ?>

      <?php if ($startPage > 1): ?>
        <a href="?<?= h($qs(['page' => 1])) ?>" class="btn-page">1</a>
        <?php if ($startPage > 2): ?>
          <span class="page-dots">...</span>
        <?php endif; ?>
      <?php endif; ?>

      <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
        <a href="?<?= h($qs(['page' => $i])) ?>" class="btn-page <?= $i === $page ? 'active' : '' ?>">
          <?= $i ?>
        </a>
      <?php endfor; ?>

      <?php if ($endPage < $totalPages): ?>
        <?php if ($endPage < $totalPages - 1): ?>
          <span class="page-dots">...</span>
        <?php endif; ?>
        <a href="?<?= h($qs(['page' => $totalPages])) ?>" class="btn-page"><?= $totalPages ?></a>
      <?php endif; ?>

      <!-- Tombol Next -->
      <?php if ($page < $totalPages): ?>
        <a href="?<?= h($qs(['page' => $page + 1])) ?>" class="btn-page">Next &raquo;</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <script>
    // Refresh halaman setiap 10 detik
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