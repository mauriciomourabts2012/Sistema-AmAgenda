"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const INSTALADOR_PATH = path.resolve(__dirname, "../../public/js/PWA/InstalarPWA.js");
const MENU_PATH = path.resolve(__dirname, "../../public/js/menu-lateral/menu-lateral.js");
const AGENDA_PATH = path.resolve(__dirname, "../../public/views/agenda.html");
const PAINEL_PATH = path.resolve(__dirname, "../../public/views/painel-administrativo/painel-administrativo.html");
const SUPER_ADMIN_PATH = path.resolve(__dirname, "../../public/views/super-admin/painel-super-admin.html");
const LOGIN_CLIENTE_PATH = path.resolve(__dirname, "../../public/views/login-cliente.php");
const CLIENTE_AGENDAMENTO_PATH = path.resolve(__dirname, "../../public/views/cliente-agendamento.html");
const CLIENTE_PERFIL_PATH = path.resolve(__dirname, "../../public/views/cliente-perfil.html");
const APP_VERSION_PATH = path.resolve(__dirname, "../../backend/_config/app-version.json");
const INSTALADOR_CSS_PATH = path.resolve(__dirname, "../../public/css/PWA/instalar-pwa.css");

function alvoEventos() {
  const listeners = new Map();
  const alvo = {
    addEventListener(tipo, handler) {
      if (!listeners.has(tipo)) listeners.set(tipo, []);
      listeners.get(tipo).push(handler);
    },
    dispatchEvent(evento) {
      for (const handler of listeners.get(evento.type) || []) handler(evento);
      return true;
    },
    async emitir(tipo, evento = {}) {
      for (const handler of listeners.get(tipo) || []) await handler(evento);
    },
  };
  return alvo;
}

function elementoFalso() {
  const classes = new Set();
  const listeners = alvoEventos();
  const botoes = new Map();
  const elemento = {
    ...listeners,
    isConnected: true,
    children: [],
    dataset: {},
    hidden: false,
    parentNode: null,
    className: "",
    innerHTML: "",
    attrs: new Map(),
    classList: {
      add(...nomes) { nomes.forEach(nome => classes.add(nome)); },
      remove(...nomes) { nomes.forEach(nome => classes.delete(nome)); },
      contains(nome) { return classes.has(nome); },
    },
    setAttribute(nome, valor) { this.attrs.set(nome, String(valor)); },
    getAttribute(nome) { return this.attrs.get(nome); },
    contains(alvo) { return alvo === elemento || [...botoes.values()].includes(alvo); },
    focus() { elemento.focado = true; },
    appendChild(filho) {
      filho.parentNode = elemento;
      elemento.children.push(filho);
      return filho;
    },
    insertBefore(filho, referencia) {
      filho.parentNode = elemento;
      const indice = elemento.children.indexOf(referencia);
      elemento.children.splice(indice < 0 ? elemento.children.length : indice, 0, filho);
      return filho;
    },
    querySelector(seletor) {
      if (!botoes.has(seletor)) botoes.set(seletor, elementoFalso());
      return botoes.get(seletor);
    },
  };
  return elemento;
}

