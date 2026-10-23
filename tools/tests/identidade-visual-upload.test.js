const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const SCRIPT_PATH = path.resolve(
  __dirname,
  "../../public/js/identidade-visual/identidade-visual.js"
);

test("salvar envia a imagem de login processada em vez do arquivo original", async () => {
  const original = {
    name: "foto-camera.jpg",
    type: "image/jpeg",
    size: 3 * 1024 * 1024,
    slice() {}
  };
  const processada = {
    name: "foto-camera.jpg",
    type: "image/jpeg",
    size: 900 * 1024,
    slice() {}
  };
  const campos = {
    "#identidadeNome": { value: "Studio Moura" },
    "#identidadeCorHex": { value: "#1163DD" },
    "#identidadeLogin": { files: [original] }
  };
  const botaoSalvar = { disabled: false, textContent: "Salvar alterações" };
  const modal = {
    querySelector(seletor) {
      if (seletor === "#identidadeSalvar") return botaoSalvar;
      return campos[seletor] || { value: "", reset() {}, focus() {} };
    },
    querySelectorAll() { return []; },
    classList: { remove() {} },
    setAttribute() {}
  };
  const erroCor = { textContent: "" };
  const toastStack = { appendChild() {} };
  let corpoEnviado = null;
  let opcoesProcessamento = null;

  class FormDataFake {
    constructor(form) {
      this.valores = new Map();
      const arquivo = form.querySelector("#identidadeLogin").files[0];
      if (arquivo) this.valores.set("imagem_login", arquivo);
    }
    set(chave, valor) { this.valores.set(chave, valor); }
    get(chave) { return this.valores.get(chave); }
  }

  const document = {
    activeElement: null,
    body: { classList: { remove() {} }, appendChild() {} },
    documentElement: { style: { setProperty() {} } },
    addEventListener() {},
    dispatchEvent() {},
    getElementById(id) {
      if (id === "modalIdentidadeVisual") return modal;
      if (id === "identidadeCorHex") return campos["#identidadeCorHex"];
      if (id === "identidadeCorErro") return erroCor;
      return null;
    },
    querySelector(seletor) {
      return seletor === ".ui-toast-stack" ? toastStack : null;
    },
    querySelectorAll() { return []; },
    createElement() {
      return {
        className: "",
        innerHTML: "",
        querySelector() { return { textContent: "" }; },
        remove() {}
      };
    }
  };
  const window = {
    __AUTH__: { csrf_token: "token-teste", empresa_id: 1 },
    AmAgendaImageProcessor: {
      async process(arquivo, opcoes) {
        assert.equal(arquivo, original);
        opcoesProcessamento = opcoes;
        return { file: processada, optimized: true };
      }
    }
  };
  const contexto = {
    window,
    document,
    FormData: FormDataFake,
    CustomEvent: class {},
    URL: { createObjectURL() { return "blob:teste"; }, revokeObjectURL() {} },
    Image: class {},
    setTimeout() { return 0; },
    clearTimeout() {},
    console,
    async fetch(_url, opcoes) {
      corpoEnviado = opcoes.body;
      throw new Error("interromper após capturar o envio");
    }
  };
  contexto.globalThis = contexto;

  const codigo = fs.readFileSync(SCRIPT_PATH, "utf8").replace(
    "window.AmAgendaIdentidade = { carregar, obter: () => ({ ...identidade }) };",
    "window.AmAgendaIdentidade = { carregar, obter: () => ({ ...identidade }) }; window.__salvarIdentidadeTeste = salvar;"
  );
  vm.runInNewContext(codigo, contexto, { filename: SCRIPT_PATH });

  const form = {
    querySelector(seletor) { return campos[seletor]; },
    reset() {}
  };
  await window.__salvarIdentidadeTeste({ preventDefault() {}, currentTarget: form });

  assert.ok(opcoesProcessamento, "o processador compartilhado deve ser chamado");
  // Fase 3: original de até 40 MB; arquivo final de até 4,5 MB (backend aceita 5 MB).
  assert.equal(opcoesProcessamento.maxInputBytes, 40 * 1024 * 1024);
  assert.equal(opcoesProcessamento.maxBytes, Math.round(4.5 * 1024 * 1024));
  assert.ok(opcoesProcessamento.maxBytes < 5 * 1024 * 1024);
  assert.equal(opcoesProcessamento.minShortSide, 400);
  assert.equal(opcoesProcessamento.minLongSide, 800);
  assert.equal(opcoesProcessamento.allowHeic, true);
  assert.equal(corpoEnviado.get("imagem_login"), processada);
});
