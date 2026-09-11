(() => {
  "use strict";

  const tab = document.getElementById("documentos-legais");
  if (!tab) return;

  const API = "../../api/api_central.php";
  const VERSION = "1.0";
  const DOCUMENTS = new Set([
    "termos_empresa",
    "termos_usuario",
    "termos_cliente",
    "termo_super_admin",
    "politica_privacidade"
  ]);
  const select = tab.querySelector("#documentoSelecionado");
  const button = tab.querySelector("#btnCarregarPreview");
  const alert = tab.querySelector("#mensagemErroDocumentosLegais");
  const content = tab.querySelector("#previewConteudo");
  const title = tab.querySelector("#previewTitulo");
  const code = tab.querySelector("#previewCodigo");
  const version = tab.querySelector("#previewVersao");
  const status = tab.querySelector("#previewStatus");
  let loadingCode = "";
  let loadedCode = "";
  let activeRequest = 0;

  if (!select || !button || !alert || !content || !title || !code || !version || !status) return;

  function showLoading() {
    const spinner = document.createElement("div");
    spinner.className = "am-spinner";
    spinner.setAttribute("aria-label", "Carregando rascunho");
    content.textContent = "";
    content.append(spinner);
  }

  function showError(message) {
    content.textContent = "";
    alert.textContent = message || "Não foi possível carregar o rascunho.";
    alert.hidden = false;
  }

  async function load(force = false) {
    if (!tab.classList.contains("ativa")) return;

    const selectedCode = select.value;
    if (!DOCUMENTS.has(selectedCode)) {
      showError("Documento inválido.");
      return;
    }
    if (loadingCode === selectedCode || (!force && loadedCode === selectedCode)) return;

    loadingCode = selectedCode;
    const requestId = ++activeRequest;
    alert.hidden = true;
    button.disabled = true;
    showLoading();

    try {
      const url = `${API}?path=documentos-legais/preview&codigo=${encodeURIComponent(selectedCode)}&versao=${VERSION}`;
      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
      const payload = await response.json().catch(() => null);

      if (requestId !== activeRequest) return;

      if (response.status === 401) {
        window.location.replace("/public/views/login-super-admin.html");
        return;
      }
      if (!response.ok || payload?.ok !== true || payload.code !== "LEGAL_DOCUMENT_DRAFT_PREVIEW") {
        throw new Error(payload?.user_msg || "Não foi possível carregar o rascunho.");
      }

      const documentData = payload.data?.documento;
      if (!documentData || documentData.codigo !== selectedCode || documentData.versao !== VERSION
        || documentData.status !== "rascunho" || typeof documentData.titulo !== "string"
        || typeof documentData.conteudo_html !== "string" || documentData.conteudo_html.trim() === "") {
        throw new Error("O servidor retornou um rascunho inválido.");
      }

      title.textContent = documentData.titulo;
      code.textContent = documentData.codigo;
      version.textContent = `Versão ${documentData.versao}`;
      status.textContent = documentData.status.toUpperCase();
      content.innerHTML = documentData.conteudo_html;
      loadedCode = selectedCode;
    } catch (error) {
      if (requestId !== activeRequest) return;
      showError(error.message);
    } finally {
      if (requestId === activeRequest) {
        loadingCode = "";
        button.disabled = false;
      }
    }
  }

  button.addEventListener("click", () => load(true));
  select.addEventListener("change", () => load(false));
  document.addEventListener("amagenda:menu-aba-alterada", (event) => {
    if (event.detail?.contexto === "super-admin" && event.detail?.aba === "documentos-legais") {
      load(false);
    }
  });

  if (tab.classList.contains("ativa")) load(false);
})();
