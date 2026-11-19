#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClient.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <DHT.h>
#include <PubSubClient.h>

// ==================================================
// KONFIGURASI WIFI DAN SERVER
// ==================================================
const char* WIFI_SSID     = "SKK - STUDENT";
const char* WIFI_PASSWORD = "";

// IPv4 komputer yang menjalankan Laragon
const char* SERVER_URL    = "http://10.10.7.237/CekApi-Project/cekapi.id/api/sensor/store.php";
const char* CONTROL_URL   = "http://10.10.7.237/CekApi-Project/cekapi.id/api/control.php";
const char* API_KEY       = "CEKAPI_ESP32_2026_Rahasia_9xK7pQ2m";
const int ID_ALAT         = 1;

// ==================================================
// KONFIGURASI MQTT (UNTUK REAL-TIME WEB)
// ==================================================
const char* MQTT_BROKER   = "broker.hivemq.com";
const int MQTT_PORT       = 1883;
const char* MQTT_TOPIC    = "cekapi/sensor/realtime/1";

WiFiClient espClient;
PubSubClient mqttClient(espClient);

// ==================================================
// PENGATURAN WAKTU (TIMER MULTITASKING)
// ==================================================
const unsigned long INTERVAL_KIRIM   = 30000; // Kirim ke Database: 30 Detik
const unsigned long INTERVAL_MQTT    = 3000;  // Kirim ke Web UI: 3 Detik
const unsigned long INTERVAL_KONTROL = 5000;  // Ambil data kontrol dari Web: 5 Detik
const unsigned long INTERVAL_SENSOR  = 1000;  // Baca sensor & OLED: 1 Detik
const unsigned long INTERVAL_WIFI    = 10000; // Cek WiFi: 10 Detik

// ==================================================
// OLED & PIN
// ==================================================
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET -1
#define OLED_ADDRESS 0x3C
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

#define DHT_PIN 23
#define DHT_TYPE DHT11
DHT dht(DHT_PIN, DHT_TYPE);

#define FLAME_PIN 14
#define GAS_PIN 34
const bool FLAME_ACTIVE_HIGH = false;

// Ambang batas baru: Gas/Asap Pekat > 2000, Suhu Panas >= 30.0
const int GAS_THRESHOLD   = 2000;
const float BATAS_SUHU    = 30.0;

#define LED_HIJAU 4
#define LED_MERAH 18
#define BUZZER_PIN 27
#define KIPAS_PIN 26
const bool KIPAS_ACTIVE_LOW = true;

// ==================================================
// VARIABEL GLOBAL
// ==================================================
bool apiTerdeteksi = false, gasTerdeteksi = false, suhuTinggi = false;
bool suhuValid = false, kelembapanValid = false, kondisiGabungan = false, kipasMenyala = false;
float suhu = NAN, kelembapan = NAN;
int nilaiGas = 0;
bool manualLED = false, manualBuzzer = false, manualKipas = false;

unsigned long waktuSensor = 0;
unsigned long waktuAlarm = 0;
unsigned long waktuKirim = 0;
unsigned long waktuMQTT = 0;
unsigned long waktuWiFi = 0;
unsigned long waktuAmbilKontrol = 0;
bool statusBunyi = false;

// ==================================================
// FUNGSI STATUS SISTEM
// ==================================================
const char* statusSistem() {
  if (!suhuValid || !kelembapanValid) return "waspada";
  if (kondisiGabungan || apiTerdeteksi) return "bahaya";
  if (gasTerdeteksi || suhuTinggi) return "waspada";
  return "aman";
}

// ==================================================
// FUNGSI KONEKSI
// ==================================================
void hubungkanWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.println("\nMenghubungkan ke WiFi...");
  WiFi.mode(WIFI_STA);
  WiFi.disconnect(true);
  delay(200);

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  unsigned long mulai = millis();
  while (WiFi.status() != WL_CONNECTED && (millis() - mulai) < 15000) {
    delay(500);
    Serial.print(".");
  }

  if (WiFi.status() == WL_CONNECTED) {
    WiFi.setSleep(false);
    Serial.println("\nWiFi terhubung! IP ESP32: " + WiFi.localIP().toString());
  } else {
    Serial.println("\nWiFi gagal terhubung.");
  }
}

void reconnectMQTT() {
  if (WiFi.status() != WL_CONNECTED) return;
  if (!mqttClient.connected()) {
    String clientId = "ESP32-CekApi-" + String(random(0xffff), HEX);
    if (mqttClient.connect(clientId.c_str())) {
      Serial.println("MQTT Terhubung!");
    } else {
      Serial.print("MQTT Gagal, rc=");
      Serial.println(mqttClient.state());
    }
  }
}

// ==================================================
// FUNGSI KONTROL & ALARM
// ==================================================
void setKipas(bool nyala) {
  digitalWrite(KIPAS_PIN, (KIPAS_ACTIVE_LOW ? !nyala : nyala));
}

