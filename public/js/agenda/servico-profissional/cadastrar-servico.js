/* ==========================================================
   cadastrar-servico.js
========================================================== */
(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const API_URL = "/public/api/api_central.php?path=agenda/servico-profissional/cadastrar";

    const btn = document.getElementById("cfg_btn_add_servico");
    const nome = document.getElementById("cfg_servico_nome");
    const descricao = document.getElementById("cfg_servico_descricao");
    const duracao = document.getElementById("cfg_servico_duracao");
    const valor = document.getElementById("cfg_servico_valor");

    if (!btn) {
      console.warn("[cadastrar-servico] Botão cfg_btn_add_servico não encontrado.");
      return;
    }

    function texto(v) {
      return String(v ?? "").trim();
    }

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

    function msgOk(mensagem, callback) {
      mostrarMensagem("success", mensagem, callback);
    }

    function msgWarn(mensagem, callback) {
      mostrarMensagem("warning", mensagem, callback);
    }

    function msgErro(mensagem, callback) {
      mostrarMensagem("danger", mensagem, callback);
    }

    function cssEscape(v) {
      if (window.CSS && typeof window.CSS.escape === "function") {
        return window.CSS.escape(v);
      }

      return String(v).replace(/"/g, '\\"');
    }

    function valorDecimalBR(v) {
      let raw = texto(v);

      if (!raw) return null;

      raw = raw.replace(/R\$/gi, "").replace(/\s+/g, "");

      if (!/^\d{1,8}([,.]\d{1,2})?$/.test(raw)) {
        return null;
      }

      raw = raw.replace(",", ".");

      const n = Number(raw);
      if (!Number.isFinite(n) || n <= 0 || n > 99999999.99) return null;

      return n.toFixed(2);
    }

    function campoContainer(campo) {
      return campo?.closest?.(".modal-campo") || null;
    }

    function erroEl(campo) {
      if (!campo?.id) return null;
      return document.querySelector(`[data-erro-for="${cssEscape(campo.id)}"]`);
    }

    function setErro(campo, mensagem) {
      const box = campoContainer(campo);
      const msg = erroEl(campo);

      if (box) box.classList.add("erro");

      if (msg) {
        msg.textContent = mensagem || "";
        msg.classList.add("ativo");
      }
    }

    function limparErro(campo) {
      const box = campoContainer(campo);
      const msg = erroEl(campo);

      if (box) box.classList.remove("erro");

      if (msg) {
        msg.textContent = "";
        msg.classList.remove("ativo");
      }
    }

    function limparErros() {
      [nome, descricao, duracao, valor].forEach(limparErro);
    }

    function focarComAviso(campo, mensagem) {
      setErro(campo, mensagem);

      msgWarn(mensagem, () => {
        campo?.focus();
      });
    }

    function limparFormulario() {
      if (nome) nome.value = "";
      if (descricao) descricao.value = "";
      if (duracao) duracao.value = "";
      if (valor) valor.value = "";

      limparErros();
    }

    function setLoading(on) {
      btn.disabled = !!on;
      btn.dataset.textOriginal = btn.dataset.textOriginal || btn.textContent || "Adicionar";
      btn.textContent = on ? "Salvando..." : btn.dataset.textOriginal;
    }

    function aplicarErrosBackend(fields) {
      let primeiroCampo = null;

      Object.entries(fields || {}).forEach(([id, mensagem]) => {
        const campo = document.getElementById(id);
        if (!campo) return;

        setErro(campo, mensagem);

        if (!primeiroCampo) {
          primeiroCampo = campo;
        }
      });

      return { primeiroCampo };
    }

    function atualizarListaServicos(data) {
      document.dispatchEvent(new CustomEvent("servico:cadastrado", {
        detail: data?.data || null,
      }));

      document.dispatchEvent(new CustomEvent("agenda:servicos:recarregar", {
        detail: {
          origem: "cadastro",
          servico: data?.data || null,
        },
      }));

      if (
        window.ListaServicosConfig &&
        typeof window.ListaServicosConfig.recarregar === "function"
      ) {
        window.ListaServicosConfig.recarregar({ force: true });
      }
    }

    async function cadastrarServico() {
      limparErros();

      const nomeVal = texto(nome?.value);
      const descVal = texto(descricao?.value);
      const durVal = texto(duracao?.value);
      const valorVal = valorDecimalBR(valor?.value);

      if (!nomeVal) {
        focarComAviso(nome, "Informe o nome do serviço.");
        return;
      }

      if (nomeVal.length < 2) {
        focarComAviso(nome, "O nome deve ter no mínimo 2 caracteres.");
        return;
      }

      if (nomeVal.length > 120) {
        focarComAviso(nome, "O nome deve ter no máximo 120 caracteres.");
        return;
      }

      if (descVal.length > 220) {
        focarComAviso(descricao, "A descrição deve ter no máximo 220 caracteres.");
        return;
      }

      if (!durVal) {
        focarComAviso(duracao, "Selecione a duração do serviço.");
        return;
      }

      if (valorVal === null) {
        focarComAviso(valor, "Informe um valor válido. Use apenas números, vírgula ou ponto.");
        return;
      }

      const fd = new FormData();
      fd.append("nome", nomeVal);
      fd.append("descricao", descVal);
      fd.append("duracao_min", durVal);
      fd.append("valor", valorVal);
      fd.append("status", "ativo");

      setLoading(true);

      try {
        const resp = await fetch(API_URL, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: {
            "X-Requested-With": "XMLHttpRequest",
          },
        });

        const text = await resp.text();
        let data = null;

        try {
          data = text ? JSON.parse(text) : null;
        } catch (e) {
          data = null;
        }

        if (!resp.ok || !data?.ok) {
          const erros = aplicarErrosBackend(data?.fields || {});
          const mensagem = data?.user_msg || "Não foi possível cadastrar o serviço.";

          const mostrarMensagemErro = resp.status >= 500 ? msgErro : msgWarn;

          mostrarMensagemErro(mensagem, () => {
            erros.primeiroCampo?.focus();
          });

          return;
        }

        msgOk(data.user_msg || "Serviço cadastrado com sucesso.", () => {
          limparFormulario();
          atualizarListaServicos(data);
        });
      } catch (err) {
        console.error("[cadastrar-servico]", err);

        msgErro("Erro de comunicação ao cadastrar serviço.");
      } finally {
        setLoading(false);
      }
    }

    btn.addEventListener("click", cadastrarServico);

    [nome, descricao, duracao, valor].forEach((campo) => {
      campo?.addEventListener("input", () => limparErro(campo));
      campo?.addEventListener("change", () => limparErro(campo));
    });

    console.log("[cadastrar-servico] JS carregado e botão conectado.");
  });
})();