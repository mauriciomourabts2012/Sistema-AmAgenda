"use strict";

// Fase 5: ícones do PWA (any + maskable) no manifest interno e na Agenda Online.
const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const PUBLIC = path.resolve(__dirname, "../../public");
const MANIFEST_INTERNO = path.join(PUBLIC, "manifest.json");
const MANIFEST_AGENDA_ONLINE = path.join(PUBLIC, "manifest-agenda-online.php");

const ESPERADOS = [
  ["imagens/PWA/app-icon-192.png", "192x192", "any"],
  ["imagens/PWA/app-icon-512.png", "512x512", "any"],
  ["imagens/PWA/app-icon-maskable-192.png", "192x192", "maskable"],
  ["imagens/PWA/app-icon-maskable-512.png", "512x512", "maskable"],
];

function lerPng(relativo) {
  const bytes = fs.readFileSync(path.join(PUBLIC, relativo));
  assert.deepEqual([...bytes.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10], `${relativo} não é PNG`);
  assert.equal(bytes.toString("ascii", 12, 16), "IHDR");
  return { largura: bytes.readUInt32BE(16), altura: bytes.readUInt32BE(20), tipoCor: bytes[25] };
}

function iconesAgendaOnline() {
  const php = fs.readFileSync(MANIFEST_AGENDA_ONLINE, "utf8");
  return [...php.matchAll(/'src' => '([^']+)',\s*'sizes' => '([^']+)',\s*'type' => '([^']+)',\s*'purpose' => '([^']+)'/g)]
    .map(([, src, sizes, type, purpose]) => ({ src, sizes, type, purpose }));
}

test("manifest interno declara any e maskable 192/512", () => {
  const manifest = JSON.parse(fs.readFileSync(MANIFEST_INTERNO, "utf8"));
  assert.deepEqual(
    manifest.icons.map(i => [i.src, i.sizes, i.purpose]),
    ESPERADOS
  );
  assert.ok(manifest.icons.every(i => i.type === "image/png"));
  // Campos já validados nas fases anteriores.
  assert.equal(manifest.start_url, "pwa.php?source=pwa");
  assert.equal(manifest.scope, "./");
  assert.equal(manifest.id, "./");
});

test("manifest da Agenda Online usa o mesmo conjunto de ícones", () => {
  assert.deepEqual(
    iconesAgendaOnline().map(i => [i.src, i.sizes, i.purpose]),
    ESPERADOS.map(([src, sizes, purpose]) => [`/public/${src}`, sizes, purpose])
  );
  const php = fs.readFileSync(MANIFEST_AGENDA_ONLINE, "utf8");
  assert.match(php, /'id' => \$entradaPublica/);
  assert.match(php, /'start_url' => \$entradaPublica/);
});

test("arquivos de ícone existem, são PNG e têm as dimensões declaradas", () => {
  for (const [src, sizes, purpose] of ESPERADOS) {
    const png = lerPng(src);
    assert.equal(`${png.largura}x${png.altura}`, sizes, src);
    if (purpose === "any") {
      // RGBA: cantos fora do quadrado arredondado são transparentes (não pretos).
      assert.equal(png.tipoCor, 6, `${src} precisa de canal alfa`);
    }
  }
});

test("maskable é arquivo próprio, não substitui o ícone normal", () => {
  for (const tamanho of ["192", "512"]) {
    const normal = fs.readFileSync(path.join(PUBLIC, `imagens/PWA/app-icon-${tamanho}.png`));
    const maskable = fs.readFileSync(path.join(PUBLIC, `imagens/PWA/app-icon-maskable-${tamanho}.png`));
    assert.notDeepEqual(normal, maskable);
  }
});