bool bacaNilaiKontrol(String json, const char* nama, bool &hasil) {
  String key = "\"" + String(nama) + "\"";
  int posisi = json.indexOf(key);
  if (posisi < 0) return false;
  int titikDua = json.indexOf(':', posisi + key.length());
  if (titikDua < 0) return false;
  int awal = titikDua + 1;
  while (awal < (int)json.length() && (json[awal] == ' ' || json[awal] == '\n')) awal++;
  if (json.startsWith("true", awal) || json.startsWith("1", awal)) { hasil = true; return true; }
  if (json.startsWith("false", awal) || json.startsWith("0", awal)) { hasil = false; return true; }
  return false;
}

void ambilKontrolServer() {
  if (WiFi.status() != WL_CONNECTED) return;

  WiFiClient client;
  HTTPClient http;

  if (http.begin(client, CONTROL_URL)) {
    http.setTimeout(5000);
    http.addHeader("X-API-KEY", API_KEY);

    int httpCode = http.GET();

    Serial.print("[KONTROL] HTTP Code: ");
    Serial.println(httpCode);

    if (httpCode >= 200 && httpCode < 300) {
      String payload = http.getString();

      Serial.print("[KONTROL] Respon Server: ");
      Serial.println(payload);

      bool led, buzzer, kipas;

      if (bacaNilaiKontrol(payload, "led", led) &&
          bacaNilaiKontrol(payload, "buzzer", buzzer) &&
          bacaNilaiKontrol(payload, "kipas", kipas)) {

        manualLED = led;
        manualBuzzer = buzzer;
        manualKipas = kipas;

        Serial.println("[KONTROL] Data berhasil dibaca!");
        Serial.print("[KONTROL] LED: ");
        Serial.println(manualLED);
        Serial.print("[KONTROL] Buzzer: ");
        Serial.println(manualBuzzer);
        Serial.print("[KONTROL] Kipas: ");
        Serial.println(manualKipas);

      } else {
        Serial.println("[KONTROL] Gagal membaca data JSON!");
      }
    }

    http.end();
  }
}

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
      statusBunyi ? tone(BUZZER_PIN, 3000) : noTone(BUZZER_PIN);
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

void aturOutput() {
  if (strcmp(statusSistem(), "aman") == 0) {
    digitalWrite(LED_MERAH, manualLED ? HIGH : LOW);
    digitalWrite(LED_HIJAU, manualLED ? HIGH : LOW);
    manualBuzzer ? tone(BUZZER_PIN, 2000) : noTone(BUZZER_PIN);
    statusBunyi = false;
    kipasMenyala = manualKipas;
    setKipas(kipasMenyala);
    return;
  }
  aturAlarm();
  kipasMenyala = (kondisiGabungan || apiTerdeteksi) ? false : (gasTerdeteksi || suhuTinggi);
  setKipas(kipasMenyala);
}

// ==================================================
// PEMBACAAN SENSOR & STATUS
// ==================================================
void bacaSensor() {
  suhu = dht.readTemperature();
  kelembapan = dht.readHumidity();
  suhuValid = !isnan(suhu);
  kelembapanValid = !isnan(kelembapan);

  int nilaiApi = digitalRead(FLAME_PIN);
  apiTerdeteksi = FLAME_ACTIVE_HIGH ? (nilaiApi == HIGH) : (nilaiApi == LOW);

  nilaiGas = analogRead(GAS_PIN);
  gasTerdeteksi = (nilaiGas > GAS_THRESHOLD);          // Di atas 2000 = Gas / Asap Pekat
  suhuTinggi = suhuValid && (suhu >= BATAS_SUHU);      // 30.0 C ke atas = Panas
  kondisiGabungan = apiTerdeteksi && gasTerdeteksi && suhuTinggi;

  aturOutput();
}

// ==================================================
// TAMPILAN OLED & SERIAL
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
    display.print(" C");
    if (suhuTinggi) display.print(" (PANAS)");
    display.println();
  } else {
    display.println("ERROR");
  }

  display.setCursor(0, 26);
  display.print("Api: ");
  display.println(apiTerdeteksi ? "TERDETEKSI" : "Tidak");

  display.setCursor(0, 36);
  display.print("Gas/Asap: ");
  display.print(nilaiGas);
  if (gasTerdeteksi) display.print(" (PEKAT)");
  display.println();

  display.setCursor(0, 46);
  display.print("Kipas: ");
  display.println(kipasMenyala ? "NYALA" : "MATI");

  display.setCursor(0, 56);
  display.print("WiFi: ");
  display.print(WiFi.status() == WL_CONNECTED ? "ONLINE" : "OFFLINE");

  display.display();
}

