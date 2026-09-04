<?php
declare(strict_types=1);

/**
 * HANDLER: perfil/alterar-foto
 * - Atualiza a foto do usuário logado
 * - Salva arquivo em: /public/imagens/usuarios
 * - Grava o caminho relativo em: usuario.foto_perfil
 * - Atualiza sessão
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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
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
   HELPERS
========================================================== */
function mapUploadError(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE  => 'A imagem excede o tamanho permitido.',
        UPLOAD_ERR_PARTIAL    => 'O upload da imagem foi enviado parcialmente.',
        UPLOAD_ERR_NO_FILE    => 'Selecione uma imagem para continuar.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária de upload não encontrada.',
        UPLOAD_ERR_CANT_WRITE => 'Não foi possível gravar o arquivo no disco.',
        UPLOAD_ERR_EXTENSION  => 'O upload foi bloqueado por uma extensão do servidor.',
        default               => 'Erro ao enviar a imagem.',
    };
}

/* ==========================================================
   VALIDAÇÃO DO ARQUIVO
========================================================== */
if (!isset($_FILES['perfil_foto'])) {
    out([
        'ok' => false,
        'code' => 'FILE_REQUIRED',
        'user_msg' => 'Selecione uma imagem para continuar.',
    ], 422);
}

$arquivo = $_FILES['perfil_foto'];

$uploadError = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    out([
        'ok' => false,
        'code' => 'UPLOAD_ERROR',
        'user_msg' => mapUploadError($uploadError),
    ], 422);
}

$tmpName = (string)($arquivo['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    out([
        'ok' => false,
        'code' => 'INVALID_UPLOAD',
        'user_msg' => 'Arquivo de upload inválido.',
    ], 422);
}

$tamanho = (int)($arquivo['size'] ?? 0);
if ($tamanho <= 0) {
    out([
        'ok' => false,
        'code' => 'EMPTY_FILE',
        'user_msg' => 'O arquivo enviado está vazio.',
    ], 422);
}

$maxBytes = 3 * 1024 * 1024; // 3MB
if ($tamanho > $maxBytes) {
    out([
        'ok' => false,
        'code' => 'FILE_TOO_LARGE',
        'user_msg' => 'A imagem deve ter no máximo 3MB.',
    ], 422);
}

if (!class_exists('finfo')) {
    out([
        'ok' => false,
        'code' => 'FILEINFO_NOT_AVAILABLE',
        'user_msg' => 'O servidor não possui suporte para validação de arquivo.',
    ], 500);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmpName);

$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

if (!isset($allowedMimes[$mime])) {
    out([
        'ok' => false,
        'code' => 'INVALID_IMAGE_TYPE',
        'user_msg' => 'Formato inválido. Envie JPG, PNG ou WEBP.',
    ], 422);
}

$ext = $allowedMimes[$mime];

/* ==========================================================
   PASTA DE DESTINO
========================================================== */
$baseDir = dirname(__DIR__, 2);
$uploadDir = $baseDir . '/public/imagens/usuarios';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true)) {
        out([
            'ok' => false,
            'code' => 'UPLOAD_DIR_CREATE_ERROR',
            'user_msg' => 'Não foi possível preparar a pasta de destino.',
        ], 500);
    }
}

if (!is_writable($uploadDir)) {
    out([
        'ok' => false,
        'code' => 'UPLOAD_DIR_NOT_WRITABLE',
        'user_msg' => 'A pasta de destino não possui permissão de escrita.',
    ], 500);
}

/* ==========================================================
   BANCO
========================================================== */
require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_servicos/notificacao.php';

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

$tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
$destinatarioTipo = $tipoUsuario === 'super_admin' ? 'super_admin' : 'usuario';
$idEmpresaNotificacao = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? 0);
$destinoFisico = null;
$arquivoNovoMovido = false;
$transacaoAtiva = false;

