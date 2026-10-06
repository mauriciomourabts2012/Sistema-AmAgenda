"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const WORKER_PATH = path.resolve(__dirname, "../../public/sw.js");
const WORKER_SOURCE = fs.readFileSync(WORKER_PATH, "utf8");
const ORIGIN = "https://amagenda.test";
const INTERNAL_CLIENT = `${ORIGIN}/views/agenda.html`;

class HeadersFake {
  constructor(values = {}) {
    this.values = new Map(
      Object.entries(values).map(([key, value]) => [key.toLowerCase(), String(value)])
    );
  }

  get(name) {
    return this.values.get(String(name).toLowerCase()) ?? null;
  }
}

class ResponseFake {
  constructor(body, { status = 200, type = "basic", headers = {} } = {}) {
    this.body = body;
    this.status = status;
    this.ok = status >= 200 && status < 300;
    this.type = type;
    this.headers = new HeadersFake(headers);
  }

  clone() {
    return new ResponseFake(this.body, {
      status: this.status,
      type: this.type,
      headers: Object.fromEntries(this.headers.values),
    });
  }
}

function request(url, options = {}) {
  return {
    url: new URL(url, ORIGIN).href,
    method: options.method || "GET",
    mode: options.mode || "cors",
    destination: options.destination || "script",
    referrer: options.referrer || INTERNAL_CLIENT,
  };
}

function createHarness() {
  const listeners = new Map();
  const stores = new Map();
  const deletedCaches = [];
  const fetchCalls = [];
  const cacheOps = { open: 0, match: 0, put: 0 };
  let fetchImplementation = async (req) => new ResponseFake(`network:${req.url}`);
  let clientUrl = INTERNAL_CLIENT;
  let skipWaitingCalls = 0;
  let claimCalls = 0;

  const keyFor = (req) => (typeof req === "string" ? req : req.url);
  const caches = {
    async open(name) {
      cacheOps.open += 1;
      if (!stores.has(name)) stores.set(name, new Map());
      const store = stores.get(name);
      return {
        async match(req) {
          cacheOps.match += 1;
          const value = store.get(keyFor(req));
          return value ? value.clone() : undefined;
        },
        async put(req, response) {
          cacheOps.put += 1;
          store.set(keyFor(req), response.clone());
        },
      };
    },
    async keys() {
      return [...stores.keys()];
    },
    async delete(name) {
      deletedCaches.push(name);
      return stores.delete(name);
    },
  };

  const self = {
    location: new URL(`${ORIGIN}/sw.js?v=1.2.3`),
    clients: {
      async get() {
        return clientUrl ? { url: clientUrl } : undefined;
      },
      async claim() {
        claimCalls += 1;
      },
    },
    skipWaiting() {
      skipWaitingCalls += 1;
    },
    addEventListener(type, handler) {
      listeners.set(type, handler);
    },
  };

  const context = vm.createContext({
    self,
    caches,
    URL,
    Promise,
    console,
    fetch: async (req) => {
      fetchCalls.push(req.url);
      return fetchImplementation(req);
    },
  });
  vm.runInContext(WORKER_SOURCE, context, { filename: WORKER_PATH });

  async function dispatchLifecycle(type) {
    const waits = [];
    const handler = listeners.get(type);
    assert.equal(typeof handler, "function", `listener ${type} deve existir`);
    handler({ waitUntil(promise) { waits.push(Promise.resolve(promise)); } });
    await Promise.all(waits);
  }

  async function dispatchFetch(req, urlCliente = INTERNAL_CLIENT) {
    clientUrl = urlCliente;
    const waits = [];
    let responded = false;
    let responsePromise;
    const handler = listeners.get("fetch");
    assert.equal(typeof handler, "function", "listener fetch deve existir");
    handler({
      request: req,
      clientId: urlCliente ? "client-1" : "",
      respondWith(value) {
        responded = true;
        responsePromise = Promise.resolve(value);
      },
      waitUntil(value) {
        waits.push(Promise.resolve(value));
      },
    });
    const response = responded ? await responsePromise : undefined;
    await Promise.all(waits);
    return { responded, response };
  }

  async function seed(cacheName, req, response) {
    const cache = await caches.open(cacheName);
    await cache.put(req, response);
  }

  return {
    listeners,
    stores,
    deletedCaches,
    fetchCalls,
    cacheOps,
    dispatchLifecycle,
    dispatchFetch,
    seed,
    setFetch(fn) { fetchImplementation = fn; },
    get skipWaitingCalls() { return skipWaitingCalls; },
    get claimCalls() { return claimCalls; },
  };
}

