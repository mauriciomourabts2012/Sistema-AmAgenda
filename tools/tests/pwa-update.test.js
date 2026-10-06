"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const ATUALIZAR_PATH = path.resolve(__dirname, "../../public/js/PWA/AtualizarPWA.js");
const SW_PATH = path.resolve(__dirname, "../../public/sw.js");
const AGENDA_PATH = path.resolve(__dirname, "../../public/views/agenda.html");
const PAINEL_PATH = path.resolve(__dirname, "../../public/views/painel-administrativo/painel-administrativo.html");
const APP_VERSION_PATH = path.resolve(__dirname, "../../backend/_config/app-version.json");

function alvoEventos() {
  const listeners = new Map();
  return {
    addEventListener(tipo, handler) {
      if (!listeners.has(tipo)) listeners.set(tipo, []);
      listeners.get(tipo).push(handler);
    },
    async emitir(tipo, evento = {}) {
      for (const handler of listeners.get(tipo) || []) await handler(evento);
    },
  };
}

function elementoFalso(texto = "") {
  const eventos = alvoEventos();
  const classes = new Set();
  const elemento = {
    ...eventos,
    textContent: texto,
    className: "",
    dataset: {},
    children: [],
    disabled: false,
    isConnected: true,
    classList: {
      add(...nomes) { nomes.forEach(nome => classes.add(nome)); },
      remove(...nomes) { nomes.forEach(nome => classes.delete(nome)); },
      contains(nome) { return classes.has(nome); },
    },
    append(...filhos) { elemento.children.push(...filhos); },
    prepend(...filhos) { elemento.children.unshift(...filhos); },
    remove() { elemento.isConnected = false; },
    setAttribute(nome, valor) { elemento[nome] = String(valor); },
    querySelector(seletor) {
      if (seletor === "button") return elemento.children.find(item => item.tagName === "BUTTON") || null;
      if (seletor === ".ui-alert__actions") return elemento.actions || null;
      if (seletor === ".ui-alert__msg") return elemento.mensagem || null;
      if (seletor === ".ui-alert__title") return elemento.titulo || null;
      return null;
    },
  };
  return elemento;
}

function workerFalso(estado = "installed", versao = "1.0.1") {
  const eventos = alvoEventos();
  const mensagens = [];
  return {
    ...eventos,
    state: estado,
    scriptURL: `https://amagenda.local/sw.js?v=${versao}`,
    mensagens,
    postMessage(mensagem) { mensagens.push(mensagem); },
  };
}

function registroFalso({ active = workerFalso("activated", "1.0.0"), waiting = null, installing = null } = {}) {
  return { ...alvoEventos(), active, waiting, installing };
}

