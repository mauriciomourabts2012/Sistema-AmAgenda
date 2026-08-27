(() => {
  "use strict";

  const ENDPOINT = "/api/api_central.php?path=painel/auditoria/listar";
  const EVENTOS = {
    agenda: ["agendamento.criado", "agendamento.editado", "agendamento.confirmado", "agendamento.cancelado", "agendamento.concluido", "agendamento.excluido"],
    clientes: ["cliente.criado", "cliente.editado", "cliente.status_alterado"],
    usuarios: ["usuario.criado", "usuario.editado", "usuario.status_alterado", "usuario.senha_redefinida"],
    permissoes: ["usuario.permissoes_alteradas", "usuario.permissoes_restauradas"],
    servicos: ["servico.criado", "servico.excluido"],
    configuracoes: ["empresa.configuracoes_alteradas", "empresa.identidade_visual_alterada", "empresa.identidade_visual_restaurada", "agenda_profissional.configuracao_alterada", "agenda_profissional.configuracao_restaurada"],
    perfil: ["perfil.senha_alterada"]
  };
  const ROTULOS_MODULOS = { agenda: "Agenda", clientes: "Clientes", usuarios: "Usuários", permissoes: "Permissões", servicos: "Serviços", configuracoes: "Configurações", perfil: "Perfil" };
  const ROTULOS_CAMPOS = {
    nome: "Nome", telefone: "Telefone", email: "E-mail", perfil: "Perfil", status: "Status",
    status_vinculo: "Situação do vínculo", especialidade: "Especialidade", observacao: "Observação",
    cliente: "Cliente", profissional: "Profissional", servico: "Serviço", data_agendamento: "Data",
    hora_inicio: "Hora inicial", hora_fim: "Hora final", duracao_min_aplicada: "Duração",
    valor_aplicado: "Valor", permissoes: "Permissões", intervalo_padrao: "Intervalo padrão",
    observacao_padrao: "Observação padrão", inicio_semana: "Início da semana", horarios: "Horários",
    ddi_padrao: "DDI padrão", ddd_padrao: "DDD padrão", mensagem_whatsapp: "Mensagem do WhatsApp",
    nome_exibicao: "Nome de exibição", logo: "Logo", imagem_login: "Imagem de login",
    senha_alterada: "Senha", repetir_semanalmente: "Repetição semanal", recorrencia_data_fim: "Fim da recorrência"
  };

  const aba = document.getElementById("auditoria");
  const form = document.getElementById("formFiltrosAuditoria");
  const lista = document.getElementById("listaAuditoria");
  const estado = document.getElementById("estadoAuditoria");
  const paginacao = document.getElementById("paginacaoAuditoria");
  const busca = document.getElementById("busca_auditoria");
  const modulo = document.getElementById("modulo_auditoria");
  const evento = document.getElementById("evento_auditoria");
  if (!aba || !form || !lista || !estado || !paginacao || !busca || !modulo || !evento) return;

  // Histórico de cursores mantido apenas em memória durante a navegação atual.
  let cursoresPaginas = [null];
  let indicePagina = 0;
  let proximoCursor = null;
  let temMais = false;
  let filtrosAplicados = null;
  let requisicao = null;
  let sequenciaRequisicao = 0;
  let temporizadorPesquisa = null;
  const controlesAcao = [...form.querySelectorAll("button")];

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
    etiquetas.append(elemento("span", "auditoria-etiqueta", ROTULOS_MODULOS[item?.evento?.modulo] || texto(item?.evento?.modulo)));
    etiquetas.append(elemento("span", "auditoria-etiqueta", rotuloEvento(item?.evento?.codigo)));
    if (item?.ator?.modo_suporte) etiquetas.append(elemento("span", "auditoria-etiqueta auditoria-etiqueta--suporte", "Suporte Super Admin"));
    artigo.append(etiquetas);
    artigo.append(elemento("p", "auditoria-descricao", item?.descricao || "Atividade registrada."));

    if (item?.entidade?.tipo || item?.entidade?.rotulo) {
      const entidadeTexto = item?.entidade?.rotulo || `${rotuloCampo(item.entidade.tipo)}${item.entidade.id ? ` #${item.entidade.id}` : ""}`;
      artigo.append(elemento("div", "auditoria-entidade", `Entidade: ${entidadeTexto}`));
    }
    const alteracoes = renderizarAlteracoes(item?.alteracoes);
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
    inicio.setDate(inicio.getDate() - 29);
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

  function capturarFiltros() {
    const params = new URLSearchParams();
    const campos = { inicio: "inicio_auditoria", fim: "fim_auditoria", modulo: "modulo_auditoria", evento: "evento_auditoria", q: "busca_auditoria" };
    Object.entries(campos).forEach(([nome, id]) => {
      const valor = document.getElementById(id)?.value?.trim();
      if (valor) params.set(nome, valor);
    });
    params.set("limite", "25");
    return params;
  }

  function parametros(cursorPagina) {
    const params = new URLSearchParams(filtrosAplicados || capturarFiltros());
    if (cursorPagina) params.set("cursor", cursorPagina);
    return params;
  }

  function renderizarPaginacao() {
    paginacao.replaceChildren();

    if (indicePagina > 0) {
      const anterior = elemento("button", "btn-pag", "◀ Anterior");
      anterior.type = "button";
      // Anterior: reutiliza o cursor já guardado para a página visitada.
      anterior.addEventListener("click", () => consultar(indicePagina - 1));
      paginacao.append(anterior);
    }

    if (temMais && proximoCursor) {
      const proximo = elemento("button", "btn-pag", "Próximo ▶");
      proximo.type = "button";
      proximo.addEventListener("click", () => {
        // Próximo: guarda o cursor devolvido pela página atual antes de avançar.
        cursoresPaginas[indicePagina + 1] = proximoCursor;
        cursoresPaginas.length = indicePagina + 2;
        consultar(indicePagina + 1);
      });
      paginacao.append(proximo);
    }
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

  function cancelarPesquisaAgendada() {
    if (temporizadorPesquisa === null) return;
    clearTimeout(temporizadorPesquisa);
    temporizadorPesquisa = null;
  }

  function agendarPesquisa() {
    cancelarPesquisaAgendada();
    if (requisicao) requisicao.abort();
    resetarPaginacao();
    temporizadorPesquisa = setTimeout(() => {
      temporizadorPesquisa = null;
      resetarPaginacao({ atualizarFiltros: true });
      consultar(0);
    }, 350);
  }

  datasPadrao();
  preencherEventos();
  filtrosAplicados = capturarFiltros();
  modulo.addEventListener("change", preencherEventos);
  busca.addEventListener("input", agendarPesquisa);
  form.addEventListener("change", eventoForm => {
    if (eventoForm.target !== busca) resetarPaginacao();
  });
  form.addEventListener("submit", eventoForm => {
    eventoForm.preventDefault();
    cancelarPesquisaAgendada();
    resetarPaginacao({ atualizarFiltros: true });
    consultar(0);
  });
  document.getElementById("limparFiltrosAuditoria").addEventListener("click", () => {
    cancelarPesquisaAgendada();
    form.reset();
    datasPadrao();
    preencherEventos();
    resetarPaginacao({ atualizarFiltros: true });
    consultar(0);
  });
  document.addEventListener("amagenda:painel-aba-alterada", eventoAba => {
    if (eventoAba.detail?.aba === "auditoria") {
      cancelarPesquisaAgendada();
      resetarPaginacao({ atualizarFiltros: true });
      consultar(0);
    }
  });
  if (aba.classList.contains("ativa")) consultar(0);
})();
