
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClient.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <DHT.h>

// ==================================================
// KONFIGURASI WIFI DAN SERVER CEKAPI.ID
// ==================================================

const char* WIFI_SSID     = "Kio";
const char* WIFI_PASSWORD = "1sampai10";

// Ganti IP berikut dengan IPv4 komputer yang menjalankan Laragon.
const char* SERVER_URL =
  "http://192.168.0.6/CekApi-Project/cekapi.id/api/sensor/store.php";

const char* CONTROL_URL =
  "http://192.168.0.6/CekApi-Project/cekapi.id/api/control.php";

// Samakan dengan API_KEY di config/app.php.
const char* API_KEY = "CEKAPI_ESP32_2026_Rahasia_9xK7pQ2m";

// Ganti dengan id_alat yang benar-benar terdaftar di tabel alat.
const int ID_ALAT = 2;

// Kirim data setiap 2 detik.
// Kirim data setiap 10 detik (10000 ms) atau 1 menit (60000 ms).
const unsigned long INTERVAL_KIRIM = 60000;
const unsigned long INTERVAL_WIFI = 10000;
const unsigned long INTERVAL_KONTROL = 3000;

// ==================================================
// OLED
// ==================================================

#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET -1
#define OLED_ADDRESS 0x3C

Adafruit_SSD1306 display(
  SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET
);

// ==================================================
// SENSOR
// ==================================================

#define DHT_PIN 23
#define DHT_TYPE DHT11
DHT dht(DHT_PIN, DHT_TYPE);

#define FLAME_PIN 14
#define GAS_PIN 34

const bool FLAME_ACTIVE_HIGH = false;

const int GAS_THRESHOLD = 600;
const float BATAS_SUHU = 30.0;

// ==================================================
// OUTPUT
// ==================================================

#define LED_HIJAU 4
#define LED_MERAH 18
#define BUZZER_PIN 27
#define KIPAS_PIN 26

const bool KIPAS_ACTIVE_LOW = true;

// ==================================================
// STATUS SENSOR
// ==================================================

bool apiTerdeteksi = false;
bool gasTerdeteksi = false;
bool suhuTinggi = false;
bool suhuValid = false;

bool kelembapanValid = false;
bool kondisiGabungan = false;
bool kipasMenyala = false;

float suhu = NAN;
float kelembapan = NAN;

int nilaiGas = 0;

// ==================================================
// KONTROL MANUAL DARI WEBSITE
// ==================================================

bool manualLED = false;
bool manualBuzzer = false;
bool manualKipas = false;

// ==================================================
// TIMER
// ==================================================

unsigned long waktuAlarm = 0;
unsigned long waktuKirim = 0;
unsigned long waktuWiFi = 0;
unsigned long waktuAmbilKontrol = 0;

bool statusBunyi = false;

// ==================================================
// KONEKSI WIFI
// ==================================================

