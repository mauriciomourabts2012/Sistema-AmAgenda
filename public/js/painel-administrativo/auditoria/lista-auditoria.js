(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C) return;

  const AUDITORIA_GLOBAL = document.body.dataset.menuContexto === "super-admin";
  const ENDPOINT = AUDITORIA_GLOBAL
    ? "/api/api_central.php?path=superadmin/auditoria/listar"
    : "/api/api_central.php?path=painel/auditoria/listar";
  const PERIODO_PADRAO_DIAS = 7;
  const EVENTOS = {
    agenda: ["agendamento.criado", "agendamento.editado", "agendamento.confirmado", "agendamento.cancelado", "agendamento.concluido", "agendamento.excluido"],
    clientes: ["cliente.criado", "cliente.editado", "cliente.status_alterado"],
    usuarios: ["usuario.criado", "usuario.editado", "usuario.status_alterado", "usuario.senha_redefinida", "super_admin.criado", "super_admin.editado", "super_admin.status_alterado"],
    permissoes: ["usuario.permissoes_alteradas", "usuario.permissoes_restauradas"],
    servicos: ["servico.criado", "servico.excluido"],
    configuracoes: ["empresa.configuracoes_alteradas", "empresa.identidade_visual_alterada", "empresa.identidade_visual_restaurada", "agenda_profissional.configuracao_alterada", "agenda_profissional.configuracao_restaurada"],
    perfil: ["perfil.senha_alterada"],
    empresas: ["empresa.criada", "empresa.editada", "empresa.status_alterado"],
    planos: ["plano.criado", "plano.editado", "plano.status_alterado"],
    autenticacao: ["autenticacao.credenciais_invalidas", "autenticacao.usuario_inativo", "autenticacao.empresa_inativa", "autenticacao.vinculo_inativo", "autenticacao.acesso_negado"]
  };
  const ROTULOS_MODULOS = { agenda: "Agenda", clientes: "Clientes", usuarios: "Usuários", permissoes: "Permissões", servicos: "Serviços", configuracoes: "Configurações", perfil: "Perfil", empresas: "Empresas", planos: "Planos", autenticacao: "Autenticação" };
  const ROTULOS_ORIGENS = { empresa: "Empresa", plataforma: "Plataforma", modo_suporte: "Modo suporte", autenticacao: "Autenticação" };
  const ROTULOS_CAMPOS = {
    nome: "Nome", telefone: "Telefone", email: "E-mail", perfil: "Perfil", status: "Status",
    status_vinculo: "Situação do vínculo", especialidade: "Especialidade", observacao: "Observação",
    cliente: "Cliente", profissional: "Profissional", servico: "Serviço", data_agendamento: "Data",
    hora_inicio: "Hora inicial", hora_fim: "Hora final", duracao_min_aplicada: "Duração",
    valor_aplicado: "Valor", permissoes: "Permissões", intervalo_padrao: "Intervalo padrão",
    observacao_padrao: "Observação padrão", inicio_semana: "Início da semana", horarios: "Horários",
    ddi_padrao: "DDI padrão", ddd_padrao: "DDD padrão", mensagem_whatsapp: "Mensagem do WhatsApp",
    nome_exibicao: "Nome de exibição", logo: "Logo", imagem_login: "Imagem de login",
    login_tentado: "Login tentado",
    senha_alterada: "Senha", repetir_semanalmente: "Repetição semanal", recorrencia_data_fim: "Fim da recorrência",
    cnpj: "CNPJ", plano: "Plano", preco_mensal: "Preço mensal", cobranca: "Cobrança",
    limite_usuarios: "Limite de usuários", limite_profissionais: "Limite de profissionais",
    limite_servicos: "Limite de serviços", limite_agendamentos: "Limite de agendamentos", destaque: "Destaque"
  };

  const aba = document.getElementById("auditoria");
  const form = document.getElementById("formFiltrosAuditoria");
  const lista = document.getElementById("listaAuditoria");
  const estado = document.getElementById("estadoAuditoria");
  const paginacao = document.getElementById("paginacaoAuditoria");
  const busca = document.getElementById("busca_auditoria");
  const btnLimparPesquisa = aba?.querySelector(".btn-limpar-pesquisa");
  const btnFiltro = document.getElementById("btnPeriodo_auditoria");
  const labelFiltro = document.getElementById("labelPeriodo_auditoria");
  const popover = document.getElementById("popoverPeriodo_auditoria");
  const inicio = document.getElementById("inicio_auditoria");
  const fim = document.getElementById("fim_auditoria");
  const modulo = document.getElementById("modulo_auditoria");
  const evento = document.getElementById("evento_auditoria");
  const ordem = document.getElementById("ordem_auditoria");
  const limite = document.getElementById("limite_auditoria");
  const empresa = document.getElementById("empresa_auditoria");
  const ator = document.getElementById("ator_auditoria");
  const origem = document.getElementById("origem_auditoria");
  const btnLimparFiltro = document.getElementById("limparFiltrosAuditoria");
  const btnFecharFiltro = document.getElementById("fecharPopover_auditoria");
  if (!aba || !form || !lista || !estado || !paginacao || !busca || !btnFiltro || !labelFiltro || !popover || !inicio || !fim || !modulo || !evento || !ordem || !limite || !btnLimparFiltro || !btnFecharFiltro) return;

  // Histórico de cursores mantido apenas em memória durante a navegação atual.
  let cursoresPaginas = [null];
  let indicePagina = 0;
  let proximoCursor = null;
  let temMais = false;
  let filtrosAplicados = null;
  let requisicao = null;
  let sequenciaRequisicao = 0;
  const controlesAcao = [...form.querySelectorAll("button")];
  const POP_MARGIN = 12;
  const MQ_MOBILE = window.matchMedia("(max-width: 680px)");
  const Z_FRONT = 10050;
  const POPOVER_ORIG = { parent: popover.parentNode, next: popover.nextSibling };

  function texto(valor) {
    if (valor === null || valor === undefined || valor === "") return "—";
    if (typeof valor === "boolean") return valor ? "Sim" : "Não";
    if (Array.isArray(valor)) return valor.map(texto).join(", ") || "—";
    if (typeof valor === "object") return Object.entries(valor).map(([chave, item]) => `${rotuloCampo(chave)}: ${texto(item)}`).join("; ");
    return String(valor);
  }

  function rotuloCampo(campo) {
    return ROTULOS_CAMPOS[campo] || String(campo).replaceAll("_", " ").replace(/^./, letra => letra.toUpperCase());
  }

  function rotuloEvento(codigo) {
    return String(codigo || "Evento").replaceAll("_", " ").replaceAll(".", " · ").replace(/^./, letra => letra.toUpperCase());
  }

  function dataBrasil(valor) {
    if (!valor) return "Data indisponível";
    // O banco devolve o horário local da empresa sem indicador de fuso.
    const dataIsoLocal = String(valor).replace(" ", "T").replace(/(\.\d{3})\d+$/, "$1");
    const data = new Date(dataIsoLocal);
    if (Number.isNaN(data.getTime())) return String(valor);
    return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "medium" }).format(data);
  }

  function elemento(tag, classe, conteudo) {
    const no = document.createElement(tag);
    if (classe) no.className = classe;
    if (conteudo !== undefined) no.textContent = conteudo;
    return no;
  }

  function renderizarAlteracoes(alteracoes) {
    if (!alteracoes || typeof alteracoes !== "object" || !Object.keys(alteracoes).length) return null;
    const caixa = elemento("div", "auditoria-alteracoes");
    Object.entries(alteracoes).forEach(([campo, mudanca]) => {
      const linha = elemento("div", "auditoria-alteracao");
      const eMudanca = mudanca && typeof mudanca === "object" && !Array.isArray(mudanca) && ("antes" in mudanca || "depois" in mudanca);
      linha.append(elemento("span", "auditoria-alteracao-campo", rotuloCampo(campo)));
      if (eMudanca) {
        linha.append(elemento("span", "auditoria-alteracao-antes", texto(mudanca.antes)));
        linha.append(elemento("span", "auditoria-alteracao-seta", "→"));
        linha.append(elemento("span", "auditoria-alteracao-depois", texto(mudanca.depois)));
      } else {
        const valor = elemento("span", "auditoria-alteracao-depois", texto(mudanca));
        valor.style.gridColumn = "span 3";
        linha.append(valor);
      }
      caixa.append(linha);
    });
    return caixa;
  }

  function renderizarItem(item) {
    const artigo = elemento("article", "auditoria-item");
    const topo = elemento("div", "auditoria-item-topo");
    const identidade = elemento("div", "auditoria-item-identidade");
    identidade.append(elemento("strong", "", item?.ator?.nome || "Sistema"));
    identidade.append(elemento("small", "", item?.ator?.perfil ? `Perfil: ${texto(item.ator.perfil)}` : texto(item?.ator?.tipo)));
    topo.append(identidade, elemento("time", "auditoria-item-data", dataBrasil(item?.ocorrido_em)));
    artigo.append(topo);

    const etiquetas = elemento("div", "auditoria-etiquetas");
    if (item?.empresa?.nome) etiquetas.append(elemento("span", "auditoria-etiqueta", `Empresa: ${item.empresa.nome}`));
    if (item?.origem) etiquetas.append(elemento("span", "auditoria-etiqueta", ROTULOS_ORIGENS[item.origem] || texto(item.origem)));
    etiquetas.append(elemento("span", "auditoria-etiqueta", ROTULOS_MODULOS[item?.evento?.modulo] || texto(item?.evento?.modulo)));
    etiquetas.append(elemento("span", "auditoria-etiqueta", rotuloEvento(item?.evento?.codigo)));
    if (item?.ator?.modo_suporte) etiquetas.append(elemento("span", "auditoria-etiqueta auditoria-etiqueta--suporte", "Suporte Super Admin"));
    artigo.append(etiquetas);
    artigo.append(elemento("p", "auditoria-descricao", item?.descricao || "Atividade registrada."));

    if (item?.entidade?.tipo || item?.entidade?.rotulo) {
      const entidadeTexto = item?.entidade?.rotulo || `${rotuloCampo(item.entidade.tipo)}${item.entidade.id ? ` #${item.entidade.id}` : ""}`;
      artigo.append(elemento("div", "auditoria-entidade", `Entidade: ${entidadeTexto}`));
    }
    const loginTentado = item?.alteracoes?.login_tentado?.depois;
    if (AUDITORIA_GLOBAL && loginTentado) artigo.append(elemento("div", "auditoria-entidade", `Login tentado: ${texto(loginTentado)}`));
    const alteracoesVisiveis = item?.alteracoes && typeof item.alteracoes === "object" ? { ...item.alteracoes } : item?.alteracoes;
    if (alteracoesVisiveis && typeof alteracoesVisiveis === "object") delete alteracoesVisiveis.login_tentado;
    const alteracoes = renderizarAlteracoes(alteracoesVisiveis);
    if (alteracoes) artigo.append(alteracoes);
    return artigo;
  }

  function mostrarEstado(mensagem, erro = false) {
    estado.textContent = mensagem;
    estado.classList.toggle("auditoria-estado--erro", erro);
  }

  function datasPadrao() {
    const fim = new Date();
    const inicio = new Date();
    inicio.setDate(inicio.getDate() - (PERIODO_PADRAO_DIAS - 1));
    const local = data => new Date(data.getTime() - data.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    document.getElementById("inicio_auditoria").value = local(inicio);
    document.getElementById("fim_auditoria").value = local(fim);
  }

  function preencherEventos() {
    const selecionado = evento.value;
    evento.replaceChildren(new Option("Todos", ""));
    const modulos = modulo.value ? [modulo.value] : Object.keys(EVENTOS);
    modulos.flatMap(chave => EVENTOS[chave] || []).forEach(codigo => evento.add(new Option(rotuloEvento(codigo), codigo)));
    if ([...evento.options].some(opcao => opcao.value === selecionado)) evento.value = selecionado;
  }

  function rotuloData(dataIso) {
    return String(dataIso || "").split("-").reverse().join("/");
  }

  function atualizarLabelFiltro() {
    const partes = [];
    const inicioAplicado = filtrosAplicados?.get("inicio") || "";
    const fimAplicado = filtrosAplicados?.get("fim") || "";
    if (inicioAplicado && fimAplicado) partes.push(`${rotuloData(inicioAplicado)} - ${rotuloData(fimAplicado)}`);
    if (filtrosAplicados?.get("modulo")) partes.push(ROTULOS_MODULOS[filtrosAplicados.get("modulo")] || filtrosAplicados.get("modulo"));
    if (filtrosAplicados?.get("evento")) partes.push(rotuloEvento(filtrosAplicados.get("evento")));
    if (filtrosAplicados?.get("origem")) partes.push(ROTULOS_ORIGENS[filtrosAplicados.get("origem")] || filtrosAplicados.get("origem"));
    labelFiltro.textContent = partes.length ? partes.join(" • ") : "Filtro";
  }

  function isMobile() {
    return MQ_MOBILE.matches;
  }

  function moverPopoverParaBodyNoMobile() {
    if (!isMobile() || popover.parentNode === document.body) return;
    document.body.appendChild(popover);
  }

  function restaurarPopover() {
    if (popover.parentNode !== document.body || !POPOVER_ORIG.parent) return;
    if (POPOVER_ORIG.next && POPOVER_ORIG.next.parentNode === POPOVER_ORIG.parent) {
      POPOVER_ORIG.parent.insertBefore(popover, POPOVER_ORIG.next);
    } else {
      POPOVER_ORIG.parent.appendChild(popover);
    }
  }

  function limparPosicaoPopover() {
    ["position", "zIndex", "left", "top", "right", "bottom", "width", "maxWidth", "transform"].forEach(propriedade => {
      popover.style[propriedade] = "";
    });
  }

  function posicionarPopover() {
    if (popover.hasAttribute("hidden")) return;
    if (isMobile()) {
      moverPopoverParaBodyNoMobile();
      popover.style.zIndex = String(Z_FRONT);
      Object.assign(popover.style, { position: "fixed", left: "12px", right: "12px", bottom: "12px", top: "auto", width: "auto", maxWidth: "none" });
      return;
    }

    restaurarPopover();
    limparPosicaoPopover();
    popover.style.zIndex = String(Z_FRONT);
    const botao = btnFiltro.getBoundingClientRect();
    Object.assign(popover.style, { position: "fixed", left: "-9999px", top: "-9999px", right: "", bottom: "" });
    const painel = popover.getBoundingClientRect();
    const esquerda = Math.max(POP_MARGIN, Math.min(botao.right - painel.width, window.innerWidth - painel.width - POP_MARGIN));
    const topo = Math.max(POP_MARGIN, Math.min(botao.bottom + 8, window.innerHeight - painel.height - POP_MARGIN));
    popover.style.left = `${Math.round(esquerda)}px`;
    popover.style.top = `${Math.round(topo)}px`;
  }

  function fecharPopover() {
    popover.setAttribute("hidden", "");
    btnFiltro.setAttribute("aria-expanded", "false");
    restaurarPopover();
    limparPosicaoPopover();
  }

  function abrirPopover() {
    popover.removeAttribute("hidden");
    btnFiltro.setAttribute("aria-expanded", "true");
    if (isMobile()) moverPopoverParaBodyNoMobile();
    requestAnimationFrame(() => {
      posicionarPopover();
      setTimeout(() => inicio.focus(), 0);
    });
  }

  function capturarFiltros() {
    const params = new URLSearchParams();
    const campos = { inicio: "inicio_auditoria", fim: "fim_auditoria", empresa_id: "empresa_auditoria", ator: "ator_auditoria", modulo: "modulo_auditoria", evento: "evento_auditoria", origem: "origem_auditoria", ordem: "ordem_auditoria", limite: "limite_auditoria", q: "busca_auditoria" };
    Object.entries(campos).forEach(([nome, id]) => {
      const valor = document.getElementById(id)?.value?.trim();
      if (valor) params.set(nome, valor);
    });
    return params;
  }

  function parametros(cursorPagina) {
    const params = new URLSearchParams(filtrosAplicados || capturarFiltros());
    if (cursorPagina) params.set("cursor", cursorPagina);
    return params;
  }

  function renderizarPaginacao() {
    paginacao.replaceChildren();
    if (!lista.childElementCount) return;

    const anterior = elemento("button", "btn-pag", "◀ Anterior");
    anterior.type = "button";
    anterior.disabled = indicePagina === 0;
    // Anterior: reutiliza o cursor já guardado para a página visitada.
    anterior.addEventListener("click", () => consultar(indicePagina - 1));

    const proximo = elemento("button", "btn-pag", "Próximo ▶");
    proximo.type = "button";
    proximo.disabled = !temMais || !proximoCursor;
    proximo.addEventListener("click", () => {
      // Próximo: guarda o cursor devolvido pela página atual antes de avançar.
      cursoresPaginas[indicePagina + 1] = proximoCursor;
      cursoresPaginas.length = indicePagina + 2;
      consultar(indicePagina + 1);
    });
    paginacao.append(anterior, proximo);
  }

  function resetarPaginacao({ atualizarFiltros = false } = {}) {
    // Filtros novos invalidam os cursores visitados e sempre retornam à página inicial.
    cursoresPaginas = [null];
    indicePagina = 0;
    proximoCursor = null;
    temMais = false;
    if (atualizarFiltros) filtrosAplicados = capturarFiltros();
    renderizarPaginacao();
  }

  async function consultar(paginaAlvo = 0) {
    if (requisicao) requisicao.abort();
    const controlador = new AbortController();
    const sequenciaAtual = ++sequenciaRequisicao;
    requisicao = controlador;
    const cursorPagina = cursoresPaginas[paginaAlvo] || null;
    paginacao.replaceChildren();
    mostrarEstado("Carregando atividades…");
    controlesAcao.forEach(controle => controle.disabled = true);
    form.setAttribute("aria-busy", "true");

    try {
      const resposta = await fetch(`${ENDPOINT}&${parametros(cursorPagina)}`, { credentials: "same-origin", signal: controlador.signal });
      const json = await resposta.json().catch(() => null);
      if (sequenciaAtual !== sequenciaRequisicao) return;
      if (!resposta.ok || !json?.ok) throw new Error(json?.user_msg || "Não foi possível consultar a auditoria.");
      const itens = Array.isArray(json?.data?.items) ? json.data.items : [];
      const fragmento = document.createDocumentFragment();
      itens.forEach(item => fragmento.append(renderizarItem(item)));
      // Cada navegação substitui a timeline; páginas nunca são concatenadas.
      lista.replaceChildren(fragmento);
      indicePagina = paginaAlvo;
      proximoCursor = json?.meta?.proximo_cursor || null;
      temMais = !!json?.meta?.tem_mais;
      renderizarPaginacao();
      mostrarEstado(lista.childElementCount ? "" : "Nenhuma atividade encontrada para os filtros selecionados.");
    } catch (erro) {
      if (erro?.name === "AbortError" || sequenciaAtual !== sequenciaRequisicao) return;
      lista.replaceChildren();
      mostrarEstado(erro?.message || "Não foi possível consultar a auditoria.", true);
      renderizarPaginacao();
    } finally {
      if (sequenciaAtual === sequenciaRequisicao) {
        requisicao = null;
        controlesAcao.forEach(controle => controle.disabled = false);
        form.removeAttribute("aria-busy");
      }
    }
  }

  function atualizarBotaoLimparPesquisa() {
    if (btnLimparPesquisa) btnLimparPesquisa.style.display = busca.value.trim() ? "inline-flex" : "none";
  }

  function aplicarPesquisa() {
    const filtrosComPesquisa = new URLSearchParams(filtrosAplicados);
    const termo = busca.value.trim();
    if (termo) filtrosComPesquisa.set("q", termo);
    else filtrosComPesquisa.delete("q");
    filtrosAplicados = filtrosComPesquisa;
    atualizarBotaoLimparPesquisa();
    resetarPaginacao();
    consultar(0);
  }

  const pesquisarComDebounce = C.debounce(aplicarPesquisa, 350);

  datasPadrao();
  preencherEventos();
  filtrosAplicados = capturarFiltros();
  atualizarLabelFiltro();
  atualizarBotaoLimparPesquisa();
  modulo.addEventListener("change", preencherEventos);
  busca.addEventListener("input", pesquisarComDebounce);
  btnLimparPesquisa?.addEventListener("click", () => {
    busca.value = "";
    busca.focus();
    aplicarPesquisa();
  });
  form.addEventListener("submit", eventoForm => {
    eventoForm.preventDefault();
    eventoForm.stopPropagation();
    fim.setCustomValidity("");
    if (inicio.value && fim.value && inicio.value > fim.value) {
      fim.setCustomValidity("A data final deve ser igual ou posterior à data inicial.");
      fim.reportValidity();
      return;
    }
    resetarPaginacao({ atualizarFiltros: true });
    atualizarLabelFiltro();
    fecharPopover();
    consultar(0);
  });
  btnLimparFiltro.addEventListener("click", eventoClique => {
    eventoClique.stopPropagation();
    form.reset();
    busca.value = "";
    datasPadrao();
    preencherEventos();
    atualizarBotaoLimparPesquisa();
    resetarPaginacao({ atualizarFiltros: true });
    atualizarLabelFiltro();
    fecharPopover();
    consultar(0);
  });
  btnFecharFiltro.addEventListener("click", eventoClique => {
    eventoClique.stopPropagation();
    fecharPopover();
  });
  btnFiltro.addEventListener("click", eventoClique => {
    eventoClique.stopPropagation();
    if (popover.hasAttribute("hidden")) abrirPopover();
    else fecharPopover();
  });
  document.addEventListener("click", eventoClique => {
    if (popover.hasAttribute("hidden")) return;
    const cliqueDentro = eventoClique.target.closest("#popoverPeriodo_auditoria") || eventoClique.target.closest("#btnPeriodo_auditoria");
    if (!cliqueDentro) fecharPopover();
  });
  document.addEventListener("keydown", eventoTeclado => {
    if (eventoTeclado.key === "Escape") fecharPopover();
  });
  window.addEventListener("scroll", posicionarPopover, true);
  window.addEventListener("resize", posicionarPopover);
  MQ_MOBILE.addEventListener?.("change", posicionarPopover);
  document.addEventListener("amagenda:menu-aba-alterada", eventoAba => {
    if (eventoAba.detail?.aba === "auditoria") {
      resetarPaginacao();
      consultar(0);
    }
  });
  if (aba.classList.contains("ativa")) consultar(0);
})();
