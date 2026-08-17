

window.ListaCore = (() => {
  "use strict";

  const escapeHtml = (s) =>
    String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  function normalizar(txt) {
    return (txt || "")
      .toString()
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
  }

  function debounce(fn, ms = 120) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  async function fetchJSON(url) {
    const r = await fetch(url, { cache: "no-store" });
    const j = await r.json().catch(() => null);
    if (!r.ok) throw new Error(j?.reason || `HTTP ${r.status}`);
    return j;
  }

  // =========================
  // MENU FLUTUANTE (anti-corte)
  // =========================
  function createFloatingMenuController({ rootSelector }) {
    let menuAberto = null;
    let menuOwnerCard = null;
    let menuAnchorBtn = null;
    let menuOriginalParent = null;
    let menuOriginalNextSibling = null;

    function restaurarMenuParaCard() {
      if (!menuAberto || !menuOriginalParent) return;

      menuAberto.classList.remove("menu-flutuante");
      try {
        if (menuOriginalNextSibling && menuOriginalNextSibling.parentNode === menuOriginalParent) {
          menuOriginalParent.insertBefore(menuAberto, menuOriginalNextSibling);
        } else {
          menuOriginalParent.appendChild(menuAberto);
        }
      } catch (_) {
        const acoes = menuOwnerCard?.querySelector(".agenda-acoes");
        if (acoes) acoes.appendChild(menuAberto);
      }

      menuAberto.style.left = "";
      menuAberto.style.top = "";
      menuOriginalParent = null;
      menuOriginalNextSibling = null;
    }

    function fechar() {
      document.querySelectorAll(`${rootSelector} .agenda-menu.aberto`).forEach((m) => m.classList.remove("aberto"));
      document
        .querySelectorAll(`${rootSelector} .agenda-btn-acoes[aria-expanded='true']`)
        .forEach((b) => b.setAttribute("aria-expanded", "false"));

      if (menuAberto) {
        menuAberto.classList.remove("aberto");
        restaurarMenuParaCard();
      }

      menuAberto = null;
      menuOwnerCard = null;
      menuAnchorBtn = null;
    }

    function calcularPosicao(btn, menu) {
      const r = btn.getBoundingClientRect();

      const prevDisplay = menu.style.display;
      const prevVis = menu.style.visibility;

      menu.style.display = "block";
      menu.style.visibility = "hidden";
      const mw = menu.offsetWidth;
      const mh = menu.offsetHeight;
      menu.style.display = prevDisplay;
      menu.style.visibility = prevVis;

      const gap = 10;
      let top = r.bottom + gap;
      let left = r.right - mw;

      const minLeft = 8;
      const maxLeft = window.innerWidth - mw - 8;
      if (left < minLeft) left = minLeft;
      if (left > maxLeft) left = maxLeft;

      const maxTop = window.innerHeight - mh - 8;
      if (top > maxTop) {
        top = r.top - mh - gap;
        if (top < 8) top = 8;
      }

      return { top, left };
    }

    function reposicionar() {
      if (!menuAberto || !menuAnchorBtn) return;
      const { top, left } = calcularPosicao(menuAnchorBtn, menuAberto);
      menuAberto.style.top = `${top}px`;
      menuAberto.style.left = `${left}px`;
    }

    function toggle(btn) {
      const card = btn.closest(".agenda-card");
      const menu = card?.querySelector(".agenda-menu");
      if (!menu) return;

      const jaAberto = menu.classList.contains("aberto");
      fechar();
      if (jaAberto) return;

      menu.classList.add("aberto");
      btn.setAttribute("aria-expanded", "true");

      menuAberto = menu;
      menuOwnerCard = card;
      menuAnchorBtn = btn;

      menuOriginalParent = menu.parentNode;
      menuOriginalNextSibling = menu.nextSibling;

      document.body.appendChild(menu);
      menu.classList.add("menu-flutuante");

      reposicionar();
    }

    window.addEventListener("resize", () => reposicionar(), { passive: true });
    window.addEventListener("scroll", () => reposicionar(), { passive: true, capture: true });

    return { fechar, toggle, reposicionar, getOwnerCard: () => menuOwnerCard };
  }

  return {
    escapeHtml,
    normalizar,
    debounce,
    fetchJSON,
    createFloatingMenuController,
  };
})();
