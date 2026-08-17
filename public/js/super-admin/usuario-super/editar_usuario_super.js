/* ==========================================================
   editar_usuario_super.js — SuperAdmin | Editar Super Admin
   ✅ Modal: #modalEditarUsuarioSuper
   ✅ Form:  #formEditarUsuarioSuper
   ✅ API:   POST /public/api/api_central.php?path=superadmin/usuario/editar-super
   ✅ PADRÃO IGUAL AO CadastrarUsuarioSuper.js:
      - Toast: .ui-toast-stack + .ui-alert
      - Campo erro: .modal-campo.erro + .msg-erro.ativo
      - Envia via FormData / $_POST
      - Não envia JSON
   ✅ Atualiza senha SOMENTE se preenchida
   ✅ tipo_usuario visual fixo = super_admin
   ✅ Dispara evento: usuario-super:editado
========================================================== */
(() => {
  "use strict";

  if (window.__EDITAR_USUARIO_SUPER_JS_INIT__) {
    console.warn("[EditarUsuarioSuper] Script já inicializado.");
    return;
  }
  window.__EDITAR_USUARIO_SUPER_JS_INIT__ = true;

  const API_URL = "/public/api/api_central.php?path=superadmin/usuario/editar-super";
  const DEBUG = false;

  const MODAL_ID = "modalEditarUsuarioSuper";
  const FORM_ID = "formEditarUsuarioSuper";
  const BTN_ID = "btnSalvarEdicaoUsuarioSuper";

  const modal = document.getElementById(MODAL_ID);
  const form = document.getElementById(FORM_ID);
  const btnSubmit = document.getElementById(BTN_ID);

  if (!modal || !form || !btnSubmit) {
    console.warn("[EditarUsuarioSuper] Modal/Form/Botão não encontrados.");
    return;
  }

  const els = {
    id_usuario: form.querySelector("#eus_id_usuario"),
    nome: form.querySelector("#eus_nome"),
    email: form.querySelector("#eus_email"),
    telefone: form.querySelector("#eus_telefone"),
    status: form.querySelector("#eus_status"),
    senha: form.querySelector("#eus_senha"),
    senha2: form.querySelector("#eus_senha2"),
  };

  let isSubmitting = false;

  /* ==========================================================
     Debug
  ========================================================== */
  function debugLog(...args) {
    if (!DEBUG) return;
    console.log("[EditarUsuarioSuper]", ...args);
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

  function clearFieldErrorById(fieldId) {
    const small = getErrorSmall(fieldId);
    const field = form.querySelector(`#${fieldId}`);
    const wrapper = getModalCampoWrapper(field, small);

    if (small) {
      small.textContent = "";
      small.classList.remove("ativo");
    }

    if (wrapper) {
      wrapper.classList.remove("erro");
    }
  }

  function clearAllErrors() {
    form.querySelectorAll(".msg-erro").forEach((s) => {
      s.textContent = "";
      s.classList.remove("ativo");
      const wrapper = s.closest(".modal-campo");
      if (wrapper) wrapper.classList.remove("erro");
    });

    form.querySelectorAll(".modal-campo.erro").forEach((wrapper) => {
      wrapper.classList.remove("erro");
    });
  }

  function setFieldErrorById(fieldId, message) {
    const small = getErrorSmall(fieldId);
    const field = form.querySelector(`#${fieldId}`);
    const wrapper = getModalCampoWrapper(field, small);

    if (small) {
      small.textContent = String(message || "");
      small.classList.toggle("ativo", !!message);
    }

    if (wrapper) {
      wrapper.classList.toggle("erro", !!message);
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

    const handler = () => clearFieldErrorById(fieldEl.id);
    fieldEl.addEventListener("input", handler);
    fieldEl.addEventListener("change", handler);
    fieldEl.addEventListener("blur", handler);
  }

  Object.values(els).forEach(bindClearOnInput);

  /* ==========================================================
     Helpers
  ========================================================== */
  function firstFilled(...values) {
    for (const v of values) {
      if (v !== null && v !== undefined && String(v).trim() !== "") {
        return String(v);
      }
    }
    return "";
  }

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
    if (els.senha) els.senha.value = "";
    if (els.senha2) els.senha2.value = "";
  }

  function setLoading(loading) {
    btnSubmit.disabled = !!loading;
    btnSubmit.classList.toggle("is-loading", !!loading);
    form.classList.toggle("is-loading", !!loading);

    btnSubmit.innerHTML = loading
      ? `<i class="fa-solid fa-spinner fa-spin"></i> Salvando...`
      : `<i class="fa-solid fa-floppy-disk"></i> Salvar`;

    [els.nome, els.email, els.telefone, els.status, els.senha, els.senha2].forEach((el) => {
      if (el) el.disabled = !!loading;
    });
  }

  function closeModal() {
    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("ativo", "aberto", "show");

    const backdrop = document.querySelector(".modal-backdrop");
    if (backdrop) backdrop.remove();

    document.body.classList.remove("modal-open", "no-scroll");
  }

  function resetFormState() {
    form.reset();
    clearAllErrors();
    clearSensitiveFields();
  }

  function buildPayloadData() {
    return {
      id_usuario: String(els.id_usuario?.value || "").trim(),
      nome: sanitizeName(els.nome?.value || ""),
      email: sanitizeEmail(els.email?.value || ""),
      telefone: sanitizePhone(els.telefone?.value || ""),
      status: sanitizeStatus(els.status?.value || ""),
      senha: String(els.senha?.value || ""),
      senha2: String(els.senha2?.value || ""),
    };
  }

  /* ==========================================================
     Validação
  ========================================================== */
  function validateForm(data) {
    clearAllErrors();
    let ok = true;

    const { id_usuario, nome, email, telefone, status, senha, senha2 } = data;

    if (!id_usuario) {
      toastMsg("danger", "ID do Super Admin não informado.");
      ok = false;
    }

    if (!nome) {
      setFieldErrorById("eus_nome", "Informe o nome.");
      ok = false;
    } else if (nome.length < 3) {
      setFieldErrorById("eus_nome", "O nome deve ter no mínimo 3 caracteres.");
      ok = false;
    } else if (nome.length > 140) {
      setFieldErrorById("eus_nome", "O nome é muito longo.");
      ok = false;
    }

    if (!email) {
      setFieldErrorById("eus_email", "Informe o e-mail.");
      ok = false;
    } else if (email.length > 160) {
      setFieldErrorById("eus_email", "O e-mail é muito longo.");
      ok = false;
    } else if (!isValidEmail(email)) {
      setFieldErrorById("eus_email", "Informe um e-mail válido.");
      ok = false;
    }

    if (telefone) {
      if (telefone.length < 10 || telefone.length > 11) {
        setFieldErrorById("eus_telefone", "Informe um telefone válido com DDD.");
        ok = false;
      }
    }

    if (!status) {
      setFieldErrorById("eus_status", "Selecione o status.");
      ok = false;
    } else if (!["ativo", "inativo", "bloqueado"].includes(status)) {
      setFieldErrorById("eus_status", "Status inválido.");
      ok = false;
    }

    if (senha || senha2) {
      if (!senha) {
        setFieldErrorById("eus_senha", "Informe a nova senha.");
        ok = false;
      } else if (senha.length < 6) {
        setFieldErrorById("eus_senha", "A senha deve ter no mínimo 6 caracteres.");
        ok = false;
      } else if (senha.length > 72) {
        setFieldErrorById("eus_senha", "A senha deve ter no máximo 72 caracteres.");
        ok = false;
      } else if (hasInvisibleChars(senha)) {
        setFieldErrorById("eus_senha", "A senha contém caracteres invisíveis inválidos.");
        ok = false;
      } else if (!isAllowedPasswordCharSet(senha)) {
        setFieldErrorById("eus_senha", "A senha contém caracteres inválidos.");
        ok = false;
      }

      if (!senha2) {
        setFieldErrorById("eus_senha2", "Confirme a nova senha.");
        ok = false;
      } else if (senha !== senha2) {
        setFieldErrorById("eus_senha2", "As senhas não coincidem.");
        ok = false;
      }
    }

    if (!ok) {
      focusFirstError();
    }

    return ok;
  }

  /* ==========================================================
     Erros vindos do backend
  ========================================================== */
  function applyServerFieldErrors(fields) {
    if (!fields || typeof fields !== "object") return;

    const mapPhpToJs = {
      id_usuario: "eus_id_usuario",
      nome: "eus_nome",
      email: "eus_email",
      telefone: "eus_telefone",
      status: "eus_status",
      senha: "eus_senha",
      senha2: "eus_senha2",
      senha_hash: "eus_senha",
    };

    Object.entries(fields).forEach(([fieldId, msg]) => {
      const domId = mapPhpToJs[fieldId] || fieldId;
      setFieldErrorById(String(domId), String(msg || ""));
    });

    focusFirstError();
  }

  /* ==========================================================
     Submit
  ========================================================== */
  async function submitForm(event) {
    event.preventDefault();

    if (isSubmitting) return;

    const data = buildPayloadData();
    debugLog("submit iniciado", data);

    if (!validateForm(data)) {
      toastMsg("warning", "Revise os campos destacados.");
      return;
    }

    const fd = new FormData();
    fd.append("id_usuario", data.id_usuario);
    fd.append("nome", data.nome);
    fd.append("email", data.email);
    fd.append("telefone", data.telefone);
    fd.append("status", data.status);

    if (data.senha !== "" || data.senha2 !== "") {
      fd.append("senha", data.senha);
      fd.append("senha2", data.senha2);
    }

    isSubmitting = true;
    setLoading(true);

    try {
      const resp = await fetch(API_URL, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
        cache: "no-store",
      });

      const json = await resp.json().catch(() => null);

      debugLog("HTTP status =", resp.status);
      debugLog("Resposta JSON =", json);

      if (!resp.ok || !json || json.ok !== true) {
        if (json?.fields && typeof json.fields === "object") {
          applyServerFieldErrors(json.fields);
        }

        clearSensitiveFields();

        toastMsg(
          "danger",
          json?.user_msg ||
          json?.message ||
          `Falha ao salvar edição. STATUS: ${resp.status}`
        );
        return;
      }

      toastMsg(
        "success",
        json?.user_msg || "Super Admin atualizado com sucesso."
      );

window.dispatchEvent(
  new CustomEvent("usuario-super:editado", {
    detail: json?.data || null,
  })
);

resetFormState();
closeModal();

/* ✅ NOVO: delay + reload */
setTimeout(() => {
  window.location.reload();
}, 1200); // pode ajustar: 900, 1500, 2000

      window.dispatchEvent(
        new CustomEvent("usuario-super:editado", {
          detail: json?.data || null,
        })
      );

      resetFormState();
      closeModal();
    } catch (error) {
      console.error("[EditarUsuarioSuper] Erro:", error);
      clearSensitiveFields();
      toastMsg("danger", "Erro de comunicação com o servidor.");
    } finally {
      isSubmitting = false;
      setLoading(false);
    }
  }

  form.addEventListener("submit", submitForm);

  /* ==========================================================
     Fechamento local do modal
  ========================================================== */
  modal.addEventListener("click", (e) => {
    if (e.target && e.target.hasAttribute("data-fechar-modal")) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && modal.getAttribute("aria-hidden") !== "true") {
      closeModal();
    }
  });

  /* ==========================================================
     API pública para preencher/abrir o modal
  ========================================================== */
  window.EditarUsuarioSuperModal = {
    abrir(dados = {}) {
      clearAllErrors();
      form.reset();

      if (els.id_usuario) els.id_usuario.value = firstFilled(dados.id_usuario, dados.id, "");
      if (els.nome) els.nome.value = firstFilled(dados.nome, "");
      if (els.email) els.email.value = firstFilled(dados.email, "");
      if (els.telefone) els.telefone.value = firstFilled(dados.telefone, "");
      if (els.status) els.status.value = firstFilled(dados.status, "ativo");
      if (els.senha) els.senha.value = "";
      if (els.senha2) els.senha2.value = "";

      modal.setAttribute("aria-hidden", "false");
      modal.classList.add("ativo", "aberto", "show");
      document.body.classList.add("modal-open");

      setTimeout(() => {
        els.nome?.focus();
      }, 60);
    },

    fechar() {
      closeModal();
    },
  };
})();