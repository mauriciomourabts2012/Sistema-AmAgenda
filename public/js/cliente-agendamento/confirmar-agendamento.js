// js/Cliente/Confirmar.js
(() => {
  "use strict";

  const box = document.getElementById("boxResumoAgendamento");
  const btnVoltar = document.getElementById("btnVoltarConfirmar");
  const btnAgendar = document.getElementById("btnAgendarFinal");

  const inServJson = document.getElementById("ag_servicos_json");
  const inTotal = document.getElementById("ag_servicos_total");

  const inData = document.getElementById("ag_data_iso");
  const inHora = document.getElementById("ag_hora");

  const inObs = document.getElementById("ag_obs");

  if (!box || !btnVoltar || !btnAgendar || !inServJson || !inTotal || !inData || !inHora || !inObs) return;

  // ✅ depois você liga no profissional selecionado (hidden vindo da aba profissional)
  // Por enquanto mock:
  const profissionalNome = "Profissional selecionado";

  function moneyBR(v) {
    const n = Number(v || 0);
    return n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  }

  function formatDataHora(iso, hora) {
    if (!iso || !hora) return "—";
    const [y,m,d] = String(iso).split("-").map(Number);
    const dt = new Date(y, (m||1)-1, d||1);
    const sem = dt.toLocaleDateString("pt-BR", { weekday: "short" }).replace(".", "");
    return `${sem}, ${String(d).padStart(2,"0")}/${String(m).padStart(2,"0")}/${y} às ${hora}`;
  }

  function getServicoResumo() {
    let arr = [];
    try { arr = JSON.parse(inServJson.value || "[]"); } catch { arr = []; }
    const s = arr[0] || null;
    return s;
  }

  function renderResumo() {
    const s = getServicoResumo();
    const total = Number(inTotal.value || 0);

    const dataHoraTxt = formatDataHora(inData.value, inHora.value);

    box.innerHTML = `
      <div class="u-resumo-linha">
        <div class="u-resumo-label">Profissional:</div>
        <div class="u-resumo-valor">${profissionalNome}</div>
      </div>

      <div class="u-resumo-linha">
        <div class="u-resumo-label">Data e Hora:</div>
        <div class="u-resumo-valor">${dataHoraTxt}</div>
      </div>

      <div class="u-resumo-linha">
        <div class="u-resumo-label">Serviço:</div>
        <div class="u-resumo-row">
          <div class="u-resumo-valor">${s ? (s.nome || "—") : "—"}</div>
          <div class="u-resumo-preco">${moneyBR(total)}</div>
        </div>
      </div>
    `;
  }

  // Importante: renderizar sempre que entrar na aba
  // (assumindo que seu Tabs.go() dispara algum evento; se não tiver, deixo fallback)
  document.addEventListener("click", (e) => {
    const isTabBtn = e.target.closest("[data-tab='confirmar'], #tab-confirmar-btn");
    if (isTabBtn) renderResumo();
  });

  btnVoltar.addEventListener("click", () => Tabs.go("horario"));

  btnAgendar.addEventListener("click", () => {
    // aqui você vai fazer o POST final
    // payload sugerido:
    const payload = {
      profissional: profissionalNome,
      data: inData.value,
      hora: inHora.value,
      servicos: inServJson.value,
      total: inTotal.value,
      obs: String(inObs.value || "").trim()
    };

    console.log("AGENDAR payload:", payload);
    window.alert("✅ (mock) Agendamento pronto para enviar!");
  });

  // primeira renderização
  renderResumo();
})();
