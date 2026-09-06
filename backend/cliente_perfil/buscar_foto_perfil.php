<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PERFIL DO CLIENTE - BUSCAR FOTO
|--------------------------------------------------------------------------
|
| Retorna somente os dados necessários ao modal "Meu Perfil" do cliente.
| Cliente e empresa são obtidos exclusivamente da sessão autenticada.
|
*/

require_once __DIR__ . '/../cliente/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

try {

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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

    $stmt = $conexao->prepare(
        "SELECT
            nome_completo,
            foto_perfil,
            senha_hash
         FROM cliente
         WHERE id_cliente = ?
           AND id_empresa = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Não foi possível preparar a consulta do perfil.'
        );
    }

    $stmt->bind_param(
        'ii',
        $idCliente,
        $idEmpresa
    );

    if (!$stmt->execute()) {
        $stmt->close();

        throw new RuntimeException(
            'Não foi possível consultar o perfil.'
        );
    }

    $resultado = $stmt->get_result();
    $cliente = $resultado ? $resultado->fetch_assoc() : null;

    $stmt->close();

    if (!$cliente) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'user_msg' => 'Cliente não encontrado.',
        ], 404);
    }

    $nome = trim((string)($cliente['nome_completo'] ?? ''));
    $foto = trim((string)($cliente['foto_perfil'] ?? ''));
    $senhaHash = trim((string)($cliente['senha_hash'] ?? ''));

    if ($nome === '') {
        $nome = 'Cliente';
    }

    if ($foto === '') {
        $foto = '/public/imagens/avatar-default.png';
    }

    out([
        'ok' => true,
        'code' => 'CLIENT_PROFILE_LOADED',
        'data' => [
            'nome' => $nome,
            'foto_url' => $foto,
            'tem_senha' => $senhaHash !== '',
        ],
    ]);

} catch (Throwable $e) {

    error_log(
        '[cliente_perfil_buscar_foto] ' . $e->getMessage()
    );

    out([
        'ok' => false,
        'code' => 'CLIENT_PROFILE_LOAD_ERROR',
        'user_msg' => 'Não foi possível carregar seu perfil agora.',
    ], 500);
}