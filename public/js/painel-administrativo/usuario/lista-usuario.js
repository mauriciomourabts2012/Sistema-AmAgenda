/* ==========================================================
   lista-usuario.js — ABA USUÁRIOS (ListaCore) + FILTRO + PAGINAÇÃO
   ✅ 100% compatível com lista_usuario.php
   ✅ Busca REAL no backend
   ✅ Paginação REAL no backend
   ✅ Filtro por status REAL no backend
   ✅ Filtro por perfil REAL no backend
   ✅ Filtro por período REAL no backend
   ✅ Exclui Proprietário no backend
   ✅ Mostra foto_perfil vinda do PHP
   ✅ Se foto vier vazia/quebrada usa avatar padrão
   ✅ O próprio JS abre/fecha apenas:
      - modalVisualizarUsuario
      - modalEditarUsuario
   ✅ Toast no mesmo padrão do sistema
   ✅ NÃO interage com submit/salvar do modal editar
   ✅ Modal editar agora preenche perfil corretamente
   ✅ FILTRO ajustado no mesmo padrão do ListaClientes
========================================================== */
(() => {
  "use strict";

  if (window.__LISTA_USUARIO_JS_INIT__) {
    console.warn("[ListaUsuario] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__LISTA_USUARIO_JS_INIT__ = true;

  const C = window.ListaCore;
  if (!C) {
    console.warn("[ListaUsuario] ListaCore não carregado.");
    return;
  }

  const CFG = {
    ENDPOINT: "/public/api/api_central.php?path=painel/usuario/listar",
    itensPorPagina: 20,

    ABA_ID: "usuarios",
    BOX_ID: "listaUsuarios",
    PAG_ID: "paginacao_usuarios",
    INPUT_ID: "pesquisar-usuarios",

    BTN_FILTRO: "btnPeriodo_usuarios",
    LABEL_FILTRO: "labelPeriodo_usuarios",
    POPOVER: "popoverPeriodo_usuarios",
    FORM_FILTRO: "formPeriodo_usuarios",
    INICIO: "inicio_usuarios",
    FIM: "fim_usuarios",
    STATUS: "status_usuarios",
    PERFIL: "perfil_usuarios",
    BTN_LIMPAR_FILTRO: "limparFiltro_usuarios",
    BTN_FECHAR_FILTRO: "fecharPopover_usuarios",

    ROOT_SELECTOR: "#listaUsuarios",
    EMPTY_MSG: "Nenhum usuário encontrado.",

    MODAL_VISUALIZAR_ID: "modalVisualizarUsuario",
    MODAL_EDITAR_ID: "modalEditarUsuario",
    MODAL_PERMISSOES_ID: "modalPermissoesUsuario",

    FOTO_FALLBACK: "/public/imagens/avatar-default.png",
  };

  const TOAST_DEFAULT_TIMEOUT = 3500;
  const TOAST_LEAVE_TIME = 180;

  const aba = document.getElementById(CFG.ABA_ID);
  const box = document.getElementById(CFG.BOX_ID);
  const pagDiv = document.getElementById(CFG.PAG_ID);
  const inputPesquisa = document.getElementById(CFG.INPUT_ID);

  if (!aba || !box || !pagDiv) {
    console.warn("[ListaUsuario] DOM faltando:", { aba, box, pagDiv });
    return;
  }

  const btnLimparPesquisa = aba.querySelector(".btn-limpar-pesquisa");

  const btnFiltro = document.getElementById(CFG.BTN_FILTRO);
  const popover = document.getElementById(CFG.POPOVER);
  const formFiltro = document.getElementById(CFG.FORM_FILTRO);
  const inpInicio = document.getElementById(CFG.INICIO);
  const inpFim = document.getElementById(CFG.FIM);
  const selStatus = document.getElementById(CFG.STATUS);
  const selPerfil = document.getElementById(CFG.PERFIL);
  const labelFiltro = document.getElementById(CFG.LABEL_FILTRO);
  const btnLimparFiltro = document.getElementById(CFG.BTN_LIMPAR_FILTRO);
  const btnFecharFiltro = document.getElementById(CFG.BTN_FECHAR_FILTRO);

  const modalVisualizar = document.getElementById(CFG.MODAL_VISUALIZAR_ID);
  const modalEditar = document.getElementById(CFG.MODAL_EDITAR_ID);
  const modalPermissoes = document.getElementById(CFG.MODAL_PERMISSOES_ID);
  const permissoesUsuarioId = document.getElementById("permissoes_usuario_id");
  const permissoesUsuarioNome = document.getElementById("permissoes_usuario_nome");
  const permissoesUsuarioPerfil = document.getElementById("permissoes_usuario_perfil");
  const permissoesUsuarioAvatar = document.getElementById("permissoes_usuario_avatar");
  const btnSalvarPermissoes = document.getElementById("btnSalvarPermissoesUsuario");

  const vcAvatar = document.getElementById("vc_usr_avatar");
  const vcNome = document.getElementById("vc_usr_nome");
  const vcTelefone = document.getElementById("vc_usr_telefone");
  const vcChipPerfil = document.getElementById("vc_usr_chip_perfil");
  const vcChipStatus = document.getElementById("vc_usr_chip_status");
  const vcBtnWhats = document.getElementById("vc_usr_btn_whats");
  const vcBtnCopiarTel = document.getElementById("vc_usr_btn_copiar_tel");
  const vcEmail = document.getElementById("vc_usr_email");
  const vcPerfil = document.getElementById("vc_usr_perfil");
  const vcWrapEspecialidade = document.getElementById("vc_usr_wrap_especialidade");
  const vcEspecialidade = document.getElementById("vc_usr_especialidade");
  const vcDataCadastro = document.getElementById("vc_usr_data_cadastro");
  const vcObs = document.getElementById("vc_usr_obs");

  const u_e_id = document.getElementById("u_e_id");
  const u_e_nome = document.getElementById("u_e_nome");
  const u_e_perfil = document.getElementById("u_e_perfil");
  const campoEspecialidadeEditar = document.getElementById("campo_especialidade_editar");
  const u_e_especialidade = document.getElementById("u_e_especialidade");
  const u_e_email = document.getElementById("u_e_email");
  const u_e_tel = document.getElementById("u_e_tel");
  const u_e_senha = document.getElementById("u_e_senha");
  const u_e_senha2 = document.getElementById("u_e_senha2");
  const u_status = document.getElementById("u_e_status");

  // Estado temporário e específico deste componente. Ele nunca é usado como
  // autorização: cada permissão deverá ser validada pelo backend futuramente.
  let usuarioPermissaoSelecionado = null;

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

  function brData(iso) {
    const v = String(iso || "").trim();
    if (!v) return "";
    const base = v.includes(" ") ? v.split(" ")[0] : v;
    if (!base.includes("-")) return v;
    return base.split("-").reverse().join("/");
  }

  function formatTelefone(tel) {
    const v = String(tel || "").trim();
    return v || "—";
  }

  function normalizeStatus(status) {
    const st = C.normalizar(status || "").trim();
    if (st === "bloqueado" || st.includes("bloque")) return "Bloqueado";
    if (st === "inativo" || st.includes("inativ")) return "Inativo";
    return "Ativo";
  }

  function normalizePerfil(perfil) {
    const p = C.normalizar(perfil || "").trim();
    if (!p) return "Perfil";
    if (p === "super_admin" || p.includes("super")) return "Super Admin";
    if (p === "profissional" || p.includes("prof")) return "Profissional";
    if (p === "recepcao" || p.includes("recep")) return "Recepção";
    if (p === "proprietario" || p.includes("propriet")) return "Proprietário";
    return String(perfil || "").trim() || "Perfil";
  }

  function normalizePerfilKey(perfil) {
    const p = C.normalizar(perfil || "");
    if (p.includes("prof")) return "profissional";
    if (p.includes("recep")) return "recepcao";
    if (p.includes("super")) return "super_admin";
    if (p.includes("propriet")) return "proprietario";
    return "";
  }

  function badgeStatus(status) {
    const st = normalizeStatus(status);

    let cls = "st-confirmado";
    if (st === "Inativo") cls = "st-cancelado";
    if (st === "Bloqueado") cls = "st-pendente";

    return `<span class="agenda-status ${cls}">${C.escapeHtml(st)}</span>`;
  }

  function formatarDataCadastro(valor) {
    const texto = String(valor || "").trim();
    const match = texto.match(/^(\d{4})-(\d{2})-(\d{2})/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : "—";
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
    const ativo = st === "Ativo";

    return `
      <div class="agenda-menu" role="menu">
        <button class="agenda-menu-item" type="button" data-acao="visualizar">
          <i class="fa-regular fa-eye"></i> Visualizar
        </button>

        <button class="agenda-menu-item" type="button" data-acao="editar_painel_administrador">
          <i class="fa-regular fa-pen-to-square"></i> Editar
        </button>

        <button class="agenda-menu-item" type="button" data-acao="permissoes">
          <i class="fa-solid fa-shield-halved"></i> Permissões
        </button>

        <button
          class="agenda-menu-item danger"
          type="button"
          data-acao="toggle-status"
          data-scope="tabela_usuario"
          data-id="${u.id_usuario}"
          data-status="${u.status}">
          <i class="fa-regular ${ativo ? "fa-circle-xmark" : "fa-circle-check"}"></i>
          ${ativo ? "Inativar" : "Ativar"}
        </button>
      </div>
    `;
  }

  function normalizarCaminhoFoto(src) {
    let v = String(src || "").trim();
    if (!v) return "";

    if (/^(https?:)?\/\//i.test(v) || v.startsWith("data:") || v.startsWith("blob:")) {
      return v;
    }

    v = v.replace(/\\/g, "/");

    if (v.startsWith("/")) return v;
    if (v.startsWith("public/")) return "/" + v;
    if (v.startsWith("./")) return "/" + v.replace(/^\.\/+/, "");
    if (v.startsWith("../")) return "/" + v.replace(/^(\.\.\/)+/, "");

    return "/" + v.replace(/^\/+/, "");
  }

  function getFotoPerfilSrc(usuario) {
    const foto = normalizarCaminhoFoto(usuario?.foto_perfil || "");
    return foto || CFG.FOTO_FALLBACK;
  }

  function avatarImgHtml(usuario, nome, extraClass = "") {
    const src = C.escapeHtml(getFotoPerfilSrc(usuario));
    const fallback = C.escapeHtml(CFG.FOTO_FALLBACK);
    const alt = C.escapeHtml(`Foto de ${nome || "usuário"}`);
    const cls = ["agenda-avatar-img", extraClass].filter(Boolean).join(" ");

    return `
      <img
        src="${src}"
        alt="${alt}"
        class="${cls}"
        loading="lazy"
        decoding="async"
        referrerpolicy="no-referrer"
        onerror="if(this.dataset.fallbackApplied==='1'){return;}this.dataset.fallbackApplied='1';this.src='${fallback}';"
      >
    `;
  }

  function aplicarAvatarNoElemento(el, usuario, nome) {
    if (!el) return;
    el.innerHTML = avatarImgHtml(usuario, nome, "avatar-modal-img");
  }

  function cardTemplate(u) {
    const id = u.id_usuario ?? "";
    const nome = u.nome ?? "Usuário";
    const perfil = normalizePerfil(u.perfil_nome || u.perfil || "Perfil");
    const email = u.email ?? "";
    const status = normalizeStatus(u.status);
    const dataCadastro = formatarDataCadastro(u.criado_em);
    const telRaw = String(u.telefone || "");
    const temTel = onlyDigits(telRaw).length >= 10;

    return `
      <article class="agenda-card usuario-lista-card"
        data-id="${C.escapeHtml(id)}"
        data-status="${C.escapeHtml(status)}"
        data-perfil="${C.escapeHtml(perfil)}"
        data-created="${C.escapeHtml(u.criado_em || "")}">

        <div class="usuario-card-conteudo">
          <div class="usuario-card-cabecalho">
            <div class="agenda-hora agenda-hora--avatar">
              ${avatarImgHtml(u, nome)}
            </div>
            <div class="usuario-card-identidade">
              <div class="agenda-nome">${C.escapeHtml(nome)}</div>
              <div class="usuario-card-email">${C.escapeHtml(email || "E-mail não informado")}</div>
            </div>
          </div>

          <div class="usuario-card-dados">
            <span><strong>ID:</strong> ${C.escapeHtml(id)}</span>
            <span><strong>Perfil:</strong> ${C.escapeHtml(perfil)}</span>
            <span><strong>Status:</strong> ${C.escapeHtml(status)}</span>
            <span><strong>Data:</strong> ${C.escapeHtml(dataCadastro)}</span>
          </div>

          <div class="usuario-card-chips">
            <span class="usuario-chip usuario-chip-perfil">${C.escapeHtml(perfil)}</span>
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

          ${buildMenuAcoes(u)}
        </div>
      </article>
    `;
  }

  let BASE_LISTA = [];
  let PAGINA_ATUAL = 1;
  let USUARIO_ATUAL = null;
  let TOTAL_PAGINAS = 1;
  let TOTAL_REGISTROS = 0;
  let __CARREGANDO__ = false;
  let __CARREGADO__ = false;

  const FILTRO = {
    busca: "",
    inicio: "",
    fim: "",
    status: "ativo",
    perfil: "",
  };

  function abrirModalLocal(modal) {
    if (!modal) return;
    modal.classList.add("ativo", "aberto", "show");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open", "body-modal-open", "is-modal-open", "modal-aberto");
  }

  function fecharModalLocal(modal) {
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

  function fecharModaisUsuario() {
    fecharModalLocal(modalVisualizar);
    fecharModalLocal(modalEditar);
    fecharModalPermissoes();
  }

  function getModalPai(element) {
    return element.closest(`#${CFG.MODAL_VISUALIZAR_ID}, #${CFG.MODAL_EDITAR_ID}, #${CFG.MODAL_PERMISSOES_ID}`);
  }

  function getUsuarioById(id) {
    const chave = String(id || "").trim();
    return (BASE_LISTA || []).find((u) => String(u.id_usuario ?? "") === chave) || null;
  }

  function limparEstadoPermissoes() {
    usuarioPermissaoSelecionado = null;
    if (permissoesUsuarioId) permissoesUsuarioId.value = "";
    if (permissoesUsuarioNome) permissoesUsuarioNome.textContent = "—";
    if (permissoesUsuarioPerfil) permissoesUsuarioPerfil.textContent = "—";
    if (permissoesUsuarioAvatar) permissoesUsuarioAvatar.textContent = "U";
    modalPermissoes?.querySelectorAll("select[data-permissao]").forEach((select) => { select.value = "padrao"; });
    modalPermissoes?.querySelectorAll("details[data-grupo-permissao]").forEach((grupo, indice) => { grupo.open = indice === 0; });
  }

  function fecharModalPermissoes() {
    fecharModalLocal(modalPermissoes);
    limparEstadoPermissoes();
  }

  function abrirModalPermissoesUsuario(id) {
    const usuario = getUsuarioById(id);
    if (!usuario || !modalPermissoes) {
      toastMsg("warning", "Não foi possível identificar o usuário selecionado.", "Atenção");
      return;
    }

    limparEstadoPermissoes();
    usuarioPermissaoSelecionado = {
      id_usuario: Number(usuario.id_usuario) || 0,
      nome: String(usuario.nome || "Usuário"),
      perfil: normalizePerfil(usuario.perfil_nome || usuario.perfil || "Perfil")
    };
    if (permissoesUsuarioId) permissoesUsuarioId.value = String(usuarioPermissaoSelecionado.id_usuario);
    if (permissoesUsuarioNome) permissoesUsuarioNome.textContent = usuarioPermissaoSelecionado.nome;
    if (permissoesUsuarioPerfil) permissoesUsuarioPerfil.textContent = usuarioPermissaoSelecionado.perfil;
    if (permissoesUsuarioAvatar) permissoesUsuarioAvatar.innerHTML = avatarImgHtml(usuario, usuarioPermissaoSelecionado.nome);
    abrirModalLocal(modalPermissoes);
  }

  function toggleCampoEspecialidadeVisualizar(usuario) {
    if (!vcWrapEspecialidade || !vcEspecialidade) return;

    const perfil = normalizePerfil(usuario?.perfil_nome || usuario?.perfil || "");
    const ehProfissional = C.normalizar(perfil).includes("prof");

    vcWrapEspecialidade.style.display = ehProfissional ? "" : "none";
    vcEspecialidade.textContent = ehProfissional
      ? (usuario?.especialidade || "—")
      : "—";
  }

  function toggleCampoEspecialidadeEditar(perfilValue) {
    if (!campoEspecialidadeEditar) return;

    const p = C.normalizar(perfilValue || "");
    const ehProfissional = p === "profissional" || p.includes("prof");

    campoEspecialidadeEditar.hidden = !ehProfissional;
    campoEspecialidadeEditar.style.display = ehProfissional ? "" : "none";

    if (!ehProfissional && u_e_especialidade) {
      u_e_especialidade.value = "";
    }
  }

  function selecionarOptionPerfil(select, usuario) {
    if (!select) return false;

    const perfilId =
      String(
        usuario?.id_perfil ??
        usuario?.perfil_id ??
        usuario?.idPerfil ??
        usuario?.perfilId ??
        ""
      ).trim();

    const perfilNome = String(
      usuario?.perfil_nome ??
      usuario?.perfil ??
      ""
    ).trim();

    const perfilKey = normalizePerfilKey(perfilNome);

    if (perfilId && select.querySelector(`option[value="${CSS.escape(perfilId)}"]`)) {
      select.value = perfilId;
      return true;
    }

    if (perfilKey && select.querySelector(`option[value="${CSS.escape(perfilKey)}"]`)) {
      select.value = perfilKey;
      return true;
    }

    const opcoes = Array.from(select.options || []);
    const alvoNormalizado = C.normalizar(perfilNome);

    const encontrada = opcoes.find((opt) => {
      const txt = C.normalizar(opt.textContent || "");
      if (!txt) return false;

      if (perfilKey === "profissional" && txt.includes("prof")) return true;
      if (perfilKey === "recepcao" && txt.includes("recep")) return true;
      if (perfilKey === "super_admin" && txt.includes("super")) return true;
      if (perfilKey === "proprietario" && txt.includes("propriet")) return true;

      return txt === alvoNormalizado;
    });

    if (encontrada) {
      select.value = encontrada.value;
      return true;
    }

    select.value = "";
    return false;
  }

  function preencherModalVisualizar(usuario) {
    if (!usuario || !modalVisualizar) return;

    const nome = usuario.nome || "Usuário";
    const telefone = usuario.telefone || "";
    const perfil = normalizePerfil(usuario.perfil_nome || usuario.perfil || "");
    const status = normalizeStatus(usuario.status || "");
    const email = usuario.email || "—";
    const dataCadastro = usuario.criado_em || "";
    const obs = usuario.perfil_descricao || "—";

    aplicarAvatarNoElemento(vcAvatar, usuario, nome);
    if (vcNome) vcNome.textContent = nome;
    if (vcTelefone) vcTelefone.textContent = `Telefone: ${formatTelefone(telefone)}`;
    if (vcChipPerfil) vcChipPerfil.textContent = perfil;

    if (vcChipStatus) {
      vcChipStatus.textContent = status;
      vcChipStatus.className = "vc-chip";

      if (status === "Ativo") vcChipStatus.classList.add("st-confirmado");
      else if (status === "Inativo") vcChipStatus.classList.add("st-cancelado");
      else vcChipStatus.classList.add("st-pendente");
    }

    if (vcEmail) vcEmail.textContent = email;
    if (vcPerfil) vcPerfil.textContent = perfil;
    if (vcDataCadastro) vcDataCadastro.textContent = brData(dataCadastro) || "—";
    if (vcObs) vcObs.textContent = obs || "—";

    toggleCampoEspecialidadeVisualizar(usuario);

    if (vcBtnWhats) {
      const temTel = onlyDigits(telefone).length >= 10;
      vcBtnWhats.disabled = !temTel;
      vcBtnWhats.setAttribute("aria-disabled", temTel ? "false" : "true");
      vcBtnWhats.dataset.telefone = telefone || "";
      vcBtnWhats.dataset.nome = nome || "";
      vcBtnWhats.title = temTel ? "WhatsApp" : "Sem telefone cadastrado";
    }

    if (vcBtnCopiarTel) {
      vcBtnCopiarTel.dataset.telefone = telefone || "";
    }
  }

  function preencherModalEditar(usuario) {
    if (!usuario || !modalEditar) return;

    if (u_e_id) u_e_id.value = usuario.id_usuario ?? "";
    if (u_e_nome) u_e_nome.value = usuario.nome || "";
    if (u_e_especialidade) u_e_especialidade.value = usuario.especialidade || "";
    if (u_e_email) u_e_email.value = usuario.email || "";
    if (u_e_tel) u_e_tel.value = usuario.telefone || "";
    if (u_e_senha) u_e_senha.value = "";
    if (u_e_senha2) u_e_senha2.value = "";

    if (u_status) {
      const st = C.normalizar(usuario.status || "");
      if (st === "bloqueado") u_status.value = "bloqueado";
      else if (st === "inativo") u_status.value = "inativo";
      else u_status.value = "ativo";
    }

    if (u_e_perfil) {
      selecionarOptionPerfil(u_e_perfil, usuario);
    }

    const perfilSelecionadoTexto =
      u_e_perfil?.selectedOptions?.[0]?.textContent ||
      usuario?.perfil_nome ||
      usuario?.perfil ||
      "";

    toggleCampoEspecialidadeEditar(normalizePerfilKey(perfilSelecionadoTexto));
  }

  function abrirModalVisualizarUsuario(id) {
    const usuario = getUsuarioById(id);
    if (!usuario) {
      toastMsg("warning", "Usuário não encontrado.", "Atenção");
      return;
    }

    USUARIO_ATUAL = usuario;
    preencherModalVisualizar(usuario);
    abrirModalLocal(modalVisualizar);
  }

  function preencherModalEditarQuandoSelectEstiverPronto(usuario, tentativa = 0) {
    if (!usuario) return;

    const possuiOptions = !!(u_e_perfil && u_e_perfil.options && u_e_perfil.options.length > 1);

    preencherModalEditar(usuario);

    if (!possuiOptions && tentativa < 12) {
      setTimeout(() => {
        preencherModalEditarQuandoSelectEstiverPronto(usuario, tentativa + 1);
      }, 150);
    }
  }

  function abrirModalEditarUsuario(id) {
    const usuario = getUsuarioById(id);
    if (!usuario) {
      toastMsg("warning", "Usuário não encontrado.", "Atenção");
      return;
    }

    USUARIO_ATUAL = usuario;

    if (window.SelectListaUniversal?.carregar) {
      Promise.resolve(
        window.SelectListaUniversal.carregar("perfis_sem_proprietario", "u_e_perfil", { force: false })
      )
        .catch(() => {})
        .finally(() => {
          preencherModalEditarQuandoSelectEstiverPronto(usuario);
          abrirModalLocal(modalEditar);
        });
      return;
    }

    preencherModalEditarQuandoSelectEstiverPronto(usuario);
    abrirModalLocal(modalEditar);
  }

  async function copiarTexto(texto) {
    const valor = String(texto || "").trim();
    if (!valor) {
      toastMsg("warning", "Telefone não informado.", "Atenção");
      return;
    }

    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(valor);
        toastMsg("success", "Telefone copiado.");
        return;
      }

      const ta = document.createElement("textarea");
      ta.value = valor;
      ta.style.position = "fixed";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      document.execCommand("copy");
      ta.remove();

      toastMsg("success", "Telefone copiado.");
    } catch (_) {
      toastMsg("warning", "Não foi possível copiar o telefone.", "Atenção");
    }
  }

  function mapPerfilLabel(v) {
    const p = C.normalizar(v || "");
    if (p.includes("super")) return "Super Admin";
    if (p.includes("prof")) return "Profissional";
    if (p.includes("recep")) return "Recepção";
    if (p.includes("propriet")) return "Proprietário";
    return "";
  }

  function setLabelFiltro(iniISO, fimISO, statusVal, perfilVal) {
    if (!labelFiltro) return;

    const st = C.normalizar(statusVal || "");
    const pf = (perfilVal || "").trim();
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

    if (pf) {
      partes.push(mapPerfilLabel(pf) || "Perfil");
    }

    labelFiltro.textContent = partes.length ? partes.join(" • ") : "Filtro";
  }

  function renderPaginacao() {
    if (!pagDiv) return;

    if (TOTAL_REGISTROS === 0 || TOTAL_PAGINAS <= 1) {
      pagDiv.innerHTML = "";
      return;
    }

    pagDiv.innerHTML = "";

    if (PAGINA_ATUAL > 1) {
      const btnAnterior = document.createElement("button");
      btnAnterior.type = "button";
      btnAnterior.textContent = "◀ Anterior";
      btnAnterior.classList.add("btn-pag");
      btnAnterior.addEventListener("click", () => {
        PAGINA_ATUAL = Math.max(1, PAGINA_ATUAL - 1);
        carregar();
      });
      pagDiv.appendChild(btnAnterior);
    }

    if (PAGINA_ATUAL < TOTAL_PAGINAS) {
      const btnProximo = document.createElement("button");
      btnProximo.type = "button";
      btnProximo.textContent = "Próximo ▶";
      btnProximo.classList.add("btn-pag");
      btnProximo.addEventListener("click", () => {
        PAGINA_ATUAL = Math.min(TOTAL_PAGINAS, PAGINA_ATUAL + 1);
        carregar();
      });
      pagDiv.appendChild(btnProximo);
    }
  }

  function renderTudo() {
    const lista = (BASE_LISTA || []).slice();

    if (!lista.length) {
      box.innerHTML = `
        <div class="agenda-vazio">
          <div class="agenda-vazio-icone">👤</div>
          <div class="agenda-vazio-titulo">${C.escapeHtml(CFG.EMPTY_MSG)}</div>
        </div>
      `;
      pagDiv.innerHTML = "";
      return;
    }

    box.innerHTML = lista.map(cardTemplate).join("");
    renderPaginacao();
  }

  function resetPagina() {
    PAGINA_ATUAL = 1;
  }

  const POP_MARGIN = 12;
  const MQ_MOBILE = window.matchMedia("(max-width: 680px)");
  const Z_FRONT = 10050;

  function isMobile() {
    return !!MQ_MOBILE?.matches;
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
    popover.style.left = "";
    popover.style.top = "";
    popover.style.right = "";
    popover.style.bottom = "";
    popover.style.zIndex = "";
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
    popover.style.right = "";
    popover.style.bottom = "";

    const b = btnFiltro.getBoundingClientRect();

    popover.style.left = "-9999px";
    popover.style.top = "-9999px";

    const p = popover.getBoundingClientRect();

    let left = (b.right - p.width);
    let top = (b.bottom + 8);

    left = Math.max(POP_MARGIN, Math.min(left, window.innerWidth - p.width - POP_MARGIN));
    top = Math.max(POP_MARGIN, Math.min(top, window.innerHeight - p.height - POP_MARGIN));

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

  if (MQ_MOBILE?.addEventListener) {
    MQ_MOBILE.addEventListener("change", () => {
      if (!popover || popover.hasAttribute("hidden")) return;
      posicionarPopoverEsquerda();
    });
  } else if (MQ_MOBILE?.addListener) {
    MQ_MOBILE.addListener(() => {
      if (!popover || popover.hasAttribute("hidden")) return;
      posicionarPopoverEsquerda();
    });
  }

  function bindFiltro() {
    if (!btnFiltro || !popover || !formFiltro || !inpInicio || !inpFim || !selStatus || !labelFiltro || !selPerfil) return;

    inpInicio.value = FILTRO.inicio || "";
    inpFim.value = FILTRO.fim || "";
    selStatus.value = FILTRO.status || "ativo";
    selPerfil.value = FILTRO.perfil || "";

    setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status, FILTRO.perfil);

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
        selPerfil.value = "";

        FILTRO.inicio = "";
        FILTRO.fim = "";
        FILTRO.status = "ativo";
        FILTRO.perfil = "";

        resetPagina();
        setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status, FILTRO.perfil);
        fecharPopover();
        carregar();
      });
    }

    formFiltro.addEventListener("submit", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      const ini = (inpInicio.value || "").trim();
      const fim = (inpFim.value || "").trim();
      const status = (selStatus.value || "").trim();
      const perfil = (selPerfil.value || "").trim();

      if (ini && fim && ini > fim) {
        toastMsg("warning", "A data inicial não pode ser maior que a data final.", "Atenção");
        return;
      }

      FILTRO.inicio = ini;
      FILTRO.fim = fim;
      FILTRO.status = status || "todos";
      FILTRO.perfil = perfil;

      resetPagina();
      setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status, FILTRO.perfil);
      fecharPopover();
      carregar();
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
  }

  function bindPesquisa() {
    if (btnLimparPesquisa) {
      btnLimparPesquisa.style.display = inputPesquisa?.value?.trim() ? "inline-flex" : "none";
    }

    if (inputPesquisa) {
      inputPesquisa.addEventListener(
        "input",
        C.debounce(() => {
          FILTRO.busca = inputPesquisa.value.trim();

          if (btnLimparPesquisa) {
            btnLimparPesquisa.style.display = FILTRO.busca ? "inline-flex" : "none";
          }

          resetPagina();
          carregar();
        }, 250)
      );
    }

    if (btnLimparPesquisa && inputPesquisa) {
      btnLimparPesquisa.addEventListener("click", () => {
        inputPesquisa.value = "";
        inputPesquisa.focus();
        btnLimparPesquisa.style.display = "none";
        FILTRO.busca = "";
        resetPagina();
        carregar();
      });
    }
  }

  const menuCtrl = C.createFloatingMenuController({ rootSelector: CFG.ROOT_SELECTOR });

  function montarMensagemWhatsapp({ nome }) {
    return `Olá ${nome || ""}! Tudo bem?\nSou da equipe do estúdio.\n\nPrecisa de ajuda com seu acesso no sistema?`;
  }

  function abrirWhatsapp({ telefone, nome }) {
    let tel = onlyDigits(telefone);
    if (!tel || tel.length < 10) {
      toastMsg("warning", "Telefone inválido ou não informado para este usuário.", "Atenção");
      return;
    }
    if (!tel.startsWith("55")) tel = "55" + tel;

    const msg = encodeURIComponent(montarMensagemWhatsapp({ nome }));
    window.open(`https://wa.me/${tel}?text=${msg}`, "_blank");
  }

  function fecharMenuAcoes() {
    try { menuCtrl.fechar(); } catch (_) {}
  }

  function bindEventosGlobais() {
    document.addEventListener("click", (ev) => {
      const btnFecharModal = ev.target.closest("[data-fechar-modal]");
      if (btnFecharModal) {
        const modalPai = getModalPai(btnFecharModal);
        if (modalPai) {
          if (modalPai === modalPermissoes) fecharModalPermissoes();
          else fecharModalLocal(modalPai);
          return;
        }
      }

      if (btnSalvarPermissoes && (ev.target === btnSalvarPermissoes || ev.target.closest("#btnSalvarPermissoesUsuario"))) {
        ev.preventDefault();
        if (!usuarioPermissaoSelecionado) {
          toastMsg("warning", "Selecione um usuário antes de configurar permissões.", "Atenção");
          return;
        }
        toastMsg("warning", "A interface está preparada. A persistência das permissões será implementada na próxima etapa.", "Informação");
        return;
      }

      if (vcBtnWhats && (ev.target === vcBtnWhats || ev.target.closest("#vc_usr_btn_whats"))) {
        ev.stopPropagation();
        if (vcBtnWhats.disabled || vcBtnWhats.getAttribute("aria-disabled") === "true") return;
        abrirWhatsapp({
          telefone: vcBtnWhats.dataset.telefone,
          nome: vcBtnWhats.dataset.nome
        });
        return;
      }

      if (vcBtnCopiarTel && (ev.target === vcBtnCopiarTel || ev.target.closest("#vc_usr_btn_copiar_tel"))) {
        ev.stopPropagation();
        copiarTexto(vcBtnCopiarTel.dataset.telefone || "");
        return;
      }

      const dentroDaLista = ev.target.closest(CFG.ROOT_SELECTOR);
      const dentroDoMenuFlutuante = ev.target.closest(".agenda-menu.menu-flutuante");

      const btnWhats = ev.target.closest('button[data-acao="whatsapp"]');
      const btnToggle = ev.target.closest('button[data-acao="toggle-menu"]');
      const menuItem = ev.target.closest(".agenda-menu-item");

      if (!dentroDaLista && !dentroDoMenuFlutuante) {
        fecharMenuAcoes();
      }

      if (btnWhats) {
        const cardDono = btnWhats.closest(".agenda-card");
        if (!cardDono || !cardDono.closest(CFG.ROOT_SELECTOR)) return;

        ev.stopPropagation();
        if (btnWhats.disabled || btnWhats.getAttribute("aria-disabled") === "true") return;
        abrirWhatsapp({ telefone: btnWhats.dataset.telefone, nome: btnWhats.dataset.nome });
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

        const id = cardDono?.dataset?.id || "";
        const acao = menuItem.dataset.acao || "";

        menuCtrl.fechar();

        if (acao === "visualizar") {
          abrirModalVisualizarUsuario(id);
          return;
        }

        if (acao === "editar_painel_administrador") {
          abrirModalEditarUsuario(id);
          return;
        }

        if (acao === "permissoes") {
          abrirModalPermissoesUsuario(id);
          return;
        }

        if (acao === "toggle-status") {
          toastMsg("warning", "Ação de alterar status ainda não foi integrada.", "Atenção");
          return;
        }
      }
    });

    document.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape") {
        fecharMenuAcoes();
        fecharModaisUsuario();
        fecharPopover();
      }
    });
  }

  function bindModalEditarSomenteUI() {
    if (u_e_perfil) {
      u_e_perfil.addEventListener("change", () => {
        const textoSelecionado =
          u_e_perfil.selectedOptions?.[0]?.textContent || u_e_perfil.value || "";
        toggleCampoEspecialidadeEditar(textoSelecionado);
      });
    }
  }

  async function obterDados(signal) {
    const url = new URL(CFG.ENDPOINT, window.location.origin);

    if (FILTRO.busca) url.searchParams.set("q", FILTRO.busca);
    url.searchParams.set("status", FILTRO.status || "todos");
    if (FILTRO.perfil) url.searchParams.set("perfil", FILTRO.perfil);
    if (FILTRO.inicio) url.searchParams.set("inicio", FILTRO.inicio);
    if (FILTRO.fim) url.searchParams.set("fim", FILTRO.fim);

    url.searchParams.set("pagina", String(PAGINA_ATUAL));
    url.searchParams.set("limite", String(CFG.itensPorPagina));
    url.searchParams.set("ordem", FILTRO.ordem || "nome_asc");

    const resp = await fetch(url.toString(), {
      method: "GET",
      credentials: "same-origin",
      signal,
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest"
      }
    });

    let json = null;
    try {
      json = await resp.json();
    } catch (_) {
      throw new Error("Resposta inválida do servidor.");
    }

    if (!resp.ok || !json?.ok) {
      throw new Error(json?.user_msg || "Falha ao carregar usuários.");
    }

    const data = json?.data || {};
    const pag = data?.paginacao || {};

    TOTAL_PAGINAS = Number(pag.total_paginas || 1);
    TOTAL_REGISTROS = Number(pag.total_registros || 0);
    PAGINA_ATUAL = Number(pag.pagina_atual || 1);

    return Array.isArray(data?.items) ? data.items : [];
  }

  let CONTROLADOR_REQUISICAO = null;
  let REQUISICAO_ATUAL = 0;

  async function carregar() {

    __CARREGANDO__ = true;
    const idRequisicao = ++REQUISICAO_ATUAL;
    CONTROLADOR_REQUISICAO?.abort();
    CONTROLADOR_REQUISICAO = new AbortController();

    try {
      const dados = await obterDados(CONTROLADOR_REQUISICAO.signal);
      if (idRequisicao !== REQUISICAO_ATUAL) return;
      BASE_LISTA = dados;
      __CARREGADO__ = true;

      setLabelFiltro(FILTRO.inicio, FILTRO.fim, FILTRO.status, FILTRO.perfil);
      renderTudo();
    } catch (e) {
      if (e?.name === "AbortError") return;
      BASE_LISTA = [];
      TOTAL_PAGINAS = 1;
      TOTAL_REGISTROS = 0;

      box.innerHTML = `
        <div class="painel-card" style="padding:14px">
          <strong>⚠️ Usuários</strong><br>
          <span style="color:var(--muted)">Falha ao carregar: ${C.escapeHtml(e?.message || "erro")}</span>
        </div>
      `;
      pagDiv.innerHTML = "";
      console.error("[ListaUsuario]", e);
      toastMsg("danger", e?.message || "Falha ao carregar usuários.", "Erro");
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
    const limite = document.getElementById("limite_usuarios");
    const ordem = document.getElementById("ordem_usuarios");
    limite?.addEventListener("change", () => {
      CFG.itensPorPagina = [20, 50, 100].includes(Number(limite.value)) ? Number(limite.value) : 20;
      resetPagina(); carregar();
    });
    ordem?.addEventListener("change", () => {
      FILTRO.ordem = ordem.value || "nome_asc";
      resetPagina(); carregar();
    });
  }

  function init() {
    bindPreferenciasLista();
    if (popover && !popover.hasAttribute("hidden")) popover.setAttribute("hidden", "");
    if (btnFiltro) btnFiltro.setAttribute("aria-expanded", "false");

    if (modalVisualizar) modalVisualizar.setAttribute("aria-hidden", "true");
    if (modalEditar) modalEditar.setAttribute("aria-hidden", "true");

    if (selStatus && !selStatus.value) {
      selStatus.value = "ativo";
    }

    bindPesquisa();
    bindFiltro();
    bindEventosGlobais();
    bindModalEditarSomenteUI();

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
