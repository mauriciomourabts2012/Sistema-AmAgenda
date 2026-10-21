/* ==========================================================
   ListaAgenda.js — dados reais da tabela agendamento
   - Consulta a API central e renderiza os registros por dia da semana
   - Pesquisa e filtro por status compartilhados pelas sete abas
   - ✅ Paginação por DIA (PADRÃO MANUTENÇÃO: btn-pag, Anterior/Próximo, sem contador)
   - WhatsApp no card (desabilita se não tiver tel)
   - Menu ⋮ flutuante anti-corte via ListaCore
   - Pesquisa na aba ativa + limpar
   - ✅ Ajuste render: identifica Cliente + Profissional (com ícones) no card
   ✅ AJUSTE AQUI: alerts/erros usando CSS .ui-toast-stack + .ui-alert (NÃO usa alert())
========================================================== */
(() => {
  "use strict";
  const C = window.ListaCore;
  if (!C) return;

  // ==========================================================
  // ✅ ALERT UNIVERSAL (Toast) — usa seu CSS .ui-toast-stack + .ui-alert
  // ==========================================================
  function ensureToastStack() {
    let stack = document.querySelector(".ui-toast-stack");
    if (!stack) {
      stack = document.createElement("div");
      stack.className = "ui-toast-stack";
      stack.setAttribute("aria-live", "polite");
      stack.setAttribute("aria-relevant", "additions");
      document.body.appendChild(stack);
    }
    return stack;
  }

  function toastClose(el) {
    if (!el) return;
    el.classList.add("is-leaving");
    el.addEventListener("animationend", () => el.remove(), { once: true });
  }

  function uiToast(type, title, msg, opts = {}) {
    const stack = ensureToastStack();

    const map = {
      success: { cls: "ui-alert--success", icon: "✓" },
      warning: { cls: "ui-alert--warning", icon: "!" },
      danger:  { cls: "ui-alert--danger",  icon: "×" },
      confirm: { cls: "ui-alert--confirm", icon: "?" },
      info:    { cls: "ui-alert--confirm", icon: "i" }, // fallback clean
    };

    const t = map[type] || map.info;

    const el = document.createElement("div");
    el.className = `ui-alert ${t.cls}`;

    el.innerHTML = `
      <div class="ui-alert__icon" aria-hidden="true">${t.icon}</div>
      <div class="ui-alert__content">
        <div class="ui-alert__title">${String(title || "Aviso")}</div>
        <div class="ui-alert__msg">${String(msg || "")}</div>
      </div>
      <div class="ui-alert__actions"></div>
    `;

    const actions = el.querySelector(".ui-alert__actions");

    // botão OK (sempre)
    const btnOk = document.createElement("button");
    btnOk.type = "button";
    btnOk.className = "ui-alert__btn ui-alert__btn--primary";
    btnOk.textContent = opts.okText || "OK";
    btnOk.addEventListener("click", () => toastClose(el));
    actions.appendChild(btnOk);

    stack.prepend(el);

    const timeout =
      typeof opts.timeout === "number"
        ? opts.timeout
        : (type === "confirm" ? 0 : 3200);

    let timer = null;
    if (timeout > 0) timer = setTimeout(() => toastClose(el), timeout);

    // fecha ao clicar fora? (não — mantém simples e consistente)
    // se quiser: poderia adicionar listener de clique no documento

    return el;
  }

  // ===== CONFIG =====
  const CFG = {
    ENDPOINT: "/public/api/api_central.php?path=agenda/agendamento/listar",
    ROOT_SELECTOR_MENU: ".conteudo-agenda",
    itensPorPagina: 4, // ✅ igual manutenção
  };

  // =========================
  // MAPA: seus IDs por dia
  // =========================
  const DIAS = [
    { dia: "segunda", box: "cardsAgendaSegunda", pag: "paginacao_segunda" },
    { dia: "terca", box: "cardsAgendaTerca", pag: "paginacao_terca" },
    { dia: "quarta", box: "cardsAgendaQuarta", pag: "paginacao_quarta" },
    { dia: "quinta", box: "cardsAgendaQuinta", pag: "paginacao_quinta" },
    { dia: "sexta", box: "cardsAgendaSexta", pag: "paginacao_sexta" },
    { dia: "sabado", box: "cardsAgendaSabado", pag: "paginacao_sabado" },
    { dia: "domingo", box: "cardsAgendaDomingo", pag: "paginacao_domingo" },
  ];


  // =========================
  // ESTADOS
  // =========================
  // Um único filtro e uma única pesquisa administram as sete abas.
  const FILTRO_GLOBAL = { status: "" };
  let PESQUISA_GLOBAL = "";
  const PAGINA_ATUAL = {}; // { dia: 1 }

  // =========================
  // Helpers
  // =========================
  const onlyDigits = (v) => String(v || "").replace(/\D/g, "");

  // ---- status normalizado p/ filtro ----
  function normalizarStatus(st) {
    const s = C.normalizar(st);
    if (!s) return "";
    if (s.includes("pend")) return "pendente";
    if (s.includes("confirm")) return "confirmado";
    if (s.includes("conclu")) return "concluido";
    if (s.includes("cancel")) return "cancelado";
    if (s.includes("falt")) return "faltou";
    return s;
  }

  function statusBate(itemStatus, filtroStatus) {
    const f = normalizarStatus(filtroStatus);
    if (!f) return true; // vazio = todos
    return normalizarStatus(itemStatus) === f;
  }

  function badgeStatus(status) {
    const st = C.normalizar(status);
    let cls = "st-pendente";
    if (st.includes("confirm")) cls = "st-confirmado";
    else if (st.includes("conclu")) cls = "st-concluido";
    else if (st.includes("cancel")) cls = "st-cancelado";
    else if (st.includes("falt")) cls = "st-cancelado";
    const rotulos = { pendente: "Pendente", confirmado: "Confirmado", concluido: "Concluído", cancelado: "Cancelado", faltou: "Faltou" };
    return `<span class="agenda-status ${cls}">${C.escapeHtml(rotulos[normalizarStatus(status)] || status || "Pendente")}</span>`;
  }

  function textoPagamento(ok) {
    // O banco de agendamento ainda não possui informação de pagamento.
    if (ok === null || typeof ok === "undefined") return "";
    return ok
      ? `<span class="agenda-pagamento pago">Pago</span>`
      : `<span class="agenda-pagamento nao-pago">Não pago</span>`;
  }

  function iconAcoes() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 7.25a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm0 6.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Z"/>
      </svg>
    `;
  }

  // =========================
  // Chat: abrir/iniciar conversa pelo agendamento
  // =========================
  // Somente orientação visual; o backend do Chat continua sendo a autoridade final.
  function podeAbrirConversaChat() {
    const auth = window.__AUTH__ || {};
    if (String(auth.tipo_usuario || "").toLowerCase() === "super_admin" || auth.modo_suporte === true) return false;
    return typeof window.usuarioPode === "function"
      && window.usuarioPode("chat.visualizar") === true
      && window.usuarioPode("chat.responder") === true;
  }

  function atualizarAcoesConversaChat() {
    const visivel = podeAbrirConversaChat();
    document.querySelectorAll('.agenda-menu-item[data-acao="abrir_conversa"]').forEach((botao) => {
      botao.style.display = visivel ? "" : "none";
    });
  }

  function avisoConversaChat(tipo, mensagem) {
    // Mensagens do backend só são exibidas como texto (MensagemSistema usa textContent).
    if (window.MensagemSistema?.[tipo]) {
      window.MensagemSistema[tipo](mensagem, { titulo: "Chat" });
      return;
    }
    uiToast(tipo === "erro" ? "danger" : "warning", "Chat", "Não foi possível abrir a conversa deste agendamento.");
  }

  function abrirConversaDoAgendamento(idAgendamento) {
    const id = String(idAgendamento || "").trim();
    if (!/^[1-9]\d{0,17}$/.test(id)) return;
    if (!podeAbrirConversaChat()) {
      avisoConversaChat("aviso", "Você não possui permissão para abrir conversas.");
      return;
    }
    if (typeof window.ChatModalAgenda?.abrir !== "function") {
      avisoConversaChat("erro", "O Chat rápido não está disponível no momento.");
      return;
    }
    window.ChatModalAgenda.abrir(id);
  }

  // ============================================================
  // AGENDA — AÇÃO RÁPIDA CONFIRMAR
  // ============================================================
  // Somente orientação visual: o backend (agenda/agendamento/editar) é a autoridade de empresa,
  // permissão, escopo do profissional, conflito, auditoria, notificações e Chat.
  function podeConfirmarAgendamento(item) {
    return podeExecutarAcaoRapida(item, "confirmar");
  }

  const ACOES_RAPIDAS_AGENDAMENTO = Object.freeze({
    confirmar: Object.freeze({ origens: ["pendente"], destino: "confirmado", exigeInicio: false, titulo: "Confirmar agendamento?", pergunta: "Deseja confirmar este agendamento?", textoConfirmar: "Confirmar", sucesso: "Agendamento confirmado.", erro: "Não foi possível confirmar o agendamento." }),
    concluir: Object.freeze({ origens: ["confirmado"], destino: "concluido", exigeInicio: true, titulo: "Concluir agendamento?", pergunta: "Deseja registrar este agendamento como concluído?", textoConfirmar: "Concluir", sucesso: "Agendamento concluído.", erro: "Não foi possível concluir o agendamento." }),
    faltou: Object.freeze({ origens: ["confirmado"], destino: "faltou", exigeInicio: true, titulo: "Registrar falta?", pergunta: "Deseja registrar que o cliente faltou a este agendamento?", textoConfirmar: "Registrar falta", sucesso: "Falta registrada.", erro: "Não foi possível registrar a falta." }),
    cancelar: Object.freeze({ origens: ["pendente", "confirmado"], destino: "cancelado", exigeInicio: false, titulo: "Cancelar agendamento?", pergunta: "Deseja cancelar este agendamento?", textoConfirmar: "Cancelar agendamento", sucesso: "Agendamento cancelado.", erro: "Não foi possível cancelar o agendamento." }),
  });

  function inicioAgendamentoAtingido(item, agora = new Date()) {
    const data = String(item?.data_agendamento ?? item?.data ?? "");
    const hora = String(item?.hora_inicio ?? item?.hora ?? "").slice(0, 5);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(data) || !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(hora)) return false;
    const inicio = new Date(`${data}T${hora}:00`);
    return !Number.isNaN(inicio.getTime()) && inicio <= agora;
  }

  function podeExecutarAcaoRapida(item, acao, agora = new Date()) {
    const definicao = ACOES_RAPIDAS_AGENDAMENTO[acao];
    if (!definicao || !definicao.origens.includes(normalizarStatus(item?.status))) return false;
    if (typeof window.usuarioPode !== "function" || window.usuarioPode("agenda.editar_agendamento") !== true) return false;
    if (acao === "cancelar" && window.usuarioPode("agenda.cancelar_agendamento") !== true) return false;
    return !definicao.exigeInicio || inicioAgendamentoAtingido(item, agora);
  }

  function avisoDeepLinkAgendamento(codigoNotificacao, statusBruto) {
    const status = normalizarStatus(statusBruto);
    if (codigoNotificacao === "agenda.agendamento_pendente") {
      return status === "pendente" ? "" : "Este agendamento não está mais pendente.";
    }
    if (codigoNotificacao !== "agenda.agendamento_finalizacao_pendente" || status === "confirmado") return "";
    return ({
      concluido: "Este agendamento já foi concluído.",
      cancelado: "Este agendamento foi cancelado.",
      faltou: "A falta deste agendamento já foi registrada.",
    })[status] || "Este agendamento não está mais aguardando finalização.";
  }

  const CODIGOS_NOTIFICACAO_AGENDAMENTO = Object.freeze(["agenda.agendamento_pendente", "agenda.agendamento_finalizacao_pendente"]);

  // Item do menu de três pontos para uma ação rápida; a visibilidade final é revista ao abrir o menu.
  function botaoAcaoRapidaMenu(item, acao, classe, icone, rotulo) {
    const id = C.escapeHtml(item.id_agendamento ?? item.id ?? "");
    return `
          <button class="agenda-menu-item agenda-menu-item--acao-rapida ${classe}" type="button"
            data-acao-rapida="${acao}" data-id-agendamento="${id}"${podeExecutarAcaoRapida(item, acao) ? "" : " hidden"}>
            <i class="${icone}"></i> <span>${rotulo}</span>
          </button>`;
  }

  const acoesRapidasEmAndamento = new Set();

  function estadoAcaoRapida(id, acao, carregando) {
    const detail = { id_agendamento: id, acao, carregando };
    document.dispatchEvent(new CustomEvent("agenda:agendamento:acao-rapida-estado", { detail }));
    // Evento existente preservado para integrações que acompanham a confirmação rápida.
    if (acao === "confirmar") document.dispatchEvent(new CustomEvent("agenda:agendamento:confirmacao-estado", { detail }));
  }

  async function apiConfirmacaoAgenda(path, { metodo = "GET", parametros = {}, corpo = null } = {}) {
    const url = new URL("/public/api/api_central.php", window.location.origin);
    url.searchParams.set("path", path);
    Object.entries(parametros).forEach(([chave, valor]) => url.searchParams.set(chave, String(valor)));
    const headers = { Accept: "application/json" };
    if (metodo !== "GET") headers["X-CSRF-Token"] = String(window.__AUTH__?.csrf_token || "");
    const resposta = await fetch(url.toString(), { method: metodo, headers, body: corpo, credentials: "same-origin", cache: "no-store" });
    const json = await resposta.json().catch(() => null);
    if (!resposta.ok || json?.ok !== true) {
      throw new Error(String(json?.user_msg || "Não foi possível atualizar o agendamento."));
    }
    return json;
  }

  function mensagemConfirmacao(tipo, mensagem) {
    // MensagemSistema exibe como texto; o fallback usa somente textos fixos.
    if (window.MensagemSistema?.[tipo]) {
      window.MensagemSistema[tipo](mensagem, { titulo: "Agendamento" });
      return;
    }
    uiToast(tipo === "sucesso" ? "success" : "danger", "Agendamento",
      tipo === "sucesso" ? "Agendamento confirmado." : "Não foi possível confirmar o agendamento.");
  }

  // Fluxo aprovado da confirmação, generalizado sem alterar seus passos: detalhar o estado
  // oficial, reenviar o snapshot e deixar o backend travar e alterar somente o status.
  async function executarAcaoRapidaAgendamento(idBruto, acao) {
    const id = String(idBruto || "").trim();
    const definicao = ACOES_RAPIDAS_AGENDAMENTO[acao];
    if (!definicao || !/^[1-9]\d{0,17}$/.test(id) || acoesRapidasEmAndamento.has(id) || !window.MensagemSistema?.confirmar) return;
    const confirmado = await window.MensagemSistema.confirmar(definicao.pergunta, {
      titulo: definicao.titulo, textoConfirmar: definicao.textoConfirmar, textoCancelar: "Voltar"
    });
    if (!confirmado || acoesRapidasEmAndamento.has(id)) return;

    acoesRapidasEmAndamento.add(id);
    estadoAcaoRapida(id, acao, true);
    try {
      const atual = (await apiConfirmacaoAgenda("agenda/agendamento/detalhar", { parametros: { id_agendamento: id } })).data || {};
      if (String(atual.id_agendamento) !== id) throw new Error(definicao.erro);
      if (!definicao.origens.includes(normalizarStatus(atual.status))) {
        mensagemConfirmacao("aviso", acao === "confirmar"
          ? "Este agendamento não está mais pendente. Atualize a agenda."
          : "O status deste agendamento foi alterado. Atualize a agenda.");
        return;
      }
      if (definicao.exigeInicio && !inicioAgendamentoAtingido(atual)) {
        mensagemConfirmacao("aviso", "Este atendimento ainda não começou.");
        return;
      }
      const corpo = new FormData();
      corpo.set("acao_rapida", acao);
      corpo.set("id_agendamento", id);
      corpo.set("id_cliente", String(atual.id_cliente ?? ""));
      corpo.set("id_profissional", String(atual.id_profissional ?? ""));
      corpo.set("id_servico", String(atual.id_servico ?? ""));
      corpo.set("duracao", String(atual.duracao_min_aplicada ?? ""));
      corpo.set("data_agendamento", String(atual.data_agendamento ?? ""));
      corpo.set("hora_inicio", String(atual.hora_inicio ?? "").slice(0, 5));
      corpo.set("status", definicao.destino);
      corpo.set("observacao", String(atual.observacao ?? ""));
      corpo.set("repetir_semanalmente", Number(atual.repetir_semanalmente) === 1 ? "1" : "0");
      corpo.set("recorrencia_data_fim", String(atual.recorrencia_data_fim ?? ""));
      const resposta = await apiConfirmacaoAgenda("agenda/agendamento/editar", { metodo: "POST", corpo });
      mensagemConfirmacao("sucesso", definicao.sucesso);
      (resposta.data?.avisos_plano || []).forEach((aviso) => mensagemConfirmacao("aviso", String(aviso?.mensagem || "O consumo mensal do plano está próximo do limite.")));
      document.dispatchEvent(new CustomEvent("agenda:agendamento:status-atualizado", { detail: { id_agendamento: id, status: definicao.destino } }));
      // Evento aprovado da confirmação permanece disponível sem conduzir o novo fluxo.
      if (acao === "confirmar") document.dispatchEvent(new CustomEvent("agenda:agendamento:confirmado", { detail: { id_agendamento: id } }));
      window.CentroNotificacoes?.carregar?.();
    } catch (erro) {
      mensagemConfirmacao("erro", erro?.message || definicao.erro);
    } finally {
      acoesRapidasEmAndamento.delete(id);
      estadoAcaoRapida(id, acao, false);
    }
  }

  function confirmarAgendamentoRapido(idBruto) {
    return executarAcaoRapidaAgendamento(idBruto, "confirmar");
  }

  function atualizarAcoesConfirmar() {
    document.querySelectorAll(".agenda-menu-item[data-acao-rapida]").forEach((botao) => {
      const card = botao.closest(".agenda-card");
      botao.hidden = !podeExecutarAcaoRapida({
        status: card?.dataset.status,
        data_agendamento: card?.dataset.data,
        hora_inicio: card?.dataset.horaInicio,
      }, String(botao.dataset.acaoRapida || ""));
    });
    document.querySelectorAll('.agenda-menu-item[data-acao="editar"]').forEach((botao) => {
      botao.hidden = typeof window.usuarioPode !== "function" || window.usuarioPode("agenda.editar_agendamento") !== true;
    });
    document.querySelectorAll('.agenda-menu-item[data-acao="excluir"]').forEach((botao) => {
      botao.hidden = typeof window.usuarioPode !== "function" || window.usuarioPode("agenda.excluir_agendamento") !== true;
    });
    const modal = document.getElementById("modalVisualizarAgendamento");
    const botaoModal = document.getElementById("vc_btn_confirmar");
    const botaoAtraso = document.getElementById("vc_btn_informar_atraso");
    if ((botaoModal || botaoAtraso) && modal?.dataset.status) {
      if (botaoModal) botaoModal.hidden = !podeConfirmarAgendamento({ status: modal.dataset.status });
      if (botaoAtraso) {
        botaoAtraso.hidden = !podeInformarAtraso({
          status: modal.dataset.status,
          data_agendamento: modal.dataset.dataAgendamento
        });
      }
      const acoesModal = document.getElementById("vc_confirmar_acoes");
      if (acoesModal) acoesModal.hidden = Boolean(botaoModal?.hidden) && Boolean(botaoAtraso?.hidden);
    }
  }

  // ============================================================
  // INFORMAR ATRASO
  // ============================================================
  const atrasoEstado = { agendamento: null, minutos: null, previa: null, ocupado: false };
  let recarregarAgendaAposAtraso = null;

  function dataLocalIsoHoje() {
    const agora = new Date();
    const ano = agora.getFullYear();
    const mes = String(agora.getMonth() + 1).padStart(2, "0");
    const dia = String(agora.getDate()).padStart(2, "0");
    return `${ano}-${mes}-${dia}`;
  }

  function podeInformarAtraso(item) {
    return normalizarStatus(item?.status) === "confirmado"
      && String(item?.data_agendamento ?? item?.data ?? "") === dataLocalIsoHoje()
      && typeof window.usuarioPode === "function"
      && window.usuarioPode("agenda.editar_agendamento") === true;
  }

  function elementoAtraso(id) { return document.getElementById(id); }

  function abrirModalAtraso(item) {
    const dataAgendamento = String(item?.data_agendamento ?? item?.data ?? "");
    const outrosCriteriosValidos = normalizarStatus(item?.status) === "confirmado"
      && typeof window.usuarioPode === "function"
      && window.usuarioPode("agenda.editar_agendamento") === true;
    if (/^\d{4}-\d{2}-\d{2}$/.test(dataAgendamento)
      && dataAgendamento !== dataLocalIsoHoje()
      && outrosCriteriosValidos) {
      const titulo = "Não é possível informar atraso";
      const descricao = "Você só pode informar atrasos em agendamentos do dia de hoje.";
      if (typeof window.MensagemSistema?.aviso === "function") {
        window.MensagemSistema.aviso(descricao, { titulo });
      } else {
        uiToast("warning", titulo, descricao);
      }
      return;
    }
    if (!podeInformarAtraso(item)) {
      mensagemConfirmacao("aviso", "Este agendamento não está disponível para informar atraso.");
      return;
    }
    const modal = elementoAtraso("modalInformarAtraso");
    if (!modal) return;
    limparEstadoAtraso();
    atrasoEstado.agendamento = item;
    elementoAtraso("atraso_cliente").textContent = resolveCliente(item) || "Cliente";
    elementoAtraso("atraso_horario_atual").textContent = `Horário atual: ${String(item.hora_inicio || item.hora || "—").slice(0, 5)}`;
    elementoAtraso("atraso_profissional").textContent = `Profissional: ${resolveProfissional(item) || "—"}`;
    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");
    elementoAtraso("atraso_minutos")?.focus();
  }

  function fecharModalAtraso() {
    const modal = elementoAtraso("modalInformarAtraso");
    if (modal) {
      modal.classList.remove("ativo");
      modal.setAttribute("aria-hidden", "true");
    }
    limparEstadoAtraso();
  }

  function limparEstadoAtraso() {
    atrasoEstado.agendamento = null;
    atrasoEstado.minutos = null;
    atrasoEstado.previa = null;
    atrasoEstado.ocupado = false;
    const campo = elementoAtraso("atraso_minutos");
    if (campo) campo.value = "";
    document.querySelectorAll("[data-atraso-preset]").forEach((botao) => botao.classList.remove("ativo"));
    elementoAtraso("atraso_previa")?.setAttribute("hidden", "");
    elementoAtraso("atraso_resultado")?.setAttribute("hidden", "");
    elementoAtraso("atraso_avisos")?.setAttribute("hidden", "");
    elementoAtraso("atraso_bloqueios")?.setAttribute("hidden", "");
    limparConteudoAtraso("atraso_afetados");
    limparConteudoAtraso("atraso_avisos");
    limparConteudoAtraso("atraso_bloqueios");
    limparConteudoAtraso("atraso_resultado_clientes");
    const resumo = elementoAtraso("atraso_resultado_resumo");
    if (resumo) resumo.textContent = "";
    mostrarFeedbackAtraso("", "");
    atualizarBotoesAtraso();
  }

  function limparConteudoAtraso(id) { elementoAtraso(id)?.replaceChildren(); }

  function mostrarFeedbackAtraso(tipo, mensagem) {
    const feedback = elementoAtraso("atraso_feedback");
    if (!feedback) return;
    feedback.textContent = mensagem;
    feedback.className = `atraso-feedback${tipo ? ` atraso-feedback--${tipo}` : ""}`;
    feedback.hidden = !mensagem;
  }

  function atualizarBotoesAtraso() {
    const temPrevia = atrasoEstado.previa !== null;
    const bloqueada = (atrasoEstado.previa?.bloqueios || []).length > 0 || atrasoEstado.previa?.valida === false;
    const aplicada = Boolean(atrasoEstado.previa?.aplicada);
    const verImpacto = elementoAtraso("atraso_ver_impacto");
    const alterar = elementoAtraso("atraso_alterar");
    const confirmar = elementoAtraso("atraso_confirmar");
    if (verImpacto) { verImpacto.hidden = temPrevia || aplicada; verImpacto.disabled = atrasoEstado.ocupado; }
    if (alterar) { alterar.hidden = !temPrevia || aplicada; alterar.disabled = atrasoEstado.ocupado; }
    if (confirmar) { confirmar.hidden = !temPrevia || aplicada; confirmar.disabled = atrasoEstado.ocupado || bloqueada; }
  }

  async function apiAtrasoAgenda(path, { metodo = "GET", parametros = {}, corpo = null } = {}) {
    const url = new URL("/public/api/api_central.php", window.location.origin);
    url.searchParams.set("path", path);
    Object.entries(parametros).forEach(([chave, valor]) => url.searchParams.set(chave, String(valor)));
    const headers = { Accept: "application/json" };
    if (metodo !== "GET") headers["X-CSRF-Token"] = String(window.__AUTH__?.csrf_token || "");
    const resposta = await fetch(url.toString(), { method: metodo, headers, body: corpo, credentials: "same-origin", cache: "no-store" });
    const json = await resposta.json().catch(() => null);
    if (!resposta.ok || json?.ok !== true) {
      const erro = new Error(String(json?.user_msg || "Não foi possível processar o atraso agora."));
      erro.code = String(json?.code || "");
      throw erro;
    }
    return json;
  }

  function rotuloAtraso(tipo) {
    const rotulos = {
      ultrapassa_expediente: "O novo horário ultrapassa o expediente.",
      invade_almoco: "O novo horário invade o intervalo de almoço.",
      fora_horario_especial: "O novo horário fica fora do horário especial.",
      invade_bloqueio: "O novo horário invade um bloqueio da agenda.",
      ultrapassa_meia_noite: "O atendimento ultrapassaria a meia-noite.",
      agendamento_ja_iniciado: "A cascata alcança um atendimento já iniciado.",
      dia_indisponivel: "O dia está indisponível para este atendimento."
    };
    return rotulos[tipo] || "Há uma condição que exige atenção na agenda.";
  }

  function renderizarAlertasAtraso(id, itens, tipo) {
    const destino = elementoAtraso(id);
    if (!destino) return;
    destino.replaceChildren();
    if (!Array.isArray(itens) || itens.length === 0) { destino.hidden = true; return; }
    const titulo = document.createElement("strong");
    titulo.textContent = tipo === "bloqueio" ? "Não é possível aplicar este atraso" : "Atenção antes de confirmar";
    const lista = document.createElement("ul");
    itens.forEach((item) => {
      const linha = document.createElement("li");
      linha.textContent = rotuloAtraso(String(item?.tipo || ""));
      lista.append(linha);
    });
    destino.append(titulo, lista);
    destino.hidden = false;
  }

  function renderizarPreviaAtraso(previa) {
    const area = elementoAtraso("atraso_previa");
    if (!area) return;
    const afetados = Array.isArray(previa?.afetados) ? previa.afetados : [];
    const resumo = elementoAtraso("atraso_resumo_texto");
    if (resumo) resumo.textContent = `${afetados.length} cliente${afetados.length === 1 ? "" : "s"} afetado${afetados.length === 1 ? "" : "s"} por ${previa.atraso_min} minuto${Number(previa.atraso_min) === 1 ? "" : "s"}.`;
    renderizarAlertasAtraso("atraso_avisos", previa?.avisos, "aviso");
    renderizarAlertasAtraso("atraso_bloqueios", previa?.bloqueios, "bloqueio");
    const lista = elementoAtraso("atraso_afetados");
    lista?.replaceChildren();
    afetados.forEach((afetado) => {
      const item = document.createElement("article");
      item.className = "atraso-afetado";
      const nome = document.createElement("strong");
      nome.textContent = String(afetado?.cliente_nome || "Cliente");
      const horario = document.createElement("span");
      horario.textContent = `${String(afetado?.hora_original || "—").slice(0, 5)} → ${String(afetado?.nova_hora || "—").slice(0, 5)}`;
      const deslocamento = document.createElement("small");
      const minutos = Number(afetado?.deslocamento_min || 0);
      deslocamento.textContent = `Atraso real: ${minutos} minuto${minutos === 1 ? "" : "s"}`;
      item.append(nome, horario, deslocamento);
      lista?.append(item);
    });
    area.hidden = false;
  }

  function renderizarResultadoComunicacao(dados) {
    const comunicacao = dados?.comunicacao || {};
    const area = elementoAtraso("atraso_resultado");
    const resumo = elementoAtraso("atraso_resultado_resumo");
    const clientes = Array.isArray(comunicacao.clientes) ? comunicacao.clientes : [];
    if (resumo) {
      const total = Number(comunicacao.total_clientes || dados?.total_afetados || 0);
      const completos = Number(comunicacao.sucesso_completo || 0);
      const pendencias = Number(comunicacao.com_pendencia || 0);
      resumo.textContent = pendencias > 0
        ? `Atraso aplicado com sucesso. ${total} clientes afetados; ${completos} avisados completamente e ${pendencias} com comunicação pendente.`
        : `Atraso aplicado com sucesso. ${total} clientes afetados; todos avisados em todos os canais.`;
    }
    const lista = elementoAtraso("atraso_resultado_clientes");
    lista?.replaceChildren();
    clientes.forEach((cliente) => {
      const item = document.createElement("article");
      item.className = "atraso-resultado-cliente";
      const nome = document.createElement("strong");
      nome.textContent = String(cliente?.cliente_nome || "Cliente");
      const canais = document.createElement("span");
      canais.textContent = `Chat: ${String(cliente?.chat?.status || "falhou")} · Notificação: ${String(cliente?.notificacao?.status || "falhou")}`;
      item.append(nome, canais);
      lista?.append(item);
    });
    if (area) area.hidden = false;
  }

  async function verImpactoAtraso() {
    const id = String(atrasoEstado.agendamento?.id_agendamento ?? atrasoEstado.agendamento?.id ?? "");
    const minutos = Number(elementoAtraso("atraso_minutos")?.value);
    if (!/^[1-9]\d{0,17}$/.test(id) || !Number.isInteger(minutos) || minutos < 5 || minutos > 180) {
      mostrarFeedbackAtraso("erro", "Informe um atraso entre 5 e 180 minutos.");
      return;
    }
    if (atrasoEstado.ocupado) return;
    atrasoEstado.ocupado = true;
    atrasoEstado.minutos = minutos;
    mostrarFeedbackAtraso("carregando", "Calculando o impacto na agenda…");
    atualizarBotoesAtraso();
    try {
      const resposta = await apiAtrasoAgenda("agenda/agendamento/atraso/previa", { parametros: { id_agendamento: id, minutos_atraso: minutos } });
      atrasoEstado.previa = resposta.data || null;
      renderizarPreviaAtraso(atrasoEstado.previa || {});
      mostrarFeedbackAtraso((atrasoEstado.previa?.bloqueios || []).length ? "erro" : "sucesso", (atrasoEstado.previa?.bloqueios || []).length ? "Altere o atraso para continuar." : "Confira o impacto antes de confirmar.");
    } catch (erro) {
      atrasoEstado.previa = null;
      mostrarFeedbackAtraso("erro", erro?.message || "Não foi possível calcular o impacto agora.");
    } finally {
      atrasoEstado.ocupado = false;
      atualizarBotoesAtraso();
    }
  }

  async function aplicarAtraso() {
    if (atrasoEstado.ocupado || !atrasoEstado.previa || (atrasoEstado.previa?.bloqueios || []).length || atrasoEstado.previa?.valida === false) return;
    const id = String(atrasoEstado.agendamento?.id_agendamento ?? atrasoEstado.agendamento?.id ?? "");
    const confirmacao = await window.MensagemSistema?.confirmar?.("Confirmar a aplicação deste atraso na agenda?", {
      titulo: "Confirmar atraso?", textoConfirmar: "Confirmar atraso", textoCancelar: "Voltar"
    });
    if (!confirmacao || atrasoEstado.ocupado) return;
    atrasoEstado.ocupado = true;
    mostrarFeedbackAtraso("carregando", "Aplicando atraso e comunicando os clientes…");
    atualizarBotoesAtraso();
    try {
      const corpo = new FormData();
      corpo.set("id_agendamento", id);
      corpo.set("minutos_atraso", String(atrasoEstado.minutos));
      corpo.set("impressao_digital", JSON.stringify(atrasoEstado.previa.impressao_digital));
      const resposta = await apiAtrasoAgenda("agenda/agendamento/atraso/aplicar", { metodo: "POST", corpo });
      atrasoEstado.previa = { ...atrasoEstado.previa, aplicada: true };
      renderizarResultadoComunicacao(resposta.data || {});
      mostrarFeedbackAtraso("sucesso", "Atraso aplicado com sucesso.");
      try {
        await recarregarAgendaAposAtraso?.();
      } catch (erroRecarregar) {
        console.error("[lista-agenda:atraso]", erroRecarregar);
      }
    } catch (erro) {
      if (erro?.code === "DELAY_PREVIEW_OUTDATED") {
        atrasoEstado.previa = null;
        elementoAtraso("atraso_previa")?.setAttribute("hidden", "");
        mostrarFeedbackAtraso("erro", "A agenda mudou desde a prévia. Atualize o impacto antes de confirmar novamente.");
      } else {
        mostrarFeedbackAtraso("erro", erro?.message || "Não foi possível aplicar o atraso agora.");
      }
    } finally {
      atrasoEstado.ocupado = false;
      atualizarBotoesAtraso();
    }
  }

  function buildMenuAcoes(item) {
    const pago = item.pagamento_confirmado === true;
    const statusChat = normalizarStatus(item.status);
    const exibirChat = statusChat === "pendente" || statusChat === "confirmado";
    const exibirConfirmar = statusChat === "pendente";
    const exibirAtraso = statusChat === "confirmado";
    // Concluído, cancelado e faltou não oferecem ações rápidas de mudança de status.
    const exibirFinalizacao = statusChat === "confirmado";
    const exibirCancelar = statusChat === "pendente" || statusChat === "confirmado";
    return `
      <div class="agenda-menu" role="menu">
        <button class="agenda-menu-item" type="button"
          data-acao="visualizar" data-abrir-modal="modalVisualizarAgendamento">
          <i class="fa-regular fa-eye"></i> Visualizar
        </button>

        ${exibirChat ? `
          <button class="agenda-menu-item" type="button" data-acao="abrir_conversa"${podeAbrirConversaChat() ? "" : ' style="display:none"'}>
            <i class="fa-regular fa-comments"></i> Abrir conversa
          </button>
        ` : ""}

        ${exibirConfirmar ? `
          <button class="agenda-menu-item agenda-menu-item--confirmar" type="button"
            data-acao="confirmar" data-acao-rapida="confirmar" data-id-agendamento="${C.escapeHtml(item.id_agendamento ?? item.id ?? "")}"${podeConfirmarAgendamento(item) ? "" : " hidden"}>
            <i class="fa-regular fa-circle-check"></i> <span>Confirmar</span>
          </button>
        ` : ""}

        ${exibirFinalizacao ? `
          ${botaoAcaoRapidaMenu(item, "concluir", "agenda-menu-item--concluir", "fa-regular fa-square-check", "Concluir")}
          ${botaoAcaoRapidaMenu(item, "faltou", "agenda-menu-item--faltou", "fa-solid fa-user-xmark", "Registrar falta")}
        ` : ""}

        ${exibirAtraso ? `
          <button class="agenda-menu-item agenda-menu-item--atraso" type="button"
            data-acao="informar_atraso" data-id-agendamento="${C.escapeHtml(item.id_agendamento ?? item.id ?? "")}"${podeInformarAtraso(item) ? "" : " hidden"}>
            <i class="fa-regular fa-clock"></i> <span>Informar atraso</span>
          </button>
        ` : ""}

        ${!pago ? `
          <button class="agenda-menu-item" type="button"
            data-acao="editar" data-abrir-modal="modalEditarAgendamento">
            <i class="fa-regular fa-pen-to-square"></i> Editar
          </button>
        ` : ""}

        ${exibirCancelar ? botaoAcaoRapidaMenu(item, "cancelar", "danger", "fa-regular fa-circle-xmark", "Cancelar") : ""}

        ${!pago ? `
          <button class="agenda-menu-item danger" type="button" data-acao="excluir">
            <i class="fa-regular fa-trash-can"></i> Excluir
          </button>
        ` : ""}
      </div>
    `;
  }

  // =========================
  // WhatsApp (abertura manual via wa.me)
  // Prioridade por campo: profissional (config própria ativa) -> empresa (ativa) -> sistema.
  // A configuração chega em agenda/agendamento/listar, restrita à empresa da sessão.
  // =========================
  const WHATSAPP_PADRAO_SISTEMA = Object.freeze({
    ddi: "55",
    ddd: "",
    mensagem: "Olá {cliente}! Tudo bem?\nSobre seu agendamento:\n• Serviço: {servico}\n• Horário: {hora}\n\nPosso te ajudar em algo?"
  });
  const WHATSAPP_ROTULOS_STATUS = { pendente: "Pendente", confirmado: "Confirmado", concluido: "Concluído", cancelado: "Cancelado", faltou: "Faltou" };
  let whatsappConfigAgenda = null;

  function normalizarConfigWhatsapp(cfg) {
    if (!cfg || typeof cfg !== "object") return null;
    const ddi = onlyDigits(cfg.ddi);
    const ddd = onlyDigits(cfg.ddd);
    return {
      ddi: /^\d{1,4}$/.test(ddi) ? ddi : "",
      ddd: /^\d{2}$/.test(ddd) ? ddd : "",
      mensagem: typeof cfg.mensagem === "string" ? cfg.mensagem : ""
    };
  }

  function atualizarConfigWhatsapp(bruto) {
    if (!bruto || typeof bruto !== "object") { whatsappConfigAgenda = null; return; }
    const profissionais = {};
    const origem = bruto.profissionais && typeof bruto.profissionais === "object" ? bruto.profissionais : {};
    Object.keys(origem).forEach((chave) => {
      if (!/^[1-9]\d*$/.test(chave)) return;
      const cfg = normalizarConfigWhatsapp(origem[chave]);
      if (cfg) profissionais[chave] = cfg;
    });
    whatsappConfigAgenda = { empresa: normalizarConfigWhatsapp(bruto.empresa), profissionais };
  }

  function resolverConfigWhatsapp(idProfissional) {
    const id = String(idProfissional ?? "").trim();
    const empresa = whatsappConfigAgenda?.empresa || null;
    // Só usa a configuração do próprio profissional do agendamento; sem id válido, usa a da empresa.
    const profissional = /^[1-9]\d*$/.test(id) && whatsappConfigAgenda?.profissionais
      && Object.prototype.hasOwnProperty.call(whatsappConfigAgenda.profissionais, id)
      ? whatsappConfigAgenda.profissionais[id]
      : null;
    const escolher = (campo) => {
      const vProf = profissional ? String(profissional[campo] || "") : "";
      if (vProf.trim() !== "") return vProf;
      const vEmp = empresa ? String(empresa[campo] || "") : "";
      if (vEmp.trim() !== "") return vEmp;
      return WHATSAPP_PADRAO_SISTEMA[campo];
    };
    return { ddi: escolher("ddi"), ddd: escolher("ddd"), mensagem: escolher("mensagem") };
  }

  // Monta o número internacional sem inventar dígitos; combinações ambíguas retornam null.
  function montarNumeroWhatsapp(telefone, ddi, ddd) {
    const tel = onlyDigits(telefone);
    if (!/^\d{1,4}$/.test(ddi)) return null;
    let numero = null;
    if (tel.length === 8 || tel.length === 9) {
      // Número local sem DDD: exige DDD configurado.
      if (!/^\d{2}$/.test(ddd) || tel.startsWith("0")) return null;
      numero = ddi + ddd + tel;
    } else if (tel.length === 10 || tel.length === 11) {
      // DDD + número (inclui DDD 55): recebe sempre o DDI. Prefixo 0 (tronco) é ambíguo.
      if (tel.startsWith("0")) return null;
      numero = ddi + tel;
    } else if (tel.length === 12 || tel.length === 13) {
      // Já contém DDI: aceito somente se coincidir com o DDI configurado.
      if (!tel.startsWith(ddi)) return null;
      numero = tel;
    }
    if (!numero || numero.length < 10 || numero.length > 15) return null;
    return numero;
  }

  function formatarDataWhatsapp(data) {
    const valor = String(data || "").slice(0, 10);
    return /^\d{4}-\d{2}-\d{2}$/.test(valor) ? valor.split("-").reverse().join("/") : "";
  }

  function formatarHoraWhatsapp(hora) {
    const valor = String(hora || "").trim();
    return /^\d{2}:\d{2}/.test(valor) ? valor.slice(0, 5) : "";
  }

  function rotuloStatusWhatsapp(status) {
    const normalizado = normalizarStatus(status);
    return WHATSAPP_ROTULOS_STATUS[normalizado] || String(status || "").trim();
  }

  function aplicarVariaveisWhatsapp(modelo, valores) {
    return String(modelo || "").replace(/\{(cliente|servico|status|data|hora)\}/g, (_, chave) => valores[chave] ?? "");
  }

  function abrirWhatsapp({ telefone, cliente, servico, hora, data, status, idProfissional }) {
    const cfg = resolverConfigWhatsapp(idProfissional);
    const tel = montarNumeroWhatsapp(telefone, cfg.ddi, cfg.ddd);
    if (!tel) {
      uiToast("warning", "WhatsApp", "Telefone inválido, incompleto ou com DDI/DDD incompatível com a configuração. Verifique o cadastro do cliente.");
      return;
    }

    const texto = aplicarVariaveisWhatsapp(cfg.mensagem, {
      cliente: String(cliente || "").trim(),
      servico: String(servico || "").trim(),
      status: rotuloStatusWhatsapp(status),
      data: formatarDataWhatsapp(data),
      hora: formatarHoraWhatsapp(hora)
    });

    window.open(`https://wa.me/${tel}?text=${encodeURIComponent(texto)}`, "_blank");
  }

  // =========================
  // ✅ Identificação profissional (Cliente + Profissional)
  // =========================
  function resolveCliente(item) {
    return String(item?.cliente ?? item?.cliente_nome ?? item?.nome_cliente ?? "").trim();
  }

  function resolveProfissional(item) {
    const p = item?.profissional ?? item?.profissional_nome ?? item?.nome_profissional ?? "";
    return String(p).trim();
  }

  function resolveServico(item) {
    return String(item?.servico ?? item?.servico_nome ?? item?.nome_servico ?? "").trim();
  }

  function renderIdentificacao(item) {
    const profissional = resolveProfissional(item) || "";
    if (!profissional) return "";

    return `
      <div class="agenda-ident" style="display:flex; flex-direction:column; gap:3px;">
        <div class="agenda-ident-linha" style="font-size:12px; color: rgba(0,0,0,.62); line-height:1.2;">
          <span aria-hidden="true">🧑‍💼</span>
          <strong style="font-weight:600;">Profissional:</strong>
          <span>${C.escapeHtml(profissional)}</span>
        </div>
      </div>
    `;
  }

  function estaEmAtendimento({ data, horaInicio, horaFim, status }) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(data) || !horaInicio || !horaFim) return false;
    // Somente um agendamento confirmado pode assumir o estado visual de
    // atendimento; pendente ainda aguarda confirmação.
    if (normalizarStatus(status) !== "confirmado") return false;
    const agora = new Date();
    const inicio = new Date(`${data}T${String(horaInicio).slice(0, 5)}:00`);
    const fim = new Date(`${data}T${String(horaFim).slice(0, 5)}:00`);
    return agora >= inicio && agora < fim;
  }

  function atualizarEstadosTemporais() {
    document.querySelectorAll(".agenda-card[data-data][data-hora-inicio][data-hora-fim]").forEach((card) => {
      const emAtendimento = estaEmAtendimento({
        data: card.dataset.data || "",
        horaInicio: card.dataset.horaInicio || "",
        horaFim: card.dataset.horaFim || "",
        status: card.dataset.status || "",
      });
      card.classList.toggle("agenda-card-em-atendimento", emAtendimento);
      const indicador = card.querySelector(".agenda-em-atendimento");
      if (indicador) indicador.hidden = !emAtendimento;
    });
  }

  function cardTemplate(item) {
    const id = item.id ?? "";
    const hora = item.hora ?? "--:--";

    const cliente = resolveCliente(item) || "Cliente";
    const servico = resolveServico(item) || "Serviço";
    const profissional = resolveProfissional(item) || "";

    const duracao = item.duracao ?? "";
    const obs = item.obs ?? "";
    const pagamento = item.pagamento_confirmado;
    const pago = pagamento === true;
    const data = String(item.data_agendamento ?? item.data ?? "");
    const horaInicio = String(item.hora_inicio ?? item.hora ?? "");
    const horaFim = String(item.hora_fim ?? "");
    const statusNormalizado = normalizarStatus(item.status);
    const emAtendimento = estaEmAtendimento({ data, horaInicio, horaFim, status: statusNormalizado });
    const dataBr = /^\d{4}-\d{2}-\d{2}$/.test(data) ? data.split("-").reverse().join("/") : data;

    const telRaw = String(item.telefone || "");
    const temTel = onlyDigits(telRaw).length >= 8;

    return `
      <article class="agenda-card ${emAtendimento ? "agenda-card-em-atendimento" : ""}" data-id="${C.escapeHtml(id)}" data-pago="${pago ? "1" : "0"}" data-data="${C.escapeHtml(data)}" data-hora-inicio="${C.escapeHtml(horaInicio)}" data-hora-fim="${C.escapeHtml(horaFim)}" data-status="${C.escapeHtml(statusNormalizado)}">
        <div class="agenda-hora"><strong>${C.escapeHtml(hora)}</strong>${dataBr ? `<small>${C.escapeHtml(dataBr)}</small>` : ""}</div>

        <div class="agenda-info">

          <!-- ✅ volta como era: CLIENTE EM CIMA (maior) -->
          <div class="agenda-nome">${C.escapeHtml(cliente)}</div>

          <!-- serviço + duração -->
          <div class="agenda-servico-linha">
            <div class="agenda-servico">${C.escapeHtml(servico)}</div>
            ${duracao ? `<div class="agenda-duracao">• ${C.escapeHtml(duracao)}</div>` : ""}
          </div>

          <!-- ✅ mantém profissional como está (linha pequena) -->
          ${renderIdentificacao({ ...item, profissional })}

          <div class="agenda-linha-extra">
            <span class="agenda-em-atendimento" ${emAtendimento ? "" : "hidden"}><i aria-hidden="true"></i> Em atendimento</span>
            ${badgeStatus(item.status)}
            ${textoPagamento(pagamento)}
          </div>

          ${obs ? `<div class="agenda-obs">${C.escapeHtml(obs)}</div>` : ""}
        </div>

        <div class="agenda-acoes" aria-haspopup="menu">
          <button class="agenda-btn-whats ${temTel ? "" : "is-disabled"}" type="button"
            data-acao="whatsapp"
            data-telefone="${C.escapeHtml(telRaw)}"
            data-cliente="${C.escapeHtml(cliente)}"
            data-servico="${C.escapeHtml(servico)}"
            data-hora="${C.escapeHtml(horaInicio)}"
            data-data="${C.escapeHtml(data)}"
            data-status="${C.escapeHtml(String(item.status ?? ""))}"
            data-id-profissional="${C.escapeHtml(String(item.id_profissional ?? ""))}"
            ${temTel ? "" : 'aria-disabled="true" disabled'}
            title="${temTel ? "WhatsApp" : "Sem telefone cadastrado"}">
            <i class="fa-brands fa-whatsapp"></i>
          </button>

          <button class="agenda-btn-acoes" type="button"
            data-acao="toggle-menu" aria-expanded="false" title="Ações">
            ${iconAcoes()}
          </button>

          ${buildMenuAcoes({ ...item, cliente, profissional, servico })}
        </div>
      </article>
    `;
  }

  async function obterDados(parametros = {}) {
    const url = new URL(CFG.ENDPOINT, window.location.origin);
    Object.entries(parametros).forEach(([chave, valor]) => {
      if (valor !== "" && valor !== null && typeof valor !== "undefined") url.searchParams.set(chave, String(valor));
    });
    const resposta = await fetch(url.toString(), {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
      cache: "no-store"
    });
    const json = await resposta.json().catch(() => null);
    if (!resposta.ok || !json || json.ok === false) {
      throw new Error(json?.user_msg || "Não foi possível carregar os agendamentos.");
    }
    if (Object.prototype.hasOwnProperty.call(json, "whatsapp")) atualizarConfigWhatsapp(json.whatsapp);
    return json.data || {};
  }

  function formatarDataBr(data) {
    const valor = String(data || "");
    if (!/^\d{4}-\d{2}-\d{2}$/.test(valor)) return "—";
    return new Date(`${valor}T12:00:00`).toLocaleDateString("pt-BR", { weekday: "long", day: "2-digit", month: "long", year: "numeric" });
  }

  function formatarMoeda(valor) {
    const numero = Number(valor);
    return Number.isFinite(numero) ? numero.toLocaleString("pt-BR", { style: "currency", currency: "BRL" }) : "—";
  }

  function preencherModalVisualizar(item) {
    if (!item) return;
    const set = (id, valor) => { const campo = document.getElementById(id); if (campo) campo.textContent = valor; };
    const cliente = resolveCliente(item) || "Cliente";
    const telefone = String(item.telefone || "").trim();
    set("vc_avatar", cliente.charAt(0).toUpperCase());
    set("vc_nome", cliente);
    set("vc_telefone", telefone ? `Telefone: ${telefone}` : "Telefone não informado");
    set("vc_data", formatarDataBr(item.data_agendamento || item.data));
    set("vc_hora", `${item.hora_inicio || item.hora || "—"}${item.hora_fim ? ` às ${item.hora_fim}` : ""}`);
    set("vc_servico", resolveServico(item) || "—");
    set("vc_duracao", item.duracao || `${item.duracao_min || 0} min`);
    set("vc_valor", formatarMoeda(item.valor_aplicado));
    set("vc_profissional", resolveProfissional(item) || "—");
    set("vc_especialidade", item.especialidade || "Não informada");
    set("vc_recorrencia", Number(item.repetir_semanalmente) === 1 ? `Semanal${item.recorrencia_data_fim ? ` até ${formatarDataBr(item.recorrencia_data_fim)}` : ""}` : "Não se repete");
    set("vc_obs", item.observacao || item.obs || "Nenhuma observação informada.");

    const chip = document.getElementById("vc_chip_status");
    const status = normalizarStatus(item.status);
    if (chip) { chip.textContent = ({pendente:"Pendente",confirmado:"Confirmado",concluido:"Concluído",cancelado:"Cancelado",faltou:"Faltou"})[status] || item.status; chip.dataset.status = status; }
    const modal = document.getElementById("modalVisualizarAgendamento");
    if (modal) {
      modal.dataset.idAgendamento = String(item.id_agendamento ?? item.id ?? "");
      modal.dataset.status = status;
      modal.dataset.dataAgendamento = String(item.data_agendamento ?? item.data ?? "");
    }
    const confirmar = document.getElementById("vc_btn_confirmar");
    if (confirmar) {
      confirmar.dataset.idAgendamento = String(item.id_agendamento ?? item.id ?? "");
      confirmar.hidden = !podeConfirmarAgendamento(item);
      confirmar.disabled = false;
      confirmar.innerHTML = '<i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>Confirmar agendamento</span>';
      const acoesConfirmar = document.getElementById("vc_confirmar_acoes");
      if (acoesConfirmar) acoesConfirmar.hidden = confirmar.hidden;
    }
    const atraso = document.getElementById("vc_btn_informar_atraso");
    if (atraso) {
      atraso.dataset.idAgendamento = String(item.id_agendamento ?? item.id ?? "");
      atraso.hidden = !podeInformarAtraso(item);
      atraso.disabled = false;
      const acoesConfirmar = document.getElementById("vc_confirmar_acoes");
      if (acoesConfirmar) acoesConfirmar.hidden = Boolean(confirmar?.hidden) && atraso.hidden;
    }
    const whats = document.getElementById("vc_btn_whats");
    if (whats) { whats.disabled = onlyDigits(telefone).length < 8; whats.onclick = () => abrirWhatsapp({ telefone, cliente, servico: resolveServico(item), hora: item.hora_inicio || item.hora, data: item.data_agendamento || item.data, status: item.status, idProfissional: item.id_profissional }); }
    const copiar = document.getElementById("vc_btn_copiar_tel");
    if (copiar) { copiar.disabled = !telefone; copiar.onclick = async () => { try { await navigator.clipboard.writeText(telefone); uiToast("success", "Telefone", "Telefone copiado."); } catch { uiToast("warning", "Telefone", "Não foi possível copiar o telefone."); } }; }
  }

  // ✅ aplica período + status
  function aplicarFiltroLista(dia, lista) {
    const f = FILTRO_GLOBAL;
    return lista.filter(item => {
      const okStatus = statusBate(item.status, f.status);
      return okStatus;
    });
  }

  // ==========================================================
  // ✅ PAGINAÇÃO (PADRÃO MANUTENÇÃO: btn-pag, Anterior/Próximo)
  // ==========================================================
  function paginarLista(dia, lista) {
    const total = lista.length;
    const porPag = CFG.itensPorPagina;
    const totalPaginas = Math.max(1, Math.ceil(total / porPag));

    const atual = Math.max(1, Math.min(PAGINA_ATUAL[dia] || 1, totalPaginas));
    PAGINA_ATUAL[dia] = atual;

    const ini = (atual - 1) * porPag;
    const fim = ini + porPag;

    return {
      pageItems: lista.slice(ini, fim),
      total,
      porPag,
      totalPaginas,
      paginaAtual: atual,
    };
  }

  function renderPaginacao(dia, info, pagDiv, renderTudoDia) {
    if (!pagDiv) return;

    if (info.total === 0 || info.totalPaginas <= 1) {
      pagDiv.innerHTML = "";
      return;
    }

    const { paginaAtual, totalPaginas } = info;
    pagDiv.innerHTML = "";

    if (paginaAtual > 1) {
      const btnAnterior = document.createElement("button");
      btnAnterior.type = "button";
      btnAnterior.textContent = "◀ Anterior";
      btnAnterior.classList.add("btn-pag");
      btnAnterior.addEventListener("click", () => {
        PAGINA_ATUAL[dia] = Math.max(1, PAGINA_ATUAL[dia] - 1);
        renderTudoDia();
      });
      pagDiv.appendChild(btnAnterior);
    }

    if (paginaAtual < totalPaginas) {
      const btnProximo = document.createElement("button");
      btnProximo.type = "button";
      btnProximo.textContent = "Próximo ▶";
      btnProximo.classList.add("btn-pag");
      btnProximo.addEventListener("click", () => {
        PAGINA_ATUAL[dia] = Math.min(totalPaginas, PAGINA_ATUAL[dia] + 1);
        renderTudoDia();
      });
      pagDiv.appendChild(btnProximo);
    }
  }

  // ==========================================================
  // ✅ RENDER DIA (FILTRO + PAGINAÇÃO + CARDS)
  // ==========================================================
  function renderDia(dia, boxId, pagId, dadosSemana) {
    const box = document.getElementById(boxId);
    const pagDiv = document.getElementById(pagId);
    if (!box) return;

    // lista base
    let lista = (dadosSemana?.[dia] || [])
      .slice()
      .sort((a, b) => String(a.hora).localeCompare(String(b.hora)));

    const termoPesquisa = C.normalizar(PESQUISA_GLOBAL);

    // Sem pesquisa, cada aba representa somente a data da semana selecionada.
    // Durante a pesquisa global, o recorte de data é suspenso exclusivamente
    // para ela, mantendo os resultados organizados pelas mesmas sete abas.
    const botaoDia = document.querySelector(`.menu-principal-abas button[data-aba="${dia}"]`);
    const dataDaAba = String(botaoDia?.dataset?.dataAgenda || "");
    if (dataDaAba && !termoPesquisa) {
      lista = lista.filter((item) => String(item.data_agendamento || item.data || "") === dataDaAba);
    }

    // filtro (período + status)
    lista = aplicarFiltroLista(dia, lista);

    // A pesquisa é única e consulta os dados de todas as abas da semana.
    // Cada aba continua exibindo exclusivamente os registros da própria data.
    if (termoPesquisa) {
      lista = lista.filter((item) => C.normalizar(Object.values(item || {}).join(" ")).includes(termoPesquisa));
    }

    // paginação (padrão manutenção)
    const info = paginarLista(dia, lista);

    // vazio
    if (info.total === 0) {
      const f = FILTRO_GLOBAL;
      const temStatus = !!normalizarStatus(f.status);

      const detalhe = [
        temStatus ? `com status "${normalizarStatus(f.status)}"` : "",
      ].filter(Boolean).join(" ");

      const msg = detalhe
        ? `Nenhum agendamento ${detalhe}.`
        : `Nenhum agendamento para ${dataDaAba ? dataDaAba.split("-").reverse().join("/") : dia}.`;

      box.innerHTML = `
        <div class="agenda-vazio">
          <div class="agenda-vazio-icone">📭</div>
          <div class="agenda-vazio-titulo">${C.escapeHtml(msg)}</div>
          <div class="agenda-vazio-sub">Use o botão <strong>Novo Agendamento</strong> para adicionar.</div>
        </div>
      `;
      if (pagDiv) pagDiv.innerHTML = "";
      return;
    }

    // render cards da página
    box.innerHTML = info.pageItems.map(cardTemplate).join("");
    atualizarEstadosTemporais();

    // render paginação (Anterior/Próximo)
    const renderTudoDia = () => renderDia(dia, boxId, pagId, dadosSemana);
    renderPaginacao(dia, info, pagDiv, renderTudoDia);
  }

  // =========================
  // Menu flutuante via Core ✅
  // =========================
  const menuCtrl = C.createFloatingMenuController({ rootSelector: CFG.ROOT_SELECTOR_MENU });

  // =========================
  // Pesquisa global aplicada aos sete dias da semana
  // =========================
  function getPesquisaAbaAtiva() {
    const barra = document.querySelector(".agenda-pesquisa-global");
    const input = document.getElementById("pesquisar-agenda-global");
    const limpar = barra?.querySelector(".btn-limpar-pesquisa") || null;
    const info = barra?.querySelector(".pesquisa-info") || null;
    return { input, limpar, info };
  }

  let getDadosPesquisa = () => ({});

  function atualizarResumoPesquisa(dadosSemana) {
    const { input, limpar, info } = getPesquisaAbaAtiva();
    if (!input) return;

    input.value = PESQUISA_GLOBAL;
    const termo = C.normalizar(PESQUISA_GLOBAL);
    if (limpar) limpar.style.display = termo ? "inline-flex" : "none";

    if (!info || !termo) { if (info) info.textContent = ""; return; }

    const total = DIAS.reduce((soma, { dia }) => {
      let itens = (dadosSemana?.[dia] || []).slice();
      itens = aplicarFiltroLista(dia, itens);
      return soma + itens.filter((item) => C.normalizar(Object.values(item || {}).join(" ")).includes(termo)).length;
    }, 0);

    info.textContent = total ? `${total} encontrado(s) em toda a agenda.` : "Nenhum agendamento encontrado em toda a agenda.";
  }

  function pesquisa_aplicar() {
    const dados = getDadosPesquisa();
    DIAS.forEach(({ dia, box, pag }) => {
      PAGINA_ATUAL[dia] = 1;
      renderDia(dia, box, pag, dados);
    });
    atualizarResumoPesquisa(dados);
  }

  const pesquisa_deb = C.debounce(pesquisa_aplicar, 80);

  function sincronizarPesquisas() {
    const input = document.getElementById("pesquisar-agenda-global");
    if (input && input.value !== PESQUISA_GLOBAL) input.value = PESQUISA_GLOBAL;
  }

  // =========================
  // Filtro único compartilhado por todas as abas da semana
  // =========================
  function idsFiltro(sufixo) {
    return {
      btn:     `btnPeriodo_${sufixo}`,
      pop:     `popoverPeriodo_${sufixo}`,
      form:    `formPeriodo_${sufixo}`,
      status:  `status_${sufixo}`,
      label:   `labelPeriodo_${sufixo}`,
      limpar:  `limparFiltro_${sufixo}`,
      fechar:  `fecharPopover_${sufixo}`,
    };
  }

  function fecharTodosPopovers() {
    const { btn, pop } = idsFiltro("global");
    const botao = document.getElementById(btn);
    const painel = document.getElementById(pop);
    if (!painel || !botao) return;
    painel.setAttribute("hidden", "");
    botao.setAttribute("aria-expanded", "false");
  }

  // ✅ label mostra filtro + status (se tiver)
  function setLabelPeriodo(labelEl, iniISO, fimISO, statusVal) {
    if (!labelEl) return;

    const st = normalizarStatus(statusVal);

    if (!st) {
      labelEl.textContent = "Filtro";
      return;
    }

    const partes = [];
    if (st) partes.push(st.charAt(0).toUpperCase() + st.slice(1));

    labelEl.textContent = partes.join(" • ");
  }

  function sincronizarControlesFiltro() {
    const ids = idsFiltro("global");
    const status = document.getElementById(ids.status);
    if (status) status.value = FILTRO_GLOBAL.status;
    setLabelPeriodo(document.getElementById(ids.label), "", "", FILTRO_GLOBAL.status);
  }

  function renderizarTodasAbas(dadosSemana) {
    DIAS.forEach(({ dia, box, pag }) => {
      PAGINA_ATUAL[dia] = 1;
      renderDia(dia, box, pag, dadosSemana);
    });
    sincronizarControlesFiltro();
    sincronizarPesquisas();
    atualizarResumoPesquisa(dadosSemana);
  }

  function bindFiltroGlobal(getDadosSemanaRef) {
    const filtro = "global";
    const ids = idsFiltro(filtro);

    const btn    = document.getElementById(ids.btn);
    const pop    = document.getElementById(ids.pop);
    const form   = document.getElementById(ids.form);
    const status = document.getElementById(ids.status);
    const label  = document.getElementById(ids.label);
    const limpar = document.getElementById(ids.limpar);
    const fechar = document.getElementById(ids.fechar);

    if (!btn || !pop || !form || !status || !label || !limpar || !fechar) return;

    // O controle visual reflete o estado global aplicado à semana.
    sincronizarControlesFiltro();

    btn.addEventListener("click", (ev) => {
      ev.stopPropagation();
      const aberto = !pop.hasAttribute("hidden");
      fecharTodosPopovers();
      if (!aberto) {
        pop.removeAttribute("hidden");
        btn.setAttribute("aria-expanded", "true");
      }
    });

    fechar.addEventListener("click", (ev) => {
      ev.stopPropagation();
      pop.setAttribute("hidden", "");
      btn.setAttribute("aria-expanded", "false");
    });

    limpar.addEventListener("click", (ev) => {
      ev.stopPropagation();

      status.value = "";
      FILTRO_GLOBAL.status = "";
      pop.setAttribute("hidden", "");
      btn.setAttribute("aria-expanded", "false");

      const dados = getDadosSemanaRef();
      renderizarTodasAbas(dados);
    });

    form.addEventListener("submit", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      FILTRO_GLOBAL.status = status.value || "";

      pop.setAttribute("hidden", "");
      btn.setAttribute("aria-expanded", "false");

      const dados = getDadosSemanaRef();
      renderizarTodasAbas(dados);
    });
  }

  // =========================
  // Eventos globais
  // =========================
  function bindEventosGlobais(getDadosSemanaRef) {
    document.addEventListener("click", (ev) => {
      const btnWhats  = ev.target.closest('button[data-acao="whatsapp"]');
      const btnToggle = ev.target.closest('button[data-acao="toggle-menu"]');
      const menuItem  = ev.target.closest(".agenda-menu-item");
      const btnConfirmar = ev.target.closest('button[data-acao="confirmar"]');
      const btnAcaoRapida = ev.target.closest("button[data-acao-rapida]");
      const btnInformarAtrasoModal = ev.target.closest("#vc_btn_informar_atraso");
      const btnPresetAtraso = ev.target.closest("[data-atraso-preset]");
      const btnVerImpactoAtraso = ev.target.closest("#atraso_ver_impacto");
      const btnAlterarAtraso = ev.target.closest("#atraso_alterar");
      const btnConfirmarAtraso = ev.target.closest("#atraso_confirmar");
      const fecharAtraso = ev.target.closest("#modalInformarAtraso [data-fechar-modal]");
      const modalAtraso = document.getElementById("modalInformarAtraso");
      const cardClicado = ev.target.closest(".agenda-card");
      const btnLimpar = ev.target.closest(".agenda-pesquisa-global .btn-limpar-pesquisa");
      const btnAba    = ev.target.closest(".menu-principal-abas button[data-aba]");
      const cliqueDentroPopover = ev.target.closest(".popover");

      // se clicou fora de popover, fecha todos
      if (!cliqueDentroPopover) fecharTodosPopovers();

      if (btnWhats) {
        ev.stopPropagation();
        if (btnWhats.disabled || btnWhats.getAttribute("aria-disabled") === "true") return;

        abrirWhatsapp({
          telefone: btnWhats.dataset.telefone,
          cliente: btnWhats.dataset.cliente,
          servico: btnWhats.dataset.servico,
          hora: btnWhats.dataset.hora,
          data: btnWhats.dataset.data,
          status: btnWhats.dataset.status,
          idProfissional: btnWhats.dataset.idProfissional,
        });
        return;
      }

      if (btnToggle) {
        ev.stopPropagation();
        // As permissões podem chegar depois da renderização dos cards.
        atualizarAcoesConversaChat();
        atualizarAcoesConfirmar();
        menuCtrl.toggle(btnToggle);
        return;
      }

      if (fecharAtraso || (modalAtraso && ev.target === modalAtraso)) {
        if (!atrasoEstado.ocupado) fecharModalAtraso();
        return;
      }

      if (btnPresetAtraso) {
        const minutos = String(btnPresetAtraso.dataset.atrasoPreset || "");
        const campo = elementoAtraso("atraso_minutos");
        if (campo) campo.value = minutos;
        document.querySelectorAll("[data-atraso-preset]").forEach((botao) => botao.classList.toggle("ativo", botao === btnPresetAtraso));
        mostrarFeedbackAtraso("", "");
        return;
      }

      if (btnVerImpactoAtraso) {
        verImpactoAtraso();
        return;
      }

      if (btnAlterarAtraso) {
        atrasoEstado.previa = null;
        elementoAtraso("atraso_previa")?.setAttribute("hidden", "");
        mostrarFeedbackAtraso("", "");
        atualizarBotoesAtraso();
        elementoAtraso("atraso_minutos")?.focus();
        return;
      }

      if (btnConfirmarAtraso) {
        aplicarAtraso();
        return;
      }

      if (btnInformarAtrasoModal) {
        const id = String(btnInformarAtrasoModal.dataset.idAgendamento || "");
        const dados = getDadosSemanaRef();
        const item = Object.values(dados || {}).flat().find((registro) => String(registro.id_agendamento ?? registro.id) === id);
        if (item) {
          document.getElementById("modalVisualizarAgendamento")?.classList.remove("ativo");
          abrirModalAtraso(item);
        }
        return;
      }

      if (btnConfirmar) {
        ev.stopPropagation();
        menuCtrl.fechar();
        if (btnConfirmar.disabled || btnConfirmar.hidden) return;
        confirmarAgendamentoRapido(btnConfirmar.dataset.idAgendamento);
        return;
      }

      // Concluir, Registrar falta e Cancelar: mesmo fluxo aprovado da confirmação rápida.
      if (btnAcaoRapida) {
        ev.stopPropagation();
        menuCtrl.fechar();
        if (btnAcaoRapida.disabled || btnAcaoRapida.hidden) return;
        executarAcaoRapidaAgendamento(btnAcaoRapida.dataset.idAgendamento, String(btnAcaoRapida.dataset.acaoRapida || ""));
        return;
      }

      if (menuItem) {
        const card = menuCtrl.getOwnerCard() || menuItem.closest(".agenda-card");
        const id = card?.dataset?.id || "";
        const pago = card?.dataset?.pago === "1";
        const acao = menuItem.dataset.acao || "";
        const modal = menuItem.dataset.abrirModal || "";

        menuCtrl.fechar();
        const dados = getDadosSemanaRef();
        const item = Object.values(dados || {}).flat().find((registro) => String(registro.id_agendamento ?? registro.id) === String(id));
        if (acao === "visualizar") preencherModalVisualizar(item);
        if (acao === "editar") {
          document.dispatchEvent(new CustomEvent("agenda:editar:selecionado", { detail: { id_agendamento: id, agendamento: item || null } }));
        }
        if (acao === "excluir") {
          document.dispatchEvent(new CustomEvent("agenda:excluir:selecionado", { detail: { id_agendamento: id, agendamento: item || null } }));
        }
        if (acao === "abrir_conversa") abrirConversaDoAgendamento(id);
        if (acao === "informar_atraso" && item) abrirModalAtraso(item);
        return;
      }

      if (btnLimpar) {
        const { input } = getPesquisaAbaAtiva();
        if (input) {
          PESQUISA_GLOBAL = "";
          sincronizarPesquisas();
          input.focus();
          pesquisa_aplicar();
        }
        return;
      }

      if (btnAba) {
        menuCtrl.fechar();
        fecharTodosPopovers();
        setTimeout(() => {
          sincronizarControlesFiltro();
          sincronizarPesquisas();
          atualizarResumoPesquisa(getDadosSemanaRef());
        }, 0);
        return;
      }

      // O corpo inteiro do card abre a visualização. Botões, links e itens do
      // menu mantêm suas próprias ações e não disparam o modal por acidente.
      if (cardClicado && !ev.target.closest("button, a, input, select, textarea, .agenda-menu")) {
        const id = String(cardClicado.dataset.id || "");
        const dados = getDadosSemanaRef();
        const item = Object.values(dados || {}).flat().find((registro) => String(registro.id_agendamento ?? registro.id) === id);
        if (item) {
          preencherModalVisualizar(item);
          const modalVisualizar = document.getElementById("modalVisualizarAgendamento");
          if (modalVisualizar) {
            modalVisualizar.classList.add("ativo");
            modalVisualizar.setAttribute("aria-hidden", "false");
          }
        }
        return;
      }

      menuCtrl.fechar();
    });

    document.addEventListener("input", (ev) => {
      const el = ev.target;
      if (el.matches("#atraso_minutos")) {
        document.querySelectorAll("[data-atraso-preset]").forEach((botao) => botao.classList.remove("ativo"));
        atrasoEstado.previa = null;
        elementoAtraso("atraso_previa")?.setAttribute("hidden", "");
        atualizarBotoesAtraso();
        return;
      }
      if (!el.matches("#pesquisar-agenda-global")) return;
      PESQUISA_GLOBAL = el.value;
      sincronizarPesquisas();
      pesquisa_deb();
    });

    document.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape") {
        menuCtrl.fechar();
        fecharTodosPopovers();
      }
    });

    document.addEventListener("amagenda:sessao-carregada", atualizarAcoesConfirmar);
  }

  // =========================
  // Init
  // =========================
  async function init() {
    let dadosSemana;
    try {
      dadosSemana = await obterDados();
    } catch (erro) {
      console.error("[lista-agenda]", erro);
      uiToast("danger", "Agenda", erro.message || "Não foi possível carregar os agendamentos.");
      dadosSemana = { segunda: [], terca: [], quarta: [], quinta: [], sexta: [], sabado: [], domingo: [] };
    }
    const getDadosSemanaRef = () => dadosSemana;
    getDadosPesquisa = getDadosSemanaRef;

    recarregarAgendaAposAtraso = async () => {
      dadosSemana = await obterDados();
      renderizarTodasAbas(dadosSemana);
    };

    // render inicial
    DIAS.forEach(({ dia, box, pag }) => {
      if (!PAGINA_ATUAL[dia]) PAGINA_ATUAL[dia] = 1;
      renderDia(dia, box, pag, dadosSemana);
    });

    // Um único formulário controla o período e o status das sete abas.
    bindFiltroGlobal(getDadosSemanaRef);

    // eventos globais
    bindEventosGlobais(getDadosSemanaRef);

    // O calendário mensal pode trocar a semana sem recarregar a página.
    document.addEventListener("agenda:semana:alterada", () => {
      renderizarTodasAbas(dadosSemana);
    });

    // Recebe um resultado da pesquisa geral, abre sua página dentro da aba
    // correta e destaca somente o card localizado.
    async function localizarAgendamento(id, data) {
      if (!id || !data) return null;

      PESQUISA_GLOBAL = "";
      FILTRO_GLOBAL.status = "";
      sincronizarPesquisas();
      sincronizarControlesFiltro();

      const mapa = ["domingo", "segunda", "terca", "quarta", "quinta", "sexta", "sabado"];
      const dataLocal = new Date(`${data}T12:00:00`);
      const dia = mapa[dataLocal.getDay()];
      const config = DIAS.find((item) => item.dia === dia);
      if (!config) return null;

      // Carrega a semana específica diretamente da API. Assim, até um registro
      // fora do limite da listagem inicial pode ser localizado corretamente.
      const deslocamento = (dataLocal.getDay() + 6) % 7;
      const inicioSemana = new Date(dataLocal);
      inicioSemana.setDate(dataLocal.getDate() - deslocamento);
      const fimSemana = new Date(inicioSemana);
      fimSemana.setDate(inicioSemana.getDate() + 6);
      const paraIso = (valor) => `${valor.getFullYear()}-${String(valor.getMonth() + 1).padStart(2, "0")}-${String(valor.getDate()).padStart(2, "0")}`;
      const inicioIso = paraIso(inicioSemana);
      const fimIso = paraIso(fimSemana);

      try {
        const semanaLocalizada = await obterDados({ data_inicio: inicioIso, data_fim: fimIso });
        DIAS.forEach(({ dia: nomeDia }) => {
          const foraDaSemana = (dadosSemana?.[nomeDia] || []).filter((item) => {
            const dataItem = String(item.data_agendamento || item.data || "");
            return dataItem < inicioIso || dataItem > fimIso;
          });
          dadosSemana[nomeDia] = [...foraDaSemana, ...(semanaLocalizada?.[nomeDia] || [])];
        });
      } catch (erro) {
        console.error("[lista-agenda:localizar-semana]", erro);
        uiToast("danger", "Pesquisa", erro.message || "Não foi possível abrir a semana do agendamento.");
        return null;
      }

      renderizarTodasAbas(dadosSemana);

      const itensDaData = (dadosSemana?.[dia] || [])
        .filter((item) => String(item.data_agendamento || item.data || "") === data)
        .sort((a, b) => String(a.hora_inicio || a.hora || "").localeCompare(String(b.hora_inicio || b.hora || "")));
      const indice = itensDaData.findIndex((item) => String(item.id_agendamento ?? item.id) === id);
      PAGINA_ATUAL[dia] = indice >= 0 ? Math.floor(indice / CFG.itensPorPagina) + 1 : 1;
      renderDia(dia, config.box, config.pag, dadosSemana);

      return new Promise((resolve) => requestAnimationFrame(() => {
        const card = document.querySelector(`#${config.box} .agenda-card[data-id="${CSS.escape(id)}"]`);
        if (!card) { resolve(null); return; }
        card.classList.add("agenda-card-localizado");
        card.scrollIntoView({ behavior: "smooth", block: "center" });
        setTimeout(() => card.classList.remove("agenda-card-localizado"), 3500);
        resolve(card);
      }));
    }

    document.addEventListener("agenda:localizar-agendamento", (evento) => {
      localizarAgendamento(String(evento.detail?.id_agendamento || ""), String(evento.detail?.data_agendamento || ""));
    });

    // ------------------------------------------------------------------
    // Notificação de agendamento pendente → Agenda → dia → card → edição.
    // O id é apenas uma referência: data, empresa e permissão vêm do backend
    // (agenda/agendamento/detalhar valida empresa da sessão, perfil e o
    // vínculo do profissional). Nenhum dado da notificação é usado como autoridade.
    // ------------------------------------------------------------------
    let abrindoAgendamento = false;

    async function detalharAgendamentoAutorizado(id) {
      const url = new URL("/public/api/api_central.php", window.location.origin);
      url.searchParams.set("path", "agenda/agendamento/detalhar");
      url.searchParams.set("id_agendamento", String(id));
      const resposta = await fetch(url.toString(), {
        method: "GET",
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store"
      });
      const json = await resposta.json().catch(() => null);
      if (!resposta.ok || !json || json.ok !== true || !json.data) {
        if (resposta.status === 403) throw new Error("Você não tem permissão para acessar este agendamento.");
        if (resposta.status === 401) throw new Error("Sessão expirada. Faça login novamente.");
        throw new Error("Agendamento não encontrado ou você não tem acesso a ele.");
      }
      return json.data;
    }

    // ============================================================
    // AGENDA — RETORNO PARA O CHAT
    // ============================================================
    // Só existe quando a Agenda foi aberta pelo Chat (origem=chat). O destino é FIXO:
    // nunca aceita URL/caminho vindo do navegador; id_conversa é só um inteiro positivo
    // e não autoriza nada (o Chat revalida a conversa no backend ao reabrir).
    const PAGINA_CHAT_RETORNO = "/public/views/chat/chat-interno.html";

    function prepararRetornoChat(origem, idConversaBruto) {
      if (origem !== "chat" || !/^[1-9]\d{0,17}$/.test(String(idConversaBruto || ""))) return null;
      const destino = new URL(PAGINA_CHAT_RETORNO, window.location.origin);
      destino.searchParams.set("id_conversa", String(idConversaBruto));
      const url = `${destino.pathname}${destino.search}`;
      // Lido por editar-agendamento.js após salvar (no lugar do reload da Agenda).
      window.AgendaRetornoChat = Object.freeze({ destino: () => url });
      return url;
    }

    // Fechar o modal aberto pelo deep link (edição ou visualização) volta para a conversa.
    function monitorarFechamentoParaChat(destino) {
      const modais = ["modalEditarAgendamento", "modalVisualizarAgendamento"]
        .map((id) => document.getElementById(id))
        .filter((modal) => modal?.classList.contains("ativo"));
      if (!modais.length) return;
      let retornando = false;
      const observador = new MutationObserver(() => {
        if (retornando || modais.some((modal) => modal.classList.contains("ativo"))) return;
        retornando = true;
        observador.disconnect();
        window.location.assign(destino);
      });
      modais.forEach((modal) => observador.observe(modal, { attributes: true, attributeFilter: ["class"] }));
    }

    async function abrirAgendamentoPorId(idBruto, opcoes = {}) {
      // Sem código conhecido (ex.: deep link do Chat) mantém o aviso original de pendentes.
      const codigoNotificacao = CODIGOS_NOTIFICACAO_AGENDAMENTO.includes(String(opcoes?.codigoNotificacao || ""))
        ? String(opcoes.codigoNotificacao)
        : "agenda.agendamento_pendente";
      const id = Number(idBruto);
      if (!Number.isInteger(id) || id <= 0 || abrindoAgendamento) return false;
      abrindoAgendamento = true;
      try {
        let registro;
        try {
          registro = await detalharAgendamentoAutorizado(id);
        } catch (erro) {
          uiToast("warning", "Agendamento", erro.message);
          return false;
        }

        const data = String(registro.data_agendamento || "");
        if (String(registro.id_agendamento) !== String(id) || !/^\d{4}-\d{2}-\d{2}$/.test(data)) {
          uiToast("warning", "Agendamento", "Não foi possível abrir este agendamento.");
          return false;
        }

        // Semana e aba do dia vêm do registro autorizado; depois a lista carrega
        // a semana pela API e posiciona o card (mesmo fluxo da pesquisa geral).
        window.AgendaSemana?.irParaData(data, true);
        await localizarAgendamento(String(id), data);

        const item = Object.values(dadosSemana || {}).flat()
          .find((registroLista) => String(registroLista.id_agendamento ?? registroLista.id) === String(id));

        // Mesma regra do menu do card: agendamento pago não oferece edição.
        if (item?.pagamento_confirmado === true) {
          preencherModalVisualizar(item);
          const modalVisualizar = document.getElementById("modalVisualizarAgendamento");
          modalVisualizar?.classList.add("ativo");
          modalVisualizar?.setAttribute("aria-hidden", "false");
          return true;
        }

        const modalEditar = document.getElementById("modalEditarAgendamento");
        if (!modalEditar || modalEditar.classList.contains("ativo")) return false;
        modalEditar.classList.add("ativo");
        modalEditar.setAttribute("aria-hidden", "false");
        document.dispatchEvent(new CustomEvent("agenda:editar:selecionado", {
          detail: { id_agendamento: String(id), agendamento: item || null, codigo_notificacao: opcoes?.codigoNotificacao ? codigoNotificacao : "" }
        }));

        const avisoDeepLink = avisoDeepLinkAgendamento(codigoNotificacao, registro.status);
        if (avisoDeepLink) uiToast("info", "Agendamento", avisoDeepLink);
        return true;
      } finally {
        abrindoAgendamento = false;
      }
    }

    // Usado pela central de notificações quando a Agenda já está aberta.
    window.AgendaNotificacao = Object.freeze({ abrirAgendamento: abrirAgendamentoPorId });

    // Após uma exclusão, consulta novamente a API e atualiza somente a lista.
    // A página, a semana ativa, a pesquisa e o filtro permanecem como estão.
    document.addEventListener("agenda:agendamento:excluido", async () => {
      try {
        dadosSemana = await obterDados();
        renderizarTodasAbas(dadosSemana);
      } catch (erro) {
        console.error("[lista-agenda:atualizar-apos-exclusao]", erro);
        uiToast("danger", "Agenda", erro.message || "O agendamento foi excluído, mas não foi possível atualizar a lista.");
      }
    });

    document.addEventListener("agenda:agendamento:confirmacao-estado", (evento) => {
      const id = String(evento.detail?.id_agendamento || "");
      const carregando = evento.detail?.carregando === true;
      document.querySelectorAll(`button[data-acao="confirmar"][data-id-agendamento="${CSS.escape(id)}"]`).forEach((botao) => {
        botao.disabled = carregando;
        const rotulo = botao.querySelector("span");
        if (rotulo) rotulo.textContent = carregando
          ? "Confirmando..."
          : (botao.id === "vc_btn_confirmar" ? "Confirmar agendamento" : "Confirmar");
      });
    });

    // Bloqueia todos os itens de ação rápida do agendamento enquanto uma ação está em andamento.
    document.addEventListener("agenda:agendamento:acao-rapida-estado", (evento) => {
      const id = String(evento.detail?.id_agendamento || "");
      const carregando = evento.detail?.carregando === true;
      document.querySelectorAll(`button[data-acao-rapida][data-id-agendamento="${CSS.escape(id)}"]`).forEach((botao) => {
        botao.disabled = carregando;
      });
    });

    // Concluir, Registrar falta e Cancelar: atualização localizada após o sucesso do backend.
    // A confirmação continua sendo tratada pelo evento próprio abaixo.
    document.addEventListener("agenda:agendamento:status-atualizado", (evento) => {
      const id = String(evento.detail?.id_agendamento || "");
      const status = normalizarStatus(evento.detail?.status);
      if (!id || status === "confirmado" || !["concluido", "faltou", "cancelado"].includes(status)) return;
      let registroAtualizado = null;
      Object.values(dadosSemana || {}).forEach((lista) => {
        if (!Array.isArray(lista)) return;
        const registro = lista.find((item) => String(item.id_agendamento ?? item.id) === id);
        if (registro) { registro.status = status; registroAtualizado = registro; }
      });
      renderizarTodasAbas(dadosSemana);
      const modal = document.getElementById("modalVisualizarAgendamento");
      if (registroAtualizado && modal?.classList.contains("ativo") && modal.dataset.idAgendamento === id) {
        preencherModalVisualizar(registroAtualizado);
      }
    });

    document.addEventListener("agenda:agendamento:confirmado", (evento) => {
      // Atualização localizada (sem recarregar): somente o status muda no registro em memória.
      const id = String(evento.detail?.id_agendamento || "");
      let registroAtualizado = null;
      Object.values(dadosSemana || {}).forEach((lista) => {
        if (!Array.isArray(lista)) return;
        const registro = lista.find((item) => String(item.id_agendamento ?? item.id) === id);
        if (registro) { registro.status = "confirmado"; registroAtualizado = registro; }
      });
      renderizarTodasAbas(dadosSemana);
      const modal = document.getElementById("modalVisualizarAgendamento");
      if (modal?.classList.contains("ativo") && modal.dataset.idAgendamento === id) {
        if (registroAtualizado) {
          preencherModalVisualizar(registroAtualizado);
        } else {
          const chip = document.getElementById("vc_chip_status");
          if (chip) { chip.textContent = "Confirmado"; chip.dataset.status = "confirmado"; }
          modal.dataset.status = "confirmado";
          atualizarAcoesConfirmar();
        }
      }
    });

    setTimeout(pesquisa_aplicar, 0);
    setInterval(atualizarEstadosTemporais, 60000);

    // Deep link: agenda.html?acao=abrir_agendamento&id_agendamento=ID.
    // Os parâmetros são removidos antes de processar, para que recarregar a
    // página não reabra o modal.
    const urlAtual = new URL(window.location.href);
    if (urlAtual.searchParams.get("acao") === "abrir_agendamento") {
      const idDeepLink = urlAtual.searchParams.get("id_agendamento");
      const codigoNotificacaoDeepLink = urlAtual.searchParams.get("notificacao");
      // Chat: preserva a conversa de origem para o retorno após consultar/editar.
      const origemDeepLink = urlAtual.searchParams.get("origem");
      const idConversaOrigem = urlAtual.searchParams.get("id_conversa");
      urlAtual.searchParams.delete("acao");
      urlAtual.searchParams.delete("id_agendamento");
      urlAtual.searchParams.delete("notificacao");
      urlAtual.searchParams.delete("origem");
      urlAtual.searchParams.delete("id_conversa");
      window.history.replaceState(window.history.state, "", `${urlAtual.pathname}${urlAtual.search}${urlAtual.hash}`);
      if (/^\d{1,10}$/.test(String(idDeepLink || ""))) {
        const retornoChat = prepararRetornoChat(origemDeepLink, idConversaOrigem);
        abrirAgendamentoPorId(idDeepLink, { codigoNotificacao: codigoNotificacaoDeepLink }).then((aberto) => {
          if (aberto && retornoChat) monitorarFechamentoParaChat(retornoChat);
          else if (retornoChat) window.AgendaRetornoChat = Object.freeze({ destino: () => null });
        });
      }
    }
  }

  document.addEventListener("DOMContentLoaded", init);

  // Cadastro e edição controlam o momento da atualização após exibirem o retorno ao usuário.
})();

