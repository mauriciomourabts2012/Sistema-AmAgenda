// Salvar o formulário Novo Agendamento pela API central.
(() => {
  "use strict";

  const API_URL = "/public/api/api_central.php?path=agenda/agendamento/cadastrar";
  const form = document.getElementById("formNovoAgendamento");
  const modal = document.getElementById("modalNovoAgendamento");
  const btnSalvar = document.getElementById("btnSalvarAgendamento");
  const feedbackForm = document.getElementById("ag_form_feedback");

  if (!form || !btnSalvar) return;

  const campos = {
    cliente: document.getElementById("ag_cliente_id"),
    profissional: document.getElementById("ag_profissional"),
    servico: document.getElementById("ag_servico"),
    data: document.getElementById("ag_data"),
    hora: document.getElementById("ag_hora"),
    status: document.getElementById("ag_status"),
    observacao: document.getElementById("ag_obs"),
    repetir: document.getElementById("ag_repetir_semanal"),
    recorrenciaBox: document.getElementById("ag_recorrencia_config"),
    recorrenciaFim: document.getElementById("ag_recorrencia_data_fim"),
    preview: document.getElementById("ag_repetir_preview")
  };

  const texto = (valor) => String(valor ?? "").trim();

  function feedback(tipo, mensagem) {
    let stack = document.querySelector(".ui-toast-stack");
    if (!stack) {
      stack = document.createElement("div");
      stack.className = "ui-toast-stack";
      document.body.appendChild(stack);
    }

    const alerta = document.createElement("div");
    const sucesso = tipo === "success";
    alerta.className = `ui-alert ${sucesso ? "ui-alert--success" : "ui-alert--danger"}`;
    alerta.setAttribute("role", "alert");
    alerta.innerHTML = `
      <div class="ui-alert__icon" aria-hidden="true">${sucesso ? "✓" : "×"}</div>
      <div class="ui-alert__content">
        <p class="ui-alert__title">${sucesso ? "Sucesso" : "Atenção"}</p>
        <div class="ui-alert__msg"></div>
      </div>
    `;
    alerta.querySelector(".ui-alert__msg").textContent = mensagem;
    stack.appendChild(alerta);
    setTimeout(() => alerta.remove(), sucesso ? 2600 : 5000);
  }

  function setCarregando(ativo) {
    btnSalvar.disabled = ativo;
    btnSalvar.dataset.textoOriginal ||= btnSalvar.innerHTML;
    btnSalvar.innerHTML = ativo
      ? '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Salvando...'
      : btnSalvar.dataset.textoOriginal;
  }

  function mensagemNoFormulario(tipo, mensagem, fields = {}) {
    if (!feedbackForm) return;
    const detalhes = Object.values(fields || {}).filter(Boolean);
    feedbackForm.className = `ag-form-feedback ${tipo === "success" ? "sucesso" : "erro"}`;
    feedbackForm.innerHTML = "";
    const titulo = document.createElement("strong");
    titulo.textContent = mensagem;
    feedbackForm.appendChild(titulo);
    if (detalhes.length) {
      const lista = document.createElement("ul");
      detalhes.forEach((detalhe) => { const item = document.createElement("li"); item.textContent = detalhe; lista.appendChild(item); });
      feedbackForm.appendChild(lista);
    }
    feedbackForm.hidden = false;
    feedbackForm.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function limparMensagemFormulario() {
    if (!feedbackForm) return;
    feedbackForm.hidden = true;
    feedbackForm.textContent = "";
  }

  function atualizarRecorrencia() {
    const repetir = campos.repetir?.checked === true;
    const dataBaseTexto = texto(campos.data?.value) || new Date().toISOString().slice(0, 10);
    if (campos.recorrenciaBox) campos.recorrenciaBox.hidden = !repetir;
    if (campos.recorrenciaFim) {
      campos.recorrenciaFim.required = repetir;
      campos.recorrenciaFim.min = dataBaseTexto;
      const dataMaxima = new Date(`${dataBaseTexto}T12:00:00`);
      dataMaxima.setFullYear(dataMaxima.getFullYear() + 1);
      campos.recorrenciaFim.max = dataMaxima.toISOString().slice(0, 10);
      if (!repetir) campos.recorrenciaFim.value = "";
    }
    if (campos.preview) {
      campos.preview.style.display = repetir ? "block" : "none";
      campos.preview.textContent = repetir
        ? "Escolha a data final da repetição semanal."
        : "";
    }
  }

  function validar() {
    if (!texto(campos.cliente?.value)) return "Selecione um cliente.";
    if (!texto(campos.profissional?.value)) return "Selecione um profissional.";
    if (!texto(campos.servico?.value) || campos.servico.value === "__novo_servico__") return "Selecione um serviço.";
    if (!texto(campos.data?.value)) return "Selecione uma data disponível no calendário.";
    if (!texto(campos.hora?.value)) return "Selecione um horário disponível.";
    if (campos.repetir?.checked && !texto(campos.recorrenciaFim?.value)) return "Informe até quando o agendamento será repetido.";
    if (campos.repetir?.checked && campos.recorrenciaFim.value > campos.recorrenciaFim.max) return "A repetição semanal pode abranger no máximo um ano.";
    if (texto(campos.observacao?.value).length > 220) return "A observação deve ter no máximo 220 caracteres.";
    return "";
  }

  campos.repetir?.addEventListener("change", atualizarRecorrencia);
  campos.data?.addEventListener("change", atualizarRecorrencia);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    limparMensagemFormulario();

    const erro = validar();
    if (erro) {
      feedback("danger", erro);
      mensagemNoFormulario("error", erro);
      return;
    }

    const payload = new FormData();
    payload.append("id_cliente", texto(campos.cliente.value));
    payload.append("id_profissional", texto(campos.profissional.value));
    payload.append("id_servico", texto(campos.servico.value));
    payload.append("data_agendamento", texto(campos.data.value));
    payload.append("hora_inicio", texto(campos.hora.value));
    payload.append("status", texto(campos.status?.value || "confirmado"));
    payload.append("observacao", texto(campos.observacao?.value));
    payload.append("repetir_semanalmente", campos.repetir?.checked ? "1" : "0");
    payload.append("recorrencia_data_fim", campos.repetir?.checked ? texto(campos.recorrenciaFim?.value) : "");

    setCarregando(true);
    try {
      const resposta = await fetch(API_URL, {
        method: "POST",
        body: payload,
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store"
      });
      const json = await resposta.json().catch(() => null);

      if (!resposta.ok || !json || json.ok === false) {
        const mensagem = json?.user_msg || json?.mensagem || "Não foi possível salvar o agendamento.";
        console.error("[cadastrar-agendamento] API", resposta.status, json);
        feedback("danger", mensagem);
        mensagemNoFormulario("error", mensagem, json?.fields || {});
        return;
      }

      const quantidade = Number(json?.data?.quantidade || 1);
      feedback("success", quantidade > 1
        ? `${quantidade} agendamentos foram criados.`
        : "Agendamento criado com sucesso.");
      mensagemNoFormulario("success", quantidade > 1
        ? `${quantidade} agendamentos semanais foram criados com sucesso.`
        : "Agendamento criado com sucesso.");

      document.dispatchEvent(new CustomEvent("agenda:agendamento:cadastrado", { detail: json.data || {} }));
      form.reset();
      atualizarRecorrencia();
      setTimeout(() => {
        if (typeof window.fecharModal === "function") window.fecharModal("modalNovoAgendamento");
        else modal?.querySelector("[data-fechar-modal]")?.click();
        window.location.reload();
      }, 1400);
    } catch (error) {
      console.error("[cadastrar-agendamento]", error);
      feedback("danger", "Falha de comunicação ao salvar o agendamento.");
      mensagemNoFormulario("error", "Falha de comunicação ao salvar o agendamento.");
    } finally {
      setCarregando(false);
    }
  });

  form.addEventListener("reset", () => setTimeout(atualizarRecorrencia, 0));
  atualizarRecorrencia();
})();