test("instala e ativa sem forçar troca do worker ou assumir clientes", async () => {
  const h = createHarness();
  await h.dispatchLifecycle("install");
  await h.dispatchLifecycle("activate");
  assert.equal(h.skipWaitingCalls, 0);
  assert.equal(h.claimCalls, 0);
});

test("não intercepta métodos de escrita nem documentos fora de navegação", async () => {
  const h = createHarness();
  const cases = [
    request("/js/app.js", { method: "POST" }),
    request("/views/agenda.html", { method: "POST", mode: "navigate", destination: "document" }),
    request("/views/painel.html", { destination: "document" }),
  ];
  for (const req of cases) {
    const result = await h.dispatchFetch(req);
    assert.equal(result.responded, false, `${req.method} ${req.url} não deve ser interceptado`);
  }
});

test("denylist não toca Cache Storage", async () => {
  const h = createHarness();
  const paths = [
    "/api/api_central.php?path=agenda/lista",
    "/backend/_auth/session.php",
    "/app-version.php",
    "/public/_auth/login.js",
    "/agendar/studio-exemplo",
    "/views/super-admin/painel-super-admin.html",
  ];
  for (const pathname of paths) {
    const result = await h.dispatchFetch(request(pathname));
    assert.equal(result.responded, false, `${pathname} deve ficar fora do handler`);
  }
  assert.equal(h.stores.size, 0);
});

test("cliente fora dos fluxos internos usa rede sem Cache Storage", async () => {
  const h = createHarness();
  const result = await h.dispatchFetch(
    request("/js/agenda/lista-agenda.js"),
    `${ORIGIN}/views/login-cliente.php`
  );
  assert.equal(result.responded, true);
  assert.equal(result.response.body, `network:${ORIGIN}/js/agenda/lista-agenda.js`);
  assert.equal(h.stores.size, 0);
});

test("CSS de cliente fora dos fluxos internos não abre Cache Storage", async () => {
  const h = createHarness();
  const result = await h.dispatchFetch(
    request("/css/login/login-cliente.css", { destination: "style" }),
    `${ORIGIN}/views/login-cliente.php`
  );
  assert.equal(result.responded, true);
  assert.equal(result.response.body, `network:${ORIGIN}/css/login/login-cliente.css`);
  assert.equal(h.stores.size, 0);
});

test("fluxos excluídos não executam nenhuma operação de Cache Storage", async () => {
  const clientesExcluidos = [
    `${ORIGIN}/views/login-cliente.php`,
    `${ORIGIN}/public/views/cliente-agendamento.html`,
    `${ORIGIN}/agendar/studio-exemplo`,
    `${ORIGIN}/views/super-admin/painel-super-admin.html`,
    `${ORIGIN}/views/login-super-admin.html`,
    "",
  ];
  const assets = [
    request("/css/agenda/agenda-web.css?v=1.2.3", { destination: "style" }),
    request("/js/agenda/lista-agenda.js?v=1.2.3"),
    request("/imagens/PWA/app-icon-192.png", { destination: "image" }),
    request("/manifest.json", { destination: "manifest" }),
  ];
  for (const urlCliente of clientesExcluidos) {
    for (const req of assets) {
      const h = createHarness();
      const result = await h.dispatchFetch(req, urlCliente);
      const rotulo = `${urlCliente || "sem cliente"} -> ${req.url}`;
      assert.equal(result.responded, true, rotulo);
      assert.equal(result.response.body, `network:${req.url}`, rotulo);
      assert.deepEqual({ ...h.cacheOps }, { open: 0, match: 0, put: 0 }, rotulo);
      assert.equal(h.stores.size, 0, rotulo);
    }
  }
});

