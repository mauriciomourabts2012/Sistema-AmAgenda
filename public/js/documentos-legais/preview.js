(() => {
  "use strict";

  const tab = document.getElementById("documentos-legais");
  if (!tab) return;

  const API = "../../api/api_central.php";
  const LOGIN = "/public/views/login-super-admin.html";
  const STATUS_LABELS = {
    ativo: "Ativo",
    rascunho: "Rascunho",
    publicado: "Publicado",
    arquivado: "Arquivado"
  };
  const TYPE_LABELS = {
    termo: "Termo",
    politica_privacidade: "Política de Privacidade"
  };
  const SCOPE_LABELS = {
    empresa: "Empresa",
    cliente: "Cliente",
    todos: "Todos",
    super_admin: "Super Admin",
    usuario_empresa: "Usuário da empresa"
  };

  const elements = {
    select: tab.querySelector("#documentoSelecionado"),
    viewButton: tab.querySelector("#btnCarregarPreview"),
    alert: tab.querySelector("#mensagemErroDocumentosLegais"),
    content: tab.querySelector("#previewConteudo"),
    contentSummary: tab.querySelector("#previewResumoAlteracoes"),
    title: tab.querySelector("#previewTitulo"),
    code: tab.querySelector("#previewCodigo"),
    version: tab.querySelector("#previewVersao"),
    status: tab.querySelector("#previewStatus"),
    type: tab.querySelector("#previewTipo"),
    scope: tab.querySelector("#previewEscopo"),
    publishedVersion: tab.querySelector("#previewVersaoPublicada"),
    publishedAt: tab.querySelector("#previewPublicadoEm"),
    validity: tab.querySelector("#previewVigencia"),
    newManifestation: tab.querySelector("#previewNovaManifestacao"),
    hash: tab.querySelector("#previewHash"),
    versions: tab.querySelector("#listaVersoesDocumentos"),
    totalDocuments: tab.querySelector("#resumoDocumentosLegais"),
    totalPublished: tab.querySelector("#resumoDocumentosPublicados"),
    totalDrafts: tab.querySelector("#resumoDocumentosRascunhos"),
    totalArchived: tab.querySelector("#resumoDocumentosArquivados"),
    editDraftButton: tab.querySelector("#btnEditarRascunhoLegal"),
    newDraftButton: document.querySelector("#btnSalvarNovoRascunhoLegal"),
    editDraftSaveButton: document.querySelector("#btnSalvarEdicaoRascunhoLegal"),
    publishVersionButton: document.querySelector("#btnPublicarVersaoLegal"),
    archiveVersionButton: document.querySelector("#btnArquivarVersaoLegal"),
    newDraftModal: document.querySelector("#modalNovaVersaoLegal"),
    editDraftModal: document.querySelector("#modalEditarRascunhoLegal"),
    newDraftDocument: document.querySelector("#novaVersaoDocumento"),
    newDraftVersion: document.querySelector("#novaVersaoNumero"),
    newDraftSummary: document.querySelector("#novaVersaoResumo"),
    newDraftManifestation: document.querySelector("#novaVersaoManifestacao"),
    newDraftContent: document.querySelector("#novaVersaoConteudo"),
    editDraftDocument: document.querySelector("#editarRascunhoDocumento"),
    editDraftVersion: document.querySelector("#editarRascunhoVersao"),
    editDraftSummary: document.querySelector("#editarRascunhoResumo"),
    editDraftManifestation: document.querySelector("#editarRascunhoManifestacao"),
    editDraftContent: document.querySelector("#editarRascunhoConteudo")
  };

  if (Object.values(elements).some(element => !element)) return;

  const state = {
    documents: [],
    selectedDocument: null,
    versions: [],
    selectedVersion: null,
    loaded: false,
    loading: false,
    request: 0
  };

  function setText(element, value, fallback = "—") {
    const text = value === null || typeof value === "undefined" ? "" : String(value).trim();
    element.textContent = text || fallback;
  }

  function formatDate(value) {
    const text = String(value || "").trim();
    if (!text) return "—";
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return text;
    return `${match[3]}/${match[2]}/${match[1]}${match[4] ? ` ${match[4]}:${match[5]}` : ""}`;
  }

  function formatValidity(start, end) {
    if (!start && !end) return "—";
    if (start && end) return `${formatDate(start)} até ${formatDate(end)}`;
    if (start) return `Desde ${formatDate(start)}`;
    return `Até ${formatDate(end)}`;
  }

  function formatBoolean(value) {
    if (value === true) return "Obrigatória";
    if (value === false) return "Não obrigatória";
    return "—";
  }

  function statusLabel(status) {
    return STATUS_LABELS[String(status || "").toLowerCase()] || String(status || "—");
  }

  function setStatusBadge(status) {
    const normalized = String(status || "").toLowerCase();
    elements.status.classList.remove("am-badge--draft", "am-badge--success", "am-badge--archived");
    if (normalized === "rascunho") elements.status.classList.add("am-badge--draft");
    if (normalized === "publicado") elements.status.classList.add("am-badge--success");
    if (normalized === "arquivado") elements.status.classList.add("am-badge--archived");
    setText(elements.status, statusLabel(normalized));
  }

  function clearAlert() {
    elements.alert.textContent = "";
    elements.alert.hidden = true;
  }

  function showError(message) {
    elements.alert.textContent = message || "Não foi possível carregar os documentos legais.";
    elements.alert.hidden = false;
  }

  function showContentMessage(message) {
    elements.content.textContent = "";
    const paragraph = document.createElement("p");
    paragraph.className = "am-document-placeholder";
    paragraph.textContent = message;
    elements.content.append(paragraph);
    elements.contentSummary.hidden = true;
    elements.contentSummary.textContent = "";
  }

  function showLoading(message = "Carregando documentos legais") {
    elements.content.textContent = "";
    const spinner = document.createElement("div");
    spinner.className = "am-spinner";
    spinner.setAttribute("aria-label", message);
    elements.content.append(spinner);
  }

  async function request(path, parameters = {}) {
    const query = new URLSearchParams({ path, ...parameters });
    const response = await fetch(`${API}?${query.toString()}`, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" }
    });
    const payload = await response.json().catch(() => null);

    if (response.status === 401) {
      window.location.replace(LOGIN);
      throw new Error("Sessão expirada.");
    }
    if (!response.ok || payload?.ok !== true) {
      throw new Error(payload?.user_msg || "Não foi possível concluir a consulta.");
    }
    return payload;
  }

  async function requestPost(path, body) {
    const csrfToken = String(window.__AUTH__?.csrf_token || "").trim();
    if (!csrfToken) throw new Error("Atualize a página para renovar sua sessão antes de salvar.");
    const query = new URLSearchParams({ path });
    const response = await fetch(`${API}?${query.toString()}`, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken
      },
      body: JSON.stringify(body)
    });
    const payload = await response.json().catch(() => null);
    if (response.status === 401) {
      window.location.replace(LOGIN);
      throw new Error("Sessão expirada.");
    }
    if (!response.ok || payload?.ok !== true) {
      throw new Error(payload?.user_msg || "Não foi possível salvar o rascunho.");
    }
    return payload;
  }

  function notifySuccess(message) {
    if (window.MensagemSistema?.sucesso) window.MensagemSistema.sucesso(message);
  }

  function notifyError(message) {
    if (window.MensagemSistema?.erro) window.MensagemSistema.erro(message);
  }

  function renderSummary(summary) {
    const values = [
      [elements.totalDocuments, summary?.documentos_ativos],
      [elements.totalPublished, summary?.versoes_publicadas],
      [elements.totalDrafts, summary?.rascunhos],
      [elements.totalArchived, summary?.versoes_arquivadas]
    ];
    values.forEach(([element, value]) => {
      const number = Number(value);
      setText(element, Number.isInteger(number) && number >= 0 ? number : null);
    });
  }

  function renderSelect(documents) {
    const previousCode = elements.select.value;
    elements.select.textContent = "";
    documents.forEach(documentData => {
      const option = document.createElement("option");
      option.value = documentData.codigo;
      option.textContent = documentData.titulo;
      elements.select.append(option);
    });
    if (documents.some(documentData => documentData.codigo === previousCode)) {
      elements.select.value = previousCode;
    }
    elements.select.disabled = documents.length === 0;
    elements.viewButton.disabled = documents.length === 0;
  }

  function renderDocument(documentData, versionData = null) {
    const selectedVersion = versionData?.versao || documentData?.versao_publicada || null;
    const selectedStatus = versionData?.status
      || (documentData?.versao_publicada ? "publicado" : (documentData?.possui_rascunho ? "rascunho" : documentData?.status));
    const publishedAt = versionData ? versionData.publicado_em : documentData?.publicado_em;
    const validityStart = versionData ? versionData.vigencia_inicio : documentData?.vigencia_inicio;
    const validityEnd = versionData ? versionData.vigencia_fim : documentData?.vigencia_fim;
    const requiresManifestation = versionData
      ? versionData.exige_nova_manifestacao
      : documentData?.exige_nova_manifestacao;

    setText(elements.title, versionData?.titulo || documentData?.titulo);
    setText(elements.code, versionData?.codigo || documentData?.codigo);
    setText(elements.version, selectedVersion ? `Versão ${selectedVersion}` : null);
    setStatusBadge(selectedStatus);
    setText(elements.type, TYPE_LABELS[versionData?.tipo || documentData?.tipo] || versionData?.tipo || documentData?.tipo);
    setText(elements.scope, SCOPE_LABELS[versionData?.escopo || documentData?.escopo] || versionData?.escopo || documentData?.escopo);
    setText(elements.publishedVersion, documentData?.versao_publicada ? `Versão ${documentData.versao_publicada}` : null);
    setText(elements.publishedAt, formatDate(publishedAt));
    setText(elements.validity, formatValidity(validityStart, validityEnd));
    setText(elements.newManifestation, formatBoolean(requiresManifestation));
    setText(elements.hash, versionData?.hash_sha256);
  }

  function createCell(value) {
    const cell = document.createElement("td");
    cell.textContent = value;
    return cell;
  }

  function createStatusBadge(status) {
    const badge = document.createElement("span");
    badge.className = "am-badge";
    if (status === "rascunho") badge.classList.add("am-badge--draft");
    if (status === "publicado") badge.classList.add("am-badge--success");
    if (status === "arquivado") badge.classList.add("am-badge--archived");
    badge.textContent = statusLabel(status);
    return badge;
  }

  function renderVersions(versions) {
    elements.versions.textContent = "";
    if (!versions.length) {
      const row = document.createElement("tr");
      row.className = "am-legal-table__empty";
      const cell = createCell("Nenhuma versão encontrada.");
      cell.colSpan = 5;
      row.append(cell);
      elements.versions.append(row);
      return;
    }

    versions.forEach(versionData => {
      const row = document.createElement("tr");
      const versionCell = createCell(versionData.versao || "—");
      const statusCell = document.createElement("td");
      statusCell.append(createStatusBadge(versionData.status));
      const publicationCell = createCell(formatDate(versionData.publicado_em));
      const manifestationCell = createCell(formatBoolean(versionData.exige_nova_manifestacao));
      const actionCell = document.createElement("td");
      const viewButton = document.createElement("button");
      viewButton.className = "botao-geral am-legal-table-action";
      viewButton.type = "button";
      viewButton.dataset.visualizarVersao = String(versionData.id_documento_legal_versao);
      viewButton.textContent = "Visualizar";
      actionCell.append(viewButton);
      if (versionData.status === "rascunho") {
        const archiveButton = document.createElement("button");
        archiveButton.className = "botao-geral am-legal-table-action";
        archiveButton.type = "button";
        archiveButton.dataset.arquivarVersao = String(versionData.id_documento_legal_versao);
        archiveButton.textContent = "Arquivar";
        actionCell.append(archiveButton);
      }
      row.append(versionCell, statusCell, publicationCell, manifestationCell, actionCell);
      elements.versions.append(row);
    });
  }

  function setEditDraftAvailability() {
    const hasDraft = selectedDraft() !== null;
    elements.editDraftButton.disabled = !hasDraft;
  }

  function selectedDraft() {
    const selectedId = Number(state.selectedVersion?.id_documento_legal_versao);
    return state.versions.find(versionData =>
      versionData.status === "rascunho"
      && Number(versionData.id_documento_legal_versao) === selectedId
    ) || state.versions.find(versionData => versionData.status === "rascunho") || null;
  }

  function fillDocumentSelect(select, documentData) {
    select.textContent = "";
    const option = document.createElement("option");
    option.value = documentData.codigo;
    option.textContent = documentData.titulo;
    select.append(option);
    select.value = documentData.codigo;
  }

  function setNewDraftControls(disabled) {
    elements.newDraftVersion.disabled = disabled;
    elements.newDraftSummary.disabled = disabled;
    elements.newDraftManifestation.disabled = disabled;
    elements.newDraftContent.disabled = disabled;
    elements.newDraftButton.disabled = disabled;
  }

  function setEditDraftControls(disabled) {
    elements.editDraftSummary.disabled = disabled;
    elements.editDraftManifestation.disabled = disabled;
    elements.editDraftContent.disabled = disabled;
    elements.editDraftSaveButton.disabled = disabled;
    elements.publishVersionButton.disabled = disabled;
    elements.archiveVersionButton.disabled = disabled;
    elements.archiveVersionButton.hidden = disabled;
  }

  function openNewDraft() {
    const documentData = state.selectedDocument;
    if (!documentData) {
      showError("Selecione um documento antes de criar uma versão.");
      return;
    }
    fillDocumentSelect(elements.newDraftDocument, documentData);
    elements.newDraftVersion.value = "";
    elements.newDraftSummary.value = "";
    elements.newDraftManifestation.checked = false;
    elements.newDraftContent.value = "";
    setNewDraftControls(false);
  }

  function draftPayloadFromNew() {
    return {
      codigo: elements.newDraftDocument.value,
      versao: elements.newDraftVersion.value.trim(),
      resumo_alteracoes: elements.newDraftSummary.value,
      exige_nova_manifestacao: elements.newDraftManifestation.checked ? 1 : 0,
      conteudo_html: elements.newDraftContent.value
    };
  }

  function draftPayloadFromEdit() {
    return {
      codigo: elements.editDraftDocument.value,
      id_documento_legal_versao: Number(elements.editDraftModal.dataset.idVersao),
      versao: elements.editDraftVersion.value,
      resumo_alteracoes: elements.editDraftSummary.value,
      exige_nova_manifestacao: elements.editDraftManifestation.checked ? 1 : 0,
      conteudo_html: elements.editDraftContent.value
    };
  }

  async function saveDraft(button, payload, modal, successMessage) {
    button.disabled = true;
    try {
      await requestPost("documentos-legais/admin/rascunho", payload);
      if (typeof window.fecharModal === "function") window.fecharModal(modal);
      await loadAdmin(true);
      notifySuccess(successMessage);
    } catch (error) {
      showError(error.message);
      notifyError(error.message);
      button.disabled = false;
    }
  }

  async function confirmAction(message, title) {
    if (!window.MensagemSistema?.confirmar) {
      throw new Error("A confirmação do sistema não está disponível.");
    }
    return window.MensagemSistema.confirmar(message, { titulo: title, textoConfirmar: "Confirmar" });
  }

  async function refreshAfterVersionChange(versionId, successMessage, closeModal = false) {
    if (closeModal && typeof window.fecharModal === "function") window.fecharModal(elements.editDraftModal);
    await loadAdmin(true);
    const versionData = state.versions.find(item => Number(item.id_documento_legal_versao) === Number(versionId));
    if (versionData) await viewVersion(versionData);
    notifySuccess(successMessage);
  }

  async function publishVersion(versionId) {
    if (!Number.isInteger(Number(versionId)) || Number(versionId) <= 0) return;
    const confirmed = await confirmAction("Publicar esta versão encerrará a vigência da versão publicada anterior.", "Publicar versão");
    if (!confirmed) return;
    elements.publishVersionButton.disabled = true;
    try {
      await requestPost("documentos-legais/admin/publicar", { id_documento_legal_versao: Number(versionId) });
      await refreshAfterVersionChange(versionId, "Versão publicada com sucesso.", true);
    } catch (error) {
      showError(error.message);
      notifyError(error.message);
      elements.publishVersionButton.disabled = false;
    }
  }

  async function archiveVersion(versionId, closeModal = false) {
    if (!Number.isInteger(Number(versionId)) || Number(versionId) <= 0) return;
    const confirmed = await confirmAction("Arquivar esta versão impedirá novas edições e não excluirá o histórico.", "Arquivar versão");
    if (!confirmed) return;
    if (closeModal) elements.archiveVersionButton.disabled = true;
    try {
      const payload = await requestPost("documentos-legais/admin/arquivar", { id_documento_legal_versao: Number(versionId) });
      const message = payload.code === "DOCUMENT_VERSION_ALREADY_ARCHIVED"
        ? "A versão já estava arquivada."
        : "Versão arquivada com sucesso.";
      await refreshAfterVersionChange(versionId, message, closeModal);
    } catch (error) {
      showError(error.message);
      notifyError(error.message);
      if (closeModal) elements.archiveVersionButton.disabled = false;
    }
  }

  function renderContent(documentData) {
    elements.content.innerHTML = documentData.conteudo_html;
    const summary = String(documentData.resumo_alteracoes || "").trim();
    elements.contentSummary.textContent = summary ? `Resumo das alterações: ${summary}` : "";
    elements.contentSummary.hidden = summary === "";
  }

  async function viewVersion(versionData) {
    const requestId = ++state.request;
    clearAlert();
    showLoading("Carregando versão do documento");
    elements.viewButton.disabled = true;

    try {
      const payload = versionData.status === "rascunho"
        ? await request("documentos-legais/preview", {
            codigo: state.selectedDocument.codigo,
            versao: versionData.versao
          })
        : await request("documentos-legais/admin/versao", {
            id_versao: versionData.id_documento_legal_versao
          });
      if (requestId !== state.request) return;
      const documentData = payload.data?.documento;
      if (!documentData || typeof documentData.conteudo_html !== "string") {
        throw new Error("O servidor retornou uma versão inválida.");
      }
      renderDocument(state.selectedDocument, documentData);
      renderContent(documentData);
      state.selectedVersion = documentData;
    } catch (error) {
      if (requestId !== state.request) return;
      showError(error.message);
      showContentMessage("Não foi possível exibir esta versão.");
    } finally {
      if (requestId === state.request) elements.viewButton.disabled = false;
    }
  }

  async function loadVersions(documentData) {
    const requestId = ++state.request;
    state.versions = [];
    renderVersions([]);
    try {
      const payload = await request("documentos-legais/admin/versoes", { codigo: documentData.codigo });
      if (requestId !== state.request || state.selectedDocument?.codigo !== documentData.codigo) return;
      const versions = Array.isArray(payload.data?.versoes) ? payload.data.versoes : [];
      state.versions = versions.filter(versionData =>
        Number.isInteger(Number(versionData?.id_documento_legal_versao))
        && Number(versionData.id_documento_legal_versao) > 0
      );
      renderVersions(state.versions);
      setEditDraftAvailability();
    } catch (error) {
      if (requestId !== state.request) return;
      showError(error.message);
      renderVersions([]);
      setEditDraftAvailability();
    }
  }

  async function selectDocument(code) {
    const documentData = state.documents.find(item => item.codigo === code) || null;
    state.selectedDocument = documentData;
    state.versions = [];
    state.selectedVersion = null;
    setEditDraftAvailability();
    if (!documentData) {
      showContentMessage("Nenhum documento legal disponível.");
      renderVersions([]);
      return;
    }
    clearAlert();
    renderDocument(documentData);
    showContentMessage("Selecione uma versão para visualizar seu conteúdo.");
    await loadVersions(documentData);
  }

  async function loadAdmin(force = false) {
    if (state.loading || (state.loaded && !force)) return;
    state.loading = true;
    elements.select.disabled = true;
    elements.viewButton.disabled = true;
    clearAlert();
    showLoading();

    try {
      const payload = await request("documentos-legais/admin/listar");
      const documents = Array.isArray(payload.data?.documentos) ? payload.data.documentos : [];
      state.documents = documents.filter(documentData =>
        typeof documentData?.codigo === "string"
        && typeof documentData?.titulo === "string"
        && Number.isInteger(Number(documentData?.id_documento_legal))
        && Number(documentData.id_documento_legal) > 0
      );
      renderSummary(payload.data?.resumo || {});
      renderSelect(state.documents);
      state.loaded = true;
      await selectDocument(elements.select.value);
    } catch (error) {
      showError(error.message);
      showContentMessage("Não foi possível carregar o gerenciamento de documentos.");
      renderVersions([]);
    } finally {
      state.loading = false;
      elements.select.disabled = state.documents.length === 0;
      elements.viewButton.disabled = state.documents.length === 0;
    }
  }

  elements.select.addEventListener("change", () => selectDocument(elements.select.value));

  elements.viewButton.addEventListener("click", () => {
    const draft = selectedDraft();
    const published = state.versions.find(versionData =>
      Number(versionData.id_documento_legal_versao) === Number(state.selectedDocument?.id_versao_publicada)
    );
    const versionData = draft || published || state.versions[0];
    if (!versionData) {
      showError("Nenhuma versão disponível para visualização.");
      return;
    }
    viewVersion(versionData);
  });

  elements.versions.addEventListener("click", event => {
    const archiveButton = event.target.closest("[data-arquivar-versao]");
    if (archiveButton) {
      archiveVersion(Number(archiveButton.dataset.arquivarVersao));
      return;
    }
    const button = event.target.closest("[data-visualizar-versao]");
    if (!button) return;
    const idVersion = Number(button.dataset.visualizarVersao);
    const versionData = state.versions.find(item => Number(item.id_documento_legal_versao) === idVersion);
    if (versionData) viewVersion(versionData);
  });

  document.querySelectorAll("#btnNovaVersaoLegal, #btnNovaVersaoLegalDetalhe").forEach(button => {
    button.addEventListener("click", openNewDraft);
  });

  elements.newDraftButton.addEventListener("click", () => {
    saveDraft(elements.newDraftButton, draftPayloadFromNew(), elements.newDraftModal, "Rascunho criado com sucesso.");
  });

  elements.editDraftButton.addEventListener("click", async () => {
    const draft = state.versions.find(versionData => versionData.status === "rascunho");
    if (!draft || !state.selectedDocument) return;
    elements.editDraftButton.disabled = true;
    try {
      const payload = await request("documentos-legais/preview", {
        codigo: state.selectedDocument.codigo,
        versao: draft.versao
      });
      const documentData = payload.data?.documento;
      if (!documentData || typeof documentData.conteudo_html !== "string") {
        throw new Error("O servidor retornou um rascunho inválido.");
      }
      fillDocumentSelect(elements.editDraftDocument, state.selectedDocument);
      elements.editDraftModal.dataset.idVersao = String(draft.id_documento_legal_versao);
      elements.editDraftVersion.value = documentData.versao;
      elements.editDraftSummary.value = documentData.resumo_alteracoes || "";
      elements.editDraftManifestation.checked = documentData.exige_nova_manifestacao === true;
      elements.editDraftContent.value = documentData.conteudo_html;
      setEditDraftControls(false);
      if (typeof window.abrirModal === "function") window.abrirModal("modalEditarRascunhoLegal");
    } catch (error) {
      showError(error.message);
      notifyError(error.message);
    } finally {
      setEditDraftAvailability();
    }
  });

  elements.editDraftSaveButton.addEventListener("click", () => {
    saveDraft(elements.editDraftSaveButton, draftPayloadFromEdit(), elements.editDraftModal, "Rascunho atualizado com sucesso.");
  });

  elements.publishVersionButton.addEventListener("click", () => {
    publishVersion(Number(elements.editDraftModal.dataset.idVersao));
  });

  elements.archiveVersionButton.addEventListener("click", () => {
    archiveVersion(Number(elements.editDraftModal.dataset.idVersao), true);
  });

  document.addEventListener("amagenda:menu-aba-alterada", event => {
    if (event.detail?.contexto === "super-admin" && event.detail?.aba === "documentos-legais") {
      loadAdmin();
    }
  });

  if (tab.classList.contains("ativa")) loadAdmin();
})();
