(() => {
  "use strict";
  const TIPOS = {
    success: ["ui-alert--success", "✓", "Sucesso"], warning: ["ui-alert--warning", "!", "Atenção"],
    danger: ["ui-alert--danger", "×", "Não foi possível concluir"], confirm: ["ui-alert--confirm", "?", "Confirmação"],
    info: ["ui-alert--info", "i", "Informação"]
  };
  function pilha() {
    let el = document.querySelector(".ui-toast-stack");
    if (!el) { el = document.createElement("div"); el.className = "ui-toast-stack"; el.setAttribute("aria-live", "polite"); document.body.appendChild(el); }
    return el;
  }
  function fechar(el) {
    if (!el || el.dataset.fechando) return;
    el.dataset.fechando = "1"; el.classList.add("is-leaving");
    el.addEventListener("animationend", () => el.remove(), { once: true });
    setTimeout(() => el.remove(), 250);
  }
  function botao(texto, classe, acao) {
    const el = document.createElement("button"); el.type = "button"; el.className = `ui-alert__btn ${classe}`; el.textContent = texto; el.addEventListener("click", acao); return el;
  }
  function exibir(tipo, mensagem, opcoes = {}) {
    const cfg = TIPOS[tipo] || TIPOS.info;
    const el = document.createElement("section"); el.className = `ui-alert ${cfg[0]}`; el.setAttribute("role", ["danger", "warning"].includes(tipo) ? "alert" : "status");
    const icon = document.createElement("div"); icon.className = "ui-alert__icon"; icon.setAttribute("aria-hidden", "true"); icon.textContent = cfg[1];
    const content = document.createElement("div"); content.className = "ui-alert__content";
    const title = document.createElement("div"); title.className = "ui-alert__title"; title.textContent = opcoes.titulo || cfg[2];
    const msg = document.createElement("div"); msg.className = "ui-alert__msg"; msg.textContent = String(mensagem || ""); content.append(title, msg);
    const actions = document.createElement("div"); actions.className = "ui-alert__actions"; el.append(icon, content, actions);
    const manual = ["warning", "danger", "confirm"].includes(tipo) || opcoes.persistente;
    if (manual && tipo !== "confirm") actions.append(botao(opcoes.textoBotao || "OK", "ui-alert__btn--primary", () => { fechar(el); opcoes.aoFechar?.(); }));
    pilha().prepend(el);
    if (!manual) setTimeout(() => { fechar(el); opcoes.aoFechar?.(); }, opcoes.tempo ?? 3200);
    if (manual) setTimeout(() => actions.querySelector("button")?.focus({ preventScroll: true }), 30);
    return el;
  }
  function confirmar(mensagem, opcoes = {}) {
    return new Promise(resolve => {
      const el = exibir("confirm", mensagem, { ...opcoes, persistente: true }); const actions = el.querySelector(".ui-alert__actions");
      const fim = valor => { fechar(el); resolve(valor); };
      actions.append(botao(opcoes.textoCancelar || "Cancelar", "ui-alert__btn--secondary", () => fim(false)), botao(opcoes.textoConfirmar || "Confirmar", "ui-alert__btn--primary", () => fim(true)));
      setTimeout(() => actions.lastElementChild?.focus({ preventScroll: true }), 30);
    });
  }
  window.MensagemSistema = { exibir, sucesso: (m,o)=>exibir("success",m,o), aviso: (m,o)=>exibir("warning",m,o), erro: (m,o)=>exibir("danger",m,o), info: (m,o)=>exibir("info",m,o), confirmar };
})();