void hubungkanWiFi() {
  if (WiFi.status() == WL_CONNECTED) {
    return;
  }

  Serial.println();
  Serial.println("Menghubungkan ke WiFi...");

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  unsigned long mulai = millis();

  while (WiFi.status() != WL_CONNECTED &&
         millis() - mulai < 15000) {
    delay(500);
    Serial.print(".");
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println();
    Serial.println("WiFi berhasil terhubung!");
    Serial.print("IP ESP32: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println();
    Serial.println("WiFi belum terhubung.");
  }
}

// ==================================================
// FUNGSI KIPAS
// ==================================================

void setKipas(bool nyala) {
  if (KIPAS_ACTIVE_LOW) {
    digitalWrite(KIPAS_PIN, nyala ? LOW : HIGH);
  } else {
    digitalWrite(KIPAS_PIN, nyala ? HIGH : LOW);
  }
}

// ==================================================
// MEMBACA KONTROL DARI WEBSITE
// ==================================================

bool bacaNilaiKontrol(String json, const char* nama, bool &hasil) {
  String key = "\"" + String(nama) + "\"";

  int posisi = json.indexOf(key);

  if (posisi < 0) return false;

  int titikDua = json.indexOf(':', posisi + key.length());

  if (titikDua < 0) return false;

  int awal = titikDua + 1;

  while (awal < json.length() &&
         (json[awal] == ' ' || json[awal] == '\n')) {
    awal++;
  }

  if (json.startsWith("true", awal) ||
      json.startsWith("1", awal)) {
    hasil = true;
    return true;
  }

  if (json.startsWith("false", awal) ||
      json.startsWith("0", awal)) {
    hasil = false;
    return true;
  }

  return false;
}

void ambilKontrolServer() {
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  WiFiClient client;
  HTTPClient http;

  if (!http.begin(client, CONTROL_URL)) {
    Serial.println("Gagal memulai koneksi kontrol.");
    return;
  }

  http.setTimeout(5000);
  http.addHeader("X-API-KEY", API_KEY);

  int httpCode = http.GET();

  if (httpCode >= 200 && httpCode < 300) {
    String response = http.getString();

    bool led, buzzer, kipas;

    bool valid =
      bacaNilaiKontrol(response, "led", led) &&
      bacaNilaiKontrol(response, "buzzer", buzzer) &&
      bacaNilaiKontrol(response, "kipas", kipas);

    if (valid) {
      manualLED = led;
      manualBuzzer = buzzer;
      manualKipas = kipas;

      Serial.println("Kontrol manual berhasil diperbarui.");
    } else {
      Serial.println("Format respons kontrol tidak valid.");
    }
  } else {
    Serial.print("Gagal mengambil kontrol. HTTP: ");
    Serial.println(httpCode);
  }

  http.end();
}

// ==================================================
// FUNGSI ALARM
// ==================================================

void aturAlarm() {
  unsigned long sekarang = millis();

  if (kondisiGabungan) {
    digitalWrite(LED_MERAH, HIGH);
    digitalWrite(LED_HIJAU, LOW);
    tone(BUZZER_PIN, 2500);
    return;
  }

  if (apiTerdeteksi) {
    if (sekarang - waktuAlarm >= 100) {
      waktuAlarm = sekarang;
      statusBunyi = !statusBunyi;

      if (statusBunyi) {
        tone(BUZZER_PIN, 2000);
      } else {
        noTone(BUZZER_PIN);
      }
    }

    digitalWrite(LED_MERAH, HIGH);
    digitalWrite(LED_HIJAU, LOW);
    return;
  }

  noTone(BUZZER_PIN);
  statusBunyi = false;

  if (gasTerdeteksi || suhuTinggi || !suhuValid) {
    digitalWrite(LED_MERAH, HIGH);
    digitalWrite(LED_HIJAU, LOW);
  } else {
    digitalWrite(LED_MERAH, LOW);
    digitalWrite(LED_HIJAU, HIGH);
  }
}

// ==================================================
// KENDALI MANUAL DAN OTOMATIS
// ==================================================

void aturOutput() {
  bool aman = strcmp(statusSistem(), "aman") == 0;

  if (aman) {
    digitalWrite(LED_MERAH, manualLED ? HIGH : LOW);
    digitalWrite(LED_HIJAU, manualLED ? HIGH : LOW);

    if (manualBuzzer) {
      tone(BUZZER_PIN, 2000);
    } else {
      noTone(BUZZER_PIN);
    }

    statusBunyi = false;

    kipasMenyala = manualKipas;
    setKipas(kipasMenyala);

    return;
  }

  // Logika otomatis tetap diprioritaskan.
  aturAlarm();

  // Logika kipas dipertahankan seperti program awal.
  if (kondisiGabungan || apiTerdeteksi) {
    kipasMenyala = false;
  } else {
    kipasMenyala = gasTerdeteksi || suhuTinggi;
  }

  setKipas(kipasMenyala);
}

// ==================================================
// BACA SENSOR
// ==================================================

void bacaSensor() {
  suhu = dht.readTemperature();
  kelembapan = dht.readHumidity();

  suhuValid = !isnan(suhu);
  kelembapanValid = !isnan(kelembapan);

  int nilaiApi = digitalRead(FLAME_PIN);

  apiTerdeteksi = FLAME_ACTIVE_HIGH
    ? (nilaiApi == HIGH)
    : (nilaiApi == LOW);

  nilaiGas = analogRead(GAS_PIN);
  gasTerdeteksi = (nilaiGas >= GAS_THRESHOLD);

  suhuTinggi = suhuValid && (suhu > BATAS_SUHU);

  kondisiGabungan =
    apiTerdeteksi &&
    gasTerdeteksi &&
    suhuTinggi;

  aturOutput();
}

// ==================================================
// STATUS SISTEM
// ==================================================

const char* statusSistem() {
  if (!suhuValid || !kelembapanValid) {
    return "waspada";
  }

  if (kondisiGabungan) {
    return "bahaya";
  }

  if (apiTerdeteksi) {
    return "bahaya";
  }

  if (gasTerdeteksi || suhuTinggi) {
    return "waspada";
  }

  return "aman";
}

// ==================================================
// TAMPILKAN OLED
// ==================================================

void tampilkanOLED() {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);

  display.setCursor(0, 0);
  display.println("MONITOR KEAMANAN");
  display.println("----------------");

  display.setCursor(0, 16);
  display.print("Suhu: ");

  if (suhuValid) {
    display.print(suhu, 1);
    display.println(" C");
  } else {
    display.println("ERROR");
  }

  display.setCursor(0, 26);
  display.print("Api: ");
  display.println(apiTerdeteksi ? "TERDETEKSI" : "Tidak");

  display.setCursor(0, 36);
  display.print("Gas/Asap ADC: ");
  display.println(nilaiGas);

  display.setCursor(0, 46);
  display.print("Kipas: ");
  display.println(kipasMenyala ? "NYALA" : "MATI");

  display.setCursor(0, 56);
  display.print("WiFi: ");
  display.print(
    WiFi.status() == WL_CONNECTED ? "ONLINE" : "OFFLINE"
  );

  display.display();
}

// ==================================================
// SERIAL MONITOR
// ==================================================

