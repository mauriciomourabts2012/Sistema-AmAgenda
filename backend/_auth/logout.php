<?php
declare(strict_types=1);

// ✅ NÃO defina header aqui (api_central já define)
// ✅ NÃO redefina out() se já existir
if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.'
    ], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Gera slug amigável da empresa
 */
if (!function_exists('slugify_empresa')) {
    function slugify_empresa(string $texto): string
    {
        $texto = trim(mb_strtolower($texto, 'UTF-8'));

        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c',
            'ñ'=>'n'
        ];

        $texto = strtr($texto, $map);
        $texto = preg_replace('/[^a-z0-9]+/u', '-', $texto) ?? '';
        $texto = trim($texto, '-');

        return $texto;
    }
}

/**
 * Lê empresa_id da sessão em vários formatos
 */
function getEmpresaId(array $session): int
{
    if (!empty($session['empresa_id'])) {
        return (int)$session['empresa_id'];
    }

    if (!empty($session['auth']) && is_array($session['auth'])) {
        if (!empty($session['auth']['empresa_id'])) {
            return (int)$session['auth']['empresa_id'];
        }

        if (!empty($session['auth']['empresa']['id'])) {
            return (int)$session['auth']['empresa']['id'];
        }
    }

    return 0;
}

/**
 * Lê nome/slug da empresa da sessão em vários formatos
 */
function getEmpresaNomeOuSlug(array $session): string
{
    $candidatos = [];

    if (!empty($session['empresa_nome'])) {
        $candidatos[] = (string)$session['empresa_nome'];
    }

    if (!empty($session['empresa_slug'])) {
        $candidatos[] = (string)$session['empresa_slug'];
    }

    if (!empty($session['slug_empresa'])) {
        $candidatos[] = (string)$session['slug_empresa'];
    }

    if (!empty($session['auth']) && is_array($session['auth'])) {
        if (!empty($session['auth']['empresa_nome'])) {
            $candidatos[] = (string)$session['auth']['empresa_nome'];
        }

        if (!empty($session['auth']['empresa_slug'])) {
            $candidatos[] = (string)$session['auth']['empresa_slug'];
        }

        if (!empty($session['auth']['empresa']['nome'])) {
            $candidatos[] = (string)$session['auth']['empresa']['nome'];
        }

        if (!empty($session['auth']['empresa']['slug'])) {
            $candidatos[] = (string)$session['auth']['empresa']['slug'];
        }
    }

    foreach ($candidatos as $valor) {
        $valor = trim($valor);
        if ($valor !== '') {
            return $valor;
        }
    }

    return '';
}

/**
 * Monta URL de retorno do logout
 */
function detectarRedirectLogout(array $session): string
{
    // SUPER ADMIN
    if (!empty($session['superadmin_id'])) {
        return '/public/views/login-super-admin.html';
    }

    // USUÁRIO DE EMPRESA / CLIENTE
    $empresaId = getEmpresaId($session);
    $empresaNome = getEmpresaNomeOuSlug($session);

    if ($empresaId > 0 && $empresaNome !== '') {
        $slug = slugify_empresa($empresaNome);

        $qs = http_build_query([
            'empresa' => $empresaId,
            'nome'    => $slug
        ]);

        // ✅ volta para a entrada que recria a sessão da empresa
        return '/login.php?' . $qs;
    }

    // fallback final
    return '/public/views/login-super-admin.html';
}

// ✅ Descobre a URL ANTES de destruir a sessão
$redirectUrl = detectarRedirectLogout($_SESSION);

$authLogout = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
$finalizandoSuporte = mb_strtolower(trim((string)($authLogout['tipo_usuario'] ?? '')), 'UTF-8') === 'super_admin'
    && (bool)($authLogout['modo_suporte'] ?? false)
    && getEmpresaId($_SESSION) > 0;

if ($finalizandoSuporte) {
    try {
        require_once __DIR__ . '/../_config/conexao.php';
        require_once __DIR__ . '/../_servicos/auditoria.php';
        if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
            throw new RuntimeException('Conexão indisponível para auditoria.');
        }
        $conexao->set_charset('utf8mb4');
        auditoriaRegistrar($conexao, 'suporte.finalizado', [
            'entidade_rotulo' => 'Modo suporte',
            'descricao' => 'Finalizou o modo suporte.',
            'contexto' => ['origem' => 'logout'],
        ]);
    } catch (Throwable $e) {
        error_log('[auditoria_suporte] Não foi possível registrar a finalização do modo suporte.');
        out([
            'ok' => false,
            'code' => 'SUPPORT_AUDIT_ERROR',
            'user_msg' => 'Não foi possível finalizar o modo suporte.',
        ], 500);
    }
}

// Limpa dados da sessão
$_SESSION = [];

// Remove cookie da sessão
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}

// Destrói a sessão
session_destroy();

out([
    'ok' => true,
    'code' => 'LOGOUT_OK',
    'user_msg' => 'Você saiu do sistema com sucesso.',
    'redirect_url' => $redirectUrl
]);
