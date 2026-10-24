/* ==========================================================
   sessao.js — Verifica sessão (AmAgenda) ✅
   - Se NÃO estiver logado: redireciona para o login interno central
     (/public/views/login-empresa.php), inclusive nas páginas de Super Admin.
   - Endpoint: /api/api_central.php?path=_auth/session
   - IMPORTANTE: credentials: "include" (cookie PHPSESSID)
   - Expõe dados da sessão e mostra nome da empresa no header
   - NOVO: antes de liberar a página, verifica documentos legais pendentes
     (/api/api_central.php?path=documentos-legais/pendencias) e redireciona
     para a página de aceite quando houver pendência aplicável ao ator logado.
     A definição de quais documentos cada ator deve aceitar continua
     exclusivamente no backend.
   - Sessão encerrada: somente 401 com SESSION_REAUTH_REQUIRED,
     NOT_AUTHENTICATED ou CLIENT_NOT_AUTHENTICATED (respostas da API central)
     encerram a página. 403 PERMISSION_DENIED e 403 CSRF_INVALID seguem para
     o consumidor como erros de autorização/CSRF. Funcionários e Super Admin
     voltam ao Login Central; clientes, ao Login do Cliente, cuja empresa vem
     do contexto validado da sessão no servidor. Destinos somente por allowlist.
   - Sem heartbeat: nenhuma consulta periódica renova a sessão PHP.
   - Login do Cliente após perda completa da sessão: usa o slug da empresa
     recebido do servidor (_auth/session, validado no banco) e guardado
     somente nesta aba (sessionStorage) -> /agendar/{slug}. Sem slug
     confiável, o Login do Cliente leva à recuperação segura (link oficial).
   - Retorno à aba/PWA (visibilitychange/pageshow): revalida a sessão pelo
     endpoint oficial, sem chamadas concorrentes e com intervalo mínimo.
     Falha de rede, timeout ou 5xx nunca encerram a página.
   - Inatividade (política autoritativa no servidor, login_multiempresa.php):
     funcionários/profissionais e clientes 2700 s; Super Admin, inclusive em
     Modo Suporte, 1800 s. GETs automáticos não renovam o prazo; renovam
     escritas com CSRF e _auth/session. Este script só pede renovação quando
     há interação do usuário (no máximo a cada RENOVACAO_INTERACAO_MS) ou
     retorno à aba/PWA; a verificação no fim do prazo usa renovar=0 (não
     renova). O limite e o tempo restante vêm do servidor.
   - Sem interação além do limite, consultas automáticas (GET) à API ficam
     retidas (agrupadas por URL) e só seguem após nova interação e
     revalidação. Escritas (POST etc.) nunca são retidas nem repetidas.
========================================================== */
(() => {
  "use strict";

  const API_BASE = "/api/api_central.php";
  // Todas as páginas internas usam o login central (sem exigir link de empresa).
  const LOGIN_URL = "/public/views/login-empresa.php";
  const LOGIN_CLIENTE_URL = "/public/views/login-cliente.php";
  const ACEITE_URL = "/views/documentos-legais/aceite-documentos.html";
  const CODIGO_REAUTENTICACAO = "SESSION_REAUTH_REQUIRED";
  const CODIGO_CLIENTE_NAO_AUTENTICADO = "CLIENT_NOT_AUTHENTICATED";
  // Somente códigos explícitos de sessão ausente/revogada encerram a página.
  // PERMISSION_DENIED e CSRF_INVALID (403) nunca são tratados como expiração.
  const CODIGOS_SESSAO_ENCERRADA = new Set([
    CODIGO_REAUTENTICACAO,
    "NOT_AUTHENTICATED",
    CODIGO_CLIENTE_NAO_AUTENTICADO,
  ]);
  // Allowlist fechada de destinos; nenhum destino vindo da resposta é usado fora dela.
  const DESTINOS_REAUTENTICACAO = new Set([LOGIN_URL, LOGIN_CLIENTE_URL]);
  // Fluxos de autenticação tratam os próprios 401 (credencial inválida etc.).
  const ROTAS_SEM_INTERCEPTACAO = new Set([
    "_auth/login",
    "_auth/cliente-login",
    "_auth/selecionar-empresa",
    "_auth/logout",
    "cadastro-publico/empresa",
  ]);
  const PAGINAS_CLIENTE = [
    "/views/cliente-agendamento.html",
    "/views/cliente-perfil.html",
  ];
  const ATRASO_REDIRECIONAMENTO_MS = 1600;
  const ID_AVISO_SESSAO = "amagendaAvisoSessaoEncerrada";
  // Contexto público da empresa do cliente, somente nesta aba.
  const CHAVE_SLUG_CLIENTE = "amagenda:cliente-empresa-slug";
  const PADRAO_SLUG_EMPRESA = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
  const SLUG_EMPRESA_MAX = 160;
  // Revalidação ao retornar e controle de inatividade (sem polling).
  // Padrões usados somente até o servidor informar o limite efetivo.
  const INATIVIDADE_PADRAO_MS = 2700 * 1000;
  const INATIVIDADE_SUPER_ADMIN_MS = 1800 * 1000;
  const RENOVACAO_INTERACAO_MS = 2 * 60 * 1000;
  const MARGEM_VERIFICACAO_EXPIRACAO_MS = 2000;
  const REVALIDACAO_INTERVALO_MINIMO_MS = 60 * 1000;
  const REVALIDACAO_TIMEOUT_MS = 10000;
  const INTERACAO_THROTTLE_MS = 1000;

  let esperaRedirecionamentoReautenticacao = null;
  const fetchNativo = window.__AMAGENDA_FETCH_NATIVO__ || window.fetch.bind(window);
  window.__AMAGENDA_FETCH_NATIVO__ = fetchNativo;
  const estadoSessao = {
    confirmada: false,
    identidade: "",
    ultimaInteracao: Date.now(),
    ultimaValidacao: 0,
    ultimaRenovacao: 0,
    limiteMs: INATIVIDADE_PADRAO_MS,
    timerExpiracao: 0,
    revalidacao: null,
    retomando: false,
    liberarRetidas: null,
    esperaRetidas: null,
    retidas: new Map(),
  };

  function esperaPendente() {
    if (!esperaRedirecionamentoReautenticacao) {
      esperaRedirecionamentoReautenticacao = new Promise(() => {});
    }
    return esperaRedirecionamentoReautenticacao;
  }

  function estaEmPaginaCliente() {
    const caminho = caminhoAtual();
    const paginaCliente = PAGINAS_CLIENTE.some(
      (pagina) => caminho === pagina || caminho === `/public${pagina}`
    );
    return paginaCliente
      || String(window.__AUTH__?.tipo_usuario || "").toLowerCase() === "cliente";
  }

  // Login do contexto atual: o Login do Cliente resolve a empresa pelo contexto
  // validado na sessão do servidor (empresa_id/slug do link), nunca pelo navegador.
  function destinoLoginContextual() {
    return estaEmPaginaCliente() ? destinoLoginCliente() : LOGIN_URL;
  }

  function slugEmpresaValido(valor) {
    const slug = String(valor || "");
    return slug.length >= 1 && slug.length <= SLUG_EMPRESA_MAX && PADRAO_SLUG_EMPRESA.test(slug);
  }

  function slugClienteArmazenado() {
    try {
      const slug = window.sessionStorage.getItem(CHAVE_SLUG_CLIENTE) || "";
      return slugEmpresaValido(slug) ? slug : "";
    } catch (_) {
      return "";
    }
  }

  /** Guarda o slug validado pelo servidor apenas para sessões de cliente; limpa nos demais casos. */
  function registrarContextoCliente(auth) {
    try {
      const cliente = String(auth?.tipo_usuario || "").toLowerCase() === "cliente";
      const slug = String(auth?.empresa_slug || "");
      if (cliente && slugEmpresaValido(slug)) {
        window.sessionStorage.setItem(CHAVE_SLUG_CLIENTE, slug);
      } else if (!cliente) {
        window.sessionStorage.removeItem(CHAVE_SLUG_CLIENTE);
      }
    } catch (_) {
      // Sem armazenamento: o Login do Cliente usa o contexto do servidor.
    }
  }

  // Resolvedor público oficial (/agendar/{slug}): revalida a empresa no banco e
  // reconstrói o contexto do Login do Cliente. Sem slug confiável, o Login do
  // Cliente encaminha à recuperação segura (link oficial da empresa).
  function destinoLoginCliente() {
    const slug = slugClienteArmazenado();
    return slug ? `/agendar/${encodeURIComponent(slug)}` : LOGIN_CLIENTE_URL;
  }

  function destinoPermitido(destino) {
    if (DESTINOS_REAUTENTICACAO.has(destino)) return true;
    const encontrado = /^\/agendar\/([a-z0-9-]+)$/.exec(String(destino || ""));
    return !!encontrado && slugEmpresaValido(encontrado[1]);
  }

  function destinoSessaoEncerrada(codigo, destinoInformado) {
    if (codigo === CODIGO_REAUTENTICACAO && DESTINOS_REAUTENTICACAO.has(destinoInformado)) {
      return destinoInformado;
    }
    if (codigo === CODIGO_CLIENTE_NAO_AUTENTICADO) return destinoLoginCliente();
    return destinoLoginContextual();
  }

  /** Retorna a rota da API central (same-origin) ou null para qualquer outra URL. */
  function rotaApiCentral(resposta) {
    try {
      const url = new URL(String(resposta?.url || ""), window.location.origin);
      if (url.origin !== window.location.origin) return null;
      if (!url.pathname.toLowerCase().endsWith("/api/api_central.php")) return null;
      return String(url.searchParams.get("path") || "").replace(/^\/+|\/+$/g, "");
    } catch (_) {
      return null;
    }
  }

  /**
   * Cobre a página (dados e controles protegidos deixam de ficar visíveis e
   * interativos) e mostra um único aviso discreto. Usa somente textContent.
   */
  function bloquearInterface(titulo, mensagem) {
    const body = document.body;
    if (!body || document.getElementById(ID_AVISO_SESSAO)) return;

    Array.from(body.children).forEach((filho) => {
      filho.setAttribute("aria-hidden", "true");
      filho.inert = true;
    });

    const camada = document.createElement("div");
    camada.id = ID_AVISO_SESSAO;
    camada.setAttribute("role", "alertdialog");
    camada.setAttribute("aria-modal", "true");
    camada.setAttribute("aria-live", "assertive");
    camada.style.cssText = [
      "position:fixed", "inset:0", "z-index:2147483647", "display:flex",
      "align-items:center", "justify-content:center", "padding:16px",
      "background:#f4f6fa", "font-family:inherit",
    ].join(";");

    const cartao = document.createElement("div");
    cartao.style.cssText = [
      "max-width:360px", "width:100%", "padding:18px 20px", "border-radius:12px",
      "background:#fff", "border:1px solid #dbe3ef", "box-shadow:0 8px 24px rgba(15,23,42,.08)",
      "color:#1f2a3d", "text-align:center", "line-height:1.45",
    ].join(";");

    const elTitulo = document.createElement("p");
    elTitulo.style.cssText = "margin:0 0 6px;font-size:15px;font-weight:700";
    elTitulo.textContent = titulo;

    const elMensagem = document.createElement("p");
    elMensagem.style.cssText = "margin:0;font-size:13px;color:#5b6678";
    elMensagem.textContent = mensagem;

    cartao.append(elTitulo, elMensagem);
    camada.appendChild(cartao);
    body.appendChild(camada);
  }

  /**
   * Encerra a página uma única vez: um aviso, um redirecionamento.
   * tipo "sessao": sessão ausente/revogada; tipo "acesso": sessão sem acesso (403 da verificação inicial).
   */
  function encerrarPagina({ tipo = "sessao", codigo = "", destinoInformado = "", mensagem = "" } = {}) {
    esperaPendente();
    if (window.__AMAGENDA_REAUTENTICANDO__) return;
    window.__AMAGENDA_REAUTENTICANDO__ = true;

    const destino = tipo === "sessao"
      ? destinoSessaoEncerrada(codigo, destinoInformado)
      : destinoLoginContextual();

    const msgServidor = String(mensagem || "").trim();
    const textoBase = tipo === "sessao"
      ? (msgServidor && msgServidor.length <= 200 ? msgServidor : "Sua sessão foi encerrada. Faça login novamente.")
      : (msgServidor && msgServidor.length <= 200 ? msgServidor : "Acesso indisponível para esta sessão.");

    try {
      document.dispatchEvent(new CustomEvent("amagenda:sessao-encerrada", { detail: { tipo, codigo } }));
    } catch (_) {
      // Evento informativo: falha não impede o encerramento.
    }

    const destinoSeguro = destinoPermitido(destino) ? destino : LOGIN_URL;
    liberarRequisicoesRetidas(false);

    if (!document.body) {
      window.location.replace(destinoSeguro);
      return;
    }

    bloquearInterface(
      tipo === "sessao" ? "Sessão encerrada" : "Acesso indisponível",
      `${textoBase} Redirecionando para o login…`
    );
    window.setTimeout(() => window.location.replace(destinoSeguro), ATRASO_REDIRECIONAMENTO_MS);
  }

  /* ----------------------------------------------------------
     Inatividade e retenção de consultas automáticas
  ---------------------------------------------------------- */
  function usuarioInativo() {
    return Date.now() - estadoSessao.ultimaInteracao >= estadoSessao.limiteMs;
  }

  /**
   * Aplica o prazo informado pelo servidor (fonte de verdade) e agenda uma
   * única verificação sem renovação para o fim do prazo. Não é polling: há no
   * máximo um temporizador, reagendado apenas por respostas do servidor.
   */
  function aplicarPrazoServidor(auth) {
    const tipo = String(auth?.tipo_usuario || "").toLowerCase();
    const limite = Number(auth?.sessao_inatividade_limite_segundos);
    estadoSessao.limiteMs = Number.isInteger(limite) && limite >= 60 && limite <= 86400
      ? limite * 1000
      : (tipo === "super_admin" ? INATIVIDADE_SUPER_ADMIN_MS : INATIVIDADE_PADRAO_MS);

    const restante = Number(auth?.sessao_expira_em_segundos);
    const restanteMs = Number.isFinite(restante) && restante > 0
      ? Math.min(restante * 1000, estadoSessao.limiteMs)
      : estadoSessao.limiteMs;

    window.clearTimeout(estadoSessao.timerExpiracao);
    estadoSessao.timerExpiracao = window.setTimeout(() => {
      estadoSessao.timerExpiracao = 0;
      // Aba oculta: o retorno (visibilitychange/pageshow) revalida.
      if (document.visibilityState !== "visible") return;
      revalidarSessao({ renovar: false });
    }, restanteMs + MARGEM_VERIFICACAO_EXPIRACAO_MS);
  }

  function deveReterRequisicao(metodo, rota) {
    return estadoSessao.confirmada
      && rota !== null
      && (metodo === "GET" || metodo === "HEAD")
      && (estadoSessao.retomando || usuarioInativo());
  }

  function esperaRetomada() {
    if (!estadoSessao.esperaRetidas) {
      estadoSessao.esperaRetidas = new Promise((resolver) => {
        estadoSessao.liberarRetidas = resolver;
      });
    }
    return estadoSessao.esperaRetidas;
  }

  /** liberar=true: as consultas retidas seguem; false: permanecem pendentes (página encerrando). */
  function liberarRequisicoesRetidas(liberar) {
    const resolver = estadoSessao.liberarRetidas;
    if (!liberar) return;
    estadoSessao.esperaRetidas = null;
    estadoSessao.liberarRetidas = null;
    estadoSessao.retidas.clear();
    if (resolver) resolver();
  }

  /** Descreve a requisição sem consumi-la: método e rota da API central (ou null). */
  function descreverRequisicao(args) {
    const [entrada, opcoes] = args;
    let url = "";
    let metodo = "GET";
    if (typeof Request !== "undefined" && entrada instanceof Request) {
      url = entrada.url;
      metodo = entrada.method || "GET";
    } else {
      url = String(entrada || "");
    }
    if (opcoes && opcoes.method) metodo = String(opcoes.method);
    const urlAbsoluta = (() => {
      try {
        return new URL(url, window.location.origin).toString();
      } catch (_) {
        return "";
      }
    })();
    return {
      metodo: metodo.toUpperCase(),
      chave: `${metodo.toUpperCase()} ${urlAbsoluta}`,
      rota: rotaApiCentral({ url: urlAbsoluta }),
    };
  }

  function identidadeSessao(auth) {
    if (!auth || typeof auth !== "object") return "";
    const tipo = String(auth.tipo_usuario || "").toLowerCase();
    const empresa = Number(auth.empresa_id || auth.id_empresa || 0);
    if (tipo === "cliente") return `cliente:${empresa}:${String(auth.telefone || "")}`;
    return `${tipo}:${Number(auth.id_usuario || 0)}:${empresa}:${auth.modo_suporte === true ? 1 : 0}`;
  }

  /**
   * Revalida pelo endpoint oficial, sem chamadas concorrentes.
   * renovar=false usa renovar=0 (somente verificação; o servidor não renova).
   * Retorna "valida", "invalida" ou "indeterminada" (rede, timeout, 5xx: nunca encerra).
   */
  function revalidarSessao({ renovar = true } = {}) {
    if (window.__AMAGENDA_REAUTENTICANDO__) return Promise.resolve("invalida");
    if (estadoSessao.revalidacao) return estadoSessao.revalidacao;

    estadoSessao.revalidacao = (async () => {
      const controle = typeof AbortController === "function" ? new AbortController() : null;
      const limite = controle ? window.setTimeout(() => controle.abort(), REVALIDACAO_TIMEOUT_MS) : 0;
      try {
        const resp = await fetchNativo(`${API_BASE}?path=_auth/session${renovar ? "" : "&renovar=0"}`, {
          method: "GET",
          credentials: "include",
          headers: { Accept: "application/json" },
          cache: "no-store",
          signal: controle ? controle.signal : undefined,
        });
        const dados = await resp.json().catch(() => null);

        if (resp.status === 401) {
          encerrarPagina({
            tipo: "sessao",
            codigo: String(dados?.code || "NOT_AUTHENTICATED"),
            destinoInformado: String(dados?.data?.redirect_url || ""),
            mensagem: dados?.user_msg,
          });
          return "invalida";
        }
        if (resp.status === 403) {
          encerrarPagina({ tipo: "acesso", mensagem: dados?.user_msg });
          return "invalida";
        }
        if (!resp.ok || dados?.ok !== true) return "indeterminada";

        const auth = dados.data?.user || dados.data || null;
        const identidade = identidadeSessao(auth);
        if (estadoSessao.identidade && identidade !== estadoSessao.identidade) {
          // Outra identidade assumiu a sessão deste navegador: a página não pode
          // continuar exibindo dados ou executando ações do usuário anterior.
          if (!window.__AMAGENDA_REAUTENTICANDO__) {
            window.__AMAGENDA_REAUTENTICANDO__ = true;
            esperaPendente();
            bloquearInterface("Sessão alterada", "A sessão deste navegador mudou. Recarregando a página…");
            window.setTimeout(() => window.location.reload(), ATRASO_REDIRECIONAMENTO_MS);
          }
          return "invalida";
        }

        registrarContextoCliente(auth);
        estadoSessao.ultimaValidacao = Date.now();
        if (renovar) estadoSessao.ultimaRenovacao = Date.now();
        aplicarPrazoServidor(auth);
        return "valida";
      } catch (_) {
        return "indeterminada";
      } finally {
        if (limite) window.clearTimeout(limite);
        estadoSessao.revalidacao = null;
      }
    })();

    return estadoSessao.revalidacao;
  }

  /** Retomada após inatividade/retorno: revalida antes de liberar consultas retidas. */
  async function retomar() {
    if (estadoSessao.retomando || window.__AMAGENDA_REAUTENTICANDO__) return;
    estadoSessao.retomando = true;
    try {
      const resultado = await revalidarSessao();
      if (resultado === "invalida") return;
      // "valida" ou "indeterminada" (rede): as consultas seguem; um 401 real
      // continua tratado pelo interceptador.
      estadoSessao.retomando = false;
      liberarRequisicoesRetidas(true);
    } finally {
      if (!window.__AMAGENDA_REAUTENTICANDO__) estadoSessao.retomando = false;
    }
  }

  function registrarInteracao() {
    const agora = Date.now();
    const estavaInativo = usuarioInativo();
    if (!estavaInativo && agora - estadoSessao.ultimaInteracao < INTERACAO_THROTTLE_MS) return;
    estadoSessao.ultimaInteracao = agora;
    if (!estadoSessao.confirmada || window.__AMAGENDA_REAUTENTICANDO__) return;
    if (estavaInativo || estadoSessao.esperaRetidas) {
      retomar();
      return;
    }
    // Interação real com a página: pede renovação ao servidor, no máximo a
    // cada RENOVACAO_INTERACAO_MS. Sem interação, nada é enviado.
    if (agora - estadoSessao.ultimaRenovacao >= RENOVACAO_INTERACAO_MS) {
      revalidarSessao({ renovar: true });
    }
  }

  function aoRetornarPagina(evento) {
    if (document.visibilityState !== "visible") return;
    const restauradaDoCache = evento?.type === "pageshow" && evento.persisted === true;
    if (evento?.type === "pageshow" && !restauradaDoCache) return;

    const estavaInativo = usuarioInativo();
    // Voltar à aba/PWA é ação humana.
    estadoSessao.ultimaInteracao = Date.now();
    if (!estadoSessao.confirmada || window.__AMAGENDA_REAUTENTICANDO__) return;

    const intervaloVencido = Date.now() - estadoSessao.ultimaValidacao >= REVALIDACAO_INTERVALO_MINIMO_MS;
    if (estavaInativo || restauradaDoCache || estadoSessao.esperaRetidas) {
      retomar();
    } else if (intervaloVencido) {
      revalidarSessao();
    }
  }

  ["pointerdown", "keydown", "touchstart", "wheel", "mousemove"].forEach((tipo) => {
    window.addEventListener(tipo, registrarInteracao, { capture: true, passive: true });
  });
  document.addEventListener("visibilitychange", aoRetornarPagina);
  window.addEventListener("pageshow", aoRetornarPagina);

  if (!window.__AMAGENDA_FETCH_REAUTENTICACAO__) {
    window.__AMAGENDA_FETCH_REAUTENTICACAO__ = true;

    const executarComTratamento = async (args) => {
      const resposta = await fetchNativo(...args);
      if (window.__AMAGENDA_REAUTENTICANDO__) return esperaPendente();

      const rota = rotaApiCentral(resposta);
      if (rota === null) return resposta;

      // Logout confirmado: a navegação do deslogar.js prevalece; respostas 401
      // de chamadas ainda em curso não disparam outro aviso/redirecionamento.
      if (rota === "_auth/logout" && resposta.ok) {
        const dadosLogout = await resposta.clone().json().catch(() => null);
        if (dadosLogout?.code === "LOGOUT_OK") window.__AMAGENDA_LOGOUT_CONFIRMADO__ = true;
        return resposta;
      }

      if (resposta.status !== 401
        || ROTAS_SEM_INTERCEPTACAO.has(rota)
        || window.__AMAGENDA_LOGOUT_CONFIRMADO__) {
        return resposta;
      }

      const dados = await resposta.clone().json().catch(() => null);
      const codigo = String(dados?.code || "");
      if (!CODIGOS_SESSAO_ENCERRADA.has(codigo)) return resposta;

      encerrarPagina({
        tipo: "sessao",
        codigo,
        destinoInformado: String(dados?.data?.redirect_url || ""),
        mensagem: dados?.user_msg,
      });

      // Manter a chamada pendente impede que os consumidores da mesma resposta
      // exibam mensagens ou redirecionem de novo.
      return esperaPendente();
    };

    window.fetch = async (...args) => {
      // Encerramento já em andamento: nenhuma outra resposta é entregue aos
      // consumidores, evitando avisos repetidos enquanto a página navega.
      if (window.__AMAGENDA_REAUTENTICANDO__) return esperaPendente();

      const requisicao = descreverRequisicao(args);
      if (!deveReterRequisicao(requisicao.metodo, requisicao.rota)) {
        return executarComTratamento(args);
      }

      // Consulta automática durante inatividade: aguarda interação e revalidação.
      // Consultas iguais são agrupadas (sem rajada ao retomar); cada consumidor
      // recebe a própria cópia da resposta.
      let compartilhada = estadoSessao.retidas.get(requisicao.chave);
      if (!compartilhada) {
        compartilhada = esperaRetomada().then(() => executarComTratamento(args));
        estadoSessao.retidas.set(requisicao.chave, compartilhada);
      }
      const resposta = await compartilhada;
      if (window.__AMAGENDA_REAUTENTICANDO__) return esperaPendente();
      return resposta.clone();
    };
  }

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

  function aplicarModoSuporte(auth) {
    const indicador = document.getElementById("indicadorModoSuporte");
    const nome = document.getElementById("nomeEmpresaModoSuporte");
    const sair = document.getElementById("btnSairModoSuporte");
    if (!indicador || !nome || !sair) return;

    const ativo = String(auth?.tipo_usuario || "").toLowerCase() === "super_admin"
      && auth?.modo_suporte === true
      && Number(auth?.empresa_id || 0) > 0;
    if (!ativo) {
      indicador.hidden = true;
      return;
    }

    nome.textContent = String(auth?.empresa_nome || "Empresa").trim() || "Empresa";
    indicador.hidden = false;
    if (sair.dataset.suporteBound === "1") return;
    sair.dataset.suporteBound = "1";

    sair.addEventListener("click", async () => {
      sair.disabled = true;
      try {
        const resp = await fetch(`${API_BASE}?path=superadmin/suporte/sair`, {
          method: "POST",
          credentials: "same-origin",
          cache: "no-store",
          headers: {
            Accept: "application/json",
            "X-CSRF-Token": String(window.__AUTH__?.csrf_token || ""),
          },
        });
        const json = await resp.json().catch(() => null);
        if (!resp.ok || json?.ok !== true) {
          throw new Error(json?.user_msg || "Não foi possível finalizar o modo suporte.");
        }
        window.location.assign(json.data?.redirect || "/views/super-admin/painel-super-admin.html");
      } catch (erro) {
        alert(erro instanceof Error ? erro.message : "Não foi possível finalizar o modo suporte.");
        sair.disabled = false;
      }
    });
  }

  /**
   * Consulta documentos legais pendentes e redireciona para a página de
   * aceite quando houver pendência. Retorna true quando o redirecionamento
   * foi disparado (o chamador deve interromper o fluxo normal da página).
   */
  async function verificarPendenciasLegais(auth) {
    if (estaNaPaginaDeAceite() || estaEmPaginaPublicaSemGuard()) return false;

    // O primeiro acesso por OTP pode autenticar um cliente ainda sem cadastro.
    // Nesse estado não existe id_cliente para vincular uma manifestação legal;
    // o cadastro obrigatório da página cria a identidade antes desta checagem.
    if (
      auth?.tipo_usuario === "cliente" &&
      (!Number.isInteger(Number(auth?.id_cliente)) || Number(auth.id_cliente) <= 0)
    ) {
      return false;
    }

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
        window.location.replace(destinoLoginContextual());
        return true;
      }

      if (json.data.possui_pendencias === true) {
        window.location.replace(ACEITE_URL);
        return true;
      }

      return false;
    } catch (e) {
      window.location.replace(destinoLoginContextual());
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
          const dadosFalha = await resp.json().catch(() => null);
          // 401: sessão ausente ou revogada. 403: sessão existente sem acesso
          // (usuário inativo, vínculo ou plano); não é apresentado como expiração.
          encerrarPagina({
            tipo: resp.status === 401 ? "sessao" : "acesso",
            codigo: resp.status === 401 ? String(dadosFalha?.code || "NOT_AUTHENTICATED") : "",
            destinoInformado: String(dadosFalha?.data?.redirect_url || ""),
            mensagem: dadosFalha?.user_msg,
          });
          return;
        }

        const json = await resp.json().catch(() => null);

        if (!json || json.ok !== true) {
          window.location.replace(destinoLoginContextual());
          return;
        }

        const auth = json.data?.user || json.data || null;
        const redirecionou = await verificarPendenciasLegais(auth);
        if (redirecionou) return;

        registrarContextoCliente(auth);
        estadoSessao.identidade = identidadeSessao(auth);
        estadoSessao.ultimaValidacao = Date.now();
        estadoSessao.ultimaRenovacao = Date.now();
        estadoSessao.ultimaInteracao = Date.now();
        estadoSessao.confirmada = true;
        aplicarPrazoServidor(auth);

        aplicarDadosSessao(auth);
        aplicarModoSuporte(auth);

      } catch (e) {
        if (window.__AMAGENDA_REAUTENTICANDO__) return;
        window.location.replace(destinoLoginContextual());
      }
    })();

    return promessaSessao;
  }

  document.addEventListener("DOMContentLoaded", verificarSessao);
})();
