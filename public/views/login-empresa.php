<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../backend/_auth/csrf.php';
$loginCsrfToken = csrfTokenSessao();

/**
 * Login interno central: o contexto de empresa da sessão (criado pelo link
 * antigo public/login.php?empresa=ID&nome=slug) é OPCIONAL. A empresa de acesso
 * só é definida pelo backend, depois da autenticação (login.php / seleção).
 */
$empresaId   = (int)($_SESSION['empresa_id'] ?? 0);
$empresaNome = trim((string)($_SESSION['empresa_nome'] ?? ''));
$empresaSlug = trim((string)($_SESSION['empresa_slug'] ?? ''));
$temContextoEmpresa = $empresaId > 0 && ($empresaNome !== '' || $empresaSlug !== '');
?>
<!DOCTYPE html>
<html lang="pt-br">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="Um sistema de Pedidos" />

    <!-- CSS -->
    <link rel="stylesheet" href="../css/login/login-web.css?v=20261002_1" />
    <link rel="stylesheet" href="../css/login/login-mobile.css?v=20260818_5" />

    <link rel="icon" href="/public/imagens/logo-menu.png" type="image/png" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />

    <title>AmAgenda • Login</title>

    <!-- Manifesto do PWA -->
    <link rel="manifest" href="/manifest.json" />
    <meta name="theme-color" content="#003355" />
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

            <!-- Área de login restrito. IDs preservados para login.js. -->
            <div class="login-dados-right">
              <?php if ($temContextoEmpresa): ?>
              <a href="../views/login-cliente.php" class="voltar-cliente" aria-label="Voltar para login cliente"> ← </a>
              <?php endif; ?>

              <header class="login-marca">
                <img src="/public/imagens/logo-menu.png" alt="" class="login-marca-logo" data-identidade-login-logo />
                <div class="login-marca-textos">
                  <strong data-identidade-login-nome>AmAgenda</strong>
                </div>
              </header>

              <!-- Etapa 1: credenciais. IDs preservados para login.js. -->
              <div class="login-etapa" id="loginEtapaCredenciais">
                <div class="login-boas-vindas">
                  <h2 class="h2-titulo">Acesso restrito</h2>
                  <p>Entre com seus dados para continuar</p>
                </div>

                <div class="campo">
                  <label for="email">E-mail</label>
                  <input
                    type="email"
                    name="email"
                    id="email"
                    placeholder="seuemail@exemplo.com"
                    required
                    autocomplete="email"
                    inputmode="email"
                  />
                </div>

                <div class="campo">
                  <label for="password">Senha</label>
                  <input
                    type="password"
                    name="password"
                    id="password"
                    placeholder="••••••••"
                    required
                    autocomplete="current-password"
                  />
                </div>

                <div class="lembrar-container">
                  <input type="checkbox" id="lembrar" name="lembrar" />
                  <label for="lembrar">Lembrar de mim</label>
                </div>

                <button class="botao-entrar" id="login" aria-label="Entrar no sistema">Entrar</button>
                <button type="button" class="botao-voltar-etapa" id="esqueciSenha">Esqueci minha senha</button>
                <p class="mensagem" id="message" role="alert" aria-live="polite"></p>
              </div>

              <!-- Recuperação global: email -> SMS -> código -> nova senha. -->
              <div class="login-etapa" id="loginEtapaRecuperacaoEmail" hidden>
                <div class="login-boas-vindas">
                  <h2 class="h2-titulo">Recuperar acesso</h2>
                  <p>Informe o e-mail da sua conta.</p>
                </div>
                <div class="campo">
                  <label for="recuperacaoEmail">E-mail</label>
                  <input type="email" id="recuperacaoEmail" autocomplete="email" inputmode="email" />
                </div>
                <button type="button" class="botao-entrar" id="recuperacaoEnviarCodigo">Enviar código</button>
                <p class="mensagem" id="recuperacaoMensagemEmail" role="alert" aria-live="polite"></p>
                <button type="button" class="botao-voltar-etapa" id="recuperacaoVoltarLogin">&larr; Voltar ao login</button>
              </div>

              <div class="login-etapa" id="loginEtapaRecuperacaoCodigo" hidden>
                <div class="login-boas-vindas">
                  <h2 class="h2-titulo">Código de recuperação</h2>
                  <p>Digite o código enviado ao telefone cadastrado.</p>
                </div>
                <div class="campo">
                  <label for="recuperacaoCodigo">Código de 6 dígitos</label>
                  <input type="text" id="recuperacaoCodigo" inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
                </div>
                <p class="mensagem" id="recuperacaoTempo" aria-live="polite"></p>
                <button type="button" class="botao-entrar" id="recuperacaoValidarCodigo">Validar código</button>
                <p class="mensagem" id="recuperacaoMensagemCodigo" role="alert" aria-live="polite"></p>
                <button type="button" class="botao-voltar-etapa" id="recuperacaoReenviarCodigo">Reenviar código</button>
                <button type="button" class="botao-voltar-etapa" id="recuperacaoVoltarEmail">&larr; Alterar e-mail</button>
              </div>

              <div class="login-etapa" id="loginEtapaRecuperacaoSenha" hidden>
                <div class="login-boas-vindas">
                  <h2 class="h2-titulo">Definir nova senha</h2>
                  <p>A nova senha será usada em todas as empresas vinculadas.</p>
                </div>
                <div class="campo">
                  <label for="recuperacaoNovaSenha">Nova senha</label>
                  <input type="password" id="recuperacaoNovaSenha" autocomplete="new-password" minlength="6" maxlength="72" />
                </div>
                <div class="campo">
                  <label for="recuperacaoConfirmarSenha">Confirmar nova senha</label>
                  <input type="password" id="recuperacaoConfirmarSenha" autocomplete="new-password" minlength="6" maxlength="72" />
                </div>
                <button type="button" class="botao-entrar" id="recuperacaoSalvarSenha">Alterar senha</button>
                <p class="mensagem" id="recuperacaoMensagemSenha" role="alert" aria-live="polite"></p>
              </div>

              <!-- Etapa 2: seleção de empresa (só aparece quando o backend responde EMPRESA_SELECTION_REQUIRED). -->
              <div class="login-etapa" id="loginEtapaEmpresa" hidden>
                <div class="login-boas-vindas">
                  <h2 class="h2-titulo" id="empresaTitulo" tabindex="-1">Selecione a empresa</h2>
                  <p>Escolha em qual empresa deseja entrar.</p>
                </div>
                <div class="empresa-lista" id="empresaLista" role="group" aria-labelledby="empresaTitulo"></div>
                <p class="mensagem" id="messageEmpresa" role="alert" aria-live="polite"></p>
                <button type="button" class="botao-voltar-etapa" id="empresaVoltar">&larr; Voltar</button>
              </div>

              <nav class="login-links-legais" aria-label="Documentos legais">
                <a href="/views/termos-de-uso/termos-empresa.html" target="_blank" rel="noopener noreferrer">Termos da Empresa</a>
                <a href="/views/termos-de-uso/termos-usuario.html" target="_blank" rel="noopener noreferrer">Termos do Usuário</a>
                <a href="/views/politica-privacidade/politica-de-privacidade.html" target="_blank" rel="noopener noreferrer">Política de Privacidade</a>
              </nav>
            </div>

          </div>
        </div>
      </div>
    </main>

    <script>
      window.AMAGENDA_EMPRESA = <?php echo $temContextoEmpresa ? json_encode([
        'id' => $empresaId,
        'nome' => $empresaNome,
        'slug' => $empresaSlug,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) : 'null'; ?>;
      window.AMAGENDA_LOGIN_CSRF = <?php echo json_encode($loginCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    </script>

    <!-- Scripts -->
    <script src="/public/_auth/login.js?v=20261003_1"></script>
    <script src="/public/js/identidade-visual/identidade-visual-login.js?v=20260822_1"></script>
  </body>
</html>
