/* ==========================================================
   MENU LATERAL UNIVERSAL
   ----------------------------------------------------------
   Desktop (> 960px): abre temporariamente ao receber hover ou
   foco e volta ao estado recolhido quando a interação termina.

   Mobile/tablet (<= 960px): funciona como drawer, com botão,
   overlay, fechamento por item selecionado e tecla Escape.
   ========================================================== */
(() => {
  "use strict";

  const BREAKPOINT_DESKTOP = "(min-width: 961px)";
  const ATRASO_ABRIR_MS = 110;
  const ATRASO_FECHAR_MS = 230;

  const MENUS = {
    agenda: [
      { tipo: "link", texto: "Painel Admin", icone: "fa-solid fa-user-gear", href: "/public/views/painel-administrativo/painel-administrativo.html", titulo: "Painel do Administrador" },
      { tipo: "button", texto: "Novo Cliente", icone: "fa-solid fa-user-plus", modal: "modalNovoCliente", titulo: "Cadastrar novo cliente" },
      { tipo: "button", texto: "Novo Agendamento", icone: "fa-solid fa-plus", modal: "modalNovoAgendamento", titulo: "Adicionar um agendamento" },
      { tipo: "button", texto: "Configurações Agenda", icone: "fa-solid fa-gear", modal: "modalConfiguracoesAgenda", titulo: "Configurações da agenda" }
    ],
    "painel-administrativo": [
      { tipo: "aba", aba: "resumo", texto: "Resumo do dia", icone: "fa-solid fa-chart-line" },
      { tipo: "link", texto: "Agenda", icone: "fa-solid fa-calendar-days", href: "/public/views/agenda.html", titulo: "Abrir Agenda" },
      { tipo: "aba", aba: "clientes", texto: "Clientes", icone: "fa-solid fa-users" },
      { tipo: "aba", aba: "usuarios", texto: "Usuários", icone: "fa-solid fa-user-gear" },
      { tipo: "button", texto: "Configurações da Empresa", icone: "fa-solid fa-gear", modal: "modalConfiguracoesAgenda" }
    ],
    "super-admin": [
      { tipo: "aba", aba: "empresas", texto: "Empresas", icone: "fa-solid fa-building" },
      { tipo: "aba", aba: "usuarios", texto: "Usuários", icone: "fa-solid fa-users" },
      { tipo: "aba", aba: "usuarios-super", texto: "Usuário Super", icone: "fa-solid fa-user-shield" },
      { tipo: "aba", aba: "planos", texto: "Planos", icone: "fa-solid fa-layer-group" }
    ]
  };

  const CONTEXTOS_ABAS = {
    "painel-administrativo": {
      storage: "aba_proprietario_ativa",
      padrao: "resumo",
      titulos: {
        resumo: ["Resumo do dia", "Acompanhe as principais informações de hoje"],
        clientes: ["Clientes", "Gerencie os clientes da sua empresa"],
        usuarios: ["Usuários", "Gerencie os usuários da sua empresa"]
      }
    },
    "super-admin": {
      storage: "aba_super_admin_ativa",
      padrao: "empresas",
      titulos: {
        empresas: ["Empresas", "Gerencie as empresas da plataforma"],
        usuarios: ["Usuários", "Gerencie os usuários vinculados às empresas"],
        "usuarios-super": ["Usuário Super", "Gerencie os administradores globais"],
        planos: ["Planos", "Gerencie os planos da plataforma"]
      }
    }
  };

  function escaparHtml(valor) {
    return String(valor).replace(/[&<>'"]/g, caractere => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" })[caractere]);
  }

  function htmlItem(item) {
    const atributos = [
      `class="sidebar-item${item.aba ? "" : ""}"`,
      `title="${escaparHtml(item.titulo || item.texto)}"`,
      `aria-label="${escaparHtml(item.titulo || item.texto)}"`
    ];
    if (item.modal) atributos.push(`data-abrir-modal="${escaparHtml(item.modal)}"`);
    if (item.aba) atributos.push(`data-menu-aba="${escaparHtml(item.aba)}"`);
    const conteudo = `<i class="${escaparHtml(item.icone)}" aria-hidden="true"></i><span class="sidebar-texto">${escaparHtml(item.texto)}</span>`;
    return item.tipo === "link"
      ? `<a href="${escaparHtml(item.href)}" ${atributos.join(" ")}>${conteudo}</a>`
      : `<button type="button" ${atributos.join(" ")}>${conteudo}</button>`;
  }

  function renderizarMenu(contexto) {
    const navegacao = document.querySelector("[data-menu-navegacao]");
    let itens = MENUS[contexto] || [];
    if (contexto === "agenda") {
      const auth = window.__AUTH__ || {};
      const perfil = String(auth.perfil_nome || auth.perfil || "")
        .normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
      const superAdminSuporte = String(auth.tipo_usuario || "").toLowerCase() === "super_admin"
        && auth.modo_suporte === true
        && Number(auth.empresa_id || auth.id_empresa || 0) > 0;
      const podeAcessarPainelAdmin = perfil === "proprietario" || superAdminSuporte;
      const podeConfigurarAgenda = perfil === "proprietario" || perfil === "profissional" || superAdminSuporte;
      itens = itens.filter(item => {
        if (item.texto === "Painel Admin") return podeAcessarPainelAdmin;
        if (item.modal === "modalConfiguracoesAgenda") return podeConfigurarAgenda;
        return true;
      });
    }
    if (navegacao && itens.length) navegacao.innerHTML = itens.map(htmlItem).join("");
  }

  function ativarAbaContexto(contexto, idAba, salvar = true) {
    const configuracao = CONTEXTOS_ABAS[contexto];
    const conteudo = document.getElementById(idAba);
    if (!configuracao || !conteudo || !configuracao.titulos[idAba]) return;

    document.querySelectorAll(".conteudo-aba").forEach(aba => aba.classList.toggle("ativa", aba.id === idAba));
    document.querySelectorAll("[data-menu-aba]").forEach(item => {
      const ativo = item.dataset.menuAba === idAba;
      item.classList.toggle("ativo", ativo);
      if (ativo) item.setAttribute("aria-current", "page");
      else item.removeAttribute("aria-current");
    });

    const [titulo, subtitulo] = configuracao.titulos[idAba];
    const elTitulo = document.getElementById("cabecalhoModuloTitulo");
    const elSubtitulo = document.getElementById("cabecalhoModuloSubtitulo");
    if (elTitulo) elTitulo.textContent = titulo;
    if (elSubtitulo) elSubtitulo.textContent = subtitulo;
    if (salvar) localStorage.setItem(configuracao.storage, idAba);
    document.dispatchEvent(new CustomEvent("amagenda:menu-aba-alterada", { detail: { contexto, aba: idAba } }));
    if (contexto === "painel-administrativo") {
      document.dispatchEvent(new CustomEvent("amagenda:painel-aba-alterada", { detail: { aba: idAba } }));
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const contexto = document.body.dataset.menuContexto || "agenda";
    renderizarMenu(contexto);
    document.addEventListener("amagenda:sessao-carregada", () => renderizarMenu(contexto));

    const sidebar = document.getElementById("sidebarAgenda");
    const abrir = document.getElementById("abrirSidebarAgenda");
    const fechar = document.getElementById("fecharSidebarAgenda");
    const overlay = document.getElementById("overlaySidebarAgenda");
    const mediaDesktop = window.matchMedia(BREAKPOINT_DESKTOP);

    if (!sidebar || !abrir || !fechar || !overlay) return;

    let temporizadorAbrir = 0;
    let temporizadorFechar = 0;

    function limparTemporizadores() {
      window.clearTimeout(temporizadorAbrir);
      window.clearTimeout(temporizadorFechar);
    }

    function definirAberta(aberta) {
      document.body.classList.toggle("sidebar-agenda-aberta", aberta);
      sidebar.setAttribute("aria-hidden", String(!aberta && !mediaDesktop.matches));
      abrir.setAttribute("aria-expanded", String(aberta));
    }

    function abrirDesktopComAtraso() {
      if (!mediaDesktop.matches) return;
      window.clearTimeout(temporizadorFechar);
      temporizadorAbrir = window.setTimeout(() => definirAberta(true), ATRASO_ABRIR_MS);
    }

    function fecharDesktopComAtraso() {
      if (!mediaDesktop.matches) return;
      window.clearTimeout(temporizadorAbrir);
      temporizadorFechar = window.setTimeout(() => {
        if (!sidebar.matches(":hover") && !sidebar.contains(document.activeElement)) {
          definirAberta(false);
        }
      }, ATRASO_FECHAR_MS);
    }

    function abrirDrawer() {
      if (mediaDesktop.matches) return;
      definirAberta(true);
      fechar.focus({ preventScroll: true });
    }

    function fecharDrawer(devolverFoco = false) {
      if (mediaDesktop.matches) return;
      const estavaAberta = document.body.classList.contains("sidebar-agenda-aberta");
      definirAberta(false);
      if (devolverFoco && estavaAberta) abrir.focus({ preventScroll: true });
    }

    function sincronizarBreakpoint() {
      limparTemporizadores();
      definirAberta(false);
      sidebar.setAttribute("aria-hidden", String(!mediaDesktop.matches));
    }

    sidebar.addEventListener("mouseenter", abrirDesktopComAtraso);
    sidebar.addEventListener("mouseleave", fecharDesktopComAtraso);
    sidebar.addEventListener("focusin", abrirDesktopComAtraso);
    sidebar.addEventListener("focusout", fecharDesktopComAtraso);

    abrir.addEventListener("click", abrirDrawer);
    fechar.addEventListener("click", () => fecharDrawer(true));
    overlay.addEventListener("click", () => fecharDrawer(true));

    sidebar.addEventListener("click", (evento) => {
      const itemAba = evento.target.closest("[data-menu-aba]");
      if (CONTEXTOS_ABAS[contexto] && itemAba) {
        ativarAbaContexto(contexto, itemAba.dataset.menuAba);
      }
      if (!mediaDesktop.matches && evento.target.closest(".sidebar-item")) {
        window.setTimeout(() => fecharDrawer(false), 0);
      }
    });

    document.addEventListener("keydown", (evento) => {
      if (evento.key === "Escape" && !mediaDesktop.matches && document.body.classList.contains("sidebar-agenda-aberta")) {
        fecharDrawer(true);
      }
    });

    if (typeof mediaDesktop.addEventListener === "function") {
      mediaDesktop.addEventListener("change", sincronizarBreakpoint);
    } else {
      mediaDesktop.addListener(sincronizarBreakpoint);
    }

    sincronizarBreakpoint();

    if (CONTEXTOS_ABAS[contexto]) {
      const configuracao = CONTEXTOS_ABAS[contexto];
      const salva = localStorage.getItem(configuracao.storage);
      ativarAbaContexto(contexto, configuracao.titulos[salva] ? salva : configuracao.padrao, false);
    }
  });
})();
