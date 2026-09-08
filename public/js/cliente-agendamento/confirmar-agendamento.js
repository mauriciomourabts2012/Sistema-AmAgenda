(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const app = window.ClienteAgendamento;
    const box = document.getElementById("boxResumoAgendamento");
    const btnVoltar = document.getElementById("btnVoltarConfirmar");
    const btnAgendar = document.getElementById("btnAgendarFinal");
    const inObs = document.getElementById("ag_obs");
    if (!app || !box || !btnVoltar || !btnAgendar || !inObs) return;

    let enviando = false;
    const moneyBR = (valor) => Number(valor || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });

    function formatarDataHora(iso, hora) {
      if (!iso || !hora) return "—";
      const [ano, mes, dia] = iso.split("-").map(Number);
      const data = new Date(ano, mes - 1, dia);
      const semana = data.toLocaleDateString("pt-BR", { weekday: "short" }).replace(".", "");
      return `${semana}, ${String(dia).padStart(2, "0")}/${String(mes).padStart(2, "0")}/${ano} às ${hora}`;
    }

    function linha(rotulo, valor, preco = "") {
      const container = document.createElement("div");
      container.className = "u-resumo-linha";
      const label = document.createElement("div");
      label.className = "u-resumo-label";
      label.textContent = rotulo;
      const row = document.createElement("div");
      row.className = preco ? "u-resumo-row" : "u-resumo-valor";
      if (preco) {
        const texto = document.createElement("div");
        texto.className = "u-resumo-valor";
        texto.textContent = valor;
        const total = document.createElement("div");
        total.className = "u-resumo-preco";
        total.textContent = preco;
        row.append(texto, total);
      } else {
        row.textContent = valor;
      }
      container.append(label, row);
      return container;
    }

    function renderizar() {
      const profissional = app.estado.profissional;
      const servico = app.estado.servico;
      box.replaceChildren(
        linha("Profissional:", profissional?.nome || "—"),
        linha("Data e Hora:", formatarDataHora(app.estado.data, app.estado.hora)),
        linha("Serviço:", servico?.nome || "—", moneyBR(servico?.valor || 0))
      );
      const valido = Boolean(profissional && servico && app.estado.data && app.estado.hora);
      btnAgendar.disabled = !valido;
      btnAgendar.setAttribute("aria-disabled", valido ? "false" : "true");
    }

    function setEnviando(ativo) {
      enviando = ativo;
      btnAgendar.disabled = ativo || !(app.estado.profissional && app.estado.servico && app.estado.data && app.estado.hora);
      btnAgendar.dataset.textoOriginal ||= btnAgendar.innerHTML;
      btnAgendar.innerHTML = ativo
        ? '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Agendando...'
        : btnAgendar.dataset.textoOriginal;
    }

    function atualizarAposConflito() {
      const dataSelecionada = app.estado.data;
      app.estado.hora = "";
      document.getElementById("ag_hora").value = "";
      document.dispatchEvent(new CustomEvent("cliente-agendamento:atualizar-horarios", { detail: { data: dataSelecionada } }));
      window.Tabs?.go?.("horario");
    }

    btnVoltar.addEventListener("click", () => window.Tabs?.go?.("horario"));
    btnAgendar.addEventListener("click", async () => {
      if (enviando) return;
      const { profissional, servico, data, hora, csrfToken } = app.estado;
      if (!profissional || !servico || !data || !hora) {
        app.mensagem("aviso", "Revise profissional, serviço, data e horário antes de agendar.");
        return;
      }
      if (!csrfToken) {
        app.mensagem("erro", "Sua sessão de agendamento expirou. Atualize a página e tente novamente.");
        return;
      }
      const body = new FormData();
      body.append("id_profissional", String(profissional.id_profissional));
      body.append("id_servico", String(servico.id_servico));
      body.append("data", data);
      body.append("hora", hora);
      body.append("observacao", String(inObs.value || "").trim());
      setEnviando(true);
      try {
        const json = await app.api("cliente/agendamento/confirmar", {
          method: "POST",
          body,
          headers: { "X-CSRF-Token": csrfToken }
        });
        app.mensagem("sucesso", json.user_msg || "Solicitação enviada. O agendamento aguarda confirmação do profissional.");
        inObs.value = "";
        document.dispatchEvent(new CustomEvent("cliente-agendamento:resetar"));
        renderizar();
        window.Tabs?.go?.("profissional");
      } catch (erro) {
        if (erro.status === 409 && ["SCHEDULE_CONFLICT", "SCHEDULE_BUSY"].includes(erro.code)) {
          app.mensagem("aviso", erro.message || "O horário acabou de ser ocupado.");
          atualizarAposConflito();
          return;
        }
        app.mensagem("erro", erro.message || "Não foi possível concluir o agendamento.");
      } finally {
        setEnviando(false);
      }
    });

    document.addEventListener("cliente-agendamento:revisar", renderizar);
    document.addEventListener("cliente-agendamento:profissional-alterado", renderizar);
    document.addEventListener("cliente-agendamento:servico-alterado", renderizar);
    document.addEventListener("cliente-agendamento:resetar", renderizar);
    renderizar();
  });
})();
