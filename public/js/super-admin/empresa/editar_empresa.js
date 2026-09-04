/* ==========================================================
   editar_empresa.js — SuperAdmin | Editar Empresa (AmAgenda)
   ✅ Modal: #modalEditarEmpresa
   ✅ Form:  #formEmpEditar
   ✅ API:   POST /public/api/api_central.php?path=superadmin/empresa/editar
   ✅ PADRÃO IGUAL AO CadastrarEmpresa.js:
      - Toast: .ui-toast-stack + .ui-alert
      - Campo erro: .modal-campo.erro + .msg-erro.ativo
      - Envia via FormData / $_POST
      - Não envia JSON
   ✅ Compatível com:
      id_empresa, nome, cnpj, email, telefone, plano_id, status, endereco, obs
   ✅ CNPJ é OPCIONAL
   ✅ Após sucesso:
      - fecha modal
      - dispara evento empresa:editada
      - mostra mensagem de sucesso
      - só depois atualiza a lista/página
========================================================== */
(() => {
  "use strict";

  if (window.__EDITAR_EMPRESA_JS_INIT__) {
    console.warn("[EditarEmpresa] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__EDITAR_EMPRESA_JS_INIT__ = true;

  const API_BASE = "/public/api/api_central.php";
  const API_PATH = "superadmin/empresa/editar";
  const API_URL = `${API_BASE}?path=${encodeURIComponent(API_PATH)}`;

  const TOAST_DEFAULT_TIMEOUT = 3500;
  const TOAST_LEAVE_TIME = 180;

  // ==========================================================
  // DOM
  // ==========================================================
  const modal = document.getElementById("modalEditarEmpresa");
  const form = document.getElementById("formEmpEditar");
  const btnSalvar = document.getElementById("btnEmpAtualizar");

  if (!modal || !form || !btnSalvar) {
    console.warn("[EditarEmpresa] Elementos do modal não encontrados.");
    return;
  }

  const elId = document.getElementById("emp_edit_id");
  const elNome = document.getElementById("emp_edit_nome");
  const elCnpj = document.getElementById("emp_edit_cnpj");
  const elEmail = document.getElementById("emp_edit_email");
  const elTelefone = document.getElementById("emp_edit_tel");
  const elPlanoId = document.getElementById("emp_edit_plano");
  const elStatus = document.getElementById("emp_edit_status");
  const elEndereco = document.getElementById("emp_edit_endereco");
  const elObservacao = document.getElementById("emp_edit_obs");
  const selecaoPlanoEl = document.getElementById("emp_edit_selecao_plano");
  const selecaoResumoEl = document.getElementById("emp_edit_selecao_resumo");
  const selecaoExcessosEl = document.getElementById("emp_edit_selecao_excessos");
  const selecaoUsuariosEl = document.getElementById("emp_edit_selecao_usuarios");
  const selecaoContadorEl = document.getElementById("emp_edit_selecao_contador");
  const selecaoErroEl = document.getElementById("emp_edit_selecao_erro");
  const btnCancelarSelecao = document.getElementById("emp_edit_cancelar_selecao");
  let selecaoPlano = null;
  let selecaoPlanoValida = true;

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
    btnSalvar.disabled = !!loading || !selecaoPlanoValida;
    form.dataset.loading = loading ? "1" : "0";
    btnSalvar.classList.toggle("is-loading", !!loading);
    btnSalvar.textContent = loading
      ? "Salvando..."
      : (selecaoPlano ? "Confirmar downgrade" : "Salvar");
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
      id_empresa: "emp_edit_id",
      nome: "emp_edit_nome",
      cnpj: "emp_edit_cnpj",
      email: "emp_edit_email",
      telefone: "emp_edit_tel",
      plano_id: "emp_edit_plano",
      status: "emp_edit_status",
      endereco: "emp_edit_endereco",
      observacao: "emp_edit_obs",
      obs: "emp_edit_obs",
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

  [
    elId,
    elNome,
    elCnpj,
    elEmail,
    elTelefone,
    elPlanoId,
    elStatus,
    elEndereco,
    elObservacao,
  ].forEach(bindClearOnInput);

  elPlanoId?.addEventListener("focus", () => {
    if (!form.dataset.planoOriginal) form.dataset.planoOriginal = String(elPlanoId.value || "");
  });
  elPlanoId?.addEventListener("change", () => {
    if (selecaoPlano) limparSelecaoPlano();
  });
  btnCancelarSelecao?.addEventListener("click", () => {
    limparSelecaoPlano({ restaurarPlano: true });
    toastMsg("neutral", "Seleção cancelada. Nenhuma alteração foi realizada.");
  });

  // ==========================================================
  // Helpers
  // ==========================================================
  const onlyDigits = (v) => String(v || "").replace(/\D+/g, "");

  function normalizarTexto(v) {
    return String(v || "").trim();
  }

  function rotuloRecurso(recurso) {
    return ({
      usuarios: "Usuários (total)",
      proprietarios: "Proprietários",
      profissionais: "Profissionais",
      recepcionistas: "Recepcionistas",
      administrativos: "Proprietários/Recepção",
    })[recurso] || recurso;
  }

  function idsSelecionadosPlano() {
    if (!selecaoUsuariosEl) return [];
    return Array.from(selecaoUsuariosEl.querySelectorAll('input[type="checkbox"]:checked'))
      .map((input) => Number(input.value))
      .filter((id) => Number.isInteger(id) && id > 0);
  }

  function validarSelecaoPlano() {
    if (!selecaoPlano) return { ok: true, mensagem: "" };

    const selecionados = new Set(idsSelecionadosPlano());
    const necessaria = Number(selecaoPlano.quantidade_selecao_necessaria || 0);
    if (selecionados.size !== necessaria) {
      return {
        ok: false,
        mensagem: `Selecione exatamente ${necessaria} ${necessaria === 1 ? "usuário" : "usuários"}.`,
      };
    }

    const candidatos = Array.isArray(selecaoPlano.usuarios_para_selecao)
      ? selecaoPlano.usuarios_para_selecao
      : [];
    const limites = selecaoPlano.limites || {};
    const excessos = Array.isArray(selecaoPlano.excessos) ? selecaoPlano.excessos : [];
    const temExcessoTotal = excessos.some((item) => item?.recurso === "usuarios");
    const contagem = {};
    candidatos.forEach((usuario) => {
      if (!selecionados.has(Number(usuario.id_empresa_usuario))) return;
      const recurso = String(usuario.recurso_limite || usuario.perfil || "");
      contagem[recurso] = (contagem[recurso] || 0) + 1;
    });

    const recursos = temExcessoTotal
      ? ["profissionais", "administrativos"]
      : excessos.map((item) => String(item?.recurso || "")).filter((item) => item !== "usuarios");

    for (const recurso of recursos) {
      const quantidade = Number(contagem[recurso] || 0);
      const limite = Number(limites[recurso] || 0);
      if (quantidade > limite || (!temExcessoTotal && quantidade !== limite)) {
        return {
          ok: false,
          mensagem: `A seleção de ${rotuloRecurso(recurso).toLowerCase()} deve respeitar o limite de ${limite}.`,
        };
      }
    }

    return { ok: true, mensagem: "" };
  }

  function atualizarEstadoSelecaoPlano() {
    const resultado = validarSelecaoPlano();
    selecaoPlanoValida = resultado.ok;
    const quantidade = idsSelecionadosPlano().length;
    const necessaria = Number(selecaoPlano?.quantidade_selecao_necessaria || 0);
    if (selecaoContadorEl) {
      selecaoContadorEl.textContent = `${quantidade} de ${necessaria} selecionado${necessaria === 1 ? "" : "s"}`;
    }
    if (selecaoErroEl) selecaoErroEl.textContent = resultado.mensagem;
    setLoading(form.dataset.loading === "1");
  }

  function limparSelecaoPlano({ restaurarPlano = false } = {}) {
    selecaoPlano = null;
    selecaoPlanoValida = true;
    if (selecaoPlanoEl) selecaoPlanoEl.hidden = true;
    if (selecaoResumoEl) selecaoResumoEl.textContent = "";
    if (selecaoExcessosEl) selecaoExcessosEl.replaceChildren();
    if (selecaoUsuariosEl) selecaoUsuariosEl.replaceChildren();
    if (selecaoContadorEl) selecaoContadorEl.textContent = "";
    if (selecaoErroEl) selecaoErroEl.textContent = "";
    if (restaurarPlano && elPlanoId && form.dataset.planoOriginal) {
      elPlanoId.value = form.dataset.planoOriginal;
    }
    setLoading(false);
  }

  function renderizarSelecaoPlano(data) {
    if (!selecaoPlanoEl || !selecaoUsuariosEl || !data) return;
    selecaoPlano = data;
    selecaoPlanoValida = false;
    selecaoPlanoEl.hidden = false;
    selecaoExcessosEl?.replaceChildren();
    selecaoUsuariosEl.replaceChildren();

    const necessaria = Number(data.quantidade_selecao_necessaria || 0);
    if (selecaoResumoEl) {
      selecaoResumoEl.textContent = `Selecione exatamente ${necessaria} ${necessaria === 1 ? "vínculo" : "vínculos"} para permanecer com acesso no novo plano.`;
    }

    (Array.isArray(data.excessos) ? data.excessos : []).forEach((excesso) => {
      const card = document.createElement("div");
      card.className = "plano-selecao-excesso";
      const titulo = document.createElement("strong");
      titulo.textContent = rotuloRecurso(String(excesso?.recurso || ""));
      const texto = document.createElement("span");
      texto.textContent = `Atual: ${Number(excesso?.consumo_atual || 0)} · Limite: ${Number(excesso?.limite || 0)} · Excesso: ${Number(excesso?.excedente || 0)}`;
      card.append(titulo, texto);
      selecaoExcessosEl?.appendChild(card);
    });

    (Array.isArray(data.usuarios_para_selecao) ? data.usuarios_para_selecao : []).forEach((usuario) => {
      const label = document.createElement("label");
      label.className = "plano-selecao-item";
      const checkbox = document.createElement("input");
      checkbox.type = "checkbox";
      checkbox.value = String(usuario.id_empresa_usuario || "");
      checkbox.addEventListener("change", atualizarEstadoSelecaoPlano);
      const textos = document.createElement("span");
      const nome = document.createElement("strong");
      nome.textContent = String(usuario.nome || "Usuário");
      const perfil = document.createElement("small");
      perfil.textContent = rotuloRecurso(String(usuario.perfil || ""));
      textos.append(nome, perfil);
      label.append(checkbox, textos);
      selecaoUsuariosEl.appendChild(label);
    });

    atualizarEstadoSelecaoPlano();
    selecaoPlanoEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function validarEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function validarCnpj(cnpj) {
    cnpj = onlyDigits(cnpj);

    if (cnpj.length !== 14) return false;
    if (/^(\d)\1{13}$/.test(cnpj)) return false;

    let tamanho = cnpj.length - 2;
    let numeros = cnpj.substring(0, tamanho);
    const digitos = cnpj.substring(tamanho);
    let soma = 0;
    let pos = tamanho - 7;

    for (let i = tamanho; i >= 1; i--) {
      soma += Number(numeros.charAt(tamanho - i)) * pos--;
      if (pos < 2) pos = 9;
    }

    let resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
    if (resultado !== Number(digitos.charAt(0))) return false;

    tamanho += 1;
    numeros = cnpj.substring(0, tamanho);
    soma = 0;
    pos = tamanho - 7;

    for (let i = tamanho; i >= 1; i--) {
      soma += Number(numeros.charAt(tamanho - i)) * pos--;
      if (pos < 2) pos = 9;
    }

    resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
    return resultado === Number(digitos.charAt(1));
  }

  function formatarCnpj(v) {
    const d = onlyDigits(v).slice(0, 14);
    return d
      .replace(/^(\d{2})(\d)/, "$1.$2")
      .replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3")
      .replace(/\.(\d{3})(\d)/, ".$1/$2")
      .replace(/(\d{4})(\d)/, "$1-$2");
  }

  function formatarTelefone(v) {
    const d = onlyDigits(v).slice(0, 11);

    if (d.length <= 10) {
      return d
        .replace(/^(\d{2})(\d)/, "($1) $2")
        .replace(/(\d{4})(\d)/, "$1-$2");
    }

    return d
      .replace(/^(\d{2})(\d)/, "($1) $2")
      .replace(/(\d{5})(\d)/, "$1-$2");
  }

  function coletarDados() {
    return {
      id_empresa: normalizarTexto(elId?.value),
      nome: normalizarTexto(elNome?.value),
      cnpj: normalizarTexto(elCnpj?.value),
      email: normalizarTexto(elEmail?.value),
      telefone: normalizarTexto(elTelefone?.value),
      plano_id: normalizarTexto(elPlanoId?.value),
      status: normalizarTexto(elStatus?.value).toLowerCase(),
      endereco: normalizarTexto(elEndereco?.value),
      obs: normalizarTexto(elObservacao?.value),
    };
  }

  // ==========================================================
  // Validate
  // ==========================================================
  function validate() {
    clearErrors();
    let ok = true;

    const dados = coletarDados();

    if (!dados.id_empresa || !/^\d+$/.test(dados.id_empresa)) {
      setFieldError("id_empresa", "Empresa inválida.");
      ok = false;
    }

    if (!dados.nome) {
      setFieldError("emp_edit_nome", "Informe o nome da empresa.");
      ok = false;
    } else if (dados.nome.length < 3) {
      setFieldError("emp_edit_nome", "O nome da empresa deve ter no mínimo 3 caracteres.");
      ok = false;
    } else if (dados.nome.length > 140) {
      setFieldError("emp_edit_nome", "O nome da empresa deve ter no máximo 140 caracteres.");
      ok = false;
    }

    if (dados.cnpj) {
      if (onlyDigits(dados.cnpj).length !== 14) {
        setFieldError("emp_edit_cnpj", "O CNPJ deve conter 14 dígitos.");
        ok = false;
      } else if (!validarCnpj(dados.cnpj)) {
        setFieldError("emp_edit_cnpj", "Informe um CNPJ válido.");
        ok = false;
      }
    }

    if (!dados.email) {
      setFieldError("emp_edit_email", "Informe o e-mail.");
      ok = false;
    } else if (dados.email.length > 160) {
      setFieldError("emp_edit_email", "O e-mail deve ter no máximo 160 caracteres.");
      ok = false;
    } else if (!validarEmail(dados.email)) {
      setFieldError("emp_edit_email", "Informe um e-mail válido.");
      ok = false;
    }

    const tel = onlyDigits(dados.telefone);
    if (!dados.telefone) {
      setFieldError("emp_edit_tel", "Informe o telefone.");
      ok = false;
    } else if (tel.length < 10 || tel.length > 11) {
      setFieldError("emp_edit_tel", "Informe um telefone válido com DDD.");
      ok = false;
    } else if (dados.telefone.length > 20) {
      setFieldError("emp_edit_tel", "O telefone deve ter no máximo 20 caracteres.");
      ok = false;
    }

    if (!dados.plano_id) {
      setFieldError("emp_edit_plano", "Selecione o plano.");
      ok = false;
    } else {
      const planoNum = parseInt(dados.plano_id, 10);
      if (!Number.isInteger(planoNum) || planoNum < 1) {
        setFieldError("emp_edit_plano", "Plano inválido.");
        ok = false;
      }
    }

    const statusPermitidos = ["ativo", "inativo", "bloqueado"];
    if (!dados.status) {
      setFieldError("emp_edit_status", "Selecione o status.");
      ok = false;
    } else if (!statusPermitidos.includes(dados.status)) {
      setFieldError("emp_edit_status", "Status inválido.");
      ok = false;
    }

    if (dados.endereco && dados.endereco.length > 200) {
      setFieldError("emp_edit_endereco", "O endereço deve ter no máximo 200 caracteres.");
      ok = false;
    }

    if (dados.obs && dados.obs.length > 220) {
      setFieldError("emp_edit_obs", "A observação deve ter no máximo 220 caracteres.");
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
  function fecharModal() {
    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("aberto", "ativo", "show", "is-open");
    document.body.classList.remove("modal-open", "body-modal-open", "is-modal-open", "overflow-hidden");
  }

  function resetForm() {
    limparSelecaoPlano();
    form.reset();
    clearErrors();
    delete form.dataset.planoOriginal;

    if (elId) elId.value = "";
    if (elStatus) elStatus.value = "ativo";

    if (elPlanoId && window.SelectListaUniversal?.carregar) {
      window.SelectListaUniversal.carregar("planos_ativos", "emp_edit_plano", { force: true });
    }
  }

  // ==========================================================
  // Máscaras
  // ==========================================================
  if (elCnpj) {
    elCnpj.addEventListener("input", () => {
      elCnpj.value = formatarCnpj(elCnpj.value);
    });
  }

  if (elTelefone) {
    elTelefone.addEventListener("input", () => {
      elTelefone.value = formatarTelefone(elTelefone.value);
    });
  }

  // ==========================================================
  // Recarregar lista
  // ==========================================================
  function recarregarListaEmpresas() {
    if (
      window.ListaEmpresas &&
      typeof window.ListaEmpresas.carregar === "function"
    ) {
      window.ListaEmpresas.carregar();
      return;
    }

    if (
      window.ListaEmpresas &&
      typeof window.ListaEmpresas.recarregar === "function"
    ) {
      window.ListaEmpresas.recarregar();
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

    const validacaoSelecao = validarSelecaoPlano();
    if (!validacaoSelecao.ok) {
      atualizarEstadoSelecaoPlano();
      toastMsg("warning", validacaoSelecao.mensagem);
      return;
    }

    const dados = coletarDados();

    const fd = new FormData();
    fd.append("id_empresa", dados.id_empresa);
    fd.append("nome", dados.nome);
    fd.append("cnpj", dados.cnpj || "");
    fd.append("email", dados.email);
    fd.append("telefone", dados.telefone);
    fd.append("plano_id", dados.plano_id);
    fd.append("status", dados.status);
    fd.append("endereco", dados.endereco || "");
    fd.append("obs", dados.obs || "");
    if (selecaoPlano) {
      fd.append("plano_usuarios_permanentes", JSON.stringify(idsSelecionadosPlano()));
    }

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

      console.log("[EditarEmpresa] STATUS:", resp.status);
      console.log("[EditarEmpresa] RAW:", raw);
      console.log("[EditarEmpresa] JSON:", json);

      if (!json) {
        toastMsg("danger", "Resposta inválida da API ao editar a empresa.");
        return;
      }

      if (!resp.ok || json.ok !== true) {
        if (
          ["PLAN_USER_SELECTION_REQUIRED", "PLAN_USER_SELECTION_INVALID"].includes(json.code) &&
          json.data?.usuarios_para_selecao
        ) {
          renderizarSelecaoPlano(json.data);
          toastMsg("warning", json.user_msg || "Revise a seleção de usuários.");
          return;
        }

        if (json.code === "PLAN_USER_SELECTION_STALE") {
          limparSelecaoPlano();
          toastMsg("warning", json.user_msg || "Os limites mudaram. Revise novamente a troca de plano.");
          return;
        }

        if (json.fields && typeof json.fields === "object") {
          applyApiFieldErrors(json.fields);
        }

        toastMsg(
          "danger",
          json.user_msg || "Não foi possível atualizar a empresa."
        );
        return;
      }

      fecharModal();

      document.dispatchEvent(
        new CustomEvent("empresa:editada", {
          detail: json?.data || null,
        })
      );

      const successTimeout = 2200;

      toastMsg(
        "success",
        json.user_msg || "Empresa atualizada com sucesso.",
        "",
        successTimeout
      );

      setTimeout(() => {
        recarregarListaEmpresas();
      }, successTimeout + TOAST_LEAVE_TIME);

    } catch (err) {
      console.error("[EditarEmpresa] Erro:", err);
      toastMsg("danger", "Falha de rede ao atualizar a empresa.");
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

    const m = btnFechar.closest("#modalEditarEmpresa");
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

  window.EditarEmpresa = {
    reset: resetForm,
    limparErros: clearErrors,
    toastConfirm,
  };
})();
