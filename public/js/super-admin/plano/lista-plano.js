/* ==========================================================
   ListaPlano.js — ABA PLANOS (ListaCore)
   ✅ Modal Visualizar e Modal Editar abrem SOMENTE por este JS
   ✅ Modal Visualizar e Modal Editar fecham SOMENTE por este JS
   ✅ NÃO depende do script universal para abrir/fechar esses modais
   ✅ Escopo restrito à aba #planos
   ✅ Compatível com toggle-status universal
   ✅ Busca segura dentro de cada modal
   ✅ FECHAMENTO local garantido:
      - botão fechar
      - clique no backdrop
      - tecla ESC
   ✅ FILTRO PADRÃO INICIAL: ATIVO
========================================================== */
(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C) {
    console.warn("[ListaPlanosSuper] ListaCore não carregado.");
    return;
  }

  if (window.__LISTA_PLANOS_SUPER_INIT__) {
    console.warn("[ListaPlanosSuper] Script já inicializado.");
    return;
  }
  window.__LISTA_PLANOS_SUPER_INIT__ = true;

  const CFG = {
    MOCK: false,
    API_URL: "/public/api/api_central.php",
    PATH: "superadmin/plano/listar",

    ABA_ID: "planos",
    BOX_ID: "listaPlanos",
    INPUT_ID: "pesquisar-planos",
    PAG_ID: "paginacao_planos",

    ROOT_SELECTOR_MENU: "#planos .conteudo-agenda",

    itensPorPagina: 20,
    EMPTY_MSG: "Nenhum plano encontrado para os critérios informados.",
    MOBILE_MAX: 680,

    MODAL_EDITAR_ID: "modalEditarPlano",
    MODAL_VISUALIZAR_ID: "modalVisualizarPlano",

    ACAO_EDITAR: "editar-plano",
    ACAO_VISUALIZAR: "visualizar",
  };

  const aba = document.getElementById(CFG.ABA_ID);
  const box = document.getElementById(CFG.BOX_ID);
  const pagDiv = document.getElementById(CFG.PAG_ID);
  const inputPesquisa = document.getElementById(CFG.INPUT_ID);

  if (!aba || !box) {
    console.warn("[ListaPlanosSuper] DOM faltando:", { aba, box });
    return;
  }

  const btnLimparPesquisa = aba.querySelector(".btn-limpar-pesquisa");

  const FIDS = {
    btn: "btnPeriodo_planos",
    pop: "popoverPeriodo_planos",
    form: "formPeriodo_planos",
    ini: "inicio_planos",
    fim: "fim_planos",
    status: "status_planos",
    destaque: "destaque_planos",
    label: "labelPeriodo_planos",
    limpar: "limparFiltro_planos",
    fechar: "fecharPopover_planos",
  };

  const $btnFiltro = document.getElementById(FIDS.btn);
  const $popFiltro = document.getElementById(FIDS.pop);
  const $formFiltro = document.getElementById(FIDS.form);
  const $ini = document.getElementById(FIDS.ini);
  const $fim = document.getElementById(FIDS.fim);
  const $st = document.getElementById(FIDS.status);
  const $ds = document.getElementById(FIDS.destaque);
  const $label = document.getElementById(FIDS.label);
  const $limpar = document.getElementById(FIDS.limpar);
  const $fechar = document.getElementById(FIDS.fechar);

  const modalEditarPlano = document.getElementById(CFG.MODAL_EDITAR_ID);
  const modalVisualizarPlano = document.getElementById(CFG.MODAL_VISUALIZAR_ID);

  let BASE_LISTA = [];
  let __CARREGADO__ = false;

  const FILTRO = {};
  const PAGINA_ATUAL = { planos: 1 };
  let META_API = { page: 1, limit: CFG.itensPorPagina, total: 0, pages: 1 };
  let REQUISICAO_ATUAL = 0;

  // ==========================================================
  // Toast
  // ==========================================================
  function getToastStack() {
    let el = document.getElementById("toastStack");
    if (!el) {
      el = document.createElement("div");
      el.id = "toastStack";
      el.className = "ui-toast-stack";
      document.body.appendChild(el);
    }
    return el;
  }

  function toast({ type = "info", title = "Aviso", msg = "", timeout = 3500 } = {}) {
    const stack = getToastStack();
    const t = String(type || "info").toLowerCase();

    const cls =
      t === "success" ? "ui-alert--success" :
      t === "warning" ? "ui-alert--warning" :
      t === "danger" ? "ui-alert--danger" :
      t === "neutral" ? "ui-alert--neutral" :
      "ui-alert--info";

    const icon =
      t === "success" ? "✓" :
      t === "warning" ? "!" :
      t === "danger" ? "×" :
      t === "neutral" ? "i" :
      "i";

    const el = document.createElement("div");
    el.className = `ui-alert ${cls}`;
    el.innerHTML = `
      <div class="ui-alert__icon">${icon}</div>
      <div>
        <p class="ui-alert__title">${C.escapeHtml(title)}</p>
        <p class="ui-alert__msg">${C.escapeHtml(msg)}</p>
      </div>
      <div class="ui-alert__actions">
        <button type="button" class="ui-alert__btn">OK</button>
      </div>
    `;

    const btn = el.querySelector(".ui-alert__btn");
    const kill = () => {
      el.classList.add("is-leaving");
      setTimeout(() => el.remove(), 180);
    };

    btn.addEventListener("click", kill);
    stack.appendChild(el);

    if (timeout > 0) setTimeout(kill, timeout);
  }

  // ==========================================================
  // Helpers
  // ==========================================================
  function isMobile() {
    return window.innerWidth <= (CFG.MOBILE_MAX || 820);
  }

  function hojeISO() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function menosDiasISO(dias) {
    const d = new Date();
    d.setDate(d.getDate() - Number(dias || 0));
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function onlyDate(isoDateTime) {
    if (!isoDateTime) return "";
    return String(isoDateTime).slice(0, 10);
  }

  function buildApiUrl(params = {}) {
    const sp = new URLSearchParams();
    sp.set("path", CFG.PATH);

    Object.entries(params).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      const vv = String(v).trim();
      if (!vv) return;
      sp.set(k, vv);
    });

    return `${CFG.API_URL}?${sp.toString()}`;
  }

  function normalizeStatus(status) {
    const st = C.normalizar(status).trim();
    if (st.includes("bloq")) return "Bloqueado";
    if (st.includes("inativ")) return "Inativo";
    if (st.includes("ativ")) return "Ativo";
    return "Ativo";
  }

  function statusFiltroNorm(v) {
    const s = C.normalizar(v || "");
    if (!s) return "";
    if (s.includes("bloq")) return "bloqueado";
    if (s.includes("inativ")) return "inativo";
    if (s.includes("ativ")) return "ativo";
    return s;
  }

  function destaqueFiltroNorm(v) {
    const s = String(v ?? "").trim();
    if (!s) return "";
    if (s === "1" || s === "true") return "1";
    if (s === "0" || s === "false") return "0";
    return s;
  }

  function statusBate(itemStatus, filtroStatus) {
    const f = statusFiltroNorm(filtroStatus);
    if (!f) return true;
    return statusFiltroNorm(itemStatus) === f;
  }

  function destaqueBate(itemDestaque, filtroDestaque) {
    const f = destaqueFiltroNorm(filtroDestaque);
    if (!f) return true;
    return destaqueFiltroNorm(itemDestaque) === f;
  }

  function badgeStatus(status) {
    const st = normalizeStatus(status);
    const cls =
      st === "Ativo" ? "st-confirmado" :
      st === "Inativo" ? "st-cancelado" :
      "st-pendente";
    return `<span class="agenda-status ${cls}">${C.escapeHtml(st)}</span>`;
  }

  function badgeDestaque(v) {
    const d = destaqueFiltroNorm(v);
    if (d !== "1") return "";
    return `<span class="agenda-status st-confirmado" style="margin-left:6px">Destaque</span>`;
  }

  function initials(nome) {
    const p = String(nome ?? "").trim().split(/\s+/).filter(Boolean);
    if (!p.length) return "??";
    return (p[0][0] || "?").toUpperCase();
  }

  function iconAcoes() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 7.25a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Z"/>
      </svg>
    `;
  }

  // ==========================================================
  // Modal local (SEM script universal)
  // ==========================================================
  function travarBodyModal() {
    document.body.classList.add("modal-open");
  }

  function destravarBodyModal() {
    const algumModalAberto =
      (modalEditarPlano && modalEditarPlano.classList.contains("ativo")) ||
      (modalVisualizarPlano && modalVisualizarPlano.classList.contains("ativo"));

    if (!algumModalAberto) {
      document.body.classList.remove("modal-open");
    }
  }

  function abrirModalLocal(modal) {
    if (!modal) return;

    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");
    travarBodyModal();

    const conteudo = modal.querySelector(".modal-conteudo");
    if (conteudo) conteudo.scrollTop = 0;
  }

  function fecharModalLocal(modal) {
    if (!modal) return;

    modal.classList.remove("ativo");
    modal.setAttribute("aria-hidden", "true");
    destravarBodyModal();
  }

  function abrirModalEditarPlano() {
    abrirModalLocal(modalEditarPlano);

    window.setTimeout(() => {
      try {
        modalEditarPlano?.querySelector("#e_nome")?.focus();
      } catch (_) {}
    }, 60);
  }

  function fecharModalEditarPlano() {
    fecharModalLocal(modalEditarPlano);
  }

  function abrirModalVisualizarPlano() {
    abrirModalLocal(modalVisualizarPlano);

    window.setTimeout(() => {
      try {
        modalVisualizarPlano?.querySelector(".modal-fechar, [data-fechar-local-modal]")?.focus();
      } catch (_) {}
    }, 60);
  }

  function fecharModalVisualizarPlano() {
    fecharModalLocal(modalVisualizarPlano);
  }

  function bindFechamentoModalLocal(modal, onClose) {
    if (!modal || typeof onClose !== "function") return;

    modal.addEventListener("click", (ev) => {
      const btnFechar = ev.target.closest("[data-fechar-modal], [data-fechar-local-modal], .modal-fechar");
      if (btnFechar) {
        ev.preventDefault();
        ev.stopPropagation();
        onClose();
        return;
      }

      // NÃO fecha ao clicar fora do modal
    });
  }

  // ==========================================================
  // Menu
  // ==========================================================
  function buildMenuAcoes(p) {
    const st = normalizeStatus(p.status);
    const ativo = st === "Ativo";

    const id = String(p.id ?? "");
    const statusRaw = String(statusFiltroNorm(p.status) || "ativo");

    return `
      <div class="agenda-menu" role="menu">
        <button class="agenda-menu-item" type="button" data-acao="${CFG.ACAO_VISUALIZAR}">
          <i class="fa-regular fa-eye"></i> Visualizar
        </button>

        <button class="agenda-menu-item" type="button" data-acao="${CFG.ACAO_EDITAR}">
          <i class="fa-regular fa-pen-to-square"></i> Editar
        </button>

        <button
          class="agenda-menu-item danger"
          type="button"
          data-acao="toggle-status"
          data-scope="tabela_plano"
          data-id="${C.escapeHtml(id)}"
          data-status="${C.escapeHtml(statusRaw)}">
          <i class="fa-regular ${ativo ? "fa-circle-xmark" : "fa-circle-check"}"></i>
          ${ativo ? "Inativar" : "Ativar"}
        </button>
      </div>
    `;
  }

  function cardTemplate(p) {
    const id = p.id ?? "";
    const nome = p.nome ?? "Plano";
    const valor = p.valor ?? "—";
    const limite = p.limite ?? "—";
    const status = normalizeStatus(p.status);
    const destaque = destaqueFiltroNorm(p.destaque);

    return `
      <article class="agenda-card"
        data-id="${C.escapeHtml(id)}"
        data-status="${C.escapeHtml(status)}"
        data-destaque="${C.escapeHtml(destaque)}"
        data-nome="${C.escapeHtml(nome)}"
        data-created_at="${C.escapeHtml(p.created_at || "")}"
        data-ref="${C.escapeHtml(p.ref || "")}"
        data-cobranca="${C.escapeHtml(p.cobranca || "")}"
        data-descricao="${C.escapeHtml(p.descricao || "")}"
        data-observacao="${C.escapeHtml(p.observacao || "")}"
        data-preco_label="${C.escapeHtml(p.valor || "")}"
        data-limite_label="${C.escapeHtml(p.limite || "")}"
        data-limite_usuarios="${C.escapeHtml(String(p.limite_usuarios ?? ""))}"
        data-limite_proprietarios="${C.escapeHtml(String(p.limite_proprietarios ?? ""))}"
        data-limite_profissionais="${C.escapeHtml(String(p.limite_profissionais ?? ""))}"
        data-limite_recepcionistas="${C.escapeHtml(String(p.limite_recepcionistas ?? ""))}"
        data-limite_servicos="${C.escapeHtml(String(p.limite_servicos ?? ""))}"
        data-limite_agendamentos="${C.escapeHtml(String(p.limite_agendamentos ?? ""))}"
        data-preco_mensal="${C.escapeHtml(String(p.preco_mensal ?? ""))}">

        <div class="agenda-hora">${C.escapeHtml(initials(nome))}</div>

        <div class="agenda-info">
          <div class="agenda-nome">${C.escapeHtml(nome)}</div>

          <div class="agenda-servico-linha">
            <div class="agenda-servico">${C.escapeHtml(valor)}</div>
            ${limite ? `<div class="agenda-duracao">• ${C.escapeHtml(limite)}</div>` : ""}
          </div>

          <div class="agenda-linha-extra">
            ${p.ref ? `<span class="agenda-duracao"><strong>Referência:</strong> ${C.escapeHtml(p.ref)}</span>` : ""}
            ${p.created_at ? `<span class="agenda-duracao"><strong>Cadastro:</strong> ${C.escapeHtml(String(p.created_at).split("-").reverse().join("/"))}</span>` : ""}
          </div>

          ${p.descricao ? `<div class="agenda-linha-extra"><span class="agenda-duracao">${C.escapeHtml(p.descricao)}</span></div>` : ""}

          <div class="agenda-linha-extra">
            ${badgeStatus(status)}
            ${badgeDestaque(destaque)}
          </div>
        </div>

        <div class="agenda-acoes" aria-haspopup="menu">
          <button class="agenda-btn-acoes" type="button"
            data-acao="toggle-menu" aria-expanded="false" title="Ações">
            ${iconAcoes()}
          </button>

          ${buildMenuAcoes({ ...p, status })}
        </div>
      </article>
    `;
  }

  // ==========================================================
  // Preencher modais
  // ==========================================================
  function preencherModalEditarPlano(card) {
    if (!card || !modalEditarPlano) return;

    const setVal = (selector, value) => {
      const el = modalEditarPlano.querySelector(selector);
      if (el) el.value = value ?? "";
    };

    setVal("#e_plano_id", card.dataset.id || "");
    setVal("#e_nome", card.dataset.nome || "");
    setVal("#e_ref", card.dataset.ref || "");
    setVal("#e_cobranca", card.dataset.cobranca || "");
    setVal("#e_limite_usuarios", card.dataset.limite_usuarios || "");
    setVal("#e_limite_proprietarios", card.dataset.limite_proprietarios || "");
    setVal("#e_limite_profissionais", card.dataset.limite_profissionais || "");
    setVal("#e_limite_recepcionistas", card.dataset.limite_recepcionistas || "");
    setVal("#e_limite_servicos", card.dataset.limite_servicos || "");
    setVal("#e_limite_agendamentos", card.dataset.limite_agendamentos || "");
    setVal("#e_status", statusFiltroNorm(card.dataset.status || "") || "ativo");
    setVal("#e_destaque", destaqueFiltroNorm(card.dataset.destaque || "") || "0");
    setVal("#e_descricao", card.dataset.descricao || "");
    setVal("#e_obs", card.dataset.observacao || "");

    const precoInput = modalEditarPlano.querySelector("#e_preco");
    if (precoInput) {
      const bruto = String(card.dataset.preco_mensal || "").replace(",", ".").trim();
      precoInput.value = bruto || "";
    }

    modalEditarPlano.querySelector("#e_limite_usuarios")?.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function preencherModalVisualizarPlano(card) {
    if (!card || !modalVisualizarPlano) return;

    const nome = card.dataset.nome || "";
    const ref = card.dataset.ref || "";
    const cobranca = card.dataset.cobranca || "";
    const status = card.dataset.status || "";
    const destaque = destaqueFiltroNorm(card.dataset.destaque || "") === "1" ? "Sim" : "Não";
    const descricao = card.dataset.descricao || "—";
    const observacao = card.dataset.observacao || "—";
    const preco = card.dataset.preco_label || "—";
    const limiteUsuarios = card.dataset.limite_usuarios || "—";
    const limiteProprietarios = card.dataset.limite_proprietarios || "—";
    const limiteProfissionais = card.dataset.limite_profissionais || "—";
    const limiteRecepcionistas = card.dataset.limite_recepcionistas || "—";
    const limiteServicos = card.dataset.limite_servicos || "—";
    const limiteAgendamentos = card.dataset.limite_agendamentos || "—";

    const setText = (selector, value) => {
      const el = modalVisualizarPlano.querySelector(selector);
      if (el) el.textContent = value ?? "";
    };

    setText("#vp_avatar", initials(nome));
    setText("#vp_nome", nome || "—");

    setText("#vp_chip_status", status || "—");
    setText("#vp_chip_cobranca", cobranca || "—");
    setText("#vp_chip_destaque", destaque);
    setText("#vp_chip_ref", `Ref: ${ref || "—"}`);

    setText("#vp_preco", preco);
    setText("#vp_cobranca", cobranca || "—");

    setText("#vp_limite_usuarios", limiteUsuarios);
    setText("#vp_limite_proprietarios", limiteProprietarios);
    setText("#vp_limite_profissionais", limiteProfissionais);
    setText("#vp_limite_recepcionistas", limiteRecepcionistas);
    setText("#vp_limite_servicos", limiteServicos);
    setText("#vp_limite_agendamentos", limiteAgendamentos);

    setText("#vp_status", status || "—");
    setText("#vp_destaque", destaque);
    setText("#vp_ref", ref || "—");
    setText("#vp_descricao", descricao);
    setText("#vp_obs", observacao);
  }

  // ==========================================================
  // Filtro
  // ==========================================================
  function inRange(dataISO, iniISO, fimISO) {
    if (!dataISO || !iniISO || !fimISO) return true;
    return dataISO >= iniISO && dataISO <= fimISO;
  }

  function aplicarFiltro(lista) {
    const f = FILTRO.planos || {};
    return (lista || []).filter((p) => {
      const data = String(p.created_at || "");
      const okPeriodo = (!f.inicio || !f.fim) ? true : inRange(data, f.inicio, f.fim);
      const okStatus = statusBate(p.status, f.status);
      const okDestaque = destaqueBate(p.destaque, f.destaque);
      return okPeriodo && okStatus && okDestaque;
    });
  }

  function setLabelFiltro(iniISO, fimISO, statusVal, destaqueVal) {
    if (!$label) return;

    const temPeriodo = !!iniISO && !!fimISO;
    const st = statusFiltroNorm(statusVal);
    const ds = destaqueFiltroNorm(destaqueVal);

    if (!temPeriodo && !st && !ds) {
      $label.textContent = "Filtro";
      return;
    }

    const br = (iso) => String(iso).split("-").reverse().join("/");
    const partes = [];

    if (temPeriodo) partes.push(`${br(iniISO)} - ${br(fimISO)}`);
    if (st) partes.push(st.charAt(0).toUpperCase() + st.slice(1));
    if (ds) partes.push(ds === "1" ? "Destaque" : "Sem destaque");

    $label.textContent = partes.join(" • ");
  }

  // ==========================================================
  // Popover
  // ==========================================================
  function ensureBackdrop() {
    let bd = document.getElementById("popoverBackdrop");
    if (!bd) {
      bd = document.createElement("div");
      bd.id = "popoverBackdrop";
      bd.className = "popover-backdrop";
      bd.hidden = true;
      document.body.appendChild(bd);
    }
    return bd;
  }

  function showBackdrop() {
    const bd = ensureBackdrop();
    bd.hidden = false;
    document.body.classList.add("is-popover-open");
    bd.onclick = () => fecharPopoverFiltro();
  }

  function hideBackdrop() {
    const bd = document.getElementById("popoverBackdrop");
    if (bd) bd.hidden = true;
    document.body.classList.remove("is-popover-open");
  }

  function aplicarModoMobilePopover(pop) {
    if (!pop) return;
    pop.dataset.mode = "mobile";
    pop.style.position = "fixed";
    pop.style.left = "8px";
    pop.style.right = "8px";
    pop.style.top = "";
    pop.style.bottom = "10px";
    pop.style.transform = "none";
    pop.style.maxWidth = "520px";
    pop.style.width = "calc(100% - 16px)";
    pop.style.margin = "0 auto";
    pop.style.zIndex = "2147483000";
  }

  function posicionarPopoverDesktop(btn, pop) {
    if (!btn || !pop) return;

    pop.dataset.mode = "desktop";
    pop.style.position = "fixed";
    pop.style.zIndex = "2147483000";
    pop.style.right = "";
    pop.style.bottom = "";
    pop.style.margin = "";
    pop.style.transform = "none";

    const wasHidden = pop.hasAttribute("hidden");
    if (wasHidden) pop.removeAttribute("hidden");

    pop.style.visibility = "hidden";
    pop.style.left = "0px";
    pop.style.top = "0px";

    const rBtn = btn.getBoundingClientRect();
    const rPop = pop.getBoundingClientRect();

    const gap = 8;
    let top = rBtn.bottom + gap;
    let left = (rBtn.left + rBtn.width) - rPop.width;

    const minLeft = 8;
    const maxLeft = window.innerWidth - rPop.width - 8;
    left = Math.max(minLeft, Math.min(left, maxLeft));

    const maxTop = window.innerHeight - rPop.height - 8;
    if (top > maxTop) {
      top = rBtn.top - rPop.height - gap;
      top = Math.max(8, Math.min(top, maxTop));
    }

    pop.style.left = `${left}px`;
    pop.style.top = `${top}px`;
    pop.style.visibility = "";

    if (wasHidden) pop.setAttribute("hidden", "");
  }

  function prepararPopoverParaViewport() {
    if (!$btnFiltro || !$popFiltro) return;

    if ($popFiltro.parentElement !== document.body) {
      document.body.appendChild($popFiltro);
    }

    if (isMobile()) {
      aplicarModoMobilePopover($popFiltro);
      return;
    }

    posicionarPopoverDesktop($btnFiltro, $popFiltro);
  }

  function abrirPopoverFiltro() {
    if (!$btnFiltro || !$popFiltro) return;

    const aberto = !$popFiltro.hasAttribute("hidden");
    fecharPopoverFiltro();
    if (aberto) return;

    prepararPopoverParaViewport();
    showBackdrop();

    $popFiltro.removeAttribute("hidden");
    $btnFiltro.setAttribute("aria-expanded", "true");
    $popFiltro.setAttribute("aria-hidden", "false");
  }

  function fecharPopoverFiltro() {
    if (!$btnFiltro || !$popFiltro) return;

    $popFiltro.setAttribute("hidden", "");
    $btnFiltro.setAttribute("aria-expanded", "false");
    $popFiltro.setAttribute("aria-hidden", "true");
    hideBackdrop();
  }

  // ==========================================================
  // Paginação
  // ==========================================================
  function paginarLista(lista) {
    const total = lista.length;
    const porPag = CFG.itensPorPagina;
    const totalPaginas = Math.max(1, Math.ceil(total / porPag));

    const atual = Math.max(1, Math.min(PAGINA_ATUAL.planos || 1, totalPaginas));
    PAGINA_ATUAL.planos = atual;

    const ini = (atual - 1) * porPag;
    const fim = ini + porPag;

    return {
      pageItems: lista.slice(ini, fim),
      total,
      porPag,
      totalPaginas,
      paginaAtual: atual
    };
  }

  function renderPaginacao(info = META_API) {
    if (!pagDiv) return;

    const paginaAtual = Number(info.page || 1);
    const totalPaginas = Number(info.pages || 1);
    if (Number(info.total || 0) === 0 || totalPaginas <= 1) {
      pagDiv.innerHTML = "";
      return;
    }

    pagDiv.innerHTML = "";

    if (paginaAtual > 1) {
      const btnAnterior = document.createElement("button");
      btnAnterior.type = "button";
      btnAnterior.textContent = "◀ Anterior";
      btnAnterior.classList.add("btn-pag");
      btnAnterior.addEventListener("click", () => {
        PAGINA_ATUAL.planos = Math.max(1, paginaAtual - 1);
        carregar(true);
      });
      pagDiv.appendChild(btnAnterior);
    }

    if (paginaAtual < totalPaginas) {
      const btnProximo = document.createElement("button");
      btnProximo.type = "button";
      btnProximo.textContent = "Próximo ▶";
      btnProximo.classList.add("btn-pag");
      btnProximo.addEventListener("click", () => {
        PAGINA_ATUAL.planos = Math.min(totalPaginas, paginaAtual + 1);
        carregar(true);
      });
      pagDiv.appendChild(btnProximo);
    }
  }

  // ==========================================================
  // Pesquisa
  // ==========================================================
  function aplicarPesquisaLista(lista) {
    const termo = inputPesquisa ? C.normalizar(inputPesquisa.value.trim()) : "";
    if (btnLimparPesquisa) btnLimparPesquisa.style.display = termo ? "inline-flex" : "none";
    if (!termo) return lista;

    return lista.filter((p) => {
      const blob = C.normalizar(
        `${p.id} ${p.nome} ${p.valor} ${p.limite} ${p.status} ${p.destaque} ${p.ref} ${p.descricao} ${p.observacao}`
      );
      return blob.includes(termo);
    });
  }

  // ==========================================================
  // Render
  // ==========================================================
  function renderTudo() {
    let lista = (BASE_LISTA || []).slice();
    lista.forEach((p) => (p.status = normalizeStatus(p.status)));

    if (btnLimparPesquisa) btnLimparPesquisa.style.display = inputPesquisa?.value.trim() ? "inline-flex" : "none";

    if (!Number(META_API.total || 0)) {
      box.innerHTML = `
        <div class="agenda-vazio">
          <div class="agenda-vazio-icone">💳</div>
          <div class="agenda-vazio-titulo">${CFG.EMPTY_MSG}</div>
        </div>
      `;
      if (pagDiv) pagDiv.innerHTML = "";
      return;
    }

    box.innerHTML = lista.map(cardTemplate).join("");
    renderPaginacao();
  }

  const menuCtrl = C.createFloatingMenuController({
    rootSelector: CFG.ROOT_SELECTOR_MENU
  });

  // ==========================================================
  // Bind filtro
  // ==========================================================
  function bindFiltro() {
    if (!$btnFiltro || !$popFiltro || !$formFiltro || !$ini || !$fim || !$st || !$ds || !$label || !$limpar || !$fechar) {
      return;
    }

    $ini.removeAttribute("required");
    $fim.removeAttribute("required");

    const hasTodosStatus = Array.from($st.options).some((o) => statusFiltroNorm(o.value) === "");
    if (!hasTodosStatus) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = "Todos";
      $st.insertBefore(opt, $st.firstChild);
    }

    const f = FILTRO.planos || {};

    FILTRO.planos = {
      ...f,
      inicio: f.inicio || "",
      fim: f.fim || "",
      status: statusFiltroNorm(f.status || "ativo"),
      destaque: destaqueFiltroNorm(f.destaque || "")
    };

    $ini.value = FILTRO.planos.inicio;
    $fim.value = FILTRO.planos.fim;
    $st.value = FILTRO.planos.status || "ativo";
    $ds.value = FILTRO.planos.destaque || "";

    setLabelFiltro(
      FILTRO.planos.inicio,
      FILTRO.planos.fim,
      $st.value || "ativo",
      $ds.value || ""
    );

    $btnFiltro.addEventListener("click", (ev) => {
      ev.stopPropagation();
      abrirPopoverFiltro();
    });

    $fechar.addEventListener("click", (ev) => {
      ev.stopPropagation();
      fecharPopoverFiltro();
    });

    $limpar.addEventListener("click", async (ev) => {
      ev.stopPropagation();

      const ini = "";
      const fim = "";

      $ini.value = ini;
      $fim.value = fim;
      $st.value = "ativo";
      $ds.value = "";

      FILTRO.planos = {
        inicio: ini,
        fim: fim,
        status: "ativo",
        destaque: ""
      };

      PAGINA_ATUAL.planos = 1;

      setLabelFiltro(ini, fim, "ativo", "");
      fecharPopoverFiltro();

      __CARREGADO__ = false;
      BASE_LISTA = [];
      await carregar(true);
    });

    $formFiltro.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      if (($ini.value && !$fim.value) || (!$ini.value && $fim.value)) {
        toast({ type: "warning", title: "Filtro", msg: "Selecione início e fim (ou deixe os dois vazios)." });
        return;
      }

      if ($ini.value && $fim.value && $ini.value > $fim.value) {
        toast({ type: "warning", title: "Filtro", msg: "Início não pode ser maior que o fim." });
        return;
      }

      const ini = $ini.value || "";
      const fim = $fim.value || "";

      FILTRO.planos = {
        inicio: ini,
        fim: fim,
        status: $st.value || "",
        destaque: $ds.value || "",
      };

      $ini.value = ini;
      $fim.value = fim;
      PAGINA_ATUAL.planos = 1;

      setLabelFiltro(ini, fim, $st.value || "", $ds.value || "");
      fecharPopoverFiltro();

      __CARREGADO__ = false;
      BASE_LISTA = [];
      await carregar(true);
    });
  }

  // ==========================================================
  // Eventos globais da aba
  // ==========================================================
  function bindEventosGlobais() {
    document.addEventListener("click", (ev) => {
      const btnToggle = ev.target.closest('button[data-acao="toggle-menu"]');
      const menuItem = ev.target.closest(".agenda-menu-item");
      const cliqueDentroPopover = ev.target.closest(".popover, .popover-simple, .popover-vaga-livre");
      const cliqueNoBtnFiltro = ev.target.closest(`#${FIDS.btn}`);
      const cliqueDentroModalLocal = ev.target.closest(`#${CFG.MODAL_EDITAR_ID}, #${CFG.MODAL_VISUALIZAR_ID}`);
      const btnLimpar = ev.target.closest(`#${CFG.ABA_ID} .btn-limpar-pesquisa`);

      if (btnToggle && !aba.contains(btnToggle)) return;
      if (menuItem && !aba.contains(menuItem)) return;
      if (btnLimpar && !aba.contains(btnLimpar)) return;

      if (!cliqueDentroPopover && !cliqueNoBtnFiltro && !cliqueDentroModalLocal) {
        fecharPopoverFiltro();
      }

      if (btnLimpar && inputPesquisa) {
        inputPesquisa.value = "";
        inputPesquisa.focus();
        PAGINA_ATUAL.planos = 1;
        renderTudo();
        return;
      }

      if (btnToggle) {
        ev.stopPropagation();
        menuCtrl.toggle(btnToggle);
        return;
      }

      if (menuItem) {
        const card = menuCtrl.getOwnerCard() || menuItem.closest(".agenda-card");
        if (!card || !aba.contains(card)) {
          menuCtrl.fechar();
          return;
        }

        const id = card?.dataset?.id || "";
        const acao = menuItem.getAttribute("data-acao") || "";

        menuCtrl.fechar();

        if (acao === CFG.ACAO_EDITAR) {
          preencherModalEditarPlano(card);
          abrirModalEditarPlano();
          return;
        }

        if (acao === CFG.ACAO_VISUALIZAR) {
          preencherModalVisualizarPlano(card);
          abrirModalVisualizarPlano();
          return;
        }

        if (acao === "toggle-status") {
          console.log("[PLANOS] toggle-status:", id);
          return;
        }

        console.log("[PLANOS] ação:", acao, "id:", id);
        return;
      }

      menuCtrl.fechar();
    });

    function reajustarSeAberto() {
      if ($btnFiltro && $popFiltro && !$popFiltro.hasAttribute("hidden")) {
        prepararPopoverParaViewport();
        $popFiltro.removeAttribute("hidden");
      }
    }

    window.addEventListener("resize", reajustarSeAberto);

    window.addEventListener("scroll", () => {
      if (isMobile()) return;
      if ($btnFiltro && $popFiltro && !$popFiltro.hasAttribute("hidden")) {
        posicionarPopoverDesktop($btnFiltro, $popFiltro);
      }
    }, true);

    document.addEventListener("keydown", (ev) => {
      if (ev.key !== "Escape") return;

      try { menuCtrl.fechar(); } catch (_) {}
      fecharPopoverFiltro();

      if (modalEditarPlano?.classList.contains("ativo")) {
        ev.preventDefault();
        ev.stopPropagation();
        fecharModalEditarPlano();
        return;
      }

      if (modalVisualizarPlano?.classList.contains("ativo")) {
        ev.preventDefault();
        ev.stopPropagation();
        fecharModalVisualizarPlano();
      }
    });
  }

  // ==========================================================
  // Bind pesquisa
  // ==========================================================
  function bindPesquisa() {
    if (inputPesquisa) {
      inputPesquisa.addEventListener(
        "input",
        C.debounce(() => {
          PAGINA_ATUAL.planos = 1;
          carregar(true);
        }, 350)
      );
    }

    if (btnLimparPesquisa && inputPesquisa) {
      btnLimparPesquisa.addEventListener("click", () => {
        inputPesquisa.value = "";
        inputPesquisa.focus();
        PAGINA_ATUAL.planos = 1;
        carregar(true);
      });
    }
  }

  // ==========================================================
  // API helpers
  // ==========================================================
  function formatBRL(v) {
    const n = Number(String(v ?? "0").replace(",", "."));
    if (!isFinite(n)) return "—";
    return n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  }

  function cobrancaLabel(v) {
    const s = C.normalizar(v || "");
    if (s.includes("trimes")) return "trimestre";
    if (s.includes("semes")) return "semestre";
    if (s.includes("anual")) return "ano";
    return "mês";
  }

  function montaLimites(p) {
    const valor = (limite) => limite ?? "—";

    return [
      `Usuários: ${valor(p.limite_usuarios)}`,
      `Proprietários: ${valor(p.limite_proprietarios)}`,
      `Profissionais: ${valor(p.limite_profissionais)}`,
      `Recepcionistas: ${valor(p.limite_recepcionistas)}`,
      `Serviços: ${valor(p.limite_servicos)}`,
      `Agendamentos/mês: ${valor(p.limite_agendamentos)}`,
    ].join(" • ");
  }

  async function obterDados() {
    if (CFG.MOCK) return [];

    const f = FILTRO.planos || {};
    const inicio = f.inicio || "";
    const fim = f.fim || "";
    const busca = inputPesquisa?.value.trim() || "";
    const status = f.status || "todos";
    const destaque = f.destaque || "";
    const page = PAGINA_ATUAL.planos;
    const limit = CFG.itensPorPagina;
    const ordem = FILTRO.planos.ordem || "nome_asc";
    const json = await C.fetchJSON(buildApiUrl({ inicio, fim, busca, status, destaque, ordem, page, limit }));

    if (!json?.ok) throw new Error(json?.user_msg || json?.msg || "API retornou erro.");

    const raw = Array.isArray(json?.data) ? json.data : [];
    return {
      meta: json?.meta || { page, limit, total: raw.length, pages: 1 },
      items: raw.map((p) => {
      const valor = `${formatBRL(p.preco_mensal)} / ${cobrancaLabel(p.cobranca)}`;
      return {
        id: p.id_plano ?? "",
        nome: p.nome ?? "",
        valor,
        limite: montaLimites(p),

        status: p.status ?? "ativo",
        destaque: String(p.destaque ?? "0"),

        created_at: onlyDate(p.criado_em ?? ""),

        ref: p.ref ?? "",
        cobranca: p.cobranca ?? "",
        descricao: p.descricao ?? "",
        observacao: p.observacao ?? "",

        limite_usuarios: p.limite_usuarios ?? "",
        limite_proprietarios: p.limite_proprietarios ?? "",
        limite_profissionais: p.limite_profissionais ?? "",
        limite_recepcionistas: p.limite_recepcionistas ?? "",
        limite_servicos: p.limite_servicos ?? "",
        limite_agendamentos: p.limite_agendamentos ?? "",
        preco_mensal: p.preco_mensal ?? "",
      };
      })
    };
  }

  async function carregar(force = false) {
    if (__CARREGADO__ && !force) return;

    const idRequisicao = ++REQUISICAO_ATUAL;
    try {
      const resultado = await obterDados();
      if (idRequisicao !== REQUISICAO_ATUAL) return;
      BASE_LISTA = resultado.items;
      META_API = resultado.meta;
      PAGINA_ATUAL.planos = Number(META_API.page || 1);
      __CARREGADO__ = true;
      renderTudo();
    } catch (e) {
      toast({
        type: "danger",
        title: "Planos",
        msg: `Falha ao carregar: ${e?.message || "erro"}`,
        timeout: 4500
      });

      box.innerHTML = `
        <div class="painel-card" style="padding:14px">
          <strong>⚠️ Planos</strong><br>
          <span style="color:var(--muted)">Falha ao carregar.</span>
        </div>
      `;

      if (pagDiv) pagDiv.innerHTML = "";
      console.error("[ListaPlanosSuper]", e);
    }
  }

  function abaVisivel() {
    const st = window.getComputedStyle(aba);
    if (st.display === "none" || st.visibility === "hidden") return false;
    const r = aba.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function init() {
    const limite = document.getElementById("limite_planos");
    const ordem = document.getElementById("ordem_planos");
    limite?.addEventListener("change", () => { CFG.itensPorPagina = [20, 50, 100].includes(Number(limite.value)) ? Number(limite.value) : 20; PAGINA_ATUAL.planos = 1; carregar(true); });
    ordem?.addEventListener("change", () => { FILTRO.planos.ordem = ordem.value || "nome_asc"; PAGINA_ATUAL.planos = 1; carregar(true); });
    FILTRO.planos = FILTRO.planos || {};
    if (typeof FILTRO.planos.inicio === "undefined") FILTRO.planos.inicio = "";
    if (typeof FILTRO.planos.fim === "undefined") FILTRO.planos.fim = "";
    if (typeof FILTRO.planos.status === "undefined") FILTRO.planos.status = "ativo";
    if (typeof FILTRO.planos.destaque === "undefined") FILTRO.planos.destaque = "";

    if ($ini) $ini.value = FILTRO.planos.inicio;
    if ($fim) $fim.value = FILTRO.planos.fim;
    if ($st) $st.value = FILTRO.planos.status;
    if ($ds) $ds.value = FILTRO.planos.destaque;

    setLabelFiltro(
      FILTRO.planos.inicio,
      FILTRO.planos.fim,
      FILTRO.planos.status || "",
      FILTRO.planos.destaque || ""
    );

    if ($popFiltro) {
      $popFiltro.setAttribute("aria-hidden", $popFiltro.hasAttribute("hidden") ? "true" : "false");
    }

    bindPesquisa();
    bindFiltro();
    bindEventosGlobais();

    bindFechamentoModalLocal(modalEditarPlano, fecharModalEditarPlano);
    bindFechamentoModalLocal(modalVisualizarPlano, fecharModalVisualizarPlano);

    if (abaVisivel()) carregar();

    const mo = new MutationObserver(() => {
      if (__CARREGADO__) return;
      if (abaVisivel()) {
        carregar();
        mo.disconnect();
      }
    });

    mo.observe(aba, { attributes: true, attributeFilter: ["class", "style", "hidden"] });
  }

  window.ListaPlanosSuper = window.ListaPlanosSuper || {};
  window.ListaPlanosSuper.abrirModalEditarPlano = abrirModalEditarPlano;
  window.ListaPlanosSuper.fecharModalEditarPlano = fecharModalEditarPlano;
  window.ListaPlanosSuper.abrirModalVisualizarPlano = abrirModalVisualizarPlano;
  window.ListaPlanosSuper.fecharModalVisualizarPlano = fecharModalVisualizarPlano;
  window.ListaPlanosSuper.preencherModalEditarPlano = preencherModalEditarPlano;
  window.ListaPlanosSuper.preencherModalVisualizarPlano = preencherModalVisualizarPlano;
  window.ListaPlanosSuper.recarregar = () => carregar(true);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
