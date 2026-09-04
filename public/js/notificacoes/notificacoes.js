/* ==========================================================
   CENTRO DE NOTIFICAÇÕES & EXPERIÊNCIA DE SENHA TEMPORÁRIA
   AmAgenda — Módulo Centralizado
   ========================================================== */
(() => {
  "use strict";

  if (window.__AMAGENDA_NOTIFICACOES_DEFINIDO__) return;
  window.__AMAGENDA_NOTIFICACOES_DEFINIDO__ = true;

  const API_NOTIFICACOES = "/public/api/api_central.php?path=notificacoes/listar";
  const API_MARCAR_LIDA = "/public/api/api_central.php?path=notificacoes/marcar-lida";
  const ACOES_NOTIFICACAO = new Set(["perfil.alterar_senha", "perfil.alterar_foto"]);

  const estadoNotificacoes = {
    itens: [],
    carregando: false,
    dropdownAberto: false,
    senhaVencida: false,
    bloqueioSenha: false,
    ultimoFoco: null,
  };

  /* ----------------------------------------------------------
     Interceptador global para SENHA_TEMPORARIA_EXPIRADA (403)
     ---------------------------------------------------------- */
  if (!window.__AMAGENDA_FETCH_SENHA_TEMPORARIA__) {
    window.__AMAGENDA_FETCH_SENHA_TEMPORARIA__ = true;
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const resposta = await fetchOriginal(...args);
      if (resposta.status === 403) {
        resposta.clone().json().then((dados) => {
          if (dados?.code === "SENHA_TEMPORARIA_EXPIRADA") {
            document.dispatchEvent(new CustomEvent("amagenda:senha-temporaria-expirada"));
          }
        }).catch(() => {});
      }
      return resposta;
    };
  }

  function elementosNotificacoes() {
    return {
      botao: document.querySelector(".cabecalho-notificacoes"),
      badge: document.querySelector(".notificacoes-badge"),
      dropdown: document.querySelector(".notificacoes-dropdown"),
      lista: document.querySelector(".notificacoes-lista"),
      estado: document.querySelector(".notificacoes-estado"),
    };
  }

  function formatarDataNotificacao(valor) {
    if (!valor) return "";
    const data = new Date(String(valor).replace(" ", "T"));
    if (Number.isNaN(data.getTime())) return "";
    return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "short" }).format(data);
  }

  function criarTextoEstado(texto, classe = "") {
    const { estado, lista } = elementosNotificacoes();
    if (!estado || !lista) return;
    lista.replaceChildren();
    estado.textContent = texto;
    estado.className = `notificacoes-estado${classe ? ` ${classe}` : ""}`;
    estado.hidden = false;
  }

  function atualizarContador() {
    const { botao, badge } = elementosNotificacoes();
    const quantidade = estadoNotificacoes.itens.length;
    if (!botao || !badge) return;
    badge.textContent = quantidade > 99 ? "99+" : String(quantidade);
    badge.hidden = quantidade === 0;
    botao.classList.toggle("tem-notificacao-pendente", quantidade > 0);
    botao.setAttribute(
      "aria-label",
      quantidade === 0 ? "Notificações: nenhuma pendência" : `Notificações: ${quantidade} pendência${quantidade === 1 ? "" : "s"}`
    );
  }

  function abrirPerfilNaArea(area, obrigatorio = false) {
    const modal = document.getElementById("modalPerfilUsuario");
    if (!modal) return;
    const focoJaEstaNoModal = modal.contains(document.activeElement);

    if (obrigatorio) {
      document.querySelectorAll(".modal-geral.ativo, .modal-cards.ativo").forEach((item) => {
        if (item !== modal) {
          item.classList.remove("ativo");
          item.setAttribute("aria-hidden", "true");
        }
      });
    }

    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");

    const destino = area === "foto"
      ? modal.querySelector('label[for="perfil_foto"]')
      : modal.querySelector("#senha_atual");
    if (destino instanceof HTMLElement && !focoJaEstaNoModal) {
      if (destino.matches("label") && !destino.hasAttribute("tabindex")) destino.tabIndex = 0;
      destino.scrollIntoView({ block: "center", behavior: "smooth" });
      window.setTimeout(() => {
        if (!modal.contains(document.activeElement)) destino.focus({ preventScroll: true });
      }, 40);
    }
  }

  function ativarBloqueioSenha() {
    estadoNotificacoes.senhaVencida = true;
    estadoNotificacoes.bloqueioSenha = true;
    document.body.classList.add("senha-temporaria-bloqueada");
    fecharDropdownNotificacoes(false);
    abrirPerfilNaArea("senha", true);
  }

  let alertaSenhaObrigatoria = null;

  function exibirAvisoSenhaObrigatoria() {
    if (alertaSenhaObrigatoria && alertaSenhaObrigatoria.isConnected) {
      return;
    }
    const mensagens = window.MensagemSistema;
    if (!mensagens?.aviso) return;

    alertaSenhaObrigatoria = mensagens.aviso(
      "Por segurança, sua senha temporária expirou e precisa ser substituída antes de continuar usando o AmAgenda. Altere sua senha para liberar o acesso ao sistema.",
      {
        titulo: "Alteração de senha obrigatória",
        persistente: true,
        textoBotao: "OK",
        aoFechar: () => {
          alertaSenhaObrigatoria = null;
          abrirPerfilNaArea("senha", true);
        },
      }
    );
  }

  function liberarBloqueioSenha(fecharPerfil = false) {
    const estavaBloqueado = estadoNotificacoes.bloqueioSenha;
    estadoNotificacoes.senhaVencida = false;
    estadoNotificacoes.bloqueioSenha = false;
    document.body.classList.remove("senha-temporaria-bloqueada");
    if (alertaSenhaObrigatoria && alertaSenhaObrigatoria.isConnected) {
      alertaSenhaObrigatoria.remove();
      alertaSenhaObrigatoria = null;
    }
    if (fecharPerfil && estavaBloqueado) {
      const modal = document.getElementById("modalPerfilUsuario");
      modal?.classList.remove("ativo");
      modal?.setAttribute("aria-hidden", "true");
    }
  }

  function aplicarEstadoSessao(auth) {
    const vencida = auth?.senha_temporaria_vencida === true;
    if (vencida) ativarBloqueioSenha();
    else if (estadoNotificacoes.bloqueioSenha) liberarBloqueioSenha(true);
  }

  async function marcarNotificacaoComoLida(item) {
    if (item.lida) return true;
    const corpo = new URLSearchParams({ id_notificacao: String(item.id_notificacao) });
    const resposta = await fetch(API_MARCAR_LIDA, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json", "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: corpo.toString(),
    });
    const dados = await resposta.json().catch(() => null);
    if (!resposta.ok || dados?.ok !== true) throw new Error(dados?.user_msg || "Não foi possível marcar a notificação como lida.");
    item.lida = true;
    return true;
  }

  function executarAcaoNotificacao(codigo) {
    if (!ACOES_NOTIFICACAO.has(codigo)) return;
    fecharDropdownNotificacoes(false);
    if (codigo === "perfil.alterar_senha") abrirPerfilNaArea("senha", false);
    if (codigo === "perfil.alterar_foto") abrirPerfilNaArea("foto", false);
  }

  function renderizarNotificacoes() {
    const { lista, estado } = elementosNotificacoes();
    if (!lista || !estado) return;
    lista.replaceChildren();
    if (estadoNotificacoes.itens.length === 0) {
      criarTextoEstado("Você não possui notificações pendentes.", "vazio");
      atualizarContador();
      return;
    }

    estado.hidden = true;
    estadoNotificacoes.itens.forEach((item) => {
      const botaoItem = document.createElement("button");
      botaoItem.type = "button";
      botaoItem.className = "notificacao-item";
      botaoItem.classList.toggle("nao-lida", !item.lida);
      botaoItem.classList.toggle("obrigatoria", item.obrigatoria === true);
      if (["alta", "critica"].includes(item.prioridade)) botaoItem.classList.add(`prioridade-${item.prioridade}`);
      botaoItem.setAttribute("aria-label", `${item.titulo}. ${item.lida ? "Lida" : "Não lida"}.`);

      const topo = document.createElement("span");
      topo.className = "notificacao-item-topo";
      const titulo = document.createElement("strong");
      titulo.textContent = item.titulo;
      topo.appendChild(titulo);
      if (item.obrigatoria) {
        const obrigatoria = document.createElement("span");
        obrigatoria.className = "notificacao-chip";
        obrigatoria.textContent = "Obrigatória";
        topo.appendChild(obrigatoria);
      }

      const mensagem = document.createElement("span");
      mensagem.className = "notificacao-item-mensagem";
      mensagem.textContent = item.mensagem;
      const rodape = document.createElement("span");
      rodape.className = "notificacao-item-rodape";
      const data = document.createElement("time");
      data.textContent = formatarDataNotificacao(item.criada_em);
      rodape.appendChild(data);
      const estadoLeitura = document.createElement("span");
      estadoLeitura.textContent = item.lida ? "Lida · pendente" : "Não lida";
      rodape.appendChild(estadoLeitura);
      botaoItem.append(topo, mensagem, rodape);

      botaoItem.addEventListener("click", async () => {
        if (botaoItem.disabled) return;
        botaoItem.disabled = true;
        try {
          await marcarNotificacaoComoLida(item);
          renderizarNotificacoes();
          executarAcaoNotificacao(item.acao_codigo);
        } catch (erro) {
          botaoItem.disabled = false;
          criarTextoEstado(erro?.message || "Não foi possível abrir a notificação.", "erro");
        }
      });
      lista.appendChild(botaoItem);
    });
    atualizarContador();
  }

  async function carregarNotificacoes() {
    if (estadoNotificacoes.carregando || estadoNotificacoes.senhaVencida) return;
    estadoNotificacoes.carregando = true;
    criarTextoEstado("Carregando notificações…", "carregando");
    try {
      const resposta = await fetch(API_NOTIFICACOES, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const dados = await resposta.json().catch(() => null);
      if (!resposta.ok || dados?.ok !== true || !Array.isArray(dados?.data?.itens)) {
        if (dados?.code === "SENHA_TEMPORARIA_EXPIRADA") return;
        throw new Error(dados?.user_msg || "Não foi possível carregar as notificações.");
      }
      estadoNotificacoes.itens = dados.data.itens.map((item) => ({ ...item, lida: item.lida === true }));
      renderizarNotificacoes();
    } catch (erro) {
      criarTextoEstado(erro?.message || "Não foi possível carregar as notificações.", "erro");
    } finally {
      estadoNotificacoes.carregando = false;
    }
  }

  function abrirDropdownNotificacoes() {
    const { botao, dropdown } = elementosNotificacoes();
    if (!botao || !dropdown || estadoNotificacoes.bloqueioSenha) return;
    estadoNotificacoes.dropdownAberto = true;
    estadoNotificacoes.ultimoFoco = botao;
    dropdown.hidden = false;
    botao.setAttribute("aria-expanded", "true");
    carregarNotificacoes();
    dropdown.querySelector(".notificacoes-fechar")?.focus({ preventScroll: true });
  }

  function fecharDropdownNotificacoes(devolverFoco = true) {
    const { botao, dropdown } = elementosNotificacoes();
    if (!botao || !dropdown) return;
    estadoNotificacoes.dropdownAberto = false;
    dropdown.hidden = true;
    botao.setAttribute("aria-expanded", "false");
    if (devolverFoco) estadoNotificacoes.ultimoFoco?.focus?.({ preventScroll: true });
  }

  function inicializarCentroNotificacoes() {
    const botao = document.querySelector(".cabecalho-notificacoes");
    if (!botao || botao.closest(".centro-notificacoes")) return;

    const container = document.createElement("div");
    container.className = "centro-notificacoes";
    botao.parentNode?.insertBefore(container, botao);
    container.appendChild(botao);
    botao.setAttribute("aria-haspopup", "dialog");
    botao.setAttribute("aria-expanded", "false");
    botao.setAttribute("aria-controls", "notificacoesDropdown");

    const badge = document.createElement("span");
    badge.className = "notificacoes-badge";
    badge.setAttribute("aria-hidden", "true");
    badge.hidden = true;
    botao.appendChild(badge);

    const dropdown = document.createElement("section");
    dropdown.id = "notificacoesDropdown";
    dropdown.className = "notificacoes-dropdown";
    dropdown.setAttribute("role", "dialog");
    dropdown.setAttribute("aria-label", "Centro de notificações");
    dropdown.hidden = true;
    const cabecalho = document.createElement("div");
    cabecalho.className = "notificacoes-dropdown-topo";
    const titulo = document.createElement("h2");
    titulo.textContent = "Notificações";
    const fechar = document.createElement("button");
    fechar.type = "button";
    fechar.className = "notificacoes-fechar";
    fechar.setAttribute("aria-label", "Fechar notificações");
    fechar.textContent = "×";
    cabecalho.append(titulo, fechar);
    const estado = document.createElement("p");
    estado.className = "notificacoes-estado";
    estado.setAttribute("role", "status");
    estado.setAttribute("aria-live", "polite");
    const lista = document.createElement("div");
    lista.className = "notificacoes-lista";
    dropdown.append(cabecalho, estado, lista);
    container.appendChild(dropdown);

    botao.addEventListener("click", () => estadoNotificacoes.dropdownAberto ? fecharDropdownNotificacoes() : abrirDropdownNotificacoes());
    fechar.addEventListener("click", () => fecharDropdownNotificacoes());
    carregarNotificacoes();
    aplicarEstadoSessao(window.__AUTH__ || null);
  }

  /* ----------------------------------------------------------
     Eventos e Escutas
     ---------------------------------------------------------- */
  document.addEventListener("amagenda:senha-temporaria-expirada", ativarBloqueioSenha);
  document.addEventListener("amagenda:sessao-carregada", (evento) => aplicarEstadoSessao(evento.detail));
  document.addEventListener("perfil:senha-atualizada", (evento) => {
    const auth = evento.detail?.auth || window.__AUTH__ || {};
    if (auth.senha_temporaria_vencida === false && auth.deve_alterar_senha === false) {
      liberarBloqueioSenha(true);
      carregarNotificacoes();
    }
  });
  document.addEventListener("perfil:foto-atualizada", carregarNotificacoes);

  document.addEventListener("click", (evento) => {
    if (estadoNotificacoes.bloqueioSenha) {
      if (evento.target.closest?.(".ui-alert__btn, .ui-toast-stack button, #btnSair")) {
        return;
      }
      const modal = document.getElementById("modalPerfilUsuario");
      const dentroModal = modal?.contains(evento.target);
      const tentouFechar = evento.target.closest?.("#modalPerfilUsuario [data-fechar-modal]");
      if (!dentroModal || tentouFechar) {
        evento.preventDefault();
        evento.stopImmediatePropagation();
        abrirPerfilNaArea("senha", true);
        exibirAvisoSenhaObrigatoria();
      }
      return;
    }
    if (estadoNotificacoes.dropdownAberto && !evento.target.closest?.(".centro-notificacoes")) {
      fecharDropdownNotificacoes(false);
    }
  }, true);

  document.addEventListener("keydown", (evento) => {
    if (estadoNotificacoes.bloqueioSenha) {
      const modal = document.getElementById("modalPerfilUsuario");
      if (evento.key === "Escape") {
        evento.preventDefault();
        evento.stopImmediatePropagation();
        abrirPerfilNaArea("senha", true);
        exibirAvisoSenhaObrigatoria();
        return;
      }
      if (evento.key === "Tab") {
        if (alertaSenhaObrigatoria && alertaSenhaObrigatoria.isConnected) {
          const botaoAlerta = alertaSenhaObrigatoria.querySelector("button");
          if (botaoAlerta && document.activeElement !== botaoAlerta) {
            evento.preventDefault();
            botaoAlerta.focus();
            return;
          }
        }
        if (modal) {
          const focaveis = Array.from(modal.querySelectorAll('input:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter((item) => item instanceof HTMLElement && !item.hidden && item.offsetParent !== null);
          if (focaveis.length) {
            const primeiro = focaveis[0];
            const ultimo = focaveis[focaveis.length - 1];
            if (evento.shiftKey && document.activeElement === primeiro) {
              evento.preventDefault(); ultimo.focus();
            } else if (!evento.shiftKey && document.activeElement === ultimo) {
              evento.preventDefault(); primeiro.focus();
            } else if (!modal.contains(document.activeElement)) {
              evento.preventDefault(); primeiro.focus();
            }
          }
        }
      }
      return;
    }
    if (evento.key === "Escape" && estadoNotificacoes.dropdownAberto) {
      evento.preventDefault();
      fecharDropdownNotificacoes();
    }
  }, true);

  document.addEventListener("DOMContentLoaded", () => {
    inicializarCentroNotificacoes();
  });

  window.CentroNotificacoes = {
    inicializar: inicializarCentroNotificacoes,
    carregar: carregarNotificacoes,
    abrir: abrirDropdownNotificacoes,
    fechar: fecharDropdownNotificacoes,
    ativarBloqueioSenha,
    liberarBloqueioSenha,
  };
})();
