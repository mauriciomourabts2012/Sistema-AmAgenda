<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

try {
    clienteAgendamentoMetodo('GET');
    $contexto = clienteAgendamentoContexto($conexao);
    $idEmpresa = (int)$contexto['id_empresa'];
    $idProfissional = clienteAgendamentoId($_GET['id_profissional'] ?? null);
    $idServico = clienteAgendamentoId($_GET['id_servico'] ?? null);
    $operacao = trim((string)($_GET['operacao'] ?? ''));

    if ($idProfissional <= 0 || $idServico <= 0) {
        out(['ok' => false, 'code' => 'SCHEDULE_SELECTION_INVALID', 'user_msg' => 'Selecione um profissional e um serviço válidos.'], 422);
    }
    $servico = clienteAgendamentoServico($conexao, $idEmpresa, $idProfissional, $idServico);
    if ($servico === null) {
        out(['ok' => false, 'code' => 'SERVICE_NOT_FOUND', 'user_msg' => 'O serviço não está disponível para o profissional selecionado.'], 404);
    }

    $hoje = new DateTimeImmutable('today');
    $maxima = $hoje->modify('+' . CLIENTE_AGENDAMENTO_JANELA_DIAS . ' days');

    if ($operacao === 'dias') {
        $ano = filter_var($_GET['ano'] ?? null, FILTER_VALIDATE_INT);
        $mes = filter_var($_GET['mes'] ?? null, FILTER_VALIDATE_INT);
        if (!$ano || !$mes || $mes < 1 || $mes > 12) {
            out(['ok' => false, 'code' => 'MONTH_INVALID', 'user_msg' => 'Mês inválido.'], 422);
        }
        $primeiroDia = clienteAgendamentoData(sprintf('%04d-%02d-01', $ano, $mes));
        if ($primeiroDia === null) {
            out(['ok' => false, 'code' => 'MONTH_INVALID', 'user_msg' => 'Mês inválido.'], 422);
        }
        $ultimoDia = $primeiroDia->modify('last day of this month');
        if ($ultimoDia < $hoje || $primeiroDia > $maxima) {
            out(['ok' => true, 'code' => 'CLIENT_AVAILABLE_DAYS_LISTED', 'data' => [
                'ano' => (int)$ano, 'mes' => (int)$mes, 'datas_disponiveis' => [],
                'data_minima' => $hoje->format('Y-m-d'), 'data_maxima' => $maxima->format('Y-m-d'),
            ]]);
        }

        $inicio = $primeiroDia < $hoje ? $hoje : $primeiroDia;
        $fim = $ultimoDia > $maxima ? $maxima : $ultimoDia;
        $datas = [];
        for ($data = $inicio; $data <= $fim; $data = $data->modify('+1 day')) {
            if (clienteAgendamentoHorarios($conexao, $idEmpresa, $servico, $data) !== []) {
                $datas[] = $data->format('Y-m-d');
            }
        }
        out(['ok' => true, 'code' => 'CLIENT_AVAILABLE_DAYS_LISTED', 'data' => [
            'ano' => (int)$ano, 'mes' => (int)$mes, 'datas_disponiveis' => $datas,
            'data_minima' => $hoje->format('Y-m-d'), 'data_maxima' => $maxima->format('Y-m-d'),
        ]]);
    }

    if ($operacao === 'horarios') {
        $dataTexto = trim((string)($_GET['data'] ?? ''));
        $data = clienteAgendamentoData($dataTexto);
        if ($data === null || $data < $hoje || $data > $maxima) {
            out(['ok' => false, 'code' => 'DATE_NOT_ALLOWED', 'user_msg' => 'Selecione uma data válida dentro da janela disponível.'], 422);
        }
        $grade = clienteAgendamentoGrade($conexao, $idEmpresa, $idProfissional, $data);
        $horarios = clienteAgendamentoHorarios($conexao, $idEmpresa, $servico, $data);
        out(['ok' => true, 'code' => 'CLIENT_AVAILABLE_TIMES_LISTED', 'data' => [
            'data' => $dataTexto,
            'horarios' => $horarios,
            'duracao_servico_min' => (int)$servico['duracao_min'],
            'intervalo_min' => $grade === null ? null : (int)$grade['intervalo_min'],
            'origem_horario' => $grade['origem'] ?? null,
        ]]);
    }

    out(['ok' => false, 'code' => 'SCHEDULE_OPERATION_INVALID', 'user_msg' => 'Operação de disponibilidade inválida.'], 422);
} catch (Throwable $e) {
    error_log('[cliente_agendamento_disponibilidade] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'CLIENT_SCHEDULE_LOAD_ERROR', 'user_msg' => 'Não foi possível consultar a disponibilidade agora.'], 500);
}
