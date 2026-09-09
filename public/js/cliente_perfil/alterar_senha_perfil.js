(() => {
  "use strict";

  /*
  |--------------------------------------------------------------------------
  | PERFIL DO CLIENTE - ALTERAR SENHA
  |--------------------------------------------------------------------------
  |
  | Modos de apresentação:
  | - primeira senha: não exibe senha atual e bloqueia o modal;
  | - alteração normal: exige senha atual e permite fechar o modal;
  | - recuperação por SMS: não exibe senha atual e bloqueia o modal.
  |
  | A autorização para dispensar a senha atual pertence ao backend.
  |
  */

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";

  const modal = document.getElementById("modalPerfilUsuario");
  const tituloModal = document.getElementById(
    "tituloPerfilUsuario"
  );
  const form = document.getElementById("formAlterarSenha");
  const senhaAtual = document.getElementById("senha_atual");
  const novaSenha = document.getElementById("nova_senha");
  const confirmarSenha = document.getElementById(
    "confirmar_senha"
  );

  if (
    !form ||
    !senhaAtual ||
    !novaSenha ||
    !confirmarSenha
  ) {
    return;
  }

  const blocoSenhaAtual =
    document.getElementById("campoSenhaAtual") ||
    senhaAtual.closest(".modal-campo");

  const blocoNovaSenha = novaSenha.closest(".modal-campo");
  const blocoConfirmarSenha = confirmarSenha.closest(
    ".modal-campo"
  );

  const botaoSalvar = form.querySelector(
    'button[type="submit"]'
  );

  const MODOS_SENHA = Object.freeze({
    PRIMEIRA_SENHA: "primeira_senha",
    ALTERACAO: "alteracao",
    RECUPERACAO: "recuperacao"
  });

  const CONFIGURACOES_MODO = Object.freeze({
    [MODOS_SENHA.PRIMEIRA_SENHA]: {
      titulo: "Crie sua senha de acesso",
      textoBotao: "Criar senha",
      exigirSenhaAtual: false,
      bloquearModal: true,
      abrirModal: true
    },
    [MODOS_SENHA.ALTERACAO]: {
      titulo: "Meu Perfil",
      textoBotao: "Salvar nova senha",
      exigirSenhaAtual: true,
      bloquearModal: false,
      abrirModal: false
    },
    [MODOS_SENHA.RECUPERACAO]: {
      titulo: "Criar nova senha",
      textoBotao: "Criar nova senha",
      exigirSenhaAtual: false,
      bloquearModal: true,
      abrirModal: true
    }
  });

  let exigirSenhaAtual = true;
  let enviando = false;

  /*
  |--------------------------------------------------------------------------
  | MENSAGENS
  |--------------------------------------------------------------------------
  */

  function mensagem(tipo, texto) {
    const sistema = window.MensagemSistema;

    if (
      sistema &&
      typeof sistema[tipo] === "function"
    ) {
      sistema[tipo](texto);
      return;
    }

    if (tipo === "erro") {
      console.error(texto);
      return;
    }

    console.log(texto);
  }

  /*
  |--------------------------------------------------------------------------
  | NORMALIZAÇÃO DO ESTADO
  |--------------------------------------------------------------------------
  */

  function normalizarDadosEstado(estado) {
    if (!estado || typeof estado !== "object") {
      return {};
    }

    if (
      estado.data &&
      typeof estado.data === "object"
    ) {
      return {
        ...estado,
        ...estado.data
      };
    }

    return estado;
  }

  /*
  |--------------------------------------------------------------------------
  | CONFIGURAÇÃO DO MODO
  |--------------------------------------------------------------------------
  */

  function configurarModoSenha(modo) {
    const configuracao = CONFIGURACOES_MODO[modo];

    if (!configuracao) {
      return false;
    }

    exigirSenhaAtual = configuracao.exigirSenhaAtual;

    senhaAtual.required = exigirSenhaAtual;
    senhaAtual.disabled = !exigirSenhaAtual;

    if (!exigirSenhaAtual) {
      senhaAtual.value = "";
    }

    if (blocoSenhaAtual) {
      blocoSenhaAtual.hidden = !exigirSenhaAtual;
    }

    if (blocoNovaSenha) {
      blocoNovaSenha.hidden = false;
    }

    if (blocoConfirmarSenha) {
      blocoConfirmarSenha.hidden = false;
    }

    novaSenha.required = true;
    confirmarSenha.required = true;

    if (tituloModal) {
      tituloModal.textContent = configuracao.titulo;
    }

    if (botaoSalvar) {
      botaoSalvar.innerHTML =
        `<i class="fa-solid fa-key"></i> ${configuracao.textoBotao}`;
    }

    if (modal) {
      modal.classList.toggle(
        "cadastro-bloqueado",
        configuracao.bloquearModal
      );
    }

    if (
      configuracao.abrirModal &&
      typeof window.abrirModal === "function"
    ) {
      window.abrirModal("modalPerfilUsuario");
    }

    return true;
  }

  function aplicarEstadoSeguroDoPerfil(estado) {
    const dados = normalizarDadosEstado(estado);

    if (dados.recuperacao_senha_autorizada === true) {
      configurarModoSenha(
        MODOS_SENHA.RECUPERACAO
      );
      return;
    }

    if (dados.tem_senha === false) {
      configurarModoSenha(
        MODOS_SENHA.PRIMEIRA_SENHA
      );
      return;
    }

    configurarModoSenha(MODOS_SENHA.ALTERACAO);
  }

  /*
  |--------------------------------------------------------------------------
  | VALIDAÇÃO
  |--------------------------------------------------------------------------
  */

  function validar() {
    const atual = senhaAtual.value;
    const nova = novaSenha.value;
    const confirmacao = confirmarSenha.value;

    if (exigirSenhaAtual && atual === "") {
      throw new Error("Informe sua senha atual.");
    }

    if (nova.length < 6) {
      throw new Error(
        "A nova senha deve possuir pelo menos 6 caracteres."
      );
    }

    if (nova.length > 72) {
      throw new Error(
        "A nova senha deve possuir no máximo 72 caracteres."
      );
    }

    if (nova !== confirmacao) {
      throw new Error(
        "A confirmação da nova senha não confere."
      );
    }

    return {
      senha_atual: exigirSenhaAtual ? atual : "",
      nova_senha: nova,
      confirmar_senha: confirmacao
    };
  }

  /*
  |--------------------------------------------------------------------------
  | REQUISIÇÃO
  |--------------------------------------------------------------------------
  */

  async function enviar(payload) {
    const resposta = await fetch(
      `${API}?path=${encodeURIComponent(
        "cliente/perfil/alterar-senha"
      )}`,
      {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json"
        },
        body: JSON.stringify(payload)
      }
    );

    const json = await resposta
      .json()
      .catch(() => null);

    if (resposta.status === 401) {
      window.location.replace(LOGIN);

      throw new Error("Sessão expirada.");
    }

    if (!resposta.ok || !json || json.ok !== true) {
      const erro = new Error(
        json?.user_msg ||
        "Não foi possível atualizar sua senha."
      );

      erro.code = json?.code || null;
      erro.fields = json?.fields || null;

      throw erro;
    }

    return json;
  }

  /*
  |--------------------------------------------------------------------------
  | ENVIO DO FORMULÁRIO
  |--------------------------------------------------------------------------
  */

  form.addEventListener("submit", async evento => {
    evento.preventDefault();

    if (enviando) {
      return;
    }

    try {
      const payload = validar();

      enviando = true;

      if (botaoSalvar) {
        botaoSalvar.disabled = true;
      }

      const json = await enviar(payload);

      senhaAtual.value = "";
      novaSenha.value = "";
      confirmarSenha.value = "";

      if (window.ClientePerfilEstado) {
        window.ClientePerfilEstado.tem_senha = true;
        window.ClientePerfilEstado
          .recuperacao_senha_autorizada = false;
      }

      configurarModoSenha(MODOS_SENHA.ALTERACAO);

      mensagem(
        "sucesso",
        json.user_msg ||
        "Senha atualizada com sucesso."
      );

      document.dispatchEvent(
        new CustomEvent(
          "amagenda:cliente-senha-atualizada",
          {
            detail: {
              tem_senha: true,
              primeiro_acesso_concluido: true
            }
          }
        )
      );

    } catch (erro) {

      /*
      | Proteção para alteração comum:
      | se o backend informar que a senha atual é necessária,
      | o campo é imediatamente apresentado.
      */

      if (
        erro?.code ===
          "CLIENT_PASSWORD_FIELDS_REQUIRED" &&
        erro?.fields?.senha_atual
      ) {
        configurarModoSenha(MODOS_SENHA.ALTERACAO);

        senhaAtual.focus();
      }

      mensagem(
        "erro",
        erro.message ||
        "Não foi possível atualizar sua senha."
      );

    } finally {
      enviando = false;

      if (botaoSalvar) {
        botaoSalvar.disabled = false;
      }
    }
  });

  /*
  |--------------------------------------------------------------------------
  | PERFIL CARREGADO
  |--------------------------------------------------------------------------
  */

  document.addEventListener(
    "amagenda:cliente-perfil-carregado",
    evento => {
      aplicarEstadoSeguroDoPerfil(
        evento.detail || {}
      );
    }
  );

  /*
  |--------------------------------------------------------------------------
  | ESTADO INICIAL
  |--------------------------------------------------------------------------
  */

  window.ClientePerfil = window.ClientePerfil || {};
  window.ClientePerfil.configurarModoSenha =
    configurarModoSenha;

  aplicarEstadoSeguroDoPerfil(
    window.ClientePerfilEstado || {}
  );
})();
