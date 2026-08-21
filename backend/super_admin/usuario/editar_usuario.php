<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/usuario/editar
 * - Atualiza dados do usuário na tabela `usuario`
 * - Atualiza status do vínculo na tabela `empresa_usuario`
 * - Compatível com API central
 * - Sempre retorna JSON válido
 * - Atualiza senha SOMENTE se enviada
 * - Email é obrigatório
 * - Nome é obrigatório
 * - Status é obrigatório
 * - id_usuario é obrigatório
 * - Telefone é opcional
 * - tipo_usuario NÃO é atualizado neste endpoint
 * - perfil NÃO é atualizado neste endpoint
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

require __DIR__ . '/../../_auth/bloquear.php';

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
$idEmpresa   = intOrNull('id_empresa'); // opcional por enquanto
$nome        = strv('nome', 140, true);
$email       = mb_strtolower(strv('email', 160, true));
$telefoneRaw = strv('telefone', 20, false);
$status      = mb_strtolower(strv('status', 20, true));
$senha       = (string)($_POST['senha'] ?? '');
$senha2      = (string)($_POST['senha2'] ?? '');

$senha = trim($senha);
$senha2 = trim($senha2);

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

if ($idUsuario === null) {
    $erros['id_usuario'] = 'Usuário inválido.';
}

