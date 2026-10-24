/* ==========================================================
   CHAT — NÃO LIDAS (módulo global, singleton)
   ----------------------------------------------------------
   - Um único ciclo de consulta por página (interno ou cliente);
   - 30 s com a aba visível; pausa com a aba oculta e atualiza
     imediatamente ao voltar;
   - backoff progressivo em erro e parada definitiva em 401/403;
   - parada definitiva quando o sessao.js confirma a perda da sessão
     (evento "amagenda:sessao-encerrada"); durante inatividade, as
     consultas automáticas ficam retidas pelo sessao.js e só seguem após
     nova interação e revalidação, sem manter a sessão PHP viva;
   - publica o estado no evento "amagenda:chat-nao-lidas".
   Os consumidores (menu, página do Chat, modais, cards e a Central de
   Notificações do cliente) apenas reagem ao evento; nenhum deles cria
   timer próprio de contador.
   ========================================================== */
(() => {
  "use strict";

  if (window.ChatNaoLidas) return;

  const API_BASE = "/public/api/api_central.php?path=";
  const ROTAS = {
    interno: "chat/conversas/nao-lidas",
    cliente: "cliente/chat/conversas/nao-lidas",
  };
  const INTERVALO_MS = 30000;
  const BACKOFF_MAX_MS = 300000;
  const INTERVALO_MINIMO_MS = 2000;
  const EVENTO = "amagenda:chat-nao-lidas";

  const estado = {
    contexto: null,
    iniciado: false,
    parado: false,
    emAndamento: null,
    repetir: false,
    timer: 0,
    falhas: 0,
    ultimaConsulta: 0,
    total: 0,
    conversas: [],
    porConversa: new Map(),
    porAgendamento: new Map(),
  };

  function normalizar(valor) {
    return String(valor || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  }

  /** Contexto da página + sessão. null = sem Chat (ex.: Super Admin/suporte, cliente incompleto). */
  function detectarContexto() {
    const auth = window.__AUTH__;
    if (!auth || typeof auth !== "object") return null;
    const contextoMenu = document.body?.dataset?.menuContexto || "";

    if (contextoMenu === "cliente") {
      return auth.tipo_usuario === "cliente" && Number(auth.id_cliente) > 0 && auth.cadastro_completo === true
        ? "cliente"
        : null;
    }
    if (contextoMenu === "agenda" || contextoMenu === "chat") {
      if (normalizar(auth.tipo_usuario) === "super_admin" || auth.modo_suporte === true) return null;
      return typeof window.usuarioPode === "function" && window.usuarioPode("chat.visualizar") === true
        ? "interno"
        : null;
    }
    return null;
  }

  function limparTimer() {
    window.clearTimeout(estado.timer);
    estado.timer = 0;
  }

  function agendar(ms) {
    limparTimer();
    if (estado.parado) return;
    estado.timer = window.setTimeout(() => {
      estado.timer = 0;
      if (document.visibilityState === "visible") atualizarAgora();
      // Aba oculta: nenhum novo timer; o retorno à aba dispara a atualização.
    }, ms);
  }

  function aplicar(dados) {
    const anterior = estado.total;
    const conversas = Array.isArray(dados?.conversas) ? dados.conversas : [];
    const total = estado.contexto === "cliente"
      ? Number(dados?.total_conversas_nao_lidas || 0)
      : Number(dados?.conversas_nao_lidas || 0);

    estado.total = Number.isFinite(total) && total > 0 ? Math.floor(total) : 0;
    estado.conversas = conversas
      .map(item => ({
        id_conversa: Number(item?.id_conversa) || 0,
        id_agendamento: Number(item?.id_agendamento) || null,
        quantidade_nao_lidas: Math.max(0, Number(item?.quantidade_nao_lidas) || 0),
      }))
      .filter(item => item.id_conversa > 0);
    estado.porConversa = new Map(estado.conversas.map(item => [item.id_conversa, item.quantidade_nao_lidas]));
    estado.porAgendamento = new Map(
      estado.conversas.filter(item => item.id_agendamento).map(item => [item.id_agendamento, item.quantidade_nao_lidas])
    );

    document.dispatchEvent(new CustomEvent(EVENTO, {
      detail: {
        contexto: estado.contexto,
        total: estado.total,
        anterior,
        aumentou: estado.total > anterior,
        conversas: estado.conversas.map(item => ({ ...item })),
      },
    }));
  }

  async function consultar() {
    const url = new URL(`${API_BASE}${encodeURIComponent(ROTAS[estado.contexto])}`, window.location.origin);
    const resposta = await fetch(url.toString(), {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    const json = await resposta.json().catch(() => null);
    if (resposta.status === 401 || resposta.status === 403) {
      const erro = new Error("Sem acesso às mensagens não lidas.");
      erro.definitivo = true;
      throw erro;
    }
    if (!resposta.ok || json?.ok !== true) throw new Error(json?.user_msg || "Falha ao consultar não lidas.");
    return json.data || {};
  }

  /** Atualiza agora (ou logo após a consulta em andamento). Nunca cria um segundo ciclo. */
  function atualizarAgora() {
    if (estado.parado || !estado.contexto) return Promise.resolve(obter());
    if (estado.emAndamento) {
      estado.repetir = true;
      return estado.emAndamento;
    }
    limparTimer();
    estado.ultimaConsulta = Date.now();
    estado.emAndamento = consultar()
      .then(dados => {
        estado.falhas = 0;
        aplicar(dados);
        agendar(INTERVALO_MS);
      })
      .catch(erro => {
        if (erro?.definitivo) {
          parar();
          return;
        }
        estado.falhas += 1;
        agendar(Math.min(INTERVALO_MS * (2 ** estado.falhas), BACKOFF_MAX_MS));
      })
      .finally(() => {
        estado.emAndamento = null;
        if (estado.repetir && !estado.parado) {
          estado.repetir = false;
          atualizarAgora();
        }
      })
      .then(() => obter());
    return estado.emAndamento;
  }

  function parar() {
    estado.parado = true;
    limparTimer();
  }

  function iniciar() {
    if (estado.iniciado || estado.parado) return;
    const contexto = detectarContexto();
    if (!contexto) return;
    estado.contexto = contexto;
    estado.iniciado = true;
    if (document.visibilityState === "visible") atualizarAgora();
  }

  function obter() {
    return {
      contexto: estado.contexto,
      total: estado.total,
      conversas: estado.conversas.map(item => ({ ...item })),
    };
  }

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState !== "visible" || !estado.iniciado || estado.parado) return;
    if (Date.now() - estado.ultimaConsulta < INTERVALO_MINIMO_MS) return;
    atualizarAgora();
  });
  document.addEventListener("amagenda:sessao-carregada", iniciar);
  // Sessão encerrada: nenhum ciclo é reiniciado (nem pelo retorno à aba).
  document.addEventListener("amagenda:sessao-encerrada", parar);
  document.addEventListener("DOMContentLoaded", iniciar);

  window.ChatNaoLidas = Object.freeze({
    iniciar,
    atualizarAgora,
    obter,
    total: () => estado.total,
    quantidadePorConversa: id => estado.porConversa.get(Number(id)) || 0,
    quantidadePorAgendamento: id => estado.porAgendamento.get(Number(id)) || 0,
    contexto: () => estado.contexto,
    EVENTO,
  });
})();