function executarInstalador({
  contexto = "agenda",
  perfil = "proprietario",
  tipoUsuario = "usuario",
  modoSuporte = false,
  standalone = false,
  navigatorStandalone = false,
  userAgent = "Mozilla/5.0 Chrome/140.0",
  auth = true,
  comCabecalho = true,
  comContainerPublico = false,
} = {}) {
  if (!fs.existsSync(INSTALADOR_PATH)) throw new Error("InstalarPWA.js ainda não existe");

  const windowEvents = alvoEventos();
  const documentEvents = alvoEventos();
  const mediaEvents = alvoEventos();
  const modal = elementoFalso();
  const cabecalhoDireita = elementoFalso();
  const indicadorModoSuporte = elementoFalso();
  const notificacoes = elementoFalso();
  const perfilCabecalho = elementoFalso();
  const containerPublico = elementoFalso();
  indicadorModoSuporte.className = "cabecalho-modo-suporte";
  notificacoes.className = "cabecalho-notificacoes";
  perfilCabecalho.className = "cabecalho-perfil";
  cabecalhoDireita.appendChild(indicadorModoSuporte);
  cabecalhoDireita.appendChild(notificacoes);
  cabecalhoDireita.appendChild(perfilCabecalho);
  let modalCriado = false;
  let recarregamentos = 0;

  const document = {
    ...documentEvents,
    readyState: "complete",
    activeElement: elementoFalso(),
    body: {
      dataset: { menuContexto: contexto },
      insertAdjacentHTML(_posicao, html) {
        assert.match(html, /modalInstalacaoIOS/);
        modalCriado = true;
      },
    },
    createElement() { return elementoFalso(); },
    querySelector(seletor) {
      if (seletor === ".cabecalho-direita .cabecalho-notificacoes") return comCabecalho ? notificacoes : null;
      if (seletor === "[data-pwa-instalar-container]") return comContainerPublico ? containerPublico : null;
      if (seletor === "[data-pwa-instalar]") {
        return cabecalhoDireita.children.find(item => item.dataset.pwaInstalar !== undefined)
          || containerPublico.children.find(item => item.dataset.pwaInstalar !== undefined)
          || null;
      }
      return null;
    },
    getElementById(id) {
      return id === "modalInstalacaoIOS" && modalCriado ? modal : null;
    },
  };
  const media = {
    ...mediaEvents,
    matches: standalone,
    addListener(handler) { mediaEvents.addEventListener("change", handler); },
  };
  const window = {
    ...windowEvents,
    matchMedia() { return media; },
    location: { reload() { recarregamentos += 1; } },
  };
  if (auth) {
    window.__AUTH__ = {
      tipo_usuario: tipoUsuario,
      perfil_nome: perfil,
      empresa_id: 7,
      modo_suporte: modoSuporte,
    };
  }
  const navigator = {
    userAgent,
    platform: /iPhone|iPad|iPod/.test(userAgent) ? "iPhone" : "Win32",
    maxTouchPoints: /iPad/.test(userAgent) ? 5 : 0,
    standalone: navigatorStandalone,
  };
  class CustomEvent {
    constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
  }
  const context = vm.createContext({
    window,
    document,
    navigator,
    CustomEvent,
    Promise,
    console,
    setTimeout,
    clearTimeout,
  });
  vm.runInContext(fs.readFileSync(INSTALADOR_PATH, "utf8"), context, { filename: INSTALADOR_PATH });

  return {
    api: window.AmAgendaInstalacaoPWA,
    modal,
    cabecalhoDireita,
    indicadorModoSuporte,
    notificacoes,
    containerPublico,
    botaoInstalar: () => cabecalhoDireita.children.find(item => item.dataset.pwaInstalar !== undefined)
      || containerPublico.children.find(item => item.dataset.pwaInstalar !== undefined)
      || null,
    window,
    recarregamentos: () => recarregamentos,
    modalCriado: () => modalCriado,
    emitirWindow: windowEvents.emitir,
    emitirDocument: documentEvents.emitir,
  };
}

function eventoBeforeInstallPrompt(resultado = "accepted") {
  let prevenido = 0;
  let prompts = 0;
  return {
    evento: {
      preventDefault() { prevenido += 1; },
      prompt() { prompts += 1; },
      userChoice: Promise.resolve({ outcome: resultado, platform: "web" }),
    },
    prevenido: () => prevenido,
    prompts: () => prompts,
  };
}

test("Chromium captura beforeinstallprompt, impede o prompt automático e disponibiliza a ação", async () => {
  const h = executarInstalador();
  const bip = eventoBeforeInstallPrompt();
  await h.emitirWindow("beforeinstallprompt", bip.evento);
  assert.equal(bip.prevenido(), 1);
  assert.equal(h.api.obterEstado().visivel, true);
  assert.equal(bip.prompts(), 0);
  assert.equal(h.botaoInstalar().hidden, false);
  assert.equal(h.cabecalhoDireita.children.indexOf(h.botaoInstalar()) + 1, h.cabecalhoDireita.children.indexOf(h.notificacoes));
  assert.equal(h.botaoInstalar().getAttribute("aria-label"), "Instalar App");
});

