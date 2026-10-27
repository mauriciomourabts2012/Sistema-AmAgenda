(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const INICIAR = `${API}?path=painel/faturamento/pagamento/pix/iniciar`;
  const CONSULTAR = `${API}?path=painel/faturamento/pagamento/transacao`;
  const CARTAO_CONFIGURACAO = `${API}?path=painel/faturamento/pagamento/cartao/configuracao`;
  const CARTAO_AUTORIZAR = `${API}?path=painel/faturamento/pagamento/cartao/autorizar`;
  const CARTAO_STATUS = `${API}?path=painel/faturamento/pagamento/cartao/status`;
  const CARTAO_USAR_PIX = `${API}?path=painel/faturamento/pagamento/cartao/usar-pix`;
  const estado = { idCobranca: 0, idTransacao: 0, valor: 0, codigo: "" };
  const cartao = { configuracao: null, carregando: false, controller: null, redirecionando: false, retornoProcessado: false, retornoEmCurso: false };
  // Destino do redirecionamento de autorização: somente o checkout de assinaturas do Mercado Pago (o servidor valida antes).
  // Query lida literalmente: preapproval_id obrigatório e activation opcional (opaco), cada um uma única vez.
  const initPointValido = url => {
    try {
      const texto = String(url || "");
      const u = new URL(texto);
      if (u.protocol !== "https:" || !["www.mercadopago.com.br", "mercadopago.com.br"].includes(u.hostname)
        // href idêntico ao recebido: recusa qualquer link que o navegador reescreveria (codificação, maiúsculas, "#" vazio).
        || u.href !== texto || u.pathname !== "/subscriptions/checkout" || u.username || u.password || u.hash || texto.includes("#")
        || !(u.port === "" || u.port === "443") || !u.search) return false;
      const vistos = {};
      for (const par of u.search.slice(1).split("&")) {
        const m = /^(preapproval_id|activation)=([^=&]*)$/.exec(par);
        if (!m || vistos[m[1]] !== undefined) return false;
        vistos[m[1]] = m[2];
      }
      return /^[A-Za-z0-9_-]{1,150}$/.test(vistos.preapproval_id || "")
        && (vistos.activation === undefined || (/^[A-Za-z0-9._~%-]{1,512}$/.test(vistos.activation) && !/%(?![0-9A-Fa-f]{2})/.test(vistos.activation)));
    } catch (erro) { return false; }
  };
  const moeda = valor => Number(valor || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const avisar = (tipo, mensagem) => (window.MensagemSistema?.[tipo] || window.MensagemSistema?.info)?.(mensagem);
  const esc = valor => String(valor ?? "").replace(/[&<>'"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" }[c]));
  const statusTexto = valor => ({
    criada: "Criado", enviando: "Gerando PIX", aguardando: "Aguardando pagamento",
    processando: "Processando", confirmada: "Pagamento identificado", recusada: "Recusado",
    expirada: "PIX expirado", cancelada: "Cancelado", erro: "Erro no envio", conciliacao: "Em verificação"
  }[String(valor || "")] || "Aguardando atualização");

  async function json(resposta) {
    const dados = await resposta.json().catch(() => ({}));
    if (!resposta.ok || dados.ok !== true) throw new Error(dados.user_msg || "Não foi possível processar o PIX.");
    return dados;
  }

  function abrirModalId(id) {
    document.getElementById(id)?.classList.add("ativo");
  }

  function fecharModalId(id) {
    document.getElementById(id)?.classList.remove("ativo");
  }

  function limparResultado() {
    // Cada abertura começa sem dados da cobrança anterior.
    estado.idTransacao = 0;
    estado.codigo = "";
    document.getElementById("pixPagamentoResultado")?.setAttribute("hidden", "");
    document.getElementById("pixPagamentoPreparacao")?.removeAttribute("hidden");
    document.getElementById("pixPagamentoCarregando")?.setAttribute("hidden", "");
    document.getElementById("pixPagamentoErro")?.setAttribute("hidden", "");
    document.getElementById("pixPagamentoQr")?.removeAttribute("src");
    document.getElementById("pixPagamentoQr")?.setAttribute("hidden", "");
    const codigo = document.getElementById("pixPagamentoCodigo");
    if (codigo) { codigo.value = ""; codigo.replaceChildren(); codigo.closest(".pix-copia")?.setAttribute("hidden", ""); }
    const status = document.getElementById("pixPagamentoStatus");
    if (status) { status.textContent = "Aguardando pagamento"; status.dataset.status = ""; }
    const validade = document.getElementById("pixPagamentoValidade");
    if (validade) validade.textContent = "—";
    const link = document.getElementById("pixPagamentoLink");
    if (link) { link.removeAttribute("href"); link.setAttribute("hidden", ""); }
    const gerar = document.getElementById("btnGerarPixFaturamento");
    if (gerar) { gerar.disabled = false; gerar.innerHTML = '<i class="fa-brands fa-pix" aria-hidden="true"></i>Gerar PIX'; }
  }

  function abrir(botao) {
    estado.idCobranca = Number(botao.dataset.pagarCobranca || 0);
    estado.valor = Number(botao.dataset.pagarValor || 0);
    if (!Number.isInteger(estado.idCobranca) || estado.idCobranca <= 0 || estado.valor <= 0) return;
    limparResultado();
    const valor = document.getElementById("pixPagamentoValor");
    if (valor) valor.textContent = moeda(estado.valor);
    abrirModalId("modalPagamentoPixFaturamento");
  }

  function renderizar(dados) {
    estado.idTransacao = Number(dados.id_transacao || 0);
    estado.codigo = String(dados.qr_code || "");
    const preparacao = document.getElementById("pixPagamentoPreparacao");
    const resultado = document.getElementById("pixPagamentoResultado");
    preparacao?.setAttribute("hidden", "");
    resultado?.removeAttribute("hidden");
    document.getElementById("pixPagamentoCarregando")?.setAttribute("hidden", "");
    document.getElementById("pixPagamentoErro")?.setAttribute("hidden", "");
    const valor = document.getElementById("pixPagamentoValorResultado");
    const status = document.getElementById("pixPagamentoStatus");
    const validade = document.getElementById("pixPagamentoValidade");
    const codigo = document.getElementById("pixPagamentoCodigo");
    const imagem = document.getElementById("pixPagamentoQr");
    const link = document.getElementById("pixPagamentoLink");
    if (valor) valor.textContent = moeda(dados.valor);
    if (status) { status.textContent = statusTexto(dados.status); status.dataset.status = String(dados.status || ""); }
    if (validade) validade.textContent = dados.expira_em ? new Date(String(dados.expira_em).replace(" ", "T")).toLocaleString("pt-BR") : "Validade padrão do Mercado Pago";
    if (codigo) { codigo.value = estado.codigo; codigo.closest(".pix-copia")?.toggleAttribute("hidden", !estado.codigo); }
    const base64 = String(dados.qr_code_base64 || "");
    if (imagem) {
      if (/^[A-Za-z0-9+/=]+$/.test(base64)) { imagem.src = `data:image/png;base64,${base64}`; imagem.hidden = false; }
      else { imagem.removeAttribute("src"); imagem.hidden = true; }
    }
    const url = String(dados.ticket_url || "");
    if (link) {
      if (/^https:\/\/([a-z0-9-]+\.)*mercadopago\.com\.br\//i.test(url)) { link.href = url; link.hidden = false; }
      else { link.removeAttribute("href"); link.hidden = true; }
    }
    if (dados.status === "expirada") avisar("aviso", "Este PIX expirou. Feche a janela e gere um novo PIX pela cobrança.");
  }

  async function iniciar() {
    const botao = document.getElementById("btnGerarPixFaturamento");
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) { avisar("erro", "Atualize a página para renovar sua sessão."); return; }
    if (botao) { botao.disabled = true; botao.textContent = "Gerando PIX..."; }
    document.getElementById("pixPagamentoCarregando")?.removeAttribute("hidden");
    document.getElementById("pixPagamentoErro")?.setAttribute("hidden", "");
    try {
      const corpo = new FormData(); corpo.set("id_cobranca", String(estado.idCobranca));
      const resposta = await fetch(INICIAR, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const retorno = await json(resposta);
      renderizar(retorno.data || {});
      avisar("sucesso", retorno.user_msg || "PIX disponível para pagamento.");
    } catch (erro) {
      avisar("erro", erro.message);
      document.getElementById("pixPagamentoCarregando")?.setAttribute("hidden", "");
      document.getElementById("pixPagamentoErro")?.removeAttribute("hidden");
      if (botao) { botao.disabled = false; botao.innerHTML = '<i class="fa-brands fa-pix" aria-hidden="true"></i>Gerar PIX'; }
    }
  }

  async function consultar() {
    const botao = document.getElementById("btnAtualizarPixFaturamento");
    if (!Number.isInteger(estado.idTransacao) || estado.idTransacao <= 0) return;
    if (botao) { botao.disabled = true; botao.textContent = "Atualizando..."; }
    try {
      const resposta = await fetch(`${CONSULTAR}&id_transacao=${encodeURIComponent(estado.idTransacao)}`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const retorno = await json(resposta);
      renderizar(retorno.data || {});
      avisar("info", retorno.user_msg || "Situação do PIX atualizada.");
    } catch (erro) { avisar("erro", erro.message); }
    finally { if (botao) { botao.disabled = false; botao.innerHTML = '<i class="fa-solid fa-rotate" aria-hidden="true"></i>Atualizar situação'; } }
  }

  async function copiar() {
    if (!estado.codigo) return;
    try { await navigator.clipboard.writeText(estado.codigo); avisar("sucesso", "Código PIX copiado."); }
    catch (erro) { document.getElementById("pixPagamentoCodigo")?.select(); avisar("erro", "Não foi possível copiar automaticamente. Selecione e copie o código."); }
  }

  function podePagar() {
    const auth = window.__AUTH__ || {};
    if (String(auth.tipo_usuario || "").toLowerCase() === "super_admin" && auth.modo_suporte === true) return false;
    return typeof window.usuarioPode === "function" && window.usuarioPode("faturamento.pagar");
  }

  function renderizarCartao(dados) {
    cartao.configuracao = { ...(cartao.configuracao || {}), ...(dados || {}) };
    const secao = document.getElementById("faturamentoPagamentoAutomatico");
    const alvo = document.getElementById("faturamentoCartaoEstado");
    if (!secao || !alvo) return;
    secao.hidden = !podePagar();
    if (secao.hidden) return;
    const atual = cartao.configuracao;
    if (atual.motor_recorrencia === "preapproval") { renderizarAutorizacao(alvo, atual); return; }
    if (atual.configurado) {
      alvo.innerHTML = `<div class="faturamento-cartao-identidade"><span class="faturamento-cartao-icone" aria-hidden="true"><i class="fa-regular fa-credit-card"></i></span><div><span>Cartão cadastrado</span><strong>${esc(String(atual.bandeira || "Cartão").toUpperCase())} •••• ${esc(atual.final_cartao || "••••")}</strong><small>Autorizado para pagamentos automáticos futuros.</small></div></div><span class="faturamento-status faturamento-status--ativa">Ativo</span>`;
      return;
    }
    if (atual.status === "pendente") {
      alvo.innerHTML = `<div class="faturamento-cartao-identidade"><span class="faturamento-cartao-icone" aria-hidden="true"><i class="fa-solid fa-hourglass-half"></i></span><div><span>Validação em andamento</span><strong>Cartão em processamento</strong><small>O pagamento automático só será ativado após a confirmação do Mercado Pago.</small></div></div><button class="botao-geral" type="button" data-atualizar-cartao><i class="fa-solid fa-rotate" aria-hidden="true"></i>Atualizar situação</button>`;
      return;
    }
    const detalhe = atual.valor_referencia
      ? `Sua assinatura atual é de ${moeda(atual.valor_referencia)}. Nenhuma cobrança será feita durante o cadastro.`
      : "É necessário possuir uma assinatura ativa para configurar o pagamento automático.";
    const acao = atual.pode_configurar
      ? '<button class="botao-geral destaque" type="button" data-configurar-cartao><i class="fa-regular fa-credit-card" aria-hidden="true"></i>Configurar cartão</button>'
      : "";
    alvo.innerHTML = `<div class="faturamento-cartao-identidade"><span class="faturamento-cartao-icone" aria-hidden="true"><i class="fa-regular fa-credit-card"></i></span><div><span>Pagamento automático</span><strong>Nenhum cartão configurado</strong><small>${esc(detalhe)}</small></div></div>${acao}`;
  }

  // Primeira mensalidade da contratação nova: PIX (fluxo atual) ou cartão de crédito recorrente (Preapproval).
  // Os estados vêm do servidor; o navegador nunca decide que algo foi pago.
  const SITUACOES_PRIMEIRA = {
    escolha: ["fa-regular fa-credit-card", "Escolha como pagar", "No PIX, você paga agora e pode configurar o cartão depois. No cartão de crédito, a primeira mensalidade é cobrada após a autorização no Mercado Pago e as próximas passam a ser automáticas."],
    pix_em_andamento: ["fa-brands fa-pix", "PIX em andamento", "Já existe um PIX gerado para esta cobrança. Conclua o pagamento ou aguarde a expiração para escolher o cartão."],
    aguardando_autorizacao: ["fa-solid fa-hourglass-half", "Aguardando sua autorização", "Conclua a autorização do cartão no Mercado Pago. A primeira mensalidade só é cobrada depois dela."],
    autorizacao_em_analise: ["fa-solid fa-hourglass-half", "Autorizado no Mercado Pago — ativação em análise", "A autorização foi recebida e precisa de conferência antes da cobrança. O acesso só é liberado após o pagamento confirmado."],
    aguardando_primeira_cobranca: ["fa-solid fa-hourglass-half", "Aguardando a primeira cobrança", "O cartão foi autorizado. O acesso é liberado quando o Mercado Pago confirmar o pagamento da primeira mensalidade."],
    processando: ["fa-solid fa-hourglass-half", "Pagamento em processamento", "O Mercado Pago está processando a primeira mensalidade no cartão."],
    pagamento_recusado: ["fa-solid fa-triangle-exclamation", "Pagamento recusado", "O cartão recusou a primeira mensalidade. O Mercado Pago pode tentar novamente; se preferir, pague com PIX."],
    em_verificacao: ["fa-solid fa-triangle-exclamation", "Pagamento em verificação", "O pagamento precisa de conferência. Atualize a situação mais tarde ou fale com o suporte."],
    cancelando_cartao: ["fa-solid fa-hourglass-half", "Cancelando o pagamento no cartão", "Aguardando a confirmação do Mercado Pago. O PIX será liberado em seguida."],
    cartao_cancelado: ["fa-brands fa-pix", "Pagamento no cartão cancelado", "Pague a primeira mensalidade com PIX."],
    pagamento_confirmado: ["fa-solid fa-circle-check", "Pagamento confirmado", "A primeira mensalidade foi paga. A contratação é ativada automaticamente; se ainda não foi, use \"Concluir contratação\"."],
    indisponivel: ["fa-solid fa-triangle-exclamation", "Cobrança de contratação indisponível", "Atualize a página ou fale com o suporte."]
  };
  const cabecalhoCartao = (icone, rotulo, titulo, texto) => `<div class="faturamento-cartao-identidade"><span class="faturamento-cartao-icone" aria-hidden="true"><i class="${icone}"></i></span><div><span>${esc(rotulo)}</span><strong>${esc(titulo)}</strong><small>${esc(texto)}</small></div></div>`;
  const botaoAutorizarCartao = rotulo => `<button class="botao-geral destaque" type="button" data-autorizar-assinatura><i class="fa-regular fa-credit-card" aria-hidden="true"></i>${esc(rotulo)}</button>`;

  function renderizarPrimeiraCobranca(alvo, atual, botaoVerificar) {
    const p = atual.primeira_cobranca || {};
    const [icone, titulo, texto] = SITUACOES_PRIMEIRA[p.situacao] || SITUACOES_PRIMEIRA.indisponivel;
    const rotulo = Number(p.valor) > 0 ? `Primeira mensalidade · ${moeda(p.valor)}` : "Primeira mensalidade";
    // Autorização encerrada (cancelamento confirmado) não é retomada: o botão cria uma nova.
    const criada = atual.autorizacao_criada === true && !["cancelada", "encerrada"].includes(String(atual.status || ""));
    const pix = p.pode_pagar_pix && Number(p.id_cobranca) > 0
      ? `<button class="botao-geral${atual.pode_configurar ? "" : " destaque"}" type="button" data-pagar-cobranca="${esc(p.id_cobranca)}" data-pagar-valor="${esc(p.valor)}"><i class="fa-brands fa-pix" aria-hidden="true"></i>Pagar com PIX</button>` : "";
    const cartaoBotao = atual.pode_configurar ? botaoAutorizarCartao(criada ? "Continuar no Mercado Pago" : "Pagar com cartão de crédito") : "";
    const trocar = p.pode_trocar_para_pix ? '<button class="botao-geral" type="button" data-usar-pix><i class="fa-brands fa-pix" aria-hidden="true"></i>Pagar com PIX em vez do cartão</button>' : "";
    const indisponivel = p.situacao === "escolha" && !atual.pode_configurar && atual.subscriptions_habilitado === false
      ? " O pagamento no cartão ainda não está disponível; use o PIX."
      : p.situacao === "cartao_cancelado" && atual.pode_configurar ? " Se preferir, autorize o cartão novamente." : "";
    alvo.innerHTML = `${cabecalhoCartao(icone, rotulo, titulo, texto + indisponivel)}<div class="faturamento-acoes">${cartaoBotao}${pix}${trocar}${botaoVerificar}</div>`;
  }

  async function usarPix() {
    if (cartao.redirecionando || !cartao.configuracao?.primeira_cobranca?.pode_trocar_para_pix) return;
    const mensagem = "O pagamento no cartão desta contratação será cancelado no Mercado Pago e não poderá ser retomado para este contrato. Depois da confirmação, a primeira mensalidade poderá ser paga com PIX. Deseja continuar?";
    const confirmado = window.MensagemSistema?.confirmar
      ? await window.MensagemSistema.confirmar(mensagem, { titulo: "Pagar com PIX em vez do cartão", textoConfirmar: "Cancelar o cartão" })
      : window.confirm(mensagem);
    if (!confirmado) return;
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) { avisar("erro", "Atualize a página para renovar sua sessão."); return; }
    cartao.redirecionando = true;
    document.querySelectorAll("[data-usar-pix]").forEach(b => { b.disabled = true; });
    try {
      const resposta = await fetch(CARTAO_USAR_PIX, { method: "POST", body: new FormData(), credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const retorno = await json(resposta);
      renderizarCartao(retorno.data || {});
      avisar(retorno.code === "PIX_LIBERADO" ? "sucesso" : "info", retorno.user_msg || "Cancelamento do cartão solicitado.");
    } catch (erro) {
      avisar("erro", erro.message);
      document.querySelectorAll("[data-usar-pix]").forEach(b => { b.disabled = false; });
    } finally {
      cartao.redirecionando = false;
    }
  }

  // Contratos com Preapproval: o servidor indica o fluxo; o navegador só mostra o estado e redireciona ao Mercado Pago.
  function renderizarAutorizacao(alvo, atual) {
    const cabecalho = cabecalhoCartao;
    const botaoAutorizar = botaoAutorizarCartao;
    const botaoVerificar = atual.pode_verificar
      ? '<button class="botao-geral" type="button" data-atualizar-cartao><i class="fa-solid fa-rotate" aria-hidden="true"></i>Atualizar situação</button>' : "";
    if (atual.primeira_cobranca) { renderizarPrimeiraCobranca(alvo, atual, botaoVerificar); return; }
    const status = String(atual.status || "nao_configurado");
    if (atual.configurado) {
      alvo.innerHTML = `${cabecalho("fa-regular fa-credit-card", "Pagamento automático", "Autorizado no Mercado Pago", "Os pagamentos futuros serão cobrados automaticamente no cartão autorizado.")}<span class="faturamento-status faturamento-status--ativa">Ativo</span>`;
      return;
    }
    if (status === "pendente") {
      // Pendente com Preapproval já criada: o servidor devolve o mesmo link (sem nova criação). Pendente sem Preapproval
      // criada (tentativa anterior falhou ou ficou incerta): o servidor concilia e só então cria, uma única vez.
      const criada = atual.autorizacao_criada !== false;
      const titulo = criada ? "Aguardando sua autorização" : "Autorização ainda não criada";
      const texto = criada
        ? "A autorização só vale depois da confirmação do Mercado Pago. Nenhuma cobrança é feita nesta etapa."
        : "A autorização ainda não foi criada no Mercado Pago. Clique para tentar novamente; nenhuma cobrança é feita agora e nada é duplicado.";
      const semAcao = !atual.pode_configurar && !atual.pode_verificar
        ? (atual.subscriptions_habilitado === false ? "O pagamento automático por este meio ainda não está disponível." : "Atualize a página ou fale com o suporte caso a situação persista.")
        : (!atual.pode_configurar && criada ? "O Mercado Pago já registrou outra situação para esta autorização. Atualize para conferir." : "");
      alvo.innerHTML = `${cabecalho("fa-solid fa-hourglass-half", "Autorização pendente", titulo, semAcao || texto)}<div class="faturamento-acoes">${atual.pode_configurar ? botaoAutorizar(criada ? "Continuar no Mercado Pago" : "Autorizar cartão no Mercado Pago") : ""}${botaoVerificar}</div>`;
      return;
    }
    const mensagens = {
      cancelamento_pendente: ["fa-solid fa-hourglass-half", "Cancelamento em andamento", "O cancelamento do pagamento automático está sendo confirmado."],
      cancelada: ["fa-regular fa-credit-card", "Pagamento automático cancelado", "Esta autorização foi encerrada e não será reaproveitada."],
      suspensa: ["fa-solid fa-circle-pause", "Pagamento automático pausado", "As cobranças automáticas estão pausadas."],
      atualizacao_necessaria: ["fa-solid fa-triangle-exclamation", "Atualização necessária", "O pagamento automático precisa de atenção. Atualize a situação."],
      erro: ["fa-solid fa-triangle-exclamation", "Não foi possível concluir", "Tente novamente ou atualize a situação."]
    };
    if (mensagens[status]) {
      const [icone, titulo, texto] = mensagens[status];
      alvo.innerHTML = `${cabecalho(icone, "Pagamento automático", titulo, texto)}<div class="faturamento-acoes">${atual.pode_configurar ? botaoAutorizar("Autorizar cartão no Mercado Pago") : ""}${botaoVerificar}</div>`;
      return;
    }
    const detalhe = atual.valor_referencia
      ? `Sua assinatura atual é de ${moeda(atual.valor_referencia)}. Você autoriza o cartão no Mercado Pago e nada é cobrado agora.`
      : "É necessário possuir uma assinatura ativa para configurar o pagamento automático.";
    const indisponivel = atual.subscriptions_habilitado === false ? "O pagamento automático por este meio ainda não está disponível." : detalhe;
    alvo.innerHTML = `${cabecalho("fa-regular fa-credit-card", "Pagamento automático", "Nenhum cartão autorizado", indisponivel)}${atual.pode_configurar ? botaoAutorizar("Autorizar cartão no Mercado Pago") : ""}`;
  }

  async function iniciarAutorizacaoAssinatura() {
    if (cartao.redirecionando || !cartao.configuracao?.pode_configurar) return;
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) { avisar("erro", "Atualize a página para renovar sua sessão."); return; }
    cartao.redirecionando = true;
    const botoes = document.querySelectorAll("[data-autorizar-assinatura]");
    botoes.forEach(b => { b.disabled = true; });
    try {
      // Sem corpo contratual: empresa, valor e contrato são sempre os do servidor.
      const resposta = await fetch(CARTAO_AUTORIZAR, { method: "POST", body: new FormData(), credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const retorno = await json(resposta);
      const dados = retorno.data || {};
      if (initPointValido(dados.init_point)) {
        avisar("info", retorno.user_msg || "Redirecionando ao Mercado Pago...");
        window.location.assign(dados.init_point);
        return;
      }
      renderizarCartao(dados);
      avisar("info", retorno.user_msg || "Situação do pagamento automático atualizada.");
    } catch (erro) {
      avisar("erro", erro.message);
    }
    cartao.redirecionando = false;
    botoes.forEach(b => { b.disabled = false; });
  }

  // Retorno do Mercado Pago: a URL não prova nada. O servidor consulta o provedor (POST com CSRF) e devolve o estado real.
  function limparRetornoDaUrl() {
    try { window.history.replaceState(null, "", window.location.pathname + window.location.hash); } catch (erro) { /* histórico indisponível */ }
  }

  async function verificarRetornoAssinatura() {
    const alvo = document.getElementById("faturamentoCartaoEstado");
    if (alvo) alvo.innerHTML = '<div class="faturamento-cartao-identidade"><span class="faturamento-cartao-icone" aria-hidden="true"><i class="fa-solid fa-hourglass-half"></i></span><div><span>Verificando autorização</span><strong>Consultando o Mercado Pago</strong><small>Aguarde: a confirmação vem do provedor, não desta página.</small></div></div>';
    const csrf = String(window.__AUTH__?.csrf_token || "");
    try {
      if (!/^[a-f0-9]{64}$/.test(csrf)) throw new Error("Atualize a página para renovar sua sessão.");
      const resposta = await fetch(CARTAO_STATUS, { method: "POST", body: new FormData(), credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const retorno = await json(resposta);
      renderizarCartao(retorno.data || {});
      avisar(retorno.data?.configurado ? "sucesso" : "info", retorno.user_msg || "Situação da autorização atualizada.");
    } catch (erro) {
      avisar("erro", erro.message || "Não foi possível confirmar a autorização agora.");
      cartao.carregando = false;
      cartao.retornoEmCurso = false;
      await carregarConfiguracaoCartao();
    } finally {
      cartao.retornoEmCurso = false;
    }
  }

  function tentarRetornoAssinatura() {
    if (cartao.retornoProcessado || !podePagar()) return;
    const parametros = new URLSearchParams(window.location.search);
    if (parametros.get("mp_assinatura_retorno") !== "1") return;
    cartao.retornoProcessado = true;
    cartao.retornoEmCurso = true;
    limparRetornoDaUrl();
    document.querySelector('[data-menu-aba="faturamento"]')?.click();
    const secao = document.getElementById("faturamentoPagamentoAutomatico");
    if (secao) secao.hidden = false;
    verificarRetornoAssinatura();
  }

  async function carregarConfiguracaoCartao() {
    // Durante a verificação do retorno, uma leitura anterior ao POST não pode sobrescrever o estado confirmado.
    if (!podePagar() || cartao.carregando || cartao.retornoEmCurso) return;
    const secao = document.getElementById("faturamentoPagamentoAutomatico");
    if (secao) secao.hidden = false;
    cartao.carregando = true;
    try {
      const resposta = await fetch(CARTAO_CONFIGURACAO, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const retorno = await json(resposta);
      renderizarCartao(retorno.data || {});
    } catch (erro) {
      const alvo = document.getElementById("faturamentoCartaoEstado");
      if (alvo) alvo.innerHTML = '<div class="faturamento-estado faturamento-estado--erro"><span class="faturamento-estado-icone" aria-hidden="true"><i class="fa-solid fa-credit-card"></i></span><div><strong>Pagamento automático indisponível.</strong><p>Tente novamente mais tarde.</p></div></div>';
      avisar("erro", erro.message);
    } finally { cartao.carregando = false; }
  }

  async function desmontarBrick() {
    const controller = cartao.controller;
    cartao.controller = null;
    if (controller?.unmount) {
      try { await controller.unmount(); } catch (erro) { /* componente já removido */ }
    }
    const container = document.getElementById("cardPaymentBrick_container");
    if (container) container.replaceChildren();
  }

  async function autorizarCartao(formData) {
    let token = String(formData?.token || "");
    const bandeira = String(formData?.payment_method_id || "");
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) throw new Error("Atualize a página para renovar sua sessão.");
    if (!/^[A-Za-z0-9_-]{32,33}$/.test(token) || !/^[a-z0-9_]{2,30}$/i.test(bandeira)) {
      throw new Error("Não foi possível tokenizar o cartão. Confira os dados informados.");
    }
    const corpo = new FormData();
    corpo.set("token", token);
    corpo.set("payment_method_id", bandeira);
    try {
      const resposta = await fetch(CARTAO_AUTORIZAR, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const retorno = await json(resposta);
      renderizarCartao(retorno.data || {});
      avisar(retorno.data?.configurado ? "sucesso" : "info", retorno.user_msg || "Cartão enviado para validação.");
      fecharModalId("modalCartaoRecorrenteFaturamento");
      await desmontarBrick();
    } finally {
      corpo.delete("token");
      token = "";
    }
  }

  async function renderizarBrick() {
    const carregando = document.getElementById("cartaoBrickCarregando");
    const erro = document.getElementById("cartaoBrickErro");
    carregando?.removeAttribute("hidden");
    erro?.setAttribute("hidden", "");
    await desmontarBrick();
    const cfg = cartao.configuracao || {};
    if (!window.MercadoPago || typeof cfg.public_key !== "string" || cfg.public_key.length < 20) {
      carregando?.setAttribute("hidden", ""); erro?.removeAttribute("hidden"); return;
    }
    try {
      const mp = new window.MercadoPago(cfg.public_key, { locale: "pt-BR" });
      const bricksBuilder = mp.bricks();
      cartao.controller = await bricksBuilder.create("cardPayment", "cardPaymentBrick_container", {
        initialization: { amount: Number(cfg.valor_referencia || 0) },
        customization: {
          paymentMethods: { minInstallments: 1, maxInstallments: 1, types: { excluded: ["debit_card", "prepaid-card"] } },
          visual: { style: { theme: "default" }, texts: { formTitle: "Cartão de crédito", installmentsSectionTitle: "", formSubmit: "Autorizar cartão" } }
        },
        callbacks: {
          onReady: () => carregando?.setAttribute("hidden", ""),
          onSubmit: formData => new Promise((resolve, reject) => {
            autorizarCartao(formData).then(resolve).catch(erroEnvio => { avisar("erro", erroEnvio.message); reject(); });
          }),
          onError: () => { carregando?.setAttribute("hidden", ""); avisar("erro", "Revise os dados do cartão e tente novamente."); }
        }
      });
    } catch (falha) {
      carregando?.setAttribute("hidden", "");
      erro?.removeAttribute("hidden");
    }
  }

  async function abrirCartao() {
    if (!cartao.configuracao?.pode_configurar || cartao.configuracao?.motor_recorrencia === "preapproval") return;
    abrirModalId("modalCartaoRecorrenteFaturamento");
    await renderizarBrick();
  }

  async function atualizarCartao() {
    if (cartao.configuracao?.motor_recorrencia === "preapproval") {
      if (cartao.carregando) return;
      cartao.carregando = true;
      try { await verificarRetornoAssinatura(); } finally { cartao.carregando = false; }
      return;
    }
    try {
      const resposta = await fetch(CARTAO_STATUS, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const retorno = await json(resposta);
      renderizarCartao(retorno.data || {});
      avisar("info", retorno.user_msg || "Situação do cartão atualizada.");
    } catch (erro) { avisar("erro", erro.message); }
  }

  document.addEventListener("click", evento => {
    const pagar = evento.target.closest("[data-pagar-cobranca]");
    if (pagar) abrir(pagar);
    if (evento.target.closest("[data-configurar-cartao]")) abrirCartao();
    if (evento.target.closest("[data-atualizar-cartao]")) atualizarCartao();
    if (evento.target.closest("[data-autorizar-assinatura]")) iniciarAutorizacaoAssinatura();
    if (evento.target.closest("[data-usar-pix]")) usarPix();
    if (evento.target.closest("#modalCartaoRecorrenteFaturamento [data-fechar-modal]")) desmontarBrick();
  });
  document.addEventListener("keydown", evento => { if (evento.key === "Escape") desmontarBrick(); });
  document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("btnGerarPixFaturamento")?.addEventListener("click", iniciar);
    document.getElementById("btnAtualizarPixFaturamento")?.addEventListener("click", consultar);
    document.getElementById("btnCopiarPixFaturamento")?.addEventListener("click", copiar);
    document.addEventListener("amagenda:painel-aba-alterada", evento => { if (evento.detail?.aba === "faturamento") carregarConfiguracaoCartao(); });
    document.addEventListener("amagenda:sessao-carregada", () => { tentarRetornoAssinatura(); carregarConfiguracaoCartao(); });
    tentarRetornoAssinatura();
    if (!cartao.retornoProcessado && document.getElementById("faturamento")?.classList.contains("ativa")) carregarConfiguracaoCartao();
  });
})();
