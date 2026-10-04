(() => {
  "use strict";

  /*
  |--------------------------------------------------------------------------
  | FAQ
  |--------------------------------------------------------------------------
  */

  const itensFaq =
    document.querySelectorAll(
      ".faq-item"
    );

  itensFaq.forEach(item => {

    const botao =
      item.querySelector(
        ".faq-pergunta"
      );

    if (!botao) {
      return;
    }

    botao.addEventListener(
      "click",
      () => {

        const estavaAberto =
          item.classList.contains(
            "aberto"
          );

        itensFaq.forEach(outro => {
          outro.classList.remove(
            "aberto"
          );
        });

        if (!estavaAberto) {
          item.classList.add(
            "aberto"
          );
        }

      }
    );

  });


  /*
  |--------------------------------------------------------------------------
  | LIGHTBOX DAS SCREENSHOTS
  |--------------------------------------------------------------------------
  */

  const siteLightbox =
    document.getElementById(
      "siteLightbox"
    );

  const siteLightboxImagem =
    document.getElementById(
      "siteLightboxImagem"
    );

  const siteLightboxLegenda =
    document.getElementById(
      "siteLightboxLegenda"
    );

  const siteLightboxFechar =
    document.getElementById(
      "siteLightboxFechar"
    );

  const movimentoReduzido =
    window.matchMedia?.(
      "(prefers-reduced-motion: reduce)"
    ).matches === true;

  let acionadorLightbox = null;
  let temporizadorLightbox = null;

  function lightboxDisponivel() {
    return Boolean(
      siteLightbox &&
      siteLightboxImagem &&
      siteLightboxLegenda &&
      siteLightboxFechar
    );
  }

  function abrirLightbox(imagem, acionador) {
    if (!lightboxDisponivel()) {
      return;
    }

    const origem =
      imagem.getAttribute("src");

    if (!origem) {
      return;
    }

    const descricao =
      String(
        imagem.getAttribute("alt") || ""
      ).trim();

    if (temporizadorLightbox) {
      window.clearTimeout(
        temporizadorLightbox
      );
      temporizadorLightbox = null;
    }

    acionadorLightbox = acionador;
    siteLightboxImagem.src = origem;
    siteLightboxImagem.alt = descricao;
    siteLightboxLegenda.textContent = descricao;
    siteLightboxLegenda.hidden = descricao === "";
    siteLightbox.hidden = false;
    document.body.classList.add(
      "site-lightbox-aberto"
    );

    window.requestAnimationFrame(() => {
      siteLightbox.classList.add(
        "aberto"
      );
    });

    siteLightboxFechar.focus({
      preventScroll: true
    });
  }

  function concluirFechamentoLightbox() {
    if (!lightboxDisponivel()) {
      return;
    }

    siteLightbox.hidden = true;
    siteLightboxImagem.removeAttribute(
      "src"
    );
    siteLightboxImagem.alt = "";
    siteLightboxLegenda.textContent = "";
    siteLightboxLegenda.hidden = true;

    const elementoParaFoco =
      acionadorLightbox;

    acionadorLightbox = null;
    temporizadorLightbox = null;

    if (
      elementoParaFoco instanceof HTMLElement &&
      elementoParaFoco.isConnected
    ) {
      elementoParaFoco.focus({
        preventScroll: true
      });
    }
  }

  function fecharLightbox() {
    if (
      !lightboxDisponivel() ||
      siteLightbox.hidden
    ) {
      return;
    }

    siteLightbox.classList.remove(
      "aberto"
    );
    document.body.classList.remove(
      "site-lightbox-aberto"
    );

    if (temporizadorLightbox) {
      window.clearTimeout(
        temporizadorLightbox
      );
    }

    if (movimentoReduzido) {
      concluirFechamentoLightbox();
      return;
    }

    temporizadorLightbox =
      window.setTimeout(
        concluirFechamentoLightbox,
        210
      );
  }

  document.addEventListener(
    "click",
    evento => {
      if (!(evento.target instanceof Element)) {
        return;
      }

      const acionador =
        evento.target.closest(
          ".site-screenshot-trigger"
        );

      if (!acionador) {
        return;
      }

      const imagem =
        acionador.querySelector(
          ".site-screenshot-ampliavel"
        );

      if (imagem instanceof HTMLImageElement) {
        abrirLightbox(
          imagem,
          acionador
        );
      }
    }
  );

  if (siteLightboxFechar) {
    siteLightboxFechar.addEventListener(
      "click",
      fecharLightbox
    );
  }

  if (siteLightbox) {
    siteLightbox.addEventListener(
      "click",
      evento => {
        if (evento.target === siteLightbox) {
          fecharLightbox();
        }
      }
    );
  }

  document.addEventListener(
    "keydown",
    evento => {
      if (
        evento.key === "Escape" &&
        siteLightbox &&
        !siteLightbox.hidden
      ) {
        fecharLightbox();
      }
    }
  );


  /*
  |--------------------------------------------------------------------------
  | TOAST
  |--------------------------------------------------------------------------
  */

  const toast =
    document.getElementById(
      "toastSistema"
    );

  const toastTitulo =
    document.getElementById(
      "toastTitulo"
    );

  const toastMensagem =
    document.getElementById(
      "toastMensagem"
    );

  let temporizadorToast = null;

  function mostrarToast(
    titulo,
    mensagem
  ) {

    if (
      !toast ||
      !toastTitulo ||
      !toastMensagem
    ) {
      return;
    }

    toastTitulo.textContent =
      titulo;

    toastMensagem.textContent =
      mensagem;

    toast.classList.add(
      "ativo"
    );

    if (temporizadorToast) {
      clearTimeout(
        temporizadorToast
      );
    }

    temporizadorToast =
      setTimeout(
        () => {

          toast.classList.remove(
            "ativo"
          );

        },
        3500
      );

  }


  /*
  |--------------------------------------------------------------------------
  | BOTÕES FUTUROS
  |--------------------------------------------------------------------------
  |
  | O botão de cadastro abre o modal de cadastro público.
  | Enquanto login empresarial e pagamento ainda não estiverem
  | integrados, esses botões apenas informam o usuário.
  |
  */

  document
    .querySelectorAll(
      "[data-futuro]"
    )
    .forEach(botao => {

      botao.addEventListener(
        "click",
        () => {

          const acao =
            botao.dataset.futuro;

          if (acao === "login") {

            window.location.assign(
              "/public/views/login-empresa.php"
            );

            return;
          }

          if (acao === "cadastro") {

            abrirCadastroPublico(botao);

          }

        }
      );

    });


  /*
  |--------------------------------------------------------------------------
  | PLANOS COMERCIAIS
  |--------------------------------------------------------------------------
  */

  const planosGrid =
    document.getElementById(
      "planosGrid"
    );

  const formatadorMoeda =
    new Intl.NumberFormat(
      "pt-BR",
      {
        style: "currency",
        currency: "BRL"
      }
    );

  const formatadorQuantidade =
    new Intl.NumberFormat(
      "pt-BR"
    );

  function criarElemento(
    tag,
    classe,
    texto
  ) {

    const elemento =
      document.createElement(tag);

    if (classe) {
      elemento.className = classe;
    }

    if (texto !== undefined) {
      elemento.textContent = texto;
    }

    return elemento;
  }

  function formatarPreco(valor) {

    const numero = Number(valor);

    if (!Number.isFinite(numero)) {
      return {
        simbolo: "R$",
        valor: "—"
      };
    }

    const partes =
      formatadorMoeda.formatToParts(numero);

    return {
      simbolo: partes
        .filter(parte => parte.type === "currency")
        .map(parte => parte.value)
        .join(""),
      valor: partes
        .filter(parte => parte.type !== "currency" && parte.type !== "literal")
        .map(parte => parte.value)
        .join("")
    };
  }

  function textoLimite(
    valor,
    singular,
    plural
  ) {

    const quantidade = Number(valor);
    const rotulo = quantidade === 1
      ? singular
      : plural;

    return `Até ${formatadorQuantidade.format(quantidade)} ${rotulo}`;
  }

  function adicionarLimite(
    lista,
    texto
  ) {

    const item = criarElemento("li");
    item.appendChild(
      criarElemento(
        "span",
        "check",
        "✓"
      )
    );
    item.appendChild(
      document.createTextNode(texto)
    );
    lista.appendChild(item);
  }

  const CHAVE_PLANO_CADASTRO =
    "amagenda:cadastro-publico:plano-id";

  let planoCadastroSelecionado = null;

  function selecionarPlanoParaCadastro(plano) {

    const idPlano = Number(plano.id_plano);

    if (
      !Number.isSafeInteger(idPlano) ||
      idPlano <= 0
    ) {
      mostrarToast(
        "Cadastro indisponível",
        "Não foi possível selecionar este plano agora."
      );
      return;
    }

    planoCadastroSelecionado = {
      plano_id: idPlano,
      nome: String(plano.nome ?? "").trim()
    };

    try {
      sessionStorage.setItem(
        CHAVE_PLANO_CADASTRO,
        String(idPlano)
      );
    } catch (_erroArmazenamento) {
      // O estado em memória mantém a seleção nesta navegação.
    }

    abrirCadastroPublico(null);
  }

  // =====================================================================
  // Cadastro público: estado
  // A validação no navegador é apenas conveniência de uso. O backend é a
  // autoridade para plano, identidade, CNPJ, documentos legais, senha,
  // período de teste e limites. Nenhuma sessão é criada por este fluxo.
  // =====================================================================

  const URL_API_PUBLICA =
    "/public/api/api_central.php";

  const DOCUMENTOS_CADASTRO = [
    "termos_empresa",
    "politica_privacidade"
  ];

  const idsPlanosCarregados = new Set();
  const nomesPlanosCarregados = new Map();

  let cadastroPlano = null;
  let documentosCadastro = null;
  let cargaDocumentosId = 0;
  let enviandoCadastro = false;
  let elementoFocoAnterior = null;

  function cadEl(id) {
    return document.getElementById(id);
  }

  function idPlanoValido(valor) {
    return (
      typeof valor === "number" &&
      Number.isSafeInteger(valor) &&
      valor > 0
    );
  }

  // Plano escolhido: memória, sessionStorage (somente o id) ou data-plano-id do botão.
  function lerPlanoArmazenado() {
    try {
      const bruto =
        sessionStorage.getItem(
          CHAVE_PLANO_CADASTRO
        );

      if (bruto === null || !/^[0-9]{1,15}$/.test(bruto)) {
        return null;
      }

      return Number(bruto);
    } catch (_erroArmazenamento) {
      return null;
    }
  }

  function descartarPlanoSelecionado() {
    planoCadastroSelecionado = null;

    try {
      sessionStorage.removeItem(
        CHAVE_PLANO_CADASTRO
      );
    } catch (_erroArmazenamento) {
      // Sem armazenamento disponível: nada a limpar.
    }
  }

  function obterPlanoCadastro(origem) {

    let idPlano = null;

    if (
      origem &&
      origem.dataset &&
      origem.dataset.planoId
    ) {
      idPlano = Number(origem.dataset.planoId);
    }

    if (!idPlanoValido(idPlano) && planoCadastroSelecionado) {
      idPlano = Number(planoCadastroSelecionado.plano_id);
    }

    if (!idPlanoValido(idPlano)) {
      idPlano = lerPlanoArmazenado();
    }

    if (!idPlanoValido(idPlano)) {
      return null;
    }

    if (
      idsPlanosCarregados.size > 0 &&
      !idsPlanosCarregados.has(idPlano)
    ) {
      descartarPlanoSelecionado();
      return null;
    }

    let nome = "";

    if (
      planoCadastroSelecionado &&
      Number(planoCadastroSelecionado.plano_id) === idPlano
    ) {
      nome = planoCadastroSelecionado.nome || "";
    }

    if (!nome) {
      nome = nomesPlanosCarregados.get(idPlano) || "";
    }

    return {
      plano_id: idPlano,
      nome: nome
    };
  }

  function orientarEscolhaDePlano() {

    mostrarToast(
      "Escolha um plano",
      "Selecione o plano desejado para criar a conta da empresa."
    );

    const secaoPlanos =
      cadEl("planos");

    if (secaoPlanos) {
      secaoPlanos.scrollIntoView({
        behavior: "smooth"
      });
    }
  }

  // =====================================================================
  // Cadastro público: feedback e estado visual
  // =====================================================================

  function mostrarAlertaCadastro(titulo, mensagem, tipo) {

    const alerta =
      cadEl("cadastroAlerta");

    const variante =
      tipo === "info" ? "info" : "erro";

    alerta.className =
      `cadastro-alerta ${variante}`;

    cadEl("cadastroAlertaIcone").textContent =
      variante === "info" ? "i" : "!";

    cadEl("cadastroAlertaTitulo").textContent =
      titulo;

    cadEl("cadastroAlertaTexto").textContent =
      mensagem;

    alerta.hidden = false;

    alerta.focus();
  }

  function limparAlertaCadastro() {

    const alerta =
      cadEl("cadastroAlerta");

    alerta.hidden = true;

    cadEl("cadastroAlertaTitulo").textContent = "";
    cadEl("cadastroAlertaTexto").textContent = "";
  }

  function limparErrosCampos() {

    document
      .querySelectorAll(
        "#cadastroForm [data-erro]"
      )
      .forEach(span => {
        span.textContent = "";
      });

    document
      .querySelectorAll(
        "#cadastroForm [data-campo]"
      )
      .forEach(campo => {
        campo.removeAttribute("aria-invalid");
        campo.removeAttribute("aria-describedby");
      });
  }

  function definirErroCampo(chave, mensagem) {

    const span =
      document.querySelector(
        `#cadastroForm [data-erro="${chave}"]`
      );

    if (!span) {
      return false;
    }

    span.textContent = mensagem;

    const campo =
      document.querySelector(
        `#cadastroForm [data-campo="${chave}"]`
      );

    if (campo) {

      if (!span.id) {
        span.id =
          `erro-${chave.replace(".", "-")}`;
      }

      campo.setAttribute("aria-invalid", "true");
      campo.setAttribute("aria-describedby", span.id);
    }

    return true;
  }

  function atualizarBotaoCadastro() {

    const botao =
      cadEl("cadastroEnviar");

    botao.disabled =
      enviandoCadastro ||
      documentosCadastro === null;

    botao.setAttribute(
      "aria-busy",
      enviandoCadastro ? "true" : "false"
    );

    cadEl("cadastroSpinner").hidden =
      !enviandoCadastro;

    cadEl("cadastroEnviarRotulo").textContent =
      enviandoCadastro
        ? "Criando conta..."
        : "Criar conta e iniciar teste grátis";
  }

  // =====================================================================
  // Cadastro público: documentos legais
  // Versão e hash vêm sempre do backend; o HTML publicado é sanitizado.
  // =====================================================================

  function sanitizarHtmlDocumento(html) {

    const documentoInerte =
      new DOMParser().parseFromString(
        html,
        "text/html"
      );

    documentoInerte.body
      .querySelectorAll(
        "script,style,iframe,object,embed,link,meta,base,form,input,button,textarea,select,svg,math,img,video,audio,source,frame,frameset"
      )
      .forEach(no => no.remove());

    documentoInerte.body
      .querySelectorAll("*")
      .forEach(elemento => {

        Array.from(elemento.attributes).forEach(atributo => {

          const nome =
            atributo.name.toLowerCase();

          if (
            nome.startsWith("on") ||
            nome === "style" ||
            nome === "srcdoc"
          ) {
            elemento.removeAttribute(atributo.name);
            return;
          }

          if (
            nome === "href" ||
            nome === "src" ||
            nome === "xlink:href" ||
            nome === "action" ||
            nome === "formaction"
          ) {

            const valor =
              atributo.value
                .trim()
                .toLowerCase();

            if (!/^(https?:|mailto:|#)/.test(valor)) {
              elemento.removeAttribute(atributo.name);
            }
          }
        });

        if (elemento.tagName === "A") {
          elemento.setAttribute("target", "_blank");
          elemento.setAttribute("rel", "noopener noreferrer");
        }
      });

    return Array.from(
      documentoInerte.body.childNodes
    ).map(no => document.importNode(no, true));
  }

  async function buscarDocumentoPublico(codigo) {

    const resposta = await fetch(
      `${URL_API_PUBLICA}?path=documentos-legais/publico&codigo=${encodeURIComponent(codigo)}`,
      {
        headers: {
          Accept: "application/json"
        },
        cache: "no-store",
        credentials: "omit"
      }
    );

    const payload =
      await resposta.json();

    const documento =
      payload &&
      payload.data &&
      payload.data.documento;

    if (
      !resposta.ok ||
      payload.ok !== true ||
      !documento ||
      documento.codigo !== codigo ||
      typeof documento.versao !== "string" ||
      documento.versao === "" ||
      typeof documento.hash_sha256 !== "string" ||
      !/^[0-9a-fA-F]{64}$/.test(documento.hash_sha256) ||
      typeof documento.conteudo_html !== "string"
    ) {
      throw new Error("Documento legal indisponível.");
    }

    return {
      codigo: codigo,
      titulo: String(documento.titulo ?? ""),
      versao: documento.versao,
      hash_sha256: documento.hash_sha256,
      conteudo_html: documento.conteudo_html
    };
  }

  function textoAceite(codigo, versao) {

    if (codigo === "termos_empresa") {
      return `Li e aceito os Termos de Uso da Empresa (versão ${versao}).`;
    }

    return `Declaro ciência da Política de Privacidade (versão ${versao}).`;
  }

  function renderizarEstadoDocumentos(mensagem, comTentativa) {

    const area =
      cadEl("cadastroDocs");

    const estado =
      criarElemento(
        "div",
        "cadastro-docs-estado",
        mensagem
      );

    if (comTentativa) {

      const botao =
        criarElemento(
          "button",
          "btn btn-secundario",
          "Tentar novamente"
        );

      botao.type = "button";
      botao.style.marginTop = "10px";

      botao.addEventListener(
        "click",
        carregarDocumentosCadastro
      );

      estado.appendChild(botao);
    }

    estado.setAttribute("role", "status");

    area.replaceChildren(estado);
  }

  const ROTULOS_DOCUMENTOS = {
    termos_empresa: {
      titulo: "Termos de Uso",
      icone: "file"
    },
    politica_privacidade: {
      titulo: "Política de Privacidade",
      icone: "shield"
    }
  };

  function criarIconeCadastro(nome, classe) {

    const ns =
      "http://www.w3.org/2000/svg";

    const svg =
      document.createElementNS(ns, "svg");

    svg.setAttribute("class", classe);
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");

    const uso =
      document.createElementNS(ns, "use");

    uso.setAttribute("href", `#cad-ic-${nome}`);

    svg.appendChild(uso);

    return svg;
  }

  function renderizarDocumentos() {

    const area =
      cadEl("cadastroDocs");

    area.replaceChildren();

    // Painel único de leitura: mostra o texto integral do documento escolhido.
    const painel =
      criarElemento("div", "cadastro-doc-painel");

    painel.id = "cadastroDocPainel";
    painel.hidden = true;

    const painelTitulo =
      criarElemento("div", "cadastro-doc-painel-titulo");

    const painelTexto =
      criarElemento("div", "cadastro-doc-texto");

    painelTexto.tabIndex = 0;

    painel.appendChild(painelTitulo);
    painel.appendChild(painelTexto);

    let codigoAberto = null;

    const botoesLeitura = [];

    DOCUMENTOS_CADASTRO.forEach(codigo => {

      const documento =
        documentosCadastro[codigo];

      const rotulos =
        ROTULOS_DOCUMENTOS[codigo] || {
          titulo: documento.titulo || codigo,
          icone: "file"
        };

      const cartao =
        criarElemento("div", "cadastro-doc");

      const caixa =
        document.createElement("input");

      caixa.type = "checkbox";
      caixa.id = `cadAceite_${codigo}`;
      caixa.dataset.documento = codigo;

      caixa.addEventListener(
        "change",
        () => {
          definirErroDocumentos("");
        }
      );

      cartao.appendChild(caixa);

      cartao.appendChild(
        criarIconeCadastro(
          rotulos.icone,
          "cadastro-doc-icone"
        )
      );

      const info =
        criarElemento("div", "cadastro-doc-info");

      const topo =
        criarElemento("div", "cadastro-doc-topo");

      const titulo =
        criarElemento("span", "cadastro-doc-titulo", `${rotulos.titulo} `);

      const obrigatorio =
        criarElemento("span", "cadastro-req", "*");

      obrigatorio.setAttribute("aria-hidden", "true");

      titulo.appendChild(obrigatorio);

      topo.appendChild(titulo);

      const leitura =
        criarElemento("button", "cadastro-leitura", "Ler documento");

      leitura.type = "button";
      leitura.setAttribute("aria-expanded", "false");
      leitura.setAttribute("aria-controls", "cadastroDocPainel");

      leitura.appendChild(
        criarIconeCadastro("external", "cadastro-ic")
      );

      botoesLeitura.push(leitura);

      leitura.addEventListener(
        "click",
        () => {

          const fechar =
            codigoAberto === codigo;

          codigoAberto =
            fechar ? null : codigo;

          botoesLeitura.forEach(botao => {
            botao.setAttribute("aria-expanded", "false");
          });

          if (fechar) {
            painel.hidden = true;
            return;
          }

          leitura.setAttribute("aria-expanded", "true");

          painelTitulo.textContent =
            `${rotulos.titulo} — versão ${documento.versao}`;

          painelTexto.replaceChildren(
            ...sanitizarHtmlDocumento(
              documento.conteudo_html
            )
          );

          painelTexto.scrollTop = 0;
          painel.hidden = false;

          painel.scrollIntoView({
            block: "nearest"
          });
        }
      );

      topo.appendChild(leitura);

      info.appendChild(topo);

      const descricao =
        criarElemento(
          "label",
          "cadastro-doc-descricao",
          textoAceite(codigo, documento.versao)
        );

      descricao.htmlFor = caixa.id;

      info.appendChild(descricao);

      cartao.appendChild(info);

      area.appendChild(cartao);
    });

    area.appendChild(painel);
  }

  function definirErroDocumentos(mensagem) {

    const span =
      document.querySelector(
        '#cadastroForm [data-erro="documentos"]'
      );

    if (span) {
      span.textContent = mensagem;
    }
  }

  async function carregarDocumentosCadastro() {

    const cargaId =
      ++cargaDocumentosId;

    documentosCadastro = null;

    definirErroDocumentos("");

    renderizarEstadoDocumentos(
      "Carregando os documentos legais...",
      false
    );

    atualizarBotaoCadastro();

    try {

      const documentos =
        await Promise.all(
          DOCUMENTOS_CADASTRO.map(
            buscarDocumentoPublico
          )
        );

      if (cargaId !== cargaDocumentosId) {
        return;
      }

      const mapa = {};

      documentos.forEach(documento => {
        mapa[documento.codigo] = documento;
      });

      documentosCadastro = mapa;

      renderizarDocumentos();

    } catch (erro) {

      if (cargaId !== cargaDocumentosId) {
        return;
      }

      console.error("Não foi possível carregar os documentos legais.", erro);

      renderizarEstadoDocumentos(
        "Não foi possível carregar os documentos legais agora. Tente novamente para continuar o cadastro.",
        true
      );
    }

    atualizarBotaoCadastro();
  }

  // =====================================================================
  // Cadastro público: máscaras visuais
  // Apenas formatam o que é exibido; o backend normaliza os valores.
  // =====================================================================

  function mascararCnpj(valor) {

    const d =
      valor.replace(/\D/g, "").slice(0, 14);

    let texto = d.slice(0, 2);

    if (d.length > 2) texto += "." + d.slice(2, 5);
    if (d.length > 5) texto += "." + d.slice(5, 8);
    if (d.length > 8) texto += "/" + d.slice(8, 12);
    if (d.length > 12) texto += "-" + d.slice(12, 14);

    return texto;
  }

  function mascararTelefone(valor) {

    const d =
      valor.replace(/\D/g, "").slice(0, 11);

    if (d.length === 0) {
      return "";
    }

    if (d.length <= 2) {
      return `(${d}`;
    }

    const corte = d.length > 10 ? 7 : 6;

    let texto = `(${d.slice(0, 2)}) ${d.slice(2, corte)}`;

    if (d.length > corte) {
      texto += "-" + d.slice(corte);
    }

    return texto;
  }

  document
    .querySelectorAll(
      "#cadastroForm [data-mascara]"
    )
    .forEach(campo => {

      campo.addEventListener(
        "input",
        () => {

          campo.value =
            campo.dataset.mascara === "cnpj"
              ? mascararCnpj(campo.value)
              : mascararTelefone(campo.value);
        }
      );
    });

  // =====================================================================
  // Cadastro público: validação (somente UX; o backend revalida tudo)
  // =====================================================================

  function cnpjValido(texto) {

    const digitos =
      texto.replace(/\D/g, "");

    if (
      digitos.length !== 14 ||
      /^(\d)\1+$/.test(digitos)
    ) {
      return false;
    }

    const digitoVerificador = base => {

      let soma = 0;
      let peso = base.length - 7;

      for (let i = 0; i < base.length; i++) {

        soma += Number(base[i]) * peso;

        peso--;

        if (peso < 2) {
          peso = 9;
        }
      }

      const resto = soma % 11;

      return resto < 2 ? 0 : 11 - resto;
    };

    return (
      digitoVerificador(digitos.slice(0, 12)) === Number(digitos[12]) &&
      digitoVerificador(digitos.slice(0, 13)) === Number(digitos[13])
    );
  }

  function emailValido(texto) {
    return (
      texto.length <= 160 &&
      /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(texto)
    );
  }

  function telefoneValido(texto) {

    if (
      texto.length > 20 ||
      !/^[0-9()+\-.\s]+$/.test(texto)
    ) {
      return false;
    }

    const quantidade =
      texto.replace(/\D/g, "").length;

    return quantidade >= 10 && quantidade <= 11;
  }

  function lerFormularioCadastro() {

    const texto = id =>
      cadEl(id).value.trim();

    return {
      empresa: {
        nome: texto("cadEmpresaNome"),
        cnpj: texto("cadEmpresaCnpj"),
        telefone: texto("cadEmpresaTelefone"),
        email: texto("cadEmpresaEmail")
      },
      proprietario: {
        nome: texto("cadProprietarioNome"),
        email: texto("cadProprietarioEmail"),
        telefone: texto("cadProprietarioTelefone"),
        senha: cadEl("cadProprietarioSenha").value,
        senha2: cadEl("cadProprietarioSenha2").value
      }
    };
  }

  function validarFormularioCadastro(dados) {

    const erros = {};

    const nomeEmpresa = dados.empresa.nome;
    const nomeProprietario = dados.proprietario.nome;

    if (nomeEmpresa.length < 3 || nomeEmpresa.length > 140) {
      erros["empresa.nome"] =
        "Informe o nome da empresa com pelo menos 3 caracteres.";
    }

    if (dados.empresa.cnpj === "") {
      erros["empresa.cnpj"] =
        "Informe o CNPJ da empresa.";
    } else if (
      !/^[0-9.\/\-\s]+$/.test(dados.empresa.cnpj) ||
      !cnpjValido(dados.empresa.cnpj)
    ) {
      erros["empresa.cnpj"] =
        "Confira o CNPJ: ele deve ter 14 números válidos.";
    }

    if (!telefoneValido(dados.empresa.telefone)) {
      erros["empresa.telefone"] =
        "Informe o telefone com DDD, por exemplo (11) 91234-5678.";
    }

    if (!emailValido(dados.empresa.email)) {
      erros["empresa.email"] =
        "Informe um e-mail válido, como nome@empresa.com.br.";
    }

    if (nomeProprietario.length < 3 || nomeProprietario.length > 140) {
      erros["proprietario.nome"] =
        "Informe seu nome com pelo menos 3 caracteres.";
    }

    if (!emailValido(dados.proprietario.email)) {
      erros["proprietario.email"] =
        "Informe um e-mail válido, como nome@empresa.com.br.";
    }

    if (!telefoneValido(dados.proprietario.telefone)) {
      erros["proprietario.telefone"] =
        "Informe o telefone com DDD, por exemplo (11) 91234-5678.";
    }

    const senha = dados.proprietario.senha;

    const bytesSenha =
      new TextEncoder().encode(senha).length;

    if (senha.length < 8) {
      erros["proprietario.senha"] =
        "Use uma senha com pelo menos 8 caracteres.";
    } else if (bytesSenha > 72) {
      erros["proprietario.senha"] =
        "A senha é muito longa. Use uma senha mais curta.";
    }

    if (dados.proprietario.senha2 !== senha || senha === "") {
      erros["proprietario.senha2"] =
        "A confirmação precisa ser igual à senha digitada.";
    }

    if (!documentosCadastro) {
      erros.documentos =
        "Aguarde o carregamento dos documentos legais.";
    } else {

      const todosAceitos =
        DOCUMENTOS_CADASTRO.every(codigo => {

          const caixa =
            document.querySelector(
              `#cadastroDocs input[data-documento="${codigo}"]`
            );

          return Boolean(caixa && caixa.checked);
        });

      if (!todosAceitos) {
        erros.documentos =
          "Para continuar, aceite os Termos de Uso da Empresa e confirme a ciência da Política de Privacidade.";
      }
    }

    return erros;
  }

  function aplicarErrosCadastro(erros) {

    let primeiro = null;

    Object.keys(erros).forEach(chave => {

      if (chave === "documentos") {
        definirErroDocumentos(erros[chave]);
        return;
      }

      if (definirErroCampo(chave, erros[chave]) && primeiro === null) {
        primeiro =
          document.querySelector(
            `#cadastroForm [data-campo="${chave}"]`
          );
      }
    });

    if (primeiro) {
      primeiro.focus();
    } else if (erros.documentos) {
      const caixa =
        document.querySelector(
          "#cadastroDocs input[data-documento]"
        );

      if (caixa) {
        caixa.focus();
      }
    }
  }

  // =====================================================================
  // Cadastro público: respostas do servidor
  // =====================================================================

  let acaoResultadoSecundario = "fechar";

  function mostrarResultadoCadastro(variante, conteudo) {

    const resultado =
      cadEl("cadastroResultado");

    resultado.className =
      `cadastro-resultado ${variante}`;

    cadEl("cadastroResultadoTitulo").textContent =
      conteudo.titulo;

    cadEl("cadastroResultadoDescricao").textContent =
      conteudo.descricao;

    cadEl("cadastroResultadoOrientacao").textContent =
      conteudo.orientacao;

    acaoResultadoSecundario =
      conteudo.acaoSecundaria;

    cadEl("cadastroResultadoSecundario").textContent =
      conteudo.acaoSecundaria === "revisar"
        ? "Revisar dados"
        : "Fechar";

    limparAlertaCadastro();
    limparErrosCampos();

    cadEl("cadastroForm").hidden = true;
    resultado.hidden = false;

    resultado.focus();
  }

  function limparDadosCadastro() {

    [
      "cadEmpresaNome",
      "cadEmpresaCnpj",
      "cadEmpresaTelefone",
      "cadEmpresaEmail",
      "cadProprietarioNome",
      "cadProprietarioEmail",
      "cadProprietarioTelefone",
      "cadProprietarioSenha",
      "cadProprietarioSenha2"
    ].forEach(id => {
      cadEl(id).value = "";
    });

    documentosCadastro = null;
    cargaDocumentosId++;
    cadEl("cadastroDocs").replaceChildren();

    descartarPlanoSelecionado();
    cadastroPlano = null;
  }

  function mostrarSucessoCadastro(dados) {

    const empresaNome =
      dados && typeof dados.empresa_nome === "string"
        ? dados.empresa_nome.trim()
        : "";

    limparDadosCadastro();

    mostrarResultadoCadastro(
      "sucesso",
      {
        titulo: "Conta criada com sucesso!",
        descricao:
          empresaNome
            ? `A empresa ${empresaNome} foi cadastrada e o teste gratuito de 30 dias já começou.`
            : "A empresa foi cadastrada e o teste gratuito de 30 dias já começou.",
        orientacao:
          "Para sua segurança, você não foi conectado automaticamente. Use a opção Entrar com o e-mail e a senha cadastrados.",
        acaoSecundaria: "fechar"
      }
    );
  }

  function mostrarEmpresaJaCadastrada() {

    // Os dados digitados permanecem no formulário para que o CNPJ possa ser corrigido.
    mostrarResultadoCadastro(
      "aviso",
      {
        titulo: "Esta empresa já possui cadastro no AmAgenda.",
        descricao:
          "Identificamos que este CNPJ já está vinculado a uma empresa cadastrada.",
        orientacao:
          "Se você já é o responsável pela empresa, utilize a opção Entrar para acessar sua conta. Se não conseguir acessar, entre em contato com o suporte.",
        acaoSecundaria: "revisar"
      }
    );
  }

  function tratarRespostaCadastro(status, payload) {

    const codigo =
      payload && typeof payload.code === "string"
        ? payload.code
        : "";

    if (
      (status === 200 || status === 201) &&
      payload &&
      payload.ok === true &&
      codigo === "SIGNUP_COMPLETED"
    ) {
      mostrarSucessoCadastro(payload.data);
      return;
    }

    if (status === 409 && codigo === "COMPANY_ALREADY_EXISTS") {
      mostrarEmpresaJaCadastrada();
      return;
    }

    if (codigo === "VALIDATION_ERROR") {

      const campos =
        payload && payload.fields && typeof payload.fields === "object"
          ? payload.fields
          : {};

      const erros = {};
      let temGeral = false;

      Object.keys(campos).forEach(chave => {

        const mensagem =
          String(campos[chave] ?? "");

        if (
          mensagem !== "" &&
          document.querySelector(
            `#cadastroForm [data-campo="${chave}"]`
          )
        ) {
          erros[chave] = mensagem;
        } else if (chave === "documentos") {
          erros.documentos = mensagem;
        } else {
          temGeral = true;
        }
      });

      aplicarErrosCadastro(erros);

      mostrarAlertaCadastro(
        "Revise os dados informados.",
        temGeral || Object.keys(erros).length === 0
          ? "Algumas informações não puderam ser aceitas. Confira o formulário e tente novamente."
          : "Corrija os campos destacados abaixo e envie novamente."
      );

      return;
    }

    if (codigo === "PLAN_UNAVAILABLE") {

      descartarPlanoSelecionado();

      cadastroPlano = null;

      fecharCadastroPublico();

      mostrarToast(
        "Plano indisponível",
        "O plano selecionado não está mais disponível. Escolha outro plano para continuar."
      );

      orientarEscolhaDePlano();

      return;
    }

    if (codigo === "DOCUMENT_VERSION_CHANGED") {

      carregarDocumentosCadastro();

      mostrarAlertaCadastro(
        "Os documentos foram atualizados.",
        "Revise a nova versão e confirme novamente para continuar."
      );

      return;
    }

    if (codigo === "DOCUMENT_NOT_AVAILABLE") {

      mostrarAlertaCadastro(
        "Documentos temporariamente indisponíveis.",
        "Os documentos necessários para o cadastro estão temporariamente indisponíveis. Tente novamente em instantes."
      );

      return;
    }

    if (codigo === "SIGNUP_BUSY") {

      mostrarAlertaCadastro(
        "Cadastro em andamento.",
        "Seu cadastro já está sendo processado. Aguarde alguns instantes e tente novamente.",
        "info"
      );

      return;
    }

    if (status === 429) {

      mostrarAlertaCadastro(
        "Muitas tentativas de cadastro.",
        "Aguarde alguns minutos e tente novamente."
      );

      return;
    }

    if (codigo === "SIGNUP_REFUSED") {

      mostrarAlertaCadastro(
        "Não foi possível concluir o cadastro.",
        "Confira os dados informados e tente novamente. Se o problema continuar, entre em contato com o suporte."
      );

      return;
    }

    mostrarAlertaCadastro(
      "Não foi possível concluir o cadastro neste momento.",
      "Tente novamente em instantes. Se o problema continuar, entre em contato com o suporte."
    );
  }

  // =====================================================================
  // Cadastro público: envio
  // O botão fica bloqueado durante a requisição para impedir envio duplo.
  // =====================================================================

  async function enviarCadastroPublico(evento) {

    evento.preventDefault();

    if (enviandoCadastro) {
      return;
    }

    limparAlertaCadastro();
    limparErrosCampos();
    definirErroDocumentos("");

    if (!cadastroPlano || !idPlanoValido(cadastroPlano.plano_id)) {
      fecharCadastroPublico();
      orientarEscolhaDePlano();
      return;
    }

    const dados =
      lerFormularioCadastro();

    const erros =
      validarFormularioCadastro(dados);

    if (Object.keys(erros).length > 0) {

      aplicarErrosCadastro(erros);

      mostrarAlertaCadastro(
        "Revise os campos destacados.",
        "Corrija as informações indicadas abaixo para continuar."
      );

      return;
    }

    const corpo = {
      plano_id: cadastroPlano.plano_id,
      empresa: dados.empresa,
      proprietario: dados.proprietario,
      documentos: DOCUMENTOS_CADASTRO.map(codigo => ({
        codigo: codigo,
        versao: documentosCadastro[codigo].versao,
        hash_sha256: documentosCadastro[codigo].hash_sha256
      }))
    };

    enviandoCadastro = true;
    atualizarBotaoCadastro();

    let status = 0;
    let payload = null;

    try {

      const resposta = await fetch(
        `${URL_API_PUBLICA}?path=cadastro-publico/empresa`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json"
          },
          body: JSON.stringify(corpo),
          cache: "no-store",
          credentials: "omit"
        }
      );

      status = resposta.status;

      try {
        payload = await resposta.json();
      } catch (_erroJson) {
        payload = null;
      }

    } catch (_erroRede) {
      status = 0;
    }

    enviandoCadastro = false;

    tratarRespostaCadastro(status, payload);

    atualizarBotaoCadastro();
  }

  // =====================================================================
  // Cadastro público: acessibilidade (foco, Esc e retorno ao disparador)
  // =====================================================================

  function abrirCadastroPublico(origem) {

    const plano =
      obterPlanoCadastro(origem);

    if (!plano) {
      orientarEscolhaDePlano();
      return;
    }

    cadastroPlano = plano;

    elementoFocoAnterior =
      origem instanceof HTMLElement
        ? origem
        : document.activeElement;

    cadEl("cadastroPlanoInfo").textContent =
      plano.nome
        ? `Plano escolhido: ${plano.nome}`
        : "Plano escolhido";

    limparAlertaCadastro();
    limparErrosCampos();

    cadEl("cadProprietarioSenha").value = "";
    cadEl("cadProprietarioSenha2").value = "";

    cadEl("cadastroResultado").hidden = true;
    cadEl("cadastroForm").hidden = false;

    cadEl("cadastroModal").hidden = false;

    document.body.classList.add(
      "cadastro-aberto"
    );

    atualizarBotaoCadastro();

    carregarDocumentosCadastro();

    cadEl("cadEmpresaNome").focus();
  }

  function fecharCadastroPublico() {

    if (enviandoCadastro) {
      return;
    }

    cadEl("cadastroModal").hidden = true;

    document.body.classList.remove(
      "cadastro-aberto"
    );

    cargaDocumentosId++;

    cadEl("cadProprietarioSenha").value = "";
    cadEl("cadProprietarioSenha2").value = "";

    if (
      elementoFocoAnterior &&
      typeof elementoFocoAnterior.focus === "function" &&
      document.contains(elementoFocoAnterior)
    ) {
      elementoFocoAnterior.focus();
    }
  }

  function prenderFocoCadastro(evento) {

    if (evento.key === "Escape") {
      fecharCadastroPublico();
      return;
    }

    if (evento.key !== "Tab") {
      return;
    }

    const focaveis =
      Array.from(
        cadEl("cadastroModal").querySelectorAll(
          "button, input, [href], [tabindex]:not([tabindex='-1'])"
        )
      ).filter(
        elemento =>
          !elemento.disabled &&
          elemento.offsetParent !== null
      );

    if (focaveis.length === 0) {
      return;
    }

    const primeiro = focaveis[0];
    const ultimo = focaveis[focaveis.length - 1];

    if (evento.shiftKey && document.activeElement === primeiro) {
      evento.preventDefault();
      ultimo.focus();
    } else if (!evento.shiftKey && document.activeElement === ultimo) {
      evento.preventDefault();
      primeiro.focus();
    }
  }

  // Alternar visibilidade da senha (o valor digitado não é alterado).
  document
    .querySelectorAll(
      "#cadastroForm .cadastro-olho"
    )
    .forEach(botao => {

      botao.addEventListener(
        "click",
        () => {

          const campo =
            cadEl(botao.dataset.alvo);

          const visivel =
            campo.type === "text";

          campo.type =
            visivel ? "password" : "text";

          botao.setAttribute(
            "aria-pressed",
            visivel ? "false" : "true"
          );

          botao.setAttribute(
            "aria-label",
            visivel ? "Mostrar senha" : "Ocultar senha"
          );
        }
      );
    });

  // =====================================================================
  // Cadastro público: eventos
  // =====================================================================

  cadEl("cadastroForm").addEventListener(
    "submit",
    enviarCadastroPublico
  );

  cadEl("cadastroFechar").addEventListener(
    "click",
    fecharCadastroPublico
  );

  cadEl("cadastroCancelar").addEventListener(
    "click",
    fecharCadastroPublico
  );

  cadEl("cadastroResultadoSecundario").addEventListener(
    "click",
    () => {

      if (acaoResultadoSecundario === "revisar") {

        cadEl("cadastroResultado").hidden = true;
        cadEl("cadastroForm").hidden = false;

        cadEl("cadEmpresaCnpj").focus();

        return;
      }

      fecharCadastroPublico();
    }
  );

  cadEl("cadastroResultadoEntrar").addEventListener(
    "click",
    () => {

      window.location.assign(
        "/public/views/login-empresa.php"
      );
    }
  );

  cadEl("cadastroModal").addEventListener(
    "keydown",
    prenderFocoCadastro
  );


  function criarCardPlano(plano) {

    const destacado =
      Number(plano.destaque) === 1;

    const card = criarElemento(
      "article",
      destacado
        ? "plano destaque"
        : "plano"
    );

    if (destacado) {
      card.appendChild(
        criarElemento(
          "span",
          "plano-selo",
          "MAIS ESCOLHIDO"
        )
      );
    }

    card.appendChild(
      criarElemento(
        "h3",
        "",
        String(plano.nome ?? "")
      )
    );

    card.appendChild(
      criarElemento(
        "p",
        "plano-descricao",
        String(plano.descricao ?? "")
      )
    );

    const precoFormatado =
      formatarPreco(plano.preco_mensal);
    const preco = criarElemento(
      "div",
      "preco"
    );
    preco.appendChild(
      criarElemento(
        "small",
        "",
        precoFormatado.simbolo
      )
    );
    preco.appendChild(
      criarElemento(
        "strong",
        "",
        precoFormatado.valor
      )
    );
    preco.appendChild(
      criarElemento(
        "span",
        "",
        `/${String(plano.cobranca ?? "")}`
      )
    );
    card.appendChild(preco);

    const limites = criarElemento("ul");
    adicionarLimite(limites, textoLimite(plano.limite_usuarios, "usuário", "usuários"));
    adicionarLimite(limites, textoLimite(plano.limite_proprietarios, "proprietário", "proprietários"));
    adicionarLimite(limites, textoLimite(plano.limite_profissionais, "profissional", "profissionais"));
    adicionarLimite(limites, textoLimite(plano.limite_recepcionistas, "recepcionista", "recepcionistas"));
    adicionarLimite(limites, textoLimite(plano.limite_servicos, "serviço", "serviços"));
    adicionarLimite(limites, textoLimite(plano.limite_agendamentos, "agendamento", "agendamentos"));
    card.appendChild(limites);

    const botao = criarElemento(
      "button",
      destacado
        ? "btn btn-primary"
        : "btn btn-secundario",
      `Escolher ${String(plano.nome ?? "")}`
    );
    botao.type = "button";
    botao.dataset.planoId = String(plano.id_plano ?? "");
    botao.addEventListener(
      "click",
      () => {
        selecionarPlanoParaCadastro(plano);
      }
    );
    card.appendChild(botao);

    return card;
  }

  async function carregarPlanos() {

    if (!planosGrid) {
      return;
    }

    try {
      const resposta = await fetch(
        "/public/api/api_central.php?path=planos/listar",
        {
          headers: {
            Accept: "application/json"
          },
          cache: "no-store"
        }
      );
      const payload = await resposta.json();

      if (!resposta.ok || payload.ok !== true || !Array.isArray(payload.data)) {
        throw new Error("Resposta inválida ao carregar planos.");
      }

      planosGrid.replaceChildren();

      if (payload.data.length === 0) {
        planosGrid.appendChild(
          criarElemento(
            "p",
            "",
            "Nenhum plano está disponível no momento."
          )
        );
        return;
      }

      idsPlanosCarregados.clear();
      nomesPlanosCarregados.clear();

      payload.data.forEach(plano => {

        const idPlano = Number(plano.id_plano);

        if (idPlanoValido(idPlano)) {
          idsPlanosCarregados.add(idPlano);
          nomesPlanosCarregados.set(
            idPlano,
            String(plano.nome ?? "").trim()
          );
        }

        planosGrid.appendChild(
          criarCardPlano(plano)
        );
      });
    } catch (erro) {
      console.error("Não foi possível carregar os planos.", erro);
      planosGrid.replaceChildren(
        criarElemento(
          "p",
          "",
          "Não foi possível carregar os planos no momento."
        )
      );
    }
  }

  carregarPlanos();


  /*
  |--------------------------------------------------------------------------
  | MENU MOBILE
  |--------------------------------------------------------------------------
  */

  // Painel de navegação do celular/tablet: reutiliza os mesmos links do
  // menu desktop (.nav). Abaixo de 1050px o .nav fica oculto e passa a ser
  // exibido como painel suspenso quando o cabeçalho recebe .menu-aberto.
  const botaoMenu =
    document.getElementById(
      "botaoMenuMobile"
    );

  const topoSite =
    document.querySelector(".topo");

  const navPrincipal =
    document.getElementById(
      "navPrincipal"
    );

  const consultaMenuMobile =
    window.matchMedia(
      "(max-width: 1050px)"
    );

  function menuMobileAberto() {
    return Boolean(
      topoSite &&
      topoSite.classList.contains(
        "menu-aberto"
      )
    );
  }

  function definirMenuMobile(aberto, devolverFoco = false) {
    if (!botaoMenu || !topoSite || !navPrincipal) {
      return;
    }

    topoSite.classList.toggle(
      "menu-aberto",
      aberto
    );

    botaoMenu.setAttribute(
      "aria-expanded",
      aberto ? "true" : "false"
    );

    botaoMenu.setAttribute(
      "aria-label",
      aberto ? "Fechar menu" : "Abrir menu"
    );

    botaoMenu.textContent =
      aberto ? "✕" : "☰";

    if (!aberto && devolverFoco) {
      botaoMenu.focus({
        preventScroll: true
      });
    }
  }

  if (botaoMenu && topoSite && navPrincipal) {

    botaoMenu.addEventListener(
      "click",
      () => {
        definirMenuMobile(
          !menuMobileAberto()
        );
      }
    );

    // Escolher um destino fecha o painel (a rolagem âncora segue normal).
    navPrincipal.addEventListener(
      "click",
      evento => {
        if (
          evento.target instanceof Element &&
          evento.target.closest("a")
        ) {
          definirMenuMobile(false);
        }
      }
    );

    // Toque fora do cabeçalho fecha o painel.
    document.addEventListener(
      "click",
      evento => {
        if (
          menuMobileAberto() &&
          evento.target instanceof Node &&
          !topoSite.contains(evento.target)
        ) {
          definirMenuMobile(false);
        }
      }
    );

    document.addEventListener(
      "keydown",
      evento => {
        if (
          evento.key === "Escape" &&
          menuMobileAberto()
        ) {
          definirMenuMobile(false, true);
        }
      }
    );

    // Ao voltar para o layout desktop, o painel não pode ficar preso aberto.
    const aoMudarLayout = evento => {
      if (!evento.matches) {
        definirMenuMobile(false);
      }
    };

    if (typeof consultaMenuMobile.addEventListener === "function") {
      consultaMenuMobile.addEventListener("change", aoMudarLayout);
    } else if (typeof consultaMenuMobile.addListener === "function") {
      consultaMenuMobile.addListener(aoMudarLayout);
    }
  }

})();