test("clique explícito chama prompt e trata aceite", async () => {
  const h = executarInstalador();
  const bip = eventoBeforeInstallPrompt("accepted");
  await h.emitirWindow("beforeinstallprompt", bip.evento);
  const resultado = await h.api.instalar();
  assert.equal(bip.prompts(), 1);
  assert.equal(resultado.outcome, "accepted");
  assert.equal(h.api.obterEstado().visivel, false);
});

test("cancelamento do prompt não é erro e descarta o evento já consumido", async () => {
  const h = executarInstalador();
  const bip = eventoBeforeInstallPrompt("dismissed");
  await h.emitirWindow("beforeinstallprompt", bip.evento);
  await assert.doesNotReject(() => h.api.instalar());
  assert.equal(bip.prompts(), 1);
  assert.equal(h.api.obterEstado().visivel, false);
});

test("sem beforeinstallprompt o Chromium não tenta instalar", async () => {
  const h = executarInstalador();
  const resultado = await h.api.instalar();
  assert.equal(resultado.resultado, "indisponivel");
  assert.equal(h.api.obterEstado().visivel, false);
});

test("display-mode standalone oculta a opção", async () => {
  const h = executarInstalador({ standalone: true });
  const bip = eventoBeforeInstallPrompt();
  await h.emitirWindow("beforeinstallprompt", bip.evento);
  assert.equal(h.api.obterEstado().visivel, false);
  assert.equal(h.botaoInstalar().hidden, true);
});

test("navigator.standalone oculta a opção", () => {
  const h = executarInstalador({ navigatorStandalone: true, userAgent: "Mozilla/5.0 (iPhone) Safari/605.1" });
  assert.equal(h.api.obterEstado().visivel, false);
});

test("appinstalled descarta evento, oculta opção e não recarrega", async () => {
  const h = executarInstalador();
  const bip = eventoBeforeInstallPrompt();
  await h.emitirWindow("beforeinstallprompt", bip.evento);
  await h.emitirWindow("appinstalled");
  assert.equal(h.api.obterEstado().visivel, false);
  assert.equal(h.botaoInstalar().hidden, true);
  assert.equal(h.recarregamentos(), 0);
  const resultado = await h.api.instalar();
  assert.equal(resultado.resultado, "indisponivel");
});

test("iPhone fora de standalone oferece orientação somente após clique", async () => {
  const h = executarInstalador({ userAgent: "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/605.1" });
  assert.equal(h.api.obterEstado().visivel, true);
  assert.equal(h.modalCriado(), false);
  await h.botaoInstalar().emitir("click");
  assert.equal(h.modalCriado(), true);
  assert.equal(h.modal.classList.contains("ativo"), true);
});

test("iPhone instalado não oferece orientação", () => {
  const h = executarInstalador({
    userAgent: "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/605.1",
    navigatorStandalone: true,
  });
  assert.equal(h.api.obterEstado().visivel, false);
});

