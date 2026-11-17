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

    <!-- TOMBOL DOWNLOAD DIUBAH UNTUK MEMBUKA MODAL -->
    <button type="button" class="btn btn-blue" onclick="openDownloadModal()">
      <?= icon('download', 20) ?> Download PDF
    </button>
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

  <!-- STYLING UNTUK MODAL -->
  <style>
    .modal-overlay {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.5);
      display: flex; align-items: center; justify-content: center;
      z-index: 1000;
    }
    .modal-content {
      background: #fff; padding: 25px; border-radius: 8px;
      width: 90%; max-width: 400px; position: relative;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .close-modal {
      position: absolute; top: 10px; right: 15px;
      font-size: 24px; cursor: pointer; font-weight: bold; color: #555;
    }
    .form-group { margin-bottom: 15px; text-align: left; }
    .form-group label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 14px;}
    .form-group input {
      width: 100%; padding: 10px; border: 1px solid #ccc; 
      border-radius: 4px; box-sizing: border-box;
    }
    .modal-actions { margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px; }
  </style>

  <!-- HTML MODAL -->
  <div id="downloadModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
      <span class="close-modal" onclick="closeDownloadModal()">&times;</span>
      <h3 style="margin-top: 0; margin-bottom: 20px;">Pilih Waktu Export</h3>
      <form id="downloadPdfForm" onsubmit="handleDownloadSubmit(event)">
        <div class="form-group">
          <label for="start_time">Dari Tanggal & Jam:</label>
          <input type="datetime-local" id="start_time" name="start_time" required>
        </div>
        <div class="form-group">
          <label for="end_time">Sampai Tanggal & Jam:</label>
          <input type="datetime-local" id="end_time" name="end_time" required>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-default" onclick="closeDownloadModal()" style="background: #f1f1f1; border: none; padding: 8px 15px; cursor: pointer; border-radius: 4px;">Batal</button>
          <button type="submit" class="btn btn-blue" style="padding: 8px 15px;">Cetak PDF</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    let isModalOpen = false;

    function openDownloadModal() {
      const modal = document.getElementById('downloadModal');
      const startInput = document.getElementById('start_time');
      const endInput = document.getElementById('end_time');
      
      // Batasi agar tidak bisa memilih waktu di masa depan
      const now = new Date();
      now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
      const maxDatetime = now.toISOString().slice(0, 16);
      
      startInput.max = maxDatetime;
      endInput.max = maxDatetime;
      
      modal.style.display = 'flex';
      isModalOpen = true; 
    }

    function closeDownloadModal() {
      document.getElementById('downloadModal').style.display = 'none';
      isModalOpen = false; 
    }

    async function handleDownloadSubmit(e) {
      e.preventDefault();
      
      const start = document.getElementById('start_time').value;
      const end = document.getElementById('end_time').value;
      
      if (start > end) {
        alert('Waktu awal tidak boleh lebih besar dari waktu akhir.');
        return;
      }

      try {
        // Memanggil file check-data.php untuk verifikasi ketersediaan data
        const response = await fetch(`check-data.php?start=${start}&end=${end}`);
        const result = await response.json();
        
        if (result.count > 0) {
          // Data ditemukan, buka PDF di tab baru
          window.open(`history-export.php?from_time=${start}&to_time=${end}`, '_blank');
          closeDownloadModal();
        } else {
          // Data kosong
          alert('Tidak ada data yang tersimpan pada rentang waktu yang dipilih.');
        }
      } catch (error) {
        alert('Terjadi kesalahan saat memeriksa data. Pastikan file check-data.php sudah dibuat.');
      }
    }

    // Refresh halaman setiap 10 detik (berhenti jika modal sedang terbuka)
    window.setInterval(() => {
      const form = document.getElementById('filterForm');
      if (
        document.visibilityState === 'visible' &&
        !form.contains(document.activeElement) &&
        !isModalOpen
      ) {
        window.location.reload();
      }
    }, 10000);
  </script>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>