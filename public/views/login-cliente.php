<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * O login do cliente sempre nasce a partir do contexto de uma empresa.
 * A empresa nunca deve ser escolhida pelo navegador durante a autenticação.
 */
if (empty($_SESSION['empresa_id']) || (int)$_SESSION['empresa_id'] <= 0) {
    header('Location: /public/views/link-empresa-invalido.html');
    exit;
}

$empresaId = (int)$_SESSION['empresa_id'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="AmAgenda • Login do Cliente" />
  <meta name="theme-color" content="#000f50" />

  <title>AmAgenda • Login Cliente</title>

  <link
    rel="stylesheet"
    href="../css/login/login-web.css?v=20260818_15"
  />

  <link
    rel="stylesheet"
    href="../css/login/login-mobile.css?v=20260818_5"
  />

  <link
    rel="stylesheet"
    href="../css/login/login-cliente.css?v=20260818_3"
  />

  <link
    rel="icon"
    href="/public/imagens/logo-menu.png"
    type="image/png"
  />

  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap"
    rel="stylesheet"
  />

  <link
    rel="manifest"
    href="/manifest.json"
  />
</head>

<body>

<main>

  <div class="login-fundo">

    <div class="login">

      <div class="login-container">

        <!-- ======================================================
             ÁREA VISUAL
        ======================================================= -->

        <div class="imagem-left">

          <img
            src="../imagens/logo.png"
            alt="Imagem institucional"
            class="imagem-desktop"
            data-identidade-login
          />

          <div
            class="login-hero-brand"
            aria-hidden="true"
          >
            <img
              src="/public/imagens/logo-menu.png"
              alt=""
              data-identidade-login-logo
            />

            <b data-identidade-login-nome>
              AmAgenda
            </b>
          </div>

          <div
            class="login-hero-texto"
            aria-hidden="true"
          >
            <strong>
              Organize sua agenda,<br>
              <em>encante</em> seus clientes.
            </strong>

            <span>
              Simples, rápido e feito para<br>
              o seu negócio.
            </span>
          </div>

          <div
            class="login-hero-beneficios"
            aria-hidden="true"
          >

            <div>
              <i>
                <svg viewBox="0 0 24 24" fill="none">
                  <rect
                    x="4"
                    y="5"
                    width="16"
                    height="15"
                    rx="2"
                  />
                  <path
                    d="M8 3v4M16 3v4M4 9h16M8 13h2M14 13h2M8 17h2M14 17h2"
                  />
                </svg>
              </i>

              <span>
                <b>Agenda organizada</b>
                <small>
                  Mais controle do seu dia a dia.
                </small>
              </span>
            </div>

            <div>
              <i>
                <svg viewBox="0 0 24 24" fill="none">
                  <path
                    d="M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM17 11a4 4 0 0 1 4 4v2M16 3.2a4 4 0 0 1 0 7.6"
                  />
                </svg>
              </i>

              <span>
                <b>Clientes em um só lugar</b>
                <small>
                  Histórico, contatos e muito mais.
                </small>
              </span>
            </div>

            <div>
              <i>
                <svg viewBox="0 0 24 24" fill="none">
                  <path
                    d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"
                  />
                </svg>
              </i>

              <span>
                <b>Confirmação automática</b>
                <small>
                  Reduza faltas e melhore sua agenda.
                </small>
              </span>
            </div>

            <div>
              <i>
                <svg viewBox="0 0 24 24" fill="none">
                  <path
                    d="M4 20V10M10 20V4M16 20v-7M22 20H2M3 9l6-5 6 5 6-6"
                  />
                </svg>
              </i>

              <span>
                <b>Gestão simplificada</b>
                <small>
                  Relatórios e insights em poucos cliques.
                </small>
              </span>
            </div>

          </div>

        </div>

        <!-- ======================================================
             LOGIN DO CLIENTE
        ======================================================= -->

        <div class="login-dados-right">

          <header class="login-marca">

            <img
              src="/public/imagens/logo-menu.png"
              alt=""
              class="login-marca-logo"
              data-identidade-login-logo
            />

            <div class="login-marca-textos">

              <strong data-identidade-login-nome>
                AmAgenda
              </strong>

            </div>

          </header>

          <div class="login-boas-vindas">

            <h2 class="h2-titulo">
              Bem-vindo(a)!
            </h2>

            <p>
              Escolha uma opção para continuar
            </p>

          </div>

          <!-- ====================================================
               STEP 1
          ===================================================== -->

          <section
            class="step is-active"
            data-step="1"
          >

            <p class="cliente-sub">
              Escolha uma opção abaixo para continuar.
            </p>

            <button
              class="botao-entrar"
              id="btnContinuarTelefone"
              type="button"
            >

              <svg
                class="login-icone"
                aria-hidden="true"
                viewBox="0 0 24 24"
                fill="none"
              >
                <path
                  d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.62 2.63a2 2 0 0 1-.45 2.11L8 9.73a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.85.29 1.73.5 2.63.62A2 2 0 0 1 22 16.92Z"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>

              Continuar com telefone

            </button>

            <div
              class="login-divisor"
              aria-hidden="true"
            >
              <span>ou</span>
            </div>

            <a
              href="../views/login-empresa.php"
              class="cliente-link"
            >

              <svg
                class="login-icone"
                aria-hidden="true"
                viewBox="0 0 24 24"
                fill="none"
              >
                <rect
                  x="4"
                  y="10"
                  width="16"
                  height="11"
                  rx="2"
                  stroke="currentColor"
                  stroke-width="2"
                />

                <path
                  d="M8 10V7a4 4 0 0 1 8 0v3"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                />
              </svg>

              Acesso restrito • login com senha

            </a>

            <p class="cliente-termos">
              Ao continuar você aceita os
              <a
                class="cliente-link-a"
                href="#"
                target="_blank"
                rel="noopener"
              >
                termos de uso
              </a>.
            </p>

            <p
              class="mensagem"
              id="msgStep1"
              role="alert"
              aria-live="polite"
            ></p>

          </section>

          <!-- ====================================================
               STEP 2
          ===================================================== -->

          <section
            class="step"
            data-step="2"
          >

            <button
              class="cliente-voltar"
              id="btnVoltarStep2"
              type="button"
              aria-label="Voltar"
            >
              ←
            </button>

            <h3 class="cliente-titulo">
              Informe seu telefone
            </h3>

            <div class="cliente-linha-telefone">

              <div class="cliente-ddi">

                <span
                  class="cliente-flag"
                  aria-hidden="true"
                >
                  🇧🇷
                </span>

                <select
                  id="ddi"
                  class="cliente-select"
                  aria-label="DDI"
                >
                  <option
                    value="55"
                    selected
                  >
                    +55
                  </option>
                </select>

              </div>

              <input
                type="tel"
                id="telefone"
                class="cliente-input"
                placeholder="(__) _____-____"
                inputmode="numeric"
                autocomplete="tel"
                maxlength="15"
              />

            </div>

            <p class="cliente-info">
              Informe seu telefone e clique em verificar.
              Será enviado um SMS com um código para validar seu número.
            </p>

            <button
              class="botao-entrar"
              id="btnEnviarCodigo"
              type="button"
              disabled
            >
              Verificar
            </button>

            <p
              class="mensagem"
              id="msgStep2"
              role="alert"
              aria-live="polite"
            ></p>

          </section>

          <!-- ====================================================
               STEP 3
          ===================================================== -->

          <section
            class="step"
            data-step="3"
          >

            <button
              class="cliente-voltar"
              id="btnVoltarStep3"
              type="button"
              aria-label="Voltar"
            >
              ←
            </button>

            <h3 class="cliente-titulo">
              Confirme seu telefone
            </h3>

            <input
              type="text"
              id="codigo"
              class="cliente-input"
              placeholder="Código de verificação"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
            />

            <div
              class="cliente-info"
              id="infoCodigo"
            ></div>

            <button
              class="botao-entrar"
              id="btnValidarCodigo"
              type="button"
              disabled
            >
              Validar Código
            </button>

            <button
              class="cliente-link"
              id="btnReenviar"
              type="button"
            >
              Reenviar código
            </button>

            <p
              class="mensagem"
              id="msgStep3"
              role="alert"
              aria-live="polite"
            ></p>

          </section>

        </div>

      </div>

    </div>

  </div>

</main>

<script>
window.AMAGENDA_EMPRESA_ID = <?php echo (int)$empresaId; ?>;
</script>

<script>
(() => {
  "use strict";

  const ENDPOINT_CLIENTE =
    "/api/api_central.php?path=_auth/cliente-login";

  const $ = (selector) =>
    document.querySelector(selector);

  const steps =
    Array.from(document.querySelectorAll(".step"));

  const btnContinuarTelefone =
    $("#btnContinuarTelefone");

  const btnVoltarStep2 =
    $("#btnVoltarStep2");

  const ddi =
    $("#ddi");

  const inTelefone =
    $("#telefone");

  const btnEnviarCodigo =
    $("#btnEnviarCodigo");

  const btnVoltarStep3 =
    $("#btnVoltarStep3");

  const inCodigo =
    $("#codigo");

  const btnValidarCodigo =
    $("#btnValidarCodigo");

  const btnReenviar =
    $("#btnReenviar");

  const infoCodigo =
    $("#infoCodigo");

  const msg1 =
    $("#msgStep1");

  const msg2 =
    $("#msgStep2");

  const msg3 =
    $("#msgStep3");

  if (
    !steps.length ||
    !btnContinuarTelefone ||
    !inTelefone ||
    !inCodigo
  ) {
    return;
  }

  let telefoneE164 = "";
  let expiresAt = null;
  let timerId = null;

  function safeOn(element, eventName, handler) {

    if (!element) {
      return;
    }

    element.addEventListener(
      eventName,
      handler
    );
  }

  function onlyDigits(value) {

    return String(value || "")
      .replace(/\D+/g, "");
  }

  function maskPhone(value) {

    const digits =
      onlyDigits(value).slice(0, 11);

    const p1 =
      digits.slice(0, 2);

    const p2 =
      digits.slice(2, 7);

    const p3 =
      digits.slice(7, 11);

    if (digits.length <= 2) {
      return `(${p1}`;
    }

    if (digits.length <= 7) {
      return `(${p1}) ${p2}`;
    }

    return `(${p1}) ${p2}-${p3}`;
  }

  function isValidBRPhone(value) {

    return onlyDigits(value).length === 11;
  }

  function setButtonEnabled(
    element,
    enabled
  ) {

    if (!element) {
      return;
    }

    element.disabled = !enabled;
  }

  function clearMessages() {

    if (msg1) {
      msg1.textContent = "";
    }

    if (msg2) {
      msg2.textContent = "";
    }

    if (msg3) {
      msg3.textContent = "";
    }
  }

  function showStep(stepNumber) {

    steps.forEach((step) => {

      const active =
        step.dataset.step === String(stepNumber);

      step.classList.toggle(
        "is-active",
        active
      );

      step.hidden = !active;

    });

    clearMessages();
  }

  async function requisitarAutenticacao(
    acao,
    dados = {}
  ) {

    const body = new URLSearchParams({
      acao,
      telefone: telefoneE164,
      ...dados
    });

    const response = await fetch(
      ENDPOINT_CLIENTE,
      {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type":
            "application/x-www-form-urlencoded; charset=UTF-8",
          "X-Requested-With":
            "XMLHttpRequest"
        },
        body: body.toString()
      }
    );

    const json =
      await response
        .json()
        .catch(() => null);

    if (
      !response.ok ||
      !json ||
      json.ok !== true
    ) {

      const erro = new Error(
        json?.user_msg ||
        "Não foi possível concluir a autenticação."
      );

      erro.code =
        json?.code || "";

      erro.retryAfter =
        Number(json?.data?.retry_after || 0);

      throw erro;
    }

    return json;
  }

  function stopTimer() {

    if (timerId) {
      clearInterval(timerId);
    }

    timerId = null;
  }

  function atualizarInfoCodigo(textoTempo) {

    if (!infoCodigo) {
      return;
    }

    infoCodigo.replaceChildren();

    const linha1 =
      document.createElement("span");

    linha1.textContent =
      `Enviamos um código para ${telefoneE164}.`;

    const quebra =
      document.createElement("br");

    const linha2 =
      document.createElement("span");

    linha2.textContent =
      `Expira em: ${textoTempo}`;

    infoCodigo.append(
      linha1,
      quebra,
      linha2
    );
  }

  function updateCountdown() {

    if (!infoCodigo) {
      return;
    }

    const now =
      Date.now();

    const expires =
      expiresAt
        ? expiresAt.getTime()
        : 0;

    const diff =
      expires - now;

    if (!expiresAt || diff <= 0) {

      stopTimer();

      infoCodigo.textContent =
        "⏳ Código expirado. Clique em “Reenviar código”.";

      setButtonEnabled(
        btnValidarCodigo,
        false
      );

      return;
    }

    const totalSeconds =
      Math.max(
        0,
        Math.floor(diff / 1000)
      );

    const minutes =
      String(
        Math.floor(totalSeconds / 60)
      ).padStart(2, "0");

    const seconds =
      String(
        totalSeconds % 60
      ).padStart(2, "0");

    atualizarInfoCodigo(
      `${minutes}:${seconds}`
    );
  }

  function startTimer() {

    stopTimer();

    updateCountdown();

    timerId =
      window.setInterval(
        updateCountdown,
        500
      );
  }

  safeOn(
    btnContinuarTelefone,
    "click",
    () => {

      showStep(2);

      inTelefone.focus();
    }
  );

  safeOn(
    btnVoltarStep2,
    "click",
    (event) => {

      event.preventDefault();

      showStep(1);
    }
  );

  safeOn(
    btnVoltarStep3,
    "click",
    (event) => {

      event.preventDefault();

      stopTimer();

      showStep(2);
    }
  );

  safeOn(
    inTelefone,
    "input",
    () => {

      inTelefone.value =
        maskPhone(inTelefone.value);

      setButtonEnabled(
        btnEnviarCodigo,
        isValidBRPhone(inTelefone.value)
      );
    }
  );

  safeOn(
    btnEnviarCodigo,
    "click",
    async () => {

      const ddiNumero =
        ddi?.value || "55";

      const digits =
        onlyDigits(inTelefone.value);

      if (!isValidBRPhone(digits)) {

        if (msg2) {
          msg2.textContent =
            "⚠️ Informe um telefone válido.";
        }

        return;
      }

      telefoneE164 =
        `+${ddiNumero}${digits}`;

      setButtonEnabled(
        btnEnviarCodigo,
        false
      );

      if (msg2) {
        msg2.textContent =
          "Enviando código...";
      }

      try {

        const json =
          await requisitarAutenticacao(
            "enviar_codigo"
          );

        const expiresIn =
          Number(
            json?.data?.expires_in
          ) || 300;

        expiresAt =
          new Date(
            Date.now() +
            expiresIn * 1000
          );

        showStep(3);

        startTimer();

        inCodigo.value = "";

        setButtonEnabled(
          btnValidarCodigo,
          false
        );

        inCodigo.focus();

      } catch (error) {

        if (msg2) {
          msg2.textContent =
            `❌ ${
              error.message ||
              "Não foi possível enviar o código."
            }`;
        }

        setButtonEnabled(
          btnEnviarCodigo,
          true
        );
      }
    }
  );

  safeOn(
    inCodigo,
    "input",
    () => {

      const digits =
        onlyDigits(inCodigo.value)
          .slice(0, 6);

      inCodigo.value =
        digits;

      const valido =
        digits.length === 6 &&
        expiresAt &&
        expiresAt.getTime() > Date.now();

      setButtonEnabled(
        btnValidarCodigo,
        Boolean(valido)
      );
    }
  );

  safeOn(
    btnValidarCodigo,
    "click",
    async () => {

      const codigo =
        onlyDigits(inCodigo.value);

      if (codigo.length !== 6) {

        if (msg3) {
          msg3.textContent =
            "⚠️ Informe o código com 6 dígitos.";
        }

        return;
      }

      if (
        !expiresAt ||
        expiresAt.getTime() <= Date.now()
      ) {

        if (msg3) {
          msg3.textContent =
            "⏳ O código expirou. Clique em “Reenviar código”.";
        }

        setButtonEnabled(
          btnValidarCodigo,
          false
        );

        return;
      }

      setButtonEnabled(
        btnValidarCodigo,
        false
      );

      if (msg3) {
        msg3.textContent =
          "Validando...";
      }

      try {

        const json =
          await requisitarAutenticacao(
            "validar_codigo",
            {
              codigo
            }
          );

        stopTimer();

        if (msg3) {
          msg3.textContent =
            "✅ Código validado! Entrando...";
        }

        window.setTimeout(
          () => {

            window.location.href =
              json?.data?.redirect ||
              "/public/views/cliente-perfil.html";
          },
          700
        );

      } catch (error) {

        if (msg3) {
          msg3.textContent =
            `❌ ${
              error.message ||
              "Código inválido. Verifique e tente novamente."
            }`;
        }

        const aindaValido =
          expiresAt &&
          expiresAt.getTime() > Date.now();

        setButtonEnabled(
          btnValidarCodigo,
          Boolean(aindaValido)
        );
      }
    }
  );

  safeOn(
    btnReenviar,
    "click",
    async () => {

      if (!telefoneE164) {

        showStep(2);

        return;
      }

      setButtonEnabled(
        btnReenviar,
        false
      );

      if (msg3) {
        msg3.textContent =
          "Reenviando código...";
      }

      try {

        const json =
          await requisitarAutenticacao(
            "enviar_codigo"
          );

        const expiresIn =
          Number(
            json?.data?.expires_in
          ) || 300;

        expiresAt =
          new Date(
            Date.now() +
            expiresIn * 1000
          );

        inCodigo.value = "";

        setButtonEnabled(
          btnValidarCodigo,
          false
        );

        if (msg3) {
          msg3.textContent =
            "✅ Código reenviado!";
        }

        startTimer();

      } catch (error) {

        if (msg3) {
          msg3.textContent =
            `❌ ${
              error.message ||
              "Não foi possível reenviar o código."
            }`;
        }

      } finally {

        setButtonEnabled(
          btnReenviar,
          true
        );
      }
    }
  );

  showStep(1);

})();
</script>

<script src="/js/InstalarPWA.js"></script>

<script
  src="/public/js/identidade-visual/identidade-visual-login.js?v=20260822_1"
></script>

</body>
</html>