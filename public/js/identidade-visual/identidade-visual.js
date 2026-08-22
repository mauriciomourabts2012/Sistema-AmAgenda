/* Identidade Visual global: aplica a marca no menu e oferece edição ao proprietário. */
(() => {
  "use strict";
  if (window.__AMAGENDA_IDENTIDADE_INIT__) return;
  window.__AMAGENDA_IDENTIDADE_INIT__ = true;

  const API = "/api/api_central.php?path=";
  const PADRAO = {
    nome_exibicao: "AmAgenda",
    logo_url: "/public/imagens/logo-menu.png",
    imagem_login_url: "/public/imagens/logo.png",
    imagem_login_escala: 100,
    imagem_login_pos_x: 0,
    imagem_login_pos_y: 0,
    personalizada: false
  };
  const MAX_BYTES = 5 * 1024 * 1024;
  const TIPOS = ["image/jpeg", "image/png", "image/webp"];
  const LOGIN_LADO_MENOR_MIN = 400;
  const LOGIN_LADO_MAIOR_MIN = 800;
  let identidade = { ...PADRAO };
  let carregamento = null;
  let auth = window.__AUTH__ || null;
  let urlsTemporarias = [];
  let focoAnterior = null;

  function urlSemCache(url) {
    const valor = String(url || "");
    if (!valor.startsWith("/uploads/")) return valor;
    return `${valor}${valor.includes("?") ? "&" : "?"}v=${Date.now()}`;
  }

  function aplicarFavicon() {
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

  function podeEditar() {
    const sessaoAtual = window.__AUTH__ || auth || {};
    auth = sessaoAtual;
    const perfil = String(sessaoAtual.perfil_nome || sessaoAtual.perfil || "")
      .trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    const superAdminSuporte = String(sessaoAtual.tipo_usuario || "").toLowerCase() === "super_admin"
      && sessaoAtual.modo_suporte === true
      && Number(sessaoAtual.empresa_id || sessaoAtual.id_empresa || 0) > 0;
    return perfil === "proprietario" || superAdminSuporte;
  }

  function sessaoSuperAdmin() {
    const sessaoAtual = window.__AUTH__ || auth || {};
    return String(sessaoAtual.tipo_usuario || "").toLowerCase() === "super_admin";
  }

  function superAdminForaDeEmpresa() {
    const sessaoAtual = window.__AUTH__ || auth || {};
    return sessaoSuperAdmin()
      && !(sessaoAtual.modo_suporte === true && Number(sessaoAtual.empresa_id || sessaoAtual.id_empresa || 0) > 0);
  }

  async function garantirAuth() {
    if (window.__AUTH__ || auth) {
      auth = window.__AUTH__ || auth;
      return auth;
    }
    try {
      const resposta = await fetch(`${API}_auth/session`, {
        credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }
      });
      const json = await resposta.json();
      if (resposta.ok && json?.ok) {
        auth = json.data?.user || json.data || null;
        window.__AUTH__ = auth;
        if (superAdminForaDeEmpresa()) aplicar(PADRAO);
      }
    } catch (_) {}
    atualizarPermissaoMarca();
    return auth;
  }

  function aplicar(data) {
    identidade = { ...PADRAO, ...(data || {}) };
    document.querySelectorAll("[data-identidade-nome]").forEach(el => { el.textContent = identidade.nome_exibicao; });
    document.querySelectorAll("[data-identidade-logo]").forEach(img => {
      img.src = urlSemCache(identidade.logo_url);
      img.alt = `Logo ${identidade.nome_exibicao}`;
      img.onerror = () => { img.onerror = null; img.src = PADRAO.logo_url; };
    });
    aplicarFavicon();
    atualizarPermissaoMarca();
  }

  async function carregar(forcar = false) {
    if (carregamento && !forcar) return carregamento;
    carregamento = fetch(`${API}empresa/identidade-visual/publica`, {
      credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }
    }).then(r => r.json()).then(json => {
      aplicar(superAdminForaDeEmpresa() ? PADRAO : (json?.ok ? json.data : PADRAO));
      return identidade;
    }).catch(() => { aplicar(PADRAO); return identidade; });
    return carregamento;
  }

  function toast(mensagem, tipo = "success") {
    let stack = document.querySelector(".ui-toast-stack");
    if (!stack) { stack = document.createElement("div"); stack.className = "ui-toast-stack"; document.body.appendChild(stack); }
    const box = document.createElement("div");
    box.className = `ui-alert ui-alert--${tipo}`;
    box.innerHTML = '<div class="ui-alert__icon" aria-hidden="true"></div><div class="ui-alert__content"><p class="ui-alert__title"></p><div class="ui-alert__msg"></div></div>';
    box.querySelector(".ui-alert__icon").textContent = tipo === "danger" ? "×" : "✓";
    box.querySelector(".ui-alert__title").textContent = tipo === "danger" ? "Atenção" : "Sucesso";
    box.querySelector(".ui-alert__msg").textContent = mensagem;
    stack.appendChild(box);
    setTimeout(() => { box.classList.add("is-leaving"); setTimeout(() => box.remove(), 180); }, 3500);
  }

  function confirmarRestauracao() {
    return new Promise(resolve => {
      let stack = document.querySelector(".ui-toast-stack");
      if (!stack) { stack = document.createElement("div"); stack.className = "ui-toast-stack"; document.body.appendChild(stack); }
      const box = document.createElement("div");
      box.className = "ui-alert ui-alert--confirm";
      box.innerHTML = '<div class="ui-alert__icon" aria-hidden="true">?</div><div class="ui-alert__content"><p class="ui-alert__title">Restaurar identidade visual?</p><div class="ui-alert__msg">O nome, a logo e a imagem de login personalizados serão removidos. A identidade padrão do AmAgenda voltará a ser utilizada.</div></div><div class="ui-alert__actions"><button type="button" class="ui-alert__btn js-cancelar">Cancelar</button><button type="button" class="ui-alert__btn ui-alert__btn--primary js-confirmar">Restaurar</button></div>';
      let finalizado = false;
      const fechar = valor => { if (finalizado) return; finalizado = true; document.removeEventListener("keydown", tecla); box.remove(); resolve(valor); };
      const tecla = e => { if (e.key === "Escape") fechar(false); };
      box.querySelector(".js-cancelar").onclick = () => fechar(false);
      box.querySelector(".js-confirmar").onclick = () => fechar(true);
      document.addEventListener("keydown", tecla);
      stack.appendChild(box);
      box.querySelector(".js-confirmar").focus();
    });
  }

  function criarModal() {
    if (document.getElementById("modalIdentidadeVisual")) return;
    const modal = document.createElement("div");
    modal.id = "modalIdentidadeVisual";
    modal.className = "modal-geral identidade-visual";
    modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = `
      <div class="modal-conteudo identidade-visual__conteudo" role="dialog" aria-modal="true" aria-labelledby="tituloIdentidadeVisual">
        <div class="modal-topo"><h2 id="tituloIdentidadeVisual">Identidade Visual</h2><button class="modal-fechar" type="button" data-identidade-fechar aria-label="Fechar">×</button></div>
        <div class="modal-corpo">
          <p class="identidade-visual__intro">Personalize como sua empresa aparece no sistema e para seus clientes.</p>
          <form id="formIdentidadeVisual" novalidate>
            <div class="modal-campo identidade-visual__nome"><input id="identidadeNome" name="nome_exibicao" maxlength="80" placeholder=" "><label for="identidadeNome">Nome exibido</label><small class="msg-erro" data-identidade-erro></small></div>
            <div class="identidade-visual__grade">
              <section class="identidade-visual__bloco"><h3>Logo da empresa</h3><div class="identidade-visual__logo-linha"><img id="identidadePreviewLogo" class="identidade-visual__preview-logo" alt="Prévia da logo"><label class="botao-geral identidade-visual__upload" for="identidadeLogo">Alterar imagem</label><input id="identidadeLogo" name="logo_empresa" type="file" accept="image/png,image/jpeg,image/webp" hidden></div><small>PNG, JPG ou WebP • máximo 5 MB</small></section>
              <section class="identidade-visual__bloco identidade-visual__bloco-login"><h3>Imagem da tela de login</h3><div class="identidade-visual__preview-login-moldura"><img id="identidadePreviewLogin" class="identidade-visual__preview-login" alt="Prévia da imagem de login"><span>Prévia do enquadramento</span></div><div class="identidade-visual__ajustes"><label>Zoom <output id="identidadeEscalaValor">100%</output><input id="identidadeEscala" name="imagem_login_escala" type="range" min="60" max="150" value="100"></label><label>Posição horizontal <output id="identidadePosXValor">0</output><input id="identidadePosX" name="imagem_login_pos_x" type="range" min="-30" max="30" value="0"></label><label>Posição vertical <output id="identidadePosYValor">0</output><input id="identidadePosY" name="imagem_login_pos_y" type="range" min="-30" max="30" value="0"></label><button id="identidadeResetarEnquadramento" class="identidade-visual__reset-enquadramento" type="button">Restaurar enquadramento</button></div><label class="botao-geral identidade-visual__upload" for="identidadeLogin">Alterar imagem</label><input id="identidadeLogin" name="imagem_login" type="file" accept="image/png,image/jpeg,image/webp" hidden><small class="identidade-visual__requisitos"><b>Aceita imagem vertical, horizontal ou quadrada.</b> O lado menor deve ter pelo menos 400 px e o maior pelo menos 800 px. JPG, PNG ou WebP, máximo 5 MB. A imagem ocupará o espaço do mascote, preservando o fundo, o slogan e os benefícios do layout padrão.</small><small id="identidadeLoginDimensoes" class="identidade-visual__dimensoes" aria-live="polite"></small></section>
            </div>
            <section class="identidade-visual__resultado" aria-label="Pré-visualização da marca"><span>Pré-visualização</span><div><img id="identidadePreviewMarcaLogo" alt=""><strong id="identidadePreviewMarcaNome">AmAgenda</strong></div></section>
            <div class="modal-acoes identidade-visual__acoes"><button id="identidadeRestaurar" class="botao-geral identidade-visual__restaurar" type="button">Restaurar padrão</button><span class="identidade-visual__acoes-direita"><button class="botao-geral" type="button" data-identidade-fechar>Cancelar</button><button id="identidadeSalvar" class="botao-geral destaque" type="submit">Salvar alterações</button></span></div>
          </form>
        </div>
      </div>`;
    document.body.appendChild(modal);
    modal.querySelectorAll("[data-identidade-fechar]").forEach(b => b.addEventListener("click", fecharModal));
    // O overlay não fecha este modal: evita perder nome e imagens selecionadas acidentalmente.
    modal.addEventListener("click", e => {
      if (e.target === modal) e.stopPropagation();
    });
    modal.querySelector("#identidadeNome").addEventListener("input", atualizarPreviewMarca);
    modal.querySelector("#identidadeLogo").addEventListener("change", e => previewArquivo(e.target, "#identidadePreviewLogo", true));
    modal.querySelector("#identidadeLogin").addEventListener("change", e => previewArquivo(e.target, "#identidadePreviewLogin", false, true));
    modal.querySelector("#identidadePreviewLogin").addEventListener("load", atualizarDimensoesPreviewLogin);
    ["#identidadeEscala", "#identidadePosX", "#identidadePosY"].forEach(seletor => modal.querySelector(seletor).addEventListener("input", aplicarAjustePreview));
    modal.querySelector("#identidadeResetarEnquadramento").addEventListener("click", () => {
      modal.querySelector("#identidadeEscala").value = "100";
      modal.querySelector("#identidadePosX").value = "0";
      modal.querySelector("#identidadePosY").value = "0";
      aplicarAjustePreview();
    });
    modal.querySelector("#formIdentidadeVisual").addEventListener("submit", salvar);
    modal.querySelector("#identidadeRestaurar").addEventListener("click", restaurar);
  }

  function limparUrls() { urlsTemporarias.forEach(URL.revokeObjectURL); urlsTemporarias = []; }

  function preencherModal() {
    limparUrls();
    const modal = document.getElementById("modalIdentidadeVisual");
    modal.querySelector("#formIdentidadeVisual").reset();
    modal.querySelector("#identidadeNome").value = identidade.personalizada && identidade.nome_exibicao !== PADRAO.nome_exibicao ? identidade.nome_exibicao : "";
    modal.querySelector("#identidadePreviewLogo").src = urlSemCache(identidade.logo_url);
    modal.querySelector("#identidadePreviewLogin").src = urlSemCache(identidade.imagem_login_url);
    modal.querySelector("#identidadeEscala").value = String(identidade.imagem_login_escala ?? 100);
    modal.querySelector("#identidadePosX").value = String(identidade.imagem_login_pos_x ?? 0);
    modal.querySelector("#identidadePosY").value = String(identidade.imagem_login_pos_y ?? 0);
    aplicarAjustePreview();
    atualizarPreviewMarca();
  }

  function atualizarPreviewMarca() {
    const modal = document.getElementById("modalIdentidadeVisual");
    if (!modal) return;
    const nome = modal.querySelector("#identidadeNome").value.trim() || PADRAO.nome_exibicao;
    modal.querySelector("#identidadePreviewMarcaNome").textContent = nome;
    modal.querySelector("#identidadePreviewMarcaLogo").src = modal.querySelector("#identidadePreviewLogo").src || PADRAO.logo_url;
  }

  function atualizarDimensoesPreviewLogin() {
    const modal = document.getElementById("modalIdentidadeVisual");
    const imagem = modal?.querySelector("#identidadePreviewLogin");
    const saida = modal?.querySelector("#identidadeLoginDimensoes");
    if (!imagem || !saida) return;
    saida.textContent = imagem.naturalWidth && imagem.naturalHeight
      ? `Imagem atual: ${imagem.naturalWidth} × ${imagem.naturalHeight} px`
      : "";
  }

  function aplicarAjustePreview() {
    const modal = document.getElementById("modalIdentidadeVisual");
    if (!modal) return;
    const escala = Number(modal.querySelector("#identidadeEscala").value || 100);
    const posX = Number(modal.querySelector("#identidadePosX").value || 0);
    const posY = Number(modal.querySelector("#identidadePosY").value || 0);
    modal.querySelector("#identidadePreviewLogin").style.transform = `translate(${posX}%, ${posY}%) scale(${escala / 100})`;
    modal.querySelector("#identidadeEscalaValor").value = `${escala}%`;
    modal.querySelector("#identidadePosXValor").value = String(posX);
    modal.querySelector("#identidadePosYValor").value = String(posY);
  }

  function lerDimensoes(arquivo) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(arquivo);
      const imagem = new Image();
      imagem.onload = () => {
        const dimensoes = { largura: imagem.naturalWidth, altura: imagem.naturalHeight };
        URL.revokeObjectURL(url);
        resolve(dimensoes);
      };
      imagem.onerror = () => { URL.revokeObjectURL(url); reject(new Error("Imagem inválida.")); };
      imagem.src = url;
    });
  }

  async function previewArquivo(input, seletor, sincronizarLogo, validarLogin = false) {
    const arquivo = input.files?.[0];
    if (!arquivo) return;
    if (!TIPOS.includes(arquivo.type) || arquivo.size > MAX_BYTES) {
      toast("Envie uma imagem JPG, PNG ou WebP de até 5 MB.", "danger"); input.value = ""; return;
    }
    if (validarLogin) {
      try {
        const { largura, altura } = await lerDimensoes(arquivo);
        if (Math.min(largura, altura) < LOGIN_LADO_MENOR_MIN || Math.max(largura, altura) < LOGIN_LADO_MAIOR_MIN) {
          toast("A imagem precisa ter o lado menor com pelo menos 400 px e o maior com pelo menos 800 px.", "danger"); input.value = ""; return;
        }
      } catch (_) {
        toast("Não foi possível ler a imagem selecionada.", "danger"); input.value = ""; return;
      }
    }
    const url = URL.createObjectURL(arquivo); urlsTemporarias.push(url);
    document.querySelector(seletor).src = url;
    if (sincronizarLogo) atualizarPreviewMarca();
  }

  function setBusy(ativo, texto = "Salvando...") {
    const modal = document.getElementById("modalIdentidadeVisual");
    modal.querySelectorAll("button, input").forEach(el => { el.disabled = ativo; });
    const salvar = modal.querySelector("#identidadeSalvar");
    salvar.textContent = ativo ? texto : "Salvar alterações";
  }

  function abrirModal() {
    if (!podeEditar()) return;
    criarModal(); preencherModal(); focoAnterior = document.activeElement;
    const modal = document.getElementById("modalIdentidadeVisual");
    modal.classList.add("ativo"); modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-aberto");
    setTimeout(() => modal.querySelector("#identidadeNome").focus(), 0);
  }

  function fecharModal() {
    const modal = document.getElementById("modalIdentidadeVisual");
    if (!modal) return;
    modal.classList.remove("ativo"); modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-aberto"); limparUrls();
    modal.querySelector("#formIdentidadeVisual").reset();
    focoAnterior?.focus?.();
  }

  async function salvar(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const nome = form.querySelector("#identidadeNome").value.trim();
    if (nome.length > 80) { toast("O nome exibido deve ter no máximo 80 caracteres.", "danger"); return; }
    // FormData deve ser criado antes de desabilitar os inputs; controles disabled não são enviados.
    const dados = new FormData(form);
    setBusy(true);
    try {
      const resposta = await fetch(`${API}empresa/identidade-visual/salvar`, { method: "POST", credentials: "same-origin", headers: { Accept: "application/json" }, body: dados });
      const json = await resposta.json();
      if (!resposta.ok || !json?.ok) throw new Error(json?.user_msg || "Não foi possível salvar.");
      aplicar(json.data); document.dispatchEvent(new CustomEvent("amagenda:identidade-atualizada", { detail: identidade }));
      fecharModal(); toast(json.user_msg || "Identidade visual atualizada.");
    } catch (erro) { toast(erro.message || "Não foi possível salvar.", "danger"); }
    finally { setBusy(false); }
  }

  async function restaurar() {
    if (!(await confirmarRestauracao())) return;
    setBusy(true, "Restaurando...");
    try {
      const resposta = await fetch(`${API}empresa/identidade-visual/restaurar`, { method: "POST", credentials: "same-origin", headers: { Accept: "application/json" } });
      const json = await resposta.json();
      if (!resposta.ok || !json?.ok) throw new Error(json?.user_msg || "Não foi possível restaurar.");
      aplicar(json.data); document.dispatchEvent(new CustomEvent("amagenda:identidade-atualizada", { detail: identidade }));
      fecharModal(); toast(json.user_msg || "Identidade padrão restaurada.");
    } catch (erro) { toast(erro.message || "Não foi possível restaurar.", "danger"); }
    finally { setBusy(false); }
  }

  function atualizarPermissaoMarca() {
    document.querySelectorAll("[data-identidade-abrir]").forEach(marca => {
      const permitido = podeEditar();
      marca.classList.toggle("identidade-editavel", permitido);
      marca.tabIndex = permitido ? 0 : -1;
      marca.setAttribute("role", permitido ? "button" : "group");
      marca.setAttribute("aria-label", permitido ? "Abrir Identidade Visual" : "Identidade visual da empresa");
    });
  }

  document.addEventListener("click", async e => {
    if (e.target.closest("#fecharSidebarAgenda")) return;
    if (!e.target.closest("[data-identidade-abrir]")) return;
    await garantirAuth();
    if (podeEditar()) abrirModal();
    else toast("Somente o proprietário pode alterar a identidade visual.", "danger");
  });
  document.addEventListener("keydown", async e => {
    if ((e.key === "Enter" || e.key === " ") && !e.target.closest?.("#fecharSidebarAgenda") && e.target.closest?.("[data-identidade-abrir]")) {
      e.preventDefault();
      await garantirAuth();
      if (podeEditar()) abrirModal();
      else toast("Somente o proprietário pode alterar a identidade visual.", "danger");
    }
    if (e.key === "Escape" && document.getElementById("modalIdentidadeVisual")?.classList.contains("ativo")) fecharModal();
  });
  document.addEventListener("amagenda:sessao-carregada", e => {
    auth = e.detail;
    if (superAdminForaDeEmpresa()) aplicar(PADRAO);
    atualizarPermissaoMarca();
  });
  document.addEventListener("DOMContentLoaded", () => { criarModal(); carregar(); garantirAuth(); atualizarPermissaoMarca(); });
  window.AmAgendaIdentidade = { carregar, obter: () => ({ ...identidade }) };
})();
