(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const INICIAR = `${API}?path=painel/faturamento/pagamento/pix/iniciar`;
  const CONSULTAR = `${API}?path=painel/faturamento/pagamento/transacao`;
  const CARTAO_CONFIGURACAO = `${API}?path=painel/faturamento/pagamento/cartao/configuracao`;
  const CARTAO_AUTORIZAR = `${API}?path=painel/faturamento/pagamento/cartao/autorizar`;
  const CARTAO_STATUS = `${API}?path=painel/faturamento/pagamento/cartao/status`;
  const CARTAO_USAR_PIX = `${API}?path=painel/faturamento/pagamento/cartao/usar-pix`;
  const CARTAO_TROCAR = `${API}?path=painel/faturamento/pagamento/cartao/trocar`;
  const estado = { idCobranca: 0, idTransacao: 0, valor: 0, codigo: "" };
  const cartao = { configuracao: null, carregando: false, controller: null, redirecionando: false, retornoProcessado: false, retornoEmCurso: false, trocando: false };
  const TEXTOS_MODAL_CARTAO = {
    configurar: { titulo: "Configurar cartão", consentimento: "Ao autorizar, este cartão poderá ser usado nos pagamentos futuros automáticos das cobranças da sua assinatura AmAgenda. Nenhuma cobrança será feita agora.", enviar: "Autorizar cartão" },
    trocar: { titulo: "Alterar cartão de crédito", consentimento: "O novo cartão substituirá o atual nas próximas cobranças automáticas da sua assinatura AmAgenda. O Mercado Pago processará a atualização do cartão, e plano e valor não mudam.", enviar: "Salvar novo cartão" }
  };
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
  // "AAAA-MM-DD HH:MM:SS" do servidor -> "DD/MM/AAAA" (sem conversão de fuso no navegador).
  const dataBr = valor => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(valor || "")); return m ? `${m[3]}/${m[2]}/${m[1]}` : ""; };
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
    // PIX já gerado para a cobrança: reabre o mesmo (o servidor reutiliza a transação e consulta o Mercado Pago).
    if (botao.dataset.pixExistente === "1") iniciar();
  }

  // Estados sem PIX pagável: o QR e o código antigos somem e a mesma janela oferece gerar um novo.
  const PIX_ENCERRADO = ["expirada", "cancelada", "recusada", "erro"];
  const MENSAGENS_PIX = {
    aguardando: ["info", "PIX válido. Pague com o QR Code ou o código Copia e Cola; você pode fechar esta janela e reabri-la pela cobrança."],
    processando: ["info", "O Mercado Pago está processando este PIX. Aguarde e atualize a situação."],
    confirmada: ["sucesso", "Pagamento identificado pelo Mercado Pago. A cobrança é atualizada assim que a confirmação for registrada."],
    conciliacao: ["aviso", "Este pagamento está em verificação. Nenhum novo PIX é gerado até a conferência."],
    expirada: ["aviso", "Este PIX expirou e não pode mais ser pago. Gere um novo PIX."],
    cancelada: ["aviso", "Este PIX foi cancelado e não pode mais ser pago. Gere um novo PIX."]
  };

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
    if (PIX_ENCERRADO.includes(String(dados.status || ""))) {
      estado.codigo = "";
      if (codigo) { codigo.value = ""; codigo.closest(".pix-copia")?.setAttribute("hidden", ""); }
      if (imagem) { imagem.removeAttribute("src"); imagem.hidden = true; }
      if (link) { link.removeAttribute("href"); link.hidden = true; }
      preparacao?.removeAttribute("hidden");
      const gerar = document.getElementById("btnGerarPixFaturamento");
      if (gerar) { gerar.disabled = false; gerar.innerHTML = '<i class="fa-brands fa-pix" aria-hidden="true"></i>Gerar novo PIX'; }
    }
    const [tipo, mensagem] = MENSAGENS_PIX[String(dados.status || "")] || [];
    if (mensagem) avisar(tipo, mensagem);
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
      if (!MENSAGENS_PIX[String(retorno.data?.status || "")]) avisar("sucesso", retorno.user_msg || "PIX disponível para pagamento.");
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
      if (!MENSAGENS_PIX[String(retorno.data?.status || "")]) avisar("info", retorno.user_msg || "Situação do PIX atualizada.");
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

  // Primeira mensalidade da contratação nova: paga via PIX (ou pelo suporte); o cartão só é cadastrado depois da ativação.
  // Autorizações no cartão criadas antes dessa regra continuam exibidas com o estado real que o servidor informa.
  // Os estados vêm do servidor; o navegador nunca decide que algo foi pago.
  const TEXTO_PRIMEIRA_PIX = "Para ativar seu plano, pague a primeira mensalidade via PIX. Para utilizar outra forma de pagamento, entre em contato com o suporte.";
  const NOTA_CARTAO_APOS_ATIVACAO = "Após a confirmação do pagamento e a ativação do plano, você poderá cadastrar um cartão para as próximas mensalidades automáticas.";
  const TEXTO_EM_VERIFICACAO = "O pagamento anterior ainda precisa de confirmação. Atualize a situação antes de realizar uma nova tentativa ou entre em contato com o suporte.";
  const SITUACOES_PRIMEIRA = {
    escolha: ["fa-brands fa-pix", "Aguardando pagamento", TEXTO_PRIMEIRA_PIX],
    pix_em_andamento: ["fa-brands fa-pix", "PIX em andamento", "Já existe um PIX gerado para esta cobrança. Conclua o pagamento ou aguarde a expiração para gerar outro."],
    aguardando_autorizacao: ["fa-solid fa-hourglass-half", "Autorização no cartão não concluída", "Há uma autorização no cartão iniciada anteriormente e ainda não concluída no Mercado Pago. A primeira mensalidade agora é paga via PIX: use \"Pagar com PIX em vez do cartão\" ou fale com o suporte."],
    autorizacao_em_analise: ["fa-solid fa-hourglass-half", "Autorizado no Mercado Pago — ativação em análise", "A autorização foi recebida e precisa de conferência antes da cobrança. O acesso só é liberado após o pagamento confirmado."],
    aguardando_primeira_cobranca: ["fa-solid fa-hourglass-half", "Aguardando a primeira cobrança", "O cartão foi autorizado. O acesso é liberado quando o Mercado Pago confirmar o pagamento da primeira mensalidade."],
    processando: ["fa-solid fa-hourglass-half", "Pagamento em processamento", "O Mercado Pago está processando a primeira mensalidade no cartão."],
    pagamento_recusado: ["fa-solid fa-triangle-exclamation", "Pagamento recusado", "O cartão recusou a primeira mensalidade. O Mercado Pago pode tentar novamente; se preferir, use \"Pagar com PIX em vez do cartão\"."],
    em_verificacao: ["fa-solid fa-triangle-exclamation", "Pagamento em verificação", TEXTO_EM_VERIFICACAO],
    cancelando_cartao: ["fa-solid fa-hourglass-half", "Cancelando o pagamento no cartão", "Aguardando a confirmação do Mercado Pago. O PIX será liberado em seguida."],
    cartao_cancelado: ["fa-regular fa-credit-card", "Pagamento no cartão cancelado", "A autorização anterior no cartão foi encerrada no Mercado Pago. " + TEXTO_PRIMEIRA_PIX],
    pagamento_confirmado: ["fa-solid fa-circle-check", "Pagamento confirmado", "A primeira mensalidade foi paga. A contratação é ativada automaticamente; se ainda não foi, use \"Concluir contratação\"."],
    indisponivel: ["fa-solid fa-triangle-exclamation", "Cobrança de contratação indisponível", "Atualize a página ou fale com o suporte."]
  };
  const cabecalhoCartao = (icone, rotulo, titulo, texto, nota = "") => `<div class="faturamento-cartao-identidade"><span class="faturamento-cartao-icone" aria-hidden="true"><i class="${icone}"></i></span><div><span>${esc(rotulo)}</span><strong>${esc(titulo)}</strong><small>${esc(texto)}</small>${nota ? `<small>${esc(nota)}</small>` : ""}</div></div>`;
  const botaoAutorizarCartao = rotulo => `<button class="botao-geral destaque" type="button" data-autorizar-assinatura><i class="fa-regular fa-credit-card" aria-hidden="true"></i>${esc(rotulo)}</button>`;

  // Título e subtítulo da seção acompanham o estado: primeira mensalidade, cadastro do cartão ou recorrência autorizada.
  function definirCabecalhoSecao(titulo, subtitulo) {
    const h2 = document.getElementById("tituloPagamentoAutomatico");
    const p = document.getElementById("subtituloPagamentoAutomatico");
    if (h2) h2.textContent = titulo;
    if (p) p.textContent = subtitulo;
  }

  function renderizarPrimeiraCobranca(alvo, atual, botaoVerificar) {
    const p = atual.primeira_cobranca || {};
    const [icone, titulo, texto] = SITUACOES_PRIMEIRA[p.situacao] || SITUACOES_PRIMEIRA.indisponivel;
    const rotulo = Number(p.valor) > 0 ? `Primeira mensalidade · ${moeda(p.valor)}` : "Primeira mensalidade";
    definirCabecalhoSecao("Pagamento da primeira mensalidade", "Efetue o primeiro pagamento para ativar seu plano.");
    // PIX já gerado: o botão reabre o mesmo PIX (o servidor o consulta no Mercado Pago antes de exibir).
    const pix = p.pode_pagar_pix && Number(p.id_cobranca) > 0
      ? `<button class="botao-geral destaque" type="button" data-pagar-cobranca="${esc(p.id_cobranca)}" data-pagar-valor="${esc(p.valor)}"${p.pix_em_andamento ? ' data-pix-existente="1"' : ""}><i class="fa-brands fa-pix" aria-hidden="true"></i>${p.pix_em_andamento ? "Ver PIX gerado" : "Pagar com PIX"}</button>` : "";
    // Autorização no cartão anterior a esta regra: só é possível desistir dela (o PIX volta depois do cancelamento confirmado).
    const trocar = p.pode_trocar_para_pix ? '<button class="botao-geral" type="button" data-usar-pix><i class="fa-brands fa-pix" aria-hidden="true"></i>Pagar com PIX em vez do cartão</button>' : "";
    const nota = p.situacao === "pagamento_confirmado" ? "" : NOTA_CARTAO_APOS_ATIVACAO;
    alvo.innerHTML = `${cabecalhoCartao(icone, rotulo, titulo, texto, nota)}<div class="faturamento-acoes">${pix}${trocar}${botaoVerificar}</div>`;
  }

  // Teste grátis (só contratos em modalidade teste; o servidor envia teste_gratis apenas para eles): cadastro de cartão
  // opcional, com a primeira mensalidade programada para depois do teste e sujeita à confirmação do Mercado Pago.
  const SITUACOES_TESTE = {
    escolha: ["fa-regular fa-calendar-check", "Nada é cobrado durante o teste", "Ao final do teste grátis, pague a primeira mensalidade via PIX para ativar o plano. Depois disso, você poderá cadastrar um cartão para as próximas mensalidades automáticas."],
    aguardando_autorizacao: ["fa-solid fa-hourglass-half", "Aguardando sua autorização", "Conclua a autorização do cartão no Mercado Pago. Seus dias grátis continuam valendo: a primeira mensalidade está programada para depois do teste grátis, sujeita à confirmação do Mercado Pago."],
    autorizacao_em_analise: ["fa-solid fa-hourglass-half", "Autorização em análise", "A autorização foi recebida no Mercado Pago e está sendo conferida. Seus dias grátis continuam valendo: a primeira mensalidade está programada para depois do teste grátis, sujeita à confirmação do Mercado Pago."],
    cartao_autorizado: ["fa-regular fa-credit-card", "Cartão autorizado para cobranças futuras", "Seu cartão está autorizado. A primeira mensalidade está prevista para após o encerramento do teste grátis."],
    processando: ["fa-solid fa-hourglass-half", "Primeira mensalidade em processamento", "O Mercado Pago está processando a primeira mensalidade no cartão. O plano pago é liberado após a confirmação."],
    pagamento_recusado: ["fa-solid fa-triangle-exclamation", "Pagamento recusado", "O cartão recusou a primeira mensalidade. O Mercado Pago pode tentar novamente; se preferir, pague com PIX em vez do cartão."],
    em_verificacao: ["fa-solid fa-triangle-exclamation", "Pagamento em verificação", "O pagamento precisa de conferência. Atualize a situação mais tarde ou fale com o suporte."],
    cancelando_cartao: ["fa-solid fa-hourglass-half", "Cancelando o cartão", "Aguardando a confirmação do Mercado Pago. Nenhuma mensalidade será cobrada no cartão depois dela."],
    cartao_cancelado: ["fa-regular fa-credit-card", "Cartão cancelado", "Nenhuma mensalidade será cobrada neste cartão."]
  };

  function renderizarTeste(alvo, atual, botaoVerificar) {
    const t = atual.teste_gratis || {};
    let [icone, titulo, texto] = SITUACOES_TESTE[t.situacao] || SITUACOES_TESTE.em_verificacao;
    const prevista = dataBr(t.primeira_cobranca_prevista);
    const fim = dataBr(t.expira_em);
    const rotulo = t.em_teste ? `Teste grátis${fim ? ` até ${fim}` : ""}` : "Teste grátis encerrado";
    // Teste grátis ativo: cadastro opcional para as mensalidades depois do teste (elegibilidade e liberação do servidor).
    const cadastro = atual.cadastro_cartao || {};
    definirCabecalhoSecao("Pagamento automático", cadastro.caminho === "teste"
      ? "O cadastro do cartão é opcional durante o teste grátis."
      : "O cartão pode ser cadastrado depois da primeira mensalidade paga.");
    let nota = "";
    if (prevista && ["aguardando_autorizacao", "autorizacao_em_analise", "cartao_autorizado"].includes(t.situacao)) {
      texto += ` Primeira mensalidade prevista para ${prevista}.`;
    }
    if (t.em_teste && t.situacao === "escolha" && atual.pode_configurar) {
      titulo = "Cadastro de cartão opcional";
      // Sem promessa de que nada é cobrado antes do fim do teste: a data é a programada, confirmada pelo Mercado Pago.
      texto = "Você pode cadastrar um cartão agora para as mensalidades depois do teste grátis. Seus dias grátis continuam valendo e você pode cancelar o cartão antes do fim do teste.";
      const inicio = dataBr(cadastro.primeira_cobranca_prevista);
      nota = `${inicio ? `Primeira cobrança automática prevista para ${inicio}, sujeita à confirmação do Mercado Pago. ` : ""}Se preferir, pague via PIX ao fim do teste.`;
    } else if (t.em_teste && t.situacao === "escolha" && cadastro.elegivel === true) {
      nota = "O cadastro do cartão estará disponível em breve. Até lá, a primeira mensalidade é paga via PIX ao fim do teste.";
    }
    if (!t.em_teste && ["escolha", "cartao_cancelado"].includes(t.situacao)) {
      texto = "Sem cartão para cobrar a primeira mensalidade. Use \"Gerar cobrança de regularização\" para pagar com PIX.";
    }
    const criada = atual.autorizacao_criada === true && !["cancelada", "encerrada"].includes(String(atual.status || ""));
    // Autorização confirmada: "Alterar cartão" substitui o cadastro (o servidor só oferece a troca para a recorrência ativa);
    // os dois nunca aparecem juntos, mesmo que o servidor sinalize ambos.
    const trocar = atual.pode_trocar_cartao
      ? '<button class="botao-geral" type="button" data-trocar-cartao><i class="fa-regular fa-credit-card" aria-hidden="true"></i>Alterar cartão</button>' : "";
    const autorizar = atual.pode_configurar && !trocar ? botaoAutorizarCartao(criada ? "Continuar no Mercado Pago" : "Cadastrar cartão") : "";
    const remover = t.pode_remover_cartao
      ? `<button class="botao-geral" type="button" data-usar-pix><i class="fa-regular fa-credit-card" aria-hidden="true"></i>${t.em_teste ? "Cancelar cartão autorizado" : "Pagar com PIX em vez do cartão"}</button>` : "";
    alvo.innerHTML = `${cabecalhoCartao(icone, rotulo, titulo, texto, nota)}<div class="faturamento-acoes">${autorizar}${trocar}${remover}${botaoVerificar}</div>`;
  }

  async function usarPix() {
    const teste = cartao.configuracao?.teste_gratis;
    if (cartao.redirecionando || !(cartao.configuracao?.primeira_cobranca?.pode_trocar_para_pix || teste?.pode_remover_cartao)) return;
    const [tituloConfirmacao, mensagem] = teste && teste.em_teste
      ? ["Cancelar cartão autorizado", "A autorização do cartão será cancelada no Mercado Pago e nenhuma mensalidade será cobrada nele. Seu teste grátis continua até o fim. Deseja continuar?"]
      : ["Pagar com PIX em vez do cartão", "O pagamento no cartão desta contratação será cancelado no Mercado Pago e não poderá ser retomado para este contrato. Depois da confirmação, a primeira mensalidade poderá ser paga com PIX. Deseja continuar?"];
    const confirmado = window.MensagemSistema?.confirmar
      ? await window.MensagemSistema.confirmar(mensagem, { titulo: tituloConfirmacao, textoConfirmar: "Cancelar o cartão" })
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
    if (atual.teste_gratis) { renderizarTeste(alvo, atual, botaoVerificar); return; }
    const status = String(atual.status || "nao_configurado");
    if (atual.configurado) {
      definirCabecalhoSecao("Pagamento automático", "Suas próximas mensalidades estão configuradas para cobrança automática.");
      // Bandeira e final só chegam do servidor depois de confirmados pela consulta da Preapproval.
      const bandeira = /^[a-z0-9_]{2,30}$/i.test(String(atual.bandeira || "")) ? String(atual.bandeira).toUpperCase() : "";
      const final = /^[0-9]{4}$/.test(String(atual.final_cartao || "")) ? String(atual.final_cartao) : "";
      const titulo = bandeira && final ? `${bandeira} •••• ${final}` : (bandeira || "Cartão autorizado no Mercado Pago");
      // Próxima cobrança só quando sincronizada do Mercado Pago.
      const proxima = dataBr(atual.proxima_cobranca_em);
      const texto = `Recorrência autorizada no Mercado Pago.${proxima ? ` Próxima cobrança: ${proxima}.` : ""}`;
      const trocar = atual.pode_trocar_cartao
        ? '<button class="botao-geral destaque" type="button" data-trocar-cartao><i class="fa-regular fa-credit-card" aria-hidden="true"></i>Alterar cartão</button>' : "";
      alvo.innerHTML = `${cabecalho("fa-regular fa-credit-card", "Cartão das próximas mensalidades", titulo, texto)}<div class="faturamento-acoes">${trocar}<span class="faturamento-status faturamento-status--ativa">Ativo</span></div>`;
      return;
    }
    definirCabecalhoSecao("Pagamento automático", "Configure um cartão para automatizar as próximas mensalidades.");
    if (status === "pendente") {
      // Pendente com Preapproval já criada: o servidor devolve o mesmo link (sem nova criação). Pendente sem Preapproval
      // criada (tentativa anterior falhou ou ficou incerta): o servidor concilia e só então cria, uma única vez.
      const criada = atual.autorizacao_criada !== false;
      if (criada && atual.status_externo === "authorized") {
        // Autorizada no provedor, mas ainda não ativada aqui (ex.: próxima cobrança fora da competência esperada): nunca "concluído".
        alvo.innerHTML = `${cabecalho("fa-solid fa-hourglass-half", "Pagamento automático", "Autorização em conferência", "O Mercado Pago registrou a autorização do cartão. O pagamento automático só é ativado depois de confirmada a próxima cobrança fora do período já pago. Atualize a situação ou entre em contato com o suporte.")}<div class="faturamento-acoes">${botaoVerificar}</div>`;
        return;
      }
      const titulo = criada ? "Aguardando sua autorização" : "Autorização ainda não criada";
      const texto = criada
        ? "A autorização só vale depois da confirmação do Mercado Pago."
        : "A autorização ainda não foi criada no Mercado Pago. Clique para tentar novamente; a tentativa anterior é conferida antes de qualquer nova criação.";
      const semAcao = !atual.pode_configurar && !atual.pode_verificar
        ? (atual.subscriptions_habilitado === false ? "O pagamento automático por este meio ainda não está disponível." : "Atualize a página ou fale com o suporte caso a situação persista.")
        : (!atual.pode_configurar && criada ? "O Mercado Pago já registrou outra situação para esta autorização. Atualize para conferir." : "");
      alvo.innerHTML = `${cabecalho("fa-solid fa-hourglass-half", "Autorização pendente", titulo, semAcao || texto)}<div class="faturamento-acoes">${atual.pode_configurar ? botaoAutorizar(criada ? "Continuar no Mercado Pago" : "Cadastrar cartão") : ""}${botaoVerificar}</div>`;
      return;
    }
    // Autorização cancelada (confirmada no provedor) segue para o cadastro abaixo; os demais estados reais são exibidos.
    const mensagens = {
      cancelamento_pendente: ["fa-solid fa-hourglass-half", "Cancelamento em andamento", "O cancelamento do pagamento automático está sendo confirmado."],
      suspensa: ["fa-solid fa-circle-pause", "Pagamento automático pausado", "As cobranças automáticas estão pausadas."],
      atualizacao_necessaria: ["fa-solid fa-triangle-exclamation", "Atualização necessária", "O pagamento automático precisa de atenção. Atualize a situação."],
      // Ativo localmente, mas sem autorização confirmada pelo Mercado Pago (ex.: pausada no provedor): não é "sem cartão".
      ativa: ["fa-solid fa-triangle-exclamation", "Atualização necessária", "O Mercado Pago não confirma a autorização ativa do cartão. Atualize a situação ou entre em contato com o suporte."],
      erro: ["fa-solid fa-triangle-exclamation", "Não foi possível concluir", TEXTO_EM_VERIFICACAO]
    };
    if (mensagens[status]) {
      const [icone, titulo, texto] = mensagens[status];
      alvo.innerHTML = `${cabecalho(icone, "Pagamento automático", titulo, texto)}<div class="faturamento-acoes">${atual.pode_configurar ? botaoAutorizar("Cadastrar cartão") : ""}${botaoVerificar}</div>`;
      return;
    }
    // Primeira mensalidade paga e sem recorrência: a elegibilidade e a liberação do cadastro vêm do servidor.
    const cadastro = atual.cadastro_cartao || {};
    let texto = "Seu primeiro pagamento foi confirmado. Você poderá cadastrar um cartão de crédito para as próximas renovações automáticas.";
    let nota = "";
    if (atual.subscriptions_habilitado === false) texto = "O pagamento automático por este meio ainda não está disponível.";
    else if (cadastro.elegivel !== true) texto = String(cadastro.mensagem || "O cadastro do cartão fica disponível com o plano ativo, depois da primeira mensalidade paga.");
    else if (!atual.pode_configurar) nota = "O cadastro do cartão estará disponível em breve. Até lá, pague as mensalidades via PIX.";
    else {
      // Sem promessa de que a competência paga nunca é cobrada: a data é a programada, confirmada pelo Mercado Pago.
      texto = "Seu plano está ativo. Cadastre um cartão para automatizar as próximas mensalidades.";
      const prevista = dataBr(cadastro.primeira_cobranca_prevista);
      nota = `${prevista ? `Primeira cobrança automática prevista para ${prevista}, sujeita à confirmação do Mercado Pago. ` : ""}Se preferir, continue pagando via PIX.`;
    }
    const titulo = status === "cancelada" ? "Pagamento automático cancelado" : "Nenhum cartão cadastrado";
    alvo.innerHTML = `${cabecalho("fa-regular fa-credit-card", "Pagamento automático", titulo, texto, nota)}<div class="faturamento-acoes">${atual.pode_configurar ? botaoAutorizar("Cadastrar cartão") : ""}${botaoVerificar}</div>`;
  }

  // outroCartao: checkout anterior abandonado; o servidor encerra a autorização pendente e só então cria outra.
  async function iniciarAutorizacaoAssinatura(outroCartao = false) {
    if (cartao.redirecionando || !(outroCartao ? cartao.configuracao?.pode_tentar_outro_cartao : cartao.configuracao?.pode_configurar)) return;
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) { avisar("erro", "Atualize a página para renovar sua sessão."); return; }
    if (outroCartao) {
      const mensagem = "A autorização anterior, ainda não concluída, será cancelada no Mercado Pago e você será levado a um novo checkout para usar outro cartão. A primeira mensalidade continua sendo a mesma cobrança. Deseja continuar?";
      const confirmado = window.MensagemSistema?.confirmar
        ? await window.MensagemSistema.confirmar(mensagem, { titulo: "Tentar outro cartão de crédito", textoConfirmar: "Tentar outro cartão" })
        : window.confirm(mensagem);
      if (!confirmado) return;
    }
    cartao.redirecionando = true;
    const botoes = document.querySelectorAll("[data-autorizar-assinatura],[data-outro-cartao]");
    botoes.forEach(b => { b.disabled = true; });
    try {
      // Sem corpo contratual: empresa, valor e contrato são sempre os do servidor.
      const corpo = new FormData();
      if (outroCartao) corpo.append("outro_cartao", "1");
      const resposta = await fetch(CARTAO_AUTORIZAR, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
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

  // Recusa, "sem alteração" e token inválido mantêm o modal aberto para nova tentativa; o cartão anterior segue ativo.
  // Incerteza, divergência ou bloqueio fecham o modal: o estado real precisa ser conferido antes de outra troca.
  async function trocarCartao(formData) {
    let token = String(formData?.token || "");
    const csrf = String(window.__AUTH__?.csrf_token || "");
    if (!/^[a-f0-9]{64}$/.test(csrf)) throw new Error("Atualize a página para renovar sua sessão.");
    if (!/^[A-Za-z0-9_-]{32,33}$/.test(token)) throw new Error("Não foi possível tokenizar o cartão. Confira os dados informados.");
    if (cartao.trocando) throw new Error("A troca do cartão já está em andamento.");
    cartao.trocando = true;
    const corpo = new FormData();
    corpo.set("token", token);
    try {
      const resposta = await fetch(CARTAO_TROCAR, { method: "POST", body: corpo, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "X-CSRF-Token": csrf } });
      const retorno = await resposta.json().catch(() => ({}));
      if (retorno?.data) renderizarCartao(retorno.data);
      if (resposta.ok && retorno.ok === true) {
        avisar("sucesso", retorno.user_msg || "Cartão alterado.");
        fecharModalId("modalCartaoRecorrenteFaturamento");
        await desmontarBrick();
        return;
      }
      const mensagem = retorno.user_msg || "Não foi possível trocar o cartão.";
      if (["CARTAO_TROCA_INCERTA", "CARTAO_TROCA_DIVERGENTE", "CARTAO_TROCA_BLOQUEADA"].includes(retorno.code)) {
        avisar("aviso", mensagem);
        fecharModalId("modalCartaoRecorrenteFaturamento");
        await desmontarBrick();
        return;
      }
      throw new Error(mensagem);
    } finally {
      corpo.delete("token");
      token = "";
      cartao.trocando = false;
    }
  }

  function aplicarTextosModalCartao(modo) {
    const textos = TEXTOS_MODAL_CARTAO[modo] || TEXTOS_MODAL_CARTAO.configurar;
    const titulo = document.getElementById("tituloModalCartaoRecorrente");
    const consentimento = document.getElementById("cartaoConsentimentoTexto");
    if (titulo) titulo.textContent = textos.titulo;
    if (consentimento) consentimento.textContent = textos.consentimento;
    return textos;
  }

  async function renderizarBrick(modo = "configurar") {
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
          visual: { style: { theme: "default" }, texts: { formTitle: "Cartão de crédito", installmentsSectionTitle: "", formSubmit: aplicarTextosModalCartao(modo).enviar } }
        },
        callbacks: {
          onReady: () => carregando?.setAttribute("hidden", ""),
          onSubmit: formData => new Promise((resolve, reject) => {
            (modo === "trocar" ? trocarCartao(formData) : autorizarCartao(formData)).then(resolve).catch(erroEnvio => { avisar("erro", erroEnvio.message); reject(); });
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
    await renderizarBrick("configurar");
  }

  async function abrirTrocaCartao() {
    if (cartao.trocando || !cartao.configuracao?.pode_trocar_cartao || cartao.configuracao?.motor_recorrencia !== "preapproval") return;
    aplicarTextosModalCartao("trocar");
    abrirModalId("modalCartaoRecorrenteFaturamento");
    await renderizarBrick("trocar");
  }

  async function atualizarCartao() {
    if (cartao.configuracao?.motor_recorrencia === "preapproval") {
      if (cartao.carregando) return;
      // Se a página ainda está aqui, nenhum redirecionamento ao Mercado Pago está em curso (ex.: checkout fechado).
      cartao.redirecionando = false;
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
    if (evento.target.closest("[data-trocar-cartao]")) abrirTrocaCartao();
    if (evento.target.closest("[data-atualizar-cartao]")) atualizarCartao();
    if (evento.target.closest("[data-autorizar-assinatura]")) iniciarAutorizacaoAssinatura();
    if (evento.target.closest("[data-outro-cartao]")) iniciarAutorizacaoAssinatura(true);
    if (evento.target.closest("[data-usar-pix]")) usarPix();
    if (evento.target.closest("#modalCartaoRecorrenteFaturamento [data-fechar-modal]")) desmontarBrick();
  });
  document.addEventListener("keydown", evento => { if (evento.key === "Escape") desmontarBrick(); });
  // Fechar o modal PIX é só visual (nada é cancelado no provedor nem no banco). Se um PIX foi exibido, a tela do
  // Faturamento e a seção do cartão são recarregadas do servidor para refletir um eventual pagamento.
  function aoFecharModalPix() {
    if (!(estado.idTransacao > 0)) return;
    estado.idTransacao = 0;
    document.dispatchEvent(new CustomEvent("amagenda:faturamento-atualizar"));
    carregarConfiguracaoCartao();
  }
  document.addEventListener("click", evento => {
    if (evento.target.closest("#modalPagamentoPixFaturamento [data-fechar-modal]")) aoFecharModalPix();
  });
  document.addEventListener("keydown", evento => {
    if (evento.key !== "Escape") return;
    setTimeout(() => { if (!document.getElementById("modalPagamentoPixFaturamento")?.classList.contains("ativo")) aoFecharModalPix(); }, 0);
  });
  document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("btnGerarPixFaturamento")?.addEventListener("click", iniciar);
    document.getElementById("btnAtualizarPixFaturamento")?.addEventListener("click", consultar);
    document.getElementById("btnCopiarPixFaturamento")?.addEventListener("click", copiar);
    document.addEventListener("amagenda:painel-aba-alterada", evento => { if (evento.detail?.aba === "faturamento") carregarConfiguracaoCartao(); });
    document.addEventListener("amagenda:sessao-carregada", () => { tentarRetornoAssinatura(); carregarConfiguracaoCartao(); });
    tentarRetornoAssinatura();
    if (!cartao.retornoProcessado && document.getElementById("faturamento")?.classList.contains("ativa")) carregarConfiguracaoCartao();
  });
  // Voltar do checkout pelo navegador restaura a página do cache com o estado "redirecionando" e os botões desativados:
  // libera a tela e consulta o servidor de novo (a URL não prova nada; o estado vem do backend).
  window.addEventListener("pageshow", evento => {
    if (!evento.persisted) return;
    cartao.redirecionando = false;
    cartao.carregando = false;
    document.querySelectorAll("[data-autorizar-assinatura],[data-outro-cartao],[data-usar-pix]").forEach(b => { b.disabled = false; });
    if (document.getElementById("faturamento")?.classList.contains("ativa")) carregarConfiguracaoCartao();
  });
})();
