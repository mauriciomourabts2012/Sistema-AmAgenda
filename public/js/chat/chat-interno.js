/* Chat interno: usa apenas as rotas Chat existentes e nunca injeta dados da API como HTML. */
(() => {
  "use strict";

  const API_BASE = "/public/api/api_central.php?path=";
  const LIMITE_CONVERSAS = 50;
  const LIMITE_MENSAGENS = 50;
  const PAGINA_SEGURA = "/public/views/agenda.html";
  // Super Admin fora do suporte não tem empresa: volta ao próprio painel (mesma rota usada por sessao.js).
  const PAGINA_SEGURA_SUPER_ADMIN = "/views/super-admin/painel-super-admin.html";
  const estado = {
    conversas: [],
    profissionais: new Map(),
    pagina: 1,
    temMais: false,
    conversa: null,
    mensagens: [],
    temMaisMensagens: false,
    enviando: false,
    chaveEnvioPendente: null,
    versaoLista: 0,
    versaoConversa: 0,
    inicializado: false,
  };
  const ui = {};

  function normalizar(valor) {
    return String(valor || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
  }

  function texto(valor) {
    return String(valor ?? "").trim();
  }

  function auth() {
    return window.__AUTH__ || {};
  }

  function sessaoBloqueadaParaChat() {
    const atual = auth();
    return normalizar(atual.tipo_usuario) === "super_admin" || atual.modo_suporte === true;
  }

  function temPermissao(codigo) {
    return !sessaoBloqueadaParaChat()
      && typeof window.usuarioPode === "function"
      && window.usuarioPode(codigo) === true;
  }

  function perfilProfissional() {
    const atual = auth();
    return normalizar(atual.perfil_nome || atual.perfil) === "profissional";
  }

  function podeFiltrarProfissional() {
    return !perfilProfissional() && temPermissao("chat.visualizar_todos_profissionais");
  }

  function podeResponder(conversa) {
    return conversa?.status === "aberta" && conversa?.pode_responder !== false && temPermissao("chat.responder");
  }

  function podeEncerrar(conversa) {
    return conversa?.status === "aberta" && conversa?.pode_encerrar !== false && temPermissao("chat.encerrar");
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
      const erro = new Error(json?.user_msg || "Não foi possível concluir a operação do chat.");
      erro.code = json?.code || "CHAT_REQUEST_FAILED";
      throw erro;
    }
    return json;
  }

  function formatarDataHora(data, hora) {
    const dataTexto = texto(data);
    const horaTexto = texto(hora).slice(0, 5);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dataTexto)) return horaTexto || "";
    const local = new Date(`${dataTexto}T12:00:00`);
    const formatada = Number.isNaN(local.getTime())
      ? dataTexto
      : local.toLocaleDateString("pt-BR", { day: "2-digit", month: "short", year: "numeric" });
    return horaTexto ? `${formatada} às ${horaTexto}` : formatada;
  }

  function formatarHorario(valor) {
    const bruto = texto(valor);
    if (!bruto) return "";
    const data = new Date(bruto.includes("T") ? bruto : bruto.replace(" ", "T"));
    if (Number.isNaN(data.getTime())) return bruto;
    return data.toLocaleString("pt-BR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
  }

  function rotuloStatus(status) {
    return status === "encerrada" ? "Encerrada" : "Aberta";
  }

  function definirMensagemLista(mensagem = "", erro = false) {
    ui.listaMensagem.textContent = mensagem;
    ui.listaMensagem.classList.toggle("chat-erro", erro);
  }

  function definirAviso(mensagem = "", erro = false) {
    ui.aviso.textContent = mensagem;
    ui.aviso.classList.toggle("erro", erro);
  }

  function criarElemento(tag, classe, valor) {
    const elemento = document.createElement(tag);
    if (classe) elemento.className = classe;
    if (typeof valor !== "undefined") elemento.textContent = valor;
    return elemento;
  }

  function substituirConversaNaLista(conversa) {
    if (!conversa?.id_conversa) return;
    const indice = estado.conversas.findIndex(item => Number(item.id_conversa) === Number(conversa.id_conversa));
    if (indice >= 0) estado.conversas[indice] = { ...estado.conversas[indice], ...conversa };
  }

  function registrarProfissionais(conversas) {
    (conversas || []).forEach(conversa => {
      const id = Number(conversa?.id_profissional || 0);
      const nome = texto(conversa?.profissional_nome);
      if (id > 0 && nome) estado.profissionais.set(id, nome);
    });
  }

  function atualizarOpcoesProfissional() {
    if (!podeFiltrarProfissional()) return;
    const selecionado = ui.filtroProfissional.value;
    while (ui.filtroProfissional.options.length > 1) ui.filtroProfissional.remove(1);
    [...estado.profissionais.entries()]
      .sort((a, b) => a[1].localeCompare(b[1], "pt-BR"))
      .forEach(([id, nome]) => {
        const opcao = document.createElement("option");
        opcao.value = String(id);
        opcao.textContent = nome;
        ui.filtroProfissional.append(opcao);
      });
    if ([...estado.profissionais.keys()].some(id => String(id) === selecionado)) {
      ui.filtroProfissional.value = selecionado;
    }
  }

  function conversasFiltradas() {
    const busca = normalizar(ui.busca.value);
    if (!busca) return estado.conversas;
    return estado.conversas.filter(conversa => {
      return [conversa.cliente_nome, conversa.profissional_nome, conversa.ultima_mensagem_previa]
        .some(valor => normalizar(valor).includes(busca));
    });
  }

  function renderizarLista() {
    ui.lista.replaceChildren();
    const conversas = conversasFiltradas();
    if (conversas.length === 0) {
      const vazio = criarElemento("p", "chat-historico-vazio", estado.conversas.length ? "Nenhuma conversa corresponde à busca." : "Nenhuma conversa encontrada.");
      ui.lista.append(vazio);
    } else {
      conversas.forEach(conversa => {
        const botao = document.createElement("button");
        botao.type = "button";
        botao.className = "chat-item-conversa";
        botao.dataset.idConversa = String(conversa.id_conversa);
        botao.setAttribute("role", "listitem");
        const ativa = Number(estado.conversa?.id_conversa) === Number(conversa.id_conversa);
        botao.classList.toggle("ativa", ativa);
        if (ativa) botao.setAttribute("aria-current", "true");

        const topo = criarElemento("div", "chat-item-topo");
        topo.append(criarElemento("strong", "chat-item-cliente", texto(conversa.cliente_nome) || "Cliente"));
        if (conversa.nao_lida === true) {
          const indicador = criarElemento("span", "chat-indicador-nao-lida");
          indicador.setAttribute("aria-label", "Não lida");
          indicador.title = "Não lida";
          topo.append(indicador);
        }
        const status = criarElemento("span", `chat-status ${conversa.status === "encerrada" ? "encerrada" : "aberta"}`, rotuloStatus(conversa.status));
        topo.append(status);

        const profissional = texto(conversa.profissional_nome);
        const agenda = formatarDataHora(conversa.agendamento_data, conversa.agendamento_hora_inicio);
        const meta = [profissional, agenda].filter(Boolean).join(" • ");
        const previa = texto(conversa.ultima_mensagem_previa) || "Sem mensagens";
        botao.append(topo, criarElemento("div", "chat-item-meta", meta), criarElemento("div", "chat-item-previa", previa));
        ui.lista.append(botao);
      });
    }
    ui.carregarMais.hidden = !estado.temMais;
  }

  function renderizarContador(quantidade) {
    const numero = Number(quantidade || 0);
    ui.contador.textContent = String(numero);
    ui.contador.hidden = numero <= 0;
  }

  function renderizarHistorico() {
    ui.historico.replaceChildren();
    if (estado.mensagens.length === 0) {
      ui.historico.append(criarElemento("p", "chat-historico-vazio", "Ainda não há mensagens nesta conversa."));
      return;
    }
    estado.mensagens.forEach(mensagem => {
      const artigo = criarElemento("article", "chat-mensagem");
      artigo.classList.toggle("propria", mensagem.propria === true);
      const nome = mensagem.propria === true ? "Você" : (texto(mensagem.remetente_nome) || "Remetente");
      artigo.append(
        criarElemento("span", "chat-mensagem-remetente", nome),
        criarElemento("p", "chat-mensagem-conteudo", texto(mensagem.conteudo)),
        criarElemento("time", "chat-mensagem-horario", formatarHorario(mensagem.criado_em))
      );
      ui.historico.append(artigo);
    });
  }

  function sincronizarCompositor() {
    const responder = podeResponder(estado.conversa);
    ui.formulario.hidden = !responder;
    ui.textarea.disabled = !responder || estado.enviando;
    ui.enviar.disabled = !responder || estado.enviando;
    if (!responder && estado.conversa?.status === "encerrada") {
      definirAviso("Esta conversa está encerrada.");
      ui.formulario.classList.add("encerrada");
    } else if (!responder && estado.conversa) {
      definirAviso("Você não possui permissão para responder esta conversa.");
      ui.formulario.classList.remove("encerrada");
    } else {
      if (!estado.enviando && !ui.aviso.classList.contains("erro")) definirAviso("");
      ui.formulario.classList.remove("encerrada");
    }
    ui.enviar.querySelector("span").textContent = estado.enviando ? "Enviando..." : "Enviar";
  }

  function renderizarConversa({ rolarAoFim = false } = {}) {
    const conversa = estado.conversa;
    const existe = Boolean(conversa);
    ui.estadoVazio.hidden = existe;
    ui.conteudo.hidden = !existe;
    ui.app.classList.toggle("chat-detalhe-aberta", existe);
    if (!conversa) {
      ui.carregarAnteriores.hidden = true;
      return;
    }

    ui.clienteNome.textContent = texto(conversa.cliente_nome) || "Conversa";
    const meta = [texto(conversa.profissional_nome), formatarDataHora(conversa.agendamento_data, conversa.agendamento_hora_inicio)]
      .filter(Boolean)
      .join(" • ");
    ui.meta.textContent = meta;
    ui.status.textContent = rotuloStatus(conversa.status);
    ui.status.className = `chat-status ${conversa.status === "encerrada" ? "encerrada" : "aberta"}`;
    ui.encerrar.hidden = !podeEncerrar(conversa);
    ui.carregarAnteriores.hidden = !estado.temMaisMensagens;
    renderizarHistorico();
    sincronizarCompositor();
    renderizarLista();
    if (rolarAoFim) {
      requestAnimationFrame(() => { ui.historico.scrollTop = ui.historico.scrollHeight; });
    }
  }

  async function atualizarContadorNaoLidas() {
    try {
      const resposta = await apiChat("chat/conversas/nao-lidas");
      renderizarContador(resposta.data?.conversas_nao_lidas);
    } catch (_) {
      renderizarContador(0);
    }
  }

  function parametrosDaLista(pagina) {
    const parametros = {
      status: ui.statusLista.value,
      pagina,
      limite: LIMITE_CONVERSAS,
    };
    if (ui.somenteNaoLidas.checked) parametros.nao_lidas = 1;
    if (podeFiltrarProfissional() && ui.filtroProfissional.value) {
      parametros.id_profissional = ui.filtroProfissional.value;
    }
    return parametros;
  }

  async function carregarConversas({ mais = false } = {}) {
    const versao = ++estado.versaoLista;
    const pagina = mais ? estado.pagina + 1 : 1;
    if (!mais) definirMensagemLista("Carregando conversas...");
    ui.carregarMais.disabled = true;
    try {
      const resposta = await apiChat("chat/conversas/listar", { parametros: parametrosDaLista(pagina) });
      if (versao !== estado.versaoLista) return;
      const itens = Array.isArray(resposta.data?.itens) ? resposta.data.itens : [];
      estado.conversas = mais ? [...estado.conversas, ...itens] : itens;
      estado.pagina = Number(resposta.data?.pagina || pagina);
      estado.temMais = resposta.data?.tem_mais === true;
      registrarProfissionais(itens);
      atualizarOpcoesProfissional();
      definirMensagemLista(estado.conversas.length ? "" : "Nenhuma conversa encontrada.");
      renderizarLista();
    } catch (erro) {
      if (versao !== estado.versaoLista) return;
      estado.temMais = false;
      definirMensagemLista(erroAmigavel(erro, "Não foi possível carregar as conversas."), true);
      renderizarLista();
    } finally {
      if (versao === estado.versaoLista) ui.carregarMais.disabled = false;
    }
  }

  async function marcarLeituraDaConversa() {
    const ultima = estado.mensagens[estado.mensagens.length - 1];
    if (!estado.conversa || !ultima?.id_mensagem) return;
    try {
      const resposta = await apiChat("chat/leitura/marcar", {
        metodo: "POST",
        dados: {
          id_conversa: estado.conversa.id_conversa,
          id_ultima_mensagem_lida: ultima.id_mensagem,
        },
      });
      if (!estado.conversa || Number(estado.conversa.id_conversa) !== Number(resposta.data?.id_conversa)) return;
      estado.conversa.nao_lida = false;
      estado.conversa.id_ultima_mensagem_lida = resposta.data?.id_ultima_mensagem_lida || ultima.id_mensagem;
      substituirConversaNaLista(estado.conversa);
      renderizarLista();
      atualizarContadorNaoLidas();
    } catch (_) {
      // A conversa continua aberta; uma falha na marcação não pode esconder mensagens já autorizadas.
    }
  }

  async function abrirConversa(idConversa) {
    const versao = ++estado.versaoConversa;
    definirAviso("Carregando conversa...");
    try {
      const resposta = await apiChat("chat/conversa/abrir", {
        parametros: { id_conversa: idConversa, limite: LIMITE_MENSAGENS },
      });
      if (versao !== estado.versaoConversa) return;
      estado.conversa = resposta.data?.conversa || null;
      estado.mensagens = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
      estado.temMaisMensagens = resposta.data?.tem_mais_anteriores === true;
      substituirConversaNaLista(estado.conversa);
      definirAviso("");
      renderizarConversa({ rolarAoFim: true });
      marcarLeituraDaConversa();
      return true;
    } catch (erro) {
      if (versao !== estado.versaoConversa) return null; // substituída por outra abertura
      definirAviso(erroAmigavel(erro, "Não foi possível abrir a conversa."), true);
      return false;
    }
  }

  // Deep link: chat-interno.html?id_conversa=ID (vindo de "Abrir conversa" na Agenda).
  // O parâmetro é mantido na URL; a autorização é sempre do backend.
  function idConversaDaUrl() {
    const valor = new URL(window.location.href).searchParams.get("id_conversa");
    return /^[1-9]\d{0,17}$/.test(String(valor || "")) ? valor : null;
  }

  async function abrirConversaDaUrl(idConversa) {
    const aberta = await abrirConversa(idConversa);
    if (aberta !== false) return; // aberta, ou substituída por outra escolha do usuário
    // Sem expor detalhes: a conversa pode não existir ou estar fora do escopo do usuário.
    const mensagem = "Não foi possível abrir a conversa solicitada. Ela pode não existir ou não estar disponível para você.";
    if (window.MensagemSistema?.aviso) window.MensagemSistema.aviso(mensagem, { titulo: "Chat" });
    else definirMensagemLista(mensagem, true);
  }

  async function carregarMensagensAnteriores() {
    const primeira = estado.mensagens[0];
    if (!estado.conversa || !primeira?.id_mensagem || !estado.temMaisMensagens) return;
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
      const anteriores = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
      const ids = new Set(estado.mensagens.map(mensagem => Number(mensagem.id_mensagem)));
      estado.mensagens = [...anteriores.filter(mensagem => !ids.has(Number(mensagem.id_mensagem))), ...estado.mensagens];
      estado.temMaisMensagens = resposta.data?.tem_mais === true;
      renderizarConversa();
      requestAnimationFrame(() => { ui.historico.scrollTop = ui.historico.scrollHeight - alturaAnterior; });
    } catch (erro) {
      definirAviso(erroAmigavel(erro, "Não foi possível carregar mensagens anteriores."), true);
    } finally {
      ui.carregarAnteriores.disabled = false;
    }
  }

  function uuidV4() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (let i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
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

  function incorporarMensagem(mensagem) {
    if (!mensagem?.id_mensagem) return;
    const existe = estado.mensagens.some(item => Number(item.id_mensagem) === Number(mensagem.id_mensagem));
    if (!existe) estado.mensagens.push(mensagem);
    estado.mensagens.sort((a, b) => Number(a.id_mensagem) - Number(b.id_mensagem));
    if (estado.conversa) {
      estado.conversa.id_ultima_mensagem = mensagem.id_mensagem;
      estado.conversa.ultima_mensagem_em = mensagem.criado_em || estado.conversa.ultima_mensagem_em;
      estado.conversa.ultima_mensagem_previa = mensagem.conteudo || "";
      estado.conversa.nao_lida = false;
      const restante = estado.conversas.filter(item => Number(item.id_conversa) !== Number(estado.conversa.id_conversa));
      estado.conversas = [{ ...estado.conversa }, ...restante];
    }
  }

  async function enviarMensagem(evento) {
    evento.preventDefault();
    if (!estado.conversa || estado.enviando || !podeResponder(estado.conversa)) return;
    const conteudo = ui.textarea.value.trim();
    if (!conteudo) {
      definirAviso("Escreva uma mensagem antes de enviar.", true);
      ui.textarea.focus();
      return;
    }
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
      const mensagem = resposta.data?.mensagem;
      if (!mensagem?.id_mensagem) throw new Error("A mensagem foi enviada sem confirmação válida.");
      incorporarMensagem(mensagem);
      estado.chaveEnvioPendente = null;
      ui.textarea.value = "";
      definirAviso("");
      renderizarConversa({ rolarAoFim: true });
      atualizarContadorNaoLidas();
    } catch (erro) {
      if (erro?.code === "CHAT_CLOSED" && estado.conversa) {
        estado.conversa.status = "encerrada";
        substituirConversaNaLista(estado.conversa);
        renderizarConversa();
        carregarConversas();
      }
      definirAviso(erroAmigavel(erro, "Não foi possível enviar a mensagem."), true);
    } finally {
      estado.enviando = false;
      sincronizarCompositor();
    }
  }

  async function encerrarConversa() {
    if (!estado.conversa || !podeEncerrar(estado.conversa)) return;
    const pergunta = "Deseja encerrar esta conversa? Ela continuará disponível no histórico autorizado.";
    // Confirmação destrutiva no componente padrão do sistema (variante de perigo).
    const confirmado = typeof window.MensagemSistema?.confirmar === "function"
      ? await window.MensagemSistema.confirmar(pergunta, { titulo: "Encerrar conversa", textoConfirmar: "Encerrar", textoCancelar: "Cancelar", perigoso: true })
      : window.confirm(pergunta);
    if (!confirmado || !estado.conversa || !podeEncerrar(estado.conversa)) return;
    ui.encerrar.disabled = true;
    definirAviso("Encerrando conversa...");
    try {
      const resposta = await apiChat("chat/conversa/encerrar", {
        metodo: "POST",
        dados: { id_conversa: estado.conversa.id_conversa },
      });
      if (resposta.data?.status === "encerrada") {
        estado.conversa.status = "encerrada";
        estado.conversa.pode_responder = false;
        estado.conversa.pode_encerrar = false;
        substituirConversaNaLista(estado.conversa);
        definirAviso("Conversa encerrada.");
        renderizarConversa();
        carregarConversas();
        atualizarContadorNaoLidas();
      }
    } catch (erro) {
      if (erro?.code === "CHAT_ALREADY_CLOSED" && estado.conversa) {
        estado.conversa.status = "encerrada";
        renderizarConversa();
        carregarConversas();
      }
      definirAviso(erroAmigavel(erro, "Não foi possível encerrar a conversa."), true);
    } finally {
      ui.encerrar.disabled = false;
    }
  }

  function voltarParaLista() {
    estado.conversa = null;
    estado.mensagens = [];
    estado.temMaisMensagens = false;
    definirAviso("");
    renderizarConversa();
  }

  function reiniciarLista() {
    estado.conversas = [];
    estado.temMais = false;
    carregarConversas();
  }

  function configurarControles() {
    ui.lista.addEventListener("click", evento => {
      const item = evento.target.closest("button[data-id-conversa]");
      if (item) abrirConversa(item.dataset.idConversa);
    });
    ui.carregarMais.addEventListener("click", () => carregarConversas({ mais: true }));
    ui.carregarAnteriores.addEventListener("click", carregarMensagensAnteriores);
    ui.statusLista.addEventListener("change", reiniciarLista);
    ui.somenteNaoLidas.addEventListener("change", reiniciarLista);
    ui.filtroProfissional.addEventListener("change", reiniciarLista);
    let temporizadorBusca = 0;
    ui.busca.addEventListener("input", () => {
      window.clearTimeout(temporizadorBusca);
      temporizadorBusca = window.setTimeout(renderizarLista, 160);
    });
    ui.formulario.addEventListener("submit", enviarMensagem);
    ui.encerrar.addEventListener("click", encerrarConversa);
    ui.voltar.addEventListener("click", voltarParaLista);
  }

  function mapearElementos() {
    ui.app = document.getElementById("chatApp");
    ui.busca = document.getElementById("chatBusca");
    ui.statusLista = document.getElementById("chatStatus");
    ui.somenteNaoLidas = document.getElementById("chatSomenteNaoLidas");
    ui.filtroProfissionalGrupo = document.getElementById("chatFiltroProfissionalGrupo");
    ui.filtroProfissional = document.getElementById("chatFiltroProfissional");
    ui.listaMensagem = document.getElementById("chatListaMensagem");
    ui.lista = document.getElementById("chatListaConversas");
    ui.carregarMais = document.getElementById("chatCarregarMais");
    ui.contador = document.getElementById("chatContadorNaoLidas");
    ui.estadoVazio = document.getElementById("chatEstadoVazio");
    ui.conteudo = document.getElementById("chatConversaConteudo");
    ui.clienteNome = document.getElementById("chatClienteNome");
    ui.meta = document.getElementById("chatConversaMeta");
    ui.status = document.getElementById("chatStatusConversa");
    ui.encerrar = document.getElementById("chatEncerrar");
    ui.historico = document.getElementById("chatHistorico");
    ui.carregarAnteriores = document.getElementById("chatCarregarAnteriores");
    ui.formulario = document.getElementById("chatFormularioMensagem");
    ui.textarea = document.getElementById("chatConteudoMensagem");
    ui.enviar = document.getElementById("chatEnviar");
    ui.aviso = document.getElementById("chatAviso");
    ui.voltar = document.getElementById("chatVoltar");
  }

  function inicializar() {
    if (estado.inicializado || !window.__AUTH__) return;
    estado.inicializado = true;
    if (sessaoBloqueadaParaChat() || !temPermissao("chat.visualizar")) {
      const superAdminSemSuporte = normalizar(auth().tipo_usuario) === "super_admin" && auth().modo_suporte !== true;
      window.location.replace(superAdminSemSuporte ? PAGINA_SEGURA_SUPER_ADMIN : PAGINA_SEGURA);
      return;
    }
    mapearElementos();
    ui.app.hidden = false;
    if (!temPermissao("chat.visualizar_historico")) {
      const encerradas = ui.statusLista.querySelector('option[value="encerrada"]');
      if (encerradas) encerradas.remove();
    }
    ui.filtroProfissionalGrupo.hidden = !podeFiltrarProfissional();
    configurarControles();
    renderizarConversa();
    const idConversaUrl = idConversaDaUrl();
    carregarConversas().finally(() => {
      if (idConversaUrl) abrirConversaDaUrl(idConversaUrl);
    });
    atualizarContadorNaoLidas();
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.addEventListener("amagenda:sessao-carregada", inicializar);
    inicializar();
  });
})();
