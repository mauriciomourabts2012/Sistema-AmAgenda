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

  const form = document.getElementById(
    "formAlterarSenha"
  );

  const senhaAtual = document.getElementById(
    "senha_atual"
  );

  const novaSenha = document.getElementById(
    "nova_senha"
  );

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

  const blocoNovaSenha =
    novaSenha.closest(".modal-campo");

  const blocoConfirmarSenha =
    confirmarSenha.closest(".modal-campo");

  const botaoSalvar = form.querySelector(
    'button[type="submit"]'
  );


  /*
  |--------------------------------------------------------------------------
  | MODOS DE SENHA
  |--------------------------------------------------------------------------
  */

  const MODOS_SENHA = Object.freeze({
    PRIMEIRA_SENHA: "primeira_senha",
    ALTERACAO: "alteracao",
    RECUPERACAO: "recuperacao"
  });


  /*
  |--------------------------------------------------------------------------
  | CONFIGURAÇÕES DOS MODOS
  |--------------------------------------------------------------------------
  */

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


  /*
  |--------------------------------------------------------------------------
  | ESTADO INTERNO
  |--------------------------------------------------------------------------
  */

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

    if (
      !estado ||
      typeof estado !== "object"
    ) {

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
  | CONFIGURAÇÃO DO MODO DE SENHA
  |--------------------------------------------------------------------------
  */

  function configurarModoSenha(modo) {

    const configuracao =
      CONFIGURACOES_MODO[modo];

    if (!configuracao) {

      return false;
    }


    /*
    |--------------------------------------------------------------------------
    | SENHA ATUAL
    |--------------------------------------------------------------------------
    */

    exigirSenhaAtual =
      configuracao.exigirSenhaAtual;

    senhaAtual.required =
      exigirSenhaAtual;

    senhaAtual.disabled =
      !exigirSenhaAtual;

    if (!exigirSenhaAtual) {

      senhaAtual.value = "";
    }


    /*
    |--------------------------------------------------------------------------
    | CAMPOS
    |--------------------------------------------------------------------------
    */

    if (blocoSenhaAtual) {

      blocoSenhaAtual.hidden =
        !exigirSenhaAtual;
    }

    if (blocoNovaSenha) {

      blocoNovaSenha.hidden = false;
    }

    if (blocoConfirmarSenha) {

      blocoConfirmarSenha.hidden = false;
    }

    novaSenha.required = true;

    confirmarSenha.required = true;


    /*
    |--------------------------------------------------------------------------
    | TÍTULO
    |--------------------------------------------------------------------------
    */

    if (tituloModal) {

      tituloModal.textContent =
        configuracao.titulo;
    }


    /*
    |--------------------------------------------------------------------------
    | BOTÃO
    |--------------------------------------------------------------------------
    */

    if (botaoSalvar) {

      botaoSalvar.innerHTML =
        `<i class="fa-solid fa-key"></i> ${configuracao.textoBotao}`;
    }


    /*
    |--------------------------------------------------------------------------
    | BLOQUEIO DO MODAL
    |--------------------------------------------------------------------------
    */

    if (modal) {

      modal.classList.toggle(
        "cadastro-bloqueado",
        configuracao.bloquearModal
      );
    }


    /*
    |--------------------------------------------------------------------------
    | ABRIR MODAL
    |--------------------------------------------------------------------------
    */

    if (
      configuracao.abrirModal &&
      typeof window.abrirModal === "function"
    ) {

      window.abrirModal(
        "modalPerfilUsuario"
      );
    }

    return true;
  }


  /*
  |--------------------------------------------------------------------------
  | FECHAR MODAL APÓS SUCESSO
  |--------------------------------------------------------------------------
  |
  | IMPORTANTE:
  |
  | fecharModal() do sistema recebe o ELEMENTO HTML do modal.
  |
  | Portanto:
  |
  | CORRETO:
  | fecharModal(modal)
  |
  | ERRADO:
  | fecharModal("modalPerfilUsuario")
  |
  */

  function fecharModalPerfil() {

    if (!modal) {

      return;
    }


    /*
    |--------------------------------------------------------------------------
    | REMOVE BLOQUEIO DO PRIMEIRO ACESSO / RECUPERAÇÃO
    |--------------------------------------------------------------------------
    */

    modal.classList.remove(
      "cadastro-bloqueado"
    );


    /*
    |--------------------------------------------------------------------------
    | UTILIZA O FECHAMENTO CENTRAL DO AMAGENDA
    |--------------------------------------------------------------------------
    */

    if (
      typeof window.fecharModal === "function"
    ) {

      window.fecharModal(modal);

      return;
    }


    /*
    |--------------------------------------------------------------------------
    | FALLBACK DE SEGURANÇA
    |--------------------------------------------------------------------------
    |
    | Utilizado somente se o controlador global de modais
    | não estiver disponível.
    |
    */

    modal.classList.remove("ativo");

    modal.setAttribute(
      "aria-hidden",
      "true"
    );

    modal.style.display = "none";

    document.body.classList.remove(
      "modal-aberto"
    );
  }


  /*
  |--------------------------------------------------------------------------
  | APLICAR ESTADO DO PERFIL
  |--------------------------------------------------------------------------
  */

  function aplicarEstadoSeguroDoPerfil(estado) {

    const dados =
      normalizarDadosEstado(estado);


    /*
    |--------------------------------------------------------------------------
    | RECUPERAÇÃO DE SENHA
    |--------------------------------------------------------------------------
    */

    if (
      dados.recuperacao_senha_autorizada === true
    ) {

      configurarModoSenha(
        MODOS_SENHA.RECUPERACAO
      );

      return;
    }


    /*
    |--------------------------------------------------------------------------
    | PRIMEIRA SENHA
    |--------------------------------------------------------------------------
    */

    if (
      dados.tem_senha === false
    ) {

      configurarModoSenha(
        MODOS_SENHA.PRIMEIRA_SENHA
      );

      return;
    }


    /*
    |--------------------------------------------------------------------------
    | ALTERAÇÃO NORMAL
    |--------------------------------------------------------------------------
    */

    configurarModoSenha(
      MODOS_SENHA.ALTERACAO
    );
  }


  /*
  |--------------------------------------------------------------------------
  | VALIDAÇÃO
  |--------------------------------------------------------------------------
  */

  function validar() {

    const atual =
      senhaAtual.value;

    const nova =
      novaSenha.value;

    const confirmacao =
      confirmarSenha.value;


    /*
    |--------------------------------------------------------------------------
    | SENHA ATUAL
    |--------------------------------------------------------------------------
    */

    if (
      exigirSenhaAtual &&
      atual === ""
    ) {

      throw new Error(
        "Informe sua senha atual."
      );
    }


    /*
    |--------------------------------------------------------------------------
    | TAMANHO MÍNIMO
    |--------------------------------------------------------------------------
    */

    if (
      nova.length < 6
    ) {

      throw new Error(
        "A nova senha deve possuir pelo menos 6 caracteres."
      );
    }


    /*
    |--------------------------------------------------------------------------
    | TAMANHO MÁXIMO
    |--------------------------------------------------------------------------
    */

    if (
      nova.length > 72
    ) {

      throw new Error(
        "A nova senha deve possuir no máximo 72 caracteres."
      );
    }


    /*
    |--------------------------------------------------------------------------
    | CONFIRMAÇÃO
    |--------------------------------------------------------------------------
    */

    if (
      nova !== confirmacao
    ) {

      throw new Error(
        "A confirmação da nova senha não confere."
      );
    }


    /*
    |--------------------------------------------------------------------------
    | PAYLOAD
    |--------------------------------------------------------------------------
    */

    return {

      senha_atual:
        exigirSenhaAtual
          ? atual
          : "",

      nova_senha:
        nova,

      confirmar_senha:
        confirmacao

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

        method:
          "POST",

        credentials:
          "same-origin",

        cache:
          "no-store",

        headers: {

          Accept:
            "application/json",

          "Content-Type":
            "application/json"

        },

        body:
          JSON.stringify(payload)

      }

    );


    /*
    |--------------------------------------------------------------------------
    | RESPOSTA JSON
    |--------------------------------------------------------------------------
    */

    const json = await resposta
      .json()
      .catch(() => null);


    /*
    |--------------------------------------------------------------------------
    | SESSÃO EXPIRADA
    |--------------------------------------------------------------------------
    */

    if (
      resposta.status === 401
    ) {

      window.location.replace(
        LOGIN
      );

      throw new Error(
        "Sessão expirada."
      );
    }


    /*
    |--------------------------------------------------------------------------
    | ERRO DO BACKEND
    |--------------------------------------------------------------------------
    */

    if (
      !resposta.ok ||
      !json ||
      json.ok !== true
    ) {

      const erro = new Error(

        json?.user_msg ||
        "Não foi possível atualizar sua senha."

      );

      erro.code =
        json?.code || null;

      erro.fields =
        json?.fields || null;

      throw erro;
    }


    /*
    |--------------------------------------------------------------------------
    | SUCESSO
    |--------------------------------------------------------------------------
    */

    return json;
  }


  /*
  |--------------------------------------------------------------------------
  | ENVIO DO FORMULÁRIO
  |--------------------------------------------------------------------------
  */

  form.addEventListener(
    "submit",
    async evento => {

      evento.preventDefault();


      /*
      |--------------------------------------------------------------------------
      | EVITA ENVIO DUPLICADO
      |--------------------------------------------------------------------------
      */

      if (enviando) {

        return;
      }


      try {


        /*
        |--------------------------------------------------------------------------
        | VALIDAR
        |--------------------------------------------------------------------------
        */

        const payload =
          validar();


        /*
        |--------------------------------------------------------------------------
        | BLOQUEAR NOVO ENVIO
        |--------------------------------------------------------------------------
        */

        enviando = true;

        if (botaoSalvar) {

          botaoSalvar.disabled =
            true;
        }


        /*
        |--------------------------------------------------------------------------
        | ENVIAR PARA BACKEND
        |--------------------------------------------------------------------------
        */

        const json =
          await enviar(payload);


        /*
        |--------------------------------------------------------------------------
        | SUCESSO CONFIRMADO PELO BACKEND
        |--------------------------------------------------------------------------
        |
        | A partir deste ponto:
        |
        | json.ok === true
        |
        */


        /*
        |--------------------------------------------------------------------------
        | LIMPAR CAMPOS
        |--------------------------------------------------------------------------
        */

        senhaAtual.value = "";

        novaSenha.value = "";

        confirmarSenha.value = "";


        /*
        |--------------------------------------------------------------------------
        | ATUALIZAR ESTADO GLOBAL
        |--------------------------------------------------------------------------
        */

        if (
          window.ClientePerfilEstado
        ) {

          window.ClientePerfilEstado.tem_senha =
            true;

          window.ClientePerfilEstado
            .recuperacao_senha_autorizada =
            false;

        }


        /*
        |--------------------------------------------------------------------------
        | TRANSFORMAR PARA MODO NORMAL
        |--------------------------------------------------------------------------
        |
        | Isso também remove o bloqueio obrigatório do modal.
        |
        */

        configurarModoSenha(
          MODOS_SENHA.ALTERACAO
        );


        /*
        |--------------------------------------------------------------------------
        | FECHAR MODAL AUTOMATICAMENTE
        |--------------------------------------------------------------------------
        |
        | Executado nos três casos:
        |
        | 1. Primeira senha criada com sucesso
        | 2. Recuperação de senha concluída
        | 3. Alteração normal de senha concluída
        |
        */

        fecharModalPerfil();


        /*
        |--------------------------------------------------------------------------
        | MENSAGEM DE SUCESSO
        |--------------------------------------------------------------------------
        */

        mensagem(
          "sucesso",

          json.user_msg ||
          "Senha atualizada com sucesso."
        );


        /*
        |--------------------------------------------------------------------------
        | EVENTO GLOBAL
        |--------------------------------------------------------------------------
        */

        document.dispatchEvent(

          new CustomEvent(

            "amagenda:cliente-senha-atualizada",

            {

              detail: {

                tem_senha:
                  true,

                primeiro_acesso_concluido:
                  true

              }

            }

          )

        );


      } catch (erro) {


        /*
        |--------------------------------------------------------------------------
        | SENHA ATUAL OBRIGATÓRIA
        |--------------------------------------------------------------------------
        */

        if (

          erro?.code ===
            "CLIENT_PASSWORD_FIELDS_REQUIRED" &&

          erro?.fields?.senha_atual

        ) {

          configurarModoSenha(
            MODOS_SENHA.ALTERACAO
          );

          senhaAtual.focus();
        }


        /*
        |--------------------------------------------------------------------------
        | MENSAGEM DE ERRO
        |--------------------------------------------------------------------------
        |
        | Em caso de erro o modal permanece aberto.
        |
        */

        mensagem(

          "erro",

          erro.message ||
          "Não foi possível atualizar sua senha."

        );


      } finally {


        /*
        |--------------------------------------------------------------------------
        | LIBERAR ENVIO
        |--------------------------------------------------------------------------
        */

        enviando = false;


        /*
        |--------------------------------------------------------------------------
        | REATIVAR BOTÃO
        |--------------------------------------------------------------------------
        */

        if (botaoSalvar) {

          botaoSalvar.disabled =
            false;
        }

      }

    }
  );


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

  window.ClientePerfil =
    window.ClientePerfil || {};


  /*
  |--------------------------------------------------------------------------
  | EXPOR CONFIGURAÇÃO DE SENHA
  |--------------------------------------------------------------------------
  */

  window.ClientePerfil.configurarModoSenha =
    configurarModoSenha;


  /*
  |--------------------------------------------------------------------------
  | APLICAR ESTADO INICIAL
  |--------------------------------------------------------------------------
  */

  aplicarEstadoSeguroDoPerfil(
    window.ClientePerfilEstado || {}
  );

})();