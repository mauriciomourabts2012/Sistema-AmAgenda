<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Bloqueia acesso direto sem empresa definida na sessão
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

  <!-- CSS padrão do login -->
  <link rel="stylesheet" href="../css/login/login-web.css?v=20260818_15" />
  <link rel="stylesheet" href="../css/login/login-mobile.css?v=20260818_5" />

  <!-- CSS extra só para etapas do cliente -->
  <link rel="stylesheet" href="../css/login/login-cliente.css?v=20260818_3" />

  <link rel="shortcut icon" href="/Imagens/Log-Titulo.png" type="image/x-icon" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="manifest" href="/manifest.json" />
</head>

<body>
  <main>
    <div class="login-fundo">
      <div class="login">
        <div class="login-container">

          <!-- Área visual alimentada pela Identidade Visual existente. -->
          <div class="imagem-left">
            <img src="../imagens/logo.png" alt="Imagem institucional" class="imagem-desktop" data-identidade-login />
            <div class="login-hero-brand" aria-hidden="true">
              <img src="/public/imagens/logo-menu.png" alt="" data-identidade-login-logo />
              <b data-identidade-login-nome>AmAgenda</b>
            </div>
            <div class="login-hero-texto" aria-hidden="true">
              <strong>Organize sua agenda,<br><em>encante</em> seus clientes.</strong>
              <span>Simples, rápido e feito para<br>o seu negócio.</span>
            </div>
            <div class="login-hero-beneficios" aria-hidden="true">
              <div><i><svg viewBox="0 0 24 24" fill="none"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 9h16M8 13h2M14 13h2M8 17h2M14 17h2"/></svg></i><span><b>Agenda organizada</b><small>Mais controle do seu dia a dia.</small></span></div>
              <div><i><svg viewBox="0 0 24 24" fill="none"><path d="M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM17 11a4 4 0 0 1 4 4v2M16 3.2a4 4 0 0 1 0 7.6"/></svg></i><span><b>Clientes em um só lugar</b><small>Histórico, contatos e muito mais.</small></span></div>
              <div><i><svg viewBox="0 0 24 24" fill="none"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></i><span><b>Confirmação automática</b><small>Reduza faltas e melhore sua agenda.</small></span></div>
              <div><i><svg viewBox="0 0 24 24" fill="none"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2M3 9l6-5 6 5 6-6"/></svg></i><span><b>Gestão simplificada</b><small>Relatórios e insights em poucos cliques.</small></span></div>
            </div>
          </div>

          <!-- Área de autenticação. IDs e etapas são preservados para o fluxo atual. -->
          <div class="login-dados-right">
            <header class="login-marca">
              <img src="/public/imagens/logo-menu.png" alt="" class="login-marca-logo" data-identidade-login-logo />
              <div class="login-marca-textos">
                <strong data-identidade-login-nome>AmAgenda</strong>
              </div>
            </header>

            <div class="login-boas-vindas">
              <h2 class="h2-titulo">Bem-vindo(a)!</h2>
              <p>Escolha uma opção para continuar</p>
            </div>

            <!-- STEP 1 -->
            <section class="step is-active" data-step="1">
              <p class="cliente-sub">
                Escolha uma opção abaixo para continuar.
              </p>

              <button class="botao-entrar" id="btnContinuarTelefone" type="button">
                <svg class="login-icone" aria-hidden="true" viewBox="0 0 24 24" fill="none">
                  <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.62 2.63a2 2 0 0 1-.45 2.11L8 9.73a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.85.29 1.73.5 2.63.62A2 2 0 0 1 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Continuar com telefone
              </button>

              <div class="login-divisor" aria-hidden="true"><span>ou</span></div>

              <a href="../views/login-empresa.php" class="cliente-link">
                <svg class="login-icone" aria-hidden="true" viewBox="0 0 24 24" fill="none">
                  <rect x="4" y="10" width="16" height="11" rx="2" stroke="currentColor" stroke-width="2" />
                  <path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                Acesso restrito • login com senha
              </a>

              <p class="cliente-termos">
                Ao continuar você aceita os <a class="cliente-link-a" href="#" target="_blank" rel="noopener">termos de uso</a>.
              </p>

              <p class="mensagem" id="msgStep1" role="alert" aria-live="polite"></p>
            </section>

            <!-- STEP 2 -->
            <section class="step" data-step="2">
              <button class="cliente-voltar" id="btnVoltarStep2" type="button" aria-label="Voltar">←</button>

              <h3 class="cliente-titulo">Informe seu telefone</h3>

              <div class="cliente-linha-telefone">
                <div class="cliente-ddi">
                  <span class="cliente-flag" aria-hidden="true">🇧🇷</span>
                  <select id="ddi" class="cliente-select" aria-label="DDI">
                    <option value="55" selected>+55</option>
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
                Informe seu telefone e clique em verificar. Será enviado um SMS com um código para validar seu número.
              </p>

              <button class="botao-entrar" id="btnEnviarCodigo" type="button" disabled>
                Verificar
              </button>

              <p class="mensagem" id="msgStep2" role="alert" aria-live="polite"></p>
            </section>

            <!-- STEP 3 -->
            <section class="step" data-step="3">
              <button class="cliente-voltar" id="btnVoltarStep3" type="button" aria-label="Voltar">←</button>

              <h3 class="cliente-titulo">Confirme seu telefone</h3>

              <input
                type="text"
                id="codigo"
                class="cliente-input"
                placeholder="Código de verificação"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
              />

              <div class="cliente-info" id="infoCodigo"></div>

              <button class="botao-entrar" id="btnValidarCodigo" type="button" disabled>
                Validar Código
              </button>

              <button class="cliente-link" id="btnReenviar" type="button">
                Reenviar código
              </button>

              <p class="mensagem" id="msgStep3" role="alert" aria-live="polite"></p>
            </section>

          </div>

        </div>
      </div>
    </div>
  </main>

  <script>
    window.AMAGENDA_EMPRESA_ID = <?php echo (int)$empresaId; ?>;
  </script>

  <!-- Scripts -->
  <script>
