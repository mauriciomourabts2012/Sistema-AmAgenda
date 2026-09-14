(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";

  /* ============================
     MODAL: Dados cadastrais do cliente
     Único modal de cadastro da página. Abre manualmente pelo menu
     lateral (botão "Dados cadastrais") e automaticamente quando
     existir qualquer dado cadastral pendente (obrigatório ou opcional).
     ============================ */
  const modalCadastro = document.getElementById("modalDadosCadastraisCliente");
  const formCadastro = document.getElementById("formPerfilCliente");
  const mensagemCadastro = document.getElementById("perfilMensagem");
  const avisoObrigatorio = document.getElementById("cadastroAvisoObrigatorio");
  const botaoSalvarCadastro = document.getElementById("btnSalvarPerfil");
  const botaoVoltar = document.getElementById("btnVoltarInformacoes");
  const botaoFecharCadastro = modalCadastro?.querySelector(".modal-fechar[data-fechar-modal]");
  const abasCadastro = Array.from(modalCadastro?.querySelectorAll("[data-tab]") || []);
  const paineisCadastro = Array.from(modalCadastro?.querySelectorAll(".tab-pane") || []);

  // Campos cadastrais opcionais: se qualquer um estiver vazio, o modal
  // abre automaticamente como lembrete (sem bloquear o uso do sistema).
  const CAMPOS_OPCIONAIS = [
    "email", "cpf", "data_nascimento", "cep",
    "logradouro", "numero", "bairro", "cidade", "uf", "complemento"
  ];

  let iniciado = false;

  if (!modalCadastro || !formCadastro || !mensagemCadastro) return;

  const campo = id => document.getElementById(id);
  const somenteDigitos = valor => String(valor || "").replace(/\D+/g, "");

  function possuiCampoOpcionalVazio(dados) {
    return CAMPOS_OPCIONAIS.some(nomeCampo => String(dados?.[nomeCampo] ?? "").trim() === "");
  }

  /* ============================
     ABAS (Informações / Endereço)
     ============================ */
  function ativarAba(nome) {
    abasCadastro.forEach(botao => {
      const ativa = botao.dataset.tab === nome;
      botao.classList.toggle("active", ativa);
      botao.setAttribute("aria-selected", String(ativa));
    });
    paineisCadastro.forEach(painel => {
      const ativo = painel.id === `tab-${nome}`;
      painel.classList.toggle("active", ativo);
      painel.hidden = !ativo;
    });
    if (botaoVoltar) botaoVoltar.hidden = nome !== "endereco";
  }

  /* ============================
     BLOQUEIO (Nome/Telefone obrigatórios pendentes)
     Enquanto bloqueado: sem X, sem ESC, sem clique fora
     (garantido pela classe .cadastro-bloqueado, lida pelo script
     genérico de modais em fecharModal).
     ============================ */
  function definirBloqueio(bloquear) {
    modalCadastro.classList.toggle("cadastro-bloqueado", bloquear);
    if (avisoObrigatorio) avisoObrigatorio.hidden = !bloquear;
    if (botaoFecharCadastro) {
      botaoFecharCadastro.hidden = bloquear;
      botaoFecharCadastro.disabled = bloquear;
    }
    document.body.classList.toggle("cadastro-cliente-pendente", bloquear);
  }

  function abrirCadastro(bloquear) {
    definirBloqueio(bloquear);
    modalCadastro.classList.add("ativo");
    modalCadastro.setAttribute("aria-hidden", "false");
    window.setTimeout(() => campo("cli_nome")?.focus({ preventScroll: true }), 0);
  }

  function fecharCadastroAutomaticamente() {
    definirBloqueio(false);
    modalCadastro.classList.remove("ativo");
    modalCadastro.setAttribute("aria-hidden", "true");
  }

  /* ============================
     PREENCHIMENTO DOS DADOS
     ============================ */
  function preencherCadastro(dados) {
    const valores = {
      cli_nome: dados.nome_completo,
      cli_whats: dados.whatsapp_celular,
      cli_email: dados.email,
      cli_cpf: dados.cpf,
      cli_nasc: dados.data_nascimento,
      end_cep: dados.cep,
      end_logradouro: dados.logradouro,
      end_numero: dados.numero,
      end_bairro: dados.bairro,
      end_cidade: dados.cidade,
      end_uf: dados.uf,
      end_complemento: dados.complemento
    };
    Object.entries(valores).forEach(([id, valor]) => {
      if (campo(id)) campo(id).value = String(valor || "");
    });
    if (campo("cli_cpf")) campo("cli_cpf").value = formatarCpf(campo("cli_cpf").value);
    if (campo("end_cep")) campo("end_cep").value = formatarCep(campo("end_cep").value);
  }

  function limparErros() {
    formCadastro.querySelectorAll("[aria-invalid='true']").forEach(el => el.removeAttribute("aria-invalid"));
    formCadastro.querySelectorAll("[data-erro-for]").forEach(el => { el.textContent = ""; });
  }

  function mostrarErros(campos) {
    limparErros();
    let primeiro = null;
    Object.entries(campos || {}).forEach(([id, texto]) => {
      const entrada = campo(id);
      const erro = formCadastro.querySelector(`[data-erro-for="${CSS.escape(id)}"]`);
      if (entrada) {
        entrada.setAttribute("aria-invalid", "true");
        primeiro ||= entrada;
      }
      if (erro) erro.textContent = String(texto || "");
    });
    if (primeiro) {
      ativarAba(primeiro.closest("#tab-endereco") ? "endereco" : "perfil");
      primeiro.focus();
    }
  }

  function formatarCpf(valor) {
    const digitos = somenteDigitos(valor).slice(0, 11);
    return digitos
      .replace(/^(\d{3})(\d)/, "$1.$2")
      .replace(/^(\d{3})\.(\d{3})(\d)/, "$1.$2.$3")
      .replace(/(\d{3})(\d{1,2})$/, "$1-$2");
  }

  function formatarCep(valor) {
    return somenteDigitos(valor).slice(0, 8).replace(/^(\d{5})(\d)/, "$1-$2");
  }

  async function requisitar(caminho, opcoes = {}) {
    const resposta = await fetch(`${API}?path=${encodeURIComponent(caminho)}`, {
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json", ...(opcoes.headers || {}) },
      ...opcoes
    });
    const json = await resposta.json().catch(() => null);
    if (resposta.status === 401 || resposta.status === 403) {
      window.location.replace(LOGIN);
      throw new Error("Sessão expirada.");
    }
    if (!resposta.ok || !json || json.ok !== true) {
      const erro = new Error(json?.user_msg || "Não foi possível concluir a solicitação.");
      erro.campos = json?.fields || {};
      throw erro;
    }
    return json;
  }

  /* ============================
     CARREGAMENTO E DECISÃO DE ABERTURA AUTOMÁTICA
     ============================ */
  async function iniciar(auth) {
    if (iniciado || auth?.tipo_usuario !== "cliente") return;
    iniciado = true;

    if (auth.telefone_verificado === true && campo("cli_whats")) {
      campo("cli_whats").value = String(auth.telefone || "");
    }

    try {
      const json = await requisitar("cliente/perfil");
      const dados = json.data || {};
      preencherCadastro(dados);

      const obrigatoriosCompletos = dados.dados_obrigatorios_completos === true;
      if (!obrigatoriosCompletos) {
        // Falta Nome e/ou Telefone: abre e bloqueia até salvar.
        abrirCadastro(true);
      } else if (possuiCampoOpcionalVazio(dados)) {
        // Obrigatórios ok, falta algum opcional: abre como lembrete, sem bloquear.
        abrirCadastro(false);
      }
    } catch (erro) {
      mensagemCadastro.textContent = erro.message;
      mensagemCadastro.className = "cadastro-mensagem erro";
    }
  }

  document.addEventListener("amagenda:sessao-carregada", evento => iniciar(evento.detail));
  document.addEventListener("DOMContentLoaded", () => {
    if (window.__AUTH__) iniciar(window.__AUTH__);
  });

  /* ============================
     ABAS: eventos
     ============================ */
  abasCadastro.forEach(botao => botao.addEventListener("click", () => ativarAba(botao.dataset.tab)));
  if (botaoVoltar) {
    botaoVoltar.addEventListener("click", () => {
      ativarAba("perfil");
      campo("cli_nome")?.focus();
    });
  }

  if (campo("cli_cpf")) campo("cli_cpf").addEventListener("input", evento => { evento.target.value = formatarCpf(evento.target.value); });
  if (campo("end_cep")) campo("end_cep").addEventListener("input", evento => { evento.target.value = formatarCep(evento.target.value); });
  if (campo("end_uf")) {
    campo("end_uf").addEventListener("input", evento => {
      evento.target.value = evento.target.value.replace(/[^a-z]/gi, "").toUpperCase().slice(0, 2);
    });
  }

  /* ============================
     SUBMIT: Dados cadastrais
     ============================ */
  formCadastro.addEventListener("submit", async evento => {
    evento.preventDefault();
    limparErros();
    mensagemCadastro.textContent = "";
    mensagemCadastro.className = "cadastro-mensagem";

    if (!formCadastro.checkValidity()) {
      const invalido = formCadastro.querySelector(":invalid");
      ativarAba(invalido?.closest("#tab-endereco") ? "endereco" : "perfil");
      invalido?.reportValidity();
      invalido?.focus();
      return;
    }

    botaoSalvarCadastro.disabled = true;
    botaoSalvarCadastro.classList.add("carregando");
    mensagemCadastro.textContent = "Salvando seus dados...";
    try {
      const json = await requisitar("cliente/perfil/salvar", {
        method: "POST",
        body: new FormData(formCadastro)
      });
      mensagemCadastro.textContent = json.user_msg || "Dados cadastrais salvos com sucesso.";
      mensagemCadastro.className = "cadastro-mensagem sucesso";
      window.setTimeout(() => window.location.reload(), 650);
    } catch (erro) {
      mensagemCadastro.textContent = erro.message || "Não foi possível salvar os dados cadastrais.";
      mensagemCadastro.className = "cadastro-mensagem erro";
      mostrarErros(erro.campos);
    } finally {
      botaoSalvarCadastro.disabled = false;
      botaoSalvarCadastro.classList.remove("carregando");
    }
  });
})();
