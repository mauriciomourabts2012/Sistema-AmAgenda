/* ==========================================================
   perfil_foto.js — Universal | Alterar foto do perfil
   ✅ Sempre carrega a foto salva
   ✅ Atualiza todos os avatares
   ✅ Mantém fallback
   ✅ Evita cache antigo
   ✅ Compatível com caminho /public/imagens/usuarios/
   ✅ Padrão visual compatível com .ui-toast-stack + .ui-alert
   ✅ Mensagens no mesmo padrão do sistema
   ✅ Estado visual de carregando no botão "Alterar foto"
========================================================== */
(() => {
  "use strict";

  if (window.__PERFIL_FOTO_JS_INIT__) {
    console.warn("[PerfilFoto] Script já inicializado.");
    return;
  }
  window.__PERFIL_FOTO_JS_INIT__ = true;

  const API_URL = "/public/api/api_central.php?path=perfil/alterar-foto";
  const INPUT_ID = "perfil_foto";
  const MAX_BYTES = 3 * 1024 * 1024;
  const ALLOWED_TYPES = ["image/jpeg", "image/png", "image/webp"];
  const TOAST_TIMEOUT = 3500;
  const TOAST_LEAVE = 180;
  const FALLBACK_AVATAR = "/public/imagens/avatar-default.png";

  const input = document.getElementById(INPUT_ID);
  if (!input) {
    console.warn("[PerfilFoto] Input #perfil_foto não encontrado.");
    return;
  }

  /* ==========================================================
     TOASTS | PADRÃO DO SISTEMA
  ========================================================== */
  function ensureToastStack() {
    let stack = document.querySelector(".ui-toast-stack");
    if (!stack) {
      stack = document.createElement("div");
      stack.className = "ui-toast-stack";
      document.body.appendChild(stack);
    }
    return stack;
  }

  function showToast(message, type = "info", timeout = TOAST_TIMEOUT) {
    const stack = ensureToastStack();
    const el = document.createElement("div");
    el.className = `ui-alert ${type}`;

    const icons = {
      success: "✅",
      danger: "❌",
      warning: "⚠️",
      info: "ℹ️"
    };

    el.innerHTML = `
      <div class="ui-alert-icon">${icons[type] || icons.info}</div>
      <div class="ui-alert-content">${String(message || "Aviso.").trim()}</div>
    `;

    stack.appendChild(el);

    requestAnimationFrame(() => el.classList.add("show"));

    setTimeout(() => {
      el.classList.remove("show");
      el.classList.add("hide");
      setTimeout(() => {
        if (el && el.parentNode) el.remove();
      }, TOAST_LEAVE);
    }, timeout);
  }

  /* ==========================================================
     ESTILO VISUAL DE LOADING NO BOTÃO
  ========================================================== */
  function ensureLoadingStyle() {
    if (document.getElementById("perfil-foto-loading-style")) return;

    const style = document.createElement("style");
    style.id = "perfil-foto-loading-style";
    style.textContent = `
      label[for="${INPUT_ID}"].is-loading {
        pointer-events: none;
        opacity: .75;
        position: relative;
      }

      label[for="${INPUT_ID}"].is-loading::after {
        content: " Enviando...";
        font-weight: 600;
      }
    `;
    document.head.appendChild(style);
  }

  /* ==========================================================
     HELPERS
  ========================================================== */
  function getTriggerLabel() {
    return document.querySelector(`label[for="${CSS.escape(INPUT_ID)}"]`);
  }

  function getPreviewImages() {
    return Array.from(document.querySelectorAll(".perfil-foto, [data-avatar-usuario]"))
      .filter((el) => el && el.tagName === "IMG");
  }

  function appendNoCache(url) {
    try {
      const u = new URL(url, window.location.origin);
      u.searchParams.set("_t", Date.now());
      return u.pathname + u.search;
    } catch (_) {
      const sep = String(url).includes("?") ? "&" : "?";
      return `${url}${sep}_t=${Date.now()}`;
    }
  }

  function normalizeAvatarUrl(url) {
    if (!url || typeof url !== "string") return FALLBACK_AVATAR;

    let value = String(url).trim();
    if (!value) return FALLBACK_AVATAR;

    value = value.replace(/\\/g, "/");
    value = value.replace("/public/imagens/Usuarios/", "/public/imagens/usuarios/");
    value = value.replace("/imagens/Usuarios/", "/imagens/usuarios/");
    value = value.replace("public/imagens/Usuarios/", "public/imagens/usuarios/");
    value = value.replace("imagens/Usuarios/", "imagens/usuarios/");

    if (/^https?:\/\//i.test(value) || value.startsWith("/")) {
      return value;
    }

    return "/" + value.replace(/^\/+/, "");
  }

  function bindFallback(img) {
    if (!img || img.__perfilFotoFallbackBound__) return;
    img.__perfilFotoFallbackBound__ = true;

    img.addEventListener("error", () => {
      if (img.dataset.fallbackApplied === "1") return;
      img.dataset.fallbackApplied = "1";
      img.src = FALLBACK_AVATAR;
    });
  }

  function updateAllAvatars(url) {
    const finalUrl = appendNoCache(normalizeAvatarUrl(url));

    getPreviewImages().forEach((img) => {
      bindFallback(img);
      img.dataset.fallbackApplied = "0";
      img.src = finalUrl;
    });
  }

  function restoreAvatars(state) {
    state.forEach((item) => {
      if (!item || !item.el) return;
      bindFallback(item.el);
      item.el.dataset.fallbackApplied = "0";
      item.el.src = item.src || FALLBACK_AVATAR;
    });
  }

  function getCurrentAvatarState() {
    return getPreviewImages().map((img) => ({
      el: img,
      src: img.getAttribute("src") || img.src || FALLBACK_AVATAR
    }));
  }

  function setBusy(isBusy) {
    const busy = !!isBusy;
    const label = getTriggerLabel();

    input.disabled = busy;
    input.dataset.loading = busy ? "1" : "0";

    if (label) {
      label.classList.toggle("is-loading", busy);
      label.setAttribute("aria-busy", busy ? "true" : "false");
    }
  }

  function descobrirFotoAtualDoSistema() {
    const candidatos = [
      document.querySelector("[data-avatar-usuario]")?.getAttribute("src"),
      document.querySelector(".perfil-foto")?.getAttribute("src"),
      window?.AUTH_USER?.foto_perfil,
      window?.AUTH_USER?.foto_url,
      window?.usuarioLogado?.foto_perfil,
      window?.usuarioLogado?.foto_url
    ];

    for (const item of candidatos) {
      const url = normalizeAvatarUrl(item || "");
      if (url && url !== FALLBACK_AVATAR) {
        return url;
      }
    }

    return FALLBACK_AVATAR;
  }

  function initExistingAvatars() {
    getPreviewImages().forEach((img) => {
      bindFallback(img);

      const srcAtual = String(img.getAttribute("src") || "").trim();
      if (!srcAtual) {
        img.dataset.fallbackApplied = "0";
        img.src = FALLBACK_AVATAR;
        return;
      }

      img.dataset.fallbackApplied = "0";
      img.src = appendNoCache(normalizeAvatarUrl(srcAtual));
    });
  }

  /* ==========================================================
     VALIDAÇÃO
  ========================================================== */
  function validateFile(file) {
    if (!file) {
      return "Selecione uma imagem para continuar.";
    }

    if (!ALLOWED_TYPES.includes(file.type)) {
      return "Formato inválido. Envie uma imagem JPG, PNG ou WEBP.";
    }

    if (file.size > MAX_BYTES) {
      return "A imagem deve ter no máximo 3MB.";
    }

    return "";
  }

  /* ==========================================================
     API
  ========================================================== */
  async function sendPhoto(file) {
    const fd = new FormData();
    fd.append("perfil_foto", file);

    const res = await fetch(API_URL, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json"
      }
    });

    let json = null;
    try {
      json = await res.json();
    } catch (_) {
      throw new Error("Resposta inválida do servidor.");
    }

    if (!res.ok || !json?.ok) {
      throw new Error(
        json?.user_msg ||
        json?.message ||
        "Não foi possível atualizar a foto do perfil."
      );
    }

    return json;
  }

  /* ==========================================================
     EVENTO DE ALTERAÇÃO
  ========================================================== */
  input.addEventListener("change", async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const error = validateFile(file);
    if (error) {
      showToast(error, "warning");
      input.value = "";
      return;
    }

    const previousState = getCurrentAvatarState();
    let localPreviewUrl = "";

    try {
      setBusy(true);

      localPreviewUrl = URL.createObjectURL(file);
      updateAllAvatars(localPreviewUrl);

      const json = await sendPhoto(file);
      const fotoUrl =
        json?.data?.foto_url ||
        json?.data?.foto_perfil ||
        json?.foto_url ||
        json?.foto_perfil ||
        "";

      if (!fotoUrl) {
        throw new Error("A foto foi enviada, mas o retorno da imagem é inválido.");
      }

      updateAllAvatars(fotoUrl);

      document.dispatchEvent(new CustomEvent("perfil:foto-atualizada", {
        detail: {
          foto_url: fotoUrl,
          foto_perfil: fotoUrl
        }
      }));

      showToast(
        json?.user_msg || "Foto do perfil atualizada com sucesso.",
        "success"
      );
    } catch (err) {
      restoreAvatars(previousState);
      showToast(
        err?.message || "Não foi possível atualizar a foto do perfil.",
        "danger"
      );
    } finally {
      if (localPreviewUrl) {
        URL.revokeObjectURL(localPreviewUrl);
      }

      setBusy(false);
      input.value = "";
    }
  });

  /* ==========================================================
     SINCRONIZAÇÃO ENTRE TELAS / MODAIS
  ========================================================== */
  document.addEventListener("perfil:foto-atualizada", (e) => {
    const foto =
      e?.detail?.foto_url ||
      e?.detail?.foto_perfil ||
      "";

    if (foto) {
      updateAllAvatars(foto);
    }
  });

  /* ==========================================================
     INICIALIZAÇÃO
  ========================================================== */
  function boot() {
    ensureLoadingStyle();
    initExistingAvatars();
    updateAllAvatars(descobrirFotoAtualDoSistema());
  }

  document.addEventListener("DOMContentLoaded", boot);

  if (document.readyState !== "loading") {
    boot();
  }
})();