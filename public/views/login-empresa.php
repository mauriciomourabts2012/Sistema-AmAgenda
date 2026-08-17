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
    <link rel="stylesheet" href="../css/login/login-web.css" />
    <link rel="stylesheet" href="../css/login/login-mobile.css" />

    <link rel="shortcut icon" href="/Imagens/Log-Titulo.png" type="image/x-icon" />
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

            <!-- Lado da imagem -->
            <div class="imagem-left">
              <img src="../imagens/logo.png" alt="Mascote AmAgenda" class="imagem-desktop" />
            </div>

            <!-- Lado do formulário -->
            <div class="login-dados-right">
              <h2 class="h2-titulo">Bem Vindo<span class="AM-AGENDA">AM-AGENDA</span></h2>

              <a href="../views/login-cliente.php" class="voltar-cliente" aria-label="Voltar para login cliente"> ← </a>

              <div class="campo">
                <label for="email"></label>
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
                <label for="password"></label>
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
  </body>
</html>