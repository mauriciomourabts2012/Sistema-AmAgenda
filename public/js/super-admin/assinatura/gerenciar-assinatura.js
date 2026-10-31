(() => {
  "use strict";

  if (window.__GERENCIAR_ASSINATURAS_INIT__) return;

  const C = window.ListaCore;
  if (!C) {
    console.warn("[GerenciarAssinaturas] ListaCore não carregado.");
    return;
  }

  const API = "/public/api/api_central.php";
  const LISTAR = `${API}?path=superadmin/assinatura/listar`;
  const PREVIA_CANCELAMENTO = `${LISTAR}&previa_cancelamento=1`;
  const ALTERAR_STATUS = `${API}?path=superadmin/assinatura/status`;
  // Sinais de possível recorrência duplicada no Mercado Pago (vêm do servidor; a conferência é manual, no painel do provedor).
  const ALERTAS_RECORRENCIA = {
    PREAPPROVAL_EXTERNA_DUPLICADA: "Uma assinatura de tentativa anterior deste contrato apareceu no Mercado Pago. Confira e encerre a antiga no painel do Mercado Pago.",
    PREAPPROVAL_ANTERIOR_ENCONTRADA: "A nova criação foi bloqueada porque existe uma assinatura de tentativa anterior no Mercado Pago. Encerre a antiga antes.",
    MULTIPLAS_ASSINATURAS_EXTERNAS: "Mais de uma assinatura externa usa a mesma referência. Análise manual necessária."
  };
  const EMPRESAS = `${API}?path=superadmin/empresa/listar&status=todos&ordem=nome_asc&limit=100`;
  const menuCtrl = C.createFloatingMenuController({ rootSelector: "#assinaturas" });

  let idAssinaturaCancelamento = "";
  let idEmpresaCancelamento = "";
  let empresasDisponiveis = [];
  let assinaturasDisponiveis = [];
  let paginaAtual = 1;
  let requisicaoListagem = 0;
  const itensPorPagina = 20;

  const esc = valor => String(valor ?? "").replace(/[&<>'"]/g, caractere => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;"
  })[caractere]);
  const dataBr = valor => {
    const texto = String(valor || "");
    return /^\d{4}-\d{2}-\d{2}/.test(texto) ? texto.slice(0, 10).split("-").reverse().join("/") : texto || "—";
  };
  const moeda = valor => Number(valor || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const normalizarBusca = valor => String(valor || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
  const avisar = (tipo, mensagem) => {
    const funcao = window.MensagemSistema?.[tipo] || window.MensagemSistema?.info;
    funcao?.(mensagem);
  };

  function statusLabel(status) {
    return ({ ativa: "Ativa", suspensa: "Suspensa", cancelada: "Cancelada", encerrada: "Encerrada" })[status] || status || "Indefinida";
  }

  function statusClass(status) {
    if (status === "ativa") return "st-confirmado";
    if (status === "suspensa") return "st-pendente";
    if (status === "cancelada") return "st-cancelado";
    return "assinatura-status--encerrada";
  }

  function iconAcoes() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 7.25a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Z"/>
      </svg>
    `;
  }

  function buildMenuAcoes(item, status) {
    const podeCancelarRecorrencia = item.recorrencia?.pode_cancelar === true;
    // Cadastro de cartão com criação enviada e resultado incerto: só o Super Admin pode encerrá-lo, com evidência externa.
    const podeResolverCriacao = item.recorrencia?.situacao === "incerta";
    const ehAtiva = ['ativa', 'suspensa'].includes(status);
    // Sem contrato vigente e sem recorrência a cancelar, não há ações.
    if (!ehAtiva && !podeCancelarRecorrencia && !podeResolverCriacao) return "";

    const dados = `data-id-assinatura="${esc(item.id_assinatura)}" data-id-empresa="${esc(item.id_empresa)}"`;
    const pendente = item.recorrencia?.situacao === "cancelamento_pendente";
    // Só aparece com recorrência externa elegível; some quando a Preapproval está confirmadamente cancelada ou encerrada.
    const itemRecorrencia = podeCancelarRecorrencia ? `
        <button class="agenda-menu-item" type="button" role="menuitem" data-acao="cancelar_recorrencia" ${dados}
          title="${pendente ? "Cancelamento já solicitado: confirmar com o Mercado Pago" : "Cancelar só a cobrança automática no cartão"}">
          <i class="fa-regular fa-credit-card" aria-hidden="true"></i>
          Cancelar pagamento automático
        </button>` : "";
    const itemResolver = podeResolverCriacao ? `
        <button class="agenda-menu-item" type="button" role="menuitem" data-acao="resolver_criacao_cartao" ${dados}
          title="Verificar no Mercado Pago e, com evidência suficiente, liberar um novo cadastro de cartão">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          Resolver cadastro de cartão incerto
        </button>` : "";
    const vigente = ehAtiva && !item.data_fim;
    const acaoPrincipal = status === "ativa" ? "suspender" : "reativar";
    const iconePrincipal = status === "ativa" ? "fa-pause" : "fa-play";
    const textoPrincipal = status === "ativa" ? "Suspender" : "Reativar";

    return `
      <div class="agenda-menu assinatura-menu" role="menu">
        ${vigente ? `<button class="agenda-menu-item" type="button" role="menuitem"
          data-acao="${acaoPrincipal}" ${dados}>
          <i class="fa-solid ${iconePrincipal}" aria-hidden="true"></i>
          ${textoPrincipal}
        </button>` : ""}
        ${itemRecorrencia}${itemResolver}
        ${vigente ? `<div class="assinatura-menu-separador" role="separator"></div>
        <button class="agenda-menu-item danger" type="button" role="menuitem"
          data-acao="cancelar" ${dados}>
          <i class="fa-solid fa-ban" aria-hidden="true"></i>
          Cancelar assinatura
        </button>` : ""}
      </div>
    `;
  }

  function renderizar(items) {
    const lista = document.getElementById("listaAssinaturas");
    if (!lista) return;

    menuCtrl.fechar();

    if (!items.length) {
      lista.innerHTML = '<div class="a-empty">Nenhuma assinatura encontrada.</div>';
      return;
    }

    lista.innerHTML = items.map(item => {
      const status = String(item.status || "").toLowerCase().trim();
      const historico = ["cancelada", "encerrada"].includes(status);
      const menuAcoes = buildMenuAcoes(item, status);

      return `<article class="agenda-card assinatura-card${historico ? " assinatura-card--historico" : ""}">
        <div class="assinatura-card-cabecalho">
          <div class="agenda-nome">${esc(item.empresa_nome)}</div>
          <div class="assinatura-status-acoes">
            <span class="agenda-status ${statusClass(status)}">${esc(item.data_fim && ['ativa', 'suspensa'].includes(status) ? `Cancelamento programado: ${dataBr(item.data_fim)}` : statusLabel(status))}</span>
            ${menuAcoes ? `<div class="agenda-acoes assinatura-acoes" aria-haspopup="menu">
              <button class="agenda-btn-acoes" type="button" data-acao="toggle-menu" data-id-assinatura="${esc(item.id_assinatura)}" aria-expanded="false" title="Ações" aria-label="Abrir ações da assinatura">
                ${iconAcoes()}
              </button>
              ${menuAcoes}
            </div>` : ""}
          </div>
        </div>
        <div class="assinatura-detalhes" aria-label="Detalhes da assinatura">
          <div class="assinatura-detalhe"><span>Plano</span><strong>${esc(item.plano_nome || "Não identificado")}</strong></div>
          <div class="assinatura-detalhe assinatura-detalhe--valor"><span>Valor</span><strong>${moeda(item.valor_contratado)}</strong></div>
          <div class="assinatura-detalhe"><span>Periodicidade</span><strong>${esc(item.periodicidade)}</strong></div>
          ${item.recorrencia?.alerta ? `<div class="assinatura-detalhe"><span>Pagamento automático</span><strong title="${esc(ALERTAS_RECORRENCIA[item.recorrencia.alerta] || item.recorrencia.alerta)}">⚠ Possível recorrência duplicada no Mercado Pago</strong></div>` : ""}
          <div class="assinatura-detalhe"><span>Vencimento</span><strong>Dia ${esc(item.dia_vencimento)}</strong></div>
          <div class="assinatura-detalhe"><span>Início</span><strong>${dataBr(item.data_inicio)}</strong></div>
          <div class="assinatura-detalhe"><span>Fim</span><strong>${dataBr(item.data_fim)}</strong></div>
        </div>
      </article>`;
    }).join("");
  }

  function renderizarOpcoesEmpresa(termo = "") {
    const opcoes = document.getElementById("opcoesAssinaturaEmpresa");
    if (!opcoes) return;

    const busca = normalizarBusca(termo);
    const empresas = empresasDisponiveis.filter(empresa => normalizarBusca(empresa.nome).includes(busca));
    const opcaoTodas = !busca
      ? '<button class="agenda-menu-item" type="button" role="option" data-id="" data-nome="Todas as empresas"><i class="fa-solid fa-building" aria-hidden="true"></i>Todas as empresas</button>'
      : "";
    opcoes.innerHTML = empresas.length
      ? opcaoTodas + empresas.map(empresa => `<button class="agenda-menu-item" type="button" role="option" data-id="${esc(empresa.id_empresa)}" data-nome="${esc(empresa.nome)}"><i class="fa-solid fa-building" aria-hidden="true"></i>${esc(empresa.nome)}</button>`).join("")
      : (opcaoTodas || '<div class="agenda-pesquisa-vazia">Nenhuma empresa encontrada.</div>');
  }

  function atualizarListagem() {
    const termo = normalizarBusca(document.getElementById("pesquisar-assinaturas")?.value).trim().replace(/\s+/g, " ");
    const filtradas = assinaturasDisponiveis.filter(item =>
      [item.empresa_nome, item.plano_nome, item.status].some(valor =>
        normalizarBusca(valor).trim().replace(/\s+/g, " ").includes(termo))
    );
    const totalPaginas = Math.max(1, Math.ceil(filtradas.length / itensPorPagina));
    paginaAtual = Math.min(paginaAtual, totalPaginas);
    renderizar(filtradas.slice((paginaAtual - 1) * itensPorPagina, paginaAtual * itensPorPagina));

    const pagDiv = document.getElementById("paginacao_assinaturas");
    if (!pagDiv) return;
    pagDiv.replaceChildren();
    if (totalPaginas <= 1) return;
    const adicionarBotao = (texto, destino) => {
      const botao = document.createElement("button");
      botao.type = "button";
      botao.textContent = texto;
      botao.classList.add("btn-pag");
      botao.addEventListener("click", () => {
        paginaAtual = destino;
        atualizarListagem();
      });
      pagDiv.appendChild(botao);
    };
    if (paginaAtual > 1) adicionarBotao("◀ Anterior", paginaAtual - 1);
    if (paginaAtual < totalPaginas) adicionarBotao("Próximo ▶", paginaAtual + 1);
  }

  function fecharSeletorEmpresa() {
    const popover = document.getElementById("popoverAssinaturaEmpresa");
    const botao = document.getElementById("btnAssinaturaEmpresa");
    if (popover) popover.hidden = true;
    botao?.setAttribute("aria-expanded", "false");
  }

  function abrirSeletorEmpresa() {
    const popover = document.getElementById("popoverAssinaturaEmpresa");
    const botao = document.getElementById("btnAssinaturaEmpresa");
    const pesquisa = document.getElementById("assinaturaEmpresaPesquisa");
    if (!popover || !botao) return;
    if (!popover.hidden) {
      fecharSeletorEmpresa();
      return;
    }
    popover.hidden = false;
    botao.setAttribute("aria-expanded", "true");
    if (pesquisa) pesquisa.value = "";
    renderizarOpcoesEmpresa();
    pesquisa?.focus();
  }

  async function carregarEmpresas() {
    const campo = document.getElementById("assinaturaEmpresa");
    const nome = document.getElementById("assinaturaEmpresaNome");
    if (!campo || !nome) return;

    try {
      const resposta = await fetch(EMPRESAS, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const json = await resposta.json();
      if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível listar empresas.");
      empresasDisponiveis = json.data?.items || [];
      const selecionada = empresasDisponiveis.find(empresa => String(empresa.id_empresa) === String(campo.value));
      campo.value = selecionada ? String(selecionada.id_empresa) : "";
      nome.textContent = selecionada?.nome || "Todas as empresas";
      renderizarOpcoesEmpresa(document.getElementById("assinaturaEmpresaPesquisa")?.value || "");
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível carregar as empresas.");
    }
  }

  function selecionarEmpresa(opcao) {
    const campo = document.getElementById("assinaturaEmpresa");
    const nome = document.getElementById("assinaturaEmpresaNome");
    if (!campo || !nome) return;
    campo.value = String(opcao.dataset.id || "");
    nome.textContent = String(opcao.dataset.nome || "Todas as empresas");
    fecharSeletorEmpresa();
    paginaAtual = 1;
    carregarAssinaturas();
  }

  async function carregarAssinaturas() {
    const requisicao = ++requisicaoListagem;
    menuCtrl.fechar();
    assinaturasDisponiveis = [];
    document.getElementById("paginacao_assinaturas")?.replaceChildren();
    const lista = document.getElementById("listaAssinaturas");
    const idEmpresa = document.getElementById("assinaturaEmpresa")?.value || "";
    const url = idEmpresa ? `${LISTAR}&id_empresa=${encodeURIComponent(idEmpresa)}` : LISTAR;
    if (lista) lista.innerHTML = '<div class="a-loading">Carregando assinaturas...</div>';

    try {
      const resposta = await fetch(url, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const json = await resposta.json();
      // Uma resposta antiga não pode substituir o filtro de empresa mais recente.
      if (requisicao !== requisicaoListagem) return;
      if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível listar as assinaturas.");
      assinaturasDisponiveis = json.data?.items || [];
      atualizarListagem();
    } catch (erro) {
      if (requisicao !== requisicaoListagem) return;
      if (lista) lista.innerHTML = '<div class="a-empty">Não foi possível carregar as assinaturas.</div>';
      avisar("erro", erro.message || "Não foi possível carregar as assinaturas.");
    }
  }

  async function confirmar(mensagem, opcoes) {
    const funcao = window.MensagemSistema?.confirmar;
    if (typeof funcao !== "function") {
      avisar("erro", "Não foi possível abrir a confirmação de segurança.");
      return false;
    }
    return funcao(mensagem, opcoes);
  }

  function limparCancelamento() {
    idAssinaturaCancelamento = "";
    idEmpresaCancelamento = "";
    const id = document.getElementById("cancelamentoAssinaturaId");
    const empresa = document.getElementById("cancelamentoAssinaturaEmpresaId");
    const motivo = document.getElementById("cancelamentoAssinaturaMotivo");
    if (id) id.value = "";
    if (empresa) empresa.value = "";
    if (motivo) motivo.value = "";
    document.querySelectorAll('#formCancelarAssinatura input[name="modalidade_cancelamento"]').forEach(campo => {
      campo.checked = false;
      campo.disabled = campo.value === "fim_periodo_pago";
    });
    const previa = document.getElementById("cancelamentoPreviaFim");
    if (previa) {
      previa.hidden = true;
      previa.textContent = "";
    }
  }

  async function carregarPreviaCancelamento() {
    const fimPeriodo = document.querySelector('#formCancelarAssinatura input[name="modalidade_cancelamento"][value="fim_periodo_pago"]');
    const previa = document.getElementById("cancelamentoPreviaFim");
    if (fimPeriodo) fimPeriodo.disabled = true;
    if (previa) {
      previa.hidden = false;
      previa.textContent = "Consultando a data de encerramento no servidor...";
    }
    try {
      const parametros = new URLSearchParams({ id_assinatura: idAssinaturaCancelamento, id_empresa: idEmpresaCancelamento });
      const resposta = await fetch(`${PREVIA_CANCELAMENTO}&${parametros.toString()}`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const json = await resposta.json();
      if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível consultar o período pago.");
      if (String(json.data?.id_assinatura || "") !== idAssinaturaCancelamento || String(json.data?.id_empresa || "") !== idEmpresaCancelamento) return;
      const fim = json.data?.data_fim_periodo_pago;
      if (json.data?.pode_fim_periodo_pago && fim) {
        if (fimPeriodo) fimPeriodo.disabled = false;
        if (previa) previa.textContent = `Cancelamento ao fim do período pago: acesso até ${dataBr(fim)}.`;
      } else if (previa) {
        previa.textContent = "Não há período integralmente pago vigente para programar o cancelamento. O cancelamento imediato continua disponível.";
      }
    } catch (erro) {
      if (previa) previa.textContent = erro.message || "Não foi possível consultar o período pago.";
    }
  }

  async function abrirCancelamento(botao) {
    // O motivo é coletado separadamente para impedir cancelamento acidental e manter a justificativa auditável.
    idAssinaturaCancelamento = String(botao.dataset.idAssinatura || "");
    idEmpresaCancelamento = String(botao.dataset.idEmpresa || "");
    document.getElementById("cancelamentoAssinaturaId").value = idAssinaturaCancelamento;
    document.getElementById("cancelamentoAssinaturaEmpresaId").value = idEmpresaCancelamento;
    document.getElementById("cancelamentoAssinaturaMotivo").value = "";
    window.abrirModal?.("modalCancelarAssinatura");
    document.getElementById("cancelamentoAssinaturaMotivo")?.focus();
    await carregarPreviaCancelamento();
  }

  async function enviarStatus(idAssinatura, idEmpresa, acao, motivo = "", modalidadeCancelamento = "", extras = {}) {
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!csrf) throw new Error("Token de segurança indisponível. Atualize a página.");
    const corpo = new FormData();
    corpo.append("id_assinatura", idAssinatura);
    corpo.append("id_empresa", idEmpresa);
    corpo.append("acao", acao);
    if (motivo) corpo.append("motivo", motivo);
    if (modalidadeCancelamento) corpo.append("modalidade_cancelamento", modalidadeCancelamento);
    Object.entries(extras).forEach(([chave, valor]) => corpo.append(chave, String(valor)));
    const resposta = await fetch(ALTERAR_STATUS, {
      method: "POST",
      body: corpo,
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json", "X-CSRF-Token": csrf },
    });
    // 409/422/503 podem vir sem JSON (proxy, erro do servidor): a falha continua tratada e nunca vira sucesso.
    const json = await resposta.json().catch(() => ({}));
    if (!resposta.ok || json.ok !== true) {
      const erro = new Error(json.user_msg || (resposta.status === 503 ? "Serviço indisponível no momento. Tente novamente em instantes." : "Não foi possível atualizar a assinatura."));
      erro.status = resposta.status;
      erro.codigo = String(json.code || "");
      erro.bloqueioRecorrencia = json.data?.bloqueio_recorrencia === true;
      erro.dados = json.data || {};
      throw erro;
    }
    return json;
  }

  async function alterarStatus(botao) {
    const acao = botao.dataset.acao;
    const idAssinatura = String(botao.dataset.idAssinatura || "");
    const idEmpresa = String(botao.dataset.idEmpresa || "");
    const textos = {
      suspender: ["Confirma a suspensão desta assinatura?", "Suspender assinatura"],
      reativar: ["Confirma a reativação desta assinatura?", "Reativar assinatura"],
    };
    const [mensagem, confirmacao] = textos[acao] || [];
    if (!mensagem) return;
    const confirmou = await confirmar(mensagem, { titulo: "Confirmar alteração", textoCancelar: "Voltar", textoConfirmar: confirmacao });
    if (!confirmou) return;
    botao.disabled = true;
    try {
      await enviarStatus(idAssinatura, idEmpresa, acao);
      avisar("sucesso", acao === "suspender" ? "Assinatura suspensa com sucesso." : "Assinatura reativada com sucesso.");
      await carregarAssinaturas();
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível atualizar a assinatura.");
    } finally {
      botao.disabled = false;
    }
  }

  // Abre o menu Ações da assinatura e destaca por 6 segundos "Cancelar pagamento automático" (desktop e celular).
  function destacarCancelarRecorrencia(idAssinatura) {
    const toggle = document.querySelector(`#assinaturas button[data-acao="toggle-menu"][data-id-assinatura="${CSS.escape(String(idAssinatura))}"]`);
    if (!toggle) return;
    toggle.scrollIntoView({ block: "center", behavior: "smooth" });
    menuCtrl.fechar();
    menuCtrl.toggle(toggle);
    const alvo = document.querySelector('.assinatura-menu.menu-flutuante [data-acao="cancelar_recorrencia"]')
      || toggle.closest(".agenda-card")?.querySelector('[data-acao="cancelar_recorrencia"]');
    if (!alvo) return;
    alvo.classList.add("assinatura-menu-item--destacado");
    alvo.setAttribute("aria-current", "true");
    try { alvo.focus({ preventScroll: true }); } catch {}
    setTimeout(() => {
      alvo.classList.remove("assinatura-menu-item--destacado");
      alvo.removeAttribute("aria-current");
    }, 6000);
  }

  async function cancelarRecorrencia(botao) {
    const idAssinatura = String(botao.dataset.idAssinatura || "");
    const idEmpresa = String(botao.dataset.idEmpresa || "");
    const confirmou = await confirmar("As próximas cobranças automáticas desta empresa serão interrompidas. A assinatura continuará ativa.", {
      titulo: "Cancelar pagamento automático?", textoCancelar: "Voltar", textoConfirmar: "Confirmar cancelamento",
    });
    if (!confirmou) return;
    try {
      const resultado = await enviarStatus(idAssinatura, idEmpresa, "cancelar_recorrencia");
      // Sucesso só com o encerramento confirmado pelo Mercado Pago; pendente nunca é apresentado como concluído.
      if (resultado.code === "PAGAMENTO_AUTOMATICO_CANCELADO") avisar("sucesso", resultado.user_msg);
      else avisar("aviso", resultado.user_msg || "O cancelamento foi solicitado. Aguarde a confirmação do Mercado Pago.");
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível cancelar o pagamento automático. Atualize a situação ou tente novamente.");
    } finally {
      await carregarAssinaturas();
    }
  }

  // Resolução administrativa (Super Admin global) num único modal: diagnóstico somente leitura (contexto, histórico e
  // resultado da busca no Mercado Pago) e, quando a varredura completa não localiza a assinatura, a conferência
  // independente (fonte, data e hora, evidência) com o número da empresa digitado. A confirmação reaproveita a evidência
  // que o servidor acabou de coletar (token da sessão); nada é criado nem cancelado no provedor; contrato e teste grátis
  // não mudam. Preapproval encontrada ou consulta inconclusiva nunca liberam.
  const MOTIVOS_ATESTADO = {
    ATESTADO_FONTE_INVALIDA: "Fonte da conferência inválida.",
    ATESTADO_DATA_INVALIDA: "Data/hora da conferência inválida (deve ser depois da tentativa e não no futuro).",
    ATESTADO_DESCRICAO_INVALIDA: "Identifique a evidência consultada com 20 a 300 caracteres."
  };
  const RESULTADOS_BUSCA = {
    encontrada: "Assinatura externa relacionada encontrada no Mercado Pago: a recuperação fica bloqueada.",
    nao_localizada: "Não localizada após consulta completa (isso não prova inexistência; registre a conferência).",
    inconclusiva: "Consulta inconclusiva: nada pode ser liberado agora."
  };
  // Situações em que a conferência não resolve: o formulário não é oferecido.
  const RESOLUCAO_BLOQUEADA = ["PREAPPROVAL_EXTERNA_ENCONTRADA", "EVIDENCIA_INCOMPLETA", "EVIDENCIA_INDISPONIVEL", "CONSULTA_RECUSADA",
    "REFERENCIA_ESGOTADA", "REFERENCIA_NAO_SUPORTADA", "SEM_CRIACAO_INCERTA", "ASSINATURA_NAO_ENCONTRADA"];
  // O que falta em cada desfecho e o procedimento seguro (nada é liberado sem evidência suficiente).
  const PROXIMO_PASSO = {
    ATESTADO_NECESSARIO: "a sua conferência independente. Preencha a confirmação administrativa abaixo (fonte, data e hora, evidência consultada e número da empresa).",
    PREAPPROVAL_EXTERNA_ENCONTRADA: "Existe assinatura relacionada no Mercado Pago: não encerre. Se for da tentativa atual, o cliente a recupera em \"Atualizar situação\"; se for antiga, cancele-a no painel do Mercado Pago e consulte novamente.",
    EVIDENCIA_INCOMPLETA: "A consulta não percorreu todas as assinaturas da conta. Consulte novamente; se persistir, é necessária análise técnica.",
    EVIDENCIA_INDISPONIVEL: "Falha temporária ao consultar o Mercado Pago. Nada foi alterado: consulte novamente em instantes.",
    CONSULTA_RECUSADA: "O Mercado Pago recusou a consulta (configuração ou credencial). Nada foi alterado; é necessária análise técnica.",
    OPERACAO_EM_ANDAMENTO: "Uma verificação automática deste cadastro está em curso. Consulte novamente em alguns instantes.",
    ESTADO_ALTERADO: "O cadastro mudou durante a verificação (por exemplo, o cliente clicou em \"Atualizar situação\"). Consulte novamente.",
    REFERENCIA_NAO_SUPORTADA: "O banco precisa comportar a nova referência: faça o ajuste estrutural manual autorizado e consulte novamente.",
    REFERENCIA_ESGOTADA: "O contrato atingiu o limite de tentativas de cadastro: é necessária análise técnica.",
    RESOLVER_CRIACAO_ERRO: "Falha técnica no servidor (registrada no log). Nada foi alterado: consulte novamente.",
    SEM_CRIACAO_INCERTA: "Não há tentativa incerta aberta para esta assinatura: nada a resolver.",
  };
  // Desfechos temporários ou que mudam com uma ação externa: a consulta pode ser repetida no próprio modal.
  const RESOLUCAO_REPETIVEL = ["", "EVIDENCIA_INDISPONIVEL", "EVIDENCIA_INCOMPLETA", "OPERACAO_EM_ANDAMENTO", "ESTADO_ALTERADO",
    "RESOLVER_CRIACAO_ERRO", "PREAPPROVAL_EXTERNA_ENCONTRADA", "REFERENCIA_NAO_SUPORTADA", "OPERACAO_CARTAO_EM_ANDAMENTO"];
  let resolucaoAtual = null;

  // Apresentação: rótulo e valor separados (dl), referências longas com quebra, avisos nos componentes existentes.
  const dataHoraBr = valor => {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(valor || ""));
    return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : (valor ? String(valor) : "—");
  };
  const dado = (rotulo, valorHtml) => `<dt>${esc(rotulo)}</dt><dd>${valorHtml}</dd>`;
  const secaoResolucao = (titulo, conteudo) => `<section class="resolver-secao"><h3 class="resolver-secao-titulo">${esc(titulo)}</h3>${conteudo}</section>`;

  function htmlResolucao(dados = {}, mensagem = "", sucesso = false, codigo = null) {
    const c = dados.contexto || null;
    const e = dados.evidencia || null;
    // Conferência pendente é etapa esperada (informativa); bloqueios e falhas usam o aviso de erro.
    const tipo = sucesso ? "sucesso" : (codigo === null || ["ATESTADO_NECESSARIO", "CONFIRMACAO_OBRIGATORIA"].includes(codigo) ? "carregando" : "erro");
    const partes = [`<p class="atraso-feedback atraso-feedback--${tipo}">${esc(mensagem)}</p>`];
    if (codigo !== null && PROXIMO_PASSO[codigo]) {
      partes.push(`<div class="atraso-alertas${RESOLUCAO_BLOQUEADA.includes(codigo) ? " atraso-alertas--bloqueio" : ""}">O que falta: ${esc(PROXIMO_PASSO[codigo])}</div>`);
    }
    if (codigo !== null && RESOLUCAO_REPETIVEL.includes(codigo) && resolucaoAtual) {
      partes.push(`<div class="resolver-repetir"><button class="botao-geral" type="button" data-resolver-repetir data-id-assinatura="${esc(resolucaoAtual.idAssinatura)}" data-id-empresa="${esc(resolucaoAtual.idEmpresa)}"><i class="fa-solid fa-rotate" aria-hidden="true"></i>Consultar novamente</button></div>`);
    }
    if (c) {
      const a = c.assinatura || {};
      const o = c.operacao || {};
      partes.push(secaoResolucao("Resumo da assinatura", `<dl class="resolver-dados">
        ${dado("Empresa", `#${esc(resolucaoAtual?.idEmpresa || "—")}`)}
        ${dado("Assinatura comercial", `#${esc(a.id)} · ${esc(a.status || "—")} · ${esc(a.modalidade || "—")}`)}
        ${a.teste_expira_em ? dado("Teste grátis até", esc(dataHoraBr(a.teste_expira_em))) : ""}
        ${dado("Vínculo financeiro", `#${esc(c.vinculo?.id)} · ${esc(c.vinculo?.status || "—")}`)}
        ${dado("Operação incerta", `#${esc(o.id)} · ${esc(o.status || "—")}${o.erro_codigo ? ` · ${esc(o.erro_codigo)}` : ""}`)}
        ${dado("Tentativa criada em", `${esc(dataHoraBr(o.criado_em))} · ${esc(o.tentativas ?? 0)} consulta(s)`)}
        ${dado("Referência externa", `<code class="resolver-referencia">${esc(c.vinculo?.referencia || "—")}</code>`)}
        ${dados.referencia_nova ? dado("Nova referência", `<code class="resolver-referencia">${esc(dados.referencia_nova)}</code>`) : ""}
      </dl>`));
    }
    if (e) {
      const limitacoes = Array.isArray(e.limitacoes) && e.limitacoes.length
        ? `<div class="atraso-alertas">Limitações da consulta<ul>${e.limitacoes.map(l => `<li>${esc(l)}</li>`).join("")}</ul></div>` : "";
      partes.push(secaoResolucao("Verificação do Mercado Pago", `<dl class="resolver-dados">
        ${dado("Resultado", esc(RESULTADOS_BUSCA[e.resultado_busca] || e.resultado || "—"))}
        ${dado("Assinaturas verificadas", `${esc(e.itens_varridos ?? "?")}${e.canceladas_consultadas ? " (inclusive canceladas)" : ""}`)}
        ${e.evidencia_reaproveitada ? dado("Evidência", `reaproveitada da consulta de ${esc(dataHoraBr(e.coletada_em))}`) : ""}
      </dl>${limitacoes}`));
    }
    const historico = Array.isArray(c?.historico) ? c.historico : [];
    if (historico.length) {
      partes.push(secaoResolucao("Histórico da operação", `<details class="resolver-historico"><summary>Ver histórico técnico (${historico.length} registro${historico.length > 1 ? "s" : ""})</summary><ul>${historico.map(h =>
        `<li><strong>#${esc(h.id)} · ${esc(h.tipo)} · ${esc(h.status)}</strong><span>${h.erro_codigo ? `${esc(h.erro_codigo)} · ` : ""}criada em ${esc(dataHoraBr(h.criado_em))} · atualizada em ${esc(dataHoraBr(h.atualizado_em))} · ${esc(h.tentativas ?? 0)} tentativa(s)</span></li>`).join("")}</ul></details>`));
    }
    return partes.join("");
  }

  function mostrarResolucao(dados, mensagem, { formulario = false, sucesso = false, erro = "", codigo = null } = {}) {
    const diag = document.getElementById("resolverCartaoDiagnostico");
    const form = document.getElementById("formResolverCartao");
    const aviso = document.getElementById("resolverCartaoErro");
    const token = document.getElementById("resolverCartaoToken");
    const enviar = document.getElementById("resolverCartaoEnviar");
    if (diag) diag.innerHTML = htmlResolucao(dados || {}, mensagem, sucesso, codigo);
    if (form) form.hidden = !formulario;
    // O botão do rodapé só existe enquanto a confirmação administrativa é possível; concluída, resta "Fechar".
    if (enviar) enviar.hidden = !formulario;
    const cancelar = document.getElementById("resolverCartaoCancelar");
    if (cancelar) cancelar.textContent = sucesso ? "Fechar" : "Cancelar";
    if (token && dados && Object.prototype.hasOwnProperty.call(dados, "evidencia_token")) token.value = dados.evidencia_token || "";
    if (aviso) { aviso.hidden = !erro; aviso.textContent = erro; }
  }

  async function resolverCriacaoCartao(botao) {
    const idAssinatura = String(botao.dataset.idAssinatura || "");
    const idEmpresa = String(botao.dataset.idEmpresa || "");
    resolucaoAtual = { idAssinatura, idEmpresa };
    // "Consultar novamente" no próprio modal preserva a conferência já digitada.
    if (!botao.hasAttribute("data-resolver-repetir")) document.getElementById("formResolverCartao")?.reset();
    document.getElementById("resolverCartaoAssinatura").value = idAssinatura;
    document.getElementById("resolverCartaoEmpresa").value = idEmpresa;
    document.getElementById("resolverCartaoToken").value = "";
    document.getElementById("resolverCartaoConfirmarRotulo").textContent = `Digite o número da empresa (${idEmpresa}) para confirmar`;
    mostrarResolucao({}, "Consultando o Mercado Pago (somente leitura)...");
    window.abrirModal?.("modalResolverCartao");
    try {
      const resultado = await enviarStatus(idAssinatura, idEmpresa, "resolver_criacao_cartao");
      mostrarResolucao(resultado.data || {}, resultado.user_msg, { sucesso: true });
    } catch (erro) {
      if (resolucaoAtual?.idAssinatura !== idAssinatura) return;
      const liberavel = erro.codigo === "ATESTADO_NECESSARIO" || erro.codigo === "CONFIRMACAO_OBRIGATORIA";
      mostrarResolucao(erro.dados || {}, erro.message || "Não foi possível concluir a verificação agora. Nada foi alterado.", { formulario: liberavel, codigo: erro.codigo || "" });
    }
  }

  async function confirmarResolucao(evento) {
    evento.preventDefault();
    if (!resolucaoAtual) return;
    const { idAssinatura, idEmpresa } = resolucaoAtual;
    const form = evento.currentTarget;
    const fonte = form.querySelector('input[name="fonte_evidencia"]:checked')?.value || "";
    const conferidoEm = String(document.getElementById("resolverCartaoConferidoEm").value || "");
    const atestado = String(document.getElementById("resolverCartaoAtestado").value || "").trim();
    const digitado = String(document.getElementById("resolverCartaoConfirmarEmpresa").value || "").trim();
    const aviso = document.getElementById("resolverCartaoErro");
    const falhar = texto => { if (aviso) { aviso.hidden = false; aviso.textContent = texto; } };
    if (!fonte) return falhar("Informe onde a conferência foi feita.");
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(conferidoEm)) return falhar("Informe a data e a hora da conferência.");
    if (atestado.length < 20 || atestado.length > 300) return falhar(MOTIVOS_ATESTADO.ATESTADO_DESCRICAO_INVALIDA);
    if (digitado !== idEmpresa) return falhar("O número informado não confere com a empresa.");
    const botao = document.getElementById("resolverCartaoEnviar");
    if (botao) botao.disabled = true;
    try {
      const resultado = await enviarStatus(idAssinatura, idEmpresa, "resolver_criacao_cartao", "", "", {
        fonte_evidencia: fonte, conferido_em: conferidoEm, atestado, evidencia_token: document.getElementById("resolverCartaoToken").value,
        confirmo: "1", confirmar_empresa: idEmpresa,
      });
      mostrarResolucao(resultado.data || {}, resultado.user_msg, { sucesso: true });
      avisar("sucesso", resultado.user_msg);
      await carregarAssinaturas();
    } catch (erro) {
      const dados = erro.dados || {};
      const mensagem = erro.message || "Não foi possível concluir a verificação agora. Nada foi alterado.";
      if (erro.codigo === "ATESTADO_NECESSARIO") {
        mostrarResolucao(dados, mensagem, { formulario: true, codigo: "ATESTADO_NECESSARIO", erro: MOTIVOS_ATESTADO[dados.atestado_motivo] || "A conferência registrada não foi aceita." });
      } else {
        // Estado alterado ou verificação automática em andamento: pode tentar de novo; os demais bloqueios encerram o formulário.
        const bloqueada = RESOLUCAO_BLOQUEADA.includes(erro.codigo);
        mostrarResolucao(dados, mensagem, { formulario: !bloqueada, erro: bloqueada ? "" : mensagem, codigo: erro.codigo || "" });
      }
    } finally {
      if (botao) botao.disabled = false;
    }
  }

  async function cancelar(evento) {
    evento.preventDefault();
    const botao = evento.submitter || document.querySelector('#formCancelarAssinatura button[type="submit"]');
    if (botao?.disabled) return;
    const motivo = String(document.getElementById("cancelamentoAssinaturaMotivo")?.value || "").trim();
    const modalidade = document.querySelector('#formCancelarAssinatura input[name="modalidade_cancelamento"]:checked')?.value || "";
    if (!["imediato", "fim_periodo_pago"].includes(modalidade)) {
      avisar("aviso", "Escolha a modalidade do cancelamento.");
      return;
    }
    if (!motivo) {
      avisar("aviso", "Informe um motivo para cancelar a assinatura.");
      return;
    }
    try {
      if (botao) botao.disabled = true;
      const resultado = await enviarStatus(idAssinaturaCancelamento, idEmpresaCancelamento, "cancelar", motivo, modalidade);
      const fim = resultado.data?.data_fim;
      avisar("sucesso", modalidade === "fim_periodo_pago"
        ? `Cancelamento programado. Acesso até ${dataBr(fim)}; a renovação automática foi bloqueada.`
        : "Assinatura cancelada imediatamente. O histórico financeiro foi preservado.");
      if (Number(resultado.data?.operacoes_em_andamento || 0) > 0) {
        avisar("aviso", "Há operações de pagamento em andamento ou incertas. Elas devem ser conciliadas; o cancelamento não as estorna.");
      }
      window.fecharModal?.(document.getElementById("modalCancelarAssinatura"));
      limparCancelamento();
      await carregarAssinaturas();
    } catch (erro) {
      if (erro.bloqueioRecorrencia) {
        // Recorrência no cartão ativa, pendente ou incerta: o contrato não foi cancelado e nada foi enviado ao Mercado Pago.
        const idBloqueado = idAssinaturaCancelamento;
        window.fecharModal?.(document.getElementById("modalCancelarAssinatura"));
        limparCancelamento();
        avisar("aviso", erro.message);
        if (erro.codigo === "CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO") destacarCancelarRecorrencia(idBloqueado);
      } else {
        avisar("erro", erro.message || "Não foi possível cancelar a assinatura.");
      }
    } finally {
      if (botao) botao.disabled = false;
    }
  }

  function bind() {
    document.getElementById("pesquisar-assinaturas")?.addEventListener("input", () => {
      paginaAtual = 1;
      atualizarListagem();
    });
    document.getElementById("btnAssinaturaEmpresa")?.addEventListener("click", abrirSeletorEmpresa);
    document.getElementById("assinaturaEmpresaPesquisa")?.addEventListener("input", evento => renderizarOpcoesEmpresa(evento.target.value));
    document.getElementById("opcoesAssinaturaEmpresa")?.addEventListener("click", evento => {
      const opcao = evento.target.closest('[role="option"]');
      if (opcao) selecionarEmpresa(opcao);
    });
    document.getElementById("btnAtualizarAssinaturas")?.addEventListener("click", async () => {
      await carregarEmpresas();
      await carregarAssinaturas();
    });
    document.addEventListener("click", evento => {
      const toggle = evento.target.closest('#assinaturas button[data-acao="toggle-menu"]');
      if (toggle) {
        evento.preventDefault();
        evento.stopPropagation();
        if (menuCtrl.getOwnerCard?.() === toggle.closest(".agenda-card")) menuCtrl.fechar();
        else menuCtrl.toggle(toggle);
        return;
      }

      const botao = evento.target.closest("#assinaturas .assinatura-menu .agenda-menu-item, .assinatura-menu.menu-flutuante .agenda-menu-item");
      if (!botao) return;
      menuCtrl.fechar();
      if (botao.dataset.acao === "cancelar") void abrirCancelamento(botao);
      else if (botao.dataset.acao === "cancelar_recorrencia") void cancelarRecorrencia(botao);
      else if (botao.dataset.acao === "resolver_criacao_cartao") void resolverCriacaoCartao(botao);
      else alterarStatus(botao);
    });
    document.getElementById("formCancelarAssinatura")?.addEventListener("submit", cancelar);
    document.getElementById("formResolverCartao")?.addEventListener("submit", confirmarResolucao);
    // Falha temporária nunca vira bloqueio: a consulta pode ser repetida no próprio modal.
    document.getElementById("resolverCartaoDiagnostico")?.addEventListener("click", evento => {
      const botao = evento.target.closest("[data-resolver-repetir]");
      if (botao) void resolverCriacaoCartao(botao);
    });
    document.addEventListener("click", evento => {
      if (evento.target.closest("#modalCancelarAssinatura [data-fechar-modal]")) limparCancelamento();
      if (!evento.target.closest("#seletorAssinaturaEmpresa")) fecharSeletorEmpresa();
      if (!evento.target.closest("#assinaturas .agenda-acoes, #assinaturas .agenda-menu, .agenda-menu.menu-flutuante")) {
        menuCtrl.fechar();
      }
    });
    document.addEventListener("keydown", evento => {
      if (evento.key === "Escape") {
        fecharSeletorEmpresa();
        menuCtrl.fechar();
      }
      if (evento.key === "Escape" && document.getElementById("modalCancelarAssinatura")?.classList.contains("ativo")) {
        limparCancelamento();
      }
    });
  }

  function iniciar() {
    if (window.__GERENCIAR_ASSINATURAS_INIT__) return;
    if (!document.getElementById("listaAssinaturas")) return;
    window.__GERENCIAR_ASSINATURAS_INIT__ = true;
    bind();
    carregarEmpresas();
    carregarAssinaturas();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => { if (window.__AUTH__) iniciar(); }, { once: true });
  } else if (window.__AUTH__) {
    iniciar();
  }
  document.addEventListener("amagenda:sessao-carregada", iniciar, { once: true });
})();