test("fotos de usuários e clientes nunca tocam Cache Storage", async () => {
  const h = createHarness();
  const paths = [
    "/imagens/usuarios/usuario_1_211fd7c23464f4a5.png",
    "/public/imagens/usuarios/usuario_3_072bb927b30cdd1f.webp",
    "/imagens/clientes/cliente_1_e1f4188d95630ab1.png",
    "/public/imagens/clientes/cliente_2_61cb9dea79056fdc.png",
  ];
  for (const pathname of paths) {
    const result = await h.dispatchFetch(request(pathname, { destination: "image" }));
    assert.equal(result.responded, false, `${pathname} deve ficar fora do handler`);
  }
  assert.deepEqual({ ...h.cacheOps }, { open: 0, match: 0, put: 0 });
  assert.equal(h.stores.size, 0);
});

test("JavaScript permitido usa network-first e atualiza cache versionado", async () => {
  const h = createHarness();
  const req = request("/js/agenda/lista-agenda.js?v=1.2.3");
  await h.seed("amagenda-assets-1.2.3", req, new ResponseFake("old"));
  h.setFetch(async () => new ResponseFake("new"));
  const result = await h.dispatchFetch(req);
  assert.equal(result.response.body, "new");
  const cached = h.stores.get("amagenda-assets-1.2.3").get(req.url);
  assert.equal(cached.body, "new");
});

test("JavaScript usa cache somente quando a rede rejeita", async () => {
  const h = createHarness();
  const req = request("/public/js/menu-lateral/menu-lateral.js?v=1.2.3");
  await h.seed("amagenda-assets-1.2.3", req, new ResponseFake("cached"));
  h.setFetch(async () => { throw new TypeError("offline"); });
  const result = await h.dispatchFetch(req);
  assert.equal(result.response.body, "cached");
});

test("erro HTTP de JavaScript não é substituído por cache antigo", async () => {
  const h = createHarness();
  const req = request("/js/app.js?v=1.2.3");
  await h.seed("amagenda-assets-1.2.3", req, new ResponseFake("cached"));
  h.setFetch(async () => new ResponseFake("server-error", { status: 500 }));
  const result = await h.dispatchFetch(req);
  assert.equal(result.response.status, 500);
  assert.equal(result.response.body, "server-error");
});

test("CSS permitido usa stale-while-revalidate", async () => {
  const h = createHarness();
  const req = request("/css/agenda/agenda-web.css?v=1.2.3", { destination: "style" });
  await h.seed("amagenda-assets-1.2.3", req, new ResponseFake("old-css"));
  h.setFetch(async () => new ResponseFake("new-css"));
  const result = await h.dispatchFetch(req);
  assert.equal(result.response.body, "old-css");
  assert.equal(h.stores.get("amagenda-assets-1.2.3").get(req.url).body, "new-css");
});

test("manifest, imagens e fontes públicas entram na allowlist", async () => {
  const h = createHarness();
  const cases = [
    request("/manifest.json", { destination: "manifest" }),
    request("/imagens/PWA/app-icon-192.png", { destination: "image" }),
    request("/fonts/Poppins.woff2", { destination: "font" }),
  ];
  for (const req of cases) {
    const result = await h.dispatchFetch(req);
    assert.equal(result.responded, true, req.url);
    assert.equal(result.response.status, 200);
  }
});

test("no-store e private impedem escrita, Set-Cookie não", async () => {
  const cases = [
    { name: "no-store", headers: { "Cache-Control": "no-store" }, cached: false },
    { name: "private", headers: { "Cache-Control": "private, max-age=60" }, cached: false },
    { name: "set-cookie", headers: { "Set-Cookie": "infra=1; Secure" }, cached: true },
  ];
  for (const item of cases) {
    const h = createHarness();
    const req = request(`/css/${item.name}.css?v=1.2.3`, { destination: "style" });
    h.setFetch(async () => new ResponseFake(item.name, { headers: item.headers }));
    await h.dispatchFetch(req);
    const store = h.stores.get("amagenda-assets-1.2.3");
    assert.equal(Boolean(store && store.has(req.url)), item.cached, item.name);
  }
});

test("resposta diferente de 200 nunca é gravada", async () => {
  const h = createHarness();
  const req = request("/imagens/PWA/app-icon-512.png", { destination: "image" });
  h.setFetch(async () => new ResponseFake("not-found", { status: 404 }));
  const result = await h.dispatchFetch(req);
  assert.equal(result.response.status, 404);
  const store = h.stores.get("amagenda-assets-1.2.3");
  assert.equal(Boolean(store && store.has(req.url)), false);
});

