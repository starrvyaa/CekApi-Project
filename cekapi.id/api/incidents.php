<?php
/**
 * GET /api/incidents.php  - 5 kejadian terakhir untuk "Log Insiden Aktif"
 * Filter Anti-Spam: Menggabungkan insiden dengan pesan sama dalam 1 menit
 */
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

try {
    // Ambil lebih banyak data (misal 50) untuk kita saring menjadi 5 data unik
    $sql = "SELECT a.nama_alat, ds.waktu_rekam, ds.status_sistem, ds.suhu, ds.kadar_asap, ds.kadar_gas 
            FROM data_sensor ds
            LEFT JOIN alat a ON ds.id_alat = a.id_alat
            WHERE ds.status_sistem IN ('waspada', 'bahaya')
            ORDER BY ds.id_log DESC LIMIT 50";
            
    $rows = db()->query($sql)->fetchAll();
    
    $out = [];
    $last_pesan = null;
    $last_waktu = null;
    
    foreach ($rows as $r) {
        // Menyusun pesan berdasarkan nilai sensor
        $pesan_arr = [];
        if ((float)$r['suhu'] > 30.0) $pesan_arr[] = "Suhu: " . $r['suhu'] . "°C";
        if ((int)$r['kadar_asap'] >= 100) $pesan_arr[] = "Asap: " . $r['kadar_asap'] . " ppm";
        if ((float)$r['kadar_gas'] >= 20.0) $pesan_arr[] = "Gas: " . $r['kadar_gas'] . "%";
        
        $pesan = empty($pesan_arr) ? 'Kondisi tidak wajar' : implode(', ', $pesan_arr);
        $waktu_current = strtotime($r['waktu_rekam']);
        
        $is_duplicate = false;
        
        // Logika Pengecekan 1 Menit: 
        // Jika pesan sama persis dengan baris sebelumnya DAN jarak waktu <= 60 detik
        if ($last_pesan === $pesan && $last_waktu !== null) {
            if (abs($last_waktu - $waktu_current) <= 60) {
                $is_duplicate = true;
            }
        }
        
        // Jika bukan data duplikat (atau pesan berubah), masukkan ke daftar yang akan ditampilkan
       if (!$is_duplicate) {
            $out[] = [
                'alat'   => $r['nama_alat'] ?? 'Sensor Utama',
                'waktu'  => date('d-m-Y H:i:s', $waktu_current),
                
                // Tambahkan strtolower agar hurufnya jadi kecil semua
                'status' => strtolower($r['status_sistem']), 
                
                'pesan'  => $pesan,
            ];
            
            // Batasi hanya menampilkan 5 data unik terbaru di website
            if (count($out) >= 5) {
                break;
            }
        }
        
        // Simpan pesan & waktu saat ini untuk dibandingkan dengan data berikutnya
        $last_pesan = $pesan;
        $last_waktu = $waktu_current;
    }
    
    json_response(['ok' => true, 'items' => $out]);
} catch (Throwable $e) {
    error_log('[cekapi] incidents.php: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Kesalahan server'], 500);
}