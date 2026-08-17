<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/usuario/editar-super
 * - Atualiza dados do usuário super admin na tabela `usuario`
 * - Compatível com API central
 * - Sempre retorna JSON válido
 * - Atualiza senha SOMENTE se enviada
 * - Email é obrigatório
 * - Nome é obrigatório
 * - Status é obrigatório
 * - id_usuario é obrigatório
 * - Telefone é opcional
 * - tipo_usuario NÃO é atualizado neste endpoint
 * - Aceita somente usuário com tipo_usuario = super_admin
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

/* ==========================================================
   HELPERS
========================================================== */
function strv(string $k, int $max, bool $required = false): string
{
    $v = trim((string)($_POST[$k] ?? ''));

    if ($required && $v === '') {
        return '';
    }

    if ($v === '') {
        return '';
    }

    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }

    return $v;
}

function intOrNull(string $k): ?int
{
    $raw = $_POST[$k] ?? null;

    if ($raw === null || $raw === '') {
        return null;
    }

    if (is_int($raw)) {
        return ($raw > 0) ? $raw : null;
    }

    $raw = trim((string)$raw);

    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return null;
    }

    $n = (int)$raw;
    return ($n > 0) ? $n : null;
}

function onlyDigits(?string $v): string
{
    return preg_replace('/\D+/', '', (string)$v) ?? '';
}

/* ==========================================================
   ENTRADAS
========================================================== */
$idUsuario   = intOrNull('id_usuario');
$nome        = strv('nome', 140, true);
$email       = mb_strtolower(strv('email', 160, true));
$telefoneRaw = strv('telefone', 20, false);
$status      = mb_strtolower(strv('status', 20, true));
$senha       = trim((string)($_POST['senha'] ?? ''));
$senha2      = trim((string)($_POST['senha2'] ?? ''));

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

if ($idUsuario === null) {
    $erros['id_usuario'] = 'Super Admin inválido.';
}

if ($nome === '') {
    $erros['nome'] = 'Informe o nome.';
} elseif (mb_strlen($nome) < 3) {
    $erros['nome'] = 'O nome deve ter no mínimo 3 caracteres.';
}

if ($email === '') {
    $erros['email'] = 'Informe o e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros['email'] = 'Informe um e-mail válido.';
}

$telefoneDigits = onlyDigits($telefoneRaw);
$telefone = null;

if ($telefoneRaw !== '') {
    if (strlen($telefoneDigits) < 10 || strlen($telefoneDigits) > 11) {
        $erros['telefone'] = 'Informe um telefone válido com DDD.';
    } else {
        $telefone = $telefoneRaw;
    }
}

$allowedStatus = ['ativo', 'inativo', 'bloqueado'];
if ($status === '') {
    $erros['status'] = 'Selecione o status.';
} elseif (!in_array($status, $allowedStatus, true)) {
    $erros['status'] = 'Status inválido.';
}

$alterarSenha = false;
$senhaHash = null;

if ($senha !== '' || $senha2 !== '') {
    if ($senha === '') {
        $erros['senha'] = 'Informe a nova senha.';
    }

    if ($senha2 === '') {
        $erros['senha2'] = 'Confirme a nova senha.';
    }

    if ($senha !== '' && mb_strlen($senha) < 6) {
        $erros['senha'] = 'A senha deve ter no mínimo 6 caracteres.';
    }

    if ($senha2 !== '' && mb_strlen($senha2) < 6) {
        $erros['senha2'] = 'A confirmação deve ter no mínimo 6 caracteres.';
    }

    if ($senha !== '' && $senha2 !== '' && $senha !== $senha2) {
        $erros['senha2'] = 'As senhas não conferem.';
    }

    if (!isset($erros['senha']) && !isset($erros['senha2'])) {
        $alterarSenha = true;
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
    }
}

if (!empty($erros)) {
    out([
        'ok' => false,
        'code' => 'VALIDATION_ERROR',
        'user_msg' => 'Revise os campos destacados.',
        'fields' => $erros,
    ], 422);
}

