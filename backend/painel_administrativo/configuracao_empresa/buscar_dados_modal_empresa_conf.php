<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('getIdEmpresaFromSession')) {
    function getIdEmpresaFromSession(): int
    {
        $candidatos = [
            $_SESSION['auth']['id_empresa'] ?? null,
            $_SESSION['empresa_id'] ?? null,
            $_SESSION['id_empresa'] ?? null,
            $_SESSION['empresa']['id_empresa'] ?? null,
            $_SESSION['empresa']['id'] ?? null,
        ];

        foreach ($candidatos as $valor) {
            if (filter_var($valor, FILTER_VALIDATE_INT) !== false && (int)$valor > 0) {
                return (int)$valor;
            }
        }

        return 0;
    }
}

if (!function_exists('normalizarHora')) {
    function normalizarHora($valor, string $fallback = ''): string
    {
        $valor = trim((string)($valor ?? ''));

        if ($valor === '') {
            return $fallback;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $valor)) {
            return substr($valor, 0, 5);
        }

        if (preg_match('/^\d{2}:\d{2}$/', $valor)) {
            return $valor;
        }

        return $fallback;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../_config/conexao.php';

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

$idEmpresa = getIdEmpresaFromSession();

if ($idEmpresa <= 0) {
    out([
        'ok' => false,
        'code' => 'INVALID_COMPANY',
        'user_msg' => 'Empresa da sessão não encontrada.',
    ], 401);
}

try {
    $sqlEmpresa = "
        SELECT id_empresa, nome, status
        FROM empresa
        WHERE id_empresa = ?
        LIMIT 1
    ";

    $stmtEmpresa = $conexao->prepare($sqlEmpresa);
    if (!$stmtEmpresa) {
        throw new Exception('Falha ao preparar consulta da empresa.');
    }

    $stmtEmpresa->bind_param('i', $idEmpresa);
    $stmtEmpresa->execute();

    $resultEmpresa = $stmtEmpresa->get_result();
    $empresa = $resultEmpresa ? $resultEmpresa->fetch_assoc() : null;
    $stmtEmpresa->close();

    if (!$empresa) {
        out([
            'ok' => false,
            'code' => 'COMPANY_NOT_FOUND',
            'user_msg' => 'Empresa não encontrada.',
        ], 404);
    }

    if (($empresa['status'] ?? '') !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'COMPANY_INACTIVE',
            'user_msg' => 'A empresa está inativa.',
        ], 403);
    }

    $sqlConfig = "
        SELECT
            id_config,
            id_empresa,
            inicio_semana,
            intervalo_padrao_min,
            observacao_padrao,
            status,
            criado_em,
            atualizado_em
        FROM configuracao_geral_empresa
        WHERE id_empresa = ?
        LIMIT 1
    ";

    $stmtConfig = $conexao->prepare($sqlConfig);
    if (!$stmtConfig) {
        throw new Exception('Falha ao preparar consulta da configuração geral.');
    }

    $stmtConfig->bind_param('i', $idEmpresa);
    $stmtConfig->execute();

    $resultConfig = $stmtConfig->get_result();
    $config = $resultConfig ? $resultConfig->fetch_assoc() : null;
    $stmtConfig->close();

    $inicioSemana = (string)($config['inicio_semana'] ?? 'segunda');
    $intervaloPadraoMin = (int)($config['intervalo_padrao_min'] ?? 10);
    $observacaoPadrao = trim((string)($config['observacao_padrao'] ?? ''));

    $diasOrdem = [
        'segunda',
        'terca',
        'quarta',
        'quinta',
        'sexta',
        'sabado',
        'domingo',
    ];

    $horarios = [];

    foreach ($diasOrdem as $dia) {
        $horarios[$dia] = [
            'id_horario_empresa' => null,
            'id_empresa' => $idEmpresa,
            'dia_semana' => $dia,
            'ativo' => 0,
            'disponivel' => 0,
            'hora_inicio' => '',
            'hora_fim' => '',
            'almoco_inicio' => '',
            'almoco_fim' => '',
            'status' => 'ativo',
            'criado_em' => null,
            'atualizado_em' => null,
        ];
    }

    $sqlHorarios = "
        SELECT
            id_horario_empresa,
            id_empresa,
            dia_semana,
            hora_inicio,
            hora_fim,
            almoco_inicio,
            almoco_fim,
            disponivel,
            status,
            criado_em,
            atualizado_em
        FROM horario_empresa
        WHERE id_empresa = ?
        ORDER BY FIELD(
            dia_semana,
            'segunda','terca','quarta','quinta','sexta','sabado','domingo'
        )
    ";

    $stmtHorarios = $conexao->prepare($sqlHorarios);
    if (!$stmtHorarios) {
        throw new Exception('Falha ao preparar consulta dos horários.');
    }

    $stmtHorarios->bind_param('i', $idEmpresa);
    $stmtHorarios->execute();

    $resultHorarios = $stmtHorarios->get_result();
    if ($resultHorarios === false) {
        throw new Exception('Falha ao obter resultado dos horários.');
    }

    $diasSelecionados = [];
    $horarioBase = null;

    while ($row = $resultHorarios->fetch_assoc()) {
        $diaSemana = trim((string)($row['dia_semana'] ?? ''));

        if (!in_array($diaSemana, $diasOrdem, true)) {
            continue;
        }

        $statusLinha = trim((string)($row['status'] ?? 'ativo'));
        $disponivel = (int)($row['disponivel'] ?? 0);

        $item = [
            'id_horario_empresa' => isset($row['id_horario_empresa']) ? (int)$row['id_horario_empresa'] : null,
            'id_empresa' => isset($row['id_empresa']) ? (int)$row['id_empresa'] : $idEmpresa,
            'dia_semana' => $diaSemana,

            'ativo' => $disponivel,
            'disponivel' => $disponivel,

            'hora_inicio' => normalizarHora($row['hora_inicio'] ?? null, ''),
            'hora_fim' => normalizarHora($row['hora_fim'] ?? null, ''),
            'almoco_inicio' => normalizarHora($row['almoco_inicio'] ?? null, ''),
            'almoco_fim' => normalizarHora($row['almoco_fim'] ?? null, ''),

            'status' => $statusLinha,
            'criado_em' => $row['criado_em'] ?? null,
            'atualizado_em' => $row['atualizado_em'] ?? null,
        ];

        $horarios[$diaSemana] = $item;

        if ($disponivel === 1 && strtolower($statusLinha) === 'ativo') {
            $diasSelecionados[] = $diaSemana;

            if ($horarioBase === null) {
                $horarioBase = $item;
            }
        }
    }

    $stmtHorarios->close();

    $diasSelecionados = array_values(array_unique($diasSelecionados));

    $horaInicio = $horarioBase['hora_inicio'] ?? '08:00';
    $horaFim = $horarioBase['hora_fim'] ?? '18:00';
    $almocoInicio = $horarioBase['almoco_inicio'] ?? '';
    $almocoFim = $horarioBase['almoco_fim'] ?? '';

    $sqlWhatsapp = "
        SELECT
            id_config_whatsapp,
            id_empresa,
            ddi_padrao,
            ddd_padrao,
            mensagem_padrao,
            status,
            criado_em,
            atualizado_em
        FROM configuracao_whatsapp_empresa
        WHERE id_empresa = ?
        LIMIT 1
    ";

    $stmtWhatsapp = $conexao->prepare($sqlWhatsapp);
    if (!$stmtWhatsapp) {
        throw new Exception('Falha ao preparar consulta da configuração do WhatsApp.');
    }

    $stmtWhatsapp->bind_param('i', $idEmpresa);
    $stmtWhatsapp->execute();

    $resultWhatsapp = $stmtWhatsapp->get_result();
    $whatsapp = $resultWhatsapp ? $resultWhatsapp->fetch_assoc() : null;
    $stmtWhatsapp->close();

    $ddiPadrao = trim((string)($whatsapp['ddi_padrao'] ?? '55'));
    $dddPadrao = trim((string)($whatsapp['ddd_padrao'] ?? ''));
    $mensagemPadraoWhatsapp = trim((string)($whatsapp['mensagem_padrao'] ?? 'Olá {cliente}! Seu agendamento de {servico} está {status} para {data} às {hora}.'));
    $statusWhatsapp = trim((string)($whatsapp['status'] ?? 'ativo'));

    out([
        'ok' => true,
        'code' => 'CONFIG_LOADED',
        'user_msg' => 'Configurações da agenda carregadas com sucesso.',
        'data' => [
            'id_empresa' => $idEmpresa,

            'id_config' => isset($config['id_config']) ? (int)$config['id_config'] : null,
            'id_config_whatsapp' => isset($whatsapp['id_config_whatsapp']) ? (int)$whatsapp['id_config_whatsapp'] : null,

            'inicio_semana' => $inicioSemana,
            'semana_inicio' => $inicioSemana,

            'intervalo_padrao_min' => $intervaloPadraoMin,
            'intervalo_padrao' => (string)$intervaloPadraoMin,

            'observacao_padrao' => $observacaoPadrao,

            'status' => (string)($config['status'] ?? 'ativo'),
            'criado_em' => $config['criado_em'] ?? null,
            'atualizado_em' => $config['atualizado_em'] ?? null,

            'hora_inicio' => $horaInicio,
            'hora_fim' => $horaFim,
            'almoco_inicio' => $almocoInicio,
            'almoco_fim' => $almocoFim,

            'dias' => $diasSelecionados,
            'horarios' => $horarios,

            'ddi_padrao' => $ddiPadrao,
            'ddd_padrao' => $dddPadrao,
            'mensagem_padrao' => $mensagemPadraoWhatsapp,
            'whatsapp_status' => $statusWhatsapp,
            'whatsapp_criado_em' => $whatsapp['criado_em'] ?? null,
            'whatsapp_atualizado_em' => $whatsapp['atualizado_em'] ?? null,

            'empresa' => [
                'id_empresa' => (int)$empresa['id_empresa'],
                'nome' => (string)($empresa['nome'] ?? ''),
                'status' => (string)($empresa['status'] ?? ''),
            ],

            'origem' => [
                'geral' => $config ? 'configuracao_geral_empresa' : 'padrao_sistema',
                'horarios' => count($diasSelecionados) > 0 ? 'horario_empresa' : 'padrao_sistema',
                'whatsapp' => $whatsapp ? 'configuracao_whatsapp_empresa' : 'padrao_sistema',
            ],
        ],
    ], 200);

} catch (Throwable $e) {
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao buscar as configurações gerais da agenda.',
        'debug' => $e->getMessage(),
    ], 500);
}