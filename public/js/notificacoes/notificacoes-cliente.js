/* ==========================================================
   CENTRAL DE NOTIFICAÇÕES — ÁREA DO CLIENTE
   ----------------------------------------------------------
   Módulo próprio do cliente (não reutiliza o notificacoes.js interno):
   - lista/marca como lida pelas rotas cliente/notificacoes/*;
   - empresa e cliente vêm somente da sessão do cliente (backend);
   - a ação só direciona: o Chat do cliente revalida a autorização;
   - sem timer próprio: recarrega pelo ciclo único do chat-nao-lidas.js.
   Reutiliza apenas o visual (classes .notificacoes-* já existentes).
   ========================================================== */
(() => {
  "use strict";

  if (window.CentroNotificacoesCliente) return;

  const API_BASE = "/public/api/api_central.php?path=";
  const ROTA_LISTAR = "cliente/notificacoes/listar";
  const ROTA_MARCAR_LIDA = "cliente/notificacoes/marcar-lida";
  const ACAO_CHAT = "cliente.chat.abrir_conversa";

  const estado = {
    itens: [],
    carregando: false,
    recarregar: false,
    inicializada: false,
    aberto: false,
    parado: false,
    assinaturaChat: null,
    ultimoFoco: null,
  };
  const ui = {};

  function clienteCompleto() {
    const auth = window.__AUTH__;
    return !!auth
      && auth.tipo_usuario === "cliente"
      && Number(auth.id_cliente) > 0
      && auth.cadastro_completo === true;
  }

  function formatarData(valor) {
    if (!valor) return "";
    const data = new Date(String(valor).replace(" ", "T"));
    if (Number.isNaN(data.getTime())) return "";
    return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "short" }).format(data);
  }

  function mostrarEstado(texto, classe = "") {
    ui.lista.replaceChildren();
    ui.estado.textContent = texto;
    ui.estado.className = `notificacoes-estado${classe ? ` ${classe}` : ""}`;
    ui.estado.hidden = false;
  }

  function quantidadeNaoLidas() {
    return estado.itens.filter(item => !item.lida).length;
  }

  /* Único contador da sineta: notificações não lidas da central. */
  function atualizarContador() {
    const total = quantidadeNaoLidas();
    ui.badge.textContent = total > 99 ? "99+" : String(total);
    ui.badge.hidden = total === 0;
    ui.botao.classList.toggle("tem-notificacao-pendente", total > 0);
    const rotulo = total === 0
      ? "Notificações: nenhuma notificação nova"
      : `Notificações: ${total} nova${total === 1 ? "" : "s"}`;
    ui.botao.setAttribute("aria-label", rotulo);
    ui.botao.title = rotulo;
  }

  async function requisitar(rota, { metodo = "GET", dados = null } = {}) {
    const headers = { Accept: "application/json" };
    const opcoes = { method: metodo, credentials: "same-origin", cache: "no-store", headers };
    if (metodo === "POST") {
      headers["Content-Type"] = "application/x-www-form-urlencoded; charset=UTF-8";
      headers["X-CSRF-Token"] = String(window.__AUTH__?.csrf_token || "");
      opcoes.body = new URLSearchParams(dados || {}).toString();
    }
    const resposta = await fetch(`${API_BASE}${encodeURIComponent(rota)}`, opcoes);
    const json = await resposta.json().catch(() => null);
    if (resposta.status === 401 || resposta.status === 403) {
      const erro = new Error(json?.user_msg || "Sem acesso às notificações.");
      erro.definitivo = true;
      throw erro;
    }
    if (!resposta.ok || json?.ok !== true) throw new Error(json?.user_msg || "Não foi possível concluir a operação.");
    return json.data || {};
  }

  async function marcarLida(item) {
    if (item.lida) return;
    await requisitar(ROTA_MARCAR_LIDA, { metodo: "POST", dados: { id_notificacao: String(item.id_notificacao) } });
    item.lida = true;
  }

  /* Chat: somente o id do agendamento segue adiante. Marcar a notificação como lida NÃO
     marca a conversa como lida; isso só ocorre quando o modal do Chat exibe as mensagens. */
  function abrirChatDaNotificacao(item) {
    const idAgendamento = Number(item?.contexto?.id_agendamento);
    if (!Number.isInteger(idAgendamento) || idAgendamento <= 0) return;
    if (typeof window.ChatModalCliente?.abrir === "function") {
      window.ChatModalCliente.abrir(idAgendamento);
      return;
    }
    window.MensagemSistema?.aviso?.("Não foi possível abrir o Chat agora.", { titulo: "Chat" });
  }

  function executarAcao(item) {
    if (item.acao_codigo === ACAO_CHAT) abrirChatDaNotificacao(item);
  }

  function renderizar() {
    ui.lista.replaceChildren();
    atualizarContador();
    if (estado.itens.length === 0) {
      mostrarEstado("Nenhuma notificação no momento.", "vazio");
      return;
    }

    ui.estado.hidden = true;
    estado.itens.forEach(item => {
      const botaoItem = document.createElement("button");
      botaoItem.type = "button";
      botaoItem.className = "notificacao-item";
      botaoItem.classList.toggle("nao-lida", !item.lida);
      botaoItem.setAttribute("aria-label", `${item.titulo}. ${item.lida ? "Lida" : "Não lida"}.`);

      const topo = document.createElement("span");
      topo.className = "notificacao-item-topo";
      const titulo = document.createElement("strong");
      titulo.textContent = item.titulo;
      topo.appendChild(titulo);

      const mensagem = document.createElement("span");
      mensagem.className = "notificacao-item-mensagem";
      mensagem.textContent = item.mensagem;

      const rodape = document.createElement("span");
      rodape.className = "notificacao-item-rodape";
      const data = document.createElement("time");
      data.textContent = formatarData(item.categoria === "chat" && item.prazo_em ? item.prazo_em : item.criada_em);
      const situacao = document.createElement("span");
      situacao.textContent = item.acao_codigo === ACAO_CHAT
        ? (item.lida ? "Lida · Abrir conversa" : "Não lida · Abrir conversa")
        : (item.lida ? "Lida" : "Não lida");
      rodape.append(data, situacao);
      botaoItem.append(topo, mensagem, rodape);

      botaoItem.addEventListener("click", async () => {
        if (botaoItem.disabled) return;
        botaoItem.disabled = true;
        try {
          await marcarLida(item);
        } catch (erro) {
          // A leitura da notificação não impede o direcionamento: o Chat revalida o acesso.
          if (erro?.definitivo) {
            botaoItem.disabled = false;
            mostrarEstado(erro.message, "erro");
            return;
          }
        }
        renderizar();
        fechar(false);
        executarAcao(item);
      });
      ui.lista.appendChild(botaoItem);
    });
  }

  async function carregar({ silencioso = false } = {}) {
    if (estado.parado || !estado.inicializada) return;
    if (estado.carregando) {
      estado.recarregar = true;
      return;
    }
    estado.carregando = true;
    if (!silencioso || estado.itens.length === 0) mostrarEstado("Carregando notificações…", "carregando");
    try {
      const dados = await requisitar(ROTA_LISTAR);
      estado.itens = (Array.isArray(dados.itens) ? dados.itens : []).map(item => ({ ...item, lida: Boolean(item.lida_em) }));
      renderizar();
    } catch (erro) {
      if (erro?.definitivo) {
        estado.parado = true;
        estado.itens = [];
        atualizarContador();
      }
      if (!silencioso || estado.itens.length === 0) mostrarEstado(erro?.message || "Não foi possível carregar as notificações.", "erro");
    } finally {
      estado.carregando = false;
      if (estado.recarregar && !estado.parado) {
        estado.recarregar = false;
        carregar({ silencioso: true });
      }
    }
  }

  function abrir() {
    if (!estado.inicializada) return;
    estado.aberto = true;
    estado.ultimoFoco = ui.botao;
    ui.dropdown.hidden = false;
    ui.botao.setAttribute("aria-expanded", "true");
    carregar();
    ui.dropdown.querySelector(".notificacoes-fechar")?.focus({ preventScroll: true });
  }

  function fechar(devolverFoco = true) {
    if (!estado.inicializada) return;
    estado.aberto = false;
    ui.dropdown.hidden = true;
    ui.botao.setAttribute("aria-expanded", "false");
    if (devolverFoco) estado.ultimoFoco?.focus?.({ preventScroll: true });
  }

  function inicializar() {
    if (estado.inicializada) return;
    if (document.body?.dataset?.menuContexto !== "cliente" || !clienteCompleto()) return;
    const botao = document.querySelector(".cabecalho-notificacoes");
    if (!botao || botao.closest(".centro-notificacoes")) return;

    const container = document.createElement("div");
    container.className = "centro-notificacoes";
    botao.parentNode?.insertBefore(container, botao);
    container.appendChild(botao);
    botao.setAttribute("aria-haspopup", "dialog");
    botao.setAttribute("aria-expanded", "false");
    botao.setAttribute("aria-controls", "notificacoesClienteDropdown");

    const badge = document.createElement("span");
    badge.className = "notificacoes-badge";
    badge.setAttribute("aria-hidden", "true");
    badge.hidden = true;
    botao.appendChild(badge);

    const dropdown = document.createElement("section");
    dropdown.id = "notificacoesClienteDropdown";
    dropdown.className = "notificacoes-dropdown";
    dropdown.setAttribute("role", "dialog");
    dropdown.setAttribute("aria-label", "Central de notificações");
    dropdown.hidden = true;
    const cabecalho = document.createElement("div");
    cabecalho.className = "notificacoes-dropdown-topo";
    const titulo = document.createElement("h2");
    titulo.textContent = "Notificações";
    const fecharBotao = document.createElement("button");
    fecharBotao.type = "button";
    fecharBotao.className = "notificacoes-fechar";
    fecharBotao.setAttribute("aria-label", "Fechar notificações");
    fecharBotao.textContent = "×";
    cabecalho.append(titulo, fecharBotao);
    const estadoEl = document.createElement("p");
    estadoEl.className = "notificacoes-estado";
    estadoEl.setAttribute("role", "status");
    estadoEl.setAttribute("aria-live", "polite");
    const lista = document.createElement("div");
    lista.className = "notificacoes-lista";
    dropdown.append(cabecalho, estadoEl, lista);
    container.appendChild(dropdown);

    Object.assign(ui, { botao, badge, dropdown, estado: estadoEl, lista });
    botao.addEventListener("click", () => (estado.aberto ? fechar() : abrir()));
    fecharBotao.addEventListener("click", () => fechar());
    estado.inicializada = true;
    carregar({ silencioso: true });
  }

  /* Ciclo único: o chat-nao-lidas.js já consulta o Chat do cliente a cada 30 s (aba visível).
     Quando a assinatura (conversa + quantidade) muda, a central é recarregada; se o painel
     estiver aberto, a lista é atualizada junto. Nenhum timer adicional é criado aqui. */
  document.addEventListener("amagenda:chat-nao-lidas", evento => {
    if (!estado.inicializada || evento.detail?.contexto !== "cliente") return;
    const assinatura = (evento.detail.conversas || []).map(c => `${c.id_conversa}:${c.quantidade_nao_lidas}`).join("|");
    if (assinatura === estado.assinaturaChat) return;
    const primeira = estado.assinaturaChat === null;
    estado.assinaturaChat = assinatura;
    // A carga inicial já aconteceu em inicializar(); só reage a mudanças posteriores
    // (ou à primeira leitura quando ela já traz pendências).
    if (primeira && assinatura === "") return;
    carregar({ silencioso: true });
  });

  document.addEventListener("click", evento => {
    if (estado.aberto && !evento.target.closest?.(".centro-notificacoes")) fechar(false);
  }, true);

  document.addEventListener("keydown", evento => {
    if (evento.key === "Escape" && estado.aberto) {
      evento.preventDefault();
      fechar();
    }
  }, true);

  document.addEventListener("amagenda:sessao-carregada", inicializar);
  document.addEventListener("DOMContentLoaded", inicializar);

  window.CentroNotificacoesCliente = Object.freeze({
    inicializar,
    carregar: () => carregar({ silencioso: true }),
    abrir,
    fechar,
  });
})();
