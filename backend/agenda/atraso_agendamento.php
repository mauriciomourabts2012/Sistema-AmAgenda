<?php
declare(strict_types=1);

// Mesmo fuso da edição e da validação de horários da Agenda.
date_default_timezone_set('America/Sao_Paulo');

/*
|--------------------------------------------------------------------------
| ATRASO DE AGENDAMENTO
|--------------------------------------------------------------------------
| GET  agenda/agendamento/atraso/previa  — SOMENTE LEITURA (Fase 5D.2).
| POST agenda/agendamento/atraso/aplicar — aplicação transacional (Fase 5D.3).
|
| Regra oficial da cascata: usa somente os horários reais gravados.
| novo_inicio = max(inicio_original, fim_cursor); vãos reais absorvem o atraso e
| intervalo_padrao_min NÃO é somado. Os novos horários não precisam cair na grade.
| A comunicação aos clientes afetados é da Fase 5D.4 (não é feita aqui).
|--------------------------------------------------------------------------
*/
if (!function_exists('out')) { function out(array $p, int $c = 200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; } }

const ATRASO_MINUTOS_MIN = 5;
const ATRASO_MINUTOS_MAX = 180;
const ATRASO_ULTIMO_MINUTO_DIA = 1439; // 23:59: o atendimento não pode passar para o dia seguinte
const ATRASO_IMPRESSAO_MAX_ITENS = 200;
const ATRASO_COLUNAS_AGENDAMENTO = "a.id_agendamento, a.id_cliente, a.id_profissional,
    DATE_FORMAT(a.data_agendamento, '%Y-%m-%d') AS data_agendamento,
    TIME_FORMAT(a.hora_inicio, '%H:%i:%s') AS hora_inicio, TIME_FORMAT(a.hora_fim, '%H:%i:%s') AS hora_fim,
    a.duracao_min_aplicada, a.status, a.grupo_recorrencia, a.atualizado_em";

/** Recusa controlada da aplicação: a transação é desfeita e o lock liberado pelo chamador. */
final class AtrasoRecusa extends RuntimeException
{
    public array $payload;
    public int $http;

    public function __construct(array $payload, int $http)
    {
        parent::__construct((string)($payload['code'] ?? 'DELAY_REFUSED'));
        $this->payload = $payload;
        $this->http = $http;
    }
}

function atrasoMinutos(string $hora): int
{
    [$h, $m] = array_map('intval', explode(':', substr($hora, 0, 5)) + [0, 0]);
    return ($h * 60) + $m;
}

function atrasoHora(int $minutos): string
{
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

function atrasoConsultar(mysqli $conexao, string $sql, string $tipos, array $parametros): array
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar a consulta do atraso.');
    if ($tipos !== '') $stmt->bind_param($tipos, ...$parametros);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao consultar dados do atraso.');
    }
    $resultado = $stmt->get_result();
    $linhas = [];
    while ($resultado && ($linha = $resultado->fetch_assoc())) $linhas[] = $linha;
    $stmt->close();
    return $linhas;
}

/**
 * Expediente efetivo do profissional na data, com a mesma regra da Agenda Online:
 * horário da empresa ou do profissional (usa_padrao_empresa); horario_especial em
 * excecao_agenda substitui o expediente (sem almoço); folga, feriado ou bloqueio
 * sem horário tornam o dia indisponível; bloqueio com horário vira janela bloqueada.
 */