if ($nome === '') {
    $erros['nome'] = 'Informe o nome do usuário.';
} elseif (mb_strlen($nome) < 3) {
    $erros['nome'] = 'O nome do usuário deve ter no mínimo 3 caracteres.';
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
require_once __DIR__ . '/../../_regras/limites_plano.php';

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
       VALIDA SE USUÁRIO EXISTE
    ========================================================== */
    $sqlUsuario = "SELECT id_usuario, tipo_usuario, status FROM usuario WHERE id_usuario = ? LIMIT 1";
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
            'user_msg' => 'Usuário não encontrado.',
            'fields' => [
                'id_usuario' => 'O usuário informado não existe.',
            ],
        ], 404);
    }

    $tipoUsuario = (string)($usuario['tipo_usuario'] ?? '');
    $statusUsuarioGlobal = (string)($usuario['status'] ?? '');

    /* ==========================================================
       VALIDA DUPLICIDADE DE EMAIL
    ========================================================== */
    $sqlCheckEmail = "SELECT id_usuario FROM usuario WHERE email = ? AND id_usuario <> ? LIMIT 1";
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
       VALIDA VÍNCULO empresa_usuario
       - para usuário comum, status deve ser salvo no vínculo
       - se vier id_empresa, usa vínculo exato
       - se não vier, pega o primeiro vínculo
    ========================================================== */
    $idEmpresaUsuario = null;
    $idEmpresaVinculo = null;

    if ($tipoUsuario !== 'super_admin') {
        if ($idEmpresa === null) {
            out([
                'ok' => false,
                'code' => 'EMPRESA_OBRIGATORIA',
                'user_msg' => 'Informe a empresa do vínculo que será alterado.',
                'fields' => ['id_empresa' => 'Empresa obrigatória.'],
            ], 422);
        }

        if ($idEmpresa !== null) {
            $sqlVinculo = "
                SELECT eu.id_empresa_usuario, eu.id_empresa, eu.status, pf.nome AS perfil_nome
                FROM empresa_usuario eu
                INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
                WHERE eu.id_usuario = ?
                  AND eu.id_empresa = ?
                LIMIT 1
            ";
            $st = $conexao->prepare($sqlVinculo);

            if (!$st) {
                throw new Exception('Prepare check vínculo do usuário falhou.');
            }

            $st->bind_param('ii', $idUsuario, $idEmpresa);
        } else {
            $sqlVinculo = "
                SELECT id_empresa_usuario, id_empresa
                FROM empresa_usuario
                WHERE id_usuario = ?
                ORDER BY id_empresa_usuario ASC
                LIMIT 1
            ";
            $st = $conexao->prepare($sqlVinculo);

            if (!$st) {
                throw new Exception('Prepare check vínculo do usuário falhou.');
            }

            $st->bind_param('i', $idUsuario);
        }

        $st->execute();
        $resultVinculo = $st->get_result();
        $vinculo = $resultVinculo ? $resultVinculo->fetch_assoc() : null;
        $st->close();

        if (!$vinculo) {
            out([
                'ok' => false,
                'code' => 'VINCULO_NAO_ENCONTRADO',
                'user_msg' => 'Vínculo do usuário com a empresa não encontrado.',
                'fields' => [
                    'id_usuario' => 'Não foi encontrado vínculo ativo para este usuário.',
                ],
            ], 404);
        }

        $idEmpresaUsuario = (int)($vinculo['id_empresa_usuario'] ?? 0);
        $idEmpresaVinculo = (int)($vinculo['id_empresa'] ?? 0);

        if ($idEmpresaUsuario <= 0) {
            out([
                'ok' => false,
                'code' => 'VINCULO_INVALIDO',
                'user_msg' => 'Vínculo do usuário inválido.',
            ], 422);
        }
    }

    /* ==========================================================
       TRANSAÇÃO
    ========================================================== */
    $conexao->begin_transaction();

    if ($tipoUsuario !== 'super_admin') {
        $resultadoPlano = limitesPlanoBloquearEmpresa($conexao, (int)$idEmpresaVinculo);
        limitesPlanoAbortarSeNegado($conexao, $resultadoPlano);

        $usuarioGlobalConta = limitesPlanoStatusConta($statusUsuarioGlobal);
        $statusAnteriorPlano = $usuarioGlobalConta ? (string)($vinculo['status'] ?? '') : 'inativo';
        $statusNovoPlano = $usuarioGlobalConta ? $status : 'inativo';
        $resultadoLimites = limitesPlanoVerificarTransicaoPerfil(
            $conexao,
            $resultadoPlano['plano'],
            (int)$idEmpresaVinculo,
            (string)($vinculo['perfil_nome'] ?? ''),
            $statusAnteriorPlano,
            (string)($vinculo['perfil_nome'] ?? ''),
            $statusNovoPlano
        );
        limitesPlanoAbortarSeNegado($conexao, $resultadoLimites);
    }

    /* ==========================================================
       UPDATE usuario
    ========================================================== */
    if ($alterarSenha) {
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?,
                senha_hash = ?
            WHERE id_usuario = ?
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario com senha falhou.');
        }

        $stmt->bind_param(
            'ssssi',
            $nome,
            $email,
            $telefone,
            $senhaHash,
            $idUsuario
        );
    } else {
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?
            WHERE id_usuario = ?
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario falhou.');
        }

        $stmt->bind_param(
            'sssi',
            $nome,
            $email,
            $telefone,
            $idUsuario
        );
    }

    $ok = $stmt->execute();

    if (!$ok) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        throw new Exception("Erro ao atualizar tabela usuario [{$errno}] {$error}");
    }

    $affectedUsuario = (int)$stmt->affected_rows;
    $stmt->close();

    /* ==========================================================
       UPDATE status
       - super_admin: status em usuario
       - usuário comum: status em empresa_usuario
    ========================================================== */
    $affectedStatus = 0;

    if ($tipoUsuario === 'super_admin') {
        $sqlStatus = "
            UPDATE usuario
            SET status = ?
            WHERE id_usuario = ?
            LIMIT 1
        ";

        $stmtStatus = $conexao->prepare($sqlStatus);

        if (!$stmtStatus) {
            throw new Exception('Prepare update status de super admin falhou.');
        }

        $stmtStatus->bind_param('si', $status, $idUsuario);
    } else {
        $sqlStatus = "
            UPDATE empresa_usuario
            SET status = ?
            WHERE id_empresa_usuario = ?
            LIMIT 1
        ";

        $stmtStatus = $conexao->prepare($sqlStatus);

        if (!$stmtStatus) {
            throw new Exception('Prepare update status vínculo falhou.');
        }

        $stmtStatus->bind_param('si', $status, $idEmpresaUsuario);
    }

    $okStatus = $stmtStatus->execute();

    if (!$okStatus) {
        $errno = (int)$stmtStatus->errno;
        $error = (string)$stmtStatus->error;
        $stmtStatus->close();

        throw new Exception("Erro ao atualizar status [{$errno}] {$error}");
    }

    $affectedStatus = (int)$stmtStatus->affected_rows;
    $stmtStatus->close();

    $conexao->commit();

    $houveAlteracao = ($affectedUsuario > 0) || ($affectedStatus > 0);

    out([
        'ok' => true,
        'code' => 'UPDATED',
        'user_msg' => $houveAlteracao
            ? ($alterarSenha ? 'Usuário e senha atualizados com sucesso.' : 'Usuário atualizado com sucesso.')
            : 'Nenhuma alteração foi realizada.',
        'data' => [
            'id_usuario'       => $idUsuario,
            'id_empresa'       => $idEmpresaVinculo,
            'nome'             => $nome,
            'email'            => $email,
            'telefone'         => $telefone,
            'status'           => $status,
            'senha_alterada'   => $alterarSenha,
            'tipo_usuario'     => $tipoUsuario,
        ],
    ], 200);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            if ($conexao->errno === 0) {
                // noop
            }
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
            // ignora rollback error
        }
    }

    error_log('[editar_usuario.php] ' . $e->getMessage());

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
        'user_msg' => 'Erro interno ao atualizar o usuário.',
    ], 500);
}
