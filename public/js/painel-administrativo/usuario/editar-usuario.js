// /js/editar-usuario.js
(() => {
  "use strict";

  const ENDPOINT = "/public/api/api_central.php?path=painel/usuario/editar";

  const form = document.getElementById("formEditarUsuario");
  const modal = document.getElementById("modalEditarUsuario");
  const btnSalvar = document.getElementById("btnAtualizarUsuario");

  if (!form || !modal || !btnSalvar) return;

  const campos = {
    id_usuario: document.getElementById("u_e_id"),
    nome: document.getElementById("u_e_nome"),
    perfil: document.getElementById("u_e_perfil"),
    especialidadeWrap: document.getElementById("campo_especialidade_editar"),
    especialidade: document.getElementById("u_e_especialidade"),
    email: document.getElementById("u_e_email"),
    telefone: document.getElementById("u_e_tel"),
    senha: document.getElementById("u_e_senha"),
    senha2: document.getElementById("u_e_senha2"),
    status: document.getElementById("u_e_status"),
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

  function perfilSelecionadoTexto() {
    const sel = campos.perfil;
    if (!sel) return "";
    const opt = sel.options[sel.selectedIndex];
    return (opt?.textContent || "").trim().toLowerCase();
  }

  function toggleEspecialidade() {
    const mostrar = perfilSelecionadoTexto() === "profissional";

    if (!campos.especialidadeWrap) return;

    campos.especialidadeWrap.hidden = !mostrar;
    campos.especialidadeWrap.style.display = mostrar ? "" : "none";

    if (!mostrar && campos.especialidade) {
      campos.especialidade.value = "";
    }
  }

  function normalizarTelefone(v) {
    return String(v || "").replace(/\D+/g, "").slice(0, 11);
  }

  function aplicarMascaraTelefone(input) {
    if (!input) return;

    input.addEventListener("input", () => {
      let v = normalizarTelefone(input.value);

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

  function fecharModalUsuario() {
    if (typeof window.fecharModal === "function") {
      window.fecharModal(modal);
      return;
    }

    modal.classList.remove("ativo", "is-open");
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
      btnSalvar.textContent = btnSalvar.dataset.textoOriginal || "Atualizar";
    }
  }

  function montarFormData() {
    const fd = new FormData();
    fd.append("id_usuario", campos.id_usuario?.value?.trim() || "");
    fd.append("nome", campos.nome?.value?.trim() || "");
    fd.append("perfil", campos.perfil?.value?.trim() || "");
    fd.append("especialidade", campos.especialidade?.value?.trim() || "");
    fd.append("email", campos.email?.value?.trim() || "");
    fd.append("telefone", campos.telefone?.value?.trim() || "");
    fd.append("senha", campos.senha?.value || "");
    fd.append("senha2", campos.senha2?.value || "");
    fd.append("status", campos.status?.value?.trim() || "");

    return fd;
  }

  function aplicarErrosRetorno(fields) {
    if (!fields || typeof fields !== "object") return;

    const mapa = {
      u_e_id: "u_e_id",
      id_usuario: "u_e_id",

      u_e_nome: "u_e_nome",
      nome: "u_e_nome",

      u_e_perfil: "u_e_perfil",
      perfil: "u_e_perfil",

      u_e_especialidade: "u_e_especialidade",
      especialidade: "u_e_especialidade",

      u_e_email: "u_e_email",
      email: "u_e_email",

      u_e_tel: "u_e_tel",
      telefone: "u_e_tel",

      u_e_senha: "u_e_senha",
      senha: "u_e_senha",

      u_e_senha2: "u_e_senha2",
      senha2: "u_e_senha2",

      u_e_status: "u_e_status",
      u_status: "u_e_status",
      status: "u_e_status",
    };

    Object.entries(fields).forEach(([key, mensagem]) => {
      const campoId = mapa[key];
      if (campoId) {
        setErro(campoId, String(mensagem || ""));
      }
    });
  }

  async function enviarFormulario(event) {
    event.preventDefault();

    limparErros();
    bloquearFormulario(true);

    try {
      const formData = montarFormData();

      const resp = await fetch(ENDPOINT, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      let json = null;
      try {
        json = await resp.json();
      } catch {
        throw new Error("Resposta inválida do servidor.");
      }

      if (!resp.ok || !json?.ok) {
        aplicarErrosRetorno(json?.fields);

        showToast(
          "warning",
          "Atenção",
          json?.user_msg || "Não foi possível atualizar o usuário."
        );
        return;
      }

      showToast(
        "success",
        "Sucesso",
        json?.user_msg || "Usuário atualizado com sucesso.",
        1800
      );

      form.dispatchEvent(new CustomEvent("usuario:atualizado", {
        bubbles: true,
        detail: json?.data || null,
      }));

      window.dispatchEvent(new CustomEvent("usuario:atualizado", {
        detail: json?.data || null,
      }));

      fecharModalUsuario();

      setTimeout(() => {
        if (typeof window.recarregarListaUsuarios === "function") {
          window.recarregarListaUsuarios();
        } else if (window.ListaUsuario && typeof window.ListaUsuario.recarregar === "function") {
          window.ListaUsuario.recarregar();
        } else {
          window.location.reload();
        }
      }, 1800);

    } catch (err) {
      console.error("[editar-usuario] erro:", err);
      showToast(
        "danger",
        "Erro",
        err?.message || "Erro ao atualizar o usuário."
      );
    } finally {
      bloquearFormulario(false);
    }
  }

  campos.perfil?.addEventListener("change", toggleEspecialidade);
  aplicarMascaraTelefone(campos.telefone);
  form.addEventListener("submit", enviarFormulario);

  modal.addEventListener("transitionend", toggleEspecialidade);
  toggleEspecialidade();

  window.UsuarioEditarModal = {
    abrir(dados = {}) {
      limparErros();

      if (campos.id_usuario) campos.id_usuario.value = dados.id_usuario ?? dados.id ?? "";
      if (campos.nome) campos.nome.value = dados.nome ?? "";
      if (campos.perfil) campos.perfil.value = dados.id_perfil ?? dados.perfil ?? "";
      if (campos.especialidade) campos.especialidade.value = dados.especialidade ?? "";
      if (campos.email) campos.email.value = dados.email ?? "";
      if (campos.telefone) campos.telefone.value = dados.telefone ?? "";
      if (campos.senha) campos.senha.value = "";
      if (campos.senha2) campos.senha2.value = "";
      if (campos.status) campos.status.value = dados.status ?? "ativo";

      toggleEspecialidade();

      modal.classList.add("ativo");
      modal.setAttribute("aria-hidden", "false");

      setTimeout(() => {
        campos.nome?.focus();
      }, 80);
    },

    fechar() {
      fecharModalUsuario();
    }
  };
})();