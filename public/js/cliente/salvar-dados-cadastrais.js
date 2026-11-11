(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";

  /* ============================
     MODAL: Dados cadastrais do cliente
     Único modal de cadastro da página.
     - Dados OBRIGATÓRIOS pendentes (Nome e um contato; primeiro acesso): abre sozinho e
       BLOQUEADO até salvar. Só então existe identidade (id_cliente) apta a registrar o aceite
       dos documentos legais; o salvamento recarrega a página e o guard (sessao.js) encaminha
       ao aceite obrigatório.
     - Somente dados OPCIONAIS pendentes: nunca abre sozinho; o sino mostra o lembrete
       e o modal abre pelo menu ou pela notificação (window.AbrirCadastroCliente).
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

  // Campos cadastrais opcionais: se qualquer um estiver vazio, a notificação de
  // cadastro permanece no sino como lembrete (sem bloquear o uso do sistema).
  const CAMPOS_OPCIONAIS = [
    "email", "whatsapp_celular", "cpf", "data_nascimento", "cep",
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
     BLOQUEIO (dados obrigatórios pendentes — primeiro acesso)
     Enquanto bloqueado: sem X, sem ESC, sem clique fora (classe .cadastro-bloqueado,
     respeitada pelo fecharModal genérico da página) e a aplicação fica inacessível
     (body.cadastro-cliente-pendente, em cliente-web.css).
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

  function abrirFormularioCadastro() {
    modalCadastro.classList.add("ativo");
    modalCadastro.setAttribute("aria-hidden", "false");
    window.setTimeout(() => campo("cli_nome")?.focus({ preventScroll: true }), 0);
  }
  window.AbrirCadastroCliente = abrirFormularioCadastro;

  /* ============================
     CADASTRO INCOMPLETO
     Obrigatórios pendentes: cadastro bloqueante (não vai para o sino).
     Somente opcionais pendentes: lembrete no sino (notificacoes-cliente.js), sem bloquear.
     ============================ */
  function aplicarCadastroPendente(dados) {
    const obrigatoriosCompletos = dados?.dados_obrigatorios_completos === true;
    const opcionalPendente = obrigatoriosCompletos && possuiCampoOpcionalVazio(dados);
    window.CentroNotificacoesCliente?.definirCadastro?.({ pendente: opcionalPendente, obrigatorio: false });
    definirBloqueio(!obrigatoriosCompletos);
    if (!obrigatoriosCompletos) abrirFormularioCadastro();
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
    renderizarContatos(dados);
  }

  /* ============================
     CONTATOS (celular e e-mail): validação do segundo contato
     Cada contato é uma credencial independente e só vale depois de validado por código
     (SMS ou e-mail). Não é preciso validar os dois ao mesmo tempo.
     ============================ */
  let dadosContatos = {};
  let contatoPendente = null; // { tipo: "verificar"|"adicionar_telefone", contato: "telefone"|"email" }

  const el = {
    estadoTelefone: document.getElementById("contatoTelefoneEstado"),
    estadoEmail: document.getElementById("contatoEmailEstado"),
    btnValidarTelefone: document.getElementById("btnValidarTelefone"),
    btnValidarEmail: document.getElementById("btnValidarEmailPerfil"),
    boxAdicionar: document.getElementById("contatoAdicionarTelefone"),
    inNovoTelefone: document.getElementById("cli_whats_novo"),
    btnAdicionar: document.getElementById("btnAdicionarTelefone"),
    boxCodigo: document.getElementById("contatoCodigoBox"),
    inCodigo: document.getElementById("contatoCodigo"),
    rotuloCodigo: document.getElementById("contatoCodigoRotulo"),
    btnConfirmar: document.getElementById("btnConfirmarContato"),
    mensagem: document.getElementById("contatoMensagem")
  };

  function mensagemContato(texto, tipo = "") {
    if (!el.mensagem) return;
    el.mensagem.textContent = texto || "";
    el.mensagem.className = `cadastro-mensagem${tipo ? ` ${tipo}` : ""}`;
  }

  function definirEstado(elemento, rotulo, preenchido, validado) {
    if (!elemento) return;
    elemento.textContent = !preenchido
      ? `${rotulo}: não informado`
      : (validado ? `${rotulo}: validado` : `${rotulo}: ainda não validado`);
    elemento.classList.toggle("validado", Boolean(preenchido && validado));
    elemento.classList.toggle("pendente", Boolean(preenchido && !validado));
  }

  function renderizarContatos(dados) {
    dadosContatos = dados || {};
    const cadastrado = dados?.cadastrado === true;
    const temTelefone = String(dados?.whatsapp_celular ?? "").trim() !== "";
    const temEmail = String(dados?.email ?? "").trim() !== "";

    definirEstado(el.estadoTelefone, "Celular", temTelefone, dados?.telefone_validado === true);
    definirEstado(el.estadoEmail, "E-mail", temEmail, dados?.email_validado === true);

    // Só dá para validar um contato já salvo na conta (o cadastro precisa existir).
    if (el.btnValidarTelefone) el.btnValidarTelefone.hidden = !(cadastrado && temTelefone && dados?.telefone_validado !== true);
    if (el.btnValidarEmail) el.btnValidarEmail.hidden = !(cadastrado && temEmail && dados?.email_validado !== true);
    // Conta sem celular pode adicionar um; o número só é gravado depois de confirmado por SMS.
    if (el.boxAdicionar) el.boxAdicionar.hidden = !(cadastrado && !temTelefone);
    // A unificação só é oferecida a quem já tem cadastro salvo.
    const boxUnificacao = document.getElementById("unificacaoBox");
    if (boxUnificacao) boxUnificacao.hidden = !cadastrado;

    // O último contato validado não pode ser alterado/removido (o servidor também recusa).
    const emailBloqueado = (!cadastrado && dados?.email_validado === true) || dados?.email_editavel === false;
    const campoEmail = campo("cli_email");
    if (campoEmail) {
      campoEmail.readOnly = emailBloqueado;
      campoEmail.setAttribute("aria-readonly", String(emailBloqueado));
      campoEmail.title = emailBloqueado ? "Valide outro contato para poder alterar este e-mail." : "";
    }

    if (!cadastrado) mensagemContato("Salve seus dados para poder validar os contatos.");
    atualizarDicaContato();
  }

  /* Orientação opcional do segundo contato: sugere o que falta (e-mail para quem entrou por celular,
     celular para quem entrou por e-mail) e some quando os dois existem. Nunca é exigido. */
  function atualizarDicaContato() {
    const dica = campo("cadastroDicaContato");
    const texto = campo("cadastroDicaContatoTexto");
    if (!dica || !texto) return;
    const temTelefone = String(dadosContatos?.whatsapp_celular ?? "").trim() !== "";
    const temEmail = String(dadosContatos?.email ?? "").trim() !== "" || String(campo("cli_email")?.value || "").trim() !== "";
    let mensagem = "";
    if (temTelefone && !temEmail) {
      mensagem = "Dica (opcional): cadastre também um e-mail. Ele ajuda a recuperar sua conta e oferece outra forma de entrar.";
    } else if (!temTelefone && temEmail) {
      mensagem = dadosContatos?.cadastrado === true
        ? "Dica (opcional): adicione também um celular em “Validação dos contatos” para ter outra forma de entrar."
        : "Dica (opcional): depois de salvar, adicione também um celular em “Validação dos contatos” para ter outra forma de entrar.";
    }
    texto.textContent = mensagem;
    dica.hidden = mensagem === "";
  }

  function mostrarCodigo(contato) {
    if (el.boxCodigo) el.boxCodigo.hidden = false;
    if (el.rotuloCodigo) {
      el.rotuloCodigo.textContent = contato === "email"
        ? "Código enviado ao seu e-mail"
        : "Código enviado por SMS";
    }
    if (el.inCodigo) { el.inCodigo.value = ""; el.inCodigo.focus(); }
    if (el.btnConfirmar) el.btnConfirmar.disabled = true;
  }

  async function requisitarContato(dados) {
    const corpo = new FormData();
    Object.entries(dados).forEach(([chave, valor]) => corpo.append(chave, String(valor)));
    return requisitar("cliente/perfil/contatos", {
      method: "POST",
      headers: { Accept: "application/json", "X-CSRF-Token": String(window.__AUTH__?.csrf_token || "") },
      body: corpo
    });
  }

  async function solicitarContato(dados, pendente, botao) {
    if (botao) botao.disabled = true;
    mensagemContato("Enviando código...");
    try {
      const json = await requisitarContato(dados);
      contatoPendente = pendente;
      mostrarCodigo(pendente.contato);
      mensagemContato(json.user_msg || "Código enviado.", "sucesso");
    } catch (erro) {
      mensagemContato(erro.message || "Não foi possível enviar o código.", "erro");
      if (erro.campos && erro.campos.cli_whats_novo && campo("cli_whats_novo")) {
        campo("cli_whats_novo").setAttribute("aria-invalid", "true");
      }
      if (erro.dados?.oferecer_unificacao) oferecerUnificacao(erro.dados.contato);
    } finally {
      if (botao) botao.disabled = false;
    }
  }

  if (el.btnValidarTelefone) {
    el.btnValidarTelefone.addEventListener("click", () => solicitarContato(
      { acao: "solicitar_verificacao", contato: "telefone" },
      { tipo: "verificar", contato: "telefone" },
      el.btnValidarTelefone
    ));
  }

  if (el.btnValidarEmail) {
    el.btnValidarEmail.addEventListener("click", () => {
      // O código vai para o e-mail SALVO na conta: alterações ainda não salvas não valem.
      const digitado = String(campo("cli_email")?.value || "").trim().toLowerCase();
      if (digitado !== String(dadosContatos.email || "").trim().toLowerCase()) {
        mensagemContato("Salve o e-mail antes de validá-lo.", "erro");
        return;
      }
      solicitarContato(
        { acao: "solicitar_verificacao", contato: "email" },
        { tipo: "verificar", contato: "email" },
        el.btnValidarEmail
      );
    });
  }

  if (el.btnAdicionar) {
    el.btnAdicionar.addEventListener("click", () => {
      const digitos = somenteDigitos(el.inNovoTelefone?.value);
      if (digitos.length !== 11) {
        mensagemContato("Informe o celular com DDD (11 dígitos).", "erro");
        return;
      }
      solicitarContato(
        { acao: "solicitar_adicao_telefone", telefone: digitos },
        { tipo: "adicionar_telefone", contato: "telefone" },
        el.btnAdicionar
      );
    });
  }

  if (el.inCodigo) {
    el.inCodigo.addEventListener("input", () => {
      el.inCodigo.value = somenteDigitos(el.inCodigo.value).slice(0, 6);
      if (el.btnConfirmar) el.btnConfirmar.disabled = el.inCodigo.value.length !== 6;
    });
  }

  if (el.btnConfirmar) {
    el.btnConfirmar.addEventListener("click", async () => {
      if (!contatoPendente) return;
      el.btnConfirmar.disabled = true;
      mensagemContato("Validando...");
      try {
        const json = await requisitarContato({ acao: "validar", codigo: somenteDigitos(el.inCodigo?.value) });
        mensagemContato(json.user_msg || "Contato validado.", "sucesso");
        const eraUnificacao = contatoPendente.tipo === "unificar";
        contatoPendente = null;
        if (eraUnificacao && json.code === "CLIENT_UNIFY_PROOF_OK") {
          // Contato da outra conta comprovado: segue para a revisão (não recarrega a página).
          if (el.boxCodigo) el.boxCodigo.hidden = true;
          mostrarPreviaUnificacao(json.data);
          return;
        }
        window.setTimeout(() => window.location.reload(), 650);
      } catch (erro) {
        mensagemContato(erro.message || "Código inválido ou expirado.", "erro");
        el.btnConfirmar.disabled = (el.inCodigo?.value || "").length !== 6;
      }
    });
  }

  /* ============================
     UNIFICAÇÃO DE CONTAS (C4.2)
     Só com prova de posse do contato da outra conta (código) e confirmação expressa; o
     servidor revalida tudo (login recente, mesma empresa, elegibilidade, escolhas).
     ============================ */
  const un = {
    box: campo("unificacaoBox"),
    abrir: campo("btnUnificarAbrir"),
    passo1: campo("unificacaoPasso1"),
    passo2: campo("unificacaoPasso2"),
    tipo: campo("unif_tipo"),
    valor: campo("unif_valor"),
    enviar: campo("btnUnificarEnviar"),
    resumo: campo("unificacaoResumo"),
    escolhas: campo("unificacaoEscolhas"),
    aceite: campo("unif_aceite"),
    confirmar: campo("btnUnificarConfirmar"),
    mensagem: campo("unificacaoMensagem")
  };
  let unifNonce = "";
  let unifPrevia = null;

  function mensagemUnificacao(texto, tipo = "") {
    if (!un.mensagem) return;
    un.mensagem.textContent = texto || "";
    un.mensagem.className = `cadastro-mensagem${tipo ? ` ${tipo}` : ""}`;
  }

  // Chamada quando o cadastro recusa um contato que já é de outra conta da empresa.
  function oferecerUnificacao(contato) {
    if (!un.box || !un.passo1) return;
    un.box.hidden = false;
    un.passo1.hidden = false;
    if (un.tipo) un.tipo.value = contato === "email" ? "email" : "telefone";
    if (un.valor) {
      un.valor.value = contato === "email"
        ? String(campo("cli_email")?.value || "").trim()
        : String(campo("cli_whats_novo")?.value || "").trim();
    }
    mensagemUnificacao("Se este contato é de outra conta sua, você pode unificá-las confirmando o contato por código.");
  }

  function criarTexto(tag, texto, classe) {
    const no = document.createElement(tag);
    no.textContent = texto;
    if (classe) no.className = classe;
    return no;
  }

  function cartaoConta(titulo, c) {
    const div = document.createElement("div");
    div.className = "unificacao-conta";
    div.appendChild(criarTexto("strong", titulo));
    div.appendChild(criarTexto("span", `Nome: ${c.nome || "não informado"}`));
    div.appendChild(criarTexto("span", `Celular: ${c.telefone ? `${c.telefone} (${c.telefone_validado ? "validado" : "não validado"})` : "não informado"}`));
    div.appendChild(criarTexto("span", `E-mail: ${c.email ? `${c.email} (${c.email_validado ? "validado" : "não validado"})` : "não informado"}`));
    div.appendChild(criarTexto("span", `Agendamentos: ${c.agendamentos} · Conversas: ${c.conversas}`));
    return div;
  }

  function grupoEscolhaContato(nome, titulo, atual, outra) {
    const set = document.createElement("fieldset");
    set.className = "unificacao-escolha";
    set.appendChild(criarTexto("legend", titulo));
    [["atual", `Manter ${atual} (conta atual)`], ["outra", `Manter ${outra} (outra conta)`]].forEach(([valor, texto]) => {
      const rotulo = document.createElement("label");
      const radio = document.createElement("input");
      radio.type = "radio";
      radio.name = nome;
      radio.value = valor;
      radio.addEventListener("change", atualizarConfirmacaoUnificacao);
      rotulo.appendChild(radio);
      rotulo.appendChild(document.createTextNode(` ${texto}`));
      set.appendChild(rotulo);
    });
    set.appendChild(criarTexto("small", "O contato não escolhido deixa de ser usado para entrar, mas fica registrado para recuperação."));
    return set;
  }

  function mostrarPreviaUnificacao(dados) {
    unifNonce = String(dados?.nonce || "");
    unifPrevia = dados?.previa || null;
    if (!unifPrevia || !un.passo2 || !un.resumo || !un.escolhas) return;

    un.resumo.replaceChildren(
      cartaoConta("Esta conta (atual)", unifPrevia.atual),
      cartaoConta("Outra conta", unifPrevia.outra)
    );
    if (unifPrevia.sinais?.cpf_igual || unifPrevia.sinais?.nome_igual) {
      un.resumo.appendChild(criarTexto(
        "small",
        "O CPF ou o nome coincidem, mas isso é só um indício: a unificação vale porque você confirmou o contato por código."
      ));
    }

    un.escolhas.replaceChildren();
    if (unifPrevia.divergencias?.telefone) {
      un.escolhas.appendChild(grupoEscolhaContato("unif_escolha_telefone", "Os celulares são diferentes. Qual manter?", unifPrevia.atual.telefone, unifPrevia.outra.telefone));
    }
    if (unifPrevia.divergencias?.email) {
      un.escolhas.appendChild(grupoEscolhaContato("unif_escolha_email", "Os e-mails são diferentes. Qual manter?", unifPrevia.atual.email, unifPrevia.outra.email));
    }

    if (un.passo1) un.passo1.hidden = true;
    un.passo2.hidden = false;
    if (un.aceite) un.aceite.checked = false;
    atualizarConfirmacaoUnificacao();
    mensagemUnificacao("Contato confirmado. Revise e escolha a conta que será mantida.", "sucesso");
  }

  function escolhido(nome) {
    return document.querySelector(`input[name="${nome}"]:checked`)?.value || "";
  }

  function atualizarConfirmacaoUnificacao() {
    if (!un.confirmar) return;
    const faltaTel = Boolean(unifPrevia?.divergencias?.telefone) && !escolhido("unif_escolha_telefone");
    const faltaEmail = Boolean(unifPrevia?.divergencias?.email) && !escolhido("unif_escolha_email");
    un.confirmar.disabled = !(escolhido("unif_principal") && un.aceite?.checked && !faltaTel && !faltaEmail && unifNonce);
  }

  if (un.abrir && un.passo1) un.abrir.addEventListener("click", () => { un.passo1.hidden = !un.passo1.hidden; });

  document.querySelectorAll('input[name="unif_principal"]').forEach(r => r.addEventListener("change", atualizarConfirmacaoUnificacao));
  if (un.aceite) un.aceite.addEventListener("change", atualizarConfirmacaoUnificacao);

  if (un.enviar) {
    un.enviar.addEventListener("click", () => {
      const tipo = un.tipo?.value === "email" ? "email" : "telefone";
      const bruto = String(un.valor?.value || "").trim();
      const valor = tipo === "telefone" ? somenteDigitos(bruto) : bruto.toLowerCase();
      if (tipo === "telefone" && valor.length !== 11) {
        mensagemUnificacao("Informe o celular com DDD (11 dígitos).", "erro");
        return;
      }
      if (tipo === "email" && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(valor)) {
        mensagemUnificacao("Informe um e-mail válido.", "erro");
        return;
      }
      mensagemUnificacao("");
      // O código aparece na caixa de confirmação de contatos (mesmo fluxo e mesmas mensagens).
      solicitarContato({ acao: "unificar_solicitar", contato: tipo, valor }, { tipo: "unificar", contato: tipo }, un.enviar);
    });
  }

  if (un.confirmar) {
    un.confirmar.addEventListener("click", async () => {
      un.confirmar.disabled = true;
      mensagemUnificacao("Unificando as contas...");
      try {
        const json = await requisitarContato({
          acao: "unificar_confirmar",
          nonce: unifNonce,
          aceite: "1",
          principal: escolhido("unif_principal"),
          escolha_telefone: escolhido("unif_escolha_telefone"),
          escolha_email: escolhido("unif_escolha_email")
        });
        mensagemUnificacao(json.user_msg || "Contas unificadas.", "sucesso");
        window.setTimeout(() => window.location.reload(), 900);
      } catch (erro) {
        mensagemUnificacao(erro.message || "Não foi possível unificar as contas.", "erro");
        atualizarConfirmacaoUnificacao();
      }
    });
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
      erro.dados = json?.data || {};
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

      // Obrigatórios pendentes: abre bloqueado até salvar. Só opcionais: lembrete no sino.
      aplicarCadastroPendente(dados);
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

  campo("cli_email")?.addEventListener("input", atualizarDicaContato);
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
        headers: { Accept: "application/json", "X-CSRF-Token": String(window.__AUTH__?.csrf_token || "") },
        body: new FormData(formCadastro)
      });
      mensagemCadastro.textContent = json.user_msg || "Dados cadastrais salvos com sucesso.";
      mensagemCadastro.className = "cadastro-mensagem sucesso";
      window.setTimeout(() => window.location.reload(), 650);
    } catch (erro) {
      mensagemCadastro.textContent = erro.message || "Não foi possível salvar os dados cadastrais.";
      mensagemCadastro.className = "cadastro-mensagem erro";
      mostrarErros(erro.campos);
      if (erro.dados?.oferecer_unificacao) oferecerUnificacao(erro.dados.contato);
    } finally {
      botaoSalvarCadastro.disabled = false;
      botaoSalvarCadastro.classList.remove("carregando");
    }
  });
})();
