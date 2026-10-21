/* ==========================================================
   deslogar.js — Logout (AmAgenda) ✅
   - Botão: #btnSair
   - Endpoint: /api/api_central.php?path=_auth/logout
   - Envia o token CSRF; só redireciona após LOGOUT_OK do servidor
   - Redirecionamento definido pelo PHP:
       • super admin   -> /public/views/login-empresa.php
       • demais perfis -> destino preservado conforme o contexto da sessão
   - Confirmação reutiliza o componente central window.MensagemSistema
     (mesmo padrão visual usado nas demais confirmações do AmAgenda)
========================================================== */
(() => {
  "use strict";

  const API_BASE = "/api/api_central.php";
  const LOGIN_FALLBACK = "/public/views/login-empresa.php";

  const btn = document.getElementById("btnSair");
  if (!btn) return;

  // ==========================================================
  // Busy state
  // ==========================================================
  function setBusy(isBusy) {
    btn.disabled = isBusy;
    btn.style.opacity = isBusy ? "0.7" : "";
    btn.style.pointerEvents = isBusy ? "none" : "";
  }

  // ==========================================================
  // Logout
  // - Envia o token CSRF da sessão (X-CSRF-Token).
  // - Só redireciona após o servidor confirmar LOGOUT_OK.
  // - CSRF_INVALID: renova o token por _auth/session e repete uma única vez.
  // - Qualquer outra falha: informa o erro e mantém o usuário na página.
  // ==========================================================
  const TOKEN_VALIDO = /^[a-f0-9]{64}$/;
  const MSG_FALHA = "Não foi possível encerrar a sessão. Verifique sua conexão e tente novamente.";

  function tokenDaPagina() {
    const token = String(window.__AUTH__?.csrf_token || "");
    return TOKEN_VALIDO.test(token) ? token : "";
  }

  async function enviarLogout(token) {
    const headers = {
      "Accept": "application/json",
      "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
    };
    if (token) headers["X-CSRF-Token"] = token;

    const resp = await fetch(`${API_BASE}?path=_auth/logout`, {
      method: "POST",
      credentials: "include",
      cache: "no-store",
      headers,
      body: ""
    });

    const data = await resp.json().catch(() => null);
    return { resp, data };
  }

  async function renovarToken() {
    try {
      const resp = await fetch(`${API_BASE}?path=_auth/session`, {
        method: "GET",
        credentials: "include",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await resp.json().catch(() => null);
      const token = String(data?.data?.user?.csrf_token || "");
      return TOKEN_VALIDO.test(token) ? token : "";
    } catch (_) {
      return "";
    }
  }

  function logoutConfirmado(resultado) {
    return !!resultado
      && resultado.resp.ok
      && resultado.data?.ok === true
      && resultado.data?.code === "LOGOUT_OK";
  }

  // Aceita somente caminhos internos (evita redirecionamento para outro domínio).
  function destinoSeguro(url) {
    const destino = typeof url === "string" ? url.trim() : "";
    if (!destino.startsWith("/") || destino.startsWith("//") || destino.includes("\\")) {
      return LOGIN_FALLBACK;
    }
    return destino;
  }

  function informarFalha(mensagem) {
    const texto = mensagem || MSG_FALHA;
    if (typeof window.MensagemSistema?.erro === "function") {
      window.MensagemSistema.erro(texto, { titulo: "Sair do sistema" });
    } else {
      window.alert(texto);
    }
  }

  async function logout() {
    setBusy(true);

    try {
      let resultado = await enviarLogout(tokenDaPagina());

      if (!logoutConfirmado(resultado) && resultado.data?.code === "CSRF_INVALID") {
        const tokenNovo = await renovarToken();
        resultado = await enviarLogout(tokenNovo);
      }

      if (logoutConfirmado(resultado)) {
        window.location.replace(destinoSeguro(resultado.data.redirect_url));
        return;
      }

      // Falha confirmada pelo servidor: nunca apresentar como sucesso.
      informarFalha(MSG_FALHA);
    } catch (e) {
      // Falha de rede ou resposta inesperada: a sessão pode continuar ativa.
      informarFalha(MSG_FALHA);
    }

    setBusy(false);
  }

  // ==========================================================
  // Click — confirmação usa o componente central MensagemSistema
  // ==========================================================
  btn.addEventListener("click", async (ev) => {
    ev.preventDefault();

    const confirmar = window.MensagemSistema?.confirmar;

    const ok = typeof confirmar === "function"
      ? await confirmar("Deseja realmente sair do sistema?", {
          titulo: "Sair do sistema",
          textoConfirmar: "Sair",
          textoCancelar: "Cancelar",
        })
      : true;

    if (!ok) return;
    logout();
  });
})();
