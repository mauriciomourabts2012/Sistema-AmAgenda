/* Chat rápido da Agenda: reutiliza exclusivamente as rotas existentes do Chat interno. */
(() => {
  "use strict";

  const API_BASE = "/public/api/api_central.php?path=";
  const LIMITE_MENSAGENS = 50;
  // Modal aberto e visível: busca incremental (after_id) a cada 10 s.
  const INTERVALO_CONVERSA_MS = 10000;
  const PREFIXOS_FOTO = ["/public/uploads/usuarios/", "/public/uploads/clientes/", "/public/imagens/usuarios/", "/public/imagens/clientes/"];
  const estado = {
    conversa: null,
    mensagens: [],
    temMaisMensagens: false,
    abrindo: false,
    enviando: false,
    chaveEnvioPendente: null,
    ciclo: 0,
    idPrimeiraNova: null,
    leituraPendente: false,
    timerConversa: 0,
    sincronizando: false,
  };
  const ui = {};

  function texto(valor) {
    return String(valor ?? "").trim();
  }

  function normalizar(valor) {
    return texto(valor).normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
  }

  function sessaoBloqueada() {
    const auth = window.__AUTH__ || {};
    return normalizar(auth.tipo_usuario) === "super_admin" || auth.modo_suporte === true;
  }

  function temPermissao(codigo) {
    return !sessaoBloqueada()
      && typeof window.usuarioPode === "function"
      && window.usuarioPode(codigo) === true;
  }

  function podeAbrir() {
    return temPermissao("chat.visualizar") && temPermissao("chat.responder");
  }

  function podeVisualizarConversa(conversa) {
    return temPermissao("chat.visualizar")
      && (conversa?.status !== "encerrada" || temPermissao("chat.visualizar_historico"));
  }

  function podeResponder() {
    return estado.conversa?.status === "aberta"
      && estado.conversa?.pode_responder !== false
      && temPermissao("chat.responder");
  }

  function podeEncerrar() {
    return estado.conversa?.status === "aberta"
      && estado.conversa?.pode_encerrar !== false
      && temPermissao("chat.encerrar");
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

  async function apiChat(rota, { metodo = "GET", parametros = {}, dados = null } = {}) {
    const url = new URL(`${API_BASE}${encodeURIComponent(rota)}`, window.location.origin);
    Object.entries(parametros || {}).forEach(([chave, valor]) => {
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
    if (!resposta.ok || json?.ok !== true) {
      const erro = new Error(json?.user_msg || "Não foi possível concluir a operação do Chat.");
      erro.code = json?.code || "CHAT_REQUEST_FAILED";
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
    return data.toLocaleString("pt-BR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
  }

  /* Avatar: iniciais por padrão; foto apenas de caminho de upload conhecido. */
  function urlFotoSegura(valor) {
    const caminho = texto(valor);
    if (!caminho || !PREFIXOS_FOTO.some((prefixo) => caminho.startsWith(prefixo))) return "";
    return /^[A-Za-z0-9/._-]+\.(?:jpe?g|png|webp)$/i.test(caminho) ? caminho : "";
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
    ui.cliente.textContent = texto(conversa?.cliente_nome) || "Conversa do agendamento";
    if (ui.avatar) {
      if (conversa) preencherAvatar(ui.avatar, texto(conversa.cliente_nome) || "Cliente", conversa.cliente_foto_url);
      else ui.avatar.replaceChildren();
    }
    ui.profissional.textContent = texto(conversa?.profissional_nome) || "—";
    ui.dataHora.textContent = conversa
      ? formatarDataHora(conversa.agendamento_data, conversa.agendamento_hora_inicio)
      : "—";
    const encerrada = conversa?.status === "encerrada";
    ui.status.textContent = conversa ? (encerrada ? "Encerrada" : "Aberta") : "";
    ui.status.className = `chat-modal-status ${encerrada ? "encerrada" : "aberta"}`;
    ui.encerrar.hidden = !podeEncerrar();
    ui.encerrar.disabled = false;
    // Sem agendamento vinculado (id_agendamento NULL) ou fora da Agenda: sem atalho.
    if (ui.abrirAgendamento) {
      ui.abrirAgendamento.hidden = !idAgendamentoDaConversa(conversa)
        || typeof window.AgendaNotificacao?.abrirAgendamento !== "function";
    }
  }

  // ============================================================
  // CHAT RÁPIDO — ABRIR AGENDAMENTO VINCULADO
  // ============================================================
  // O Chat rápido já está em agenda.html: sem deep link e sem recarregar. Fecha este modal e
  // usa o fluxo oficial da Agenda (window.AgendaNotificacao.abrirAgendamento, exposto por
  // lista-agenda.js), que consulta o backend com as permissões atuais antes de abrir o modal.
  function idAgendamentoDaConversa(conversa) {
    const id = Number(conversa?.id_agendamento);
    return Number.isInteger(id) && id > 0 ? id : null;
  }

  function abrirAgendamentoDaConversa() {
    const idAgendamento = idAgendamentoDaConversa(estado.conversa);
    const abrirAgendamento = window.AgendaNotificacao?.abrirAgendamento;
    if (!idAgendamento || typeof abrirAgendamento !== "function") return;
    ui.modal.classList.remove("ativo"); // o observador do modal limpa o estado e para o polling
    ui.modal.setAttribute("aria-hidden", "true");
    abrirAgendamento(idAgendamento);
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
      ui.historico.append(criarElemento("p", "chat-modal-vazio", "Ainda não há mensagens nesta conversa."));
      return;
    }
    estado.mensagens.forEach((mensagem) => {
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
      const linha = criarElemento("div", `chat-modal-linha${propria ? " propria" : ""}`);
      const artigo = criarElemento("article", "chat-modal-mensagem");
      artigo.classList.toggle("propria", propria);
      const nomeRemetente = texto(mensagem.remetente_nome) || "Remetente";
      artigo.append(
        criarElemento("span", "chat-modal-remetente", propria ? "Você" : nomeRemetente),
        criarElemento("p", "chat-modal-conteudo", texto(mensagem.conteudo)),
        criarElemento("time", "chat-modal-horario", formatarHorario(mensagem.criado_em))
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
      definirAviso("Esta conversa está encerrada.");
    } else if (!responder && estado.conversa) {
      definirAviso("Você não possui permissão para responder esta conversa.", true);
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

  function bloquearAcoesAgenda(bloquear) {
    document.querySelectorAll('.agenda-menu-item[data-acao="abrir_conversa"]').forEach((botao) => {
      botao.disabled = bloquear;
    });
  }

  function avisarFalha(mensagem) {
    if (typeof window.MensagemSistema?.erro === "function") {
      window.MensagemSistema.erro(mensagem, { titulo: "Chat" });
    }
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
      await apiChat("chat/leitura/marcar", {
        metodo: "POST",
        dados: {
          id_conversa: conversa.id_conversa,
          id_ultima_mensagem_lida: ultima.id_mensagem,
        },
      });
      window.ChatNaoLidas?.atualizarAgora?.();
    } catch (_) {
      // A leitura das mensagens continua disponível mesmo se a marcação falhar.
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
      const resposta = await apiChat("chat/mensagens/listar", {
        parametros: { id_conversa: conversa.id_conversa, after_id: ultima?.id_mensagem || 0, limite: LIMITE_MENSAGENS },
      });
      if (ciclo !== estado.ciclo || estado.conversa !== conversa) return;
      const novas = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
      const statusMudou = resposta.data?.status && resposta.data.status !== conversa.status;
      if (statusMudou) {
        conversa.status = resposta.data.status;
        if (conversa.status !== "aberta") {
          conversa.pode_responder = false;
          conversa.pode_encerrar = false;
        }
      }
      const ids = new Set(estado.mensagens.map((mensagem) => Number(mensagem.id_mensagem)));
      const ineditas = novas.filter((mensagem) => !ids.has(Number(mensagem.id_mensagem)));
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
    ui.historico.replaceChildren(criarElemento("p", "chat-modal-vazio", "Carregando mensagens..."));
    const resposta = await apiChat("chat/conversa/abrir", {
      parametros: { id_conversa: idConversa, limite: LIMITE_MENSAGENS },
    });
    if (ciclo !== estado.ciclo) return;
    estado.conversa = resposta.data?.conversa || null;
    estado.mensagens = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
    estado.temMaisMensagens = resposta.data?.tem_mais_anteriores === true;
    if (!estado.conversa?.id_conversa) throw new Error("A conversa não retornou dados válidos.");
    if (!podeVisualizarConversa(estado.conversa)) throw new Error("Você não possui permissão para visualizar esta conversa.");
    // Marcador anterior à leitura: posição do separador "Novas mensagens".
    const marcador = Number(estado.conversa.id_ultima_mensagem_lida || 0);
    const primeiraNova = estado.mensagens.find((mensagem) => Number(mensagem.id_mensagem) > marcador && mensagem.propria !== true && mensagem.remetente_tipo !== "sistema");
    estado.idPrimeiraNova = primeiraNova ? Number(primeiraNova.id_mensagem) : null;
    definirAviso("");
    renderizarConversa({ rolar: "abertura" });
    marcarLeitura();
    agendarPolling();
  }

  async function abrir(idAgendamento) {
    if (estado.abrindo) return;
    const id = texto(idAgendamento);
    if (!/^[1-9]\d{0,17}$/.test(id)) return;
    if (!podeAbrir()) {
      avisarFalha("Você não possui permissão para abrir conversas.");
      return;
    }

    estado.abrindo = true;
    bloquearAcoesAgenda(true);
    const ciclo = ++estado.ciclo;
    try {
      const resposta = await apiChat("chat/conversa/iniciar", {
        metodo: "POST",
        dados: { id_agendamento: id },
      });
      if (ciclo !== estado.ciclo) return;
      const conversa = resposta.data?.conversa || null;
      const idConversa = Number(conversa?.id_conversa);
      if (!Number.isInteger(idConversa) || idConversa <= 0) {
        throw new Error("Não foi possível identificar a conversa deste agendamento.");
      }
      if (!podeVisualizarConversa(conversa)) {
        throw new Error("Você não possui permissão para visualizar esta conversa.");
      }
      estado.conversa = conversa;
      estado.mensagens = [];
      estado.temMaisMensagens = false;
      estado.chaveEnvioPendente = null;
      renderizarCabecalho();
      sincronizarCompositor();
      abrirModal();
      await carregarConversa(idConversa, ciclo);
    } catch (erro) {
      if (ciclo !== estado.ciclo) return;
      if (ui.modal.classList.contains("ativo")) {
        definirAviso(erroAmigavel(erro, "Não foi possível abrir a conversa."), true);
        ui.historico.replaceChildren(criarElemento("p", "chat-modal-vazio erro", "Não foi possível carregar as mensagens."));
      } else {
        avisarFalha(erroAmigavel(erro, "Não foi possível abrir a conversa deste agendamento."));
      }
    } finally {
      if (ciclo === estado.ciclo) estado.abrindo = false;
      bloquearAcoesAgenda(false);
    }
  }

  async function carregarMensagensAnteriores() {
    const primeira = estado.mensagens[0];
    if (!estado.conversa || !primeira?.id_mensagem || !estado.temMaisMensagens) return;
    const ciclo = estado.ciclo;
    ui.carregarAnteriores.disabled = true;
    const alturaAnterior = ui.historico.scrollHeight;
    try {
      const resposta = await apiChat("chat/mensagens/listar", {
        parametros: {
          id_conversa: estado.conversa.id_conversa,
          before_id: primeira.id_mensagem,
          limite: LIMITE_MENSAGENS,
        },
      });
      if (ciclo !== estado.ciclo) return;
      const anteriores = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
      const ids = new Set(estado.mensagens.map((mensagem) => Number(mensagem.id_mensagem)));
      estado.mensagens = [
        ...anteriores.filter((mensagem) => !ids.has(Number(mensagem.id_mensagem))),
        ...estado.mensagens,
      ];
      estado.temMaisMensagens = resposta.data?.tem_mais === true;
      if (resposta.data?.status === "encerrada") estado.conversa.status = "encerrada";
      renderizarConversa();
      window.requestAnimationFrame(() => {
        ui.historico.scrollTop = ui.historico.scrollHeight - alturaAnterior;
      });
      marcarLeitura();
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
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");
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
    definirAviso("Enviando mensagem...");
    sincronizarCompositor();
    try {
      const resposta = await apiChat("chat/mensagem/enviar", {
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
      if (!estado.mensagens.some((item) => Number(item.id_mensagem) === Number(mensagem.id_mensagem))) {
        estado.mensagens.push(mensagem);
        estado.mensagens.sort((a, b) => Number(a.id_mensagem) - Number(b.id_mensagem));
      }
      estado.chaveEnvioPendente = null;
      ui.textarea.value = "";
      definirAviso("");
      renderizarConversa({ rolar: true });
    } catch (erro) {
      if (ciclo !== estado.ciclo) return;
      if (erro?.code === "CHAT_CLOSED") {
        estado.conversa.status = "encerrada";
        estado.conversa.pode_responder = false;
        estado.conversa.pode_encerrar = false;
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

  async function encerrarConversa() {
    if (!estado.conversa || !podeEncerrar()) return;
    if (typeof window.MensagemSistema?.confirmar !== "function") {
      definirAviso("Não foi possível abrir a confirmação de encerramento.", true);
      return;
    }
    const confirmado = await window.MensagemSistema.confirmar(
      "Após encerrar, não será possível enviar novas mensagens nesta conversa. O histórico continuará disponível para consulta.\n\nDeseja continuar?",
      { titulo: "Encerrar conversa?", textoConfirmar: "Encerrar conversa", textoCancelar: "Cancelar", perigoso: true }
    );
    if (!confirmado || !estado.conversa || !podeEncerrar()) return;

    const ciclo = estado.ciclo;
    ui.encerrar.disabled = true;
    definirAviso("Encerrando conversa...");
    try {
      const resposta = await apiChat("chat/conversa/encerrar", {
        metodo: "POST",
        dados: { id_conversa: estado.conversa.id_conversa },
      });
      if (ciclo !== estado.ciclo) return;
      if (resposta.data?.status === "encerrada") {
        estado.conversa.status = "encerrada";
        estado.conversa.pode_responder = false;
        estado.conversa.pode_encerrar = false;
        renderizarConversa();
        definirAviso("Conversa encerrada.");
      }
    } catch (erro) {
      if (ciclo !== estado.ciclo) return;
      if (erro?.code === "CHAT_ALREADY_CLOSED") {
        estado.conversa.status = "encerrada";
        estado.conversa.pode_responder = false;
        estado.conversa.pode_encerrar = false;
        renderizarConversa();
      }
      definirAviso(erroAmigavel(erro, "Não foi possível encerrar a conversa."), true);
    } finally {
      if (ciclo === estado.ciclo) ui.encerrar.disabled = false;
    }
  }

  function mapearElementos() {
    ui.modal = document.getElementById("modalChatAgenda");
    ui.cliente = document.getElementById("chatModalCliente");
    ui.profissional = document.getElementById("chatModalProfissional");
    ui.dataHora = document.getElementById("chatModalDataHora");
    ui.status = document.getElementById("chatModalStatus");
    ui.encerrar = document.getElementById("chatModalEncerrar");
    ui.aviso = document.getElementById("chatModalAviso");
    ui.carregarAnteriores = document.getElementById("chatModalCarregarAnteriores");
    ui.historico = document.getElementById("chatModalHistorico");
    ui.formulario = document.getElementById("chatModalFormulario");
    ui.textarea = document.getElementById("chatModalMensagem");
    ui.enviar = document.getElementById("chatModalEnviar");
    const obrigatoriosOk = Object.values(ui).every(Boolean);
    ui.avatar = document.getElementById("chatModalAvatar");
    ui.abrirAgendamento = document.getElementById("chatModalAbrirAgendamento");
    return obrigatoriosOk;
  }

  function inicializar() {
    if (!mapearElementos()) return;
    ui.formulario.addEventListener("submit", enviarMensagem);
    ui.encerrar.addEventListener("click", encerrarConversa);
    ui.abrirAgendamento?.addEventListener("click", abrirAgendamentoDaConversa);
    ui.carregarAnteriores.addEventListener("click", carregarMensagensAnteriores);
    const observador = new MutationObserver(() => {
      if (!ui.modal.classList.contains("ativo") && estado.conversa) limparEstado();
    });
    observador.observe(ui.modal, { attributes: true, attributeFilter: ["class", "aria-hidden"] });
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState !== "visible" || !estado.conversa) return;
      if (estado.leituraPendente) marcarLeitura();
      pararPolling();
      sincronizarConversa();
    });
  }

  window.ChatModalAgenda = Object.freeze({ abrir });
  document.addEventListener("DOMContentLoaded", inicializar);
})();
