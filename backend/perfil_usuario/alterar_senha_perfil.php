<?php
declare(strict_types=1);

/**
 * HANDLER: perfil/alterar-senha
 * - Altera a senha do usuário logado
 * - Valida senha atual
 * - Valida nova senha e confirmação
 * - Atualiza usuario.senha_hash
 * - Retorna JSON padrão
 */

date_default_timezone_set('America/Sao_Paulo');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out([
        'ok'       => false,
        'code'     => 'METHOD_NOT_ALLOWED',
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
        'ok'       => false,
        'code'     => 'NOT_AUTHENTICATED',
        'user_msg' => 'Sessão expirada. Faça login novamente.',
    ], 401);
}

/* ==========================================================
   ENTRADA
========================================================== */
$senhaAtual     = trim((string)($_POST['senha_atual'] ?? ''));
$novaSenha      = trim((string)($_POST['nova_senha'] ?? ''));
$confirmarSenha = trim((string)($_POST['confirmar_senha'] ?? ''));

if ($senhaAtual === '') {
    out([
        'ok'       => false,
        'code'     => 'CURRENT_PASSWORD_REQUIRED',
        'user_msg' => 'Informe a senha atual.',
        'field'    => 'senha_atual',
    ], 422);
}

if ($novaSenha === '') {
    out([
        'ok'       => false,
        'code'     => 'NEW_PASSWORD_REQUIRED',
        'user_msg' => 'Informe a nova senha.',
        'field'    => 'nova_senha',
    ], 422);
}

if ($confirmarSenha === '') {
    out([
        'ok'       => false,
        'code'     => 'CONFIRM_PASSWORD_REQUIRED',
        'user_msg' => 'Confirme a nova senha.',
        'field'    => 'confirmar_senha',
    ], 422);
}

if (mb_strlen($novaSenha) < 6 || mb_strlen($novaSenha) > 72) {
    out([
        'ok'       => false,
        'code'     => 'NEW_PASSWORD_INVALID_LENGTH',
        'user_msg' => 'A nova senha deve ter entre 6 e 72 caracteres.',
        'field'    => 'nova_senha',
    ], 422);
}

if ($novaSenha !== $confirmarSenha) {
    out([
        'ok'       => false,
        'code'     => 'PASSWORD_CONFIRMATION_MISMATCH',
        'user_msg' => 'A confirmação da nova senha não confere.',
        'field'    => 'confirmar_senha',
    ], 422);
}

if ($senhaAtual === $novaSenha) {
    out([
        'ok'       => false,
        'code'     => 'PASSWORD_SAME_AS_CURRENT',
        'user_msg' => 'A nova senha deve ser diferente da senha atual.',
        'field'    => 'nova_senha',
    ], 422);
}

/* ==========================================================
   BANCO
========================================================== */
require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_servicos/auditoria.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out([
        'ok'       => false,
        'code'     => 'DB_CONN_MISSING',
        'user_msg' => 'Conexão com banco não encontrada.',
    ], 500);
}

if ($conexao->connect_errno) {
    out([
        'ok'       => false,
        'code'     => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco.',
    ], 500);
}

$conexao->set_charset('utf8mb4');

try {
    $sqlUser = "SELECT id_usuario, nome, senha_hash, status FROM usuario WHERE id_usuario = ? LIMIT 1";
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
            'ok'       => false,
            'code'     => 'USER_NOT_FOUND',
            'user_msg' => 'Usuário não encontrado.',
        ], 404);
    }

    $status = (string)($usuario['status'] ?? '');
    if ($status !== 'ativo') {
        out([
            'ok'       => false,
            'code'     => 'USER_INACTIVE',
            'user_msg' => 'Seu usuário não está ativo para alterar a senha.',
        ], 403);
    }

    $senhaHashBanco = (string)($usuario['senha_hash'] ?? '');
    if ($senhaHashBanco === '' || !password_verify($senhaAtual, $senhaHashBanco)) {
        out([
            'ok'       => false,
            'code'     => 'CURRENT_PASSWORD_INVALID',
            'user_msg' => 'A senha atual informada está incorreta.',
            'field'    => 'senha_atual',
        ], 422);
    }

    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    if ($novoHash === false || $novoHash === '') {
        throw new Exception('Falha ao gerar hash da nova senha.');
    }

    // A atualização da senha e o registro do fato passam a ser atômicos.
    $conexao->begin_transaction();
    $sqlUpdate = "UPDATE usuario SET senha_hash = ? WHERE id_usuario = ? LIMIT 1";
    $stmtUpdate = $conexao->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Prepare update senha_hash falhou.');
    }

    $stmtUpdate->bind_param('si', $novoHash, $idUsuario);
    $ok = $stmtUpdate->execute();
    $stmtUpdate->close();

    if (!$ok) {
        $conexao->rollback();
        out([
            'ok'       => false,
            'code'     => 'DB_UPDATE_ERROR',
            'user_msg' => 'Não foi possível atualizar a senha.',
        ], 500);
    }

    // Nenhuma senha, hash, tamanho ou característica é enviada ao serviço central.
    $tipoAtor = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
    $superAdminSemEmpresa = $tipoAtor === 'super_admin' && !((bool)($auth['modo_suporte'] ?? false));
    // Super Admin fora de suporte não possui empresa para a tabela empresarial de auditoria; o fluxo legado é preservado.
    if (!$superAdminSemEmpresa) {
        auditoriaRegistrar($conexao, 'perfil.senha_alterada', [
            'entidade_id' => $idUsuario,
            'entidade_rotulo' => (string)($usuario['nome'] ?? 'Usuário'),
            'descricao' => 'Alterou a própria senha.',
            'alteracoes' => ['senha_alterada' => ['antes' => false, 'depois' => true]],
            'contexto' => ['origem' => 'perfil_usuario'],
        ]);
    }
    $conexao->commit();

    out([
        'ok'       => true,
        'code'     => 'PASSWORD_UPDATED',
        'user_msg' => 'Senha alterada com sucesso.',
        'data'     => [
            'id_usuario' => $idUsuario,
        ],
    ], 200);

} catch (Throwable $e) {
    try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    error_log('[alterar_senha_perfil] ' . $e->getMessage());
    out([
        'ok'       => false,
        'code'     => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao alterar a senha.',
    ], 500);
}
