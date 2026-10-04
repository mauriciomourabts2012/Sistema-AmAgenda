/* ==========================================================
   Login.js — Login (AmAgenda)
   - HTML:
     #email, #password, #lembrar, #login, #message
   - API:
     /api/api_central.php?path=_auth/login  (POST)
     /api/api_central.php?path=_auth/selecionar-empresa  (POST, X-CSRF-Token)
   - Compatível com PHP:
     email + password
   - Retorno esperado:
     LOGIN_OK                  -> { data: { redirect } }  (segue o redirect do servidor)
     EMPRESA_SELECTION_REQUIRED -> { data: { empresas:[{id_empresa,nome,perfil_nome}],
                                   csrf_token, expira_em_segundos } }
   - O frontend apenas apresenta a escolha: quem autoriza é o backend.
     O csrf_token da pré-sessão e a lista de empresas ficam SOMENTE em memória
     (variável `selecao`), nunca em storage/cookie.
========================================================== */
(() => {
  "use strict";

  const API_URL = "/api/api_central.php?path=_auth/login";
  const API_SELECAO_URL = "/api/api_central.php?path=_auth/selecionar-empresa";
  const MSG_FALHA_TECNICA = "Não foi possível concluir o acesso. Tente novamente.";
  const MSG_ERRO_REDE = "Erro de conexão. Verifique sua internet e tente novamente.";

  const $email = document.getElementById("email");
  const $pass = document.getElementById("password");
  const $lembrar = document.getElementById("lembrar");
  const $btn = document.getElementById("login");
  const $msg = document.getElementById("message");
  const $form = $btn ? ($btn.closest("form") || document.getElementById("formLogin")) : null;

  if (!$email || !$pass || !$btn || !$msg) return;

  const KEY_EMAIL = "amagenda_login_email";
  const KEY_LEMBRAR = "amagenda_login_lembrar";

  // Etapa de seleção de empresa (opcional no HTML).
  const $etapaCredenciais = document.getElementById("loginEtapaCredenciais");
  const $etapaEmpresa = document.getElementById("loginEtapaEmpresa");
  const $empresaLista = document.getElementById("empresaLista");
  const $empresaTitulo = document.getElementById("empresaTitulo");
  const $msgEmpresa = document.getElementById("messageEmpresa");
  const $empresaVoltar = document.getElementById("empresaVoltar");

  const $esqueciSenha = document.getElementById("esqueciSenha");
  const $recEmailEtapa = document.getElementById("loginEtapaRecuperacaoEmail");
  const $recCodigoEtapa = document.getElementById("loginEtapaRecuperacaoCodigo");
  const $recSenhaEtapa = document.getElementById("loginEtapaRecuperacaoSenha");
  const $recEmail = document.getElementById("recuperacaoEmail");
  const $recEnviar = document.getElementById("recuperacaoEnviarCodigo");
  const $recMsgEmail = document.getElementById("recuperacaoMensagemEmail");
  const $recVoltarLogin = document.getElementById("recuperacaoVoltarLogin");
  const $recCodigo = document.getElementById("recuperacaoCodigo");
  const $recValidar = document.getElementById("recuperacaoValidarCodigo");
  const $recMsgCodigo = document.getElementById("recuperacaoMensagemCodigo");
  const $recTempo = document.getElementById("recuperacaoTempo");
  const $recReenviar = document.getElementById("recuperacaoReenviarCodigo");
  const $recVoltarEmail = document.getElementById("recuperacaoVoltarEmail");
  const $recNovaSenha = document.getElementById("recuperacaoNovaSenha");
  const $recConfirmarSenha = document.getElementById("recuperacaoConfirmarSenha");
  const $recSalvarSenha = document.getElementById("recuperacaoSalvarSenha");
  const $recMsgSenha = document.getElementById("recuperacaoMensagemSenha");

  let isSending = false;
  let isSelecting = false;
  // Estado da pré-sessão: somente em memória. null = fora da etapa de seleção.
  let selecao = null; // { csrf: string, empresas: [{id_empresa, nome, perfil_nome}] }
  let recuperacaoCsrf = String(window.AMAGENDA_LOGIN_CSRF || "");
  let recuperacaoEmailAtual = "";
  let recuperacaoExpiraEm = 0;
  let recuperacaoTimer = null;

  function setMsg(text, type = "info") {
    $msg.textContent = text || "";
    $msg.dataset.type = type; // info | ok | err
  }

  function setBusy(busy) {
    isSending = !!busy;
    $btn.disabled = !!busy;
    $btn.setAttribute("aria-busy", busy ? "true" : "false");
    const idleLabel = $btn.dataset.labelIdle || "Entrar";
    const busyLabel = $btn.dataset.labelBusy || "Entrando...";
    const labelElement = $btn.querySelector("[data-button-label]");
    if (labelElement) {
      labelElement.textContent = busy ? busyLabel : idleLabel;
    } else {
      $btn.textContent = busy ? busyLabel : idleLabel;
    }
  }

  function loadRemember() {
    try {
      const lembrar = localStorage.getItem(KEY_LEMBRAR) === "1";

      if ($lembrar) {
        $lembrar.checked = lembrar;
      }

      if (lembrar) {
        const savedEmail = localStorage.getItem(KEY_EMAIL) || "";
        if (savedEmail) {
          $email.value = savedEmail;
        }
      }
    } catch (_) {}
  }

  function saveRemember() {
    try {
      if ($lembrar && $lembrar.checked) {
        localStorage.setItem(KEY_LEMBRAR, "1");
        localStorage.setItem(KEY_EMAIL, ($email.value || "").trim().toLowerCase());
      } else {
        localStorage.setItem(KEY_LEMBRAR, "0");
        localStorage.removeItem(KEY_EMAIL);
      }
    } catch (_) {}
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || "").trim());
  }

  function getUserMessageByCode(code, fallback) {
    switch (String(code || "")) {
      case "EMAIL_REQUIRED":
        return "Informe seu e-mail.";

      case "EMAIL_INVALID":
        return "Informe um e-mail válido.";

      case "PASSWORD_REQUIRED":
        return "Informe sua senha.";

      case "METHOD_NOT_ALLOWED":
        return "Método não permitido.";

      case "DB_CONN_MISSING":
      case "DB_CONN_ERROR":
      case "DB_PREPARE_FAIL":
      case "DB_EXEC_FAIL":
      case "DB_PREPARE_EMPRESA_FAIL":
      case "DB_EXEC_EMPRESA_FAIL":
        return "Erro interno ao processar o login.";

      case "LOGIN_INVALID_USER_NOT_FOUND":
      case "LOGIN_INVALID_PASSWORD_MISMATCH":
      case "USER_NOT_ACTIVE":
      case "EMPTY_HASH":
      case "LOGIN_INVALID_CREDENTIALS":
        return "Usuário ou senha inválidos.";

      case "LOGIN_ACCESS_DENIED":
        return "Não foi possível realizar o acesso.";

      case "USER_WITHOUT_EMPRESA":
        return "Usuário sem empresa vinculada.";

      case "EMPRESA_REQUIRED":
        return "Acesse pelo link da sua empresa.";

      case "LOGIN_OK":
        return "Login realizado com sucesso.";

      default:
        return fallback || "Falha ao entrar. Tente novamente.";
    }
  }

  function focusFieldByCode(code) {
    switch (String(code || "")) {
      case "EMAIL_REQUIRED":
      case "EMAIL_INVALID":
      case "LOGIN_INVALID_USER_NOT_FOUND":
        $email.focus();
        break;

      case "PASSWORD_REQUIRED":
      case "LOGIN_INVALID_PASSWORD_MISMATCH":
      case "EMPTY_HASH":
      case "LOGIN_INVALID_CREDENTIALS":
      case "LOGIN_ACCESS_DENIED":
        $pass.focus();
        break;
    }
  }

  async function doLogin() {
    if (isSending) return;

    const email = ($email.value || "").trim().toLowerCase();
    const password = $pass.value || "";

    setMsg("");

    if (!email) {
      setMsg("Informe seu e-mail.", "err");
      $email.focus();
      return;
    }

    if (!isValidEmail(email)) {
      setMsg("Informe um e-mail válido.", "err");
      $email.focus();
      return;
    }

    if (!password) {
      setMsg("Informe sua senha.", "err");
      $pass.focus();
      return;
    }

    setBusy(true);

    try {
      const body = new URLSearchParams();
      body.set("email", email);
      body.set("password", password);

      const resp = await fetch(API_URL, {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          "X-Requested-With": "XMLHttpRequest"
        },
        body: body.toString()
      });

      let json = null;
      try {
        json = await resp.json();
      } catch (_) {
        json = null;
      }

      if (!resp.ok || !json || json.ok !== true) {
        const code = json?.code || "";
        const msg = getUserMessageByCode(
          code,
          json?.user_msg || "Falha ao entrar. Tente novamente."
        );

        setMsg(msg, "err");
        focusFieldByCode(code);
        setBusy(false);
        return;
      }

      // Credenciais válidas, porém o usuário tem mais de uma empresa:
      // ainda NÃO está logado. Apresenta o seletor.
      if (json.code === "EMPRESA_SELECTION_REQUIRED") {
        saveRemember();
        if (!mostrarSelecao(json.data)) {
          setMsg(MSG_FALHA_TECNICA, "err");
          setBusy(false);
        }
        return;
      }

      if (json.code !== "LOGIN_OK") {
        setMsg(MSG_FALHA_TECNICA, "err");
        setBusy(false);
        return;
      }

      saveRemember();
      setMsg(
        json?.user_msg || getUserMessageByCode(json?.code, "Login realizado com sucesso."),
        "ok"
      );
      irParaRedirect(json?.data?.redirect);
    } catch (_) {
      setMsg(MSG_ERRO_REDE, "err");
      setBusy(false);
    }
  }

  /* ------------------------------------------------------------------
     Redirect do servidor (não é modificado; só aceita caminho do próprio site)
  ------------------------------------------------------------------ */
  function irParaRedirect(redirect) {
    let destino = String(redirect || "").trim();
    if (!destino) destino = "/views/painel-administrativo/agenda.html";
    if (!destino.startsWith("/") || destino.startsWith("//") || destino.includes("\\")) {
      destino = "/views/painel-administrativo/agenda.html";
    }
    window.location.replace(destino);
  }

  /* ------------------------------------------------------------------
     Etapa de seleção de empresa
  ------------------------------------------------------------------ */
  function setMsgEmpresa(text, type = "info") {
    if (!$msgEmpresa) return;
    $msgEmpresa.textContent = text || "";
    $msgEmpresa.dataset.type = type;
  }

  function limparSelecao() {
    selecao = null;
    isSelecting = false;
    if ($empresaLista) $empresaLista.replaceChildren();
    setMsgEmpresa("");
  }

  /** Volta ao formulário de e-mail/senha, limpando todo o estado da seleção. */
  function voltarParaCredenciais(mensagem, tipo = "info") {
    limparSelecao();
    pararTimerRecuperacao();
    $pass.value = "";
    if ($etapaEmpresa) $etapaEmpresa.hidden = true;
    if ($recEmailEtapa) $recEmailEtapa.hidden = true;
    if ($recCodigoEtapa) $recCodigoEtapa.hidden = true;
    if ($recSenhaEtapa) $recSenhaEtapa.hidden = true;
    if ($etapaCredenciais) $etapaCredenciais.hidden = false;
    setBusy(false);
    setMsg(mensagem || "", tipo);
    ($email.value ? $pass : $email).focus();
  }

  function normalizarEmpresas(lista) {
    if (!Array.isArray(lista)) return [];
    return lista
      .map((e) => ({
        id_empresa: Number(e?.id_empresa),
        nome: String(e?.nome ?? "").trim(),
        perfil_nome: String(e?.perfil_nome ?? "").trim()
      }))
      .filter((e) => Number.isInteger(e.id_empresa) && e.id_empresa > 0 && e.nome !== "");
  }

  /** Mostra o seletor. Retorna false se a resposta não tiver o formato esperado. */
  function mostrarSelecao(data) {
    const empresas = normalizarEmpresas(data?.empresas);
    const csrf = typeof data?.csrf_token === "string" ? data.csrf_token : "";

    if (!$etapaCredenciais || !$etapaEmpresa || !$empresaLista || !csrf || empresas.length < 2) {
      return false;
    }

    selecao = { csrf, empresas };
    isSelecting = false;
    $pass.value = "";
    setMsg("");
    setMsgEmpresa("");

    const itens = empresas.map((empresa) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "empresa-opcao";

      const textos = document.createElement("span");
      textos.className = "empresa-opcao-textos";

      const nome = document.createElement("span");
      nome.className = "empresa-opcao-nome";
      nome.textContent = empresa.nome;
      textos.appendChild(nome);

      if (empresa.perfil_nome) {
        const perfil = document.createElement("span");
        perfil.className = "empresa-opcao-perfil";
        perfil.textContent = empresa.perfil_nome;
        textos.appendChild(perfil);
      }

      btn.appendChild(textos);
      btn.setAttribute(
        "aria-label",
        empresa.perfil_nome
          ? `${empresa.nome}, perfil ${empresa.perfil_nome}`
          : empresa.nome
      );
      btn.addEventListener("click", () => selecionarEmpresa(empresa.id_empresa, btn));
      return btn;
    });

    $empresaLista.replaceChildren(...itens);
    $etapaCredenciais.hidden = true;
    $etapaEmpresa.hidden = false;
    setBusy(false);
    if ($empresaTitulo) $empresaTitulo.focus();
    return true;
  }

  function setSelecaoBusy(busy, botaoAtivo) {
    isSelecting = !!busy;
    if ($empresaLista) {
      $empresaLista.querySelectorAll(".empresa-opcao").forEach((b) => {
        b.disabled = !!busy;
        b.classList.toggle("is-loading", !!busy && b === botaoAtivo);
      });
      $empresaLista.setAttribute("aria-busy", busy ? "true" : "false");
    }
    if ($empresaVoltar) $empresaVoltar.disabled = !!busy;
  }

  async function selecionarEmpresa(idEmpresa, botao) {
    if (isSelecting || !selecao) return;

    setSelecaoBusy(true, botao);
    setMsgEmpresa("Entrando...", "info");

    try {
      const body = new URLSearchParams();
      body.set("id_empresa", String(idEmpresa));

      const resp = await fetch(API_SELECAO_URL, {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": selecao.csrf
        },
        body: body.toString()
      });

      let json = null;
      try {
        json = await resp.json();
      } catch (_) {
        json = null;
      }

      if (resp.ok && json && json.ok === true && json.code === "LOGIN_OK") {
        // Concluído: descarta o estado da pré-sessão e segue o redirect do servidor.
        // isSelecting permanece true para impedir novos cliques até a navegação.
        const redirect = json?.data?.redirect;
        selecao = null;
        setMsgEmpresa(json?.user_msg || "Login realizado com sucesso.", "ok");
        irParaRedirect(redirect);
        return;
      }

      const code = String(json?.code || "");

      switch (code) {
        case "LOGIN_PENDING_EXPIRED":
        case "LOGIN_PENDING_REQUIRED":
          voltarParaCredenciais("Sua sessão de seleção expirou. Faça login novamente.", "err");
          return;

        case "LOGIN_PENDING_ATTEMPTS_EXCEEDED":
          voltarParaCredenciais("Por segurança, faça login novamente.", "err");
          return;

        case "CSRF_INVALID":
        case "LOGIN_ACCESS_DENIED":
          // A pré-sessão não é mais utilizável.
          voltarParaCredenciais("Por segurança, faça login novamente.", "err");
          return;

        case "EMPRESA_SELECTION_DENIED":
        case "EMPRESA_SELECTION_INVALID":
          // Pré-sessão ainda válida: permite escolher outra empresa.
          setSelecaoBusy(false, null);
          setMsgEmpresa("Não foi possível acessar essa empresa.", "err");
          return;
      }

      if (resp.status === 401 || resp.status === 429) {
        voltarParaCredenciais("Por segurança, faça login novamente.", "err");
        return;
      }

      setSelecaoBusy(false, null);
      setMsgEmpresa(MSG_FALHA_TECNICA, "err");
    } catch (_) {
      setSelecaoBusy(false, null);
      setMsgEmpresa(MSG_ERRO_REDE, "err");
    }
  }

  if ($empresaVoltar) {
    $empresaVoltar.addEventListener("click", () => {
      if (isSelecting) return;
      // O servidor mantém a pré-sessão até expirar ou ser substituída por um novo
      // login; ela não concede acesso operacional.
      voltarParaCredenciais("");
    });
  }

  // Enter nos campos envia o login (não há <form> no HTML).
  [$email, $pass].forEach((campo) => {
    campo.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter" && !ev.isComposing) {
        ev.preventDefault();
        doLogin();
      }
    });
  });

  $btn.addEventListener("click", (ev) => {
    ev.preventDefault();
    doLogin();
  });

  if ($form) {
    $form.addEventListener("submit", (ev) => {
      ev.preventDefault();
      doLogin();
    });
  }

  /* ------------------------------------------------------------------
     Recuperação global de senha: e-mail -> SMS -> OTP -> nova senha
  ------------------------------------------------------------------ */
  function setMensagemRecuperacao(elemento, texto, tipo = "info") {
    if (!elemento) return;
    elemento.textContent = texto || "";
    elemento.dataset.type = tipo;
  }

  function mostrarEtapaRecuperacao(etapa) {
    if ($etapaCredenciais) $etapaCredenciais.hidden = true;
    if ($etapaEmpresa) $etapaEmpresa.hidden = true;
    if ($recEmailEtapa) $recEmailEtapa.hidden = etapa !== "email";
    if ($recCodigoEtapa) $recCodigoEtapa.hidden = etapa !== "codigo";
    if ($recSenhaEtapa) $recSenhaEtapa.hidden = etapa !== "senha";
  }

  function pararTimerRecuperacao() {
    if (recuperacaoTimer) {
      clearInterval(recuperacaoTimer);
      recuperacaoTimer = null;
    }
    recuperacaoExpiraEm = 0;
    if ($recTempo) $recTempo.textContent = "";
  }

  function iniciarTimerRecuperacao(segundos) {
    pararTimerRecuperacao();
    recuperacaoExpiraEm = Date.now() + Math.max(1, Number(segundos) || 300) * 1000;

    const atualizar = () => {
      const restante = Math.max(0, Math.ceil((recuperacaoExpiraEm - Date.now()) / 1000));
      if ($recTempo) {
        const minutos = Math.floor(restante / 60);
        const segundosRestantes = String(restante % 60).padStart(2, "0");
        $recTempo.textContent = restante > 0
          ? `Código válido por ${minutos}:${segundosRestantes}.`
          : "O código expirou. Solicite um novo.";
      }
      if (restante <= 0) {
        if (recuperacaoTimer) clearInterval(recuperacaoTimer);
        recuperacaoTimer = null;
        recuperacaoExpiraEm = 0;
        if ($recValidar) $recValidar.disabled = true;
      }
    };

    atualizar();
    recuperacaoTimer = setInterval(atualizar, 1000);
  }

  async function requisitarRecuperacao(acao, dados = {}) {
    if (!/^[a-f0-9]{64}$/.test(recuperacaoCsrf)) {
      throw new Error("Atualize a página para renovar sua sessão.");
    }

    const body = new URLSearchParams({ acao, ...dados });
    const resposta = await fetch(API_URL, {
      method: "POST",
      credentials: "include",
      cache: "no-store",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": recuperacaoCsrf
      },
      body: body.toString()
    });
    const json = await resposta.json().catch(() => null);
    if (!resposta.ok || !json || json.ok !== true) {
      throw new Error(json?.user_msg || "Não foi possível concluir a recuperação.");
    }
    const csrfRenovado = String(json?.data?.csrf_token || "");
    if (/^[a-f0-9]{64}$/.test(csrfRenovado)) recuperacaoCsrf = csrfRenovado;
    return json;
  }

  async function solicitarCodigoRecuperacao() {
    const email = String($recEmail?.value || "").trim().toLowerCase();
    setMensagemRecuperacao($recMsgEmail, "");
    if (!isValidEmail(email)) {
      setMensagemRecuperacao($recMsgEmail, "Informe um e-mail válido.", "err");
      $recEmail?.focus();
      return;
    }

    recuperacaoEmailAtual = email;
    if ($recEnviar) $recEnviar.disabled = true;
    try {
      const json = await requisitarRecuperacao("recuperacao_solicitar", { email });
      setMensagemRecuperacao($recMsgEmail, json.user_msg || "Se os dados estiverem corretos, enviaremos um código de recuperação.", "ok");
      if ($recCodigo) $recCodigo.value = "";
      if ($recValidar) $recValidar.disabled = true;
      mostrarEtapaRecuperacao("codigo");
      iniciarTimerRecuperacao(Number(json?.data?.expires_in) || 300);
      $recCodigo?.focus();
    } catch (erro) {
      setMensagemRecuperacao($recMsgEmail, erro.message, "err");
    } finally {
      if ($recEnviar) $recEnviar.disabled = false;
    }
  }

  $esqueciSenha?.addEventListener("click", () => {
    limparSelecao();
    if ($recEmail) $recEmail.value = String($email.value || "").trim().toLowerCase();
    setMensagemRecuperacao($recMsgEmail, "");
    mostrarEtapaRecuperacao("email");
    $recEmail?.focus();
  });

  $recVoltarLogin?.addEventListener("click", () => voltarParaCredenciais(""));
  $recVoltarEmail?.addEventListener("click", () => {
    pararTimerRecuperacao();
    setMensagemRecuperacao($recMsgCodigo, "");
    mostrarEtapaRecuperacao("email");
    $recEmail?.focus();
  });
  $recEnviar?.addEventListener("click", solicitarCodigoRecuperacao);
  $recEmail?.addEventListener("keydown", (evento) => {
    if (evento.key === "Enter" && !evento.isComposing) {
      evento.preventDefault();
      solicitarCodigoRecuperacao();
    }
  });

  $recCodigo?.addEventListener("input", () => {
    $recCodigo.value = String($recCodigo.value || "").replace(/\D+/g, "").slice(0, 6);
    if ($recValidar) {
      $recValidar.disabled = $recCodigo.value.length !== 6 || recuperacaoExpiraEm <= Date.now();
    }
  });

  $recValidar?.addEventListener("click", async () => {
    const codigo = String($recCodigo?.value || "");
    if (codigo.length !== 6) return;
    $recValidar.disabled = true;
    setMensagemRecuperacao($recMsgCodigo, "Validando...", "info");
    try {
      const json = await requisitarRecuperacao("recuperacao_validar_codigo", { codigo });
      pararTimerRecuperacao();
      setMensagemRecuperacao($recMsgCodigo, json.user_msg || "Código validado.", "ok");
      if ($recNovaSenha) $recNovaSenha.value = "";
      if ($recConfirmarSenha) $recConfirmarSenha.value = "";
      mostrarEtapaRecuperacao("senha");
      $recNovaSenha?.focus();
    } catch (erro) {
      setMensagemRecuperacao($recMsgCodigo, erro.message, "err");
      $recValidar.disabled = recuperacaoExpiraEm <= Date.now();
    }
  });

  $recReenviar?.addEventListener("click", async () => {
    if (!recuperacaoEmailAtual) return;
    $recReenviar.disabled = true;
    setMensagemRecuperacao($recMsgCodigo, "Solicitando novo código...", "info");
    try {
      const json = await requisitarRecuperacao("recuperacao_solicitar", { email: recuperacaoEmailAtual });
      if ($recCodigo) $recCodigo.value = "";
      if ($recValidar) $recValidar.disabled = true;
      iniciarTimerRecuperacao(Number(json?.data?.expires_in) || 300);
      setMensagemRecuperacao($recMsgCodigo, json.user_msg || "Se os dados estiverem corretos, enviaremos um código de recuperação.", "ok");
    } catch (erro) {
      setMensagemRecuperacao($recMsgCodigo, erro.message, "err");
    } finally {
      $recReenviar.disabled = false;
    }
  });

  $recSalvarSenha?.addEventListener("click", async () => {
    const novaSenha = String($recNovaSenha?.value || "");
    const confirmarSenha = String($recConfirmarSenha?.value || "");
    setMensagemRecuperacao($recMsgSenha, "");
    if (novaSenha.length < 6 || novaSenha.length > 72) {
      setMensagemRecuperacao($recMsgSenha, "A nova senha deve ter entre 6 e 72 caracteres.", "err");
      $recNovaSenha?.focus();
      return;
    }
    if (novaSenha !== confirmarSenha) {
      setMensagemRecuperacao($recMsgSenha, "A confirmação da nova senha não confere.", "err");
      $recConfirmarSenha?.focus();
      return;
    }

    $recSalvarSenha.disabled = true;
    try {
      const json = await requisitarRecuperacao("recuperacao_redefinir_senha", {
        nova_senha: novaSenha,
        confirmar_senha: confirmarSenha
      });
      setMensagemRecuperacao($recMsgSenha, json.user_msg || "Senha alterada com sucesso. Faça login novamente.", "ok");
      if ($email) $email.value = recuperacaoEmailAtual;
      window.setTimeout(() => voltarParaCredenciais("Senha alterada com sucesso. Faça login novamente.", "ok"), 1200);
    } catch (erro) {
      setMensagemRecuperacao($recMsgSenha, erro.message, "err");
      $recSalvarSenha.disabled = false;
    }
  });

  loadRemember();
})();