try {
    $sqlUser = "SELECT id_usuario, foto_perfil FROM usuario WHERE id_usuario = ? LIMIT 1";
    $stmtUser = $conexao->prepare($sqlUser);

    if (!$stmtUser) {
        throw new Exception('Prepare select usuário falhou.');
    }

    $stmtUser->bind_param('i', $idUsuario);
    $stmtUser->execute();

    $resUser = $stmtUser->get_result();
    $usuario = $resUser ? $resUser->fetch_assoc() : null;
    $stmtUser->close();

    if (!$usuario) {
        out([
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'user_msg' => 'Usuário não encontrado.',
        ], 404);
    }

    $fotoAntiga = trim((string)($usuario['foto_perfil'] ?? ''));

    try {
        $token = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $token = (string)time();
    }

    $novoNome = 'usuario_' . $idUsuario . '_' . $token . '.' . $ext;
    $destinoFisico = $uploadDir . '/' . $novoNome;
    $caminhoRelativo = '/public/imagens/usuarios/' . $novoNome;

    if (!move_uploaded_file($tmpName, $destinoFisico)) {
        out([
            'ok' => false,
            'code' => 'MOVE_UPLOAD_FAILED',
            'user_msg' => 'Não foi possível salvar a imagem enviada.',
        ], 500);
    }

    $arquivoNovoMovido = true;

    @chmod($destinoFisico, 0644);
    clearstatcache(true, $destinoFisico);

    $conexao->begin_transaction();
    $transacaoAtiva = true;

    $sqlUpdate = "UPDATE usuario SET foto_perfil = ? WHERE id_usuario = ? LIMIT 1";
    $stmtUpdate = $conexao->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Prepare update foto_perfil falhou.');
    }

    $stmtUpdate->bind_param('si', $caminhoRelativo, $idUsuario);
    $ok = $stmtUpdate->execute();

    if (!$ok) {
        $stmtUpdate->close();
        throw new Exception('Update foto_perfil falhou.');
    }

    $stmtUpdate->close();

    if ($idEmpresaNotificacao > 0) {
        $stmtNotificacoes = $conexao->prepare(
            "SELECT id_notificacao
               FROM notificacao
              WHERE destinatario_tipo = ?
                AND destinatario_id = ?
                AND id_empresa = ?
                AND codigo = 'perfil.foto_pendente'
                AND concluida_em IS NULL
                AND cancelada_em IS NULL
              FOR UPDATE"
        );
        if (!$stmtNotificacoes) {
            throw new Exception('Prepare localização da notificação de foto falhou.');
        }
        $stmtNotificacoes->bind_param('sii', $destinatarioTipo, $idUsuario, $idEmpresaNotificacao);
    } else {
        $stmtNotificacoes = $conexao->prepare(
            "SELECT id_notificacao
               FROM notificacao
              WHERE destinatario_tipo = ?
                AND destinatario_id = ?
                AND id_empresa IS NULL
                AND codigo = 'perfil.foto_pendente'
                AND concluida_em IS NULL
                AND cancelada_em IS NULL
              FOR UPDATE"
        );
        if (!$stmtNotificacoes) {
            throw new Exception('Prepare localização da notificação de foto falhou.');
        }
        $stmtNotificacoes->bind_param('si', $destinatarioTipo, $idUsuario);
    }

    if (!$stmtNotificacoes->execute()) {
        $stmtNotificacoes->close();
        throw new Exception('Consulta da notificação de foto falhou.');
    }

    $resultadoNotificacoes = $stmtNotificacoes->get_result();
    $idsNotificacoes = [];
    while ($resultadoNotificacoes && ($notificacao = $resultadoNotificacoes->fetch_assoc())) {
        $idsNotificacoes[] = (int)$notificacao['id_notificacao'];
    }
    $stmtNotificacoes->close();

    foreach ($idsNotificacoes as $idNotificacao) {
        notificacaoConcluir(
            $conexao,
            $idNotificacao,
            $destinatarioTipo,
            $idUsuario,
            $idEmpresaNotificacao > 0 ? $idEmpresaNotificacao : null
        );
    }

    $conexao->commit();
    $transacaoAtiva = false;
    $arquivoNovoMovido = false;

    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        $_SESSION['auth'] = [];
    }

    $_SESSION['auth']['foto_perfil'] = $caminhoRelativo;

    if ($fotoAntiga !== '' && str_starts_with($fotoAntiga, '/public/imagens/usuarios/')) {
        $fotoAntigaFisica = $baseDir . $fotoAntiga;

        if (
            is_file($fotoAntigaFisica) &&
            realpath($fotoAntigaFisica) !== realpath($destinoFisico)
        ) {
            @unlink($fotoAntigaFisica);
        }
    }

    out([
        'ok' => true,
        'code' => 'PHOTO_UPDATED',
        'user_msg' => 'Foto de perfil atualizada com sucesso.',
        'data' => [
            'id_usuario'  => $idUsuario,
            'foto_perfil' => $caminhoRelativo,
            'foto_url'    => $caminhoRelativo . '?v=' . time(),
        ],
    ], 200);

} catch (Throwable $e) {
    if ($transacaoAtiva) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
        }
    }

    if ($arquivoNovoMovido && is_string($destinoFisico) && is_file($destinoFisico)) {
        @unlink($destinoFisico);
    }

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao atualizar a foto do perfil.',
    ], 500);
}
