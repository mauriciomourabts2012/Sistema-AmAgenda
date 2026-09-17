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
  const ALTERAR_STATUS = `${API}?path=superadmin/assinatura/status`;
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
    if (!['ativa', 'suspensa'].includes(status)) return "";

    const acaoPrincipal = status === "ativa" ? "suspender" : "reativar";
    const iconePrincipal = status === "ativa" ? "fa-pause" : "fa-play";
    const textoPrincipal = status === "ativa" ? "Suspender" : "Reativar";

    return `
      <div class="agenda-menu assinatura-menu" role="menu">
        <button class="agenda-menu-item" type="button" role="menuitem"
          data-acao="${acaoPrincipal}"
          data-id-assinatura="${esc(item.id_assinatura)}"
          data-id-empresa="${esc(item.id_empresa)}">
          <i class="fa-solid ${iconePrincipal}" aria-hidden="true"></i>
          ${textoPrincipal}
        </button>
        <div class="assinatura-menu-separador" role="separator"></div>
        <button class="agenda-menu-item danger" type="button" role="menuitem"
          data-acao="cancelar"
          data-id-assinatura="${esc(item.id_assinatura)}"
          data-id-empresa="${esc(item.id_empresa)}">
          <i class="fa-solid fa-ban" aria-hidden="true"></i>
          Cancelar assinatura
        </button>
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
            <span class="agenda-status ${statusClass(status)}">${esc(statusLabel(status))}</span>
            ${menuAcoes ? `<div class="agenda-acoes assinatura-acoes" aria-haspopup="menu">
              <button class="agenda-btn-acoes" type="button" data-acao="toggle-menu" aria-expanded="false" title="Ações" aria-label="Abrir ações da assinatura">
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
  }

  function abrirCancelamento(botao) {
    // O motivo é coletado separadamente para impedir cancelamento acidental e manter a justificativa auditável.
    idAssinaturaCancelamento = String(botao.dataset.idAssinatura || "");
    idEmpresaCancelamento = String(botao.dataset.idEmpresa || "");
    document.getElementById("cancelamentoAssinaturaId").value = idAssinaturaCancelamento;
    document.getElementById("cancelamentoAssinaturaEmpresaId").value = idEmpresaCancelamento;
    document.getElementById("cancelamentoAssinaturaMotivo").value = "";
    window.abrirModal?.("modalCancelarAssinatura");
    document.getElementById("cancelamentoAssinaturaMotivo")?.focus();
  }

  async function enviarStatus(idAssinatura, idEmpresa, acao, motivo = "") {
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!csrf) throw new Error("Token de segurança indisponível. Atualize a página.");
    const corpo = new FormData();
    corpo.append("id_assinatura", idAssinatura);
    corpo.append("id_empresa", idEmpresa);
    corpo.append("acao", acao);
    if (motivo) corpo.append("motivo", motivo);
    const resposta = await fetch(ALTERAR_STATUS, {
      method: "POST",
      body: corpo,
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json", "X-CSRF-Token": csrf },
    });
    const json = await resposta.json();
    if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível atualizar a assinatura.");
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

  async function cancelar(evento) {
    evento.preventDefault();
    const motivo = String(document.getElementById("cancelamentoAssinaturaMotivo")?.value || "").trim();
    if (!motivo) {
      avisar("aviso", "Informe um motivo para cancelar a assinatura.");
      return;
    }
    try {
      await enviarStatus(idAssinaturaCancelamento, idEmpresaCancelamento, "cancelar", motivo);
      avisar("sucesso", "Assinatura cancelada com sucesso.");
      window.fecharModal?.(document.getElementById("modalCancelarAssinatura"));
      limparCancelamento();
      await carregarAssinaturas();
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível cancelar a assinatura.");
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
      if (botao.dataset.acao === "cancelar") abrirCancelamento(botao);
      else alterarStatus(botao);
    });
    document.getElementById("formCancelarAssinatura")?.addEventListener("submit", cancelar);
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