function executarAtualizador({
  controller = true,
  versaoPagina = "1.0.0",
  versaoController = versaoPagina,
  versaoServidor = versaoPagina,
  falhaFetch = false,
  falhaMensagem = false,
} = {}) {
  if (!fs.existsSync(ATUALIZAR_PATH)) throw new Error("AtualizarPWA.js ainda não existe");

  const windowEvents = alvoEventos();
  const swEvents = alvoEventos();
  const alertas = [];
  const erros = [];
  const registrosSolicitados = [];
  const intervalos = [];
  let recarregamentos = 0;
  let registroAtual = null;

  const document = {
    currentScript: { src: `https://amagenda.local/public/js/PWA/AtualizarPWA.js?v=${versaoPagina}` },
    visibilityState: "visible",
    formularioValor: "texto em andamento",
    createElement(tag) {
      const elemento = elementoFalso();
      elemento.tagName = String(tag).toUpperCase();
      return elemento;
    },
    addEventListener: alvoEventos().addEventListener,
  };
  const MensagemSistema = {
    aviso(mensagem, opcoes = {}) {
      const alerta = elementoFalso();
      const actions = elementoFalso();
      const depois = elementoFalso(opcoes.textoBotao || "OK");
      depois.tagName = "BUTTON";
      depois.addEventListener("click", () => {
        alerta.remove();
        opcoes.aoFechar?.();
      });
      actions.append(depois);
      alerta.actions = actions;
      alerta.mensagem = elementoFalso(mensagem);
      alerta.titulo = elementoFalso(opcoes.titulo || "Atenção");
      alerta.opcoes = opcoes;
      alertas.push(alerta);
      return alerta;
    },
    erro(mensagem) { erros.push(mensagem); },
  };
  const navigator = {
    serviceWorker: {
      ...swEvents,
      controller: controller
        ? { scriptURL: `https://amagenda.local/sw.js?v=${versaoController}` }
        : null,
      async getRegistration() { return registroAtual; },
    },
  };
  const window = {
    ...windowEvents,
    MensagemSistema,
    location: { reload() { recarregamentos += 1; } },
    AmAgendaRegistroPWA: {
      obterRegistro() { return registroAtual; },
      async registrarVersao(versao) {
        registrosSolicitados.push(versao);
        return registroAtual;
      },
    },
  };
  class CustomEvent {
    constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
  }
  const fetch = async () => {
    if (falhaFetch) throw new Error("rede indisponível");
    return { ok: true, async json() { return { ok: true, version: versaoServidor }; } };
  };
  const context = vm.createContext({
    window,
    document,
    navigator,
    CustomEvent,
    URL,
    Promise,
    Date,
    fetch,
    console,
    setTimeout(handler) { handler(); return 1; },
    clearTimeout() {},
    setInterval(handler, ms) { intervalos.push({ handler, ms }); return intervalos.length; },
    clearInterval() {},
  });
  vm.runInContext(fs.readFileSync(ATUALIZAR_PATH, "utf8"), context, { filename: ATUALIZAR_PATH });

  const prepararRegistro = (registro) => {
    registroAtual = registro;
    if (falhaMensagem && registro.waiting) {
      registro.waiting.postMessage = () => { throw new Error("worker indisponível"); };
    }
    return registro;
  };
  const flush = () => new Promise(resolve => setImmediate(resolve));
  const botao = (alerta, texto) => alerta?.actions?.children.find(item => item.textContent === texto);

  return {
    api: window.AmAgendaAtualizacaoPWA,
    alertas,
    erros,
    intervalos,
    registrosSolicitados,
    document,
    navigator,
    prepararRegistro,
    flush,
    botao,
    recarregamentos: () => recarregamentos,
    emitirControllerChange: () => swEvents.emitir("controllerchange"),
  };
}

test("sem nova versão não mostra aviso", async () => {
  const h = executarAtualizador();
  const registro = h.prepararRegistro(registroFalso());
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.equal(h.alertas.length, 0);
});

test("registration.waiting existente mostra aviso", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  const registro = h.prepararRegistro(registroFalso({ waiting: workerFalso() }));
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.equal(h.alertas.at(-1).titulo.textContent, "Nova versão disponível");
});

test("waiting superior não avisa quando a versão central não supera a página", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.8",
    versaoController: "1.0.8",
    versaoServidor: "1.0.8",
  });
  const registro = h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.8"),
    waiting: workerFalso("installed", "1.0.9"),
  }));
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.equal(h.alertas.length, 0);
  assert.deepEqual(h.registrosSolicitados, []);
});

test("waiting da mesma versão da página e do worker ativo não mostra aviso", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.3",
    versaoController: "1.0.3",
    versaoServidor: "1.0.3",
  });
  const registro = h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.3"),
    waiting: workerFalso("installed", "1.0.3"),
  }));
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.equal(h.alertas.length, 0);
});

test("waiting de versão superior à página e ao worker ativo mostra aviso", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.3",
    versaoController: "1.0.3",
    versaoServidor: "1.0.4",
  });
  const registro = h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.3"),
    waiting: workerFalso("installed", "1.0.4"),
  }));
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.equal(h.alertas.at(-1).titulo.textContent, "Nova versão disponível");
  await h.flush();
  assert.deepEqual(h.registrosSolicitados, []);
});

test("waiting residual de versão anterior não mostra aviso", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.3",
    versaoController: "1.0.3",
    versaoServidor: "1.0.3",
  });
  const registro = h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.3"),
    waiting: workerFalso("installed", "1.0.2"),
  }));
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.equal(h.alertas.length, 0);
});

test("após atualizar e recarregar a mesma versão waiting não volta a ser oferecida", async () => {
  const antes = executarAtualizador({
    versaoPagina: "1.0.2",
    versaoController: "1.0.2",
    versaoServidor: "1.0.3",
  });
  const waiting = workerFalso("installed", "1.0.3");
  await antes.api.anexarRegistro(antes.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.2"),
    waiting,
  })));
  await antes.flush();
  await antes.botao(antes.alertas.at(-1), "Atualizar agora").emitir("click");
  await antes.emitirControllerChange();
  assert.equal(antes.recarregamentos(), 1);

  const depois = executarAtualizador({
    versaoPagina: "1.0.3",
    versaoController: "1.0.3",
    versaoServidor: "1.0.3",
  });
  await depois.api.anexarRegistro(depois.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.3"),
    waiting: workerFalso("installed", "1.0.3"),
  })));
  await depois.flush();
  assert.equal(depois.alertas.length, 0);
});

