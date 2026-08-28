<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/empresa/cadastrar
 * - Insere empresa na tabela `empresa`
 * - Cria automaticamente a configuração padrão da empresa
 * - Cria automaticamente o horário padrão da empresa
 * - Cria automaticamente a configuração padrão do WhatsApp da empresa
 * - Retorna JSON padrão
 * - CNPJ é OPCIONAL
 * - Email é OBRIGATÓRIO
 * - Plano é OBRIGATÓRIO
 * - Status é OBRIGATÓRIO
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

    $observacaoPadraoConfig = 'Defina as configurações gerais da agenda da empresa, como dias de funcionamento, horários de trabalho e intervalos (ex: almoço). Essas regras servem como base, podendo ser ajustadas por cada profissional.';
    $mensagemPadraoWhatsapp = 'Olá {cliente}! Seu agendamento de {servico} está {status} para {data} às {hora}.';
    $ddiPadraoWhatsapp = '55';
    $dddPadraoWhatsapp = null; // deixar null para a empresa preencher depois no modal

    // valida duplicidade de CNPJ apenas se informado
    if ($cnpj !== null) {
        $sqlCheckCnpj = "SELECT id_empresa FROM empresa WHERE cnpj = ? LIMIT 1";
        $st = $conexao->prepare($sqlCheckCnpj);

        if (!$st) {
            throw new Exception('Prepare check cnpj falhou.');
        }

        $st->bind_param('s', $cnpj);
        $st->execute();
        $st->store_result();

        if ($st->num_rows > 0) {
            $st->close();

            out([
                'ok' => false,
                'code' => 'CNPJ_DUPLICADO',
                'user_msg' => 'Já existe uma empresa cadastrada com este CNPJ.',
                'fields' => [
                    'cnpj' => 'Este CNPJ já está em uso.'
                ],
            ], 409);
        }

        $st->close();
    }

    // valida duplicidade de email
    $sqlCheckEmail = "SELECT id_empresa FROM empresa WHERE email = ? LIMIT 1";
    $st = $conexao->prepare($sqlCheckEmail);

    if (!$st) {
        throw new Exception('Prepare check email falhou.');
    }

    $st->bind_param('s', $email);
    $st->execute();
    $st->store_result();

    if ($st->num_rows > 0) {
        $st->close();

        out([
            'ok' => false,
            'code' => 'EMAIL_DUPLICADO',
            'user_msg' => 'Já existe uma empresa cadastrada com este e-mail.',
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

    // insert empresa
    $sql = "
        INSERT INTO empresa (
            nome, cnpj, email, telefone, plano_id, status, endereco, observacao
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new Exception('Prepare insert empresa falhou.');
    }

    $stmt->bind_param(
        'ssssisss',
        $nome,
        $cnpj,
        $email,
        $telefone,
        $planoId,
        $status,
        $endereco,
        $observacao
    );

    $ok = $stmt->execute();

    if (!$ok) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        if ($errno === 1062) {
            throw new Exception('Registro duplicado ao inserir empresa.');
        }

        throw new Exception('Não foi possível inserir a empresa. Erro: ' . $error);
    }

    $idEmpresa = (int)$stmt->insert_id;
    $stmt->close();

    // cria configuração padrão da empresa
    $sqlConfig = "
        INSERT INTO configuracao_geral_empresa (
            id_empresa,
            inicio_semana,
            intervalo_padrao_min,
            observacao_padrao,
            status
        ) VALUES (?, 'segunda', 10, ?, 'ativo')
    ";

    $stmtConfig = $conexao->prepare($sqlConfig);

    if (!$stmtConfig) {
        throw new Exception('Prepare insert configuracao_geral_empresa falhou.');
    }

    $stmtConfig->bind_param(
        'is',
        $idEmpresa,
        $observacaoPadraoConfig
    );

    $okConfig = $stmtConfig->execute();

    if (!$okConfig) {
        $errorConfig = (string)$stmtConfig->error;
        $stmtConfig->close();
        throw new Exception('Erro ao criar configuração padrão da empresa. Erro: ' . $errorConfig);
    }

    $stmtConfig->close();

    // cria horário padrão da empresa
    $sqlHorario = "
        INSERT INTO horario_empresa (
            id_empresa,
            dia_semana,
            hora_inicio,
            hora_fim,
            almoco_inicio,
            almoco_fim,
            disponivel,
            status
        ) VALUES
        (?, 'domingo', NULL, NULL, NULL, NULL, 0, 'ativo'),
        (?, 'segunda', '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
        (?, 'terca',   '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
        (?, 'quarta',  '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
        (?, 'quinta',  '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
        (?, 'sexta',   '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
        (?, 'sabado',  NULL, NULL, NULL, NULL, 0, 'ativo')
    ";

    $stmtHorario = $conexao->prepare($sqlHorario);

    if (!$stmtHorario) {
        throw new Exception('Prepare insert horario_empresa falhou.');
    }

    $stmtHorario->bind_param(
        'iiiiiii',
        $idEmpresa,
        $idEmpresa,
        $idEmpresa,
        $idEmpresa,
        $idEmpresa,
        $idEmpresa,
        $idEmpresa
    );

    $okHorario = $stmtHorario->execute();

    if (!$okHorario) {
        $errorHorario = (string)$stmtHorario->error;
        $stmtHorario->close();
        throw new Exception('Erro ao criar horário padrão da empresa. Erro: ' . $errorHorario);
    }

    $stmtHorario->close();

    // cria configuração padrão do WhatsApp da empresa
    $sqlWhatsapp = "
        INSERT INTO configuracao_whatsapp_empresa (
            id_empresa,
            ddi_padrao,
            ddd_padrao,
            mensagem_padrao,
            status
        ) VALUES (?, ?, ?, ?, 'ativo')
    ";

    $stmtWhatsapp = $conexao->prepare($sqlWhatsapp);

    if (!$stmtWhatsapp) {
        throw new Exception('Prepare insert configuracao_whatsapp_empresa falhou.');
    }

    $stmtWhatsapp->bind_param(
        'isss',
        $idEmpresa,
        $ddiPadraoWhatsapp,
        $dddPadraoWhatsapp,
        $mensagemPadraoWhatsapp
    );

    $okWhatsapp = $stmtWhatsapp->execute();

    if (!$okWhatsapp) {
        $errorWhatsapp = (string)$stmtWhatsapp->error;
        $stmtWhatsapp->close();
        throw new Exception('Erro ao criar configuração padrão do WhatsApp da empresa. Erro: ' . $errorWhatsapp);
    }

    $stmtWhatsapp->close();

    auditoriaRegistrar($conexao, 'empresa.criada', [
        'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
        'entidade_id' => $idEmpresa,
        'entidade_rotulo' => $nome,
        'descricao' => 'Criou a empresa ' . $nome . '.',
        'alteracoes' => ['depois' => ['antes' => null, 'depois' => [
            'nome' => $nome, 'cnpj' => $cnpj, 'email' => $email, 'telefone' => $telefone,
            'plano' => $planoId, 'status' => $status, 'endereco' => $endereco, 'observacao' => $observacao,
        ]]],
        'contexto' => ['origem' => 'painel_super_admin'],
    ]);

    $conexao->commit();

    out([
        'ok' => true,
        'code' => 'CREATED',
        'user_msg' => 'Empresa cadastrada com sucesso.',
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
            'configuracao_geral_empresa' => [
                'inicio_semana' => 'segunda',
                'intervalo_padrao_min' => 10,
                'observacao_padrao' => $observacaoPadraoConfig,
                'status' => 'ativo',
            ],
            'horario_empresa' => [
                ['dia_semana' => 'domingo', 'hora_inicio' => null,        'hora_fim' => null,        'almoco_inicio' => null,        'almoco_fim' => null,        'disponivel' => 0, 'status' => 'ativo'],
                ['dia_semana' => 'segunda', 'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '14:00:00', 'disponivel' => 1, 'status' => 'ativo'],
                ['dia_semana' => 'terca',   'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '14:00:00', 'disponivel' => 1, 'status' => 'ativo'],
                ['dia_semana' => 'quarta',  'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '14:00:00', 'disponivel' => 1, 'status' => 'ativo'],
                ['dia_semana' => 'quinta',  'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '14:00:00', 'disponivel' => 1, 'status' => 'ativo'],
                ['dia_semana' => 'sexta',   'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '14:00:00', 'disponivel' => 1, 'status' => 'ativo'],
                ['dia_semana' => 'sabado',  'hora_inicio' => null,        'hora_fim' => null,        'almoco_inicio' => null,        'almoco_fim' => null,        'disponivel' => 0, 'status' => 'ativo'],
            ],
            'configuracao_whatsapp_empresa' => [
                'ddi_padrao' => $ddiPadraoWhatsapp,
                'ddd_padrao' => $dddPadraoWhatsapp,
                'mensagem_padrao' => $mensagemPadraoWhatsapp,
                'status' => 'ativo',
            ],
        ],
    ], 201);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
            // ignora erro de rollback
        }
    }

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao cadastrar a empresa.',
        'debug' => [
            'message' => $e->getMessage(),
        ],
    ], 500);
}
