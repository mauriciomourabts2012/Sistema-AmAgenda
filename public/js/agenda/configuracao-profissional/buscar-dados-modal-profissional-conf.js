(() => {
  "use strict";

  if (window.__CONFIG_AGENDA_EMPRESA_DADOS_JS_INIT__) {
    console.warn("[ConfigAgendaEmpresa] Script já inicializado.");
    return;
  }
  window.__CONFIG_AGENDA_EMPRESA_DADOS_JS_INIT__ = true;

  const MODAL_ID = "modalConfiguracoesAgenda";
  const FORM_ID = "formConfigAgenda";

  const API_BUSCAR = "/public/api/api_central.php?path=agenda/configuracao-geral-buscar";

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

  async function carregarConfiguracao() {
    const modal = getModal();
    if (!modal) return;

    try {
      modal.dataset.loading = "1";

      const resp = await fetch(API_BUSCAR, {
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

        setTimeout(() => {
          carregarConfiguracao();
        }, 50);
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
    bindAberturaModal();
    bindFecharModal();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();