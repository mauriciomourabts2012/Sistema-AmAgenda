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

  function buildMenuAcoes(item) {
    const pago = item.pagamento_confirmado === true;
    return `
      <div class="agenda-menu" role="menu">
        <button class="agenda-menu-item" type="button"
          data-acao="visualizar" data-abrir-modal="modalVisualizarAgendamento">
          <i class="fa-regular fa-eye"></i> Visualizar
        </button>

        ${!pago ? `
          <button class="agenda-menu-item" type="button"
            data-acao="editar" data-abrir-modal="modalEditarAgendamento">
            <i class="fa-regular fa-pen-to-square"></i> Editar
          </button>

          <button class="agenda-menu-item danger" type="button" data-acao="excluir">
            <i class="fa-regular fa-trash-can"></i> Excluir
          </button>
        ` : ""}
      </div>
    `;
  }

  function abrirWhatsapp({ telefone, cliente, servico, hora }) {
    let tel = onlyDigits(telefone);
    if (!tel || tel.length < 10) {
      uiToast("warning", "WhatsApp", "Telefone inválido ou não informado para este cliente.");
      return;
    }
    if (!tel.startsWith("55")) tel = "55" + tel;

    const msg = encodeURIComponent(
      `Olá ${cliente || ""}! Tudo bem?\n` +
      `Sobre seu agendamento:\n` +
      `• Serviço: ${servico || "-"}\n` +
      `• Horário: ${hora || "-"}\n\n` +
      `Posso te ajudar em algo?`
    );

    window.open(`https://wa.me/${tel}?text=${msg}`, "_blank");
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
    const temTel = onlyDigits(telRaw).length >= 10;

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
            data-hora="${C.escapeHtml(hora)}"
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
    if (chip) { const status = normalizarStatus(item.status); chip.textContent = ({pendente:"Pendente",confirmado:"Confirmado",concluido:"Concluído",cancelado:"Cancelado",faltou:"Faltou"})[status] || item.status; chip.dataset.status = status; }
    const whats = document.getElementById("vc_btn_whats");
    if (whats) { whats.disabled = onlyDigits(telefone).length < 10; whats.onclick = () => abrirWhatsapp({ telefone, cliente, servico: resolveServico(item), hora: item.hora_inicio || item.hora }); }
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
        });
        return;
      }

      if (btnToggle) {
        ev.stopPropagation();
        menuCtrl.toggle(btnToggle);
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
    document.addEventListener("agenda:localizar-agendamento", async (evento) => {
      const id = String(evento.detail?.id_agendamento || "");
      const data = String(evento.detail?.data_agendamento || "");
      if (!id || !data) return;

      PESQUISA_GLOBAL = "";
      FILTRO_GLOBAL.status = "";
      sincronizarPesquisas();
      sincronizarControlesFiltro();

      const mapa = ["domingo", "segunda", "terca", "quarta", "quinta", "sexta", "sabado"];
      const dataLocal = new Date(`${data}T12:00:00`);
      const dia = mapa[dataLocal.getDay()];
      const config = DIAS.find((item) => item.dia === dia);
      if (!config) return;

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
        return;
      }

      renderizarTodasAbas(dadosSemana);

      const itensDaData = (dadosSemana?.[dia] || [])
        .filter((item) => String(item.data_agendamento || item.data || "") === data)
        .sort((a, b) => String(a.hora_inicio || a.hora || "").localeCompare(String(b.hora_inicio || b.hora || "")));
      const indice = itensDaData.findIndex((item) => String(item.id_agendamento ?? item.id) === id);
      PAGINA_ATUAL[dia] = indice >= 0 ? Math.floor(indice / CFG.itensPorPagina) + 1 : 1;
      renderDia(dia, config.box, config.pag, dadosSemana);

      requestAnimationFrame(() => {
        const card = document.querySelector(`#${config.box} .agenda-card[data-id="${CSS.escape(id)}"]`);
        if (!card) return;
        card.classList.add("agenda-card-localizado");
        card.scrollIntoView({ behavior: "smooth", block: "center" });
        setTimeout(() => card.classList.remove("agenda-card-localizado"), 3500);
      });
    });

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

    setTimeout(pesquisa_aplicar, 0);
    setInterval(atualizarEstadosTemporais, 60000);
  }

  document.addEventListener("DOMContentLoaded", init);

  // Cadastro e edição controlam o momento da atualização após exibirem o retorno ao usuário.
})();
