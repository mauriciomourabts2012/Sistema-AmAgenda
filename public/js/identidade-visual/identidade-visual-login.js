/* Carrega somente os dados públicos necessários antes da autenticação. */
(() => {
  "use strict";
  const PADRAO = { nome_exibicao: "AmAgenda", logo_url: "/public/imagens/logo-menu.png", imagem_login_url: "/public/imagens/logo.png", imagem_login_escala: 100, imagem_login_pos_x: 0, imagem_login_pos_y: 0, cor_primaria: "#1163DD", cor_hover: "#0F57C5", cor_suave: "#EEF5FF", cor_borda: "#6591D4", cor_foco: "#1163DD", cor_contraste: "#FFFFFF", cor_texto: "#1163DD", cor_rgb: "17, 99, 221", personalizada: false };
  const TOKENS_CSS = { cor_primaria: "--am-marca", cor_hover: "--am-marca-hover", cor_suave: "--am-marca-suave", cor_borda: "--am-marca-borda", cor_foco: "--am-marca-foco", cor_contraste: "--am-marca-contraste", cor_texto: "--am-marca-texto", cor_rgb: "--am-marca-rgb" };

  function contextoEmpresarialExplicito() {
    const empresaInterna = window.AMAGENDA_EMPRESA;
    return Number(empresaInterna?.id || 0) > 0 || Number(window.AMAGENDA_EMPRESA_ID || 0) > 0;
  }

  function aplicarTokens(identidade, permitido) {
    const estilo = document.documentElement.style;
    Object.values(TOKENS_CSS).forEach(token => estilo.removeProperty(token));
    // Sem cor personalizada não define tokens: os fallbacks do CSS mantêm o visual oficial.
    if (!permitido || identidade?.cor_personalizada !== true) return;
    Object.entries(TOKENS_CSS).forEach(([campo, token]) => {
      const valor = String(identidade?.[campo] || "").trim();
      if (valor !== "") estilo.setProperty(token, valor);
    });
  }

  function urlSemCache(url) {
    const valor = String(url || "");
    if (!valor.startsWith("/uploads/")) return valor;
    return `${valor}${valor.includes("?") ? "&" : "?"}v=${Date.now()}`;
  }

  function aplicarFavicon(identidade) {
    const favicons = [...document.querySelectorAll('link[rel~="icon"]')];
    const favicon = favicons.shift() || document.createElement("link");
    favicons.forEach(item => item.remove());

    if (!favicon.isConnected) document.head.appendChild(favicon);
    favicon.rel = "icon";
    favicon.removeAttribute("type");

    const logoUrl = String(identidade.logo_url || "").trim();
    const temLogoPersonalizada = identidade.personalizada === true
      && logoUrl !== ""
      && logoUrl !== PADRAO.logo_url;

    favicon.onerror = temLogoPersonalizada
      ? () => {
          favicon.onerror = null;
          favicon.href = PADRAO.logo_url;
        }
      : null;
    favicon.href = temLogoPersonalizada ? urlSemCache(logoUrl) : PADRAO.logo_url;
  }

  function aplicar(data, contextoEmpresa) {
    const identidade = { ...PADRAO, ...(data || {}) };
    aplicarTokens(identidade, contextoEmpresa);
    document.documentElement.classList.toggle("login-identidade-personalizada", identidade.personalizada === true);
    document.documentElement.classList.toggle("login-imagem-personalizada", Boolean(identidade.imagem_login_url) && identidade.imagem_login_url !== PADRAO.imagem_login_url);
    document.documentElement.style.setProperty("--login-img-scale", String(Number(identidade.imagem_login_escala || 100) / 100));
    document.documentElement.style.setProperty("--login-img-pos-x", `${Number(identidade.imagem_login_pos_x || 0)}%`);
    document.documentElement.style.setProperty("--login-img-pos-y", `${Number(identidade.imagem_login_pos_y || 0)}%`);
    document.querySelectorAll("[data-identidade-login]").forEach(img => {
      // Proporção natural usada pela regra única de enquadramento (identidade-visual.css).
      const publicarProporcao = () => {
        if (img.naturalWidth && img.naturalHeight) {
          img.style.setProperty("--login-img-ratio", String(img.naturalWidth / img.naturalHeight));
        }
      };
      img.addEventListener("load", publicarProporcao);
      img.src = identidade.imagem_login_url;
      if (img.complete) publicarProporcao();
      img.alt = `Imagem de login ${identidade.nome_exibicao}`;
      img.onerror = () => {
        img.onerror = null;
        img.src = PADRAO.imagem_login_url;
      };
    });
    document.querySelectorAll("[data-identidade-login-nome]").forEach(el => {
      el.textContent = String(identidade.nome_exibicao || PADRAO.nome_exibicao);
    });
    // Texto voltado ao cliente: troca o nome cadastrado (vindo do servidor) apenas
    // pelo nome exibido personalizado da empresa; nunca pelo nome padrão AmAgenda.
    const nomeEmpresa = String(identidade.nome_exibicao || "").trim();
    if (identidade.personalizada === true && nomeEmpresa !== "" && nomeEmpresa !== PADRAO.nome_exibicao) {
      document.querySelectorAll("[data-identidade-login-empresa]").forEach(el => { el.textContent = nomeEmpresa; });
    }
    document.querySelectorAll("[data-identidade-login-logo]").forEach(img => {
      img.src = identidade.logo_url || PADRAO.logo_url;
      img.onerror = () => { img.onerror = null; img.src = PADRAO.logo_url; };
    });
    aplicarFavicon(identidade);
    document.title = `${identidade.nome_exibicao || PADRAO.nome_exibicao} • Login`;
  }

  const contextoEmpresa = contextoEmpresarialExplicito();
  if (!contextoEmpresa) {
    aplicar(PADRAO, false);
    return;
  }

  fetch("/api/api_central.php?path=empresa/identidade-visual/publica", {
    method: "GET", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }
  }).then(r => r.json()).then(json => aplicar(json?.ok ? json.data : PADRAO, true)).catch(() => aplicar(PADRAO, true));
})();
