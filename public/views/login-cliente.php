<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/../../backend/_config/sessao.php';
    amagendaConfigurarSessaoSegura();
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
// Nome cadastrado da empresa do contexto (definido pelo link validado); o nome
// exibido personalizado, quando houver, é aplicado por identidade-visual-login.js.
$empresaNomeContexto = trim((string)($_SESSION['empresa_nome'] ?? ''));
// Slug do contexto validado da sessão: o manifesto só personaliza se conferir com a sessão.
$empresaSlugContexto = trim((string)($_SESSION['empresa_slug'] ?? ''));
if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $empresaSlugContexto) !== 1) {
    $empresaSlugContexto = '';
}

/*
 * Restaura somente o estado visual do desafio OTP após atualizar a página.
 * A autorização e os prazos efetivos continuam sendo validados no backend.
 */
$otpEstadoInicial = null;
$desafioOtp = $_SESSION['cliente_auth_desafio'] ?? null;
$agoraOtp = time();

if (
    is_array($desafioOtp)
    && (int)($desafioOtp['id_empresa'] ?? 0) === $empresaId
    && (int)($desafioOtp['expira_em'] ?? 0) > $agoraOtp
    && in_array(
        (string)($desafioOtp['finalidade'] ?? ''),
        ['acesso', 'recuperacao_senha'],
        true
    )
) {
    $enviadoEmOtp = (int)($desafioOtp['enviado_em'] ?? 0);

    $otpEstadoInicial = [
        'telefone' => (string)($desafioOtp['telefone'] ?? ''),
        'acao' => (string)$desafioOtp['finalidade'] === 'recuperacao_senha'
            ? 'iniciar_recuperacao'
            : 'enviar_codigo',
        'expires_in' => max(
            1,
            (int)$desafioOtp['expira_em'] - $agoraOtp
        ),
        'retry_after' => max(
            0,
            30 - max(0, $agoraOtp - $enviadoEmOtp)
        ),
        'request_state' => (string)($desafioOtp['estado_envio'] ?? '') === 'uncertain'
            ? 'uncertain'
            : 'existing',
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="AmAgenda • Login do Cliente" />
  <meta name="theme-color" content="#000f50" />

  <title>AmAgenda • Login Cliente</title>

  <link rel="manifest" href="/public/manifest-agenda-online.php<?php if ($empresaSlugContexto !== '') { echo '?slug=' . rawurlencode($empresaSlugContexto); } ?>" crossorigin="use-credentials" />
  <link rel="stylesheet" href="/public/css/PWA/instalar-pwa.css?v=1.14.8" />

  <link
    rel="stylesheet"
    href="../css/login/login-web.css?v=1.14.8"
  />

  <link
    rel="stylesheet"
    href="../css/login/login-mobile.css?v=1.14.8"
  />

  <link
    rel="stylesheet"
    href="../css/login/login-cliente.css?v=1.14.8"
  />

  <!-- Regra única de enquadramento da imagem de login (mesma da prévia do modal). -->
  <link
    rel="stylesheet"
    href="../css/identidade-visual/identidade-visual.css?v=1.14.8"
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

  <script src="/public/js/PWA/InstalarPWA.js?v=1.14.8"></script>

</head>

<body data-menu-contexto="agenda-online">

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
            alt="Imagem da empresa"
            class="imagem-desktop"
            data-identidade-login
          />

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
              Acesse seus agendamentos
            </h2>

            <p>
              Entre com seu telefone para consultar e gerenciar seus horários<?php if ($empresaNomeContexto !== ''): ?> com <span data-identidade-login-empresa><?php echo htmlspecialchars($empresaNomeContexto, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>.
            </p>

          </div>

          <div class="pwa-instalar-area" data-pwa-instalar-container></div>

          <!-- ====================================================
               STEP 1
          ===================================================== -->

          <section
            class="step is-active"
            data-step="1"
          >

            <p class="cliente-sub">
              Continue com seu telefone para receber o código de acesso.
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
              Informe seu telefone para continuar com segurança.
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
               STEP 4 - SENHA
          ===================================================== -->

          <section
            class="step"
            data-step="4"
          >

            <button
              class="cliente-voltar"
              id="btnVoltarStepSenha"
              type="button"
              aria-label="Voltar"
            >
              ←
            </button>

            <h3 class="cliente-titulo">
              Informe sua senha
            </h3>

            <p
              class="cliente-info"
              id="infoTelefoneSenha"
            ></p>

            <input
              type="password"
              id="senhaCliente"
              class="cliente-input"
              placeholder="Senha"
              autocomplete="current-password"
              maxlength="72"
            />

            <button
              class="botao-entrar"
              id="btnEntrarSenha"
              type="button"
              disabled
            >
              Entrar
            </button>

            <button
              class="cliente-link"
              id="btnEsqueciSenha"
              type="button"
            >
              Esqueci minha senha?
            </button>

            <p
              class="mensagem"
              id="msgStep4"
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
              disabled
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

          <p class="login-tecnologia">
            Tecnologia <strong>AmAgenda</strong>
          </p>

        </div>

      </div>

    </div>

  </div>

</main>

<nav class="login-links-legais login-links-legais--cliente" aria-label="Documentos legais">
  <a href="/views/termos-de-uso/termos-cliente.html" target="_blank" rel="noopener noreferrer">Termos do Cliente</a>
  <a href="/views/politica-privacidade/politica-de-privacidade.html" target="_blank" rel="noopener noreferrer">Política de Privacidade</a>
</nav>

<script>
window.AMAGENDA_EMPRESA_ID = <?php echo (int)$empresaId; ?>;
</script>

<script>
(() => {
  "use strict";

  const ENDPOINT_CLIENTE =
    "/api/api_central.php?path=_auth/cliente-login";

  const REENVIO_PADRAO_SEGUNDOS = 30;

  const otpEstadoInicial = <?php
    echo json_encode(
        $otpEstadoInicial,
        JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
  ?>;

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

  const btnVoltarStepSenha =
    $("#btnVoltarStepSenha");

  const inSenha =
    $("#senhaCliente");

  const btnEntrarSenha =
    $("#btnEntrarSenha");

  const btnEsqueciSenha =
    $("#btnEsqueciSenha");

  const infoTelefoneSenha =
    $("#infoTelefoneSenha");

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

  const msg4 =
    $("#msgStep4");

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
  let resendAvailableAt = 0;
  let resendTimerId = null;
  let reenvioEmAndamento = false;
  let phoneRetryAvailableAt = 0;
  let phoneRetryTimerId = null;
  let phoneRetryDigits = "";
  let verificacaoEmAndamento = false;
  let acaoOtpAtual = "enviar_codigo";
  const MENSAGEM_ENVIO_ACEITO =
    "Código solicitado com sucesso. Confira suas mensagens SMS.";
  const MENSAGEM_CODIGO_EXISTENTE =
    "Você já solicitou um código. Utilize o código anterior ou aguarde para solicitar outro.";
  const MENSAGEM_ENVIO_INCERTO =
    "Não foi possível confirmar o envio do código. Aguarde antes de tentar novamente.";
  const MENSAGEM_SOLICITACAO_GENERICA =
    "Se o telefone estiver elegível, você receberá um código por SMS.";
  let mensagemCodigoAtual = MENSAGEM_CODIGO_EXISTENTE;

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

    if (msg4) {
      msg4.textContent = "";
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

    let response;

    try {
      response = await fetch(
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
    } catch (falhaRede) {
      // Sem resposta do servidor: nada foi confirmado e nenhum prazo é inventado.
      const erroRede = new Error(
        "Não foi possível se comunicar com o servidor. Verifique sua conexão e tente novamente."
      );

      erroRede.code = "NETWORK_ERROR";
      erroRede.retryAfter = 0;
      erroRede.data = {};

      throw erroRede;
    }

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
        Math.max(
          0,
          Math.ceil(Number(json?.data?.retry_after) || 0)
        );

      erro.data =
        json?.data && typeof json.data === "object"
          ? json.data
          : {};

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

  /** 45 → "45s"; 125 → "2 min 5s"; 2843 → "48 min" (prazo real informado pelo backend). */
  function formatarEspera(totalSegundos) {

    const segundos =
      Math.max(0, Math.ceil(Number(totalSegundos) || 0));

    if (segundos < 60) {
      return `${segundos}s`;
    }

    if (segundos < 600) {
      const resto = segundos % 60;

      return resto > 0
        ? `${Math.floor(segundos / 60)} min ${resto}s`
        : `${segundos / 60} min`;
    }

    return `${Math.ceil(segundos / 60)} min`;
  }

  /** Mensagem de recusa com o prazo real informado pelo backend, quando houver. */
  function textoRecusa(error, padrao) {

    const espera =
      Number(error?.retryAfter) || 0;

    if (error?.code === "CLIENT_OTP_RESEND_LIMIT" && espera > 0) {
      return `Aguarde ${formatarEspera(espera)} antes de solicitar outro código.`;
    }

    const texto =
      error?.message || padrao;

    if (error?.code === "CLIENT_OTP_HOURLY_LIMIT" && espera > 0) {
      return `${texto} Nova solicitação disponível em ${formatarEspera(espera)}.`;
    }

    return texto;
  }

  function stopPhoneRetryTimer() {

    if (phoneRetryTimerId) {
      clearInterval(phoneRetryTimerId);
    }

    phoneRetryTimerId = null;
  }

  /**
   * Etapa do telefone: mantém "Verificar" indisponível somente pelo prazo
   * que o backend informou (30 s de reenvio ou o limite por hora).
   */
  function updatePhoneRetry() {

    if (!btnEnviarCodigo) {
      return;
    }

    const restante =
      Math.max(
        0,
        Math.ceil((phoneRetryAvailableAt - Date.now()) / 1000)
      );

    if (restante > 0) {
      btnEnviarCodigo.textContent =
        `Verificar em ${formatarEspera(restante)}`;

      setButtonEnabled(btnEnviarCodigo, false);

      return;
    }

    stopPhoneRetryTimer();

    btnEnviarCodigo.textContent = "Verificar";

    setButtonEnabled(
      btnEnviarCodigo,
      isValidBRPhone(inTelefone.value)
    );
  }

  function startPhoneRetry(seconds) {

    stopPhoneRetryTimer();

    phoneRetryAvailableAt =
      Date.now() + Math.max(0, Math.ceil(Number(seconds) || 0)) * 1000;

    updatePhoneRetry();

    if (phoneRetryAvailableAt > Date.now()) {
      phoneRetryTimerId =
        window.setInterval(updatePhoneRetry, 250);
    }
  }

  function phoneRetryAtivo() {
    return phoneRetryAvailableAt > Date.now();
  }

  /**
   * Recusa sem novo desafio, mas com um código desta sessão ainda válido:
   * abre a etapa do código com o prazo do backend em vez de prender o
   * cliente na etapa do telefone.
   */
  function abrirCodigoPendente(acao, erro) {

    const dados = erro?.data || {};
    const expiresIn = Number(dados.expires_in) || 0;

    if (dados.codigo_pendente !== true || expiresIn <= 0) {
      return false;
    }

    acaoOtpAtual = acao;
    mensagemCodigoAtual =
      erro.code === "CLIENT_OTP_SEND_UNCERTAIN"
        ? MENSAGEM_ENVIO_INCERTO
        : MENSAGEM_CODIGO_EXISTENTE;

    expiresAt =
      new Date(Date.now() + expiresIn * 1000);

    showStep(3);
    startTimer();
    startResendCountdown(erro.retryAfter);

    inCodigo.value = "";
    setButtonEnabled(btnValidarCodigo, false);

    if (msg3) {
      msg3.textContent = `ℹ️ ${mensagemCodigoAtual}`;
    }

    inCodigo.focus();

    return true;
  }

  function stopResendTimer() {

    if (resendTimerId) {
      clearInterval(resendTimerId);
    }

    resendTimerId = null;
  }

  function updateResendCountdown() {

    if (!btnReenviar) {
      return;
    }

    const remainingSeconds =
      Math.max(
        0,
        Math.ceil(
          (resendAvailableAt - Date.now()) / 1000
        )
      );

    if (remainingSeconds > 0) {
      btnReenviar.textContent =
        `Reenviar código em ${formatarEspera(remainingSeconds)}`;

      setButtonEnabled(
        btnReenviar,
        false
      );

      return;
    }

    stopResendTimer();

    btnReenviar.textContent =
      "Reenviar código";

    setButtonEnabled(
      btnReenviar,
      !reenvioEmAndamento
    );
  }

  function startResendCountdown(
    seconds = REENVIO_PADRAO_SEGUNDOS
  ) {

    stopResendTimer();

    const safeSeconds =
      Math.max(
        0,
        Math.ceil(Number(seconds) || 0)
      );

    resendAvailableAt =
      Date.now() + safeSeconds * 1000;

    updateResendCountdown();

    if (safeSeconds > 0) {
      resendTimerId =
        window.setInterval(
          updateResendCountdown,
          250
        );
    }
  }

  function atualizarInfoCodigo(textoTempo) {

    if (!infoCodigo) {
      return;
    }

    infoCodigo.replaceChildren();

    const linha1 =
      document.createElement("span");

    linha1.textContent =
      mensagemCodigoAtual;

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

  async function iniciarFluxoOtp(
    acao,
    elementoMensagem
  ) {

    if (elementoMensagem) {
      elementoMensagem.textContent =
        "Enviando código...";
    }

    const json =
      await requisitarAutenticacao(acao);

    acaoOtpAtual = acao;
    mensagemCodigoAtual =
      json?.data?.request_state === "accepted"
        ? MENSAGEM_ENVIO_ACEITO
        : MENSAGEM_SOLICITACAO_GENERICA;

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
    startResendCountdown();

    inCodigo.value = "";

    setButtonEnabled(
      btnValidarCodigo,
      false
    );

    if (msg3) {
      msg3.textContent = `ℹ️ ${mensagemCodigoAtual}`;
    }

    inCodigo.focus();
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

      showStep(
        acaoOtpAtual === "iniciar_recuperacao"
          ? 4
          : 2
      );

      if (acaoOtpAtual !== "iniciar_recuperacao") {
        updatePhoneRetry();
      }
    }
  );

  safeOn(
    btnVoltarStepSenha,
    "click",
    (event) => {

      event.preventDefault();

      if (inSenha) {
        inSenha.value = "";
      }

      showStep(2);

      updatePhoneRetry();
    }
  );

  safeOn(
    inTelefone,
    "input",
    () => {

      inTelefone.value =
        maskPhone(inTelefone.value);

      // O prazo recebido vale para o telefone que foi recusado; outro número
      // é avaliado novamente pelo backend.
      if (
        phoneRetryAtivo()
        && onlyDigits(inTelefone.value) !== phoneRetryDigits
      ) {
        phoneRetryAvailableAt = 0;
      }

      updatePhoneRetry();
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

      // Evita cliques repetidos e pedidos durante o prazo informado pelo backend.
      if (verificacaoEmAndamento || phoneRetryAtivo()) {
        updatePhoneRetry();
        return;
      }

      verificacaoEmAndamento = true;

      telefoneE164 =
        `+${ddiNumero}${digits}`;

      setButtonEnabled(
        btnEnviarCodigo,
        false
      );

      if (msg2) {
        msg2.textContent =
          "Verificando...";
      }

      try {

        const json =
          await requisitarAutenticacao(
            "verificar_telefone"
          );

        const proximoPasso =
          json?.data?.proximo_passo;

        if (proximoPasso === "senha") {
          acaoOtpAtual = "enviar_codigo";

          if (infoTelefoneSenha) {
            infoTelefoneSenha.textContent =
              `Telefone identificado: ${telefoneE164}`;
          }

          if (inSenha) {
            inSenha.value = "";
          }

          setButtonEnabled(
            btnEntrarSenha,
            false
          );

          showStep(4);
          inSenha?.focus();
          return;
        }

        if (proximoPasso === "otp") {
          await iniciarFluxoOtp(
            "enviar_codigo",
            msg2
          );
          return;
        }

        throw new Error(
          "Não foi possível determinar a forma de acesso."
        );

      } catch (error) {

        // Recusa sem novo SMS, mas com código desta sessão ainda válido.
        if (abrirCodigoPendente("enviar_codigo", error)) {
          return;
        }

        if (error.retryAfter > 0) {
          phoneRetryDigits = digits;
          startPhoneRetry(error.retryAfter);
        }

        if (msg2) {
          msg2.textContent =
            `❌ ${textoRecusa(error, "Não foi possível continuar.")}`;
        }

      } finally {

        verificacaoEmAndamento = false;

        if (
          steps.some(
            (step) => step.dataset.step === "2" && !step.hidden
          )
        ) {
          updatePhoneRetry();
        }
      }
    }
  );

  safeOn(
    inSenha,
    "input",
    () => {
      setButtonEnabled(
        btnEntrarSenha,
        String(inSenha.value || "").length > 0
      );
    }
  );

  safeOn(
    btnEntrarSenha,
    "click",
    async () => {
      const senha = String(inSenha?.value || "");

      if (!senha) {
        if (msg4) {
          msg4.textContent = "⚠️ Informe sua senha.";
        }
        return;
      }

      setButtonEnabled(btnEntrarSenha, false);

      if (msg4) {
        msg4.textContent = "Entrando...";
      }

      try {
        const json = await requisitarAutenticacao(
          "login_senha",
          { senha }
        );

        if (msg4) {
          msg4.textContent = "✅ Acesso autorizado! Entrando...";
        }

        window.location.href =
          json?.data?.redirect ||
          "/public/views/cliente-perfil.html";
      } catch (error) {
        if (msg4) {
          msg4.textContent =
            `❌ ${error.message || "Telefone ou senha inválidos."}`;
        }

        setButtonEnabled(btnEntrarSenha, true);
      }
    }
  );

  safeOn(
    btnEsqueciSenha,
    "click",
    async () => {
      setButtonEnabled(btnEsqueciSenha, false);

      try {
        await iniciarFluxoOtp(
          "iniciar_recuperacao",
          msg4
        );
      } catch (error) {
        if (abrirCodigoPendente("iniciar_recuperacao", error)) {
          return;
        }

        if (msg4) {
          msg4.textContent =
            `❌ ${textoRecusa(error, "Não foi possível iniciar a recuperação.")}`;
        }
      } finally {
        setButtonEnabled(btnEsqueciSenha, true);
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
        stopResendTimer();

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

      if (
        reenvioEmAndamento
        || Date.now() < resendAvailableAt
      ) {
        updateResendCountdown();
        return;
      }

      if (!telefoneE164) {

        showStep(2);

        return;
      }

      reenvioEmAndamento = true;

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
            acaoOtpAtual
          );

        const expiresIn =
          Number(
            json?.data?.expires_in
          ) || 300;

        mensagemCodigoAtual =
          json?.data?.request_state === "accepted"
            ? MENSAGEM_ENVIO_ACEITO
            : MENSAGEM_SOLICITACAO_GENERICA;

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
            `ℹ️ ${mensagemCodigoAtual}`;
        }

        startTimer();
        startResendCountdown();

      } catch (error) {

        if (abrirCodigoPendente(acaoOtpAtual, error)) {
          return;
        }

        // Prazo real do backend (30 s ou limite por hora); sem prazo, o
        // contador não é reiniciado arbitrariamente.
        if (error.retryAfter > 0) {
          startResendCountdown(
            error.retryAfter
          );
        }

        // Falha definitiva sem desafio anterior utilizável.
        if (error.code === "CLIENT_OTP_SEND_FAILED" && error.retryAfter > 0) {
          expiresAt = null;
          stopTimer();
          setButtonEnabled(btnValidarCodigo, false);

          if (infoCodigo) {
            infoCodigo.textContent =
              "Solicite um novo código para continuar.";
          }
        }

        if (msg3) {
          let texto =
            textoRecusa(error, "Não foi possível reenviar o código.");

          if (
            error.code === "CLIENT_OTP_HOURLY_LIMIT"
            && error.data?.codigo_pendente === true
          ) {
            texto += " Use o código já enviado por SMS.";
          }

          msg3.textContent =
            `❌ ${texto}`;
        }

      } finally {

        reenvioEmAndamento = false;

        updateResendCountdown();
      }
    }
  );

  if (
    otpEstadoInicial
    && otpEstadoInicial.telefone
  ) {
    telefoneE164 =
      String(otpEstadoInicial.telefone);

    acaoOtpAtual =
      otpEstadoInicial.acao === "iniciar_recuperacao"
        ? "iniciar_recuperacao"
        : "enviar_codigo";

    mensagemCodigoAtual =
      otpEstadoInicial.request_state === "uncertain"
        ? MENSAGEM_ENVIO_INCERTO
        : MENSAGEM_CODIGO_EXISTENTE;

    expiresAt =
      new Date(
        Date.now()
        + Math.max(
          1,
          Number(otpEstadoInicial.expires_in) || 1
        ) * 1000
      );

    showStep(3);
    startTimer();
    startResendCountdown(
      Number(otpEstadoInicial.retry_after) || 0
    );
    if (msg3) {
      msg3.textContent = `ℹ️ ${mensagemCodigoAtual}`;
    }
    inCodigo.focus();
  } else {
    showStep(1);
  }

})();
</script>

<script src="/public/js/PWA/RegistrarServiceWorker.js?v=1.14.8" data-pwa-contexto="agenda-online"></script>

<script
  src="/public/js/identidade-visual/identidade-visual-login.js?v=1.14.8"
></script>

</body>
</html>
