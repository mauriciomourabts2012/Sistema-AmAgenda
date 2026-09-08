(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const app = window.ClienteAgendamento;
    const lista = document.getElementById("listaServicos");
    const btnVoltar = document.getElementById("btnVoltarServico");
    const btnContinuar = document.getElementById("btnContinuarServico");
    const inServJson = document.getElementById("ag_servicos_json");
    const inTotal = document.getElementById("ag_servicos_total");
    if (!app || !lista || !btnVoltar || !btnContinuar || !inServJson || !inTotal) return;

    let servicos = [];
    let controller = null;

    const moneyBR = (valor) => Number(valor || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
    const duracaoLabel = (minutos) => {
      const total = Number(minutos || 0);
      if (total < 60) return `${total} min`;
      const horas = Math.floor(total / 60);
      const resto = total % 60;
      return resto ? `${horas}h ${String(resto).padStart(2, "0")}m` : `${horas}h`;
    };

    function estadoLista(texto) {
      lista.replaceChildren();
      const item = document.createElement("div");
      item.className = "u-empty";
      item.textContent = texto;
      lista.appendChild(item);
    }

    function limparSelecao(disparar = true) {
      app.estado.servico = null;
      app.estado.data = "";
      app.estado.hora = "";
      inServJson.value = "[]";
      inTotal.value = "0";
      btnContinuar.innerHTML = '<i class="fa-solid fa-arrow-right"></i> Continuar';
      btnContinuar.disabled = true;
      btnContinuar.setAttribute("aria-disabled", "true");
      if (disparar) document.dispatchEvent(new CustomEvent("cliente-agendamento:servico-alterado", { detail: null }));
    }

    function selecionar(servico, item) {
      const mesmo = String(app.estado.servico?.id_servico || "") === String(servico.id_servico);
      lista.querySelectorAll(".u-item-servico").forEach((botao) => {
        botao.classList.remove("is-active");
        botao.setAttribute("aria-pressed", "false");
        botao.querySelector(".u-badge")?.classList.remove("confirmado");
      });
      if (mesmo) {
        limparSelecao();
        return;
      }
      app.estado.servico = servico;
      app.estado.data = "";
      app.estado.hora = "";
      item.classList.add("is-active");
      item.setAttribute("aria-pressed", "true");
      item.querySelector(".u-badge")?.classList.add("confirmado");
      inServJson.value = JSON.stringify([{
        id: servico.id_servico,
        nome: servico.nome,
        preco: servico.valor,
        duracaoMin: servico.duracao_min
      }]);
      inTotal.value = String(Number(servico.valor || 0));
      btnContinuar.innerHTML = `<i class="fa-solid fa-arrow-right"></i> Continuar • ${moneyBR(servico.valor)}`;
      btnContinuar.disabled = false;
      btnContinuar.setAttribute("aria-disabled", "false");
      document.dispatchEvent(new CustomEvent("cliente-agendamento:servico-alterado", { detail: servico }));
    }

    function renderizar() {
      lista.replaceChildren();
      servicos.forEach((servico) => {
        const item = document.createElement("button");
        item.type = "button";
        item.className = "u-item u-item-servico has-badge";
        item.dataset.id = String(servico.id_servico);
        item.setAttribute("aria-pressed", "false");

        const avatar = document.createElement("span");
        avatar.className = "u-avatar is-fallback";
        const info = document.createElement("span");
        info.className = "u-info";
        const nome = document.createElement("span");
        nome.className = "u-name";
        nome.textContent = servico.nome;
        info.appendChild(nome);
        if (servico.descricao) {
          const descricao = document.createElement("span");
          descricao.className = "u-desc";
          descricao.textContent = servico.descricao;
          info.appendChild(descricao);
        }
        const badge = document.createElement("span");
        badge.className = "u-badge pendente";
        badge.setAttribute("aria-hidden", "true");
        badge.textContent = `${moneyBR(servico.valor)} • ${duracaoLabel(servico.duracao_min)}`;
        item.append(avatar, info, badge);
        item.addEventListener("click", () => selecionar(servico, item));
        lista.appendChild(item);
      });
    }

    async function carregar(profissional) {
      controller?.abort();
      controller = new AbortController();
      servicos = [];
      limparSelecao();
      if (!profissional?.id_profissional) {
        estadoLista("Selecione um profissional para ver os serviços disponíveis.");
        return;
      }
      estadoLista("Carregando serviços...");
      try {
        const query = `&id_profissional=${encodeURIComponent(profissional.id_profissional)}`;
        const json = await app.api("cliente/agendamento/servicos", { query, signal: controller.signal });
        servicos = Array.isArray(json.data?.itens) ? json.data.itens : [];
        if (!servicos.length) {
          estadoLista("Este profissional não possui serviços disponíveis no momento.");
          return;
        }
        renderizar();
      } catch (erro) {
        if (erro.name === "AbortError") return;
        estadoLista(erro.message || "Não foi possível carregar os serviços.");
        app.mensagem("erro", erro.message || "Não foi possível carregar os serviços.");
      }
    }

    document.addEventListener("cliente-agendamento:profissional-alterado", (evento) => carregar(evento.detail));
    document.addEventListener("cliente-agendamento:resetar", () => {
      controller?.abort();
      servicos = [];
      limparSelecao();
      estadoLista("Selecione um profissional para ver os serviços disponíveis.");
    });
    btnVoltar.addEventListener("click", () => window.Tabs?.go?.("profissional"));
    btnContinuar.addEventListener("click", () => {
      if (!app.estado.servico) {
        app.mensagem("aviso", "Selecione um serviço para continuar.");
        return;
      }
      window.Tabs?.go?.("horario");
    });
    estadoLista("Selecione um profissional para ver os serviços disponíveis.");
  });
})();
