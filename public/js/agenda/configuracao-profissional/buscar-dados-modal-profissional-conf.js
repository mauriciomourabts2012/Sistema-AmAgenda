(() => {
  "use strict";

  if (window.__CONFIG_AGENDA_EMPRESA_DADOS_JS_INIT__) {
    console.warn("[ConfigAgendaEmpresa] Script já inicializado.");
    return;
  }
  window.__CONFIG_AGENDA_EMPRESA_DADOS_JS_INIT__ = true;

  const MODAL_ID = "modalConfiguracoesAgenda";
  const MODAL_SELECAO_ID = "modalSelecionarProfissionalConfig";
  const FORM_ID = "formConfigAgenda";

  const API_BUSCAR = "/public/api/api_central.php?path=agenda/configuracao-geral-buscar";
  const API_LISTAR_PROFISSIONAIS = "/public/api/api_central.php?path=agenda/profissional-modal-novo-agendamento/listar&contexto=administracao&status=ativo&pagina=1&limite=200";
  const API_PROFISSIONAL_LOGADO = "/public/api/api_central.php?path=agenda/profissional-modal-novo-agendamento/listar&status=ativo&pagina=1&limite=1";

  // Proprietário e Super Admin em suporte precisam selecionar explicitamente
  // qual profissional desejam administrar antes de abrir as configurações.
  const contextoProfissional = {
    id: 0,
    nome: "",
    getId() { return this.id; },
    getNome() { return this.nome; },
    definir(id, nome) {
      this.id = Number(id) || 0;
      this.nome = String(nome || "").trim();
    },
    limpar() {
      this.id = 0;
      this.nome = "";
    }
  };
  window.ConfigAgendaProfissional = contextoProfissional;

  const IDS = {
    semana: "cfg_semana_inicio",
    intervalo: "cfg_intervalo_padrao",
    obs: "cfg_obs_geral",
    ddd: "cfg_ddd_padrao",
    ddi: "cfg_ddi_padrao",
    msgWhats: "cfg_msg_whats"
  };

  const MSG_WHATS_PADRAO =
    "Olá {cliente}! Seu agendamento de {servico} está {status} para {data} às {hora}.";

  const DIAS = [
    "segunda",
    "terca",
    "quarta",
    "quinta",
    "sexta",
    "sabado",
    "domingo"
  ];

  const MAP_SIGLA_PARA_DIA = {
    seg: "segunda",
    ter: "terca",
    qua: "quarta",
    qui: "quinta",
    sex: "sexta",
    sab: "sabado",
    dom: "domingo"
  };

  function $(id) {
    return document.getElementById(id);
  }

  function getModal() {
    return $(MODAL_ID);
  }

  function getForm() {
    return $(FORM_ID);
  }

  function getModalSelecao() {
    return $(MODAL_SELECAO_ID);
  }

  function criarStackAlertas() {
    let stack = document.querySelector(".ui-toast-stack");

    if (!stack) {
      stack = document.createElement("div");
      stack.className = "ui-toast-stack";
      document.body.appendChild(stack);
    }

    return stack;
  }

  function alertaBase(tipo, mensagem, opcoes = {}) {
    const stack = criarStackAlertas();

    const alert = document.createElement("div");
    alert.className = `ui-alert ui-alert--${tipo}`;

    const icones = {
      success: "✓",
      warning: "!",
      danger: "×",
      confirm: "?"
    };

    const textoBotao = opcoes.textoBotao || "OK";
    const autoClose = opcoes.autoClose === true;
    const tempo = Number(opcoes.tempo || 3000);

    alert.innerHTML = `
      <div class="ui-alert__icon">${icones[tipo] || "!"}</div>
      <div class="ui-alert__content">
        <div class="ui-alert__message">${mensagem}</div>
        ${
          autoClose
            ? ""
            : `<button type="button" class="ui-alert__btn">${textoBotao}</button>`
        }
      </div>
    `;

    stack.appendChild(alert);

    const fechar = () => {
      alert.classList.add("saindo");
      setTimeout(() => alert.remove(), 180);
    };

    const btn = alert.querySelector(".ui-alert__btn");
    if (btn) {
      btn.addEventListener("click", fechar);
    }

    if (autoClose) {
      setTimeout(fechar, tempo);
    }

    return alert;
  }

  function alertWarn(mensagem) {
    return alertaBase("warning", mensagem, {
      autoClose: false,
      textoBotao: "OK"
    });
  }

  function alertErr(mensagem) {
    return alertaBase("danger", mensagem, {
      autoClose: false,
      textoBotao: "OK"
    });
  }

  function abrirModal() {
    const modal = getModal();
    if (!modal) return;

    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");
    modal.style.display = "flex";

    document.body.classList.add("modal-aberto");
  }

  function fecharModal() {
    const modal = getModal();
    if (!modal) return;

    modal.classList.remove(
      "ativo",
      "aberto",
      "show",
      "mostrar",
      "modal-aberto",
      "is-active",
      "open"
    );

    modal.setAttribute("aria-hidden", "true");
    modal.style.display = "none";

    document.body.classList.remove(
      "modal-aberto",
      "modal-open",
      "sem-scroll"
    );

    contextoProfissional.limpar();
    const tituloNome = $("cfg_profissional_nome_titulo");
    if (tituloNome) tituloNome.textContent = "";
  }

  function abrirModalSelecao() {
    const modal = getModalSelecao();
    if (!modal) return;
    modal.classList.add("ativo");
    modal.setAttribute("aria-hidden", "false");
    modal.style.display = "flex";
    document.body.classList.add("modal-aberto");
  }

  function fecharModalSelecao() {
    const modal = getModalSelecao();
    if (!modal) return;
    modal.classList.remove("ativo");
    modal.setAttribute("aria-hidden", "true");
    modal.style.display = "none";
    if (!getModal()?.classList.contains("ativo")) document.body.classList.remove("modal-aberto");
  }

  async function carregarProfissionaisParaSelecao() {
    const select = $("cfg_profissional_selecionado");
    const continuar = $("btnContinuarSelecaoProfissional");
    const msg = $("cfg_profissional_selecao_msg");
    if (!select || !continuar) return;

    contextoProfissional.limpar();
    select.disabled = true;
    continuar.disabled = true;
    select.innerHTML = '<option value="">Carregando profissionais...</option>';
    if (msg) { msg.textContent = ""; msg.classList.remove("ativo"); }
    abrirModalSelecao();

    try {
      const resp = await fetch(API_LISTAR_PROFISSIONAIS, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        cache: "no-store"
      });
      const json = await resp.json().catch(() => null);
      if (!resp.ok || !json?.ok) throw new Error(json?.user_msg || json?.mensagem || "Não foi possível carregar os profissionais.");

      const profissionais = Array.isArray(json.data) ? json.data : [];
      select.innerHTML = '<option value="">Selecione um profissional</option>';
      profissionais.forEach((item) => {
        const option = document.createElement("option");
        option.value = String(item.id_profissional || "");
        option.textContent = String(item.nome || "Profissional");
        select.appendChild(option);
      });
      select.disabled = profissionais.length === 0;
      if (!profissionais.length) {
        select.innerHTML = '<option value="">Nenhum profissional ativo encontrado.</option>';
        if (msg) { msg.textContent = "Nenhum profissional ativo encontrado."; msg.classList.add("ativo"); }
      }
    } catch (err) {
      select.innerHTML = '<option value="">Profissionais indisponíveis</option>';
      if (msg) { msg.textContent = err.message; msg.classList.add("ativo"); }
      alertWarn(err.message || "Não foi possível carregar os profissionais.");
    }
  }

  function usuarioLogadoEhProfissional() {
    const auth = window.__AUTH__ || {};
    const perfil = String(auth.perfil_nome || auth.perfil || "")
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    return perfil === "profissional" || perfil === "profissionais";
  }

  async function abrirConfiguracaoDoProfissionalLogado() {
    try {
      const resp = await fetch(API_PROFISSIONAL_LOGADO, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        cache: "no-store"
      });
      const json = await resp.json().catch(() => null);
      if (!resp.ok || !json?.ok) {
        throw new Error(json?.user_msg || json?.mensagem || "Não foi possível identificar seu cadastro profissional.");
      }

      const profissional = Array.isArray(json.data) ? json.data[0] : null;
      const idProfissional = Number(json.profissional_logado?.id_profissional || profissional?.id_profissional || 0);
      if (!idProfissional) throw new Error("Seu usuário não possui um cadastro profissional ativo nesta empresa.");

      contextoProfissional.definir(idProfissional, profissional?.nome || "");
      await carregarConfiguracao(idProfissional);
    } catch (err) {
      alertWarn(err.message || "Não foi possível abrir as configurações da sua agenda.");
    }
  }

  function soDigitos(valor) {
    return String(valor || "").replace(/\D+/g, "");
  }

  function setValor(id, valor) {
    const el = $(id);
    if (!el) return;
    el.value = valor == null ? "" : String(valor);
  }

  function normalizarHora(valor) {
    const v = String(valor || "").trim();

    if (!v) return "";

    if (/^\d{2}:\d{2}:\d{2}$/.test(v)) {
      return v.substring(0, 5);
    }

    if (/^\d{2}:\d{2}$/.test(v)) {
      return v;
    }

    return "";
  }

  function getHorarioPorDia(horarios, dia) {
    if (!horarios || typeof horarios !== "object") return null;

    if (horarios[dia]) {
      return horarios[dia];
    }

    for (const [sigla, nomeDia] of Object.entries(MAP_SIGLA_PARA_DIA)) {
      if (nomeDia === dia && horarios[sigla]) {
        return horarios[sigla];
      }
    }

    return null;
  }

  function setCampoHorario(dia, campo, valor) {
    const form = getForm();
    if (!form) return;

    const input = form.querySelector(`[name="horarios[${dia}][${campo}]"]`);
    if (!input) return;

    input.value = normalizarHora(valor);
  }

  function setCheckHorario(dia, ativo) {
    const form = getForm();
    if (!form) return;

    const input = form.querySelector(`[name="horarios[${dia}][ativo]"]`);
    if (!input) return;

    input.checked = Number(ativo) === 1;
  }

  function limparHorarioDia(dia) {
    setCampoHorario(dia, "hora_inicio", "");
    setCampoHorario(dia, "hora_fim", "");
    setCampoHorario(dia, "almoco_inicio", "");
    setCampoHorario(dia, "almoco_fim", "");
  }

  function limparHorarios() {
    DIAS.forEach((dia) => {
      setCheckHorario(dia, 0);
      limparHorarioDia(dia);
    });
  }

  function aplicarHorariosNoFormulario(data) {
    limparHorarios();

    const horarios = data.horarios || {};

    DIAS.forEach((dia) => {
      const item = getHorarioPorDia(horarios, dia);

      if (!item) return;

      const disponivel = Number(item.disponivel || 0);
      const status = String(item.status || "ativo").toLowerCase();
      const estaAtivo = disponivel === 1 && status === "ativo";

      setCheckHorario(dia, estaAtivo);

      if (!estaAtivo) {
        limparHorarioDia(dia);
        return;
      }

      setCampoHorario(dia, "hora_inicio", item.hora_inicio || "");
      setCampoHorario(dia, "hora_fim", item.hora_fim || "");
      setCampoHorario(dia, "almoco_inicio", item.almoco_inicio || "");
      setCampoHorario(dia, "almoco_fim", item.almoco_fim || "");
    });
  }

  function aplicarDadosNoFormulario(data) {
    if (!data || typeof data !== "object") return;

    setValor(IDS.semana, data.inicio_semana ?? "segunda");
    setValor(IDS.intervalo, data.intervalo_padrao ?? "10");
    setValor(IDS.obs, data.observacao_padrao ?? "");

    aplicarHorariosNoFormulario(data);

    setValor(IDS.ddd, data.ddd_padrao ?? "");
    setValor(IDS.ddi, data.ddi_padrao ?? "55");
    setValor(IDS.msgWhats, data.mensagem_padrao ?? MSG_WHATS_PADRAO);
  }

  async function carregarConfiguracao(idProfissional) {
    const modal = getModal();
    if (!modal) return;

    try {
      modal.dataset.loading = "1";

      const url = `${API_BUSCAR}&id_profissional=${encodeURIComponent(idProfissional)}`;
      const resp = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        headers: {
          Accept: "application/json"
        },
        cache: "no-store"
      });

      const texto = await resp.text();
      let json = null;

      try {
        json = texto ? JSON.parse(texto) : null;
      } catch (e) {
        console.error("[ConfigAgendaEmpresa] Resposta inválida:", texto);
        throw new Error("Não foi possível carregar as configurações. Tente novamente.");
      }

      if (!resp.ok || !json || json.ok !== true) {
        fecharModal();

        alertWarn(
          json?.user_msg ||
            "Você não tem permissão para acessar esta configuração."
        );

        return;
      }

      aplicarDadosNoFormulario(json.data || {});
      const nomeValidado = String(json.data?.profissional_nome || contextoProfissional.getNome()).trim();
      contextoProfissional.definir(json.data?.id_profissional || idProfissional, nomeValidado);
      const tituloNome = $("cfg_profissional_nome_titulo");
      if (tituloNome) tituloNome.textContent = nomeValidado ? `— ${nomeValidado}` : "";
      document.dispatchEvent(new CustomEvent("agenda:profissional-config-selecionado", {
        detail: { id_profissional: contextoProfissional.getId(), nome: nomeValidado }
      }));
      abrirModal();

    } catch (err) {
      console.error("[ConfigAgendaEmpresa] Erro ao carregar:", err);

      fecharModal();

      alertErr(
        err.message ||
          "Não foi possível carregar as configurações. Tente novamente."
      );
    } finally {
      delete modal.dataset.loading;
    }
  }

  function bindEventosCampos() {
    const campoDDD = $(IDS.ddd);
    if (campoDDD) {
      campoDDD.addEventListener("input", () => {
        campoDDD.value = soDigitos(campoDDD.value).slice(0, 2);
      });
    }

    const campoDDI = $(IDS.ddi);
    if (campoDDI) {
      campoDDI.addEventListener("input", () => {
        campoDDI.value = soDigitos(campoDDI.value).slice(0, 5);
      });
    }
  }

  function bindEventosHorarios() {
    const form = getForm();
    if (!form) return;

    DIAS.forEach((dia) => {
      const check = form.querySelector(`[name="horarios[${dia}][ativo]"]`);
      if (!check) return;

      check.addEventListener("change", () => {
        if (!check.checked) {
          limparHorarioDia(dia);
        }
      });
    });
  }

  function bindAberturaModal() {
    document.addEventListener(
      "click",
      (ev) => {
        const btn = ev.target.closest(
          '[data-abrir-modal="modalConfiguracoesAgenda"]'
        );

        if (!btn) return;

        ev.preventDefault();
        ev.stopPropagation();
        ev.stopImmediatePropagation();

        fecharModal();
        if (usuarioLogadoEhProfissional()) {
          setTimeout(abrirConfiguracaoDoProfissionalLogado, 50);
          return;
        }
        setTimeout(carregarProfissionaisParaSelecao, 50);
      },
      true
    );
  }

  function bindFecharModal() {
    document.addEventListener("click", (ev) => {
      const btnFechar = ev.target.closest("[data-fechar-modal]");
      if (!btnFechar) return;

      const modal = btnFechar.closest(`#${MODAL_ID}`);
      if (!modal) return;

      ev.preventDefault();
      fecharModal();
    });
  }

  function bindSelecaoProfissional() {
    const select = $("cfg_profissional_selecionado");
    const continuar = $("btnContinuarSelecaoProfissional");
    const cancelar = $("btnCancelarSelecaoProfissional");
    const msg = $("cfg_profissional_selecao_msg");
    if (!select || !continuar) return;

    select.addEventListener("change", () => {
      continuar.disabled = !(Number(select.value) > 0);
      if (msg) { msg.textContent = ""; msg.classList.remove("ativo"); }
    });

    continuar.addEventListener("click", async () => {
      const id = Number(select.value) || 0;
      if (!id) {
        if (msg) { msg.textContent = "Selecione um profissional para continuar."; msg.classList.add("ativo"); }
        return;
      }
      contextoProfissional.definir(id, select.options[select.selectedIndex]?.textContent || "");
      continuar.disabled = true;
      continuar.textContent = "Carregando...";
      fecharModalSelecao();
      await carregarConfiguracao(id);
      continuar.textContent = "Continuar";
      continuar.disabled = false;
    });

    cancelar?.addEventListener("click", () => { contextoProfissional.limpar(); fecharModalSelecao(); });
    document.addEventListener("click", (ev) => {
      if (ev.target.closest("[data-fechar-selecao-profissional]")) {
        contextoProfissional.limpar();
        fecharModalSelecao();
      }
    });
    document.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape" && getModal()?.classList.contains("ativo")) fecharModal();
      if (ev.key === "Escape" && getModalSelecao()?.classList.contains("ativo")) {
        contextoProfissional.limpar();
        fecharModalSelecao();
      }
    });
  }

  function init() {
    const modal = getModal();
    const form = getForm();

    if (!modal || !form) {
      console.warn("[ConfigAgendaEmpresa] Modal ou formulário não encontrado.");
      return;
    }

    fecharModal();
    bindEventosCampos();
    bindEventosHorarios();
    bindSelecaoProfissional();
    bindAberturaModal();
    bindFecharModal();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
