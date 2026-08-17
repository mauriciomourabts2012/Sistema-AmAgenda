/* ==========================================================
   editar_usuario.js — SuperAdmin | Editar Usuário
   ✅ Modal: #modalEditarUsuario
   ✅ Form:  #formEditarUsuario
   ✅ API:   POST /public/api/api_central.php?path=superadmin/usuario/editar
   ✅ Compatível com o PHP atual:
      - envia: id_usuario, id_empresa, nome, email, telefone, status
      - envia senha/senha2 SOMENTE se preenchidas
      - NÃO envia perfil
      - NÃO envia tipo_usuario
   ✅ Compatível com o modal atual:
      - #edit_u_perfil_super_admin = visual
      - #edit_u_empresa_super_admin = usado para enviar id_empresa
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
  const elPerfilVisual = document.getElementById("edit_u_perfil_super_admin");
  const elEmpresa = document.getElementById("edit_u_empresa_super_admin");
  const elTelefone = document.getElementById("edit_u_tel");
  const elStatus = document.getElementById("edit_u_status");

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
      status: "edit_u_status",
      senha: "edit_u_senha",
      senha2: "edit_u_senha2",
      perfil: "edit_u_perfil_super_admin",
      perfil_visual: "edit_u_perfil_super_admin",
      empresa: "edit_u_empresa_super_admin",
      empresa_id: "edit_u_empresa_super_admin",
      id_empresa: "edit_u_empresa_super_admin",
      vinculo: "edit_u_empresa_super_admin",
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
    elPerfilVisual,
    elEmpresa,
    elTelefone,
    elStatus,
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

  function coletarDados() {
    return {
      id_usuario: normalizarTexto(elId?.value),
      id_empresa: normalizarTexto(elEmpresa?.value),
      nome: normalizarTexto(elNome?.value),
      email: normalizarTexto(elEmail?.value).toLowerCase(),
      telefone: normalizarTexto(elTelefone?.value),
      status: normalizarTexto(elStatus?.value).toLowerCase(),
      senha: normalizarTexto(elSenha?.value),
      senha2: normalizarTexto(elSenha2?.value),
      perfil_visual: normalizarTexto(elPerfilVisual?.value),
    };
  }

  // ==========================================================
  // Validação compatível com o PHP
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

    const statusPermitidos = ["ativo", "inativo", "bloqueado"];
    if (!dados.status) {
      setFieldError("status", "Selecione o status.");
      ok = false;
    } else if (!statusPermitidos.includes(dados.status)) {
      setFieldError("status", "Status inválido.");
      ok = false;
    }

    if (dados.senha || dados.senha2) {
      if (!dados.senha) {
        setFieldError("senha", "Informe a nova senha.");
        ok = false;
      } else if (dados.senha.length < 6) {
        setFieldError("senha", "A senha deve ter no mínimo 6 caracteres.");
        ok = false;
      }

      if (!dados.senha2) {
        setFieldError("senha2", "Confirme a nova senha.");
        ok = false;
      } else if (dados.senha2.length < 6) {
        setFieldError("senha2", "A confirmação deve ter no mínimo 6 caracteres.");
        ok = false;
      }

      if (dados.senha && dados.senha2 && dados.senha !== dados.senha2) {
        setFieldError("senha2", "As senhas não conferem.");
        ok = false;
      }
    }

    // id_empresa é opcional no PHP.
    // Porém, se o campo estiver visível e preenchido, só aceitamos número.
    if (dados.id_empresa && !/^\d+$/.test(dados.id_empresa)) {
      setFieldError("id_empresa", "Empresa inválida.");
      ok = false;
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
    if (elPerfilVisual) elPerfilVisual.value = "";
    if (elEmpresa) elEmpresa.value = "";
    if (elTelefone) elTelefone.value = "";
    if (elStatus) elStatus.value = "";
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

    const status =
      normalizarTexto(data.status ?? data.status_usuario ?? data.status_vinculo ?? "")
        .toLowerCase();

    const perfilValor =
      data.perfil ??
      data.tipo_usuario ??
      data.tipo ??
      "";

    const empresaId =
      data.id_empresa ??
      data.empresa_id ??
      data.idempresa ??
      "";

    const empresaNome =
      data.nome_empresa ??
      data.empresa ??
      data.empresa_nome ??
      "";

    if (elId) elId.value = id ? String(id) : "";
    if (elNome) elNome.value = nome ? String(nome) : "";
    if (elEmail) elEmail.value = email ? String(email) : "";
    if (elTelefone) elTelefone.value = telefone ? formatarTelefone(String(telefone)) : "";
    if (elStatus) setSelectValue(elStatus, status || "ativo");

    // Perfil é visual, não enviado
    if (elPerfilVisual) {
      let perfilSelectValue = "";

      if (perfilValor !== "") {
        perfilSelectValue =
          findOptionValueByText(elPerfilVisual, perfilValor) || String(perfilValor);
      }

      setSelectValue(elPerfilVisual, perfilSelectValue);
    }

    // Empresa será enviada como id_empresa
    if (elEmpresa) {
      let empresaSelectValue = "";

      if (empresaId !== "") {
        empresaSelectValue = String(empresaId);
      } else if (empresaNome !== "") {
        empresaSelectValue = findOptionValueByText(elEmpresa, empresaNome);
      }

      setSelectValue(elEmpresa, empresaSelectValue);
    }

    if (elSenha) elSenha.value = "";
    if (elSenha2) elSenha2.value = "";

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
    fd.append("status", dados.status);

    // IMPORTANTÍSSIMO: PHP espera id_empresa, não empresa_id
    if (dados.id_empresa) {
      fd.append("id_empresa", dados.id_empresa);
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
          "Accept": "application/json"
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