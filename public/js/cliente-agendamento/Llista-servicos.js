// js/Cliente/Servicos.js
(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const lista = document.getElementById("listaServicos");
    const btnVoltar = document.getElementById("btnVoltarServico");
    const btnContinuar = document.getElementById("btnContinuarServico");

    const inServJson = document.getElementById("ag_servicos_json");
    const inTotal = document.getElementById("ag_servicos_total");

    if (!lista || !btnVoltar || !btnContinuar || !inServJson || !inTotal) return;

    // 🔁 depois troca por fetch
    const servicos = [
      {
        id: 11,
        nome: "Avaliação Para Micropigmentação",
        desc: "Consulta personalizada para analisar o formato ideal e as expectativas.",
        foto: "/img/servicos/micro.jpg",
        preco: 0,
        duracaoMin: 30
      },
      {
        id: 12,
        nome: "Browlamination",
        desc: "Alinhamento e fixação dos fios das sobrancelhas.",
        foto: "/img/servicos/brow.jpg",
        preco: 95,
        duracaoMin: 60
      },
      {
        id: 13,
        nome: "Cílios Efeito Rímel (fio a fio)",
        desc: "Aplicação de fios sintéticos para efeito natural e alongado.",
        foto: "/img/servicos/cilios.jpg",
        preco: 125,
        duracaoMin: 120
      }
    ];

    // ✅ seleção única
    let selectedId = null;

    function escapeHtml(s) {
      return String(s ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
    }

    function moneyBR(v) {
      const n = Number(v || 0);
      return n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
    }

    function duracaoLabel(min) {
      const m = Number(min || 0);
      if (!m) return "";
      if (m < 60) return `${m} min`;
      const h = Math.floor(m / 60);
      const r = m % 60;
      return r ? `${h}h ${String(r).padStart(2, "0")}m` : `${h}h`;
    }

    function getSelectedServico() {
      return servicos.find(x => String(x.id) === String(selectedId)) || null;
    }

    // ✅ NÃO usa disabled (pra alert funcionar sempre)
    function updateHiddenAndButtonText() {
      const s = getSelectedServico();

      if (!s) {
        inServJson.value = "[]";
        inTotal.value = "0";
        btnContinuar.innerHTML = `<i class="fa-solid fa-arrow-right"></i> Continuar`;
        return;
      }

      const payload = [{ id: s.id, nome: s.nome, preco: s.preco, duracaoMin: s.duracaoMin }];
      inServJson.value = JSON.stringify(payload);
      inTotal.value = String(Number(s.preco || 0));

      btnContinuar.innerHTML =
        `<i class="fa-solid fa-arrow-right"></i> Continuar • ${moneyBR(s.preco || 0)}`;
    }

    function paintSelection() {
      [...lista.querySelectorAll(".u-item-servico")].forEach(btn => {
        const ativo = btn.dataset.id === String(selectedId);
        btn.classList.toggle("is-active", ativo);
        btn.setAttribute("aria-pressed", ativo ? "true" : "false");

        const badge = btn.querySelector(".u-badge");
        if (badge) badge.classList.toggle("is-selected", ativo);
      });
    }

    function markSelected(id) {
      if (String(selectedId) === String(id)) selectedId = null;
      else selectedId = id;

      paintSelection();
      updateHiddenAndButtonText();
    }

    function render(items) {
      if (!items || !items.length) {
        lista.innerHTML = `
          <div class="u-empty">
            ⚠️ Nenhum serviço disponível no momento.
          </div>
        `;
        selectedId = null;
        updateHiddenAndButtonText();
        return;
      }

      lista.innerHTML = items.map(s => {
        const nome = escapeHtml(s.nome);
        const desc = escapeHtml(s.desc || "");
        const foto = escapeHtml(s.foto || "");
        const precoTxt = moneyBR(Number(s.preco || 0));
        const durTxt = duracaoLabel(s.duracaoMin);

        const isActive = String(s.id) === String(selectedId);
        const badgeTxt = `${precoTxt}${durTxt ? ` • ${durTxt}` : ""}`;

        return `
          <button
            type="button"
            class="u-item u-item-servico has-badge ${isActive ? "is-active" : ""}"
            data-id="${s.id}"
            aria-pressed="${isActive ? "true" : "false"}"
          >
            <span class="u-avatar">
              <img src="${foto}" alt=""
                   loading="lazy"
                   onerror="this.style.display='none'; this.closest('.u-avatar').classList.add('is-fallback')">
            </span>

            <span class="u-info">
              <span class="u-name">${nome}</span>
              ${desc ? `<span class="u-desc">${desc}</span>` : ``}
            </span>

            <span class="u-badge ${isActive ? "confirmado" : "pendente"}" aria-hidden="true">
              ${badgeTxt}
            </span>
          </button>
        `;
      }).join("");

      updateHiddenAndButtonText();
    }

    // clique = seleciona 1 (toggle)
    lista.addEventListener("click", (e) => {
      const item = e.target.closest(".u-item-servico");
      if (!item) return;
      markSelected(item.dataset.id);
    });

    btnVoltar.addEventListener("click", () => {
      if (window.Tabs && typeof window.Tabs.go === "function") window.Tabs.go("profissional");
    });

    btnContinuar.addEventListener("click", () => {
      if (!selectedId) {
        alert("⚠️ Selecione 1 serviço para continuar.");
        return;
      }
      if (window.Tabs && typeof window.Tabs.go === "function") window.Tabs.go("horario");
    });

    render(servicos);
  });
})();
