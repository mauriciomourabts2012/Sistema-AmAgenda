(() => {
  "use strict";

  if (!("serviceWorker" in navigator)) return;

  const INTERVALO_VERIFICACAO = 30 * 60 * 1000;
  // Ao voltar para a aba (foco/visibilidade), consulta se a última consulta tiver mais de 15 s.
  const INTERVALO_MINIMO_RETORNO = 15 * 1000;
  const EVENTO_REGISTRO_PRONTO = "amagenda:pwa-registro-pronto";
  const VERSAO_VALIDA = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/;
  const scriptUrl = String(document.currentScript?.src || "");
  const ICONE_ATUALIZACAO = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12a9 9 0 0 1-15.5 6.2"/><path d="M3 12a9 9 0 0 1 15.5-6.2"/><path d="M18.5 2.5v3.7h-3.7"/><path d="M5.5 21.5v-3.7h3.7"/></svg>';

  let registroAtual = null;
  let registroObservado = null;
  let avisoAtual = null;
  let intervaloId = null;
  let verificacaoEmAndamento = null;
  let versaoSolicitada = "";
  let solicitouAtivacao = false;
  let recarregando = false;
  let tipoAvisoAtual = "";
  let versaoCentralConhecida = "";
  let versaoDispensada = "";
  let ultimaVerificacao = 0;
  const workersAcompanhados = new WeakSet();

  function obterVersaoAtual() {
    try {
      const versao = new URL(scriptUrl).searchParams.get("v") || "";
      return VERSAO_VALIDA.test(versao) ? versao : "";
    } catch (_) {
      return "";
    }
  }

  function obterVersaoWorker(worker) {
    try {
      const versao = new URL(String(worker?.scriptURL || "")).searchParams.get("v") || "";
      return VERSAO_VALIDA.test(versao) ? versao : "";
    } catch (_) {
      return "";
    }
  }

  function compararVersoes(versaoA, versaoB) {
    const separar = (versao) => {
      const semBuild = versao.split("+")[0];
      const indicePre = semBuild.indexOf("-");
      const principal = indicePre >= 0 ? semBuild.slice(0, indicePre) : semBuild;
      const pre = indicePre >= 0 ? semBuild.slice(indicePre + 1).split(".") : [];
      return { principal: principal.split(".").map(Number), pre };
    };
    const a = separar(versaoA);
    const b = separar(versaoB);

    for (let indice = 0; indice < 3; indice += 1) {
      if (a.principal[indice] !== b.principal[indice]) {
        return a.principal[indice] > b.principal[indice] ? 1 : -1;
      }
    }
    if (a.pre.length === 0 || b.pre.length === 0) {
      if (a.pre.length === b.pre.length) return 0;
      return a.pre.length === 0 ? 1 : -1;
    }
    const total = Math.max(a.pre.length, b.pre.length);
    for (let indice = 0; indice < total; indice += 1) {
      if (a.pre[indice] === undefined) return -1;
      if (b.pre[indice] === undefined) return 1;
      if (a.pre[indice] === b.pre[indice]) continue;
      const aNumerico = /^\d+$/.test(a.pre[indice]);
      const bNumerico = /^\d+$/.test(b.pre[indice]);
      if (aNumerico && bNumerico) return Number(a.pre[indice]) > Number(b.pre[indice]) ? 1 : -1;
      if (aNumerico !== bNumerico) return aNumerico ? -1 : 1;
      return a.pre[indice] > b.pre[indice] ? 1 : -1;
    }
    return 0;
  }

  function workerRepresentaAtualizacao(worker, registro = registroAtual) {
    const versaoWorker = obterVersaoWorker(worker);
    if (!versaoWorker) return false;
    const versoesAtuais = [
      obterVersaoAtual(),
      obterVersaoWorker(registro?.active),
      obterVersaoWorker(navigator.serviceWorker.controller),
    ].filter(Boolean);
    return versoesAtuais.length > 0
      && versoesAtuais.every((versao) => compararVersoes(versaoWorker, versao) > 0);
  }

  // A interface em execução corresponde aos assets carregados pela página.
  // Workers servem para escolher a ação, não para considerar esta aba atualizada.
  function versaoEmUso() {
    return obterVersaoAtual();
  }

  function workerTemVersaoIgualOuSuperior(worker, versaoAlvo) {
    const versaoWorker = obterVersaoWorker(worker);
    return Boolean(versaoWorker)
      && compararVersoes(versaoWorker, versaoAlvo) >= 0;
  }

  // Variante visual própria (mensagem-erro-universal.css): não altera os demais
  // avisos do MensagemSistema. A mensagem é informativa, não um alerta crítico.
  function aplicarVisualAtualizacao(alerta) {
    if (!alerta) return;
    alerta.classList?.add?.("ui-alert--pwa-atualizacao");
    alerta.setAttribute?.("role", "status");
    // Ícone de atualização em SVG estático (não depende do Font Awesome/CDN).
    const icone = alerta.querySelector?.(".ui-alert__icon");
    if (icone) icone.innerHTML = ICONE_ATUALIZACAO;
  }

  function removerAvisoAtual() {
    if (avisoAtual?.isConnected) avisoAtual.remove();
    avisoAtual = null;
    tipoAvisoAtual = "";
  }

  function criarAviso({ tipo, titulo, mensagem, textoAcao, aoAcionar }) {
    if (!window.MensagemSistema?.aviso) return null;
    removerAvisoAtual();

    const alerta = window.MensagemSistema.aviso(mensagem, {
      titulo,
      persistente: true,
      textoBotao: "Depois",
      focar: false,
      aoFechar: () => {
        // "Depois": o retorno à aba não reexibe esta versão; o polling periódico sim.
        versaoDispensada = versaoCentralConhecida;
        if (avisoAtual === alerta) {
          avisoAtual = null;
          tipoAvisoAtual = "";
        }
      },
    });
    aplicarVisualAtualizacao(alerta);
    const acoes = alerta?.querySelector?.(".ui-alert__actions");
    const botaoDepois = acoes?.querySelector?.("button");
    if (!acoes || !botaoDepois) return alerta || null;

    botaoDepois.classList.remove("ui-alert__btn--primary");
    botaoDepois.classList.add("ui-alert__btn--secondary");

    const botaoAcao = document.createElement("button");
    botaoAcao.type = "button";
    botaoAcao.className = "ui-alert__btn ui-alert__btn--primary";
    botaoAcao.textContent = textoAcao;
    botaoAcao.addEventListener("click", async () => {
      botaoAcao.disabled = true;
      const concluido = await aoAcionar();
      if (concluido === false && botaoAcao.isConnected) botaoAcao.disabled = false;
    });
    acoes.append(botaoAcao);
    avisoAtual = alerta;
    tipoAvisoAtual = tipo;
    return alerta;
  }

  function mostrarAtualizacaoDisponivel(worker = registroAtual?.waiting, registro = registroAtual) {
    if (!workerRepresentaAtualizacao(worker, registro)) return null;
    if (avisoAtual?.isConnected && tipoAvisoAtual === "disponivel") return avisoAtual;
    return criarAviso({
      tipo: "disponivel",
      titulo: "Nova versão disponível",
      mensagem: "Uma nova versão do AmAgenda está pronta. Você pode atualizar agora ou continuar trabalhando.",
      textoAcao: "Atualizar agora",
      aoAcionar: atualizarAgora,
    });
  }

  function mostrarAtualizacaoAplicadaEmOutraAba() {
    if (avisoAtual?.isConnected && tipoAvisoAtual === "aplicada") return avisoAtual;
    return criarAviso({
      tipo: "aplicada",
      titulo: "Nova versão aplicada",
      mensagem: "Outra aba atualizou o AmAgenda. Recarregue esta página quando puder para usar a nova versão.",
      textoAcao: "Recarregar agora",
      aoAcionar: () => {
        if (recarregando) return true;
        recarregando = true;
        window.location.reload();
        return true;
      },
    });
  }

  async function atualizarAgora() {
    const workerEmEspera = registroAtual?.waiting;
    if (!workerEmEspera) {
      window.MensagemSistema?.erro?.("A atualização ainda não está pronta. Tente novamente em instantes.");
      return false;
    }

    try {
      solicitouAtivacao = true;
      workerEmEspera.postMessage({ tipo: "ATIVAR_NOVA_VERSAO" });
      return true;
    } catch (_) {
      solicitouAtivacao = false;
      window.MensagemSistema?.erro?.("Não foi possível iniciar a atualização. Continue usando o sistema e tente novamente.");
      return false;
    }
  }

  // Acompanha o worker em instalação até ficar "installed" (waiting) ou "activated"
  // e então refaz a decisão. Não exige controller: uma página aberta com
  // Ctrl+Shift+R não é controlada pelo SW, mas continua desatualizada.
  function acompanharWorker(worker) {
    if (!worker || workersAcompanhados.has(worker)) return;
    workersAcompanhados.add(worker);
    worker.addEventListener("statechange", () => {
      if (worker.state === "installed" || worker.state === "activated") {
        verificarVersao();
      }
    });
  }

  function observarInstalacao(registro) {
    acompanharWorker(registro?.installing);
  }

  function iniciarVerificacaoPeriodica() {
    if (intervaloId !== null) return;
    intervaloId = setInterval(() => {
      verificarVersao();
    }, INTERVALO_VERIFICACAO);
  }

  function anexarRegistro(registro, { verificar = true } = {}) {
    if (!registro) return null;
    registroAtual = registro;

    if (registro !== registroObservado) {
      registroObservado = registro;
      registro.addEventListener("updatefound", () => observarInstalacao(registro));
    }
    observarInstalacao(registro);

    iniciarVerificacaoPeriodica();
    if (verificar) verificarVersao();
    return registro;
  }

  // Aba que já estava aberta antes da nova release: verifica ao voltar para ela,
  // sem esperar o polling de 30 min. Só atua depois que o registro do PWA foi
  // autorizado para esta sessão (registroAtual), preservando Super Admin/Modo Suporte.
  function verificarAoRetornar() {
    if (!registroAtual || recarregando) return;
    if (document.visibilityState && document.visibilityState !== "visible") return;
    if (Date.now() - ultimaVerificacao < INTERVALO_MINIMO_RETORNO) return;
    verificarVersao({ respeitarDispensa: true });
  }

  async function consultarVersaoCentral() {
    const resposta = await fetch("/app-version.php", {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    if (!resposta.ok) throw new Error("Falha ao consultar a versão do aplicativo.");
    const dados = await resposta.json();
    const versao = String(dados?.version || "");
    if (!VERSAO_VALIDA.test(versao)) throw new Error("Versão do aplicativo inválida.");
    return versao;
  }

  // Escolhe a mensagem/ação para uma aba já desatualizada. Retorna true quando
  // não é preciso registrar o worker da nova versão.
  function aplicarDecisao(registro, novaVersao, respeitarDispensa) {
    const silenciar = respeitarDispensa && versaoDispensada === novaVersao;
    if (workerTemVersaoIgualOuSuperior(registro?.active, novaVersao)
      || workerTemVersaoIgualOuSuperior(navigator.serviceWorker.controller, novaVersao)) {
      if (!silenciar) mostrarAtualizacaoAplicadaEmOutraAba();
      return true;
    }
    if (workerTemVersaoIgualOuSuperior(registro?.waiting, novaVersao)) {
      if (!silenciar) mostrarAtualizacaoDisponivel(registro.waiting, registro);
      return true;
    }
    if (workerTemVersaoIgualOuSuperior(registro?.installing, novaVersao)) {
      acompanharWorker(registro.installing);
      return true;
    }
    return false;
  }

  // Regra única: a aba está desatualizada quando a versão central (app-version.php)
  // é maior que a versão com que ESTA página foi carregada (?v= do script).
  // O Service Worker só define qual mensagem/ação usar.
  async function verificarVersao(opcoes = {}) {
    if (verificacaoEmAndamento) return verificacaoEmAndamento;
    const respeitarDispensa = opcoes.respeitarDispensa === true;
    ultimaVerificacao = Date.now();
    verificacaoEmAndamento = (async () => {
      try {
        const novaVersao = await consultarVersaoCentral();
        versaoCentralConhecida = novaVersao;
        const versaoPagina = versaoEmUso();
        if (versaoPagina && compararVersoes(novaVersao, versaoPagina) <= 0) return null;

        if (aplicarDecisao(registroAtual, novaVersao, respeitarDispensa)) return registroAtual;

        const apiRegistro = window.AmAgendaRegistroPWA;
        if (!apiRegistro?.registrarVersao) return null;
        const registro = await apiRegistro.registrarVersao(novaVersao);
        versaoSolicitada = novaVersao;
        anexarRegistro(registro, { verificar: false });
        // O register() pode resolver antes ou depois de o worker sair de "installing".
        aplicarDecisao(registro, novaVersao, respeitarDispensa);
        return registro;
      } catch (_) {
        // A verificação é auxiliar e nunca bloqueia o uso normal do sistema.
        return null;
      } finally {
        verificacaoEmAndamento = null;
      }
    })();
    return verificacaoEmAndamento;
  }

  navigator.serviceWorker.addEventListener("controllerchange", () => {
    if (solicitouAtivacao) {
      if (recarregando) return;
      recarregando = true;
      window.location.reload();
      return;
    }
    verificarVersao();
  });

  document.addEventListener("visibilitychange", verificarAoRetornar);
  window.addEventListener("focus", verificarAoRetornar);
  window.addEventListener("pageshow", verificarAoRetornar);

  window.addEventListener(EVENTO_REGISTRO_PRONTO, (evento) => {
    anexarRegistro(evento?.detail?.registro || null);
  });

  window.AmAgendaAtualizacaoPWA = {
    anexarRegistro,
    verificarVersao,
    atualizarAgora,
    obterEstado: () => ({
      registroAtual,
      versaoAtual: obterVersaoAtual(),
      versaoEmUso: versaoEmUso(),
      versaoAtiva: obterVersaoWorker(registroAtual?.active),
      versaoEmEspera: obterVersaoWorker(registroAtual?.waiting),
      versaoCentral: versaoCentralConhecida,
      versaoSolicitada,
      solicitouAtivacao,
      recarregando,
    }),
  };

  anexarRegistro(window.AmAgendaRegistroPWA?.obterRegistro?.() || null);
})();
