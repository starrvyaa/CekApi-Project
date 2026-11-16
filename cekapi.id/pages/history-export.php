<?php
/**
 * Halaman cetak riwayat. Dialog cetak terbuka otomatis -> pilih "Simpan sebagai PDF".
 * (Tanpa library tambahan. Untuk PDF langsung di server, gunakan Dompdf/FPDF.)
 */
require __DIR__ . '/../includes/auth.php';
require_login();

$f    = riwayat_filter($_GET);
$pdo  = db();

$where  = [];
$params = [];

if ($f['from'] !== '') {
    $where[] = 'waktu_rekam >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if ($f['to'] !== '') {
    $where[] = 'waktu_rekam <= ?';
    $params[] = $f['to'] . ' 23:59:59';
}

$sql = 'SELECT id_log, suhu, kelembaban, kadar_asap, kadar_gas, status_api,
               status_sistem, waktu_rekam
        FROM data_sensor';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY waktu_rekam DESC, id_log DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$periode = ($f['from'] || $f['to'])
    ? ($f['from'] ? date('d/m/Y', strtotime($f['from'])) : '…') . ' – ' . ($f['to'] ? date('d/m/Y', strtotime($f['to'])) : '…')
    : 'Semua periode';

$formatSensor = static function ($value, string $suffix = '') {
    if ($value === null || $value === '') return '-';
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') . $suffix;
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Riwayat CEKAPI.id</title>
  <style>
    @page { size: A4 landscape; margin: 16mm; }
    body { font: 12px/1.4 Arial, Helvetica, sans-serif; color: #111; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    p { margin: 0 0 12px; color: #444; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #999; padding: 8px 10px; text-align: center; }
    th { background: #cfe6f7; }
    tr { page-break-inside: avoid; }
    
    /* Memaksa browser mencetak warna background */
    * {
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }

    /* Style Badge Status (Pill) di file Export */
    .status-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 4px 14px;
      border-radius: 50px;
      font-weight: bold;
      font-size: 10px;
      border: 1px solid transparent;
      white-space: nowrap;
    }
    .badge-aman { background-color: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .badge-waspada { background-color: #fef9c3; color: #854d0e; border-color: #fde047; }
    .badge-bahaya { background-color: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .badge-default { background-color: #f1f5f9; color: #334155; border-color: #cbd5e1; }

    .noprint { margin-bottom: 12px; }
    @media print { .noprint { display: none; } }
  </style>
</head>
<body>
  <div class="noprint" style="margin-bottom: 16px;">
  <!-- Jika halaman dibuka di tab baru, bisa gunakan window.close(). Jika di tab yang sama, gunakan window.history.back() -->
  <button onclick="window.history.length > 1 ? window.history.back() : window.close()" style="padding:8px 12px; cursor:pointer; margin-right:8px;">
    &laquo; Kembali
  </button>
  <button onclick="window.print()" style="padding:8px 12px; cursor:pointer;">
    Cetak / Simpan PDF
  </button>
</div>
  <h1>Riwayat Data Sensor &amp; Aktivitas — CEKAPI.id</h1>
  <p>Periode: <?= h($periode) ?> · Dicetak: <?= date('d-m-Y H:i') ?> · <?= count($rows) ?> data</p>
  
  <table>
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
<?php foreach ($rows as $r): ?>
      <?php 
        $teksStatus = strtoupper(trim((string) $r['status_sistem'])); 
        $classWarna = 'badge-default';
        if ($teksStatus === 'AMAN') $classWarna = 'badge-aman';
        elseif ($teksStatus === 'WASPADA') $classWarna = 'badge-waspada';
        elseif ($teksStatus === 'BAHAYA') $classWarna = 'badge-bahaya';
      ?>
      <tr>
        <td><?= h(fmt_waktu($r['waktu_rekam'])) ?></td>
        <td><?= h($formatSensor($r['suhu'], '°C')) ?></td>
        <td><?= h($formatSensor($r['kelembaban'], '%')) ?></td>
        <td><?= h($formatSensor($r['kadar_asap'])) ?></td>
        <td><?= h($formatSensor($r['kadar_gas'], '%')) ?></td>
        <td><?= (int) $r['status_api'] === 1 ? 'TERDETEKSI' : 'TIDAK TERDETEKSI' ?></td>
        <td>
          <span class="status-badge <?= $classWarna ?>">
            <?= h($teksStatus) ?>
          </span>
        </td>
      </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
      <tr><td colspan="7">Tidak ada data.</td></tr>
<?php endif; ?>
    </tbody>
  </table>

  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>