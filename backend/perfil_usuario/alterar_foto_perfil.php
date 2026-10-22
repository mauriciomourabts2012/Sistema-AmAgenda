<?php
declare(strict_types=1);

/**
 * HANDLER: perfil/alterar-foto
 * - Atualiza a foto do usuário logado
 * - Salva arquivo em: /public/uploads/usuarios
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
require_once __DIR__ . '/../_auth/csrf.php';
csrfValidarSessao();

/* ==========================================================
   HELPERS
========================================================== */
function mapUploadError(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE   => 'A imagem excede o limite aceito pelo servidor. O limite da foto de perfil é 10 MB.',
        UPLOAD_ERR_FORM_SIZE  => 'A imagem excede o limite de 10 MB.',
        UPLOAD_ERR_PARTIAL    => 'O upload da imagem foi enviado parcialmente.',
        UPLOAD_ERR_NO_FILE    => 'Selecione uma imagem para continuar.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária de upload não encontrada.',
        UPLOAD_ERR_CANT_WRITE => 'Não foi possível gravar o arquivo no disco.',
        UPLOAD_ERR_EXTENSION  => 'O upload foi bloqueado por uma extensão do servidor.',
        default               => 'Erro ao enviar a imagem.',
    };
}

function perfilIniBytes(string $valor): int
{
    $valor = trim($valor);
    if ($valor === '') return 0;
    $numero = (float)$valor;
    return match (strtolower(substr($valor, -1))) {
        'g' => (int)round($numero * 1024 * 1024 * 1024),
        'm' => (int)round($numero * 1024 * 1024),
        'k' => (int)round($numero * 1024),
        default => (int)$numero,
    };
}

function perfilRequisicaoExcedePostMax(): bool
{
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = perfilIniBytes((string)ini_get('post_max_size'));
    return $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes;
}

/* ==========================================================
   VALIDAÇÃO DO ARQUIVO
========================================================== */
if (perfilRequisicaoExcedePostMax()) {
    out([
        'ok' => false,
        'code' => 'REQUEST_TOO_LARGE',
        'user_msg' => 'A requisição excede o limite aceito pelo servidor. A foto de perfil pode ter até 10 MB.',
    ], 413);
}

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

$maxBytes = 10 * 1024 * 1024;
if ($tamanho > $maxBytes) {
    out([
        'ok' => false,
        'code' => 'FILE_TOO_LARGE',
        'user_msg' => 'A imagem deve ter no máximo 10 MB.',
    ], 422);
}

