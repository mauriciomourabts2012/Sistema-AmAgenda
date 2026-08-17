/* ==========================================================
   ListaUsuario.js — ABA USUÁRIOS (ListaCore)
   ✅ API Central (path=...)
   ✅ Default: últimos 30 dias (data_inicio/data_fim)
   ✅ Default inicial: STATUS = ativo
   ✅ Ao aplicar/limpar filtro de PERÍODO, recarrega do backend
   ✅ Status/Perfil filtram no front (rápido)
   ✅ Paginação client-side (padrão manutenção)
   ✅ POPOVER responsivo:
      - Desktop: anchor (fixed, anti-corte)
      - Mobile: bottom-sheet (full width)
   ✅ FIX MOBILE: popover NÃO fica atrás da lista
      - append no body + backdrop + zIndex alto + trava body
   ✅ NOVO: Alerts/Toasts universais (sem alert())
   ✅ AJUSTE: mostra EMPRESA vinculada e status do MEMENTO
   ✅ AJUSTE PROFISSIONAL:
      - Editar usuário abre SOMENTE pelo JS da lista
      - Ação específica: editar_modal_usuario
      - Modal editar usuário NÃO usa data-abrir-modal
      - Fechamento continua compatível com script universal
   ✅ NOVO: mostra FOTO DO USUÁRIO na lista
========================================================== */
(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C) {
    console.warn("[ListaUsuariosSuper] ListaCore não carregado.");
    return;
  }

  // ==========================================================
  // ✅ ALERTS UNIVERSAIS (Toast)
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

  function iconFor(type) {
    switch (type) {
      case "success": return "✓";
      case "warning": return "!";
      case "danger": return "×";
      case "info": return "i";
      case "confirm": return "?";
      default: return "i";
    }
  }

  function clsFor(type) {
    switch (type) {
      case "success": return "ui-alert ui-alert--success";
      case "warning": return "ui-alert ui-alert--warning";
      case "danger": return "ui-alert ui-alert--danger";
      case "confirm": return "ui-alert ui-alert--confirm";
      case "info":
      default: return "ui-alert ui-alert--confirm";
    }
  }

  function toast({
    type = "info",
    title = "Aviso",
    msg = "",
    duration = 3200,
    actions = null,
  } = {}) {
    const stack = getToastStack();
    const el = document.createElement("div");
    el.className = clsFor(type);

    const safeTitle = C.escapeHtml(String(title || ""));
    const safeMsg = C.escapeHtml(String(msg || ""));
    const hasActions = Array.isArray(actions) && actions.length > 0;

    el.innerHTML = `
      <div class="ui-alert__icon" aria-hidden="true">${iconFor(type)}</div>

      <div class="ui-alert__content">
        <p class="ui-alert__title">${safeTitle}</p>
        ${safeMsg ? `<div class="ui-alert__msg">${safeMsg}</div>` : ``}
      </div>

      <div class="ui-alert__actions">
        ${hasActions ? actions.map((a, idx) => {
          const label = C.escapeHtml(String(a?.label || "OK"));
          const cls = a?.primary ? "ui-alert__btn ui-alert__btn--primary" : "ui-alert__btn";
          return `<button type="button" class="${cls}" data-idx="${idx}">${label}</button>`;
        }).join("") : `<button type="button" class="ui-alert__btn ui-alert__btn--primary">OK</button>`}
      </div>
    `;

    const close = () => {
      el.classList.add("is-leaving");
      window.setTimeout(() => el.remove(), 180);
    };

    const btns = el.querySelectorAll("button");
    btns.forEach((b) => {
      b.addEventListener("click", (ev) => {
        const idx = ev.currentTarget.getAttribute("data-idx");
        if (hasActions && idx !== null) {
          const a = actions[Number(idx)];
          try { a?.onClick?.(); } catch (_) {}
          close();
          return;
        }
        close();
      });
    });

    stack.prepend(el);

    if (!hasActions && Number(duration) > 0) {
      window.setTimeout(close, Number(duration));
    }

    return { el, close };
  }

  const ui = {
    info: (msg, title = "Info") => toast({ type: "info", title, msg }),
    success: (msg, title = "Sucesso") => toast({ type: "success", title, msg }),
    warning: (msg, title = "Atenção") => toast({ type: "warning", title, msg }),
    danger: (msg, title = "Erro") => toast({ type: "danger", title, msg }),
    confirm: ({ title = "Confirmar", msg = "", okText = "Confirmar", cancelText = "Cancelar", onOk, onCancel } = {}) =>
      toast({
        type: "confirm",
        title,
        msg,
        duration: 0,
        actions: [
          { label: cancelText, primary: false, onClick: () => { try { onCancel?.(); } catch (_) {} } },
          { label: okText, primary: true, onClick: () => { try { onOk?.(); } catch (_) {} } },
        ],
      }),
  };

  // ===== CONFIG =====
  const CFG = {
    MOCK: false,

    API_URL: "/public/api/api_central.php",
    PATH: "superadmin/usuario/listar",

    ABA_ID: "usuarios",
    BOX_ID: "listaUsuarios",
    INPUT_ID: "pesquisar-usuarios",
    PAG_ID: "paginacao_usuarios",

    ROOT_SELECTOR_MENU: ".conteudo-agenda",
    itensPorPagina: 5,

    EMPTY_MSG: "Nenhum usuário encontrado nos últimos 30 dias. Ajuste o filtro para visualizar registros mais antigos.",
    MOBILE_MAX: 680,

    MODAL_EDITAR_USUARIO_ID: "modalEditarUsuario",
    ACAO_EDITAR_USUARIO: "editar_modal_usuario",

    FOTO_FALLBACK: "/public/imagens/avatar-default.png",
  };

  // =========================
  // DOM
  // =========================
  const aba = document.getElementById(CFG.ABA_ID);
  const box = document.getElementById(CFG.BOX_ID);
  const pagDiv = document.getElementById(CFG.PAG_ID);
  const inputPesquisa = document.getElementById(CFG.INPUT_ID);

  if (!aba || !box) {
    console.warn("[ListaUsuariosSuper] DOM faltando:", { aba, box });
    return;
  }

  const btnLimparPesquisa = aba.querySelector(".btn-limpar-pesquisa");

  // =========================
  // FILTRO (IDs)
  // =========================
  const FIDS = {
    btn: "btnPeriodo_usuarios",
    pop: "popoverPeriodo_usuarios",
    form: "formPeriodo_usuarios",
    ini: "inicio_usuarios",
    fim: "fim_usuarios",
    status: "status_usuarios",
    perfil: "perfil_usuarios",
    label: "labelPeriodo_usuarios",
    limpar: "limparFiltro_usuarios",
    fechar: "fecharPopover_usuarios",
  };

  const $btnFiltro = document.getElementById(FIDS.btn);
  const $popFiltro = document.getElementById(FIDS.pop);
  const $formFiltro = document.getElementById(FIDS.form);
  const $ini = document.getElementById(FIDS.ini);
  const $fim = document.getElementById(FIDS.fim);
  const $st = document.getElementById(FIDS.status);
  const $pf = document.getElementById(FIDS.perfil);
  const $label = document.getElementById(FIDS.label);
  const $limpar = document.getElementById(FIDS.limpar);
  const $fechar = document.getElementById(FIDS.fechar);

  // =========================
  // MODAL EDITAR USUÁRIO
  // =========================
  const modalEditarUsuario = document.getElementById(CFG.MODAL_EDITAR_USUARIO_ID);
  const formEditarUsuario = document.getElementById("formEditarUsuario");

  const camposEdit = {
    id: document.getElementById("edit_u_id"),
    nome: document.getElementById("edit_u_nome"),
    email: document.getElementById("edit_u_email"),
    telefone: document.getElementById("edit_u_tel"),
    senha: document.getElementById("edit_u_senha"),
    senha2: document.getElementById("edit_u_senha2"),
    perfil: document.getElementById("edit_u_perfil_super_admin"),
    empresa: document.getElementById("edit_u_empresa_super_admin"),
    status: document.getElementById("edit_u_status"),
  };

  // =========================
  // MOCK (opcional)
  // =========================
  const MOCK_DATA = [
    {
      id: 1,
      nome: "Mauricio Moura",
      email: "mauricio@empresa.com",
      telefone: "(38) 99999-1111",
      perfil: "super_admin",
      status: "ativo",
      created_at: "2026-02-22",
      empresa: "AmAgenda",
      empresa_id: "1",
      memento: "Conectado",
      foto_perfil: "/public/assets/img/avatar-default.png",
    },
    {
      id: 2,
      nome: "Teste",
      email: "teste@hotmail.com",
      telefone: "(38) 99999-2222",
      perfil: "proprietario",
      status: "ativo",
      created_at: "2026-02-22",
      empresa: "Studio Bela Arte",
      empresa_id: "2",
      memento: "Desconectado",
      foto_perfil: null,
    },
    {
      id: 3,
      nome: "Luiz Eduardo",
      email: "luiz@gmail.com",
      telefone: "(38) 99999-3333",
      perfil: "proprietario",
      status: "ativo",
      created_at: "2026-02-23",
      empresa: "Studio Premium",
      empresa_id: "3",
      memento: "Conectado",
      foto_perfil: null,
    },
  ];

  // =========================
  // ESTADO
  // =========================
  let BASE_LISTA = [];
  let __CARREGADO__ = false;

  const FILTRO = {};
  const PAGINA_ATUAL = { usuarios: 1 };

  // =========================
  // Helpers
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
    if (st.includes("bloq")) return "bloqueado";
    if (st.includes("inativ")) return "inativo";
    if (st.includes("ativ")) return "ativo";
    return "ativo";
  }

  function normalizeStatusLabel(status) {
    const st = normalizeStatus(status);
    if (st === "bloqueado") return "Bloqueado";
    if (st === "inativo") return "Inativo";
    return "Ativo";
  }

  function normalizeMemento(valor) {
    const v = C.normalizar(valor ?? "").trim();

    if (!v) return "Não informado";
    if (["1", "true", "sim", "conectado", "ativo", "online"].includes(v)) return "Conectado";
    if (["0", "false", "nao", "não", "desconectado", "inativo", "offline"].includes(v)) return "Desconectado";

    return String(valor);
  }

  function normalizePerfil(valor) {
    const v = C.normalizar(valor ?? "").trim();
    if (v.includes("super")) return "super_admin";
    if (v.includes("propriet")) return "proprietario";
    return String(valor || "").trim();
  }

  function statusFiltroNorm(v) {
    const s = C.normalizar(v || "");
    if (!s) return "";
    if (s.includes("bloq")) return "bloqueado";
    if (s.includes("inativ")) return "inativo";
    if (s.includes("ativ")) return "ativo";
    return s;
  }

  function perfilFiltroNorm(v) {
    const s = C.normalizar(v || "");
    if (!s) return "";
    if (s.includes("super")) return "super_admin";
    if (s.includes("propriet")) return "proprietario";
    return s;
  }

  function statusBate(itemStatus, filtroStatus) {
    const f = statusFiltroNorm(filtroStatus);
    if (!f) return true;
    return statusFiltroNorm(itemStatus) === f;
  }

  function perfilBate(itemPerfil, filtroPerfil) {
    const f = perfilFiltroNorm(filtroPerfil);
    if (!f) return true;
    return perfilFiltroNorm(itemPerfil) === f;
  }

  function badgeStatus(status) {
    const label = normalizeStatusLabel(status);
    const cls =
      label === "Ativo" ? "st-confirmado" :
      label === "Inativo" ? "st-cancelado" :
      "st-pendente";

    return `<span class="agenda-status ${cls}">${C.escapeHtml(label)}</span>`;
  }

  function badgeMemento(valor) {
    const st = normalizeMemento(valor);
    const cls =
      st === "Conectado" ? "st-confirmado" :
      st === "Desconectado" ? "st-cancelado" :
      "st-pendente";

    return `<span class="agenda-status ${cls}">${C.escapeHtml(`Memento: ${st}`)}</span>`;
  }

  function iconAcoes() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 7.25a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Z"/>
      </svg>
    `;
  }

  function buildMenuAcoes(u) {
    const st = normalizeStatus(u.status);
    const ativo = st === "ativo";
    const id = u.id ?? "";

    return `
      <div class="agenda-menu" role="menu">

        <button class="agenda-menu-item" type="button" data-acao="${CFG.ACAO_EDITAR_USUARIO}">
          <i class="fa-regular fa-pen-to-square"></i> Editar
        </button>

        <button
          class="agenda-menu-item danger"
          type="button"
          data-acao="toggle-status"
          data-scope="tabela_usuario"
          data-id="${C.escapeHtml(id)}"
          data-status="${C.escapeHtml(st)}"
        >
          <i class="fa-regular ${ativo ? "fa-circle-xmark" : "fa-circle-check"}"></i>
          ${ativo ? "Inativar" : "Ativar"}
        </button>
      </div>
    `;
  }

  function avatarTemplate(u) {
    const nome = u.nome ?? "Usuário";
    const foto = String(u.foto_perfil || "").trim();

    if (foto) {
      return `
        <div class="agenda-hora agenda-hora--avatar" aria-hidden="true">
          <img
            src="${C.escapeHtml(foto)}"
            alt="Foto de ${C.escapeHtml(nome)}"
            class="agenda-avatar-img"
            loading="lazy"
            onerror="this.onerror=null;this.src='${C.escapeHtml(CFG.FOTO_FALLBACK)}';"
          >
        </div>
      `;
    }

    return `
      <div class="agenda-hora agenda-hora--iniciais" aria-hidden="true">
        ${C.escapeHtml(initials(nome))}
      </div>
    `;
  }

  function cardTemplate(u) {
    const id = u.id ?? "";
    const nome = u.nome ?? "Usuário";
    const email = u.email ?? "";
    const telefone = u.telefone ?? "";
    const perfil = normalizePerfil(u.perfil ?? "—");
    const status = normalizeStatus(u.status);
    const empresa = u.empresa ?? "—";
    const empresaId = u.empresa_id ?? "";
    const memento = u.memento ?? "Não informado";
    const fotoPerfil = u.foto_perfil ?? "";

    return `
      <article class="agenda-card"
        data-id="${C.escapeHtml(id)}"
        data-status="${C.escapeHtml(status)}"
        data-nome="${C.escapeHtml(nome)}"
        data-email="${C.escapeHtml(email)}"
        data-telefone="${C.escapeHtml(telefone)}"
        data-perfil="${C.escapeHtml(perfil)}"
        data-empresa="${C.escapeHtml(empresa)}"
        data-empresa_id="${C.escapeHtml(empresaId)}"
        data-memento="${C.escapeHtml(memento)}"
        data-foto_perfil="${C.escapeHtml(fotoPerfil)}"
        data-created_at="${C.escapeHtml(u.created_at || "")}">

        ${avatarTemplate(u)}

        <div class="agenda-info">
          <div class="agenda-nome">${C.escapeHtml(nome)}</div>

          <div class="agenda-servico-linha">
            <div class="agenda-servico">${C.escapeHtml(perfil)}</div>
            ${email ? `<div class="agenda-duracao">• ${C.escapeHtml(email)}</div>` : ""}
          </div>

          <div class="agenda-servico-linha">
            <div class="agenda-servico">Empresa: ${C.escapeHtml(empresa || "—")}</div>
          </div>

          <div class="agenda-linha-extra">
            ${badgeStatus(status)}
            ${badgeMemento(memento)}
          </div>
        </div>

        <div class="agenda-acoes" aria-haspopup="menu">
          <button class="agenda-btn-acoes" type="button"
            data-acao="toggle-menu" aria-expanded="false" title="Ações">
            ${iconAcoes()}
          </button>

          ${buildMenuAcoes({ ...u, status })}
        </div>
      </article>
    `;
  }

  // =========================
  // MODAL EDITAR
  // =========================
  function limparErrosModalEditar() {
    if (!formEditarUsuario) return;

    formEditarUsuario
      .querySelectorAll(".modal-campo.erro")
      .forEach((el) => el.classList.remove("erro"));

    formEditarUsuario
      .querySelectorAll(".msg-erro")
      .forEach((el) => {
        el.textContent = "";
        el.classList.remove("ativo");
      });
  }

  function abrirModalEditarUsuario() {
    const modal = document.getElementById(CFG.MODAL_EDITAR_USUARIO_ID);
    if (!modal) return;

    const conteudo = modal.querySelector(".modal-conteudo");
    if (conteudo) conteudo.scrollTop = 0;

    if (typeof window.abrirModal === "function") {
      window.abrirModal(CFG.MODAL_EDITAR_USUARIO_ID);
    } else {
      modal.classList.add("ativo");
      modal.setAttribute("aria-hidden", "false");
    }
  }

  function fecharModalEditarUsuario() {
    const modal = document.getElementById(CFG.MODAL_EDITAR_USUARIO_ID);
    if (!modal) return;

    if (typeof window.fecharModal === "function") {
      window.fecharModal(modal);
    } else {
      modal.classList.remove("ativo");
      modal.setAttribute("aria-hidden", "true");
    }
  }

  function setSelectValueSafe(selectEl, value, textFallback = "") {
    if (!selectEl) return;

    const val = String(value ?? "");
    const existe = Array.from(selectEl.options).some((opt) => String(opt.value) === val);

    if (existe) {
      selectEl.value = val;
      return;
    }

    if (val !== "") {
      const opt = document.createElement("option");
      opt.value = val;
      opt.textContent = textFallback || val;
      opt.dataset.dynamic = "1";
      selectEl.appendChild(opt);
      selectEl.value = val;
    } else {
      selectEl.value = "";
    }
  }

  function preencherModalEditarUsuario(card) {
    if (!card || !modalEditarUsuario) return;

    limparErrosModalEditar();

    if (formEditarUsuario) formEditarUsuario.reset();

    const ds = card.dataset || {};

    if (camposEdit.id) camposEdit.id.value = ds.id || "";
    if (camposEdit.nome) camposEdit.nome.value = ds.nome || "";
    if (camposEdit.email) camposEdit.email.value = ds.email || "";
    if (camposEdit.telefone) camposEdit.telefone.value = ds.telefone || "";

    if (camposEdit.perfil) {
      const perfilNorm = normalizePerfil(ds.perfil || "");
      const perfilLabel =
        perfilNorm === "super_admin" ? "Super Admin" :
        perfilNorm === "proprietario" ? "Proprietário" :
        perfilNorm || "Perfil";

      setSelectValueSafe(camposEdit.perfil, perfilNorm, perfilLabel);
    }

    if (camposEdit.empresa) {
      setSelectValueSafe(camposEdit.empresa, ds.empresa_id || "", ds.empresa || "Empresa");
    }

    if (camposEdit.status) {
      const stNorm = normalizeStatus(ds.status || "");
      const stLabel = normalizeStatusLabel(stNorm);
      setSelectValueSafe(camposEdit.status, stNorm, stLabel);
    }

    if (camposEdit.senha) camposEdit.senha.value = "";
    if (camposEdit.senha2) camposEdit.senha2.value = "";

    const conteudo = modalEditarUsuario.querySelector(".modal-conteudo");
    if (conteudo) conteudo.scrollTop = 0;

    window.setTimeout(() => {
      try { camposEdit.nome?.focus(); } catch (_) {}
    }, 60);
  }

  // =========================
  // FILTRO
  // =========================
  function inRange(dataISO, iniISO, fimISO) {
    if (!dataISO || !iniISO || !fimISO) return true;
    return dataISO >= iniISO && dataISO <= fimISO;
  }

  function aplicarFiltro(lista) {
    const f = FILTRO.usuarios || {};
    return (lista || []).filter((u) => {
      const data = String(u.created_at || "");
      const okPeriodo = (!f.inicio || !f.fim) ? true : inRange(data, f.inicio, f.fim);
      const okStatus = statusBate(u.status, f.status);
      const okPerfil = perfilBate(u.perfil, f.perfil);
      return okPeriodo && okStatus && okPerfil;
    });
  }

  function setLabelFiltro(iniISO, fimISO, statusVal, perfilVal) {
    if (!$label) return;

    const temPeriodo = !!iniISO && !!fimISO;
    const st = statusFiltroNorm(statusVal);
    const pf = perfilFiltroNorm(perfilVal);

    if (!temPeriodo && !st && !pf) {
      $label.textContent = "Filtro";
      return;
    }

    const br = (iso) => String(iso).split("-").reverse().join("/");
    const partes = [];
    if (temPeriodo) partes.push(`${br(iniISO)} - ${br(fimISO)}`);
    if (st) partes.push(st.charAt(0).toUpperCase() + st.slice(1));
    if (pf) {
      const mapPf = { super_admin: "Super Admin", proprietario: "Proprietário" };
      partes.push(mapPf[pf] || (pf.charAt(0).toUpperCase() + pf.slice(1)));
    }
    $label.textContent = partes.join(" • ");
  }

  // ==========================================================
  // BACKDROP
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

    if (isMobile()) aplicarModoMobilePopover($popFiltro);
    else posicionarPopoverDesktop($btnFiltro, $popFiltro);
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
  // PAGINAÇÃO
  // =========================
  function paginarLista(lista) {
    const total = lista.length;
    const porPag = CFG.itensPorPagina;
    const totalPaginas = Math.max(1, Math.ceil(total / porPag));

    const atual = Math.max(1, Math.min(PAGINA_ATUAL.usuarios || 1, totalPaginas));
    PAGINA_ATUAL.usuarios = atual;

    const ini = (atual - 1) * porPag;
    const fim = ini + porPag;

    return { pageItems: lista.slice(ini, fim), total, porPag, totalPaginas, paginaAtual: atual };
  }

  function renderPaginacao(info) {
    if (!pagDiv) return;

    if (info.total === 0 || info.totalPaginas <= 1) {
      pagDiv.innerHTML = "";
      return;
    }

    const { paginaAtual, totalPaginas } = info;
    pagDiv.innerHTML = "";

    if (paginaAtual > 1) {
      const btnAnterior = document.createElement("button");
      btnAnterior.type = "button";
      btnAnterior.textContent = "◀ Anterior";
      btnAnterior.classList.add("btn-pag");
      btnAnterior.addEventListener("click", () => {
        PAGINA_ATUAL.usuarios = Math.max(1, PAGINA_ATUAL.usuarios - 1);
        renderTudo();
      });
      pagDiv.appendChild(btnAnterior);
    }

    if (paginaAtual < totalPaginas) {
      const btnProximo = document.createElement("button");
      btnProximo.type = "button";
      btnProximo.textContent = "Próximo ▶";
      btnProximo.classList.add("btn-pag");
      btnProximo.addEventListener("click", () => {
        PAGINA_ATUAL.usuarios = Math.min(totalPaginas, PAGINA_ATUAL.usuarios + 1);
        renderTudo();
      });
      pagDiv.appendChild(btnProximo);
    }
  }

  // =========================
  // Pesquisa
  // =========================
  function aplicarPesquisaLista(lista) {
    const termo = inputPesquisa ? C.normalizar(inputPesquisa.value.trim()) : "";
    if (btnLimparPesquisa) btnLimparPesquisa.style.display = termo ? "inline-flex" : "none";
    if (!termo) return lista;

    return lista.filter((u) => {
      const blob = C.normalizar(`${u.id} ${u.nome} ${u.email} ${u.telefone} ${u.perfil} ${u.status} ${u.empresa} ${u.memento}`);
      return blob.includes(termo);
    });
  }

  // =========================
  // Render
  // =========================
  function renderTudo() {
    let lista = (BASE_LISTA || []).slice();

    lista.forEach((u) => {
      u.status = normalizeStatus(u.status);
      u.memento = normalizeMemento(u.memento);
      u.perfil = normalizePerfil(u.perfil);
    });

    lista.sort((a, b) => String(a.nome || "").localeCompare(String(b.nome || ""), "pt-BR"));
    lista = aplicarFiltro(lista);
    lista = aplicarPesquisaLista(lista);

    const info = paginarLista(lista);

    if (!info.total) {
      box.innerHTML = `
        <div class="agenda-vazio">
          <div class="agenda-vazio-icone">👤</div>
          <div class="agenda-vazio-titulo">${C.escapeHtml(CFG.EMPTY_MSG)}</div>
        </div>
      `;
      if (pagDiv) pagDiv.innerHTML = "";
      return;
    }

    box.innerHTML = info.pageItems.map(cardTemplate).join("");
    renderPaginacao(info);
  }

  // =========================
  // Menu flutuante
  // =========================
  const menuCtrl = C.createFloatingMenuController({ rootSelector: CFG.ROOT_SELECTOR_MENU });
  function fecharMenuAcoes() { try { menuCtrl.fechar(); } catch (_) {} }

  // =========================
  // Bind Filtro
  // =========================
  function bindFiltro() {
    if (!$btnFiltro || !$popFiltro || !$formFiltro || !$ini || !$fim || !$st || !$pf || !$label || !$limpar || !$fechar) return;

    $ini.removeAttribute("required");
    $fim.removeAttribute("required");

    const hasTodosStatus = Array.from($st.options).some(o => statusFiltroNorm(o.value) === "");
    if (!hasTodosStatus) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = "Todos";
      $st.insertBefore(opt, $st.firstChild);
    }

    const f = FILTRO.usuarios || {};
    const ini30 = f.inicio || menosDiasISO(30);
    const fimHoje = f.fim || hojeISO();
    const statusPadrao = typeof f.status !== "undefined" ? f.status : "ativo";

    FILTRO.usuarios = {
      ...f,
      inicio: ini30,
      fim: fimHoje,
      status: statusPadrao,
    };

    $ini.value = FILTRO.usuarios.inicio;
    $fim.value = FILTRO.usuarios.fim;
    $st.value = FILTRO.usuarios.status || "";
    if (typeof FILTRO.usuarios.perfil !== "undefined") $pf.value = FILTRO.usuarios.perfil || "";

    setLabelFiltro(
      FILTRO.usuarios.inicio,
      FILTRO.usuarios.fim,
      FILTRO.usuarios.status || "",
      FILTRO.usuarios.perfil || ""
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

      const ini = menosDiasISO(30);
      const fim = hojeISO();

      $ini.value = ini;
      $fim.value = fim;
      $st.value = "ativo";
      $pf.value = "";

      FILTRO.usuarios = { inicio: ini, fim: fim, status: "ativo", perfil: "" };
      PAGINA_ATUAL.usuarios = 1;

      setLabelFiltro(ini, fim, "ativo", "");
      fecharPopoverFiltro();

      __CARREGADO__ = false;
      BASE_LISTA = [];

      ui.info("Filtro limpo. Recarregando lista…", "Usuários");
      await carregar(true);
    });

    $formFiltro.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      if (($ini.value && !$fim.value) || (!$ini.value && $fim.value)) {
        ui.warning("Selecione início e fim (ou deixe os dois vazios).", "Filtro de período");
        return;
      }
      if ($ini.value && $fim.value && $ini.value > $fim.value) {
        ui.warning("A data de início não pode ser maior que a data de fim.", "Filtro de período");
        return;
      }

      const ini = $ini.value || menosDiasISO(30);
      const fim = $fim.value || hojeISO();

      FILTRO.usuarios = {
        inicio: ini,
        fim: fim,
        status: $st.value || "",
        perfil: $pf.value || "",
      };

      $ini.value = ini;
      $fim.value = fim;

      PAGINA_ATUAL.usuarios = 1;

      setLabelFiltro(ini, fim, $st.value || "", $pf.value || "");
      fecharPopoverFiltro();

      __CARREGADO__ = false;
      BASE_LISTA = [];

      ui.info("Aplicando filtro…", "Usuários");
      await carregar(true);
    });
  }

  // =========================
  // Eventos globais
  // =========================
  function bindEventosGlobais() {
    document.addEventListener("click", (ev) => {

      // ==========================================
      // ✅ FECHAR MODAL EDITAR USUÁRIO (SOMENTE NO X)
      // ==========================================
      const btnFecharModalUsuario = ev.target.closest(
        `#${CFG.MODAL_EDITAR_USUARIO_ID} .modal-fechar`
      );

      if (btnFecharModalUsuario) {
        ev.preventDefault();
        ev.stopPropagation();
        fecharModalEditarUsuario();
        return;
      }

      // ==========================================
      // CONTROLE NORMAL DA LISTA
      // ==========================================
      const btnToggle = ev.target.closest('button[data-acao="toggle-menu"]');
      const menuItem = ev.target.closest(".agenda-menu-item");

      if (btnToggle && !aba.contains(btnToggle)) return;
      if (menuItem && !aba.contains(menuItem)) return;

      const cliqueDentroPopover = ev.target.closest(".popover, .popover-simple, .popover-vaga-livre");
      const cliqueNoBtnFiltro = ev.target.closest(`#${FIDS.btn}`);
      const btnLimpar = ev.target.closest(`#${CFG.ABA_ID} .btn-limpar-pesquisa`);

      if (!cliqueDentroPopover && !cliqueNoBtnFiltro) fecharPopoverFiltro();

      if (btnLimpar && inputPesquisa) {
        inputPesquisa.value = "";
        inputPesquisa.focus();
        PAGINA_ATUAL.usuarios = 1;
        renderTudo();
        ui.info("Pesquisa limpa.", "Usuários");
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

        menuCtrl.fechar();

        if (acao === CFG.ACAO_EDITAR_USUARIO) {
          if (!card) {
            ui.warning("Não foi possível identificar o usuário selecionado.", "Usuários");
            return;
          }

          preencherModalEditarUsuario(card);
          abrirModalEditarUsuario();
          return;
        }

        console.log("[USUARIOS] ação:", acao, "id:", id);
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
      if (ev.key === "Escape") {
        fecharMenuAcoes();
        fecharPopoverFiltro();

        const modal = document.getElementById(CFG.MODAL_EDITAR_USUARIO_ID);

        if (modal && modal.classList.contains("ativo")) {
          fecharModalEditarUsuario();
        }
      }
    });
  }

  // =========================
  // Pesquisa bind
  // =========================
  function bindPesquisa() {
    if (inputPesquisa) {
      inputPesquisa.addEventListener(
        "input",
        C.debounce(() => {
          PAGINA_ATUAL.usuarios = 1;
          renderTudo();
        }, 80)
      );
    }

    if (btnLimparPesquisa && inputPesquisa) {
      btnLimparPesquisa.addEventListener("click", () => {
        inputPesquisa.value = "";
        inputPesquisa.focus();
        PAGINA_ATUAL.usuarios = 1;
        renderTudo();
        ui.info("Pesquisa limpa.", "Usuários");
      });
    }
  }

  // =========================
  // Load (API)
  // =========================
  async function obterDados() {
    if (CFG.MOCK) return MOCK_DATA.slice();

    const f = FILTRO.usuarios || {};
    const data_inicio = f.inicio || menosDiasISO(30);
    const data_fim = f.fim || hojeISO();

    const url = buildApiUrl({ data_inicio, data_fim });
    const json = await C.fetchJSON(url);

    if (!json?.ok) {
      throw new Error(json?.user_msg || json?.msg || "API retornou erro.");
    }

    const raw = Array.isArray(json?.data) ? json.data : [];
    return raw.map((u) => ({
      id: u.id_usuario ?? u.id ?? "",
      nome: u.nome ?? "",
      email: u.email ?? "",
      telefone: u.telefone ?? "",
      foto_perfil: u.foto_perfil ?? "",
      perfil: u.perfil ?? u.perfil_nome ?? "",
      status: u.status ?? "ativo",
      created_at: onlyDate(u.criado_em ?? u.created_at ?? ""),
      atualizado_em: onlyDate(u.atualizado_em ?? ""),
      ultimo_login_em: u.ultimo_login_em ?? "",
      empresa: u.empresa_nome ?? u.nome_empresa ?? u.empresa ?? "—",
      empresa_id: u.empresa_id ?? u.id_empresa ?? "",
      memento: u.memento_conectado ?? u.conectado_memento ?? u.memento_status ?? u.memento ?? "Não informado",
    }));
  }

  async function carregar(force = false) {
    if (__CARREGADO__ && !force) return;

    try {
      BASE_LISTA = await obterDados();
      __CARREGADO__ = true;
      renderTudo();
    } catch (e) {
      box.innerHTML = `
        <div class="painel-card" style="padding:14px">
          <strong>⚠️ Usuários</strong><br>
          <span style="color:var(--muted)">Falha ao carregar: ${C.escapeHtml(e?.message || "erro")}</span>
        </div>
      `;
      if (pagDiv) pagDiv.innerHTML = "";
      console.error("[ListaUsuariosSuper]", e);
      ui.danger(e?.message || "Falha ao carregar lista de usuários.", "Usuários");
    }
  }

  function abaVisivel() {
    const st = window.getComputedStyle(aba);
    if (st.display === "none" || st.visibility === "hidden") return false;
    const r = aba.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function init() {
    const ini30 = menosDiasISO(30);
    const fimHoje = hojeISO();

    FILTRO.usuarios = FILTRO.usuarios || {};

    if (!FILTRO.usuarios.inicio) FILTRO.usuarios.inicio = ini30;
    if (!FILTRO.usuarios.fim) FILTRO.usuarios.fim = fimHoje;
    if (typeof FILTRO.usuarios.status === "undefined") FILTRO.usuarios.status = "ativo";
    if (typeof FILTRO.usuarios.perfil === "undefined") FILTRO.usuarios.perfil = "";

    if ($ini) $ini.value = FILTRO.usuarios.inicio;
    if ($fim) $fim.value = FILTRO.usuarios.fim;
    if ($st) $st.value = FILTRO.usuarios.status || "";
    if ($pf) $pf.value = FILTRO.usuarios.perfil || "";

    setLabelFiltro(
      FILTRO.usuarios.inicio,
      FILTRO.usuarios.fim,
      FILTRO.usuarios.status || "",
      FILTRO.usuarios.perfil || ""
    );

    if ($popFiltro) {
      $popFiltro.setAttribute("aria-hidden", $popFiltro.hasAttribute("hidden") ? "true" : "false");
    }

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

  window.ListaUsuariosSuper = window.ListaUsuariosSuper || {};
  window.ListaUsuariosSuper.abrirModalEditarUsuario = abrirModalEditarUsuario;
  window.ListaUsuariosSuper.fecharModalEditarUsuario = fecharModalEditarUsuario;
  window.ListaUsuariosSuper.preencherModalEditarUsuario = preencherModalEditarUsuario;
  window.ListaUsuariosSuper.recarregar = () => carregar(true);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();