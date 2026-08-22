(() => {
  "use strict";
  function usuarioPode(codigo) {
    const auth = window.__AUTH__ || {};
    if (String(auth.tipo_usuario || "").toLowerCase() === "super_admin" && auth.modo_suporte === true) return true;
    return auth.permissoes?.[codigo] === true;
  }
  window.usuarioPode = usuarioPode;
  document.addEventListener("amagenda:sessao-carregada", (evento) => {
    const auth = evento.detail || {};
    document.querySelectorAll("[data-requer-permissao]").forEach((el) => {
      const permitido = usuarioPode(el.dataset.requerPermissao);
      el.hidden = !permitido;
      el.setAttribute("aria-hidden", permitido ? "false" : "true");
    });
    if (document.body.dataset.menuContexto === "painel-administrativo" && auth.permissoes?.["painel.acessar"] !== true && !(String(auth.tipo_usuario||"").toLowerCase()==="super_admin"&&auth.modo_suporte===true)) {
      window.location.replace("/public/views/agenda.html");
    }
  });
})();
