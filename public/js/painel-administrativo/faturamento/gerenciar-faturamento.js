(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const rotas = {
    resumo: `${API}?path=painel/faturamento/resumo`,
    cobrancas: `${API}?path=painel/faturamento/cobrancas`,
    pagamentos: `${API}?path=painel/faturamento/pagamentos`,
    regularizacao: `${API}?path=painel/faturamento/regularizacao/cobranca`,
    cancelar: `${API}?path=painel/faturamento/cancelar`,
    contratacao: `${API}?path=painel/faturamento/contratacao`,
    trocaPlano: `${API}?path=painel/faturamento/troca-plano`
  };
  const estado = { pagina: 1, pagamentoPagina: 1, totalPagamentos: 0, idCobranca: "", carregado: false, requisicao: 0, regularizando: false };
  const esc = valor => String(valor ?? "").replace(/[&<>'"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" }[c]));
  const br = valor => /^\d{4}-\d{2}-\d{2}/.test(String(valor || "")) ? String(valor).slice(0, 10).split("-").reverse().join("/") : "—";
  const moeda = valor => Number(valor || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const texto = valor => String(valor || "").replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase()) || "—";
  const avisar = (tipo, msg) => (window.MensagemSistema?.[tipo] || window.MensagemSistema?.info)?.(msg);
  const normalizar = v => String(v || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
  const statusClasse = status => `faturamento-status faturamento-status--${esc(normalizar(status || "pendente").replace(/\s+/g, "-"))}`;
  const estadoVisual = (titulo, detalhe, erro = false, icone = "fa-file-circle-exclamation") => `<div class="faturamento-estado${erro ? " faturamento-estado--erro" : ""}"><span class="faturamento-estado-icone" aria-hidden="true"><i class="fa-solid ${icone}"></i></span><div><strong>${esc(titulo)}</strong><p>${esc(detalhe)}</p></div></div>`;

  async function get(url) {
    const resposta = await fetch(url, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
    const json = await resposta.json().catch(() => ({}));
    if (!resposta.ok || !json.ok) throw new Error(json.user_msg || "Não foi possível carregar o faturamento.");
    return json.data || {};
  }
  function podeVer() {
    const auth = window.__AUTH__ || {};
    if (auth.permissoes && typeof window.usuarioPode === "function" && !window.usuarioPode("faturamento.visualizar")) return false;
    if (auth.perfil_nome || auth.perfil) return normalizar(auth.perfil_nome || auth.perfil) === "proprietario";
    return true;
  }
  function podePagar() {
    const auth = window.__AUTH__ || {};
    if (String(auth.tipo_usuario || "").toLowerCase() === "super_admin" && auth.modo_suporte === true) return false;
    return typeof window.usuarioPode === "function" && window.usuarioPode("faturamento.pagar");
  }
  function podeRegularizar(assinatura) {
    const auth = window.__AUTH__ || {};
    const perfil = normalizar(auth.perfil_nome || auth.perfil);
    return auth.modo_regularizacao === true && assinatura?.em_regularizacao === true && perfil === "proprietario";
  }
  function podeTrocar() {
    const auth = window.__AUTH__ || {};
    return normalizar(auth.perfil_nome || auth.perfil) === "proprietario" && auth.modo_suporte !== true
      && typeof window.usuarioPode === "function" && window.usuarioPode("faturamento.trocar_plano");
  }
  function htmlTrocaPendente(troca) {
    const pendente = troca?.pendente;
    if (!pendente || !podeTrocar()) return "";
    if (pendente.tipo === "upgrade") {
      const situacao = pendente.paga ? "Pagamento confirmado. Conclua para liberar o novo plano."
        : pendente.em_andamento ? "Pagamento em confirmação."
        : `Pague a cobrança proporcional de ${moeda(pendente.valor_proporcional)} na lista de cobranças. Válida até ${br(pendente.expira_em)}.`;
      return `<div class="faturamento-troca-pendente"><p><strong>${esc(troca.mensagem || "")}</strong></p><p>${esc(situacao)}</p><button class="botao-geral destaque" type="button" data-concluir-troca>${pendente.paga ? "Concluir upgrade" : "Verificar pagamento"}</button>${pendente.paga || pendente.em_andamento ? "" : '<button class="botao-geral" type="button" data-cancelar-troca>Cancelar upgrade</button>'}</div>`;
    }
    return `<div class="faturamento-troca-pendente"><p><strong>${esc(troca.mensagem || "")}</strong></p><p>Até lá, o plano e os benefícios atuais continuam valendo.</p><button class="botao-geral" type="button" data-cancelar-troca>Cancelar downgrade</button></div>`;
  }
  function podeCancelar(assinatura) {
    const auth = window.__AUTH__ || {};
    return !assinatura?.data_fim && ["ativa", "suspensa"].includes(assinatura?.status)
      && assinatura?.motivo_suspensao !== "contratacao_pendente"
      && normalizar(auth.perfil_nome || auth.perfil) === "proprietario"
      && auth.modo_suporte !== true
      && typeof window.usuarioPode === "function" && window.usuarioPode("faturamento.cancelar");
  }
  function renderAssinatura(assinatura, erro = false) {
    const el = document.getElementById("faturamentoAssinatura"); if (!el) return;
    if (!assinatura) { el.innerHTML = erro ? estadoVisual("Não foi possível carregar sua assinatura.", "Tente novamente mais tarde.", true) : window.__AUTH__?.modo_regularizacao === true ? estadoVisual("Assinatura suspensa.", "Regularize o faturamento para continuar usando o sistema.", false, "fa-crown") : estadoVisual("Você ainda não possui uma assinatura ativa.", "Quando houver uma assinatura, os detalhes do plano aparecerão aqui.", false, "fa-crown"); return; }
    const acaoRegularizacao = podeRegularizar(assinatura) ? '<button class="botao-geral destaque" type="button" data-regularizar-trial><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>Gerar cobrança de regularização</button>' : "";
    const troca = estado.troca;
    const acaoTrocar = podeTrocar() && assinatura.status === "ativa" && !assinatura.data_fim && troca && !troca.pendente && troca.disponivel
      ? '<button class="botao-geral destaque" type="button" data-alterar-plano><i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i>Alterar plano</button>' : "";
    const trocaPendente = assinatura.status === "ativa" ? htmlTrocaPendente(troca) : "";
    const acaoCancelar = podeCancelar(assinatura) ? '<button class="botao-geral" type="button" data-cancelar-assinatura>Cancelar assinatura</button>' : "";
    const encerramento = assinatura.data_fim ? `<div><span>Encerramento</span><strong>${br(assinatura.data_fim)}</strong></div>` : "";
    const rotuloPlano = assinatura.status === "cancelada" ? "Último plano contratado" : "Plano atual";
    const situacao = assinatura.motivo_suspensao === "contratacao_pendente" ? "Aguardando pagamento da contratação"
      : assinatura.data_fim && assinatura.status === "ativa" ? `Cancelamento programado: acesso até ${br(assinatura.data_fim)}` : texto(assinatura.status);
    el.innerHTML = `<div class="faturamento-plano-identidade"><span class="faturamento-plano-icone" aria-hidden="true"><i class="fa-solid fa-crown"></i></span><div class="faturamento-assinatura-plano"><span class="faturamento-assinatura-rotulo">${rotuloPlano}</span><strong class="faturamento-assinatura-nome">${esc(assinatura.plano || "Plano não identificado")}</strong><div class="faturamento-assinatura-preco"><b>${moeda(assinatura.valor_contratado)}</b><small>/ ${esc(texto(assinatura.periodicidade))}</small></div></div></div><div><span>Vencimento</span><strong>Dia ${esc(assinatura.dia_vencimento)}</strong></div><div><span>Início</span><strong>${br(assinatura.data_inicio)}</strong></div>${encerramento}<span class="${statusClasse(assinatura.status)}">${esc(situacao)}</span>${acaoRegularizacao}${acaoTrocar}${acaoCancelar}${trocaPendente}`;
    agendarVerificacaoTroca();
  }
  function renderIndicadores(indicadores) {
    const el = document.getElementById("faturamentoIndicadores"); if (!el) return;
    if (!indicadores) { el.innerHTML = estadoVisual("Resumo financeiro indisponível.", "Não foi possível consultar os valores neste momento.", true); return; }
    const itens = [
      ["proximo", "fa-calendar-day", "Próximo vencimento", br(indicadores.proximo_vencimento), indicadores.proximo_vencimento ? "Vencimento mais próximo." : "Nenhum vencimento agendado."],
      ["receber", "fa-arrow-trend-up", "A receber", moeda(indicadores.total_a_receber), "Saldo das cobranças."],
      ["vencido", "fa-circle-exclamation", "Vencido", moeda(indicadores.total_vencido), "Valores em atraso."],
      ["recebido", "fa-circle-check", "Recebido", moeda(indicadores.total_pago_confirmado), "Pagamentos confirmados."]
    ];
    el.innerHTML = itens.map(([tipo, icone, rotulo, valor, detalhe]) => `<article class="faturamento-indicador faturamento-indicador--${tipo}"><span class="faturamento-indicador-icone" aria-hidden="true"><i class="fa-solid ${icone}"></i></span><span>${rotulo}</span><strong>${esc(valor)}</strong><small>${detalhe}</small></article>`).join("");
  }
  function paramsCobrancas() {
    const p = new URLSearchParams({ page: String(estado.pagina), limit: "20" });
    const q = document.getElementById("pesquisar-faturamento")?.value.trim(); const situacao = document.getElementById("filtroSituacaoFaturamento")?.value;
    const de = document.getElementById("filtroVencimentoDe")?.value; const ate = document.getElementById("filtroVencimentoAte")?.value;
    if (q) p.set("q", q); if (situacao) p.set("situacao", situacao); if (de) p.set("vencimento_de", de); if (ate) p.set("vencimento_ate", ate); return p;
  }
  function renderCobrancas(data) {
    const lista = document.getElementById("listaCobrancasFaturamento"); if (!lista) return;
    const items = data.items || [];
    const mudarPagina = destino => { estado.pagina = destino; carregarCobrancas(); };
    if (!items.length) { lista.innerHTML = `<div class="faturamento-estado faturamento-estado--lista"><span class="faturamento-estado-icone" aria-hidden="true"><i class="fa-solid fa-file-invoice"></i></span><div><strong>Nenhuma cobrança encontrada.</strong><p>Suas cobranças aparecerão aqui quando estiverem disponíveis.</p></div></div>`; renderPaginacao(data, "paginacaoFaturamento", mudarPagina); return; }
    lista.innerHTML = items.map(item => {
      const elegivel = podePagar() && item.status === "pendente" && Number(item.saldo_restante) > 0;
      const acao = elegivel ? `<button class="botao-geral destaque faturamento-pagar-agora" type="button" data-pagar-cobranca="${esc(item.id_cobranca)}" data-pagar-valor="${esc(item.saldo_restante)}"><i class="fa-brands fa-pix" aria-hidden="true"></i>Pagar agora</button>` : "";
      return `<article class="agenda-card faturamento-cobranca-card"><div class="faturamento-cobranca-topo"><strong>${esc(item.plano || "Plano não identificado")}</strong><span class="${statusClasse(item.situacao)}">${esc(texto(item.situacao))}</span></div><div class="faturamento-cobranca-detalhes"><div><span>Valor</span><strong>${moeda(item.valor)}</strong></div><div><span>Período</span><strong>${br(item.periodo_inicio)} a ${br(item.periodo_fim)}</strong></div><div><span>Vencimento</span><strong>${br(item.data_vencimento)}</strong></div></div><div class="faturamento-cobranca-rodape"><div class="faturamento-cobranca-valores"><span>Pago: <strong>${moeda(item.total_pago_confirmado)}</strong></span><span>Saldo: <strong>${moeda(item.saldo_restante)}</strong></span></div>${acao}</div></article>`;
    }).join("");
    renderPaginacao(data, "paginacaoFaturamento", mudarPagina);
  }
  function renderPaginacao(data, id, aoMudarPagina) {
    const el = document.getElementById(id); if (!el) return; el.replaceChildren(); const total = Number(data.total || 0), page = Number(data.page || 1), pages = Number(data.total_pages || 0); if (!total || pages <= 1) return;
    [["Anterior", page - 1], ["Próximo", page + 1]].forEach(([label, destino]) => { const b = document.createElement("button"); b.type = "button"; b.className = "btn-pag"; b.textContent = label; b.disabled = destino < 1 || destino > pages; b.addEventListener("click", () => aoMudarPagina(destino)); el.append(b); });
  }
  async function carregarCobrancas() { const lista = document.getElementById("listaCobrancasFaturamento"); if (lista) lista.innerHTML = '<div class="a-loading">Carregando cobranças...</div>'; const req = ++estado.requisicao; try { const data = await get(`${rotas.cobrancas}&${paramsCobrancas()}`); if (req === estado.requisicao) renderCobrancas(data); } catch (erro) { if (req !== estado.requisicao) return; if (lista) lista.innerHTML = `<div class="faturamento-estado faturamento-estado--lista faturamento-estado--erro"><span class="faturamento-estado-icone" aria-hidden="true"><i class="fa-solid fa-file-circle-exclamation"></i></span><div><strong>Não foi possível carregar as cobranças.</strong><p>Tente novamente mais tarde ou verifique sua conexão.</p></div></div>`; avisar("erro", erro.message); } }
  async function regularizarTrial(botao) {
    if (estado.regularizando) return;
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) { avisar("erro", "Atualize a página para renovar sua sessão."); return; }
    estado.regularizando = true;
    botao.disabled = true;
    botao.textContent = "Gerando cobrança...";
    try {
      const resposta = await fetch(rotas.regularizacao, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const retorno = await resposta.json().catch(() => ({}));
      if (!resposta.ok || retorno.ok !== true) throw new Error(retorno.user_msg || "Não foi possível gerar a cobrança de regularização.");
      avisar("sucesso", retorno.user_msg || "Cobrança de regularização disponível.");
      estado.pagina = 1;
      await carregarCobrancas();
    } catch (erro) {
      avisar("erro", erro.message);
    } finally {
      estado.regularizando = false;
      botao.disabled = false;
      botao.innerHTML = '<i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>Gerar cobrança de regularização';
    }
  }
  function renderPagamentos(data) { const lista = document.getElementById("listaPagamentosFaturamento"); if (!lista) return; const items = data.items || []; lista.innerHTML = items.length ? items.map(item => `<article class="faturamento-pagamento-item"><div><strong>${br(item.data_pagamento)}</strong><span>${moeda(item.valor_pago)} · ${esc(texto(item.forma_pagamento))}</span></div><span class="${statusClasse(item.status)}">${esc(texto(item.status))}</span></article>`).join("") : '<div class="a-empty">Nenhum pagamento registrado para esta cobrança.</div>'; renderPaginacao(data, "paginacaoPagamentosFaturamento", destino => { estado.pagamentoPagina = destino; carregarPagamentos(); }); }
  async function carregarPagamentos() { const resumo = document.getElementById("resumoPagamentoFaturamento"); const lista = document.getElementById("listaPagamentosFaturamento"); if (resumo) resumo.textContent = "Carregando..."; if (lista) lista.innerHTML = '<div class="a-loading">Carregando pagamentos...</div>'; try { const data = await get(`${rotas.pagamentos}&id_cobranca=${encodeURIComponent(estado.idCobranca)}&page=${estado.pagamentoPagina}&limit=20`); if (resumo) resumo.innerHTML = `<span>Pagamentos da cobrança</span><strong>${data.total || 0} registro(s)</strong>`; renderPagamentos(data); } catch (erro) { if (lista) lista.innerHTML = '<div class="a-empty">Não foi possível carregar os pagamentos.</div>'; avisar("erro", erro.message); } }
  function abrirPagamentos(id) { estado.idCobranca = String(id || ""); estado.pagamentoPagina = 1; window.abrirModal?.("modalPagamentosFaturamento"); carregarPagamentos(); }
  function podeContratar() {
    const auth = window.__AUTH__ || {};
    return normalizar(auth.perfil_nome || auth.perfil) === "proprietario" && auth.modo_suporte !== true
      && typeof window.usuarioPode === "function" && window.usuarioPode("faturamento.contratar");
  }
  function renderContratacao(contratacao) {
    const secao = document.getElementById("faturamentoContratacao");
    const el = document.getElementById("faturamentoContratacaoConteudo");
    estado.contratacao = contratacao || null;
    if (!secao || !el) return;
    // Só há seção quando existe recontratação aplicável: contratação pendente, planos para contratar
    // ou um impedimento explicado pelo servidor. Contrato vigente (ativo, teste ou inadimplência) chega como null.
    const temPlanos = Array.isArray(contratacao?.planos) && contratacao.planos.length > 0;
    const aplicavel = !!contratacao && (!!contratacao.pendente || temPlanos || String(contratacao.mensagem || "").trim() !== "");
    if (!aplicavel || !podeContratar()) { secao.hidden = true; el.innerHTML = ""; return; }
    secao.hidden = false;
    const pendente = contratacao.pendente;
    const detalhes = pendente ? `<div><span>Plano escolhido</span><strong>${esc(pendente.plano || "Plano")}</strong></div><div><span>Valor do período</span><strong>${moeda(pendente.valor)} / ${esc(texto(pendente.periodicidade))}</strong></div>${pendente.data_vencimento ? `<div><span>Vencimento</span><strong>${br(pendente.data_vencimento)}</strong></div>` : ""}` : "";
    const aguardando = !!pendente && !pendente.paga;
    const concluir = pendente ? `<button class="botao-geral destaque" type="button" data-concluir-contratacao>${pendente.paga ? "Concluir contratação" : "Verificar pagamento"}</button>` : "";
    const temPlanosPublicos = Array.isArray(contratacao.planos) && contratacao.planos.length > 0;
    const contratar = temPlanosPublicos ? `<button class="botao-geral${pendente ? "" : " destaque"}" type="button" data-abrir-contratacao>${pendente ? "Escolher outro plano" : "Contratar novamente"}</button>` : "";
    const cartao = contratacao.cartao_sincronizacao_pendente ? "<p><small>O cartão do contrato anterior aguarda confirmação de encerramento. Até lá, a contratação é paga via PIX; o cartão poderá ser usado depois dessa confirmação.</small></p>" : "";
    const aviso = aguardando
      ? `<header class="faturamento-contratacao-aviso"><h3>Nova contratação aguardando pagamento</h3><p>Realize o pagamento da primeira mensalidade por PIX ou cartão de crédito na seção abaixo. O acesso será liberado após a confirmação do pagamento.</p></header>`
      : `<p><strong>${esc(contratacao.mensagem || "")}</strong></p>`;
    el.innerHTML = `${aviso}${detalhes}${cartao}${concluir}${contratar}`;
  }
  function textoLimiteContratacao(valor, singular, plural) {
    // Mesma redação do cartão de plano do site público (site-amagenda.js).
    const limite = Number(valor || 0);
    return `Até ${limite.toLocaleString("pt-BR")} ${limite === 1 ? singular : plural}`;
  }
  function atualizarResumoContratacao() {
    const resumo = document.getElementById("resumoContratarFaturamento");
    const botao = document.getElementById("confirmarContratarFaturamento");
    const selecionado = document.querySelector('#planosContratarFaturamento input[name="id_plano"]:checked');
    const plano = (estado.contratacao?.planos || []).find(item => String(item.id_plano) === String(selecionado?.value || ""));
    if (!plano || plano.disponivel === false) {
      if (resumo) resumo.textContent = "Selecione um plano compatível para gerar a cobrança do primeiro período.";
      if (botao) botao.disabled = true;
      return;
    }
    if (resumo) resumo.textContent = `Você selecionou ${plano.nome}: ${moeda(plano.valor)} por ${texto(plano.periodicidade)}. A cobrança será gerada após a confirmação.`;
    if (botao) botao.disabled = false;
  }
  function criarOpcaoContratacao(plano, selecionado, nomeCampo = "id_plano", rotuloTipo = "", atual = false) {
    const disponivel = plano.disponivel !== false && !atual;
    const cartao = document.createElement("label");
    cartao.className = "faturamento-contratacao-plano"
      + (atual ? " faturamento-contratacao-plano--atual" : disponivel ? "" : " faturamento-contratacao-plano--indisponivel");
    const radio = document.createElement("input");
    radio.type = "radio";
    radio.name = nomeCampo;
    radio.value = String(plano.id_plano);
    radio.checked = selecionado && disponivel;
    radio.disabled = !disponivel;
    if (nomeCampo === "id_plano") radio.addEventListener("change", atualizarResumoContratacao);
    const conteudo = document.createElement("span");
    conteudo.className = "faturamento-contratacao-plano-conteudo";
    const cabecalho = document.createElement("span");
    cabecalho.className = "faturamento-contratacao-plano-cabecalho";
    const titulo = document.createElement("strong");
    titulo.textContent = (plano.nome || "Plano") + (rotuloTipo ? ` — ${rotuloTipo}` : "");
    cabecalho.append(titulo);
    if (atual) {
      const selo = document.createElement("span");
      selo.className = "faturamento-contratacao-plano-selo";
      selo.textContent = "Plano atual";
      cabecalho.append(selo);
    }
    const preco = document.createElement("span");
    preco.className = "faturamento-contratacao-plano-preco";
    preco.textContent = `${moeda(plano.valor)} / ${texto(plano.periodicidade)}`;
    const descricao = document.createElement("small");
    descricao.className = "faturamento-contratacao-plano-descricao";
    descricao.textContent = plano.descricao || "Plano disponível para contratação.";
    const equipe = document.createElement("small");
    equipe.className = "faturamento-contratacao-plano-recursos";
    equipe.textContent = [
      textoLimiteContratacao(plano.limite_usuarios, "usuário", "usuários"),
      textoLimiteContratacao(plano.limite_proprietarios, "proprietário", "proprietários"),
      textoLimiteContratacao(plano.limite_profissionais, "profissional", "profissionais"),
      textoLimiteContratacao(plano.limite_recepcionistas, "recepcionista", "recepcionistas")
    ].join(" · ");
    const recursos = document.createElement("small");
    recursos.className = "faturamento-contratacao-plano-recursos";
    recursos.textContent = [
      textoLimiteContratacao(plano.limite_servicos, "serviço", "serviços"),
      textoLimiteContratacao(plano.limite_agendamentos, "agendamento", "agendamentos"),
      Number(plano.agenda_online) === 1 ? "Agenda Online incluída" : "Agenda Online não incluída"
    ].join(" · ");
    conteudo.append(cabecalho, preco, descricao, equipe, recursos);
    if (!disponivel && !atual) {
      const motivo = document.createElement("small");
      motivo.className = "faturamento-contratacao-plano-motivo";
      motivo.textContent = plano.motivo_indisponibilidade || "Este plano não está disponível para a estrutura atual da empresa.";
      conteudo.append(motivo);
    }
    cartao.append(radio, conteudo);
    return cartao;
  }
  // Resumo do plano atual exibido nos modais de troca e cancelamento.
  function preencherPlanoAtual() {
    const assinatura = estado.assinatura || {};
    const vencimento = estado.indicadores?.proximo_vencimento || estado.cancelamento?.data_fim_periodo_pago || "";
    const valores = {
      nome: assinatura.plano || "—",
      valor: moeda(assinatura.valor_contratado),
      periodicidade: texto(assinatura.periodicidade),
      inicio: br(assinatura.data_inicio),
      vencimento: vencimento ? br(vencimento) : (assinatura.dia_vencimento ? `Dia ${assinatura.dia_vencimento}` : "—")
    };
    document.querySelectorAll("[data-plano-atual]").forEach(el => { el.textContent = valores[el.dataset.planoAtual] ?? "—"; });
  }
  // Abertura/fechamento locais: esta página não expõe window.abrirModal/fecharModal.
  const MODAIS_FATURAMENTO = ["modalContratarFaturamento", "modalTrocaPlano", "modalCancelarFaturamento"];
  function abrirModalFaturamento(id) {
    const modal = document.getElementById(id);
    if (!modal) return false;
    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");
    return true;
  }
  function fecharModalFaturamento(id) {
    const modal = document.getElementById(id);
    modal?.classList.remove("ativo");
    modal?.setAttribute("aria-hidden", "true");
    if (id === "modalCancelarFaturamento") document.getElementById("formCancelarFaturamento")?.reset();
    if (id === "modalTrocaPlano") estado.trocaSelecionada = null;
  }
  const fecharContratacao = () => fecharModalFaturamento("modalContratarFaturamento");
  function abrirContratacao() {
    const lista = document.getElementById("planosContratarFaturamento");
    const planos = estado.contratacao?.planos || [];
    if (!lista || !document.getElementById("modalContratarFaturamento") || !planos.length) { avisar("aviso", "Nenhum plano disponível para contratação no momento."); return; }
    const atual = estado.contratacao?.pendente?.id_plano;
    const primeiroDisponivel = planos.find(plano => plano.disponivel !== false);
    lista.replaceChildren(...planos.map(plano => criarOpcaoContratacao(
      plano,
      atual ? Number(plano.id_plano) === Number(atual) && plano.disponivel !== false : plano === primeiroDisponivel
    )));
    atualizarResumoContratacao();
    abrirModalFaturamento("modalContratarFaturamento");
  }
  async function postarContratacao(campos) {
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) throw new Error("Atualize a página para renovar sua sessão.");
    const corpo = new FormData();
    Object.entries(campos).forEach(([chave, valor]) => corpo.append(chave, String(valor)));
    const resposta = await fetch(rotas.contratacao, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
    const json = await resposta.json().catch(() => ({}));
    if (!resposta.ok || json.ok !== true) throw new Error(json.user_msg || "Não foi possível concluir a contratação.");
    return json;
  }
  async function enviarContratacao(evento) {
    evento.preventDefault();
    const form = evento.target; // listener delegado em document
    const botao = evento.submitter || form.querySelector('button[type="submit"]');
    if (botao?.disabled) return;
    const idPlano = String(form.elements.id_plano?.value || "");
    if (!/^[1-9]\d*$/.test(idPlano)) { avisar("aviso", "Escolha um plano."); return; }
    if (botao) botao.disabled = true;
    try {
      const json = await postarContratacao({ acao: "contratar", id_plano: idPlano });
      fecharContratacao();
      avisar("sucesso", json.user_msg || "Cobrança de contratação gerada.");
      if (json.code === "CONTRATACAO_ATIVADA") { window.location.reload(); return; }
      estado.carregado = false;
      await iniciar();
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível concluir a contratação.");
    } finally {
      if (botao) botao.disabled = false;
    }
  }
  async function concluirContratacao(botao) {
    if (botao?.disabled) return;
    if (botao) botao.disabled = true;
    try {
      const json = await postarContratacao({ acao: "concluir" });
      avisar("sucesso", json.user_msg || "Nova contratação ativada.");
      window.location.reload();
    } catch (erro) {
      avisar("aviso", erro.message || "O pagamento ainda não foi confirmado.");
      if (botao) botao.disabled = false;
    }
  }
  document.addEventListener("click", evento => {
    if (evento.target.closest("[data-abrir-contratacao]")) abrirContratacao();
    const fechar = evento.target.closest("[data-fechar-modal]");
    const modalFechar = fechar?.closest(".modal-geral");
    if (modalFechar && MODAIS_FATURAMENTO.includes(modalFechar.id)) fecharModalFaturamento(modalFechar.id);
    const concluir = evento.target.closest("[data-concluir-contratacao]");
    if (concluir) concluirContratacao(concluir);
  });
  document.addEventListener("keydown", evento => {
    if (evento.key !== "Escape") return;
    MODAIS_FATURAMENTO.forEach(id => { if (document.getElementById(id)?.classList.contains("ativo")) fecharModalFaturamento(id); });
  });
  document.addEventListener("submit", evento => {
    if (evento.target.id === "formContratarFaturamento") enviarContratacao(evento);
  });
  function renderPreviaTroca() {
    const destino = document.getElementById("previaTrocaPlano");
    const titulo = document.getElementById("tituloPreviaTrocaPlano");
    const textoPrevia = document.getElementById("textoPreviaTrocaPlano");
    const botao = document.getElementById("confirmarTrocaPlano");
    const opcao = estado.trocaSelecionada;
    if (!destino || !titulo || !textoPrevia) return;
    if (!opcao || !opcao.disponivel) { destino.hidden = true; textoPrevia.replaceChildren(); if (botao) botao.disabled = true; return; }
    // Valores exibidos exatamente como calculados pelo servidor (Fase 4).
    const p = opcao.previa || {};
    const forte = valor => { const b = document.createElement("b"); b.textContent = valor; return b; };
    if (p.tipo === "upgrade") {
      titulo.textContent = "Troca de plano - Upgrade";
      const partes = Number(opcao.valor_confirmacao) > 0
        ? ["Será cobrado agora ", forte(moeda(p.valor_proporcional)), `, valor proporcional aos ${p.dias_restantes} dias restantes do seu ciclo atual (${br(p.ciclo_inicio)} a ${br(p.ciclo_fim)}). `]
        : ["Não há valor proporcional a pagar neste ciclo. "];
      textoPrevia.replaceChildren(...partes, "O novo valor mensal passará a ser ", forte(moeda(p.valor_novo)), ` a partir de ${br(p.proxima_renovacao)}.`);
    } else {
      titulo.textContent = "Troca de plano - Downgrade";
      textoPrevia.replaceChildren(`O plano ${p.plano_novo || opcao.nome} passa a valer em `, forte(br(p.data_efetivacao)),
        ". Até lá, o plano e os benefícios atuais continuam valendo. O novo valor mensal será ", forte(moeda(p.valor_novo)), ", sem estorno.");
    }
    destino.hidden = false;
    if (botao) botao.disabled = false;
  }
  function abrirTrocaPlano() {
    const lista = document.getElementById("opcoesTrocaPlano");
    const opcoes = estado.troca?.opcoes || [];
    if (!lista || !opcoes.length) { avisar("aviso", estado.troca?.mensagem || "Não há planos disponíveis para troca."); return; }
    estado.trocaSelecionada = null;
    const atual = estado.troca?.plano_atual_dados;
    const cartoes = opcoes.map(opcao => ({ plano: { ...opcao, motivo_indisponibilidade: opcao.previa?.mensagem || "Este plano não está disponível para troca." }, atual: false }));
    if (atual) cartoes.push({ plano: atual, atual: true });
    // Mesma ordem do site público (plano.ref), com o plano atual em sua posição.
    cartoes.sort((a, b) => (Number(a.plano.ordem) || 0) - (Number(b.plano.ordem) || 0) || Number(a.plano.id_plano) - Number(b.plano.id_plano));
    lista.replaceChildren(...cartoes.map(item => criarOpcaoContratacao(item.plano, false, "id_plano_troca", "", item.atual)));
    preencherPlanoAtual();
    renderPreviaTroca();
    abrirModalFaturamento("modalTrocaPlano");
  }
  async function postarTroca(campos) {
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) throw new Error("Atualize a página para renovar sua sessão.");
    const corpo = new FormData();
    Object.entries(campos).forEach(([chave, valor]) => corpo.append(chave, String(valor)));
    const resposta = await fetch(rotas.trocaPlano, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
    const json = await resposta.json().catch(() => ({}));
    if (!resposta.ok || json.ok !== true) { const erro = new Error(json.user_msg || "Não foi possível concluir a troca de plano."); erro.code = json.code; erro.data = json.data; throw erro; }
    return json;
  }
  async function confirmarTroca(botao) {
    const opcao = estado.trocaSelecionada;
    if (!opcao?.disponivel || botao.disabled) return;
    botao.disabled = true;
    try {
      const json = await postarTroca({ acao: "solicitar", id_plano: opcao.id_plano, valor_confirmado: opcao.valor_confirmacao });
      fecharModalFaturamento("modalTrocaPlano");
      avisar("sucesso", json.user_msg || "Troca de plano registrada.");
      if (json.code === "TROCA_PLANO_ATIVADA") { window.location.reload(); return; }
      estado.carregado = false;
      await iniciar();
    } catch (erro) {
      if (erro.code === "VALOR_ALTERADO" && erro.data?.previa) {
        const previa = erro.data.previa;
        opcao.previa = previa;
        opcao.valor_confirmacao = previa.tipo === "upgrade" ? previa.valor_proporcional_centavos : Math.round(Number(previa.valor_novo) * 100);
        renderPreviaTroca();
      }
      avisar("aviso", erro.message);
      botao.disabled = !estado.trocaSelecionada?.disponivel;
    }
  }
  async function concluirTroca(botao, silencioso = false) {
    if (botao?.disabled) return;
    if (botao) botao.disabled = true;
    try {
      const json = await postarTroca({ acao: "concluir" });
      avisar("sucesso", json.user_msg || "Upgrade concluído.");
      window.location.reload();
    } catch (erro) {
      if (!silencioso) avisar("aviso", erro.message);
      if (botao) botao.disabled = false;
    }
  }
  async function cancelarTroca(botao) {
    if (botao?.disabled) return;
    if (botao) botao.disabled = true;
    try {
      const json = await postarTroca({ acao: "cancelar" });
      avisar("sucesso", json.user_msg || "Troca de plano cancelada.");
      estado.carregado = false;
      await iniciar();
    } catch (erro) {
      avisar("erro", erro.message);
      if (botao) botao.disabled = false;
    }
  }
  // Upgrade aguardando pagamento: verificação discreta enquanto a aba está visível (a ativação é idempotente no servidor).
  function agendarVerificacaoTroca() {
    clearInterval(estado.timerTroca);
    const pendente = estado.troca?.pendente;
    if (!pendente || pendente.tipo !== "upgrade" || !podeTrocar()) return;
    estado.timerTroca = setInterval(() => {
      if (document.visibilityState === "visible" && document.getElementById("faturamento")?.classList.contains("ativa")) concluirTroca(null, true);
    }, 30000);
  }
  document.addEventListener("click", evento => {
    if (evento.target.closest("[data-alterar-plano]")) abrirTrocaPlano();
    const concluir = evento.target.closest("[data-concluir-troca]");
    if (concluir) concluirTroca(concluir);
    const cancelar = evento.target.closest("[data-cancelar-troca]");
    if (cancelar) cancelarTroca(cancelar);
    const confirmar = evento.target.closest("#confirmarTrocaPlano");
    if (confirmar) confirmarTroca(confirmar);
  });
  document.addEventListener("change", evento => {
    if (evento.target.name !== "id_plano_troca") return;
    estado.trocaSelecionada = (estado.troca?.opcoes || []).find(opcao => String(opcao.id_plano) === evento.target.value) || null;
    renderPreviaTroca();
  });
  function atualizarContadorMotivo() {
    const campo = document.getElementById("motivoCancelarFaturamento");
    const contador = document.getElementById("contadorMotivoCancelarFaturamento");
    if (campo && contador) contador.textContent = `${campo.value.length} / 500`;
  }
  function abrirCancelamento() {
    const form = document.getElementById("formCancelarFaturamento");
    const opcao = document.getElementById("opcaoCancelarFimPeriodo");
    const detalhe = document.getElementById("detalheCancelarFimPeriodo");
    if (!form || !opcao || !detalhe) return;
    form.reset();
    atualizarContadorMotivo();
    const previa = estado.cancelamento;
    const radio = opcao.querySelector("input");
    let elegivel = true;
    if (previa && previa.pode_fim_periodo_pago && previa.data_fim_periodo_pago) {
      const data = document.createElement("b");
      data.textContent = br(previa.data_fim_periodo_pago);
      detalhe.replaceChildren("Você continuará com acesso ao plano atual até o dia ", data, ". Não será gerada nova cobrança após essa data.");
    } else if (previa) {
      elegivel = false;
      detalhe.textContent = "Indisponível: esta opção exige assinatura paga e ativa com um período integralmente pago.";
    } else {
      detalhe.textContent = "A data de encerramento será confirmada pelo servidor. Não será gerada nova cobrança após essa data.";
    }
    radio.disabled = !elegivel;
    opcao.classList.toggle("faturamento-cancelamento-opcao--indisponivel", !elegivel);
    preencherPlanoAtual();
    abrirModalFaturamento("modalCancelarFaturamento");
  }
  document.addEventListener("input", evento => { if (evento.target.id === "motivoCancelarFaturamento") atualizarContadorMotivo(); });
  async function enviarCancelamento(evento) {
    evento.preventDefault();
    const form = evento.target; // listener delegado em document
    const botao = evento.submitter || form.querySelector('button[type="submit"]');
    if (botao?.disabled) return;
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) { avisar("erro", "Atualize a página para renovar sua sessão."); return; }
    const motivo = String(form.elements.motivo?.value || "").trim();
    const modalidade = String(form.elements.modalidade_cancelamento?.value || "");
    if (!["imediato", "fim_periodo_pago"].includes(modalidade)) { avisar("aviso", "Escolha a modalidade do cancelamento."); return; }
    if (!motivo) { avisar("aviso", "Informe o motivo do cancelamento."); return; }
    if (botao) botao.disabled = true;
    try {
      const corpo = new FormData();
      corpo.append("motivo", motivo);
      corpo.append("modalidade_cancelamento", modalidade);
      const resposta = await fetch(rotas.cancelar, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const json = await resposta.json().catch(() => ({}));
      if (!resposta.ok || json.ok !== true) throw new Error(json.user_msg || "Não foi possível cancelar a assinatura.");
      fecharModalFaturamento("modalCancelarFaturamento");
      avisar("sucesso", modalidade === "fim_periodo_pago"
        ? `Cancelamento programado. O acesso termina após ${br(json.data?.data_fim)}.`
        : "Assinatura cancelada. O histórico financeiro permanece disponível.");
      estado.carregado = false;
      await iniciar();
      if (modalidade === "imediato") window.location.reload();
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível cancelar a assinatura.");
    } finally {
      if (botao) botao.disabled = false;
    }
  }
  async function iniciar() { const aba = document.getElementById("faturamento"); if (!aba || !podeVer()) { if (aba) aba.hidden = true; return; } try { const data = await get(rotas.resumo); estado.troca = data.troca || null; estado.cancelamento = data.cancelamento || null; estado.assinatura = data.assinatura || null; estado.indicadores = data.indicadores || null; renderAssinatura(data.assinatura); renderContratacao(data.contratacao); renderIndicadores(data.indicadores || {}); estado.carregado = true; } catch (erro) { renderAssinatura(null, true); renderContratacao(null); renderIndicadores(null); avisar("erro", erro.message); } await carregarCobrancas(); }
  document.addEventListener("click", evento => { const botao = evento.target.closest("[data-regularizar-trial]"); if (botao) regularizarTrial(botao); });
  document.addEventListener("click", evento => {
    if (evento.target.closest("[data-cancelar-assinatura]")) abrirCancelamento();
  });
  document.addEventListener("submit", evento => {
    if (evento.target.id === "formCancelarFaturamento") enviarCancelamento(evento);
  });
  document.addEventListener("DOMContentLoaded", () => { document.addEventListener("amagenda:painel-aba-alterada", e => { if (e.detail?.aba === "faturamento" && !estado.carregado) iniciar(); }); document.addEventListener("amagenda:sessao-carregada", () => { if (document.getElementById("faturamento")?.classList.contains("ativa")) carregarCobrancas(); }); document.getElementById("pesquisar-faturamento")?.addEventListener("input", () => { estado.pagina = 1; carregarCobrancas(); }); const form = document.getElementById("formFiltrosFaturamento"); form?.addEventListener("submit", e => { e.preventDefault(); form.hidden = true; document.getElementById("btnFiltrosFaturamento")?.setAttribute("aria-expanded", "false"); estado.pagina = 1; carregarCobrancas(); }); document.getElementById("limparFiltrosFaturamento")?.addEventListener("click", () => { form?.reset(); estado.pagina = 1; carregarCobrancas(); }); document.getElementById("btnFiltrosFaturamento")?.addEventListener("click", () => { const aberto = !form?.hidden; if (form) form.hidden = aberto; document.getElementById("btnFiltrosFaturamento")?.setAttribute("aria-expanded", String(!aberto)); }); if (document.getElementById("faturamento")?.classList.contains("ativa")) iniciar(); });
})();
