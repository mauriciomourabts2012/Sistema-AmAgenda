(() => {
  "use strict";

  const ENDPOINT = "/public/api/api_central.php?path=agenda/configuracao-profissional/salvar-geral";

  const form = document.getElementById("formConfigAgenda");
  const btnSalvar = document.getElementById("btnSalvarConfigAgenda");

  if (!form || !btnSalvar) return;

  const DIAS = ["segunda", "terca", "quarta", "quinta", "sexta", "sabado", "domingo"];

  function getAbaAtiva() {
    return window.ConfigAgendaTabs?.getAbaAtiva?.()
      || form.querySelector(".tab-painel.ativa")?.id
      || form.querySelector(".tab-painel.ativo")?.id
      || "cfg-geral";
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

  function escapeAlertHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function removerAlertaSistema(alertEl) {
    if (!alertEl || !alertEl.parentNode) return;

    alertEl.classList.add("is-leaving");

    setTimeout(() => {
      if (alertEl.parentNode) alertEl.remove();
    }, 180);
  }

  function mostrarAlertaSistema(tipo, mensagem, opcoes = {}) {
    const titulos = {
      success: "Sucesso",
      warning: "Atenção",
      danger: "Erro",
      info: "Informação"
    };

    const icones = {
      success: "✓",
      warning: "!",
      danger: "×",
      info: "i"
    };

    const stack = getToastStack();

    const alertEl = document.createElement("div");
    alertEl.className = `ui-alert ui-alert--${tipo || "info"}`;

    alertEl.innerHTML = `
      <div class="ui-alert__icon">${icones[tipo] || icones.info}</div>
      <div class="ui-alert__content">
        <div class="ui-alert__title">${titulos[tipo] || titulos.info}</div>
        <div class="ui-alert__msg">${escapeAlertHtml(mensagem)}</div>
      </div>
    `;

    stack.appendChild(alertEl);

    if (opcoes.persistente !== true) {
      setTimeout(() => removerAlertaSistema(alertEl), Number(opcoes.tempo ?? 4500));
    }

    return alertEl;
  }

  function setLoading(ativo) {
    btnSalvar.disabled = ativo;
    btnSalvar.dataset.textoOriginal = btnSalvar.dataset.textoOriginal || btnSalvar.textContent;

    btnSalvar.textContent = ativo
      ? "Salvando..."
      : btnSalvar.dataset.textoOriginal;
  }

  function limparErros() {
    form.querySelectorAll(".modal-campo.erro").forEach((el) => el.classList.remove("erro"));

    form.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
      el.classList.remove("ativo");
    });

    form.querySelectorAll("[data-js-erro-horario]").forEach((el) => el.remove());

    form.querySelectorAll("input.erro, select.erro, textarea.erro").forEach((el) => {
      el.classList.remove("erro");
    });
  }

  function aplicarErroCampo(idCampo, mensagem) {
    const campo = document.getElementById(idCampo);
    const erro = form.querySelector(`[data-erro-for="${idCampo}"]`);

    if (campo) {
      const wrapper = campo.closest(".modal-campo");
      if (wrapper) wrapper.classList.add("erro");
    }

    if (erro) {
      erro.textContent = mensagem;
      erro.classList.add("ativo");
    }
  }

  function aplicarErroInput(input, mensagem) {
    if (!input) return;

    input.classList.add("erro");

    const td = input.closest("td");
    if (!td) return;

    let erro = td.querySelector("[data-js-erro-horario]");

    if (!erro) {
      erro = document.createElement("small");
      erro.dataset.jsErroHorario = "1";
      erro.className = "msg-erro ativo";
      erro.style.display = "block";
      erro.style.marginTop = "4px";
      td.appendChild(erro);
    }

    erro.textContent = mensagem;
  }

  function getHorarioInput(dia, campo) {
    return form.querySelector(`[name="horarios[${dia}][${campo}]"]`);
  }

  function getHorarioDia(dia) {
    const ativoInput = getHorarioInput(dia, "ativo");
    const disponivelInput = getHorarioInput(dia, "disponivel");

    const disponivel = ativoInput
      ? ativoInput.checked
      : disponivelInput
        ? ["1", "true", "sim", "on"].includes(String(disponivelInput.value).toLowerCase())
        : false;

    return {
      dia,
      disponivel,
      hora_inicio: String(getHorarioInput(dia, "hora_inicio")?.value ?? "").trim(),
      hora_fim: String(getHorarioInput(dia, "hora_fim")?.value ?? "").trim(),
      almoco_inicio: String(getHorarioInput(dia, "almoco_inicio")?.value ?? "").trim(),
      almoco_fim: String(getHorarioInput(dia, "almoco_fim")?.value ?? "").trim()
    };
  }

  function validarGeral() {
    const campoIntervalo = document.getElementById("cfg_intervalo_padrao");
    const campoObs = document.getElementById("cfg_obs_geral");

    const intervalo = String(campoIntervalo?.value ?? "").trim();
    const obs = String(campoObs?.value ?? "").trim();

    const intervalosPermitidos = ["10", "15", "20", "30", "45", "60"];
    let valido = true;

    if (!intervalosPermitidos.includes(intervalo)) {
      aplicarErroCampo("cfg_intervalo_padrao", "Selecione um intervalo válido.");
      valido = false;
    }

    if (obs.length > 220) {
      aplicarErroCampo("cfg_obs_geral", "A observação deve ter no máximo 220 caracteres.");
      valido = false;
    }

    return valido;
  }

  function validarHorarios() {
    let valido = true;
    let temDiaDisponivel = false;

    DIAS.forEach((dia) => {
      const h = getHorarioDia(dia);

      const inputHoraInicio = getHorarioInput(dia, "hora_inicio");
      const inputHoraFim = getHorarioInput(dia, "hora_fim");
      const inputAlmocoInicio = getHorarioInput(dia, "almoco_inicio");
      const inputAlmocoFim = getHorarioInput(dia, "almoco_fim");

      if (!h.disponivel) return;

      temDiaDisponivel = true;

      if (!h.hora_inicio) {
        aplicarErroInput(inputHoraInicio, "Informe o início.");
        valido = false;
      }

      if (!h.hora_fim) {
        aplicarErroInput(inputHoraFim, "Informe o fim.");
        valido = false;
      }

      if (h.hora_inicio && h.hora_fim && h.hora_inicio >= h.hora_fim) {
        aplicarErroInput(inputHoraFim, "Fim maior que início.");
        valido = false;
      }

      if ((h.almoco_inicio && !h.almoco_fim) || (!h.almoco_inicio && h.almoco_fim)) {
        if (!h.almoco_inicio) aplicarErroInput(inputAlmocoInicio, "Informe o início.");
        if (!h.almoco_fim) aplicarErroInput(inputAlmocoFim, "Informe o fim.");
        valido = false;
      }

      if (h.almoco_inicio && h.almoco_fim && h.almoco_inicio >= h.almoco_fim) {
        aplicarErroInput(inputAlmocoFim, "Fim maior que início.");
        valido = false;
      }

      if (
        h.hora_inicio &&
        h.hora_fim &&
        h.almoco_inicio &&
        h.almoco_fim &&
        (h.almoco_inicio <= h.hora_inicio || h.almoco_fim >= h.hora_fim)
      ) {
        aplicarErroInput(inputAlmocoInicio, "Almoço fora do expediente.");
        valido = false;
      }
    });

    if (!temDiaDisponivel) {
      mostrarAlertaSistema("warning", "Selecione pelo menos um dia disponível.");
      valido = false;
    }

    return valido;
  }

  function validarWhatsapp() {
    const ddi = String(document.getElementById("cfg_ddi_padrao")?.value ?? "").replace(/\D+/g, "");
    const ddd = String(document.getElementById("cfg_ddd_padrao")?.value ?? "").replace(/\D+/g, "");
    const msg = String(document.getElementById("cfg_msg_whats")?.value ?? "");

    let valido = true;

    if (!ddi) {
      aplicarErroCampo("cfg_ddi_padrao", "Informe o DDI.");
      valido = false;
    }

    if (ddi && (ddi.length < 1 || ddi.length > 5)) {
      aplicarErroCampo("cfg_ddi_padrao", "O DDI deve ter entre 1 e 5 dígitos.");
      valido = false;
    }

    if (ddd && ddd.length !== 2) {
      aplicarErroCampo("cfg_ddd_padrao", "O DDD deve ter 2 dígitos.");
      valido = false;
    }

    if (msg.length > 5000) {
      aplicarErroCampo("cfg_msg_whats", "A mensagem padrão deve ter no máximo 5000 caracteres.");
      valido = false;
    }

    return valido;
  }

  function validarLocal(aba) {
    limparErros();

    let valido = true;

    if (aba === "cfg-geral") valido = validarGeral();
    if (aba === "cfg-horarios") valido = validarHorarios();
    if (aba === "cfg-whatsapp") valido = validarWhatsapp();

    if (!valido) {
      mostrarAlertaSistema("warning", "Revise os campos destacados.");
    }

    return valido;
  }

  function montarPayload(aba) {
    const fd = new FormData();

    fd.append("aba", aba);
    fd.append("id_profissional", String(window.ConfigAgendaProfissional?.getId?.() || 0));

    if (aba === "cfg-geral") {
      fd.append("intervalo_padrao", String(document.getElementById("cfg_intervalo_padrao")?.value ?? "").trim());
      fd.append("observacao_padrao", String(document.getElementById("cfg_obs_geral")?.value ?? "").trim());
    }

    if (aba === "cfg-horarios") {
      DIAS.forEach((dia) => {
        const h = getHorarioDia(dia);

        fd.append(`horarios[${dia}][disponivel]`, h.disponivel ? "1" : "0");
        fd.append(`horarios[${dia}][hora_inicio]`, h.disponivel ? h.hora_inicio : "");
        fd.append(`horarios[${dia}][hora_fim]`, h.disponivel ? h.hora_fim : "");
        fd.append(`horarios[${dia}][almoco_inicio]`, h.disponivel ? h.almoco_inicio : "");
        fd.append(`horarios[${dia}][almoco_fim]`, h.disponivel ? h.almoco_fim : "");
      });
    }

    if (aba === "cfg-whatsapp") {
      fd.append("ddd_padrao", String(document.getElementById("cfg_ddd_padrao")?.value ?? "").trim());
      fd.append("ddi_padrao", String(document.getElementById("cfg_ddi_padrao")?.value ?? "").trim());
      fd.append("msg_whats", String(document.getElementById("cfg_msg_whats")?.value ?? "").trim());
    }

    return fd;
  }

  function marcarAbaAtualComoSalva() {
    const abaAtual = getAbaAtiva();

    if (abaAtual && window.ConfigAgendaTabs?.marcarAbaComoSalva) {
      window.ConfigAgendaTabs.marcarAbaComoSalva(abaAtual);
    }
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const aba = getAbaAtiva();

    if (!validarLocal(aba)) return;

    setLoading(true);

    try {
      const response = await fetch(ENDPOINT, {
        method: "POST",
        body: montarPayload(aba),
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest"
        }
      });

      const text = await response.text();

      let json;

      try {
        json = JSON.parse(text);
      } catch (e) {
        console.error("Resposta inválida do servidor:", text);
        mostrarAlertaSistema("danger", "O servidor retornou uma resposta inválida. Verifique o PHP.");
        return;
      }

      if (!response.ok || !json.ok) {
        if (json.fields && typeof json.fields === "object") {
          Object.entries(json.fields).forEach(([idCampo, msg]) => {
            aplicarErroCampo(idCampo, msg);
          });
        }

        mostrarAlertaSistema("warning", json.user_msg || "Não foi possível salvar as configurações.");
        return;
      }

      mostrarAlertaSistema("success", json.user_msg || "Configurações salvas com sucesso.");

      form.dataset.configAlterada = "0";

      marcarAbaAtualComoSalva();

      document.dispatchEvent(new CustomEvent("configProfissionalSalva", {
        detail: json.data || {}
      }));

    } catch (error) {
      console.error("Erro ao salvar configurações do profissional:", error);
      mostrarAlertaSistema("danger", "Erro de comunicação com o servidor.");
    } finally {
      setLoading(false);
    }
  });
})();
