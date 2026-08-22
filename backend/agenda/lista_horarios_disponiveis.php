<?php
declare(strict_types=1);

// A agenda trabalha com a data local da empresa. Esta definição precisa
// ocorrer antes da validação de "hoje", não somente ao abrir a conexão.
date_default_timezone_set('America/Sao_Paulo');

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

function minutos_hora(string $hora): int
{
    [$horas, $minutos] = array_map('intval', explode(':', substr($hora, 0, 5)));
    return ($horas * 60) + $minutos;
}

function hora_minutos(int $minutos): string
{
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = $_SESSION['auth'] ?? null;
    $idUsuarioSessao = (int)($auth['id_usuario'] ?? 0);
    $statusSessao = (string)($auth['status'] ?? '');
    $idEmpresaSessao = (int)(
        $auth['id_empresa']
        ?? $_SESSION['empresa_id']
        ?? $_SESSION['id_empresa']
        ?? $_SESSION['empresa']['id_empresa']
        ?? $_SESSION['empresa']['id']
        ?? 0
    );

    if ($idUsuarioSessao <= 0) {
        out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
    }
    if ($statusSessao !== '' && $statusSessao !== 'ativo') {
        out(['ok' => false, 'code' => 'SESSION_USER_INACTIVE', 'user_msg' => 'Seu usuário não está ativo.'], 403);
    }
    if ($idEmpresaSessao <= 0) {
        out(['ok' => false, 'code' => 'SESSION_WITHOUT_COMPANY', 'user_msg' => 'Não foi possível identificar a empresa da sessão.'], 403);
    }

    $idProfissional = filter_input(INPUT_GET, 'id_profissional', FILTER_VALIDATE_INT) ?: 0;
    $idServico = filter_input(INPUT_GET, 'id_servico', FILTER_VALIDATE_INT) ?: 0;
    $duracaoSolicitada = filter_input(INPUT_GET, 'duracao', FILTER_VALIDATE_INT) ?: 0;
    $idAgendamentoIgnorar = filter_input(INPUT_GET, 'id_agendamento', FILTER_VALIDATE_INT) ?: 0;
    $dataTexto = trim((string)($_GET['data'] ?? ''));
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $dataTexto);

    $duracoesPermitidas = [15, 30, 45, 60, 75, 90, 105, 120, 150, 180, 210, 240];
    if ($idProfissional <= 0 || $idServico <= 0 || !in_array($duracaoSolicitada, $duracoesPermitidas, true) || !$data || $data->format('Y-m-d') !== $dataTexto) {
        out(['ok' => false, 'code' => 'INVALID_PARAMETERS', 'user_msg' => 'Informe profissional, serviço e uma data válida.'], 422);
    }
    $hoje = new DateTimeImmutable('today');
    if ($data < $hoje) {
        out(['ok' => false, 'code' => 'DATE_NOT_ALLOWED', 'user_msg' => 'Não é permitido agendar em uma data passada.'], 422);
    }

    require __DIR__ . '/../_config/conexao.php';
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out(['ok' => false, 'code' => 'DB_CONNECTION_ERROR', 'user_msg' => 'Erro de conexão com banco de dados.'], 500);
    }
    $conexao->set_charset('utf8mb4');

    $stmt = $conexao->prepare("
        SELECT e.status, eu.status
        FROM empresa e
        INNER JOIN empresa_usuario eu ON eu.id_empresa = e.id_empresa
        WHERE e.id_empresa = ? AND eu.id_usuario = ?
        LIMIT 1
    ");
    if (!$stmt) throw new RuntimeException('Falha ao validar empresa: ' . $conexao->error);
    $stmt->bind_param('ii', $idEmpresaSessao, $idUsuarioSessao);
    $stmt->execute();
    $stmt->bind_result($empresaStatus, $vinculoStatus);
    $vinculoEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$vinculoEncontrado || $empresaStatus !== 'ativo' || $vinculoStatus !== 'ativo') {
        out(['ok' => false, 'code' => 'COMPANY_ACCESS_DENIED', 'user_msg' => 'Acesso à empresa não autorizado.'], 403);
    }

    $stmt = $conexao->prepare("
        SELECT s.duracao_min
        FROM servico s
        INNER JOIN profissional p ON p.id_profissional = s.id_profissional
        INNER JOIN empresa_usuario eu ON eu.id_usuario = p.id_usuario AND eu.id_empresa = s.id_empresa
        WHERE s.id_servico = ? AND s.id_profissional = ? AND s.id_empresa = ?
          AND s.status = 'ativo' AND eu.status = 'ativo'
        LIMIT 1
    ");
    if (!$stmt) throw new RuntimeException('Falha ao validar serviço: ' . $conexao->error);
    $stmt->bind_param('iii', $idServico, $idProfissional, $idEmpresaSessao);
    $stmt->execute();
    $stmt->bind_result($duracaoMinDb);
    $servicoEncontrado = $stmt->fetch();
    $stmt->close();
    $duracaoPadraoMin = (int)$duracaoMinDb;

    if (!$servicoEncontrado || $duracaoPadraoMin <= 0) {
        out(['ok' => false, 'code' => 'SERVICE_NOT_FOUND', 'user_msg' => 'Serviço não encontrado para o profissional selecionado.'], 404);
    }
    $duracaoMin = $duracaoSolicitada;

    // Na edição, o horário da própria ocorrência precisa continuar visível,
    // inclusive se já tiver começado. Isso permite atualizar cliente/status
    // sem obrigar o usuário a escolher outro horário.
    $dataOriginalIgnorada = null;
    $minutoOriginalIgnorado = null;
    if ($idAgendamentoIgnorar > 0) {
        $stmtOriginal = $conexao->prepare("SELECT data_agendamento,hora_inicio,id_profissional,id_servico FROM agendamento WHERE id_agendamento=? AND id_empresa=? LIMIT 1");
        if (!$stmtOriginal) throw new RuntimeException('Falha ao consultar o horário original: ' . $conexao->error);
        $stmtOriginal->bind_param('ii', $idAgendamentoIgnorar, $idEmpresaSessao);
        $stmtOriginal->execute();
        $stmtOriginal->bind_result($dataOriginalDb, $horaOriginalDb, $profissionalOriginalDb, $servicoOriginalDb);
        if ($stmtOriginal->fetch() && (int)$profissionalOriginalDb === $idProfissional && (int)$servicoOriginalDb === $idServico) {
            $dataOriginalIgnorada = (string)$dataOriginalDb;
            $minutoOriginalIgnorado = minutos_hora((string)$horaOriginalDb);
        }
        $stmtOriginal->close();
    }

    $dias = [1 => 'segunda', 2 => 'terca', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sabado', 7 => 'domingo'];
    $diaSemana = $dias[(int)$data->format('N')];

    $usaPadraoHorario = 1;
    $stmt = $conexao->prepare("
        SELECT usa_padrao_empresa
        FROM horario_profissional
        WHERE id_empresa = ? AND id_profissional = ?
        ORDER BY id_horario_profissional ASC LIMIT 1
    ");
    if (!$stmt) throw new RuntimeException('Falha ao consultar origem dos horários: ' . $conexao->error);
    $stmt->bind_param('ii', $idEmpresaSessao, $idProfissional);
    $stmt->execute();
    $stmt->bind_result($usaPadraoHorarioDb);
    if ($stmt->fetch()) $usaPadraoHorario = (int)$usaPadraoHorarioDb;
    $stmt->close();

    $intervaloMin = 10;
    $stmt = $conexao->prepare("
        SELECT intervalo_padrao_min, usa_padrao_empresa
        FROM configuracao_geral_profissional
        WHERE id_empresa = ? AND id_profissional = ? AND status = 'ativo'
        LIMIT 1
    ");
    if (!$stmt) throw new RuntimeException('Falha ao consultar configuração profissional: ' . $conexao->error);
    $stmt->bind_param('ii', $idEmpresaSessao, $idProfissional);
    $stmt->execute();
    $stmt->bind_result($intervaloProfissional, $usaPadraoGeral);
    $temConfigProfissional = $stmt->fetch();
    $stmt->close();

    if ($temConfigProfissional && (int)$usaPadraoGeral === 0) {
        $intervaloMin = (int)$intervaloProfissional;
    } else {
        $stmt = $conexao->prepare("
            SELECT intervalo_padrao_min FROM configuracao_geral_empresa
            WHERE id_empresa = ? AND status = 'ativo' LIMIT 1
        ");
        if (!$stmt) throw new RuntimeException('Falha ao consultar configuração da empresa: ' . $conexao->error);
        $stmt->bind_param('i', $idEmpresaSessao);
        $stmt->execute();
        $stmt->bind_result($intervaloEmpresa);
        if ($stmt->fetch()) $intervaloMin = (int)$intervaloEmpresa;
        $stmt->close();
    }
    if ($intervaloMin <= 0) $intervaloMin = 10;

    /*
    |------------------------------------------------------------------
    | DIAS DE ATENDIMENTO
    |------------------------------------------------------------------
    | A interface usa esta lista para informar, antes da escolha da
    | data, quais dias da semana estão habilitados para o profissional.
    */
    $diasAtendimento = [];
    if ($usaPadraoHorario === 0) {
        $stmtDias = $conexao->prepare("
            SELECT dia_semana
            FROM horario_profissional
            WHERE id_empresa = ? AND id_profissional = ?
              AND disponivel = 1 AND status = 'ativo'
            ORDER BY FIELD(dia_semana, 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo')
        ");
        if (!$stmtDias) throw new RuntimeException('Falha ao consultar dias do profissional: ' . $conexao->error);
        $stmtDias->bind_param('ii', $idEmpresaSessao, $idProfissional);
    } else {
        $stmtDias = $conexao->prepare("
            SELECT dia_semana
            FROM horario_empresa
            WHERE id_empresa = ? AND disponivel = 1 AND status = 'ativo'
            ORDER BY FIELD(dia_semana, 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo')
        ");
        if (!$stmtDias) throw new RuntimeException('Falha ao consultar dias da empresa: ' . $conexao->error);
        $stmtDias->bind_param('i', $idEmpresaSessao);
    }
    $stmtDias->execute();
    $resultadoDias = $stmtDias->get_result();
    while ($linhaDia = $resultadoDias->fetch_assoc()) {
        $diasAtendimento[] = (string)$linhaDia['dia_semana'];
    }
    $stmtDias->close();

    if ($usaPadraoHorario === 0) {
        $origemHorario = 'horario_profissional';
        $stmt = $conexao->prepare("
            SELECT hora_inicio, hora_fim, almoco_inicio, almoco_fim, disponivel, status
            FROM horario_profissional
            WHERE id_empresa = ? AND id_profissional = ? AND dia_semana = ? LIMIT 1
        ");
        if (!$stmt) throw new RuntimeException('Falha ao consultar horário profissional: ' . $conexao->error);
        $stmt->bind_param('iis', $idEmpresaSessao, $idProfissional, $diaSemana);
    } else {
        $origemHorario = 'horario_empresa';
        $stmt = $conexao->prepare("
            SELECT hora_inicio, hora_fim, almoco_inicio, almoco_fim, disponivel, status
            FROM horario_empresa
            WHERE id_empresa = ? AND dia_semana = ? LIMIT 1
        ");
        if (!$stmt) throw new RuntimeException('Falha ao consultar horário da empresa: ' . $conexao->error);
        $stmt->bind_param('is', $idEmpresaSessao, $diaSemana);
    }

    $stmt->execute();
    $stmt->bind_result($horaInicio, $horaFim, $almocoInicio, $almocoFim, $disponivel, $horarioStatus);
    $horarioEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$horarioEncontrado || (int)$disponivel !== 1 || $horarioStatus !== 'ativo' || !$horaInicio || !$horaFim) {
        out(['ok' => true, 'data' => [
            'data' => $dataTexto, 'dia_semana' => $diaSemana, 'atende_no_dia' => false,
            'origem_horario' => $origemHorario, 'intervalo_min' => $intervaloMin,
            'duracao_servico_min' => $duracaoMin, 'dias_atendimento' => $diasAtendimento,
            'horarios' => []
        ]]);
    }

    $inicioExpediente = minutos_hora((string)$horaInicio);
    $fimExpediente = minutos_hora((string)$horaFim);
    $inicioAlmoco = $almocoInicio ? minutos_hora((string)$almocoInicio) : null;
    $fimAlmoco = $almocoFim ? minutos_hora((string)$almocoFim) : null;
    $horarios = [];

    /*
    |------------------------------------------------------------------
    | CONFLITOS COM AGENDAMENTOS EXISTENTES
    |------------------------------------------------------------------
    | Cancelados, concluidos e faltas nao bloqueiam a grade. A comparacao
    | por sobreposicao tambem protege servicos com duracoes diferentes.
    */
    $stmtConflito = $conexao->prepare("
        SELECT id_agendamento
        FROM agendamento
        WHERE id_empresa = ?
          AND id_profissional = ?
          AND data_agendamento = ?
          AND status IN ('pendente', 'confirmado')
          AND (? = 0 OR id_agendamento <> ?)
          AND hora_inicio < ?
          AND hora_fim > ?
        LIMIT 1
    ");
    if (!$stmtConflito) throw new RuntimeException('Falha ao validar conflitos de agenda: ' . $conexao->error);

    $dataEhHoje = $data->format('Y-m-d') === $hoje->format('Y-m-d');
    $agora = new DateTimeImmutable('now');
    $minutoAtual = ((int)$agora->format('H') * 60) + (int)$agora->format('i');

    for ($inicio = $inicioExpediente; $inicio + $duracaoMin <= $fimExpediente; $inicio += $intervaloMin) {
        // No dia atual, nunca oferece um horário que já começou.
        $ehHorarioOriginal = $dataOriginalIgnorada === $dataTexto && $minutoOriginalIgnorado === $inicio;
        if ($dataEhHoje && $inicio <= $minutoAtual && !$ehHorarioOriginal) continue;
        $fim = $inicio + $duracaoMin;
        $invadeAlmoco = $inicioAlmoco !== null && $fimAlmoco !== null
            && $inicio < $fimAlmoco && $fim > $inicioAlmoco;
        if ($invadeAlmoco) continue;

        $inicioTexto = hora_minutos($inicio) . ':00';
        $fimTexto = hora_minutos($fim) . ':00';
        $stmtConflito->bind_param('iisiiss', $idEmpresaSessao, $idProfissional, $dataTexto, $idAgendamentoIgnorar, $idAgendamentoIgnorar, $fimTexto, $inicioTexto);
        $stmtConflito->execute();
        $stmtConflito->store_result();
        if ($stmtConflito->num_rows === 0) {
            $horarios[] = ['hora_inicio' => hora_minutos($inicio), 'hora_fim' => hora_minutos($fim)];
        }
    }
    $stmtConflito->close();

    out(['ok' => true, 'data' => [
        'data' => $dataTexto, 'dia_semana' => $diaSemana, 'atende_no_dia' => true,
        'origem_horario' => $origemHorario, 'intervalo_min' => $intervaloMin,
        'duracao_servico_min' => $duracaoMin,
        'dias_atendimento' => $diasAtendimento,
        'hora_inicio_expediente' => substr((string)$horaInicio, 0, 5),
        'hora_fim_expediente' => substr((string)$horaFim, 0, 5),
        'horarios' => $horarios,
        'considera_conflitos_agendamento' => true
    ]]);
} catch (Throwable $e) {
    error_log('[horarios_disponiveis] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'INTERNAL_ERROR', 'user_msg' => 'Não foi possível calcular os horários disponíveis.'], 500);
}