test("CSS compartilhado oculta e exibe corretamente a orientação iOS", () => {
  const css = fs.readFileSync(INSTALADOR_CSS_PATH, "utf8");
  assert.match(css, /#modalInstalacaoIOS\s*\{[^}]*display:\s*none/s);
  assert.match(css, /#modalInstalacaoIOS\.ativo\s*\{[^}]*display:\s*flex/s);
  assert.match(css, /#modalInstalacaoIOS\s+\.modal-conteudo\s*\{/);
});

test("Agenda Online pública recebe uma única opção no container público", async () => {
  const h = executarInstalador({
    contexto: "agenda-online",
    auth: false,
    comCabecalho: false,
    comContainerPublico: true,
  });
  await h.emitirWindow("beforeinstallprompt", eventoBeforeInstallPrompt().evento);
  await h.emitirWindow("beforeinstallprompt", eventoBeforeInstallPrompt().evento);
  assert.equal(h.api.obterEstado().visivel, true);
  assert.equal(h.containerPublico.children.length, 1);
  assert.equal(h.botaoInstalar().hidden, false);
});

test("cliente autenticado recebe opção de instalação no cabeçalho", async () => {
  const h = executarInstalador({ contexto: "cliente", tipoUsuario: "cliente", perfil: "cliente" });
  await h.emitirWindow("beforeinstallprompt", eventoBeforeInstallPrompt().evento);
  assert.equal(h.api.obterEstado().visivel, true);
  assert.equal(h.botaoInstalar().hidden, false);
});

for (const [nome, opcoes] of [
  ["proprietario", { perfil: "proprietario" }],
  ["recepcionista", { perfil: "recepcionista" }],
  ["profissional", { perfil: "profissional" }],
  ["Super Admin", { contexto: "super-admin", tipoUsuario: "super_admin", perfil: "super_admin" }],
  ["Super Admin em Modo Suporte", { tipoUsuario: "super_admin", perfil: "super_admin", modoSuporte: true }],
]) {
  test(`${nome} pode receber a opção de instalação no cabeçalho`, async () => {
    const h = executarInstalador(opcoes);
    const bip = eventoBeforeInstallPrompt();
    await h.emitirWindow("beforeinstallprompt", bip.evento);
    assert.equal(h.api.obterEstado().visivel, true);
    assert.equal(h.botaoInstalar().hidden, false);
    if (nome === "Super Admin em Modo Suporte") {
      assert.ok(h.cabecalhoDireita.children.indexOf(h.indicadorModoSuporte) < h.cabecalhoDireita.children.indexOf(h.botaoInstalar()));
      assert.equal(h.cabecalhoDireita.children.indexOf(h.botaoInstalar()) + 1, h.cabecalhoDireita.children.indexOf(h.notificacoes));
    }
  });
}

test("integração usa o mesmo instalador somente nas seis páginas elegíveis", () => {
  const menu = fs.readFileSync(MENU_PATH, "utf8");
  const agenda = fs.readFileSync(AGENDA_PATH, "utf8");
  const painel = fs.readFileSync(PAINEL_PATH, "utf8");
  const superAdmin = fs.readFileSync(SUPER_ADMIN_PATH, "utf8");
  const referenciaInstalador = /js\/PWA\/InstalarPWA\.js\?v=\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?/;
  const publico = path.resolve(__dirname, "../../public");
  const referencias = [];
  const visitar = diretorio => {
    for (const entrada of fs.readdirSync(diretorio, { withFileTypes: true })) {
      const alvo = path.join(diretorio, entrada.name);
      if (entrada.isDirectory()) visitar(alvo);
      else if (/\.(html|php)$/i.test(entrada.name)
        && fs.readFileSync(alvo, "utf8").includes("/PWA/InstalarPWA.js")) {
        referencias.push(path.relative(publico, alvo).split(path.sep).join("/"));
      }
    }
  };
  visitar(publico);
  assert.doesNotMatch(menu, /instalar-pwa/);
  assert.doesNotMatch(menu, /AmAgendaInstalacaoPWA/);
  assert.match(agenda, referenciaInstalador);
  assert.match(painel, referenciaInstalador);
  assert.match(superAdmin, referenciaInstalador);
  assert.match(agenda, /rel="manifest" href="\/manifest\.json"/);
  assert.match(painel, /rel="manifest" href="\/manifest\.json"/);
  assert.match(superAdmin, /rel="manifest" href="\/manifest\.json"/);
  assert.deepEqual(referencias.sort(), [
    "views/agenda.html",
    "views/cliente-agendamento.html",
    "views/cliente-perfil.html",
    "views/login-cliente.php",
    "views/painel-administrativo/painel-administrativo.html",
    "views/super-admin/painel-super-admin.html",
  ]);
});

test("Agenda Online e áreas autenticadas do cliente reutilizam o componente PWA", () => {
  const { version } = JSON.parse(fs.readFileSync(APP_VERSION_PATH, "utf8"));
  const loginCliente = fs.readFileSync(LOGIN_CLIENTE_PATH, "utf8");
  const clienteAgendamento = fs.readFileSync(CLIENTE_AGENDAMENTO_PATH, "utf8");
  const clientePerfil = fs.readFileSync(CLIENTE_PERFIL_PATH, "utf8");

  assert.match(loginCliente, /data-menu-contexto="agenda-online"/);
  assert.match(loginCliente, /data-pwa-instalar-container/);
  assert.doesNotMatch(loginCliente, /src="\/js\/InstalarPWA\.js"/);

  for (const pagina of [loginCliente, clienteAgendamento, clientePerfil]) {
    assert.match(pagina, /manifest-agenda-online\.php/);
    assert.ok(pagina.includes(`js/PWA/InstalarPWA.js?v=${version}`));
    assert.ok(pagina.includes(`js/PWA/RegistrarServiceWorker.js?v=${version}`));
    assert.ok(pagina.includes(`css/PWA/instalar-pwa.css?v=${version}`));
  }
});

// Regra: o botão representa instalação REAL. No Chrome/Edge ele só aparece com
// beforeinstallprompt capturado; sem o evento não há botão nem modal manual.
for (const [nome, opcoes] of [
  ["Proprietário", { perfil: "proprietario" }],
  ["Cliente autenticado", { contexto: "cliente", tipoUsuario: "cliente", perfil: "cliente" }],
  ["Agenda Online pública", { contexto: "agenda-online", auth: false, comCabecalho: false, comContainerPublico: true }],
]) {
  test(`${nome}: sem beforeinstallprompt não exibe botão nem abre modal`, async () => {
    const h = executarInstalador(opcoes);
    assert.equal(h.api.obterEstado().visivel, false);
    assert.equal(h.botaoInstalar()?.hidden ?? true, true);
    const resultado = await h.api.instalar();
    assert.equal(resultado.resultado, "indisponivel");
    assert.equal(h.modalCriado(), false);
  });

  test(`${nome}: com beforeinstallprompt exibe botão e o clique abre o prompt nativo`, async () => {
    const h = executarInstalador(opcoes);
    const bip = eventoBeforeInstallPrompt("accepted");
    await h.emitirWindow("beforeinstallprompt", bip.evento);
    assert.equal(h.botaoInstalar().hidden, false);
    await h.botaoInstalar().emitir("click");
    assert.equal(bip.prompts(), 1);
    assert.equal(h.modalCriado(), false);
  });
}

test("aceite seguido de appinstalled oculta o botão", async () => {
  const h = executarInstalador();
  const bip = eventoBeforeInstallPrompt("accepted");
  await h.emitirWindow("beforeinstallprompt", bip.evento);
  await h.api.instalar();
  await h.emitirWindow("appinstalled");
  assert.equal(h.botaoInstalar().hidden, true);
});

test("standalone não exibe botão nem com beforeinstallprompt", async () => {
  const h = executarInstalador({ standalone: true });
  await h.emitirWindow("beforeinstallprompt", eventoBeforeInstallPrompt().evento);
  assert.equal(h.botaoInstalar().hidden, true);
});

test("botão não duplica com eventos repetidos", async () => {
  const h = executarInstalador();
  await h.emitirWindow("beforeinstallprompt", eventoBeforeInstallPrompt().evento);
  await h.emitirWindow("beforeinstallprompt", eventoBeforeInstallPrompt().evento);
  await h.emitirDocument("amagenda:sessao-carregada");
  const botoes = h.cabecalhoDireita.children.filter(item => item.dataset.pwaInstalar !== undefined);
  assert.equal(botoes.length, 1);
});

test("modal de orientação manual para navegador desktop não existe no componente", () => {
  const codigo = fs.readFileSync(INSTALADOR_PATH, "utf8");
  assert.doesNotMatch(codigo, /pelo navegador/i);
  assert.doesNotMatch(codigo, /orientacao-navegador/);
});

test("manifest dinâmico da Agenda Online é buscado com cookies da sessão", () => {
  // manifest-agenda-online.php depende de $_SESSION; sem crossorigin="use-credentials"
  // o navegador busca o manifest sem cookies, recebe 404 e nunca dispara beforeinstallprompt.
  for (const pagina of [LOGIN_CLIENTE_PATH, CLIENTE_AGENDAMENTO_PATH, CLIENTE_PERFIL_PATH]) {
    const html = fs.readFileSync(pagina, "utf8");
    assert.match(html, /<link rel="manifest" href="\/public\/manifest-agenda-online\.php" crossorigin="use-credentials"/, pagina);
  }
});
