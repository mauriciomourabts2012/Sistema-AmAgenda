<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/usuario/cadastrar-super
 * - Insere usuário super admin na tabela `usuario`
 * - Protegido contra envio de dados inválidos
 * - Ignora completamente senha_hash e tipo_usuario do frontend
 * - Sem logs sensíveis de senha/hash
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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
function normalizeSpaces(string $v): string
{
    $v = preg_replace('/\s+/u', ' ', $v);
    return trim($v ?? '');
}

function strv(string $k, int $max, bool $required = false): string
{
    $v = (string)($_POST[$k] ?? '');
    $v = normalizeSpaces($v);

    if ($required && $v === '') {
        return '';
    }

    if ($v === '') {
        return '';
    }

    if (mb_strlen($v, 'UTF-8') > $max) {
        $v = mb_substr($v, 0, $max, 'UTF-8');
    }

    return $v;
}

function lowerStr(string $v): string
{
    return mb_strtolower(normalizeSpaces($v), 'UTF-8');
}

function onlyDigits(?string $v): string
{
    return preg_replace('/\D+/', '', (string)$v) ?? '';
}

function hasInvisibleChars(string $v): bool
{
    return preg_match('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', $v) === 1;
}

function hasAngleBrackets(string $v): bool
{
    return preg_match('/[<>]/', $v) === 1;
}

function isAsciiPrintable(string $v): bool
{
    return preg_match('/^[\x20-\x7E]+$/', $v) === 1;
}

/* ==========================================================
   ENTRADAS
========================================================== */
$nome        = strv('nome', 140, true);
$email       = lowerStr(strv('email', 160, true));
$telefoneRaw = strv('telefone', 20, true);
$status      = lowerStr(strv('status', 20, true));
$senha       = (string)($_POST['senha'] ?? '');
$tipoUsuario = 'super_admin';

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

if ($nome === '') {
    $erros['nome'] = 'Informe o nome do usuário.';
} elseif (mb_strlen($nome, 'UTF-8') < 3) {
    $erros['nome'] = 'O nome deve ter no mínimo 3 caracteres.';
} elseif (hasAngleBrackets($nome)) {
    $erros['nome'] = 'Nome inválido.';
}

if ($email === '') {
    $erros['email'] = 'Informe o e-mail.';
} elseif (mb_strlen($email, 'UTF-8') > 160) {
    $erros['email'] = 'E-mail inválido.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros['email'] = 'Informe um e-mail válido.';
}

$telefoneDigits = onlyDigits($telefoneRaw);
if ($telefoneDigits === '') {
    $erros['telefone'] = 'Informe o telefone.';
} elseif (strlen($telefoneDigits) < 10 || strlen($telefoneDigits) > 11) {
    $erros['telefone'] = 'Informe um telefone válido com DDD.';
}

$allowedStatus = ['ativo', 'inativo', 'bloqueado'];
if ($status === '') {
    $erros['status'] = 'Selecione o status.';
} elseif (!in_array($status, $allowedStatus, true)) {
    $erros['status'] = 'Status inválido.';
}

if ($senha === '') {
    $erros['senha'] = 'Informe a senha.';
} elseif (strlen($senha) < 6) {
    $erros['senha'] = 'A senha deve ter no mínimo 6 caracteres.';
} elseif (strlen($senha) > 72) {
    $erros['senha'] = 'A senha deve ter no máximo 72 caracteres.';
} elseif (hasInvisibleChars($senha)) {
    $erros['senha'] = 'A senha contém caracteres invisíveis inválidos.';
} elseif (!isAsciiPrintable($senha)) {
    $erros['senha'] = 'A senha contém caracteres inválidos.';
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
$arquivoConexao = __DIR__ . '/../../_config/conexao.php';

if (!is_file($arquivoConexao)) {
    out([
        'ok' => false,
        'code' => 'DB_CONFIG_NOT_FOUND',
        'user_msg' => 'Arquivo de configuração do banco não encontrado.',
    ], 500);
}

require $arquivoConexao;

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
    $nome = normalizeSpaces($nome);
    $email = lowerStr($email);
    $telefone = $telefoneDigits;

    /* ==========================================================
       GERA HASH
    ========================================================== */
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    if ($senhaHash === false) {
        out([
            'ok' => false,
            'code' => 'PASSWORD_HASH_ERROR',
            'user_msg' => 'Não foi possível processar a senha.',
        ], 500);
    }

    /* ==========================================================
       VALIDAR DUPLICIDADE GLOBAL DE E-MAIL
    ========================================================== */
    $sqlCheckEmail = "
        SELECT id_usuario, tipo_usuario
        FROM usuario
        WHERE LOWER(email) = ?
        LIMIT 1
    ";

    $st = $conexao->prepare($sqlCheckEmail);

    if (!$st) {
        throw new RuntimeException('Prepare check email falhou: ' . $conexao->error);
    }

    $st->bind_param('s', $email);

    if (!$st->execute()) {
        throw new RuntimeException('Execute check email falhou: ' . $st->error);
    }

    $st->bind_result($idUsuarioExistente, $tipoUsuarioExistente);
    $emailJaExiste = $st->fetch();
    $st->close();

    if ($emailJaExiste) {
        out([
            'ok' => false,
            'code' => 'EMAIL_DUPLICADO',
            'user_msg' => 'Já existe um usuário cadastrado com este e-mail.',
            'fields' => [
                'email' => 'Este e-mail já está em uso.'
            ],
            'data' => [
                'id_usuario' => (int)$idUsuarioExistente,
                'tipo_usuario' => (string)$tipoUsuarioExistente
            ]
        ], 409);
    }

    /* ==========================================================
       INSERT SUPER ADMIN
    ========================================================== */
    $sql = "
        INSERT INTO usuario (
            nome,
            email,
            telefone,
            senha_hash,
            status,
            tipo_usuario
        ) VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare insert usuario falhou: ' . $conexao->error);
    }

    $stmt->bind_param(
        'ssssss',
        $nome,
        $email,
        $telefone,
        $senhaHash,
        $status,
        $tipoUsuario
    );

    $ok = $stmt->execute();

    if (!$ok) {
        $errno = (int)$stmt->errno;
        $stmt->close();

        if ($errno === 1062) {
            out([
                'ok' => false,
                'code' => 'DUPLICATE_KEY',
                'user_msg' => 'Já existe um registro duplicado para os dados informados.',
            ], 409);
        }

        out([
            'ok' => false,
            'code' => 'DB_INSERT_ERROR',
            'user_msg' => 'Não foi possível cadastrar o super admin.',
        ], 500);
    }

    $idUsuario = (int)$stmt->insert_id;
    $stmt->close();

    out([
        'ok' => true,
        'code' => 'CREATED',
        'user_msg' => 'Super admin cadastrado com sucesso.',
        'data' => [
            'id_usuario' => $idUsuario,
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone,
            'status' => $status,
            'tipo_usuario' => $tipoUsuario,
        ]
    ], 201);

} catch (Throwable $e) {
    error_log('[cadastrar_super_admin] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao cadastrar o super admin.',
    ], 500);
}