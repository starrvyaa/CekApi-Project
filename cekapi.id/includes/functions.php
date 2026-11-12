
<?php
/**
 * Fungsi bantu bersama (dipakai API dan halaman)
 */
require_once __DIR__ . '/../config/app.php';

const SENSOR_JENIS = ['Sensor Suhu', 'Sensor Asap', 'Sensor Gas', 'Sensor Api'];
const STATUS_LIST  = ['aman', 'waspada', 'bahaya'];

// ID perangkat ESP32 yang digunakan
const CEKAPI_ID_ALAT = 2;

/* ---------- Umum ---------- */

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function fmt_waktu(string $dt): string
{
    return date('d-m-Y H:i:s', strtotime($dt));
}

/* ---------- Klasifikasi status ---------- */

function status_rank(string $s): int
{
    $r = ['aman' => 0, 'waspada' => 1, 'bahaya' => 2];
    return $r[$s] ?? 0;
}

function level_nilai($v, $waspada, $bahaya): string
{
    if ($v === null) return 'aman';
    if ($v >= $bahaya) return 'bahaya';
    if ($v >= $waspada) return 'waspada';
    return 'aman';
}

/**
 * @return array{overall:string, per:array<string,string>}
 */
function klasifikasi($suhu, $asap, $gas, bool $api): array
{
    $per = [
        'suhu' => level_nilai($suhu, TH_SUHU_WASPADA, TH_SUHU_BAHAYA),
        'asap' => level_nilai($asap, TH_ASAP_WASPADA, TH_ASAP_BAHAYA),
        'gas'  => level_nilai($gas, TH_GAS_WASPADA, TH_GAS_BAHAYA),
        'api'  => $api ? 'bahaya' : 'aman',
    ];

    $overall = 'aman';

    foreach ($per as $s) {
        if (status_rank($s) > status_rank($overall)) {
            $overall = $s;
        }
    }

    return ['overall' => $overall, 'per' => $per];
}

function pesan_event(string $jenis, string $status): string
{
    $m = [
        'Sensor Suhu' => [
            'aman' => 'Suhu kembali normal',
            'waspada' => 'Suhu ruangan mulai tinggi',
            'bahaya' => 'Suhu sangat tinggi'
        ],
        'Sensor Asap' => [
            'aman' => 'Asap kembali normal',
            'waspada' => 'Asap pekat terdeteksi',
            'bahaya' => 'Asap berbahaya terdeteksi'
        ],
        'Sensor Gas' => [
            'aman' => 'Gas kembali normal',
            'waspada' => 'Kadar gas meningkat',
            'bahaya' => 'Kemungkinan kebocoran gas'
        ],
        'Sensor Api' => [
            'aman' => 'Api tidak terdeteksi',
            'waspada' => 'Terdeteksi api',
            'bahaya' => 'Terdeteksi api'
        ],
    ];

    return $m[$jenis][$status] ?? '';
}

function nilai_text(string $jenis, $suhu, $asap, $gas, bool $api): string
{
    switch ($jenis) {
        case 'Sensor Suhu':
            return $suhu === null ? '-' : number_format((float) $suhu, 1, ',', '') . '°C';

        case 'Sensor Asap':
            return $asap === null ? '-' : ((int) $asap) . ' ppm';

        case 'Sensor Gas':
            return $gas === null ? '-' : ((int) $gas) . '%';

        default:
            return $api ? 'Terdeteksi!!' : 'Tidak Terdeteksi';
    }
}

/* ---------- Kontrol perangkat ---------- */

/**
 * Mengambil status LED, Buzzer, dan Kipas.
 *
 * @return array{led:int,buzzer:int,kipas:int}
 */
