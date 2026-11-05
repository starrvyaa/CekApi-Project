/* Dashboard real-time: kartu (polling 3 dtk), grafik & log insiden (polling 10 dtk) */
(() => {
  "use strict";
  const base = window.CEKAPI.base;
  const $ = (s, r = document) => r.querySelector(s);
  const LABEL = { aman: "NORMAL", waspada: "WASPADA", bahaya: "BAHAYA" };
  const BADGE = { aman: "Aman", waspada: "Waspada", bahaya: "Bahaya" };
  const baseTitle = document.title;
  let chart = null,
    range = "live";

  // Variabel Paginasi Log Insiden
  let allIncidents = [];
  let currentPage = 1;
  const rowsPerPage = 5;

  async function getJSON(path) {
    const res = await fetch(base + "/api/" + path, {
      credentials: "same-origin",
      cache: "no-store",
    });
    if (res.status === 401) {
      location.href = base + "/login.php";
      throw new Error("Sesi berakhir");
    }
    if (!res.ok) throw new Error("HTTP " + res.status);
    return res.json();
  }

  function paint(id, state, value, text) {
    const el = $("#card-" + id);
    if (!el) return;
    el.dataset.state = state;
    if (value !== null && value !== undefined) {
      const v = $("[data-val]", el);
      if (v) v.textContent = value;
    }
    $("[data-state-text]", el).textContent = text || LABEL[state];
  }

  /* ---------- Kartu Indikator ---------- */
  async function pollLatest() {
    const conn = $("#conn"),
      cards = $("#cards");
    try {
      const d = await getJSON("latest.php");
      if (!d.data) {
        conn.className = "conn offline";
        conn.textContent = "Belum ada data dari perangkat";
        cards.classList.add("stale");
        return;
      }
      const on = d.online,
        s = d.status,
        x = d.data;
      conn.className = "conn " + (on ? "online" : "offline");
      conn.textContent =
        (on ? "Perangkat online · " : "Perangkat offline · data terakhir ") +
        d.updated_at;
      cards.classList.toggle("stale", !on);

      const st = (k) => (on ? s[k] : "aman");
      const tx = (k, t) => (on ? t : "OFFLINE");
      paint(
        "suhu",
        st("suhu"),
        x.suhu === null ? "--" : x.suhu.toFixed(1).replace(".", ","),
        tx("suhu", LABEL[s.suhu]),
      );
      paint(
        "asap",
        st("asap"),
        x.asap === null ? "--" : x.asap,
        tx("asap", LABEL[s.asap]),
      );
      paint(
        "gas",
        st("gas"),
        x.gas === null ? "--" : x.gas,
        tx("gas", LABEL[s.gas]),
      );
      paint(
        "api",
        st("api"),
        null,
        tx("api", x.api ? "TERDETEKSI" : "TIDAK TERDETEKSI"),
      );

      const bar = $("[data-bar]", $("#card-suhu"));
      if (bar && x.suhu !== null)
        bar.style.height =
          Math.max(0, Math.min(100, (x.suhu / 60) * 100)) + "%";

      document.title =
        (on && s.overall === "bahaya" ? "⚠ BAHAYA · " : "") + baseTitle;
    } catch (e) {
      conn.className = "conn offline";
      conn.textContent = "Koneksi ke server terputus";
    }
  }

  /* ---------- Log Insiden & Paginasi ---------- */
  function renderIncidentsTable() {
    const tbody = $("#incidents");
    const btnPrev = $("#btn-prev");
    const btnNext = $("#btn-next");
    const pgInfo = $("#pg-info");

    if (!tbody) return;

    if (!allIncidents.length) {
      tbody.innerHTML =
        '<tr><td class="empty" colspan="4">Belum ada insiden tercatat.</td></tr>';
      if (btnPrev) btnPrev.disabled = true;
      if (btnNext) btnNext.disabled = true;
      if (pgInfo) pgInfo.textContent = "1";
      return;
    }

    const totalPages = Math.ceil(allIncidents.length / rowsPerPage);
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    const start = (currentPage - 1) * rowsPerPage;
    const pageData = allIncidents.slice(start, start + rowsPerPage);

    tbody.innerHTML = pageData
      .map(
        (it) => `
      <tr>
        <td>${it.alat || "-"}</td>
        <td>${it.waktu || "-"}</td>
        <td><span class="badge ${it.status || ""}">${BADGE[it.status] || it.status || "-"}</span></td>
        <td>${it.pesan || "-"}</td>
      </tr>
    `,
      )
      .join("");

    if (pgInfo) pgInfo.textContent = `${currentPage} / ${totalPages}`;
    if (btnPrev) btnPrev.disabled = currentPage === 1;
    if (btnNext) btnNext.disabled = currentPage === totalPages;
  }

  async function loadIncidents() {
    try {
      const d = await getJSON("incidents.php");
      allIncidents = d.items || [];
      renderIncidentsTable();
    } catch (e) {
      /* biarkan data lama */
    }
  }

  $("#btn-prev")?.addEventListener("click", () => {
    if (currentPage > 1) {
      currentPage--;
      renderIncidentsTable();
    }
  });

  $("#btn-next")?.addEventListener("click", () => {
    const totalPages = Math.ceil(allIncidents.length / rowsPerPage);
    if (currentPage < totalPages) {
      currentPage++;
      renderIncidentsTable();
    }
  });

  /* ---------- Grafik & Filter Dynamic Scales ---------- */
  function buildChart() {
    const canvas = $("#chart");
    if (typeof Chart === "undefined") {
      canvas.parentElement.textContent =
        "Grafik tidak dapat dimuat (Chart.js butuh koneksi internet).";
      return false;
    }
    const ds = (label, color, axis) => ({
      label,
      data: [],
      borderColor: color,
      backgroundColor: color + "22",
      fill: true,
      tension: 0.35,
      pointRadius: 0,
      borderWidth: 2,
      spanGaps: true,
      yAxisID: axis,
    });
    chart = new Chart(canvas.getContext("2d"), {
      type: "line",
      data: {
        labels: [],
        datasets: [
          ds("Suhu (°C)", "#2e7d32", "y"),
          ds("Asap (ppm)", "#c4141c", "y1"),
          ds("Gas (%)", "#1e9bd7", "y"),
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: "index", intersect: false },
        scales: {
          y: { min: 0, max: 100, title: { display: true, text: "°C / %" } },
          y1: {
            position: "right",
            min: 0,
            grid: { drawOnChartArea: false },
            title: { display: true, text: "ppm" },
          },
          x: { ticks: { maxTicksLimit: 8 } },
        },
        plugins: { legend: { display: false } }, // Legenda disembunyikan
      },
    });
    return true;
  }

  // Fungsi Filter Grafik + Penyesuaian Sumbu Y
  function applySensorFilter(val) {
    if (!chart) return;

    // Dataset: 0 = Suhu (°C), 1 = Asap (ppm), 2 = Gas (%)
    if (val === "suhu") {
      chart.data.datasets[0].hidden = false;
      chart.data.datasets[1].hidden = true;
      chart.data.datasets[2].hidden = true;

      chart.options.scales.y.display = true;
      chart.options.scales.y.title.text = "°C";
      chart.options.scales.y1.display = false;
    } else if (val === "asap") {
      chart.data.datasets[0].hidden = true;
      chart.data.datasets[1].hidden = false;
      chart.data.datasets[2].hidden = true;

      chart.options.scales.y.display = false;
      chart.options.scales.y1.display = true;
      chart.options.scales.y1.title.text = "ppm";
    } else if (val === "gas") {
      chart.data.datasets[0].hidden = true;
      chart.data.datasets[1].hidden = true;
      chart.data.datasets[2].hidden = false;

      chart.options.scales.y.display = true;
      chart.options.scales.y.title.text = "%";
      chart.options.scales.y1.display = false;
    } else {
      // 'all' / Semua Sensor
      chart.data.datasets[0].hidden = false;
      chart.data.datasets[1].hidden = false;
      chart.data.datasets[2].hidden = false;

      chart.options.scales.y.display = true;
      chart.options.scales.y.title.text = "°C / %";
      chart.options.scales.y1.display = true;
      chart.options.scales.y1.title.text = "ppm";
    }

    chart.update();
  }

  async function loadChart() {
    if (!chart) return;
    try {
      const d = await getJSON("chart.php?range=" + range);
      chart.data.labels = d.labels;
      chart.data.datasets[0].data = d.suhu;
      chart.data.datasets[1].data = d.asap;
      chart.data.datasets[2].data = d.gas;
      chart.update();
    } catch (e) {
      /* abaikan */
    }
  }

  // Event listener dropdown filter sensor
  const sensorFilter = $("#sensor-filter");
  if (sensorFilter) {
    sensorFilter.addEventListener("change", (e) => {
      applySensorFilter(e.target.value);
    });
  }

  document.querySelectorAll(".seg button").forEach((btn) =>
    btn.addEventListener("click", () => {
      range = btn.dataset.range;
      document
        .querySelectorAll(".seg button")
        .forEach((b) => b.setAttribute("aria-pressed", String(b === btn)));
      loadChart();
    }),
  );

  /* ---------- Jalankan ---------- */
  buildChart();
  pollLatest();
  loadIncidents();
  loadChart();
  setInterval(() => {
    if (!document.hidden) pollLatest();
  }, 3000);
  setInterval(() => {
    if (!document.hidden) {
      loadIncidents();
      if (range === "live") loadChart();
    }
  }, 10000);
})();
