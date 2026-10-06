"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const REGISTRAR_PATH = path.resolve(__dirname, "../../public/js/PWA/RegistrarServiceWorker.js");
const MANIFEST_PATH = path.resolve(__dirname, "../../public/manifest.json");

const SCRIPT_V = "https://amagenda.test/js/PWA/RegistrarServiceWorker.js?v=1.2.3";

function executarRegistrador({
  scriptUrl,
  serviceWorker = true,
  reject = false,
  contexto,
  authInicial,
  registroExistente,
}) {
  if (!fs.existsSync(REGISTRAR_PATH)) {
    throw new Error("registrador ainda não existe");
  }

  const chamadas = [];
  let loadHandler = null;
  const documentListeners = new Map();
  const navigator = serviceWorker
    ? {
        serviceWorker: {
          async register(url, options) {
            chamadas.push({ url, options });
            if (reject) throw new Error("registro recusado");
            return { scope: options.scope };
          },
          ...(registroExistente !== undefined
            ? { async getRegistration() { return registroExistente; } }
            : {}),
        },
      }
    : {};
  const window = {
    location: { origin: "https://amagenda.test" },
    addEventListener(type, handler) {
      if (type === "load") loadHandler = handler;
    },
  };
  if (authInicial !== undefined) window.__AUTH__ = authInicial;
  const dataset = contexto ? { pwaContexto: contexto } : {};
  const document = {
    currentScript: { src: scriptUrl, dataset },
    addEventListener(type, handler) {
      if (!documentListeners.has(type)) documentListeners.set(type, []);
      documentListeners.get(type).push(handler);
    },
  };
  const context = vm.createContext({ URL, Promise, navigator, window, document });
  vm.runInContext(fs.readFileSync(REGISTRAR_PATH, "utf8"), context, {
    filename: REGISTRAR_PATH,
  });

  const esperar = () => new Promise((resolve) => setImmediate(resolve));

  return {
    api: window.AmAgendaRegistroPWA,
    chamadas,
    async emitirLoad() {
      if (loadHandler) await Promise.race([loadHandler(), esperar()]);
      await esperar();
    },
    async emitirSessao(auth) {
      // Reproduz exatamente o que _auth/sessao.js faz em aplicarDadosSessao().
      window.__AUTH__ = auth;
      for (const handler of documentListeners.get("amagenda:sessao-carregada") || []) {
        handler({ detail: auth });
      }
      await esperar();
      await esperar();
    },
  };
}

const AUTH_INTERNO = {
  tipo_usuario: "usuario",
  status: "ativo",
  empresa_id: 7,
  modo_suporte: false,
};

test("navegador sem Service Worker não agenda registro", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    contexto: "login",
    serviceWorker: false,
  });
  await h.emitirLoad();
  assert.deepEqual(h.chamadas, []);
});

test("propaga versão semver e usa opções seguras de atualização", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    contexto: "login",
  });
  await h.emitirLoad();
  assert.equal(h.chamadas.length, 1);
  assert.equal(h.chamadas[0].url, "/sw.js?v=1.2.3");
  assert.deepEqual(
    JSON.parse(JSON.stringify(h.chamadas[0].options)),
    { scope: "/", updateViaCache: "none" }
  );
});

test("nova versão central registra o worker versionado no mesmo escopo", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    contexto: "login",
  });
  await h.api.registrarVersao("1.0.1");
  assert.equal(h.chamadas.length, 1);
  assert.equal(h.chamadas[0].url, "/sw.js?v=1.0.1");
  assert.deepEqual(
    JSON.parse(JSON.stringify(h.chamadas[0].options)),
    { scope: "/", updateViaCache: "none" }
  );
});

test("não propaga versão inválida ao URL do worker", async () => {
  const h = executarRegistrador({
    scriptUrl: "https://amagenda.test/js/PWA/RegistrarServiceWorker.js?v=../../privado",
    contexto: "login",
  });
  await h.emitirLoad();
  assert.equal(h.chamadas[0].url, "/sw.js");
});

test("falha de registro é absorvida sem navegação ou exceção", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    contexto: "login",
    reject: true,
  });
  await assert.doesNotReject(() => h.emitirLoad());
  assert.equal(h.chamadas.length, 1);
});

test("manifest preserva identidade e inicia pelo entrypoint PWA", () => {
  const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, "utf8"));
  assert.equal(manifest.id, "./");
  assert.equal(manifest.scope, "./");
  assert.equal(manifest.start_url, "pwa.php?source=pwa");
});

test("Super Admin em Modo Suporte não registra o Service Worker", async () => {
  const h = executarRegistrador({ scriptUrl: SCRIPT_V });
  await h.emitirLoad();
  await h.emitirSessao({
    tipo_usuario: "super_admin",
    modo_suporte: true,
    empresa_id: 7,
    perfil_nome: "super_admin",
  });
  assert.deepEqual(h.chamadas, []);
});

