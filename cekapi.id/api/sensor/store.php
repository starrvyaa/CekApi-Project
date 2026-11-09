<?php
// Mengizinkan format JSON dan metode POST
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

// 1. Konfigurasi Database (Sesuaikan jika ada password di Laragon)
$host = "localhost";
$user = "root";
$pass = "";
$db   = "cekapi";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(["status" => "error", "pesan" => "Koneksi database gagal: " . $conn->connect_error]));
}

// 2. API Key (Harus sama dengan di ESP32)
$API_KEY_SERVER = "CEKAPI_ESP32_2026_Rahasia_9xK7pQ2m";

// 3. Menangkap data JSON dari ESP32
$data = json_decode(file_get_contents("php://input"));

if (empty($data)) {
    echo json_encode(["status" => "error", "pesan" => "Tidak ada data yang masuk"]);
    exit;
}

// 4. Verifikasi API Key
if (!isset($data->api_key) || $data->api_key !== $API_KEY_SERVER) {
    echo json_encode(["status" => "error", "pesan" => "API Key salah! Akses ditolak."]);
    exit;
}

// 5. Mengambil nilai dari JSON (Mencocokkan dengan kolom tabel data_sensor)
$id_alat       = isset($data->id_alat) ? (int)$data->id_alat : 0;
$suhu          = isset($data->suhu) ? (float)$data->suhu : 0;
$kelembaban    = isset($data->kelembaban) ? (float)$data->kelembaban : 0;
$mq2_adc       = isset($data->mq2_adc) ? (int)$data->mq2_adc : 0;
$kadar_asap    = isset($data->kadar_asap) ? (float)$data->kadar_asap : 0;
$kadar_gas     = isset($data->kadar_gas) ? (float)$data->kadar_gas : 0;
$status_api    = isset($data->status_api) ? (int)$data->status_api : 0;
$status_sistem = isset($data->status_sistem) ? $conn->real_escape_string($data->status_sistem) : 'Aman';

// 6. Menyimpan data ke tabel data_sensor
$sql_insert = "INSERT INTO `data_sensor` 
              (`id_alat`, `suhu`, `kelembaban`, `mq2_adc`, `kadar_asap`, `kadar_gas`, `status_api`, `status_sistem`) 
              VALUES 
              ('$id_alat', '$suhu', '$kelembaban', '$mq2_adc', '$kadar_asap', '$kadar_gas', '$status_api', '$status_sistem')";

if ($conn->query($sql_insert) === TRUE) {
    // 7. Mengupdate kolom 'terakhir_terhubung' di tabel alat
    $sql_update = "UPDATE `alat` SET `terakhir_terhubung` = NOW() WHERE `id_alat` = '$id_alat'";
    $conn->query($sql_update);

    // 8. AUTO CLEANUP: Menghapus data lama (lebih dari 2 jam)
    // Ini memastikan tabel hanya berisi data paling baru (ID paling tinggi)
    $sql_cleanup = "DELETE FROM `data_sensor` WHERE `waktu_rekam` < (NOW() - INTERVAL 2 HOUR)";
    $conn->query($sql_cleanup);

    echo json_encode(["status" => "sukses", "pesan" => "Data sensor berhasil disimpan & data di atas 2 jam dihapus"]);
} else {
    echo json_encode(["status" => "error", "pesan" => "Gagal menyimpan data: " . $conn->error]);
}

$conn->close();
?>