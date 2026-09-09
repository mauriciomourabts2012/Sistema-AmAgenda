<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PERFIL DO CLIENTE - ALTERAR SENHA
|--------------------------------------------------------------------------
|
| Permite:
| - definir a senha definitiva no primeiro acesso;
| - alterar posteriormente a senha mediante validação da senha atual.
| - redefinir a senha após OTP de recuperação autorizado na sessão.
|
| No primeiro acesso:
| - senha_hash está NULL ou vazia;
| - senha_atual não é obrigatória;
| - o cliente já comprovou sua identidade ao autenticar-se;
| - após a troca, primeiro_acesso_em recebe a data/hora atual.
|
| Cliente e empresa vêm exclusivamente da sessão autenticada.
|
*/

require_once __DIR__ . '/../cliente/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

try {

    /*
    |--------------------------------------------------------------------------
    | MÉTODO
    |--------------------------------------------------------------------------
    */

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.',
        ], 405);
    }

    /*
    |--------------------------------------------------------------------------
    | SESSÃO DO CLIENTE
    |--------------------------------------------------------------------------
    */

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

    $recuperacao = $_SESSION['cliente_recuperacao_senha'] ?? null;
    $recuperacaoAutorizada = is_array($recuperacao)
        && (int)($recuperacao['id_empresa'] ?? 0) === $idEmpresa
        && (int)($recuperacao['id_cliente'] ?? 0) === $idCliente
        && hash_equals(
            (string)($sessao['telefone'] ?? ''),
            (string)($recuperacao['telefone'] ?? '')
        )
        && (int)($recuperacao['expira_em'] ?? 0) > time();

    if (!$recuperacaoAutorizada && is_array($recuperacao)) {
        unset($_SESSION['cliente_recuperacao_senha']);
    }

    /*
    |--------------------------------------------------------------------------
    | PAYLOAD
    |--------------------------------------------------------------------------
    */

    $entrada = json_decode(
        file_get_contents('php://input') ?: '',
        true
    );

    if (!is_array($entrada)) {
        $entrada = $_POST;
    }

    $senhaAtual = (string)($entrada['senha_atual'] ?? '');
    $novaSenha = (string)($entrada['nova_senha'] ?? '');
    $confirmarSenha = (string)($entrada['confirmar_senha'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | CREDENCIAL E ESTADO DO PRIMEIRO ACESSO
    |--------------------------------------------------------------------------
    */

    $stmt = $conexao->prepare(
        "SELECT
            senha_hash
         FROM cliente
         WHERE id_cliente = ?
           AND id_empresa = ?
           AND status = 'ativo'
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Não foi possível preparar a consulta da senha.'
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
            'Não foi possível consultar a credencial do cliente.'
        );
    }

    $resultado = $stmt->get_result();

    $registro = $resultado
        ? $resultado->fetch_assoc()
        : null;

    $stmt->close();

    if (!$registro) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'user_msg' => 'Cliente não encontrado.',
        ], 404);
    }

    $hashAtual = trim(
        (string)($registro['senha_hash'] ?? '')
    );

    $temSenha = $hashAtual !== '';

    $primeiroAcesso = !$temSenha;

    /*
    | Uma credencial existente só dispensa a senha atual quando a sessão
    | possui autorização de recuperação OTP válida para este cliente.
    */

    $exigirSenhaAtual = $temSenha && !$recuperacaoAutorizada;

    /*
    |--------------------------------------------------------------------------
    | CAMPOS OBRIGATÓRIOS
    |--------------------------------------------------------------------------
    */

    $campos = [];

    if ($exigirSenhaAtual && $senhaAtual === '') {
        $campos['senha_atual'] = 'Informe sua senha atual.';
    }

    if ($novaSenha === '') {
        $campos['nova_senha'] = 'Informe a nova senha.';
    }

    if ($confirmarSenha === '') {
        $campos['confirmar_senha'] = 'Confirme a nova senha.';
    }

    if ($campos !== []) {
        out([
            'ok' => false,
            'code' => 'CLIENT_PASSWORD_FIELDS_REQUIRED',
            'user_msg' => 'Preencha os campos obrigatórios.',
            'fields' => $campos,
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | POLÍTICA DA NOVA SENHA
    |--------------------------------------------------------------------------
    */

    $tamanhoSenha = strlen($novaSenha);

    if ($tamanhoSenha < 6 || $tamanhoSenha > 72) {
        out([
            'ok' => false,
            'code' => 'CLIENT_PASSWORD_INVALID_LENGTH',
            'user_msg' => 'A nova senha deve possuir entre 6 e 72 caracteres.',
            'fields' => [
                'nova_senha' => 'Use entre 6 e 72 caracteres.',
            ],
        ], 422);
    }

    if (!hash_equals($novaSenha, $confirmarSenha)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_PASSWORD_CONFIRMATION_MISMATCH',
            'user_msg' => 'A confirmação da nova senha não confere.',
            'fields' => [
                'confirmar_senha' => 'As senhas não coincidem.',
            ],
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÃO DA SENHA EXISTENTE
    |--------------------------------------------------------------------------
    */

    if ($exigirSenhaAtual) {
        if (!password_verify($senhaAtual, $hashAtual)) {
            out([
                'ok' => false,
                'code' => 'CLIENT_CURRENT_PASSWORD_INVALID',
                'user_msg' => 'A senha atual está incorreta.',
                'fields' => [
                    'senha_atual' => 'Senha atual incorreta.',
                ],
            ], 422);
        }
    }

    /* Impede reutilizar a credencial definitiva existente. */

    if ($temSenha && password_verify($novaSenha, $hashAtual)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_PASSWORD_UNCHANGED',
            'user_msg' => 'A nova senha deve ser diferente da senha atual.',
            'fields' => [
                'nova_senha' => 'Escolha uma senha diferente da senha atual.',
            ],
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | NOVO HASH
    |--------------------------------------------------------------------------
    */

    $novoHash = password_hash(
        $novaSenha,
        PASSWORD_DEFAULT
    );

    if ($novoHash === false) {
        throw new RuntimeException(
            'Não foi possível gerar o hash da nova senha.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ATUALIZAÇÃO
    |--------------------------------------------------------------------------
    |
    | COALESCE preserva primeiro_acesso_em quando ele já estiver preenchido.
    |
    */

    $stmtAtualizar = $conexao->prepare(
        "UPDATE cliente
         SET senha_hash = ?,
             primeiro_acesso_em = COALESCE(
                 primeiro_acesso_em,
                 CURRENT_TIMESTAMP
             )
         WHERE id_cliente = ?
           AND id_empresa = ?
           AND status = 'ativo'"
    );

    if (!$stmtAtualizar) {
        throw new RuntimeException(
            'Não foi possível preparar a atualização da senha.'
        );
    }

    $stmtAtualizar->bind_param(
        'sii',
        $novoHash,
        $idCliente,
        $idEmpresa
    );

    if (!$stmtAtualizar->execute()) {
        $stmtAtualizar->close();

        throw new RuntimeException(
            'Não foi possível atualizar a senha.'
        );
    }

    if ($stmtAtualizar->affected_rows < 1) {
        $stmtAtualizar->close();

        throw new RuntimeException(
            'Nenhum cadastro de cliente foi atualizado.'
        );
    }

    $stmtAtualizar->close();

    if ($recuperacaoAutorizada) {
        unset($_SESSION['cliente_recuperacao_senha']);
    }

    /*
    |--------------------------------------------------------------------------
    | RESPOSTA
    |--------------------------------------------------------------------------
    */

    out([
        'ok' => true,
        'code' => $recuperacaoAutorizada
            ? 'CLIENT_PASSWORD_RECOVERED'
            : ($primeiroAcesso
                ? 'CLIENT_FIRST_PASSWORD_DEFINED'
                : 'CLIENT_PASSWORD_UPDATED'),
        'user_msg' => $recuperacaoAutorizada
            ? 'Sua senha foi redefinida com sucesso.'
            : ($primeiroAcesso
                ? 'Sua nova senha foi definida com sucesso.'
                : 'Senha alterada com sucesso.'),
        'data' => [
            'tem_senha' => true,
            'primeiro_acesso_concluido' => true,
        ],
    ]);

} catch (Throwable $e) {

    error_log(
        '[cliente_perfil_alterar_senha] ' . $e->getMessage()
    );

    out([
        'ok' => false,
        'code' => 'CLIENT_PASSWORD_UPDATE_ERROR',
        'user_msg' => 'Não foi possível atualizar sua senha agora.',
    ], 500);
}
