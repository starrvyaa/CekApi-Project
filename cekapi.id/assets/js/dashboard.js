/* Dashboard real-time: kartu (polling 3 dtk), grafik & log insiden (polling 10 dtk) */
(() => {
  "use strict";
  const base = window.CEKAPI.base;
  const $ = (s, r = document) => r.querySelector(s);
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

  /* ---------- Logika visual kustom untuk kartu ---------- */
  function updateVisualCards(x, on) {
    // 1. KARTU SUHU (Dingin < 22°C | Hangat 22 - 30°C | Panas > 30°C)
    const cardSuhu = $("#card-suhu");
    if (cardSuhu) {
      const valEl = $("[data-val]", cardSuhu);
      const textEl = $("[data-state-text]", cardSuhu);
      const bar = $("[data-bar]", cardSuhu);

      if (!on || x.suhu === null) {
        if (valEl) valEl.textContent = "--";
        if (textEl) textEl.textContent = "OFFLINE";
        cardSuhu.dataset.state = "aman";
      } else {
        const val = parseFloat(x.suhu);
        if (valEl) valEl.textContent = val.toFixed(1).replace(".", ",");

        let statusText = "Hangat";
        let stateClass = "aman";

        if (val < 22.0) {
          statusText = "Dingin";
          stateClass = "aman";
        } else if (val >= 22.0 && val <= 30.0) {
          statusText = "Hangat";
          stateClass = "waspada";
        } else {
          statusText = "Panas";
          stateClass = "bahaya";
        }

        if (textEl) textEl.textContent = "Status: " + statusText;
        cardSuhu.dataset.state = stateClass;

        if (bar) {
          const heightPercent = Math.max(0, Math.min(100, (val / 60) * 100));
          bar.style.height = heightPercent + "%";
        }
      }
    }

    // 2. KARTU API (Terdeteksi -> Merah | Tidak Terdeteksi -> Abu-abu)
    const cardApi = $("#card-api");
    if (cardApi) {
      const textEl = $("[data-state-text]", cardApi);
      const iconPic = $(".pic", cardApi);

      if (!on) {
        if (textEl) textEl.textContent = "OFFLINE";
        cardApi.dataset.state = "aman";
        if (iconPic) iconPic.style.color = "#888888";
      } else {
        const isApi = Boolean(x.api);
        if (textEl) textEl.textContent = isApi ? "TERDETEKSI" : "TIDAK TERDETEKSI";
        
        if (isApi) {
          cardApi.dataset.state = "bahaya";
          if (iconPic) iconPic.style.color = "#ff3b30"; // Merah
        } else {
          cardApi.dataset.state = "aman";
          if (iconPic) iconPic.style.color = "#888888"; // Abu-abu
        }
      }
    }

    // 3. KARTU ASAP (Tidak Pekat < 100 | Sedang 100 - 300 | Pekat > 300)
    const cardAsap = $("#card-asap");
    if (cardAsap) {
      const valEl = $("[data-val]", cardAsap);
      const textEl = $("[data-state-text]", cardAsap);
      const iconPic = $(".pic", cardAsap);

      if (!on || x.asap === null) {
        if (valEl) valEl.textContent = "--";
        if (textEl) textEl.textContent = "OFFLINE";
        cardAsap.dataset.state = "aman";
      } else {
        const val = parseInt(x.asap, 10);
        if (valEl) valEl.textContent = val;

        let statusText = "Tidak Pekat";
        let stateClass = "aman";
        let opacity = 0.3; // Semakin pekat semakin jelas/gelap

        if (val < 100) {
          statusText = "Tidak Pekat";
          stateClass = "aman";
          opacity = 0.3;
        } else if (val >= 100 && val <= 300) {
          statusText = "Sedang";
          stateClass = "waspada";
          opacity = 0.65;
        } else {
          statusText = "Pekat";
          stateClass = "bahaya";
          opacity = 1.0;
        }

        if (textEl) textEl.textContent = "Status: " + statusText;
        cardAsap.dataset.state = stateClass;
        if (iconPic) {
          iconPic.style.opacity = opacity;
          iconPic.style.transition = "opacity 0.4s ease";
        }
      }
    }

    // 4. KARTU GAS (Rendah < 20% | Normal 20 - 50% | Tinggi > 50%)
    const cardGas = $("#card-gas");
    if (cardGas) {
      const valEl = $("[data-val]", cardGas);
      const textEl = $("[data-state-text]", cardGas);
      const iconPic = $(".pic", cardGas);

      if (!on || x.gas === null) {
        if (valEl) valEl.textContent = "--";
        if (textEl) textEl.textContent = "OFFLINE";
        cardGas.dataset.state = "aman";
      } else {
        const val = parseFloat(x.gas);
        if (valEl) valEl.textContent = val.toFixed(1).replace(".", ",");

        let statusText = "Normal";
        let stateClass = "aman";

        if (val < 20.0) {
          statusText = "Rendah";
          stateClass = "aman";
        } else if (val >= 20.0 && val <= 50.0) {
          statusText = "Normal";
          stateClass = "aman";
        } else {
          statusText = "Tinggi";
          stateClass = "bahaya";
        }

        if (textEl) textEl.textContent = "Status: " + statusText;
        cardGas.dataset.state = stateClass;

        // Memutar ikon Gauge SVG sesuai persentase nilai gas (0 - 100% -> -90 deg s/d 90 deg)
        if (iconPic) {
          const deg = -90 + Math.min(100, Math.max(0, val)) * 1.8;
          iconPic.style.transform = `rotate(${deg}deg)`;
          iconPic.style.transition = "transform 0.4s ease";
          iconPic.style.display = "inline-block";
        }
      }
    }
  }

  /* ---------- Kartu Indikator Polling ---------- */
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

      // Panggil pembaruan visual kartu
      updateVisualCards(x, on);

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
        plugins: { legend: { display: false } },
      },
    });
    return true;
  }

  function applySensorFilter(val) {
    if (!chart) return;

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