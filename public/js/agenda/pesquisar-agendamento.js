/* Pesquisa toda a tabela de agendamentos e navega até a ocorrência escolhida. */
(() => {
  "use strict";

  const input = document.getElementById("pesquisar-agenda-global");
  const painel = document.getElementById("agendaPesquisaResultados");
  const lista = document.getElementById("agendaPesquisaLista");
  const paginacao = document.getElementById("agendaPesquisaPaginacao");
  const fechar = document.getElementById("agendaPesquisaFechar");
  const info = document.querySelector(".agenda-pesquisa-global .pesquisa-info");
  const limpar = document.querySelector(".agenda-pesquisa-global .btn-limpar-pesquisa");
  if (!input || !painel || !lista || !paginacao) return;

  const API = "/public/api/api_central.php?path=agenda/agendamento/pesquisar";
  let timer = null;
  let abortador = null;
  let paginaAtual = 1;
  let termoAtual = "";
  const esc = (valor) => String(valor ?? "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
  const dataBr = (iso) => String(iso || "").split("-").reverse().join("/");

  // No celular o cabeçalho pode variar de altura. A posição é calculada pela
  // barra real, evitando que os resultados fiquem escondidos atrás dela.
  function posicionarPainelMobile() {
    if (!window.matchMedia("(max-width: 768px)").matches) {
      painel.style.removeProperty("--agenda-pesquisa-top");
      return;
    }
    const barra = document.querySelector(".agenda-ferramentas-global");
    const cabecalho = document.querySelector(".cabecalho-agenda");
    const limite = Math.max(90, window.innerHeight - 190);
    const base = Math.max(
      barra?.getBoundingClientRect().bottom || 70,
      cabecalho?.getBoundingClientRect().bottom || 0
    );
    const topo = Math.min(Math.max(12, base + 8), limite);
    painel.style.setProperty("--agenda-pesquisa-top", `${Math.round(topo)}px`);
  }

  function ocultar() {
    painel.hidden = true;
    lista.innerHTML = "";
    paginacao.innerHTML = "";
    if (info) info.textContent = "";
  }

  function estado(mensagem) {
    posicionarPainelMobile();
    painel.hidden = false;
    lista.innerHTML = `<div class="agenda-pesquisa-estado">${esc(mensagem)}</div>`;
    paginacao.innerHTML = "";
  }

  function renderizar(itens, meta) {
    posicionarPainelMobile();
    painel.hidden = false;
    if (!itens.length) { estado("Nenhum agendamento encontrado."); return; }
    lista.innerHTML = itens.map((item) => `<button type="button" class="agenda-pesquisa-item" role="option" data-id-agendamento="${item.id_agendamento}" data-data-agendamento="${esc(item.data_agendamento)}">
      <span class="agenda-pesquisa-item-data"><strong>${esc(dataBr(item.data_agendamento))}</strong><small>${esc(item.hora_inicio)}</small></span>
      <span class="agenda-pesquisa-item-conteudo"><strong>${esc(item.cliente_nome)}</strong><small>${esc(item.servico_nome)} · ${esc(item.profissional_nome)}</small></span>
      <span class="agenda-pesquisa-item-status" data-status="${esc(item.status)}">${esc(item.status)}</span>
      <span class="agenda-pesquisa-item-ir" aria-hidden="true">›</span>
    </button>`).join("");

    const totalPaginas = Number(meta?.total_paginas || 1);
    paginacao.innerHTML = `${paginaAtual > 1 ? '<button type="button" data-pagina="anterior">◀ Anterior</button>' : ""}<span>Página ${paginaAtual} de ${totalPaginas}</span>${paginaAtual < totalPaginas ? '<button type="button" data-pagina="proxima">Próximo ▶</button>' : ""}`;
    if (info) info.textContent = `${Number(meta?.total || itens.length)} agendamento(s) encontrado(s).`;
  }

  async function pesquisar(pagina = 1) {
    const termo = input.value.trim();
    termoAtual = termo;
    paginaAtual = pagina;
    if (limpar) limpar.style.display = termo ? "inline-flex" : "none";
    if (termo !== "" && termo.length < 2) { ocultar(); return; }

    abortador?.abort();
    abortador = new AbortController();
    estado("Pesquisando em toda a agenda...");
    try {
      const params = new URLSearchParams({ q: termo, pagina: String(pagina), limite: "10" });
      const resposta = await fetch(`${API}&${params}`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }, signal: abortador.signal });
      const json = await resposta.json().catch(() => null);
      if (!resposta.ok || !json?.ok) throw new Error(json?.user_msg || "Não foi possível pesquisar os agendamentos.");
      if (termo !== input.value.trim()) return;
      renderizar(Array.isArray(json.data?.items) ? json.data.items : [], json.meta || {});
    } catch (erro) {
      if (erro?.name === "AbortError") return;
      estado(erro.message || "Não foi possível pesquisar os agendamentos.");
    }
  }

  input.addEventListener("input", (evento) => {
    // Impede que a antiga filtragem local da lista processe esta barra.
    evento.stopPropagation();
    clearTimeout(timer);
    timer = setTimeout(() => pesquisar(1), 300);
  });

  input.addEventListener("focus", () => {
    clearTimeout(timer);
    pesquisar(1);
  });

  limpar?.addEventListener("click", (evento) => {
    evento.preventDefault();
    evento.stopPropagation();
    input.value = "";
    abortador?.abort();
    ocultar();
    input.focus();
  });
  fechar?.addEventListener("click", ocultar);
  window.addEventListener("resize", () => { if (!painel.hidden) posicionarPainelMobile(); });
  window.addEventListener("scroll", () => { if (!painel.hidden) posicionarPainelMobile(); }, { passive: true });

  paginacao.addEventListener("click", (evento) => {
    const botao = evento.target.closest("button[data-pagina]");
    if (!botao) return;
    pesquisar(botao.dataset.pagina === "anterior" ? paginaAtual - 1 : paginaAtual + 1);
  });

  lista.addEventListener("click", (evento) => {
    const item = evento.target.closest(".agenda-pesquisa-item");
    if (!item) return;
    const data = item.dataset.dataAgendamento;
    const id = Number(item.dataset.idAgendamento || 0);
    if (!data || !id) return;

    input.value = "";
    ocultar();
    window.AgendaSemana?.irParaData(data, true);
    setTimeout(() => document.dispatchEvent(new CustomEvent("agenda:localizar-agendamento", { detail: { id_agendamento: id, data_agendamento: data } })), 0);
  });
})();
