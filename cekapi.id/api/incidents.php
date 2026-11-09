<?php
/**
 * GET /api/incidents.php  - 5 kejadian terakhir untuk "Log Insiden Aktif" (login admin)
 */
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

try {
    // Mengambil dari data_sensor yang berstatus waspada/bahaya
    $sql = "SELECT a.nama_alat, ds.waktu_rekam, ds.status_sistem, ds.suhu, ds.kadar_asap, ds.kadar_gas 
            FROM data_sensor ds
            LEFT JOIN alat a ON ds.id_alat = a.id_alat
            WHERE ds.status_sistem IN ('waspada', 'bahaya')
            ORDER BY ds.id_log DESC LIMIT 5";
            
    $rows = db()->query($sql)->fetchAll();
    
    $out = array_map(static function($r) {
        // Menyusun pesan berdasarkan nilai sensor
        $pesan = [];
        if ((float)$r['suhu'] > 30.0) $pesan[] = "Suhu: " . $r['suhu'] . "°C";
        if ((int)$r['kadar_asap'] >= 100) $pesan[] = "Asap: " . $r['kadar_asap'] . " ppm";
        if ((float)$r['kadar_gas'] >= 20.0) $pesan[] = "Gas: " . $r['kadar_gas'] . "%";
        
        return [
            'alat'   => $r['nama_alat'] ?? 'Sensor Utama',
            'waktu'  => date('d-m-Y H:i:s', strtotime($r['waktu_rekam'])),
            'status' => $r['status_sistem'],
            'pesan'  => empty($pesan) ? 'Kondisi tidak wajar' : implode(', ', $pesan),
        ];
    }, $rows);
    
    json_response(['ok' => true, 'items' => $out]);
} catch (Throwable $e) {
    error_log('[cekapi] incidents.php: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Kesalahan server'], 500);
}