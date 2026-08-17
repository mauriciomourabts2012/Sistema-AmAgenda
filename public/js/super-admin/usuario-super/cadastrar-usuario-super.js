/* ==========================================================
   CadastrarUsuarioSuper.js — SuperAdmin | Cadastrar Super Admin (AmAgenda)
   - Modal: #modalCadastrarUsuarioSuper
   - Form:  #formCadastrarUsuarioSuper
   - API:   POST /api/api_central.php?path=superadmin/usuario/cadastrar-super
   - Proteções:
     ✅ trava contra carga duplicada
     ✅ bloqueio contra submit duplo
     ✅ captura EXATA dos campos do formulário correto
     ✅ validação reforçada
     ✅ limpeza segura após sucesso
     ✅ fechamento compatível com modal "ativo"
     ✅ diagnóstico real da senha sem confusão visual
========================================================== */
(() => {
  "use strict";

  if (window.__CADASTRAR_USUARIO_SUPER_INIT__) {
    console.warn("[CadastrarUsuarioSuper] Script já inicializado.");
    return;
  }
  window.__CADASTRAR_USUARIO_SUPER_INIT__ = true;

  const API_BASE = "/api/api_central.php";
  const API_PATH = "superadmin/usuario/cadastrar-super";
  const DEBUG = true;

  const form = document.getElementById("formCadastrarUsuarioSuper");
  if (!form) return;

  const btnSalvar = document.getElementById("btnSalvarUsuarioSuper");

  /* ==========================================================
     Buscar SEMPRE dentro do formulário correto
  ========================================================== */
  const elNome = form.querySelector("#us_nome");
  const elEmail = form.querySelector("#us_email");
  const elTelefone = form.querySelector("#us_telefone");
  const elStatus = form.querySelector("#us_status");
  const elSenha = form.querySelector("#us_senha");
  const elSenha2 = form.querySelector("#us_senha2");
  const elTipoUsuario = form.querySelector('input[name="tipo_usuario"]');

  let isSubmitting = false;

  /* ==========================================================
     Debug
  ========================================================== */
  function debugLog(...args) {
    if (!DEBUG) return;
    console.log("[CadastrarUsuarioSuper]", ...args);
  }

  function debugPassword(label, value) {
    if (!DEBUG) return;

    const v = String(value ?? "");
    const codes = Array.from(v).map((ch) => ch.charCodeAt(0));

    console.log(`[CadastrarUsuarioSuper][${label}] json=`, JSON.stringify(v));
    console.log(`[CadastrarUsuarioSuper][${label}] length=`, v.length);
    console.log(`[CadastrarUsuarioSuper][${label}] charCodes=`, codes.join(","));
  }

  /* ==========================================================
     Toast Universal
  ========================================================== */
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
    setTimeout(() => el.remove(), 180);
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

  function toastMsg(type, msg, title = "") {
    toast({ type, title, message: msg });
  }

  /* ==========================================================
     Loading
  ========================================================== */
  function setLoading(loading) {
    if (btnSalvar) {
      btnSalvar.disabled = !!loading;
      btnSalvar.classList.toggle("is-loading", !!loading);
      btnSalvar.innerHTML = loading
        ? `<i class="fa-solid fa-spinner fa-spin"></i> Salvando...`
        : `<i class="fa-solid fa-floppy-disk"></i> Salvar`;
    }

    [elNome, elEmail, elTelefone, elStatus, elSenha, elSenha2].forEach((el) => {
      if (el) el.disabled = !!loading;
    });
  }

  /* ==========================================================
     Errors
  ========================================================== */
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
    const field = form.querySelector(`#${fieldId}`);
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

    const field = form.querySelector(`#${fieldId}`);
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

  [elNome, elEmail, elTelefone, elStatus, elSenha, elSenha2].forEach(bindClearOnInput);

  /* ==========================================================
     Helpers
  ========================================================== */
  function normalizeSpaces(v) {
    return String(v || "").replace(/\s+/g, " ").trim();
  }

  function onlyDigits(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function sanitizeName(v) {
    return normalizeSpaces(v).replace(/[<>]/g, "");
  }

  function sanitizeEmail(v) {
    return normalizeSpaces(v).toLowerCase();
  }

  function sanitizePhone(v) {
    return onlyDigits(v).slice(0, 11);
  }

  function sanitizeStatus(v) {
    return normalizeSpaces(v).toLowerCase();
  }

  function getExactPasswordValue(inputEl) {
    return inputEl ? String(inputEl.value ?? "") : "";
  }

  function isValidEmail(email) {
    const v = String(email || "").trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
  }

  function hasInvisibleChars(v) {
    return /[\u0000-\u001F\u007F\u200B-\u200D\uFEFF]/.test(String(v || ""));
  }

  function isAllowedPasswordCharSet(v) {
    return /^[\x20-\x7E]+$/.test(String(v || ""));
  }

  function clearSensitiveFields() {
    if (elSenha) elSenha.value = "";
    if (elSenha2) elSenha2.value = "";
  }

  function closeModal() {
    const modal = document.getElementById("modalCadastrarUsuarioSuper");
    if (!modal) return;

    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("ativo", "aberto", "show");

    const backdrop = document.querySelector(".modal-backdrop");
    if (backdrop) backdrop.remove();

    document.body.classList.remove("modal-open", "no-scroll");
  }

  function buildSafePayload() {
    const rawSenha = getExactPasswordValue(elSenha);
    const rawSenha2 = getExactPasswordValue(elSenha2);

    return {
      nome: sanitizeName(elNome?.value || ""),
      email: sanitizeEmail(elEmail?.value || ""),
      telefone: sanitizePhone(elTelefone?.value || ""),
      status: sanitizeStatus(elStatus?.value || "ativo"),
      senha: rawSenha,
      senha2: rawSenha2,
      tipo_usuario: String(elTipoUsuario?.value || "super_admin"),
    };
  }

  /* ==========================================================
     Validate
  ========================================================== */
  function validatePayload(data) {
    clearErrors();
    let ok = true;

    const { nome, email, telefone, status, senha, senha2 } = data;

    if (!nome) {
      setFieldError("us_nome", "Informe o nome do usuário.");
      ok = false;
    } else if (nome.length < 3) {
      setFieldError("us_nome", "O nome deve ter no mínimo 3 caracteres.");
      ok = false;
    } else if (nome.length > 140) {
      setFieldError("us_nome", "O nome é muito longo.");
      ok = false;
    }

    if (!email) {
      setFieldError("us_email", "Informe o e-mail.");
      ok = false;
    } else if (email.length > 160) {
      setFieldError("us_email", "O e-mail é muito longo.");
      ok = false;
    } else if (!isValidEmail(email)) {
      setFieldError("us_email", "Informe um e-mail válido.");
      ok = false;
    }

    if (!telefone) {
      setFieldError("us_telefone", "Informe o telefone.");
      ok = false;
    } else if (telefone.length < 10 || telefone.length > 11) {
      setFieldError("us_telefone", "Informe um telefone válido com DDD.");
      ok = false;
    }

    if (!status) {
      setFieldError("us_status", "Selecione o status.");
      ok = false;
    } else if (!["ativo", "inativo", "bloqueado"].includes(status)) {
      setFieldError("us_status", "Status inválido.");
      ok = false;
    }

    if (!senha) {
      setFieldError("us_senha", "Informe a senha.");
      ok = false;
    } else if (senha.length < 6) {
      setFieldError("us_senha", "A senha deve ter no mínimo 6 caracteres.");
      ok = false;
    } else if (senha.length > 72) {
      setFieldError("us_senha", "A senha deve ter no máximo 72 caracteres.");
      ok = false;
    } else if (hasInvisibleChars(senha)) {
      setFieldError("us_senha", "A senha contém caracteres invisíveis inválidos.");
      ok = false;
    } else if (!isAllowedPasswordCharSet(senha)) {
      setFieldError("us_senha", "A senha contém caracteres inválidos.");
      ok = false;
    }

    if (!senha2) {
      setFieldError("us_senha2", "Confirme a senha.");
      ok = false;
    } else if (senha !== senha2) {
      setFieldError("us_senha2", "As senhas não coincidem.");
      ok = false;
    }

    if (!ok) {
      focusFirstError();
    }

    return ok;
  }

  /* ==========================================================
     Eventos auxiliares de diagnóstico
  ========================================================== */
  if (elSenha) {
    elSenha.addEventListener("input", () => {
      debugPassword("INPUT_senha", elSenha.value);
    });
  }

  if (elSenha2) {
    elSenha2.addEventListener("input", () => {
      debugPassword("INPUT_senha2", elSenha2.value);
    });
  }

  /* ==========================================================
     Submit
  ========================================================== */
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (isSubmitting) return;

    const data = buildSafePayload();

    debugLog("submit iniciado");
    debugPassword("SAFE_senha", data.senha);
    debugPassword("SAFE_senha2", data.senha2);

    if (!validatePayload(data)) {
      toastMsg("warning", "Revise os campos destacados.");
      return;
    }

    const payload = new URLSearchParams();
    payload.set("nome", data.nome);
    payload.set("email", data.email);
    payload.set("telefone", data.telefone);
    payload.set("status", data.status);
    payload.set("senha", data.senha);
    payload.set("tipo_usuario", "super_admin");

    debugPassword("POST_senha", payload.get("senha") || "");
    debugLog("POST nome =", JSON.stringify(payload.get("nome") || ""));
    debugLog("POST email =", JSON.stringify(payload.get("email") || ""));
    debugLog("POST telefone =", JSON.stringify(payload.get("telefone") || ""));
    debugLog("POST status =", JSON.stringify(payload.get("status") || ""));
    debugLog("POST tipo_usuario =", JSON.stringify(payload.get("tipo_usuario") || ""));

    isSubmitting = true;
    setLoading(true);

    try {
      const url = `${API_BASE}?path=${encodeURIComponent(API_PATH)}`;

      const resp = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          "X-Requested-With": "XMLHttpRequest"
        },
        body: payload.toString(),
        cache: "no-store",
        credentials: "same-origin",
      });

      const json = await resp.json().catch(() => null);

      debugLog("HTTP status =", resp.status);
      debugLog("Resposta JSON =", json);

      if (!resp.ok || !json || json.ok !== true) {
        if (json && json.fields && typeof json.fields === "object") {
          const mapPhpToJs = {
            nome: "us_nome",
            email: "us_email",
            telefone: "us_telefone",
            status: "us_status",
            senha: "us_senha",
            senha2: "us_senha2",
            senha_hash: "us_senha",
          };

          Object.entries(json.fields).forEach(([fieldId, msg]) => {
            const domId = mapPhpToJs[fieldId] || fieldId;
            setFieldError(String(domId), String(msg || ""));
          });

          focusFirstError();
        }

        clearSensitiveFields();

        toastMsg(
          "danger",
          (json && json.user_msg)
            ? json.user_msg
            : "Não foi possível cadastrar o super admin."
        );
        return;
      }

      toastMsg("success", json.user_msg || "Super admin cadastrado com sucesso.");

      form.reset();
      clearErrors();
      clearSensitiveFields();

      if (elStatus) {
        elStatus.value = "ativo";
      }

      closeModal();

      window.dispatchEvent(new CustomEvent("usuarioSuper:created", {
        detail: json.data || null
      }));

      setTimeout(() => {
        window.location.reload();
      }, 900);

    } catch (err) {
      debugLog("Erro fetch =", err);
      clearSensitiveFields();
      toastMsg("danger", "Falha de rede ao cadastrar o super admin.");
    } finally {
      isSubmitting = false;
      setLoading(false);
    }
  });
})();