(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const rotas = {
    resumo: `${API}?path=painel/faturamento/resumo`,
    cobrancas: `${API}?path=painel/faturamento/cobrancas`,
    pagamentos: `${API}?path=painel/faturamento/pagamentos`,
    regularizacao: `${API}?path=painel/faturamento/regularizacao/cobranca`
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
  function renderAssinatura(assinatura, erro = false) {
    const el = document.getElementById("faturamentoAssinatura"); if (!el) return;
    if (!assinatura) { el.innerHTML = erro ? estadoVisual("Não foi possível carregar sua assinatura.", "Tente novamente mais tarde.", true) : window.__AUTH__?.modo_regularizacao === true ? estadoVisual("Assinatura suspensa.", "Regularize o faturamento para continuar usando o sistema.", false, "fa-crown") : estadoVisual("Você ainda não possui uma assinatura ativa.", "Quando houver uma assinatura, os detalhes do plano aparecerão aqui.", false, "fa-crown"); return; }
    const acaoRegularizacao = podeRegularizar(assinatura) ? '<button class="botao-geral destaque" type="button" data-regularizar-trial><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>Gerar cobrança de regularização</button>' : "";
    el.innerHTML = `<div class="faturamento-plano-identidade"><span class="faturamento-plano-icone" aria-hidden="true"><i class="fa-solid fa-crown"></i></span><div class="faturamento-assinatura-plano"><span class="faturamento-assinatura-rotulo">Plano atual</span><strong class="faturamento-assinatura-nome">${esc(assinatura.plano || "Plano não identificado")}</strong><div class="faturamento-assinatura-preco"><b>${moeda(assinatura.valor_contratado)}</b><small>/ ${esc(texto(assinatura.periodicidade))}</small></div></div></div><div><span>Vencimento</span><strong>Dia ${esc(assinatura.dia_vencimento)}</strong></div><div><span>Início</span><strong>${br(assinatura.data_inicio)}</strong></div><span class="${statusClasse(assinatura.status)}">${esc(texto(assinatura.status))}</span>${acaoRegularizacao}`;
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
  async function iniciar() { const aba = document.getElementById("faturamento"); if (!aba || !podeVer()) { if (aba) aba.hidden = true; return; } try { const data = await get(rotas.resumo); renderAssinatura(data.assinatura); renderIndicadores(data.indicadores || {}); estado.carregado = true; } catch (erro) { renderAssinatura(null, true); renderIndicadores(null); avisar("erro", erro.message); } await carregarCobrancas(); }
  document.addEventListener("click", evento => { const botao = evento.target.closest("[data-regularizar-trial]"); if (botao) regularizarTrial(botao); });
  document.addEventListener("DOMContentLoaded", () => { document.addEventListener("amagenda:painel-aba-alterada", e => { if (e.detail?.aba === "faturamento" && !estado.carregado) iniciar(); }); document.addEventListener("amagenda:sessao-carregada", () => { if (document.getElementById("faturamento")?.classList.contains("ativa")) carregarCobrancas(); }); document.getElementById("pesquisar-faturamento")?.addEventListener("input", () => { estado.pagina = 1; carregarCobrancas(); }); const form = document.getElementById("formFiltrosFaturamento"); form?.addEventListener("submit", e => { e.preventDefault(); form.hidden = true; document.getElementById("btnFiltrosFaturamento")?.setAttribute("aria-expanded", "false"); estado.pagina = 1; carregarCobrancas(); }); document.getElementById("limparFiltrosFaturamento")?.addEventListener("click", () => { form?.reset(); estado.pagina = 1; carregarCobrancas(); }); document.getElementById("btnFiltrosFaturamento")?.addEventListener("click", () => { const aberto = !form?.hidden; if (form) form.hidden = aberto; document.getElementById("btnFiltrosFaturamento")?.setAttribute("aria-expanded", String(!aberto)); }); if (document.getElementById("faturamento")?.classList.contains("ativa")) iniciar(); });
})();