void tampilkanSerial() {
  Serial.print("Suhu: ");

  if (suhuValid) Serial.print(suhu, 1);
  else Serial.print("ERROR");

  Serial.print(" C | Kelembapan: ");

  if (kelembapanValid) Serial.print(kelembapan, 1);
  else Serial.print("ERROR");

  Serial.print(" % | Api: ");
  Serial.print(apiTerdeteksi ? "YA" : "TIDAK");

  Serial.print(" | MQ2 ADC: ");
  Serial.print(nilaiGas);

  Serial.print(" | Status: ");
  Serial.print(statusSistem());

  Serial.print(" | Kipas: ");
  Serial.println(kipasMenyala ? "NYALA" : "MATI");
}

// ==================================================
// KIRIM DATA KE PHP API
// ==================================================

void kirimDataServer() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Data belum dikirim: WiFi terputus.");
    return;
  }

  if (!suhuValid || !kelembapanValid) {
    Serial.println("Data belum dikirim: pembacaan DHT tidak valid.");
    return;
  }

  WiFiClient client;
  HTTPClient http;

  if (!http.begin(client, SERVER_URL)) {
    Serial.println("Gagal memulai koneksi HTTP.");
    return;
  }

  http.setTimeout(5000);

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-KEY", API_KEY);

  // Konversi nilai ADC (0-4095) menjadi persentase Gas (0-100%)
  float persenGas = (nilaiGas / 4095.0) * 100.0;

  String json = "{";
  json += "\"api_key\":\"" + String(API_KEY) + "\",";
  json += "\"id_alat\":" + String(ID_ALAT) + ",";
  json += "\"suhu\":" + String(suhu, 1) + ",";
  json += "\"kelembaban\":" + String(kelembapan, 1) + ",";
  json += "\"mq2_adc\":" + String(nilaiGas) + ",";

  // MENGUBAH null MENJADI NILAI SENSOR:
  json += "\"kadar_asap\":" + String(nilaiGas) + ",";
  json += "\"kadar_gas\":" + String(persenGas, 1) + ",";

  json += "\"status_api\":" + String(apiTerdeteksi ? 1 : 0) + ",";
  json += "\"status_sistem\":\"" + String(statusSistem()) + "\"";
  json += "}";

  Serial.println();
  Serial.println("Mengirim data ke server...");
  Serial.println(json);

  int httpCode = http.POST(json);

  if (httpCode > 0) {
    Serial.print("HTTP Code: ");
    Serial.println(httpCode);

    String response = http.getString();

    Serial.print("Respons server: ");
    Serial.println(response);

    if (httpCode >= 200 && httpCode < 300) {
      Serial.println("Pengiriman berhasil.");
    } else {
      Serial.println("Server menolak atau gagal memproses data.");
    }
  } else {
    Serial.print("HTTP gagal: ");
    Serial.println(http.errorToString(httpCode));
  }

  http.end();
}

// ==================================================
// SETUP
// ==================================================

void setup() {
  Serial.begin(115200);

  pinMode(FLAME_PIN, INPUT);
  pinMode(LED_HIJAU, OUTPUT);
  pinMode(LED_MERAH, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(KIPAS_PIN, OUTPUT);

  analogReadResolution(12);

  digitalWrite(LED_HIJAU, LOW);
  digitalWrite(LED_MERAH, LOW);

  noTone(BUZZER_PIN);
  setKipas(false);

  dht.begin();

  Wire.begin(21, 22);

  if (!display.begin(
        SSD1306_SWITCHCAPVCC, OLED_ADDRESS)) {
    Serial.println("OLED tidak terdeteksi!");

    while (true) {
      delay(1000);
    }
  }

  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 0);

  display.println("MONITOR KEAMANAN");
  display.println("Memulai sensor...");
  display.display();

  delay(2000);

  // Hubungkan WiFi setelah OLED dan sensor siap.
  hubungkanWiFi();

  Serial.println();
  Serial.println("Sistem CEKAPI.id siap.");
}

// ==================================================
// LOOP
// ==================================================

void loop() {
  unsigned long sekarang = millis();

  // 1. Baca sensor.
  // 2. Tampilkan OLED.
  // 3. Tampilkan Serial Monitor.
  // 4. Coba sambungkan ulang WiFi secara berkala.

  if (WiFi.status() != WL_CONNECTED &&
      sekarang - waktuWiFi >= INTERVAL_WIFI) {
    waktuWiFi = sekarang;
    hubungkanWiFi();
  }

  // Ambil pengaturan kontrol dari website.
  if (WiFi.status() == WL_CONNECTED &&
      sekarang - waktuAmbilKontrol >= INTERVAL_KONTROL) {
    waktuAmbilKontrol = sekarang;
    ambilKontrolServer();
  }

  bacaSensor();
  tampilkanOLED();
  tampilkanSerial();

  // 5. Kirim data setiap 2 detik.
  if (sekarang - waktuKirim >= INTERVAL_KIRIM) {
    waktuKirim = sekarang;
    kirimDataServer();
  }

  delay(1000);
}