function ambil_controls(): array
{
    $default = [
        'led' => 0,
        'buzzer' => 0,
        'kipas' => 0,
    ];

    $st = db()->prepare(
        'SELECT led, buzzer, kipas
         FROM device_controls
         WHERE id_alat = ?
         LIMIT 1'
    );

    $st->execute([CEKAPI_ID_ALAT]);
    $row = $st->fetch();

    if (!$row) {
        return $default;
    }

    return [
        'led' => (int) $row['led'],
        'buzzer' => (int) $row['buzzer'],
        'kipas' => (int) $row['kipas'],
    ];
}

/* ---------- Catatan aktivitas ---------- */

/**
 * Mencatat perubahan kontrol atau aktivitas sensor ke tabel riwayat.
 *
 * $jenis  : LED, Buzzer, Kipas, atau jenis sensor
 * $nilai  : ON, OFF, atau nilai sensor
 * $pesan  : keterangan aktivitas
 * $sumber : manual atau sensor
 * $status : aman, waspada, atau bahaya
 */
function catat_aktivitas(
    string $jenis,
    string $nilai,
    string $pesan,
    string $sumber = 'manual',
    string $status = 'aman'
): void {
    if (!in_array($sumber, ['manual', 'sensor'], true)) {
        throw new InvalidArgumentException('Sumber aktivitas tidak valid.');
    }

    if (!in_array($status, STATUS_LIST, true)) {
        throw new InvalidArgumentException('Status aktivitas tidak valid.');
    }

    $st = db()->prepare(
        'INSERT INTO riwayat
            (jenis_sensor, nilai, status, pesan, sumber)
         VALUES (?, ?, ?, ?, ?)'
    );

    $st->execute([
        mb_substr($jenis, 0, 30),
        mb_substr($nilai, 0, 40),
        $status,
        mb_substr($pesan, 0, 160),
        $sumber
    ]);
}

/**
 * Mengambil aktivitas terbaru dari tabel riwayat.
 */
function ambil_aktivitas(int $limit = 8): array
{
    $limit = max(1, min($limit, 100));

    $st = db()->prepare(
        'SELECT pesan, created_at
         FROM riwayat
         WHERE sumber = ?
         ORDER BY id DESC
         LIMIT ?'
    );

    $st->bindValue(1, 'manual', PDO::PARAM_STR);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();

    return array_map(
        fn($r) => [
            'waktu' => date('H.i', strtotime($r['created_at'])),
            'pesan' => $r['pesan']
        ],
        $st->fetchAll()
    );
}

/* ---------- Peringatan ---------- */

function hitung_peringatan_hari_ini(): int
{
    try {
        $st = db()->query(
            "SELECT COUNT(*)
             FROM riwayat
             WHERE status <> 'aman'
             AND created_at >= CURDATE()"
        );

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/* ---------- Filter halaman riwayat ---------- */

/**
 * @return array{where:string, params:array, q:string, from:string, to:string}
 */
function riwayat_filter(array $get): array
{
    $q = trim((string) ($get['q'] ?? ''));
    $from = (string) ($get['from'] ?? '');
    $to = (string) ($get['to'] ?? '');

    $okDate = fn($d) =>
        (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && strtotime($d) !== false;

    if (!$okDate($from)) $from = '';
    if (!$okDate($to)) $to = '';

    $w = [];
    $p = [];

    if ($q !== '') {
        $w[] = '(jenis_sensor LIKE ? OR nilai LIKE ? OR status LIKE ? OR pesan LIKE ? OR id LIKE ?)';

        $like = '%' . addcslashes($q, '%_\\') . '%';

        array_push($p, $like, $like, $like, $like, $like);
    }

    if ($from !== '') {
        $w[] = 'created_at >= ?';
        $p[] = $from . ' 00:00:00';
    }

    if ($to !== '') {
        $w[] = 'created_at <= ?';
        $p[] = $to . ' 23:59:59';
    }

    return [
        'where' => $w ? 'WHERE ' . implode(' AND ', $w) : '',
        'params' => $p,
        'q' => $q,
        'from' => $from,
        'to' => $to
    ];
}
