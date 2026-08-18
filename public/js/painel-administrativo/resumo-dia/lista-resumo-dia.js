/* Resumo operacional do dia, alimentado pela empresa da sessão autenticada. */
(() => {
  "use strict";

  const C = window.ListaCore;
  if (!C || window.__AMAGENDA_RESUMO_DIA_INIT__) return;
  window.__AMAGENDA_RESUMO_DIA_INIT__ = true;

  const ENDPOINT = "/api/api_central.php?path=painel/resumo-dia";
  const FOTO_FALLBACK = "/public/imagens/avatar-default.png";
  const INTERVALO_ATUALIZACAO_RESUMO = 60000;
  const aba = document.getElementById("resumo");
  const cards = document.querySelector("#resumo .resumo-cards");
  const graficos = document.querySelector("#resumo .resumo-graficos");
  if (!aba || !cards || !graficos) return;

  let carregando = false;
  let intervaloId = null;

  function formatarData(iso) {
    return /^\d{4}-\d{2}-\d{2}$/.test(String(iso || "")) ? String(iso).split("-").reverse().join("/") : "Hoje";
  }

  function formatarHora(valor) {
    const hora = String(valor || "").slice(0, 5);
    return /^\d{2}:\d{2}$/.test(hora) ? hora : "--:--";
  }

  function calcularDuracao(inicio, fim) {
    const [hi, mi] = formatarHora(inicio).split(":").map(Number);
    const [hf, mf] = formatarHora(fim).split(":").map(Number);
    const minutos = (hf * 60 + mf) - (hi * 60 + mi);
    return Number.isFinite(minutos) && minutos > 0 ? `${minutos} min` : "";
  }

  function formatarMoeda(valor) {
    if (valor === null || valor === undefined) return "Não disponível";
    return Number(valor).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  }

  function normalizarStatus(status) {
    const valor = C.normalizar(status || "").trim();
    if (valor.includes("confirm")) return { texto: "Confirmado", classe: "st-confirmado" };
    if (valor.includes("cancel")) return { texto: "Cancelado", classe: "st-cancelado" };
    return { texto: "Pendente", classe: "st-pendente" };
  }

  function cardKpi({ titulo, valor, subtitulo, icone, indisponivel = false }) {
    return `<article class="painel-kpi${indisponivel ? " painel-kpi--indisponivel" : ""}"><div class="painel-kpi-topo"><div class="painel-kpi-titulo">${C.escapeHtml(titulo)}</div><div class="painel-kpi-ico"><i class="${C.escapeHtml(icone)}" aria-hidden="true"></i></div></div><div class="painel-kpi-valor">${C.escapeHtml(String(valor))}</div><div class="painel-kpi-sub">${C.escapeHtml(subtitulo)}</div></article>`;
  }

  function renderizarCardsResumo(data) {
    const resumo = data?.resumo || {};
    const ocupacao = resumo.ocupacao;
    const ocupacaoDisponivel = ocupacao && ocupacao.percentual !== null && ocupacao.percentual !== undefined;
    const ocupacaoMotivo = ocupacao?.motivo_indisponivel === "nenhum_profissional_ativo"
      ? "Nenhum profissional ativo"
      : "Horários da agenda não configurados";
    const dataHoje = `Hoje • ${formatarData(data?.data)}`;
    cards.innerHTML = [
      cardKpi({ titulo: "Agendamentos", valor: resumo.agendamentos ?? 0, subtitulo: dataHoje, icone: "fa-regular fa-calendar" }),
      cardKpi({ titulo: "Confirmados", valor: resumo.confirmados ?? 0, subtitulo: "Inclui quem está em atendimento", icone: "fa-regular fa-circle-check" }),
      cardKpi({ titulo: "Pendentes", valor: resumo.pendentes ?? 0, subtitulo: "Aguardando confirmação", icone: "fa-regular fa-clock" }),
      cardKpi({ titulo: "Cancelados", valor: resumo.cancelados ?? 0, subtitulo: "Cancelamentos no dia", icone: "fa-regular fa-circle-xmark" }),
      cardKpi({ titulo: "Faturamento (dia)", valor: formatarMoeda(resumo.faturamento), subtitulo: resumo.faturamento == null ? "Pagamento ainda não controlado" : "Receita realizada", icone: "fa-solid fa-sack-dollar", indisponivel: resumo.faturamento == null }),
      cardKpi({ titulo: "Ocupação semanal", valor: ocupacaoDisponivel ? `${Number(ocupacao.percentual)}%` : "Não disponível", subtitulo: ocupacaoDisponivel ? "Agenda preenchida nesta semana" : ocupacaoMotivo, icone: "fa-solid fa-chart-simple", indisponivel: !ocupacaoDisponivel }),
    ].join("");
  }

  function renderizarProximosAtendimentos(data) {
    const itens = Array.isArray(data?.proximos_atendimentos) ? data.proximos_atendimentos : [];
    const conteudo = itens.length ? itens.map((item) => {
      const status = normalizarStatus(item.status);
      return `<div class="painel-linha"><div class="painel-linha-esq"><div class="painel-hora">${C.escapeHtml(formatarHora(item.hora_inicio))}</div><div class="painel-info"><div class="painel-cliente">${C.escapeHtml(item.cliente || "Cliente")}</div><div class="painel-sub">${C.escapeHtml(item.servico || "Serviço")} • ${C.escapeHtml(item.profissional || "Profissional")} • até ${C.escapeHtml(formatarHora(item.hora_fim))}</div></div></div><div class="painel-linha-dir"><span class="agenda-status ${status.classe}">${status.texto}</span></div></div>`;
    }).join("") : '<div class="painel-vazio">Nenhum próximo atendimento para hoje.</div>';
    return `<section class="painel-bloco painel-bloco--proximos"><div class="painel-bloco-topo"><h3>Próximos atendimentos</h3></div><div class="painel-lista">${conteudo}</div></section>`;
  }

  function renderizarProfissional(profissional, dataResumo) {
    const atual = profissional?.atendimento_atual;
    const atendendo = profissional?.em_atendimento === true && atual;
    const nomeProfissional = C.escapeHtml(profissional?.nome || "Profissional");
    const fotoInformada = String(profissional?.foto_perfil || "").trim().replace(/\\/g, "/");
    const fotoPerfil = fotoInformada
      ? (/^(https?:)?\/\//i.test(fotoInformada) || fotoInformada.startsWith("data:") || fotoInformada.startsWith("blob:") || fotoInformada.startsWith("/")
        ? fotoInformada
        : `/${fotoInformada.replace(/^(\.\.\/)+/, "").replace(/^\.\//, "")}`)
      : FOTO_FALLBACK;
    const avatar = `<span class="painel-prof-avatar" aria-hidden="true"><img src="${C.escapeHtml(fotoPerfil)}" alt="" class="agenda-avatar-img" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="if(this.dataset.fallbackApplied==='1'){this.onerror=null;return;}this.dataset.fallbackApplied='1';this.src='${FOTO_FALLBACK}';"></span>`;
    const total = Number(profissional?.total || 0);
    const estado = atendendo
      ? '<span class="painel-prof-badge"><i aria-hidden="true"></i> Em atendimento</span>'
      : '<span class="painel-prof-livre"><i aria-hidden="true"></i> Livre neste momento</span>';
    const atendimentoAtual = atendendo
      ? `<div class="painel-prof-atual"><span>Atendendo agora</span><strong><i class="fa-regular fa-user" aria-hidden="true"></i>${C.escapeHtml(atual.cliente || "Cliente")}</strong><small>${C.escapeHtml(atual.servico || "Serviço")}</small><small><i class="fa-regular fa-clock" aria-hidden="true"></i>${C.escapeHtml(formatarHora(atual.hora_inicio))} às ${C.escapeHtml(formatarHora(atual.hora_fim))}</small></div>`
      : "";
    return `<article class="painel-prof${atendendo ? " painel-prof--atendendo" : ""}"><header class="painel-prof-cabecalho"><div class="painel-prof-nome">${nomeProfissional}</div>${avatar}</header><div class="painel-prof-estado">${estado}</div><div class="painel-prof-servico">${total} agendamento(s) hoje</div><div class="painel-prof-metrics"><span class="pill">${total} total</span><span class="pill ok">${Number(profissional?.confirmados || 0)} conf.</span><span class="pill warn">${Number(profissional?.pendentes || 0)} pend.</span><span class="pill danger">${Number(profissional?.cancelados || 0)} canc.</span></div>${atendimentoAtual}</article>`;
  }

  function renderizarProfissionais(data) {
    const profissionais = Array.isArray(data?.profissionais) ? data.profissionais : [];
    const conteudo = profissionais.length ? profissionais.map((profissional) => renderizarProfissional(profissional, data?.data)).join("") : '<div class="painel-vazio">Nenhum profissional ativo nesta empresa.</div>';
    return `<section class="painel-bloco painel-bloco--profissionais"><div class="painel-bloco-topo"><h3>Por profissional</h3><small>Atualizado pelo horário do servidor</small></div><div class="painel-prof-list">${conteudo}</div></section>`;
  }

  function renderizarConteudo(data) {
    renderizarCardsResumo(data);
    graficos.innerHTML = renderizarProfissionais(data) + renderizarProximosAtendimentos(data);
  }

  function renderizarCarregamento() {
    cards.innerHTML = Array.from({ length: 6 }, () => '<article class="painel-kpi painel-kpi--carregando"><div class="painel-kpi-valor">…</div></article>').join("");
    graficos.innerHTML = '<section class="painel-bloco"><div class="painel-vazio">Carregando resumo…</div></section>';
  }

  function renderizarErro(mensagem) {
    cards.innerHTML = "";
    graficos.innerHTML = `<section class="painel-bloco"><div class="painel-erro"><strong>Não foi possível carregar o resumo.</strong><span>${C.escapeHtml(mensagem || "Tente novamente em instantes.")}</span></div></section>`;
  }

  async function carregarResumoDia({ silencioso = false } = {}) {
    if (carregando) return;
    carregando = true;
    if (!silencioso) renderizarCarregamento();
    try {
      const json = await C.fetchJSON(ENDPOINT);
      if (!json?.ok || !json?.data) throw new Error(json?.user_msg || "Resposta inválida do servidor.");
      renderizarConteudo(json.data);
    } catch (erro) {
      if (!silencioso) renderizarErro(erro.message);
      console.error("[ResumoDia]", erro);
    } finally {
      carregando = false;
    }
  }

  function abaEstaAtiva() { return aba.classList.contains("ativa"); }

  function iniciarAtualizacaoAutomatica() {
    if (intervaloId !== null) return;
    intervaloId = window.setInterval(() => {
      if (abaEstaAtiva() && !document.hidden) carregarResumoDia({ silencioso: true });
    }, INTERVALO_ATUALIZACAO_RESUMO);
  }

  new MutationObserver(() => { if (abaEstaAtiva()) carregarResumoDia({ silencioso: true }); }).observe(aba, { attributes: true, attributeFilter: ["class"] });
  document.addEventListener("visibilitychange", () => { if (!document.hidden && abaEstaAtiva()) carregarResumoDia({ silencioso: true }); });
  document.addEventListener("resumo:recarregar", () => carregarResumoDia({ silencioso: true }));
  document.addEventListener("DOMContentLoaded", () => {
    if (abaEstaAtiva()) carregarResumoDia();
    iniciarAtualizacaoAutomatica();
  });
})();
