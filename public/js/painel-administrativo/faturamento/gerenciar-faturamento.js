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
    if (!contratacao || !podeContratar()) { secao.hidden = true; el.innerHTML = ""; return; }
    secao.hidden = false;
    const pendente = contratacao.pendente;
    const detalhes = pendente ? `<div><span>Plano escolhido</span><strong>${esc(pendente.plano || "Plano")}</strong></div><div><span>Valor do período</span><strong>${moeda(pendente.valor)} / ${esc(texto(pendente.periodicidade))}</strong></div>${pendente.data_vencimento ? `<div><span>Vencimento</span><strong>${br(pendente.data_vencimento)}</strong></div>` : ""}` : "";
    const orientacao = pendente && !pendente.paga ? "<p>Pague a cobrança de contratação na lista de cobranças abaixo. Depois da confirmação, conclua a contratação.</p>" : "";
    const concluir = pendente ? `<button class="botao-geral destaque" type="button" data-concluir-contratacao>${pendente.paga ? "Concluir contratação" : "Verificar pagamento"}</button>` : "";
    const contratar = contratacao.disponivel ? `<button class="botao-geral${pendente ? "" : " destaque"}" type="button" data-abrir-contratacao>${pendente ? "Escolher outro plano" : "Contratar novamente"}</button>` : "";
    const cartao = contratacao.cartao_sincronizacao_pendente ? "<p><small>O cartão do contrato anterior aguarda confirmação de encerramento. A contratação é paga via PIX; o pagamento automático poderá ser configurado depois dessa confirmação.</small></p>" : "";
    el.innerHTML = `<p><strong>${esc(contratacao.mensagem || "")}</strong></p>${detalhes}${orientacao}${cartao}${concluir}${contratar}`;
  }
  function abrirContratacao() {
    const lista = document.getElementById("planosContratarFaturamento");
    const planos = estado.contratacao?.planos || [];
    if (!lista || !planos.length) { avisar("aviso", "Nenhum plano disponível para contratação no momento."); return; }
    const atual = estado.contratacao?.pendente?.id_plano;
    lista.innerHTML = planos.map((plano, i) => `<label><input type="radio" name="id_plano" value="${esc(plano.id_plano)}"${(atual ? plano.id_plano === atual : i === 0) ? " checked" : ""}> ${esc(plano.nome)} — ${moeda(plano.valor)} / ${esc(texto(plano.periodicidade))}</label><br>`).join("");
    window.abrirModal?.("modalContratarFaturamento");
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
    const form = evento.currentTarget;
    const botao = evento.submitter || form.querySelector('button[type="submit"]');
    if (botao?.disabled) return;
    const idPlano = String(form.elements.id_plano?.value || "");
    if (!/^[1-9]\d*$/.test(idPlano)) { avisar("aviso", "Escolha um plano."); return; }
    if (botao) botao.disabled = true;
    try {
      const json = await postarContratacao({ acao: "contratar", id_plano: idPlano });
      window.fecharModal?.(document.getElementById("modalContratarFaturamento"));
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
    const concluir = evento.target.closest("[data-concluir-contratacao]");
    if (concluir) concluirContratacao(concluir);
  });
  document.addEventListener("submit", evento => {
    if (evento.target.id === "formContratarFaturamento") enviarContratacao(evento);
  });
  function linhaTroca(rotulo, valor) { return `<div><span>${esc(rotulo)}</span><strong>${esc(valor)}</strong></div>`; }
  function renderPreviaTroca() {
    const destino = document.getElementById("previaTrocaPlano");
    const botao = document.getElementById("confirmarTrocaPlano");
    const opcao = estado.trocaSelecionada;
    if (!destino) return;
    if (!opcao) { destino.innerHTML = "<p>Escolha um plano para ver as condições.</p>"; if (botao) botao.disabled = true; return; }
    const p = opcao.previa || {};
    if (!opcao.disponivel) { destino.innerHTML = `<p>${esc(p.mensagem || "Este plano não está disponível para troca.")}</p>`; if (botao) botao.disabled = true; return; }
    const linhas = p.tipo === "upgrade"
      ? linhaTroca("Plano atual", `${p.plano_atual} — ${moeda(p.valor_atual)}`)
        + linhaTroca("Novo plano", `${p.plano_novo} — ${moeda(p.valor_novo)}`)
        + linhaTroca("Diferença mensal", moeda(p.diferenca))
        + linhaTroca("Dias restantes", `${p.dias_restantes} de um ciclo de ${p.dias_ciclo} dias (${br(p.ciclo_inicio)} a ${br(p.ciclo_fim)})`)
        + linhaTroca("Cálculo proporcional", p.formula || "")
        + linhaTroca("Valor a pagar agora", moeda(p.valor_proporcional))
        + linhaTroca("Próxima renovação", br(p.proxima_renovacao))
        + linhaTroca("Próxima mensalidade", moeda(p.valor_novo))
      : linhaTroca("Plano atual", `${p.plano_atual} — ${moeda(p.valor_atual)}`)
        + linhaTroca("Novo plano", `${p.plano_novo} — ${moeda(p.valor_novo)}`)
        + linhaTroca("Vale a partir de", br(p.data_efetivacao))
        + linhaTroca("Nova mensalidade", moeda(p.valor_novo));
    destino.innerHTML = `<div class="faturamento-troca-previa">${linhas}</div><p>${esc(p.mensagem || "")}</p>`;
    if (botao) { botao.disabled = false; botao.textContent = p.tipo === "upgrade" ? (Number(opcao.valor_confirmacao) > 0 ? "Confirmar e gerar cobrança" : "Confirmar upgrade") : "Agendar downgrade"; }
  }
  function abrirTrocaPlano() {
    const lista = document.getElementById("opcoesTrocaPlano");
    const opcoes = estado.troca?.opcoes || [];
    if (!lista || !opcoes.length) { avisar("aviso", estado.troca?.mensagem || "Não há planos disponíveis para troca."); return; }
    estado.trocaSelecionada = null;
    lista.innerHTML = opcoes.map(opcao => `<label><input type="radio" name="id_plano_troca" value="${esc(opcao.id_plano)}"${opcao.disponivel ? "" : " disabled"}> ${esc(opcao.nome)} — ${moeda(opcao.valor)} <small>(${opcao.tipo === "upgrade" ? "Upgrade" : "Downgrade"}${opcao.disponivel ? "" : " indisponível"})</small></label><br>`).join("");
    renderPreviaTroca();
    window.abrirModal?.("modalTrocaPlano");
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
      window.fecharModal?.(document.getElementById("modalTrocaPlano"));
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
  async function enviarCancelamento(evento) {
    evento.preventDefault();
    const form = evento.currentTarget;
    const botao = evento.submitter || form.querySelector('button[type="submit"]');
    if (botao?.disabled) return;
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) { avisar("erro", "Atualize a página para renovar sua sessão."); return; }
    const motivo = String(form.elements.motivo?.value || "").trim();
    const modalidade = String(form.elements.modalidade_cancelamento?.value || "");
    if (!motivo || !["imediato", "fim_periodo_pago"].includes(modalidade)) {
      avisar("aviso", "Escolha a modalidade e informe o motivo."); return;
    }
    if (botao) botao.disabled = true;
    try {
      const corpo = new FormData();
      corpo.append("motivo", motivo);
      corpo.append("modalidade_cancelamento", modalidade);
      const resposta = await fetch(rotas.cancelar, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const json = await resposta.json().catch(() => ({}));
      if (!resposta.ok || json.ok !== true) throw new Error(json.user_msg || "Não foi possível cancelar a assinatura.");
      window.fecharModal?.(document.getElementById("modalCancelarFaturamento"));
      form.reset();
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
  async function iniciar() { const aba = document.getElementById("faturamento"); if (!aba || !podeVer()) { if (aba) aba.hidden = true; return; } try { const data = await get(rotas.resumo); estado.troca = data.troca || null; renderAssinatura(data.assinatura); renderContratacao(data.contratacao); renderIndicadores(data.indicadores || {}); estado.carregado = true; } catch (erro) { renderAssinatura(null, true); renderContratacao(null); renderIndicadores(null); avisar("erro", erro.message); } await carregarCobrancas(); }
  document.addEventListener("click", evento => { const botao = evento.target.closest("[data-regularizar-trial]"); if (botao) regularizarTrial(botao); });
  document.addEventListener("click", evento => {
    if (evento.target.closest("[data-cancelar-assinatura]")) window.abrirModal?.("modalCancelarFaturamento");
  });
  document.addEventListener("submit", evento => {
    if (evento.target.id === "formCancelarFaturamento") enviarCancelamento(evento);
  });
  document.addEventListener("DOMContentLoaded", () => { document.addEventListener("amagenda:painel-aba-alterada", e => { if (e.detail?.aba === "faturamento" && !estado.carregado) iniciar(); }); document.addEventListener("amagenda:sessao-carregada", () => { if (document.getElementById("faturamento")?.classList.contains("ativa")) carregarCobrancas(); }); document.getElementById("pesquisar-faturamento")?.addEventListener("input", () => { estado.pagina = 1; carregarCobrancas(); }); const form = document.getElementById("formFiltrosFaturamento"); form?.addEventListener("submit", e => { e.preventDefault(); form.hidden = true; document.getElementById("btnFiltrosFaturamento")?.setAttribute("aria-expanded", "false"); estado.pagina = 1; carregarCobrancas(); }); document.getElementById("limparFiltrosFaturamento")?.addEventListener("click", () => { form?.reset(); estado.pagina = 1; carregarCobrancas(); }); document.getElementById("btnFiltrosFaturamento")?.addEventListener("click", () => { const aberto = !form?.hidden; if (form) form.hidden = aberto; document.getElementById("btnFiltrosFaturamento")?.setAttribute("aria-expanded", String(!aberto)); }); if (document.getElementById("faturamento")?.classList.contains("ativa")) iniciar(); });
})();
