// js/Cliente/MeusAgendamentos.js
(() => {
  "use strict";

  const lista  = document.getElementById("ma_lista");
  const estado = document.getElementById("ma_estado");

  if (!lista || !estado) return;

  // 🔁 depois você troca isso por fetch no PHP
  const dados = [
    {
      id: 201,
      data: "15/02/2026",
      hora: "10:00",
      servico: "Manicure",
      profissional: "Ana",
      status: "Confirmado"
    },
    {
      id: 202,
      data: "18/02/2026",
      hora: "14:30",
      servico: "Escova",
      profissional: "Bia",
      status: "Pendente"
    }
  ];

  // Normaliza o texto de status -> classe CSS
  function statusClass(statusText) {
    const s = String(statusText || "").trim().toLowerCase();

    if (s === "confirmado") return "confirmado";
    if (s === "pendente") return "pendente";
    if (s === "cancelado") return "cancelado";
    if (s === "concluído" || s === "concluido") return "concluido";

    return "neutro";
  }

  function render(listaDados) {
    lista.innerHTML = "";

    if (!listaDados.length) {
      estado.textContent = "⚠️ Você não possui agendamentos.";
      lista.hidden = true;
      return;
    }

    estado.textContent = `✅ ${listaDados.length} agendamento(s) encontrado(s).`;
    lista.hidden = false;

    lista.innerHTML = listaDados.map(a => {
      const cls = statusClass(a.status);

      return `
        <div class="ma-item" data-id="${a.id}">
          <div class="ma-top">
            <div class="ma-titulo">${a.data} • ${a.hora}</div>

            <!-- ✅ badge com cor -->
            <span class="badge-status ${cls}">${a.status}</span>
          </div>

          <div class="ma-sub">
            <div><strong>Serviço:</strong> ${a.servico}</div>
            <div><strong>Profissional:</strong> ${a.profissional}</div>
          </div>

          <div class="ma-acoes">
            <button class="botao-geral pequeno"
                    type="button"
                    data-acao="cancelar"
                    data-id="${a.id}">
              <i class="fa-solid fa-ban"></i>
              Cancelar
            </button>
          </div>
        </div>
      `;
    }).join("");
  }

  // Clique em cancelar
  lista.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-acao='cancelar']");
    if (!btn) return;

    const id = btn.dataset.id;

    const ok = window.confirm(
      `⚠️ Tem certeza que deseja cancelar o agendamento #${id}?`
    );

    if (!ok) return;

    // 🔗 aqui você liga no PHP depois
    window.alert(`✅ Agendamento #${id} cancelado.`);
  });

  // render inicial
  render(dados);

})();