test("worker instalado com controller existente é tratado como atualização", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  const instalando = workerFalso("installing");
  const registro = h.prepararRegistro(registroFalso({ installing: instalando }));
  await h.api.anexarRegistro(registro);
  registro.waiting = instalando;
  instalando.state = "installed";
  await instalando.emitir("statechange");
  await h.flush();
  assert.equal(h.alertas.length, 1);
});

test("installing correspondente aguarda instalação sem registrar novamente", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.8",
    versaoController: "1.0.8",
    versaoServidor: "1.0.9",
  });
  const instalando = workerFalso("installing", "1.0.9");
  const registro = h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.8"),
    installing: instalando,
  }));
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.deepEqual(h.registrosSolicitados, []);
  assert.equal(h.alertas.length, 0);

  registro.waiting = instalando;
  instalando.state = "installed";
  await instalando.emitir("statechange");
  await h.flush();
  assert.equal(h.alertas.at(-1).titulo.textContent, "Nova versão disponível");
});

test("primeira instalação sem controller não mostra atualização", async () => {
  const h = executarAtualizador({ controller: false });
  const instalando = workerFalso("installing");
  const registro = h.prepararRegistro(registroFalso({ installing: instalando }));
  await h.api.anexarRegistro(registro);
  registro.waiting = instalando;
  instalando.state = "installed";
  await instalando.emitir("statechange");
  assert.equal(h.alertas.length, 0);
});

test("Depois oculta aviso sem ativar worker", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  const waiting = workerFalso();
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({ waiting })));
  await h.flush();
  await h.botao(h.alertas.at(-1), "Depois").emitir("click");
  assert.deepEqual(waiting.mensagens, []);
  assert.equal(h.alertas.at(-1).isConnected, false);
});

test("Atualizar agora envia autorização ao worker waiting", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  const waiting = workerFalso();
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({ waiting })));
  await h.flush();
  await h.botao(h.alertas.at(-1), "Atualizar agora").emitir("click");
  assert.equal(waiting.mensagens.length, 1);
  assert.equal(waiting.mensagens[0]?.tipo, "ATIVAR_NOVA_VERSAO");
});

test("Service Worker só chama skipWaiting após mensagem explícita", async () => {
  const listeners = {};
  let skipWaiting = 0;
  const self = {
    location: { href: "https://amagenda.local/sw.js?v=1.0.1", origin: "https://amagenda.local" },
    clients: { async get() { return null; } },
    addEventListener(tipo, handler) { listeners[tipo] = handler; },
    async skipWaiting() { skipWaiting += 1; },
  };
  const caches = { async keys() { return []; }, async open() { return {}; }, async delete() { return true; } };
  vm.runInNewContext(fs.readFileSync(SW_PATH, "utf8"), { self, caches, URL, Promise, fetch: async () => ({}) });
  let espera = Promise.resolve();
  listeners.install({ waitUntil(promise) { espera = promise; } });
  await espera;
  assert.equal(skipWaiting, 0);
  listeners.message({ data: { tipo: "IGNORAR" }, waitUntil(promise) { espera = promise; } });
  await espera;
  assert.equal(skipWaiting, 0);
  listeners.message({ data: { tipo: "ATIVAR_NOVA_VERSAO" }, waitUntil(promise) { espera = promise; } });
  await espera;
  assert.equal(skipWaiting, 1);
});

test("controllerchange recarrega uma vez somente a aba solicitante", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  const waiting = workerFalso();
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({ waiting })));
  await h.flush();
  await h.botao(h.alertas.at(-1), "Atualizar agora").emitir("click");
  await h.emitirControllerChange();
  await h.emitirControllerChange();
  assert.equal(h.recarregamentos(), 1);
});

test("controllerchange sem solicitação não recarrega a outra aba", async () => {
  const h = executarAtualizador({ versaoController: "1.0.1", versaoServidor: "1.0.1" });
  await h.emitirControllerChange();
  await h.flush();
  assert.equal(h.recarregamentos(), 0);
  assert.equal(h.alertas.at(-1).titulo.textContent, "Nova versão aplicada");
});

