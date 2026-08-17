/* ==========================================================
   altear_status_universal.js
   ✅ Toggle de status UNIVERSAL
   ✅ Mesmo padrão visual e estrutural do CadastrarEmpresa.js
   ✅ Toast: .ui-toast-stack + .ui-alert
   ✅ Confirm: mesmo padrão visual do deslogar.js / CadastrarEmpresa.js
   ✅ Envia via application/x-www-form-urlencoded
   ✅ Compatível com menu flutuante
   ✅ Prioriza dados no próprio botão
   ✅ Reload só acontece depois que a mensagem some
========================================================== */
(() => {
  "use strict";

  if (window.__ALTERAR_STATUS_UNIVERSAL_JS_INIT__) {
    console.warn("[ToggleStatusUniversal] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__ALTERAR_STATUS_UNIVERSAL_JS_INIT__ = true;

  const TOAST_DEFAULT_TIMEOUT = 3500;
  const TOAST_LEAVE_TIME = 180;

  const MAP = {

    //======================
    //PAINEL SUPER ADMIN
    //=====================

    //EMPRESA
    tabela_empresa: {
      endpoint: "/public/api/api_central.php?path=superadmin/empresa/alterar-status",
      idPayload: "id_empresa",
      label: "empresa",
    },

    //USUARIO
    tabela_usuario: {
      endpoint: "/public/api/api_central.php?path=superadmin/usuario/alterar-status",
      idPayload: "id_usuario",
      label: "usuário",
    },

    //USUARIO SUPER
    tabela_usuario_super: {
      endpoint: "/public/api/api_central.php?path=superadmin/usuario/alterar-status-super",
      idPayload: "id_usuario",
      label: "super admin",
    },

    //PLANO
    tabela_plano: {
      endpoint: "/public/api/api_central.php?path=superadmin/plano/alterar-status",
      idPayload: "id_plano",
      label: "plano",
    },

    //======================
    //PAINEL ADMINISTRATIVO
    //=====================

    //CLIENTE
    tabela_cliente: {
      endpoint: "/public/api/api_central.php?path=painel/cliente/alterar-status",
      idPayload: "id_cliente",
      label: "cliente",
    },

    //USUARIO (FUNCIONARIO)
    tabela_usuario_painel: {
      endpoint: "/public/api/api_central.php?path=painel/usuario/alterar-status",
      idPayload: "id_usuario",
      label: "usuário",
    },

  };
  

  const ativoValues = ["ativo", "1", "true", "sim", "atv"];
  let reloadTimer = null;

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

      let closed = false;

      function close(result) {
        if (closed) return;
        closed = true;
        document.removeEventListener("keydown", onKey);
        wrap.classList.add("is-leaving");
        setTimeout(() => wrap.remove(), TOAST_LEAVE_TIME);
        resolve(result);
      }

      const onKey = (e) => {
        if (e.key === "Escape") {
          close(false);
        }
      };

      $ok?.addEventListener("click", () => close(true));
      $cancel?.addEventListener("click", () => close(false));
      document.addEventListener("keydown", onKey);

      stack.appendChild(wrap);
      setTimeout(() => $ok?.focus?.(), 0);
    });
  }

  function toastMsg(type, msg, title = "", timeout = TOAST_DEFAULT_TIMEOUT) {
    toast({ type, title, message: msg, timeout });
  }

  function agendarReloadPagina(delayMs) {
    if (reloadTimer) {
      clearTimeout(reloadTimer);
      reloadTimer = null;
    }

    reloadTimer = setTimeout(() => {
      window.location.reload();
    }, Math.max(0, Number(delayMs) || 0));
  }

  // ==========================================================
  // Helpers
  // ==========================================================
  function normaliza(txt) {
    return String(txt ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .trim();
  }

  function isAtivo(status) {
    return ativoValues.includes(normaliza(status));
  }

  function getContexto(btn) {
    // 1) PRIORIDADE: dados no próprio botão
    const scopeBtn = btn.dataset.scope || "";
    const idBtn = btn.dataset.id || "";
    const statusBtn = btn.dataset.status || "";

    if (scopeBtn && idBtn) {
      return {
        scope: scopeBtn,
        id: idBtn,
        status: statusBtn,
        source: "button",
      };
    }

    // 2) fallback: card
    const card = btn.closest(".agenda-card");
    if (card) {
      const scopeEl = card.closest("[data-toggle-scope]");
      return {
        scope: scopeEl?.getAttribute("data-toggle-scope") || "",
        id: card.getAttribute("data-id") || "",
        status: card.getAttribute("data-status") || "",
        source: "card",
      };
    }

    // 3) fallback: tabela
    const tr = btn.closest("tr");
    if (tr) {
      const table = tr.closest("table");
      return {
        scope: table?.id || "",
        id: tr.getAttribute("data-id") || "",
        status: tr.getAttribute("data-status") || "",
        source: "tr",
      };
    }

    return null;
  }

  function setButtonLoading(btn, loading, ativoAgora) {
    if (!btn) return;

    if (!btn.dataset.originalText) {
      btn.dataset.originalText = btn.textContent.trim();
    }

    btn.disabled = !!loading;
    btn.classList.toggle("is-loading", !!loading);

    if (loading) {
      btn.textContent = ativoAgora ? "Inativando..." : "Ativando...";
      return;
    }

    btn.textContent = btn.dataset.originalText || "Alterar status";
  }

  async function toggleStatus(cfg, id) {
    const body = new URLSearchParams();
    body.set(cfg.idPayload, id);

    const resp = await fetch(cfg.endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json",
      },
      body: body.toString(),
      credentials: "same-origin",
      cache: "no-store",
    });

    const raw = await resp.text();

    let data = null;
    try {
      data = raw ? JSON.parse(raw) : null;
    } catch (_) {
      data = null;
    }

    console.log("[ToggleStatusUniversal] STATUS:", resp.status);
    console.log("[ToggleStatusUniversal] RAW:", raw);
    console.log("[ToggleStatusUniversal] JSON:", data);

    if (!resp.ok || !data || data.ok !== true) {
      const err = new Error("STATUS_ERROR");
      err.http = resp.status;
      err.code = data?.code || "UNKNOWN";
      err.data = data;
      err.raw = raw;
      throw err;
    }

    return data;
  }

  function mensagemErro(cfg, err) {
    const apiMsg = String(err?.data?.user_msg || "").trim();
    if (apiMsg) return apiMsg;

    if (err?.code === "INVALID_ID") {
      return "Registro inválido.";
    }

    if (err?.code === "NOT_FOUND") {
      return `${cfg.label.charAt(0).toUpperCase() + cfg.label.slice(1)} não encontrado.`;
    }

    if (err?.code === "BLOCKED") {
      return `${cfg.label.charAt(0).toUpperCase() + cfg.label.slice(1)} bloqueado não pode ter o status alterado.`;
    }

    if (err?.code === "UPDATE_ERROR") {
      return `Não foi possível alterar o status do ${cfg.label}.`;
    }

    if (err?.code === "HANDLER_NOT_FOUND") {
      return "Handler não encontrado para esta ação.";
    }

    return `Não foi possível alterar o status do ${cfg.label}.`;
  }

  function mensagemSucesso(cfg, data, ativoAgora) {
    const apiMsg = String(data?.user_msg || "").trim();
    if (apiMsg) return apiMsg;

    return ativoAgora
      ? `${cfg.label.charAt(0).toUpperCase() + cfg.label.slice(1)} inativado com sucesso.`
      : `${cfg.label.charAt(0).toUpperCase() + cfg.label.slice(1)} ativado com sucesso.`;
  }

  // ==========================================================
  // Click universal
  // ==========================================================
  document.addEventListener("click", async (e) => {
    const btn = e.target.closest('button[data-acao="toggle-status"], button[data-action="toggle-status"]');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    if (btn.disabled) return;

    const ctx = getContexto(btn);
    if (!ctx) {
      console.warn("[ToggleStatusUniversal] contexto não encontrado");
      toastMsg("warning", "Não foi possível identificar o registro para alterar o status.");
      return;
    }

    const cfg = MAP[ctx.scope];
    if (!cfg) {
      console.warn("[ToggleStatusUniversal] scope sem configuração:", ctx.scope);
      toastMsg("warning", "Ação de status não configurada para este item.");
      return;
    }

    if (!ctx.id) {
      console.warn("[ToggleStatusUniversal] id não encontrado");
      toastMsg("warning", "Registro inválido para alteração de status.");
      return;
    }

    const ativoAgora = isAtivo(ctx.status);

    const confirmou = await toastConfirm({
      title: "Confirmação",
      message: ativoAgora
        ? `Deseja inativar este ${cfg.label}?`
        : `Deseja ativar este ${cfg.label}?`,
      type: "confirm",
      confirmText: ativoAgora ? "Inativar" : "Ativar",
      cancelText: "Cancelar",
    });

    if (!confirmou) return;

    setButtonLoading(btn, true, ativoAgora);

    try {
      const data = await toggleStatus(cfg, ctx.id);

      document.dispatchEvent(
        new CustomEvent("status:alterado", {
          detail: {
            scope: ctx.scope,
            id: ctx.id,
            statusAnterior: ctx.status,
            response: data,
          },
        })
      );

      toastMsg(
        "success",
        mensagemSucesso(cfg, data, ativoAgora)
      );

      const tempoReload = TOAST_DEFAULT_TIMEOUT + TOAST_LEAVE_TIME + 120;
      agendarReloadPagina(tempoReload);

    } catch (err) {
      console.error("[ToggleStatusUniversal]", err);
      console.error("[ToggleStatusUniversal][RAW]", err?.raw);

      toastMsg(
        "danger",
        mensagemErro(cfg, err)
      );
    } finally {
      setButtonLoading(btn, false, ativoAgora);
    }
  }, true);

  // ==========================================================
  // API pública opcional
  // ==========================================================
  window.ToggleStatusUniversal = {
    toastConfirm,
  };
})();