function atrasoExpediente(mysqli $conexao, int $idEmpresa, int $idProfissional, DateTimeImmutable $data): array
{
    $dias = [1 => 'segunda', 2 => 'terca', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sabado', 7 => 'domingo'];
    $diaSemana = $dias[(int)$data->format('N')];
    $dataTexto = $data->format('Y-m-d');

    $origem = atrasoConsultar(
        $conexao,
        'SELECT usa_padrao_empresa FROM horario_profissional WHERE id_empresa = ? AND id_profissional = ? ORDER BY id_horario_profissional ASC LIMIT 1',
        'ii',
        [$idEmpresa, $idProfissional]
    );
    $usaPadraoEmpresa = isset($origem[0]) ? (int)$origem[0]['usa_padrao_empresa'] : 1;
    $colunas = "TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(hora_fim, '%H:%i') AS hora_fim,
                TIME_FORMAT(almoco_inicio, '%H:%i') AS almoco_inicio, TIME_FORMAT(almoco_fim, '%H:%i') AS almoco_fim,
                disponivel, status";
    $grade = $usaPadraoEmpresa === 0
        ? (atrasoConsultar($conexao, "SELECT $colunas FROM horario_profissional WHERE id_empresa = ? AND id_profissional = ? AND dia_semana = ? LIMIT 1", 'iis', [$idEmpresa, $idProfissional, $diaSemana])[0] ?? null)
        : (atrasoConsultar($conexao, "SELECT $colunas FROM horario_empresa WHERE id_empresa = ? AND dia_semana = ? LIMIT 1", 'is', [$idEmpresa, $diaSemana])[0] ?? null);

    $expediente = ['dia_indisponivel' => false, 'inicio' => null, 'fim' => null, 'almoco' => null, 'janelas_especiais' => [], 'bloqueios' => []];
    if ($grade !== null && (int)$grade['disponivel'] === 1 && (string)$grade['status'] === 'ativo' && $grade['hora_inicio'] && $grade['hora_fim']) {
        $expediente['inicio'] = atrasoMinutos((string)$grade['hora_inicio']);
        $expediente['fim'] = atrasoMinutos((string)$grade['hora_fim']);
        if ($grade['almoco_inicio'] && $grade['almoco_fim']
            && atrasoMinutos((string)$grade['almoco_inicio']) < atrasoMinutos((string)$grade['almoco_fim'])) {
            $expediente['almoco'] = ['inicio' => atrasoMinutos((string)$grade['almoco_inicio']), 'fim' => atrasoMinutos((string)$grade['almoco_fim'])];
        }
    }

    $excecoes = atrasoConsultar(
        $conexao,
        "SELECT tipo, TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(hora_fim, '%H:%i') AS hora_fim
           FROM excecao_agenda
          WHERE id_empresa = ? AND data_excecao = ? AND status = 'ativo'
            AND (id_profissional IS NULL OR id_profissional = ?)
          ORDER BY id_profissional IS NULL DESC, id_excecao_agenda ASC",
        'isi',
        [$idEmpresa, $dataTexto, $idProfissional]
    );
    $temHorarioEspecial = false;
    foreach ($excecoes as $excecao) {
        $tipo = (string)$excecao['tipo'];
        $intervaloValido = $excecao['hora_inicio'] !== null && $excecao['hora_fim'] !== null
            && atrasoMinutos((string)$excecao['hora_inicio']) < atrasoMinutos((string)$excecao['hora_fim']);
        $janela = $intervaloValido
            ? ['inicio' => atrasoMinutos((string)$excecao['hora_inicio']), 'fim' => atrasoMinutos((string)$excecao['hora_fim'])]
            : null;
        if ($tipo === 'folga' || $tipo === 'feriado') {
            $expediente['dia_indisponivel'] = true;
        } elseif ($tipo === 'bloqueio') {
            if ($janela === null) $expediente['dia_indisponivel'] = true;
            else $expediente['bloqueios'][] = $janela;
        } elseif ($tipo === 'horario_especial') {
            $temHorarioEspecial = true;
            if ($janela !== null) $expediente['janelas_especiais'][] = $janela;
        }
    }
    if ($temHorarioEspecial) {
        $expediente['almoco'] = null;
        if ($expediente['janelas_especiais'] === []) {
            $expediente['dia_indisponivel'] = true;
        } else {
            $expediente['inicio'] = min(array_column($expediente['janelas_especiais'], 'inicio'));
            $expediente['fim'] = max(array_column($expediente['janelas_especiais'], 'fim'));
        }
    }
    if ($expediente['inicio'] === null || $expediente['fim'] === null) $expediente['dia_indisponivel'] = true;
    return $expediente;
}

/** Avisos de um novo intervalo [inicio, fim) em relação ao expediente efetivo do dia. */
function atrasoAvisosIntervalo(array $expediente, int $inicio, int $fim): array
{
    $avisos = [];
    if ($expediente['dia_indisponivel']) {
        $avisos[] = 'dia_indisponivel';
    } elseif ($expediente['janelas_especiais'] !== []) {
        $dentro = false;
        foreach ($expediente['janelas_especiais'] as $janela) {
            if ($inicio >= $janela['inicio'] && $fim <= $janela['fim']) { $dentro = true; break; }
        }
        if (!$dentro) $avisos[] = 'fora_horario_especial';
    } elseif ($fim > (int)$expediente['fim']) {
        $avisos[] = 'ultrapassa_expediente';
    }
    $almoco = $expediente['almoco'];
    if ($almoco !== null && $inicio < $almoco['fim'] && $fim > $almoco['inicio']) $avisos[] = 'invade_almoco';
    foreach ($expediente['bloqueios'] as $bloqueio) {
        if ($inicio < $bloqueio['fim'] && $fim > $bloqueio['inicio']) { $avisos[] = 'invade_bloqueio'; break; }
    }
    return $avisos;
}

function atrasoImpressaoDigital(array $linha): array
{
    return [
        'id_agendamento' => (int)$linha['id_agendamento'],
        'hora_inicio' => (string)$linha['hora_inicio'],
        'hora_fim' => (string)$linha['hora_fim'],
        'status' => (string)$linha['status'],
        'duracao_min_aplicada' => (int)$linha['duracao_min_aplicada'],
        'atualizado_em' => (string)$linha['atualizado_em'],
    ];
}

/** Valida a origem já lida do banco (empresa da sessão). Retorna [payload, http] da recusa ou null. */
function atrasoValidarOrigem(?array $origem, array $contexto, DateTimeImmutable $agora, int $atraso): ?array
{
    // Profissional só enxerga a própria agenda: fora do escopo responde como inexistente.
    $foraDoEscopo = $origem !== null && !($contexto['super_admin_suporte'] ?? false)
        && ($contexto['perfil'] ?? '') === 'profissional' && (int)($contexto['id_profissional'] ?? 0) !== (int)$origem['id_profissional'];
    if ($origem === null || $foraDoEscopo) return [['ok'=>false,'code'=>'APPOINTMENT_NOT_FOUND','user_msg'=>'Agendamento não encontrado.'], 404];
    if ((string)$origem['status'] !== 'confirmado') return [['ok'=>false,'code'=>'ORIGIN_NOT_CONFIRMED','user_msg'=>'O atraso só pode ser informado para um agendamento confirmado.'], 422];
    if ((string)$origem['data_agendamento'] !== $agora->format('Y-m-d')) return [['ok'=>false,'code'=>'ORIGIN_NOT_TODAY','user_msg'=>'O atraso só pode ser informado para agendamentos de hoje.'], 422];
    $minutoAgora = ((int)$agora->format('H') * 60) + (int)$agora->format('i');
    $inicioOrigem = atrasoMinutos((string)$origem['hora_inicio']);
    // A origem pode já estar atrasada, mas o novo início precisa ser agora ou depois.
    if ($inicioOrigem + $atraso < $minutoAgora) {
        return [['ok'=>false,'code'=>'DELAY_TOO_SHORT','user_msg'=>'Com esse atraso o novo horário ficaria no passado. Informe um atraso maior.','data'=>['atraso_minimo'=>$minutoAgora - $inicioOrigem]], 422];
    }
    return null;
}

// ============================================================
// AGENDA — CASCATA DO ATRASO (mesma regra na prévia e na aplicação)
// ============================================================
// $proximos: pendentes/confirmados do mesmo profissional/dia depois da origem, ordenados por
// hora_inicio e id. A cascata para no primeiro agendamento cujo início original já absorve o
// atraso. Já iniciado (exceto a origem) é fixo e torna o cenário inválido se for alcançado.
function atrasoCalcularCascata(array $origem, array $proximos, int $atraso, int $minutoAgora, array $expediente, array $nomesClientes): array
{
    $afetados = [];
    $avisos = [];
    $bloqueios = [];
    $impressaoDigital = [atrasoImpressaoDigital($origem)];
    $registrar = static function (array $linha, int $novoInicio, bool $ehOrigem) use (&$afetados, &$avisos, &$bloqueios, $expediente, $nomesClientes): int {
        $inicioOriginal = atrasoMinutos((string)$linha['hora_inicio']);
        $duracao = (int)$linha['duracao_min_aplicada'];
        if ($duracao <= 0) $duracao = max(0, atrasoMinutos((string)$linha['hora_fim']) - $inicioOriginal);
        $novoFim = $novoInicio + $duracao;
        $idAgendamento = (int)$linha['id_agendamento'];
        $afetados[] = [
            'id_agendamento' => $idAgendamento,
            'id_cliente' => (int)$linha['id_cliente'],
            'id_profissional' => (int)$linha['id_profissional'],
            'origem' => $ehOrigem,
            'status' => (string)$linha['status'],
            'cliente_nome' => (string)($nomesClientes[$idAgendamento] ?? ''),
            'recorrente' => trim((string)($linha['grupo_recorrencia'] ?? '')) !== '',
            'hora_original' => substr((string)$linha['hora_inicio'], 0, 5),
            'hora_fim_original' => substr((string)$linha['hora_fim'], 0, 5),
            'nova_hora' => atrasoHora(min($novoInicio, ATRASO_ULTIMO_MINUTO_DIA)),
            'nova_hora_fim' => $novoFim > ATRASO_ULTIMO_MINUTO_DIA ? null : atrasoHora($novoFim),
            'deslocamento_min' => $novoInicio - $inicioOriginal,
        ];
        if ($novoFim > ATRASO_ULTIMO_MINUTO_DIA) {
            $bloqueios[] = ['tipo' => 'ultrapassa_meia_noite', 'id_agendamento' => $idAgendamento];
        } else {
            foreach (atrasoAvisosIntervalo($expediente, $novoInicio, $novoFim) as $tipo) {
                $avisos[] = ['tipo' => $tipo, 'id_agendamento' => $idAgendamento];
            }
        }
        return $novoFim;
    };

    $fimCursor = $registrar($origem, atrasoMinutos((string)$origem['hora_inicio']) + $atraso, true);
    foreach ($proximos as $proximo) {
        $impressaoDigital[] = atrasoImpressaoDigital($proximo);
        $inicioOriginal = atrasoMinutos((string)$proximo['hora_inicio']);
        if ($inicioOriginal >= $fimCursor) break; // vão real absorve o restante: fim da cascata
        if ($inicioOriginal <= $minutoAgora) {
            $bloqueios[] = ['tipo' => 'agendamento_ja_iniciado', 'id_agendamento' => (int)$proximo['id_agendamento']];
            break;
        }
        $fimCursor = $registrar($proximo, $fimCursor, false);
        if ($fimCursor > ATRASO_ULTIMO_MINUTO_DIA) break;
    }
    return ['afetados' => $afetados, 'avisos' => $avisos, 'bloqueios' => $bloqueios, 'impressao_digital' => $impressaoDigital];
}

function atrasoResumoExpediente(array $expediente): array
{
    return [
        'hora_inicio' => $expediente['inicio'] === null ? null : atrasoHora($expediente['inicio']),
        'hora_fim' => $expediente['fim'] === null ? null : atrasoHora($expediente['fim']),
        'almoco_inicio' => $expediente['almoco'] === null ? null : atrasoHora($expediente['almoco']['inicio']),
        'almoco_fim' => $expediente['almoco'] === null ? null : atrasoHora($expediente['almoco']['fim']),
        'horario_especial' => $expediente['janelas_especiais'] !== [],
        'dia_indisponivel' => $expediente['dia_indisponivel'],
    ];
}

/** Resultado público dos helpers pós-commit, sem expor detalhes internos de falha. */
function atrasoStatusComunicacao(array $resultado): array
{
    $resultadoBruto = (string)($resultado['resultado'] ?? 'erro');
    return match ($resultadoBruto) {
        'gravada' => ['status' => 'enviado'],
        'idempotente' => ['status' => 'ja_existia'],
        default => ['status' => 'falhou'],
    };
}

/** Nomes dos clientes do profissional/dia, indexados pelo agendamento (somente exibição). */
function atrasoNomesClientes(mysqli $conexao, int $idEmpresa, int $idProfissional, string $data): array
{
    $nomes = [];
    $linhas = atrasoConsultar(
        $conexao,
        'SELECT a.id_agendamento, c.nome_completo
           FROM agendamento a
          INNER JOIN cliente c ON c.id_cliente = a.id_cliente AND c.id_empresa = a.id_empresa
          WHERE a.id_empresa = ? AND a.id_profissional = ? AND a.data_agendamento = ?',
        'iis',
        [$idEmpresa, $idProfissional, $data]
    );
    foreach ($linhas as $linha) $nomes[(int)$linha['id_agendamento']] = (string)$linha['nome_completo'];
    return $nomes;
}

/** Impressão digital recebida da prévia: somente para detectar estado obsoleto, nunca como fonte. */
function atrasoLerImpressaoDigital(mixed $bruto): ?array
{
    if (!is_string($bruto) || $bruto === '' || strlen($bruto) > 60000) return null;
    try { $itens = json_decode($bruto, true, 4, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    if (!is_array($itens) || $itens === [] || array_keys($itens) !== range(0, count($itens) - 1) || count($itens) > ATRASO_IMPRESSAO_MAX_ITENS) return null;
    $normalizados = [];
    foreach ($itens as $item) {
        if (!is_array($item)) return null;
        foreach (['id_agendamento', 'hora_inicio', 'hora_fim', 'status', 'duracao_min_aplicada', 'atualizado_em'] as $campo) {
            if (!array_key_exists($campo, $item) || !is_scalar($item[$campo])) return null;
        }
        $normalizados[] = atrasoImpressaoDigital($item);
    }
    return $normalizados;
}

/**
 * Aplica o atraso. Chamador: GET_LOCK já obtido e transação aberta. Relê e trava (FOR UPDATE,
 * ordem estável por id) todos os agendamentos do profissional/dia, recalcula a cascata, compara
 * com a impressão digital da prévia e grava SOMENTE hora_inicio/hora_fim dos deslocados.
 * Lança AtrasoRecusa para qualquer recusa (o chamador desfaz a transação).
 */
function atrasoAplicar(mysqli $conexao, int $idEmpresa, int $idUsuario, array $contexto, int $idOrigem, int $idProfissional, string $data, int $atraso, array $impressaoRecebida): array
{
    $obsoleta = ['ok'=>false,'code'=>'DELAY_PREVIEW_OUTDATED','user_msg'=>'A agenda foi alterada desde a prévia. Atualize o cálculo antes de confirmar o atraso.'];

    // Ordem de lock única (id_agendamento). O intervalo empresa+profissional+data travado também
    // impede que um novo agendamento entre nesse dia durante a aplicação.
    $linhas = atrasoConsultar(
        $conexao,
        'SELECT ' . ATRASO_COLUNAS_AGENDAMENTO . '
           FROM agendamento a
          WHERE a.id_empresa = ? AND a.id_profissional = ? AND a.data_agendamento = ?
          ORDER BY a.id_agendamento ASC
          FOR UPDATE',
        'iis',
        [$idEmpresa, $idProfissional, $data]
    );
    $origem = null;
    foreach ($linhas as $linha) {
        if ((int)$linha['id_agendamento'] === $idOrigem) { $origem = $linha; break; }
    }
    // Origem movida para outro profissional/dia (ou removida) desde a leitura inicial.
    if ($origem === null) throw new AtrasoRecusa($obsoleta, 409);

    $agora = new DateTimeImmutable('now');
    $recusa = atrasoValidarOrigem($origem, $contexto, $agora, $atraso);
    if ($recusa !== null) throw new AtrasoRecusa($recusa[0], $recusa[1]);

    $inicioOrigem = (string)$origem['hora_inicio'];
    $proximos = array_values(array_filter($linhas, static fn (array $l): bool =>
        in_array((string)$l['status'], ['pendente', 'confirmado'], true)
        && (int)$l['id_agendamento'] !== $idOrigem
        && ((string)$l['hora_inicio'] > $inicioOrigem || ((string)$l['hora_inicio'] === $inicioOrigem && (int)$l['id_agendamento'] > $idOrigem))));
    usort($proximos, static fn (array $a, array $b): int =>
        [(string)$a['hora_inicio'], (int)$a['id_agendamento']] <=> [(string)$b['hora_inicio'], (int)$b['id_agendamento']]);

    $minutoAgora = ((int)$agora->format('H') * 60) + (int)$agora->format('i');
    $expediente = atrasoExpediente($conexao, $idEmpresa, $idProfissional, DateTimeImmutable::createFromFormat('!Y-m-d', $data));
    $nomes = atrasoNomesClientes($conexao, $idEmpresa, $idProfissional, $data);
    $calculo = atrasoCalcularCascata($origem, $proximos, $atraso, $minutoAgora, $expediente, $nomes);

    // Qualquer diferença no estado examinado (horário, status, duração, atualizado_em, registro
    // novo ou removido na janela) torna a prévia obsoleta: nunca adapta silenciosamente.
    if ($calculo['impressao_digital'] !== $impressaoRecebida) throw new AtrasoRecusa($obsoleta, 409);
    if ($calculo['bloqueios'] !== []) {
        throw new AtrasoRecusa(['ok'=>false,'code'=>'DELAY_BLOCKED','user_msg'=>'Não é possível aplicar este atraso. Atualize o cálculo para ver o motivo.','data'=>['bloqueios'=>$calculo['bloqueios']]], 409);
    }

    $porId = [];
    foreach ($linhas as $linha) $porId[(int)$linha['id_agendamento']] = $linha;
    $deslocados = array_values(array_filter($calculo['afetados'], static fn (array $a): bool => (int)$a['deslocamento_min'] > 0));
    if ($deslocados === []) throw new AtrasoRecusa($obsoleta, 409);

    $stmt = $conexao->prepare('UPDATE agendamento SET hora_inicio = ?, hora_fim = ?
                                WHERE id_agendamento = ? AND id_empresa = ? AND hora_inicio = ? AND hora_fim = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Falha ao preparar a aplicação do atraso.');
    try {
        foreach ($deslocados as $afetado) {
            $linha = $porId[(int)$afetado['id_agendamento']];
            $novaHora = $afetado['nova_hora'] . ':00';
            $novaHoraFim = $afetado['nova_hora_fim'] . ':00';
            $idAgendamento = (int)$afetado['id_agendamento'];
            $horaAntiga = (string)$linha['hora_inicio'];
            $horaFimAntiga = (string)$linha['hora_fim'];
            $stmt->bind_param('ssiiss', $novaHora, $novaHoraFim, $idAgendamento, $idEmpresa, $horaAntiga, $horaFimAntiga);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new AtrasoRecusa($obsoleta, 409);
        }
    } finally {
        $stmt->close();
    }

    // Conferência final: nenhum deslocado pode sobrepor outro agendamento ativo do dia.
    $ids = array_map(static fn (array $a): int => (int)$a['id_agendamento'], $deslocados);
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $conflito = atrasoConsultar(
        $conexao,
        "SELECT 1
           FROM agendamento a
          INNER JOIN agendamento b
                  ON b.id_empresa = a.id_empresa AND b.id_profissional = a.id_profissional
                 AND b.data_agendamento = a.data_agendamento AND b.id_agendamento <> a.id_agendamento
                 AND b.status IN ('pendente', 'confirmado')
                 AND a.hora_inicio < b.hora_fim AND a.hora_fim > b.hora_inicio
          WHERE a.id_empresa = ? AND a.id_profissional = ? AND a.data_agendamento = ?
            AND a.status IN ('pendente', 'confirmado')
            AND a.id_agendamento IN ($marcadores)
          LIMIT 1",
        'iis' . str_repeat('i', count($ids)),
        array_merge([$idEmpresa, $idProfissional, $data], $ids)
    );
    if ($conflito !== []) throw new AtrasoRecusa($obsoleta, 409);

    foreach ($deslocados as $afetado) {
        $idAgendamento = (int)$afetado['id_agendamento'];
        $linha = $porId[$idAgendamento];
        // Chat: somente o snapshot data/hora da conversa vinculada (sem mensagem — Fase 5D.4).
        chatPrepararConversaParaEdicaoAgendamento(
            $conexao, $idEmpresa, $idAgendamento, (int)$linha['id_cliente'], (int)$linha['id_profissional'],
            $data, $afetado['nova_hora'] . ':00', $idUsuario
        );
        // Pendente deslocado: a notificação existente passa a refletir o novo horário.
        if ((string)$linha['status'] === 'pendente') notificacaoReconciliarAgendamentoPendente($conexao, $idEmpresa, $idAgendamento);
        // Confirmado deslocado: mantém ou cancela a pendência de finalização conforme o novo término.
        notificacaoReconciliarAgendamentoFinalizacao($conexao, $idEmpresa, $idAgendamento);

        $clienteNome = (string)$afetado['cliente_nome'];
        auditoriaRegistrar($conexao, 'agendamento.atraso_informado', [
            'entidade_id' => $idAgendamento,
            'entidade_rotulo' => $clienteNome,
            'descricao' => 'Informou atraso de ' . $atraso . ' min: agendamento de ' . ($clienteNome !== '' ? $clienteNome : 'cliente')
                . ' passou de ' . $afetado['hora_original'] . ' para ' . $afetado['nova_hora'] . '.',
            'alteracoes' => [
                'hora_inicio' => ['antes' => (string)$linha['hora_inicio'], 'depois' => $afetado['nova_hora'] . ':00'],
                'hora_fim' => ['antes' => (string)$linha['hora_fim'], 'depois' => $afetado['nova_hora_fim'] . ':00'],
                'atraso' => ['antes' => null, 'depois' => [
                    'minutos' => $atraso,
                    'id_agendamento_origem' => $idOrigem,
                    'id_profissional' => $idProfissional,
                    'deslocamento_min' => (int)$afetado['deslocamento_min'],
                    'agendamentos_afetados' => $ids,
                ]],
            ],
            'contexto' => [
                'origem' => 'agenda_atraso',
                'escopo' => 'ocorrencia_unica',
                'quantidade_afetada' => count($ids),
                'data_referencia' => $data,
                'grupo_recorrencia' => $linha['grupo_recorrencia'] ?? null,
            ],
        ]);
    }

    return [
        'atraso_min' => $atraso,
        'data_agendamento' => $data,
        'id_profissional' => $idProfissional,
        'id_agendamento_origem' => $idOrigem,
        'total_afetados' => count($deslocados),
        'afetados' => $deslocados,
        'avisos' => $calculo['avisos'],
        'expediente' => atrasoResumoExpediente($expediente),
        'aplicado_em' => $agora->format('Y-m-d H:i:s'),
    ];
}

$conexao = null;
$nomeLock = null;
$transacao = false;

try {
    $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
    if ($metodo !== 'GET' && $metodo !== 'POST') out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','user_msg'=>'Método não permitido.'],405);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth = $_SESSION['auth'] ?? [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $idEmpresa = (int)($auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? $_SESSION['id_empresa'] ?? $_SESSION['empresa']['id_empresa'] ?? 0);
    if ($idUsuario <= 0) out(['ok'=>false,'code'=>'NOT_AUTHENTICATED','user_msg'=>'Sessão expirada. Faça login novamente.'],401);
    if ($idEmpresa <= 0) out(['ok'=>false,'code'=>'SESSION_WITHOUT_COMPANY','user_msg'=>'Não foi possível identificar a empresa da sessão.'],403);
    if ($metodo === 'POST') {
        // Escrita autenticada: exige o token CSRF da sessão (mecanismo existente).
        require_once __DIR__ . '/../_auth/csrf.php';
        csrfValidarSessao();
    }

    // Somente id da origem e minutos vêm do navegador; profissional, data e horários vêm do banco.
    $tipoEntrada = $metodo === 'POST' ? INPUT_POST : INPUT_GET;
    $id = filter_input($tipoEntrada, 'id_agendamento', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $atraso = filter_input($tipoEntrada, 'minutos_atraso', FILTER_VALIDATE_INT, ['options' => ['min_range' => ATRASO_MINUTOS_MIN, 'max_range' => ATRASO_MINUTOS_MAX]]);
    $impressaoRecebida = $metodo === 'POST' ? atrasoLerImpressaoDigital($_POST['impressao_digital'] ?? null) : [];
    $erros = [];
    if ($id <= 0) $erros['id_agendamento'] = 'Agendamento inválido.';
    if (!is_int($atraso)) $erros['minutos_atraso'] = 'Informe um atraso entre ' . ATRASO_MINUTOS_MIN . ' e ' . ATRASO_MINUTOS_MAX . ' minutos.';
    if ($impressaoRecebida === null) $erros['impressao_digital'] = 'Atualize o cálculo do atraso antes de confirmar.';
    if ($erros) out(['ok'=>false,'code'=>'VALIDATION_ERROR','user_msg'=>'Revise os dados do atraso.','fields'=>$erros],422);

    require __DIR__ . '/../_config/conexao.php';
    require_once __DIR__ . '/../_regras/permissoes_usuario.php';
    $conexao->set_charset('utf8mb4');
    $contexto = permissoesContexto($conexao);
    if (!($contexto['valido'] ?? false)) out(['ok'=>false,'code'=>'COMPANY_ACCESS_DENIED','user_msg'=>'Acesso à empresa não autorizado.'],403);

    $origem = atrasoConsultar(
        $conexao,
        'SELECT ' . ATRASO_COLUNAS_AGENDAMENTO . ' FROM agendamento a WHERE a.id_agendamento = ? AND a.id_empresa = ? LIMIT 1',
        'ii',
        [$id, $idEmpresa]
    )[0] ?? null;
    $agora = new DateTimeImmutable('now');
    $recusa = atrasoValidarOrigem($origem, $contexto, $agora, $atraso);
    if ($recusa !== null) out($recusa[0], $recusa[1]);
    $idProfissional = (int)$origem['id_profissional'];
    $dataTexto = (string)$origem['data_agendamento'];

    if ($metodo === 'GET') {
        // ------------------------------------------------------------
        // PRÉVIA (somente leitura): nada é gravado nem travado.
        // ------------------------------------------------------------
        $expediente = atrasoExpediente($conexao, $idEmpresa, $idProfissional, DateTimeImmutable::createFromFormat('!Y-m-d', $dataTexto));
        $proximos = atrasoConsultar(
            $conexao,
            'SELECT ' . ATRASO_COLUNAS_AGENDAMENTO . "
               FROM agendamento a
              WHERE a.id_empresa = ? AND a.id_profissional = ? AND a.data_agendamento = ?
                AND a.status IN ('pendente', 'confirmado')
                AND a.id_agendamento <> ?
                AND (a.hora_inicio > ? OR (a.hora_inicio = ? AND a.id_agendamento > ?))
              ORDER BY a.hora_inicio ASC, a.id_agendamento ASC",
            'iisissi',
            [$idEmpresa, $idProfissional, $dataTexto, $id, (string)$origem['hora_inicio'], (string)$origem['hora_inicio'], $id]
        );
        $minutoAgora = ((int)$agora->format('H') * 60) + (int)$agora->format('i');
        $calculo = atrasoCalcularCascata($origem, $proximos, $atraso, $minutoAgora, $expediente, atrasoNomesClientes($conexao, $idEmpresa, $idProfissional, $dataTexto));
        out(['ok'=>true,'code'=>'DELAY_PREVIEW','data'=>[
            'valida' => $calculo['bloqueios'] === [],
            'atraso_min' => $atraso,
            'data_agendamento' => $dataTexto,
            'id_profissional' => $idProfissional,
            'id_agendamento_origem' => $id,
            'total_afetados' => count($calculo['afetados']),
            'afetados' => $calculo['afetados'],
            'avisos' => $calculo['avisos'],
            'bloqueios' => $calculo['bloqueios'],
            'expediente' => atrasoResumoExpediente($expediente),
            // Estado lido agora (origem + agendamentos examinados); reenviado na confirmação.
            'impressao_digital' => $calculo['impressao_digital'],
            'calculado_em' => $agora->format('Y-m-d H:i:s'),
        ]]);
    }

    // ------------------------------------------------------------
    // APLICAÇÃO (Fase 5D.3)
    // ------------------------------------------------------------
    require_once __DIR__ . '/../_servicos/auditoria.php';
    require_once __DIR__ . '/../_servicos/chat.php';
    require_once __DIR__ . '/../_servicos/notificacao.php';

    // Mesmo lock (namespace e formato) da Agenda Online em cliente_agendamento/confirmar-agendamento.php:
    // serializa o atraso com reservas de clientes para a mesma empresa + profissional + data.
    $nomeLockSolicitado = sprintf('amagenda_cliente_%d_%d_%s', $idEmpresa, $idProfissional, $dataTexto);
    $lock = atrasoConsultar($conexao, 'SELECT GET_LOCK(?, 5) AS obtido', 's', [$nomeLockSolicitado]);
    if ((int)($lock[0]['obtido'] ?? 0) !== 1) {
        out(['ok'=>false,'code'=>'SCHEDULE_BUSY','user_msg'=>'A agenda deste profissional está sendo alterada agora. Tente novamente em instantes.'],409);
    }
    $nomeLock = $nomeLockSolicitado;

    $resposta = [];
    $httpResposta = 200;
    try {
        $conexao->begin_transaction();
        $transacao = true;
        $dados = atrasoAplicar($conexao, $idEmpresa, $idUsuario, $contexto, $id, $idProfissional, $dataTexto, $atraso, $impressaoRecebida);
        $conexao->commit();
        $transacao = false;
        $resposta = ['ok'=>true,'code'=>'DELAY_APPLIED','user_msg'=>'Atraso aplicado com sucesso.','data'=>$dados];
    } catch (AtrasoRecusa $recusaAplicacao) {
        $resposta = $recusaAplicacao->payload;
        $httpResposta = $recusaAplicacao->http;
    } finally {
        if ($transacao) {
            try { $conexao->rollback(); } catch (Throwable $ignorado) {}
            $transacao = false;
        }
        try { atrasoConsultar($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', [$nomeLock]); } catch (Throwable $ignorado) {}
        $nomeLock = null;
    }
    // ============================================================
    // COMUNICAÇÃO DO ATRASO — CHAT + CENTRAL DO CLIENTE (Fases 5D.4A/5D.4B)
    // ============================================================
    // Somente após o COMMIT (e já sem o lock da agenda): cada canal é independente e nunca
    // altera o sucesso da agenda. A lista de afetados e o deslocamento são os já calculados
    // autoritativamente pela aplicação; cada helper relê sua condição atual antes de gravar.
    if (($resposta['code'] ?? '') === 'DELAY_APPLIED') {
        $clientes = [];
        $sucessoCompleto = 0;
        foreach ($resposta['data']['afetados'] as $afetado) {
            if ((int)$afetado['deslocamento_min'] <= 0) continue;

            try {
                $resultadoChat = chatRegistrarEventoAgendamentoAtraso(
                    $conexao,
                    $idEmpresa,
                    (int)$afetado['id_agendamento'],
                    $idUsuario,
                    (string)$afetado['nova_hora'],
                    (int)$afetado['deslocamento_min']
                );
            } catch (Throwable $erroChat) {
                error_log('[atraso_agendamento] chat evento=agendamento_atraso empresa=' . $idEmpresa . ' agendamento=' . (int)$afetado['id_agendamento'] . ' erro=' . get_class($erroChat));
                $resultadoChat = ['resultado' => 'erro'];
            }
            try {
                $resultadoNotificacao = notificacaoRegistrarEventoAgendamentoAtraso(
                    $conexao,
                    $idEmpresa,
                    (int)$afetado['id_agendamento'],
                    (string)$afetado['nova_hora'],
                    (int)$afetado['deslocamento_min']
                );
            } catch (Throwable $erroNotificacao) {
                error_log('[atraso_agendamento] notificacao evento=agendamento_atraso empresa=' . $idEmpresa . ' agendamento=' . (int)$afetado['id_agendamento'] . ' erro=' . get_class($erroNotificacao));
                $resultadoNotificacao = ['resultado' => 'erro'];
            }

            $chat = atrasoStatusComunicacao($resultadoChat);
            $notificacao = atrasoStatusComunicacao($resultadoNotificacao);
            $completo = $chat['status'] !== 'falhou' && $notificacao['status'] !== 'falhou';
            if ($completo) $sucessoCompleto++;
            $clientes[] = [
                'id_agendamento' => (int)$afetado['id_agendamento'],
                'id_cliente' => (int)$afetado['id_cliente'],
                'cliente_nome' => (string)$afetado['cliente_nome'],
                'chat' => $chat,
                'notificacao' => $notificacao,
            ];
        }
        $resposta['data']['comunicacao'] = [
            'total_clientes' => count($clientes),
            'sucesso_completo' => $sucessoCompleto,
            'com_pendencia' => count($clientes) - $sucessoCompleto,
            'clientes' => $clientes,
        ];
    }
    out($resposta, $httpResposta);
} catch (Throwable $e) {
    if ($conexao instanceof mysqli) {
        if ($transacao) { try { $conexao->rollback(); } catch (Throwable $ignorado) {} }
        if ($nomeLock !== null) { try { atrasoConsultar($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', [$nomeLock]); } catch (Throwable $ignorado) {} }
    }
    error_log('[atraso_agendamento] ' . $e->getMessage());
    out(['ok'=>false,'code'=>'INTERNAL_ERROR','user_msg'=>'Não foi possível processar o atraso agora.'],500);
}
