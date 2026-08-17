// /js/cadastrar_usuario.js
(() => {
  "use strict";

  if (window.__CADASTRAR_USUARIO_JS_INIT__) {
    console.warn("[CadastrarUsuario] Script já inicializado. Ignorando carga duplicada.");
    return;
  }
  window.__CADASTRAR_USUARIO_JS_INIT__ = true;

  const ENDPOINT = "/public/api/api_central.php?path=painel/usuario/cadastrar";
  const SUCCESS_TOAST_TIME = 3500;
  const TOAST_LEAVE_TIME = 180;

  const FORM_ID = "formCadastrarUsuario";
  const MODAL_ID = "modalNovoUsuario";
  const BTN_ID = "btnSalvarUsuario";

  const form = document.getElementById(FORM_ID);
  const modal = document.getElementById(MODAL_ID);
  const btnSalvar = document.getElementById(BTN_ID);

  if (!form || !modal || !btnSalvar) return;

  const campos = {
    nome: document.getElementById("u_nome"),
    perfil: document.getElementById("u_perfil"),
    especialidadeWrap: document.getElementById("campo_especialidade"),
    especialidade: document.getElementById("u_especialidade"),
    email: document.getElementById("u_email"),
    telefone: document.getElementById("u_tel"),
    senha: document.getElementById("u_senha"),
    senha2: document.getElementById("u_senha2"),
  };

  let successReloadTimer = null;

  // ==========================================================
  // TOASTS
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
    if (!el || el.dataset.leaving === "1") return;

    el.dataset.leaving = "1";
    el.classList.add("is-leaving");

    setTimeout(() => {
      try { el.remove(); } catch (_) {}
    }, TOAST_LEAVE_TIME);
  }

  function getToastTitleByType(type) {
    switch (type) {
      case "success": return "Sucesso";
      case "warning": return "Atenção";
      case "danger":  return "Erro";
      case "neutral": return "Aviso";
      case "confirm": return "Informação";
      default:        return "Aviso";
    }
  }

  function getToastIconByType(type) {
    switch (type) {
      case "success": return "✅";
      case "warning": return "⚠️";
      case "danger":  return "❌";
      case "neutral": return "💬";
      case "confirm": return "ℹ️";
      default:        return "ℹ️";
    }
  }

  function toast({
    title = "",
    message = "—",
    type = "confirm",
    timeout = 3500,
    buttonText = "Fechar",
  }) {
    const stack = getToastStack();

    const wrap = document.createElement("div");
    wrap.className = `ui-alert ui-alert--${type}`;
    wrap.setAttribute("role", type === "danger" ? "alert" : "status");
    wrap.setAttribute("aria-live", type === "danger" ? "assertive" : "polite");

    wrap.innerHTML = `
      <div class="ui-alert__icon"></div>
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
    const $icon = wrap.querySelector(".ui-alert__icon");
    const $close = wrap.querySelector(".js-close");

    $title.textContent = String(title || "").trim() || getToastTitleByType(type);
    $msg.textContent = String(message || "").trim() || "—";
    $icon.textContent = getToastIconByType(type);

    $close?.addEventListener("click", () => closeToast(wrap));

    stack.appendChild(wrap);

    if (timeout > 0) {
      setTimeout(() => closeToast(wrap), timeout);
    }

    return wrap;
  }

  function showToast(tipo, titulo, msg, tempo = 3500) {
    return toast({
      type: tipo,
      title: titulo,
      message: msg,
      timeout: tempo,
    });
  }

  // ==========================================================
  // HELPERS
  // ==========================================================
  function onlyDigits(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function normalizarTexto(v) {
    return String(v || "").trim().replace(/\s+/g, " ");
  }

  function normalizarComparacao(v) {
    return String(v || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim()
      .toLowerCase()
      .replace(/\s+/g, " ");
  }

  function emailValido(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || "").trim());
  }

  function getPerfilSelecionadoTexto() {
    const select = campos.perfil;
    if (!select) return "";

    const opt = select.options[select.selectedIndex];
    return opt ? String(opt.textContent || "").trim() : "";
  }

  function isPerfilProfissional() {
    const texto = normalizarComparacao(getPerfilSelecionadoTexto());
    return texto === "profissional";
  }

  function setLoading(loading) {
    btnSalvar.disabled = !!loading;
    btnSalvar.classList.toggle("is-loading", !!loading);
    btnSalvar.textContent = loading ? "Salvando..." : "Salvar Usuário";
  }

  function limparErroCampo(input) {
    if (!input || !input.id) return;

    input.classList.remove("campo-invalido");

    const erro = form.querySelector(`.msg-erro[data-erro-for="${input.id}"]`);
    if (erro) {
      erro.textContent = "";
      erro.classList.remove("ativo");
    }

    const wrapper = input.closest(".modal-campo");
    if (wrapper) wrapper.classList.remove("erro");
  }

  function limparErros() {
    form.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
      el.classList.remove("ativo");

      const wrapper = el.closest(".modal-campo");
      if (wrapper) wrapper.classList.remove("erro");
    });

    [
      campos.nome,
      campos.perfil,
      campos.especialidade,
      campos.email,
      campos.telefone,
      campos.senha,
      campos.senha2,
    ].forEach((campo) => {
      if (!campo) return;
      campo.classList.remove("campo-invalido");

      const wrapper = campo.closest(".modal-campo");
      if (wrapper) wrapper.classList.remove("erro");
    });
  }

  function setErro(input, mensagem) {
    if (!input || !input.id) return;

    input.classList.add("campo-invalido");

    const wrapper = input.closest(".modal-campo");
    if (wrapper) wrapper.classList.add("erro");

    const erro = form.querySelector(`.msg-erro[data-erro-for="${input.id}"]`);
    if (erro) {
      erro.textContent = String(mensagem || "");
      erro.classList.add("ativo");
    }
  }

  function aplicarErrosBackend(fields) {
    if (!fields || typeof fields !== "object") return;

    const mapa = {
      u_nome: campos.nome,
      nome: campos.nome,

      u_perfil: campos.perfil,
      perfil: campos.perfil,
      id_perfil: campos.perfil,

      u_especialidade: campos.especialidade,
      especialidade: campos.especialidade,

      u_email: campos.email,
      email: campos.email,

      u_telefone: campos.telefone,
      u_tel: campos.telefone,
      telefone: campos.telefone,

      u_senha: campos.senha,
      senha: campos.senha,

      u_senha2: campos.senha2,
      senha2: campos.senha2,
      confirmar_senha: campos.senha2,
    };

    Object.entries(fields).forEach(([chave, mensagem]) => {
      const campo = mapa[chave];
      if (campo) setErro(campo, mensagem);
    });
  }

  function focarPrimeiroErro() {
    const primeiro = form.querySelector(".msg-erro.ativo");
    if (!primeiro) return;

    const fieldId = primeiro.getAttribute("data-erro-for");
    if (!fieldId) return;

    const campo = document.getElementById(fieldId);
    if (campo && typeof campo.focus === "function") {
      campo.focus();
    }
  }

  function toggleEspecialidade() {
    const mostrar = isPerfilProfissional();

    if (mostrar) {
      if (campos.especialidadeWrap) {
        campos.especialidadeWrap.hidden = false;
        campos.especialidadeWrap.style.display = "";
      }
      if (campos.especialidade) {
        campos.especialidade.required = true;
      }
      return;
    }

    if (campos.especialidadeWrap) {
      campos.especialidadeWrap.hidden = true;
      campos.especialidadeWrap.style.display = "none";
    }

    if (campos.especialidade) {
      campos.especialidade.required = false;
      campos.especialidade.value = "";
      limparErroCampo(campos.especialidade);
    }
  }

  function validar() {
    limparErros();

    const nome = normalizarTexto(campos.nome?.value);
    const idPerfil = Number(campos.perfil?.value || 0);
    const especialidade = normalizarTexto(campos.especialidade?.value);
    const email = normalizarTexto(campos.email?.value).toLowerCase();
    const telefone = normalizarTexto(campos.telefone?.value);
    const telefoneDigitos = onlyDigits(telefone);
    const senha = String(campos.senha?.value || "");
    const senha2 = String(campos.senha2?.value || "");

    let ok = true;

    if (!nome) {
      setErro(campos.nome, "Informe o nome do usuário.");
      ok = false;
    } else if (nome.length < 3) {
      setErro(campos.nome, "O nome deve ter no mínimo 3 caracteres.");
      ok = false;
    } else if (nome.length > 140) {
      setErro(campos.nome, "O nome deve ter no máximo 140 caracteres.");
      ok = false;
    }

    if (!idPerfil || idPerfil <= 0) {
      setErro(campos.perfil, "Selecione um perfil válido.");
      ok = false;
    }

    if (isPerfilProfissional()) {
      if (!especialidade) {
        setErro(campos.especialidade, "Informe a especialidade do profissional.");
        ok = false;
      } else if (especialidade.length > 120) {
        setErro(campos.especialidade, "A especialidade deve ter no máximo 120 caracteres.");
        ok = false;
      }
    }

    if (!email) {
      setErro(campos.email, "Informe o e-mail.");
      ok = false;
    } else if (email.length > 160) {
      setErro(campos.email, "O e-mail deve ter no máximo 160 caracteres.");
      ok = false;
    } else if (!emailValido(email)) {
      setErro(campos.email, "E-mail inválido.");
      ok = false;
    }

    if (!telefone) {
      setErro(campos.telefone, "Informe o telefone/WhatsApp.");
      ok = false;
    } else if (telefoneDigitos.length < 10 || telefoneDigitos.length > 15) {
      setErro(campos.telefone, "Telefone inválido. Informe DDD + número.");
      ok = false;
    }

    if (!senha) {
      setErro(campos.senha, "Informe a senha.");
      ok = false;
    } else if (senha.length < 6 || senha.length > 60) {
      setErro(campos.senha, "A senha deve ter entre 6 e 60 caracteres.");
      ok = false;
    }

    if (!senha2) {
      setErro(campos.senha2, "Confirme a senha.");
      ok = false;
    } else if (senha !== senha2) {
      setErro(campos.senha2, "A confirmação de senha não confere.");
      ok = false;
    }

    if (!ok) {
      focarPrimeiroErro();
      return false;
    }

    return true;
  }

  function coletarPayload() {
    return {
      nome: normalizarTexto(campos.nome?.value),
      id_perfil: Number(campos.perfil?.value || 0),
      especialidade: isPerfilProfissional()
        ? normalizarTexto(campos.especialidade?.value)
        : "",
      email: normalizarTexto(campos.email?.value).toLowerCase(),
      telefone: normalizarTexto(campos.telefone?.value),
      senha: String(campos.senha?.value || ""),
      senha2: String(campos.senha2?.value || ""),
    };
  }

  function resetarFormulario() {
    form.reset();
    limparErros();
    toggleEspecialidade();
  }

  function fecharModalCadastro() {
    if (typeof window.fecharModal === "function") {
      window.fecharModal(modal);
      return;
    }

    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("aberto", "ativo", "abrir");
    document.body.classList.remove("modal-open", "body-travado", "sem-scroll", "modal-aberto");

    const backdrop = document.querySelector(`.modal-backdrop[data-modal="${MODAL_ID}"]`);
    if (backdrop) backdrop.remove();
  }

  function agendarReloadPagina() {
    if (successReloadTimer) {
      clearTimeout(successReloadTimer);
      successReloadTimer = null;
    }

    successReloadTimer = setTimeout(() => {
      window.location.reload();
    }, SUCCESS_TOAST_TIME + 220);
  }

  async function enviarCadastro(event) {
    event.preventDefault();

    if (btnSalvar.disabled) return;

    if (!validar()) {
      showToast("warning", "Verifique os campos", "Corrija os dados destacados antes de salvar.");
      return;
    }

    const payload = coletarPayload();
    setLoading(true);

    try {
      const resp = await fetch(ENDPOINT, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
        },
        body: JSON.stringify(payload),
        credentials: "same-origin",
        cache: "no-store",
      });

      const json = await resp.json().catch(() => null);

      if (!resp.ok || !json?.ok) {
        limparErros();

        if (json?.fields && typeof json.fields === "object") {
          aplicarErrosBackend(json.fields);
          focarPrimeiroErro();
        }

        const msg = json?.user_msg || "Não foi possível cadastrar o usuário.";
        showToast("danger", "Cadastro não realizado", msg);
        return;
      }

      showToast(
        "success",
        "Usuário cadastrado",
        json?.user_msg || "Usuário cadastrado com sucesso.",
        SUCCESS_TOAST_TIME
      );

      resetarFormulario();
      fecharModalCadastro();

      document.dispatchEvent(
        new CustomEvent("usuario:cadastrado", {
          detail: json?.data || null,
        })
      );

      agendarReloadPagina();
    } catch (error) {
      console.error("[cadastrar_usuario.js] Erro ao cadastrar usuário:", error);
      showToast("danger", "Erro de conexão", "Não foi possível concluir o cadastro agora.");
    } finally {
      setLoading(false);
    }
  }

  // ==========================================================
  // EVENTOS
  // ==========================================================
  campos.perfil?.addEventListener("change", toggleEspecialidade);

  [
    campos.nome,
    campos.perfil,
    campos.especialidade,
    campos.email,
    campos.telefone,
    campos.senha,
    campos.senha2,
  ]
    .filter(Boolean)
    .forEach((campo) => {
      const evento = campo.tagName === "SELECT" ? "change" : "input";

      campo.addEventListener(evento, () => {
        limparErroCampo(campo);

        if (campo === campos.perfil) {
          toggleEspecialidade();
        }
      });

      campo.addEventListener("blur", () => {
        limparErroCampo(campo);
      });
    });

  toggleEspecialidade();
  form.addEventListener("submit", enviarCadastro);
})();