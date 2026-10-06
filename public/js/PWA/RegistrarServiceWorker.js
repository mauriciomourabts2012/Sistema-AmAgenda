(() => {
  "use strict";

  if (!("serviceWorker" in navigator)) return;

  const script = document.currentScript;
  const scriptUrl = String(script?.src || "");
  // "login": página pública do login central (visitante ainda anônimo).
  // "bloqueado": o servidor já identificou Super Admin/Modo Suporte na sessão.
  // Ausente: página interna; o registro aguarda a sessão validada por _auth/sessao.js.
  const contexto = String(script?.dataset?.pwaContexto || "");
  const EVENTO_REGISTRO_PRONTO = "amagenda:pwa-registro-pronto";
  let registroAtual = null;

  function obterVersao() {
    try {
      const versao = new URL(scriptUrl).searchParams.get("v") || "";
      return /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(versao)
        ? versao
        : "";
    } catch (_) {
      return "";
    }
  }

  // Reutiliza os campos já entregues por _auth/session (mesma regra de Modo
  // Suporte usada em _auth/sessao.js). Não decide autorização: só impede o registro.
  function sessaoPermiteRegistro(auth, permitirCliente = false) {
    if (!auth || typeof auth !== "object") return false;
    const tipo = String(auth.tipo_usuario || "").toLowerCase();
    if (tipo === "super_admin") return false;
    if (tipo === "cliente") return permitirCliente && auth.modo_suporte !== true;
    return auth.modo_suporte !== true;
  }

  function aguardarSessao() {
    return new Promise((resolve) => {
      if (window.__AUTH__) {
        resolve(window.__AUTH__);
        return;
      }
      document.addEventListener(
        "amagenda:sessao-carregada",
        (evento) => resolve(evento?.detail || window.__AUTH__ || null),
        { once: true }
      );
    });
  }

  function versaoDoWorker(worker) {
    try {
      const versao = new URL(String(worker?.scriptURL || "")).searchParams.get("v") || "";
      return /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(versao) ? versao : "";
    } catch (_) {
      return "";
    }
  }

  // true quando a versão a é estritamente mais nova que b (major.minor.patch).
  function versaoMaisNova(a, b) {
    const nucleo = (v) => String(v).split(/[-+]/)[0].split(".").map(Number);
    const [na, nb] = [nucleo(a), nucleo(b)];
    for (let i = 0; i < 3; i += 1) {
      if (na[i] !== nb[i]) return na[i] > nb[i];
    }
    return false;
  }

  // Registro existente com worker (ativo, em espera ou instalando) de versão MAIS
  // NOVA que a solicitada. Nesse caso não se registra a URL antiga: isso criaria
  // um worker "waiting" de versão anterior (downgrade) e reoferecia atualização.
  async function registroComVersaoMaisNova(versao) {
    if (typeof navigator.serviceWorker.getRegistration !== "function") return null;
    try {
      const existente = await navigator.serviceWorker.getRegistration("/");
      if (!existente) return null;
      const versoes = [existente.active, existente.waiting, existente.installing]
        .map(versaoDoWorker)
        .filter(Boolean);
      return versoes.some((v) => versaoMaisNova(v, versao)) ? existente : null;
    } catch (_) {
      return null;
    }
  }

  async function registrarVersao(versao = obterVersao()) {
    const versaoValida = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(String(versao || ""));
    const workerUrl = versaoValida
      ? `/sw.js?v=${encodeURIComponent(versao)}`
      : "/sw.js";
    const registroMaisNovo = versaoValida ? await registroComVersaoMaisNova(String(versao)) : null;
    const registro = registroMaisNovo || await navigator.serviceWorker.register(workerUrl, {
      scope: "/",
      updateViaCache: "none",
    });
    registroAtual = registro;

    if (typeof window.dispatchEvent === "function" && typeof CustomEvent === "function") {
      window.dispatchEvent(new CustomEvent(EVENTO_REGISTRO_PRONTO, {
        detail: { registro, versao: versaoValida ? String(versao) : "" },
      }));
    }
    return registro;
  }

  window.AmAgendaRegistroPWA = {
    obterRegistro: () => registroAtual,
    obterVersao,
    registrarVersao,
  };

  window.addEventListener("load", async () => {
    if (contexto === "bloqueado") return;
    if (contexto !== "login" && contexto !== "agenda-online") {
      const auth = await aguardarSessao();
      if (!sessaoPermiteRegistro(auth, contexto === "cliente")) return;
    }

    try {
      await registrarVersao(obterVersao());
    } catch (_) {
      // O registro não interfere no fluxo de login ou no uso normal do sistema.
    }
  }, { once: true });
})();
