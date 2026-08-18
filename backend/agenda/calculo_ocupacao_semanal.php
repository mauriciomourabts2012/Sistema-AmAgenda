<?php
declare(strict_types=1);

function ocupacaoHoraParaMinutos(?string $hora): ?int
{
    if ($hora === null || !preg_match('/^(\d{2}):(\d{2})/', $hora, $m)) return null;
    $h = (int)$m[1];
    $min = (int)$m[2];
    return $h <= 23 && $min <= 59 ? ($h * 60) + $min : null;
}

function ocupacaoCapacidadeDia(array $horario): int
{
    if ((int)($horario['disponivel'] ?? 0) !== 1 || ($horario['status'] ?? '') !== 'ativo') return 0;
    $inicio = ocupacaoHoraParaMinutos($horario['hora_inicio'] ?? null);
    $fim = ocupacaoHoraParaMinutos($horario['hora_fim'] ?? null);
    if ($inicio === null || $fim === null || $fim <= $inicio) return 0;

    $capacidade = $fim - $inicio;
    $almocoInicio = ocupacaoHoraParaMinutos($horario['almoco_inicio'] ?? null);
    $almocoFim = ocupacaoHoraParaMinutos($horario['almoco_fim'] ?? null);
    if ($almocoInicio !== null && $almocoFim !== null && $almocoFim > $almocoInicio) {
        $sobreposicaoInicio = max($inicio, $almocoInicio);
        $sobreposicaoFim = min($fim, $almocoFim);
        if ($sobreposicaoFim > $sobreposicaoInicio) $capacidade -= $sobreposicaoFim - $sobreposicaoInicio;
    }
    return max(0, $capacidade);
}

function ocupacaoMesclarIntervalos(array $intervalos): int
{
    if (!$intervalos) return 0;
    usort($intervalos, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    $total = 0;
    [$inicioAtual, $fimAtual] = array_shift($intervalos);
    foreach ($intervalos as [$inicio, $fim]) {
        if ($inicio <= $fimAtual) {
            $fimAtual = max($fimAtual, $fim);
            continue;
        }
        $total += max(0, $fimAtual - $inicioAtual);
        [$inicioAtual, $fimAtual] = [$inicio, $fim];
    }
    return $total + max(0, $fimAtual - $inicioAtual);
}

function calcularOcupacaoSemanal(mysqli $conexao, int $idEmpresa, array $idsProfissionais, string $inicioSemana, string $fimSemana): array
{
    $idsProfissionais = array_values(array_unique(array_filter(array_map('intval', $idsProfissionais), static fn(int $id): bool => $id > 0)));
    $base = [
        'percentual' => null,
        'minutos_disponiveis' => 0,
        'minutos_ocupados' => 0,
        'inicio_semana' => $inicioSemana,
        'fim_semana' => $fimSemana,
        'motivo_indisponivel' => null,
        'capacidade_excedida' => false,
    ];
    if (!$idsProfissionais) {
        $base['motivo_indisponivel'] = 'nenhum_profissional_ativo';
        return $base;
    }

    $horariosEmpresa = [];
    $stmt = $conexao->prepare("SELECT dia_semana,hora_inicio,hora_fim,almoco_inicio,almoco_fim,disponivel,status FROM horario_empresa WHERE id_empresa=?");
    if (!$stmt) throw new RuntimeException('Falha ao consultar capacidade da empresa: '.$conexao->error);
    $stmt->bind_param('i', $idEmpresa);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $horariosEmpresa[(string)$row['dia_semana']] = $row;
    $stmt->close();

    $marcadores = implode(',', array_fill(0, count($idsProfissionais), '?'));
    $tiposIds = str_repeat('i', count($idsProfissionais));
    $horariosProfissionais = [];
    $usaPadrao = [];
    $stmt = $conexao->prepare("SELECT id_profissional,dia_semana,hora_inicio,hora_fim,almoco_inicio,almoco_fim,disponivel,status,usa_padrao_empresa FROM horario_profissional WHERE id_empresa=? AND id_profissional IN ($marcadores) ORDER BY id_profissional,id_horario_profissional");
    if (!$stmt) throw new RuntimeException('Falha ao consultar capacidade profissional: '.$conexao->error);
    $tipos = 'i'.$tiposIds;
    $params = array_merge([$idEmpresa], $idsProfissionais);
    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['id_profissional'];
        if (!array_key_exists($id, $usaPadrao)) $usaPadrao[$id] = (int)$row['usa_padrao_empresa'];
        $horariosProfissionais[$id][(string)$row['dia_semana']] = $row;
    }
    $stmt->close();

    $minutosDisponiveis = 0;
    foreach ($idsProfissionais as $idProfissional) {
        $grade = (($usaPadrao[$idProfissional] ?? 1) === 0)
            ? ($horariosProfissionais[$idProfissional] ?? [])
            : $horariosEmpresa;
        foreach ($grade as $horario) $minutosDisponiveis += ocupacaoCapacidadeDia($horario);
    }

    if ($minutosDisponiveis <= 0) {
        $base['motivo_indisponivel'] = 'horarios_nao_configurados';
        return $base;
    }

    $intervalosPorProfissionalDia = [];
    $stmt = $conexao->prepare("SELECT id_agendamento,id_profissional,data_agendamento,hora_inicio,hora_fim FROM agendamento WHERE id_empresa=? AND id_profissional IN ($marcadores) AND data_agendamento BETWEEN ? AND ? AND status IN ('pendente','confirmado','concluido','faltou') ORDER BY id_profissional,data_agendamento,hora_inicio,id_agendamento");
    if (!$stmt) throw new RuntimeException('Falha ao consultar minutos ocupados: '.$conexao->error);
    $tipos = 'i'.$tiposIds.'ss';
    $params = array_merge([$idEmpresa], $idsProfissionais, [$inicioSemana, $fimSemana]);
    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $inicio = ocupacaoHoraParaMinutos((string)$row['hora_inicio']);
        $fim = ocupacaoHoraParaMinutos((string)$row['hora_fim']);
        if ($inicio === null || $fim === null || $fim <= $inicio) continue;
        $chave = (int)$row['id_profissional'].'|'.(string)$row['data_agendamento'];
        $intervalosPorProfissionalDia[$chave][] = [$inicio, $fim];
    }
    $stmt->close();

    $minutosOcupados = 0;
    foreach ($intervalosPorProfissionalDia as $intervalos) $minutosOcupados += ocupacaoMesclarIntervalos($intervalos);
    $percentual = (int)round(($minutosOcupados / $minutosDisponiveis) * 100);

    return [
        'percentual' => $percentual,
        'minutos_disponiveis' => $minutosDisponiveis,
        'minutos_ocupados' => $minutosOcupados,
        'inicio_semana' => $inicioSemana,
        'fim_semana' => $fimSemana,
        'motivo_indisponivel' => null,
        'capacidade_excedida' => $minutosOcupados > $minutosDisponiveis,
    ];
}
