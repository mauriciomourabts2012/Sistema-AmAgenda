/* ==========================================================
   alterar_foto_perfil.js — Cliente | Alterar foto do perfil
   ✅ Otimização no navegador antes do envio, via componente compartilhado
      /public/js/componentes/processador-imagem.js
      (deve ser carregado ANTES deste script)
   ✅ Limite de entrada (original, 30 MB) separado do limite de envio (10 MB)
   ✅ Prévia do arquivo efetivamente enviado (URL blob: intacta)
   ✅ Foto anterior restaurada em caso de erro
========================================================== */
(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";
  const TIPOS_PERMITIDOS = [
    "image/jpeg",
    "image/png",
    "image/webp"
  ];
  const LIMITE_ENVIO = 10 * 1024 * 1024;    // limite do arquivo enviado (backend)
  const LIMITE_ENTRADA = 30 * 1024 * 1024;  // limite do original para tentativa de otimização

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

  function atualizarAvatares(url, opcoes = {}) {
    window.ClientePerfil.atualizarAvatares(url, opcoes);
  }

  function limparPreview() {
    if (previewAtual) {
      URL.revokeObjectURL(previewAtual);
      previewAtual = null;
    }
  }

  function validarArquivo(file, limite = LIMITE_ENVIO) {
    if (
      /^image\/hei[cf]/i.test(file.type || "") ||
      /\.(heic|heif)$/i.test(file.name || "")
    ) {
      throw new Error(
        "Fotos HEIC/HEIF ainda não são aceitas. Envie a imagem em JPG, PNG ou WebP."
      );
    }

    if (!TIPOS_PERMITIDOS.includes(file.type)) {
      throw new Error(
        "Use uma imagem JPG, PNG ou WebP."
      );
    }

    if (file.size <= 0 || file.size > limite) {
      throw new Error(
        `A imagem deve possuir no máximo ${Math.round(limite / (1024 * 1024))} MB.`
      );
    }
  }

  function obterProcessador() {
    const processador = window.AmAgendaImageProcessor;

    if (!processador || typeof processador.process !== "function") {
      console.error(
        "[cliente_perfil_alterar_foto] processador-imagem.js não foi carregado antes deste script."
      );
      throw new Error(
        "Não foi possível preparar a imagem para envio. Recarregue a página e tente novamente."
      );
    }

    return processador;
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
          Accept: "application/json",
          "X-CSRF-Token": String(window.__AUTH__?.csrf_token || "")
        },
        body: formData
      }
    );

    const json = await resposta.json().catch(() => null);

    if (resposta.status === 413) {
      throw new Error(
        "A requisição excede o limite aceito pelo servidor. A foto de perfil pode ter até 10 MB."
      );
    }

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

    // Foto persistida antes da troca, para restauração imediata em caso de erro.
    const fotoAnterior = String(
      window.ClientePerfilEstado?.foto_url || ""
    ).trim();

    try {
      validarArquivo(file, LIMITE_ENTRADA);

      envioEmAndamento = true;
      inputFoto.disabled = true;

      const processado = await obterProcessador().process(file, {
        maxBytes: LIMITE_ENVIO,
        maxInputBytes: LIMITE_ENTRADA,
        allowedTypes: TIPOS_PERMITIDOS
      });
      const arquivoEnvio = processado.file;

      // Revalidação do arquivo final: nunca enviar formato inválido ou acima de 10 MB.
      validarArquivo(arquivoEnvio, LIMITE_ENVIO);

      limparPreview();

      previewAtual = URL.createObjectURL(arquivoEnvio);

      /*
       * URL blob deve permanecer intacta.
       * Não aplicar cache-busting em blob: ou data:.
       */
      atualizarAvatares(previewAtual);

      const json = await enviarFoto(arquivoEnvio);

      const fotoPersistida = String(
        json.data?.foto_url || ""
      ).trim();

      limparPreview();

      if (fotoPersistida) {
        atualizarAvatares(fotoPersistida, {
          cacheBust: true
        });

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

      if (fotoAnterior) {
        atualizarAvatares(fotoAnterior);
      } else if (
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
