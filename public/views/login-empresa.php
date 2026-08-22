<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$empresaId   = (int)($_SESSION['empresa_id'] ?? 0);
$empresaNome = trim((string)($_SESSION['empresa_nome'] ?? ''));
$empresaSlug = trim((string)($_SESSION['empresa_slug'] ?? ''));

/**
 * Exige contexto mínimo da empresa
 */
if ($empresaId <= 0 || ($empresaNome === '' && $empresaSlug === '')) {
    header('Location: /public/views/link-empresa-invalido.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="Um sistema de Pedidos" />

    <!-- CSS -->
    <link rel="stylesheet" href="../css/login/login-web.css?v=20260818_15" />
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
              <a href="../views/login-cliente.php" class="voltar-cliente" aria-label="Voltar para login cliente"> ← </a>

              <header class="login-marca">
                <img src="/public/imagens/logo-menu.png" alt="" class="login-marca-logo" data-identidade-login-logo />
                <div class="login-marca-textos">
                  <strong data-identidade-login-nome>AmAgenda</strong>
                </div>
              </header>

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
              <p class="mensagem" id="message" role="alert" aria-live="polite"></p>
            </div>

          </div>
        </div>
      </div>
    </main>

    <script>
      window.AMAGENDA_EMPRESA = {
        id: <?php echo $empresaId; ?>,
        nome: <?php echo json_encode($empresaNome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        slug: <?php echo json_encode($empresaSlug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
      };
    </script>

    <!-- Scripts -->
    <script src="/public/_auth/login.js"></script>
    <script src="/public/js/identidade-visual/identidade-visual-login.js?v=20260822_1"></script>
  </body>
</html>
