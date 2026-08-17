/* ==========================================================
   ListaConfiguracoes.js — MODAL CONFIG AGENDA
   - Tabs do modal
   - Aba "Serviços": lista + paginação via PHP
   - Serviço (Nome + Valor) | Duração | Ações
========================================================== */
(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C) {
    console.warn("[ListaConfiguracoes] ListaCore não carregado.");
    return;
  }

  const CFG = {
    MOCK: false,
    ENDPOINT: "/public/api/api_central.php?path=agenda/servico-profissional/listar",
    ROOT_SELECTOR_MENU: "#modalConfiguracoesAgenda .modal-conteudo",
    itensPorPagina: 3,
  };

  const MODAL_ID = "modalConfiguracoesAgenda";
  const TABS_BTN_SEL = `#${MODAL_ID} .tabs-config [data-tab]`;

  const TABELA_ID = "tabelaServicosConfig";
  const PAG_ID = "paginacao_servicos";

  const modal = document.getElementById(MODAL_ID);
  const tabela = document.getElementById(TABELA_ID);
  if (!modal || !tabela) return;

  const tbody = tabela.querySelector("tbody");
  if (!tbody) return;

  const pagDiv = document.getElementById(PAG_ID);
  const menuCtrl = C.createFloatingMenuController({ rootSelector: CFG.ROOT_SELECTOR_MENU });

  let dados = [];
  let paginaAtual = 1;
  let carregado = false;
  let carregando = false;

  function duracaoLabel(min) {
    const m = Number(min || 0);
    if (!m) return "-";
    if (m === 60) return "1h";
    if (m === 90) return "1h30";
    if (m === 120) return "2h";
    return `${m} min`;
  }

  function moneyBR(v) {
    const n = Number(v || 0);
    return n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  }

  function profissionaisLabel(arr) {
    const list = Array.isArray(arr) ? arr : [];
    const clean = list
      .map(x => String(x ?? "").trim())
      .filter(Boolean);

    if (!clean.length) return "";
    return clean.join(", ");
  }

  function normalizarServico(row) {
    return {
      id: Number(row.id_servico || row.id || 0),
      nome: String(row.nome || ""),
      duracaoMin: Number(row.duracao_min || row.duracaoMin || 0),
      preco: Number(row.valor || row.preco || 0),
      ativo: String(row.status || "ativo") === "ativo",
      status: String(row.status || "ativo"),
      profissionais: Array.isArray(row.profissionais) ? row.profissionais : [],
      criado_em: row.criado_em || null,
      atualizado_em: row.atualizado_em || null,
    };
  }

  function fecharMenusDaTabela() {
    tbody.querySelectorAll(".agenda-menu").forEach(m => m.setAttribute("hidden", ""));
    tbody.querySelectorAll('button[data-acao="toggle-menu"]').forEach(b => b.setAttribute("aria-expanded", "false"));
  }

  function renderLoading() {
    tbody.innerHTML = `
      <tr class="linha-vazia">
        <td colspan="3">Carregando serviços...</td>
      </tr>
    `;

    if (pagDiv) pagDiv.innerHTML = "";
  }

  function renderErro(msg) {
    tbody.innerHTML = `
      <tr class="linha-vazia">
        <td colspan="3">${C.escapeHtml(msg || "Erro ao carregar serviços.")}</td>
      </tr>
    `;

    if (pagDiv) pagDiv.innerHTML = "";
  }

  async function carregarServicos({ force = false, manterPagina = false } = {}) {
    if (carregando) return;

    if (carregado && !force) {
      renderTudo();
      return;
    }

    const paginaAnterior = paginaAtual;

    carregando = true;
    renderLoading();

    try {
      const resp = await fetch(CFG.ENDPOINT, {
        method: "GET",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
        },
      });

      const json = await resp.json().catch(() => null);

      if (!resp.ok || !json || json.ok !== true) {
        throw new Error(json?.user_msg || json?.mensagem || "Não foi possível carregar os serviços.");
      }

      const lista = json?.data?.servicos || [];
      dados = lista.map(normalizarServico);

      paginaAtual = manterPagina ? paginaAnterior : 1;
      carregado = true;

      renderTudo();
    } catch (err) {
      console.error("[ListaConfiguracoes] Erro ao carregar serviços:", err);
      renderErro(err.message || "Erro ao carregar serviços.");
    } finally {
      carregando = false;
    }
  }

  function renderTabela(pageItems) {
    if (!pageItems.length) {
      tbody.innerHTML = `
        <tr class="linha-vazia">
          <td colspan="3">Nenhum serviço cadastrado.</td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = pageItems.map((s) => {
      const valor = moneyBR(s.preco);
      const profs = profissionaisLabel(s.profissionais);

      return `
        <tr class="cfg-servico-row" data-id="${C.escapeHtml(s.id)}">
          <td>
            <div style="display:flex; flex-direction:column; gap:3px;">
              <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <strong>${C.escapeHtml(s.nome)}</strong>
                <span class="badge-valor">${C.escapeHtml(valor)}</span>
              </div>

              ${profs ? `
                <span style="font-size:12px; color: rgba(0,0,0,.55); line-height:1.2;">
                  👤 ${C.escapeHtml(profs)}
                </span>
              ` : ""}
            </div>
          </td>

          <td>${C.escapeHtml(duracaoLabel(s.duracaoMin))}</td>

          <td class="td-acoes-servico">
            <button class="botao-geral btn-excluir-servico"
                    type="button"
                    data-acao="excluir"
                    title="Excluir"
                    aria-label="Excluir">
              <i class="fa-regular fa-trash-can"></i>
            </button>
          </td>
        </tr>
      `;
    }).join("");

    fecharMenusDaTabela();
    menuCtrl.fechar();
  }

  function paginar(lista) {
    const total = lista.length;
    const porPag = CFG.itensPorPagina;
    const totalPaginas = Math.max(1, Math.ceil(total / porPag));

    paginaAtual = Math.max(1, Math.min(paginaAtual, totalPaginas));

    const ini = (paginaAtual - 1) * porPag;
    const fim = ini + porPag;

    return {
      pageItems: lista.slice(ini, fim),
      total,
      totalPaginas,
      paginaAtual,
    };
  }

  function renderPaginacao(info) {
    if (!pagDiv) return;

    if (info.total === 0 || info.totalPaginas <= 1) {
      pagDiv.innerHTML = "";
      return;
    }

    pagDiv.innerHTML = "";

    if (info.paginaAtual > 1) {
      const btnAnt = document.createElement("button");
      btnAnt.type = "button";
      btnAnt.textContent = "◀ Anterior";
      btnAnt.classList.add("btn-pag");
      btnAnt.addEventListener("click", () => {
        paginaAtual = Math.max(1, paginaAtual - 1);
        renderTudo();
      });
      pagDiv.appendChild(btnAnt);
    }

    if (info.paginaAtual < info.totalPaginas) {
      const btnProx = document.createElement("button");
      btnProx.type = "button";
      btnProx.textContent = "Próximo ▶";
      btnProx.classList.add("btn-pag");
      btnProx.addEventListener("click", () => {
        paginaAtual = Math.min(info.totalPaginas, info.paginaAtual + 1);
        renderTudo();
      });
      pagDiv.appendChild(btnProx);
    }
  }

  function renderTudo() {
    const info = paginar(dados);
    renderTabela(info.pageItems);
    renderPaginacao(info);
  }

  function recarregarServicos(options = {}) {
    carregado = false;

    return carregarServicos({
      force: true,
      manterPagina: options.manterPagina === true,
    });
  }

  function ativarTab(tabId) {
    modal.querySelectorAll(".tab-painel").forEach(p => {
      p.classList.toggle("ativa", p.id === tabId);
    });

    document.querySelectorAll(TABS_BTN_SEL).forEach(btn => {
      const on = btn.getAttribute("data-tab") === tabId;
      btn.classList.toggle("destaque", on);
    });

    if (tabId === "cfg-servicos") {
      setTimeout(() => carregarServicos(), 0);
    } else {
      fecharMenusDaTabela();
      menuCtrl.fechar();
      if (pagDiv) pagDiv.innerHTML = "";
    }
  }

  modal.addEventListener("click", (e) => {
    const btnTab = e.target.closest?.("[data-tab]");
    if (btnTab) {
      const tabId = btnTab.getAttribute("data-tab");
      if (tabId) ativarTab(tabId);
      return;
    }

    if (!e.target.closest(".agenda-menu")) {
      fecharMenusDaTabela();
      menuCtrl.fechar();
    }
  });

  document.addEventListener("agenda:servicos:recarregar", (e) => {
    recarregarServicos({
      manterPagina: e.detail?.manterPagina === true,
    });
  });

  document.addEventListener("servico:cadastrado", () => {
    recarregarServicos({
      manterPagina: false,
    });
  });

  document.addEventListener("servico:excluido", () => {
    recarregarServicos({
      manterPagina: true,
    });
  });

  window.ListaServicosConfig = {
    recarregar: recarregarServicos,
    render: renderTudo,
    getDados() {
      return dados.slice();
    },
  };

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      fecharMenusDaTabela();
      menuCtrl.fechar();
    }
  });

  function init() {
    ativarTab("cfg-geral");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();