(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const modal = document.getElementById("modalPermissoesUsuario");
  if (!modal) return;

  const selects = [...modal.querySelectorAll("select[data-permissao]")];
  const btnSalvar = document.getElementById("btnSalvarPermissoesUsuario");
  const btnRestaurar = document.getElementById("btnRestaurarPermissoesUsuario");
  const idInput = document.getElementById("permissoes_usuario_id");
  let carregando = false;

  function aviso(tipo, mensagem, titulo = "Permissões") {
    const mensagens = window.MensagemSistema;
    if (mensagens) {
      if (tipo === "success") return mensagens.sucesso(mensagem, { titulo });
      if (tipo === "warning") return mensagens.aviso(mensagem, { titulo });
      return mensagens.erro(mensagem, { titulo });
    }
    console.error(`[${titulo}] ${mensagem}`);
  }

  async function requisicao(url, opcoes = {}) {
    const resposta = await fetch(url, { credentials: "same-origin", ...opcoes });
    const json = await resposta.json().catch(() => ({}));
    if (!resposta.ok || json.ok === false) throw new Error(json.user_msg || "Não foi possível concluir a operação.");
    return json;
  }

  function travar(estado) {
    carregando = estado;
    selects.forEach((select) => { select.disabled = estado || select.dataset.editavel === "false"; });
    [btnSalvar, btnRestaurar].forEach((botao) => { if (botao) botao.disabled = estado || botao.dataset.editavel === "false"; });
  }

  function pintar(select) {
    const label = select.closest("label");
    label?.classList.toggle("permissao-permitida", select.value === "permitido");
    label?.classList.toggle("permissao-bloqueada", select.value === "bloqueado");
  }

  function preencher(itens, podeEditar) {
    const mapa = new Map(itens.map((item) => [item.codigo, item]));
    selects.forEach((select) => {
      const item = mapa.get(select.dataset.permissao);
      const padrao = item?.padrao_permitido ? "Permitido" : "Bloqueado";
      select.innerHTML = `<option value="padrao">Padrão do perfil (${padrao})</option><option value="permitido">Permitir</option><option value="bloqueado">Bloquear</option>`;
      select.value = item?.estado || "padrao";
      select.dataset.editavel = podeEditar ? "true" : "false";
      pintar(select);
    });
    [btnSalvar, btnRestaurar].forEach((botao) => { if (botao) botao.dataset.editavel = podeEditar ? "true" : "false"; });
    travar(false);
  }

  async function carregar(idUsuario) {
    travar(true);
    try {
      const json = await requisicao(`${API}?path=painel/usuario/permissoes&id_usuario=${encodeURIComponent(idUsuario)}`);
      preencher(json.data?.permissoes || [], json.data?.pode_editar !== false);
    } catch (erro) {
      aviso("error", erro.message);
      selects.forEach((select) => { select.dataset.editavel = "false"; });
      [btnSalvar, btnRestaurar].forEach((botao) => { if (botao) botao.dataset.editavel = "false"; });
      travar(false);
    }
  }

  async function salvar() {
    if (carregando) return;
    const idUsuario = Number(idInput?.value || 0);
    if (!idUsuario) return aviso("warning", "Selecione um usuário.");
    const permissoes = Object.fromEntries(selects.map((select) => [select.dataset.permissao, select.value]));
    const corpo = new URLSearchParams({ id_usuario: String(idUsuario), permissoes: JSON.stringify(permissoes) });
    travar(true);
    try {
      const json = await requisicao(`${API}?path=painel/usuario/permissoes/salvar`, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: corpo });
      aviso("success", json.user_msg || "Permissões atualizadas com sucesso.");
      await carregar(idUsuario);
    } catch (erro) { aviso("error", erro.message); travar(false); }
  }

  async function restaurar() {
    if (carregando) return;
    const idUsuario = Number(idInput?.value || 0);
    if (!idUsuario) return;
    const confirmado = await window.MensagemSistema?.confirmar(
      "As personalizações serão removidas e o usuário voltará a seguir integralmente o padrão do perfil.",
      { titulo: "Restaurar padrão do perfil", textoConfirmar: "Restaurar" }
    );
    if (confirmado === false) return;
    const corpo = new URLSearchParams({ id_usuario: String(idUsuario) });
    travar(true);
    try {
      const json = await requisicao(`${API}?path=painel/usuario/permissoes/restaurar`, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: corpo });
      aviso("success", json.user_msg || "Padrão do perfil restaurado.");
      await carregar(idUsuario);
    } catch (erro) { aviso("error", erro.message); travar(false); }
  }

  selects.forEach((select) => select.addEventListener("change", () => pintar(select)));
  btnSalvar?.addEventListener("click", salvar);
  btnRestaurar?.addEventListener("click", restaurar);
  document.addEventListener("amagenda:permissoes-usuario:abrir", (evento) => carregar(evento.detail?.id_usuario));
  travar(true);
})();
