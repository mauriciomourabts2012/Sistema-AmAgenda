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

try {

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.',
        ], 405);
    }

    $sessao = exigirSessaoCliente();
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
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_UPLOAD_ERROR',
            'user_msg' => 'Não foi possível receber a imagem.',
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
    | LIMITE DE 5 MB
    |--------------------------------------------------------------------------
    */

    $limiteBytes = 5 * 1024 * 1024;

    if ($tamanho <= 0 || $tamanho > $limiteBytes) {
        out([
            'ok' => false,
            'code' => 'PROFILE_PHOTO_SIZE_INVALID',
            'user_msg' => 'A imagem deve possuir no máximo 5 MB.',
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

    if ($infoImagem === false) {
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

    $diretorio = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'public'
        . DIRECTORY_SEPARATOR . 'imagens'
        . DIRECTORY_SEPARATOR . 'clientes';

    if (!is_dir($diretorio)) {
        throw new RuntimeException(
            'Diretório de fotos dos clientes não encontrado.'
        );
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

    $fotoPublica = '/public/imagens/clientes/' . $nomeArquivo;

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

    if (
        $fotoAnterior !== '' &&
        str_starts_with(
            $fotoAnterior,
            '/public/imagens/clientes/'
        )
    ) {
        $caminhoAnterior = parse_url(
            $fotoAnterior,
            PHP_URL_PATH
        );

        $nomeAnterior = basename(
            (string)$caminhoAnterior
        );

        $arquivoAnterior = $diretorio
            . DIRECTORY_SEPARATOR
            . $nomeAnterior;

        if (
            $arquivoAnterior !== $arquivoNovo &&
            is_file($arquivoAnterior)
        ) {
            @unlink($arquivoAnterior);
        }
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