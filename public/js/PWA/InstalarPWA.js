(() => {
  "use strict";

  const EVENTO_ATUALIZACAO = "amagenda:pwa-instalacao-atualizada";
  const CONTEXTOS_PERMITIDOS = new Set([
    "agenda",
    "painel-administrativo",
    "super-admin",
    "agenda-online",
    "cliente",
  ]);
  const PERFIS_PERMITIDOS = new Set(["proprietario", "recepcionista", "profissional"]);
  const mediaStandalone = window.matchMedia("(display-mode: standalone)");

  let eventoInstalacao = null;
  let instaladoNestaSessao = false;
  let focoAnterior = null;

  function normalizar(valor) {
    return String(valor || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
  }

  function contextoAtual() {
    return String(document.body?.dataset?.menuContexto || "").toLowerCase();
  }

  function sessaoElegivel() {
    const contexto = contextoAtual();
    if (contexto === "agenda-online") return true;

    const auth = window.__AUTH__;
    if (!auth || typeof auth !== "object") return false;

    const tipo = normalizar(auth.tipo_usuario);
    const perfil = normalizar(auth.perfil_nome || auth.perfil);
    if (contexto === "cliente") return tipo === "cliente";
    if (tipo === "cliente") return false;
    if (tipo === "super_admin") return true;
    return PERFIS_PERMITIDOS.has(perfil);
  }

  function executandoComoAplicativo() {
    return instaladoNestaSessao
      || mediaStandalone.matches
      || navigator.standalone === true;
  }

  function dispositivoIOS() {
    const agente = String(navigator.userAgent || "");
    const plataforma = String(navigator.platform || "");
    return /iPad|iPhone|iPod/i.test(agente)
      || (plataforma === "MacIntel" && Number(navigator.maxTouchPoints || 0) > 1);
  }

  function obterEstado() {
    const elegivel = CONTEXTOS_PERMITIDOS.has(contextoAtual()) && sessaoElegivel();
    const standalone = executandoComoAplicativo();
    const ios = dispositivoIOS();
    return {
      elegivel,
      standalone,
      ios,
      chromiumDisponivel: Boolean(eventoInstalacao),
      visivel: elegivel && !standalone && (ios || Boolean(eventoInstalacao)),
    };
  }

  function obterBotaoCabecalho() {
    let botao = document.querySelector("[data-pwa-instalar]");
    if (botao) return botao;

    const notificacoes = document.querySelector(".cabecalho-direita .cabecalho-notificacoes");
    const containerPublico = document.querySelector("[data-pwa-instalar-container]");
    if (!notificacoes?.parentNode && !containerPublico) return null;

    botao = document.createElement("button");
    botao.type = "button";
    botao.className = "botao-geral cabecalho-instalar-pwa";
    botao.dataset.pwaInstalar = "";
    botao.title = "Instalar App";
    botao.setAttribute("aria-label", "Instalar App");
    botao.innerHTML = '<svg class="pwa-instalar-icone" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg><span class="btn-texto">Instalar App</span>';
    botao.hidden = true;
    botao.addEventListener("click", instalar);
    if (notificacoes?.parentNode) {
      notificacoes.parentNode.insertBefore(botao, notificacoes);
    } else {
      containerPublico.appendChild(botao);
    }
    return botao;
  }

  function notificarAtualizacao() {
    const estado = obterEstado();
    const botao = obterBotaoCabecalho();
    if (botao) botao.hidden = !estado.visivel;
    document.dispatchEvent(new CustomEvent(EVENTO_ATUALIZACAO, { detail: estado }));
  }

  function fecharOrientacaoIOS() {
    const modal = document.getElementById("modalInstalacaoIOS");
    if (!modal) return;
    modal.classList.remove("ativo");
    modal.setAttribute("aria-hidden", "true");
    focoAnterior?.focus?.({ preventScroll: true });
    focoAnterior = null;
  }

  function obterModalIOS() {
    let modal = document.getElementById("modalInstalacaoIOS");
    if (modal) return modal;

    document.body.insertAdjacentHTML("beforeend", `
      <div id="modalInstalacaoIOS" class="modal-geral" aria-hidden="true">
        <div class="modal-conteudo" role="dialog" aria-modal="true" aria-labelledby="tituloInstalacaoIOS">
          <div class="modal-topo">
            <h2 id="tituloInstalacaoIOS">Instalar AmAgenda</h2>
            <button class="modal-fechar" data-fechar-instalacao-ios type="button" aria-label="Fechar">×</button>
          </div>
          <div class="modal-corpo">
            <p>Para adicionar o AmAgenda à Tela de Início:</p>
            <ol>
              <li>Toque no botão <strong>Compartilhar</strong> do Safari.</li>
              <li>Escolha <strong>Adicionar à Tela de Início</strong>.</li>
              <li>Confirme em <strong>Adicionar</strong>.</li>
            </ol>
            <div class="modal-acoes">
              <button class="botao-geral destaque" data-entendi-instalacao-ios type="button">Entendi</button>
            </div>
          </div>
        </div>
      </div>
    `);

    modal = document.getElementById("modalInstalacaoIOS");
    modal.querySelector("[data-fechar-instalacao-ios]")?.addEventListener("click", fecharOrientacaoIOS);
    modal.querySelector("[data-entendi-instalacao-ios]")?.addEventListener("click", fecharOrientacaoIOS);
    modal.addEventListener("click", evento => {
      if (evento.target === modal) fecharOrientacaoIOS();
    });
    return modal;
  }

  function abrirOrientacaoIOS() {
    const modal = obterModalIOS();
    focoAnterior = document.activeElement;
    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");
    modal.querySelector("[data-fechar-instalacao-ios]")?.focus({ preventScroll: true });
  }

  async function instalar() {
    const estado = obterEstado();
    if (!estado.visivel) return { resultado: "indisponivel" };

    if (estado.ios) {
      abrirOrientacaoIOS();
      return { resultado: "orientacao-ios" };
    }

    const evento = eventoInstalacao;
    if (!evento) return { resultado: "indisponivel" };

    try {
      await evento.prompt();
      return await evento.userChoice;
    } catch (_erro) {
      return { resultado: "indisponivel" };
    } finally {
      eventoInstalacao = null;
      notificarAtualizacao();
    }
  }

  window.AmAgendaInstalacaoPWA = { obterEstado, instalar };

  window.addEventListener("beforeinstallprompt", evento => {
    evento.preventDefault();
    eventoInstalacao = evento;
    notificarAtualizacao();
  });

  window.addEventListener("appinstalled", () => {
    eventoInstalacao = null;
    instaladoNestaSessao = true;
    fecharOrientacaoIOS();
    notificarAtualizacao();
  });

  document.addEventListener("amagenda:sessao-carregada", notificarAtualizacao);
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", notificarAtualizacao, { once: true });
  } else {
    notificarAtualizacao();
  }
  document.addEventListener("keydown", evento => {
    if (evento.key === "Escape") fecharOrientacaoIOS();
  });

  if (typeof mediaStandalone.addEventListener === "function") {
    mediaStandalone.addEventListener("change", notificarAtualizacao);
  } else {
    mediaStandalone.addListener(notificarAtualizacao);
  }
})();
