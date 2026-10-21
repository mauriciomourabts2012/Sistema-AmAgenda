/**
 * AmAgenda - Componente IndicadorSenha
 * ---------------------------------------------------------------------------
 * Mostra, em tempo real, os requisitos de uma senha DEFINITIVA.
 * É apenas um auxílio visual: a validação oficial é sempre a do backend
 * (backend/_regras/politica_senha.php). Este componente NÃO altera valores,
 * NÃO armazena, NÃO registra e NÃO envia senhas a lugar nenhum.
 *
 * Uso:
 *   const ind = IndicadorSenha.anexar({
 *     campoNova:        document.getElementById("novaSenha"),      // obrigatório
 *     campoConfirmacao: document.getElementById("confirmaSenha"),  // opcional
 *     campoAtual:       document.getElementById("senhaAtual"),     // opcional
 *     dadosPessoais:    { nome, email, telefone },                 // opcional (objeto ou função)
 *     opcional:         false,   // true = campo pode ficar vazio (ex.: edição sem trocar senha)
 *     container:        null     // elemento ou seletor onde o indicador será inserido
 *   });
 *   ind.limpar();      // reinicia o indicador (não altera os campos)
 *   ind.destruir();    // remove o indicador e os ouvintes
 *   ind.obterEstado(); // { valido, requisitos, recomendacoes } - sem dados da senha
 *
 * Política refletida (backend): mínimo 8 caracteres, máximo 72 bytes (bcrypt),
 * pelo menos um caractere especial (pontuação ou símbolo Unicode, /[\p{P}\p{S}]/u,
 * igual ao backend), sem caracteres de controle/invisíveis, diferente da atual,
 * não previsível, confirmação idêntica. NÃO exige maiúsculas, minúsculas nem números.
 */