/* ==========================================================
   LINK PÚBLICO DA AGENDA ONLINE
   O backend fornece somente o slug da empresa validada na sessão.
========================================================== */
(() => {
  "use strict";

  const PERFIS_COM_LINK = new Set(["proprietario", "recepcionista", "profissional"]);

  function perfilNormalizado(auth) {
    return String(auth?.perfil_nome || auth?.perfil || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim()
      .toLowerCase();
  }

  function podeExibir(auth) {
    if (auth?.agenda_online_disponivel !== true) return false;

    const perfil = perfilNormalizado(auth);
    const modoSuporte = String(auth?.tipo_usuario || "").toLowerCase() === "super_admin"
      && auth?.modo_suporte === true;

    return PERFIS_COM_LINK.has(perfil) || modoSuporte;
  }

  function urlAgendaOnline(auth) {
    const slug = String(auth?.empresa_slug || "").trim();
    const path = String(auth?.agenda_online_path || "").trim();
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug) || path !== `/agendar/${slug}`) {
      return "";
    }

    return new URL(path, window.location.origin).href;
  }

  async function copiarEndereco(campo, botao) {
    const url = campo.value;
    if (!url) return;

    botao.disabled = true;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(url);
      } else {
        campo.focus();
        campo.select();
        if (!document.execCommand("copy")) {
          throw new Error("Cópia não suportada.");
        }
      }

      window.MensagemSistema?.sucesso("Link da Agenda Online copiado.", {
        titulo: "Link copiado"
      });
    } catch (_erro) {
      window.MensagemSistema?.erro("Não foi possível copiar o link. Selecione o endereço e copie manualmente.");
      campo.focus();
      campo.select();
    } finally {
      botao.disabled = false;
    }
  }

  // O navegador não permite abrir janela anônima ou outro navegador: apenas orienta
  // e oferece a cópia do mesmo link. A sessão atual da empresa não é tocada.
  async function orientarTesteCliente(campo, botao) {
    if (!campo.value || !window.MensagemSistema?.confirmar) return;
    const copiarLink = await window.MensagemSistema.confirmar(
      "Para testar a Agenda Online como cliente, abra este link em uma janela anônima ou em outro navegador.",
      { titulo: "Testar como cliente", textoConfirmar: "Copiar link", textoCancelar: "Fechar" }
    );
    if (copiarLink) await copiarEndereco(campo, botao);
  }

  function configurarLink(auth) {
    const bloco = document.getElementById("agendaOnlineCompartilhar");
    const campo = document.getElementById("agendaOnlineUrl");
    const copiar = document.getElementById("agendaOnlineCopiar");
    const testar = document.getElementById("agendaOnlineTestar");
    if (!bloco || !campo || !copiar || !testar) return;

    const url = podeExibir(auth) ? urlAgendaOnline(auth) : "";
    if (!url) {
      bloco.hidden = true;
      campo.value = "";
      bloco.dataset.configurado = "0";
      return;
    }

    campo.value = url;
    bloco.hidden = false;
    bloco.dataset.configurado = "1";
    if (copiar.dataset.listenerConfigurado !== "1") {
      copiar.addEventListener("click", () => copiarEndereco(campo, copiar));
      copiar.dataset.listenerConfigurado = "1";
    }
    if (testar.dataset.listenerConfigurado !== "1") {
      testar.addEventListener("click", () => orientarTesteCliente(campo, testar));
      testar.dataset.listenerConfigurado = "1";
    }
  }

  document.addEventListener("amagenda:sessao-carregada", (evento) => {
    configurarLink(evento.detail);
  });

  document.addEventListener("DOMContentLoaded", () => {
    configurarLink(window.__AUTH__);
  });
})();
