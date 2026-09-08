(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const app = window.ClienteAgendamento;
    const listaDias = document.getElementById("listaDias");
    const listaHorarios = document.getElementById("listaHorarios");
    const labelMesAtual = document.getElementById("labelMesAtual");
    const btnMesPrev = document.getElementById("btnMesPrev");
    const btnMesNext = document.getElementById("btnMesNext");
    const inData = document.getElementById("ag_data_iso");
    const inHora = document.getElementById("ag_hora");
    const btnVoltar = document.getElementById("btnVoltarHorario");
    const btnContinuar = document.getElementById("btnContinuarHorario");
    if (!app || !listaDias || !listaHorarios || !labelMesAtual || !btnMesPrev || !btnMesNext || !inData || !inHora || !btnVoltar || !btnContinuar) return;

    const hoje = new Date();
    let cursor = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    const mesMinimo = new Date(cursor);
    let mesMaximo = new Date(hoje.getFullYear(), hoje.getMonth() + 3, 1);
    let datasDisponiveis = new Set();
    let controllerDias = null;
    let controllerHorarios = null;
    let sequenciaDias = 0;
    let sequenciaHorarios = 0;

    const pad2 = (valor) => String(valor).padStart(2, "0");
    const toISO = (data) => `${data.getFullYear()}-${pad2(data.getMonth() + 1)}-${pad2(data.getDate())}`;
    const labelMes = (data) => data.toLocaleDateString("pt-BR", { month: "long", year: "numeric" }).replace(/^\w/, (c) => c.toUpperCase());

    function estado(container, texto) {
      container.replaceChildren();
      const item = document.createElement("div");
      item.className = "u-empty";
      item.textContent = texto;
      container.appendChild(item);
    }

    function sincronizar() {
      inData.value = app.estado.data || "";
      inHora.value = app.estado.hora || "";
      const valido = Boolean(app.estado.data && app.estado.hora);
      btnContinuar.disabled = !valido;
      btnContinuar.setAttribute("aria-disabled", valido ? "false" : "true");
    }

    function limpar() {
      controllerDias?.abort();
      controllerHorarios?.abort();
      app.estado.data = "";
      app.estado.hora = "";
      datasDisponiveis = new Set();
      sincronizar();
      estado(listaHorarios, "Selecione uma data para ver os horários disponíveis.");
    }

    function pintarDias() {
      listaDias.replaceChildren();
      labelMesAtual.textContent = labelMes(cursor);
      const ultimo = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
      for (let dia = 1; dia <= ultimo; dia += 1) {
        const data = new Date(cursor.getFullYear(), cursor.getMonth(), dia);
        const iso = toISO(data);
        const disponivel = datasDisponiveis.has(iso);
        const botao = document.createElement("button");
        botao.type = "button";
        botao.className = `u-dia${iso === app.estado.data ? " is-active" : ""}${disponivel ? "" : " is-disabled"}`;
        botao.dataset.iso = iso;
        botao.disabled = !disponivel;
        const semana = document.createElement("span");
        semana.className = "u-dia-semana";
        semana.textContent = data.toLocaleDateString("pt-BR", { weekday: "short" }).replace(".", "");
        const numero = document.createElement("span");
        numero.className = "u-dia-num";
        numero.textContent = String(dia);
        const mes = document.createElement("span");
        mes.className = "u-dia-mes";
        mes.textContent = data.toLocaleDateString("pt-BR", { month: "short" }).replace(".", "");
        botao.append(semana, numero, mes);
        listaDias.appendChild(botao);
      }
      btnMesPrev.disabled = cursor <= mesMinimo;
      btnMesNext.disabled = cursor >= mesMaximo;
    }

    async function carregarDias() {
      limpar();
      if (!app.estado.profissional || !app.estado.servico) {
        estado(listaDias, "Selecione um profissional e um serviço para consultar as datas.");
        return;
      }
      controllerDias = new AbortController();
      const atual = ++sequenciaDias;
      estado(listaDias, "Consultando datas disponíveis...");
      labelMesAtual.textContent = labelMes(cursor);
      try {
        const query = `&operacao=dias&id_profissional=${encodeURIComponent(app.estado.profissional.id_profissional)}&id_servico=${encodeURIComponent(app.estado.servico.id_servico)}&ano=${cursor.getFullYear()}&mes=${cursor.getMonth() + 1}`;
        const json = await app.api("cliente/agendamento/disponibilidade", { query, signal: controllerDias.signal });
        if (atual !== sequenciaDias) return;
        datasDisponiveis = new Set(Array.isArray(json.data?.datas_disponiveis) ? json.data.datas_disponiveis : []);
        const limite = String(json.data?.data_maxima || "");
        if (/^\d{4}-\d{2}-\d{2}$/.test(limite)) {
          const [ano, mes] = limite.split("-").map(Number);
          mesMaximo = new Date(ano, mes - 1, 1);
        }
        pintarDias();
        if (!datasDisponiveis.size) estado(listaHorarios, "Não existem horários disponíveis neste mês.");
      } catch (erro) {
        if (erro.name === "AbortError") return;
        estado(listaDias, erro.message || "Não foi possível consultar as datas.");
        app.mensagem("erro", erro.message || "Não foi possível consultar as datas.");
      }
    }

    async function selecionarData(iso) {
      if (!datasDisponiveis.has(iso) || !app.estado.profissional || !app.estado.servico) return;
      controllerHorarios?.abort();
      controllerHorarios = new AbortController();
      const atual = ++sequenciaHorarios;
      app.estado.data = iso;
      app.estado.hora = "";
      sincronizar();
      pintarDias();
      estado(listaHorarios, "Consultando horários disponíveis...");
      try {
        const query = `&operacao=horarios&id_profissional=${encodeURIComponent(app.estado.profissional.id_profissional)}&id_servico=${encodeURIComponent(app.estado.servico.id_servico)}&data=${encodeURIComponent(iso)}`;
        const json = await app.api("cliente/agendamento/disponibilidade", { query, signal: controllerHorarios.signal });
        if (atual !== sequenciaHorarios || app.estado.data !== iso) return;
        const horarios = Array.isArray(json.data?.horarios) ? json.data.horarios : [];
        listaHorarios.replaceChildren();
        if (!horarios.length) {
          estado(listaHorarios, "Nenhum horário disponível para esta data.");
          return;
        }
        horarios.forEach((horario) => {
          const botao = document.createElement("button");
          botao.type = "button";
          botao.className = "u-hora";
          botao.dataset.hora = horario.hora_inicio;
          botao.textContent = horario.hora_inicio;
          listaHorarios.appendChild(botao);
        });
      } catch (erro) {
        if (erro.name === "AbortError") return;
        estado(listaHorarios, erro.message || "Não foi possível consultar os horários.");
        app.mensagem("erro", erro.message || "Não foi possível consultar os horários.");
      }
    }

    listaDias.addEventListener("click", (evento) => {
      const botao = evento.target.closest(".u-dia");
      if (botao && !botao.disabled) selecionarData(botao.dataset.iso);
    });
    listaHorarios.addEventListener("click", (evento) => {
      const botao = evento.target.closest(".u-hora");
      if (!botao || !app.estado.data) return;
      app.estado.hora = botao.dataset.hora || "";
      listaHorarios.querySelectorAll(".u-hora").forEach((item) => item.classList.toggle("is-active", item === botao));
      sincronizar();
    });
    btnMesPrev.addEventListener("click", () => {
      if (cursor <= mesMinimo) return;
      cursor = new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1);
      carregarDias();
    });
    btnMesNext.addEventListener("click", () => {
      if (cursor >= mesMaximo) return;
      cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
      carregarDias();
    });
    btnVoltar.addEventListener("click", () => window.Tabs?.go?.("servico"));
    btnContinuar.addEventListener("click", () => {
      if (!app.estado.data || !app.estado.hora) {
        app.mensagem("aviso", "Selecione uma data e um horário para continuar.");
        return;
      }
      document.dispatchEvent(new CustomEvent("cliente-agendamento:revisar"));
      window.Tabs?.go?.("confirmar");
    });
    document.addEventListener("cliente-agendamento:profissional-alterado", () => {
      limpar();
      estado(listaDias, "Selecione um serviço para consultar as datas.");
    });
    document.addEventListener("cliente-agendamento:servico-alterado", () => {
      cursor = new Date(mesMinimo);
      carregarDias();
    });
    document.addEventListener("cliente-agendamento:atualizar-horarios", (evento) => {
      const data = String(evento.detail?.data || "");
      if (data && datasDisponiveis.has(data)) selecionarData(data);
      else carregarDias();
    });
    document.addEventListener("cliente-agendamento:resetar", () => {
      cursor = new Date(mesMinimo);
      limpar();
      estado(listaDias, "Selecione um profissional e um serviço para consultar as datas.");
    });

    sincronizar();
    estado(listaDias, "Selecione um profissional e um serviço para consultar as datas.");
    estado(listaHorarios, "Selecione uma data para ver os horários disponíveis.");
  });
})();
