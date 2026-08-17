// sw.js
const CACHE_NAME = 'conex-v2';   // troquei versão
const SAME_ORIGIN = self.location.origin;
const NEVER_CACHE = [
  '/manifest.json',
  '/Imagens/icon-v2.png',        // adicione outros que mudam com frequência
];

self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  const deveCachear =
    request.method === 'GET' &&
    (url.protocol === 'http:' || url.protocol === 'https:') &&
    url.origin === SAME_ORIGIN &&
    !NEVER_CACHE.includes(url.pathname);   // <‑‑ NÃO cacheia manifest nem ícone

  if (!deveCachear) return;

  event.respondWith(
    caches.open(CACHE_NAME).then(cache =>
      cache.match(request).then(respCache =>
        respCache ||
        fetch(request).then(respNet => {
          if (respNet.ok && respNet.type === 'basic') {
            cache.put(request, respNet.clone());
          }
          return respNet;
        })
      )
    )
  );
});
