<?php
// Mengizinkan format JSON dan metode POST
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
date_default_timezone_set('Asia/Jakarta');

// 1. Konfigurasi Database
$host = "localhost";
$user = "root";
$pass = "";
$db   = "cekapi";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(["status" => "error", "pesan" => "Koneksi database gagal: " . $conn->connect_error]));
}

// 2. API Key & Konfigurasi Telegram
$API_KEY_SERVER   = "CEKAPI_ESP32_2026_Rahasia_9xK7pQ2m";
$TELEGRAM_TOKEN   = "9104059661:AAET8TY7hPL-4RXHZq7UQ4vHWQzjiY0Z31c";
$TELEGRAM_CHAT_ID = "8934160326";

// Fungsi kirim pesan ke Telegram
function kirimNotifTelegram($token, $chatId, $pesan) {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id'    => $chatId,
        'text'       => $pesan,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// 3. Menangkap data
$data = json_decode(file_get_contents("php://input"));
if (empty($data)) {
    echo json_encode(["status" => "error", "pesan" => "Tidak ada data yang masuk"]);
    exit;
}

if (!isset($data->api_key) || $data->api_key !== $API_KEY_SERVER) {
    echo json_encode(["status" => "error", "pesan" => "API Key salah! Akses ditolak."]);
    exit;
}

// 5. Mengambil nilai
$id_alat       = isset($data->id_alat) ? (int)$data->id_alat : 0;
$suhu          = isset($data->suhu) ? (float)$data->suhu : 0;
$kelembaban    = isset($data->kelembaban) ? (float)$data->kelembaban : 0;
$mq2_adc       = isset($data->mq2_adc) ? (int)$data->mq2_adc : 0;
$kadar_asap    = isset($data->kadar_asap) ? (float)$data->kadar_asap : 0;
$kadar_gas     = isset($data->kadar_gas) ? (float)$data->kadar_gas : 0;
$status_api    = isset($data->status_api) ? (int)$data->status_api : 0;
$status_sistem = isset($data->status_sistem) ? $conn->real_escape_string($data->status_sistem) : 'Aman';

// 6. Menyimpan data
$sql_insert = "INSERT INTO `data_sensor` 
              (`id_alat`, `suhu`, `kelembaban`, `mq2_adc`, `kadar_asap`, `kadar_gas`, `status_api`, `status_sistem`) 
              VALUES 
              ('$id_alat', '$suhu', '$kelembaban', '$mq2_adc', '$kadar_asap', '$kadar_gas', '$status_api', '$status_sistem')";

if ($conn->query($sql_insert) === TRUE) {
    $sql_update = "UPDATE `alat` SET `terakhir_terhubung` = NOW() WHERE `id_alat` = '$id_alat'";
    $conn->query($sql_update);

    $sql_cleanup = "DELETE FROM `data_sensor` WHERE `waktu_rekam` < (NOW() - INTERVAL 2 HOUR)";
    $conn->query($sql_cleanup);

    // ==========================================================
    // 7. LOGIKA NOTIFIKASI TELEGRAM
    // ==========================================================
    $status_lower      = strtolower(trim($status_sistem));
    $lock_file         = sys_get_temp_dir() . "/cekapi_tele_state_{$id_alat}.json";
    $state_lama        = file_exists($lock_file)
                         ? json_decode(file_get_contents($lock_file), true)
                         : ['status' => 'aman'];
    $status_sebelumnya = $state_lama['status'] ?? 'aman';

    $teks_api  = ($status_api === 1) ? "🔥 TERDETEKSI!" : "Tidak Terdeteksi";
    $waktu_wib = date('d-m-Y H:i:s');

    if ($status_lower === 'bahaya') {
        // Selalu kirim ke Telegram setiap kali data Bahaya masuk
        $pesan_tele = "🚨 *PERINGATAN BAHAYA - CEKAPI.id* 🚨\n\n"
                    . "• *ID Alat:* #{$id_alat}\n"
                    . "• *Status Api:* {$teks_api}\n"
                    . "• *Suhu:* {$suhu} °C\n"
                    . "• *Kadar Asap:* {$kadar_asap} ppm\n"
                    . "• *Kadar Gas:* {$kadar_gas} %\n"
                    . "• *Waktu:* {$waktu_wib} WIB\n\n"
                    . "_Segera periksa lokasi atau cek panel kontrol perangkat!_";

        kirimNotifTelegram($TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID, $pesan_tele);
        file_put_contents($lock_file, json_encode(['status' => 'bahaya']));

    } elseif ($status_lower === 'waspada') {
        // Selalu kirim ke Telegram setiap kali data Waspada masuk
        $pesan_tele = "⚠️ *PERINGATAN WASPADA - CEKAPI.id* ⚠️\n\n"
                    . "• *ID Alat:* #{$id_alat}\n"
                    . "• *Status Api:* {$teks_api}\n"
                    . "• *Suhu:* {$suhu} °C\n"
                    . "• *Kadar Asap:* {$kadar_asap} ppm\n"
                    . "• *Kadar Gas:* {$kadar_gas} %\n"
                    . "• *Waktu:* {$waktu_wib} WIB\n\n"
                    . "_Parameter mulai meningkat, harap pantau kondisi sekitar._";

        kirimNotifTelegram($TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID, $pesan_tele);
        file_put_contents($lock_file, json_encode(['status' => 'waspada']));

    } elseif ($status_lower === 'aman') {
        // Kirim 1 kali saja jika status sebelumnya adalah Waspada atau Bahaya
        if ($status_sebelumnya === 'bahaya' || $status_sebelumnya === 'waspada') {
            $pesan_tele = "✅ *KONDISI KEMBALI AMAN - CEKAPI.id* ✅\n\n"
                        . "• *ID Alat:* #{$id_alat}\n"
                        . "• *Suhu:* {$suhu} °C\n"
                        . "• *Kadar Asap:* {$kadar_asap} ppm\n"
                        . "• *Kadar Gas:* {$kadar_gas} %\n"
                        . "• *Waktu:* {$waktu_wib} WIB\n\n"
                        . "_Situasi telah terkendali dan kembali normal._";

            kirimNotifTelegram($TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID, $pesan_tele);
        }

        // Kunci status ke 'aman' agar data Aman berikutnya tidak memicu pesan lagi
        if ($status_sebelumnya !== 'aman') {
            file_put_contents($lock_file, json_encode(['status' => 'aman']));
        }
    }

    echo json_encode(["status" => "sukses", "pesan" => "Data disimpan & dibersihkan"]);
} else {
    echo json_encode(["status" => "error", "pesan" => "Gagal menyimpan data: " . $conn->error]);
}
$conn->close();
?>