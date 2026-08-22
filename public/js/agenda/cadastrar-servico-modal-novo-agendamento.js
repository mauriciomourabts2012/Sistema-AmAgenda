// Cadastrar servico pelo modal Novo Agendamento
(() => {
  "use strict";

  const API_URL = "/public/api/api_central.php?path=agenda/servico-profissional/cadastrar-agendamento";

  const modal = document.getElementById("modalNovoServicoAgendamento");
  const form = document.getElementById("formNovoServicoAgendamento");
  const btnSalvar = document.getElementById("btnSalvarServicoAgendamento");

  const campos = {
    idProfissional: document.getElementById("serv_ag_id_profissional"),
    profissionalNome: document.getElementById("serv_ag_profissional_nome"),
    nome: document.getElementById("serv_ag_nome"),
    duracao: document.getElementById("serv_ag_duracao"),
    valor: document.getElementById("serv_ag_valor"),
    status: document.getElementById("serv_ag_status"),
    descricao: document.getElementById("serv_ag_descricao")
  };

  if (!form || !btnSalvar) return;

  function normalizar(v) {
    return String(v ?? "").trim();
  }

  function perfilRecepcionista() {
    const auth = window.__AUTH__ || window.AUTH_USER || window.usuarioLogado || {};
    const perfil = normalizar(auth.perfil_nome ?? auth.perfil ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
    return perfil === "recepcionista" || perfil === "recepcao";
  }

  function bloquearModalParaRecepcionista() {
    if (!modal || !perfilRecepcionista()) return;
    const aberto = modal.classList.contains("ativo") || modal.getAttribute("aria-hidden") === "false";
    if (!aberto) return;
    modal.classList.remove("ativo", "aberto", "show");
    modal.setAttribute("aria-hidden", "true");
    mostrarMensagem("warning", "Acesso negado. O perfil Recepcionista não pode cadastrar novos serviços.");
  }

  function escapeHtml(v) {
    return String(v ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function getToastStack() {
    let stack = document.querySelector(".ui-toast-stack");

    if (!stack) {
      stack = document.createElement("div");
      stack.className = "ui-toast-stack";
      document.body.appendChild(stack);
    }

    return stack;
  }

  function fecharAlerta(alerta, callback) {
    if (!alerta || !alerta.isConnected) {
      if (typeof callback === "function") callback();
      return;
    }

    alerta.classList.add("is-leaving");

    setTimeout(() => {
      if (alerta.isConnected) alerta.remove();
      if (typeof callback === "function") callback();
    }, 150);
  }

  function mostrarMensagem(tipo, mensagem, callback) {
    const config = {
      success: {
        classe: "ui-alert--success",
        titulo: "Sucesso",
        icone: "✓",
        botao: false,
        timeout: 2200
      },
      warning: {
        classe: "ui-alert--warning",
        titulo: "Atenção",
        icone: "!",
        botao: true,
        timeout: null
      },
      danger: {
        classe: "ui-alert--danger",
        titulo: "Erro",
        icone: "×",
        botao: true,
        timeout: null
      }
    };

    const cfg = config[tipo] || config.warning;
    const stack = getToastStack();

    const alerta = document.createElement("div");
    alerta.className = `ui-alert ${cfg.classe}`;
    alerta.setAttribute("role", "alert");
    alerta.setAttribute("aria-live", tipo === "success" ? "polite" : "assertive");

    alerta.innerHTML = `
      <div class="ui-alert__icon" aria-hidden="true">${cfg.icone}</div>

      <div class="ui-alert__content">
        <p class="ui-alert__title">${escapeHtml(cfg.titulo)}</p>
        <div class="ui-alert__msg">${escapeHtml(mensagem)}</div>
      </div>

      <div class="ui-alert__actions">
        ${
          cfg.botao
            ? `<button type="button" class="ui-alert__btn ui-alert__btn--primary">OK</button>`
            : ``
        }
      </div>
    `;

    stack.appendChild(alerta);

    if (cfg.botao) {
      const btnOk = alerta.querySelector(".ui-alert__btn");

      btnOk?.addEventListener("click", () => {
        fecharAlerta(alerta, callback);
      });

      btnOk?.focus();
      return;
    }

    setTimeout(() => {
      fecharAlerta(alerta, callback);
    }, cfg.timeout);
  }

  function msgOk(mensagem, callback) {
    mostrarMensagem("success", mensagem, callback);
  }

  function msgWarn(mensagem, callback) {
    mostrarMensagem("warning", mensagem, callback);
  }

  function msgErro(mensagem, callback) {
    mostrarMensagem("danger", mensagem, callback);
  }

  function cssEscape(v) {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(v);
    }

    return String(v).replace(/"/g, '\\"');
  }

  function campoContainer(campo) {
    return campo?.closest?.(".modal-campo") || null;
  }

  function erroEl(campo) {
    if (!campo?.id) return null;
    return document.querySelector(`[data-erro-for="${cssEscape(campo.id)}"]`);
  }

  function setErro(campo, mensagem) {
    const box = campoContainer(campo);
    const msg = erroEl(campo);

    if (box) box.classList.add("erro");

    if (msg) {
      msg.textContent = mensagem || "";
      msg.classList.add("ativo");
    }
  }

  function limparErro(campo) {
    const box = campoContainer(campo);
    const msg = erroEl(campo);

    if (box) box.classList.remove("erro");

    if (msg) {
      msg.textContent = "";
      msg.classList.remove("ativo");
    }
  }

  function limparErros() {
    [
      campos.idProfissional,
      campos.nome,
      campos.duracao,
      campos.valor,
      campos.status,
      campos.descricao
    ].forEach(limparErro);
  }

  function focarComAviso(campo, mensagem) {
    setErro(campo, mensagem);

    msgWarn(mensagem, () => {
      campo?.focus();
    });
  }

  function valorDecimalBR(v) {
    let raw = normalizar(v);

    if (!raw) return null;

    raw = raw.replace(/R\$/gi, "").replace(/\s+/g, "");

    if (!/^\d{1,8}([,.]\d{1,2})?$/.test(raw)) {
      return null;
    }

    raw = raw.replace(",", ".");

    const n = Number(raw);
    if (!Number.isFinite(n) || n <= 0 || n > 99999999.99) return null;

    return n.toFixed(2);
  }

  function setLoading(on) {
    btnSalvar.disabled = !!on;
    btnSalvar.dataset.textOriginal = btnSalvar.dataset.textOriginal || btnSalvar.innerHTML;
    btnSalvar.innerHTML = on
      ? "Salvando..."
      : btnSalvar.dataset.textOriginal;
  }

  function fecharModalServico() {
    if (modal) {
      modal.classList.remove("ativo", "open", "aberto");
      modal.setAttribute("aria-hidden", "true");
    }
  }

  function limparFormulario() {
    if (campos.nome) campos.nome.value = "";
    if (campos.duracao) campos.duracao.value = "";
    if (campos.valor) campos.valor.value = "";
    if (campos.status) campos.status.value = "ativo";
    if (campos.descricao) campos.descricao.value = "";

    limparErros();
  }

  function aplicarErrosBackend(fields) {
    let primeiroCampo = null;

    const mapa = {
      id_profissional: campos.idProfissional,
      serv_ag_id_profissional: campos.idProfissional,

      cfg_servico_nome: campos.nome,
      serv_ag_nome: campos.nome,
      nome: campos.nome,

      cfg_servico_duracao: campos.duracao,
      serv_ag_duracao: campos.duracao,
      duracao_min: campos.duracao,
      duracao: campos.duracao,

      cfg_servico_valor: campos.valor,
      serv_ag_valor: campos.valor,
      valor: campos.valor,

      cfg_servico_status: campos.status,
      serv_ag_status: campos.status,
      status: campos.status,

      cfg_servico_descricao: campos.descricao,
      serv_ag_descricao: campos.descricao,
      descricao: campos.descricao
    };

    Object.entries(fields || {}).forEach(([chave, mensagem]) => {
      const campo = mapa[chave];

      if (!campo) return;

      setErro(campo, mensagem);

      if (!primeiroCampo) {
        primeiroCampo = campo;
      }
    });

    return { primeiroCampo };
  }

  function extrairServico(data) {
    return data?.data?.servico || data?.servico || data?.data || null;
  }

  function avisarServicoCadastrado(data) {
    const servico = extrairServico(data);

    document.dispatchEvent(
      new CustomEvent("agenda:servico-agendamento:cadastrado", {
        detail: {
          resposta: data,
          servico
        }
      })
    );
  }

  function validar() {
    if (perfilRecepcionista()) {
      mostrarMensagem("warning", "Acesso negado. O perfil Recepcionista não pode cadastrar novos serviços.");
      return false;
    }

    limparErros();

    const idProfissional = normalizar(campos.idProfissional?.value);
    const nome = normalizar(campos.nome?.value);
    const duracao = normalizar(campos.duracao?.value);
    const valor = valorDecimalBR(campos.valor?.value);
    const descricao = normalizar(campos.descricao?.value);
    const status = normalizar(campos.status?.value || "ativo");

    if (!idProfissional) {
      focarComAviso(campos.idProfissional, "Selecione um profissional antes de cadastrar o serviço.");
      return false;
    }

    if (!nome) {
      focarComAviso(campos.nome, "Informe o nome do serviço.");
      return false;
    }

    if (nome.length < 2) {
      focarComAviso(campos.nome, "O nome deve ter no mínimo 2 caracteres.");
      return false;
    }

    if (nome.length > 120) {
      focarComAviso(campos.nome, "O nome deve ter no máximo 120 caracteres.");
      return false;
    }

    if (!duracao) {
      focarComAviso(campos.duracao, "Selecione a duração do serviço.");
      return false;
    }

    if (Number(duracao) <= 0 || Number(duracao) > 1440) {
      focarComAviso(campos.duracao, "Informe uma duração válida.");
      return false;
    }

    if (valor === null) {
      focarComAviso(campos.valor, "Informe um valor válido. Use apenas números, vírgula ou ponto.");
      return false;
    }

    if (!["ativo", "inativo"].includes(status)) {
      focarComAviso(campos.status, "Status inválido.");
      return false;
    }

    if (descricao.length > 220) {
      focarComAviso(campos.descricao, "A descrição deve ter no máximo 220 caracteres.");
      return false;
    }

    return true;
  }

  async function enviar(event) {
    event.preventDefault();

    if (btnSalvar.disabled) return;
    if (!validar()) return;

    const fd = new FormData();

    fd.append("id_profissional", normalizar(campos.idProfissional?.value));
    fd.append("nome", normalizar(campos.nome?.value));
    fd.append("duracao_min", normalizar(campos.duracao?.value));
    fd.append("valor", valorDecimalBR(campos.valor?.value));
    fd.append("status", normalizar(campos.status?.value || "ativo"));
    fd.append("descricao", normalizar(campos.descricao?.value));

    setLoading(true);

    try {
      const resp = await fetch(API_URL, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest"
        },
        cache: "no-store"
      });

      const text = await resp.text();
      let data = null;

      try {
        data = text ? JSON.parse(text) : null;
      } catch (_) {
        data = null;
      }

      if (!resp.ok || !data?.ok) {
        const erros = aplicarErrosBackend(data?.fields || {});
        const mensagem = data?.user_msg || "Não foi possível cadastrar o serviço.";

        const mostrar = resp.status >= 500 ? msgErro : msgWarn;

        mostrar(mensagem, () => {
          erros.primeiroCampo?.focus();
        });

        return;
      }

      msgOk(data.user_msg || "Serviço cadastrado com sucesso.", () => {
        avisarServicoCadastrado(data);
        limparFormulario();
        fecharModalServico();
      });
    } catch (err) {
      console.error("[cadastrar-servico-modal-novo-agendamento]", err);
      msgErro("Erro de comunicação ao cadastrar serviço.");
    } finally {
      setLoading(false);
    }
  }

  form.addEventListener("submit", enviar);
  document.addEventListener("amagenda:sessao-carregada", bloquearModalParaRecepcionista);
  if (modal) new MutationObserver(bloquearModalParaRecepcionista).observe(modal, { attributes: true, attributeFilter: ["class", "aria-hidden"] });

  [
    campos.idProfissional,
    campos.nome,
    campos.duracao,
    campos.valor,
    campos.status,
    campos.descricao
  ].forEach((campo) => {
    campo?.addEventListener("input", () => limparErro(campo));
    campo?.addEventListener("change", () => limparErro(campo));
  });
})();
