<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PERFIL DO CLIENTE - ALTERAR FOTO
|--------------------------------------------------------------------------
|
| Atualiza exclusivamente a foto do cliente autenticado.
| id_cliente e id_empresa nunca são recebidos do navegador.
|
*/

require_once __DIR__ . '/../cliente/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

$arquivoNovo = null;
$transacaoAberta = false;

function clientePerfilIniBytes(string $valor): int
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

function clientePerfilRequisicaoExcedePostMax(): bool
{
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = clientePerfilIniBytes((string)ini_get('post_max_size'));
    return $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes;
}

try {

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.',
        ], 405);
    }

    $sessao = exigirSessaoCliente();
    require_once __DIR__ . '/../_auth/csrf.php';
    csrfValidarSessao();
    $clienteSessao = buscarClienteDaSessao($conexao, $sessao);

    if ($clienteSessao === null) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'user_msg' => 'Cliente não encontrado.',
        ], 404);
    }

    $idCliente = (int)$clienteSessao['id_cliente'];
    $idEmpresa = (int)$sessao['id_empresa'];

    if (clientePerfilRequisicaoExcedePostMax()) {
        out([
            'ok' => false,
            'code' => 'REQUEST_TOO_LARGE',
            'user_msg' => 'A requisição excede o limite aceito pelo servidor. A foto de perfil pode ter até 10 MB.',
        ], 413);
    }

    if (
        !isset($_FILES['perfil_foto']) ||
        !is_array($_FILES['perfil_foto'])
    ) {
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_REQUIRED',
            'user_msg' => 'Selecione uma imagem.',
        ], 422);
    }

    $arquivo = $_FILES['perfil_foto'];

    $erroUpload = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($erroUpload !== UPLOAD_ERR_OK) {
        $mensagemUpload = match ($erroUpload) {
            UPLOAD_ERR_INI_SIZE => 'A imagem excede o limite aceito pelo servidor. O limite da foto de perfil é 10 MB.',
            UPLOAD_ERR_FORM_SIZE => 'A imagem excede o limite de 10 MB.',
            UPLOAD_ERR_PARTIAL => 'O upload da imagem foi enviado parcialmente.',
            UPLOAD_ERR_NO_FILE => 'Selecione uma imagem.',
            UPLOAD_ERR_NO_TMP_DIR => 'A pasta temporária de upload não está disponível.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo enviado.',
            UPLOAD_ERR_EXTENSION => 'O upload foi bloqueado por uma extensão do servidor.',
            default => 'Não foi possível receber a imagem.',
        };
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_UPLOAD_ERROR',
            'user_msg' => $mensagemUpload,
        ], 422);
    }

    $arquivoTemporario = (string)($arquivo['tmp_name'] ?? '');
    $tamanho = (int)($arquivo['size'] ?? 0);

    if (
        $arquivoTemporario === '' ||
        !is_uploaded_file($arquivoTemporario)
    ) {
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_INVALID_UPLOAD',
            'user_msg' => 'Arquivo de imagem inválido.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | LIMITE DE 10 MB
    |--------------------------------------------------------------------------
    */

    $limiteBytes = 10 * 1024 * 1024;

    if ($tamanho <= 0 || $tamanho > $limiteBytes) {
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_SIZE_INVALID',
            'user_msg' => 'A imagem deve possuir no máximo 10 MB.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | MIME REAL
    |--------------------------------------------------------------------------
    */

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($arquivoTemporario);

    $tiposPermitidos = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($tiposPermitidos[$mime])) {
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_TYPE_INVALID',
            'user_msg' => 'Use uma imagem JPG, PNG ou WebP.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÃO REAL DA IMAGEM
    |--------------------------------------------------------------------------
    */

    $infoImagem = @getimagesize($arquivoTemporario);

    if ($infoImagem === false || (string)($infoImagem['mime'] ?? '') !== $mime) {
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_INVALID_IMAGE',
            'user_msg' => 'O arquivo selecionado não é uma imagem válida.',
        ], 422);
    }

    $largura = (int)($infoImagem[0] ?? 0);
    $altura = (int)($infoImagem[1] ?? 0);

    if (
        $largura <= 0 ||
        $altura <= 0 ||
        $largura > 8000 ||
        $altura > 8000
    ) {
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_DIMENSION_INVALID',
            'user_msg' => 'As dimensões da imagem são inválidas.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | DIRETÓRIO CONTROLADO
    |--------------------------------------------------------------------------
    */

    $raizProjeto = dirname(__DIR__, 2);
    $publicDirReal = realpath($raizProjeto . DIRECTORY_SEPARATOR . 'public');
    $uploadsRootConfigurado = $raizProjeto
        . DIRECTORY_SEPARATOR . 'public'
        . DIRECTORY_SEPARATOR . 'uploads';
    $uploadsRootReal = realpath($uploadsRootConfigurado);

    if (
        $publicDirReal === false ||
        $uploadsRootReal === false ||
        !is_dir($uploadsRootReal) ||
        is_link($uploadsRootConfigurado) ||
        dirname($uploadsRootReal) !== $publicDirReal
    ) {
        throw new RuntimeException('Raiz de uploads ausente ou inválida.');
    }

    $diretorioConfigurado = $uploadsRootReal
        . DIRECTORY_SEPARATOR . 'clientes';

    if (!is_dir($diretorioConfigurado)) {
        if (!is_writable($uploadsRootReal)) {
            throw new RuntimeException('Raiz de uploads sem escrita para criar o diretório de clientes.');
        }

        if (!@mkdir($diretorioConfigurado, 0775, false) && !is_dir($diretorioConfigurado)) {
            throw new RuntimeException('Falha ao criar o diretório de fotos dos clientes.');
        }
    }

    $diretorio = realpath($diretorioConfigurado);
    if (
        $diretorio === false ||
        !is_dir($diretorio) ||
        is_link($diretorioConfigurado) ||
        dirname($diretorio) !== $uploadsRootReal
    ) {
        throw new RuntimeException('Diretório de fotos dos clientes fora da raiz autorizada.');
    }

    if (!is_writable($diretorio)) {
        throw new RuntimeException(
            'Diretório de fotos dos clientes sem permissão de escrita.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FOTO ATUAL
    |--------------------------------------------------------------------------
    */

    $stmtAtual = $conexao->prepare(
        "SELECT foto_perfil
         FROM cliente
         WHERE id_cliente = ?
           AND id_empresa = ?
         LIMIT 1"
    );

    if (!$stmtAtual) {
        throw new RuntimeException(
            'Não foi possível consultar a foto atual.'
        );
    }

    $stmtAtual->bind_param(
        'ii',
        $idCliente,
        $idEmpresa
    );

    if (!$stmtAtual->execute()) {
        $stmtAtual->close();

        throw new RuntimeException(
            'Não foi possível consultar a foto atual.'
        );
    }

    $resultadoAtual = $stmtAtual->get_result();
    $registroAtual = $resultadoAtual
        ? $resultadoAtual->fetch_assoc()
        : null;

    $stmtAtual->close();

    if (!$registroAtual) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'user_msg' => 'Cliente não encontrado.',
        ], 404);
    }

    $fotoAnterior = trim(
        (string)($registroAtual['foto_perfil'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | NOVO ARQUIVO
    |--------------------------------------------------------------------------
    */

    $extensao = $tiposPermitidos[$mime];
    $identificador = bin2hex(random_bytes(8));

    $nomeArquivo = sprintf(
        'cliente_%d_%s.%s',
        $idCliente,
        $identificador,
        $extensao
    );

    $arquivoNovo = $diretorio
        . DIRECTORY_SEPARATOR
        . $nomeArquivo;

    $fotoPublica = '/public/uploads/clientes/' . $nomeArquivo;

    clearstatcache(true, $diretorioConfigurado);
    if (is_link($diretorioConfigurado) || realpath($diretorioConfigurado) !== $diretorio) {
        throw new RuntimeException('Diretório de fotos dos clientes deixou de ser válido.');
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSAÇÃO + MOVIMENTAÇÃO
    |--------------------------------------------------------------------------
    */

    $conexao->begin_transaction();
    $transacaoAberta = true;

    if (!move_uploaded_file($arquivoTemporario, $arquivoNovo)) {
        throw new RuntimeException(
            'Não foi possível armazenar a nova foto.'
        );
    }

    $stmt = $conexao->prepare(
        "UPDATE cliente
         SET foto_perfil = ?
         WHERE id_cliente = ?
           AND id_empresa = ?"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Não foi possível preparar a atualização da foto.'
        );
    }

    $stmt->bind_param(
        'sii',
        $fotoPublica,
        $idCliente,
        $idEmpresa
    );

    if (!$stmt->execute()) {
        $stmt->close();

        throw new RuntimeException(
            'Não foi possível atualizar a foto do perfil.'
        );
    }

    $stmt->close();

    $conexao->commit();
    $transacaoAberta = false;

    /*
    |--------------------------------------------------------------------------
    | REMOVE SOMENTE FOTO ANTIGA CONTROLADA
    |--------------------------------------------------------------------------
    */

    $diretoriosFotosCliente = [
        '/public/uploads/clientes/' => $raizProjeto . '/public/uploads/clientes',
        '/public/imagens/clientes/' => $raizProjeto . '/public/imagens/clientes',
    ];
    foreach ($diretoriosFotosCliente as $prefixoPublico => $diretorioFisico) {
        if (!str_starts_with($fotoAnterior, $prefixoPublico)) {
            continue;
        }

        $nomeAnterior = substr($fotoAnterior, strlen($prefixoPublico));
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.(?:jpe?g|png|webp)$/i', $nomeAnterior) !== 1 ||
            basename($nomeAnterior) !== $nomeAnterior
        ) {
            break;
        }

        $diretorioReal = realpath($diretorioFisico);
        $arquivoAnteriorReal = realpath($diretorioFisico . DIRECTORY_SEPARATOR . $nomeAnterior);
        if (
            $diretorioReal !== false &&
            $arquivoAnteriorReal !== false &&
            str_starts_with($arquivoAnteriorReal, $diretorioReal . DIRECTORY_SEPARATOR) &&
            is_file($arquivoAnteriorReal) &&
            $arquivoAnteriorReal !== realpath($arquivoNovo)
        ) {
            @unlink($arquivoAnteriorReal);
        }
        break;
    }

    out([
        'ok' => true,
        'code' => 'CLIENT_PROFILE_PHOTO_UPDATED',
        'user_msg' => 'Foto atualizada com sucesso.',
        'data' => [
            'foto_url' => $fotoPublica,
        ],
    ]);

} catch (Throwable $e) {

    if ($transacaoAberta) {
        try {
            $conexao->rollback();
        } catch (Throwable) {
            // Preserva a exceção original.
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LIMPEZA COMPENSATÓRIA
    |--------------------------------------------------------------------------
    */

    if (
        is_string($arquivoNovo) &&
        $arquivoNovo !== '' &&
        is_file($arquivoNovo)
    ) {
        @unlink($arquivoNovo);
    }

    error_log(
        '[cliente_perfil_alterar_foto] ' . $e->getMessage()
    );

    out([
        'ok' => false,
        'code' => 'CLIENT_PROFILE_PHOTO_UPDATE_ERROR',
        'user_msg' => 'Não foi possível atualizar sua foto agora.',
    ], 500);
}
