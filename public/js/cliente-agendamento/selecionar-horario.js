// js/Cliente/Horario.js
(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const listaDias = document.getElementById("listaDias");
    const listaHorarios = document.getElementById("listaHorarios");
    const labelMesAtual = document.getElementById("labelMesAtual");

    const btnMesPrev = document.getElementById("btnMesPrev");
    const btnMesNext = document.getElementById("btnMesNext");

    const inData = document.getElementById("ag_data_iso");
    const inHora = document.getElementById("ag_hora");

    const btnVoltar = document.getElementById("btnVoltarHorario");
    const btnContinuar = document.getElementById("btnContinuarHorario");

    if (
      !listaDias || !listaHorarios || !labelMesAtual ||
      !btnMesPrev || !btnMesNext ||
      !inData || !inHora || !btnVoltar || !btnContinuar
    ) return;

    // mês exibido
    let cursor = new Date();
    cursor = new Date(cursor.getFullYear(), cursor.getMonth(), 1);

    const today = new Date();
    const minMonth = new Date(today.getFullYear(), today.getMonth(), 1);

    let selectedDayIso = "";
    let selectedHora = "";

    function pad2(n) { return String(n).padStart(2, "0"); }

    function toISO(d) {
      return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
    }

    function labelMes(d) {
      return d.toLocaleDateString("pt-BR", { month: "long", year: "numeric" })
        .replace(/^\w/, c => c.toUpperCase());
    }

    function startOfDay(d) {
      const x = new Date(d);
      x.setHours(0, 0, 0, 0);
      return x;
    }

    function getHorariosDisponiveis(isoDate) {
      return [
        "07:00", "07:30", "08:00", "08:30",
        "09:00", "09:30", "10:00", "10:30",
        "14:00", "14:30", "15:00", "15:30", "16:00", "16:30"
      ];
    }

    function buildMonthDays(dateFirstDay) {
      const y = dateFirstDay.getFullYear();
      const m = dateFirstDay.getMonth();
      const last = new Date(y, m + 1, 0).getDate();

      const today0 = startOfDay(new Date());
      const out = [];

      for (let day = 1; day <= last; day++) {
        const d = new Date(y, m, day);
        const d0 = startOfDay(d);

        const iso = toISO(d0);
        const semana = d0.toLocaleDateString("pt-BR", { weekday: "short" }).replace(".", "");
        const mes = d0.toLocaleDateString("pt-BR", { month: "short" }).replace(".", "");

        out.push({
          iso,
          semana,
          dia: day,
          mes,
          disabled: d0 < today0
        });
      }
      return out;
    }

    function syncHidden() {
      inData.value = selectedDayIso || "";
      inHora.value = selectedHora || "";
    }

    function paintDias() {
      const days = buildMonthDays(cursor);
      labelMesAtual.textContent = labelMes(cursor);

      const selectedIsDisabled = selectedDayIso
        ? days.some(x => x.iso === selectedDayIso && x.disabled)
        : false;

      if (selectedIsDisabled) {
        selectedDayIso = "";
        selectedHora = "";
        syncHidden();
      }

      listaDias.innerHTML = days.map(d => `
        <button type="button"
          class="u-dia
            ${d.iso === selectedDayIso ? "is-active" : ""}
            ${d.disabled ? "is-disabled" : ""}"
          data-iso="${d.iso}"
          ${d.disabled ? "disabled" : ""}>
          <span class="u-dia-semana">${d.semana}</span>
          <span class="u-dia-num">${d.dia}</span>
          <span class="u-dia-mes">${d.mes}</span>
        </button>
      `).join("");

      const active = listaDias.querySelector(".u-dia.is-active");
      if (active && typeof active.scrollIntoView === "function") {
        active.scrollIntoView({ behavior: "smooth", inline: "center", block: "nearest" });
      }

      btnMesPrev.disabled = (
        cursor.getFullYear() === minMonth.getFullYear() &&
        cursor.getMonth() === minMonth.getMonth()
      );
      btnMesPrev.style.opacity = btnMesPrev.disabled ? "0.45" : "";
      btnMesPrev.style.pointerEvents = btnMesPrev.disabled ? "none" : "";
    }

    function paintHorarios() {
      if (!selectedDayIso) {
        listaHorarios.innerHTML = `
          <div class="u-empty" style="opacity:.75;">
            📅 Selecione uma data para ver os horários disponíveis.
          </div>
        `;
        selectedHora = "";
        syncHidden();
        return;
      }

      const hs = getHorariosDisponiveis(selectedDayIso);

      if (!hs.length) {
        listaHorarios.innerHTML = `
          <div class="u-empty" style="opacity:.7;">
            ⚠️ Nenhum horário disponível para esta data.
          </div>
        `;
        selectedHora = "";
        syncHidden();
        return;
      }

      listaHorarios.innerHTML = hs.map(h => `
        <button type="button"
          class="u-hora ${h === selectedHora ? "is-active" : ""}"
          data-hora="${h}">
          ${h}
        </button>
      `).join("");

      syncHidden();
    }

    function selectDay(iso) {
      selectedDayIso = String(iso || "");
      selectedHora = "";
      syncHidden();
      paintDias();
      paintHorarios();
    }

    function selectHora(h) {
      if (!selectedDayIso) return;

      selectedHora = String(h || "");

      listaHorarios.querySelectorAll(".u-hora").forEach(el => {
        el.classList.toggle("is-active", el.dataset.hora === selectedHora);
      });

      syncHidden();
    }

    function changeMonth(delta) {
      const y = cursor.getFullYear();
      const m = cursor.getMonth();
      const next = new Date(y, m + delta, 1);

      if (next < minMonth) return;

      cursor = next;
      selectedDayIso = "";
      selectedHora = "";
      syncHidden();

      paintDias();
      paintHorarios();
    }

    listaDias.addEventListener("click", (e) => {
      const b = e.target.closest(".u-dia");
      if (!b) return;
      if (b.disabled) return;
      selectDay(b.dataset.iso);
    });

    listaHorarios.addEventListener("click", (e) => {
      const b = e.target.closest(".u-hora");
      if (!b) return;
      selectHora(b.dataset.hora);
    });

    btnMesPrev.addEventListener("click", () => changeMonth(-1));
    btnMesNext.addEventListener("click", () => changeMonth(+1));

    btnVoltar.addEventListener("click", () => {
      if (window.Tabs && typeof window.Tabs.go === "function") window.Tabs.go("servico");
    });

    btnContinuar.addEventListener("click", () => {
      if (!inData.value || !inHora.value) {
        alert("⚠️ Selecione uma data e um horário para continuar.");
        return;
      }
      if (window.Tabs && typeof window.Tabs.go === "function") window.Tabs.go("confirmar");
    });

    // init
    syncHidden();
    paintDias();
    paintHorarios();
  });
})();
