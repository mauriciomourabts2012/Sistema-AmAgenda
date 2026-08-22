/* ==========================================================
   ListaClientes.js — ABA CLIENTES (ListaCore) + FILTRO + PAGINAÇÃO
   ✅ 100% compatível com lista_cliente.php
   ✅ Pesquisa REAL no backend em todos os registros
   ✅ Paginação REAL no backend
   ✅ Filtro por status REAL no backend
   ✅ Filtro por período REAL no backend
   ✅ Padrão inicial: somente clientes ATIVOS
   ✅ Permite filtrar só por status, sem data
   ✅ Cidade vinda do PHP aparece na lista
   ✅ Informa telefone, observação, último login e última movimentação
   ✅ FOTO DE PERFIL:
      - usa foto_perfil quando existir
      - se vier null/vazia usa avatar padrão
      - se quebrar a imagem usa avatar padrão
   ✅ O PRÓPRIO JS abre/fecha apenas:
      - modalVisualizarCliente
      - modalEditarCliente
   ✅ Protegido para não conflitar com ListaUsuario.js
   ✅ ALERTAS/TOASTS NO MESMO PADRÃO DO SISTEMA
========================================================== */
(() => {
  "use strict";

  if (window.__LISTA_CLIENTES_JS_INIT__) {
    console.warn("[ListaClientes] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__LISTA_CLIENTES_JS_INIT__ = true;

  const C = window.ListaCore;
  if (!C) {
    console.warn("[ListaClientes] ListaCore não carregado.");
    return;
  }

  const CFG = {
    MOCK: false,
    ENDPOINT: "/public/api/api_central.php?path=painel/cliente/listar",
    itensPorPagina: 20,

    ABA_ID: "clientes",
    BOX_ID: "listaClientes",
    PAG_ID: "paginacao_clientes",
    INPUT_ID: "pesquisar-clientes",

    BTN_FILTRO: "btnPeriodo_clientes",
    LABEL_FILTRO: "labelPeriodo_clientes",
    POPOVER: "popoverPeriodo_clientes",
    FORM_FILTRO: "formPeriodo_clientes",
    INICIO: "inicio_clientes",
    FIM: "fim_clientes",
    STATUS: "status_clientes",
    BTN_LIMPAR_FILTRO: "limparFiltro_clientes",
    BTN_FECHAR_FILTRO: "fecharPopover_clientes",

    ROOT_SELECTOR: "#listaClientes",
    EMPTY_MSG: "Nenhum cliente encontrado.",

    MODAL_VISUALIZAR_ID: "modalVisualizarCliente",
    MODAL_EDITAR_ID: "modalEditarCliente",

    FOTO_FALLBACK: "/public/imagens/avatar-default.png",
  };

  const TOAST_DEFAULT_TIMEOUT = 3500;
  const TOAST_LEAVE_TIME = 180;

  const aba = document.getElementById(CFG.ABA_ID);
  const box = document.getElementById(CFG.BOX_ID);
  const pagDiv = document.getElementById(CFG.PAG_ID);
  const inputPesquisa = document.getElementById(CFG.INPUT_ID);

  if (!aba || !box || !pagDiv) {
    console.warn("[ListaClientes] DOM faltando:", { aba, box, pagDiv });
    return;
  }

  const btnLimparPesquisa = aba.querySelector(".btn-limpar-pesquisa");

  const btnFiltro = document.getElementById(CFG.BTN_FILTRO);
  const popover = document.getElementById(CFG.POPOVER);
  const formFiltro = document.getElementById(CFG.FORM_FILTRO);
  const inpInicio = document.getElementById(CFG.INICIO);
  const inpFim = document.getElementById(CFG.FIM);
  const selStatus = document.getElementById(CFG.STATUS);
  const labelFiltro = document.getElementById(CFG.LABEL_FILTRO);
  const btnLimparFiltro = document.getElementById(CFG.BTN_LIMPAR_FILTRO);
  const btnFecharFiltro = document.getElementById(CFG.BTN_FECHAR_FILTRO);

  const MOCK_DATA = [
    {
      id: 1,
      nome: "Ana Souza",
      telefone: "(38) 99822-7737",
      email: "ana@email.com",
      status: "Ativo",
      cidade: "Buritis",
      uf: "MG",
      observacao: "Cliente prefere atendimento no período da tarde.",
      ultimo_login_em: "2026-03-25 14:30:00",
      ultima_movimentacao_em: "2026-03-26 09:12:00",
      created_at: "2026-01-10",
      foto_perfil: ""
    },
    {
      id: 2,
      nome: "Carlos Lima",
      telefone: "",
      email: "",
      status: "Inativo",
      cidade: "",
      uf: "",
      observacao: "",
      ultimo_login_em: null,
      ultima_movimentacao_em: null,
      created_at: "2026-01-13",
      foto_perfil: ""
    },
  ];

  // ==========================================================
  // Toast Universal — mesmo padrão do sistema
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

  function closeToast(el) {
    if (!el) return;
    el.classList.add("is-leaving");
    setTimeout(() => el.remove(), TOAST_LEAVE_TIME);
  }

  function toast({
    title = "",
    message = "—",
    type = "info",
    timeout = TOAST_DEFAULT_TIMEOUT,
    buttonText = "Fechar",
  }) {
    const stack = getToastStack();

    const wrap = document.createElement("div");
    wrap.className = `ui-alert ui-alert--${type}`;

    wrap.innerHTML = `
      <div class="ui-alert__icon">ℹ️</div>
      <div class="ui-alert__content">
        <p class="ui-alert__title"></p>
        <p class="ui-alert__msg"></p>
      </div>
      <div class="ui-alert__actions">
        <button type="button" class="ui-alert__btn js-close">${buttonText}</button>
      </div>
    `;

    const $title = wrap.querySelector(".ui-alert__title");
    const $msg = wrap.querySelector(".ui-alert__msg");
    const $close = wrap.querySelector(".js-close");
    const $icon = wrap.querySelector(".ui-alert__icon");

    $title.textContent =
      (title || "").trim() ||
      (type === "success" ? "Sucesso" :
       type === "warning" ? "Atenção" :
       type === "danger" ? "Erro" :
       type === "neutral" ? "Aviso" :
       type === "confirm" ? "Confirmação" : "Aviso");

    $msg.textContent = String(message ?? "").trim() || "—";

    if ($icon) {
      $icon.textContent =
        type === "danger" ? "❌" :
        type === "success" ? "✅" :
        type === "warning" ? "⚠️" :
        type === "neutral" ? "💬" :
        type === "confirm" ? "ℹ️" :
        "ℹ️";
    }

    $close?.addEventListener("click", () => closeToast(wrap));
    stack.appendChild(wrap);

    if (timeout > 0) {
      setTimeout(() => closeToast(wrap), timeout);
    }

    return wrap;
  }

  function toastMsg(type, msg, title = "", timeout = TOAST_DEFAULT_TIMEOUT) {
    toast({ type, title, message: msg, timeout });
  }

  const onlyDigits = (v) => String(v || "").replace(/\D/g, "");

  function initials(nome) {
    const p = String(nome ?? "").trim().split(/\s+/).filter(Boolean);
    if (!p.length) return "?";
    const a = p[0][0] || "";
    const b = p.length > 1 ? (p[p.length - 1][0] || "") : "";
    return (a + b).toUpperCase();
  }

  function normalizeStatus(status) {
    const st = C.normalizar(status).trim();
    if (st === "inativo" || st.includes("inativ")) return "Inativo";
    if (st === "bloqueado" || st.includes("bloque")) return "Bloqueado";
    return "Ativo";
  }

  function badgeStatus(status) {
    const st = normalizeStatus(status);

    let cls = "st-confirmado";
    if (st === "Inativo") cls = "st-cancelado";
    if (st === "Bloqueado") cls = "st-pendente";

    return `<span class="agenda-status ${cls}">${C.escapeHtml(st)}</span>`;
  }

  function iconAcoes() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 7.25a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Z"/>
      </svg>
    `;
  }

  function buildMenuAcoes(cli) {
    const st = normalizeStatus(cli.status);
    const ativo = st === "Ativo";
    const id = String(cli.id ?? "").trim();

    return `
      <div class="agenda-menu" role="menu">
        <button class="agenda-menu-item" type="button" data-acao="visualizar">
          <i class="fa-regular fa-eye"></i> Visualizar
        </button>

        <button class="agenda-menu-item" type="button" data-acao="editar">
          <i class="fa-regular fa-pen-to-square"></i> Editar
        </button>

        <button
          class="agenda-menu-item danger"
          type="button"
          data-acao="toggle-status"
          data-scope="tabela_cliente"
          data-id="${C.escapeHtml(id)}"
          data-status="${C.escapeHtml(String(cli.status ?? ""))}">
          <i class="fa-regular ${ativo ? "fa-circle-xmark" : "fa-circle-check"}"></i>
          ${ativo ? "Inativar" : "Ativar"}
        </button>
      </div>
    `;
  }

  function montarLocalizacao(cli) {
    const cidade = String(cli.cidade || "").trim();
    const uf = String(cli.uf || "").trim();

    if (cidade && uf) return `${cidade} - ${uf}`;
    if (cidade) return cidade;
    if (uf) return uf;

    return "";
  }

  function formatarDataHoraBR(valor) {
    const raw = String(valor || "").trim();
    if (!raw) return "";

    const normalizado = raw.replace(" ", "T");
    const data = new Date(normalizado);

    if (!Number.isNaN(data.getTime())) {
      const dd = String(data.getDate()).padStart(2, "0");
      const mm = String(data.getMonth() + 1).padStart(2, "0");
      const yyyy = data.getFullYear();
      const hh = String(data.getHours()).padStart(2, "0");
      const mi = String(data.getMinutes()).padStart(2, "0");
      return `${dd}/${mm}/${yyyy} ${hh}:${mi}`;
    }

    const partes = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (partes) {
      const [, ano, mes, dia, hora = "00", minuto = "00"] = partes;
      return `${dia}/${mes}/${ano} ${hora}:${minuto}`;
    }

    return raw;
  }

  function formatarDataBR(iso) {
    const raw = String(iso || "").trim();
    if (!raw) return "";

    const partes = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (partes) {
      const [, ano, mes, dia] = partes;
      return `${dia}/${mes}/${ano}`;
    }

    const data = new Date(raw);
    if (!Number.isNaN(data.getTime())) {
      const dd = String(data.getDate()).padStart(2, "0");
      const mm = String(data.getMonth() + 1).padStart(2, "0");
      const yyyy = data.getFullYear();
      return `${dd}/${mm}/${yyyy}`;
    }

    return raw;
  }

  function normalizeFotoUrl(url) {
    const foto = String(url || "").trim();
    if (!foto) return CFG.FOTO_FALLBACK;
    return foto;
  }

  function avatarClienteTemplate(cli) {
    const nome = cli.nome ?? "Cliente";
    const foto = normalizeFotoUrl(cli.foto_url || cli.foto_perfil || "");

    return `
      <div class="agenda-hora agenda-hora--avatar">
        <img
          src="${C.escapeHtml(foto)}"
          alt="Foto de ${C.escapeHtml(nome)}"
          class="agenda-avatar-img"
          loading="lazy"
          referrerpolicy="no-referrer"
          onerror="if(this.dataset.fallbackApplied==='1'){this.onerror=null;return;}this.dataset.fallbackApplied='1';this.src='${C.escapeHtml(CFG.FOTO_FALLBACK)}';"
        >
      </div>
    `;
  }

  function cardTemplate(cli) {
    const id = cli.id ?? "";
    const nome = cli.nome ?? "Cliente";
    const email = String(cli.email ?? "").trim();
    const status = normalizeStatus(cli.status);
    const cidade = montarLocalizacao(cli);
    const telefone = String(cli.telefone || "").trim();
    const dataCadastro = formatarDataBR(cli.created_at);

    const telRaw = String(cli.telefone || "");
    const temTel = onlyDigits(telRaw).length >= 10;
    const foto = normalizeFotoUrl(cli.foto_url || cli.foto_perfil || "");

    return `
      <article class="agenda-card cliente-lista-card"
        data-id="${C.escapeHtml(String(id))}"
        data-status="${C.escapeHtml(String(status))}"
        data-created="${C.escapeHtml(String(cli.created_at || cli.data_cadastro || cli.data || ""))}"
        data-foto="${C.escapeHtml(String(foto))}">

        <div class="cliente-card-conteudo">
          <div class="cliente-card-cabecalho">
            ${avatarClienteTemplate(cli)}
            <div class="cliente-card-identidade">
              <div class="agenda-nome">${C.escapeHtml(nome)}</div>
              <div class="cliente-card-email">${C.escapeHtml(email || "E-mail não informado")}</div>
            </div>
          </div>

          <div class="cliente-card-dados">
            <span><strong>ID:</strong> ${C.escapeHtml(String(id))}</span>
            <span><strong>Telefone:</strong> ${C.escapeHtml(telefone || "Não informado")}</span>
            ${cidade ? `<span><strong>Localização:</strong> ${C.escapeHtml(cidade)}</span>` : ""}
            <span><strong>Status:</strong> ${C.escapeHtml(status)}</span>
            <span><strong>Data:</strong> ${C.escapeHtml(dataCadastro || "Não informada")}</span>
          </div>

          <div class="cliente-card-chips">
            <span class="cliente-chip">Cliente</span>
            ${badgeStatus(status)}
          </div>
        </div>

        <div class="agenda-acoes" aria-haspopup="menu">
          <button class="agenda-btn-whats ${temTel ? "" : "is-disabled"}"
            type="button"
            data-acao="whatsapp"
            data-telefone="${C.escapeHtml(telRaw)}"
            data-nome="${C.escapeHtml(nome)}"
            ${temTel ? "" : 'aria-disabled="true" disabled'}
            title="${temTel ? "WhatsApp" : "Sem telefone cadastrado"}">
            <i class="fa-brands fa-whatsapp"></i>
          </button>

          <button class="agenda-btn-acoes" type="button"
            data-acao="toggle-menu" aria-expanded="false" title="Ações">
            ${iconAcoes()}
          </button>

          ${buildMenuAcoes({ ...cli, status })}
        </div>
      </article>
    `;
  }

  let BASE_LISTA = [];
  let PAGINA_ATUAL = 1;
  let PAGINACAO_API = {
    pagina_atual: 1,
    limite: CFG.itensPorPagina,
    total_registros: 0,
    total_paginas: 1,
    tem_anterior: false,
    tem_proxima: false,
  };

  const FILTRO = {
    inicio: "",
    fim: "",
    status: "ativo"
  };

  function brData(iso) {
    return String(iso || "").split("-").reverse().join("/");
  }

  function setLabelFiltro(iniISO, fimISO, statusVal) {
    if (!labelFiltro) return;

    const st = C.normalizar(statusVal || "");
    const partes = [];

    if (iniISO && fimISO) {
      partes.push(`${brData(iniISO)} - ${brData(fimISO)}`);
    } else if (iniISO) {
      partes.push(`A partir de ${brData(iniISO)}`);
    } else if (fimISO) {
      partes.push(`Até ${brData(fimISO)}`);
    }

    if (st) {
      if (st === "todos") partes.push("Todos");
      else if (st.includes("inativ")) partes.push("Inativo");
      else if (st.includes("bloque")) partes.push("Bloqueado");
      else partes.push("Ativo");
    }

    labelFiltro.textContent = partes.length ? partes.join(" • ") : "Filtro";
  }

  function getTermoPesquisa() {
    return inputPesquisa ? String(inputPesquisa.value || "").trim() : "";
  }

  function updateBotaoLimparPesquisa() {
    if (!btnLimparPesquisa) return;
    btnLimparPesquisa.style.display = getTermoPesquisa() ? "inline-flex" : "none";
  }

  function renderPaginacao(info) {
    if (!pagDiv) return;

    const paginaAtual = Number(info?.pagina_atual || 1);
    const totalPaginas = Number(info?.total_paginas || 1);
    const temAnterior = !!info?.tem_anterior;
    const temProxima = !!info?.tem_proxima;

    pagDiv.innerHTML = "";

    if (!info || Number(info?.total_registros || 0) <= 0 || totalPaginas <= 1) {
      return;
    }

    if (temAnterior) {
      const btnAnterior = document.createElement("button");
      btnAnterior.type = "button";
      btnAnterior.textContent = "◀ Anterior";
      btnAnterior.classList.add("btn-pag");
      btnAnterior.addEventListener("click", () => {
        PAGINA_ATUAL = Math.max(1, paginaAtual - 1);
        carregar(true);
      });
      pagDiv.appendChild(btnAnterior);
    }

    if (temProxima) {
      const btnProximo = document.createElement("button");
      btnProximo.type = "button";
      btnProximo.textContent = "Próximo ▶";
      btnProximo.classList.add("btn-pag");
      btnProximo.addEventListener("click", () => {
        PAGINA_ATUAL = Math.min(totalPaginas, paginaAtual + 1);
        carregar(true);
      });
      pagDiv.appendChild(btnProximo);
    }
  }

  function renderTudo() {
    const lista = Array.isArray(BASE_LISTA) ? BASE_LISTA.slice() : [];

    if (lista.length === 0) {
      box.innerHTML = `
        <div class="agenda-vazio">
          <div class="agenda-vazio-icone">👥</div>
          <div class="agenda-vazio-titulo">${C.escapeHtml(CFG.EMPTY_MSG)}</div>
        </div>
      `;
      pagDiv.innerHTML = "";
      return;
    }

    box.innerHTML = lista.map(cardTemplate).join("");
    renderPaginacao(PAGINACAO_API);
  }

  function resetPagina() {
    PAGINA_ATUAL = 1;
  }

  const POP_MARGIN = 12;
  const MQ_MOBILE = window.matchMedia("(max-width: 680px)");
  const Z_FRONT = 10050;

  function isMobile() {
    return !!MQ_MOBILE.matches;
  }

  const POPOVER_ORIG = {
    parent: popover ? popover.parentNode : null,
    next: popover ? popover.nextSibling : null,
  };

  function ensurePopoverFront() {
    if (!popover) return;
    popover.style.zIndex = String(Z_FRONT);
  }

  function movePopoverToBodyIfMobile() {
    if (!popover || !isMobile()) return;

    if (popover.parentNode !== document.body) {
      document.body.appendChild(popover);
    }

    popover.style.position = "fixed";
    popover.style.zIndex = String(Z_FRONT);
    popover.style.left = "12px";
    popover.style.right = "12px";
    popover.style.bottom = "12px";
    popover.style.top = "auto";
    popover.style.width = "auto";
    popover.style.maxWidth = "none";
    popover.style.transform = "";
  }

  function restorePopoverParent() {
    if (!popover || !POPOVER_ORIG.parent) return;

    if (popover.parentNode === document.body) {
      if (POPOVER_ORIG.next && POPOVER_ORIG.next.parentNode === POPOVER_ORIG.parent) {
        POPOVER_ORIG.parent.insertBefore(popover, POPOVER_ORIG.next);
      } else {
        POPOVER_ORIG.parent.appendChild(popover);
      }
    }
  }

  function limparInlinePopover() {
    if (!popover) return;
    popover.style.position = "";
    popover.style.zIndex = "";
    popover.style.left = "";
    popover.style.top = "";
    popover.style.right = "";
    popover.style.bottom = "";
    popover.style.width = "";
    popover.style.maxWidth = "";
    popover.style.transform = "";
  }

  function posicionarPopoverEsquerda() {
    if (!btnFiltro || !popover) return;
    if (popover.hasAttribute("hidden")) return;

    ensurePopoverFront();

    if (isMobile()) {
      movePopoverToBodyIfMobile();
      return;
    }

    popover.style.position = "fixed";
    popover.style.zIndex = String(Z_FRONT);

    const b = btnFiltro.getBoundingClientRect();

    popover.style.left = "-9999px";
    popover.style.top = "-9999px";

    const p = popover.getBoundingClientRect();

    let left = b.right - p.width;
    let top = b.bottom + 8;

    left = Math.max(POP_MARGIN, Math.min(left, window.innerWidth - p.width - POP_MARGIN));
    top = Math.max(POP_MARGIN, Math.min(top, window.innerHeight - p.height - POP_MARGIN));

    popover.style.right = "";
    popover.style.bottom = "";

    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(top)}px`;
  }

  function fecharPopover() {
    if (!popover || !btnFiltro) return;
    popover.setAttribute("hidden", "");
    btnFiltro.setAttribute("aria-expanded", "false");

    restorePopoverParent();
    limparInlinePopover();
  }

  function abrirPopover() {
    if (!popover || !btnFiltro) return;

    popover.removeAttribute("hidden");
    btnFiltro.setAttribute("aria-expanded", "true");

    ensurePopoverFront();

    if (isMobile()) movePopoverToBodyIfMobile();

    requestAnimationFrame(() => {
      posicionarPopoverEsquerda();
      setTimeout(() => inpInicio?.focus?.(), 0);
    });
  }

  function bindFiltro() {
    if (!btnFiltro || !popover || !formFiltro || !inpInicio || !inpFim || !selStatus || !labelFiltro) return;

    inpInicio.value = FILTRO.inicio || "";
    inpFim.value = FILTRO.fim || "";
    selStatus.value = FILTRO.status || "ativo";
    setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status);

    btnFiltro.addEventListener("click", (ev) => {
      ev.stopPropagation();
      const aberto = !popover.hasAttribute("hidden");
      if (aberto) fecharPopover();
      else abrirPopover();
    });

    if (btnFecharFiltro) {
      btnFecharFiltro.addEventListener("click", (ev) => {
        ev.stopPropagation();
        fecharPopover();
      });
    }

    if (btnLimparFiltro) {
      btnLimparFiltro.addEventListener("click", (ev) => {
        ev.stopPropagation();

        inpInicio.value = "";
        inpFim.value = "";
        selStatus.value = "ativo";

        FILTRO.inicio = "";
        FILTRO.fim = "";
        FILTRO.status = "ativo";

        resetPagina();
        setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status);
        fecharPopover();
        carregar(true);
      });
    }

    formFiltro.addEventListener("submit", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      const ini = (inpInicio.value || "").trim();
      const fim = (inpFim.value || "").trim();
      const status = (selStatus.value || "").trim();

      if (ini && fim && ini > fim) {
        toastMsg("warning", "A data inicial não pode ser maior que a data final.", "Atenção");
        return;
      }

      FILTRO.inicio = ini;
      FILTRO.fim = fim;
      FILTRO.status = status || "todos";

      resetPagina();
      setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status);
      fecharPopover();
      carregar(true);
    });

    document.addEventListener("click", (ev) => {
      if (!popover || popover.hasAttribute("hidden")) return;
      const cliqueDentro = ev.target.closest(`#${CFG.POPOVER}`) || ev.target.closest(`#${CFG.BTN_FILTRO}`);
      if (!cliqueDentro) fecharPopover();
    });

    document.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape") fecharPopover();
    });

    window.addEventListener("scroll", posicionarPopoverEsquerda, true);
    window.addEventListener("resize", posicionarPopoverEsquerda);

    MQ_MOBILE.addEventListener?.("change", () => {
      if (!popover || popover.hasAttribute("hidden")) return;
      posicionarPopoverEsquerda();
    });
  }

  function bindPesquisa() {
    updateBotaoLimparPesquisa();

    if (inputPesquisa) {
      inputPesquisa.addEventListener(
        "input",
        C.debounce(() => {
          updateBotaoLimparPesquisa();
          resetPagina();
          carregar(true);
        }, 350)
      );
    }

    if (btnLimparPesquisa && inputPesquisa) {
      btnLimparPesquisa.addEventListener("click", () => {
        inputPesquisa.value = "";
        inputPesquisa.focus();
        updateBotaoLimparPesquisa();
        resetPagina();
        carregar(true);
      });
    }
  }

  const menuCtrl = C.createFloatingMenuController({ rootSelector: CFG.ROOT_SELECTOR });

  function montarMensagemWhatsapp({ nome }) {
    return `Olá ${nome || ""}! Tudo bem?\n\nGostaria de confirmar seu agendamento. 😊`;
  }

  function abrirWhatsapp({ telefone, nome }) {
    let tel = onlyDigits(telefone);
    if (!tel || tel.length < 10) {
      toastMsg("warning", "Telefone inválido ou não informado para este cliente.", "Atenção");
      return;
    }
    if (!tel.startsWith("55")) tel = "55" + tel;

    const msg = encodeURIComponent(montarMensagemWhatsapp({ nome }));
    window.open(`https://wa.me/${tel}?text=${msg}`, "_blank");
  }

  function fecharMenuAcoes() {
    try { menuCtrl.fechar(); } catch (_) {}
  }

  function getClienteById(id) {
    const alvo = String(id || "").trim();
    if (!alvo) return null;
    return BASE_LISTA.find((cli) => String(cli.id) === alvo) || null;
  }

  function abrirModalPorId(id) {
    const modal = document.getElementById(id);
    if (!modal) {
      console.warn("[ListaClientes] Modal não encontrado:", id);
      return;
    }

    modal.classList.add("ativo");
    modal.classList.add("aberto", "show");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open", "body-modal-open", "is-modal-open", "modal-aberto");
  }

  function fecharModalPorId(id) {
    const modal = document.getElementById(id);
    if (!modal) return;

    modal.classList.remove("ativo", "aberto", "show");
    modal.setAttribute("aria-hidden", "true");

    const existeOutroAberto = document.querySelector(
      ".modal-geral.ativo, .modal-cards.ativo, .modal-geral.aberto, .modal-cards.aberto, .modal-geral.show, .modal-cards.show"
    );

    if (!existeOutroAberto) {
      document.body.classList.remove("modal-open", "body-modal-open", "is-modal-open", "modal-aberto");
    }
  }

  function fecharModalElemento(modal) {
    if (!modal) return;

    modal.classList.remove("ativo", "aberto", "show");
    modal.setAttribute("aria-hidden", "true");

    const existeOutroAberto = document.querySelector(
      ".modal-geral.ativo, .modal-cards.ativo, .modal-geral.aberto, .modal-cards.aberto, .modal-geral.show, .modal-cards.show"
    );

    if (!existeOutroAberto) {
      document.body.classList.remove("modal-open", "body-modal-open", "is-modal-open", "modal-aberto");
    }
  }

  function getModalPai(element) {
    return element.closest(`#${CFG.MODAL_VISUALIZAR_ID}, #${CFG.MODAL_EDITAR_ID}`);
  }

  function limparErrosFormulario(form) {
    if (!form) return;

    form.querySelectorAll(".modal-campo.erro").forEach((el) => {
      el.classList.remove("erro");
    });

    form.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
      el.classList.remove("ativo");
    });
  }

  function preencherModalVisualizar(cli) {
    const modal = document.getElementById(CFG.MODAL_VISUALIZAR_ID);
    if (!modal || !cli) return;

    const nome = String(cli.nome || "Cliente").trim() || "Cliente";
    const telefone = String(cli.telefone || "").trim();
    const email = String(cli.email || "").trim();
    const observacao = String(cli.observacao || "").trim();
    const status = normalizeStatus(cli.status);
    const dataCadastro = formatarDataBR(cli.created_at);
    const localizacao = montarLocalizacao(cli);

    const elAvatar = modal.querySelector("#vc_cli_avatar");
    const elNome = modal.querySelector("#vc_cli_nome");
    const elTelefone = modal.querySelector("#vc_cli_telefone");
    const elChipStatus = modal.querySelector("#vc_cli_chip_status");
    const elEmail = modal.querySelector("#vc_cli_email");
    const elDataCadastro = modal.querySelector("#vc_cli_data_cadastro");
    const elObs = modal.querySelector("#vc_cli_obs");
    const btnWhats = modal.querySelector("#vc_cli_btn_whats");
    const btnCopiar = modal.querySelector("#vc_cli_btn_copiar_tel");

    if (elAvatar) elAvatar.textContent = initials(nome);
    if (elNome) elNome.textContent = nome;

    if (elTelefone) {
      elTelefone.textContent = telefone ? `Telefone: ${telefone}` : "Telefone: —";
    }

    if (elChipStatus) {
      elChipStatus.textContent = status;
      elChipStatus.className = "vc-chip";
      if (status === "Ativo") elChipStatus.classList.add("st-confirmado");
      else if (status === "Inativo") elChipStatus.classList.add("st-cancelado");
      else if (status === "Bloqueado") elChipStatus.classList.add("st-pendente");
    }

    if (elEmail) elEmail.textContent = email || "—";
    if (elDataCadastro) elDataCadastro.textContent = dataCadastro || "—";
    if (elObs) elObs.textContent = observacao || "—";

    if (btnWhats) {
      const temTel = onlyDigits(telefone).length >= 10;
      btnWhats.disabled = !temTel;
      btnWhats.setAttribute("aria-disabled", temTel ? "false" : "true");
      btnWhats.title = temTel ? "WhatsApp" : "Sem telefone cadastrado";
      btnWhats.onclick = () => {
        if (!temTel) return;
        abrirWhatsapp({ telefone, nome });
      };
    }

    if (btnCopiar) {
      btnCopiar.onclick = async () => {
        if (!telefone) {
          toastMsg("warning", "Este cliente não possui telefone cadastrado.", "Atenção");
          return;
        }

        try {
          await navigator.clipboard.writeText(telefone);
          toastMsg("success", "Telefone copiado.");
        } catch (_) {
          toastMsg("warning", "Não foi possível copiar o telefone.", "Atenção");
        }
      };
    }

    const infoCard = modal.querySelector(".vc-grid .vc-card:first-child");
    if (infoCard) {
      let linhaLocalizacao = infoCard.querySelector(".vc-linha-localizacao");

      if (!linhaLocalizacao) {
        linhaLocalizacao = document.createElement("div");
        linhaLocalizacao.className = "vc-linha vc-linha-localizacao";
        linhaLocalizacao.innerHTML = `<strong>Localização:</strong> <span>—</span>`;
        infoCard.appendChild(linhaLocalizacao);
      }

      const spanLoc = linhaLocalizacao.querySelector("span");
      if (spanLoc) spanLoc.textContent = localizacao || "—";
    }
  }

  function preencherModalEditar(cli) {
    const modal = document.getElementById(CFG.MODAL_EDITAR_ID);
    if (!modal || !cli) return;

    const form = modal.querySelector("#formEditarCliente");
    if (form) {
      limparErrosFormulario(form);
      try { form.reset(); } catch (_) {}
    }

    const setValue = (selector, valor) => {
      const el = modal.querySelector(selector);
      if (el) el.value = valor ?? "";
    };

    setValue("#e_cli_id", cli.id ?? "");
    setValue("#e_cli_nome", cli.nome ?? "");
    setValue("#e_cli_telefone", cli.telefone ?? "");
    setValue("#e_cli_email", cli.email ?? "");
    setValue("#e_cli_status", C.normalizar(cli.status || "ativo"));
    setValue("#e_cli_obs", cli.observacao ?? "");
  }

  function abrirModalVisualizarCliente(id) {
    const cli = getClienteById(id);
    if (!cli) {
      toastMsg("warning", "Cliente não encontrado.", "Atenção");
      return;
    }

    preencherModalVisualizar(cli);
    abrirModalPorId(CFG.MODAL_VISUALIZAR_ID);
  }

  function abrirModalEditarCliente(id) {
    const cli = getClienteById(id);
    if (!cli) {
      toastMsg("warning", "Cliente não encontrado.", "Atenção");
      return;
    }

    preencherModalEditar(cli);
    abrirModalPorId(CFG.MODAL_EDITAR_ID);
  }

  function bindEventosGlobais() {
    document.addEventListener("click", (ev) => {
      const btnFecharModal = ev.target.closest("[data-fechar-modal]");
      if (btnFecharModal) {
        const modal = getModalPai(btnFecharModal);
        if (modal) {
          fecharModalElemento(modal);
          return;
        }
      }

      const modalVisualizar = document.getElementById(CFG.MODAL_VISUALIZAR_ID);
      const modalEditar = document.getElementById(CFG.MODAL_EDITAR_ID);

      const dentroDaLista = ev.target.closest(CFG.ROOT_SELECTOR);
      const dentroDoMenuFlutuante = ev.target.closest(".agenda-menu.menu-flutuante");

      const btnWhats = ev.target.closest('button[data-acao="whatsapp"]');
      const btnToggle = ev.target.closest('button[data-acao="toggle-menu"]');
      const menuItem = ev.target.closest(".agenda-menu-item");

      if (!dentroDaLista && !dentroDoMenuFlutuante) {
        fecharMenuAcoes();
        return;
      }

      if (btnWhats) {
        const cardDono = btnWhats.closest(".agenda-card");
        if (!cardDono || !cardDono.closest(CFG.ROOT_SELECTOR)) return;

        ev.stopPropagation();
        if (btnWhats.disabled || btnWhats.getAttribute("aria-disabled") === "true") return;

        abrirWhatsapp({
          telefone: btnWhats.dataset.telefone,
          nome: btnWhats.dataset.nome
        });
        return;
      }

      if (btnToggle) {
        const cardDono = btnToggle.closest(".agenda-card");
        if (!cardDono || !cardDono.closest(CFG.ROOT_SELECTOR)) return;

        ev.stopPropagation();
        menuCtrl.toggle(btnToggle);
        return;
      }

      if (menuItem) {
        const cardDono = menuCtrl.getOwnerCard() || menuItem.closest(".agenda-card");

        if (!cardDono || !cardDono.closest(CFG.ROOT_SELECTOR)) {
          return;
        }

        ev.stopPropagation();

        const id = cardDono.dataset?.id || "";
        const acao = menuItem.dataset.acao || "";

        menuCtrl.fechar();

        if (acao === "visualizar") {
          abrirModalVisualizarCliente(id);
          return;
        }

        if (acao === "editar") {
          abrirModalEditarCliente(id);
          return;
        }

        if (acao === "toggle-status") {
          return;
        }

        if (acao === "excluir") {
          console.log("AÇÃO CLIENTE: excluir | ID:", id);
          return;
        }

        return;
      }

      menuCtrl.fechar();
    });

    document.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape") {
        fecharMenuAcoes();
        fecharModalPorId(CFG.MODAL_VISUALIZAR_ID);
        fecharModalPorId(CFG.MODAL_EDITAR_ID);
      }
    });
  }

  function mapItemApi(item) {
    return {
      id: item?.id_cliente ?? "",
      nome: item?.nome_completo ?? "Cliente",
      telefone: item?.whatsapp_celular ?? "",
      email: item?.email ?? "",
      status: item?.status ?? "ativo",
      foto_perfil: item?.foto_perfil ?? null,
      foto_url: item?.foto_url ?? item?.foto_perfil ?? null,
      created_at: String(item?.criado_em || "").slice(0, 10),
      atualizado_em: item?.atualizado_em ?? null,
      cadastro_completo: Number(item?.cadastro_completo ?? 0),
      cpf: item?.cpf ?? "",
      cidade: item?.cidade ?? "",
      bairro: item?.bairro ?? "",
      uf: item?.uf ?? "",
      observacao: item?.observacao ?? "",
      ultimo_login_em: item?.ultimo_login_em ?? null,
      ultima_movimentacao_em: item?.ultima_movimentacao_em ?? null,
      primeiro_acesso_em: item?.primeiro_acesso_em ?? null,
      ultimo_agendamento_em: item?.ultimo_agendamento_em ?? null,
      ultimo_atendimento_em: item?.ultimo_atendimento_em ?? null,
      total_agendamentos: Number(item?.total_agendamentos ?? 0)
    };
  }

  async function obterDados(signal) {
    if (CFG.MOCK) {
      return {
        items: MOCK_DATA.slice(),
        paginacao: {
          pagina_atual: 1,
          limite: CFG.itensPorPagina,
          total_registros: MOCK_DATA.length,
          total_paginas: 1,
          tem_anterior: false,
          tem_proxima: false,
        }
      };
    }

    const url = new URL(CFG.ENDPOINT, window.location.origin);

    url.searchParams.set("pagina", String(PAGINA_ATUAL));
    url.searchParams.set("limite", String(CFG.itensPorPagina));
    url.searchParams.set("ordem", FILTRO.ordem || "movimentacao");

    const termo = getTermoPesquisa();
    if (termo) {
      url.searchParams.set("q", termo);
    }

    const status = String(FILTRO.status || "todos").trim();
    url.searchParams.set("status", C.normalizar(status || "todos"));

    if (FILTRO.inicio) {
      url.searchParams.set("inicio", FILTRO.inicio);
    }

    if (FILTRO.fim) {
      url.searchParams.set("fim", FILTRO.fim);
    }

    const json = await C.fetchJSON(url.toString(), { signal });

    if (!json || json.ok !== true) {
      throw new Error(json?.user_msg || "Falha ao carregar clientes.");
    }

    const items = Array.isArray(json?.data?.items) ? json.data.items : [];
    const paginacao = json?.data?.paginacao || {
      pagina_atual: 1,
      limite: CFG.itensPorPagina,
      total_registros: 0,
      total_paginas: 1,
      tem_anterior: false,
      tem_proxima: false,
    };

    return {
      items: items.map(mapItemApi),
      paginacao,
    };
  }

  let __CARREGADO__ = false;
  let __CARREGANDO__ = false;
  let CONTROLADOR_REQUISICAO = null;
  let REQUISICAO_ATUAL = 0;

  async function carregar(forcar = false) {
    if (__CARREGADO__ && !forcar) return;

    __CARREGANDO__ = true;
    const idRequisicao = ++REQUISICAO_ATUAL;
    CONTROLADOR_REQUISICAO?.abort();
    CONTROLADOR_REQUISICAO = new AbortController();

    try {
      const resp = await obterDados(CONTROLADOR_REQUISICAO.signal);
      if (idRequisicao !== REQUISICAO_ATUAL) return;

      BASE_LISTA = Array.isArray(resp?.items) ? resp.items : [];
      PAGINACAO_API = resp?.paginacao || PAGINACAO_API;

      PAGINA_ATUAL = Number(PAGINACAO_API?.pagina_atual || PAGINA_ATUAL || 1);
      __CARREGADO__ = true;

      updateBotaoLimparPesquisa();
      setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status);
      renderTudo();
    } catch (e) {
      if (e?.name === "AbortError") return;
      box.innerHTML = `
        <div class="painel-card" style="padding:14px">
          <strong>⚠️ Clientes</strong><br>
          <span style="color:var(--muted)">Falha ao carregar: ${C.escapeHtml(e?.message || "erro")}</span>
        </div>
      `;
      pagDiv.innerHTML = "";
      console.error("[ListaClientes]", e);
      toastMsg("danger", e?.message || "Falha ao carregar clientes.", "Erro");
    } finally {
      if (idRequisicao === REQUISICAO_ATUAL) __CARREGANDO__ = false;
    }
  }

  function abaVisivel() {
    const st = window.getComputedStyle(aba);
    if (st.display === "none" || st.visibility === "hidden") return false;
    const r = aba.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function bindPreferenciasLista() {
    const limite = document.getElementById("limite_clientes");
    const ordem = document.getElementById("ordem_clientes");
    limite?.addEventListener("change", () => {
      CFG.itensPorPagina = [20, 50, 100].includes(Number(limite.value)) ? Number(limite.value) : 20;
      resetPagina();
      carregar(true);
    });
    ordem?.addEventListener("change", () => {
      FILTRO.ordem = ordem.value || "movimentacao";
      resetPagina();
      carregar(true);
    });
  }

  function init() {
    bindPreferenciasLista();
    if (popover && !popover.hasAttribute("hidden")) popover.setAttribute("hidden", "");
    if (btnFiltro) btnFiltro.setAttribute("aria-expanded", "false");

    if (selStatus && !selStatus.value) {
      selStatus.value = "ativo";
    }

    const modalVisualizar = document.getElementById(CFG.MODAL_VISUALIZAR_ID);
    const modalEditar = document.getElementById(CFG.MODAL_EDITAR_ID);

    if (modalVisualizar) modalVisualizar.setAttribute("aria-hidden", "true");
    if (modalEditar) modalEditar.setAttribute("aria-hidden", "true");

    bindPesquisa();
    bindFiltro();
    bindEventosGlobais();

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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