(function (global) {
  "use strict";

  var MIN_CARACTERES = 8;
  var MAX_BYTES = 72;
  var RECOMENDADO_CARACTERES = 12;
  var ATRASO_ANUNCIO_MS = 900;

  var TEXTOS = {
    tituloRequisitos: "Requisitos",
    tituloRecomendacoes: "Para deixar ainda mais forte (opcional)",
    min: "Pelo menos 8 caracteres.",
    especial: "Pelo menos um caractere especial, como #, @ ou !.",
    max: "No máximo 72 caracteres (acentos e símbolos podem contar mais).",
    maxExcedido: "A senha está muito longa. Reduza o tamanho.",
    chars: "Sem caracteres invisíveis ou de controle.",
    charsInvalidos: "Remova os caracteres invisíveis ou de controle.",
    diferente: "A nova senha deve ser diferente da senha atual.",
    previsivel: "Não ser fácil de adivinhar.",
    previsivelInvalido: "Esta senha parece fácil de adivinhar. Escolha uma senha mais segura.",
    dadosPessoais: "Não use seu nome, e-mail ou telefone como senha.",
    confPendente: "Repita a senha para confirmar.",
    confContinue: "Continue repetindo a senha.",
    confOk: "As senhas coincidem.",
    confErro: "As senhas informadas não coincidem.",
    rec12: "12 caracteres ou mais.",
    recMix: "Combine palavras e números.",
    opcionalVazio: "Deixe em branco para manter a senha atual.",
    resumoOk: "Senha válida. Todos os requisitos foram atendidos.",
    resumoOkSemConf: "Requisitos atendidos. Repita a senha para confirmar.",
    srOk: "Atendido: ",
    srPendente: "Pendente: ",
    srInvalido: "Não atendido: ",
    srRecOk: "Recomendação atendida: ",
    srRec: "Recomendação: "
  };

  var ICONES = { ok: "✓", pendente: "○", invalido: "✕", sugestao: "○" };

  // Pequeno conjunto de referência (NÃO é a lista completa; o backend é a autoridade).
  var RADICAIS_COMUNS = {
    "senha": 1, "senha123": 1, "password": 1, "admin": 1, "admin123": 1, "qwerty": 1,
    "abc123": 1, "iloveyou": 1, "letmein": 1, "welcome": 1, "mudar123": 1, "amagenda": 1,
    "12345678": 1, "123456789": 1, "1234567890": 1, "11111111": 1, "00000000": 1
  };
  var SEQUENCIAS = [
    "0123456789", "abcdefghijklmnopqrstuvwxyz",
    "qwertyuiop", "asdfghjkl", "zxcvbnm", "1qaz2wsx", "qazwsx"
  ];

  var instancias = (typeof WeakMap === "function") ? new WeakMap() : null;
  var contadorId = 0;

  // -------------------------------------------------------------------------
  // Utilitários (nenhum deles guarda ou registra valores)
  // -------------------------------------------------------------------------
  function contarCaracteres(v) { return Array.from(v).length; }

  function contarBytes(v) {
    try { return new TextEncoder().encode(v).length; }
    catch (e) { return unescape(encodeURIComponent(v)).length; }
  }

  function temCaracteresInvalidos(v) {
    try { return /[\p{Cc}\p{Cf}\p{Cs}\p{Zl}\p{Zp}]/u.test(v); }
    catch (e) { return /[\u0000-\u001F\u007F-\u009F\u200B-\u200F\u2028-\u202E\u2060-\u2064\uFEFF\u00AD]/.test(v); }
  }

  // Mesma definição do backend (politica_senha.php): pontuação ou símbolo Unicode.
  function temCaractereEspecial(v) {
    try { return /[\p{P}\p{S}]/u.test(v); }
    catch (e) { return /[!-\/:-@\[-`{-~\u00A1-\u00BF\u2010-\u2027\u2030-\u205E\u20A0-\u20CF]/.test(v); }
  }

  function temLetra(v) {
    try { return /\p{L}/u.test(v); } catch (e) { return /[A-Za-zÀ-ɏ]/.test(v); }
  }

  function removerNaoAlfanumericos(v) {
    try { return v.replace(/[^\p{L}\p{N}]+/gu, ""); } catch (e) { return v.replace(/[^A-Za-z0-9À-ɏ]+/g, ""); }
  }

  function aparar(v) {
    try { return v.replace(/^[^\p{L}]+|[^\p{L}]+$/gu, ""); } catch (e) { return v.replace(/^[^A-Za-zÀ-ɏ]+|[^A-Za-zÀ-ɏ]+$/g, ""); }
  }

  function formaComparacao(v) { return v.toLowerCase().replace(/\s+/g, ""); }

  function ehRepetitiva(v) {
    try { return /^(.{1,4}?)\1+$/su.test(v); } catch (e) { return false; }
  }

  function ehSequencial(v) {
    if (v.length < 4 || !/^[a-z0-9]+$/.test(v)) return false;
    for (var i = 0; i < SEQUENCIAS.length; i++) {
      var s = SEQUENCIAS[i];
      if (s.indexOf(v) !== -1 || s.split("").reverse().join("").indexOf(v) !== -1) return true;
    }
    return false;
  }

  function ehPrevisivel(v) {
    var forma = formaComparacao(v);
    if (forma === "") return true;
    var alnum = removerNaoAlfanumericos(forma);
    var subst = forma.replace(/[013457@$]/g, function (c) {
      return { "0": "o", "1": "i", "3": "e", "4": "a", "5": "s", "7": "t", "@": "a", "$": "s" }[c];
    });
    var candidatos = [forma, alnum, aparar(forma), subst, aparar(subst)];
    for (var i = 0; i < candidatos.length; i++) {
      if (candidatos[i] && RADICAIS_COMUNS[candidatos[i]] === 1) return true;
    }
    return [forma, alnum].some(function (c) { return c && (ehRepetitiva(c) || ehSequencial(c)); });
  }

  function correspondeDadosPessoais(v, dados) {
    if (!dados) return false;
    var forma = formaComparacao(v);
    if (forma === "") return false;
    var alvos = [];
    var nome = formaComparacao(String(dados.nome || ""));
    var email = formaComparacao(String(dados.email || ""));
    if (nome) alvos.push(nome);
    if (email) {
      alvos.push(email);
      var local = email.split("@")[0];
      if (local) alvos.push(local);
    }
    if (alvos.indexOf(forma) !== -1) return true;

    var tel = String(dados.telefone || "").replace(/\D+/g, "");
    if (tel && !temLetra(v)) {
      var dig = forma.replace(/\D+/g, "");
      if (dig && (dig === tel || (tel.length >= 8 && (dig === tel.slice(-8) || dig === tel.slice(-9))))) return true;
    }
    return false;
  }

  function obterValor(campo) { return campo ? String(campo.value == null ? "" : campo.value) : ""; }

  function resolverElemento(ref) {
    if (!ref) return null;
    if (typeof ref === "string") return document.querySelector(ref);
    return ref.nodeType === 1 ? ref : null;
  }

  function criar(tag, classe, texto) {
    var el = document.createElement(tag);
    if (classe) el.className = classe;
    if (texto != null) el.textContent = texto;
    return el;
  }

  // -------------------------------------------------------------------------
  // Instância
  // -------------------------------------------------------------------------
  function Indicador(cfg) {
    this.cfg = cfg;
    this.id = "ind-senha-" + (++contadorId);
    this.ouvintes = [];
    this.timerAnuncio = null;
    this.ultimoResumo = "";
    this.estadoAtual = { valido: false, requisitos: {}, recomendacoes: {} };
    this.descricoesOriginais = [];
    this.montar();
    this.vincular();
    this.atualizar();
  }

  Indicador.prototype.criarItem = function (lista, chave, recomendacao) {
    var li = criar("li", "ind-senha__item");
    li.setAttribute("data-estado", "pendente");
    var icone = criar("span", "ind-senha__icone", ICONES.pendente);
    icone.setAttribute("aria-hidden", "true");
    var texto = criar("span", "ind-senha__texto");
    var sr = criar("span", "ind-senha__sr");
    var msg = criar("span", "ind-senha__msg");
    texto.appendChild(sr);
    texto.appendChild(msg);
    li.appendChild(icone);
    li.appendChild(texto);
    lista.appendChild(li);
    this.itens[chave] = { li: li, icone: icone, sr: sr, msg: msg, recomendacao: !!recomendacao };
    return li;
  };

  Indicador.prototype.montar = function () {
    var cfg = this.cfg;
    this.itens = {};

    var raiz = criar("div", "ind-senha");
    raiz.id = this.id;
    raiz.setAttribute("data-vazio", "1");
    this.raiz = raiz;

    this.dica = criar("p", "ind-senha__dica");
    this.dica.hidden = true;
    raiz.appendChild(this.dica);

    var blocoReq = criar("div", "ind-senha__bloco");
    blocoReq.appendChild(criar("p", "ind-senha__titulo", TEXTOS.tituloRequisitos));
    var listaReq = criar("ul", "ind-senha__lista");
    listaReq.setAttribute("aria-label", TEXTOS.tituloRequisitos);
    this.criarItem(listaReq, "min");
    this.criarItem(listaReq, "especial");
    this.criarItem(listaReq, "max");
    this.criarItem(listaReq, "chars");
    if (cfg.campoAtual) this.criarItem(listaReq, "diferente");
    this.criarItem(listaReq, "previsivel");
    blocoReq.appendChild(listaReq);
    raiz.appendChild(blocoReq);

    if (cfg.campoConfirmacao) {
      var listaConf = criar("ul", "ind-senha__lista ind-senha__lista--conf");
      this.criarItem(listaConf, "confirmacao");
      this.idConfirmacao = this.id + "-conf";
      listaConf.id = this.idConfirmacao;
      raiz.appendChild(listaConf);
    }

    var blocoRec = criar("div", "ind-senha__bloco ind-senha__bloco--rec");
    blocoRec.appendChild(criar("p", "ind-senha__titulo", TEXTOS.tituloRecomendacoes));
    var listaRec = criar("ul", "ind-senha__lista");
    listaRec.setAttribute("aria-label", TEXTOS.tituloRecomendacoes);
    this.criarItem(listaRec, "rec12", true);
    this.criarItem(listaRec, "recMix", true);
    blocoRec.appendChild(listaRec);
    raiz.appendChild(blocoRec);

    // Região viva única: só recebe resumos curtos, com atraso, para evitar excesso de anúncios.
    this.status = criar("p", "ind-senha__status");
    this.status.setAttribute("role", "status");
    this.status.setAttribute("aria-live", "polite");
    this.status.setAttribute("aria-atomic", "true");
    raiz.appendChild(this.status);

    var destino = resolverElemento(cfg.container);
    if (destino) {
      destino.appendChild(raiz);
    } else {
      var ancora = cfg.campoNova.closest(".modal-campo, .campo, .form-group") || cfg.campoNova;
      ancora.insertAdjacentElement("afterend", raiz);
    }
  };

  Indicador.prototype.vincular = function () {
    var self = this;
    var cfg = this.cfg;

    function descrever(campo, idExtra) {
      if (!campo) return;
      var original = campo.getAttribute("aria-describedby");
      self.descricoesOriginais.push({ campo: campo, valor: original });
      campo.setAttribute("aria-describedby", ((original ? original + " " : "") + idExtra).trim());
    }
    descrever(cfg.campoNova, this.id);
    if (cfg.campoConfirmacao) descrever(cfg.campoConfirmacao, this.idConfirmacao);

    function escutar(alvo, evento, fn) {
      alvo.addEventListener(evento, fn);
      self.ouvintes.push([alvo, evento, fn]);
    }
    var aoDigitar = function () { self.atualizar(); };
    [cfg.campoNova, cfg.campoConfirmacao, cfg.campoAtual].forEach(function (campo) {
      if (!campo) return;
      escutar(campo, "input", aoDigitar);
      escutar(campo, "change", aoDigitar);
    });

    var form = cfg.campoNova.form;
    if (form) {
      escutar(form, "reset", function () { window.setTimeout(function () { self.limpar(); }, 0); });
    }
  };

  Indicador.prototype.definir = function (chave, estado, mensagem) {
    var item = this.itens[chave];
    if (!item) return;
    item.li.setAttribute("data-estado", estado);
    item.icone.textContent = ICONES[estado] || ICONES.pendente;
    item.msg.textContent = mensagem;
    var prefixo;
    if (item.recomendacao) prefixo = estado === "ok" ? TEXTOS.srRecOk : TEXTOS.srRec;
    else prefixo = estado === "ok" ? TEXTOS.srOk : (estado === "invalido" ? TEXTOS.srInvalido : TEXTOS.srPendente);
    item.sr.textContent = prefixo;
  };

  Indicador.prototype.atualizar = function () {
    var cfg = this.cfg;
    var nova = obterValor(cfg.campoNova);
    var conf = obterValor(cfg.campoConfirmacao);
    var atual = obterValor(cfg.campoAtual);
    var vazio = nova === "";
    var vazioTotal = vazio && conf === "";
    var req = {};
    var rec = {};

    this.raiz.setAttribute("data-vazio", vazioTotal ? "1" : "0");
    if (cfg.opcional) {
      this.dica.textContent = TEXTOS.opcionalVazio;
      this.dica.hidden = !vazioTotal;
    }

    var tamanho = contarCaracteres(nova);
    var bytes = contarBytes(nova);
    var invalidoChars = !vazio && temCaracteresInvalidos(nova);

    req.min = (!vazio && tamanho >= MIN_CARACTERES) ? "ok" : "pendente";
    this.definir("min", req.min, TEXTOS.min);

    req.especial = (!vazio && temCaractereEspecial(nova)) ? "ok" : "pendente";
    this.definir("especial", req.especial, TEXTOS.especial);

    if (bytes > MAX_BYTES) { req.max = "invalido"; this.definir("max", "invalido", TEXTOS.maxExcedido); }
    else { req.max = vazio ? "pendente" : "ok"; this.definir("max", req.max, TEXTOS.max); }

    if (invalidoChars) { req.chars = "invalido"; this.definir("chars", "invalido", TEXTOS.charsInvalidos); }
    else { req.chars = vazio ? "pendente" : "ok"; this.definir("chars", req.chars, TEXTOS.chars); }

    if (cfg.campoAtual) {
      if (vazio || atual === "") req.diferente = "pendente";
      else req.diferente = (nova === atual) ? "invalido" : "ok";
      this.definir("diferente", req.diferente, TEXTOS.diferente);
    }

    if (vazio || tamanho < MIN_CARACTERES || invalidoChars) {
      req.previsivel = "pendente";
      this.definir("previsivel", "pendente", TEXTOS.previsivel);
    } else {
      var dados = typeof cfg.dadosPessoais === "function" ? safeCall(cfg.dadosPessoais) : cfg.dadosPessoais;
      if (correspondeDadosPessoais(nova, dados)) {
        req.previsivel = "invalido";
        this.definir("previsivel", "invalido", TEXTOS.dadosPessoais);
      } else if (ehPrevisivel(nova)) {
        req.previsivel = "invalido";
        this.definir("previsivel", "invalido", TEXTOS.previsivelInvalido);
      } else {
        req.previsivel = "ok";
        this.definir("previsivel", "ok", TEXTOS.previsivel);
      }
    }

    if (cfg.campoConfirmacao) {
      if (conf === "") { req.confirmacao = "pendente"; this.definir("confirmacao", "pendente", TEXTOS.confPendente); }
      else if (conf === nova) { req.confirmacao = "ok"; this.definir("confirmacao", "ok", TEXTOS.confOk); }
      else if (conf.length < nova.length && nova.indexOf(conf) === 0) {
        req.confirmacao = "pendente"; this.definir("confirmacao", "pendente", TEXTOS.confContinue);
      } else { req.confirmacao = "invalido"; this.definir("confirmacao", "invalido", TEXTOS.confErro); }
    }

    var ok12 = tamanho >= RECOMENDADO_CARACTERES;
    rec.rec12 = ok12 ? "ok" : "sugestao";
    this.definir("rec12", rec.rec12, TEXTOS.rec12);
    var mistura = !vazio && temLetra(nova) && /[\p{N}\s]/u.test(nova);
    rec.recMix = mistura ? "ok" : "sugestao";
    this.definir("recMix", rec.recMix, TEXTOS.recMix);

    var obrigatoriosOk = ["min", "especial", "max", "chars", "diferente", "previsivel"].every(function (k) {
      return req[k] === undefined || req[k] === "ok";
    });
    var confOk = !cfg.campoConfirmacao || req.confirmacao === "ok";
    var valido = (cfg.opcional && vazioTotal) ? true : (obrigatoriosOk && confOk);

    this.estadoAtual = { valido: valido, requisitos: req, recomendacoes: rec };
    this.anunciar(req, obrigatoriosOk, confOk, vazioTotal);
  };

  Indicador.prototype.anunciar = function (req, obrigatoriosOk, confOk, vazioTotal) {
    var self = this;
    var resumo = "";
    if (!vazioTotal) {
      var chaves = Object.keys(req);
      for (var i = 0; i < chaves.length; i++) {
        if (req[chaves[i]] === "invalido") {
          resumo = this.itens[chaves[i]].msg.textContent;
          break;
        }
      }
      if (!resumo && obrigatoriosOk) {
        resumo = confOk ? TEXTOS.resumoOk : (this.cfg.campoConfirmacao ? TEXTOS.resumoOkSemConf : "");
      }
    }
    if (resumo === this.ultimoResumo) return;
    this.ultimoResumo = resumo;
    window.clearTimeout(this.timerAnuncio);
    this.timerAnuncio = window.setTimeout(function () {
      if (self.status) self.status.textContent = resumo;
    }, ATRASO_ANUNCIO_MS);
  };

  Indicador.prototype.limpar = function () {
    window.clearTimeout(this.timerAnuncio);
    this.ultimoResumo = "";
    if (this.status) this.status.textContent = "";
    this.atualizar();
    if (this.status) this.status.textContent = "";
    this.ultimoResumo = "";
  };

  Indicador.prototype.obterEstado = function () {
    var e = this.estadoAtual;
    return { valido: e.valido, requisitos: Object.assign({}, e.requisitos), recomendacoes: Object.assign({}, e.recomendacoes) };
  };

  Indicador.prototype.destruir = function () {
    window.clearTimeout(this.timerAnuncio);
    this.ouvintes.forEach(function (o) { o[0].removeEventListener(o[1], o[2]); });
    this.ouvintes = [];
    this.descricoesOriginais.forEach(function (d) {
      if (d.valor === null) d.campo.removeAttribute("aria-describedby");
      else d.campo.setAttribute("aria-describedby", d.valor);
    });
    this.descricoesOriginais = [];
    if (this.raiz && this.raiz.parentNode) this.raiz.parentNode.removeChild(this.raiz);
    if (instancias && this.cfg.campoNova) instancias.delete(this.cfg.campoNova);
    this.raiz = this.status = this.dica = this.itens = null;
    this.cfg = { campoNova: null };
  };

  function safeCall(fn) { try { return fn(); } catch (e) { return null; } }

  // -------------------------------------------------------------------------
  // API pública
  // -------------------------------------------------------------------------
  var IndicadorSenha = {
    anexar: function (opcoes) {
      var o = opcoes || {};
      var campoNova = resolverElemento(o.campoNova);
      if (!campoNova) return null;
      if (instancias && instancias.has(campoNova)) instancias.get(campoNova).destruir();

      var inst = new Indicador({
        campoNova: campoNova,
        campoConfirmacao: resolverElemento(o.campoConfirmacao),
        campoAtual: resolverElemento(o.campoAtual),
        dadosPessoais: o.dadosPessoais || null,
        opcional: o.opcional === true,
        container: o.container || null
      });
      if (instancias) instancias.set(campoNova, inst);

      return {
        atualizar: function () { if (inst.raiz) inst.atualizar(); },
        limpar: function () { if (inst.raiz) inst.limpar(); },
        destruir: function () { if (inst.raiz) inst.destruir(); },
        obterEstado: function () { return inst.raiz ? inst.obterEstado() : null; },
        valido: function () { return inst.raiz ? inst.estadoAtual.valido : false; }
      };
    }
  };

  global.IndicadorSenha = IndicadorSenha;
})(window);