(() => {
  "use strict";

  const $ = (sel) => document.querySelector(sel);
  const steps = Array.from(document.querySelectorAll(".step"));

  // STEP 1
  const btnContinuarTelefone = $("#btnContinuarTelefone");
  const btnOutrasOpcoes = $("#btnOutrasOpcoes");

  // STEP 2
  const btnVoltarStep2 = $("#btnVoltarStep2");
  const ddi = $("#ddi");
  const inTelefone = $("#telefone");
  const btnEnviarCodigo = $("#btnEnviarCodigo");
  const msg2 = $("#msgStep2");

  // STEP 3
  const btnVoltarStep3 = $("#btnVoltarStep3");
  const inCodigo = $("#codigo");
  const btnValidarCodigo = $("#btnValidarCodigo");
  const btnReenviar = $("#btnReenviar");
  const infoCodigo = $("#infoCodigo");
  const msg3 = $("#msgStep3");

  // mensagens
  const msg1 = $("#msgStep1");

  if (!steps.length || !btnContinuarTelefone || !inTelefone || !inCodigo) return;

  // Endpoints futuros
  const ENDPOINT_ENVIAR  = "/backend/Cliente/Auth/EnviarCodigo.php";
  const ENDPOINT_VALIDAR = "/backend/Cliente/Auth/ValidarCodigo.php";

  // estado
  let telefoneE164 = "";
  let expiresAt = null;
  let timerId = null;

  function safeOn(el, evt, fn){
    if (!el) return;
    el.addEventListener(evt, fn);
  }

  function clearMsgs(){
    if (msg1) msg1.textContent = "";
    if (msg2) msg2.textContent = "";
    if (msg3) msg3.textContent = "";
  }

  function showStep(n){
    steps.forEach(s => {
      const on = (s.dataset.step === String(n));
      s.classList.toggle("is-active", on);
      s.hidden = !on;
    });
    clearMsgs();
  }

  function onlyDigits(v){ return (v || "").replace(/\D+/g, ""); }

  function maskPhone(v){
    const d = onlyDigits(v).slice(0, 11);
    const p1 = d.slice(0,2);
    const p2 = d.slice(2,7);
    const p3 = d.slice(7,11);

    if (d.length <= 2) return `(${p1}`;
    if (d.length <= 7) return `(${p1}) ${p2}`;
    return `(${p1}) ${p2}-${p3}`;
  }

  function isValidBRPhone(v){
    return onlyDigits(v).length === 11;
  }

  function setBtn(el, enabled){
    if (!el) return;
    el.disabled = !enabled;
  }

  function stopTimer(){
    if (timerId) clearInterval(timerId);
    timerId = null;
  }

  function updateCountdown(){
    if (!infoCodigo) return;

    const now = new Date();
    const diff = expiresAt ? (expiresAt.getTime() - now.getTime()) : 0;

    if (!expiresAt || diff <= 0){
      infoCodigo.textContent = "⏳ Código expirado. Clique em “Reenviar código”.";
      setBtn(btnValidarCodigo, false);
      return;
    }

    const totalSec = Math.floor(diff / 1000);
    const mm = String(Math.floor(totalSec / 60)).padStart(2,"0");
    const ss = String(totalSec % 60).padStart(2,"0");

    infoCodigo.innerHTML =
      `Enviamos um código para <b>${telefoneE164}</b>. Ele irá chegar em alguns instantes.<br>` +
      `Expira em: <b>${mm}:${ss}</b>`;
  }

  function startTimer(){
    stopTimer();
    timerId = setInterval(updateCountdown, 500);
  }

  safeOn(btnContinuarTelefone, "click", () => {
    showStep(2);
    inTelefone.focus();
  });

  safeOn(btnOutrasOpcoes, "click", () => {
    if (msg1) msg1.textContent = "ℹ️ Use o login com senha na outra tela.";
  });

  safeOn(btnVoltarStep2, "click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    showStep(1);
  });

  safeOn(btnVoltarStep3, "click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    showStep(2);
    stopTimer();
  });

  safeOn(inTelefone, "input", () => {
    inTelefone.value = maskPhone(inTelefone.value);
    setBtn(btnEnviarCodigo, isValidBRPhone(inTelefone.value));
  });

  safeOn(btnEnviarCodigo, "click", async () => {
    const dddNum = ddi?.value || "55";
    const digits = onlyDigits(inTelefone.value);

    if (!isValidBRPhone(digits)){
      if (msg2) msg2.textContent = "⚠️ Informe um telefone válido.";
      return;
    }

    telefoneE164 = `+${dddNum}${digits}`;

    setBtn(btnEnviarCodigo, false);
    if (msg2) msg2.textContent = "Enviando código...";

    try{
      await new Promise(res => setTimeout(res, 700));
      expiresAt = new Date(Date.now() + 5 * 60 * 1000);

      if (msg2) msg2.textContent = "";
      showStep(3);

      updateCountdown();
      startTimer();
      inCodigo.focus();

    }catch(e){
      if (msg2) msg2.textContent = "❌ Não foi possível enviar o código. Tente novamente.";
      setBtn(btnEnviarCodigo, true);
    }
  });

  safeOn(inCodigo, "input", () => {
    const d = onlyDigits(inCodigo.value).slice(0, 6);
    inCodigo.value = d;

    const ok = (d.length === 6) && expiresAt && (expiresAt.getTime() > Date.now());
    setBtn(btnValidarCodigo, ok);
  });

  safeOn(btnValidarCodigo, "click", async () => {
    const code = onlyDigits(inCodigo.value);

    if (code.length !== 6){
      if (msg3) msg3.textContent = "⚠️ Informe o código com 6 dígitos.";
      return;
    }

    if (!expiresAt || expiresAt.getTime() <= Date.now()){
      if (msg3) msg3.textContent = "⏳ O código expirou. Clique em “Reenviar código”.";
      setBtn(btnValidarCodigo, false);
      return;
    }

    setBtn(btnValidarCodigo, false);
    if (msg3) msg3.textContent = "Validando...";

    try{
      await new Promise(res => setTimeout(res, 600));
      if (code !== "123456") throw new Error("invalid");

      if (msg3) msg3.textContent = "✅ Código validado! Entrando...";
      stopTimer();

      setTimeout(() => {
        window.location.href = "/views/cliente-perfil.html";
      }, 700);

    }catch(e){
      if (msg3) msg3.textContent = "❌ Código inválido. Verifique e tente novamente.";
      setBtn(btnValidarCodigo, true);
    }
  });

  safeOn(btnReenviar, "click", async () => {
    if (!telefoneE164){
      showStep(2);
      return;
    }

    if (msg3) msg3.textContent = "Reenviando código...";
    try{
      await new Promise(res => setTimeout(res, 650));
      expiresAt = new Date(Date.now() + 5 * 60 * 1000);

      if (msg3) msg3.textContent = "✅ Código reenviado!";
      updateCountdown();
      startTimer();

      const ok = onlyDigits(inCodigo.value).length === 6;
      setBtn(btnValidarCodigo, ok);

    }catch(e){
      if (msg3) msg3.textContent = "❌ Não foi possível reenviar. Tente novamente.";
    }
  });

  showStep(1);
})();
  </script>

  <script src="/js/InstalarPWA.js"></script>
  <script src="/public/js/identidade-visual/identidade-visual-login.js?v=20260818_6"></script>
</body>
</html>
