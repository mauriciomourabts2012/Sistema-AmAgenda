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

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.'
        ], 405);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = $_SESSION['auth'] ?? null;

    $idUsuarioSessao = (int)($auth['id_usuario'] ?? 0);
    $statusSessao = (string)($auth['status'] ?? '');

    if ($idUsuarioSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.'
        ], 401);
    }

    if ($statusSessao !== '' && $statusSessao !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'SESSION_USER_INACTIVE',
            'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
        ], 403);
    }

    $idEmpresaSessao = (int)(
        $auth['id_empresa']
        ?? $_SESSION['empresa_id']
        ?? $_SESSION['id_empresa']
        ?? $_SESSION['empresa']['id_empresa']
        ?? $_SESSION['empresa']['id']
        ?? 0
    );

    if ($idEmpresaSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'SESSION_WITHOUT_COMPANY',
            'user_msg' => 'Não foi possível identificar a empresa da sessão.'
        ], 403);
    }

    require __DIR__ . '/../../_config/conexao.php';
    require_once __DIR__ . '/../../_servicos/auditoria.php';

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    function s(mixed $v): string
    {
        return trim((string)$v);
    }

    function lower(mixed $v): string
    {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }

    function normalizarHoraBanco(?string $valor): ?string
    {
        $valor = trim((string)($valor ?? ''));

        if ($valor === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $valor)) {
            return $valor . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $valor)) {
            return $valor;
        }

        return null;
    }

    function horaCurta(?string $valor): string
    {
        $valor = trim((string)($valor ?? ''));

        if ($valor === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $valor)) {
            return substr($valor, 0, 5);
        }

        if (preg_match('/^\d{2}:\d{2}$/', $valor)) {
            return $valor;
        }

        return '';
    }

    function ordemDiasSemana(): array
    {
        return ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo'];
    }

    function tsHora(?string $hora): int
    {
        return strtotime('1970-01-01 ' . (string)$hora) ?: 0;
    }

    /** Snapshot mínimo das configurações próprias; sempre limitado à empresa e ao profissional alvo. */
    function auditoriaSnapshotConfiguracaoProfissional(mysqli $conexao, int $empresa, int $profissional): array
    {
        $snapshot=['intervalo_padrao'=>null,'observacao_padrao'=>null,'horarios'=>[],'ddi_padrao'=>null,'ddd_padrao'=>null,'mensagem_whatsapp'=>null];
        $stmt=$conexao->prepare('SELECT intervalo_padrao_min,observacao_padrao FROM configuracao_geral_profissional WHERE id_empresa=? AND id_profissional=? LIMIT 1');$stmt->bind_param('ii',$empresa,$profissional);$stmt->execute();$res=$stmt->get_result();if($row=$res->fetch_assoc()){$snapshot['intervalo_padrao']=(int)$row['intervalo_padrao_min'];$snapshot['observacao_padrao']=$row['observacao_padrao'];}$stmt->close();
        $stmt=$conexao->prepare('SELECT dia_semana,hora_inicio,hora_fim,almoco_inicio,almoco_fim,disponivel FROM horario_profissional WHERE id_empresa=? AND id_profissional=? ORDER BY dia_semana');$stmt->bind_param('ii',$empresa,$profissional);$stmt->execute();$res=$stmt->get_result();while($row=$res->fetch_assoc()){$dia=(string)$row['dia_semana'];$snapshot['horarios'][$dia]=['dia_semana'=>$dia,'disponivel'=>(int)$row['disponivel'],'hora_inicio'=>$row['hora_inicio'],'hora_fim'=>$row['hora_fim'],'almoco_inicio'=>$row['almoco_inicio'],'almoco_fim'=>$row['almoco_fim']];}$stmt->close();
        $stmt=$conexao->prepare('SELECT ddi_padrao,ddd_padrao,mensagem_padrao FROM configuracao_whatsapp_profissional WHERE id_empresa=? AND id_profissional=? LIMIT 1');$stmt->bind_param('ii',$empresa,$profissional);$stmt->execute();$res=$stmt->get_result();if($row=$res->fetch_assoc()){$snapshot['ddi_padrao']=$row['ddi_padrao'];$snapshot['ddd_padrao']=$row['ddd_padrao'];$snapshot['mensagem_whatsapp']=$row['mensagem_padrao'];}$stmt->close();
        return $snapshot;
    }

    function auditoriaRegistrarConfiguracaoProfissional(mysqli $conexao, int $idProfissional, string $nome, string $aba, array $antes, array $depois): void
    {
        $diferencas=[];foreach($antes as $campo=>$valor)if(!auditoriaValoresIguais($valor,$depois[$campo]??null))$diferencas[$campo]=['antes'=>$valor,'depois'=>$depois[$campo]??null];
        if($diferencas!==[])auditoriaRegistrar($conexao,'agenda_profissional.configuracao_alterada',['entidade_id'=>$idProfissional,'entidade_rotulo'=>$nome,'descricao'=>'Alterou a configuração da agenda de '.$nome.'.','alteracoes'=>$diferencas,'contexto'=>['aba'=>$aba,'origem'=>'configuracao_profissional']]);
    }

    $aba = s($_POST['aba'] ?? 'cfg-geral');

    $abasPermitidas = ['cfg-geral', 'cfg-horarios', 'cfg-whatsapp'];

    if (!in_array($aba, $abasPermitidas, true)) {
        out([
            'ok' => false,
            'code' => 'INVALID_TAB',
            'user_msg' => 'Aba de configuração inválida.'
        ], 422);
    }

    $stmt = $conexao->prepare("
        SELECT id_empresa, nome, status
        FROM empresa
        WHERE id_empresa = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação da empresa: ' . $conexao->error);
    }

    $stmt->bind_param('i', $idEmpresaSessao);
    $stmt->execute();
    $stmt->bind_result($empresaIdDb, $empresaNomeDb, $empresaStatusDb);

    $empresaEncontrada = $stmt->fetch();
    $stmt->close();

    if (!$empresaEncontrada) {
        out([
            'ok' => false,
            'code' => 'EMPRESA_NOT_FOUND',
            'user_msg' => 'Empresa da sessão não encontrada.'
        ], 404);
    }

    if (lower((string)$empresaStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INACTIVE',
            'user_msg' => 'A empresa vinculada à sessão está inativa.'
        ], 403);
    }

    $tipoUsuario = lower($auth['tipo_usuario'] ?? '');
    $modoSuporte = ($auth['modo_suporte'] ?? false) === true || (int)($auth['modo_suporte'] ?? 0) === 1;
    $idProfissionalProprio = 0;
    $restringirAoProfissionalProprio = false;
    if ($tipoUsuario === 'super_admin') {
        if (!$modoSuporte) {
            out(['ok' => false, 'code' => 'SUPPORT_COMPANY_REQUIRED', 'user_msg' => 'Acesse uma empresa em modo suporte antes de administrar estas configurações.'], 403);
        }
    } else {
        $stmt = $conexao->prepare("SELECT p.id_profissional FROM empresa_usuario eu LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' LIMIT 1");
        $stmt->bind_param('ii', $idEmpresaSessao, $idUsuarioSessao);
        $stmt->execute(); $stmt->bind_result($idProfissionalProprio); $vinculoOk = $stmt->fetch(); $stmt->close();
        if (!$vinculoOk) out(['ok' => false, 'code' => 'ACCESS_DENIED', 'user_msg' => 'Seu vínculo com a empresa não está ativo.'], 403);
    }

    // O alvo vem do modal, mas empresa, vínculo e status são revalidados aqui.
    $idProfissionalSolicitado = filter_input(INPUT_POST, 'id_profissional', FILTER_VALIDATE_INT)
        ?: (is_numeric($_POST['id_profissional'] ?? null) ? (int)$_POST['id_profissional'] : 0);
    if ($idProfissionalSolicitado <= 0) {
        out(['ok' => false, 'code' => 'PROFESSIONAL_REQUIRED', 'user_msg' => 'Selecione um profissional para continuar.'], 422);
    }
    require_once __DIR__ . '/../../_regras/permissoes_usuario.php';
    $permissaoEdicao = (int)$idProfissionalProprio > 0 && (int)$idProfissionalProprio === $idProfissionalSolicitado
        ? 'agenda_configuracao.editar_propria'
        : 'agenda_configuracao.editar_todos_profissionais';
    exigirPermissao($conexao, $permissaoEdicao);

    $stmt = $conexao->prepare("
        SELECT p.id_profissional, p.id_usuario, p.especialidade, u.nome
        FROM profissional p
        INNER JOIN empresa_usuario eu
            ON eu.id_usuario = p.id_usuario
           AND eu.id_empresa = ?
           AND eu.status = 'ativo'
        INNER JOIN usuario u ON u.id_usuario = p.id_usuario AND u.status = 'ativo'
        WHERE p.id_profissional = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar consulta do profissional: ' . $conexao->error);
    }

    $stmt->bind_param('ii', $idEmpresaSessao, $idProfissionalSolicitado);
    $stmt->execute();
    $stmt->bind_result($idProfissionalDb, $idUsuarioProfissionalDb, $especialidadeDb, $nomeProfissionalDb);

    $profissionalEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$profissionalEncontrado || (int)$idProfissionalDb <= 0) {
        out([
            'ok' => false,
            'code' => 'PROFISSIONAL_NOT_FOUND',
            'user_msg' => 'O profissional selecionado não está ativo ou não pertence à empresa acessada.'
        ], 404);
    }

    $idProfissional = (int)$idProfissionalDb;
    $status = 'ativo';
    $fields = [];

    $conexao->begin_transaction();
    // Estado anterior capturado dentro da mesma transação usada pela operação principal.
    $auditoriaAntes = auditoriaSnapshotConfiguracaoProfissional($conexao, $idEmpresaSessao, $idProfissional);

    /*
    |--------------------------------------------------------------------------
    | ABA GERAL
    |--------------------------------------------------------------------------
    */
    if ($aba === 'cfg-geral') {
        $intervaloRaw = s($_POST['intervalo_padrao'] ?? $_POST['intervalo_padrao_min'] ?? '');
        $observacaoRaw = s($_POST['observacao_padrao'] ?? '');

        $intervaloPadrao = (int)$intervaloRaw;
        $observacaoPadrao = $observacaoRaw !== '' ? $observacaoRaw : null;

        $intervalosPermitidos = [10, 15, 20, 30, 45, 60];

        if (!in_array($intervaloPadrao, $intervalosPermitidos, true)) {
            $fields['cfg_intervalo_padrao'] = 'Selecione um intervalo válido.';
        }

        if ($observacaoPadrao !== null && mb_strlen($observacaoPadrao) > 220) {
            $fields['cfg_obs_geral'] = 'A observação deve ter no máximo 220 caracteres.';
        }

        if (!empty($fields)) {
            $conexao->rollback();
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Revise os campos destacados.',
                'fields' => $fields
            ], 422);
        }

        $stmt = $conexao->prepare("
            INSERT INTO configuracao_geral_profissional
                (
                    id_empresa,
                    id_profissional,
                    intervalo_padrao_min,
                    observacao_padrao,
                    status,
                    usa_padrao_empresa
                )
            VALUES
                (?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                intervalo_padrao_min = VALUES(intervalo_padrao_min),
                observacao_padrao = VALUES(observacao_padrao),
                status = VALUES(status),
                usa_padrao_empresa = 0,
                atualizado_em = CURRENT_TIMESTAMP
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar salvamento geral: ' . $conexao->error);
        }

        $stmt->bind_param(
            'iiiss',
            $idEmpresaSessao,
            $idProfissional,
            $intervaloPadrao,
            $observacaoPadrao,
            $status
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao salvar configuração geral: ' . $stmt->error);
        }

        $stmt->close();
        $auditoriaDepois=auditoriaSnapshotConfiguracaoProfissional($conexao,$idEmpresaSessao,$idProfissional);auditoriaRegistrarConfiguracaoProfissional($conexao,$idProfissional,(string)$nomeProfissionalDb,$aba,$auditoriaAntes,$auditoriaDepois);
        $conexao->commit();

        out([
            'ok' => true,
            'code' => 'CONFIG_GERAL_PROFISSIONAL_SAVED',
            'user_msg' => 'Configurações gerais do profissional salvas com sucesso.',
            'aba' => $aba,
            'data' => [
                'id_empresa' => $idEmpresaSessao,
                'empresa_nome' => (string)$empresaNomeDb,
                'id_profissional' => $idProfissional,
                'intervalo_padrao_min' => $intervaloPadrao,
                'observacao_padrao' => $observacaoPadrao,
                'status' => $status,
                'usa_padrao_empresa' => 0
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ABA HORÁRIOS — NOVA LÓGICA
    |--------------------------------------------------------------------------
    */
    if ($aba === 'cfg-horarios') {
        $horarios = $_POST['horarios'] ?? [];

        if (!is_array($horarios)) {
            $horarios = [];
        }

        $diasPermitidos = ordemDiasSemana();
        $dadosHorarios = [];
        $temDiaDisponivel = false;

        foreach ($diasPermitidos as $diaSemana) {
            $linha = $horarios[$diaSemana] ?? [];

            if (!is_array($linha)) {
                $linha = [];
            }

            $disponivel = (int)($linha['disponivel'] ?? $linha['ativo'] ?? 0);
            $disponivel = $disponivel === 1 ? 1 : 0;

            $horaInicioRaw = s($linha['hora_inicio'] ?? '');
            $horaFimRaw = s($linha['hora_fim'] ?? '');
            $almocoInicioRaw = s($linha['almoco_inicio'] ?? '');
            $almocoFimRaw = s($linha['almoco_fim'] ?? '');

            $horaInicioDb = normalizarHoraBanco($horaInicioRaw);
            $horaFimDb = normalizarHoraBanco($horaFimRaw);
            $almocoInicioDb = normalizarHoraBanco($almocoInicioRaw);
            $almocoFimDb = normalizarHoraBanco($almocoFimRaw);

            if ($disponivel === 1) {
                $temDiaDisponivel = true;

                if ($horaInicioDb === null) {
                    $fields["horarios_{$diaSemana}_hora_inicio"] = "Informe a hora inicial de {$diaSemana}.";
                }

                if ($horaFimDb === null) {
                    $fields["horarios_{$diaSemana}_hora_fim"] = "Informe a hora final de {$diaSemana}.";
                }

                if ($horaInicioDb !== null && $horaFimDb !== null && tsHora($horaInicioDb) >= tsHora($horaFimDb)) {
                    $fields["horarios_{$diaSemana}_hora_fim"] = "A hora final deve ser maior que a hora inicial em {$diaSemana}.";
                }

                $temAlmocoInicio = $almocoInicioRaw !== '';
                $temAlmocoFim = $almocoFimRaw !== '';

                if ($temAlmocoInicio xor $temAlmocoFim) {
                    if (!$temAlmocoInicio) {
                        $fields["horarios_{$diaSemana}_almoco_inicio"] = "Informe o início do almoço em {$diaSemana}.";
                    }

                    if (!$temAlmocoFim) {
                        $fields["horarios_{$diaSemana}_almoco_fim"] = "Informe o fim do almoço em {$diaSemana}.";
                    }
                }

                if ($temAlmocoInicio && $almocoInicioDb === null) {
                    $fields["horarios_{$diaSemana}_almoco_inicio"] = "Informe um horário de almoço válido em {$diaSemana}.";
                }

                if ($temAlmocoFim && $almocoFimDb === null) {
                    $fields["horarios_{$diaSemana}_almoco_fim"] = "Informe um horário de almoço válido em {$diaSemana}.";
                }

                if ($almocoInicioDb !== null && $almocoFimDb !== null) {
                    if (tsHora($almocoInicioDb) >= tsHora($almocoFimDb)) {
                        $fields["horarios_{$diaSemana}_almoco_fim"] = "O fim do almoço deve ser maior que o início em {$diaSemana}.";
                    }

                    if ($horaInicioDb !== null && $horaFimDb !== null) {
                        if (tsHora($almocoInicioDb) <= tsHora($horaInicioDb) || tsHora($almocoFimDb) >= tsHora($horaFimDb)) {
                            $fields["horarios_{$diaSemana}_almoco_inicio"] = "O almoço deve estar dentro do expediente em {$diaSemana}.";
                        }
                    }
                }
            } else {
                $horaInicioDb = null;
                $horaFimDb = null;
                $almocoInicioDb = null;
                $almocoFimDb = null;
            }

            $dadosHorarios[$diaSemana] = [
                'dia_semana' => $diaSemana,
                'disponivel' => $disponivel,
                'hora_inicio' => $horaInicioDb,
                'hora_fim' => $horaFimDb,
                'almoco_inicio' => $almocoInicioDb,
                'almoco_fim' => $almocoFimDb,
            ];
        }

        if (!$temDiaDisponivel) {
            $fields['horarios'] = 'Selecione pelo menos um dia disponível.';
        }

        if (!empty($fields)) {
            $conexao->rollback();
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Revise os horários informados.',
                'fields' => $fields
            ], 422);
        }

        $stmt = $conexao->prepare("
            INSERT INTO horario_profissional
                (
                    id_empresa,
                    id_profissional,
                    dia_semana,
                    hora_inicio,
                    hora_fim,
                    almoco_inicio,
                    almoco_fim,
                    disponivel,
                    status,
                    usa_padrao_empresa
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                hora_inicio = VALUES(hora_inicio),
                hora_fim = VALUES(hora_fim),
                almoco_inicio = VALUES(almoco_inicio),
                almoco_fim = VALUES(almoco_fim),
                disponivel = VALUES(disponivel),
                status = VALUES(status),
                usa_padrao_empresa = 0,
                atualizado_em = CURRENT_TIMESTAMP
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar salvamento dos horários: ' . $conexao->error);
        }

        foreach ($dadosHorarios as $diaSemana => $linha) {
            $diaSemanaDb = $linha['dia_semana'];
            $horaInicioDb = $linha['hora_inicio'];
            $horaFimDb = $linha['hora_fim'];
            $almocoInicioDb = $linha['almoco_inicio'];
            $almocoFimDb = $linha['almoco_fim'];
            $disponivelDb = (int)$linha['disponivel'];

            $stmt->bind_param(
                'iisssssis',
                $idEmpresaSessao,
                $idProfissional,
                $diaSemanaDb,
                $horaInicioDb,
                $horaFimDb,
                $almocoInicioDb,
                $almocoFimDb,
                $disponivelDb,
                $status
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Erro ao salvar horário do profissional: ' . $stmt->error);
            }
        }

        $stmt->close();

        $stmtConfig = $conexao->prepare("
            INSERT INTO configuracao_geral_profissional
                (
                    id_empresa,
                    id_profissional,
                    intervalo_padrao_min,
                    observacao_padrao,
                    status,
                    usa_padrao_empresa
                )
            VALUES
                (?, ?, 10, NULL, 'ativo', 0)
            ON DUPLICATE KEY UPDATE
                usa_padrao_empresa = 0,
                status = 'ativo',
                atualizado_em = CURRENT_TIMESTAMP
        ");

        if (!$stmtConfig) {
            throw new RuntimeException('Erro ao preparar atualização da configuração geral profissional: ' . $conexao->error);
        }

        $stmtConfig->bind_param('ii', $idEmpresaSessao, $idProfissional);

        if (!$stmtConfig->execute()) {
            throw new RuntimeException('Erro ao atualizar configuração geral profissional: ' . $stmtConfig->error);
        }

        $stmtConfig->close();

        $inicioSemanaAutomatico = 'segunda';

        foreach (ordemDiasSemana() as $diaSemana) {
            if (($dadosHorarios[$diaSemana]['disponivel'] ?? 0) === 1) {
                $inicioSemanaAutomatico = $diaSemana;
                break;
            }
        }

        $retornoHorarios = [];

        foreach ($dadosHorarios as $diaSemana => $linha) {
            $retornoHorarios[$diaSemana] = [
                'disponivel' => (int)$linha['disponivel'],
                'hora_inicio' => horaCurta($linha['hora_inicio']),
                'hora_fim' => horaCurta($linha['hora_fim']),
                'almoco_inicio' => horaCurta($linha['almoco_inicio']),
                'almoco_fim' => horaCurta($linha['almoco_fim']),
            ];
        }

        $auditoriaDepois=auditoriaSnapshotConfiguracaoProfissional($conexao,$idEmpresaSessao,$idProfissional);auditoriaRegistrarConfiguracaoProfissional($conexao,$idProfissional,(string)$nomeProfissionalDb,$aba,$auditoriaAntes,$auditoriaDepois);
        $conexao->commit();

        out([
            'ok' => true,
            'code' => 'HORARIO_PROFISSIONAL_SAVED',
            'user_msg' => 'Horários do profissional salvos com sucesso.',
            'aba' => $aba,
            'data' => [
                'id_empresa' => $idEmpresaSessao,
                'id_profissional' => $idProfissional,
                'horarios' => $retornoHorarios,
                'inicio_semana' => $inicioSemanaAutomatico,
                'semana_inicio' => $inicioSemanaAutomatico,
                'usa_padrao_empresa' => 0
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ABA WHATSAPP
    |--------------------------------------------------------------------------
    */
    if ($aba === 'cfg-whatsapp') {
        $ddiPadrao = preg_replace('/\D+/', '', s($_POST['ddi_padrao'] ?? '55'));
        $dddPadrao = preg_replace('/\D+/', '', s($_POST['ddd_padrao'] ?? ''));
        $mensagemPadrao = s($_POST['msg_whats'] ?? '');

        if ($ddiPadrao === '') {
            $fields['cfg_ddi_padrao'] = 'Informe o DDI.';
        } elseif (mb_strlen($ddiPadrao) < 1 || mb_strlen($ddiPadrao) > 5) {
            $fields['cfg_ddi_padrao'] = 'O DDI deve ter entre 1 e 5 dígitos.';
        }

        if ($dddPadrao !== '' && mb_strlen($dddPadrao) !== 2) {
            $fields['cfg_ddd_padrao'] = 'O DDD deve ter 2 dígitos.';
        }

        if (mb_strlen($mensagemPadrao) > 5000) {
            $fields['cfg_msg_whats'] = 'A mensagem padrão deve ter no máximo 5000 caracteres.';
        }

        if (!empty($fields)) {
            $conexao->rollback();
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Revise os campos destacados.',
                'fields' => $fields
            ], 422);
        }

        $mensagemPadraoDb = $mensagemPadrao !== '' ? $mensagemPadrao : null;
        $dddPadraoDb = $dddPadrao !== '' ? $dddPadrao : null;

        $stmt = $conexao->prepare("
            INSERT INTO configuracao_whatsapp_profissional
                (
                    id_empresa,
                    id_profissional,
                    ddi_padrao,
                    ddd_padrao,
                    mensagem_padrao,
                    status,
                    usa_padrao_empresa
                )
            VALUES
                (?, ?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                ddi_padrao = VALUES(ddi_padrao),
                ddd_padrao = VALUES(ddd_padrao),
                mensagem_padrao = VALUES(mensagem_padrao),
                status = VALUES(status),
                usa_padrao_empresa = 0,
                atualizado_em = CURRENT_TIMESTAMP
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar salvamento do WhatsApp: ' . $conexao->error);
        }

        $stmt->bind_param(
            'iissss',
            $idEmpresaSessao,
            $idProfissional,
            $ddiPadrao,
            $dddPadraoDb,
            $mensagemPadraoDb,
            $status
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao salvar configuração de WhatsApp do profissional: ' . $stmt->error);
        }

        $stmt->close();
        $auditoriaDepois=auditoriaSnapshotConfiguracaoProfissional($conexao,$idEmpresaSessao,$idProfissional);auditoriaRegistrarConfiguracaoProfissional($conexao,$idProfissional,(string)$nomeProfissionalDb,$aba,$auditoriaAntes,$auditoriaDepois);
        $conexao->commit();

        out([
            'ok' => true,
            'code' => 'WHATSAPP_PROFISSIONAL_SAVED',
            'user_msg' => 'Configurações de WhatsApp do profissional salvas com sucesso.',
            'aba' => $aba,
            'data' => [
                'id_empresa' => $idEmpresaSessao,
                'id_profissional' => $idProfissional,
                'ddi_padrao' => $ddiPadrao,
                'ddd_padrao' => $dddPadraoDb,
                'mensagem_padrao' => $mensagemPadraoDb,
                'usa_padrao_empresa' => 0
            ]
        ]);
    }

    $conexao->rollback();

    out([
        'ok' => false,
        'code' => 'INVALID_FLOW',
        'user_msg' => 'Fluxo de salvamento inválido.'
    ], 422);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
        }
    }

    error_log('[salvar_config_profissional] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao salvar as configurações do profissional.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}