if (!class_exists('finfo')) {
    error_log('[perfil_usuario_alterar_foto] fileinfo indisponível');
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

$imageInfo = @getimagesize($tmpName);
if ($imageInfo === false || (string)($imageInfo['mime'] ?? '') !== $mime) {
    out([
        'ok' => false,
        'code' => 'INVALID_IMAGE_CONTENT',
        'user_msg' => 'O arquivo selecionado não contém uma imagem válida.',
    ], 422);
}

$largura = (int)($imageInfo[0] ?? 0);
$altura = (int)($imageInfo[1] ?? 0);
if ($largura <= 0 || $altura <= 0 || $largura > 8000 || $altura > 8000) {
    out([
        'ok' => false,
        'code' => 'INVALID_IMAGE_DIMENSIONS',
        'user_msg' => 'A imagem possui dimensões inválidas ou muito grandes.',
    ], 422);
}

/* ==========================================================
   PASTA DE DESTINO
========================================================== */
$baseDir = dirname(__DIR__, 2);
$publicDirReal = realpath($baseDir . '/public');
$uploadsRootConfigurado = $baseDir . '/public/uploads';
$uploadsRootReal = realpath($uploadsRootConfigurado);

if (
    $publicDirReal === false ||
    $uploadsRootReal === false ||
    !is_dir($uploadsRootReal) ||
    is_link($uploadsRootConfigurado) ||
    dirname($uploadsRootReal) !== $publicDirReal
) {
    error_log('[perfil_usuario_alterar_foto] raiz de uploads ausente ou inválida');
    out([
        'ok' => false,
        'code' => 'UPLOAD_ROOT_INVALID',
        'user_msg' => 'Não foi possível preparar a pasta de destino.',
    ], 500);
}

$uploadDirConfigurado = $uploadsRootReal . DIRECTORY_SEPARATOR . 'usuarios';

if (!is_dir($uploadDirConfigurado)) {
    if (!is_writable($uploadsRootReal)) {
        error_log('[perfil_usuario_alterar_foto] raiz de uploads sem escrita para criar diretório');
        out([
            'ok' => false,
            'code' => 'UPLOAD_ROOT_NOT_WRITABLE',
            'user_msg' => 'Não foi possível preparar a pasta de destino.',
        ], 500);
    }

    if (!@mkdir($uploadDirConfigurado, 0775, false) && !is_dir($uploadDirConfigurado)) {
        error_log('[perfil_usuario_alterar_foto] falha ao criar diretório de destino');
        out([
            'ok' => false,
            'code' => 'UPLOAD_DIR_CREATE_ERROR',
            'user_msg' => 'Não foi possível preparar a pasta de destino.',
        ], 500);
    }
}

$uploadDir = realpath($uploadDirConfigurado);
if (
    $uploadDir === false ||
    !is_dir($uploadDir) ||
    is_link($uploadDirConfigurado) ||
    dirname($uploadDir) !== $uploadsRootReal
) {
    error_log('[perfil_usuario_alterar_foto] diretório de destino fora da raiz autorizada');
    out([
        'ok' => false,
        'code' => 'UPLOAD_DIR_INVALID',
        'user_msg' => 'Não foi possível preparar a pasta de destino.',
    ], 500);
}

if (!is_writable($uploadDir)) {
    error_log('[perfil_usuario_alterar_foto] diretório de destino sem escrita');
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
    $caminhoRelativo = '/public/uploads/usuarios/' . $novoNome;

    clearstatcache(true, $uploadDirConfigurado);
    if (is_link($uploadDirConfigurado) || realpath($uploadDirConfigurado) !== $uploadDir) {
        throw new RuntimeException('Diretório de fotos dos usuários deixou de ser válido.');
    }

    if (!move_uploaded_file($tmpName, $destinoFisico)) {
        throw new RuntimeException('Falha ao mover o upload para o diretório de fotos de usuários.');
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

    $diretoriosFotosUsuario = [
        '/public/uploads/usuarios/' => $baseDir . '/public/uploads/usuarios',
        '/public/imagens/usuarios/' => $baseDir . '/public/imagens/usuarios',
    ];
    foreach ($diretoriosFotosUsuario as $prefixoPublico => $diretorioFisico) {
        if (!str_starts_with($fotoAntiga, $prefixoPublico)) {
            continue;
        }

        $nomeAntigo = substr($fotoAntiga, strlen($prefixoPublico));
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.(?:jpe?g|png|webp)$/i', $nomeAntigo) !== 1 ||
            basename($nomeAntigo) !== $nomeAntigo
        ) {
            break;
        }

        $diretorioReal = realpath($diretorioFisico);
        $fotoAntigaFisica = $diretorioFisico . DIRECTORY_SEPARATOR . $nomeAntigo;
        $fotoAntigaReal = realpath($fotoAntigaFisica);
        if (
            $diretorioReal !== false &&
            $fotoAntigaReal !== false &&
            str_starts_with($fotoAntigaReal, $diretorioReal . DIRECTORY_SEPARATOR) &&
            is_file($fotoAntigaReal) &&
            $fotoAntigaReal !== realpath($destinoFisico)
        ) {
            @unlink($fotoAntigaReal);
        }
        break;
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

    error_log(sprintf(
        '[perfil_usuario_alterar_foto] usuario=%d tipo=%s codigo=%s mensagem=%s',
        $idUsuario,
        get_class($e),
        (string)$e->getCode(),
        $e->getMessage()
    ));

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao atualizar a foto do perfil.',
    ], 500);
}
