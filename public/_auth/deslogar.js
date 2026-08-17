/* ==========================================================
   deslogar.js — Logout (AmAgenda) ✅
   - Botão: #btnSair
   - Endpoint: /api/api_central.php?path=_auth/logout
   - Redirecionamento definido pelo PHP:
       • super admin   -> /public/views/login-super-admin.html
       • demais perfis -> /login.php?empresa=ID&nome=slug-da-empresa
   - ✅ Usa Alert Universal (Toast) no lugar de confirm()
========================================================== */
(() => {
  "use strict";

  const API_BASE = "/api/api_central.php";
  const LOGIN_FALLBACK = "/public/views/login-super-admin.html";

  const btn = document.getElementById("btnSair");
  if (!btn) return;

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

      $title.textContent = title;
      $msg.textContent = message;

      const $icon = wrap.querySelector(".ui-alert__icon");
      if ($icon) {
        $icon.textContent =
          type === "danger" ? "❌" :
          type === "success" ? "✅" :
          type === "warning" ? "⚠️" :
          type === "neutral" ? "💬" :
          "ℹ️";
      }

      let closed = false;

      function close(result) {
        if (closed) return;
        closed = true;

        document.removeEventListener("keydown", onKey);
        wrap.classList.add("is-leaving");
        setTimeout(() => wrap.remove(), 180);
        resolve(result);
      }

      $ok.addEventListener("click", () => close(true));
      $cancel.addEventListener("click", () => close(false));

      function onKey(e) {
        if (e.key === "Escape") close(false);
      }

      document.addEventListener("keydown", onKey);

      stack.appendChild(wrap);
      setTimeout(() => $ok?.focus?.(), 0);
    });
  }

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
  // Click
  // ==========================================================
  btn.addEventListener("click", async (ev) => {
    ev.preventDefault();

    const ok = await toastConfirm({
      title: "Sair do sistema",
      message: "Deseja sair do sistema agora?",
      type: "confirm",
      confirmText: "Sair",
      cancelText: "Cancelar",
    });

    if (!ok) return;
    logout();
  });
})();