(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";

  const inputFoto = document.getElementById("perfil_foto");

  if (!inputFoto) return;

  let previewAtual = null;
  let envioEmAndamento = false;

  function mensagem(tipo, texto) {
    const sistema = window.MensagemSistema;

    if (
      sistema &&
      typeof sistema[tipo] === "function"
    ) {
      sistema[tipo](texto);
      return;
    }

    if (tipo === "erro") {
      console.error(texto);
    } else {
      console.log(texto);
    }
  }

  function atualizarAvatares(url) {
    if (
      window.ClientePerfil &&
      typeof window.ClientePerfil.atualizarAvatares === "function"
    ) {
      window.ClientePerfil.atualizarAvatares(url);
      return;
    }

    document
      .querySelectorAll("[data-avatar-usuario]")
      .forEach(img => {
        img.src = url;
      });
  }

  function limparPreview() {
    if (previewAtual) {
      URL.revokeObjectURL(previewAtual);
      previewAtual = null;
    }
  }

  function validarArquivo(file) {
    const tiposPermitidos = [
      "image/jpeg",
      "image/png",
      "image/webp"
    ];

    const limite = 5 * 1024 * 1024;

    if (!tiposPermitidos.includes(file.type)) {
      throw new Error(
        "Use uma imagem JPG, PNG ou WebP."
      );
    }

    if (file.size <= 0 || file.size > limite) {
      throw new Error(
        "A imagem deve possuir no máximo 5 MB."
      );
    }
  }

  async function enviarFoto(file) {
    const formData = new FormData();

    formData.append(
      "perfil_foto",
      file,
      file.name
    );

    const resposta = await fetch(
      `${API}?path=${encodeURIComponent(
        "cliente/perfil/alterar-foto"
      )}`,
      {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json"
        },
        body: formData
      }
    );

    const json = await resposta.json().catch(() => null);

    if (resposta.status === 401) {
      window.location.replace(LOGIN);
      throw new Error("Sessão expirada.");
    }

    if (!resposta.ok || !json || json.ok !== true) {
      throw new Error(
        json?.user_msg ||
        "Não foi possível atualizar a foto."
      );
    }

    return json;
  }

  inputFoto.addEventListener("change", async () => {
    const file = inputFoto.files?.[0];

    if (!file || envioEmAndamento) {
      return;
    }

    try {
      validarArquivo(file);

      limparPreview();

      previewAtual = URL.createObjectURL(file);

      /*
       * URL blob deve permanecer intacta.
       * Não aplicar cache-busting em blob: ou data:.
       */
      atualizarAvatares(previewAtual);

      envioEmAndamento = true;
      inputFoto.disabled = true;

      const json = await enviarFoto(file);

      const fotoPersistida = String(
        json.data?.foto_url || ""
      ).trim();

      limparPreview();

      if (fotoPersistida) {
        atualizarAvatares(fotoPersistida);

        if (window.ClientePerfilEstado) {
          window.ClientePerfilEstado.foto_url =
            fotoPersistida;
        }
      }

      mensagem(
        "sucesso",
        json.user_msg || "Foto atualizada com sucesso."
      );

      document.dispatchEvent(
        new CustomEvent(
          "amagenda:cliente-foto-atualizada",
          {
            detail: {
              foto_url: fotoPersistida
            }
          }
        )
      );

    } catch (erro) {

      limparPreview();

      if (
        window.ClientePerfil &&
        typeof window.ClientePerfil.carregar === "function"
      ) {
        window.ClientePerfil
          .carregar()
          .catch(() => {});
      }

      mensagem(
        "erro",
        erro.message ||
        "Não foi possível atualizar a foto."
      );

    } finally {
      envioEmAndamento = false;
      inputFoto.disabled = false;
      inputFoto.value = "";
    }
  });

  window.addEventListener(
    "beforeunload",
    limparPreview
  );
})();