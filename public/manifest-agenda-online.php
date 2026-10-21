<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/../backend/_config/sessao.php';
    amagendaConfigurarSessaoSegura();
    session_start();
}

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$slug = trim((string)($_SESSION['empresa_slug'] ?? ''));
$padraoSlug = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

if ($empresaId <= 0 || preg_match($padraoSlug, $slug) !== 1) {
    http_response_code(404);
    echo json_encode(['error' => 'Contexto da Agenda Online indisponível.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$entradaPublica = '/agendar/' . rawurlencode($slug);
$iconesOficiais = [
    [
        'src' => '/public/imagens/PWA/app-icon-192.png',
        'sizes' => '192x192',
        'type' => 'image/png',
        'purpose' => 'any',
    ],
    [
        'src' => '/public/imagens/PWA/app-icon-512.png',
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'any',
    ],
    [
        'src' => '/public/imagens/PWA/app-icon-maskable-192.png',
        'sizes' => '192x192',
        'type' => 'image/png',
        'purpose' => 'maskable',
    ],
    [
        'src' => '/public/imagens/PWA/app-icon-maskable-512.png',
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'maskable',
    ],
];

/*
 * Personalização (nome e ícones da empresa) somente quando o slug solicitado pela
 * página confere exatamente com o contexto validado da sessão. O slug da URL nunca
 * decide a empresa: ele apenas confirma que a página é da mesma empresa da sessão.
 * - ?slug=... (login-cliente.php): precisa ser idêntico ao slug da sessão.
 * - sem ?slug (páginas estáticas da área do cliente): exige cliente autenticado
 *   da mesma empresa do contexto da sessão.
 * Qualquer divergência (ex.: outra aba trocou o contexto) mantém o manifesto oficial.
 */
$slugSolicitado = isset($_GET['slug']) && is_string($_GET['slug']) ? trim($_GET['slug']) : null;
if ($slugSolicitado !== null) {
    $slugSolicitadoValido = preg_match($padraoSlug, $slugSolicitado) === 1;
    $personalizar = $slugSolicitadoValido && hash_equals($slug, $slugSolicitado);
    if ($slugSolicitadoValido && !$personalizar) {
        // Divergência: mantém a entrada pública da página que pediu o manifesto
        // (/agendar/{slug} revalida a empresa no servidor), sem dados de empresa.
        $entradaPublica = '/agendar/' . rawurlencode($slugSolicitado);
    }
} else {
    $clienteAuth = is_array($_SESSION['cliente_auth'] ?? null) ? $_SESSION['cliente_auth'] : [];
    $personalizar = (int)($clienteAuth['id_empresa'] ?? 0) === $empresaId;
}

$nomeApp = 'AmAgenda';
$nomeCurto = 'AmAgenda';
$iconesEmpresa = null;

if ($personalizar) {
    require_once __DIR__ . '/../backend/_config/conexao.php';
    require_once __DIR__ . '/../backend/_config/identidade_visual.php';

    $stmt = $conexao->prepare("SELECT e.nome, c.nome_exibicao, c.logo_empresa FROM empresa e LEFT JOIN configuracao_geral_empresa c ON c.id_empresa = e.id_empresa WHERE e.id_empresa = ? AND e.status = 'ativo' LIMIT 1");
    $empresa = [];
    if ($stmt) {
        $stmt->bind_param('i', $empresaId);
        $stmt->execute();
        $res = $stmt->get_result();
        $empresa = $res ? ($res->fetch_assoc() ?: []) : [];
        $stmt->close();
    }

    if ($empresa) {
        // nome_exibicao personalizado -> nome cadastrado da empresa -> AmAgenda.
        $nome = preg_replace('/\s+/u', ' ', trim((string)($empresa['nome_exibicao'] ?? ''))) ?? '';
        if ($nome === '') {
            $nome = preg_replace('/\s+/u', ' ', trim((string)($empresa['nome'] ?? ''))) ?? '';
        }
        if ($nome !== '') {
            $nomeApp = mb_strlen($nome, 'UTF-8') > 45 ? rtrim(mb_substr($nome, 0, 45, 'UTF-8')) : $nome;
            // short_name curto para caber sob o ícone da tela inicial.
            if (mb_strlen($nomeApp, 'UTF-8') <= 12) {
                $nomeCurto = $nomeApp;
            } else {
                $primeiraPalavra = explode(' ', $nomeApp)[0];
                $nomeCurto = mb_strlen($primeiraPalavra, 'UTF-8') <= 12
                    ? $primeiraPalavra
                    : rtrim(mb_substr($nomeApp, 0, 12, 'UTF-8'));
            }
        }
        // Os 4 ícones derivados do logo atual, somente se todos existirem.
        $iconesEmpresa = identidadeIconesPwaManifesto($empresaId, $empresa['logo_empresa'] ?? null);
    }
}

$manifesto = [
    'id' => $entradaPublica,
    'name' => $nomeApp,
    'short_name' => $nomeCurto,
    'description' => 'Agenda Online para agendamentos e acompanhamento de horários.',
    'lang' => 'pt-BR',
    'dir' => 'ltr',
    'start_url' => $entradaPublica,
    'scope' => '/',
    'display' => 'standalone',
    'theme_color' => '#111827',
    'background_color' => '#FFFFFF',
    'orientation' => 'portrait-primary',
    'icons' => $iconesEmpresa ?? $iconesOficiais,
];

echo json_encode($manifesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
