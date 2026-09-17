(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const INICIAR = `${API}?path=painel/faturamento/pagamento/pix/iniciar`;
  const CONSULTAR = `${API}?path=painel/faturamento/pagamento/transacao`;
  const CARTAO_CONFIGURACAO = `${API}?path=painel/faturamento/pagamento/cartao/configuracao`;
  const CARTAO_AUTORIZAR = `${API}?path=painel/faturamento/pagamento/cartao/autorizar`;
  const CARTAO_STATUS = `${API}?path=painel/faturamento/pagamento/cartao/status`;
  const estado = { idCobranca: 0, idTransacao: 0, valor: 0, codigo: "" };
  const cartao = { configuracao: null, carregando: false, controller: null };
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

  async function carregarConfiguracaoCartao() {
    if (!podePagar() || cartao.carregando) return;
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
          paymentMethods: { minInstallments: 1, maxInstallments: 1, types: { excluded: ["debit_card", "prepaid_card"] } },
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
    if (!cartao.configuracao?.pode_configurar) return;
    abrirModalId("modalCartaoRecorrenteFaturamento");
    await renderizarBrick();
  }

  async function atualizarCartao() {
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
    if (evento.target.closest("#modalCartaoRecorrenteFaturamento [data-fechar-modal]")) desmontarBrick();
  });
  document.addEventListener("keydown", evento => { if (evento.key === "Escape") desmontarBrick(); });
  document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("btnGerarPixFaturamento")?.addEventListener("click", iniciar);
    document.getElementById("btnAtualizarPixFaturamento")?.addEventListener("click", consultar);
    document.getElementById("btnCopiarPixFaturamento")?.addEventListener("click", copiar);
    document.addEventListener("amagenda:painel-aba-alterada", evento => { if (evento.detail?.aba === "faturamento") carregarConfiguracaoCartao(); });
    document.addEventListener("amagenda:sessao-carregada", carregarConfiguracaoCartao);
    if (document.getElementById("faturamento")?.classList.contains("ativa")) carregarConfiguracaoCartao();
  });
})();
