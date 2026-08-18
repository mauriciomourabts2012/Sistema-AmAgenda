<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LISTAR AGENDA
|--------------------------------------------------------------------------
| - Lista dados reais da tabela agendamento.
| - A empresa vem exclusivamente da sessão autenticada.
| - Perfil Profissional visualiza somente os próprios agendamentos.
| - Demais perfis autorizados visualizam os profissionais da empresa.
| - O retorno é agrupado por dia da semana para o componente da agenda.
|--------------------------------------------------------------------------
*/

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

function lista_agenda_data_valida(string $valor): ?string
{
    if ($valor === '') return null;
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $data && $data->format('Y-m-d') === $valor ? $valor : null;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $auth = $_SESSION['auth'] ?? [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
    $modoSuporte = (bool)($auth['modo_suporte'] ?? false);
    $idEmpresa = (int)(
        $auth['id_empresa']
        ?? $_SESSION['empresa_id']
        ?? $_SESSION['id_empresa']
        ?? $_SESSION['empresa']['id_empresa']
        ?? $_SESSION['empresa']['id']
        ?? 0
    );

    if ($idUsuario <= 0) {
        out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
    }
    if (($auth['status'] ?? 'ativo') !== 'ativo') {
        out(['ok' => false, 'code' => 'USER_INACTIVE', 'user_msg' => 'Seu usuário está inativo.'], 403);
    }
    if ($idEmpresa <= 0) {
        out(['ok' => false, 'code' => 'SESSION_WITHOUT_COMPANY', 'user_msg' => 'Empresa da sessão não identificada.'], 403);
    }

    $dataInicioRaw = trim((string)($_GET['data_inicio'] ?? ''));
    $dataFimRaw = trim((string)($_GET['data_fim'] ?? ''));
    $dataInicio = lista_agenda_data_valida($dataInicioRaw);
    $dataFim = lista_agenda_data_valida($dataFimRaw);

    if (($dataInicioRaw !== '' && $dataInicio === null) || ($dataFimRaw !== '' && $dataFim === null)) {
        out(['ok' => false, 'code' => 'INVALID_DATE_FILTER', 'user_msg' => 'O período informado é inválido.'], 422);
    }
    if ($dataInicio !== null && $dataFim !== null && $dataInicio > $dataFim) {
        out(['ok' => false, 'code' => 'INVALID_DATE_RANGE', 'user_msg' => 'A data inicial não pode ser posterior à data final.'], 422);
    }

    require __DIR__ . '/../_config/conexao.php';
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        throw new RuntimeException('Conexão com o banco indisponível.');
    }
    $conexao->set_charset('utf8mb4');

    // Super Admin em suporte usa a empresa da sessão sem exigir vínculo em empresa_usuario.
    if ($tipoUsuario === 'super_admin' && $modoSuporte) {
        $stmt = $conexao->prepare("SELECT 1 FROM empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao preparar validação da empresa: ' . $conexao->error);
        $stmt->bind_param('i', $idEmpresa);
        $stmt->execute();
        $vinculoEncontrado = (bool)$stmt->get_result()?->fetch_row();
        $stmt->close();
        $perfilNome = 'proprietario';
        $idProfissionalSessao = null;
    } else {
        // Usuários comuns continuam dependendo do vínculo ativo com a empresa.
        $stmt = $conexao->prepare("
            SELECT pf.nome, p.id_profissional
            FROM empresa_usuario eu
            INNER JOIN empresa e ON e.id_empresa = eu.id_empresa
            INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
            LEFT JOIN profissional p ON p.id_usuario = eu.id_usuario
            WHERE eu.id_empresa = ?
              AND eu.id_usuario = ?
              AND eu.status = 'ativo'
              AND e.status = 'ativo'
              AND pf.status = 'ativo'
            LIMIT 1
        ");
        if (!$stmt) throw new RuntimeException('Falha ao preparar validação da sessão: ' . $conexao->error);
        $stmt->bind_param('ii', $idEmpresa, $idUsuario);
        $stmt->execute();
        $stmt->bind_result($perfilNome, $idProfissionalSessao);
        $vinculoEncontrado = $stmt->fetch();
        $stmt->close();
    }

    if (!$vinculoEncontrado) {
        out(['ok' => false, 'code' => 'COMPANY_ACCESS_DENIED', 'user_msg' => 'Acesso à empresa não autorizado.'], 403);
    }

    $perfilNormalizado = mb_strtolower(trim((string)$perfilNome));
    $somenteProprioProfissional = in_array($perfilNormalizado, ['profissional', 'profissionais'], true);
    if ($somenteProprioProfissional && (int)$idProfissionalSessao <= 0) {
        out(['ok' => false, 'code' => 'PROFESSIONAL_NOT_LINKED', 'user_msg' => 'Seu usuário não possui cadastro profissional vinculado.'], 403);
    }

    $sql = "
        SELECT
            a.id_agendamento,
            a.id_cliente,
            a.id_profissional,
            a.id_servico,
            DATE_FORMAT(a.data_agendamento, '%Y-%m-%d') AS data_agendamento,
            DATE_FORMAT(a.hora_inicio, '%H:%i') AS hora_inicio,
            DATE_FORMAT(a.hora_fim, '%H:%i') AS hora_fim,
            a.duracao_min_aplicada,
            a.valor_aplicado,
            a.status,
            a.observacao,
            a.repetir_semanalmente,
            a.recorrencia_data_fim,
            a.grupo_recorrencia,
            a.criado_por,
            a.criado_em,
            a.atualizado_em,
            c.nome_completo AS cliente_nome,
            c.whatsapp_celular AS cliente_telefone,
            u.nome AS profissional_nome,
            p.especialidade AS profissional_especialidade,
            s.nome AS servico_nome
        FROM agendamento a
        INNER JOIN cliente c
                ON c.id_cliente = a.id_cliente
               AND c.id_empresa = a.id_empresa
        INNER JOIN profissional p
                ON p.id_profissional = a.id_profissional
        INNER JOIN usuario u
                ON u.id_usuario = p.id_usuario
        INNER JOIN empresa_usuario eup
                ON eup.id_usuario = p.id_usuario
               AND eup.id_empresa = a.id_empresa
        INNER JOIN servico s
                ON s.id_servico = a.id_servico
               AND s.id_profissional = a.id_profissional
               AND s.id_empresa = a.id_empresa
        WHERE a.id_empresa = ?
          AND eup.status = 'ativo'
    ";

    $tipos = 'i';
    $parametros = [$idEmpresa];

    if ($somenteProprioProfissional) {
        $sql .= ' AND a.id_profissional = ? ';
        $tipos .= 'i';
        $parametros[] = (int)$idProfissionalSessao;
    }
    if ($dataInicio !== null) {
        $sql .= ' AND a.data_agendamento >= ? ';
        $tipos .= 's';
        $parametros[] = $dataInicio;
    }
    if ($dataFim !== null) {
        $sql .= ' AND a.data_agendamento <= ? ';
        $tipos .= 's';
        $parametros[] = $dataFim;
    }

    $sql .= ' ORDER BY a.data_agendamento ASC, a.hora_inicio ASC, a.id_agendamento ASC LIMIT 1000 ';
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar listagem da agenda: ' . $conexao->error);
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $dias = ['segunda'=>[], 'terca'=>[], 'quarta'=>[], 'quinta'=>[], 'sexta'=>[], 'sabado'=>[], 'domingo'=>[]];
    $mapaDias = [1=>'segunda', 2=>'terca', 3=>'quarta', 4=>'quinta', 5=>'sexta', 6=>'sabado', 7=>'domingo'];
    $total = 0;

    while ($row = $resultado->fetch_assoc()) {
        $dataAgendamento = (string)$row['data_agendamento'];
        $dia = $mapaDias[(int)(new DateTimeImmutable($dataAgendamento))->format('N')];
        $duracao = (int)$row['duracao_min_aplicada'];

        $dias[$dia][] = [
            'id' => (int)$row['id_agendamento'],
            'id_agendamento' => (int)$row['id_agendamento'],
            'id_cliente' => (int)$row['id_cliente'],
            'id_profissional' => (int)$row['id_profissional'],
            'id_servico' => (int)$row['id_servico'],
            'data' => $dataAgendamento,
            'data_agendamento' => $dataAgendamento,
            'hora' => (string)$row['hora_inicio'],
            'hora_inicio' => (string)$row['hora_inicio'],
            'hora_fim' => (string)$row['hora_fim'],
            'cliente' => (string)$row['cliente_nome'],
            'cliente_nome' => (string)$row['cliente_nome'],
            'telefone' => (string)($row['cliente_telefone'] ?? ''),
            'profissional' => (string)$row['profissional_nome'],
            'profissional_nome' => (string)$row['profissional_nome'],
            'especialidade' => (string)($row['profissional_especialidade'] ?? ''),
            'servico' => (string)$row['servico_nome'],
            'servico_nome' => (string)$row['servico_nome'],
            'duracao_min' => $duracao,
            'duracao' => $duracao . ' min',
            'valor_aplicado' => number_format((float)$row['valor_aplicado'], 2, '.', ''),
            'status' => (string)$row['status'],
            'obs' => (string)($row['observacao'] ?? ''),
            'observacao' => (string)($row['observacao'] ?? ''),
            'repetir_semanalmente' => (int)$row['repetir_semanalmente'],
            'recorrencia_data_fim' => $row['recorrencia_data_fim'],
            'grupo_recorrencia' => $row['grupo_recorrencia'],
            'criado_por' => $row['criado_por'] !== null ? (int)$row['criado_por'] : null,
            'criado_em' => (string)$row['criado_em'],
            'atualizado_em' => (string)$row['atualizado_em'],
            // Ainda não existe vínculo de pagamento na tabela agendamento.
            'pagamento_confirmado' => null,
        ];
        $total++;
    }
    $stmt->close();

    out(['ok' => true, 'code' => 'AGENDA_LISTED', 'data' => $dias, 'meta' => ['total' => $total]]);
} catch (Throwable $e) {
    error_log('[lista_agenda] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'INTERNAL_ERROR', 'user_msg' => 'Não foi possível carregar a agenda.'], 500);
}