/* ==========================================================
   DB
========================================================== */
require __DIR__ . '/../../_config/conexao.php';

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
    $nome  = trim($nome);
    $email = trim($email);

    /* ==========================================================
       VALIDA SE USUÁRIO EXISTE E SE É SUPER ADMIN
    ========================================================== */
    $sqlUsuario = "
        SELECT id_usuario, tipo_usuario
        FROM usuario
        WHERE id_usuario = ?
        LIMIT 1
    ";
    $st = $conexao->prepare($sqlUsuario);

    if (!$st) {
        throw new Exception('Prepare check usuario falhou.');
    }

    $st->bind_param('i', $idUsuario);
    $st->execute();
    $resultUsuario = $st->get_result();
    $usuario = $resultUsuario ? $resultUsuario->fetch_assoc() : null;
    $st->close();

    if (!$usuario) {
        out([
            'ok' => false,
            'code' => 'USUARIO_NAO_ENCONTRADO',
            'user_msg' => 'Super Admin não encontrado.',
            'fields' => [
                'id_usuario' => 'O usuário informado não existe.',
            ],
        ], 404);
    }

    $tipoUsuario = (string)($usuario['tipo_usuario'] ?? '');

    if ($tipoUsuario !== 'super_admin') {
        out([
            'ok' => false,
            'code' => 'USUARIO_INVALIDO',
            'user_msg' => 'O usuário informado não é um Super Admin.',
            'fields' => [
                'id_usuario' => 'O registro selecionado não pertence a um Super Admin.',
            ],
        ], 422);
    }

    /* ==========================================================
       VALIDA DUPLICIDADE DE EMAIL
    ========================================================== */
    $sqlCheckEmail = "
        SELECT id_usuario
        FROM usuario
        WHERE email = ?
          AND id_usuario <> ?
        LIMIT 1
    ";
    $st = $conexao->prepare($sqlCheckEmail);

    if (!$st) {
        throw new Exception('Prepare check email falhou.');
    }

    $st->bind_param('si', $email, $idUsuario);
    $st->execute();
    $st->store_result();

    if ($st->num_rows > 0) {
        $st->close();

        out([
            'ok' => false,
            'code' => 'EMAIL_DUPLICADO',
            'user_msg' => 'Já existe outro usuário cadastrado com este e-mail.',
            'fields' => [
                'email' => 'Este e-mail já está em uso.',
            ],
        ], 409);
    }

    $st->close();

    /* ==========================================================
       TRANSAÇÃO
    ========================================================== */
    $conexao->begin_transaction();

    /* ==========================================================
       UPDATE usuario
    ========================================================== */
    if ($alterarSenha) {
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?,
                status = ?,
                senha_hash = ?
            WHERE id_usuario = ?
              AND tipo_usuario = 'super_admin'
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario super com senha falhou.');
        }

        $stmt->bind_param(
            'sssssi',
            $nome,
            $email,
            $telefone,
            $status,
            $senhaHash,
            $idUsuario
        );
    } else {
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?,
                status = ?
            WHERE id_usuario = ?
              AND tipo_usuario = 'super_admin'
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario super falhou.');
        }

        $stmt->bind_param(
            'ssssi',
            $nome,
            $email,
            $telefone,
            $status,
            $idUsuario
        );
    }

    $ok = $stmt->execute();

    if (!$ok) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        throw new Exception("Erro ao atualizar super admin [{$errno}] {$error}");
    }

    $affected = (int)$stmt->affected_rows;
    $stmt->close();

    $conexao->commit();

    out([
        'ok' => true,
        'code' => 'UPDATED',
        'user_msg' => $affected > 0
            ? ($alterarSenha ? 'Super Admin e senha atualizados com sucesso.' : 'Super Admin atualizado com sucesso.')
            : 'Nenhuma alteração foi realizada.',
        'data' => [
            'id_usuario'     => $idUsuario,
            'nome'           => $nome,
            'email'          => $email,
            'telefone'       => $telefone,
            'status'         => $status,
            'senha_alterada' => $alterarSenha,
            'tipo_usuario'   => $tipoUsuario,
        ],
    ], 200);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
            // ignora rollback error
        }
    }

    error_log('[editar_usuario_super.php] ' . $e->getMessage());

    $msg = $e->getMessage();
    if (str_contains($msg, '1062')) {
        out([
            'ok' => false,
            'code' => 'DUPLICATE_KEY',
            'user_msg' => 'Já existe um registro duplicado para os dados informados.',
        ], 409);
    }

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao atualizar o Super Admin.',
    ], 500);
}