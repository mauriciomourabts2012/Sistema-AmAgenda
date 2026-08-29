/* ==========================================================
   Login.js — Login (AmAgenda)
   - HTML:
     #email, #password, #lembrar, #login, #message
   - API:
     /api/api_central.php?path=_auth/login  (POST)
   - Compatível com PHP:
     email + password
   - Retorno esperado:
     { ok, code, user_msg, data: { redirect } }
========================================================== */
(() => {
  "use strict";

  const API_URL = "/api/api_central.php?path=_auth/login";

  const $email = document.getElementById("email");
  const $pass = document.getElementById("password");
  const $lembrar = document.getElementById("lembrar");
  const $btn = document.getElementById("login");
  const $msg = document.getElementById("message");
  const $form = $btn ? ($btn.closest("form") || document.getElementById("formLogin")) : null;

  if (!$email || !$pass || !$btn || !$msg) return;

  const KEY_EMAIL = "amagenda_login_email";
  const KEY_LEMBRAR = "amagenda_login_lembrar";

  let isSending = false;

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

      saveRemember();

      const redirect =
        json?.data?.redirect ||
        "/views/painel-administrativo/agenda.html";

      setMsg(
        json?.user_msg || getUserMessageByCode(json?.code, "Login realizado com sucesso."),
        "ok"
      );

      window.location.replace(redirect);
    } catch (_) {
      setMsg("Erro de conexão. Verifique sua internet e tente novamente.", "err");
      setBusy(false);
    }
  }

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

  loadRemember();
})();
