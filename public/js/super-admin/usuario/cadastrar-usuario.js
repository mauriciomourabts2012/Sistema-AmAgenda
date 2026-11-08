/* ==========================================================
   CadastrarUsuario.js — SuperAdmin | Cadastrar Usuário (AmAgenda)
   ✅ Compatível com HTML atual
   ✅ Compatível com PHP novo (usuario + empresa_usuario)
   ✅ API:
      POST /public/api/api_central.php?path=superadmin/usuario/cadastrar
   ✅ Envia:
      - nome
      - email
      - telefone
      - senha
      - senha2
      - empresa_id
      - id_perfil
      - status
   ✅ Padrão visual:
      - Toast: .ui-toast-stack + .ui-alert
      - Campo erro: .modal-campo.erro + .msg-erro.ativo
========================================================== */
(() => {
  "use strict";

  if (window.__CADASTRAR_USUARIO_JS_INIT__) {
    console.warn("[CadastrarUsuario] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__CADASTRAR_USUARIO_JS_INIT__ = true;

  const API_BASE = "/public/api/api_central.php";
  const API_PATH = "superadmin/usuario/cadastrar";
  const SUCCESS_TOAST_TIME = 3500;

  const form = document.getElementById("formCadastrarUsuario");
  if (!form) {
    console.warn("[CadastrarUsuario] Formulário #formCadastrarUsuario não encontrado.");
    return;
  }

  const modal = document.getElementById("modalCadastrarUsuario");
  const btnSalvar = document.getElementById("btnSalvarUsuario");
  const $ = (id) => document.getElementById(id);

  // ==========================================================
  // CAMPOS
  // ==========================================================
  const elNome    = $("u_nome");
  const elEmail   = $("u_email");
  const elTel     = $("u_tel");
  const elSenha   = $("u_senha");
  const elSenha2  = $("u_senha2");
  const elPerfil  = $("u_perfil_super_admin");
  const elEmpresa = $("u_empresa_super_admin");
  const elStatus  = $("u_status");

  // ==========================================================
  // TOAST UNIVERSAL
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
    setTimeout(() => {
      try { el.remove(); } catch (_) {}
    }, 180);
  }

  function toast({
    title = "",
    message = "—",
    type = "info",
    timeout = 3500,
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
       type === "danger"  ? "Erro" :
       type === "neutral" ? "Aviso" :
       type === "confirm" ? "Confirmação" : "Aviso");

    $msg.textContent = String(message ?? "").trim() || "—";

    if ($icon) {
      $icon.textContent =
        type === "danger"  ? "❌" :
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

  function toastMsg(type, msg, title = "", timeout = 3500) {
    return toast({ type, title, message: msg, timeout });
  }

  // ==========================================================
  // LOADING
  // ==========================================================
  function setLoading(loading) {
    if (!btnSalvar) return;
    btnSalvar.disabled = loading;
    btnSalvar.classList.toggle("is-loading", loading);
    btnSalvar.textContent = loading ? "Salvando..." : "Salvar";
  }

  // ==========================================================
  // ERROS DE CAMPOS
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
    const small = getErrorSmall(fieldId);
    const field = document.getElementById(fieldId);
    const wrapper = getModalCampoWrapper(field, small);

    if (small) {
      small.textContent = msg || "";
      small.classList.toggle("ativo", !!msg);
    }

    if (wrapper) {
      wrapper.classList.toggle("erro", !!msg);
    }
  }

  function applyBackendFieldError(fieldKey, msg) {
    const key = String(fieldKey || "").trim();
    const text = String(msg || "").trim();

    if (!key) return;

    // Mapeia chaves do PHP para os IDs reais do HTML
    const aliases = {
      u_nome: ["u_nome"],
      nome: ["u_nome"],

      u_email: ["u_email"],
      email: ["u_email"],

      u_tel: ["u_tel"],
      telefone: ["u_tel"],

      u_senha: ["u_senha"],
      senha: ["u_senha"],

      u_senha2: ["u_senha2"],
      senha2: ["u_senha2"],

      u_status: ["u_status"],
      status: ["u_status"],

      u_perfil_super_admin: ["u_perfil_super_admin"],
      id_perfil: ["u_perfil_super_admin"],
      perfil: ["u_perfil_super_admin"],

      u_empresa: ["u_empresa_super_admin"],
      u_empresa_super_admin: ["u_empresa_super_admin"],
      id_empresa: ["u_empresa_super_admin"],
      empresa_id: ["u_empresa_super_admin"],
    };

    const targets = aliases[key] || [key];
    targets.forEach((id) => setFieldError(id, text));
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

  [elNome, elEmail, elTel, elSenha, elSenha2, elPerfil, elEmpresa, elStatus]
    .forEach(bindClearOnInput);

  // ==========================================================
  // HELPERS
  // ==========================================================
  function isEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || "").trim());
  }

  function normalizePhoneDigits(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function closeModal() {
    if (!modal) return;

    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("aberto");
    document.body.classList.remove("modal-open", "body-travado", "sem-scroll");
  }

  // ==========================================================
  // VALIDAÇÃO
  // ==========================================================
  function validate() {
    clearErrors();

    let ok = true;

    const nome        = (elNome?.value || "").trim();
    const email       = (elEmail?.value || "").trim();
    const telRaw      = (elTel?.value || "").trim();
    const telDigits   = normalizePhoneDigits(telRaw);
    const senha       = (elSenha?.value || "");
    const senha2      = (elSenha2?.value || "");
    const idPerfil    = (elPerfil?.value || "").trim();
    const empresaId   = (elEmpresa?.value || "").trim();
    const status      = (elStatus?.value || "ativo").trim();

    if (!nome) {
      setFieldError("u_nome", "Informe o nome completo.");
      ok = false;
    }

    if (!email) {
      setFieldError("u_email", "Informe o e-mail (login).");
      ok = false;
    } else if (!isEmail(email)) {
      setFieldError("u_email", "E-mail inválido.");
      ok = false;
    }

    if (!senha) {
      setFieldError("u_senha", "Informe a senha.");
      ok = false;
    } else if (senha.length < 6) {
      setFieldError("u_senha", "Senha deve ter no mínimo 6 caracteres.");
      ok = false;
    }

    if (!senha2) {
      setFieldError("u_senha2", "Confirme a senha.");
      ok = false;
    } else if (senha !== senha2) {
      setFieldError("u_senha2", "As senhas não coincidem.");
      ok = false;
    }

    if (!idPerfil) {
      setFieldError("u_perfil_super_admin", "Selecione o perfil.");
      ok = false;
    }

    if (!empresaId) {
      setFieldError("u_empresa_super_admin", "Selecione a empresa.");
      ok = false;
    }

    if (telRaw && (telDigits.length < 10 || telDigits.length > 13)) {
      setFieldError("u_tel", "Telefone inválido. Informe DDD + número.");
      ok = false;
    }

    if (!status) {
      setFieldError("u_status", "Selecione o status.");
      ok = false;
    }

    if (!ok) {
      focusFirstError();
      return null;
    }

    return {
      nome,
      email,
      telefone: telRaw,
      senha,
      senha2,
      empresa_id: empresaId,
      id_perfil: idPerfil,
      status,
    };
  }

  // ==========================================================
  // SELETOR PESQUISÁVEL DE EMPRESA
  // Mesmo padrão de popover do modal Editar Usuário. A lista vem do select oculto, já carregado pelo
  // SelectListaUniversal com as empresas autorizadas: a pesquisa filtra localmente, sem nova consulta.
  // ==========================================================
  const elSeletorEmpresa = $("seletorCadastroUsuarioEmpresa");
  const elEmpresaBotao = $("btnCadastroUsuarioEmpresa");
  const elEmpresaNome = $("cadastroUsuarioEmpresaNome");
  const elEmpresaPopover = $("popoverCadastroUsuarioEmpresa");
  const elEmpresaPesquisa = $("cadastroUsuarioEmpresaPesquisa");
  const elEmpresaOpcoes = $("opcoesCadastroUsuarioEmpresa");
  const ROTULO_EMPRESA = "Selecione uma empresa";

  function normalizarBuscaEmpresa(v) {
    return String(v || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().trim();
  }

  function empresasDisponiveis() {
    if (!elEmpresa) return [];
    return Array.from(elEmpresa.options)
      .filter((opcao) => opcao.value !== "")
      .map((opcao) => ({ id: opcao.value, nome: opcao.textContent.trim() }));
  }

  function sincronizarEmpresaSelecionada() {
    if (!elEmpresaNome) return;
    const opcao = elEmpresa?.value ? elEmpresa.selectedOptions[0] : null;
    elEmpresaNome.textContent = opcao ? opcao.textContent.trim() : ROTULO_EMPRESA;
    elEmpresaNome.title = opcao ? opcao.textContent.trim() : "";
  }

  function renderizarOpcoesEmpresa() {
    if (!elEmpresaOpcoes) return;
    const termo = normalizarBuscaEmpresa(elEmpresaPesquisa?.value);
    const selecionada = String(elEmpresa?.value || "");
    const itens = empresasDisponiveis().filter((empresa) =>
      !termo || normalizarBuscaEmpresa(empresa.nome).includes(termo) || empresa.id === termo
    );

    elEmpresaOpcoes.replaceChildren();
    if (!itens.length) {
      const vazio = document.createElement("div");
      vazio.className = "agenda-pesquisa-estado";
      vazio.textContent = empresasDisponiveis().length ? "Nenhuma empresa encontrada." : "Nenhuma empresa disponível.";
      elEmpresaOpcoes.appendChild(vazio);
      return;
    }

    itens.forEach((empresa) => {
      const botao = document.createElement("button");
      botao.type = "button";
      botao.className = "agenda-menu-item";
      botao.setAttribute("role", "option");
      botao.setAttribute("aria-selected", String(empresa.id === selecionada));
      botao.dataset.id = empresa.id;
      const icone = document.createElement("i");
      icone.className = "fa-regular fa-building";
      icone.setAttribute("aria-hidden", "true");
      const nome = document.createElement("span");
      nome.textContent = empresa.nome;
      botao.append(icone, nome);
      elEmpresaOpcoes.appendChild(botao);
    });
  }

  function fecharSeletorEmpresa({ focarBotao = false } = {}) {
    if (elEmpresaPopover) elEmpresaPopover.hidden = true;
    elEmpresaBotao?.setAttribute("aria-expanded", "false");
    if (focarBotao) elEmpresaBotao?.focus();
  }

  function abrirSeletorEmpresa() {
    if (!elEmpresaPopover || !elEmpresaBotao) return;
    if (!elEmpresaPopover.hidden) {
      fecharSeletorEmpresa();
      return;
    }
    if (elEmpresaPesquisa) elEmpresaPesquisa.value = "";
    renderizarOpcoesEmpresa();
    elEmpresaPopover.hidden = false;
    elEmpresaBotao.setAttribute("aria-expanded", "true");
    elEmpresaPesquisa?.focus();
  }

  function reiniciarPesquisaEmpresa() {
    fecharSeletorEmpresa();
    if (elEmpresaPesquisa) elEmpresaPesquisa.value = "";
    sincronizarEmpresaSelecionada();
  }

  elEmpresaBotao?.addEventListener("click", abrirSeletorEmpresa);
  elEmpresaPesquisa?.addEventListener("input", renderizarOpcoesEmpresa);
  elEmpresaOpcoes?.addEventListener("click", (e) => {
    const opcao = e.target.closest("button[data-id]");
    if (!opcao || !elEmpresa) return;
    // O ID vai para o select oculto: o envio e a validação continuam lendo elEmpresa.value.
    elEmpresa.value = opcao.dataset.id;
    elEmpresa.dispatchEvent(new Event("change", { bubbles: true }));
    setFieldError("u_empresa_super_admin", "");
    fecharSeletorEmpresa({ focarBotao: true });
  });

  // Teclado: setas percorrem pesquisa e opções; Esc fecha só a lista (sem fechar o modal).
  elEmpresaPopover?.addEventListener("keydown", (e) => {
    const opcoes = Array.from(elEmpresaOpcoes?.querySelectorAll("button[data-id]") || []);
    const atual = opcoes.indexOf(document.activeElement);
    if (e.key === "Escape") {
      e.preventDefault();
      e.stopPropagation();
      fecharSeletorEmpresa({ focarBotao: true });
    } else if (e.key === "ArrowDown" && opcoes.length) {
      e.preventDefault();
      opcoes[Math.min(atual + 1, opcoes.length - 1)].focus();
    } else if (e.key === "ArrowUp" && opcoes.length) {
      e.preventDefault();
      if (atual <= 0) elEmpresaPesquisa?.focus();
      else opcoes[atual - 1].focus();
    }
  });

  document.addEventListener("click", (e) => {
    if (elSeletorEmpresa && !elSeletorEmpresa.contains(e.target)) fecharSeletorEmpresa();
  });

  // Reflete o valor do select: recarga das empresas (SelectListaUniversal), reset do formulário e abertura/fechamento do modal.
  if (elEmpresa) {
    elEmpresa.addEventListener("change", sincronizarEmpresaSelecionada);
    new MutationObserver(sincronizarEmpresaSelecionada).observe(elEmpresa, { childList: true });
  }
  form.addEventListener("reset", () => setTimeout(reiniciarPesquisaEmpresa, 0));
  if (modal) {
    new MutationObserver(reiniciarPesquisaEmpresa).observe(modal, { attributes: true, attributeFilter: ["aria-hidden", "class"] });
  }
  sincronizarEmpresaSelecionada();

  // ==========================================================
  // SUBMIT
  // ==========================================================
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const data = validate();
    if (!data) {
      toastMsg("warning", "Revise os campos destacados.");
      return;
    }

    setLoading(true);

    try {
      const payload = new URLSearchParams();
      Object.entries(data).forEach(([k, v]) => {
        payload.set(k, String(v ?? ""));
      });

      const url = `${API_BASE}?path=${encodeURIComponent(API_PATH)}`;

      const resp = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          "Accept": "application/json",
          "X-CSRF-Token": String(window.__AUTH__?.csrf_token || ""),
        },
        body: payload.toString(),
        credentials: "include",
        cache: "no-store",
      });

      const json = await resp.json().catch(() => null);

      if (!resp.ok || !json || json.ok !== true) {
        if (json?.fields && typeof json.fields === "object") {
          Object.entries(json.fields).forEach(([fieldKey, msg]) => {
            applyBackendFieldError(fieldKey, msg);
          });
          focusFirstError();
        }

        toastMsg(
          "danger",
          json?.user_msg || "Não foi possível cadastrar o usuário."
        );
        return;
      }

      toastMsg(
        "success",
        json?.user_msg || "Usuário cadastrado com sucesso.",
        "",
        SUCCESS_TOAST_TIME
      );

      form.reset();
      clearErrors();

      // Mantém status ativo após reset
      if (elStatus) {
        elStatus.value = "ativo";
      }

      closeModal();

      window.dispatchEvent(new CustomEvent("usuario:created", {
        detail: json?.data || null
      }));

      // Recarrega somente depois da mensagem desaparecer
      setTimeout(() => {
        window.location.reload();
      }, SUCCESS_TOAST_TIME + 220);

    } catch (err) {
      console.error("[CadastrarUsuario] Erro no cadastro:", err);
      toastMsg("danger", "Falha de rede ao cadastrar o usuário.");
    } finally {
      setLoading(false);
    }
  });
})();