/* ==========================================================
   PlanoCadastrar.js — SuperAdmin | Cadastrar Plano (AmAgenda)
   - Modal: #modalCadastrarPlano
   - Form:  #formCadastrarPlano
   - API:   POST /api/api_central.php?path=superadmin/plano/cadastrar
   - ✅ Usa CSS padrão:
       - Toast: .ui-toast-stack + .ui-alert
       - Campo erro: .modal-campo.erro + .msg-erro.ativo
   - ✅ Toast/Confirm MESMO padrão do deslogar.js
========================================================== */
(() => {
  "use strict";

  const API_BASE = "/api/api_central.php";
  const API_PATH = "superadmin/plano/cadastrar";

  const form = document.getElementById("formCadastrarPlano");
  if (!form) return;

  const btnSalvar = document.getElementById("btnSalvarPlano");

  // Campos
  const elNome = document.getElementById("p_nome");
  const elRef = document.getElementById("p_ref");
  const elPreco = document.getElementById("p_preco");
  const elCobranca = document.getElementById("p_cobranca");
  const elLimiteUsuarios = document.getElementById("p_limite_usuarios");
  const elLimiteProfissionais = document.getElementById("p_limite_profissionais");
  const elLimiteServicos = document.getElementById("p_limite_servicos");
  const elLimiteAgendamentos = document.getElementById("p_limite_agendamentos");
  const elDestaque = document.getElementById("p_destaque");
  const elStatus = document.getElementById("p_status");
  const elDescricao = document.getElementById("p_descricao");
  const elObs = document.getElementById("p_obs");

  // ==========================================================
  // ✅ Toast Universal — MESMO padrão do deslogar.js
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
    setTimeout(() => el.remove(), 180);
  }

  // Toast simples (info/success/warning/danger/neutral/confirm)
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

    $title.textContent =
      (title || "").trim() ||
      (type === "success" ? "Sucesso" :
       type === "warning" ? "Atenção" :
       type === "danger" ? "Erro" :
       type === "neutral" ? "Aviso" :
       type === "confirm" ? "Confirmação" : "Aviso");

    $msg.textContent = String(message ?? "").trim() || "—";

    // Ícone conforme tipo
    const $icon = wrap.querySelector(".ui-alert__icon");
    if ($icon) {
      $icon.textContent =
        type === "danger" ? "❌" :
        type === "success" ? "✅" :
        type === "warning" ? "⚠️" :
        type === "neutral" ? "💬" :
        type === "confirm" ? "ℹ️" :
        /* info */ "ℹ️";
    }

    $close?.addEventListener("click", () => closeToast(wrap));

    stack.appendChild(wrap);

    // auto-close
    if (timeout > 0) {
      setTimeout(() => closeToast(wrap), timeout);
    }

    return wrap;
  }

  // Confirm (igual do logout)
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

      $title.textContent = title;
      $msg.textContent = message;

      const $icon = wrap.querySelector(".ui-alert__icon");
      if ($icon) {
        $icon.textContent =
          type === "danger" ? "❌" :
          type === "success" ? "✅" :
          type === "warning" ? "⚠️" :
          type === "neutral" ? "💬" :
          /* info OU confirm */ "ℹ️";
      }

      function close(result) {
        wrap.classList.add("is-leaving");
        setTimeout(() => wrap.remove(), 180);
        resolve(result);
      }

      $ok.addEventListener("click", () => close(true));
      $cancel.addEventListener("click", () => close(false));

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

  // helper simples
  function toastMsg(type, msg, title = "") {
    toast({ type, title, message: msg });
  }

  // ==========================================================
  // Loading
  // ==========================================================
  function setLoading(loading) {
    if (!btnSalvar) return;
    btnSalvar.disabled = loading;
    btnSalvar.classList.toggle("is-loading", loading);
    btnSalvar.textContent = loading ? "Salvando..." : "Salvar";
  }

  // ==========================================================
  // ✅ Erros de campos (usa seu CSS)
  // - .modal-campo.erro no wrapper
  // - .msg-erro.ativo no <small>
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

    form.querySelectorAll(".modal-campo.erro").forEach((w) => w.classList.remove("erro"));
  }

  function setFieldError(fieldId, msg) {
    const small = getErrorSmall(fieldId);
    const field = document.getElementById(fieldId);
    const wrapper = getModalCampoWrapper(field, small);

    if (small) {
      small.textContent = msg || "";
      small.classList.toggle("ativo", !!msg);
    }
    if (wrapper) wrapper.classList.toggle("erro", !!msg);
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

  [
    elNome, elRef, elPreco, elCobranca, elLimiteUsuarios,
    elLimiteProfissionais, elLimiteServicos, elLimiteAgendamentos,
    elDestaque, elStatus, elDescricao, elObs
  ].forEach(bindClearOnInput);

  // ==========================================================
  // Helpers
  // ==========================================================
  function normalizeMoneyBR(raw) {
    raw = (raw || "").trim();
    if (!raw) return "";
    raw = raw.replace(/[^\d,\.]/g, "");
    if (raw.includes(",") && raw.includes(".")) {
      raw = raw.replace(/\./g, "").replace(",", ".");
      return raw;
    }
    if (raw.includes(",") && !raw.includes(".")) {
      raw = raw.replace(",", ".");
      return raw;
    }
    return raw;
  }

  // ==========================================================
  // Validate
  // ==========================================================
  function validate() {
    clearErrors();
    let ok = true;

    const nome = (elNome?.value || "").trim();
    if (!nome) {
      setFieldError("p_nome", "Informe o nome do plano.");
      ok = false;
    }

    const precoStr = normalizeMoneyBR(elPreco?.value || "");
    if (!precoStr || !/^\d+(\.\d{1,2})?$/.test(precoStr)) {
      setFieldError("p_preco", "Preço inválido. Ex: 49,90");
      ok = false;
    }

    const cobranca = (elCobranca?.value || "").trim();
    if (!cobranca) {
      setFieldError("p_cobranca", "Selecione a cobrança.");
      ok = false;
    }

    const limiteUsuarios = parseInt(elLimiteUsuarios?.value || "0", 10);
    if (!Number.isFinite(limiteUsuarios) || limiteUsuarios < 1) {
      setFieldError("p_limite_usuarios", "O limite de usuários deve ser no mínimo 1.");
      ok = false;
    }

    const lp = elLimiteProfissionais?.value;
    if (lp !== "" && parseInt(lp, 10) < 0) {
      setFieldError("p_limite_profissionais", "Não pode ser negativo.");
      ok = false;
    }

    const ls = elLimiteServicos?.value;
    if (ls !== "" && parseInt(ls, 10) < 0) {
      setFieldError("p_limite_servicos", "Não pode ser negativo.");
      ok = false;
    }

    const la = elLimiteAgendamentos?.value;
    if (la !== "" && parseInt(la, 10) < 0) {
      setFieldError("p_limite_agendamentos", "Não pode ser negativo.");
      ok = false;
    }

    if (!ok) focusFirstError();
    return ok;
  }

  // ==========================================================
  // Submit
  // ==========================================================
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (!validate()) {
      toastMsg("warning", "Revise os campos destacados.");
      return;
    }

    const payload = new URLSearchParams();
    payload.set("nome", (elNome.value || "").trim());
    payload.set("ref", (elRef.value || "").trim());
    payload.set("preco", (elPreco.value || "").trim());
    payload.set("cobranca", (elCobranca.value || "").trim());
    payload.set("limite_usuarios", String(parseInt(elLimiteUsuarios.value || "0", 10)));

    payload.set("limite_profissionais", String(parseInt(elLimiteProfissionais?.value || "0", 10) || 0));
    payload.set("limite_servicos", String(parseInt(elLimiteServicos?.value || "0", 10) || 0));
    payload.set("limite_agendamentos", String(parseInt(elLimiteAgendamentos?.value || "0", 10) || 0));

    payload.set("destaque", String(parseInt(elDestaque?.value || "0", 10) || 0));
    payload.set("status", (elStatus?.value || "ativo").trim());
    payload.set("descricao", (elDescricao?.value || "").trim());
    payload.set("obs", (elObs?.value || "").trim());

    setLoading(true);

    try {
      const url = `${API_BASE}?path=${encodeURIComponent(API_PATH)}`;

      const resp = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: payload.toString(),
      });

      const json = await resp.json().catch(() => null);

      if (!resp.ok || !json || json.ok !== true) {
        if (json && json.fields && typeof json.fields === "object") {
          Object.entries(json.fields).forEach(([fieldId, msg]) => {
            setFieldError(String(fieldId), String(msg || ""));
          });
          focusFirstError();
        }

        toastMsg("danger", (json && json.user_msg) ? json.user_msg : "Não foi possível cadastrar o plano.");
        return;
      }

      toastMsg("success", json.user_msg || "Plano cadastrado com sucesso.");

      form.reset();
      clearErrors();

      const modal = document.getElementById("modalCadastrarPlano");
      if (modal) {
        modal.setAttribute("aria-hidden", "true");
        modal.classList.remove("aberto");
      }

      window.dispatchEvent(new CustomEvent("plano:created", { detail: json.data || null }));

      // ✅ Atualiza a página, mas deixa a mensagem aparecer
      setTimeout(() => window.location.reload(), 900);

    } catch (err) {
      toastMsg("danger", "Falha de rede ao cadastrar o plano.");
    } finally {
      setLoading(false);
    }
  });

  // ==========================================================
  // UX: mascara leve no preço
  // ==========================================================
  if (elPreco) {
    elPreco.addEventListener("blur", () => {
      const v = normalizeMoneyBR(elPreco.value || "");
      if (!v || !/^\d+(\.\d{1,2})?$/.test(v)) return;
      const n = Number(v);
      if (!Number.isFinite(n)) return;
      elPreco.value = n.toLocaleString("pt-BR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      });
    });
  }

  // (Opcional) expor confirm caso queira usar em outros pontos
  // window.toastConfirm = toastConfirm;

})();