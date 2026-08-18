/* Busca e troca o cliente no modal Editar Agendamento, no padrão do cadastro. */
(() => {
  "use strict";

  const busca = document.getElementById("ed_ag_cliente_busca");
  const idCliente = document.getElementById("ed_ag_cliente_id");
  const telefone = document.getElementById("ed_ag_cliente_tel");
  const resultados = document.getElementById("ed_ag_cliente_resultados");
  const modal = document.getElementById("modalEditarAgendamento");
  if (!busca || !idCliente || !resultados) return;

  let timer = null;
  let abortador = null;
  let itensAtuais = [];
  const texto = (valor) => String(valor ?? "").trim();
  const digitos = (valor) => String(valor ?? "").replace(/\D+/g, "");
  const esc = (valor) => String(valor ?? "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
  const lista = (json) => Array.isArray(json?.data?.items) ? json.data.items : Array.isArray(json?.data) ? json.data : [];
  const mapear = (c) => ({ id: texto(c?.id_cliente ?? c?.id), nome: texto(c?.nome_completo ?? c?.nome), telefone: texto(c?.whatsapp_celular ?? c?.telefone), cidade: texto(c?.cidade), bairro: texto(c?.bairro) });

  function esconder() { resultados.hidden = true; resultados.innerHTML = ""; }
  function rodape() { return '<div class="cliente-resultado-rodape"><button type="button" class="cliente-resultado-item cliente-resultado-novo" data-novo-cliente><i class="fa-solid fa-user-plus"></i><span>Cadastrar novo cliente</span></button></div>'; }
  function mensagem(msg) { resultados.innerHTML = `<div class="cliente-resultado-item cliente-resultado-vazio">${esc(msg)}</div>${rodape()}`; resultados.hidden = false; }

  function renderizar(itens) {
    itensAtuais = itens;
    if (!itens.length) { mensagem("Nenhum cliente encontrado"); return; }
    resultados.innerHTML = itens.map(c => `<button type="button" class="cliente-resultado-item" data-id="${esc(c.id)}"><div class="cliente-resultado-nome">${esc(c.nome)}</div><div class="cliente-resultado-info">${c.telefone ? `<span>${esc(c.telefone)}</span>` : ""}${c.bairro || c.cidade ? `<span>${esc([c.bairro,c.cidade].filter(Boolean).join(" / "))}</span>` : ""}</div></button>`).join("") + rodape();
    resultados.hidden = false;
  }

  function selecionar(cliente) {
    busca.value = cliente.nome;
    idCliente.value = cliente.id;
    if (telefone) telefone.value = cliente.telefone;
    esconder();
  }

  async function pesquisar() {
    const termo = texto(busca.value);
    if (termo.length < 2 && digitos(termo).length < 2) { esconder(); return; }
    abortador?.abort(); abortador = new AbortController(); mensagem("Pesquisando clientes...");
    try {
      const params = new URLSearchParams({ path:"painel/cliente/listar", q:termo, status:"ativo", pagina:"1", limite:"20" });
      const resposta = await fetch(`/public/api/api_central.php?${params}`, { credentials:"same-origin", cache:"no-store", headers:{Accept:"application/json"}, signal:abortador.signal });
      const json = await resposta.json().catch(() => null);
      if (!resposta.ok || !json || json.ok === false) throw new Error();
      renderizar(lista(json).map(mapear).filter(c => c.id && c.nome));
    } catch (erro) { if (erro?.name !== "AbortError") mensagem("Não foi possível pesquisar clientes"); }
  }

  busca.addEventListener("input", () => { idCliente.value = ""; if (telefone) telefone.value = ""; clearTimeout(timer); timer = setTimeout(pesquisar, 350); });
  busca.addEventListener("focus", () => { if (itensAtuais.length && texto(busca.value).length >= 2) resultados.hidden = false; });
  resultados.addEventListener("click", (evento) => {
    if (evento.target.closest("[data-novo-cliente]")) { document.querySelector('[data-abrir-modal="modalNovoCliente"]')?.click(); esconder(); return; }
    const botao = evento.target.closest(".cliente-resultado-item[data-id]");
    const cliente = itensAtuais.find(c => c.id === texto(botao?.dataset.id));
    if (cliente) selecionar(cliente);
  });
  document.addEventListener("click", (evento) => { if (!evento.target.closest("#ed_ag_cliente_busca, #ed_ag_cliente_resultados")) esconder(); });
  const selecionarCadastrado = (evento) => {
    const cliente = mapear(evento?.detail || {});
    if (cliente.id && cliente.nome && modal?.getAttribute("aria-hidden") === "false") selecionar(cliente);
  };
  window.addEventListener("cliente:cadastrado", selecionarCadastrado);
  document.addEventListener("cliente:cadastrado", selecionarCadastrado);
  modal && new MutationObserver(() => { if (modal.getAttribute("aria-hidden") === "true") esconder(); }).observe(modal, { attributes:true, attributeFilter:["class","aria-hidden"] });
})();