void tampilkanSerial() {
  Serial.print("Suhu: ");
  if (suhuValid) {
    Serial.print(suhu, 1);
    Serial.print(suhuTinggi ? " C (PANAS)" : " C");
  } else {
    Serial.print("ERROR");
  }
  Serial.print(" | Kelembapan: ");
  if (kelembapanValid) Serial.print(kelembapan, 1); else Serial.print("ERROR");
  Serial.print(" % | Api: "); Serial.print(apiTerdeteksi ? "YA" : "TIDAK");
  Serial.print(" | MQ2 ADC: "); Serial.print(nilaiGas);
  if (gasTerdeteksi) Serial.print(" (PEKAT)");
  Serial.print(" | Status: "); Serial.print(statusSistem());
  Serial.print(" | Kipas: "); Serial.println(kipasMenyala ? "NYALA" : "MATI");
}

// ==================================================
// PENGIRIMAN DATA (MQTT & HTTP)
// ==================================================
void kirimDataMQTT() {
  if (WiFi.status() != WL_CONNECTED) return;
  if (!mqttClient.connected()) reconnectMQTT();
  if (mqttClient.connected()) {
    float persenGas = (nilaiGas / 4095.0) * 100.0;
    String json = "{";
    json += "\"suhu\":" + String(suhuValid ? suhu : 0, 1) + ",";
    json += "\"kadar_asap\":" + String(nilaiGas) + ",";
    json += "\"kadar_gas\":" + String(persenGas, 1) + ",";
    json += "\"status_api\":" + String(apiTerdeteksi ? 1 : 0) + ",";
    json += "\"status_sistem\":\"" + String(statusSistem()) + "\"";
    json += "}";
    mqttClient.publish(MQTT_TOPIC, json.c_str());
  }
}

void kirimDataServer() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[DB] Gagal kirim: WiFi OFFLINE");
    return;
  }
  if (!suhuValid || !kelembapanValid) {
    Serial.println("[DB] Batal kirim: Sensor DHT11 ERROR (cek kabel pin 23)");
    return;
  }

  WiFiClient client;
  HTTPClient http;
  Serial.println("[DB] Mengirim data ke Laragon...");

  if (http.begin(client, SERVER_URL)) {
    http.setTimeout(5000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-KEY", API_KEY);
    float persenGas = (nilaiGas / 4095.0) * 100.0;

    String json = "{";
    json += "\"api_key\":\"" + String(API_KEY) + "\",";
    json += "\"id_alat\":" + String(ID_ALAT) + ",";
    json += "\"suhu\":" + String(suhu, 1) + ",";
    json += "\"kelembaban\":" + String(kelembapan, 1) + ",";
    json += "\"mq2_adc\":" + String(nilaiGas) + ",";
    json += "\"kadar_asap\":" + String(nilaiGas) + ",";
    json += "\"kadar_gas\":" + String(persenGas, 1) + ",";
    json += "\"status_api\":" + String(apiTerdeteksi ? 1 : 0) + ",";
    json += "\"status_sistem\":\"" + String(statusSistem()) + "\"";
    json += "}";

    int httpCode = http.POST(json);
    Serial.println("[DB] HTTP Code: " + String(httpCode));
    if (httpCode > 0) {
      Serial.println("[DB] Respon Server: " + http.getString());
    } else {
      Serial.println("[DB] Error Koneksi: " + http.errorToString(httpCode));
    }
    http.end();
  }
}

// ==================================================
// SETUP & LOOP
// ==================================================
void setup() {
  Serial.begin(115200);
  delay(500);

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

  if (!display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDRESS)) {
    Serial.println("OLED tidak terdeteksi!");
    while (true) { delay(1000); }
  }

  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println("MONITOR KEAMANAN");
  display.println("Memulai sistem...");
  display.display();
  delay(2000);

  hubungkanWiFi();
  mqttClient.setServer(MQTT_BROKER, MQTT_PORT);
}

void loop() {
  unsigned long sekarang = millis();

  // 1. Cek Koneksi WiFi
  if (WiFi.status() != WL_CONNECTED && sekarang - waktuWiFi >= INTERVAL_WIFI) {
    waktuWiFi = sekarang;
    hubungkanWiFi();
  }

  // 2. Pertahankan Jalur MQTT Tetap Hidup Terus
  mqttClient.loop();

  // 3. Baca Sensor & Update OLED tiap 1 Detik
  if (sekarang - waktuSensor >= INTERVAL_SENSOR) {
    waktuSensor = sekarang;
    bacaSensor();
    tampilkanOLED();
    tampilkanSerial();
  }

  // 4. Kirim Data Ke Web (MQTT) tiap 3 Detik
  if (sekarang - waktuMQTT >= INTERVAL_MQTT) {
    waktuMQTT = sekarang;
    kirimDataMQTT();
  }

  // 5. Ambil status Kontrol dari Server tiap 5 Detik
  if (WiFi.status() == WL_CONNECTED && sekarang - waktuAmbilKontrol >= INTERVAL_KONTROL) {
    waktuAmbilKontrol = sekarang;
    ambilKontrolServer();
  }

  // 6. Simpan Seluruh Data ke Database tiap 30 Detik
  if (sekarang - waktuKirim >= INTERVAL_KIRIM) {
    waktuKirim = sekarang;
    kirimDataServer();
  }
}