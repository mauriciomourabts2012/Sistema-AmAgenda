(() => {
  "use strict";

  if (window.__RESETAR_CONFIG_AGENDA_EMPRESA_JS_INIT__) {
    console.warn("[ResetConfigAgendaEmpresa] Script já inicializado.");
    return;
  }

  window.__RESETAR_CONFIG_AGENDA_EMPRESA_JS_INIT__ = true;

  const BTN_ID = "btnResetarConfigAgenda";

  const API_URL =
    "/public/api/api_central.php?path=agenda/configuracao-profissional/resetar-padrao";

  const btn = document.getElementById(BTN_ID);

  if (!btn) {
    console.warn("[ResetConfigAgendaEmpresa] Botão não encontrado.");
    return;
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

  function criarAlerta({
    tipo = "success",
    titulo = "Mensagem",
    mensagem = "",
    botoes = []
  }) {
    const stack = getToastStack();

    const alertBox = document.createElement("div");
    alertBox.className = `ui-alert ui-alert--${tipo}`;

    const icones = {
      success: "✓",
      warning: "!",
      danger: "×",
      confirm: "?"
    };

    alertBox.innerHTML = `
      <div class="ui-alert__icon">${icones[tipo] || "!"}</div>

      <div class="ui-alert__content">
        <p class="ui-alert__title">${titulo}</p>
        <div class="ui-alert__msg">${mensagem}</div>
      </div>

      <div class="ui-alert__actions"></div>
    `;

    const actions = alertBox.querySelector(".ui-alert__actions");

    botoes.forEach((botao) => {
      const btnAcao = document.createElement("button");
      btnAcao.type = "button";
      btnAcao.className = `ui-alert__btn ${botao.primary ? "ui-alert__btn--primary" : ""}`;
      btnAcao.textContent = botao.texto || "OK";

      btnAcao.addEventListener("click", () => {
        fecharAlerta(alertBox, () => {
          if (typeof botao.onClick === "function") {
            botao.onClick();
          }
        });
      });

      actions.appendChild(btnAcao);
    });

    stack.appendChild(alertBox);

    return alertBox;
  }

  function fecharAlerta(alertBox, callback) {
    alertBox.classList.add("is-leaving");

    setTimeout(() => {
      alertBox.remove();

      if (typeof callback === "function") {
        callback();
      }
    }, 160);
  }

  function alertOk(mensagem, callback) {
    const alertBox = criarAlerta({
      tipo: "success",
      titulo: "Sucesso",
      mensagem
    });

    setTimeout(() => {
      fecharAlerta(alertBox, callback);
    }, 1600);
  }

  function alertErr(mensagem, callback) {
    criarAlerta({
      tipo: "danger",
      titulo: "Erro",
      mensagem,
      botoes: [
        {
          texto: "OK",
          primary: true,
          onClick: callback
        }
      ]
    });
  }

  function alertConfirm(mensagem, onConfirm) {
    criarAlerta({
      tipo: "confirm",
      titulo: "Confirmar ação",
      mensagem,
      botoes: [
        {
          texto: "Cancelar",
          primary: false
        },
        {
          texto: "Confirmar",
          primary: true,
          onClick: onConfirm
        }
      ]
    });
  }

  async function lerRespostaJson(response) {
    const texto = await response.text();

    try {
      return JSON.parse(texto);
    } catch (erro) {
      console.error("[ResetConfigAgendaEmpresa] Resposta inválida da API:", texto);

      return {
        ok: false,
        mensagem:
          "A API não retornou JSON válido. Verifique a rota no api_central.php ou erro no PHP."
      };
    }
  }

  async function resetarConfiguracoes() {
    alertConfirm(
      "Deseja restaurar o padrão da empresa para este profissional?",
      async () => {
        btn.disabled = true;
        btn.textContent = "Restaurando...";

        try {
          const response = await fetch(API_URL, {
            method: "POST",
            headers: {
              "Content-Type": "application/json"
            },
            credentials: "same-origin",
            body: JSON.stringify({})
          });

          const data = await lerRespostaJson(response);

          if (!response.ok || !data.ok) {
            throw new Error(
              data.mensagem || "Erro ao restaurar configurações."
            );
          }

          alertOk(
            data.mensagem || "Configurações restauradas com sucesso.",
            () => {
              window.location.reload();
            }
          );

        } catch (erro) {
          console.error("[ResetConfigAgendaEmpresa]", erro);

          alertErr(
            erro.message || "Erro ao restaurar configurações."
          );

        } finally {
          btn.disabled = false;
          btn.textContent = "Restaurar padrão";
        }
      }
    );
  }

  btn.addEventListener("click", resetarConfiguracoes);
})();