/* Exclui uma ocorrência ou um trecho da recorrência pela API central. */
(() => {
  "use strict";

  const API = "/public/api/api_central.php?path=agenda/agendamento/excluir";
  let processando = false;
  const texto = (valor) => String(valor ?? "").trim();

  function stackAlertas() {
    let stack = document.querySelector(".ui-toast-stack");
    if (!stack) {
      stack = document.createElement("div");
      stack.className = "ui-toast-stack";
      stack.setAttribute("aria-live", "polite");
      document.body.appendChild(stack);
    }
    return stack;
  }

  function fechar(alerta) {
    alerta?.classList.add("is-leaving");
    setTimeout(() => alerta?.remove(), 180);
  }

  function mensagem(tipo, titulo, conteudo) {
    const alerta = document.createElement("div");
    alerta.className = `ui-alert ui-alert--${tipo}`;
    alerta.innerHTML = `<div class="ui-alert__icon">${tipo === "success" ? "✓" : "×"}</div><div class="ui-alert__content"><p class="ui-alert__title"></p><div class="ui-alert__msg"></div></div>`;
    alerta.querySelector(".ui-alert__title").textContent = titulo;
    alerta.querySelector(".ui-alert__msg").textContent = conteudo;
    stackAlertas().appendChild(alerta);
    setTimeout(() => fechar(alerta), 4200);
  }

  function escolherEscopo(recorrente) {
    return new Promise((resolve) => {
      const alerta = document.createElement("div");
      alerta.className = "ui-alert ui-alert--confirm agenda-exclusao-confirmacao";
      alerta.setAttribute("role", "dialog");
      alerta.setAttribute("aria-modal", "true");
      alerta.innerHTML = `
        <div class="ui-alert__icon" aria-hidden="true">?</div>
        <div class="ui-alert__content">
          <p class="ui-alert__title">Excluir agendamento</p>
          <div class="ui-alert__msg">${recorrente ? "Escolha quais ocorrências deseja excluir:" : "Deseja excluir este agendamento?"}</div>
          ${recorrente ? `<div class="agenda-exclusao-opcoes">
            <label><input type="radio" name="agenda_exclusao_escopo" value="somente_este" checked> <span><strong>Somente este</strong><small>Exclui apenas a ocorrência selecionada.</small></span></label>
            <label><input type="radio" name="agenda_exclusao_escopo" value="este_e_proximos"> <span><strong>Este e os próximos</strong><small>Mantém as ocorrências anteriores.</small></span></label>
            <label><input type="radio" name="agenda_exclusao_escopo" value="toda_recorrencia"> <span><strong>Toda a recorrência</strong><small>Exclui todas as ocorrências da série.</small></span></label>
          </div>` : ""}
          <div class="ui-alert__actions"><button type="button" class="ui-alert__btn" data-cancelar>Cancelar</button><button type="button" class="ui-alert__btn ui-alert__btn--primary" data-confirmar>Excluir</button></div>
        </div>`;
      stackAlertas().appendChild(alerta);

      const concluir = (valor) => { fechar(alerta); resolve(valor); };
      alerta.querySelector("[data-cancelar]").addEventListener("click", () => concluir(null));
      alerta.querySelector("[data-confirmar]").addEventListener("click", () => concluir(recorrente ? alerta.querySelector('input[name="agenda_exclusao_escopo"]:checked')?.value : "somente_este"));
      alerta.querySelector("[data-confirmar]").focus();
    });
  }

  async function excluir(idAgendamento, escopo) {
    const dados = new FormData();
    dados.append("id_agendamento", String(idAgendamento));
    dados.append("escopo", escopo);
    const resposta = await fetch(API, { method: "POST", body: dados, credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } });
    const json = await resposta.json().catch(() => null);
    if (!resposta.ok || !json?.ok) throw new Error(json?.user_msg || "Não foi possível excluir o agendamento.");
    return json;
  }

  document.addEventListener("agenda:excluir:selecionado", async (evento) => {
    if (processando) return;
    const agendamento = evento.detail?.agendamento || {};
    const id = Number(evento.detail?.id_agendamento || agendamento.id_agendamento || agendamento.id || 0);
    if (!id) { mensagem("danger", "Atenção", "Agendamento inválido."); return; }

    const recorrente = texto(agendamento.grupo_recorrencia) !== "";
    const escopo = await escolherEscopo(recorrente);
    if (!escopo) return;

    processando = true;
    try {
      const resultado = await excluir(id, escopo);
      mensagem("success", "Sucesso", resultado.user_msg || "Agendamento excluído com sucesso.");
      document.dispatchEvent(new CustomEvent("agenda:agendamento:excluido", { detail: resultado.data }));
    } catch (erro) {
      mensagem("danger", "Atenção", erro.message || "Não foi possível excluir o agendamento.");
    } finally {
      processando = false;
    }
  });
})();
