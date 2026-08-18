<?php
declare(strict_types=1);

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }

    require_once __DIR__ . '/../../_auth/require_auth.php';
    require __DIR__ . '/../../_config/conexao.php';

    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $idEmpresa = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? 0);
    $tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
    $modoSuporte = (bool)($auth['modo_suporte'] ?? false);

    if ($idEmpresa <= 0) {
        out(['ok' => false, 'code' => 'SESSION_WITHOUT_COMPANY', 'user_msg' => 'Empresa da sessão não identificada.'], 403);
    }
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        throw new RuntimeException('Conexão com o banco indisponível.');
    }
    $conexao->set_charset('utf8mb4');

    if ($tipoUsuario === 'super_admin' && $modoSuporte) {
        $stmt = $conexao->prepare("SELECT 1 FROM empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao validar empresa da sessão.');
        $stmt->bind_param('i', $idEmpresa);
    } else {
        $stmt = $conexao->prepare("SELECT 1 FROM empresa_usuario eu INNER JOIN empresa e ON e.id_empresa = eu.id_empresa INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil WHERE eu.id_empresa = ? AND eu.id_usuario = ? AND eu.status = 'ativo' AND e.status = 'ativo' AND pf.status = 'ativo' LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao validar vínculo da sessão.');
        $stmt->bind_param('ii', $idEmpresa, $idUsuario);
    }
    $stmt->execute();
    $acessoValido = (bool)$stmt->get_result()?->fetch_row();
    $stmt->close();
    if (!$acessoValido) {
        out(['ok' => false, 'code' => 'COMPANY_ACCESS_DENIED', 'user_msg' => 'Acesso à empresa não autorizado.'], 403);
    }

    $hoje = (new DateTimeImmutable('now'))->format('Y-m-d');
    $servidorAgora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $stmt = $conexao->prepare("SELECT COUNT(*) total, SUM(status = 'confirmado') confirmados, SUM(status = 'pendente') pendentes, SUM(status = 'cancelado') cancelados FROM agendamento WHERE id_empresa = ? AND data_agendamento = ?");
    if (!$stmt) throw new RuntimeException('Falha ao preparar totais do resumo.');
    $stmt->bind_param('is', $idEmpresa, $hoje);
    $stmt->execute();
    $totais = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $stmt = $conexao->prepare("SELECT a.id_agendamento, c.nome_completo cliente, u.nome profissional, s.nome servico, DATE_FORMAT(a.hora_inicio, '%H:%i') hora_inicio, DATE_FORMAT(a.hora_fim, '%H:%i') hora_fim, a.status FROM agendamento a INNER JOIN cliente c ON c.id_cliente = a.id_cliente AND c.id_empresa = a.id_empresa INNER JOIN profissional p ON p.id_profissional = a.id_profissional INNER JOIN usuario u ON u.id_usuario = p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario = p.id_usuario AND eu.id_empresa = a.id_empresa AND eu.status = 'ativo' INNER JOIN servico s ON s.id_servico = a.id_servico AND s.id_empresa = a.id_empresa AND s.id_profissional = a.id_profissional WHERE a.id_empresa = ? AND a.data_agendamento = ? AND a.status IN ('pendente','confirmado') AND a.hora_fim > CURTIME() ORDER BY a.hora_inicio ASC, a.id_agendamento ASC LIMIT 10");
    if (!$stmt) throw new RuntimeException('Falha ao preparar próximos atendimentos.');
    $stmt->bind_param('is', $idEmpresa, $hoje);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $proximos = [];
    while ($row = $resultado->fetch_assoc()) {
        $proximos[] = [
            'id_agendamento' => (int)$row['id_agendamento'],
            'cliente' => (string)$row['cliente'],
            'profissional' => (string)$row['profissional'],
            'servico' => (string)$row['servico'],
            'hora_inicio' => (string)$row['hora_inicio'],
            'hora_fim' => (string)$row['hora_fim'],
            'status' => (string)$row['status'],
        ];
    }
    $stmt->close();

    $stmt = $conexao->prepare("SELECT p.id_profissional, u.nome, u.foto_perfil, COUNT(a.id_agendamento) total, COALESCE(SUM(a.status = 'confirmado'),0) confirmados, COALESCE(SUM(a.status = 'pendente'),0) pendentes, COALESCE(SUM(a.status = 'cancelado'),0) cancelados FROM profissional p INNER JOIN usuario u ON u.id_usuario = p.id_usuario AND u.status = 'ativo' INNER JOIN empresa_usuario eu ON eu.id_usuario = p.id_usuario AND eu.id_empresa = ? AND eu.status = 'ativo' INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil AND pf.status = 'ativo' AND LOWER(TRIM(pf.nome)) IN ('profissional','profissionais') LEFT JOIN agendamento a ON a.id_profissional = p.id_profissional AND a.id_empresa = eu.id_empresa AND a.data_agendamento = ? GROUP BY p.id_profissional, u.nome, u.foto_perfil ORDER BY u.nome ASC, p.id_profissional ASC");
    if (!$stmt) throw new RuntimeException('Falha ao preparar resumo por profissional.');
    $stmt->bind_param('is', $idEmpresa, $hoje);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $profissionais = [];
    $indiceProfissional = [];
    while ($row = $resultado->fetch_assoc()) {
        $indiceProfissional[(int)$row['id_profissional']] = count($profissionais);
        $profissionais[] = [
            'id_profissional' => (int)$row['id_profissional'],
            'nome' => (string)$row['nome'],
            'foto_perfil' => $row['foto_perfil'] !== null ? (string)$row['foto_perfil'] : null,
            'total' => (int)$row['total'],
            'confirmados' => (int)$row['confirmados'],
            'pendentes' => (int)$row['pendentes'],
            'cancelados' => (int)$row['cancelados'],
            'em_atendimento' => false,
            'atendimento_atual' => null,
        ];
    }
    $stmt->close();

    $inicioSemana = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $fimSemana = (new DateTimeImmutable($inicioSemana))->modify('+6 days')->format('Y-m-d');
    require_once __DIR__ . '/../../agenda/calculo_ocupacao_semanal.php';
    $ocupacao = calcularOcupacaoSemanal($conexao, $idEmpresa, array_keys($indiceProfissional), $inicioSemana, $fimSemana);

    $stmt = $conexao->prepare("SELECT a.id_agendamento, a.id_profissional, c.nome_completo cliente, s.nome servico, DATE_FORMAT(a.hora_inicio, '%H:%i') hora_inicio, DATE_FORMAT(a.hora_fim, '%H:%i') hora_fim FROM agendamento a INNER JOIN cliente c ON c.id_cliente = a.id_cliente AND c.id_empresa = a.id_empresa INNER JOIN servico s ON s.id_servico = a.id_servico AND s.id_empresa = a.id_empresa AND s.id_profissional = a.id_profissional WHERE a.id_empresa = ? AND a.data_agendamento = ? AND a.status = 'confirmado' AND a.hora_inicio <= CURTIME() AND a.hora_fim > CURTIME() ORDER BY a.hora_inicio ASC, a.id_agendamento ASC");
    if (!$stmt) throw new RuntimeException('Falha ao preparar atendimentos atuais.');
    $stmt->bind_param('is', $idEmpresa, $hoje);
    $stmt->execute();
    $resultado = $stmt->get_result();
    while ($row = $resultado->fetch_assoc()) {
        $idProfissional = (int)$row['id_profissional'];
        if (!array_key_exists($idProfissional, $indiceProfissional)) continue;
        $i = $indiceProfissional[$idProfissional];
        $profissionais[$i]['em_atendimento'] = true;
        // Em caso de inconsistência com horários sobrepostos, exibe o primeiro por ordem cronológica.
        if ($profissionais[$i]['atendimento_atual'] === null) {
            $profissionais[$i]['atendimento_atual'] = [
                'id_agendamento' => (int)$row['id_agendamento'],
                'cliente' => (string)$row['cliente'],
                'servico' => (string)$row['servico'],
                'hora_inicio' => (string)$row['hora_inicio'],
                'hora_fim' => (string)$row['hora_fim'],
            ];
        }
    }
    $stmt->close();

    out([
        'ok' => true,
        'code' => 'RESUMO_DIA_OK',
        'user_msg' => '',
        'data' => [
            'data' => $hoje,
            'servidor_agora' => $servidorAgora,
            'resumo' => [
                'agendamentos' => (int)($totais['total'] ?? 0),
                'confirmados' => (int)($totais['confirmados'] ?? 0),
                'pendentes' => (int)($totais['pendentes'] ?? 0),
                'cancelados' => (int)($totais['cancelados'] ?? 0),
                // Sem entidade de pagamento, valor_aplicado não comprova receita realizada.
                'faturamento' => null,
                'ocupacao' => $ocupacao,
            ],
            'proximos_atendimentos' => $proximos,
            'profissionais' => $profissionais,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[lista_resumo_dia] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'RESUMO_DIA_ERROR', 'user_msg' => 'Não foi possível carregar o resumo do dia.'], 500);
}
