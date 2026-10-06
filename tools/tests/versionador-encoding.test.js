"use strict";

// Regressao do versionador de assets (tools/aplicar-versao-assets.ps1):
// no Windows PowerShell 5.1, Get-Content sem -Encoding + Set-Content -Encoding utf8
// gravavam BOM e corrompiam os acentos (sessão -> sessÃ£o), quebrando o PHP com
// "strict_types declaration must be the very first statement".

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const RAIZ = path.resolve(__dirname, "../..");
const SCRIPT_PATH = path.join(RAIZ, "tools/aplicar-versao-assets.ps1");
const BOM = Buffer.from([0xef, 0xbb, 0xbf]);
// Sequencias tipicas de UTF-8 lido como Windows-1252 e regravado em UTF-8.
const MOJIBAKE = /Ã[\u0080-¿]|â€|Â[ -¿]/;

function arquivosConfigurados() {
  const script = fs.readFileSync(SCRIPT_PATH, "utf8");
  const bloco = script.match(/\[string\[\]\]\$Files\s*=\s*@\(([\s\S]*?)\)/);
  assert.ok(bloco, "lista $Files nao encontrada no versionador");
  const arquivos = [...bloco[1].matchAll(/'([^']+)'/g)].map(m => m[1]);
  assert.ok(arquivos.length > 0, "versionador sem arquivos configurados");
  return arquivos;
}

test("versionador grava e le explicitamente em UTF-8 sem BOM", () => {
  // Ignora comentarios: so o codigo executavel importa.
  const script = fs.readFileSync(SCRIPT_PATH, "utf8")
    .split(/\r?\n/)
    .filter(linha => !linha.trim().startsWith("#"))
    .join("\n");
  assert.match(script, /New-Object System\.Text\.UTF8Encoding\(\$false/);
  assert.match(script, /\[System\.IO\.File\]::WriteAllText\(\$filePath, \$updated, \$utf8SemBom\)/);
  assert.doesNotMatch(script, /\bSet-Content\b/);
  assert.doesNotMatch(script, /\bOut-File\b/);
  assert.doesNotMatch(script, /Get-Content -Raw -LiteralPath \$filePath/);
});

test("arquivos configurados no versionador estao em UTF-8 valido, sem BOM e sem mojibake", () => {
  const decoder = new TextDecoder("utf-8", { fatal: true });
  for (const relativo of arquivosConfigurados()) {
    const bytes = fs.readFileSync(path.join(RAIZ, relativo));
    assert.ok(!bytes.subarray(0, 3).equals(BOM), `${relativo} comeca com BOM UTF-8 (EF BB BF)`);
    const texto = decoder.decode(bytes);
    assert.doesNotMatch(texto, MOJIBAKE, `${relativo} contem acentuacao corrompida`);
    if (relativo.endsWith(".php")) {
      assert.ok(texto.startsWith("<?php"), `${relativo} precisa iniciar diretamente em <?php`);
    }
  }
});
