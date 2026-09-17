(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C) {
    console.warn("[GerenciarCobrancas] ListaCore não carregado.");
    return;
  }

  const API = "/public/api/api_central.php";
  const LISTAR = `${API}?path=superadmin/cobranca/listar&limit=200`;
  const GERAR = `${API}?path=superadmin/cobranca/gerar`;
  const PAGAMENTOS = `${API}?path=superadmin/cobranca/pagamentos`;
  const REGISTRAR_PAGAMENTO = `${API}?path=superadmin/cobranca/pagamento/registrar`;
  const ALTERAR_PAGAMENTO = `${API}?path=superadmin/cobranca/pagamento/status`;
  const CANCELAR_COBRANCA = `${API}?path=superadmin/cobranca/cancelar`;
  const EMPRESAS = `${API}?path=superadmin/empresa/listar&status=ativo&ordem=nome_asc&limit=100`;
  const menuCtrl = C.createFloatingMenuController({ rootSelector: "#cobrancas" });
  const formasPagamento = {
    pix: "PIX", boleto: "Boleto", cartao_credito: "Cartão de crédito", cartao_debito: "Cartão de débito",
    transferencia: "Transferência", dinheiro: "Dinheiro", outro: "Outro",
  };

  let empresasDisponiveis = [];
  let cobrancaModalAtual = null;
  // Mantém apenas a cobrança selecionada enquanto o modal de cancelamento está aberto.
  let cancelamentoCobrancaAtual = "";
  let filtrosCobranca = { id_empresa: "", situacao: "todas", data_vencimento_de: "", data_vencimento_ate: "" };
  let cobrancasDisponiveis = [];
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
  const valorOuZero = valor => moeda(Number(valor || 0));
  const classeSituacao = valor => {
    const normalizado = String(valor || "pendente").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    if (normalizado.includes("paga")) return "agenda-pagamento pago";
    if (normalizado.includes("vencida") || normalizado.includes("cancelada")) return "agenda-status st-cancelado";
    return "agenda-status st-pendente";
  };
  const iconeSituacao = valor => {
    const normalizado = String(valor || "pendente").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    if (normalizado.includes("paga")) return "fa-solid fa-circle-check";
    if (normalizado.includes("vencida")) return "fa-solid fa-triangle-exclamation";
    if (normalizado.includes("cancelada")) return "fa-solid fa-ban";
    return "fa-regular fa-clock";
  };
  const avisar = (tipo, mensagem) => {
    const fn = window.MensagemSistema?.[tipo] || window.MensagemSistema?.info;
    fn?.(mensagem);
  };
  const dataHoje = () => {
    const agora = new Date();
    return `${agora.getFullYear()}-${String(agora.getMonth() + 1).padStart(2, "0")}-${String(agora.getDate()).padStart(2, "0")}`;
  };
  const normalizarBusca = valor => String(valor || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();

  function renderizarOpcoesEmpresa(termo = "") {
    const lista = document.getElementById("opcoesCobrancaEmpresa");
    if (!lista) return;
    const busca = normalizarBusca(termo);
    const itens = empresasDisponiveis.filter(item => !busca || normalizarBusca(item.nome).includes(busca));
    const opcaoTodas = !busca ? `
      <button type="button" class="agenda-menu-item" role="option" data-id="" data-nome="Todas as empresas">
        <i class="fa-regular fa-building" aria-hidden="true"></i><span>Todas as empresas</span>
      </button>` : "";
    lista.innerHTML = itens.length ? opcaoTodas + itens.map(item => `
      <button type="button" class="agenda-menu-item" role="option" data-id="${esc(item.id_empresa)}" data-nome="${esc(item.nome)}">
        <i class="fa-regular fa-building" aria-hidden="true"></i><span>${esc(item.nome)}</span>
      </button>`).join("") : (opcaoTodas || '<div class="agenda-pesquisa-estado">Nenhuma empresa encontrada.</div>');
  }

  function fecharSeletorEmpresa() {
    const botao = document.getElementById("btnCobrancaEmpresa");
    const popover = document.getElementById("popoverCobrancaEmpresa");
    if (!botao || !popover) return;
    popover.setAttribute("hidden", "");
    botao.setAttribute("aria-expanded", "false");
  }

  function abrirSeletorEmpresa() {
    const botao = document.getElementById("btnCobrancaEmpresa");
    const popover = document.getElementById("popoverCobrancaEmpresa");
    const pesquisa = document.getElementById("cobrancaEmpresaPesquisa");
    if (!botao || !popover) return;
    const aberto = !popover.hasAttribute("hidden");
    fecharSeletorEmpresa();
    if (aberto) return;
    if (pesquisa) pesquisa.value = "";
    renderizarOpcoesEmpresa();
    popover.removeAttribute("hidden");
    botao.setAttribute("aria-expanded", "true");
    pesquisa?.focus();
  }

  function selecionarEmpresa(botaoOpcao) {
    const id = String(botaoOpcao?.dataset?.id || "");
    const nome = String(botaoOpcao?.dataset?.nome || "");
    const campo = document.getElementById("cobrancaEmpresa");
    const nomeEl = document.getElementById("cobrancaEmpresaNome");
    if (!campo || !nomeEl) return;
    campo.value = id;
    nomeEl.textContent = nome || "Todas as empresas";
    fecharSeletorEmpresa();
    filtrosCobranca.id_empresa = id;
    paginaAtual = 1;
    carregarCobrancas();
  }

  function bindSeletorEmpresa() {
    document.getElementById("btnCobrancaEmpresa")?.addEventListener("click", abrirSeletorEmpresa);
    document.getElementById("cobrancaEmpresaPesquisa")?.addEventListener("input", evento => renderizarOpcoesEmpresa(evento.currentTarget.value));
    document.getElementById("opcoesCobrancaEmpresa")?.addEventListener("click", evento => {
      const opcao = evento.target.closest("button[data-id]");
      if (opcao) selecionarEmpresa(opcao);
    });
    document.addEventListener("click", evento => {
      const seletor = document.getElementById("seletorCobrancaEmpresa");
      if (seletor && !seletor.contains(evento.target)) fecharSeletorEmpresa();
    });
  }

  function iconAcoes() {
    return `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7.25a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Z"/></svg>`;
  }

  function menuAcoesCobranca(item) {
    const cancelada = item.status === "cancelada";
    const podeRegistrar = !cancelada && Number(item.saldo_restante || 0) > 0;
    const podeCancelar = item.status === "pendente";
    return `<div class="agenda-menu cobranca-menu" role="menu">
      <button class="agenda-menu-item" type="button" role="menuitem" data-acao="pagamentos" data-id-cobranca="${esc(item.id_cobranca)}">
        <i class="fa-solid fa-list-check" aria-hidden="true"></i>Ver pagamentos
      </button>
      ${podeRegistrar ? `<button class="agenda-menu-item" type="button" role="menuitem" data-acao="registrar-pagamento" data-id-cobranca="${esc(item.id_cobranca)}">
        <i class="fa-solid fa-plus" aria-hidden="true"></i>Registrar pagamento
      </button>` : ""}
      ${podeCancelar ? `<div class="cobranca-menu-separador" role="separator"></div>
        <button class="agenda-menu-item danger" type="button" role="menuitem" data-acao="cancelar-cobranca" data-id-cobranca="${esc(item.id_cobranca)}">
          <i class="fa-solid fa-ban" aria-hidden="true"></i>Cancelar cobrança
        </button>` : ""}
    </div>`;
  }

  function renderizar(items) {
    const lista = document.getElementById("listaCobrancas");
    if (!lista) return;
    menuCtrl.fechar();
    if (!items.length) {
      lista.innerHTML = '<div class="a-empty">Nenhuma cobrança encontrada.</div>';
      return;
    }
    lista.innerHTML = items.map(item => `
      <article class="agenda-card cobranca-card">
        <div class="cobranca-card-cabecalho">
          <div class="cobranca-identidade-conteudo">
            <div class="agenda-nome">${esc(item.nome_empresa)}</div>
          </div>
          <div class="cobranca-status-acoes">
            <span class="${classeSituacao(item.situacao)} cobranca-status-badge"><i class="${iconeSituacao(item.situacao)}" aria-hidden="true"></i><span>${esc(item.situacao || "pendente")}</span></span>
            <div class="agenda-acoes cobranca-acoes" aria-haspopup="menu">
              <button class="agenda-btn-acoes" type="button" data-acao="toggle-menu-cobranca" aria-expanded="false" title="Ações" aria-label="Abrir ações da cobrança">${iconAcoes()}</button>
              ${menuAcoesCobranca(item)}
            </div>
          </div>
        </div>
        <div class="cobranca-detalhes">
          <div class="cobranca-data-item"><span class="cobranca-meta-label">Plano</span><strong>${esc(item.plano_nome || "Não identificado")}</strong></div>
          <div class="cobranca-valor"><span class="cobranca-meta-label">Valor</span><strong>${moeda(item.valor)}</strong></div>
          <div class="cobranca-data-item"><span class="cobranca-meta-label">Período</span><strong>${dataBr(item.periodo_inicio)} a ${dataBr(item.periodo_fim)}</strong></div>
          <div class="cobranca-data-item"><span class="cobranca-meta-label">Vencimento</span><strong>${dataBr(item.data_vencimento)}</strong></div>
        </div>
      </article>`).join("");
  }

  function atualizarListagem() {
    const termo = normalizarBusca(document.getElementById("pesquisar-cobrancas")?.value).replace(/\s+/g, " ");
    const filtradas = cobrancasDisponiveis.filter(item =>
      [item.nome_empresa, item.plano_nome, item.situacao, item.status].some(valor =>
        normalizarBusca(valor).replace(/\s+/g, " ").includes(termo))
    );
    const totalPaginas = Math.max(1, Math.ceil(filtradas.length / itensPorPagina));
    paginaAtual = Math.min(paginaAtual, totalPaginas);
    renderizar(filtradas.slice((paginaAtual - 1) * itensPorPagina, paginaAtual * itensPorPagina));

    const pagDiv = document.getElementById("paginacao_cobrancas");
    if (!pagDiv) return;
    pagDiv.replaceChildren();
    if (!filtradas.length) return;
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
    const indicador = document.createElement("span");
    indicador.className = "cobranca-paginacao-info";
    indicador.textContent = `Página ${paginaAtual} de ${totalPaginas} · ${filtradas.length} cobranças`;
    pagDiv.appendChild(indicador);
    if (paginaAtual < totalPaginas) adicionarBotao("Próximo ▶", paginaAtual + 1);
  }

  function renderizarResumo(resumo = {}) {
    const receber = document.getElementById("resumoAReceber");
    const recebido = document.getElementById("resumoRecebido");
    const vencido = document.getElementById("resumoVencido");
    const total = document.getElementById("resumoTotalCobrancas");
    if (receber) receber.textContent = valorOuZero(resumo.a_receber);
    if (recebido) recebido.textContent = valorOuZero(resumo.recebido);
    if (vencido) vencido.textContent = valorOuZero(resumo.vencido);
    if (total) total.textContent = String(Number(resumo.total_cobrancas || 0));
  }

  function urlListagemCobrancas() {
    const parametros = new URLSearchParams();
    Object.entries(filtrosCobranca).forEach(([campo, valor]) => {
      if (valor) parametros.set(campo, valor);
    });
    const separador = parametros.toString() ? "&" : "";
    return `${LISTAR}${separador}${parametros.toString()}`;
  }

  async function carregarCobrancas() {
    const requisicao = ++requisicaoListagem;
    const lista = document.getElementById("listaCobrancas");
    menuCtrl.fechar();
    cobrancasDisponiveis = [];
    document.getElementById("paginacao_cobrancas")?.replaceChildren();
    if (lista) lista.innerHTML = '<div class="a-loading">Carregando cobranças...</div>';
    try {
      const resposta = await fetch(urlListagemCobrancas(), { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const json = await resposta.json();
      if (requisicao !== requisicaoListagem) return;
      if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível listar cobranças.");
      renderizarResumo(json.data?.resumo || {});
      cobrancasDisponiveis = json.data?.items || [];
      atualizarListagem();
    } catch (erro) {
      if (requisicao !== requisicaoListagem) return;
      renderizarResumo();
      if (lista) lista.innerHTML = '<div class="a-empty">Não foi possível carregar as cobranças.</div>';
      avisar("erro", erro.message || "Não foi possível carregar as cobranças.");
    }
  }

  async function carregarEmpresas() {
    const lista = document.getElementById("opcoesCobrancaEmpresa");
    if (!lista) return;
    lista.innerHTML = '<div class="agenda-pesquisa-estado">Carregando empresas...</div>';
    try {
      const resposta = await fetch(EMPRESAS, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const json = await resposta.json();
      if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível listar empresas.");
      empresasDisponiveis = (json.data?.items || []).filter(item => normalizarBusca(item.status) === "ativo");
      renderizarOpcoesEmpresa();
    } catch (erro) {
      lista.innerHTML = '<div class="agenda-pesquisa-estado">Não foi possível carregar as empresas.</div>';
      avisar("erro", erro.message || "Não foi possível carregar as empresas.");
    }
  }

  function abrirModalPagamento(idCobranca, registrar = false) {
    cobrancaModalAtual = String(idCobranca || "");
    const form = document.getElementById("formRegistrarPagamentoCobranca");
    const titulo = document.getElementById("tituloModalPagamentosCobranca");
    if (form) form.hidden = !registrar;
    if (titulo) titulo.textContent = registrar ? "Registrar pagamento" : "Pagamentos da cobrança";
    if (form) {
      form.reset();
      document.getElementById("pagamentoCobrancaId").value = cobrancaModalAtual;
      document.getElementById("pagamentoData").value = dataHoje();
    }
    if (typeof window.abrirModal === "function") window.abrirModal("modalPagamentosCobranca");
    else document.getElementById("modalPagamentosCobranca")?.classList.add("ativo");
    carregarPagamentos(cobrancaModalAtual, registrar);
  }

  function limparEstadoCancelamento() {
    cancelamentoCobrancaAtual = "";
    const campoId = document.getElementById("cancelamentoCobrancaId");
    const campoMotivo = document.getElementById("cancelamentoMotivo");
    if (campoId) campoId.value = "";
    if (campoMotivo) campoMotivo.value = "";
  }

  function abrirModalCancelamento(idCobranca) {
    // O id fica em campo oculto para o envio; a autorização efetiva continua no backend.
    cancelamentoCobrancaAtual = String(idCobranca || "");
    const campoId = document.getElementById("cancelamentoCobrancaId");
    const campoMotivo = document.getElementById("cancelamentoMotivo");
    if (campoId) campoId.value = cancelamentoCobrancaAtual;
    if (campoMotivo) campoMotivo.value = "";
    if (typeof window.abrirModal === "function") window.abrirModal("modalCancelarCobranca");
    else document.getElementById("modalCancelarCobranca")?.classList.add("ativo");
    campoMotivo?.focus();
  }

  function resumoCobranca(cobranca) {
    return `<div><span>Valor da cobrança</span><strong>${moeda(cobranca.valor)}</strong></div>
      <div><span>Confirmado</span><strong>${moeda(cobranca.total_pago_confirmado)}</strong></div>
      <div><span>Saldo restante</span><strong>${moeda(cobranca.saldo_restante)}</strong></div>
      <div><span>Vencimento</span><strong>${dataBr(cobranca.data_vencimento)}</strong></div>`;
  }

  function renderizarPagamentos(items) {
    const lista = document.getElementById("listaPagamentosCobranca");
    if (!lista) return;
    if (!items.length) {
      lista.innerHTML = '<div class="a-empty">Nenhum pagamento registrado.</div>';
      return;
    }
    lista.innerHTML = items.map(item => {
      const ativo = item.status === "confirmado";
      return `<article class="am-pagamento-item">
        <div><strong>${moeda(item.valor_pago)}</strong><span>${dataBr(item.data_pagamento)} · ${esc(formasPagamento[item.forma_pagamento] || item.forma_pagamento)} · ${esc(item.origem)}</span></div>
        <div class="am-pagamento-item__status"><span class="am-pagamento-status am-pagamento-status--${esc(item.status)}">${esc(item.status)}</span>
          ${ativo ? `<button type="button" class="botao-geral" data-pagamento-acao="cancelado" data-id-pagamento="${esc(item.id_pagamento)}">Cancelar</button><button type="button" class="botao-geral" data-pagamento-acao="estornado" data-id-pagamento="${esc(item.id_pagamento)}">Estornar</button>` : ""}
        </div>
        ${item.observacao ? `<small>${esc(item.observacao)}</small>` : ""}
      </article>`;
    }).join("");
  }

  async function carregarPagamentos(idCobranca, modoRegistrar = false) {
    const resumo = document.getElementById("resumoPagamentoCobranca");
    const lista = document.getElementById("listaPagamentosCobranca");
    if (resumo) resumo.innerHTML = "Carregando resumo...";
    if (lista) lista.innerHTML = '<div class="a-loading">Carregando pagamentos...</div>';
    try {
      const resposta = await fetch(`${PAGAMENTOS}&id_cobranca=${encodeURIComponent(idCobranca)}`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const json = await resposta.json();
      if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível listar pagamentos.");
      const cobranca = json.data?.cobranca || {};
      if (resumo) resumo.innerHTML = resumoCobranca(cobranca);
      renderizarPagamentos(json.data?.items || []);
      const form = document.getElementById("formRegistrarPagamentoCobranca");
      if (form) form.hidden = !modoRegistrar || cobranca.status === "cancelada" || Number(cobranca.saldo_restante) <= 0;
    } catch (erro) {
      if (lista) lista.innerHTML = '<div class="a-empty">Não foi possível carregar os pagamentos.</div>';
      avisar("erro", erro.message || "Não foi possível carregar os pagamentos.");
    }
  }

  async function enviarPagamento(url, corpo, mensagemPadrao) {
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!csrf) throw new Error("Token de segurança indisponível. Atualize a página.");
    const resposta = await fetch(url, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
    const json = await resposta.json();
    if (!resposta.ok || !json.ok) {
      const erro = new Error(json.user_msg || mensagemPadrao);
      erro.code = json.code || "";
      throw erro;
    }
    return json;
  }

  async function confirmarUniversal(mensagem, opcoes) {
    const confirmar = window.MensagemSistema?.confirmar;
    if (typeof confirmar !== "function") {
      avisar("erro", "Não foi possível abrir a confirmação de segurança.");
      return false;
    }
    return confirmar(mensagem, opcoes);
  }

  async function registrarPagamento(evento) {
    evento.preventDefault();
    const form = evento.currentTarget;
    const botao = document.getElementById("btnSalvarPagamento");
    if (botao) botao.disabled = true;
    try {
      const corpo = new FormData(form);
      await enviarPagamento(REGISTRAR_PAGAMENTO, corpo, "Não foi possível registrar o pagamento.");
      avisar("sucesso", "Pagamento registrado com sucesso.");
      await carregarCobrancas();
      await carregarPagamentos(cobrancaModalAtual, true);
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível registrar o pagamento.");
    } finally {
      if (botao) botao.disabled = false;
    }
  }

  async function alterarPagamento(idPagamento, status) {
    const confirmou = await confirmarUniversal(`Confirma ${status === "estornado" ? "o estorno" : "o cancelamento"} deste pagamento?`, {
      titulo: status === "estornado" ? "Confirmar estorno" : "Confirmar cancelamento",
      textoCancelar: "Voltar",
      textoConfirmar: status === "estornado" ? "Estornar pagamento" : "Cancelar pagamento",
    });
    if (!confirmou) return;
    try {
      const corpo = new FormData();
      corpo.append("id_pagamento", idPagamento);
      corpo.append("status", status);
      await enviarPagamento(ALTERAR_PAGAMENTO, corpo, "Não foi possível alterar o pagamento.");
      avisar("sucesso", status === "estornado" ? "Pagamento estornado com sucesso." : "Pagamento cancelado com sucesso.");
      await carregarCobrancas();
      await carregarPagamentos(cobrancaModalAtual, false);
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível alterar o pagamento.");
    }
  }

  async function cancelarCobranca(evento) {
    evento.preventDefault();
    const motivoCampo = document.getElementById("cancelamentoMotivo");
    const motivoLimpo = String(motivoCampo?.value || "").trim();
    if (!motivoLimpo) {
      avisar("aviso", "Informe um motivo para cancelar a cobrança.");
      return;
    }
    // O motivo permanece no modal até a confirmação universal; só então a operação irreversível é enviada.
    const confirmou = await confirmarUniversal("Confirma o cancelamento desta cobrança?", {
      titulo: "Confirmar cancelamento",
      textoCancelar: "Voltar",
      textoConfirmar: "Cancelar cobrança",
    });
    if (!confirmou) return;
    try {
      const corpo = new FormData();
      corpo.append("id_cobranca", cancelamentoCobrancaAtual);
      corpo.append("motivo", motivoLimpo);
      await enviarPagamento(CANCELAR_COBRANCA, corpo, "Não foi possível cancelar a cobrança.");
      avisar("sucesso", "Cobrança cancelada com sucesso.");
      const modal = document.getElementById("modalCancelarCobranca");
      if (typeof window.fecharModal === "function") window.fecharModal(modal);
      limparEstadoCancelamento();
      await carregarCobrancas();
    } catch (erro) {
      const tipoMensagem = erro.code === "COBRANCA_COM_PAGAMENTO_CONFIRMADO" ? "aviso" : "erro";
      avisar(tipoMensagem, erro.message || "Não foi possível cancelar a cobrança.");
    }
  }

  async function gerar(evento) {
    evento.preventDefault();
    const form = evento.currentTarget;
    const campo = document.getElementById("cobrancaEmpresa");
    const idEmpresa = String(campo?.value || "");
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!idEmpresa) return avisar("aviso", "Selecione uma empresa.");
    if (!csrf) return avisar("erro", "Token de segurança indisponível. Atualize a página.");
    const botao = document.getElementById("btnGerarCobranca");
    if (botao) botao.disabled = true;
    try {
      const corpo = new FormData();
      corpo.append("id_empresa", idEmpresa);
      const resposta = await fetch(GERAR, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const json = await resposta.json();
      if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível gerar a cobrança.");
      avisar("sucesso", json.user_msg || "Cobrança gerada com sucesso.");
      await carregarCobrancas();
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível gerar a cobrança.");
    } finally {
      if (botao) botao.disabled = false;
    }
  }

  function bindPagamentos() {
    document.addEventListener("click", evento => {
      const toggle = evento.target.closest('#cobrancas button[data-acao="toggle-menu-cobranca"]');
      if (toggle) {
        evento.preventDefault();
        evento.stopPropagation();
        if (menuCtrl.getOwnerCard?.() === toggle.closest(".agenda-card")) menuCtrl.fechar();
        else menuCtrl.toggle(toggle);
        return;
      }
      const botao = evento.target.closest("#cobrancas .cobranca-menu .agenda-menu-item, .cobranca-menu.menu-flutuante .agenda-menu-item");
      if (!botao) return;
      menuCtrl.fechar();
      if (botao.dataset.acao === "cancelar-cobranca") {
        abrirModalCancelamento(botao.dataset.idCobranca);
        return;
      }
      abrirModalPagamento(botao.dataset.idCobranca, botao.dataset.acao === "registrar-pagamento");
    });
    document.getElementById("listaPagamentosCobranca")?.addEventListener("click", evento => {
      const botao = evento.target.closest("button[data-pagamento-acao]");
      if (botao) alterarPagamento(botao.dataset.idPagamento, botao.dataset.pagamentoAcao);
    });
    document.getElementById("formRegistrarPagamentoCobranca")?.addEventListener("submit", registrarPagamento);
    document.getElementById("formCancelarCobranca")?.addEventListener("submit", cancelarCobranca);
    document.addEventListener("click", evento => {
      const fechar = evento.target.closest("[data-fechar-modal]");
      if (fechar?.closest("#modalCancelarCobranca")) limparEstadoCancelamento();
      if (!evento.target.closest("#cobrancas .agenda-acoes, #cobrancas .agenda-menu, .cobranca-menu.menu-flutuante")) menuCtrl.fechar();
    });
    document.addEventListener("keydown", evento => {
      if (evento.key === "Escape") menuCtrl.fechar();
      if (evento.key === "Escape" && document.getElementById("modalCancelarCobranca")?.classList.contains("ativo")) {
        limparEstadoCancelamento();
      }
    });
  }

  function fecharPainelFiltros() {
    const painel = document.getElementById("formFiltrosCobranca");
    const botao = document.getElementById("btnFiltrosCobranca");
    if (painel) painel.hidden = true;
    botao?.setAttribute("aria-expanded", "false");
  }

  function alternarPainelFiltros() {
    const painel = document.getElementById("formFiltrosCobranca");
    const botao = document.getElementById("btnFiltrosCobranca");
    if (!painel || !botao) return;
    const abrir = painel.hidden;
    fecharPainelFiltros();
    if (abrir) {
      painel.hidden = false;
      botao.setAttribute("aria-expanded", "true");
    }
  }

  function bindFiltros() {
    document.getElementById("btnFiltrosCobranca")?.addEventListener("click", alternarPainelFiltros);
    document.getElementById("pesquisar-cobrancas")?.addEventListener("input", () => {
      paginaAtual = 1;
      atualizarListagem();
    });
    document.getElementById("formFiltrosCobranca")?.addEventListener("submit", evento => {
      evento.preventDefault();
      filtrosCobranca = {
        id_empresa: filtrosCobranca.id_empresa,
        situacao: document.getElementById("filtroCobrancaSituacao")?.value || "todas",
        data_vencimento_de: document.getElementById("filtroCobrancaDataDe")?.value || "",
        data_vencimento_ate: document.getElementById("filtroCobrancaDataAte")?.value || "",
      };
      paginaAtual = 1;
      fecharPainelFiltros();
      carregarCobrancas();
    });
    document.getElementById("btnLimparFiltrosCobranca")?.addEventListener("click", () => {
      document.getElementById("formFiltrosCobranca")?.reset();
      filtrosCobranca = { id_empresa: filtrosCobranca.id_empresa, situacao: "todas", data_vencimento_de: "", data_vencimento_ate: "" };
      paginaAtual = 1;
      fecharPainelFiltros();
      carregarCobrancas();
    });
    document.addEventListener("click", evento => {
      if (!evento.target.closest("#seletorFiltrosCobranca")) fecharPainelFiltros();
    });
    document.addEventListener("keydown", evento => {
      if (evento.key === "Escape") fecharPainelFiltros();
    });
  }

  function iniciar() {
    if (window.__GERENCIAR_COBRANCAS_INIT__) return;
    const form = document.getElementById("formGerarCobranca");
    if (!form) return;
    window.__GERENCIAR_COBRANCAS_INIT__ = true;
    form.addEventListener("submit", gerar);
    bindSeletorEmpresa();
    bindPagamentos();
    bindFiltros();
    carregarEmpresas();
    carregarCobrancas();
  }

  if (window.__AUTH__) iniciar();
  document.addEventListener("amagenda:sessao-carregada", iniciar, { once: true });
})();
