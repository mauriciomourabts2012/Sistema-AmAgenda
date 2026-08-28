<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/empresa/editar
 * - Atualiza empresa na tabela `empresa`
 * - Compatível com API central
 * - Sempre retorna JSON válido
 * - CNPJ é opcional
 * - Email é obrigatório
 * - Plano é obrigatório
 * - Status é obrigatório
 * - id_empresa é obrigatório
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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

function validarCnpj(string $cnpj): bool
{
    $cnpj = onlyDigits($cnpj);

    if (strlen($cnpj) !== 14) {
        return false;
    }

    if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }

    $calc = function (string $base, array $pesos): int {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += ((int)$base[$i]) * $peso;
        }
        $resto = $soma % 11;
        return ($resto < 2) ? 0 : 11 - $resto;
    };

    $dig1 = $calc(substr($cnpj, 0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]);
    $dig2 = $calc(substr($cnpj, 0, 12) . $dig1, [6,5,4,3,2,9,8,7,6,5,4,3,2]);

    return $cnpj === (substr($cnpj, 0, 12) . $dig1 . $dig2);
}

function formatarCnpj(string $cnpj): ?string
{
    $digits = onlyDigits($cnpj);

    if ($digits === '') {
        return null;
    }

    if (strlen($digits) !== 14) {
        return null;
    }

    return substr($digits, 0, 2) . '.' .
           substr($digits, 2, 3) . '.' .
           substr($digits, 5, 3) . '/' .
           substr($digits, 8, 4) . '-' .
           substr($digits, 12, 2);
}

/* ==========================================================
   ENTRADAS
========================================================== */
$idEmpresa  = intOrNull('id_empresa');
$nome       = strv('nome', 140, true);
$cnpjRaw    = strv('cnpj', 30, false);
$email      = strv('email', 160, true);
$telefone   = strv('telefone', 20, true);
$status     = strv('status', 20, true);
$endereco   = strv('endereco', 200, false);
$observacao = strv('observacao', 220, false);

if ($observacao === '') {
    $observacao = strv('obs', 220, false);
}

$planoId = intOrNull('plano_id');

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

// id_empresa
if ($idEmpresa === null) {
    $erros['id_empresa'] = 'Empresa inválida.';
}

// nome
if ($nome === '') {
    $erros['nome'] = 'Informe o nome da empresa.';
} elseif (mb_strlen($nome) < 3) {
    $erros['nome'] = 'O nome da empresa deve ter no mínimo 3 caracteres.';
}

// cnpj opcional
$cnpjDigits = onlyDigits($cnpjRaw);
$cnpj = null;

if ($cnpjRaw !== '') {
    if (strlen($cnpjDigits) !== 14) {
        $erros['cnpj'] = 'O CNPJ deve conter 14 dígitos.';
    } elseif (!validarCnpj($cnpjDigits)) {
        $erros['cnpj'] = 'Informe um CNPJ válido.';
    } else {
        $cnpj = formatarCnpj($cnpjDigits);
    }
}

// email obrigatório
if ($email === '') {
    $erros['email'] = 'Informe o e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros['email'] = 'Informe um e-mail válido.';
}

// telefone obrigatório
$telefoneDigits = onlyDigits($telefone);
if ($telefone === '') {
    $erros['telefone'] = 'Informe o telefone.';
} elseif (strlen($telefoneDigits) < 10 || strlen($telefoneDigits) > 11) {
    $erros['telefone'] = 'Informe um telefone válido com DDD.';
}

// plano obrigatório
if ($planoId === null) {
    $erros['plano_id'] = 'Selecione o plano.';
}

