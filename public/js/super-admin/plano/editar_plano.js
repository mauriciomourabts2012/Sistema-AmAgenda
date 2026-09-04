/* ==========================================================
   editar_plano.js — SuperAdmin | Editar Plano
   ✅ Modal: #modalEditarPlano
   ✅ Form:  #formEditarPlano
   ✅ API:   POST /public/api/api_central.php?path=superadmin/plano/editar
   ✅ PADRÃO IGUAL AO editar_empresa.js / CadastrarEmpresa.js:
      - Toast: .ui-toast-stack + .ui-alert
      - Campo erro: .modal-campo.erro + .msg-erro.ativo
      - Envia via FormData / $_POST
      - Não envia JSON manualmente
   ✅ Após sucesso:
      - fecha modal
      - dispara evento plano:editado
      - mostra mensagem de sucesso
      - só depois atualiza a lista/página
========================================================== */
(() => {
  "use strict";

  if (window.__EDITAR_PLANO_JS_INIT__) {
    console.warn("[EditarPlano] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__EDITAR_PLANO_JS_INIT__ = true;

  const API_BASE = "/public/api/api_central.php";
  const API_PATH = "superadmin/plano/editar";
  const API_URL = `${API_BASE}?path=${encodeURIComponent(API_PATH)}`;

  const TOAST_DEFAULT_TIMEOUT = 3500;
  const TOAST_LEAVE_TIME = 180;

  // ==========================================================
  // DOM
  // ==========================================================
  const modal = document.getElementById("modalEditarPlano");
  const form = document.getElementById("formEditarPlano");
  const btnSalvar = document.getElementById("btnSalvarPlanoEdit");
  const vagasAdministrativas = document.getElementById("e_vagas_administrativas");

  if (!modal || !form || !btnSalvar) {
    console.warn("[EditarPlano] Elementos do modal não encontrados.");
    return;
  }

  const campos = {
    id_plano: document.getElementById("e_plano_id"),
    nome: document.getElementById("e_nome"),
    ref: document.getElementById("e_ref"),
    preco: document.getElementById("e_preco"),
    cobranca: document.getElementById("e_cobranca"),
    limite_usuarios: document.getElementById("e_limite_usuarios"),
    limite_proprietarios: document.getElementById("e_limite_proprietarios"),
    limite_profissionais: document.getElementById("e_limite_profissionais"),
    limite_recepcionistas: document.getElementById("e_limite_recepcionistas"),
    limite_servicos: document.getElementById("e_limite_servicos"),
    limite_agendamentos: document.getElementById("e_limite_agendamentos"),
    destaque: document.getElementById("e_destaque"),
    status: document.getElementById("e_status"),
    descricao: document.getElementById("e_descricao"),
    obs: document.getElementById("e_obs"),
  };

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
      id_plano: "e_plano_id",
      nome: "e_nome",
      ref: "e_ref",
      referencia: "e_ref",
      preco: "e_preco",
      preco_mensal: "e_preco",
      cobranca: "e_cobranca",
      limite_usuarios: "e_limite_usuarios",
      limite_proprietarios: "e_limite_proprietarios",
      limite_profissionais: "e_limite_profissionais",
      limite_recepcionistas: "e_limite_recepcionistas",
      limite_servicos: "e_limite_servicos",
      limite_agendamentos: "e_limite_agendamentos",
      destaque: "e_destaque",
      status: "e_status",
      descricao: "e_descricao",
      observacao: "e_obs",
      obs: "e_obs",
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

  Object.values(campos).forEach(bindClearOnInput);

  // ==========================================================
  // Helpers
  // ==========================================================
  function normalizarTexto(v) {
    return String(v || "").trim();
  }

  function apenasDigitos(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function atualizarVagasAdministrativas() {
    if (!vagasAdministrativas) return;

    const proprietarios = Number.parseInt(campos.limite_proprietarios?.value || "0", 10);
    const profissionais = Number.parseInt(campos.limite_profissionais?.value || "0", 10);
    const recepcionistas = Number.parseInt(campos.limite_recepcionistas?.value || "0", 10);

    const propVal = Number.isFinite(proprietarios) && proprietarios >= 0 ? proprietarios : 0;
    const profVal = Number.isFinite(profissionais) && profissionais >= 0 ? profissionais : 0;
    const recepVal = Number.isFinite(recepcionistas) && recepcionistas >= 0 ? recepcionistas : 0;

    vagasAdministrativas.textContent = `${propVal} Proprietários · ${profVal} Profissionais · ${recepVal} Recepcionistas`;
  }

  function normalizeMoney(value) {
    if (value == null) return "";
    let v = String(value).trim();

    if (!v) return "";

    v = v.replace(/[^\d,.-]/g, "");

    if (v.includes(",") && v.includes(".")) {
      v = v.replace(/\./g, "").replace(",", ".");
      return v;
    }

    if (v.includes(",")) {
      v = v.replace(",", ".");
    }

    return v;
  }

  function formatMoneyBR(value) {
    if (value === null || value === undefined || value === "") return "";
    const num = Number(String(value).replace(",", "."));
    if (!Number.isFinite(num)) return String(value);
    return num.toLocaleString("pt-BR", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function coletarDados() {
    return {
      id_plano: normalizarTexto(campos.id_plano?.value),
      nome: normalizarTexto(campos.nome?.value),
      ref: normalizarTexto(campos.ref?.value),
      preco: normalizarTexto(campos.preco?.value),
      cobranca: normalizarTexto(campos.cobranca?.value).toLowerCase(),
      limite_usuarios: normalizarTexto(campos.limite_usuarios?.value),
      limite_proprietarios: normalizarTexto(campos.limite_proprietarios?.value),
      limite_profissionais: normalizarTexto(campos.limite_profissionais?.value),
      limite_recepcionistas: normalizarTexto(campos.limite_recepcionistas?.value),
      limite_servicos: normalizarTexto(campos.limite_servicos?.value),
      limite_agendamentos: normalizarTexto(campos.limite_agendamentos?.value),
      destaque: normalizarTexto(campos.destaque?.value),
      status: normalizarTexto(campos.status?.value).toLowerCase(),
      descricao: normalizarTexto(campos.descricao?.value),
      obs: normalizarTexto(campos.obs?.value),
    };
  }

  // ==========================================================
  // Validate
  // ==========================================================
  function validate() {
    clearErrors();
    let ok = true;

    const dados = coletarDados();

    if (!dados.id_plano || !/^\d+$/.test(dados.id_plano) || Number(dados.id_plano) < 1) {
      setFieldError("id_plano", "Plano inválido.");
      ok = false;
    }

    if (!dados.nome) {
      setFieldError("nome", "Informe o nome do plano.");
      ok = false;
    } else if (dados.nome.length < 3) {
      setFieldError("nome", "O nome do plano deve ter no mínimo 3 caracteres.");
      ok = false;
    } else if (dados.nome.length > 80) {
      setFieldError("nome", "O nome do plano deve ter no máximo 80 caracteres.");
      ok = false;
    }

    if (dados.ref && dados.ref.length > 60) {
      setFieldError("ref", "A referência deve ter no máximo 60 caracteres.");
      ok = false;
    }

    if (!dados.preco) {
      setFieldError("preco", "Informe o preço mensal.");
      ok = false;
    } else {
      const precoNorm = normalizeMoney(dados.preco);
      if (!/^\d+(\.\d{1,2})?$/.test(precoNorm)) {
        setFieldError("preco", "Preço inválido. Ex: 49,90");
        ok = false;
      } else if (Number(precoNorm) < 0) {
        setFieldError("preco", "O preço não pode ser negativo.");
        ok = false;
      }
    }

    if (!dados.cobranca) {
      setFieldError("cobranca", "Selecione a cobrança.");
      ok = false;
    }

    const limiteUsuarios = parseInt(dados.limite_usuarios || "0", 10);
    const limiteProprietarios = parseInt(dados.limite_proprietarios || "0", 10);
    const limiteProfissionais = parseInt(dados.limite_profissionais || "0", 10);
    const limiteRecepcionistas = parseInt(dados.limite_recepcionistas || "0", 10);
    const limiteServicos = parseInt(dados.limite_servicos || "0", 10);
    const limiteAgendamentos = parseInt(dados.limite_agendamentos || "0", 10);

    if (!Number.isInteger(limiteUsuarios) || limiteUsuarios < 1) {
      setFieldError("limite_usuarios", "O limite de usuários deve ser no mínimo 1.");
      ok = false;
    }

    if (Number.isNaN(limiteProprietarios) || limiteProprietarios < 0) {
      setFieldError("limite_proprietarios", "Não pode ser negativo.");
      ok = false;
    } else if (limiteProprietarios > limiteUsuarios) {
      setFieldError("limite_proprietarios", "Não pode superar o limite total de usuários.");
      ok = false;
    }

    if (Number.isNaN(limiteProfissionais) || limiteProfissionais < 0) {
      setFieldError("limite_profissionais", "Não pode ser negativo.");
      ok = false;
    } else if (limiteProfissionais > limiteUsuarios) {
      setFieldError("limite_profissionais", "Não pode superar o limite total de usuários.");
      ok = false;
    }

    if (Number.isNaN(limiteRecepcionistas) || limiteRecepcionistas < 0) {
      setFieldError("limite_recepcionistas", "Não pode ser negativo.");
      ok = false;
    } else if (limiteRecepcionistas > limiteUsuarios) {
      setFieldError("limite_recepcionistas", "Não pode superar o limite total de usuários.");
      ok = false;
    }

    if (Number.isNaN(limiteServicos) || limiteServicos < 0) {
      setFieldError("limite_servicos", "Não pode ser negativo.");
      ok = false;
    }

    if (Number.isNaN(limiteAgendamentos) || limiteAgendamentos < 0) {
      setFieldError("limite_agendamentos", "Não pode ser negativo.");
      ok = false;
    }

    if (!["ativo", "inativo", "bloqueado"].includes(dados.status)) {
      setFieldError("status", "Status inválido.");
      ok = false;
    }

    if (!["0", "1"].includes(dados.destaque)) {
      setFieldError("destaque", "Valor de destaque inválido.");
      ok = false;
    }

    if (dados.descricao && dados.descricao.length > 500) {
      setFieldError("descricao", "A descrição deve ter no máximo 500 caracteres.");
      ok = false;
    }

    if (dados.obs && dados.obs.length > 220) {
      setFieldError("obs", "A observação deve ter no máximo 220 caracteres.");
      ok = false;
    }

    if (!ok) focusFirstError();
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
  function abrirModal() {
    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");
  }

  function fecharModal() {
    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("aberto", "ativo", "show", "is-open");
    document.body.classList.remove("modal-open", "body-modal-open", "is-modal-open", "overflow-hidden");
  }

  function resetForm() {
    form.reset();
    clearErrors();

    if (campos.id_plano) campos.id_plano.value = "";
    if (campos.cobranca) campos.cobranca.value = "mensal";
    if (campos.destaque) campos.destaque.value = "0";
    if (campos.status) campos.status.value = "ativo";
    atualizarVagasAdministrativas();
  }

  // ==========================================================
  // Máscara / formatação
  // ==========================================================
  if (campos.preco) {
    campos.preco.addEventListener("input", () => {
      const valor = campos.preco.value || "";
      campos.preco.value = valor.replace(/[^\d,.\s]/g, "");
    });

    campos.preco.addEventListener("blur", () => {
      const v = normalizarTexto(campos.preco.value);
      if (!v) return;

      const normalizado = normalizeMoney(v);
      if (!/^\d+(\.\d{1,2})?$/.test(normalizado)) return;

      campos.preco.value = formatMoneyBR(normalizado);
    });
  }

  campos.limite_usuarios?.addEventListener("input", atualizarVagasAdministrativas);
  campos.limite_usuarios?.addEventListener("change", atualizarVagasAdministrativas);
  campos.limite_proprietarios?.addEventListener("input", atualizarVagasAdministrativas);
  campos.limite_proprietarios?.addEventListener("change", atualizarVagasAdministrativas);
  campos.limite_profissionais?.addEventListener("input", atualizarVagasAdministrativas);
  campos.limite_profissionais?.addEventListener("change", atualizarVagasAdministrativas);
  campos.limite_recepcionistas?.addEventListener("input", atualizarVagasAdministrativas);
  campos.limite_recepcionistas?.addEventListener("change", atualizarVagasAdministrativas);

  // ==========================================================
  // Recarregar lista
  // ==========================================================
  function recarregarListaPlanos() {
    if (
      window.ListaPlanos &&
      typeof window.ListaPlanos.carregar === "function"
    ) {
      window.ListaPlanos.carregar();
      return;
    }

    if (
      window.ListaPlanos &&
      typeof window.ListaPlanos.recarregar === "function"
    ) {
      window.ListaPlanos.recarregar();
      return;
    }

    if (
      window.ListaCore &&
      typeof window.ListaCore.recarregar === "function"
    ) {
      window.ListaCore.recarregar();
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
    fd.append("id_plano", dados.id_plano);
    fd.append("nome", dados.nome);
    fd.append("ref", dados.ref || "");
    fd.append("preco", normalizeMoney(dados.preco));
    fd.append("cobranca", dados.cobranca);
    fd.append("limite_usuarios", apenasDigitos(dados.limite_usuarios || "0"));
    fd.append("limite_proprietarios", apenasDigitos(dados.limite_proprietarios || "0"));
    fd.append("limite_profissionais", apenasDigitos(dados.limite_profissionais || "0"));
    fd.append("limite_recepcionistas", apenasDigitos(dados.limite_recepcionistas || "0"));
    fd.append("limite_servicos", apenasDigitos(dados.limite_servicos || "0"));
    fd.append("limite_agendamentos", apenasDigitos(dados.limite_agendamentos || "0"));
    fd.append("destaque", dados.destaque);
    fd.append("status", dados.status);
    fd.append("descricao", dados.descricao || "");
    fd.append("obs", dados.obs || "");

    setLoading(true);

    try {
      const resp = await fetch(API_URL, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
        },
      });

      const raw = await resp.text();
      let json = null;

      try {
        json = JSON.parse(raw);
      } catch (_) {
        json = null;
      }

      console.log("[EditarPlano] STATUS:", resp.status);
      console.log("[EditarPlano] RAW:", raw);
      console.log("[EditarPlano] JSON:", json);

      if (!json) {
        toastMsg("danger", "Resposta inválida da API ao editar o plano.");
        return;
      }

      if (!resp.ok || json.ok !== true) {
        if (json.fields && typeof json.fields === "object") {
          applyApiFieldErrors(json.fields);
        }

        toastMsg(
          "danger",
          json.user_msg || "Não foi possível atualizar o plano."
        );
        return;
      }

      fecharModal();

      document.dispatchEvent(
        new CustomEvent("plano:editado", {
          detail: json?.data || null,
        })
      );

      const successTimeout = 2200;

      toastMsg(
        "success",
        json.user_msg || "Plano atualizado com sucesso.",
        "",
        successTimeout
      );

      setTimeout(() => {
        recarregarListaPlanos();
      }, successTimeout + TOAST_LEAVE_TIME);

    } catch (err) {
      console.error("[EditarPlano] Erro:", err);
      toastMsg("danger", "Falha de rede ao atualizar o plano.");
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

    const m = btnFechar.closest("#modalEditarPlano");
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

  // ==========================================================
  // API pública
  // ==========================================================
  window.EditarPlano = {
    abrir(dados = {}) {
      clearErrors();

      if (campos.id_plano) campos.id_plano.value = dados.id_plano ?? dados.id ?? "";
      if (campos.nome) campos.nome.value = dados.nome ?? "";
      if (campos.ref) campos.ref.value = dados.ref ?? dados.referencia ?? "";
      if (campos.preco) campos.preco.value = formatMoneyBR(dados.preco_mensal ?? dados.preco ?? "");
      if (campos.cobranca) campos.cobranca.value = dados.cobranca ?? "mensal";
      if (campos.limite_usuarios) campos.limite_usuarios.value = dados.limite_usuarios ?? 1;
      if (campos.limite_proprietarios) campos.limite_proprietarios.value = dados.limite_proprietarios ?? 0;
      if (campos.limite_profissionais) campos.limite_profissionais.value = dados.limite_profissionais ?? 0;
      if (campos.limite_recepcionistas) campos.limite_recepcionistas.value = dados.limite_recepcionistas ?? 0;
      if (campos.limite_servicos) campos.limite_servicos.value = dados.limite_servicos ?? 0;
      if (campos.limite_agendamentos) campos.limite_agendamentos.value = dados.limite_agendamentos ?? 0;
      if (campos.destaque) campos.destaque.value = String(dados.destaque ?? 0);
      if (campos.status) campos.status.value = dados.status ?? "ativo";
      if (campos.descricao) campos.descricao.value = dados.descricao ?? "";
      if (campos.obs) campos.obs.value = dados.observacao ?? dados.obs ?? "";
      atualizarVagasAdministrativas();

      abrirModal();
    },
    fechar() {
      fecharModal();
    },
    reset: resetForm,
    limparErros: clearErrors,
  };
})();
