/* ==========================================================
   sessao.js — Verifica sessão (AmAgenda) ✅
   - Se NÃO estiver logado: redireciona para /public/views/login-cliente.php
   - Endpoint: /api/api_central.php?path=_auth/session
   - IMPORTANTE: credentials: "include" (cookie PHPSESSID)
   - NOVO: expõe dados da sessão e mostra nome da empresa no header
========================================================== */
(() => {
  "use strict";

  const API_BASE = "/api/api_central.php";
  const LOGIN_URL = "/public/views/login-cliente.php";

  function aplicarDadosSessao(auth) {
    if (!auth || typeof auth !== "object") return;

    window.__AUTH__ = auth;

    const elNomeEmpresa = document.getElementById("nomeEmpresaCabecalho");
    if (!elNomeEmpresa) return;

    const empresaNome =
      auth.empresa_nome ||
      auth.nome_empresa ||
      auth.empresa?.nome ||
      "";

    if (String(empresaNome).trim()) {
      elNomeEmpresa.textContent = String(empresaNome).trim();
    }
  }

  async function verificarSessao() {
    try {
      const resp = await fetch(`${API_BASE}?path=_auth/session`, {
        method: "GET",
        credentials: "include",
        headers: { Accept: "application/json" },
        cache: "no-store"
      });

      if (resp.status === 401 || resp.status === 403) {
        window.location.replace(LOGIN_URL);
        return;
      }

      const json = await resp.json().catch(() => null);

      if (!json || json.ok !== true) {
        window.location.replace(LOGIN_URL);
        return;
      }

      aplicarDadosSessao(json.data?.user || json.data || null);

    } catch (e) {
      window.location.replace(LOGIN_URL);
    }
  }

  document.addEventListener("DOMContentLoaded", verificarSessao);
})();