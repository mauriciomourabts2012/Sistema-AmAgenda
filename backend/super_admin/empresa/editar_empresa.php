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
require_once __DIR__ . '/../../_auth/csrf.php';
csrfValidarSessao();

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

function editarEmpresaErroTransacional(mysqli $conexao, string $code, string $mensagem, int $httpStatus = 409): void
{
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }

    out([
        'ok' => false,
        'code' => $code,
        'user_msg' => $mensagem,
    ], $httpStatus);
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
$idsPermanentesPlano = null;
$selecaoPlanoRaw = $_POST['plano_usuarios_permanentes'] ?? null;
if ($selecaoPlanoRaw !== null) {
    $idsPermanentesPlano = is_array($selecaoPlanoRaw)
        ? $selecaoPlanoRaw
        : json_decode((string)$selecaoPlanoRaw, true);
}

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
if ($selecaoPlanoRaw !== null && !is_array($idsPermanentesPlano)) {
    $erros['plano_usuarios_permanentes'] = 'A seleção de usuários é inválida.';
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
    $resultadoTrocaPlano = limitesPlanoPrepararTrocaEmpresa($conexao, $idEmpresa, $planoId, $idsPermanentesPlano);
    limitesPlanoAbortarSeNegado($conexao, $resultadoTrocaPlano);

    // A decisão da troca usa o estado autoritativo já bloqueado pela regra de limites,
    // nunca a leitura realizada antes da transação.
    $stmtEmpresaAtual = $conexao->prepare(
        'SELECT nome, cnpj, email, telefone, plano_id, status, endereco, observacao
           FROM empresa
          WHERE id_empresa = ?
          LIMIT 1
          FOR UPDATE'
    );
    if (!$stmtEmpresaAtual) {
        throw new RuntimeException('Prepare revalidação transacional da empresa falhou.');
    }
    $stmtEmpresaAtual->bind_param('i', $idEmpresa);
    if (!$stmtEmpresaAtual->execute()) {
        $erro = $stmtEmpresaAtual->error;
        $stmtEmpresaAtual->close();
        throw new RuntimeException('Falha ao revalidar empresa na transação: ' . $erro);
    }
    $empresaAtual = $stmtEmpresaAtual->get_result()->fetch_assoc();
    $stmtEmpresaAtual->close();
    if (!$empresaAtual) {
        editarEmpresaErroTransacional($conexao, 'EMPRESA_NAO_ENCONTRADA', 'Empresa não encontrada.', 404);
    }

    $empresaAnterior = $empresaAtual;
    $planoMudou = (int)$empresaAnterior['plano_id'] !== $planoId;
    $assinaturaAnteriorId = null;
    $diaVencimentoAssinatura = 10;
    $dataOperacaoAssinatura = date('Y-m-d');
    if ($planoMudou) {
        $stmtAssinaturaVigente = $conexao->prepare(
            "SELECT id_assinatura, id_empresa, id_plano, valor_contratado, periodicidade,
                    dia_vencimento, status, modalidade, teste_iniciado_em, teste_expira_em,
                    CASE
                        WHEN status = 'ativa'
                         AND modalidade = 'teste'
                         AND teste_iniciado_em IS NOT NULL
                         AND teste_expira_em IS NOT NULL
                         AND teste_expira_em > teste_iniciado_em
                         AND teste_expira_em > CURRENT_TIMESTAMP
                        THEN 1 ELSE 0
                    END AS teste_valido
               FROM assinatura
              WHERE id_empresa = ?
                AND status IN ('ativa', 'suspensa')
              ORDER BY id_assinatura DESC
              FOR UPDATE"
        );
        if (!$stmtAssinaturaVigente) {
            throw new RuntimeException('Prepare assinatura vigente falhou.');
        }
        $stmtAssinaturaVigente->bind_param('i', $idEmpresa);
        if (!$stmtAssinaturaVigente->execute()) {
            $erro = $stmtAssinaturaVigente->error;
            $stmtAssinaturaVigente->close();
            throw new RuntimeException('Falha ao localizar assinatura vigente: ' . $erro);
        }
        $resAssinaturaVigente = $stmtAssinaturaVigente->get_result();
        $assinaturasVigentes = $resAssinaturaVigente ? $resAssinaturaVigente->fetch_all(MYSQLI_ASSOC) : [];
        $stmtAssinaturaVigente->close();

        if (count($assinaturasVigentes) > 1) {
            editarEmpresaErroTransacional(
                $conexao,
                'ASSINATURA_INCONSISTENTE',
                'A empresa possui mais de uma assinatura vigente.'
            );
        }

        if ($assinaturasVigentes === []) {
            editarEmpresaErroTransacional(
                $conexao,
                'ASSINATURA_INCONSISTENTE',
                'Não foi possível determinar uma assinatura vigente para a troca de plano.'
            );
        }

        $assinaturaVigente = $assinaturasVigentes[0];
        if ((string)$assinaturaVigente['status'] !== 'ativa') {
            if ((string)$assinaturaVigente['modalidade'] === 'teste') {
                editarEmpresaErroTransacional(
                    $conexao,
                    'TRIAL_NAO_ELEGIVEL_TROCA_PLANO',
                    'O período de teste está expirado, suspenso ou inconsistente. Regularize a assinatura antes de alterar o plano.'
                );
            }
            editarEmpresaErroTransacional(
                $conexao,
                'ASSINATURA_INCONSISTENTE',
                'Não foi possível determinar uma assinatura ativa para a troca de plano.'
            );
        }

        $assinaturaAnteriorId = (int)$assinaturaVigente['id_assinatura'];
        $diaVencimentoAssinatura = (int)$assinaturaVigente['dia_vencimento'];
        $statusAssinaturaAnterior = (string)$assinaturaVigente['status'];
        $modalidadeAssinatura = (string)$assinaturaVigente['modalidade'];

        if ((int)$assinaturaVigente['id_plano'] !== (int)$empresaAnterior['plano_id']) {
            editarEmpresaErroTransacional(
                $conexao,
                'PLANO_ASSINATURA_DIVERGENTE',
                'A assinatura vigente não corresponde ao plano atual da empresa.'
            );
        }

        if ($diaVencimentoAssinatura < 1 || $diaVencimentoAssinatura > 28) {
            editarEmpresaErroTransacional(
                $conexao,
                'ASSINATURA_INCONSISTENTE',
                'A assinatura vigente possui dados contratuais inválidos.'
            );
        }

        if ($modalidadeAssinatura === 'teste' && (int)$assinaturaVigente['teste_valido'] !== 1) {
            editarEmpresaErroTransacional(
                $conexao,
                'TRIAL_NAO_ELEGIVEL_TROCA_PLANO',
                'O período de teste está expirado, suspenso ou inconsistente. Regularize a assinatura antes de alterar o plano.'
            );
        }
        if (!in_array($modalidadeAssinatura, ['teste', 'paga'], true) || $statusAssinaturaAnterior !== 'ativa') {
            editarEmpresaErroTransacional(
                $conexao,
                'ASSINATURA_INCONSISTENTE',
                'A assinatura vigente não está em um estado compatível com a troca de plano.'
            );
        }

        $stmtPlanoAssinatura = $conexao->prepare(
            'SELECT preco_mensal, cobranca FROM plano WHERE id_plano = ? LIMIT 1 FOR UPDATE'
        );
        if (!$stmtPlanoAssinatura) {
            throw new RuntimeException('Prepare plano da assinatura falhou.');
        }
        $stmtPlanoAssinatura->bind_param('i', $planoId);
        if (!$stmtPlanoAssinatura->execute()) {
            $erro = $stmtPlanoAssinatura->error;
            $stmtPlanoAssinatura->close();
            throw new RuntimeException('Falha ao carregar plano da assinatura: ' . $erro);
        }
        $resPlanoAssinatura = $stmtPlanoAssinatura->get_result();
        $planoAssinatura = $resPlanoAssinatura ? ($resPlanoAssinatura->fetch_assoc() ?: null) : null;
        $stmtPlanoAssinatura->close();
        if (!$planoAssinatura) {
            throw new RuntimeException('Plano da nova assinatura não encontrado.');
        }

        $valorContratado = (float)$planoAssinatura['preco_mensal'];
        $periodicidadeAssinatura = (string)$planoAssinatura['cobranca'];

        if ($modalidadeAssinatura === 'teste') {
            $idPlanoAnteriorAssinatura = (int)$assinaturaVigente['id_plano'];
            $valorAnteriorAssinatura = (float)$assinaturaVigente['valor_contratado'];
            $periodicidadeAnteriorAssinatura = (string)$assinaturaVigente['periodicidade'];

            $stmtAtualizarTrial = $conexao->prepare(
                "UPDATE assinatura
                    SET id_plano = ?, valor_contratado = ?, periodicidade = ?
                  WHERE id_assinatura = ?
                    AND id_empresa = ?
                    AND status = 'ativa'
                    AND modalidade = 'teste'
                    AND id_plano = ?
                    AND teste_iniciado_em IS NOT NULL
                    AND teste_expira_em IS NOT NULL
                    AND teste_expira_em > teste_iniciado_em
                    AND teste_expira_em > CURRENT_TIMESTAMP"
            );
            if (!$stmtAtualizarTrial) {
                throw new RuntimeException('Prepare atualização contratual do trial falhou.');
            }
            $stmtAtualizarTrial->bind_param(
                'idsiii',
                $planoId,
                $valorContratado,
                $periodicidadeAssinatura,
                $assinaturaAnteriorId,
                $idEmpresa,
                $idPlanoAnteriorAssinatura
            );
            if (!$stmtAtualizarTrial->execute()) {
                $erro = $stmtAtualizarTrial->error;
                $stmtAtualizarTrial->close();
                throw new RuntimeException('Falha ao atualizar o plano do trial: ' . $erro);
            }
            $trialAlterado = $stmtAtualizarTrial->affected_rows;
            $stmtAtualizarTrial->close();
            if ($trialAlterado !== 1) {
                editarEmpresaErroTransacional(
                    $conexao,
                    'TRIAL_NAO_ELEGIVEL_TROCA_PLANO',
                    'O período de teste expirou antes da conclusão da troca. Regularize a assinatura antes de alterar o plano.'
                );
            }

            auditoriaRegistrar($conexao, 'assinatura.trocada', [
                'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
                'entidade_id' => $assinaturaAnteriorId,
                'entidade_rotulo' => 'Troca de assinatura da empresa ' . $nome,
                'descricao' => 'Trocou o plano contratual da assinatura de teste da empresa ' . $nome . '.',
                'alteracoes' => [
                    'id_plano' => ['antes' => $idPlanoAnteriorAssinatura, 'depois' => $planoId],
                    'valor_contratado' => ['antes' => $valorAnteriorAssinatura, 'depois' => $valorContratado],
                    'periodicidade' => ['antes' => $periodicidadeAnteriorAssinatura, 'depois' => $periodicidadeAssinatura],
                ],
                'contexto' => ['origem' => 'painel_super_admin'],
            ]);
        } else {
            $stmtEncerrarAssinatura = $conexao->prepare(
                "UPDATE assinatura
                    SET status = 'encerrada', data_fim = ?
                   WHERE id_assinatura = ?
                     AND id_empresa = ?
                     AND status = 'ativa'
                     AND modalidade = 'paga'"
            );
            if (!$stmtEncerrarAssinatura) {
                throw new RuntimeException('Prepare encerramento de assinatura falhou.');
            }
            $stmtEncerrarAssinatura->bind_param('sii', $dataOperacaoAssinatura, $assinaturaAnteriorId, $idEmpresa);
            if (!$stmtEncerrarAssinatura->execute()) {
                $erro = $stmtEncerrarAssinatura->error;
                $stmtEncerrarAssinatura->close();
                throw new RuntimeException('Falha ao encerrar assinatura: ' . $erro);
            }
            $assinaturaEncerrada = $stmtEncerrarAssinatura->affected_rows;
            $stmtEncerrarAssinatura->close();
            if ($assinaturaEncerrada !== 1) {
                editarEmpresaErroTransacional(
                    $conexao,
                    'ASSINATURA_ALTERADA',
                    'A assinatura foi alterada por outra operação. Atualize a empresa e tente novamente.'
                );
            }

            $statusAssinatura = 'ativa';
            $stmtNovaAssinatura = $conexao->prepare(
                "INSERT INTO assinatura
                    (id_empresa, id_plano, valor_contratado, periodicidade, dia_vencimento, data_inicio, data_fim, status)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?)"
            );
            if (!$stmtNovaAssinatura) {
                throw new RuntimeException('Prepare nova assinatura falhou.');
            }
            $stmtNovaAssinatura->bind_param(
                'iidsiss',
                $idEmpresa,
                $planoId,
                $valorContratado,
                $periodicidadeAssinatura,
                $diaVencimentoAssinatura,
                $dataOperacaoAssinatura,
                $statusAssinatura
            );
            if (!$stmtNovaAssinatura->execute()) {
                $erro = $stmtNovaAssinatura->error;
                $stmtNovaAssinatura->close();
                throw new RuntimeException('Falha ao criar nova assinatura: ' . $erro);
            }
            $novaAssinaturaId = (int)$stmtNovaAssinatura->insert_id;
            $stmtNovaAssinatura->close();

            auditoriaRegistrar($conexao, 'assinatura.encerrada', [
                'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
                'entidade_id' => $assinaturaAnteriorId,
                'entidade_rotulo' => 'Assinatura encerrada da empresa ' . $nome,
                'descricao' => 'Encerrou a assinatura anterior da empresa ' . $nome . '.',
                'alteracoes' => ['status' => ['antes' => 'ativa', 'depois' => 'encerrada'], 'data_fim' => ['antes' => null, 'depois' => $dataOperacaoAssinatura]],
                'contexto' => ['origem' => 'painel_super_admin'],
            ]);
            auditoriaRegistrar($conexao, 'assinatura.criada', [
                'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
                'entidade_id' => $novaAssinaturaId,
                'entidade_rotulo' => 'Nova assinatura da empresa ' . $nome,
                'descricao' => 'Criou nova assinatura para a empresa ' . $nome . '.',
                'alteracoes' => ['depois' => ['antes' => null, 'depois' => [
                    'id_empresa' => $idEmpresa,
                    'id_plano' => $planoId,
                    'valor_contratado' => $valorContratado,
                    'periodicidade' => $periodicidadeAssinatura,
                    'dia_vencimento' => $diaVencimentoAssinatura,
                    'data_inicio' => $dataOperacaoAssinatura,
                    'status' => $statusAssinatura,
                ]]],
                'contexto' => ['origem' => 'painel_super_admin'],
            ]);
            auditoriaRegistrar($conexao, 'assinatura.trocada', [
                'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresa),
                'entidade_id' => $novaAssinaturaId,
                'entidade_rotulo' => 'Troca de assinatura da empresa ' . $nome,
                'descricao' => 'Trocou o plano contratual da empresa ' . $nome . '.',
                'alteracoes' => [
                    'id_assinatura_anterior' => ['antes' => $assinaturaAnteriorId, 'depois' => null],
                    'id_plano' => ['antes' => (int)$empresaAnterior['plano_id'], 'depois' => $planoId],
                    'valor_contratado' => ['antes' => null, 'depois' => $valorContratado],
                    'periodicidade' => ['antes' => null, 'depois' => $periodicidadeAssinatura],
                ],
                'contexto' => ['origem' => 'painel_super_admin'],
            ]);
        }
    }

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
            throw new RuntimeException('Já existe um registro duplicado para os dados informados.');
        }

        throw new RuntimeException('Não foi possível atualizar a empresa. Erro: ' . $error);
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
            'quantidade_usuarios_bloqueados_plano' => (int)($resultadoTrocaPlano['data']['quantidade_bloqueada'] ?? 0),
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
