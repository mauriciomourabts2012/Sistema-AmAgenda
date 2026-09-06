/* ==========================================================
   deslogar.js — Logout (AmAgenda) ✅
   - Botão: #btnSair
   - Endpoint: /api/api_central.php?path=_auth/logout
   - Redirecionamento definido pelo PHP:
       • super admin   -> /public/views/login-super-admin.html
       • demais perfis -> /login.php?empresa=ID&nome=slug-da-empresa
   - Confirmação reutiliza o componente central window.MensagemSistema
     (mesmo padrão visual usado nas demais confirmações do AmAgenda)
========================================================== */
(() => {
  "use strict";

  const API_BASE = "/api/api_central.php";
  const LOGIN_FALLBACK = "/public/views/login-super-admin.html";

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
  // ==========================================================
  async function logout() {
    let redirectUrl = LOGIN_FALLBACK;

    try {
      setBusy(true);

      const resp = await fetch(`${API_BASE}?path=_auth/logout`, {
        method: "POST",
        credentials: "include",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: ""
      });

      const data = await resp.json().catch(() => null);

      if (data && typeof data.redirect_url === "string" && data.redirect_url.trim() !== "") {
        redirectUrl = data.redirect_url.trim();
      }

    } catch (e) {
      // fallback de segurança
    } finally {
      window.location.replace(redirectUrl);
    }
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