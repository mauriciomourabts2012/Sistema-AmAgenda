<?php
declare(strict_types=1);

/*
| Validação autoritativa de um horário antes de cadastrar ou reagendar.
| Retorna null quando o horário é válido ou uma mensagem segura ao usuário.
*/
function validarHorarioAgendamento(
    mysqli $conexao,
    int $idEmpresa,
    int $idProfissional,
    DateTimeImmutable $data,
    string $horaInicio,
    int $duracaoMin
): ?string {
    $dias = [1=>'segunda',2=>'terca',3=>'quarta',4=>'quinta',5=>'sexta',6=>'sabado',7=>'domingo'];
    $diaSemana = $dias[(int)$data->format('N')];

    $usaPadrao = 1;
    $stmt = $conexao->prepare("SELECT usa_padrao_empresa FROM horario_profissional WHERE id_empresa=? AND id_profissional=? ORDER BY id_horario_profissional ASC LIMIT 1");
    if (!$stmt) throw new RuntimeException('Falha ao validar origem do horário: '.$conexao->error);
    $stmt->bind_param('ii',$idEmpresa,$idProfissional); $stmt->execute(); $stmt->bind_result($usaPadraoDb);
    if ($stmt->fetch()) $usaPadrao=(int)$usaPadraoDb; $stmt->close();

    if ($usaPadrao===0) {
        $stmt=$conexao->prepare("SELECT hora_inicio,hora_fim,almoco_inicio,almoco_fim,disponivel,status FROM horario_profissional WHERE id_empresa=? AND id_profissional=? AND dia_semana=? LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao validar horário profissional: '.$conexao->error);
        $stmt->bind_param('iis',$idEmpresa,$idProfissional,$diaSemana);
    } else {
        $stmt=$conexao->prepare("SELECT hora_inicio,hora_fim,almoco_inicio,almoco_fim,disponivel,status FROM horario_empresa WHERE id_empresa=? AND dia_semana=? LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao validar horário da empresa: '.$conexao->error);
        $stmt->bind_param('is',$idEmpresa,$diaSemana);
    }
    $stmt->execute(); $stmt->bind_result($expInicio,$expFim,$almocoInicio,$almocoFim,$disponivel,$statusHorario);
    $encontrado=$stmt->fetch(); $stmt->close();
    if (!$encontrado || (int)$disponivel!==1 || $statusHorario!=='ativo' || !$expInicio || !$expFim) {
        return 'O profissional não trabalha na data selecionada.';
    }

    $paraMinutos = static function(string $hora): int {
        [$h,$m]=array_map('intval',explode(':',substr($hora,0,5)));
        return $h*60+$m;
    };
    $inicio=$paraMinutos($horaInicio); $fim=$inicio+$duracaoMin;
    $inicioExp=$paraMinutos((string)$expInicio); $fimExp=$paraMinutos((string)$expFim);
    if ($inicio<$inicioExp || $fim>$fimExp) return 'O serviço não cabe no expediente do profissional.';
    if ($almocoInicio && $almocoFim) {
        $inicioAlmoco=$paraMinutos((string)$almocoInicio); $fimAlmoco=$paraMinutos((string)$almocoFim);
        if ($inicio<$fimAlmoco && $fim>$inicioAlmoco) return 'O serviço atravessa o intervalo de almoço.';
    }

    $intervalo=10; $usaPadraoGeral=1;
    $stmt=$conexao->prepare("SELECT intervalo_padrao_min,usa_padrao_empresa FROM configuracao_geral_profissional WHERE id_empresa=? AND id_profissional=? AND status='ativo' LIMIT 1");
    if (!$stmt) throw new RuntimeException('Falha ao validar intervalo profissional: '.$conexao->error);
    $stmt->bind_param('ii',$idEmpresa,$idProfissional); $stmt->execute(); $stmt->bind_result($intervaloProf,$usaPadraoGeralDb);
    $temConfig=$stmt->fetch(); if ($temConfig) $usaPadraoGeral=(int)$usaPadraoGeralDb; $stmt->close();
    if ($temConfig && $usaPadraoGeral===0) $intervalo=(int)$intervaloProf;
    else {
        $stmt=$conexao->prepare("SELECT intervalo_padrao_min FROM configuracao_geral_empresa WHERE id_empresa=? AND status='ativo' LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao validar intervalo da empresa: '.$conexao->error);
        $stmt->bind_param('i',$idEmpresa); $stmt->execute(); $stmt->bind_result($intervaloEmpresa);
        if ($stmt->fetch()) $intervalo=(int)$intervaloEmpresa; $stmt->close();
    }
    if ($intervalo<=0) $intervalo=10;
    if (($inicio-$inicioExp)%$intervalo!==0) return 'O horário selecionado não existe na grade de atendimento.';
    return null;
}
