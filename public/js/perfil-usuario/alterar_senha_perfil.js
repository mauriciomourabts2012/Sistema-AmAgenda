/* ==========================================================
   alterar_senha_perfil.js — Modal Perfil | Alterar senha
   ✅ API Central
   ✅ Form padrão do modal perfil
   ✅ Toast universal no mesmo padrão do sistema
   ✅ Validação front mais clara
   ✅ Marca campo com erro
   ✅ Limpa formulário ao sucesso
   ✅ Após alterar a senha: revalida a sessão e mantém o acesso
   ✅ Mensagens mais explicativas para o usuário
========================================================== */
(() => {
  "use strict";

  if (window.__ALTERAR_SENHA_PERFIL_JS_INIT__) {
    console.warn("[AlterarSenhaPerfil] Script já inicializado.");
    return;
  }
  window.__ALTERAR_SENHA_PERFIL_JS_INIT__ = true;

  const API_URL = "/public/api/api_central.php?path=perfil/alterar-senha";
  const SESSION_API_URL = "/api/api_central.php?path=_auth/session";

  const TOAST_DEFAULT_TIMEOUT = 4200;
  const TOAST_LEAVE_TIME = 180;

  // ==========================================================
  // DOM
  // ==========================================================
  const modal = document.getElementById("modalPerfilUsuario");
  const form = document.getElementById("formAlterarSenha");
  const btnSalvar = form?.querySelector('button[type="submit"]');

  const inputSenhaAtual = document.getElementById("senha_atual");
  const inputNovaSenha = document.getElementById("nova_senha");
  const inputConfirmarSenha = document.getElementById("confirmar_senha");

  if (!form || !inputSenhaAtual || !inputNovaSenha || !inputConfirmarSenha) {
    console.warn("[AlterarSenhaPerfil] Formulário de alteração de senha não encontrado.");
    return;
  }

  // ==========================================================
  // Indicador visual de segurança da senha (apenas apoio visual).
  // Inicializado uma única vez; a validação oficial é sempre do backend.
  // ==========================================================
  function obterDadosPessoaisIndicador() {
    const nome = (document.getElementById("perfilNomeUsuario")?.textContent || "").trim();
    return { nome: nome === "Usuário" ? "" : nome };
  }

  const indicadorSenha = window.IndicadorSenha?.anexar({
    campoNova: inputNovaSenha,
    campoConfirmacao: inputConfirmarSenha,
    campoAtual: inputSenhaAtual,
    dadosPessoais: obterDadosPessoaisIndicador,
  }) || null;

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
       type === "confirm" ? "Informação" : "Aviso");

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

  // ==========================================================
  // Loading
  // ==========================================================
  function setLoading(loading) {
    if (!btnSalvar) return;

    btnSalvar.disabled = !!loading;
    form.dataset.loading = loading ? "1" : "0";
    btnSalvar.classList.toggle("is-loading", !!loading);
    btnSalvar.textContent = loading ? "Alterando senha..." : "Salvar";
  }

  // ==========================================================
  // Erros de campos
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
      senha_atual: "senha_atual",
      nova_senha: "nova_senha",
      confirmar_senha: "confirmar_senha",
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
    if (field && typeof field.focus === "function") field.focus();
  }

  function bindClearOnInput(fieldEl) {
    if (!fieldEl?.id) return;
    const handler = () => setFieldError(fieldEl.id, "");
    fieldEl.addEventListener("input", handler);
    fieldEl.addEventListener("change", handler);
    fieldEl.addEventListener("blur", handler);
  }

  [inputSenhaAtual, inputNovaSenha, inputConfirmarSenha].forEach(bindClearOnInput);

  // ==========================================================
  // Helpers
  // ==========================================================
  // Mesmos limites da política do backend (backend/_regras/politica_senha.php).
  const SENHA_MIN_CARACTERES = 8;
  const SENHA_MAX_TAMANHO = 72;

  // As senhas são usadas exatamente como digitadas, sem remover espaços.
  function coletarDados() {
    return {
      senha_atual: String(inputSenhaAtual.value ?? ""),
      nova_senha: String(inputNovaSenha.value ?? ""),
      confirmar_senha: String(inputConfirmarSenha.value ?? ""),
    };
  }

  function contarCaracteres(v) {
    return Array.from(v).length;
  }

  function tamanhoArmazenado(v) {
    return new TextEncoder().encode(v).length;
  }

  function getMensagemAmigavelPorCode(code, fallbackMsg = "") {
    const mapa = {
      METHOD_NOT_ALLOWED: "Operação não permitida nesta requisição.",
      NOT_AUTHENTICATED: "Sua sessão expirou. Faça login novamente para continuar.",
      CURRENT_PASSWORD_REQUIRED: "Informe sua senha atual para continuar.",
      NEW_PASSWORD_REQUIRED: "Informe a nova senha.",
      CONFIRM_PASSWORD_REQUIRED: "Confirme a nova senha.",
      PASSWORD_CONFIRMATION_MISMATCH: "As senhas informadas não coincidem.",
      PASSWORD_SAME_AS_CURRENT: "A nova senha deve ser diferente da senha atual.",
      DB_CONN_MISSING: "Não foi possível acessar a conexão com o banco de dados.",
      DB_CONN_ERROR: "Falha ao conectar ao banco de dados.",
      USER_NOT_FOUND: "Não encontramos o usuário logado para concluir a alteração.",
      USER_INACTIVE: "Seu usuário não está ativo no sistema, por isso a senha não pode ser alterada.",
      CURRENT_PASSWORD_INVALID: "A senha atual informada está incorreta.",
      DB_UPDATE_ERROR: "Não foi possível salvar a nova senha agora. Tente novamente.",
      SERVER_ERROR: "Ocorreu um erro interno ao alterar a senha. Tente novamente em instantes.",
      PASSWORD_UPDATED: "Sua senha foi alterada com sucesso."
    };

    return mapa[String(code || "").trim()] || fallbackMsg || "Não foi possível alterar a senha.";
  }

  // ==========================================================
  // Validate
  // ==========================================================
  // Retorna a mensagem do primeiro erro encontrado ou "" quando tudo está correto.
  function validate() {
    clearErrors();
    let primeiraMensagem = "";

    const marcarErro = (campo, mensagem) => {
      setFieldError(campo, mensagem);
      if (!primeiraMensagem) primeiraMensagem = mensagem;
    };

    const dados = coletarDados();

    if (!dados.senha_atual) {
      marcarErro("senha_atual", "Informe sua senha atual.");
    }

    if (!dados.nova_senha) {
      marcarErro("nova_senha", "Informe a nova senha.");
    } else if (contarCaracteres(dados.nova_senha) < SENHA_MIN_CARACTERES) {
      marcarErro("nova_senha", `A senha deve ter pelo menos ${SENHA_MIN_CARACTERES} caracteres.`);
    } else if (tamanhoArmazenado(dados.nova_senha) > SENHA_MAX_TAMANHO) {
      marcarErro("nova_senha", `A senha está muito longa. Use até ${SENHA_MAX_TAMANHO} caracteres, ou menos se usar acentos.`);
    } else if (!/[\p{P}\p{S}]/u.test(dados.nova_senha)) {
      // Mesma definição de caractere especial do backend (politica_senha.php).
      marcarErro("nova_senha", "A senha deve conter pelo menos um caractere especial, como #, @ ou !.");
    }

    if (!dados.confirmar_senha) {
      marcarErro("confirmar_senha", "Confirme a nova senha.");
    } else if (dados.nova_senha !== dados.confirmar_senha) {
      marcarErro("confirmar_senha", "As senhas informadas não coincidem.");
    }

    if (dados.senha_atual && dados.nova_senha && dados.senha_atual === dados.nova_senha) {
      marcarErro("nova_senha", "A nova senha deve ser diferente da senha atual.");
    }

    if (primeiraMensagem) focusFirstError();
    return primeiraMensagem;
  }

  function applyApiFieldErrors(fields) {
    if (!fields || typeof fields !== "object") return;

    Object.entries(fields).forEach(([fieldId, msg]) => {
      setFieldError(String(fieldId), String(msg || ""));
    });

    focusFirstError();
  }

  // ==========================================================
  // Reset
  // ==========================================================
  function resetForm() {
    form.reset();
    clearErrors();
    indicadorSenha?.limpar();
    form.dataset.loading = "0";

    if (btnSalvar) {
      btnSalvar.disabled = false;
      btnSalvar.classList.remove("is-loading");
      btnSalvar.textContent = "Salvar";
    }

    form.querySelectorAll("input, button, select, textarea").forEach((el) => {
      el.disabled = false;
    });
  }

  // ==========================================================
  // ALTERAR SENHA
  // ==========================================================
  async function enviarFormulario() {
    const formData = new FormData(form);

    const response = await fetch(API_URL, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": String(window.__AUTH__?.csrf_token || ""),
      },
    });

    const raw = await response.text();
    let json = null;

    try {
      json = JSON.parse(raw);
    } catch (_) {
      json = null;
    }

    console.log("[AlterarSenhaPerfil] STATUS:", response.status);
    console.log("[AlterarSenhaPerfil] RAW:", raw);
    console.log("[AlterarSenhaPerfil] JSON:", json);

    if (!json) {
      throw new Error("O sistema retornou uma resposta inválida ao tentar alterar a senha.");
    }

    if (!response.ok || json.ok !== true) {
      if (json.fields && typeof json.fields === "object") {
        applyApiFieldErrors(json.fields);
      } else if (json.field) {
        setFieldError(
          String(json.field),
          json.user_msg || getMensagemAmigavelPorCode(json.code, "Revise o campo informado.")
        );
        focusFirstError();
      }

      const mensagemErro = getMensagemAmigavelPorCode(json.code, json.user_msg);
      const erro = new Error(mensagemErro);
      erro.api = json;
      throw erro;
    }

    return json;
  }

  // ==========================================================
  // REVALIDAÇÃO AUTORITATIVA APÓS TROCA DE SENHA
  // ==========================================================
  async function revalidarSessaoAposTrocaSenha() {
    const resposta = await fetch(SESSION_API_URL, {
      method: "GET",
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    const dados = await resposta.json().catch(() => null);
    const auth = dados?.data?.user || null;
    if (!resposta.ok || dados?.ok !== true || !auth) {
      throw new Error("A senha foi alterada, mas não foi possível revalidar a sessão.");
    }
    if (auth.deve_alterar_senha !== false || auth.senha_temporaria_vencida !== false) {
      throw new Error("A alteração ainda não foi confirmada pela sessão. Tente novamente em instantes.");
    }

    window.__AUTH__ = auth;
    document.dispatchEvent(new CustomEvent("amagenda:sessao-carregada", { detail: auth }));
    document.dispatchEvent(new CustomEvent("perfil:senha-atualizada", { detail: { auth } }));
    return auth;
  }

  // ==========================================================
  // Submit
  // ==========================================================
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (form.dataset.loading === "1") return;

    const erroValidacao = validate();
    if (erroValidacao) {
      toastMsg("warning", erroValidacao, "Verifique os dados");
      return;
    }

    setLoading(true);
    clearErrors();

    try {
      const json = await enviarFormulario();

      form.reset();
      clearErrors();

      try {
        await revalidarSessaoAposTrocaSenha();
      } catch (erroRevalidacao) {
        erroRevalidacao.senhaAlterada = true;
        throw erroRevalidacao;
      }

      toastMsg(
        "success",
        (json.user_msg || "Sua senha foi alterada com sucesso.") + " Sua sessão continua ativa.",
        "Senha atualizada",
        4200
      );

      modal?.classList.remove("ativo");
      modal?.setAttribute("aria-hidden", "true");
      resetForm();

    } catch (err) {
      console.error("[AlterarSenhaPerfil] Erro:", err);

      const api = err?.api || null;
      const code = api?.code || "";
      const msg = err?.message || "Erro ao alterar a senha.";

      if (err?.senhaAlterada === true) {
        toastMsg(
          "warning",
          msg + " Você pode sair e entrar novamente com a nova senha.",
          "Confirmação da sessão pendente",
          0
        );
        form.dataset.loading = "0";
        if (btnSalvar) {
          btnSalvar.classList.remove("is-loading");
          btnSalvar.textContent = "Senha alterada";
          btnSalvar.disabled = true;
        }
        return;
      } else if (code === "CURRENT_PASSWORD_INVALID") {
        toastMsg(
          "danger",
          "A senha atual informada não confere. Verifique e tente novamente.",
          "Senha atual inválida"
        );
      } else if (code === "NOT_AUTHENTICATED") {
        toastMsg(
          "danger",
          "Sua sessão expirou. Faça login novamente para alterar sua senha.",
          "Sessão expirada"
        );
      } else if (code === "USER_INACTIVE") {
        toastMsg(
          "warning",
          "Seu usuário não está ativo no sistema. Por isso, a senha não pode ser alterada.",
          "Usuário inativo"
        );
      } else if (code === "DB_UPDATE_ERROR" || code === "SERVER_ERROR") {
        toastMsg(
          "danger",
          "Ocorreu um problema ao salvar a nova senha. Tente novamente em instantes.",
          "Falha ao salvar"
        );
      } else {
        toastMsg("danger", msg, "Não foi possível alterar a senha");
      }

      setLoading(false);
      return;
    }

    setLoading(false);
  });

  // ==========================================================
  // Reset ao fechar modal
  // ==========================================================
  document.addEventListener("click", (e) => {
    const btnFechar = e.target.closest("[data-fechar-modal]");
    if (!btnFechar) return;

    const m = btnFechar.closest("#modalPerfilUsuario");
    if (!m) return;

    window.setTimeout(() => {
      resetForm();
    }, 80);
  });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    if (modal && modal.getAttribute("aria-hidden") === "true") return;

    window.setTimeout(() => {
      resetForm();
    }, 80);
  });

  window.AlterarSenhaPerfil = {
    reset: resetForm,
    limparErros: clearErrors,
  };
})();
