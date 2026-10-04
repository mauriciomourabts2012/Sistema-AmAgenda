<?php
declare(strict_types=1);

require_once __DIR__ . '/../_servicos/empresa.php';

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
 * Lê somente slug persistido da empresa da sessão em vários formatos.
 */
function getEmpresaSlugPersistidoSessao(array $session): string
{
    $candidatos = [];

    if (!empty($session['empresa_slug'])) {
        $candidatos[] = (string)$session['empresa_slug'];
    }

    if (!empty($session['slug_empresa'])) {
        $candidatos[] = (string)$session['slug_empresa'];
    }

    if (!empty($session['auth']) && is_array($session['auth'])) {
        if (!empty($session['auth']['empresa_slug'])) {
            $candidatos[] = (string)$session['auth']['empresa_slug'];
        }

        if (!empty($session['auth']['empresa']['slug'])) {
            $candidatos[] = (string)$session['auth']['empresa']['slug'];
        }
    }

    foreach ($candidatos as $valor) {
        $valor = trim($valor);
        if (empresaSlugEntradaValida($valor)) {
            return $valor;
        }
    }

    return '';
}

/**
 * Monta URL de retorno do logout
 */
function detectarRedirectLogout(array $session, ?mysqli $conexaoEmpresa = null): string
{
    // SUPER ADMIN
    if (!empty($session['superadmin_id'])) {
        return '/public/views/login-super-admin.html';
    }

    // USUÁRIO INTERNO (não super admin, não cliente): volta ao login central,
    // sem depender de link de empresa. Cliente OTP mantém o fluxo anterior.
    $authSessao = is_array($session['auth'] ?? null) ? $session['auth'] : [];
    if ((int)($authSessao['id_usuario'] ?? 0) > 0
        && mb_strtolower(trim((string)($authSessao['tipo_usuario'] ?? '')), 'UTF-8') !== 'super_admin'
        && empty($session['cliente_auth'])) {
        return '/public/views/login-empresa.php';
    }

    // USUÁRIO DE EMPRESA / CLIENTE
    $empresaId = getEmpresaId($session);
    $slug = getEmpresaSlugPersistidoSessao($session);

    if ($empresaId > 0 && $slug === '' && $conexaoEmpresa instanceof mysqli && !$conexaoEmpresa->connect_errno) {
        $stmt = $conexaoEmpresa->prepare('SELECT slug FROM empresa WHERE id_empresa = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $empresaId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                $slugBanco = trim((string)($row['slug'] ?? ''));
                if (empresaSlugEntradaValida($slugBanco)) {
                    $slug = $slugBanco;
                }
            }
            $stmt->close();
        }
    }

    if ($empresaId > 0 && $slug !== '') {
        // Volta pela URL publica estavel, que recria o mesmo contexto de empresa.
        return '/agendar/' . rawurlencode($slug);
    }

    // fallback final
    return '/public/views/login-super-admin.html';
}

// Descobre a URL antes de destruir a sessão sem tornar o logout dependente
// do banco. Se o chamador já disponibilizou uma conexão, ela pode recuperar
// o slug de uma sessão antiga; caso contrário, o fallback permanece seguro.
$conexaoEmpresaLogout = null;
if (isset($conexao) && $conexao instanceof mysqli) {
    $conexaoEmpresaLogout = $conexao;
} elseif (isset($ConexBD) && $ConexBD instanceof mysqli) {
    $conexaoEmpresaLogout = $ConexBD;
}
$redirectUrl = detectarRedirectLogout($_SESSION, $conexaoEmpresaLogout);

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
        // A indisponibilidade da auditoria não pode manter uma sessão de
        // suporte autenticada quando o usuário solicitou o logout completo.
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
