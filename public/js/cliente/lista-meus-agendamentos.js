(() => {
  "use strict";

  /*
  |--------------------------------------------------------------------------
  | LISTAGEM DOS AGENDAMENTOS DO CLIENTE
  |--------------------------------------------------------------------------
  |
  | Responsabilidade:
  | - carregar os agendamentos;
  | - pesquisar;
  | - paginar;
  | - renderizar os cards.
  |
  | Dados cadastrais são tratados em:
  | salvar-dados-cadastrais.js
  |
  */

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";

  const ITENS_POR_PAGINA = 5;

  const lista = document.getElementById("ma_lista");
  const estado = document.getElementById("ma_estado");

  const campoPesquisa = document.getElementById(
    "ma_pesquisa"
  );

  const botaoLimparPesquisa = document.getElementById(
    "ma_limpar_pesquisa"
  );

  const paginacao = document.getElementById(
    "ma_paginacao"
  );

  const botaoAnterior = document.getElementById(
    "ma_anterior"
  );

  const botaoProxima = document.getElementById(
    "ma_proxima"
  );

  const paginaInfo = document.getElementById(
    "ma_pagina_info"
  );

  let iniciado = false;
  let paginaAtual = 1;
  let todosAgendamentos = [];
  let agendamentosFiltrados = [];

  if (
    !lista ||
    !estado ||
    !campoPesquisa ||
    !botaoLimparPesquisa ||
    !paginacao ||
    !botaoAnterior ||
    !botaoProxima ||
    !paginaInfo
  ) {
    return;
  }

  /*
  |--------------------------------------------------------------------------
  | UTILITÁRIOS
  |--------------------------------------------------------------------------
  */

  function escaparHtml(valor) {
    return String(valor ?? "").replace(
      /[&<>'"]/g,
      caractere => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        "'": "&#39;",
        '"': "&quot;"
      })[caractere]
    );
  }

  function normalizarTexto(valor) {
    return String(valor ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .trim();
  }

  function classeStatus(status) {
    const normalizado = normalizarTexto(status);

    return [
      "confirmado",
      "pendente",
      "cancelado",
      "concluido",
      "faltou"
    ].includes(normalizado)
      ? normalizado
      : "neutro";
  }

  function rotuloStatus(status) {
    const normalizado = normalizarTexto(status);

    return ({
      confirmado: "Confirmado",
      pendente: "Pendente",
      cancelado: "Cancelado",
      concluido: "Concluído",
      faltou: "Não compareceu"
    })[normalizado] || "Aguardando atualização";
  }

  function formatarData(dataIso) {
    const partes = String(dataIso || "").split("-");

    return partes.length === 3
      ? `${partes[2]}/${partes[1]}/${partes[0]}`
      : String(dataIso || "");
  }

  function formatarValor(valor) {
    return new Intl.NumberFormat("pt-BR", {
      style: "currency",
      currency: "BRL"
    }).format(Number(valor || 0));
  }

  function textoQuantidade(quantidade) {
    return quantidade === 1
      ? "1 agendamento encontrado."
      : `${quantidade} agendamentos encontrados.`;
  }

  /*
  |--------------------------------------------------------------------------
  | REQUISIÇÃO
  |--------------------------------------------------------------------------
  */

  async function requisitar(caminho, opcoes = {}) {
    const resposta = await fetch(
      `${API}?path=${encodeURIComponent(caminho)}`,
      {
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          ...(opcoes.headers || {})
        },
        ...opcoes
      }
    );

    const json = await resposta
      .json()
      .catch(() => null);

    if (
      resposta.status === 401 ||
      resposta.status === 403
    ) {
      window.location.replace(LOGIN);

      throw new Error("Sessão expirada.");
    }

    if (
      !resposta.ok ||
      !json ||
      json.ok !== true
    ) {
      const erro = new Error(
        json?.user_msg ||
        "Não foi possível concluir a solicitação."
      );

      erro.campos = json?.fields || {};

      throw erro;
    }

    return json;
  }

  /*
  |--------------------------------------------------------------------------
  | CARD DO AGENDAMENTO
  |--------------------------------------------------------------------------
  */

  function criarCardAgendamento(item) {
    const status = classeStatus(item.status);

    return `
      <article
        class="ma-item"
        data-id="${Number(item.id_agendamento) || 0}"
      >
        <div class="ma-top">
          <div>
            <div class="ma-titulo">
              ${escaparHtml(formatarData(item.data))}
              às
              ${escaparHtml(item.hora_inicio)}
            </div>

            <div class="ma-horario-fim">
              Término previsto:
              ${escaparHtml(item.hora_fim)}
            </div>
          </div>

          <span class="badge-status ${status}">
            ${escaparHtml(rotuloStatus(item.status))}
          </span>
        </div>

        <div class="ma-sub">
          <div>
            <i
              class="fa-solid fa-scissors"
              aria-hidden="true"
            ></i>

            <span>
              <strong>Serviço:</strong>
              ${escaparHtml(item.servico)}
            </span>
          </div>

          <div>
            <i
              class="fa-solid fa-user"
              aria-hidden="true"
            ></i>

            <span>
              <strong>Profissional:</strong>
              ${escaparHtml(item.profissional)}
            </span>
          </div>

          <div>
            <i
              class="fa-solid fa-wallet"
              aria-hidden="true"
            ></i>

            <span>
              <strong>Valor:</strong>
              ${escaparHtml(formatarValor(item.valor))}
            </span>
          </div>
        </div>
      </article>
    `;
  }

  /*
  |--------------------------------------------------------------------------
  | ESTADOS VAZIOS
  |--------------------------------------------------------------------------
  */

  function renderizarSemAgendamentos() {
    lista.innerHTML = "";
    lista.hidden = true;
    paginacao.hidden = true;

    estado.className =
      "cliente-lista-estado vazio";

    estado.innerHTML = `
      <i
        class="fa-regular fa-calendar-xmark"
        aria-hidden="true"
      ></i>

      <strong>
        Você ainda não possui agendamentos.
      </strong>

      <span>
        Quando um horário for marcado,
        ele aparecerá aqui.
      </span>

      <a
        class="botao-geral destaque"
        href="/public/views/cliente-agendamento.html"
      >
        Agendar agora
      </a>
    `;
  }

  function renderizarPesquisaVazia() {
    lista.innerHTML = "";
    lista.hidden = true;
    paginacao.hidden = true;

    estado.className =
      "cliente-lista-estado vazio";

    estado.innerHTML = `
      <i
        class="fa-solid fa-magnifying-glass"
        aria-hidden="true"
      ></i>

      <strong>
        Nenhum agendamento encontrado.
      </strong>

      <span>
        Tente pesquisar por outra data,
        serviço, profissional ou status.
      </span>
    `;
  }

  /*
  |--------------------------------------------------------------------------
  | PAGINAÇÃO
  |--------------------------------------------------------------------------
  */

  function atualizarPaginacao(totalPaginas) {
    paginacao.hidden = false;

    paginaInfo.textContent =
      `Página ${paginaAtual} de ${totalPaginas}`;

    botaoAnterior.disabled = paginaAtual <= 1;

    botaoProxima.disabled =
      paginaAtual >= totalPaginas;
  }

  function renderizarPagina() {
    if (todosAgendamentos.length === 0) {
      renderizarSemAgendamentos();
      return;
    }

    if (agendamentosFiltrados.length === 0) {
      renderizarPesquisaVazia();
      return;
    }

    const totalPaginas = Math.max(
      1,
      Math.ceil(
        agendamentosFiltrados.length /
        ITENS_POR_PAGINA
      )
    );

    if (paginaAtual > totalPaginas) {
      paginaAtual = totalPaginas;
    }

    if (paginaAtual < 1) {
      paginaAtual = 1;
    }

    const indiceInicial =
      (paginaAtual - 1) * ITENS_POR_PAGINA;

    const itensPagina = agendamentosFiltrados.slice(
      indiceInicial,
      indiceInicial + ITENS_POR_PAGINA
    );

    lista.innerHTML = itensPagina
      .map(criarCardAgendamento)
      .join("");

    lista.hidden = false;

    estado.className =
      "cliente-lista-estado sucesso";

    const pesquisando =
      campoPesquisa.value.trim() !== "";

    estado.textContent = pesquisando
      ? `${textoQuantidade(
          agendamentosFiltrados.length
        )} Pesquisa aplicada sobre ${
          todosAgendamentos.length
        } registros.`
      : textoQuantidade(
          agendamentosFiltrados.length
        );

    atualizarPaginacao(totalPaginas);
  }

  /*
  |--------------------------------------------------------------------------
  | PESQUISA
  |--------------------------------------------------------------------------
  */

  function criarTextoPesquisa(item) {
    return normalizarTexto([
      item.id_agendamento,
      item.data,
      formatarData(item.data),
      item.hora_inicio,
      item.hora_fim,
      item.servico,
      item.profissional,
      item.status,
      rotuloStatus(item.status),
      item.valor,
      formatarValor(item.valor)
    ].join(" "));
  }

  function aplicarPesquisa() {
    const termo = normalizarTexto(
      campoPesquisa.value
    );

    botaoLimparPesquisa.hidden =
      termo === "";

    paginaAtual = 1;

    if (termo === "") {
      agendamentosFiltrados = [
        ...todosAgendamentos
      ];

      renderizarPagina();
      return;
    }

    agendamentosFiltrados =
      todosAgendamentos.filter(item =>
        criarTextoPesquisa(item).includes(termo)
      );

    renderizarPagina();
  }

  /*
  |--------------------------------------------------------------------------
  | CARREGAMENTO
  |--------------------------------------------------------------------------
  */

  async function carregarAgendamentos() {
    estado.className = "cliente-lista-estado";

    estado.innerHTML = `
      <i
        class="fa-solid fa-spinner fa-spin"
        aria-hidden="true"
      ></i>
      Carregando seus agendamentos...
    `;

    lista.hidden = true;
    paginacao.hidden = true;
    campoPesquisa.disabled = true;
    botaoLimparPesquisa.hidden = true;

    try {
      const json = await requisitar(
        "cliente/agendamentos/listar"
      );

      todosAgendamentos = Array.isArray(
        json.data?.itens
      )
        ? json.data.itens
        : [];

      agendamentosFiltrados = [
        ...todosAgendamentos
      ];

      paginaAtual = 1;

      renderizarPagina();

    } catch (erro) {

      todosAgendamentos = [];
      agendamentosFiltrados = [];

      lista.innerHTML = "";
      lista.hidden = true;
      paginacao.hidden = true;

      estado.className =
        "cliente-lista-estado erro";

      estado.textContent =
        erro.message ||
        "Não foi possível carregar os agendamentos.";

    } finally {
      campoPesquisa.disabled = false;
    }
  }

  /*
  |--------------------------------------------------------------------------
  | EVENTOS DA PESQUISA
  |--------------------------------------------------------------------------
  */

  campoPesquisa.addEventListener(
    "input",
    aplicarPesquisa
  );

  botaoLimparPesquisa.addEventListener(
    "click",
    () => {
      campoPesquisa.value = "";
      campoPesquisa.focus();
      aplicarPesquisa();
    }
  );

  /*
  |--------------------------------------------------------------------------
  | EVENTOS DA PAGINAÇÃO
  |--------------------------------------------------------------------------
  */

  botaoAnterior.addEventListener(
    "click",
    () => {
      if (paginaAtual <= 1) {
        return;
      }

      paginaAtual -= 1;
      renderizarPagina();
    }
  );

  botaoProxima.addEventListener(
    "click",
    () => {
      const totalPaginas = Math.max(
        1,
        Math.ceil(
          agendamentosFiltrados.length /
          ITENS_POR_PAGINA
        )
      );

      if (paginaAtual >= totalPaginas) {
        return;
      }

      paginaAtual += 1;
      renderizarPagina();
    }
  );

  /*
  |--------------------------------------------------------------------------
  | INICIALIZAÇÃO
  |--------------------------------------------------------------------------
  */

  async function iniciar(auth) {
    if (
      iniciado ||
      auth?.tipo_usuario !== "cliente"
    ) {
      return;
    }

    iniciado = true;

    await carregarAgendamentos();
  }

  document.addEventListener(
    "amagenda:sessao-carregada",
    evento => iniciar(evento.detail)
  );

  document.addEventListener(
    "DOMContentLoaded",
    () => {
      if (window.__AUTH__) {
        iniciar(window.__AUTH__);
      }
    }
  );
})();