test("Super Admin normal e cliente não registram o Service Worker", async () => {
  const sessoes = [
    { tipo_usuario: "super_admin", modo_suporte: false, empresa_id: 0 },
    { tipo_usuario: "cliente", perfil_nome: "cliente", empresa_id: 7, modo_suporte: false },
    { tipo_usuario: "usuario", perfil_nome: "proprietario", empresa_id: 7, modo_suporte: true },
  ];
  for (const auth of sessoes) {
    const h = executarRegistrador({ scriptUrl: SCRIPT_V });
    await h.emitirLoad();
    await h.emitirSessao(auth);
    assert.deepEqual(h.chamadas, [], JSON.stringify(auth));
  }
});

test("Proprietário, Recepcionista e Profissional registram após a sessão validada", async () => {
  for (const perfil of ["proprietario", "recepcionista", "profissional"]) {
    const h = executarRegistrador({ scriptUrl: SCRIPT_V });
    await h.emitirLoad();
    assert.deepEqual(h.chamadas, [], `${perfil}: não registra antes da sessão`);
    await h.emitirSessao({ ...AUTH_INTERNO, perfil_nome: perfil });
    assert.equal(h.chamadas.length, 1, perfil);
    assert.equal(h.chamadas[0].url, "/sw.js?v=1.2.3");
  }
});

test("sessão já carregada antes do load é reutilizada", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    authInicial: { ...AUTH_INTERNO, perfil_nome: "profissional" },
  });
  await h.emitirLoad();
  assert.equal(h.chamadas.length, 1);
});

test("página interna sem sessão validada nunca registra", async () => {
  const h = executarRegistrador({ scriptUrl: SCRIPT_V });
  await h.emitirLoad();
  assert.deepEqual(h.chamadas, []);
});

test("contexto bloqueado pelo servidor não registra nem no login", async () => {
  const h = executarRegistrador({ scriptUrl: SCRIPT_V, contexto: "bloqueado" });
  await h.emitirLoad();
  assert.deepEqual(h.chamadas, []);
});

test("Agenda Online registra sem depender de autenticação interna", async () => {
  const h = executarRegistrador({ scriptUrl: SCRIPT_V, contexto: "agenda-online" });
  await h.emitirLoad();
  assert.equal(h.chamadas.length, 1);
  assert.equal(h.chamadas[0].url, "/sw.js?v=1.2.3");
});

test("cliente autenticado registra no contexto explícito de cliente", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    contexto: "cliente",
    authInicial: {
      tipo_usuario: "cliente",
      perfil_nome: "cliente",
      empresa_id: 7,
      modo_suporte: false,
    },
  });
  await h.emitirLoad();
  assert.equal(h.chamadas.length, 1);
});

test("contexto de Super Admin continua sem registro", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    contexto: "super-admin",
    authInicial: {
      tipo_usuario: "super_admin",
      perfil_nome: "super_admin",
      empresa_id: 1,
      modo_suporte: false,
    },
  });
  await h.emitirLoad();
  assert.deepEqual(h.chamadas, []);
});

test("registrador existe somente nas seis páginas autorizadas", () => {
  const publico = path.resolve(__dirname, "../../public");
  const encontrados = [];
  const visitar = (dir) => {
    for (const nome of fs.readdirSync(dir, { withFileTypes: true })) {
      const alvo = path.join(dir, nome.name);
      if (nome.isDirectory()) visitar(alvo);
      else if (/\.(html|php)$/i.test(nome.name)
        && fs.readFileSync(alvo, "utf8").includes("RegistrarServiceWorker.js")) {
        encontrados.push(path.relative(publico, alvo).split(path.sep).join("/"));
      }
    }
  };
  visitar(publico);
  assert.deepEqual(encontrados.sort(), [
    "views/agenda.html",
    "views/cliente-agendamento.html",
    "views/cliente-perfil.html",
    "views/login-cliente.php",
    "views/login-empresa.php",
    "views/painel-administrativo/painel-administrativo.html",
  ]);
});

test("não registra URL de versão anterior quando já existe worker mais novo", async () => {
  const existente = { active: { scriptURL: "https://amagenda.test/sw.js?v=1.0.4" } };
  const h = executarRegistrador({
    scriptUrl: "https://amagenda.test/js/PWA/RegistrarServiceWorker.js?v=1.0.3",
    contexto: "login",
    registroExistente: existente,
  });
  const registro = await h.api.registrarVersao("1.0.3");
  assert.deepEqual(h.chamadas, []);
  assert.equal(registro, existente);
});

test("registra a nova versão quando o worker existente é anterior", async () => {
  const h = executarRegistrador({
    scriptUrl: SCRIPT_V,
    contexto: "login",
    registroExistente: { active: { scriptURL: "https://amagenda.test/sw.js?v=1.0.3" } },
  });
  await h.api.registrarVersao("1.0.4");
  assert.equal(h.chamadas.length, 1);
  assert.equal(h.chamadas[0].url, "/sw.js?v=1.0.4");
});
