/* ==========================================================
   ListaEmpresas.js — ABA EMPRESAS (ListaCore)
   ✅ Compatível com API CENTRAL + lista_empresa.php
   ✅ Endpoint:
      /public/api/api_central.php?path=superadmin/empresa/listar
   ✅ Filtro:
      - período opcional
      - status opcional
      - se vazio, PHP assume:
        de = hoje - 3 meses
        ate = hoje
        status = ativo
   ✅ NOVO COMPORTAMENTO:
      - se usuário não informar datas/status, JS usa o que o PHP devolveu em meta
      - inputs do filtro ficam preenchidos com meta.de / meta.ate / meta.status
      - label do filtro mostra os valores efetivos
   ✅ NOVO AJUSTE:
      - mostra o ID de cada empresa na renderização da lista
   ✅ NOVO AJUSTE:
      - cria automaticamente o link de login da empresa
   ✅ Paginação SERVER-SIDE
   ✅ Pesquisa SERVER-SIDE
   ✅ Menu Ações: createFloatingMenuController (toggle-menu)
   ✅ AJUSTE PROFISSIONAL:
      - modal editar empresa abre SOMENTE por este JS
      - modal editar empresa fecha SOMENTE por este JS
      - NÃO usa data-abrir-modal no botão editar
========================================================== */
(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C) {
    console.warn("[ListaEmpresas] ListaCore não carregado.");
    return;
  }

  if (window.__LISTA_EMPRESAS_INIT__) {
    console.warn("[ListaEmpresas] Script já inicializado.");
    return;
  }
  window.__LISTA_EMPRESAS_INIT__ = true;

  // =========================
  // CONFIG
  // =========================
  const CFG = {
    ENDPOINT: "/public/api/api_central.php?path=superadmin/empresa/listar",

    ABA_ID: "empresas",
    BOX_ID: "listaEmpresas",
    INPUT_ID: "pesquisar-empresas",
    PAG_ID: "paginacao_empresas",

    ROOT_SELECTOR_MENU: ".conteudo-agenda",
    itensPorPagina: 5,
    EMPTY_MSG: "Nenhuma empresa encontrada.",
    MOBILE_MAX: 680,

    LOGIN_EMPRESA_BASE_URL: "https://localhost",

    MODAL_EDITAR_ID: "modalEditarEmpresa",
    FORM_EDITAR_ID: "formEmpEditar",
  };

  // =========================
  // DOM
  // =========================
  const aba = document.getElementById(CFG.ABA_ID);
  const box = document.getElementById(CFG.BOX_ID);
  const pagDiv = document.getElementById(CFG.PAG_ID);
  const inputPesquisa = document.getElementById(CFG.INPUT_ID);

  if (!aba || !box) {
    console.warn("[ListaEmpresas] DOM faltando:", { aba, box });
    return;
  }

  const btnLimparPesquisa = aba.querySelector(".btn-limpar-pesquisa");

  // =========================
  // FILTRO (IDs)
  // =========================
  const FIDS = {
    wrap: "filtroAbaEmpresas",
    btn: "btnPeriodo_empresas",
    pop: "popoverPeriodo_empresas",
    form: "formPeriodo_empresas",
    ini: "inicio_empresas",
    fim: "fim_empresas",
    status: "status_empresas",
    label: "labelPeriodo_empresas",
    limpar: "limparFiltro_empresas",
    fechar: "fecharPopover_empresas",
  };

  const $btnFiltro = document.getElementById(FIDS.btn);
  const $popFiltro = document.getElementById(FIDS.pop);
  const $formFiltro = document.getElementById(FIDS.form);
  const $ini = document.getElementById(FIDS.ini);
  const $fim = document.getElementById(FIDS.fim);
  const $st = document.getElementById(FIDS.status);
  const $label = document.getElementById(FIDS.label);
  const $limpar = document.getElementById(FIDS.limpar);
  const $fechar = document.getElementById(FIDS.fechar);

  // =========================
  // MODAL EDITAR
  // =========================
  const modalEditar = document.getElementById(CFG.MODAL_EDITAR_ID);
  const formEmpEditar = document.getElementById(CFG.FORM_EDITAR_ID);

  const empEditId = document.getElementById("emp_edit_id");
  const empEditNome = document.getElementById("emp_edit_nome");
  const empEditCnpj = document.getElementById("emp_edit_cnpj");
  const empEditEmail = document.getElementById("emp_edit_email");
  const empEditTel = document.getElementById("emp_edit_tel");
  const empEditPlano = document.getElementById("emp_edit_plano");
  const empEditStatus = document.getElementById("emp_edit_status");
  const empEditEndereco = document.getElementById("emp_edit_endereco");
  const empEditObs = document.getElementById("emp_edit_obs");

  // =========================
  // ESTADO
  // =========================
  let BASE_LISTA = [];
  let __CARREGADO__ = false;
  let __CARREGANDO__ = false;

  const FILTRO = {
    empresas: {
      inicio: "",
      fim: "",
      status: "ativo",
      busca: "",
    }
  };

  const META = {
    page: 1,
    limit: CFG.itensPorPagina,
    total: 0,
    total_pages: 1,
    de: "",
    ate: "",
    status: "",
    busca: "",
    offset: 0,
  };

  // =========================
  // HELPERS
  // =========================
  function isMobile() {
    return window.innerWidth <= CFG.MOBILE_MAX;
  }

  function initials(nome) {
    const p = String(nome ?? "").trim().split(/\s+/).filter(Boolean);
    if (!p.length) return "??";
    const a = p[0][0] || "";
    const b = p.length > 1 ? (p[p.length - 1][0] || "") : "";
    return (a + b).toUpperCase();
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
    if (s.includes("todos")) return "";
    if (s.includes("bloq")) return "bloqueado";
    if (s.includes("inativ")) return "inativo";
    if (s.includes("ativ")) return "ativo";
    return s;
  }

  function badgeStatus(status) {
    const st = normalizeStatus(status);
    const cls =
      st === "Ativo" ? "st-confirmado" :
      st === "Inativo" ? "st-cancelado" :
      "st-pendente";

    return `<span class="agenda-status ${cls}">${C.escapeHtml(st)}</span>`;
  }

  function iconAcoes() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 7.25a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Z"/>
      </svg>
    `;
  }

  function buildMenuAcoes(emp) {
    const st = normalizeStatus(emp.status);
    const ativo = st === "Ativo";

    const id = String(emp.id ?? "");
    const statusRaw = String(emp.status ?? "").toLowerCase().trim();

    return `
      <div class="agenda-menu" role="menu">
        <button class="agenda-menu-item" type="button" data-acao="editar_empresa">
          <i class="fa-regular fa-pen-to-square"></i> Editar
        </button>

        <button
          class="agenda-menu-item danger"
          type="button"
          data-acao="toggle-status"
          data-scope="tabela_empresa"
          data-id="${C.escapeHtml(id)}"
          data-status="${C.escapeHtml(statusRaw)}"
        >
          <i class="fa-regular ${ativo ? "fa-circle-xmark" : "fa-circle-check"}"></i>
          ${ativo ? "Inativar" : "Ativar"}
        </button>
      </div>
    `;
  }

  function slugEmpresa(nome) {
    return String(nome ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");
  }

  function buildEmpresaLoginLink(id, nome) {
    const empresaId = String(id ?? "").trim();
    if (!empresaId) return "";

    const base = String(CFG.LOGIN_EMPRESA_BASE_URL || "").trim().replace(/\/+$/, "");
    if (!base) return "";

    const nomeSlug = slugEmpresa(nome);
    const url = new URL(`${base}/login.php`);

    url.searchParams.set("empresa", empresaId);

    if (nomeSlug) {
      url.searchParams.set("nome", nomeSlug);
    }

    return url.toString();
  }

  function limparErrosFormularioEditar() {
    if (!formEmpEditar) return;

    formEmpEditar.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
    });

    formEmpEditar.querySelectorAll(".campo-erro, .is-invalid").forEach((el) => {
      el.classList.remove("campo-erro", "is-invalid");
    });
  }

  function selecionarPlanoNoModal(planoId, planoNome) {
    if (!empEditPlano) return;

    const idStr = String(planoId ?? "").trim();
    const nomeStr = String(planoNome ?? "").trim();

    let selecionado = false;

    if (idStr) {
      const existePorId = Array.from(empEditPlano.options).some(
        (opt) => String(opt.value).trim() === idStr
      );

      if (existePorId) {
        empEditPlano.value = idStr;
        selecionado = true;
      }
    }

    if (!selecionado && nomeStr) {
      const optPorTexto = Array.from(empEditPlano.options).find((opt) => {
        const txt = String(opt.textContent || "").trim().toLowerCase();
        return txt === nomeStr.toLowerCase();
      });

      if (optPorTexto) {
        empEditPlano.value = optPorTexto.value;
        selecionado = true;
      }
    }

    if (!selecionado) {
      empEditPlano.value = "";
    }

    empEditPlano.dispatchEvent(new Event("change", { bubbles: true }));
  }

  // =========================
  // MODAL EDITAR - CONTROLE LOCAL
  // =========================
  function abrirModalEditar() {
    if (!modalEditar) return;
    modalEditar.classList.add("ativo");
    modalEditar.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
  }

  function fecharModalEditar() {
    if (!modalEditar) return;
    modalEditar.classList.remove("ativo");
    modalEditar.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");
  }

  function abrirModalEditarEmpresaPeloCard(card) {
    if (!card || !modalEditar) return;

    limparErrosFormularioEditar();

    if (empEditId) empEditId.value = card.dataset.id || "";
    if (empEditNome) empEditNome.value = card.dataset.nome || "";
    if (empEditCnpj) empEditCnpj.value = card.dataset.cnpj || "";
    if (empEditEmail) empEditEmail.value = card.dataset.email || "";
    if (empEditTel) empEditTel.value = card.dataset.telefone || "";
    if (empEditEndereco) empEditEndereco.value = card.dataset.endereco || "";
    if (empEditObs) empEditObs.value = card.dataset.observacao || "";

    if (empEditStatus) {
      const st = String(card.dataset.status || "").toLowerCase().trim();
      empEditStatus.value = ["ativo", "inativo", "bloqueado"].includes(st) ? st : "ativo";
    }

    selecionarPlanoNoModal(
      card.dataset.planoId || "",
      card.dataset.plano || ""
    );

    abrirModalEditar();
  }

  function bindModalEditar() {
    if (!modalEditar) return;

    modalEditar.addEventListener("click", (ev) => {
      const btnFechar = ev.target.closest("[data-fechar-modal]");
      if (btnFechar) {
        ev.preventDefault();
        fecharModalEditar();
        return;
      }

      if (ev.target === modalEditar) {
        fecharModalEditar();
      }
    });

    document.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape" && modalEditar.classList.contains("ativo")) {
        fecharModalEditar();
      }
    });
  }

  function cardTemplate(emp) {
    const id = emp.id ?? "";
    const nome = emp.nome ?? "Empresa";
    const cnpj = emp.cnpj ?? "";
    const plano = emp.plano ?? "—";
    const planoId = emp.plano_id ?? "";
    const status = normalizeStatus(emp.status);
    const telefone = emp.telefone ?? "";
    const email = emp.email ?? "";
    const endereco = emp.endereco ?? "";
    const observacao = emp.observacao ?? "";
    const loginLink = buildEmpresaLoginLink(id, nome);

    return `
      <article class="agenda-card"
        data-id="${C.escapeHtml(String(id))}"
        data-status="${C.escapeHtml(String(status).toLowerCase())}"
        data-nome="${C.escapeHtml(nome)}"
        data-cnpj="${C.escapeHtml(cnpj)}"
        data-plano-id="${C.escapeHtml(String(planoId))}"
        data-plano="${C.escapeHtml(plano)}"
        data-telefone="${C.escapeHtml(telefone)}"
        data-email="${C.escapeHtml(email)}"
        data-endereco="${C.escapeHtml(endereco)}"
        data-observacao="${C.escapeHtml(observacao)}">

        <div class="agenda-hora">${C.escapeHtml(initials(nome))}</div>

        <div class="agenda-info">
          <div class="agenda-nome">${C.escapeHtml(nome)}</div>

          <div class="agenda-linha-extra">
            <span class="agenda-duracao"><strong>ID:</strong> ${C.escapeHtml(String(id || "—"))}</span>
          </div>

          ${loginLink ? `
            <div class="agenda-linha-extra">
              <span class="agenda-duracao">
                <strong>Link:</strong>
                <a href="${C.escapeHtml(loginLink)}" target="_blank" rel="noopener noreferrer">
                  ${C.escapeHtml(loginLink)}
                </a>
              </span>
            </div>
          ` : ""}

          <div class="agenda-servico-linha">
            <div class="agenda-servico">${C.escapeHtml(plano)}</div>
            ${cnpj ? `<div class="agenda-duracao">• ${C.escapeHtml(cnpj)}</div>` : ""}
          </div>

          <div class="agenda-linha-extra">
            ${badgeStatus(status)}
            ${telefone ? `<span class="agenda-duracao">• ${C.escapeHtml(telefone)}</span>` : ""}
            ${email ? `<span class="agenda-duracao">• ${C.escapeHtml(email)}</span>` : ""}
          </div>
        </div>

        <div class="agenda-acoes" aria-haspopup="menu">
          <button class="agenda-btn-acoes" type="button"
            data-acao="toggle-menu" aria-expanded="false" title="Ações">
            ${iconAcoes()}
          </button>

          ${buildMenuAcoes({ ...emp, status })}
        </div>
      </article>
    `;
  }

  function setLabelFiltro(iniISO, fimISO, statusVal) {
    if (!$label) return;

    const temPeriodo = !!iniISO && !!fimISO;
    const st = statusFiltroNorm(statusVal);

    if (!temPeriodo && !st) {
      $label.textContent = "Filtro";
      return;
    }

    const br = (iso) => String(iso).split("-").reverse().join("/");
    const partes = [];

    if (temPeriodo) partes.push(`${br(iniISO)} - ${br(fimISO)}`);
    if (st) partes.push(st.charAt(0).toUpperCase() + st.slice(1));

    $label.textContent = partes.join(" • ");
  }

  function getFiltroEfetivo() {
    const f = FILTRO.empresas || {};

    return {
      inicio: f.inicio || META.de || "",
      fim: f.fim || META.ate || "",
      status: statusFiltroNorm(f.status || META.status || ""),
      busca: String(f.busca || META.busca || "").trim(),
    };
  }

  function syncFiltroUIComMeta() {
    if (!$ini || !$fim || !$st) return;

    const efetivo = getFiltroEfetivo();

    $ini.value = efetivo.inicio || "";
    $fim.value = efetivo.fim || "";

    const stEfetivo = efetivo.status || "";
    const existe = Array.from($st.options).some(opt => statusFiltroNorm(opt.value) === stEfetivo);

    if (existe) {
      const optMatch = Array.from($st.options).find(opt => statusFiltroNorm(opt.value) === stEfetivo);
      $st.value = optMatch ? optMatch.value : "";
    } else {
      $st.value = "";
    }

    setLabelFiltro(efetivo.inicio, efetivo.fim, efetivo.status);
  }

  function buildURL() {
    const url = new URL(CFG.ENDPOINT, window.location.origin);

    const f = FILTRO.empresas || {};

    if (f.inicio) url.searchParams.set("de", f.inicio);
    if (f.fim) url.searchParams.set("ate", f.fim);

    const st = statusFiltroNorm(f.status);
    if (st) url.searchParams.set("status", st);

    const busca = String(f.busca || "").trim();
    if (busca) url.searchParams.set("busca", busca);

    url.searchParams.set("page", String(META.page || 1));
    url.searchParams.set("limit", String(META.limit || CFG.itensPorPagina));

    return url.toString();
  }

  function aplicarMetaServidor(meta) {
    META.page = Number(meta?.page || 1);
    META.limit = Number(meta?.limit || CFG.itensPorPagina);
    META.total = Number(meta?.total || 0);
    META.total_pages = Number(meta?.total_pages || 1);
    META.de = String(meta?.de || "");
    META.ate = String(meta?.ate || "");
    META.status = String(meta?.status || "");
    META.busca = meta?.busca ?? "";
    META.offset = Number(meta?.offset || 0);
  }

  // ==========================================================
  // BACKDROP UNIVERSAL
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

  // ==========================================================
  // POPOVER DESKTOP / MOBILE
  // ==========================================================
  function posicionarPopoverEsquerda(btn, pop) {
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
      $popFiltro.dataset.mode = "mobile";
      $popFiltro.style.position = "";
      $popFiltro.style.left = "";
      $popFiltro.style.right = "";
      $popFiltro.style.top = "";
      $popFiltro.style.bottom = "";
      $popFiltro.style.transform = "";
      $popFiltro.style.maxWidth = "";
      $popFiltro.style.width = "";
      $popFiltro.style.margin = "";
      $popFiltro.style.zIndex = "";
      return;
    }

    posicionarPopoverEsquerda($btnFiltro, $popFiltro);
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

  // =========================
  // RENDER
  // =========================
  function renderLoading() {
    box.innerHTML = `
      <div class="painel-card" style="padding:14px">
        <strong>Carregando empresas...</strong>
      </div>
    `;
    if (pagDiv) pagDiv.innerHTML = "";
  }

  function renderErro(msg) {
    box.innerHTML = `
      <div class="painel-card" style="padding:14px">
        <strong>⚠️ Empresas</strong><br>
        <span style="color:var(--muted)">${C.escapeHtml(msg || "Falha ao carregar.")}</span>
      </div>
    `;
    if (pagDiv) pagDiv.innerHTML = "";
  }

  function renderTudo() {
    const lista = (BASE_LISTA || []).slice();

    if (!lista.length) {
      box.innerHTML = `
        <div class="agenda-vazio">
          <div class="agenda-vazio-icone">🏢</div>
          <div class="agenda-vazio-titulo">${C.escapeHtml(CFG.EMPTY_MSG)}</div>
        </div>
      `;
      if (pagDiv) pagDiv.innerHTML = "";
      return;
    }

    box.setAttribute("data-toggle-scope", "tabela_empresa");
    box.innerHTML = lista.map(cardTemplate).join("");
    renderPaginacao();
  }

  function renderPaginacao() {
    if (!pagDiv) return;

    const paginaAtual = Number(META.page || 1);
    const totalPaginas = Math.max(1, Number(META.total_pages || 1));

    if (Number(META.total || 0) === 0 || totalPaginas <= 1) {
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
        META.page = Math.max(1, paginaAtual - 1);
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
        META.page = Math.min(totalPaginas, paginaAtual + 1);
        carregar(true);
      });
      pagDiv.appendChild(btnProximo);
    }
  }

  // =========================
  // MENU FLUTUANTE
  // =========================
  const menuCtrl = C.createFloatingMenuController({ rootSelector: CFG.ROOT_SELECTOR_MENU });
  function fecharMenuAcoes() {
    try { menuCtrl.fechar(); } catch (_) {}
  }

  // =========================
  // FETCH API
  // =========================
  async function obterDados() {
    const url = buildURL();
    const json = await C.fetchJSON(url);

    if (!json || json.ok !== true) {
      throw new Error(json?.user_msg || "Resposta inválida da API.");
    }

    const items = Array.isArray(json?.data?.items) ? json.data.items : [];
    const meta = json?.data?.meta || {};

    aplicarMetaServidor(meta);

    return items.map((e) => ({
      id: e.id_empresa ?? "",
      nome: e.nome ?? "",
      cnpj: e.cnpj ?? "",
      email: e.email ?? "",
      telefone: e.telefone ?? "",
      plano_id: e.plano_id ?? "",
      plano: e.plano_nome ?? "—",
      status: normalizeStatus(e.status ?? "Ativo"),
      endereco: e.endereco ?? "",
      observacao: e.observacao ?? "",
      created_at: e.criado_em ?? "",
      updated_at: e.atualizado_em ?? "",
    }));
  }

  async function carregar(force = false) {
    if ((__CARREGADO__ && !force) || __CARREGANDO__) return;

    __CARREGANDO__ = true;
    renderLoading();

    try {
      BASE_LISTA = await obterDados();
      __CARREGADO__ = true;

      syncFiltroUIComMeta();
      renderTudo();
    } catch (e) {
      renderErro(e?.message || "Erro ao carregar empresas.");
      console.error("[ListaEmpresas]", e);
    } finally {
      __CARREGANDO__ = false;
    }
  }

  // =========================
  // PESQUISA
  // =========================
  function bindPesquisa() {
    if (inputPesquisa) {
      inputPesquisa.addEventListener(
        "input",
        C.debounce(() => {
          FILTRO.empresas.busca = inputPesquisa.value.trim();
          META.page = 1;

          if (btnLimparPesquisa) {
            btnLimparPesquisa.style.display = inputPesquisa.value.trim() ? "inline-flex" : "none";
          }

          carregar(true);
        }, 350)
      );
    }

    if (btnLimparPesquisa && inputPesquisa) {
      btnLimparPesquisa.addEventListener("click", () => {
        inputPesquisa.value = "";
        inputPesquisa.focus();

        FILTRO.empresas.busca = "";
        META.page = 1;

        btnLimparPesquisa.style.display = "none";
        carregar(true);
      });
    }
  }

  // =========================
  // FILTRO
  // =========================
  function bindFiltro() {
    if (!$btnFiltro || !$popFiltro || !$formFiltro || !$ini || !$fim || !$st || !$label || !$limpar || !$fechar) {
      return;
    }

    $popFiltro.setAttribute("aria-hidden", $popFiltro.hasAttribute("hidden") ? "true" : "false");
    $btnFiltro.setAttribute("aria-expanded", $popFiltro.hasAttribute("hidden") ? "false" : "true");

    $ini.removeAttribute("required");
    $fim.removeAttribute("required");

    const hasTodos = Array.from($st.options).some(o => statusFiltroNorm(o.value) === "");
    if (!hasTodos) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = "Todos";
      $st.insertBefore(opt, $st.firstChild);
    }

    syncFiltroUIComMeta();

    $btnFiltro.addEventListener("click", (ev) => {
      ev.stopPropagation();
      abrirPopoverFiltro();
    });

    $fechar.addEventListener("click", (ev) => {
      ev.stopPropagation();
      fecharPopoverFiltro();
    });

    $limpar.addEventListener("click", (ev) => {
      ev.stopPropagation();

      $ini.value = "";
      $fim.value = "";
      $st.value = "";

      FILTRO.empresas.inicio = "";
      FILTRO.empresas.fim = "";
      FILTRO.empresas.status = "";
      META.page = 1;

      fecharPopoverFiltro();
      carregar(true);
    });

    $formFiltro.addEventListener("submit", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      if (($ini.value && !$fim.value) || (!$ini.value && $fim.value)) {
        alert("⚠️ Selecione início e fim (ou deixe os dois vazios).");
        return;
      }

      if ($ini.value && $fim.value && $ini.value > $fim.value) {
        alert("⚠️ Início não pode ser maior que o fim.");
        return;
      }

      FILTRO.empresas.inicio = $ini.value || "";
      FILTRO.empresas.fim = $fim.value || "";
      FILTRO.empresas.status = $st.value || "";
      META.page = 1;

      fecharPopoverFiltro();
      carregar(true);
    });
  }

  // =========================
  // EVENTOS GLOBAIS
  // =========================
  function bindEventosGlobais() {
    document.addEventListener("click", (ev) => {
      const btnToggle = ev.target.closest('button[data-acao="toggle-menu"]');
      const menuItem = ev.target.closest(".agenda-menu-item");

      const cliqueDentroPopover = ev.target.closest(".popover, .popover-simple, .popover-vaga-livre");
      const cliqueNoBtnFiltro = ev.target.closest(`#${FIDS.btn}`);
      const cliqueNoBackdrop = ev.target.closest("#popoverBackdrop");
      const btnLimpar = ev.target.closest(`#${CFG.ABA_ID} .btn-limpar-pesquisa`);

      if (cliqueNoBackdrop) {
        fecharPopoverFiltro();
        return;
      }

      if (!cliqueDentroPopover && !cliqueNoBtnFiltro) {
        fecharPopoverFiltro();
      }

      if (btnLimpar && inputPesquisa) {
        inputPesquisa.value = "";
        inputPesquisa.focus();

        FILTRO.empresas.busca = "";
        META.page = 1;

        if (btnLimparPesquisa) btnLimparPesquisa.style.display = "none";
        carregar(true);
        return;
      }

      if (btnToggle) {
        ev.stopPropagation();
        menuCtrl.toggle(btnToggle);
        return;
      }

      if (menuItem) {
        const card = menuCtrl.getOwnerCard() || menuItem.closest(".agenda-card");
        const id = card?.dataset?.id || "";
        const acao = menuItem.dataset.acao || "";

        if (acao === "toggle-status") {
          return;
        }

        menuCtrl.fechar();

        if (acao === "editar_empresa") {
          abrirModalEditarEmpresaPeloCard(card);
          return;
        }

        console.log("[EMPRESAS] ação:", acao, "id:", id);
        return;
      }

      menuCtrl.fechar();
    });

    function reajustarSeAberto() {
      if (!$btnFiltro || !$popFiltro) return;
      if ($popFiltro.hasAttribute("hidden")) return;

      prepararPopoverParaViewport();
      $popFiltro.removeAttribute("hidden");
    }

    window.addEventListener("resize", reajustarSeAberto);

    window.addEventListener("scroll", () => {
      if (isMobile()) return;
      if ($btnFiltro && $popFiltro && !$popFiltro.hasAttribute("hidden")) {
        posicionarPopoverEsquerda($btnFiltro, $popFiltro);
      }
    }, true);

    document.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape") {
        fecharMenuAcoes();
        fecharPopoverFiltro();
      }
    });
  }

  // =========================
  // ABA VISÍVEL
  // =========================
  function abaVisivel() {
    const st = window.getComputedStyle(aba);
    if (st.display === "none" || st.visibility === "hidden") return false;
    const r = aba.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  // =========================
  // INIT
  // =========================
  function init() {
    bindPesquisa();
    bindFiltro();
    bindEventosGlobais();
    bindModalEditar();

    if (btnLimparPesquisa && inputPesquisa) {
      btnLimparPesquisa.style.display = inputPesquisa.value.trim() ? "inline-flex" : "none";
    }

    if (abaVisivel()) carregar();

    const mo = new MutationObserver(() => {
      if (__CARREGADO__) return;
      if (abaVisivel()) {
        carregar();
        mo.disconnect();
      }
    });

    mo.observe(aba, {
      attributes: true,
      attributeFilter: ["class", "style", "hidden"]
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();