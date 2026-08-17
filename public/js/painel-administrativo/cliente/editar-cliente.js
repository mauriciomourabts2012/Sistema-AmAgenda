// /js/editar_cliente.js
(() => {
  "use strict";

  const ENDPOINT = "/public/api/api_central.php?path=painel/cliente/editar";

  const form = document.getElementById("formEditarCliente");
  const btnSalvar = document.getElementById("btnAtualizarCliente");
  const modal = document.getElementById("modalEditarCliente");

  if (!form || !btnSalvar || !modal) return;

  const campos = {
    e_cli_id: document.getElementById("e_cli_id"),
    e_cli_nome: document.getElementById("e_cli_nome"),
    e_cli_telefone: document.getElementById("e_cli_telefone"),
    e_cli_email: document.getElementById("e_cli_email"),
    e_cli_status: document.getElementById("e_cli_status"),
    e_cli_obs: document.getElementById("e_cli_obs"),
  };

  function getToastStack() {
    let el = document.getElementById("toastStack");
    if (el) return el;

    el = document.createElement("div");
    el.id = "toastStack";
    el.className = "ui-toast-stack";
    document.body.appendChild(el);
    return el;
  }

  function escapeHtml(valor) {
    return String(valor ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function showToast(tipo, titulo, msg, tempo = 3500) {
    const stack = getToastStack();

    const mapaClasse = {
      success: "ui-alert--success",
      warning: "ui-alert--warning",
      danger: "ui-alert--danger",
      info: "ui-alert--confirm",
    };

    const mapaIcone = {
      success: "✅",
      warning: "⚠️",
      danger: "❌",
      info: "ℹ️",
    };

    const toast = document.createElement("div");
    toast.className = `ui-alert ${mapaClasse[tipo] || "ui-alert--confirm"}`;
    toast.innerHTML = `
      <div class="ui-alert__icon">${mapaIcone[tipo] || "ℹ️"}</div>
      <div class="ui-alert__content">
        <div class="ui-alert__title">${escapeHtml(titulo)}</div>
        <div class="ui-alert__msg">${escapeHtml(msg)}</div>
      </div>
    `;

    stack.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = "0";
      toast.style.transform = "translateY(-6px)";
      setTimeout(() => toast.remove(), 180);
    }, tempo);
  }

  function limparErros() {
    form.querySelectorAll(".msg-erro").forEach((el) => {
      el.textContent = "";
    });

    form.querySelectorAll(".campo-erro, .is-invalid").forEach((el) => {
      el.classList.remove("campo-erro", "is-invalid");
    });
  }

  function setErro(campoId, mensagem) {
    const campo = document.getElementById(campoId);
    const erro = form.querySelector(`[data-erro-for="${campoId}"]`);

    if (campo) {
      campo.classList.add("campo-erro", "is-invalid");
    }

    if (erro) {
      erro.textContent = mensagem || "";
    }
  }

  function aplicarMascaraTelefone(input) {
    if (!input) return;

    input.addEventListener("input", () => {
      let v = String(input.value || "").replace(/\D+/g, "");

      if (v.length > 13) v = v.slice(0, 13);

      if (v.length <= 10) {
        v = v.replace(/^(\d{0,2})(\d{0,4})(\d{0,4}).*/, (_, a, b, c) => {
          let out = "";
          if (a) out += `(${a}`;
          if (a && a.length === 2) out += `) `;
          if (b) out += b;
          if (c) out += `-${c}`;
          return out;
        });
      } else {
        v = v.replace(/^(\d{0,2})(\d{0,5})(\d{0,4}).*/, (_, a, b, c) => {
          let out = "";
          if (a) out += `(${a}`;
          if (a && a.length === 2) out += `) `;
          if (b) out += b;
          if (c) out += `-${c}`;
          return out;
        });
      }

      input.value = v;
    });
  }

  function fecharModalCliente() {
    modal.classList.remove("ativo");
    modal.setAttribute("aria-hidden", "true");
  }

  function bloquearFormulario(bloquear) {
    btnSalvar.disabled = bloquear;

    if (bloquear) {
      if (!btnSalvar.dataset.textoOriginal) {
        btnSalvar.dataset.textoOriginal = btnSalvar.textContent;
      }
      btnSalvar.textContent = "Salvando...";
    } else {
      btnSalvar.textContent = btnSalvar.dataset.textoOriginal || "Salvar";
    }
  }

  async function enviarFormulario(e) {
    e.preventDefault();

    limparErros();
    bloquearFormulario(true);

    try {
      const formData = new FormData(form);

      const resp = await fetch(ENDPOINT, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest"
        }
      });

      let json = null;
      try {
        json = await resp.json();
      } catch {
        throw new Error("Resposta inválida do servidor.");
      }

      if (!resp.ok || !json?.ok) {
        if (json?.fields && typeof json.fields === "object") {
          Object.entries(json.fields).forEach(([campoId, mensagem]) => {
            setErro(campoId, mensagem);
          });
        }

        showToast(
          "warning",
          "Atenção",
          json?.user_msg || "Não foi possível atualizar o cliente."
        );
        return;
      }

      showToast(
        "success",
        "Sucesso",
        json?.user_msg || "Cliente atualizado com sucesso.",
        1800
      );

      fecharModalCliente();

      setTimeout(() => {
        window.location.reload();
      }, 1500);

    } catch (err) {
      console.error("[editar_cliente] erro:", err);
      showToast(
        "danger",
        "Erro",
        err?.message || "Erro ao atualizar cliente."
      );
    } finally {
      bloquearFormulario(false);
    }
  }

  aplicarMascaraTelefone(campos.e_cli_telefone);
  form.addEventListener("submit", enviarFormulario);

  window.ClienteEditar = {
    abrir(dados = {}) {
      limparErros();

      campos.e_cli_id.value = dados.id_cliente ?? "";
      campos.e_cli_nome.value = dados.nome_completo ?? dados.nome ?? "";
      campos.e_cli_telefone.value = dados.whatsapp_celular ?? dados.telefone ?? "";
      campos.e_cli_email.value = dados.email ?? "";
      campos.e_cli_status.value = dados.status ?? "ativo";
      campos.e_cli_obs.value = dados.observacao ?? "";

      modal.classList.add("ativo");
      modal.setAttribute("aria-hidden", "false");

      setTimeout(() => {
        campos.e_cli_nome?.focus();
      }, 80);
    },

    fechar() {
      fecharModalCliente();
    }
  };
})();