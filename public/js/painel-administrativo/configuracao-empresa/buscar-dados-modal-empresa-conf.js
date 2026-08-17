(() => {
  "use strict";

  if (window.__CONFIG_AGENDA_EMPRESA_SAVE_JS_INIT__) {
    console.warn("[ConfigAgendaEmpresa] Script já inicializado.");
    return;
  }
  window.__CONFIG_AGENDA_EMPRESA_SAVE_JS_INIT__ = true;

  const MODAL_ID = "modalConfiguracoesAgenda";
  const FORM_ID = "formConfigAgenda";

  const API_BUSCAR = "/public/api/api_central.php?path=painel/configuracao-geral-buscar";
  const API_SALVAR = "/public/api/api_central.php?path=painel/configuracao-geral-salvar";

  const DIAS = ["segunda", "terca", "quarta", "quinta", "sexta", "sabado", "domingo"];

  const IDS = {
    semana: "cfg_semana_inicio",
    intervalo: "cfg_intervalo_padrao",
    obs: "cfg_obs_geral",
    ddd: "cfg_ddd_padrao",
    ddi: "cfg_ddi_padrao",
    msgWhats: "cfg_msg_whats",
    btnSalvar: "btnSalvarConfigAgenda"
  };

  const MSG_WHATS_PADRAO =
    "Olá {cliente}! Seu agendamento de {servico} está {status} para {data} às {hora}.";

  function $(id) {
    return document.getElementById(id);
  }

  function getModal() {
    return $(MODAL_ID);
  }

  function getForm() {
    return $(FORM_ID);
  }

  function soDigitos(valor) {
    return String(valor || "").replace(/\D+/g, "");
  }

  function valorCampo(id) {
    const el = $(id);
    return el ? String(el.value || "").trim() : "";
  }

  function setValor(id, valor) {
    const el = $(id);
    if (!el) return;
    el.value = valor == null ? "" : String(valor);
  }

  function mostrarToast(msg, tipo = "info") {
    if (typeof window.alertaSistema === "function") {
      window.alertaSistema(msg, tipo);
      return;
    }

    if (typeof window.mostrarToast === "function") {
      window.mostrarToast(msg, tipo);
      return;
    }

    window.alert(msg);
  }

  function limparTodosErros() {
    document.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
      el.classList.remove("ativo");
    });

    document.querySelectorAll(".modal-campo.erro").forEach((el) => {
      el.classList.remove("erro");
    });

    document.querySelectorAll("[aria-invalid='true']").forEach((el) => {
      el.removeAttribute("aria-invalid");
    });
  }

  function mostrarErroGeralHorarios(msg) {
    const erro = document.querySelector('.msg-erro[data-erro-for="horarios_empresa"]');
    if (erro) {
      erro.textContent = msg;
      erro.classList.add("ativo");
    }

    mostrarToast(msg, "warning");
  }

  function coletarHorarios() {
    const form = getForm();
    const horarios = {};

    DIAS.forEach((dia) => {
      const ativo = form.querySelector(`[name="horarios[${dia}][ativo]"]`);
      const horaInicio = form.querySelector(`[name="horarios[${dia}][hora_inicio]"]`);
      const horaFim = form.querySelector(`[name="horarios[${dia}][hora_fim]"]`);
      const almocoInicio = form.querySelector(`[name="horarios[${dia}][almoco_inicio]"]`);
      const almocoFim = form.querySelector(`[name="horarios[${dia}][almoco_fim]"]`);

      horarios[dia] = {
        ativo: ativo && ativo.checked ? 1 : 0,
        hora_inicio: horaInicio ? horaInicio.value.trim() : "",
        hora_fim: horaFim ? horaFim.value.trim() : "",
        almoco_inicio: almocoInicio ? almocoInicio.value.trim() : "",
        almoco_fim: almocoFim ? almocoFim.value.trim() : ""
      };
    });

    return horarios;
  }

  function aplicarHorarios(horarios) {
    const form = getForm();
    if (!form) return;

    DIAS.forEach((dia) => {
      const item = horarios && horarios[dia] ? horarios[dia] : {};

      const ativo = form.querySelector(`[name="horarios[${dia}][ativo]"]`);
      const horaInicio = form.querySelector(`[name="horarios[${dia}][hora_inicio]"]`);
      const horaFim = form.querySelector(`[name="horarios[${dia}][hora_fim]"]`);
      const almocoInicio = form.querySelector(`[name="horarios[${dia}][almoco_inicio]"]`);
      const almocoFim = form.querySelector(`[name="horarios[${dia}][almoco_fim]"]`);

      if (ativo) ativo.checked = Number(item.ativo ?? item.disponivel ?? 0) === 1;
      if (horaInicio) horaInicio.value = item.hora_inicio ?? "";
      if (horaFim) horaFim.value = item.hora_fim ?? "";
      if (almocoInicio) almocoInicio.value = item.almoco_inicio ?? "";
      if (almocoFim) almocoFim.value = item.almoco_fim ?? "";
    });
  }

  function coletarDadosFormulario() {
    return {
      semana_inicio: valorCampo(IDS.semana) || "segunda",
      intervalo_padrao: valorCampo(IDS.intervalo) || "10",
      observacao_padrao: valorCampo(IDS.obs),

      horarios: coletarHorarios(),

      ddd_padrao: soDigitos(valorCampo(IDS.ddd)),
      ddi_padrao: soDigitos(valorCampo(IDS.ddi)) || "55",
      msg_whats: valorCampo(IDS.msgWhats) || MSG_WHATS_PADRAO
    };
  }

  function validarHorarios(horarios) {
    let temDiaAtivo = false;

    for (const dia of DIAS) {
      const h = horarios[dia];

      if (!h || Number(h.ativo) !== 1) {
        continue;
      }

      temDiaAtivo = true;

      if (!h.hora_inicio || !h.hora_fim) {
        mostrarErroGeralHorarios(`Informe hora inicial e final para ${dia}.`);
        return false;
      }

      if (h.hora_inicio >= h.hora_fim) {
        mostrarErroGeralHorarios(`A hora final deve ser maior que a inicial em ${dia}.`);
        return false;
      }

      if ((h.almoco_inicio && !h.almoco_fim) || (!h.almoco_inicio && h.almoco_fim)) {
        mostrarErroGeralHorarios(`Preencha início e fim do almoço em ${dia}.`);
        return false;
      }

      if (h.almoco_inicio && h.almoco_fim && h.almoco_inicio >= h.almoco_fim) {
        mostrarErroGeralHorarios(`O fim do almoço deve ser maior que o início em ${dia}.`);
        return false;
      }

      if (
        h.almoco_inicio &&
        h.almoco_fim &&
        (h.almoco_inicio <= h.hora_inicio || h.almoco_fim >= h.hora_fim)
      ) {
        mostrarErroGeralHorarios(`O almoço precisa estar dentro do horário de atendimento em ${dia}.`);
        return false;
      }
    }

    if (!temDiaAtivo) {
      mostrarErroGeralHorarios("Selecione pelo menos um dia ativo.");
      return false;
    }

    return true;
  }

  function validarFormulario(payload) {
    limparTodosErros();

    const intervaloPermitido = ["10", "15", "20", "30", "45", "60"];

    if (!intervaloPermitido.includes(String(payload.intervalo_padrao))) {
      mostrarToast("Selecione um intervalo padrão válido.", "warning");
      return false;
    }

    if (!validarHorarios(payload.horarios)) {
      return false;
    }

    if (payload.ddd_padrao && payload.ddd_padrao.length < 2) {
      mostrarToast("Informe um DDD válido.", "warning");
      return false;
    }

    if (!payload.ddi_padrao) {
      mostrarToast("Informe um DDI válido.", "warning");
      return false;
    }

    return true;
  }

  function aplicarDadosNoFormulario(data) {
    if (!data || typeof data !== "object") return;

    setValor(IDS.semana, data.inicio_semana ?? data.semana_inicio ?? "segunda");
    setValor(IDS.intervalo, data.intervalo_padrao ?? data.intervalo_padrao_min ?? "10");
    setValor(IDS.obs, data.observacao_padrao ?? "");

    aplicarHorarios(data.horarios || {});

    setValor(IDS.ddd, data.ddd_padrao ?? "");
    setValor(IDS.ddi, data.ddi_padrao ?? "55");
    setValor(IDS.msgWhats, data.mensagem_padrao ?? data.msg_whats ?? MSG_WHATS_PADRAO);
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
        console.error("[ConfigAgendaEmpresa] Resposta inválida ao carregar:", texto);
        throw new Error("A resposta do servidor não está em JSON válido.");
      }

      if (!resp.ok || !json || json.ok !== true) {
        throw new Error(json?.user_msg || "Não foi possível carregar as configurações.");
      }

      aplicarDadosNoFormulario(json.data || {});
      limparTodosErros();

    } catch (err) {
      console.error("[ConfigAgendaEmpresa] Erro ao carregar:", err);
      mostrarToast(err.message || "Erro ao carregar configurações da agenda.", "danger");
    } finally {
      delete modal.dataset.loading;
    }
  }

  async function salvarConfiguracao(event) {
    event.preventDefault();

    const btn = $(IDS.btnSalvar);
    const payload = coletarDadosFormulario();

    if (!validarFormulario(payload)) {
      return;
    }

    try {
      if (btn) {
        btn.disabled = true;
        btn.dataset.loading = "1";
      }

      const resp = await fetch(API_SALVAR, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json; charset=utf-8",
          Accept: "application/json"
        },
        body: JSON.stringify(payload)
      });

      const texto = await resp.text();
      let json = null;

      try {
        json = texto ? JSON.parse(texto) : null;
      } catch (e) {
        console.error("[ConfigAgendaEmpresa] Resposta inválida ao salvar:", texto);
        throw new Error("A resposta do servidor não está em JSON válido.");
      }

      if (!resp.ok || !json || json.ok !== true) {
        throw new Error(json?.user_msg || "Não foi possível salvar as configurações.");
      }

      aplicarDadosNoFormulario(json.data || payload);
      limparTodosErros();
      mostrarToast(json.user_msg || "Configurações salvas com sucesso.", "success");

    } catch (err) {
      console.error("[ConfigAgendaEmpresa] Erro ao salvar:", err);
      mostrarToast(err.message || "Erro ao salvar configurações da agenda.", "danger");
    } finally {
      if (btn) {
        btn.disabled = false;
        delete btn.dataset.loading;
      }
    }
  }

  function bindEventosCampos() {
    const form = getForm();
    if (!form) return;

    form.querySelectorAll("input, select, textarea").forEach((el) => {
      el.addEventListener("input", limparTodosErros);
      el.addEventListener("change", limparTodosErros);
    });

    const campoDDD = $(IDS.ddd);
    if (campoDDD) {
      campoDDD.addEventListener("input", () => {
        campoDDD.value = soDigitos(campoDDD.value).slice(0, 2);
      });
    }

    const campoDDI = $(IDS.ddi);
    if (campoDDI) {
      campoDDI.addEventListener("input", () => {
        campoDDI.value = soDigitos(campoDDI.value).slice(0, 2);
      });
    }
  }

  function bindAberturaModal() {
    document.addEventListener("click", (ev) => {
      const btn = ev.target.closest('[data-abrir-modal="modalConfiguracoesAgenda"]');
      if (!btn) return;

      carregarConfiguracao();
    });
  }

  function init() {
    const modal = getModal();
    const form = getForm();

    if (!modal || !form) {
      console.warn("[ConfigAgendaEmpresa] Modal ou formulário não encontrado.");
      return;
    }

    bindEventosCampos();
    bindAberturaModal();
    form.addEventListener("submit", salvarConfiguracao);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();