test("controllerchange não avisa quando página e versão central são iguais", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.9",
    versaoController: "1.0.9",
    versaoServidor: "1.0.9",
  });
  await h.emitirControllerChange();
  await h.flush();
  assert.equal(h.alertas.length, 0);
  assert.equal(h.recarregamentos(), 0);
});

test("outra aba só recarrega após ação manual", async () => {
  const h = executarAtualizador({ versaoController: "1.0.1", versaoServidor: "1.0.1" });
  await h.emitirControllerChange();
  await h.flush();
  assert.equal(h.recarregamentos(), 0);
  await h.botao(h.alertas.at(-1), "Recarregar agora").emitir("click");
  assert.equal(h.recarregamentos(), 1);
});

test("detecção de atualização não interfere em formulário preenchido", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  const valor = h.document.formularioValor;
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({ waiting: workerFalso() })));
  await h.flush();
  assert.equal(h.document.formularioValor, valor);
  assert.equal(h.recarregamentos(), 0);
});

test("usuário pode ignorar aviso e continuar funcional", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({ waiting: workerFalso() })));
  await h.flush();
  await h.botao(h.alertas.at(-1), "Depois").emitir("click");
  assert.equal(h.document.formularioValor, "texto em andamento");
  assert.equal(h.recarregamentos(), 0);
});

test("erro ao ativar não quebra página nem recarrega", async () => {
  const h = executarAtualizador({ falhaMensagem: true, versaoServidor: "1.0.1" });
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({ waiting: workerFalso() })));
  await h.flush();
  await assert.doesNotReject(() => h.botao(h.alertas.at(-1), "Atualizar agora").emitir("click"));
  assert.equal(h.erros.length, 1);
  assert.equal(h.recarregamentos(), 0);
});

test("versão central nova registra URL de worker com a nova versão", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso()));
  await h.flush();
  assert.deepEqual(h.registrosSolicitados, ["1.0.1"]);
});

test("sem worker correspondente uma nova verificação tenta registrar novamente", async () => {
  const h = executarAtualizador({ versaoServidor: "1.0.1" });
  const registro = h.prepararRegistro(registroFalso());
  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.deepEqual(h.registrosSolicitados, ["1.0.1"]);

  await h.api.verificarVersao();
  await h.flush();
  assert.deepEqual(h.registrosSolicitados, ["1.0.1", "1.0.1"]);
});

test("consulta de versão usa intervalo não agressivo", async () => {
  const h = executarAtualizador();
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso()));
  assert.ok(h.intervalos[0].ms >= 30 * 60 * 1000);
});

test("Cliente e Agenda Online não carregam fluxo da Fase 4", () => {
  const publico = path.resolve(__dirname, "../../public");
  const encontrados = [];
  const visitar = diretorio => {
    for (const entrada of fs.readdirSync(diretorio, { withFileTypes: true })) {
      const alvo = path.join(diretorio, entrada.name);
      if (entrada.isDirectory()) visitar(alvo);
      else if (/\.(html|php)$/i.test(entrada.name)
        && fs.readFileSync(alvo, "utf8").includes("PWA/AtualizarPWA.js")) {
        encontrados.push(path.relative(publico, alvo).split(path.sep).join("/"));
      }
    }
  };
  visitar(publico);
  assert.deepEqual(encontrados.sort(), [
    "views/agenda.html",
    "views/painel-administrativo/painel-administrativo.html",
  ]);
});

test("páginas internas carregam AtualizarPWA com versão central", () => {
  const { version } = JSON.parse(fs.readFileSync(APP_VERSION_PATH, "utf8"));
  const referencia = `PWA/AtualizarPWA.js?v=${version}`;
  assert.ok(fs.readFileSync(AGENDA_PATH, "utf8").includes(referencia));
  assert.ok(fs.readFileSync(PAINEL_PATH, "utf8").includes(referencia));
});

test("Cenário 2: página, worker ativo e versão central iguais não mostram aviso nem registram", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.3",
    versaoController: "1.0.3",
    versaoServidor: "1.0.3",
  });
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.3"),
  })));
  await h.flush();
  assert.equal(h.alertas.length, 0);
  assert.deepEqual(h.registrosSolicitados, []);
});

