/* Carrega somente os dados públicos necessários antes da autenticação. */
(() => {
  "use strict";
  const PADRAO = { nome_exibicao: "AmAgenda", logo_url: "/public/imagens/logo-menu.png", imagem_login_url: "/public/imagens/logo.png", imagem_login_escala: 100, imagem_login_pos_x: 0, imagem_login_pos_y: 0, personalizada: false };

  function aplicar(data) {
    const identidade = { ...PADRAO, ...(data || {}) };
    document.documentElement.classList.toggle("login-identidade-personalizada", identidade.personalizada === true);
    document.documentElement.classList.toggle("login-imagem-personalizada", Boolean(identidade.imagem_login_url) && identidade.imagem_login_url !== PADRAO.imagem_login_url);
    document.documentElement.style.setProperty("--login-img-scale", String(Number(identidade.imagem_login_escala || 100) / 100));
    document.documentElement.style.setProperty("--login-img-pos-x", `${Number(identidade.imagem_login_pos_x || 0)}%`);
    document.documentElement.style.setProperty("--login-img-pos-y", `${Number(identidade.imagem_login_pos_y || 0)}%`);
    document.querySelectorAll("[data-identidade-login]").forEach(img => {
      img.src = identidade.imagem_login_url;
      img.alt = `Imagem de login ${identidade.nome_exibicao}`;
      img.onerror = () => {
        img.onerror = null;
        img.src = PADRAO.imagem_login_url;
      };
    });
    document.querySelectorAll("[data-identidade-login-nome]").forEach(el => {
      el.textContent = String(identidade.nome_exibicao || PADRAO.nome_exibicao);
    });
    document.querySelectorAll("[data-identidade-login-logo]").forEach(img => {
      img.src = identidade.logo_url || PADRAO.logo_url;
      img.onerror = () => { img.onerror = null; img.src = PADRAO.logo_url; };
    });
    document.title = `${identidade.nome_exibicao || PADRAO.nome_exibicao} • Login`;
  }

  fetch("/api/api_central.php?path=empresa/identidade-visual/publica", {
    method: "GET", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }
  }).then(r => r.json()).then(json => aplicar(json?.ok ? json.data : PADRAO)).catch(() => aplicar(PADRAO));
})();
