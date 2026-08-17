<?php
declare(strict_types=1);

/**
 * HANDLER: perfil/buscar-foto
 * - Busca a foto do usuário logado
 * - Lê da tabela usuario.foto_perfil
 * - Retorna JSON padrão
 */

date_default_timezone_set('America/Sao_Paulo');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

/* ==========================================================
   AUTH
========================================================== */
require_once __DIR__ . '/../_auth/require_auth.php';

$auth = $_SESSION['auth'] ?? null;
$idUsuario = (int)($auth['id_usuario'] ?? 0);

if ($idUsuario <= 0) {
    out([
        'ok' => false,
        'code' => 'NOT_AUTHENTICATED',
        'user_msg' => 'Sessão expirada. Faça login novamente.',
    ], 401);
}

/* ==========================================================
   BANCO
========================================================== */
require_once __DIR__ . '/../_config/conexao.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_MISSING',
        'user_msg' => 'Conexão com banco não encontrada.',
    ], 500);
}

if ($conexao->connect_errno) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco.',
    ], 500);
}

$conexao->set_charset('utf8mb4');

try {
    $sql = "SELECT id_usuario, nome, foto_perfil 
            FROM usuario 
            WHERE id_usuario = ? 
            LIMIT 1";

    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new Exception('Prepare select foto_perfil falhou.');
    }

    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();

    $result = $stmt->get_result();
    $usuario = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (!$usuario) {
        out([
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'user_msg' => 'Usuário não encontrado.',
        ], 404);
    }

    $fotoPerfil = trim((string)($usuario['foto_perfil'] ?? ''));
    $fotoDefault = '/public/imagens/avatar-default.png';

    $fotoUrl = $fotoDefault;
    $temFoto = false;

    if ($fotoPerfil !== '') {
        $baseDir = dirname(__DIR__, 2);
        $caminhoFisico = $baseDir . $fotoPerfil;

        if (is_file($caminhoFisico)) {
            $fotoUrl = $fotoPerfil . '?v=' . time();
            $temFoto = true;
        }
    }

    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        $_SESSION['auth'] = [];
    }

    $_SESSION['auth']['foto_perfil'] = $temFoto ? $fotoPerfil : null;

    out([
        'ok' => true,
        'code' => 'PHOTO_FOUND',
        'user_msg' => $temFoto
            ? 'Foto de perfil carregada com sucesso.'
            : 'Usuário sem foto cadastrada. Exibindo imagem padrão.',
        'data' => [
            'id_usuario'   => (int)$usuario['id_usuario'],
            'nome'         => (string)$usuario['nome'],
            'tem_foto'     => $temFoto,
            'foto_perfil'  => $temFoto ? $fotoPerfil : null,
            'foto_url'     => $fotoUrl,
            'foto_default' => $fotoDefault,
        ],
    ], 200);

} catch (Throwable $e) {
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao buscar a foto do perfil.',
    ], 500);
}