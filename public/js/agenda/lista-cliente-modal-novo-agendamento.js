// Cliente busca cliente no modal novo agendamento
(() => {
  "use strict";

  const inputBusca = document.getElementById("ag_cliente_busca");
  const inputId = document.getElementById("ag_cliente_id");
  const inputTel = document.getElementById("ag_cliente_tel");
  const boxResultados = document.getElementById("ag_cliente_resultados");
  const modal = document.getElementById("modalNovoAgendamento");
  const form = document.getElementById("formNovoAgendamento");

  if (!inputBusca || !inputId || !boxResultados) return;

  let timer = null;
  let ultimaBusca = "";
  let abortController = null;
  let resultadosAtuais = [];

  function normalizar(txt) {
    return String(txt ?? "").trim();
  }

  function onlyDigits(txt) {
    return String(txt ?? "").replace(/\D+/g, "");
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function getLista(json) {
    if (Array.isArray(json)) return json;
    if (Array.isArray(json?.data)) return json.data;
    if (Array.isArray(json?.data?.items)) return json.data.items;
    if (Array.isArray(json?.rows)) return json.rows;
    if (Array.isArray(json?.dados)) return json.dados;
    if (Array.isArray(json?.lista)) return json.lista;
    return [];
  }

  function mapCliente(c) {
    return {
      id: String(c?.id_cliente ?? c?.cliente_id ?? c?.id ?? "").trim(),
      nome: normalizar(c?.nome_completo ?? c?.nome ?? c?.cliente ?? c?.nome_cliente ?? ""),
      telefone: normalizar(c?.whatsapp_celular ?? c?.telefone ?? c?.celular ?? ""),
      cidade: normalizar(c?.cidade ?? ""),
      bairro: normalizar(c?.bairro ?? "")
    };
  }

  function getIdCliente(cliente) {
    return String(
      cliente?.id_cliente ??
      cliente?.cliente_id ??
      cliente?.id ??
      ""
    ).trim();
  }

  function limparSelecaoCliente() {
    inputId.value = "";
    if (inputTel) inputTel.value = "";
  }

  function abrirModalNovoCliente() {
    esconderResultados();

    if (typeof window.abrirModal === "function") {
      window.abrirModal("modalNovoCliente");
      return;
    }

    const btnAbrir = document.querySelector('[data-abrir-modal="modalNovoCliente"]');
    if (btnAbrir) {
      btnAbrir.click();
      return;
    }

    const modalNovoCliente = document.getElementById("modalNovoCliente");
    if (modalNovoCliente) {
      modalNovoCliente.classList.add("ativo");
      modalNovoCliente.setAttribute("aria-hidden", "false");
    }
  }

  function renderBotaoNovoCliente() {
    return `
      <div class="cliente-resultado-rodape">
        <button
          type="button"
          class="cliente-resultado-item cliente-resultado-novo"
          data-acao="novo-cliente"
        >
          <i class="fa-solid fa-user-plus"></i>
          <span>Cadastrar novo cliente</span>
        </button>
      </div>
    `;
  }

  function esconderResultados() {
    boxResultados.innerHTML = "";
    boxResultados.hidden = true;
  }

  function mostrarMensagem(msg) {
    boxResultados.innerHTML = `
      <div class="cliente-resultado-item cliente-resultado-vazio">
        ${escapeHtml(msg)}
      </div>
      ${renderBotaoNovoCliente()}
    `;
    boxResultados.hidden = false;
  }

  function renderResultados(lista) {
    resultadosAtuais = Array.isArray(lista) ? lista : [];

    if (!resultadosAtuais.length) {
      mostrarMensagem("Nenhum cliente encontrado");
      return;
    }

    const html = resultadosAtuais.map((c) => {
      const nome = escapeHtml(c.nome || "Cliente sem nome");
      const telefone = escapeHtml(c.telefone || "");
      const local = escapeHtml([c.bairro, c.cidade].filter(Boolean).join(" / "));

      return `
        <button
          type="button"
          class="cliente-resultado-item"
          data-id="${escapeHtml(c.id)}"
          data-nome="${nome}"
          data-telefone="${telefone}"
        >
          <div class="cliente-resultado-nome">${nome}</div>
          <div class="cliente-resultado-info">
            ${telefone ? `<span>${telefone}</span>` : ""}
            ${local ? `<span>${local}</span>` : ""}
          </div>
        </button>
      `;
    }).join("");

    boxResultados.innerHTML = html + renderBotaoNovoCliente();
    boxResultados.hidden = false;
  }

  function selecionarCliente(cliente) {
    if (!cliente) return;

    inputBusca.value = cliente.nome || "";
    inputId.value = cliente.id || "";
    if (inputTel) inputTel.value = cliente.telefone || "";

    esconderResultados();
  }

  async function buscarClientes(termo) {
    const busca = normalizar(termo);
    const buscaNumero = onlyDigits(busca);

    if (busca.length < 2 && buscaNumero.length < 2) {
      esconderResultados();
      limparSelecaoCliente();
      return;
    }

    if (busca === ultimaBusca) return;
    ultimaBusca = busca;

    if (abortController) {
      abortController.abort();
    }

    abortController = new AbortController();

    mostrarMensagem("Pesquisando clientes...");

    try {
      const params = new URLSearchParams();
      params.set("path", "painel/cliente/listar");
      params.set("q", busca);
      params.set("status", "ativo");
      params.set("pagina", "1");
      params.set("limite", "20");

      const resp = await fetch(`/public/api/api_central.php?${params.toString()}`, {
        method: "GET",
        headers: { Accept: "application/json" },
        cache: "no-store",
        credentials: "same-origin",
        signal: abortController.signal
      });

      const json = await resp.json().catch(() => null);

      if (!resp.ok || !json || json?.ok === false) {
        mostrarMensagem("Erro ao pesquisar clientes");
        return;
      }

      let lista = getLista(json)
        .map(mapCliente)
        .filter((c) => c.id && c.nome);

      if (buscaNumero.length >= 2) {
        lista = lista.filter((c) => {
          const tel = onlyDigits(c.telefone);
          return tel.includes(buscaNumero);
        });
      }

      renderResultados(lista);
    } catch (err) {
      if (err?.name === "AbortError") return;
      console.error("[modal-novo-agendamento-clientes-busca]", err);
      mostrarMensagem("Não foi possível pesquisar clientes");
    }
  }

  inputBusca.addEventListener("input", () => {
    const valor = normalizar(inputBusca.value);

    limparSelecaoCliente();

    clearTimeout(timer);
    timer = setTimeout(() => {
      buscarClientes(valor);
    }, 350);
  });

  inputBusca.addEventListener("focus", () => {
    const valor = normalizar(inputBusca.value);
    const valorNumero = onlyDigits(valor);

    if ((valor.length >= 2 || valorNumero.length >= 2) && resultadosAtuais.length) {
      boxResultados.hidden = false;
    }
  });

  boxResultados.addEventListener("click", (e) => {
    const botaoNovo = e.target.closest('[data-acao="novo-cliente"]');
    if (botaoNovo) {
      abrirModalNovoCliente();
      return;
    }

    const botao = e.target.closest(".cliente-resultado-item[data-id]");
    if (!botao) return;

    const id = normalizar(botao.getAttribute("data-id"));
    const cliente = resultadosAtuais.find((c) => String(c.id) === String(id));

    if (cliente) {
      selecionarCliente(cliente);
    }
  });

  document.addEventListener("click", (e) => {
    const clicouDentro =
      e.target.closest("#ag_cliente_busca") ||
      e.target.closest("#ag_cliente_resultados");

    if (!clicouDentro) {
      esconderResultados();
    }
  });

  function selecionarClienteCadastrado(detail) {
    const cliente = mapCliente(detail || {});
    const idCliente = getIdCliente(cliente);

    if (!idCliente || !cliente.nome) return;

    const existe = resultadosAtuais.some((item) => {
      return String(item.id) === String(idCliente);
    });

    if (!existe) {
      resultadosAtuais = [cliente, ...resultadosAtuais];
    }

    selecionarCliente(cliente);
    ultimaBusca = "";
  }

  window.addEventListener("cliente:cadastrado", (e) => {
    selecionarClienteCadastrado(e?.detail || null);
  });

  document.addEventListener("cliente:cadastrado", (e) => {
    selecionarClienteCadastrado(e?.detail || null);
  });

  if (form) {
    form.addEventListener("reset", () => {
      setTimeout(() => {
        inputBusca.value = "";
        inputId.value = "";
        if (inputTel) inputTel.value = "";
        resultadosAtuais = [];
        ultimaBusca = "";
        esconderResultados();
      }, 0);
    });
  }

  if (modal) {
    const observer = new MutationObserver(() => {
      const aberto =
        modal.classList.contains("ativo") ||
        modal.classList.contains("open") ||
        modal.getAttribute("aria-hidden") === "false";

      if (!aberto) {
        esconderResultados();
      }
    });

    observer.observe(modal, {
      attributes: true,
      attributeFilter: ["class", "aria-hidden"]
    });
  }
})();
