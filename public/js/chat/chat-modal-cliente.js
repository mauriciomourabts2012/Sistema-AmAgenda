/* Chat contextual da Área do Cliente, isolado da listagem de agendamentos. */
(() => {
  "use strict";

  const API_BASE = "/public/api/api_central.php?path=";
  const LOGIN = "/public/views/login-cliente.php";
  const LIMITE_MENSAGENS = 50;
  // Modal aberto e visível: busca incremental (after_id) a cada 10 s.
  const INTERVALO_CONVERSA_MS = 10000;
  const PREFIXOS_FOTO = ["/public/imagens/usuarios/", "/public/imagens/clientes/"];
  const estado = {
    conversa: null,
    mensagens: [],
    temMaisMensagens: false,
    abrindo: false,
    enviando: false,
    chaveEnvioPendente: null,
    ciclo: 0,
    // Acesso por card em status final (concluído/cancelado): somente consulta na interface.
    somenteConsulta: false,
    idPrimeiraNova: null,
    leituraPendente: false,
    timerConversa: 0,
    sincronizando: false,
  };
  const ui = {};

  function texto(valor) {
    return String(valor ?? "").trim();
  }

  function criarElemento(tag, classe, conteudo) {
    const elemento = document.createElement(tag);
    if (classe) elemento.className = classe;
    if (typeof conteudo !== "undefined") elemento.textContent = conteudo;
    return elemento;
  }

  function erroAmigavel(erro, padrao) {
    return texto(erro?.message) || padrao;
  }

  function clienteCompleto() {
    const auth = window.__AUTH__ || {};
    return auth.tipo_usuario === "cliente"
      && Number(auth.id_cliente) > 0
      && auth.cadastro_completo === true;
  }

  function podeResponder() {
    return !estado.somenteConsulta
      && estado.conversa?.status === "aberta"
      && estado.conversa?.pode_responder !== false;
  }

  /* Avatar: iniciais por padrão; foto apenas de caminho de upload conhecido. */
  function urlFotoSegura(valor) {
    const caminho = texto(valor);
    if (!caminho || !PREFIXOS_FOTO.some(prefixo => caminho.startsWith(prefixo))) return "";
    return /^[A-Za-z0-9/._-]+$/.test(caminho) ? caminho : "";
  }

  function iniciais(nome) {
    const partes = texto(nome).split(/\s+/).filter(Boolean);
    if (!partes.length) return "?";
    return `${partes[0].charAt(0)}${partes.length > 1 ? partes[partes.length - 1].charAt(0) : ""}`.toUpperCase();
  }

  function preencherAvatar(elemento, nome, foto) {
    elemento.replaceChildren(criarElemento("span", "chat-avatar-iniciais", iniciais(nome)));
    elemento.setAttribute("aria-hidden", "true");
    const url = urlFotoSegura(foto);
    if (!url) return elemento;
    const imagem = document.createElement("img");
    imagem.alt = "";
    imagem.loading = "lazy";
    imagem.decoding = "async";
    imagem.addEventListener("error", () => imagem.remove(), { once: true });
    imagem.src = url;
    elemento.append(imagem);
    return elemento;
  }

  /* Leitura e polling só com o modal ativo e a aba visível. */
  function modalVisivel() {
    return document.visibilityState === "visible"
      && Boolean(estado.conversa)
      && ui.modal.classList.contains("ativo")
      && ui.modal.getClientRects().length > 0;
  }

  async function apiChat(rota, { metodo = "GET", parametros = {}, dados = null } = {}) {
    const url = new URL(`${API_BASE}${encodeURIComponent(rota)}`, window.location.origin);
    Object.entries(parametros).forEach(([chave, valor]) => {
      if (valor !== "" && valor !== null && typeof valor !== "undefined") {
        url.searchParams.set(chave, String(valor));
      }
    });

    const headers = { Accept: "application/json" };
    const opcoes = {
      method: metodo,
      credentials: "same-origin",
      cache: "no-store",
      headers,
    };

    if (metodo !== "GET") {
      const corpo = new URLSearchParams();
      Object.entries(dados || {}).forEach(([chave, valor]) => {
        if (valor !== null && typeof valor !== "undefined") corpo.set(chave, String(valor));
      });
      headers["Content-Type"] = "application/x-www-form-urlencoded;charset=UTF-8";
      headers["X-CSRF-Token"] = String(window.__AUTH__?.csrf_token || "");
      opcoes.body = corpo.toString();
    }

    const resposta = await fetch(url.toString(), opcoes);
    const json = await resposta.json().catch(() => null);
    if (resposta.status === 401) {
      window.location.replace(LOGIN);
      throw new Error("Sessão expirada.");
    }
    if (!resposta.ok || json?.ok !== true) {
      const erro = new Error(json?.user_msg || "Não foi possível concluir a operação do Chat.");
      erro.code = json?.code || "CLIENT_CHAT_REQUEST_FAILED";
      throw erro;
    }
    return json;
  }

  function formatarDataHora(data, hora) {
    const dataTexto = texto(data);
    const horaTexto = texto(hora).slice(0, 5);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dataTexto)) return horaTexto || "—";
    const dataLocal = new Date(`${dataTexto}T12:00:00`);
    const dataFormatada = Number.isNaN(dataLocal.getTime())
      ? dataTexto
      : dataLocal.toLocaleDateString("pt-BR", { day: "2-digit", month: "2-digit", year: "numeric" });
    return horaTexto ? `${dataFormatada} às ${horaTexto}` : dataFormatada;
  }

  function formatarHorario(valor) {
    const bruto = texto(valor);
    if (!bruto) return "";
    const data = new Date(bruto.includes("T") ? bruto : bruto.replace(" ", "T"));
    if (Number.isNaN(data.getTime())) return bruto;
    return data.toLocaleString("pt-BR", {
      day: "2-digit",
      month: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  function definirAviso(mensagem = "", erro = false) {
    ui.aviso.textContent = mensagem;
    ui.aviso.classList.toggle("erro", erro);
  }

  function abrirModal() {
    ui.modal.classList.add("ativo");
    ui.modal.setAttribute("aria-hidden", "false");
  }

  function rolarAoFim() {
    window.requestAnimationFrame(() => {
      ui.historico.scrollTop = ui.historico.scrollHeight;
    });
  }

  function renderizarCabecalho() {
    const conversa = estado.conversa;
    ui.profissional.textContent = texto(conversa?.profissional_nome) || "—";
    ui.dataHora.textContent = conversa
      ? formatarDataHora(conversa.agendamento_data, conversa.agendamento_hora_inicio)
      : "—";
    const encerrada = conversa?.status === "encerrada";
    ui.status.textContent = conversa ? (encerrada ? "Encerrada" : "Aberta") : "";
    ui.status.className = `chat-cliente-status ${encerrada ? "encerrada" : "aberta"}`;
    if (ui.avatar) {
      if (conversa) preencherAvatar(ui.avatar, texto(conversa.profissional_nome) || "Profissional", conversa.profissional_foto_url);
      else ui.avatar.replaceChildren();
    }
  }

  /* Mensagem do SISTEMA: linha própria, centralizada, sem avatar e sem bolha de remetente. */
  function criarLinhaSistema(mensagem) {
    const linha = criarElemento("div", "chat-sistema-linha");
    const artigo = criarElemento("article", "chat-sistema-mensagem");
    artigo.setAttribute("role", "note");
    const topo = criarElemento("span", "chat-sistema-topo");
    const icone = criarElemento("i", "fa-solid fa-circle-info chat-sistema-icone");
    icone.setAttribute("aria-hidden", "true");
    topo.append(
      icone,
      criarElemento("span", "chat-sistema-remetente", texto(mensagem.remetente_nome) || "AmAgenda"),
      criarElemento("time", "chat-sistema-horario", formatarHorario(mensagem.criado_em))
    );
    artigo.append(topo, criarElemento("p", "chat-sistema-conteudo", texto(mensagem.conteudo)));
    linha.append(artigo);
    return linha;
  }

  function renderizarHistorico() {
    ui.historico.replaceChildren();
    if (estado.mensagens.length === 0) {
      ui.historico.append(criarElemento("p", "chat-cliente-vazio", "Ainda não há mensagens nesta conversa."));
      return;
    }

    estado.mensagens.forEach(mensagem => {
      if (estado.idPrimeiraNova !== null && Number(mensagem.id_mensagem) === estado.idPrimeiraNova) {
        const separador = criarElemento("div", "chat-separador-novas");
        separador.setAttribute("role", "separator");
        separador.append(criarElemento("span", "", "Novas mensagens"));
        ui.historico.append(separador);
      }
      if (mensagem.remetente_tipo === "sistema") {
        ui.historico.append(criarLinhaSistema(mensagem));
        return;
      }
      const propria = mensagem.propria === true;
      const linha = criarElemento("div", `chat-cliente-linha${propria ? " propria" : ""}`);
      const artigo = criarElemento("article", "chat-cliente-mensagem");
      artigo.classList.toggle("propria", propria);
      const nomeRemetente = texto(mensagem.remetente_nome) || "Empresa";
      artigo.append(
        criarElemento("span", "chat-cliente-remetente", propria ? "Você" : nomeRemetente),
        criarElemento("p", "chat-cliente-conteudo", texto(mensagem.conteudo)),
        criarElemento("time", "chat-cliente-horario", formatarHorario(mensagem.criado_em))
      );
      if (!propria) linha.append(preencherAvatar(criarElemento("span", "chat-avatar chat-avatar-mensagem"), nomeRemetente, mensagem.remetente_foto_url));
      linha.append(artigo);
      ui.historico.append(linha);
    });
  }

  function sincronizarCompositor() {
    const responder = podeResponder();
    ui.formulario.hidden = !responder;
    ui.textarea.disabled = !responder || estado.enviando;
    ui.enviar.disabled = !responder || estado.enviando;
    ui.enviar.querySelector("span").textContent = estado.enviando ? "Enviando..." : "Enviar";
    if (!responder && estado.conversa?.status === "encerrada") {
      definirAviso("Esta conversa foi encerrada e está disponível apenas para consulta.");
    } else if (!responder && estado.conversa && estado.somenteConsulta) {
      definirAviso("Este agendamento foi finalizado. A conversa está disponível apenas para consulta.");
    }
  }

  function renderizarConversa({ rolar = false } = {}) {
    const distanciaDoFim = ui.historico.scrollHeight - ui.historico.scrollTop - ui.historico.clientHeight;
    const posicaoAnterior = ui.historico.scrollTop;
    renderizarCabecalho();
    renderizarHistorico();
    sincronizarCompositor();
    ui.carregarAnteriores.hidden = !estado.temMaisMensagens;
    if (rolar === "abertura") {
      window.requestAnimationFrame(() => {
        const separador = ui.historico.querySelector(".chat-separador-novas");
        ui.historico.scrollTop = separador
          ? Math.max(0, separador.offsetTop - ui.historico.offsetTop - 12)
          : ui.historico.scrollHeight;
      });
    } else if (rolar === "seguir") {
      window.requestAnimationFrame(() => {
        ui.historico.scrollTop = distanciaDoFim < 80 ? ui.historico.scrollHeight : posicaoAnterior;
      });
    } else if (rolar) {
      rolarAoFim();
    }
  }

  function limparEstado() {
    pararPolling();
    estado.ciclo += 1;
    estado.somenteConsulta = false;
    estado.idPrimeiraNova = null;
    estado.leituraPendente = false;
    estado.conversa = null;
    estado.mensagens = [];
    estado.temMaisMensagens = false;
    estado.abrindo = false;
    estado.enviando = false;
    estado.chaveEnvioPendente = null;
    ui.textarea.value = "";
    definirAviso("");
    ui.historico.replaceChildren();
    renderizarCabecalho();
    ui.formulario.hidden = true;
    ui.carregarAnteriores.hidden = true;
  }

  async function marcarLeitura() {
    const conversa = estado.conversa;
    const ultima = estado.mensagens[estado.mensagens.length - 1];
    if (!conversa?.id_conversa || !ultima?.id_mensagem) return;
    if (!modalVisivel()) {
      estado.leituraPendente = true;
      return;
    }
    estado.leituraPendente = false;
    try {
      await apiChat("cliente/chat/leitura/marcar", {
        metodo: "POST",
        dados: {
          id_conversa: conversa.id_conversa,
          id_ultima_mensagem_lida: ultima.id_mensagem,
        },
      });
      window.ChatNaoLidas?.atualizarAgora?.();
    } catch (_) {
      // A visualização autorizada não depende da confirmação do marcador de leitura.
    }
  }

  function pararPolling() {
    window.clearTimeout(estado.timerConversa);
    estado.timerConversa = 0;
  }

  function agendarPolling() {
    pararPolling();
    if (!estado.conversa) return;
    estado.timerConversa = window.setTimeout(sincronizarConversa, INTERVALO_CONVERSA_MS);
  }

  async function sincronizarConversa() {
    estado.timerConversa = 0;
    const conversa = estado.conversa;
    if (!conversa) return;
    if (!modalVisivel() || estado.sincronizando) {
      agendarPolling();
      return;
    }
    const ciclo = estado.ciclo;
    const ultima = estado.mensagens[estado.mensagens.length - 1];
    estado.sincronizando = true;
    try {
      const resposta = await apiChat("cliente/chat/mensagens/listar", {
        parametros: { id_conversa: conversa.id_conversa, after_id: ultima?.id_mensagem || 0, limite: LIMITE_MENSAGENS },
      });
      if (ciclo !== estado.ciclo || estado.conversa !== conversa) return;
      const novas = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
      const statusMudou = resposta.data?.status && resposta.data.status !== conversa.status;
      if (statusMudou) {
        conversa.status = resposta.data.status;
        if (conversa.status !== "aberta") conversa.pode_responder = false;
      }
      const ids = new Set(estado.mensagens.map(mensagem => Number(mensagem.id_mensagem)));
      const ineditas = novas.filter(mensagem => !ids.has(Number(mensagem.id_mensagem)));
      if (ineditas.length) {
        estado.mensagens.push(...ineditas);
        estado.mensagens.sort((a, b) => Number(a.id_mensagem) - Number(b.id_mensagem));
      }
      if (ineditas.length || statusMudou) {
        renderizarConversa({ rolar: "seguir" });
        if (ineditas.length) marcarLeitura();
      }
    } catch (_) {
      // Falha transitória: nova tentativa no próximo ciclo.
    } finally {
      estado.sincronizando = false;
      if (estado.conversa === conversa && ciclo === estado.ciclo) agendarPolling();
    }
  }

  async function carregarConversa(idConversa, ciclo) {
    definirAviso("Carregando conversa...");
    ui.historico.replaceChildren(criarElemento("p", "chat-cliente-vazio", "Carregando mensagens..."));
    const resposta = await apiChat("cliente/chat/conversa/abrir", {
      parametros: { id_conversa: idConversa, limite: LIMITE_MENSAGENS },
    });
    if (ciclo !== estado.ciclo) return;

    estado.conversa = resposta.data?.conversa || null;
    estado.mensagens = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
    estado.temMaisMensagens = resposta.data?.tem_mais_anteriores === true;
    if (!estado.conversa?.id_conversa) throw new Error("A conversa não retornou dados válidos.");

    // Marcador anterior à leitura: posição do separador "Novas mensagens".
    const marcador = Number(estado.conversa.id_ultima_mensagem_lida || 0);
    const primeiraNova = estado.mensagens.find(mensagem => Number(mensagem.id_mensagem) > marcador && mensagem.propria !== true);
    estado.idPrimeiraNova = primeiraNova ? Number(primeiraNova.id_mensagem) : null;
    definirAviso("");
    renderizarConversa({ rolar: "abertura" });
    marcarLeitura();
    agendarPolling();
  }

  async function abrir(idAgendamento, opcoes = {}) {
    const id = Number(idAgendamento);
    if (!Number.isInteger(id) || id <= 0 || estado.abrindo) return;
    if (!clienteCompleto()) {
      if (typeof window.MensagemSistema?.aviso === "function") {
        window.MensagemSistema.aviso("Conclua seu cadastro para usar o chat.", { titulo: "Chat" });
      }
      return;
    }

    limparEstado();
    // A interface só oferece consulta; o backend apenas recupera conversa já existente
    // para agendamentos fora de pendente/confirmado (não cria nova).
    estado.somenteConsulta = opcoes?.somenteConsulta === true;
    estado.abrindo = true;
    const ciclo = ++estado.ciclo;
    abrirModal();
    definirAviso("Abrindo conversa...");
    ui.historico.replaceChildren(criarElemento("p", "chat-cliente-vazio", "Abrindo conversa..."));

    try {
      const resposta = await apiChat("cliente/chat/conversa/iniciar", {
        metodo: "POST",
        dados: { id_agendamento: id },
      });
      if (ciclo !== estado.ciclo) return;

      const idConversa = Number(resposta.data?.conversa?.id_conversa);
      if (!Number.isInteger(idConversa) || idConversa <= 0) {
        throw new Error("Não foi possível identificar a conversa deste agendamento.");
      }
      await carregarConversa(idConversa, ciclo);
    } catch (erro) {
      if (ciclo === estado.ciclo) {
        definirAviso(erroAmigavel(erro, "Não foi possível abrir a conversa."), true);
        ui.historico.replaceChildren();
      }
    } finally {
      if (ciclo === estado.ciclo) estado.abrindo = false;
    }
  }

  async function carregarMensagensAnteriores() {
    const primeira = estado.mensagens[0];
    if (!estado.conversa || !primeira?.id_mensagem || !estado.temMaisMensagens) return;

    const ciclo = estado.ciclo;
    ui.carregarAnteriores.disabled = true;
    const alturaAnterior = ui.historico.scrollHeight;
    try {
      const resposta = await apiChat("cliente/chat/mensagens/listar", {
        parametros: {
          id_conversa: estado.conversa.id_conversa,
          before_id: primeira.id_mensagem,
          limite: LIMITE_MENSAGENS,
        },
      });
      if (ciclo !== estado.ciclo) return;

      const anteriores = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
      const ids = new Set(estado.mensagens.map(mensagem => Number(mensagem.id_mensagem)));
      estado.mensagens = [
        ...anteriores.filter(mensagem => !ids.has(Number(mensagem.id_mensagem))),
        ...estado.mensagens,
      ];
      estado.temMaisMensagens = resposta.data?.tem_mais === true;
      renderizarConversa();
      window.requestAnimationFrame(() => {
        ui.historico.scrollTop = ui.historico.scrollHeight - alturaAnterior;
      });
    } catch (erro) {
      if (ciclo === estado.ciclo) definirAviso(erroAmigavel(erro, "Não foi possível carregar mensagens anteriores."), true);
    } finally {
      ui.carregarAnteriores.disabled = false;
    }
  }

  function uuidV4() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (let indice = 0; indice < bytes.length; indice += 1) bytes[indice] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(byte => byte.toString(16).padStart(2, "0")).join("");
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  function chaveParaEnvio(conteudo) {
    if (estado.chaveEnvioPendente?.conteudo === conteudo) return estado.chaveEnvioPendente.chave;
    const chave = uuidV4();
    estado.chaveEnvioPendente = { conteudo, chave };
    return chave;
  }

  async function enviarMensagem(evento) {
    evento.preventDefault();
    if (!estado.conversa || estado.enviando || !podeResponder()) return;

    const conteudo = ui.textarea.value.trim();
    if (!conteudo) {
      definirAviso("Escreva uma mensagem antes de enviar.", true);
      ui.textarea.focus();
      return;
    }

    const ciclo = estado.ciclo;
    const chave = chaveParaEnvio(conteudo);
    estado.enviando = true;
    sincronizarCompositor();
    definirAviso("Enviando mensagem...");
    try {
      const resposta = await apiChat("cliente/chat/mensagem/enviar", {
        metodo: "POST",
        dados: {
          id_conversa: estado.conversa.id_conversa,
          conteudo,
          chave_idempotencia: chave,
        },
      });
      if (ciclo !== estado.ciclo) return;

      const mensagem = resposta.data?.mensagem;
      if (!mensagem?.id_mensagem) throw new Error("A mensagem foi enviada sem confirmação válida.");
      if (!estado.mensagens.some(item => Number(item.id_mensagem) === Number(mensagem.id_mensagem))) {
        estado.mensagens.push(mensagem);
        estado.mensagens.sort((a, b) => Number(a.id_mensagem) - Number(b.id_mensagem));
      }
      estado.chaveEnvioPendente = null;
      ui.textarea.value = "";
      definirAviso("");
      renderizarConversa({ rolar: true });
    } catch (erro) {
      if (ciclo !== estado.ciclo) return;
      if (erro?.code === "CHAT_CLOSED" && estado.conversa) {
        estado.conversa.status = "encerrada";
        estado.conversa.pode_responder = false;
        renderizarConversa();
      }
      definirAviso(erroAmigavel(erro, "Não foi possível enviar a mensagem."), true);
    } finally {
      if (ciclo === estado.ciclo) {
        estado.enviando = false;
        sincronizarCompositor();
      }
    }
  }

  function inicializar() {
    ui.modal = document.getElementById("modalChatCliente");
    ui.profissional = document.getElementById("chatClienteProfissional");
    ui.dataHora = document.getElementById("chatClienteDataHora");
    ui.status = document.getElementById("chatClienteStatus");
    ui.aviso = document.getElementById("chatClienteAviso");
    ui.carregarAnteriores = document.getElementById("chatClienteCarregarAnteriores");
    ui.historico = document.getElementById("chatClienteHistorico");
    ui.formulario = document.getElementById("chatClienteFormulario");
    ui.textarea = document.getElementById("chatClienteMensagem");
    ui.enviar = document.getElementById("chatClienteEnviar");

    if (Object.values(ui).some(elemento => !elemento)) return;
    ui.avatar = document.getElementById("chatClienteAvatar");

    ui.formulario.addEventListener("submit", enviarMensagem);
    ui.carregarAnteriores.addEventListener("click", carregarMensagensAnteriores);
    document.addEventListener("click", evento => {
      if (evento.target.closest("[data-chat-cliente-fechar]")) limparEstado();
    }, true);
    document.addEventListener("keydown", evento => {
      if (evento.key === "Escape" && ui.modal.classList.contains("ativo")) limparEstado();
    }, true);
    // Fechamento por qualquer caminho (botão, Escape, script de modais): encerra o polling.
    new MutationObserver(() => {
      if (!ui.modal.classList.contains("ativo") && estado.conversa) limparEstado();
    }).observe(ui.modal, { attributes: true, attributeFilter: ["class"] });
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState !== "visible" || !estado.conversa) return;
      if (estado.leituraPendente) marcarLeitura();
      pararPolling();
      sincronizarConversa();
    });

    window.ChatModalCliente = { abrir };
  }

  document.addEventListener("DOMContentLoaded", inicializar);
})();
