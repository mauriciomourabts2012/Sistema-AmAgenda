(() => {
  "use strict";

  const page = document.querySelector("[data-documento-publico]");
  if (!page) return;

  const CODIGOS_PUBLICOS = new Set([
    "termos_empresa",
    "termos_usuario",
    "termos_cliente",
    "politica_privacidade"
  ]);
  const codigo = page.dataset.documentoCodigo;
  const api = page.dataset.api;
  const loading = document.getElementById("estadoCarregando");
  const unavailable = document.getElementById("estadoIndisponivel");
  const error = document.getElementById("estadoErro");
  const content = document.getElementById("documentoConteudo");
  const title = document.getElementById("documentoTitulo");
  const version = document.getElementById("documentoVersao");
  const effective = document.getElementById("documentoVigencia");

  function show(element) {
    [loading, unavailable, error, content].forEach((item) => {
      if (item) item.hidden = item !== element;
    });
  }

  async function load() {
    if (!api || !CODIGOS_PUBLICOS.has(codigo)) {
      show(error);
      return;
    }
    show(loading);
    try {
      const url = `${api}?path=documentos-legais/publico&codigo=${encodeURIComponent(codigo)}`;
      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
      const payload = await response.json().catch(() => null);

      if (response.status === 404 && payload?.code === "DOCUMENT_NOT_FOUND") {
        version.textContent = "Sem versão publicada";
        effective.textContent = "Aguardando publicação";
        effective.classList.remove("am-badge--success");
        effective.classList.add("am-badge--draft");
        show(unavailable);
        return;
      }
      if (!response.ok || payload?.ok !== true || payload.code !== "LEGAL_DOCUMENT_PUBLIC") {
        throw new Error("DOCUMENT_REQUEST_FAILED");
      }

      const documento = payload.data?.documento;
      if (!documento || documento.codigo !== codigo
        || typeof documento.titulo !== "string"
        || typeof documento.versao !== "string"
        || typeof documento.conteudo_html !== "string"
        || documento.conteudo_html.trim() === "") {
        throw new Error("DOCUMENT_RESPONSE_INVALID");
      }

      title.textContent = documento.titulo;
      version.textContent = `Versão ${documento.versao}`;
      effective.textContent = "Publicado e vigente";
      content.innerHTML = documento.conteudo_html;
      show(content);
    } catch (_) {
      show(error);
    }
  }

  document.getElementById("tentarNovamente")?.addEventListener("click", load);
  load();
})();
