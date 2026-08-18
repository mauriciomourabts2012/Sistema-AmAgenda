/* ==========================================================
   buscar_foto_perfil.js — Perfil | Buscar foto e nome do usuário
   ✅ Busca foto e nome do usuário logado
   ✅ Atualiza a imagem do modal
   ✅ Atualiza todos os avatares [data-avatar-usuario]
   ✅ Atualiza o nome no modal
   ✅ Mantém fallback padrão
   ✅ Evita cache antigo com ?t=
   ✅ Recarrega ao abrir o modal
   ✅ Recarrega após evento perfil:foto-atualizada
========================================================== */
(() => {
  "use strict";

  if (window.__BUSCAR_FOTO_PERFIL_JS_INIT__) {
    console.warn("[BuscarFotoPerfil] Script já inicializado.");
    return;
  }
  window.__BUSCAR_FOTO_PERFIL_JS_INIT__ = true;

  const API_URL = "/public/api/api_central.php?path=perfil/buscar-foto";
  const FALLBACK = "/public/imagens/avatar-default.png";
  const FALLBACK_NAME = "Usuário";

  const modal = document.getElementById("modalPerfilUsuario");
  const nomeEl = document.getElementById("perfilNomeUsuario");

  function normalizeUrl(url) {
    const valor = String(url || "").trim();
    if (!valor) return FALLBACK;
    return valor;
  }

  function withBust(url) {
    const base = normalizeUrl(url);
    return base.includes("?")
      ? `${base}&t=${Date.now()}`
      : `${base}?t=${Date.now()}`;
  }

  function getAvatarElements() {
    return Array.from(document.querySelectorAll("[data-avatar-usuario], .perfil-foto"));
  }

  function applyAvatar(url, temFoto = false) {
    const finalUrl = normalizeUrl(url);

    getAvatarElements().forEach((img) => {
      if (!(img instanceof HTMLImageElement)) return;

      const avatarCabecalho = img.hasAttribute("data-avatar-cabecalho");
      const botao = avatarCabecalho ? img.closest(".botao-perfil-usuario") : null;
      const iconeFallback = botao?.querySelector("[data-icone-perfil-cabecalho]");

      if (avatarCabecalho) {
        img.hidden = !temFoto;
        if (iconeFallback) iconeFallback.hidden = Boolean(temFoto);
      }

      img.onerror = function () {
        this.onerror = null;

        if (avatarCabecalho) {
          this.hidden = true;
          if (iconeFallback) iconeFallback.hidden = false;
          return;
        }

        this.src = withBust(FALLBACK);
      };

      img.src = withBust(finalUrl);
    });
  }

  function applyNome(nome) {
    if (!nomeEl) return;
    nomeEl.textContent = String(nome || "").trim() || FALLBACK_NAME;
  }

  async function buscarFotoPerfil() {
    try {
      const resp = await fetch(API_URL, {
        method: "GET",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        },
        cache: "no-store"
      });

      let json = null;

      try {
        json = await resp.json();
      } catch {
        json = null;
      }

      if (!resp.ok || !json || json.ok !== true) {
        applyAvatar(FALLBACK);
        applyNome(FALLBACK_NAME);
        return;
      }

      const fotoUrl = json?.data?.foto_url || FALLBACK;
      const nome = json?.data?.nome || FALLBACK_NAME;

      applyAvatar(fotoUrl, Boolean(json?.data?.tem_foto));
      applyNome(nome);

      window.dispatchEvent(new CustomEvent("perfil:foto-carregada", {
        detail: json.data || {}
      }));

    } catch (error) {
      console.warn("[BuscarFotoPerfil] Falha ao buscar foto:", error);
      applyAvatar(FALLBACK);
      applyNome(FALLBACK_NAME);
    }
  }

  function modalEstaAberto(el) {
    if (!el) return false;
    if (!el.classList.contains("ativo") && el.getAttribute("aria-hidden") !== "false") {
      return false;
    }
    return true;
  }

  function observarModal() {
    if (!modal) return;

    const observer = new MutationObserver(() => {
      if (modalEstaAberto(modal)) {
        buscarFotoPerfil();
      }
    });

    observer.observe(modal, {
      attributes: true,
      attributeFilter: ["class", "aria-hidden"]
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    applyAvatar(FALLBACK);
    applyNome("Carregando...");
    buscarFotoPerfil();
    observarModal();
  });

  window.addEventListener("perfil:foto-atualizada", () => {
    buscarFotoPerfil();
  });

  window.buscarFotoPerfilUsuario = buscarFotoPerfil;
})();
