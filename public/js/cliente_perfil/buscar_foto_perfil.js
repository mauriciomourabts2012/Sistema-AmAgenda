(() => {
  "use strict";

  const API = "/public/api/api_central.php";
  const LOGIN = "/public/views/login-cliente.php";
  const AVATAR_PADRAO = "/public/imagens/avatar-default.png";

  const nomePerfil = document.getElementById("perfilNomeUsuario");
  const avatares = document.querySelectorAll("[data-avatar-usuario]");

  window.ClientePerfilEstado = window.ClientePerfilEstado || {
    tem_senha: null,
    nome: "",
    foto_url: AVATAR_PADRAO
  };

  function urlComCache(url) {
    const valor = String(url || "").trim();

    if (!valor) {
      return AVATAR_PADRAO;
    }

    if (
      valor.startsWith("blob:") ||
      valor.startsWith("data:")
    ) {
      return valor;
    }

    const separador = valor.includes("?") ? "&" : "?";

    return `${valor}${separador}_t=${Date.now()}`;
  }

  function atualizarAvatares(url) {
    const foto = urlComCache(url || AVATAR_PADRAO);

    avatares.forEach(img => {
      img.src = foto;

      img.onerror = () => {
        img.onerror = null;
        img.src = AVATAR_PADRAO;
      };
    });
  }

  async function requisitar(caminho) {
    const resposta = await fetch(
      `${API}?path=${encodeURIComponent(caminho)}`,
      {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json"
        }
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
        "Não foi possível carregar seu perfil."
      );
    }

    return json;
  }

  async function carregarPerfil() {
    try {
      const json = await requisitar(
        "cliente/perfil/buscar-foto"
      );

      const dados = json.data || {};

      const nome = String(
        dados.nome || "Cliente"
      ).trim();

      const foto = String(
        dados.foto_url || AVATAR_PADRAO
      ).trim();

      const temSenha = dados.tem_senha === true;

      window.ClientePerfilEstado = {
        tem_senha: temSenha,
        nome,
        foto_url: foto
      };

      if (nomePerfil) {
        nomePerfil.textContent = nome;
      }

      atualizarAvatares(foto);

      document.dispatchEvent(
        new CustomEvent(
          "amagenda:cliente-perfil-carregado",
          {
            detail: {
              ...window.ClientePerfilEstado
            }
          }
        )
      );

      return window.ClientePerfilEstado;

    } catch (erro) {

      if (nomePerfil) {
        nomePerfil.textContent = "Cliente";
      }

      atualizarAvatares(AVATAR_PADRAO);

      console.error(
        "[cliente_perfil_buscar_foto]",
        erro
      );

      throw erro;
    }
  }

  window.ClientePerfil = window.ClientePerfil || {};

  window.ClientePerfil.carregar = carregarPerfil;
  window.ClientePerfil.atualizarAvatares = atualizarAvatares;
  window.ClientePerfil.urlComCache = urlComCache;

  document.addEventListener(
    "DOMContentLoaded",
    () => {
      carregarPerfil().catch(() => {});
    },
    { once: true }
  );
})();