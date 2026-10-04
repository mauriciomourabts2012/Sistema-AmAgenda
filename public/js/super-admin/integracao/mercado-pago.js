(() => {
  "use strict";

  const ENDPOINT = "/public/api/api_central.php?path=superadmin/integracoes/mercado-pago";
  let carregando = false;

  function elemento(id) {
    return document.getElementById(id);
  }

  function definirTexto(id, valor) {
    const alvo = elemento(id);
    if (alvo) alvo.textContent = valor;
  }

  function definirEstado(id, configurado, textos = ["Configurado", "Não configurado"]) {
    const alvo = elemento(id);
    if (!alvo) return;
    alvo.textContent = configurado ? textos[0] : textos[1];
    alvo.classList.toggle("integracao-status--ok", configurado);
    alvo.classList.toggle("integracao-status--pendente", !configurado);
  }

  function renderizar(dados) {
    const ambienteConfigurado = dados?.ambiente_configurado === true;
    const ambiente = ambienteConfigurado && dados?.ambiente === "producao" ? "Produção" : "Teste";

    definirEstado("mpStatusGeral", dados?.pronto === true, ["Pronto", "Configuração incompleta"]);
    definirEstado("mpAmbienteStatus", ambienteConfigurado, [ambiente, "Não configurado"]);
    definirEstado("mpPublicKeyStatus", dados?.public_key_configurada === true);
    definirEstado("mpAccessTokenStatus", dados?.access_token_configurado === true);
    definirEstado("mpWebhookSecretStatus", dados?.webhook_secret_configurado === true);
    definirEstado("mpUrlPublicaStatus", dados?.url_publica_configurada === true);
    definirTexto("mpWebhookUrl", typeof dados?.webhook_url === "string" && dados.webhook_url
      ? dados.webhook_url
      : "Disponível quando AMAGENDA_URL_PUBLICA estiver configurada com HTTPS.");
  }

  async function carregar() {
    if (carregando) return;
    carregando = true;
    const botao = elemento("btnVerificarMercadoPago");
    const estado = elemento("mpIntegracaoEstado");
    if (botao) botao.disabled = true;
    if (estado) {
      estado.hidden = false;
      estado.textContent = "Verificando a configuração local...";
      estado.className = "integracao-feedback";
    }

    try {
      const resposta = await fetch(ENDPOINT, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
      const json = await resposta.json().catch(() => null);
      if (!resposta.ok || json?.ok !== true) {
        throw new Error(json?.user_msg || "Não foi possível verificar a integração.");
      }

      renderizar(json.data || {});
      if (estado) estado.hidden = true;
    } catch (erro) {
      if (estado) {
        estado.hidden = false;
        estado.textContent = erro instanceof Error ? erro.message : "Não foi possível verificar a integração.";
        estado.className = "integracao-feedback integracao-feedback--erro";
      }
    } finally {
      carregando = false;
      if (botao) botao.disabled = false;
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    elemento("btnVerificarMercadoPago")?.addEventListener("click", carregar);
    if (document.getElementById("integracoes")?.classList.contains("ativa")) carregar();
  });

  document.addEventListener("amagenda:menu-aba-alterada", evento => {
    if (evento.detail?.contexto === "super-admin" && evento.detail?.aba === "integracoes") carregar();
  });
})();
