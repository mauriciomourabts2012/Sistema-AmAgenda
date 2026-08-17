// js/Cliente/ListaProfissionais.js
(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {

    const lista = document.getElementById("listaProfissionais");
    const btnContinuar = document.getElementById("btnContinuarProfissional");

    const inId = document.getElementById("ag_profissional_id");
    const inNome = document.getElementById("ag_profissional_nome");

    if (!lista || !btnContinuar || !inId || !inNome) return;

    // mock
    const profissionais = [
      { id: 1, nome: "Ana Cláudia Batista", desc: "Unhas e estética", foto: "../assets/img/avatar-default.png" },
      { id: 2, nome: "Elen Soares", desc: "Cabelo e tratamentos", foto: "../assets/img/avatar-default.png" },
      { id: 3, nome: "Mariana Lima", desc: "Design de sobrancelhas", foto: "../assets/img/avatar-default.png" }
    ];

    function limparSelecao() {
      lista.querySelectorAll(".u-item.is-active").forEach(el => {
        el.classList.remove("is-active");
        el.setAttribute("aria-pressed", "false");
      });
    }

    function selecionar(id, nome, el) {
      inId.value = id;
      inNome.value = nome;

      limparSelecao();
      el.classList.add("is-active");
      el.setAttribute("aria-pressed", "true");
    }

    function render() {
      lista.innerHTML = "";

      profissionais.forEach(p => {
        const item = document.createElement("button");
        item.type = "button";
        item.className = "u-item";
        item.setAttribute("aria-pressed", "false");

        item.innerHTML = `
          <div class="u-avatar">
           <img src="..."
            onerror="this.style.display='none'; this.parentNode.classList.add('is-fallback');">
          </div>
          <div class="u-info">
            <div class="u-name">${p.nome}</div>
            <div class="u-desc">${p.desc}</div>
          </div>
          <div class="u-check"><i class="fa-solid fa-check"></i></div>
        `;

        item.addEventListener("click", () => selecionar(p.id, p.nome, item));
        lista.appendChild(item);
      });
    }

    btnContinuar.addEventListener("click", () => {
      if (!inId.value) {
        alert("⚠️ Selecione um profissional para continuar.");
        return;
      }

      if (window.Tabs && typeof window.Tabs.go === "function") {
        window.Tabs.go("servico");
      }
    });

    render();
  });
})();
