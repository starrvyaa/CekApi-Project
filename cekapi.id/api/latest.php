<?php
/**
 * GET /api/latest.php  - data sensor terbaru untuk kartu real-time (login admin)
 */
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

try {
    // 1. Sesuaikan nama tabel dan primary key
    $row = db()->query('SELECT * FROM data_sensor ORDER BY id_log DESC LIMIT 1')->fetch();
    
    if (!$row) {
        json_response(['ok' => true, 'online' => false, 'data' => null, 'warnings_today' => hitung_peringatan_hari_ini()]);
    }
    
    // 2. Sesuaikan nama kolom waktu
    $age = time() - strtotime($row['waktu_rekam']);
    
    // 3. Sesuaikan nama kolom dari database ke format JSON yang diminta JavaScript
    $data = [
        'suhu'       => $row['suhu'] === null ? null : (float) $row['suhu'],
        'kelembaban' => $row['kelembaban'] === null ? null : (float) $row['kelembaban'],
        'asap'       => $row['kadar_asap'] === null ? null : (int) $row['kadar_asap'],
        'gas'        => $row['kadar_gas'] === null ? null : (int) $row['kadar_gas'],
        'api'        => (int) $row['status_api'],
    ];
    
    $cls = klasifikasi($data['suhu'], $data['asap'], $data['gas'], (bool) $data['api']);

    json_response([
        'ok'             => true,
        'online'         => $age <= DEVICE_OFFLINE_SECONDS,
        'updated_at'     => fmt_waktu($row['waktu_rekam']), // Sesuaikan kolom waktu
        'age'            => $age,
        'data'           => $data,
        'status'         => ['overall' => $cls['overall']] + $cls['per'],
        'warnings_today' => hitung_peringatan_hari_ini(),
    ]);
} catch (Throwable $e) {
    error_log('[cekapi] latest.php: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Kesalahan server'], 500);
}