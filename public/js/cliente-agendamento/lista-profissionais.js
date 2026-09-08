(() => {
  "use strict";

  const API_BASE = "/public/api/api_central.php?path=";
  const FOTO_PADRAO = "/public/imagens/avatar-default.png";
  const app = window.ClienteAgendamento = window.ClienteAgendamento || {};

  app.estado = app.estado || {
    profissional: null,
    servico: null,
    data: "",
    hora: "",
    csrfToken: ""
  };

  app.mensagem = function (tipo, texto) {
    const sistema = window.MensagemSistema;
    if (sistema && typeof sistema[tipo] === "function") {
      sistema[tipo](texto);
      return;
    }
    console.error(`[cliente-agendamento] ${texto}`);
  };

  app.api = async function (rota, opcoes = {}) {
    const resposta = await fetch(`${API_BASE}${encodeURIComponent(rota)}${opcoes.query || ""}`, {
      method: opcoes.method || "GET",
      body: opcoes.body,
      signal: opcoes.signal,
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json", ...(opcoes.headers || {}) }
    });
    const json = await resposta.json().catch(() => null);
    if (resposta.status === 401) {
      window.location.replace("/public/views/login-cliente.php");
      throw new Error("Sessão expirada.");
    }
    if (!json || typeof json !== "object") {
      throw new Error("O servidor retornou uma resposta inválida.");
    }
    if (!resposta.ok || json.ok !== true) {
      const erro = new Error(json.user_msg || "Não foi possível concluir a solicitação.");
      erro.status = resposta.status;
      erro.code = json.code || "API_ERROR";
      erro.data = json;
      throw erro;
    }
    return json;
  };

  function estadoLista(lista, texto) {
    lista.replaceChildren();
    const vazio = document.createElement("div");
    vazio.className = "u-empty";
    vazio.textContent = texto;
    lista.appendChild(vazio);
  }

  document.addEventListener("DOMContentLoaded", () => {
    const lista = document.getElementById("listaProfissionais");
    const btnContinuar = document.getElementById("btnContinuarProfissional");
    const inId = document.getElementById("ag_profissional_id");
    const inNome = document.getElementById("ag_profissional_nome");
    if (!lista || !btnContinuar || !inId || !inNome) return;

    let profissionais = [];

    function limparPosteriores() {
      app.estado.servico = null;
      app.estado.data = "";
      app.estado.hora = "";
      document.getElementById("ag_servicos_json").value = "[]";
      document.getElementById("ag_servicos_total").value = "0";
      document.getElementById("ag_data_iso").value = "";
      document.getElementById("ag_hora").value = "";
    }

    function selecionar(profissional, item) {
      const mudou = String(app.estado.profissional?.id_profissional || "") !== String(profissional.id_profissional);
      app.estado.profissional = profissional;
      inId.value = String(profissional.id_profissional);
      inNome.value = profissional.nome;
      lista.querySelectorAll(".u-item.is-active").forEach((el) => {
        el.classList.remove("is-active");
        el.setAttribute("aria-pressed", "false");
      });
      item.classList.add("is-active");
      item.setAttribute("aria-pressed", "true");
      btnContinuar.disabled = false;
      btnContinuar.setAttribute("aria-disabled", "false");
      if (mudou) limparPosteriores();
      document.dispatchEvent(new CustomEvent("cliente-agendamento:profissional-alterado", { detail: profissional }));
    }

    function renderizar() {
      lista.replaceChildren();
      profissionais.forEach((profissional) => {
        const item = document.createElement("button");
        item.type = "button";
        item.className = "u-item";
        item.setAttribute("aria-pressed", "false");

        const avatar = document.createElement("div");
        avatar.className = "u-avatar";
        const img = document.createElement("img");
        img.src = profissional.foto_url || FOTO_PADRAO;
        img.alt = `Foto de ${profissional.nome}`;
        img.loading = "lazy";
        img.addEventListener("error", () => {
          if (img.src.endsWith(FOTO_PADRAO)) return;
          img.src = FOTO_PADRAO;
        }, { once: true });
        avatar.appendChild(img);

        const info = document.createElement("div");
        info.className = "u-info";
        const nome = document.createElement("div");
        nome.className = "u-name";
        nome.textContent = profissional.nome;
        const descricao = document.createElement("div");
        descricao.className = "u-desc";
        descricao.textContent = profissional.especialidade || profissional.descricao || "Profissional";
        info.append(nome, descricao);

        const check = document.createElement("div");
        check.className = "u-check";
        check.setAttribute("aria-hidden", "true");
        const icone = document.createElement("i");
        icone.className = "fa-solid fa-check";
        check.appendChild(icone);
        item.append(avatar, info, check);
        item.addEventListener("click", () => selecionar(profissional, item));
        lista.appendChild(item);
      });
    }

    async function carregar() {
      estadoLista(lista, "Carregando profissionais...");
      btnContinuar.disabled = true;
      btnContinuar.setAttribute("aria-disabled", "true");
      try {
        const json = await app.api("cliente/agendamento/profissionais");
        profissionais = Array.isArray(json.data?.itens) ? json.data.itens : [];
        app.estado.csrfToken = String(json.data?.csrf_token || "");
        if (!profissionais.length) {
          estadoLista(lista, "Nenhum profissional disponível para agendamento no momento.");
          return;
        }
        renderizar();
      } catch (erro) {
        estadoLista(lista, erro.message || "Não foi possível carregar os profissionais.");
        app.mensagem("erro", erro.message || "Não foi possível carregar os profissionais.");
      }
    }

    btnContinuar.addEventListener("click", () => {
      if (!app.estado.profissional || !inId.value) {
        app.mensagem("aviso", "Selecione um profissional para continuar.");
        return;
      }
      window.Tabs?.go?.("servico");
    });

    document.addEventListener("cliente-agendamento:resetar", () => {
      app.estado.profissional = null;
      inId.value = "";
      inNome.value = "";
      lista.querySelectorAll(".is-active").forEach((el) => el.classList.remove("is-active"));
      btnContinuar.disabled = true;
      btnContinuar.setAttribute("aria-disabled", "true");
    });

    carregar();
  });
})();
