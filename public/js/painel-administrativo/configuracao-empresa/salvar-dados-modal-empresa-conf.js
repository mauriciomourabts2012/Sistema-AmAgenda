(() => {
  "use strict";

  if (window.__SALVAR_DADOS_EMPRESA_CONF_JS_INIT__) {
    console.warn("[ConfigAgendaSalvar] Script já inicializado.");
    return;
  }
  window.__SALVAR_DADOS_EMPRESA_CONF_JS_INIT__ = true;

  const API_URL = "/public/api/api_central.php?path=painel/configuracao-geral-salvar";

  const MODAL_ID = "modalConfiguracoesAgenda";
  const FORM_ID = "formConfigAgenda";
  const BTN_SALVAR_ID = "btnSalvarConfigAgenda";

  const DIAS = [
    "segunda",
    "terca",
    "quarta",
    "quinta",
    "sexta",
    "sabado",
    "domingo"
  ];

  const IDS = {
    geral: {
      semanaInicio: "cfg_semana_inicio",
      intervaloPadrao: "cfg_intervalo_padrao",
      observacao: "cfg_obs_geral"
    },
    whatsapp: {
      ddd: "cfg_ddd_padrao",
      ddi: "cfg_ddi_padrao",
      mensagem: "cfg_msg_whats"
    }
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

  function getBotaoSalvar() {
    return $(BTN_SALVAR_ID);
  }

  function escapeHtml(valor) {
    return String(valor ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function getToastStack() {
    let stack = document.querySelector(".ui-toast-stack");

    if (!stack) {
      stack = document.createElement("div");
      stack.className = "ui-toast-stack";
      document.body.appendChild(stack);
    }

    return stack;
  }

  function criarToast({ tipo = "info", titulo = "Aviso", mensagem = "", tempo = 3200 }) {
    const stack = getToastStack();

    const alerta = document.createElement("div");
    alerta.className = `ui-alert ui-alert--${tipo}`;
    alerta.setAttribute("role", "alert");
    alerta.setAttribute("aria-live", "assertive");

    const icone =
      tipo === "success" ? "✓" :
      tipo === "danger" ? "×" :
      tipo === "warning" ? "!" : "i";

    alerta.innerHTML = `
      <div class="ui-alert__icon" aria-hidden="true">${icone}</div>
      <div>
        <p class="ui-alert__title">${escapeHtml(titulo)}</p>
        <div class="ui-alert__msg">${escapeHtml(mensagem)}</div>
      </div>
      <div class="ui-alert__actions">
        <button type="button" class="ui-alert__btn ui-alert__btn--primary">OK</button>
      </div>
    `;

    const btnOk = alerta.querySelector(".ui-alert__btn");

    function fechar() {
      if (!alerta.isConnected) return;

      alerta.classList.add("is-leaving");

      setTimeout(() => {
        if (alerta.isConnected) alerta.remove();
      }, 180);
    }

    btnOk?.addEventListener("click", fechar);

    stack.appendChild(alerta);

    if (tempo > 0) {
      setTimeout(fechar, tempo);
    }
  }

  function setLoading(ativo) {
    const btn = getBotaoSalvar();
    if (!btn) return;

    btn.disabled = !!ativo;
    btn.dataset.loading = ativo ? "1" : "0";
    btn.textContent = ativo ? "Salvando..." : "Salvar";
  }

  function limparTodosErros() {
    const modal = getModal();
    if (!modal) return;

    modal.querySelectorAll(".modal-campo.erro, .cfg-agenda__campo.erro").forEach((el) => {
      el.classList.remove("erro");
    });

    modal.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
      el.classList.remove("ativo");
    });
  }

  function limparErrosAba(aba) {
    const modal = getModal();
    if (!modal) return;

    const painel = modal.querySelector(`#${CSS.escape(aba)}`);
    if (!painel) return;

    painel.querySelectorAll(".modal-campo.erro, .cfg-agenda__campo.erro").forEach((el) => {
      el.classList.remove("erro");
    });

    painel.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
      el.classList.remove("ativo");
    });
  }

  function aplicarErroCampo(fieldKey, mensagem, aba) {
    const modal = getModal();
    if (!modal) return;

    const painel = modal.querySelector(`#${CSS.escape(aba)}`);
    if (!painel) return;

    if (fieldKey === "horarios_empresa") {
      const erro = painel.querySelector('.msg-erro[data-erro-for="horarios_empresa"]');

      if (erro) {
        erro.textContent = mensagem || "";
        erro.classList.add("ativo");
      }

      const container = erro?.closest(".modal-campo");
      if (container) {
        container.classList.add("erro");
      }

      return;
    }

    const campo = painel.querySelector(`#${CSS.escape(fieldKey)}`);
    const erro = painel.querySelector(`.msg-erro[data-erro-for="${CSS.escape(fieldKey)}"]`);

    if (campo) {
      const container = campo.closest(".modal-campo");
      if (container) container.classList.add("erro");
    }

    if (erro) {
      erro.textContent = mensagem || "";
      erro.classList.add("ativo");
    }
  }

  function aplicarFieldErrors(fieldErrors, aba) {
    if (!fieldErrors || typeof fieldErrors !== "object") return;

    Object.entries(fieldErrors).forEach(([campo, mensagem]) => {
      aplicarErroCampo(campo, String(mensagem ?? ""), aba);
    });
  }

  function somenteDigitos(valor) {
    return String(valor ?? "").replace(/\D+/g, "");
  }

  function getAbaAtiva() {
    if (window.ConfigAgendaTabs && typeof window.ConfigAgendaTabs.getAbaAtiva === "function") {
      return window.ConfigAgendaTabs.getAbaAtiva();
    }

    const modal = getModal();
    if (!modal) return null;

    const btnAtivo =
      modal.querySelector(".tabs-config .tab-btn.ativa[data-tab]") ||
      modal.querySelector(".tabs-config .botao-geral.ativa[data-tab]");

    return btnAtivo ? btnAtivo.getAttribute("data-tab") : null;
  }

  function coletarPayloadGeral() {
    return {
      aba: "cfg-geral",
      semana_inicio: $(IDS.geral.semanaInicio)?.value || "segunda",
      intervalo_padrao: $(IDS.geral.intervaloPadrao)?.value || "",
      observacao_padrao: ($(IDS.geral.observacao)?.value || "").trim()
    };
  }

  function getInputHorario(dia, campo) {
    const modal = getModal();
    if (!modal) return null;

    return modal.querySelector(
      `#cfg-horarios input[name="horarios[${dia}][${campo}]"]`
    );
  }

  function coletarPayloadHorarios() {
    const horarios = {};

    DIAS.forEach((dia) => {
      const inputAtivo = getInputHorario(dia, "ativo");
      const inputInicio = getInputHorario(dia, "hora_inicio");
      const inputFim = getInputHorario(dia, "hora_fim");
      const inputAlmocoInicio = getInputHorario(dia, "almoco_inicio");
      const inputAlmocoFim = getInputHorario(dia, "almoco_fim");

      horarios[dia] = {
        ativo: inputAtivo?.checked ? "1" : "0",
        hora_inicio: inputInicio?.value || "",
        hora_fim: inputFim?.value || "",
        almoco_inicio: inputAlmocoInicio?.value || "",
        almoco_fim: inputAlmocoFim?.value || ""
      };
    });

    return {
      aba: "cfg-horarios",
      horarios
    };
  }

  function coletarPayloadWhatsapp() {
    return {
      aba: "cfg-whatsapp",
      ddd_padrao: somenteDigitos($(IDS.whatsapp.ddd)?.value || ""),
      ddi_padrao: somenteDigitos($(IDS.whatsapp.ddi)?.value || ""),
      msg_whats: ($(IDS.whatsapp.mensagem)?.value || "").trim()
    };
  }

  function coletarPayloadDaAba(aba) {
    if (aba === "cfg-geral") return coletarPayloadGeral();
    if (aba === "cfg-horarios") return coletarPayloadHorarios();
    if (aba === "cfg-whatsapp") return coletarPayloadWhatsapp();

    return null;
  }

  async function enviarPayload(payload) {
    const response = await fetch(API_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json"
      },
      body: JSON.stringify(payload)
    });

    const raw = await response.text();

    let json = null;

    try {
      json = raw ? JSON.parse(raw) : null;
    } catch (e) {
      console.error("[ConfigAgendaSalvar] Resposta bruta:", raw);
      throw new Error("Resposta inválida do servidor.");
    }

    return {
      status: response.status,
      okHttp: response.ok,
      json
    };
  }

  function atualizarCamposHorarios(data) {
    if (!data || typeof data !== "object") return;

    const horarios = data.horarios || {};

    DIAS.forEach((dia) => {
      const item = horarios[dia];

      if (!item || typeof item !== "object") return;

      const inputAtivo = getInputHorario(dia, "ativo");
      const inputInicio = getInputHorario(dia, "hora_inicio");
      const inputFim = getInputHorario(dia, "hora_fim");
      const inputAlmocoInicio = getInputHorario(dia, "almoco_inicio");
      const inputAlmocoFim = getInputHorario(dia, "almoco_fim");

      if (inputAtivo) {
        inputAtivo.checked = String(item.ativo) === "1";
      }

      if (inputInicio) {
        inputInicio.value = item.hora_inicio || "";
      }

      if (inputFim) {
        inputFim.value = item.hora_fim || "";
      }

      if (inputAlmocoInicio) {
        inputAlmocoInicio.value = item.almoco_inicio || "";
      }

      if (inputAlmocoFim) {
        inputAlmocoFim.value = item.almoco_fim || "";
      }
    });

    if ($(IDS.geral.semanaInicio) && data.semana_inicio != null) {
      $(IDS.geral.semanaInicio).value = String(data.semana_inicio);
    }
  }

  function atualizarCamposComRetorno(aba, data) {
    if (!data || typeof data !== "object") return;

    if (aba === "cfg-geral") {
      if ($(IDS.geral.semanaInicio) && data.semana_inicio != null) {
        $(IDS.geral.semanaInicio).value = String(data.semana_inicio);
      }

      if ($(IDS.geral.intervaloPadrao) && data.intervalo_padrao_min != null) {
        $(IDS.geral.intervaloPadrao).value = String(data.intervalo_padrao_min);
      }

      if ($(IDS.geral.observacao) && data.observacao_padrao != null) {
        $(IDS.geral.observacao).value = String(data.observacao_padrao);
      }
    }

    if (aba === "cfg-horarios") {
      atualizarCamposHorarios(data);
    }

    if (aba === "cfg-whatsapp") {
      if ($(IDS.whatsapp.ddd) && data.ddd_padrao != null) {
        $(IDS.whatsapp.ddd).value = String(data.ddd_padrao);
      }

      if ($(IDS.whatsapp.ddi) && data.ddi_padrao != null) {
        $(IDS.whatsapp.ddi).value = String(data.ddi_padrao);
      }

      if ($(IDS.whatsapp.mensagem) && data.mensagem_padrao != null) {
        $(IDS.whatsapp.mensagem).value = String(data.mensagem_padrao);
      }
    }
  }

  function habilitarDesabilitarLinha(dia) {
    const inputAtivo = getInputHorario(dia, "ativo");
    const inputInicio = getInputHorario(dia, "hora_inicio");
    const inputFim = getInputHorario(dia, "hora_fim");
    const inputAlmocoInicio = getInputHorario(dia, "almoco_inicio");
    const inputAlmocoFim = getInputHorario(dia, "almoco_fim");

    const ativo = !!inputAtivo?.checked;

    [inputInicio, inputFim, inputAlmocoInicio, inputAlmocoFim].forEach((input) => {
      if (!input) return;

      input.disabled = !ativo;

      if (!ativo) {
        input.value = "";
      }
    });
  }

  function registrarControleLinhasHorarios() {
    DIAS.forEach((dia) => {
      const inputAtivo = getInputHorario(dia, "ativo");

      if (!inputAtivo) return;

      inputAtivo.addEventListener("change", () => {
        habilitarDesabilitarLinha(dia);
      });

      habilitarDesabilitarLinha(dia);
    });
  }

  async function salvarAbaAtual() {
    const aba = getAbaAtiva();

    if (!aba) {
      criarToast({
        tipo: "warning",
        titulo: "Atenção",
        mensagem: "Nenhuma aba ativa foi encontrada."
      });
      return;
    }

    limparErrosAba(aba);

    const payload = coletarPayloadDaAba(aba);

    if (!payload) {
      criarToast({
        tipo: "danger",
        titulo: "Erro",
        mensagem: "Não foi possível montar os dados para envio."
      });
      return;
    }

    setLoading(true);

    try {
      const { json } = await enviarPayload(payload);

      if (!json || typeof json !== "object") {
        throw new Error("Resposta vazia do servidor.");
      }

      if (!json.ok) {
        if (json.code === "VALIDATION_ERROR") {
          aplicarFieldErrors(json.field_errors || {}, aba);
        }

        criarToast({
          tipo: "warning",
          titulo: "Atenção",
          mensagem: json.user_msg || "Não foi possível salvar os dados."
        });

        return;
      }

      atualizarCamposComRetorno(aba, json.data || {});

      if (window.ConfigAgendaTabs && typeof window.ConfigAgendaTabs.marcarAbaComoSalva === "function") {
        window.ConfigAgendaTabs.marcarAbaComoSalva(aba);
      }

      criarToast({
        tipo: "success",
        titulo: "Sucesso",
        mensagem: json.user_msg || "Dados salvos com sucesso."
      });

    } catch (error) {
      console.error("[ConfigAgendaSalvar] Erro:", error);

      criarToast({
        tipo: "danger",
        titulo: "Erro",
        mensagem: error?.message || "Erro interno ao salvar as configurações."
      });
    } finally {
      setLoading(false);
    }
  }

  function limitarCamposNumericos() {
    const campoDDD = $(IDS.whatsapp.ddd);
    const campoDDI = $(IDS.whatsapp.ddi);

    if (campoDDD) {
      campoDDD.addEventListener("input", () => {
        campoDDD.value = somenteDigitos(campoDDD.value).slice(0, 2);
      });
    }

    if (campoDDI) {
      campoDDI.addEventListener("input", () => {
        campoDDI.value = somenteDigitos(campoDDI.value).slice(0, 5);
      });
    }
  }

  function registrarSubmit() {
    const form = getForm();
    if (!form) return;

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      await salvarAbaAtual();
    });
  }

  function registrarCliqueBotaoSalvar() {
    const btn = getBotaoSalvar();
    if (!btn) return;

    btn.addEventListener("click", async (e) => {
      e.preventDefault();
      await salvarAbaAtual();
    });
  }

  function init() {
    const modal = getModal();
    const form = getForm();

    if (!modal || !form) {
      console.warn("[ConfigAgendaSalvar] Modal ou formulário não encontrado.");
      return;
    }

    limparTodosErros();
    limitarCamposNumericos();
    registrarControleLinhasHorarios();
    registrarSubmit();
    registrarCliqueBotaoSalvar();
  }

  document.addEventListener("DOMContentLoaded", init);

  window.ConfigAgendaSalvar = {
    salvarAbaAtual
  };
})();