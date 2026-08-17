/* ==========================================================
   cadastrar_cliente.js — Modal Novo Cliente (AmAgenda)
   ✅ Compatível com HTML atual
   ✅ API:
      POST /public/api/api_central.php?path=painel/cliente/cadastrar
   ✅ Envia:
      - nome
      - telefone
      - email
      - observacao
      - status
   ✅ Padrão visual:
      - Toast: .ui-toast-stack + .ui-alert
      - Campo erro: .modal-campo.erro + .msg-erro.ativo
   ✅ Após sucesso:
      - Limpa formulário
      - Fecha modal
      - Dispara evento
      - Recarrega a página após o toast
========================================================== */
(() => {
  "use strict";

  if (window.__CADASTRAR_CLIENTE_JS_INIT__) {
    console.warn("[CadastrarCliente] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__CADASTRAR_CLIENTE_JS_INIT__ = true;

  const API_BASE = "/public/api/api_central.php";
  const API_PATH = "painel/cliente/cadastrar";
  const SUCCESS_TOAST_TIME = 3500;

  const FORM_ID = "formNovoCliente";
  const MODAL_ID = "modalNovoCliente";
  const BTN_ID = "btnSalvarCliente";

  const CAMPO_NOME = "cli_nome";
  const CAMPO_TELEFONE = "cli_telefone";
  const CAMPO_EMAIL = "cli_email";
  const CAMPO_STATUS = "cli_status";
  const CAMPO_OBS = "cli_obs";

  const form = document.getElementById(FORM_ID);
  if (!form) {
    console.warn("[CadastrarCliente] Formulário #formNovoCliente não encontrado.");
    return;
  }

  const modal = document.getElementById(MODAL_ID);
  const btnSalvar = document.getElementById(BTN_ID);
  const $ = (id) => document.getElementById(id);

  // ==========================================================
  // CAMPOS
  // ==========================================================
  const elNome = $(CAMPO_NOME);
  const elTelefone = $(CAMPO_TELEFONE);
  const elEmail = $(CAMPO_EMAIL);
  const elStatus = $(CAMPO_STATUS);
  const elObs = $(CAMPO_OBS);

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
    btnSalvar.textContent = loading ? "Salvando..." : "Salvar Cliente";
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

    const aliases = {
      cli_nome: ["cli_nome"],
      nome: ["cli_nome"],
      nome_completo: ["cli_nome"],

      cli_telefone: ["cli_telefone"],
      telefone: ["cli_telefone"],
      whatsapp_celular: ["cli_telefone"],
      celular: ["cli_telefone"],

      cli_email: ["cli_email"],
      email: ["cli_email"],

      cli_status: ["cli_status"],
      status: ["cli_status"],

      cli_obs: ["cli_obs"],
      observacao: ["cli_obs"],
      obs: ["cli_obs"],
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

  [elNome, elTelefone, elEmail, elStatus, elObs].forEach(bindClearOnInput);

  // ==========================================================
  // HELPERS
  // ==========================================================
  function isEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || "").trim());
  }

  function normalizePhoneDigits(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function applyPhoneMask(v) {
    const digits = normalizePhoneDigits(v).slice(0, 11);

    if (digits.length <= 10) {
      return digits
        .replace(/^(\d{2})(\d)/, "($1) $2")
        .replace(/(\d{4})(\d)/, "$1-$2");
    }

    return digits
      .replace(/^(\d{2})(\d)/, "($1) $2")
      .replace(/(\d{5})(\d)/, "$1-$2");
  }

  function closeModal() {
    if (!modal) return;

    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("aberto", "ativo", "abrir");
    document.body.classList.remove("modal-open", "body-travado", "sem-scroll", "modal-aberto");

    const backdrop = document.querySelector(".modal-backdrop");
    if (backdrop && backdrop.dataset.modal === MODAL_ID) {
      backdrop.remove();
    }
  }

  if (elTelefone) {
    elTelefone.addEventListener("input", () => {
      elTelefone.value = applyPhoneMask(elTelefone.value);
    });
  }

  // ==========================================================
  // VALIDAÇÃO
  // ==========================================================
  function validate() {
    clearErrors();

    let ok = true;

    const nome = (elNome?.value || "").trim();
    const telefoneRaw = (elTelefone?.value || "").trim();
    const telefoneDigits = normalizePhoneDigits(telefoneRaw);
    const email = (elEmail?.value || "").trim().toLowerCase();
    const status = (elStatus?.value || "ativo").trim().toLowerCase();
    const observacao = (elObs?.value || "").trim();

    if (!nome) {
      setFieldError(CAMPO_NOME, "Informe o nome do cliente.");
      ok = false;
    } else if (nome.length < 3) {
      setFieldError(CAMPO_NOME, "O nome deve ter no mínimo 3 caracteres.");
      ok = false;
    } else if (nome.length > 140) {
      setFieldError(CAMPO_NOME, "O nome deve ter no máximo 140 caracteres.");
      ok = false;
    }

    if (!telefoneRaw) {
      setFieldError(CAMPO_TELEFONE, "Informe o telefone (WhatsApp).");
      ok = false;
    } else if (telefoneDigits.length < 10 || telefoneDigits.length > 13) {
      setFieldError(CAMPO_TELEFONE, "Telefone inválido. Informe DDD + número.");
      ok = false;
    }

    if (email) {
      if (!isEmail(email)) {
        setFieldError(CAMPO_EMAIL, "E-mail inválido.");
        ok = false;
      } else if (email.length > 160) {
        setFieldError(CAMPO_EMAIL, "O e-mail deve ter no máximo 160 caracteres.");
        ok = false;
      }
    }

    if (observacao.length > 220) {
      setFieldError(CAMPO_OBS, "A observação deve ter no máximo 220 caracteres.");
      ok = false;
    }

    if (!status) {
      setFieldError(CAMPO_STATUS, "Selecione o status.");
      ok = false;
    } else if (!["ativo", "inativo", "bloqueado"].includes(status)) {
      setFieldError(CAMPO_STATUS, "Status inválido.");
      ok = false;
    }

    if (!ok) {
      focusFirstError();
      return null;
    }

    return {
      nome,
      telefone: telefoneRaw,
      email,
      observacao,
      status,
    };
  }

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
          json?.user_msg || "Não foi possível cadastrar o cliente."
        );
        return;
      }

      toastMsg(
        "success",
        json?.user_msg || "Cliente cadastrado com sucesso.",
        "",
        SUCCESS_TOAST_TIME
      );

      form.reset();
      clearErrors();

      if (elStatus) {
        elStatus.value = "ativo";
      }

      closeModal();

      window.dispatchEvent(new CustomEvent("cliente:cadastrado", {
        detail: json?.data || null
      }));

      setTimeout(() => {
        window.location.reload();
      }, SUCCESS_TOAST_TIME + 220);

    } catch (err) {
      console.error("[CadastrarCliente] Erro no cadastro:", err);
      toastMsg("danger", "Falha de rede ao cadastrar o cliente.");
    } finally {
      setLoading(false);
    }
  });
})();