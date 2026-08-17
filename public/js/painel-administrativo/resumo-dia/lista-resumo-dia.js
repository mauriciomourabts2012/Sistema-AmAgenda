/* ==========================================================
   ResumoDia.js (MÓDULO) — PADRÃO CORE (ListaCore)
   - Renderiza KPIs em:  #resumo .resumo-cards
   - Renderiza listas rápidas em: #resumo .resumo-graficos
   - MOCK por padrão + pronto para backend
========================================================== */
(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C) return;

  const CFG = {
    MOCK: true,
    ENDPOINT: "/backend/Agenda/Resumo/ResumoDia.php",

    ABA_ID: "resumo",
    CARDS_SELECTOR: "#resumo .resumo-cards",
    GRAFICOS_SELECTOR: "#resumo .resumo-graficos",

    AUTO_REFRESH_ON_TAB: true,
    REFRESH_COOLDOWN_MS: 800, // evita fetch repetido
  };

  const aba = document.getElementById(CFG.ABA_ID);
  const $cards = document.querySelector(CFG.CARDS_SELECTOR);
  const $graficos = document.querySelector(CFG.GRAFICOS_SELECTOR);
  if (!aba || !$cards || !$graficos) return;

  // =========================
  // MOCK (exemplo)
  // =========================
  const MOCK_DATA = {
    date: hojeISO(),

    kpis: {
      total: 12,
      confirmados: 8,
      pendentes: 3,
      cancelados: 1,
      faturamentoDia: 420.0,
      ocupacaoPct: 74, // %
    },

    // próximos agendamentos (hoje)
    proximos: [
      { hora: "09:00", cliente: "Ana Souza", servico: "Corte", profissional: "João", status: "Confirmado" },
      { hora: "10:30", cliente: "Carlos Lima", servico: "Barba", profissional: "João", status: "Pendente" },
      { hora: "11:30", cliente: "Fernanda Alves", servico: "Escova", profissional: "Maria", status: "Confirmado" },
    ],

    // resumo por profissional (hoje)
    porProfissional: [
      { profissional: "João", total: 6, confirmados: 4, pendentes: 2, cancelados: 0 },
      { profissional: "Maria", total: 6, confirmados: 4, pendentes: 1, cancelados: 1 },
    ],
  };

  // =========================
  // Helpers
  // =========================
  function hojeISO() {
    // yyyy-mm-dd (local)
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function formatBRMoney(v) {
    const n = Number(v || 0);
    return n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  }

  function formatBRDate(iso) {
    // iso yyyy-mm-dd -> dd/mm/yyyy
    if (!iso || typeof iso !== "string" || !iso.includes("-")) return String(iso || "");
    const [y, m, d] = iso.split("-");
    return `${d}/${m}/${y}`;
  }

  function normStatus(st) {
    const s = C.normalizar(st).trim();
    if (s.includes("confirm")) return "Confirmado";
    if (s.includes("pend")) return "Pendente";
    if (s.includes("cancel")) return "Cancelado";
    return st || "Pendente";
  }

  function statusClass(st) {
    const n = normStatus(st);
    // reaproveita seus estilos já existentes (você usa st-confirmado / st-cancelado)
    if (n === "Confirmado") return "st-confirmado";
    if (n === "Cancelado") return "st-cancelado";
    return "st-pendente";
  }

  // Card KPI (bem simples, usa classes do seu design; se já tiver classe própria, troca aqui)
  function kpiCard({ titulo, valor, sub, icon }) {
    return `
      <article class="painel-kpi">
        <div class="painel-kpi-topo">
          <div class="painel-kpi-titulo">${C.escapeHtml(titulo)}</div>
          ${icon ? `<div class="painel-kpi-ico">${icon}</div>` : ""}
        </div>
        <div class="painel-kpi-valor">${C.escapeHtml(valor)}</div>
        ${sub ? `<div class="painel-kpi-sub">${C.escapeHtml(sub)}</div>` : ""}
      </article>
    `;
  }

  function renderKPIs(payload) {
    const k = payload?.kpis || {};
    const dataLabel = payload?.date ? `Hoje • ${formatBRDate(payload.date)}` : "Hoje";

    const html = [
      kpiCard({ titulo: "Agendamentos", valor: String(k.total ?? 0), sub: dataLabel, icon: `<i class="fa-regular fa-calendar"></i>` }),
      kpiCard({ titulo: "Confirmados", valor: String(k.confirmados ?? 0), sub: "Atendimentos confirmados", icon: `<i class="fa-regular fa-circle-check"></i>` }),
      kpiCard({ titulo: "Pendentes", valor: String(k.pendentes ?? 0), sub: "Aguardando confirmação", icon: `<i class="fa-regular fa-clock"></i>` }),
      kpiCard({ titulo: "Cancelados", valor: String(k.cancelados ?? 0), sub: "Cancelamentos no dia", icon: `<i class="fa-regular fa-circle-xmark"></i>` }),
      kpiCard({ titulo: "Faturamento (dia)", valor: formatBRMoney(k.faturamentoDia ?? 0), sub: "Estimado / realizado", icon: `<i class="fa-solid fa-sack-dollar"></i>` }),
      kpiCard({ titulo: "Ocupação", valor: `${Number(k.ocupacaoPct ?? 0)}%`, sub: "Agenda preenchida", icon: `<i class="fa-solid fa-chart-simple"></i>` }),
    ].join("");

    $cards.innerHTML = html;
  }

  function renderProximos(payload) {
    const itens = Array.isArray(payload?.proximos) ? payload.proximos : [];

    if (!itens.length) {
      return `
        <section class="painel-bloco">
          <div class="painel-bloco-topo">
            <h3>Próximos atendimentos</h3>
          </div>
          <div class="painel-vazio">Nenhum atendimento para hoje.</div>
        </section>
      `;
    }

    const rows = itens.map((x) => `
      <div class="painel-linha">
        <div class="painel-linha-esq">
          <div class="painel-hora">${C.escapeHtml(x.hora || "--:--")}</div>
          <div class="painel-info">
            <div class="painel-cliente">${C.escapeHtml(x.cliente || "Cliente")}</div>
            <div class="painel-sub">
              ${C.escapeHtml(x.servico || "")}
              ${x.profissional ? `• ${C.escapeHtml(x.profissional)}` : ""}
            </div>
          </div>
        </div>
        <div class="painel-linha-dir">
          <span class="agenda-status ${statusClass(x.status)}">${C.escapeHtml(normStatus(x.status))}</span>
        </div>
      </div>
    `).join("");

    return `
      <section class="painel-bloco">
        <div class="painel-bloco-topo">
          <h3>Próximos atendimentos</h3>
        </div>
        <div class="painel-lista">
          ${rows}
        </div>
      </section>
    `;
  }

  function renderPorProfissional(payload) {
    const arr = Array.isArray(payload?.porProfissional) ? payload.porProfissional : [];

    if (!arr.length) {
      return `
        <section class="painel-bloco">
          <div class="painel-bloco-topo">
            <h3>Por profissional</h3>
          </div>
          <div class="painel-vazio">Sem dados para hoje.</div>
        </section>
      `;
    }

    const rows = arr.map((p) => `
      <div class="painel-prof">
        <div class="painel-prof-nome">${C.escapeHtml(p.profissional || "Profissional")}</div>
        <div class="painel-prof-metrics">
          <span class="pill">${C.escapeHtml(String(p.total ?? 0))} total</span>
          <span class="pill ok">${C.escapeHtml(String(p.confirmados ?? 0))} conf.</span>
          <span class="pill warn">${C.escapeHtml(String(p.pendentes ?? 0))} pend.</span>
          <span class="pill danger">${C.escapeHtml(String(p.cancelados ?? 0))} canc.</span>
        </div>
      </div>
    `).join("");

    return `
      <section class="painel-bloco">
        <div class="painel-bloco-topo">
          <h3>Por profissional</h3>
        </div>
        <div class="painel-prof-list">
          ${rows}
        </div>
      </section>
    `;
  }

  function renderGraficos(payload) {
    const html = [
      renderProximos(payload),
      renderPorProfissional(payload),
    ].join("");

    $graficos.innerHTML = html;
  }

  // =========================
  // Load / Refresh
  // =========================
  let lastLoadAt = 0;

  async function carregar() {
    const now = Date.now();
    if (now - lastLoadAt < CFG.REFRESH_COOLDOWN_MS) return;
    lastLoadAt = now;

    // skeleton simples
    $cards.innerHTML = `
      <article class="painel-kpi"><div class="painel-kpi-valor">…</div></article>
      <article class="painel-kpi"><div class="painel-kpi-valor">…</div></article>
      <article class="painel-kpi"><div class="painel-kpi-valor">…</div></article>
    `;
    $graficos.innerHTML = `
      <section class="painel-bloco"><div class="painel-vazio">Carregando resumo…</div></section>
    `;

    try {
      let payload;

      if (CFG.MOCK) {
        payload = MOCK_DATA;
      } else {
        const json = await C.fetchJSON(CFG.ENDPOINT);
        // Aceita {ok:true,data:{...}} OU direto {...}
        payload = json?.data ?? json;
      }

      renderKPIs(payload);
      renderGraficos(payload);
    } catch (e) {
      $cards.innerHTML = "";
      $graficos.innerHTML = `
        <div class="painel-card" style="padding:14px">
          <strong>⚠️ Resumo do dia</strong><br>
          <span style="color:var(--muted)">Falha ao carregar: ${C.escapeHtml(e.message)}</span>
        </div>
      `;
      console.error("[ResumoDia]", e);
    }
  }

  // =========================
  // Auto refresh ao abrir a aba
  // =========================
  function abaEstaAtiva() {
    // seu HTML usa "ativa" na aba atual
    return aba.classList.contains("ativa");
  }

  if (CFG.AUTO_REFRESH_ON_TAB) {
    const obs = new MutationObserver(() => {
      if (abaEstaAtiva()) carregar();
    });
    obs.observe(aba, { attributes: true, attributeFilter: ["class"] });
  }

  document.addEventListener("DOMContentLoaded", () => {
    if (abaEstaAtiva()) carregar();
  });

  // ✅ opcional: você pode forçar refresh de fora com:
  // document.dispatchEvent(new CustomEvent("resumo:recarregar"));
  document.addEventListener("resumo:recarregar", () => carregar());
})();
