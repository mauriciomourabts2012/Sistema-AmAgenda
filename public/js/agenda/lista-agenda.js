/* ==========================================================
   ListaAgenda.js — ENXUTO (usa window.ListaCore)
   - Renderiza MOCK (DEMO) por dia ✅
   - ✅ Filtro por período por ABA (IDs únicos por dia)
   - ✅ Filtro por STATUS no mesmo popover ✅ (pendente/confirmado/concluido/cancelado)
   - ✅ Paginação por DIA (PADRÃO MANUTENÇÃO: btn-pag, Anterior/Próximo, sem contador)
   - Futuro: troca para PHP via CFG.MOCK=false
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
    MOCK: true,
    ENDPOINT: "/backend/Agenda/ListaAgendaSemana.php",
    ROOT_SELECTOR_MENU: ".conteudo-agenda",
    itensPorPagina: 4, // ✅ igual manutenção
  };

  // =========================
  // MAPA: seus IDs por dia
  // =========================
  const DIAS = [
    { dia: "segunda", box: "cardsAgendaSegunda", boxCard: "cardsAgendaSegunda", pag: "paginacao_segunda", searchId: "pesquisar-agenda",  filtro: "segunda" },
    { dia: "terca",   box: "cardsAgendaTerca",   boxCard: "cardsAgendaTerca",   pag: "paginacao_terca",   searchId: "pesquisar-terca",   filtro: "terca" },
    { dia: "quarta",  box: "cardsAgendaQuarta",  boxCard: "cardsAgendaQuarta",  pag: "paginacao_quarta",  searchId: "pesquisar-quarta",  filtro: "quarta" },
    { dia: "quinta",  box: "cardsAgendaQuinta",  boxCard: "cardsAgendaQuinta",  pag: "paginacao_quinta",  searchId: "pesquisar-quinta",  filtro: "quinta" },
    { dia: "sexta",   box: "cardsAgendaSexta",   boxCard: "cardsAgendaSexta",   pag: "paginacao_sexta",   searchId: "pesquisar-sexta",   filtro: "sexta" },
    { dia: "sabado",  box: "cardsAgendaSabado",  boxCard: "cardsAgendaSabado",  pag: "paginacao_sabado",  searchId: "pesquisar-sabado",  filtro: "sabado" },
    { dia: "domingo", box: "cardsAgendaDomingo", boxCard: "cardsAgendaDomingo", pag: "paginacao_domingo", searchId: "pesquisar-domingo", filtro: "domingo" },
  ];

  // =========================
  // MOCK (com data ISO p/ filtro)
  // ✅ adicionado: profissional (para identificar no card)
  // =========================
  const MOCK = {
    segunda: [
      { id: 101, data: "2026-02-10", hora: "08:00", cliente: "Ana Paula", profissional: "Bia", servico: "Unhas em gel", duracao: "1h30", telefone: "(38) 99822-7737", status: "Confirmado", obs: "Preferência: alongamento curto", pagamento_confirmado: true },
      { id: 102, data: "2026-02-10", hora: "10:30", cliente: "Bruna", profissional: "Bia", servico: "Manicure + esmaltação", duracao: "1h00", telefone: "(38) 99822-7737", status: "Pendente", obs: "", pagamento_confirmado: false },
      { id: 103, data: "2026-02-10", hora: "14:00", cliente: "Carla", profissional: "Bia", servico: "Pedicure", duracao: "45min", telefone: "", status: "Concluído", obs: "Chegar 10 min antes", pagamento_confirmado: true },
      { id: 104, data: "2026-02-10", hora: "15:00", cliente: "Daniela", profissional: "Cris", servico: "Design de sobrancelha", duracao: "40min", telefone: "(38) 99711-2233", status: "Confirmado", obs: "", pagamento_confirmado: true },
      { id: 105, data: "2026-02-10", hora: "16:00", cliente: "Elaine", profissional: "Maria", servico: "Escova", duracao: "1h10", telefone: "", status: "Pendente", obs: "Cabelo longo", pagamento_confirmado: false },
      { id: 106, data: "2026-02-10", hora: "17:00", cliente: "Marina", profissional: "João", servico: "Corte", duracao: "45min", telefone: "(38) 99700-1111", status: "Cancelado", obs: "", pagamento_confirmado: false },
    ],
    terca: [
      { id: 201, data: "2026-02-11", hora: "09:00", cliente: "Daniela", profissional: "Cris", servico: "Design de sobrancelha", duracao: "40min", telefone: "(38) 99822-7737", status: "Confirmado", obs: "", pagamento_confirmado: true },
      { id: 202, data: "2026-02-11", hora: "16:30", cliente: "Elaine", profissional: "Maria", servico: "Escova", duracao: "1h10", telefone: "(34) 9xxxx-4444", status: "Pendente", obs: "Cabelo longo", pagamento_confirmado: false },
    ],
    quarta: [
      { id: 301, data: "2026-02-12", hora: "11:00", cliente: "Fernanda", profissional: "Ana", servico: "Hidratação", duracao: "1h00", telefone: "", status: "Confirmado", obs: "", pagamento_confirmado: true },
    ],
    quinta: [
      { id: 401, data: "2026-02-13", hora: "13:00", cliente: "Giovana", profissional: "Maria", servico: "Corte + escova", duracao: "1h20", telefone: "(38) 99822-7737", status: "Confirmado", obs: "", pagamento_confirmado: true },
      { id: 402, data: "2026-02-13", hora: "18:00", cliente: "Helena", profissional: "Bia", servico: "Manicure", duracao: "50min", telefone: "", status: "Pendente", obs: "", pagamento_confirmado: false },
      { id: 403, data: "2026-02-13", hora: "19:00", cliente: "Paula", profissional: "Bia", servico: "Pedicure", duracao: "45min", telefone: "(38) 99111-2222", status: "Concluído", obs: "", pagamento_confirmado: true },
    ],
    sexta: [
      { id: 501, data: "2026-02-14", hora: "08:30", cliente: "Isabela", profissional: "Lu", servico: "Alongamento", duracao: "2h00", telefone: "", status: "Confirmado", obs: "Gel nude", pagamento_confirmado: true },
      { id: 502, data: "2026-02-14", hora: "15:00", cliente: "Juliana", profissional: "Lu", servico: "Pedicure + spa", duracao: "1h30", telefone: "(38) 99822-7737", status: "Pendente", obs: "", pagamento_confirmado: false },
    ],
    sabado: [
      { id: 601, data: "2026-02-15", hora: "10:00", cliente: "Fernanda", profissional: "Maria", servico: "Corte + Escova", duracao: "1h30", telefone: "(38) 99822-7737", status: "Confirmado", obs: "", pagamento_confirmado: true },
      { id: 602, data: "2026-02-15", hora: "10:30", cliente: "Larissa", profissional: "Maria", servico: "Escova", duracao: "1h00", telefone: "", status: "Pendente", obs: "", pagamento_confirmado: false },
    ],
    domingo: []
  };

  // =========================
  // ESTADOS
  // =========================
  const FILTRO = {};       // { dia:{inicio,fim,status} }
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
    return `<span class="agenda-status ${cls}">${C.escapeHtml(status || "Pendente")}</span>`;
  }

  function textoPagamento(ok) {
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
    const pago = !!item.pagamento_confirmado;
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

  function cardTemplate(item) {
    const id = item.id ?? "";
    const hora = item.hora ?? "--:--";

    const cliente = resolveCliente(item) || "Cliente";
    const servico = resolveServico(item) || "Serviço";
    const profissional = resolveProfissional(item) || "";

    const duracao = item.duracao ?? "";
    const obs = item.obs ?? "";
    const pago = !!item.pagamento_confirmado;

    const telRaw = String(item.telefone || "");
    const temTel = onlyDigits(telRaw).length >= 10;

    return `
      <article class="agenda-card" data-id="${C.escapeHtml(id)}" data-pago="${pago ? "1" : "0"}">
        <div class="agenda-hora">${C.escapeHtml(hora)}</div>

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
            ${badgeStatus(item.status)}
            ${textoPagamento(pago)}
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

  async function obterDados() {
    if (CFG.MOCK) return MOCK;
    const json = await C.fetchJSON(CFG.ENDPOINT);
    return json?.data ?? json;
  }

  // =========================
  // Filtro período
  // =========================
  function inRange(dataISO, iniISO, fimISO) {
    if (!dataISO || !iniISO || !fimISO) return true;
    return dataISO >= iniISO && dataISO <= fimISO;
  }

  // ✅ aplica período + status
  function aplicarFiltroLista(dia, lista) {
    const f = FILTRO[dia] || {};
    return lista.filter(item => {
      const okPeriodo = (!f.inicio || !f.fim)
        ? true
        : inRange(String(item.data || ""), f.inicio, f.fim);

      const okStatus = statusBate(item.status, f.status);

      return okPeriodo && okStatus;
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

    // filtro (período + status)
    lista = aplicarFiltroLista(dia, lista);

    // paginação (padrão manutenção)
    const info = paginarLista(dia, lista);

    // vazio
    if (info.total === 0) {
      const f = FILTRO[dia] || {};
      const temPeriodo = !!(f.inicio && f.fim);
      const temStatus = !!normalizarStatus(f.status);

      const detalhe = [
        temPeriodo ? "no filtro selecionado" : "",
        temStatus ? `com status "${normalizarStatus(f.status)}"` : "",
      ].filter(Boolean).join(" ");

      const msg = detalhe
        ? `Nenhum agendamento ${detalhe}.`
        : `Nenhum agendamento para ${dia}.`;

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

    // render paginação (Anterior/Próximo)
    const renderTudoDia = () => renderDia(dia, boxId, pagId, dadosSemana);
    renderPaginacao(dia, info, pagDiv, renderTudoDia);
  }

  // =========================
  // Aba ativa / box ativa (pesquisa)
  // =========================
  function diaAtivo() {
    const aba = document.querySelector(".conteudo-aba.ativa");
    return aba?.id || "segunda";
  }

  function boxAtiva() {
    const d = diaAtivo();
    const it = DIAS.find(x => x.dia === d);
    return it ? document.getElementById(it.box) : null;
  }

  // =========================
  // Menu flutuante via Core ✅
  // =========================
  const menuCtrl = C.createFloatingMenuController({ rootSelector: CFG.ROOT_SELECTOR_MENU });

  // =========================
  // Pesquisa (aba ativa)
  // =========================
  function getPesquisaAbaAtiva() {
    const aba = document.querySelector(".conteudo-aba.ativa");
    if (!aba) return { input: null, limpar: null, info: null };

    const input =
      aba.querySelector(".pesquisar input") ||
      aba.querySelector(".pesquisar-super input") ||
      aba.querySelector('input[type="search"]');

    const limpar = aba.querySelector(".btn-limpar-pesquisa");
    const info = aba.querySelector(".pesquisa-info");
    return { input, limpar, info };
  }

  function pesquisa_aplicar() {
    const { input, limpar, info } = getPesquisaAbaAtiva();
    const box = boxAtiva();
    if (!input || !box) return;

    const termo = C.normalizar(input.value.trim());
    if (limpar) limpar.style.display = termo ? "inline-flex" : "none";

    const cards = box.querySelectorAll(".agenda-card");
    let vis = 0;

    cards.forEach(card => {
      const ok = !termo || C.normalizar(card.innerText).includes(termo);
      card.style.display = ok ? "" : "none";
      if (ok) vis++;
    });

    if (info) info.textContent = !termo ? "" : (vis ? `${vis} encontrado(s).` : "Nenhum agendamento encontrado.");
  }

  const pesquisa_deb = C.debounce(pesquisa_aplicar, 80);

  // =========================
  // ✅ Filtro por ABAS (IDs únicos)
  // =========================
  function idsFiltro(sufixo) {
    return {
      btn:     `btnPeriodo_${sufixo}`,
      pop:     `popoverPeriodo_${sufixo}`,
      form:    `formPeriodo_${sufixo}`,
      ini:     `inicio_${sufixo}`,
      fim:     `fim_${sufixo}`,
      status:  `status_${sufixo}`,
      label:   `labelPeriodo_${sufixo}`,
      limpar:  `limparFiltro_${sufixo}`,
      fechar:  `fecharPopover_${sufixo}`,
    };
  }

  function fecharTodosPopovers() {
    DIAS.forEach(({ filtro }) => {
      const { btn, pop } = idsFiltro(filtro);
      const b = document.getElementById(btn);
      const p = document.getElementById(pop);
      if (!p || !b) return;
      p.setAttribute("hidden", "");
      b.setAttribute("aria-expanded", "false");
    });
  }

  // ✅ label mostra filtro + status (se tiver)
  function setLabelPeriodo(labelEl, iniISO, fimISO, statusVal) {
    if (!labelEl) return;

    const temPeriodo = !!iniISO && !!fimISO;
    const st = normalizarStatus(statusVal);

    if (!temPeriodo && !st) {
      labelEl.textContent = "Filtro";
      return;
    }

    const br = (iso) => String(iso).split("-").reverse().join("/");

    const partes = [];
    if (temPeriodo) partes.push(`${br(iniISO)} - ${br(fimISO)}`);
    if (st) partes.push(st.charAt(0).toUpperCase() + st.slice(1));

    labelEl.textContent = partes.join(" • ");
  }

  function bindFiltroPorDia({ dia, box, pag, filtro }, getDadosSemanaRef) {
    const ids = idsFiltro(filtro);

    const btn    = document.getElementById(ids.btn);
    const pop    = document.getElementById(ids.pop);
    const form   = document.getElementById(ids.form);
    const ini    = document.getElementById(ids.ini);
    const fim    = document.getElementById(ids.fim);
    const status = document.getElementById(ids.status);
    const label  = document.getElementById(ids.label);
    const limpar = document.getElementById(ids.limpar);
    const fechar = document.getElementById(ids.fechar);

    if (!btn || !pop || !form || !ini || !fim || !status || !label || !limpar || !fechar) return;

    // restaura estado se já tiver
    const f = FILTRO[dia] || {};
    if (f.inicio) ini.value = f.inicio;
    if (f.fim) fim.value = f.fim;
    if (typeof f.status !== "undefined") status.value = f.status || "";
    setLabelPeriodo(label, f.inicio, f.fim, f.status);

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

      ini.value = "";
      fim.value = "";
      status.value = "";
      delete FILTRO[dia];

      PAGINA_ATUAL[dia] = 1;

      setLabelPeriodo(label, "", "", "");
      pop.setAttribute("hidden", "");
      btn.setAttribute("aria-expanded", "false");

      const dados = getDadosSemanaRef();
      renderDia(dia, box, pag, dados);
      setTimeout(pesquisa_aplicar, 0);
    });

    form.addEventListener("submit", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      // ✅ período é opcional (pode filtrar só por status)
      if ((ini.value && !fim.value) || (!ini.value && fim.value)) {
        uiToast("warning", "Filtro", "Selecione início e fim (ou deixe os dois vazios).");
        return;
      }
      if (ini.value && fim.value && ini.value > fim.value) {
        uiToast("danger", "Filtro", "Início não pode ser maior que o fim.");
        return;
      }

      FILTRO[dia] = {
        inicio: ini.value || "",
        fim: fim.value || "",
        status: status.value || "",
      };

      PAGINA_ATUAL[dia] = 1;

      setLabelPeriodo(label, ini.value || "", fim.value || "", status.value || "");

      pop.setAttribute("hidden", "");
      btn.setAttribute("aria-expanded", "false");

      const dados = getDadosSemanaRef();
      renderDia(dia, box, pag, dados);
      setTimeout(pesquisa_aplicar, 0);
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
      const btnLimpar = ev.target.closest(".conteudo-aba .btn-limpar-pesquisa");
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
        console.log("AÇÃO:", acao, "ID:", id, "PAGO:", pago, "MODAL:", modal);
        return;
      }

      if (btnLimpar) {
        const { input } = getPesquisaAbaAtiva();
        if (input) {
          input.value = "";
          input.focus();
          pesquisa_aplicar();
        }
        return;
      }

      if (btnAba) {
        menuCtrl.fechar();
        fecharTodosPopovers();
        setTimeout(pesquisa_aplicar, 0);
        return;
      }

      menuCtrl.fechar();
    });

    document.addEventListener("input", (ev) => {
      const el = ev.target;
      if (!el.matches(".conteudo-aba input[type='search'], .conteudo-aba .pesquisar input, .conteudo-aba .pesquisar-super input")) return;
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
    let dadosSemana = await obterDados();
    const getDadosSemanaRef = () => dadosSemana;

    // render inicial
    DIAS.forEach(({ dia, box, pag }) => {
      if (!PAGINA_ATUAL[dia]) PAGINA_ATUAL[dia] = 1;
      renderDia(dia, box, pag, dadosSemana);
    });

    // bind filtros por dia
    DIAS.forEach((it) => bindFiltroPorDia(it, getDadosSemanaRef));

    // eventos globais
    bindEventosGlobais(getDadosSemanaRef);

    setTimeout(pesquisa_aplicar, 0);
  }

  document.addEventListener("DOMContentLoaded", init);
})();