test("Cenário 1 pós-reload: versão aplicada não é registrada nem oferecida de novo", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.3",
    versaoController: "1.0.3",
    versaoServidor: "1.0.3",
  });
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.3"),
  })));
  await h.flush();
  assert.equal(h.alertas.length, 0);
  assert.deepEqual(h.registrosSolicitados, []);
  assert.equal(h.recarregamentos(), 0);
});

test("página antiga com worker já ativo informa versão aplicada sem registrar ou recarregar", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.8",
    versaoController: "1.0.8",
    versaoServidor: "1.0.9",
  });
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.9"),
  })));
  await h.flush();
  assert.deepEqual(h.registrosSolicitados, []);
  assert.equal(h.alertas.at(-1).titulo.textContent, "Nova versão aplicada");
  assert.ok(h.botao(h.alertas.at(-1), "Recarregar agora"));
  assert.equal(h.recarregamentos(), 0);
});

test("registro percorre updatefound, installing e waiting antes de oferecer atualização", async () => {
  const h = executarAtualizador({
    versaoPagina: "1.0.8",
    versaoController: "1.0.8",
    versaoServidor: "1.0.9",
  });
  const registro = h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.8"),
  }));

  await h.api.anexarRegistro(registro);
  await h.flush();
  assert.deepEqual(h.registrosSolicitados, ["1.0.9"]);
  assert.equal(h.alertas.length, 0);

  const instalando = workerFalso("installing", "1.0.9");
  registro.installing = instalando;
  await registro.emitir("updatefound");
  registro.waiting = instalando;
  instalando.state = "installed";
  await instalando.emitir("statechange");
  await h.flush();

  assert.equal(h.alertas.at(-1).titulo.textContent, "Nova versão disponível");
  assert.ok(h.botao(h.alertas.at(-1), "Depois"));
  assert.ok(h.botao(h.alertas.at(-1), "Atualizar agora"));
  assert.equal(h.recarregamentos(), 0);
});

test("aviso de atualização usa variante visual própria e não rouba o foco", async () => {
  const h = executarAtualizador({ versaoPagina: "1.0.3", versaoServidor: "1.0.4" });
  await h.api.anexarRegistro(h.prepararRegistro(registroFalso({
    active: workerFalso("activated", "1.0.3"),
    waiting: workerFalso("installed", "1.0.4"),
  })));
  await h.flush();
  const alerta = h.alertas.at(-1);
  assert.ok(alerta.classList.contains("ui-alert--pwa-atualizacao"));
  assert.equal(alerta.role, "status");
  assert.equal(alerta.opcoes.focar, false);
  assert.ok(h.botao(alerta, "Depois"));
  assert.ok(h.botao(alerta, "Atualizar agora"));
});

// ===========================================================================
// Inicialização automática real: carrega RegistrarServiceWorker.js +
// AtualizarPWA.js como a página faz, simula o ciclo de vida do Service Worker
// e dispara apenas eventos de página (load / retorno à aba). Nenhum teste
// abaixo chama verificarVersao() manualmente.
// ===========================================================================
const REGISTRAR_REAL_PATH = path.resolve(__dirname, "../../public/js/PWA/RegistrarServiceWorker.js");

