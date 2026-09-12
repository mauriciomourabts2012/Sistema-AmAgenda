(() => {
  "use strict";

  const page = document.querySelector("[data-pagina-aceite]");
  if (!page) return;

  const api = page.dataset.api;
  const form = document.getElementById("formAceite");
  const list = document.getElementById("listaDocumentos");
  const loading = document.getElementById("estadoCarregando");
  const noPending = document.getElementById("estadoSemPendencias");
  const screen = document.getElementById("conteudoAceite");
  const alert = document.getElementById("mensagemErro");
  const accept = document.getElementById("btnAceitar");
  const refuse = document.getElementById("btnRecusar");
  const checkTerms = document.getElementById("confirmarTermos");
  const checkPrivacy = document.getElementById("confirmarPrivacidade");
  const majorityWrap = document.getElementById("blocoMaioridade");
  const checkMajority = document.getElementById("confirmarMaioridade");
  let pending = [];
  let applicable = [];
  let actorType = "";
  let csrfToken = "";
  let homeDestination = "";
  let logoutDestination = "/public/views/login-cliente.php";
  let sending = false;

  function apiError(message, options = {}) {
    return Object.assign(new Error(message), options);
  }

  async function request(path, options = {}) {
    const { headers = {}, ...requestOptions } = options;
    const response = await fetch(`${api}?path=${path}`, {
      credentials: "same-origin",
      cache: "no-store",
      ...requestOptions,
      headers: { Accept: "application/json", ...headers }
    });
    const payload = await response.json().catch(() => null);
    const code = typeof payload?.code === "string" ? payload.code : "";
    const sessionExpired = response.status === 401
      || code === "NOT_AUTHENTICATED"
      || code === "CLIENT_NOT_AUTHENTICATED"
      || code === "SESSION_USER_INACTIVE"
      || code === "SESSION_COMPANY_LINK_INVALID";

    if (!response.ok || payload?.ok !== true) {
      throw apiError(payload?.user_msg || "Não foi possível comunicar com o servidor.", {
        status: response.status,
        code,
        sessionExpired
      });
    }
    return payload;
  }

  function resolveDestinations(user) {
    const type = String(user.tipo_usuario || "").toLowerCase();
    const profile = String(user.perfil_nome || user.perfil || "")
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

    if (type === "cliente") {
      return { home: "/public/views/cliente-perfil.html", logout: "/public/views/login-cliente.php" };
    }
    if (type === "super_admin") {
      return {
        home: user.modo_suporte === true
          ? "/views/painel-administrativo/painel-administrativo.html"
          : "/views/super-admin/painel-super-admin.html",
        logout: "/public/views/login-super-admin.html"
      };
    }
    if (profile === "proprietario") {
      return { home: "/views/painel-administrativo/painel-administrativo.html", logout: "/public/views/login-cliente.php" };
    }
    if (profile === "profissional" || profile === "recepcionista") {
      return { home: "/views/agenda.html", logout: "/public/views/login-cliente.php" };
    }
    throw apiError("Não foi possível identificar a página inicial deste acesso.");
  }

  function safeLogoutRedirect(value) {
    if (typeof value !== "string" || !value.startsWith("/")) return null;
    const url = new URL(value, window.location.origin);
    if (url.origin !== window.location.origin) return null;
    if (url.pathname === "/public/views/login-super-admin.html") return url.href;
    if (url.pathname === "/login.php") return url.href;
    return null;
  }

  function showError(message) {
    alert.textContent = message || "Não foi possível concluir. Tente novamente.";
    alert.hidden = false;
  }

  function clearError() {
    alert.hidden = true;
    alert.textContent = "";
  }

  function updateButton() {
    const majorityOk = actorType !== "cliente" || checkMajority.checked;
    accept.disabled = sending || !checkTerms.checked || !checkPrivacy.checked || !majorityOk;
  }

  function createDocumentCard(documento) {
    const article = document.createElement("article");
    const header = document.createElement("header");
    const headingGroup = document.createElement("div");
    const heading = document.createElement("h2");
    const requirement = document.createElement("p");
    const badge = document.createElement("span");
    const documentContent = document.createElement("div");
    const spinner = document.createElement("div");

    article.className = "am-legal-item";
    article.dataset.codigo = documento.codigo;
    header.className = "am-legal-item__head";
    heading.textContent = documento.titulo;
    requirement.textContent = documento.tipo_manifestacao === "ciencia" ? "Ciência obrigatória" : "Aceite obrigatório";
    badge.className = "am-badge";
    badge.textContent = `Versão ${documento.versao}`;
    documentContent.className = "am-legal-item__content am-document";
    spinner.className = "am-spinner";
    spinner.setAttribute("aria-label", "Carregando documento");

    headingGroup.append(heading, requirement);
    header.append(headingGroup, badge);
    documentContent.append(spinner);
    article.append(header, documentContent);
    return article;
  }

  async function loadDocument(documento, card) {
    const payload = await request(`documentos-legais/conteudo&codigo=${encodeURIComponent(documento.codigo)}`);
    if (payload.code !== "LEGAL_DOCUMENT_CONTENT") throw apiError("O servidor retornou um documento inválido.");
    const item = payload.data?.documento;
    if (!item || item.codigo !== documento.codigo || item.versao !== documento.versao
      || item.tipo_manifestacao !== documento.tipo_manifestacao
      || typeof item.conteudo_html !== "string" || item.conteudo_html.trim() === "") {
      throw apiError("O conteúdo obrigatório não está disponível.");
    }
    card.querySelector(".am-legal-item__content").innerHTML = item.conteudo_html;
  }

  async function init() {
    if (!api) {
      loading.hidden = true;
      screen.hidden = false;
      showError("A configuração da API não está disponível.");
      return;
    }

    try {
      const sessionPayload = await request("_auth/session");
      const user = sessionPayload.data?.user;
      if (!user || typeof user !== "object") throw apiError("A sessão retornada é inválida.");
      const destinations = resolveDestinations(user);
      homeDestination = destinations.home;
      logoutDestination = destinations.logout;

      const pendingPayload = await request("documentos-legais/pendencias");
      if (pendingPayload.code !== "LEGAL_DOCUMENTS_PENDING_LISTED") {
        throw apiError("Não foi possível validar os documentos pendentes.");
      }
      const data = pendingPayload.data;
      actorType = String(data?.tipo_manifestante || "");
      csrfToken = typeof data?.csrf_token === "string" ? data.csrf_token : "";
      pending = Array.isArray(data?.pendencias) ? data.pendencias : [];
      applicable = Array.isArray(data?.documentos_aplicaveis) ? data.documentos_aplicaveis : [];

      if (!csrfToken || !/^[a-f0-9]{64}$/.test(csrfToken)) {
        throw apiError("Não foi possível proteger esta operação. Atualize a página.");
      }
      if (data?.possui_pendencias !== (pending.length > 0)) {
        throw apiError("A resposta de pendências é inconsistente.");
      }
      if (pending.length === 0) {
        loading.hidden = true;
        noPending.hidden = false;
        window.setTimeout(() => window.location.replace(homeDestination), 700);
        return;
      }

      const requiredCodes = {
        representante_empresa: ["politica_privacidade", "termos_empresa"],
        usuario_empresa: ["politica_privacidade", "termos_usuario"],
        cliente: ["politica_privacidade", "termos_cliente"],
        super_admin: ["politica_privacidade", "termo_super_admin"]
      }[actorType];
      const applicableCodes = applicable.map((item) => item?.codigo).sort();
      if (!requiredCodes || applicableCodes.length !== 2
        || applicableCodes.some((codigo, index) => codigo !== requiredCodes[index])
        || applicable.some((item) => typeof item.titulo !== "string"
          || typeof item.versao !== "string"
          || !["aceite", "ciencia"].includes(item.tipo_manifestacao))) {
        throw apiError("Os documentos obrigatórios ainda não estão disponíveis.");
      }

      majorityWrap.hidden = actorType !== "cliente";
      list.textContent = "";
      const cards = applicable.map((documento) => {
        const card = createDocumentCard(documento);
        list.append(card);
        return [documento, card];
      });
      await Promise.all(cards.map(([documento, card]) => loadDocument(documento, card)));
      loading.hidden = true;
      screen.hidden = false;
      updateButton();
    } catch (error) {
      if (error.sessionExpired) {
        window.location.replace(logoutDestination);
        return;
      }
      loading.hidden = true;
      screen.hidden = false;
      showError(error.message);
    }
  }

  form?.addEventListener("change", updateButton);
  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (sending || accept.disabled) return;
    sending = true;
    clearError();
    updateButton();
    accept.textContent = "Registrando...";
    const body = { documentos: applicable.map((item) => item.codigo) };
    if (actorType === "cliente") body.declaracao_maioridade = 1;

    try {
      const payload = await request("documentos-legais/manifestar", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
        body: JSON.stringify(body)
      });
      if (payload.code !== "LEGAL_MANIFESTATION_REGISTERED"
        && payload.code !== "LEGAL_MANIFESTATION_ALREADY_REGISTERED") {
        throw apiError("O servidor não confirmou a manifestação.");
      }

      const pendingPayload = await request("documentos-legais/pendencias");
      if (pendingPayload.code !== "LEGAL_DOCUMENTS_PENDING_LISTED"
        || typeof pendingPayload.data?.possui_pendencias !== "boolean") {
        throw apiError("Não foi possível confirmar a conclusão das manifestações.");
      }
      if (pendingPayload.data.possui_pendencias === false) {
        window.location.replace(homeDestination);
        return;
      }

      sending = false;
      accept.textContent = "Aceitar e continuar";
      checkTerms.checked = false;
      checkPrivacy.checked = false;
      checkMajority.checked = false;
      screen.hidden = true;
      loading.hidden = false;
      await init();
    } catch (error) {
      if (error.sessionExpired) {
        window.location.replace(logoutDestination);
        return;
      }
      showError(error.message);
      sending = false;
      accept.textContent = "Aceitar e continuar";
      updateButton();
    }
  });

  refuse?.addEventListener("click", async () => {
    if (sending) return;
    sending = true;
    clearError();
    accept.disabled = true;
    refuse.disabled = true;
    try {
      const payload = await request("_auth/logout", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: ""
      });
      if (payload.code !== "LOGOUT_OK") throw apiError("Não foi possível encerrar a sessão.");
      const redirect = safeLogoutRedirect(payload.redirect_url);
      if (!redirect) throw apiError("O servidor retornou um destino de saída inválido.");
      window.location.replace(redirect);
    } catch (error) {
      showError(error.message);
      sending = false;
      refuse.disabled = false;
      updateButton();
    }
  });

  init();
})();