test("activate remove somente caches antigos do AmAgenda", async () => {
  const h = createHarness();
  await h.seed("amagenda-assets-0.9.0", "/old", new ResponseFake("old"));
  await h.seed("amagenda-assets-1.2.3", "/current", new ResponseFake("current"));
  await h.seed("outra-aplicacao-v1", "/foreign", new ResponseFake("foreign"));
  await h.dispatchLifecycle("activate");
  assert.deepEqual(h.deletedCaches, ["amagenda-assets-0.9.0"]);
  assert.equal(h.stores.has("amagenda-assets-1.2.3"), true);
  assert.equal(h.stores.has("outra-aplicacao-v1"), true);
});

// ===========================================================================
// Fase 6 — fallback offline neutro
// ===========================================================================
const OFFLINE_PATH = path.resolve(__dirname, "../../public/offline.html");
const OFFLINE_URL = "/public/offline.html";
const navegacao = (url) => request(url, { mode: "navigate", destination: "document" });

async function harnessComOfflineInstalado() {
  const h = createHarness();
  h.setFetch(async (req) => new ResponseFake(req === OFFLINE_URL || req?.url === OFFLINE_URL ? "pagina-offline" : "rede"));
  await h.dispatchLifecycle("install");
  return h;
}

test("offline.html existe e é autossuficiente", () => {
  const html = fs.readFileSync(OFFLINE_PATH, "utf8");
  assert.match(html, /<html lang="pt-BR">/);
  assert.match(html, /Você está sem conexão/);
  assert.match(html, /Não foi possível acessar o AmAgenda agora\. Verifique sua internet e tente novamente\./);
  assert.match(html, /<button type="button" onclick="location\.reload\(\)">Tentar novamente<\/button>/);
  // Sem recursos externos, scripts carregados, formulários ou chamadas de rede.
  assert.doesNotMatch(html, /<script\b/i);
  assert.doesNotMatch(html, /<(?:link|img|iframe)\b/i);
  assert.doesNotMatch(html, /\bsrc=|@import|url\(/i);
  assert.doesNotMatch(html, /<form\b|fetch\(|XMLHttpRequest|localStorage|sessionStorage|indexedDB/i);
});

test("offline.html não contém dados de sessão, empresa ou agenda", () => {
  const html = fs.readFileSync(OFFLINE_PATH, "utf8");
  assert.doesNotMatch(html, /<\?php|__AUTH__|empresa_id|empresa_slug|tipo_usuario|cliente_id/i);
  assert.doesNotMatch(html, /\/api\/|app-version|_auth|backend/i);
});

test("install guarda somente a página offline, no cache versionado do AmAgenda", async () => {
  const h = await harnessComOfflineInstalado();
  assert.deepEqual([...h.stores.keys()], ["amagenda-assets-1.2.3"]);
  assert.deepEqual([...h.stores.get("amagenda-assets-1.2.3").keys()], [OFFLINE_URL]);
  assert.equal(h.skipWaitingCalls, 0);
});

test("falha de rede durante install não impede a instalação", async () => {
  const h = createHarness();
  h.setFetch(async () => { throw new TypeError("offline"); });
  await assert.doesNotReject(() => h.dispatchLifecycle("install"));
  assert.equal(h.stores.size, 0);
});

test("navegação online devolve a resposta da rede sem tocar o cache", async () => {
  const h = await harnessComOfflineInstalado();
  h.setFetch(async () => new ResponseFake("agenda-ao-vivo"));
  const antes = { ...h.cacheOps };
  const result = await h.dispatchFetch(navegacao("/views/agenda.html"));
  assert.equal(result.responded, true);
  assert.equal(result.response.body, "agenda-ao-vivo");
  assert.deepEqual({ ...h.cacheOps }, antes, "navegação online não lê nem grava cache");
  assert.deepEqual([...h.stores.get("amagenda-assets-1.2.3").keys()], [OFFLINE_URL]);
});

test("navegação com falha de rede devolve offline.html", async () => {
  const h = await harnessComOfflineInstalado();
  h.setFetch(async () => { throw new TypeError("Failed to fetch"); });
  for (const pagina of ["/views/agenda.html", "/agendar/studio-exemplo", "/public/views/cliente-perfil.html", "/views/login-empresa.php"]) {
    const result = await h.dispatchFetch(navegacao(pagina));
    assert.equal(result.response.body, "pagina-offline", pagina);
  }
});

test("sem página offline guardada, a falha de rede segue para o navegador", async () => {
  const h = createHarness();
  h.setFetch(async () => { throw new TypeError("Failed to fetch"); });
  const req = navegacao("/views/agenda.html");
  await assert.rejects(async () => (await h.dispatchFetch(req)).response, /Failed to fetch/);
});

test("respostas HTTP de navegação (401/403/404/500/redirect) não viram offline.html", async () => {
  for (const status of [401, 403, 404, 500, 302]) {
    const h = await harnessComOfflineInstalado();
    h.setFetch(async () => new ResponseFake(`servidor-${status}`, { status }));
    const result = await h.dispatchFetch(navegacao("/views/agenda.html"));
    assert.equal(result.response.status, status);
    assert.equal(result.response.body, `servidor-${status}`);
  }
});

test("navegação para outra origem não é interceptada", async () => {
  const h = await harnessComOfflineInstalado();
  const result = await h.dispatchFetch(navegacao("https://outro-dominio.test/pagina"));
  assert.equal(result.responded, false);
});

test("POST de navegação (envio de formulário) nunca é interceptado nem cai no offline", async () => {
  const h = await harnessComOfflineInstalado();
  h.setFetch(async () => { throw new TypeError("offline"); });
  const result = await h.dispatchFetch(request("/backend/_auth/login.php", { method: "POST", mode: "navigate", destination: "document" }));
  assert.equal(result.responded, false);
});

test("com página offline guardada, APIs, app-version e POST continuam fora do cache", async () => {
  const h = await harnessComOfflineInstalado();
  const casos = [
    request("/api/api_central.php?path=agenda/lista"),
    request("/app-version.php"),
    request("/public/_auth/sessao.js"),
    request("/api/api_central.php", { method: "POST" }),
    request("/backend/cliente_agendamento/confirmar-agendamento.php", { method: "POST" }),
  ];
  for (const req of casos) {
    const result = await h.dispatchFetch(req);
    assert.equal(result.responded, false, `${req.method} ${req.url}`);
  }
  assert.deepEqual([...h.stores.get("amagenda-assets-1.2.3").keys()], [OFFLINE_URL]);
});

test("asset GET continua com a política atual (JS network-first)", async () => {
  const h = await harnessComOfflineInstalado();
  const req = request("/js/agenda/lista-agenda.js?v=1.2.3");
  h.setFetch(async () => new ResponseFake("js-novo"));
  const result = await h.dispatchFetch(req);
  assert.equal(result.response.body, "js-novo");
  assert.equal(h.stores.get("amagenda-assets-1.2.3").get(req.url).body, "js-novo");
});

test("worker não implementa escrita offline, sincronização nem armazenamento de dados", () => {
  assert.doesNotMatch(WORKER_SOURCE, /addEventListener\(\s*["'](?:sync|periodicsync|backgroundfetch)/);
  assert.doesNotMatch(WORKER_SOURCE, /indexedDB|IDBDatabase|BackgroundSync/);
  assert.doesNotMatch(WORKER_SOURCE, /request\.method\s*===\s*["'](?:POST|PUT|PATCH|DELETE)["']/);
});

test("activate continua removendo somente caches antigos do AmAgenda (incluindo o offline antigo)", async () => {
  const h = await harnessComOfflineInstalado();
  await h.seed("amagenda-assets-1.2.2", OFFLINE_URL, new ResponseFake("offline-antigo"));
  await h.seed("outra-aplicacao-v1", "/foreign", new ResponseFake("foreign"));
  await h.dispatchLifecycle("activate");
  assert.deepEqual(h.deletedCaches, ["amagenda-assets-1.2.2"]);
  assert.equal(h.stores.get("amagenda-assets-1.2.3").has(OFFLINE_URL), true);
  assert.equal(h.stores.has("outra-aplicacao-v1"), true);
});
