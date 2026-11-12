
<?php
/**
 * Kontrol aktuator
 *
 * GET  : status kontrol untuk dashboard atau ESP32
 * POST : menyimpan perubahan kontrol dari dashboard
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$label = [
    'buzzer' => 'Buzzer',
    'led'    => 'LED',
    'kipas'  => 'Kipas'
];

try {

    /* ---------- GET: mengambil status kontrol ---------- */

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $isDevice = hash_equals(
            API_KEY,
            (string) ($_SERVER['HTTP_X_API_KEY'] ?? '')
        );

        if (!$isDevice) {
            require_login_api();
        }

        json_response([
            'ok' => true,
            'control' => ambil_controls()
        ]);
    }

    /* ---------- Validasi metode ---------- */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response([
            'ok' => false,
            'error' => 'Metode tidak didukung'
        ], 405);
    }

    /* ---------- POST: menyimpan kontrol ---------- */

    require_login_api();
    csrf_require_api();

    $in = read_json_body()['controls'] ?? null;

    if (!is_array($in)) {
        json_response([
            'ok' => false,
            'error' => 'Data kontrol tidak valid'
        ], 422);
    }

    $pdo = db();
    $pdo->beginTransaction();

    // Ambil status kontrol sebelumnya
    $sekarang = ambil_controls();

    // Validasi dan siapkan perubahan
    $perubahan = [];

    foreach ($label as $kode => $nama) {

        if (!array_key_exists($kode, $in)) {
            continue;
        }

        if (!is_bool($in[$kode]) && !in_array($in[$kode], [0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('Nilai kontrol tidak valid.');
        }

        $baru = (int) filter_var(
            $in[$kode],
            FILTER_VALIDATE_BOOLEAN
        );

        if ($sekarang[$kode] !== $baru) {
            $perubahan[$kode] = [
                'nama' => $nama,
                'nilai' => $baru
            ];
        }
    }

    // Perbarui hanya kolom yang berubah
    if (!empty($perubahan)) {

        $set = [];
        $params = [];

        foreach ($perubahan as $kode => $data) {
            $set[] = "`$kode` = ?";
            $params[] = $data['nilai'];
        }

        $params[] = CEKAPI_ID_ALAT;

        $sql = 'UPDATE device_controls SET '
             . implode(', ', $set)
             . ' WHERE id_alat = ?';

        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        // Catat setiap perubahan manual ke riwayat
        foreach ($perubahan as $kode => $data) {

            $nilai = $data['nilai'] ? 'ON' : 'OFF';

            $pesan = $data['nama']
                   . ($data['nilai'] ? ' dinyalakan secara manual' : ' dimatikan secara manual');

            catat_aktivitas(
                $data['nama'],
                $nilai,
                $pesan,
                'manual',
                'aman'
            );
        }
    }

    $pdo->commit();

    /* ---------- Respons dashboard ---------- */

    json_response([
        'ok' => true,
        'control' => ambil_controls(),
        'activities' => ambil_aktivitas(8),
        'warnings_today' => hitung_peringatan_hari_ini()
    ]);

} catch (InvalidArgumentException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'ok' => false,
        'error' => $e->getMessage()
    ], 422);

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[cekapi] control.php: ' . $e->getMessage());

    json_response([
        'ok' => false,
        'error' => 'Kesalahan server'
    ], 500);
}