function simularPaginaReal({
  versaoPagina,
  versaoCentral,
  versaoAtiva = versaoPagina,
  controlada = true,
  auth = { tipo_usuario: "usuario", perfil_nome: "proprietario", modo_suporte: false },
  updatefoundAntesDoRegister = false,
}) {
  const origem = "https://amagenda.local";
  const estado = { central: versaoCentral, agora: 1_000_000, recarregamentos: 0 };
  const alertas = [];
  const registros = [];
  const intervalos = [];
  const windowEvents = alvoEventos();
  const documentEvents = alvoEventos();
  const swEvents = alvoEventos();

  function novoWorker(versao, state) {
    const worker = {
      ...alvoEventos(),
      state,
      scriptURL: `${origem}/sw.js?v=${versao}`,
      mensagens: [],
      postMessage(mensagem) {
        worker.mensagens.push(mensagem);
        if (mensagem?.tipo === "ATIVAR_NOVA_VERSAO") Promise.resolve().then(() => ativar(worker));
      },
    };
    return worker;
  }

  const registro = {
    ...alvoEventos(),
    scope: `${origem}/`,
    active: versaoAtiva ? novoWorker(versaoAtiva, "activated") : null,
    waiting: null,
    installing: null,
  };
  const container = {
    ...swEvents,
    controller: controlada ? registro.active : null,
    async getRegistration() {
      return registro.active || registro.waiting || registro.installing ? registro : undefined;
    },
    async register(url) {
      registros.push(url);
      const atual = registro.installing || registro.waiting || registro.active;
      if (atual && atual.scriptURL === origem + url) return registro;
      registro.installing = novoWorker(new URL(origem + url).searchParams.get("v"), "installing");
      if (updatefoundAntesDoRegister) await registro.emitir("updatefound");
      else Promise.resolve().then(() => registro.emitir("updatefound"));
      return registro;
    },
  };

  async function concluirInstalacao() {
    const worker = registro.installing;
    if (!worker) return;
    registro.installing = null;
    if (registro.active) {
      registro.waiting = worker;
      worker.state = "installed";
    } else {
      registro.active = worker;
      worker.state = "activated";
    }
    await worker.emitir("statechange");
  }

  async function ativar(worker) {
    registro.waiting = null;
    registro.active = worker;
    worker.state = "activated";
    container.controller = worker;
    await worker.emitir("statechange");
    await swEvents.emitir("controllerchange");
  }

  const document = {
    ...documentEvents,
    currentScript: null,
    visibilityState: "visible",
    createElement(tag) {
      const elemento = elementoFalso();
      elemento.tagName = String(tag).toUpperCase();
      return elemento;
    },
  };
  const window = {
    ...windowEvents,
    __AUTH__: auth,
    location: { reload() { estado.recarregamentos += 1; } },
    dispatchEvent(evento) { windowEvents.emitir(evento.type, evento); return true; },
    MensagemSistema: {
      aviso(mensagem, opcoes = {}) {
        const alerta = elementoFalso();
        const actions = elementoFalso();
        const depois = elementoFalso(opcoes.textoBotao || "OK");
        depois.tagName = "BUTTON";
        depois.addEventListener("click", () => {
          alerta.remove();
          opcoes.aoFechar?.();
        });
        actions.append(depois);
        alerta.actions = actions;
        alerta.titulo = elementoFalso(opcoes.titulo || "Atenção");
        alerta.opcoes = opcoes;
        alertas.push(alerta);
        return alerta;
      },
      erro() {},
    },
  };
  class CustomEvent {
    constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
  }
  const context = vm.createContext({
    window,
    document,
    navigator: { serviceWorker: container },
    CustomEvent,
    URL,
    Promise,
    WeakSet,
    Date: { now: () => estado.agora },
    fetch: async () => ({ ok: true, async json() { return { ok: true, version: estado.central }; } }),
    console,
    setTimeout(handler) { handler(); return 1; },
    clearTimeout() {},
    setInterval(handler, ms) { intervalos.push({ handler, ms }); return intervalos.length; },
    clearInterval() {},
  });
  for (const caminho of [REGISTRAR_REAL_PATH, ATUALIZAR_PATH]) {
    document.currentScript = {
      src: `${origem}/public/js/PWA/${path.basename(caminho)}?v=${versaoPagina}`,
      dataset: {},
    };
    vm.runInContext(fs.readFileSync(caminho, "utf8"), context, { filename: caminho });
  }
  document.currentScript = null;

  const flush = async () => {
    for (let i = 0; i < 20; i += 1) await new Promise(resolve => setImmediate(resolve));
  };
  return {
    alertas,
    registros,
    intervalos,
    registro,
    flush,
    concluirInstalacao,
    publicarVersao(versao) { estado.central = versao; },
    async carregarPagina() { await windowEvents.emitir("load"); await flush(); },
    async voltarParaAba(segundos = 20) {
      estado.agora += segundos * 1000;
      await documentEvents.emitir("visibilitychange");
      await flush();
    },
    async focarJanela(segundos = 20) {
      estado.agora += segundos * 1000;
      await windowEvents.emitir("focus");
      await flush();
    },
    titulos: () => alertas.filter(a => a.isConnected).map(a => a.titulo.textContent),
    botao: (alerta, texto) => alerta?.actions?.children.find(item => item.textContent === texto),
    recarregamentos: () => estado.recarregamentos,
  };
}

