(() => {
  "use strict";

  /*
  |--------------------------------------------------------------------------
  | PERFIL DO CLIENTE - ALTERAR SENHA
  |--------------------------------------------------------------------------
  |
  | Regras:
  | - primeiro acesso: não exige senha atual;
  | - alteração posterior: exige senha atual;
  | - senha temporária existente não significa primeiro acesso concluído;
  | - primeiro_acesso_em preenchido significa primeiro acesso concluído.
  |
  */

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";

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

  const blocoSenhaAtual = senhaAtual.closest(".modal-campo");

  const botaoSalvar = form.querySelector(
    'button[type="submit"]'
  );

  let temSenha = false;
  let primeiroAcessoConcluido = false;
  let exigirSenhaAtual = false;
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

  function identificarPrimeiroAcessoConcluido(dados) {
    /*
    | Resposta explícita preferencial.
    */

    if (
      typeof dados.primeiro_acesso_concluido ===
      "boolean"
    ) {
      return dados.primeiro_acesso_concluido;
    }

    /*
    | Compatibilidade caso o backend envie:
    | primeiro_acesso: true/false
    */

    if (typeof dados.primeiro_acesso === "boolean") {
      return dados.primeiro_acesso === false;
    }

    /*
    | Compatibilidade com o valor direto do banco.
    |
    | NULL ou vazio:
    | primeiro acesso ainda não foi concluído.
    |
    | Data preenchida:
    | primeiro acesso já foi concluído.
    */

    if (
      Object.prototype.hasOwnProperty.call(
        dados,
        "primeiro_acesso_em"
      )
    ) {
      const valor = dados.primeiro_acesso_em;

      return (
        valor !== null &&
        String(valor).trim() !== ""
      );
    }

    /*
    | Enquanto o perfil não informar o estado, considera
    | primeiro acesso pendente. O backend continuará sendo
    | a autoridade final de segurança.
    */

    return false;
  }

  /*
  |--------------------------------------------------------------------------
  | APLICAÇÃO DO ESTADO
  |--------------------------------------------------------------------------
  */

  function aplicarEstadoSenha(estado) {
    const dados = normalizarDadosEstado(estado);

    temSenha = dados.tem_senha === true;

    primeiroAcessoConcluido =
      identificarPrimeiroAcessoConcluido(dados);

    /*
    | Somente exige senha atual quando:
    | - existe uma senha;
    | - o primeiro acesso já foi concluído.
    */

    exigirSenhaAtual =
      temSenha && primeiroAcessoConcluido;

    senhaAtual.required = exigirSenhaAtual;
    senhaAtual.disabled = !exigirSenhaAtual;

    if (!exigirSenhaAtual) {
      senhaAtual.value = "";
    }

    if (blocoSenhaAtual) {
      blocoSenhaAtual.hidden = !exigirSenhaAtual;
    }

    if (botaoSalvar) {
      botaoSalvar.innerHTML = exigirSenhaAtual
        ? '<i class="fa-solid fa-key"></i> Salvar nova senha'
        : '<i class="fa-solid fa-key"></i> Definir nova senha';
    }
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

      temSenha = true;
      primeiroAcessoConcluido = true;
      exigirSenhaAtual = true;

      if (window.ClientePerfilEstado) {
        window.ClientePerfilEstado.tem_senha = true;

        window.ClientePerfilEstado
          .primeiro_acesso_concluido = true;

        if (
          Object.prototype.hasOwnProperty.call(
            window.ClientePerfilEstado,
            "primeiro_acesso_em"
          )
        ) {
          window.ClientePerfilEstado.primeiro_acesso_em =
            new Date().toISOString();
        }
      }

      aplicarEstadoSenha({
        tem_senha: true,
        primeiro_acesso_concluido: true
      });

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
        aplicarEstadoSenha({
          tem_senha: true,
          primeiro_acesso_concluido: true
        });

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
      aplicarEstadoSenha(
        evento.detail || {}
      );
    }
  );

  /*
  |--------------------------------------------------------------------------
  | ESTADO INICIAL
  |--------------------------------------------------------------------------
  */

  if (
    window.ClientePerfilEstado &&
    typeof window.ClientePerfilEstado === "object"
  ) {
    aplicarEstadoSenha(
      window.ClientePerfilEstado
    );
  } else {
    aplicarEstadoSenha({
      tem_senha: false,
      primeiro_acesso_concluido: false
    });
  }
})();