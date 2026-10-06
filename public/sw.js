"use strict";

const CACHE_PREFIX = "amagenda-";

function obterVersaoWorker() {
  const valor = new URL(self.location.href).searchParams.get("v") || "unversioned";
  return /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(valor)
    ? valor
    : "unversioned";
}

const CACHE_NAME = `${CACHE_PREFIX}assets-${obterVersaoWorker()}`;
const SAME_ORIGIN = self.location.origin;
// Única página HTML guardada pelo worker: neutra, estática, sem sessão nem dados.
const OFFLINE_URL = "/public/offline.html";

const CLIENT_PATHS = new Set([
  "/views/login-empresa.php",
  "/views/agenda.html",
  "/views/painel-administrativo/painel-administrativo.html",
  "/public/views/login-empresa.php",
  "/public/views/agenda.html",
  "/public/views/painel-administrativo/painel-administrativo.html",
]);

const STATIC_ROOTS = [
  "/js/",
  "/public/js/",
  "/css/",
  "/public/css/",
  "/imagens/",
  "/public/imagens/",
  "/fonts/",
  "/public/fonts/",
];

const STATIC_EXTENSIONS = new Set([
  ".js",
  ".css",
  ".png",
  ".jpg",
  ".jpeg",
  ".webp",
  ".svg",
  ".ico",
  ".woff",
  ".woff2",
  ".ttf",
  ".otf",
]);

const DENIED_PATH_PREFIXES = [
  "/api/",
  "/public/api/",
  "/backend/",
  "/public/backend/",
  "/_auth/",
  "/public/_auth/",
  "/agendar/",
  "/views/login-cliente",
  "/public/views/login-cliente",
  "/views/cliente",
  "/public/views/cliente",
  "/views/login-super-admin",
  "/public/views/login-super-admin",
  "/views/super-admin/",
  "/public/views/super-admin/",
  "/imagens/usuarios/",
  "/public/imagens/usuarios/",
  "/imagens/clientes/",
  "/public/imagens/clientes/",
];

function caminhoNegado(pathname) {
  const caminho = pathname.toLowerCase();
  if (caminho === "/app-version.php" || caminho === "/public/app-version.php") {
    return true;
  }
  return DENIED_PATH_PREFIXES.some((prefixo) => caminho.startsWith(prefixo));
}

function extensaoDoCaminho(pathname) {
  const nome = pathname.slice(pathname.lastIndexOf("/") + 1);
  const indice = nome.lastIndexOf(".");
  return indice >= 0 ? nome.slice(indice).toLowerCase() : "";
}

function assetPermitido(url) {
  const caminho = url.pathname.toLowerCase();
  if (caminho === "/manifest.json" || caminho === "/public/manifest.json") {
    return true;
  }
  if (!STATIC_ROOTS.some((raiz) => caminho.startsWith(raiz))) {
    return false;
  }
  return STATIC_EXTENSIONS.has(extensaoDoCaminho(caminho));
}

async function clienteInternoPermitido(clientId) {
  if (!clientId) return false;
  const cliente = await self.clients.get(clientId);
  if (!cliente || !cliente.url) return false;
  try {
    const url = new URL(cliente.url);
    return url.origin === SAME_ORIGIN && CLIENT_PATHS.has(url.pathname);
  } catch (_) {
    return false;
  }
}

function respostaCacheavel(response) {
  if (!response || response.status !== 200 || response.type !== "basic") {
    return false;
  }
  const cacheControl = String(response.headers.get("Cache-Control") || "").toLowerCase();
  return !/(?:^|,)\s*(?:no-store|private)(?:\s|,|=|$)/.test(cacheControl);
}

async function networkFirst(request, clientePermitidoPromise) {
  const clientePermitido = await clientePermitidoPromise;
  if (!clientePermitido) return fetch(request);

  const cache = await caches.open(CACHE_NAME);
  try {
    const response = await fetch(request);
    if (respostaCacheavel(response)) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (erroRede) {
    const cached = await cache.match(request);
    if (cached) return cached;
    throw erroRede;
  }
}

function staleWhileRevalidate(event, clientePermitidoPromise) {
  const request = event.request;
  const cachePromise = clientePermitidoPromise.then((clientePermitido) => (
    clientePermitido ? caches.open(CACHE_NAME) : null
  ));
  const networkPromise = Promise.all([clientePermitidoPromise, cachePromise])
    .then(async ([clientePermitido, cache]) => {
      const response = await fetch(request);
      if (clientePermitido && cache && respostaCacheavel(response)) {
        await cache.put(request, response.clone());
      }
      return response;
    });

  const responsePromise = Promise.all([clientePermitidoPromise, cachePromise])
    .then(async ([clientePermitido, cache]) => {
      if (!clientePermitido) return networkPromise;
      const cached = await cache.match(request);
      return cached || networkPromise;
    });

  event.waitUntil(networkPromise.then(() => undefined).catch(() => undefined));
  return responsePromise;
}

// Guarda somente a página offline neutra. Falha aqui não impede a instalação:
// sem ela, a navegação offline apenas mostra o erro padrão do navegador.
async function guardarPaginaOffline() {
  try {
    const response = await fetch(OFFLINE_URL, { cache: "reload", credentials: "omit" });
    if (!respostaCacheavel(response)) return;
    const cache = await caches.open(CACHE_NAME);
    await cache.put(OFFLINE_URL, response);
  } catch (_) {
    // Sem rede durante a instalação: segue sem fallback.
  }
}

// Navegação: sempre a rede. A resposta HTTP do servidor (200, 401, 403, 404,
// 500, redirecionamentos) é devolvida intacta; a página offline só é usada
// quando o fetch rejeita, isto é, falha real de rede.
async function navegarComFallbackOffline(request) {
  try {
    return await fetch(request);
  } catch (erroRede) {
    const cache = await caches.open(CACHE_NAME);
    const offline = await cache.match(OFFLINE_URL);
    if (offline) return offline;
    throw erroRede;
  }
}

self.addEventListener("install", (event) => {
  event.waitUntil(guardarPaginaOffline());
});

self.addEventListener("message", (event) => {
  if (event.data?.tipo !== "ATIVAR_NOVA_VERSAO") return;
  event.waitUntil(self.skipWaiting());
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys
        .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
        .map((key) => caches.delete(key))
    ))
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method === "GET" && request.mode === "navigate"
    && new URL(request.url).origin === SAME_ORIGIN) {
    event.respondWith(navegarComFallbackOffline(request));
    return;
  }
  if (request.method !== "GET" || request.mode === "navigate" || request.destination === "document") {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== SAME_ORIGIN || caminhoNegado(url.pathname) || !assetPermitido(url)) {
    return;
  }

  const clientePermitidoPromise = clienteInternoPermitido(event.clientId);
  if (extensaoDoCaminho(url.pathname) === ".js") {
    event.respondWith(networkFirst(request, clientePermitidoPromise));
    return;
  }

  event.respondWith(staleWhileRevalidate(event, clientePermitidoPromise));
});