test("inicialização automática: aba aberta em 1.0.10 recebe aviso quando a release 1.0.11 é publicada", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.10" });
  await p.carregarPagina();
  assert.deepEqual(p.titulos(), [], "sem release nova não há aviso");

  p.publicarVersao("1.0.11"); // release-version.php patch
  await p.voltarParaAba();
  assert.ok(p.registros.includes("/sw.js?v=1.0.11"), `registros: ${p.registros}`);
  await p.concluirInstalacao(); // installing -> waiting
  await p.flush();
  assert.deepEqual(p.titulos(), ["Nova versão disponível"]);
});

test("inicialização automática: página 1.0.10 com central 1.0.11 avisa já no carregamento (caso C)", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.11" });
  await p.carregarPagina();
  assert.ok(p.registros.includes("/sw.js?v=1.0.11"));
  await p.concluirInstalacao();
  await p.flush();
  assert.deepEqual(p.titulos(), ["Nova versão disponível"]);
});

test("inicialização automática: updatefound antes do register resolver também chega ao aviso", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.11", updatefoundAntesDoRegister: true });
  await p.carregarPagina();
  await p.concluirInstalacao();
  await p.flush();
  assert.deepEqual(p.titulos(), ["Nova versão disponível"]);
});

test("inicialização automática: página aberta com Ctrl+Shift+R (sem controller) também avisa", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.11", controlada: false });
  await p.carregarPagina();
  await p.concluirInstalacao();
  await p.flush();
  assert.deepEqual(p.titulos(), ["Nova versão disponível"]);
});

test("inicialização automática: worker 1.0.11 já ativo mostra 'Nova versão aplicada' sem registrar de novo (caso B)", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.11", versaoAtiva: "1.0.11" });
  await p.carregarPagina();
  assert.deepEqual(p.titulos(), ["Nova versão aplicada"]);
  assert.deepEqual(p.registros, [], "não deve registrar nem rebaixar o worker");
  assert.equal(p.recarregamentos(), 0);
});

test("inicialização automática: página e central em 1.0.11 não mostram nada (caso D)", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.11", versaoCentral: "1.0.11" });
  await p.carregarPagina();
  await p.voltarParaAba();
  await p.focarJanela();
  assert.deepEqual(p.titulos(), []);
  assert.deepEqual(p.registros, ["/sw.js?v=1.0.11"]);
});

test("inicialização automática: Atualizar agora ativa 1.0.11 e recarrega uma vez", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.11" });
  await p.carregarPagina();
  await p.concluirInstalacao();
  await p.flush();
  const alerta = p.alertas.at(-1);
  await p.botao(alerta, "Atualizar agora").emitir("click");
  await p.flush();
  assert.equal(p.registro.active.scriptURL.endsWith("v=1.0.11"), true);
  assert.equal(p.recarregamentos(), 1);
});

test("inicialização automática: Depois não reaparece ao voltar à aba, mas o polling lembra depois", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.11" });
  await p.carregarPagina();
  await p.concluirInstalacao();
  await p.flush();
  await p.botao(p.alertas.at(-1), "Depois").emitir("click");
  assert.deepEqual(p.titulos(), []);
  await p.voltarParaAba();
  assert.deepEqual(p.titulos(), [], "retorno à aba não insiste após Depois");
  p.intervalos[0].handler();
  await p.flush();
  assert.deepEqual(p.titulos(), ["Nova versão disponível"]);
  assert.ok(p.intervalos[0].ms >= 30 * 60 * 1000);
});

test("inicialização automática: retornos seguidos à aba não geram consultas em excesso", async () => {
  const p = simularPaginaReal({ versaoPagina: "1.0.10", versaoCentral: "1.0.10" });
  await p.carregarPagina();
  p.publicarVersao("1.0.11");
  await p.voltarParaAba(1); // menos de 15 s após o carregamento: ignorado
  assert.equal(p.registros.includes("/sw.js?v=1.0.11"), false);
  await p.voltarParaAba(20);
  assert.ok(p.registros.includes("/sw.js?v=1.0.11"));
});

test("inicialização automática: Super Admin não registra Service Worker nem ao voltar à aba", async () => {
  const p = simularPaginaReal({
    versaoPagina: "1.0.10",
    versaoCentral: "1.0.11",
    versaoAtiva: null,
    controlada: false,
    auth: { tipo_usuario: "super_admin", perfil_nome: "super_admin", modo_suporte: true },
  });
  await p.carregarPagina();
  await p.voltarParaAba();
  await p.focarJanela();
  assert.deepEqual(p.registros, []);
  assert.deepEqual(p.titulos(), []);
});
