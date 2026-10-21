<?php
declare(strict_types=1);

/**
 * HANDLER: perfil/alterar-senha
 * - Altera a senha do usuário logado
 * - Valida senha atual
 * - Valida nova senha e confirmação pela política centralizada
 *   (backend/_regras/politica_senha.php)
 * - Senhas usadas exatamente como digitadas (sem trim), como no login
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
require_once __DIR__ . '/../_auth/csrf.php';
csrfValidarSessao();

require_once __DIR__ . '/../_regras/politica_senha.php';

/* ==========================================================
   ENTRADA
   As senhas não são aparadas nem normalizadas.
========================================================== */
$senhaAtual     = (string)($_POST['senha_atual'] ?? '');
$novaSenha      = (string)($_POST['nova_senha'] ?? '');
$confirmarSenha = (string)($_POST['confirmar_senha'] ?? '');

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

if (hash_equals($senhaAtual, $novaSenha)) {
    out([
        'ok'       => false,
        'code'     => 'PASSWORD_SAME_AS_CURRENT',
        'user_msg' => politicaSenhaMensagem('PASSWORD_SAME_AS_CURRENT'),
        'field'    => 'nova_senha',
    ], 422);
}

/* ==========================================================
   BANCO
========================================================== */
require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_servicos/auditoria.php';
require_once __DIR__ . '/../_servicos/notificacao.php';

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
    $sqlUser = "SELECT id_usuario, nome, email, telefone, senha_hash, status FROM usuario WHERE id_usuario = ? LIMIT 1";
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

    // Política de senha definitiva: tamanho, caracteres, senhas comuns,
    // dados pessoais e confirmação. Aplicada somente à nova senha.
    $erroPolitica = politicaSenhaValidarDefinitiva($novaSenha, $confirmarSenha, [
        'nome'     => (string)($usuario['nome'] ?? ''),
        'email'    => (string)($usuario['email'] ?? ''),
        'telefone' => (string)($usuario['telefone'] ?? ''),
    ]);
    if ($erroPolitica !== null) {
        out([
            'ok'       => false,
            'code'     => $erroPolitica['code'],
            'user_msg' => $erroPolitica['user_msg'],
            'field'    => $erroPolitica['campo'] === 'confirmacao' ? 'confirmar_senha' : 'nova_senha',
        ], 422);
    }

    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    if ($novoHash === false || $novoHash === '') {
        throw new Exception('Falha ao gerar hash da nova senha.');
    }

    // A atualização da senha e o registro do fato passam a ser atômicos.
    $conexao->begin_transaction();
    $sqlUpdate = "UPDATE usuario SET senha_hash = ?, deve_alterar_senha = 0, data_senha_temporaria = NULL WHERE id_usuario = ? LIMIT 1";
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
    $atorAuditoria = $superAdminSemEmpresa
        ? auditoriaResolverAtorSuperAdmin($conexao)
        : auditoriaResolverAtorSessao($conexao);
    auditoriaRegistrar($conexao, 'perfil.senha_alterada', [
        'ator' => $atorAuditoria,
        'entidade_id' => $idUsuario,
        'entidade_rotulo' => (string)($usuario['nome'] ?? 'Usuário'),
        'descricao' => 'Alterou a própria senha.',
        'alteracoes' => ['senha_alterada' => ['antes' => false, 'depois' => true]],
        'contexto' => ['origem' => 'perfil_usuario'],
    ]);

    $idEmpresaNotificacao = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? 0);
    $destinatarioTipo = $tipoAtor === 'super_admin' ? 'super_admin' : 'usuario';
    if ($idEmpresaNotificacao > 0) {
        $stmtNotificacoes = $conexao->prepare(
            "SELECT id_notificacao
               FROM notificacao
              WHERE destinatario_tipo = ?
                AND destinatario_id = ?
                AND id_empresa = ?
                AND codigo = 'seguranca.senha_temporaria'
                AND concluida_em IS NULL
                AND cancelada_em IS NULL
              FOR UPDATE"
        );
        if (!$stmtNotificacoes) {
            throw new Exception('Prepare localização da notificação falhou.');
        }
        $stmtNotificacoes->bind_param('sii', $destinatarioTipo, $idUsuario, $idEmpresaNotificacao);
    } else {
        $stmtNotificacoes = $conexao->prepare(
            "SELECT id_notificacao
               FROM notificacao
              WHERE destinatario_tipo = ?
                AND destinatario_id = ?
                AND id_empresa IS NULL
                AND codigo = 'seguranca.senha_temporaria'
                AND concluida_em IS NULL
                AND cancelada_em IS NULL
              FOR UPDATE"
        );
        if (!$stmtNotificacoes) {
            throw new Exception('Prepare localização da notificação falhou.');
        }
        $stmtNotificacoes->bind_param('si', $destinatarioTipo, $idUsuario);
    }

    if (!$stmtNotificacoes->execute()) {
        $stmtNotificacoes->close();
        throw new Exception('Consulta da notificação de senha temporária falhou.');
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

    $_SESSION['auth']['deve_alterar_senha'] = false;
    $_SESSION['auth']['senha_temporaria_vencida'] = false;

    // A6.1: a sessão atual acompanha a nova credencial (as demais sessões deste
    // usuário passam a divergir e são revogadas). Atualizado só depois do commit;
    // o identificador da sessão é rotacionado.
    require_once __DIR__ . '/../_regras/login_multiempresa.php';
    $_SESSION[SESSAO_CREDENCIAL_INTERNA] = sessaoCredencialImpressao((string)$novoHash);
    session_regenerate_id(true);

    out([
        'ok'       => true,
        'code'     => 'PASSWORD_UPDATED',
        'user_msg' => politicaSenhaMensagem('PASSWORD_CHANGED'),
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