// status obrigatório
$allowedStatus = ['ativo', 'inativo', 'bloqueado'];
if ($status === '') {
    $erros['status'] = 'Selecione o status.';
} elseif (!in_array($status, $allowedStatus, true)) {
    $erros['status'] = 'Status inválido.';
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
require_once __DIR__ . '/../../_servicos/auditoria.php';

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
    $nome = trim($nome);
    $email = trim($email);
    $telefone = trim($telefone);
    $endereco = ($endereco === '') ? null : trim($endereco);
    $observacao = ($observacao === '') ? null : trim($observacao);

    // valida se empresa existe
    $sqlEmpresa = "SELECT nome,cnpj,email,telefone,plano_id,status,endereco,observacao FROM empresa WHERE id_empresa = ? LIMIT 1";
    $st = $conexao->prepare($sqlEmpresa);

    if (!$st) {
        throw new Exception('Prepare check empresa falhou.');
    }

    $st->bind_param('i', $idEmpresa);
    $st->execute();
    $resultadoEmpresa = $st->get_result();
    $empresaAnterior = $resultadoEmpresa ? $resultadoEmpresa->fetch_assoc() : null;

    if (!$empresaAnterior) {
        $st->close();

        out([
            'ok' => false,
            'code' => 'EMPRESA_NAO_ENCONTRADA',
            'user_msg' => 'Empresa não encontrada.',
            'fields' => [
                'id_empresa' => 'A empresa informada não existe.'
            ],
        ], 404);
    }

    $st->close();

    // valida duplicidade de CNPJ apenas se informado
    if ($cnpj !== null) {
        $sqlCheckCnpj = "SELECT id_empresa FROM empresa WHERE cnpj = ? AND id_empresa <> ? LIMIT 1";
        $st = $conexao->prepare($sqlCheckCnpj);

        if (!$st) {
            throw new Exception('Prepare check cnpj falhou.');
        }

        $st->bind_param('si', $cnpj, $idEmpresa);
        $st->execute();
        $st->store_result();

        if ($st->num_rows > 0) {
            $st->close();

            out([
                'ok' => false,
                'code' => 'CNPJ_DUPLICADO',
                'user_msg' => 'Já existe outra empresa cadastrada com este CNPJ.',
                'fields' => [
                    'cnpj' => 'Este CNPJ já está em uso.'
                ],
            ], 409);
        }

        $st->close();
    }

    // valida duplicidade de email ignorando a própria empresa
    $sqlCheckEmail = "SELECT id_empresa FROM empresa WHERE email = ? AND id_empresa <> ? LIMIT 1";
    $st = $conexao->prepare($sqlCheckEmail);

    if (!$st) {
        throw new Exception('Prepare check email falhou.');
    }

    $st->bind_param('si', $email, $idEmpresa);
    $st->execute();
    $st->store_result();

    if ($st->num_rows > 0) {
        $st->close();

        out([
            'ok' => false,
            'code' => 'EMAIL_DUPLICADO',
            'user_msg' => 'Já existe outra empresa cadastrada com este e-mail.',
            'fields' => [
                'email' => 'Este e-mail já está em uso.'
            ],
        ], 409);
    }

    $st->close();

    // valida plano obrigatório e ativo
    $sqlPlano = "SELECT id_plano, status FROM plano WHERE id_plano = ? LIMIT 1";
    $st = $conexao->prepare($sqlPlano);

    if (!$st) {
        throw new Exception('Prepare check plano falhou.');
    }

    $st->bind_param('i', $planoId);
    $st->execute();

    $res = $st->get_result();
    $plano = $res ? $res->fetch_assoc() : null;
    $st->close();

    if (!$plano) {
        out([
            'ok' => false,
            'code' => 'PLANO_NAO_ENCONTRADO',
            'user_msg' => 'Plano não encontrado.',
            'fields' => [
                'plano_id' => 'O plano informado não existe.'
            ],
        ], 404);
    }

    if (($plano['status'] ?? '') !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'PLANO_INATIVO',
            'user_msg' => 'O plano informado não está ativo.',
            'fields' => [
                'plano_id' => 'Selecione um plano ativo.'
            ],
        ], 422);
    }

    $conexao->begin_transaction();

    $sql = "
        UPDATE empresa
           SET nome = ?,
               cnpj = ?,
               email = ?,
               telefone = ?,
               plano_id = ?,
               status = ?,
               endereco = ?,
               observacao = ?
         WHERE id_empresa = ?
         LIMIT 1
    ";

    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new Exception('Prepare update empresa falhou.');
    }

    $stmt->bind_param(
        'ssssisssi',
        $nome,
        $cnpj,
        $email,
        $telefone,
        $planoId,
        $status,
        $endereco,
        $observacao,
        $idEmpresa
    );

    $ok = $stmt->execute();

    if (!$ok) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
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
            'code' => 'DB_UPDATE_ERROR',
            'user_msg' => 'Não foi possível atualizar a empresa.',
            'debug' => [
                'errno' => $errno,
                'error' => $error,
            ],
        ], 500);
    }

    $affected = (int)$stmt->affected_rows;
    $stmt->close();

    $depois = ['nome'=>$nome,'cnpj'=>$cnpj,'email'=>$email,'telefone'=>$telefone,'plano'=>$planoId,'status'=>$status,'endereco'=>$endereco,'observacao'=>$observacao];
    $antes = ['nome'=>$empresaAnterior['nome'],'cnpj'=>$empresaAnterior['cnpj'],'email'=>$empresaAnterior['email'],'telefone'=>$empresaAnterior['telefone'],'plano'=>(int)$empresaAnterior['plano_id'],'status'=>$empresaAnterior['status'],'endereco'=>$empresaAnterior['endereco'],'observacao'=>$empresaAnterior['observacao']];
    $alteracoes = [];
    foreach ($depois as $campo => $valor) if (!auditoriaValoresIguais($antes[$campo] ?? null, $valor)) $alteracoes[$campo] = ['antes'=>$antes[$campo] ?? null,'depois'=>$valor];
    if ($alteracoes !== []) auditoriaRegistrar($conexao, 'empresa.editada', [
        'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
        'entidade_id' => $idEmpresa, 'entidade_rotulo' => $nome,
        'descricao' => 'Alterou a empresa ' . $nome . '.', 'alteracoes' => $alteracoes,
        'contexto' => ['origem' => 'painel_super_admin'],
    ]);
    $conexao->commit();

    out([
        'ok' => true,
        'code' => 'UPDATED',
        'user_msg' => ($affected > 0)
            ? 'Empresa atualizada com sucesso.'
            : 'Nenhuma alteração foi realizada.',
        'data' => [
            'id_empresa' => $idEmpresa,
            'nome' => $nome,
            'cnpj' => $cnpj,
            'email' => $email,
            'telefone' => $telefone,
            'plano_id' => $planoId,
            'status' => $status,
            'endereco' => $endereco,
            'observacao' => $observacao,
        ],
    ], 200);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) { try { $conexao->rollback(); } catch (Throwable) {} }
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao atualizar a empresa.',
    ], 500);
}
