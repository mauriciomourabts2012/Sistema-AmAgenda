/* ==========================================================
   sessao.js — Verifica sessão (AmAgenda) ✅
   - Se NÃO estiver logado: redireciona para /public/views/login-cliente.php
   - Endpoint: /api/api_central.php?path=_auth/session
   - IMPORTANTE: credentials: "include" (cookie PHPSESSID)
   - Expõe dados da sessão e mostra nome da empresa no header
   - NOVO: antes de liberar a página, verifica documentos legais pendentes
     (/api/api_central.php?path=documentos-legais/pendencias) e redireciona
     para a página de aceite quando houver pendência aplicável ao ator logado.
     A definição de quais documentos cada ator deve aceitar continua
     exclusivamente no backend.
========================================================== */
(() => {
  "use strict";

  const API_BASE = "/api/api_central.php";
  const LOGIN_URL = "/public/views/login-cliente.php";
  const ACEITE_URL = "/public/views/documentos-legais/aceite-documentos.html";

  function caminhoAtual() {
    return String(window.location.pathname || "").toLowerCase();
  }

  function estaNaPaginaDeAceite() {
    return caminhoAtual() === ACEITE_URL.toLowerCase();
  }

  // Defesa extra: o site público (AmAgenda.html) nunca deve ser bloqueado por
  // este guard, mesmo que o script venha a ser incluído por engano nele.
  function estaEmPaginaPublicaSemGuard() {
    return caminhoAtual().endsWith("/amagenda.html");
  }

  function aplicarDadosSessao(auth) {
    if (!auth || typeof auth !== "object") return;

    window.__AUTH__ = auth;
    document.dispatchEvent(new CustomEvent("amagenda:sessao-carregada", { detail: auth }));

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

  /**
   * Consulta documentos legais pendentes e redireciona para a página de
   * aceite quando houver pendência. Retorna true quando o redirecionamento
   * foi disparado (o chamador deve interromper o fluxo normal da página).
   */
  async function verificarPendenciasLegais() {
    if (estaNaPaginaDeAceite() || estaEmPaginaPublicaSemGuard()) return false;

    try {
      const resp = await fetch(`${API_BASE}?path=documentos-legais/pendencias`, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" }
      });

      const json = await resp.json().catch(() => null);

      if (!json || json.ok !== true || typeof json.data?.possui_pendencias !== "boolean") {
        // Falha ao confirmar o estado: não presume "sem pendência" nem tenta
        // liberar a página silenciosamente. O bloqueio real das APIs
        // protegidas já existe no backend; aqui apenas seguimos o mesmo
        // tratamento de erro já usado para a verificação de sessão.
        window.location.replace(LOGIN_URL);
        return true;
      }

      if (json.data.possui_pendencias === true) {
        window.location.replace(ACEITE_URL);
        return true;
      }

      return false;
    } catch (e) {
      window.location.replace(LOGIN_URL);
      return true;
    }
  }

  let promessaSessao = null;

  function verificarSessao() {
    if (promessaSessao) return promessaSessao;

    promessaSessao = (async () => {
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

        const redirecionou = await verificarPendenciasLegais();
        if (redirecionou) return;

        aplicarDadosSessao(json.data?.user || json.data || null);

      } catch (e) {
        window.location.replace(LOGIN_URL);
      }
    })();

    return promessaSessao;
  }

  document.addEventListener("DOMContentLoaded", verificarSessao);
})();
