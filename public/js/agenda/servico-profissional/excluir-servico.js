/* ==========================================================
   excluir-servico.js
   - Exclui serviço do profissional logado
========================================================== */
(() => {
  "use strict";

  if (window.__EXCLUIR_SERVICO_JS_INIT__) {
    console.warn("[excluir-servico] Script já inicializado.");
    return;
  }

  window.__EXCLUIR_SERVICO_JS_INIT__ = true;

  document.addEventListener("DOMContentLoaded", () => {
    const API_URL = "/public/api/api_central.php?path=agenda/servico-profissional/excluir";
    const MODAL_ID = "modalConfiguracoesAgenda";

    const modal = document.getElementById(MODAL_ID);

    if (!modal) {
      console.warn("[excluir-servico] Modal modalConfiguracoesAgenda não encontrado.");
      return;
    }

    let excluindo = false;

    function escapeHtml(v) {
      return String(v ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    function getToastStack() {
      let stack = document.querySelector(".ui-toast-stack");

      if (!stack) {
        stack = document.createElement("div");
        stack.className = "ui-toast-stack";
        document.body.appendChild(stack);
      }

      return stack;
    }

    function fecharAlerta(alerta, callback) {
      if (!alerta || !alerta.isConnected) {
        if (typeof callback === "function") callback();
        return;
      }

      alerta.classList.add("is-leaving");

      setTimeout(() => {
        if (alerta.isConnected) alerta.remove();
        if (typeof callback === "function") callback();
      }, 150);
    }

    function mostrarMensagem(tipo, mensagem, callback) {
      const config = {
        success: {
          classe: "ui-alert--success",
          titulo: "Sucesso",
          icone: "✓",
          botao: false,
          timeout: 2200,
        },
        warning: {
          classe: "ui-alert--warning",
          titulo: "Atenção",
          icone: "!",
          botao: true,
          timeout: null,
        },
        danger: {
          classe: "ui-alert--danger",
          titulo: "Erro",
          icone: "×",
          botao: true,
          timeout: null,
        },
        confirm: {
          classe: "ui-alert--confirm",
          titulo: "Confirmação",
          icone: "?",
          botao: true,
          timeout: null,
        },
      };

      const cfg = config[tipo] || config.warning;
      const stack = getToastStack();

      const alerta = document.createElement("div");
      alerta.className = `ui-alert ${cfg.classe}`;
      alerta.setAttribute("role", "alert");
      alerta.setAttribute("aria-live", tipo === "success" ? "polite" : "assertive");

      alerta.innerHTML = `
        <div class="ui-alert__icon" aria-hidden="true">${cfg.icone}</div>

        <div class="ui-alert__content">
          <p class="ui-alert__title">${escapeHtml(cfg.titulo)}</p>
          <div class="ui-alert__msg">${escapeHtml(mensagem)}</div>
        </div>

        <div class="ui-alert__actions">
          ${
            cfg.botao
              ? `<button type="button" class="ui-alert__btn ui-alert__btn--primary">OK</button>`
              : ``
          }
        </div>
      `;

      stack.appendChild(alerta);

      if (cfg.botao) {
        const btnOk = alerta.querySelector(".ui-alert__btn");

        btnOk?.addEventListener("click", () => {
          fecharAlerta(alerta, callback);
        });

        btnOk?.focus();
        return;
      }

      setTimeout(() => {
        fecharAlerta(alerta, callback);
      }, cfg.timeout);
    }

    function mostrarConfirmacao(mensagem, onConfirm) {
      const stack = getToastStack();

      const alerta = document.createElement("div");
      alerta.className = "ui-alert ui-alert--confirm";
      alerta.setAttribute("role", "alert");
      alerta.setAttribute("aria-live", "assertive");

      alerta.innerHTML = `
        <div class="ui-alert__icon" aria-hidden="true">?</div>

        <div class="ui-alert__content">
          <p class="ui-alert__title">Confirmação</p>
          <div class="ui-alert__msg">${escapeHtml(mensagem)}</div>
        </div>

        <div class="ui-alert__actions">
          <button type="button" class="ui-alert__btn" data-alert-cancelar>Cancelar</button>
          <button type="button" class="ui-alert__btn ui-alert__btn--primary" data-alert-confirmar>Confirmar</button>
        </div>
      `;

      stack.appendChild(alerta);

      const btnCancelar = alerta.querySelector("[data-alert-cancelar]");
      const btnConfirmar = alerta.querySelector("[data-alert-confirmar]");

      btnCancelar?.addEventListener("click", () => {
        fecharAlerta(alerta);
      });

      btnConfirmar?.addEventListener("click", () => {
        fecharAlerta(alerta, onConfirm);
      });

      btnConfirmar?.focus();
    }

    function msgOk(mensagem, callback) {
      mostrarMensagem("success", mensagem, callback);
    }

    function msgWarn(mensagem, callback) {
      mostrarMensagem("warning", mensagem, callback);
    }

    function msgErro(mensagem, callback) {
      mostrarMensagem("danger", mensagem, callback);
    }

    async function lerRespostaJson(response) {
      const text = await response.text();

      try {
        return text ? JSON.parse(text) : null;
      } catch (err) {
        console.error("[excluir-servico] Resposta inválida da API:", text);
        return null;
      }
    }

    async function excluirServico(idServico) {
      const fd = new FormData();
      fd.append("id_servico", String(idServico));

      const resp = await fetch(API_URL, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      const data = await lerRespostaJson(resp);

      if (!resp.ok || !data?.ok) {
        throw new Error(
          data?.user_msg ||
          data?.mensagem ||
          "Não foi possível excluir o serviço."
        );
      }

      return data;
    }

    function setLoading(btn, on) {
      if (!btn) return;

      btn.disabled = !!on;
      btn.dataset.titleOriginal = btn.dataset.titleOriginal || btn.getAttribute("title") || "Excluir";
      btn.setAttribute("title", on ? "Excluindo..." : btn.dataset.titleOriginal);
    }

    function removerLinhaServico(tr) {
      if (!tr) return;

      tr.remove();

      document.dispatchEvent(new CustomEvent("servico:excluido", {
        detail: {
          id_servico: Number(tr.dataset.id || 0),
        },
      }));

      window.dispatchEvent(new CustomEvent("agenda:servico-excluido", {
        detail: {
          id_servico: Number(tr.dataset.id || 0),
        },
      }));
    }

    function aoClicarExcluir(e) {
      const btnExcluir = e.target.closest?.('button[data-acao="excluir"]');
      if (!btnExcluir) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      if (excluindo || btnExcluir.disabled) return;

      const tr = btnExcluir.closest("tr[data-id]");
      const idServico = Number(tr?.dataset?.id || 0);

      if (!idServico) {
        msgWarn("Serviço inválido.");
        return;
      }

      mostrarConfirmacao("Deseja excluir este serviço?", async () => {
        excluindo = true;
        setLoading(btnExcluir, true);

        try {
          const data = await excluirServico(idServico);

          msgOk(data.user_msg || "Serviço excluído com sucesso.", () => {
            removerLinhaServico(tr);
          });
        } catch (err) {
          console.error("[excluir-servico]", err);

          msgErro(err.message || "Erro ao excluir serviço.", () => {
            setLoading(btnExcluir, false);
          });
        } finally {
          excluindo = false;
        }
      });
    }

    modal.addEventListener("click", aoClicarExcluir, true);

    console.log("[excluir-servico] JS carregado e conectado.");
  });
})();