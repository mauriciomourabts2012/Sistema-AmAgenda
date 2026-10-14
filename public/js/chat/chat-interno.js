/* Chat interno: usa apenas as rotas Chat existentes e nunca injeta dados da API como HTML. */
(() => {
  "use strict";

  const API_BASE = "/public/api/api_central.php?path=";
  const LIMITE_CONVERSAS = 50;
  const LIMITE_MENSAGENS = 50;
  // Conversa aberta e visível: busca incremental (after_id) a cada 10 s.
  const INTERVALO_CONVERSA_MS = 10000;
  const PREFIXOS_FOTO = ["/public/imagens/usuarios/", "/public/imagens/clientes/"];
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
    // Primeira mensagem não lida no momento da abertura (separador "Novas mensagens").
    idPrimeiraNova: null,
    leituraPendente: false,
    timerConversa: 0,
    sincronizandoConversa: false,
    assinaturaNaoLidas: "",
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

  // ============================================================
  // CHAT — ABRIR AGENDAMENTO VINCULADO
  // ============================================================
  // Reutiliza o deep link oficial da Agenda (o mesmo da Central de Notificações); o modal
  // oficial continua existindo só em agenda.html. origem=chat + id_conversa permitem que a
  // Agenda retorne a esta conversa; a autorização continua sendo do backend da Agenda.
  const PAGINA_AGENDA = "/public/views/agenda.html";

  function idAgendamentoDaConversa(conversa) {
    const id = Number(conversa?.id_agendamento);
    return Number.isInteger(id) && id > 0 ? id : null;
  }

  function abrirAgendamentoDaConversa() {
    const idAgendamento = idAgendamentoDaConversa(estado.conversa);
    const idConversa = Number(estado.conversa?.id_conversa);
    if (!idAgendamento || !Number.isInteger(idConversa) || idConversa <= 0) return;
    const destino = new URL(PAGINA_AGENDA, window.location.origin);
    destino.searchParams.set("acao", "abrir_agendamento");
    destino.searchParams.set("id_agendamento", String(idAgendamento));
    destino.searchParams.set("origem", "chat");
    destino.searchParams.set("id_conversa", String(idConversa));
    window.location.assign(destino.toString());
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

  // Identificador visual do agendamento: "12 out. 2026 às 08:30".
  function formatarAgendamentoCurto(data, hora) {
    return formatarDataHora(data, hora).replace(/ de /g, " ");
  }

  function criarLinhaAgendamento(classe, conversa, comRotulo) {
    const curto = formatarAgendamentoCurto(conversa.agendamento_data, conversa.agendamento_hora_inicio);
    if (!curto) return null;
    const linha = criarElemento("div", classe);
    const icone = criarElemento("i", "fa-regular fa-calendar");
    icone.setAttribute("aria-hidden", "true");
    const textoLinha = comRotulo ? `Agendamento: ${curto}` : curto;
    linha.append(icone, criarElemento("span", "chat-agendamento-texto", textoLinha));
    linha.title = `Agendamento: ${curto}`;
    return linha;
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

  /* Avatar: iniciais por padrão; a foto só é usada quando o backend devolve um caminho
     dos diretórios de upload conhecidos. Se a imagem falhar, permanecem as iniciais. */
  function urlFotoSegura(valor) {
    const caminho = texto(valor);
    if (!caminho || !PREFIXOS_FOTO.some(prefixo => caminho.startsWith(prefixo))) return "";
    return /^[A-Za-z0-9/._-]+$/.test(caminho) ? caminho : "";
  }

  function iniciais(nome) {
    const partes = texto(nome).split(/\s+/).filter(Boolean);
    if (!partes.length) return "?";
    const primeira = partes[0].charAt(0);
    const ultima = partes.length > 1 ? partes[partes.length - 1].charAt(0) : "";
    return `${primeira}${ultima}`.toUpperCase();
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

  function criarAvatar(nome, foto, classe = "") {
    return preencherAvatar(criarElemento("span", `chat-avatar${classe ? ` ${classe}` : ""}`), nome, foto);
  }

  // Lista: hoje mostra a hora; ontem "Ontem"; demais datas dd/mm (ou dd/mm/aa em outro ano).
  function formatarHorarioLista(valor) {
    const bruto = texto(valor);
    if (!bruto) return "";
    const data = new Date(bruto.includes("T") ? bruto : bruto.replace(" ", "T"));
    if (Number.isNaN(data.getTime())) return "";
    const hoje = new Date();
    const inicioHoje = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate());
    const inicioData = new Date(data.getFullYear(), data.getMonth(), data.getDate());
    const dias = Math.round((inicioHoje - inicioData) / 86400000);
    if (dias === 0) return data.toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" });
    if (dias === 1) return "Ontem";
    return data.toLocaleDateString("pt-BR", data.getFullYear() === hoje.getFullYear()
      ? { day: "2-digit", month: "2-digit" }
      : { day: "2-digit", month: "2-digit", year: "2-digit" });
  }

  function quantidadeNaoLidas(conversa) {
    const quantidade = Number(conversa?.quantidade_nao_lidas);
    if (Number.isFinite(quantidade) && quantidade > 0) return Math.floor(quantidade);
    return conversa?.nao_lida === true ? 1 : 0;
  }

  function textoPrevia(conversa) {
    const previa = texto(conversa?.ultima_mensagem_previa);
    if (!previa) return "Sem mensagens";
    // Sistema: somente o conteúdo (nunca "Você:", nem atribuído a uma pessoa).
    if (conversa?.remetente_ultima_mensagem?.tipo === "sistema") return previa;
    return conversa?.remetente_ultima_mensagem?.propria === true ? `Você: ${previa}` : previa;
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

        const naoLidas = quantidadeNaoLidas(conversa);
        botao.classList.toggle("nao-lida", naoLidas > 0);
        const nomeCliente = texto(conversa.cliente_nome) || "Cliente";

        const corpo = criarElemento("div", "chat-item-corpo");
        const topo = criarElemento("div", "chat-item-topo");
        const nomeItem = criarElemento("strong", "chat-item-cliente", nomeCliente);
        nomeItem.title = nomeCliente;
        topo.append(nomeItem);
        const horario = formatarHorarioLista(conversa.ultima_mensagem_em);
        if (horario) topo.append(criarElemento("time", "chat-item-horario", horario));

        const profissional = texto(conversa.profissional_nome);
        const linhaAgenda = criarLinhaAgendamento("chat-item-agendamento", conversa, true);
        const linhaProfissional = profissional ? criarElemento("div", "chat-item-profissional", profissional) : null;
        if (linhaProfissional) linhaProfissional.title = profissional;

        const rodape = criarElemento("div", "chat-item-rodape");
        rodape.append(criarElemento("span", "chat-item-previa", textoPrevia(conversa)));
        if (conversa.status === "encerrada") {
          rodape.append(criarElemento("span", "chat-status encerrada", rotuloStatus(conversa.status)));
        }
        if (naoLidas > 0) {
          const badge = criarElemento("span", "chat-badge-nao-lidas", naoLidas > 99 ? "99+" : String(naoLidas));
          badge.setAttribute("aria-hidden", "true");
          rodape.append(badge);
        }
        corpo.append(topo, ...[linhaAgenda, linhaProfissional].filter(Boolean), rodape);
        botao.append(criarAvatar(nomeCliente, conversa.cliente_foto_url, "chat-avatar-lista"), corpo);
        const descricaoNaoLidas = naoLidas > 0
          ? `, ${naoLidas} mensage${naoLidas === 1 ? "m não lida" : "ns não lidas"}`
          : "";
        botao.setAttribute("aria-label", `${nomeCliente}${descricaoNaoLidas}. ${textoPrevia(conversa)}`);
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
      const linha = criarElemento("div", `chat-mensagem-linha${propria ? " propria" : ""}`);
      const artigo = criarElemento("article", "chat-mensagem");
      artigo.classList.toggle("propria", propria);
      const nomeRemetente = texto(mensagem.remetente_nome) || "Remetente";
      artigo.append(
        criarElemento("span", "chat-mensagem-remetente", propria ? "Você" : nomeRemetente),
        criarElemento("p", "chat-mensagem-conteudo", texto(mensagem.conteudo)),
        criarElemento("time", "chat-mensagem-horario", formatarHorario(mensagem.criado_em))
      );
      if (propria) linha.append(artigo);
      else linha.append(criarAvatar(nomeRemetente, mensagem.remetente_foto_url, "chat-avatar-mensagem"), artigo);
      ui.historico.append(linha);
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
    ui.clienteNome.title = ui.clienteNome.textContent;
    if (ui.avatarConversa) preencherAvatar(ui.avatarConversa, texto(conversa.cliente_nome) || "Cliente", conversa.cliente_foto_url);
    const linhaAgenda = criarLinhaAgendamento("chat-agendamento-cabecalho", conversa, false);
    const profissionalCabecalho = texto(conversa.profissional_nome);
    ui.meta.replaceChildren();
    if (linhaAgenda) ui.meta.append(linhaAgenda);
    if (profissionalCabecalho) {
      ui.meta.append(criarElemento("span", "chat-agendamento-profissional", profissionalCabecalho));
    }
    ui.meta.title = [linhaAgenda?.title, profissionalCabecalho].filter(Boolean).join(" • ");
    ui.status.textContent = rotuloStatus(conversa.status);
    ui.status.className = `chat-status ${conversa.status === "encerrada" ? "encerrada" : "aberta"}`;
    ui.encerrar.hidden = !podeEncerrar(conversa);
    // Conversa sem agendamento (excluído: id_agendamento NULL) não oferece o atalho.
    if (ui.abrirAgendamento) ui.abrirAgendamento.hidden = !idAgendamentoDaConversa(conversa);
    ui.carregarAnteriores.hidden = !estado.temMaisMensagens;
    const distanciaDoFim = ui.historico.scrollHeight - ui.historico.scrollTop - ui.historico.clientHeight;
    const posicaoAnterior = ui.historico.scrollTop;
    renderizarHistorico();
    sincronizarCompositor();
    renderizarLista();
    if (rolarAoFim === "abertura") {
      requestAnimationFrame(() => { rolarParaNovasOuFim(); });
    } else if (rolarAoFim === true) {
      requestAnimationFrame(() => { ui.historico.scrollTop = ui.historico.scrollHeight; });
    } else if (rolarAoFim === "seguir") {
      // Polling: acompanha o fim somente se o usuário já estava perto dele.
      requestAnimationFrame(() => {
        ui.historico.scrollTop = distanciaDoFim < 80 ? ui.historico.scrollHeight : posicaoAnterior;
      });
    }
  }

  // Ao abrir: posiciona no separador "Novas mensagens" quando existir; senão, no fim.
  function rolarParaNovasOuFim() {
    const separador = ui.historico.querySelector(".chat-separador-novas");
    if (separador) {
      ui.historico.scrollTop = Math.max(0, separador.offsetTop - ui.historico.offsetTop - 12);
      return;
    }
    ui.historico.scrollTop = ui.historico.scrollHeight;
  }

  // O contador da página vem do módulo global (chat-nao-lidas.js), sem timer próprio.
  async function atualizarContadorNaoLidas() {
    if (window.ChatNaoLidas?.atualizarAgora) {
      window.ChatNaoLidas.atualizarAgora();
      return;
    }
    try {
      const resposta = await apiChat("chat/conversas/nao-lidas");
      renderizarContador(resposta.data?.conversas_nao_lidas);
    } catch (_) {
      renderizarContador(0);
    }
  }

  /* Estado global de não lidas: atualiza contador, quantidades por conversa e, quando o
     conjunto mudou (nova mensagem em outra conversa), recarrega a primeira página da lista. */
  function aoAtualizarNaoLidas(evento) {
    const detalhe = evento.detail || {};
    if (detalhe.contexto !== "interno") return;
    renderizarContador(detalhe.total);
    const conversas = Array.isArray(detalhe.conversas) ? detalhe.conversas : [];
    const assinatura = conversas.map(item => `${item.id_conversa}:${item.quantidade_nao_lidas}`).join("|");
    if (assinatura === estado.assinaturaNaoLidas) return;
    estado.assinaturaNaoLidas = assinatura;
    const porConversa = new Map(conversas.map(item => [Number(item.id_conversa), Number(item.quantidade_nao_lidas) || 0]));
    const listaDesatualizada = conversas.some(item => !estado.conversas.some(conversa => Number(conversa.id_conversa) === Number(item.id_conversa)
      && quantidadeNaoLidas(conversa) === Number(item.quantidade_nao_lidas)));
    if (listaDesatualizada && estado.pagina <= 1 && !ui.busca.value.trim()) {
      carregarConversas({ silencioso: true });
      return;
    }
    estado.conversas = estado.conversas.map(conversa => {
      const quantidade = porConversa.get(Number(conversa.id_conversa)) || 0;
      return { ...conversa, quantidade_nao_lidas: quantidade, nao_lida: quantidade > 0 };
    });
    renderizarLista();
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

  async function carregarConversas({ mais = false, silencioso = false } = {}) {
    const versao = ++estado.versaoLista;
    const pagina = mais ? estado.pagina + 1 : 1;
    if (!mais && !silencioso) definirMensagemLista("Carregando conversas...");
    ui.carregarMais.disabled = true;
    try {
      const resposta = await apiChat("chat/conversas/listar", { parametros: parametrosDaLista(pagina) });
      if (versao !== estado.versaoLista) return;
      const itens = Array.isArray(resposta.data?.itens) ? resposta.data.itens : [];
      estado.conversas = mais ? [...estado.conversas, ...itens] : itens;
      // A conversa aberta já foi lida na tela: não reexibe badge enquanto ela está visível.
      if (estado.conversa && conversaVisivel()) {
        estado.conversas = estado.conversas.map(item => Number(item.id_conversa) === Number(estado.conversa.id_conversa)
          ? { ...item, quantidade_nao_lidas: 0, nao_lida: false }
          : item);
      }
      estado.pagina = Number(resposta.data?.pagina || pagina);
      estado.temMais = resposta.data?.tem_mais === true;
      registrarProfissionais(itens);
      atualizarOpcoesProfissional();
      definirMensagemLista(estado.conversas.length ? "" : "Nenhuma conversa encontrada.");
      renderizarLista();
    } catch (erro) {
      if (versao !== estado.versaoLista) return;
      if (silencioso) return; // Atualização automática: mantém a lista atual em caso de falha.
      estado.temMais = false;
      definirMensagemLista(erroAmigavel(erro, "Não foi possível carregar as conversas."), true);
      renderizarLista();
    } finally {
      if (versao === estado.versaoLista) ui.carregarMais.disabled = false;
    }
  }

  /* Leitura só com a conversa realmente exibida: aba visível e área de detalhe na tela
     (no mobile, o detalhe aberto). Caso contrário a leitura fica pendente. */
  function conversaVisivel() {
    return document.visibilityState === "visible"
      && Boolean(estado.conversa)
      && Boolean(ui.conteudo)
      && !ui.conteudo.hidden
      && ui.conteudo.getClientRects().length > 0;
  }

  async function marcarLeituraDaConversa() {
    const ultima = estado.mensagens[estado.mensagens.length - 1];
    if (!estado.conversa || !ultima?.id_mensagem) return;
    if (!conversaVisivel()) {
      estado.leituraPendente = true;
      return;
    }
    estado.leituraPendente = false;
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
      estado.conversa.quantidade_nao_lidas = 0;
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
      // Marcador ANTES de avançar a leitura: define onde fica "Novas mensagens".
      const marcador = Number(estado.conversa?.id_ultima_mensagem_lida || 0);
      const primeiraNova = estado.mensagens.find(mensagem => Number(mensagem.id_mensagem) > marcador && mensagem.propria !== true && mensagem.remetente_tipo !== "sistema");
      estado.idPrimeiraNova = primeiraNova ? Number(primeiraNova.id_mensagem) : null;
      substituirConversaNaLista(estado.conversa);
      definirAviso("");
      renderizarConversa({ rolarAoFim: "abertura" });
      marcarLeituraDaConversa();
      iniciarPollingConversa();
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
      estado.conversa.remetente_ultima_mensagem = {
        tipo: mensagem.remetente_tipo,
        nome: mensagem.remetente_nome,
        propria: mensagem.propria === true,
      };
      estado.conversa.nao_lida = false;
      estado.conversa.quantidade_nao_lidas = 0;
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
    const pergunta = "Após encerrar, não será possível enviar novas mensagens nesta conversa. O histórico continuará disponível para consulta.\n\nDeseja continuar?";
    // Confirmação destrutiva no componente padrão do sistema (variante de perigo).
    const confirmado = typeof window.MensagemSistema?.confirmar === "function"
      ? await window.MensagemSistema.confirmar(pergunta, { titulo: "Encerrar conversa?", textoConfirmar: "Encerrar conversa", textoCancelar: "Cancelar", perigoso: true })
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

  /* Polling incremental da conversa aberta (after_id). Um único timer encadeado;
     só consulta com a conversa visível e nunca recarrega o histórico inteiro. */
  function pararPollingConversa() {
    window.clearTimeout(estado.timerConversa);
    estado.timerConversa = 0;
  }

  function iniciarPollingConversa() {
    pararPollingConversa();
    if (!estado.conversa) return;
    estado.timerConversa = window.setTimeout(sincronizarConversaAberta, INTERVALO_CONVERSA_MS);
  }

  async function sincronizarConversaAberta() {
    estado.timerConversa = 0;
    const conversa = estado.conversa;
    if (!conversa) return;
    if (!conversaVisivel() || estado.sincronizandoConversa) {
      iniciarPollingConversa();
      return;
    }
    const versao = estado.versaoConversa;
    const ultima = estado.mensagens[estado.mensagens.length - 1];
    estado.sincronizandoConversa = true;
    try {
      const resposta = await apiChat("chat/mensagens/listar", {
        parametros: {
          id_conversa: conversa.id_conversa,
          after_id: ultima?.id_mensagem || 0,
          limite: LIMITE_MENSAGENS,
        },
      });
      if (versao !== estado.versaoConversa || estado.conversa !== conversa) return;
      const novas = Array.isArray(resposta.data?.mensagens) ? resposta.data.mensagens : [];
      const statusMudou = resposta.data?.status && resposta.data.status !== conversa.status;
      if (statusMudou) {
        conversa.status = resposta.data.status;
        if (conversa.status !== "aberta") {
          conversa.pode_responder = false;
          conversa.pode_encerrar = false;
        }
      }
      if (novas.length) {
        const ids = new Set(estado.mensagens.map(mensagem => Number(mensagem.id_mensagem)));
        novas.filter(mensagem => !ids.has(Number(mensagem.id_mensagem))).forEach(mensagem => estado.mensagens.push(mensagem));
        estado.mensagens.sort((a, b) => Number(a.id_mensagem) - Number(b.id_mensagem));
        const ultimaNova = estado.mensagens[estado.mensagens.length - 1];
        conversa.id_ultima_mensagem = ultimaNova.id_mensagem;
        conversa.ultima_mensagem_em = ultimaNova.criado_em || conversa.ultima_mensagem_em;
        conversa.ultima_mensagem_previa = ultimaNova.conteudo || "";
        conversa.remetente_ultima_mensagem = { tipo: ultimaNova.remetente_tipo, nome: ultimaNova.remetente_nome, propria: ultimaNova.propria === true };
        substituirConversaNaLista(conversa);
      }
      if (novas.length || statusMudou) {
        renderizarConversa({ rolarAoFim: "seguir" });
        if (novas.length) marcarLeituraDaConversa();
      }
    } catch (_) {
      // Falha transitória: tenta novamente no próximo ciclo, sem alterar a tela.
    } finally {
      estado.sincronizandoConversa = false;
      if (estado.conversa === conversa) iniciarPollingConversa();
    }
  }

  function aoMudarVisibilidade() {
    if (document.visibilityState !== "visible" || !estado.conversa) return;
    if (estado.leituraPendente) marcarLeituraDaConversa();
    window.clearTimeout(estado.timerConversa);
    sincronizarConversaAberta();
  }

  function voltarParaLista() {
    pararPollingConversa();
    estado.idPrimeiraNova = null;
    estado.leituraPendente = false;
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
    ui.abrirAgendamento?.addEventListener("click", abrirAgendamentoDaConversa);
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
    ui.abrirAgendamento = document.getElementById("chatAbrirAgendamento");
    ui.historico = document.getElementById("chatHistorico");
    ui.carregarAnteriores = document.getElementById("chatCarregarAnteriores");
    ui.formulario = document.getElementById("chatFormularioMensagem");
    ui.textarea = document.getElementById("chatConteudoMensagem");
    ui.enviar = document.getElementById("chatEnviar");
    ui.aviso = document.getElementById("chatAviso");
    ui.voltar = document.getElementById("chatVoltar");
    ui.avatarConversa = document.getElementById("chatConversaAvatar");
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
    document.addEventListener("amagenda:chat-nao-lidas", aoAtualizarNaoLidas);
    document.addEventListener("visibilitychange", aoMudarVisibilidade);
    const estadoGlobal = window.ChatNaoLidas?.obter?.();
    if (estadoGlobal?.contexto === "interno") renderizarContador(estadoGlobal.total);
    carregarConversas().finally(() => {
      if (idConversaUrl) abrirConversaDaUrl(idConversaUrl);
    });
    atualizarContadorNaoLidas();
    // Usado pela central de notificações quando a página do Chat já está aberta.
    window.ChatInterno = Object.freeze({
      abrirConversa: id => {
        const numero = Number(id);
        if (!Number.isInteger(numero) || numero <= 0) return;
        const url = new URL(window.location.href);
        url.searchParams.set("id_conversa", String(numero));
        window.history.replaceState(window.history.state, "", `${url.pathname}${url.search}${url.hash}`);
        abrirConversaDaUrl(String(numero));
      },
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.addEventListener("amagenda:sessao-carregada", inicializar);
    inicializar();
  });
})();
