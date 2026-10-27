/* ==========================================================
   editar_usuario.js — SuperAdmin | Editar Usuário
   ✅ Modal: #modalEditarUsuario
   ✅ Form:  #formEditarUsuario
   ✅ API:   POST /public/api/api_central.php?path=superadmin/usuario/editar
   ✅ Compatível com edição multiempresa:
      - envia dados globais e vínculos existentes em uma única transação
      - permite adicionar um vínculo opcional ao salvar
      - envia senha/senha2 SOMENTE se preenchidas
      - NÃO envia tipo_usuario
   ✅ Toast: .ui-toast-stack + .ui-alert
   ✅ Campo erro: .modal-campo.erro + .msg-erro.ativo
   ✅ Envia via FormData / $_POST
   ✅ Após sucesso:
      - fecha modal
      - dispara evento usuario:editado
      - mostra mensagem de sucesso
      - só depois atualiza a lista/página
========================================================== */
(() => {
  "use strict";

  if (window.__EDITAR_USUARIO_JS_INIT__) {
    console.warn("[EditarUsuario] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__EDITAR_USUARIO_JS_INIT__ = true;

  const API_BASE = "/public/api/api_central.php";
  const API_PATH = "superadmin/usuario/editar";
  const API_URL = `${API_BASE}?path=${encodeURIComponent(API_PATH)}`;

  const TOAST_DEFAULT_TIMEOUT = 3500;
  const TOAST_LEAVE_TIME = 180;

  // ==========================================================
  // DOM
  // ==========================================================
  const modal = document.getElementById("modalEditarUsuario");
  const form = document.getElementById("formEditarUsuario");
  const btnSalvar = document.getElementById("btnAtualizarUsuario");

  if (!modal || !form || !btnSalvar) {
    console.warn("[EditarUsuario] Elementos do modal não encontrados.");
    return;
  }

  const elId = document.getElementById("edit_u_id");
  const elNome = document.getElementById("edit_u_nome");
  const elEmail = document.getElementById("edit_u_email");
  const elSenha = document.getElementById("edit_u_senha");
  const elSenha2 = document.getElementById("edit_u_senha2");
  const elTelefone = document.getElementById("edit_u_tel");
  const elVinculosLista = document.getElementById("edit_u_vinculos_lista");
  const elVinculosTotal = document.getElementById("edit_u_vinculos_total");
  const elNovaEmpresa = document.getElementById("edit_u_nova_empresa");

  let vinculosAtuais = [];

  // ==========================================================
  // Indicador de segurança da senha (apenas apoio visual; o backend valida).
  // Instância única; senha opcional: oculto enquanto os dois campos estão vazios.
  // ==========================================================
  let indicadorSenha = null;

  function iniciarIndicadorSenha() {
    if (indicadorSenha || !elSenha || !elSenha2) return;
    if (!window.IndicadorSenha || typeof window.IndicadorSenha.anexar !== "function") return;

    indicadorSenha = window.IndicadorSenha.anexar({
      campoNova: elSenha,
      campoConfirmacao: elSenha2,
      opcional: true,
      dadosPessoais: () => ({
        nome: elNome?.value || "",
        email: elEmail?.value || "",
        telefone: elTelefone?.value || "",
      }),
    });

    const raiz = elSenha.closest(".modal-campo")?.nextElementSibling;
    if (!raiz || !raiz.classList.contains("ind-senha")) return;

    // Layout em duas colunas: o indicador fica abaixo do par Nova senha / Confirmar nova senha.
    const campoConfirmacao = elSenha2.closest(".modal-campo");
    if (campoConfirmacao) campoConfirmacao.insertAdjacentElement("afterend", raiz);
    raiz.style.gridColumn = "1 / -1";

    const aplicarVisibilidade = () => {
      raiz.style.display = raiz.getAttribute("data-vazio") === "1" ? "none" : "";
    };
    new MutationObserver(aplicarVisibilidade).observe(raiz, {
      attributes: true,
      attributeFilter: ["data-vazio"],
    });
    aplicarVisibilidade();
  }

  function reiniciarIndicadorSenha() {
    if (indicadorSenha) indicadorSenha.limpar();
  }

  iniciarIndicadorSenha();

  // ==========================================================
  // Toast Universal
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

  function toastConfirm({
    title = "Confirmação",
    message = "Deseja continuar?",
    type = "confirm",
    confirmText = "Confirmar",
    cancelText = "Cancelar",
  }) {
    return new Promise((resolve) => {
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
          <button type="button" class="ui-alert__btn js-cancel">${cancelText}</button>
          <button type="button" class="ui-alert__btn ui-alert__btn--primary js-ok">${confirmText}</button>
        </div>
      `;

      const $title = wrap.querySelector(".ui-alert__title");
      const $msg = wrap.querySelector(".ui-alert__msg");
      const $ok = wrap.querySelector(".js-ok");
      const $cancel = wrap.querySelector(".js-cancel");
      const $icon = wrap.querySelector(".ui-alert__icon");

      $title.textContent = title;
      $msg.textContent = message;

      if ($icon) {
        $icon.textContent =
          type === "danger" ? "❌" :
          type === "success" ? "✅" :
          type === "warning" ? "⚠️" :
          type === "neutral" ? "💬" :
          "ℹ️";
      }

      function close(result) {
        wrap.classList.add("is-leaving");
        setTimeout(() => wrap.remove(), TOAST_LEAVE_TIME);
        resolve(result);
      }

      $ok?.addEventListener("click", () => close(true));
      $cancel?.addEventListener("click", () => close(false));

      const onKey = (e) => {
        if (e.key === "Escape") {
          document.removeEventListener("keydown", onKey);
          close(false);
        }
      };

      document.addEventListener("keydown", onKey);

      stack.appendChild(wrap);
      setTimeout(() => $ok?.focus?.(), 0);
    });
  }

  function toastMsg(type, msg, title = "", timeout = TOAST_DEFAULT_TIMEOUT) {
    return toast({ type, title, message: msg, timeout });
  }

  // ==========================================================
  // Loading
  // ==========================================================
  function setLoading(loading) {
    btnSalvar.disabled = !!loading;
    form.dataset.loading = loading ? "1" : "0";
    btnSalvar.classList.toggle("is-loading", !!loading);
    btnSalvar.textContent = loading ? "Salvando..." : "Salvar";
  }

  // ==========================================================
  // Helpers erro
  // ==========================================================
  function getErrorSmall(fieldId) {
    return form.querySelector(`[data-erro-for="${fieldId}"]`);
  }

  function getModalCampoWrapper(fieldEl, smallEl) {
    if (fieldEl) return fieldEl.closest(".modal-campo");
    if (smallEl) return smallEl.closest(".modal-campo");
    return null;
  }

  function clearErrors() {
    form.querySelectorAll(".msg-erro").forEach((s) => {
      s.textContent = "";
      s.classList.remove("ativo");
      const w = s.closest(".modal-campo");
      if (w) w.classList.remove("erro");
    });

    form.querySelectorAll(".modal-campo.erro").forEach((w) => {
      w.classList.remove("erro");
    });
  }

  function setFieldError(fieldId, msg) {
    const alias = {
      id_usuario: "edit_u_id",
      nome: "edit_u_nome",
      email: "edit_u_email",
      telefone: "edit_u_tel",
      senha: "edit_u_senha",
      senha2: "edit_u_senha2",
      vinculos: "edit_u_vinculos_lista",
      novo_vinculo: "edit_u_novo_vinculo",
      id_empresa: "edit_u_novo_vinculo",
    };

    const realFieldId = alias[fieldId] || fieldId;
    const small = getErrorSmall(realFieldId);
    const field = document.getElementById(realFieldId);
    const wrapper = getModalCampoWrapper(field, small);

    if (small) {
      small.textContent = msg || "";
      small.classList.toggle("ativo", !!msg);
    }

    if (wrapper) {
      wrapper.classList.toggle("erro", !!msg);
    }
  }

  function focusFirstError() {
    const firstSmall = form.querySelector(".msg-erro.ativo");
    if (!firstSmall) return;

    const fieldId = firstSmall.getAttribute("data-erro-for");
    if (!fieldId) return;

    const field = document.getElementById(fieldId);
    if (field && typeof field.focus === "function") {
      field.focus();
    }
  }

  function bindClearOnInput(fieldEl) {
    if (!fieldEl?.id) return;

    const handler = () => setFieldError(fieldEl.id, "");
    fieldEl.addEventListener("input", handler);
    fieldEl.addEventListener("change", handler);
    fieldEl.addEventListener("blur", handler);
  }

  [
    elId,
    elNome,
    elEmail,
    elSenha,
    elSenha2,
    elTelefone,
    elNovaEmpresa,
  ].forEach(bindClearOnInput);

  // ==========================================================
  // Helpers gerais
  // ==========================================================
  const onlyDigits = (v) => String(v || "").replace(/\D+/g, "");

  function normalizarTexto(v) {
    return String(v || "").trim();
  }

  function normalizar(v) {
    return String(v ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .trim();
  }

  function validarEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function formatarTelefone(v) {
    const d = onlyDigits(v).slice(0, 11);

    if (!d) return "";

    if (d.length <= 10) {
      return d
        .replace(/^(\d{2})(\d)/, "($1) $2")
        .replace(/(\d{4})(\d)/, "$1-$2");
    }

    return d
      .replace(/^(\d{2})(\d)/, "($1) $2")
      .replace(/(\d{5})(\d)/, "$1-$2");
  }

  function setSelectValue(selectEl, value) {
    if (!selectEl) return;
    const wanted = String(value ?? "").trim();

    if (!wanted) {
      selectEl.value = "";
      return;
    }

    const found = Array.from(selectEl.options).find(
      (opt) => String(opt.value).trim() === wanted
    );

    selectEl.value = found ? found.value : "";
  }

  function findOptionValueByText(selectEl, text) {
    if (!selectEl) return "";
    const wanted = normalizar(text);

    if (!wanted) return "";

    const found = Array.from(selectEl.options).find((opt) => {
      const val = normalizar(opt.value);
      const txt = normalizar(opt.textContent);
      return val === wanted || txt === wanted;
    });

    return found ? String(found.value) : "";
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  // A marcação de desvinculação fica só no estado local; nada é gravado até Salvar.
  function renderVinculos() {
    if (!elVinculosLista) return;

    elVinculosLista.innerHTML = vinculosAtuais.map((vinculo, indice) => {
      const inativo = normalizar(vinculo.status) === "inativo";
      const rotulo = vinculo.marcado ? "Desfazer" : (inativo ? "Reativar" : "Desvincular");
      return `
      <div class="usuario-edit-vinculo"
        data-indice="${indice}"
        data-marcado="${vinculo.marcado ? "1" : "0"}"
        data-id-empresa-usuario="${escapeHtml(vinculo.id_empresa_usuario)}"
        data-id-empresa="${escapeHtml(vinculo.empresa_id)}">
        <div class="usuario-edit-vinculo-icone"><i class="fa-solid fa-building" aria-hidden="true"></i></div>
        <div class="usuario-edit-vinculo-empresa">${escapeHtml(vinculo.empresa || "Empresa")}</div>
        <span class="usuario-edit-vinculo-situacao">${inativo ? "Inativo" : ""}</span>
        <button type="button" class="usuario-edit-vinculo-acao" data-acao-vinculo>${rotulo}</button>
      </div>
    `;
    }).join("");

    if (elVinculosTotal) {
      const total = vinculosAtuais.length;
      elVinculosTotal.textContent = `${total} ${total === 1 ? "empresa" : "empresas"}`;
    }
  }

  if (elVinculosLista) {
    elVinculosLista.addEventListener("click", (e) => {
      const botao = e.target.closest("[data-acao-vinculo]");
      const linha = botao?.closest(".usuario-edit-vinculo");
      const vinculo = vinculosAtuais[Number(linha?.dataset.indice)];
      if (!vinculo) return;

      vinculo.marcado = !vinculo.marcado;
      setFieldError("vinculos", "");
      renderVinculos();
    });
  }

  // Seletor pesquisável de empresa (mesmo padrão de popover usado em Cobranças e Assinaturas).
  // A busca é feita no servidor, então empresas além das 100 primeiras continuam alcançáveis.
  // As já vinculadas ao usuário (inclusive inativas) ficam fora: o vínculo existente é reativado pelo seu próprio botão.
  const elSeletorEmpresa = document.getElementById("seletorNovaEmpresa");
  const elEmpresaBotao = document.getElementById("btnEditUsuarioEmpresa");
  const elEmpresaNome = document.getElementById("novaEmpresaNome");
  const elEmpresaPopover = document.getElementById("popoverNovaEmpresa");
  const elEmpresaPesquisa = document.getElementById("novaEmpresaPesquisa");
  const elEmpresaOpcoes = document.getElementById("opcoesNovaEmpresa");
  const ROTULO_EMPRESA = "Selecione uma empresa";
  let empresasEncontradas = [];
  let requisicaoEmpresas = 0;
  let timerBuscaEmpresa = 0;

  function renderizarOpcoesEmpresa(mensagem = "") {
    if (!elEmpresaOpcoes) return;

    const vinculadas = new Set(vinculosAtuais.map((vinculo) => String(vinculo.empresa_id || "")));
    const selecionada = String(elNovaEmpresa?.value || "");
    const itens = empresasEncontradas.filter((empresa) => !vinculadas.has(String(empresa.id_empresa)));

    elEmpresaOpcoes.innerHTML = itens.length
      ? itens.map((empresa) => `
        <button type="button" class="agenda-menu-item" role="option"
          aria-selected="${String(empresa.id_empresa) === selecionada}"
          data-id="${escapeHtml(empresa.id_empresa)}" data-nome="${escapeHtml(empresa.nome)}">
          <i class="fa-regular fa-building" aria-hidden="true"></i><span>${escapeHtml(empresa.nome)}</span>
        </button>`).join("")
      : `<div class="agenda-pesquisa-estado">${escapeHtml(mensagem || "Nenhuma empresa encontrada.")}</div>`;
  }

  async function buscarEmpresas(termo = "") {
    const requisicao = ++requisicaoEmpresas;
    renderizarOpcoesEmpresa("Carregando empresas...");

    try {
      const busca = termo ? `&busca=${encodeURIComponent(termo)}` : "";
      const resposta = await fetch(`${API_BASE}?path=superadmin%2Fempresa%2Flistar&status=ativo&ordem=nome_asc&limit=100${busca}`, {
        credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" },
      });
      const json = await resposta.json();
      // Uma resposta antiga não pode sobrepor a busca mais recente.
      if (requisicao !== requisicaoEmpresas) return;
      if (!resposta.ok || json?.ok !== true) throw new Error("EMPRESAS_INVALIDAS");

      empresasEncontradas = Array.isArray(json.data?.items) ? json.data.items : [];
      renderizarOpcoesEmpresa();
    } catch (erro) {
      if (requisicao !== requisicaoEmpresas) return;
      console.error("[EditarUsuario] Empresas:", erro);
      empresasEncontradas = [];
      renderizarOpcoesEmpresa("Não foi possível carregar as empresas.");
    }
  }

  function fecharSeletorEmpresa() {
    if (elEmpresaPopover) elEmpresaPopover.hidden = true;
    elEmpresaBotao?.setAttribute("aria-expanded", "false");
  }

  function abrirSeletorEmpresa() {
    if (!elEmpresaPopover || !elEmpresaBotao) return;
    if (!elEmpresaPopover.hidden) {
      fecharSeletorEmpresa();
      return;
    }

    elEmpresaPopover.hidden = false;
    elEmpresaBotao.setAttribute("aria-expanded", "true");
    if (elEmpresaPesquisa) elEmpresaPesquisa.value = "";
    buscarEmpresas("");
    elEmpresaPesquisa?.focus();
  }

  function definirEmpresaSelecionada(id, nome) {
    if (elNovaEmpresa) elNovaEmpresa.value = id;
    if (elEmpresaNome) elEmpresaNome.textContent = id ? nome : ROTULO_EMPRESA;
  }

  function reiniciarSeletorEmpresa() {
    definirEmpresaSelecionada("", "");
    fecharSeletorEmpresa();
    if (elEmpresaPesquisa) elEmpresaPesquisa.value = "";
    empresasEncontradas = [];
  }

  elEmpresaBotao?.addEventListener("click", abrirSeletorEmpresa);
  elEmpresaPesquisa?.addEventListener("input", () => {
    clearTimeout(timerBuscaEmpresa);
    timerBuscaEmpresa = setTimeout(() => buscarEmpresas(elEmpresaPesquisa.value.trim()), 250);
  });
  elEmpresaOpcoes?.addEventListener("click", (e) => {
    const opcao = e.target.closest("button[data-id]");
    if (!opcao) return;

    // Seleção única; clicar de novo na empresa escolhida desfaz a seleção.
    const jaSelecionada = String(elNovaEmpresa?.value || "") === opcao.dataset.id;
    definirEmpresaSelecionada(jaSelecionada ? "" : opcao.dataset.id, opcao.dataset.nome || "");
    setFieldError("novo_vinculo", "");
    fecharSeletorEmpresa();
  });
  document.addEventListener("click", (e) => {
    if (elSeletorEmpresa && !elSeletorEmpresa.contains(e.target)) fecharSeletorEmpresa();
  });

  function coletarDados() {
    // O servidor decide o status final (inativo/ativo) e preserva o perfil de cada vínculo.
    const vinculos = vinculosAtuais.map((vinculo) => ({
      id_empresa_usuario: normalizarTexto(vinculo.id_empresa_usuario),
      id_empresa: normalizarTexto(vinculo.empresa_id),
      acao: !vinculo.marcado
        ? ""
        : (normalizar(vinculo.status) === "inativo" ? "reativar" : "desvincular"),
    }));

    return {
      id_usuario: normalizarTexto(elId?.value),
      nome: normalizarTexto(elNome?.value),
      email: normalizarTexto(elEmail?.value).toLowerCase(),
      telefone: normalizarTexto(elTelefone?.value),
      // Senhas exatamente como digitadas (sem trim), como o backend espera.
      senha: String(elSenha?.value ?? ""),
      senha2: String(elSenha2?.value ?? ""),
      vinculos,
      novo_vinculo: {
        id_empresa: normalizarTexto(elNovaEmpresa?.value),
      },
    };
  }
  // ==========================================================
  function validate() {
    clearErrors();
    let ok = true;

    const dados = coletarDados();

    if (!dados.id_usuario || !/^\d+$/.test(dados.id_usuario)) {
      setFieldError("id_usuario", "Usuário inválido.");
      ok = false;
    }

    if (!dados.nome) {
      setFieldError("nome", "Informe o nome do usuário.");
      ok = false;
    } else if (dados.nome.length < 3) {
      setFieldError("nome", "O nome do usuário deve ter no mínimo 3 caracteres.");
      ok = false;
    } else if (dados.nome.length > 140) {
      setFieldError("nome", "O nome deve ter no máximo 140 caracteres.");
      ok = false;
    }

    if (!dados.email) {
      setFieldError("email", "Informe o e-mail.");
      ok = false;
    } else if (dados.email.length > 160) {
      setFieldError("email", "O e-mail deve ter no máximo 160 caracteres.");
      ok = false;
    } else if (!validarEmail(dados.email)) {
      setFieldError("email", "Informe um e-mail válido.");
      ok = false;
    }

    if (dados.telefone) {
      const tel = onlyDigits(dados.telefone);
      if (tel.length < 10 || tel.length > 11) {
        setFieldError("telefone", "Informe um telefone válido com DDD.");
        ok = false;
      }
    }

    if (!dados.vinculos.length) {
      setFieldError("vinculos", "O usuário deve possuir ao menos um vínculo empresarial.");
      ok = false;
    } else {
      const vinculoInvalido = dados.vinculos.some((vinculo) =>
        !/^\d+$/.test(vinculo.id_empresa_usuario)
        || !/^\d+$/.test(vinculo.id_empresa)
      );
      if (vinculoInvalido) {
        setFieldError("vinculos", "Revise os vínculos empresariais.");
        ok = false;
      }
    }

    if (dados.novo_vinculo.id_empresa && !/^\d+$/.test(dados.novo_vinculo.id_empresa)) {
      setFieldError("novo_vinculo", "Os dados do novo vínculo são inválidos.");
      ok = false;
    }

    // Mesmos limites e mensagens da política central (backend/_regras/politica_senha.php).
    // Senhas comuns e dados pessoais são verificados somente no servidor.
    if (dados.senha || dados.senha2) {
      if (!dados.senha) {
        setFieldError("senha", "Informe a nova senha.");
        ok = false;
      } else if (Array.from(dados.senha).length < 8) {
        setFieldError("senha", "A senha deve ter pelo menos 8 caracteres.");
        ok = false;
      } else if (new TextEncoder().encode(dados.senha).length > 72) {
        setFieldError("senha", "A senha está muito longa. Use até 72 caracteres, ou menos se usar acentos.");
        ok = false;
      } else if (!/[\p{P}\p{S}]/u.test(dados.senha)) {
        // Mesma definição de caractere especial do backend (politica_senha.php).
        setFieldError("senha", "A senha deve conter pelo menos um caractere especial, como #, @ ou !.");
        ok = false;
      }

      if (!dados.senha2) {
        setFieldError("senha2", "Repita a senha no campo de confirmação.");
        ok = false;
      } else if (dados.senha !== dados.senha2) {
        setFieldError("senha2", "As senhas informadas não coincidem.");
        ok = false;
      }
    }

    if (!ok) {
      focusFirstError();
    }

    return ok;
  }

  function applyApiFieldErrors(fields) {
    if (!fields || typeof fields !== "object") return;

    Object.entries(fields).forEach(([fieldId, msg]) => {
      setFieldError(String(fieldId), String(msg || ""));
    });

    focusFirstError();
  }

  // ==========================================================
  // Modal / reset
  // ==========================================================
  function fecharModal() {
    if (elSenha) elSenha.value = "";
    if (elSenha2) elSenha2.value = "";
    reiniciarIndicadorSenha();

    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("aberto", "ativo", "show", "is-open");
    document.body.classList.remove(
      "modal-open",
      "body-modal-open",
      "is-modal-open",
      "overflow-hidden",
      "no-scroll"
    );
  }

  function resetForm() {
    form.reset();
    clearErrors();

    if (elId) elId.value = "";
    if (elNome) elNome.value = "";
    if (elEmail) elEmail.value = "";
    if (elSenha) elSenha.value = "";
    if (elSenha2) elSenha2.value = "";
    reiniciarIndicadorSenha();
    if (elTelefone) elTelefone.value = "";
    vinculosAtuais = [];
    if (elVinculosLista) elVinculosLista.innerHTML = "";
    if (elVinculosTotal) elVinculosTotal.textContent = "0 empresas";
    reiniciarSeletorEmpresa();
  }

  // ==========================================================
  // Máscaras
  // ==========================================================
  if (elTelefone) {
    elTelefone.addEventListener("input", () => {
      elTelefone.value = formatarTelefone(elTelefone.value);
    });
  }

  // ==========================================================
  // Preencher / abrir modal
  // ==========================================================
  function preencherModal(data = {}) {
    const id = data.id_usuario ?? data.id ?? "";
    const nome = data.nome ?? "";
    const email = data.email ?? "";
    const telefone = data.telefone ?? "";

    if (elId) elId.value = id ? String(id) : "";
    if (elNome) elNome.value = nome ? String(nome) : "";
    if (elEmail) elEmail.value = email ? String(email) : "";
    if (elTelefone) elTelefone.value = telefone ? formatarTelefone(String(telefone)) : "";

    vinculosAtuais = (Array.isArray(data.vinculos) ? data.vinculos : []).map((vinculo) => ({
      id_empresa_usuario: String(vinculo.id_empresa_usuario || ""),
      empresa_id: String(vinculo.empresa_id ?? vinculo.id_empresa ?? ""),
      empresa: String(vinculo.empresa ?? vinculo.empresa_nome ?? "Empresa"),
      perfil: String(vinculo.perfil ?? vinculo.perfil_nome ?? ""),
      status: String(vinculo.status ?? vinculo.status_vinculo ?? "ativo"),
    }));
    renderVinculos();
    reiniciarSeletorEmpresa();

    if (elSenha) elSenha.value = "";
    if (elSenha2) elSenha2.value = "";
    reiniciarIndicadorSenha();

    clearErrors();

  }

  function abrirModalComDados(data = {}) {
    preencherModal(data);
    modal.setAttribute("aria-hidden", "false");
    modal.classList.add("ativo", "aberto", "show");
    document.body.classList.add("modal-open", "no-scroll");
  }

  // ==========================================================
  // Recarregar lista
  // ==========================================================
  function recarregarListaUsuarios() {
    if (window.ListaUsuarios && typeof window.ListaUsuarios.carregar === "function") {
      window.ListaUsuarios.carregar();
      return;
    }

    if (window.ListaUsuarios && typeof window.ListaUsuarios.recarregar === "function") {
      window.ListaUsuarios.recarregar();
      return;
    }

    if (window.ListaUsuariosSuper && typeof window.ListaUsuariosSuper.carregar === "function") {
      window.ListaUsuariosSuper.carregar();
      return;
    }

    if (window.ListaUsuariosSuper && typeof window.ListaUsuariosSuper.recarregar === "function") {
      window.ListaUsuariosSuper.recarregar();
      return;
    }

    if (typeof window.recarregarListaUsuarios === "function") {
      window.recarregarListaUsuarios();
      return;
    }

    window.location.reload();
  }

  // ==========================================================
  // Submit
  // ==========================================================
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (form.dataset.loading === "1") return;

    if (!validate()) {
      toastMsg("warning", "Revise os campos destacados.");
      return;
    }

    const dados = coletarDados();

    const fd = new FormData();
    fd.append("id_usuario", dados.id_usuario);
    fd.append("nome", dados.nome);
    fd.append("email", dados.email);
    fd.append("telefone", dados.telefone || "");
    fd.append("vinculos", JSON.stringify(dados.vinculos));

    if (dados.novo_vinculo.id_empresa) {
      fd.append("novo_vinculo", JSON.stringify(dados.novo_vinculo));
    }

    if (dados.senha || dados.senha2) {
      fd.append("senha", dados.senha || "");
      fd.append("senha2", dados.senha2 || "");
    }

    setLoading(true);

    try {
      const resp = await fetch(API_URL, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "X-CSRF-Token": String(window.__AUTH__?.csrf_token || "")
        }
      });

      const raw = await resp.text();
      let json = null;

      try {
        json = JSON.parse(raw);
      } catch (_) {
        json = null;
      }

      console.log("[EditarUsuario] STATUS:", resp.status);
      console.log("[EditarUsuario] RAW:", raw);
      console.log("[EditarUsuario] JSON:", json);

      if (!json) {
        toastMsg("danger", "Resposta inválida da API ao editar o usuário.");
        return;
      }

      if (!resp.ok || json.ok !== true) {
        if (json.fields && typeof json.fields === "object") {
          applyApiFieldErrors(json.fields);
        }

        toastMsg("danger", json.user_msg || "Não foi possível atualizar o usuário.");
        return;
      }

      fecharModal();

      document.dispatchEvent(
        new CustomEvent("usuario:editado", {
          detail: json?.data || { id_usuario: dados.id_usuario }
        })
      );

      const successTimeout = 2200;

      toastMsg(
        "success",
        json.user_msg || "Usuário atualizado com sucesso.",
        "",
        successTimeout
      );

      setTimeout(() => {
        recarregarListaUsuarios();
      }, successTimeout + TOAST_LEAVE_TIME);

    } catch (err) {
      console.error("[EditarUsuario] Erro:", err);
      toastMsg("danger", "Falha de rede ao atualizar o usuário.");
    } finally {
      setLoading(false);
    }
  });

  // ==========================================================
  // Reset ao fechar modal
  // ==========================================================
  document.addEventListener("click", (e) => {
    const btnFechar = e.target.closest("[data-fechar-modal]");
    if (!btnFechar) return;

    const m = btnFechar.closest("#modalEditarUsuario");
    if (!m) return;

    window.setTimeout(() => {
      resetForm();
    }, 80);
  });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    if (modal.getAttribute("aria-hidden") === "true") return;

    window.setTimeout(() => {
      resetForm();
    }, 80);
  });

  window.UsuarioEditarModal = {
    open: abrirModalComDados,
    fill: preencherModal,
    close: fecharModal,
    reset: resetForm,
    limparErros: clearErrors,
    toastConfirm,
  };
})();
