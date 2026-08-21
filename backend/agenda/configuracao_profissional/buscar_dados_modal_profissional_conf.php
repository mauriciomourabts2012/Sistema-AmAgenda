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
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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
    $statusSessao    = (string)($auth['status'] ?? '');

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

    $idEmpresaSessao = 0;

    if (isset($auth['id_empresa'])) {
        $idEmpresaSessao = (int)$auth['id_empresa'];
    } elseif (isset($_SESSION['empresa_id'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa_id'];
    } elseif (isset($_SESSION['id_empresa'])) {
        $idEmpresaSessao = (int)$_SESSION['id_empresa'];
    } elseif (isset($_SESSION['empresa']['id_empresa'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa']['id_empresa'];
    } elseif (isset($_SESSION['empresa']['id'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa']['id'];
    }

    if ($idEmpresaSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'SESSION_WITHOUT_COMPANY',
            'user_msg' => 'Não foi possível identificar a empresa da sessão.',
            'debug' => [
                'auth_keys' => is_array($auth) ? array_keys($auth) : [],
                'session_keys' => array_keys($_SESSION ?? [])
            ]
        ], 403);
    }

    require __DIR__ . '/../../_config/conexao.php';

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    function lower(mixed $v): string
    {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }

    function normalizarHora(mixed $valor, string $fallback = ''): string
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

    function ordemInicioSemana(): array
    {
        return ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo'];
    }

    // O id_profissional identifica o alvo administrativo; a autorização é
    // sempre confirmada pelo perfil e pela empresa presentes na sessão.
    $idProfissionalSessao = filter_input(INPUT_GET, 'id_profissional', FILTER_VALIDATE_INT)
        ?: (is_numeric($_GET['id_profissional'] ?? null) ? (int)$_GET['id_profissional'] : 0);
    if ($idProfissionalSessao <= 0) {
        out(['ok' => false, 'code' => 'PROFESSIONAL_REQUIRED', 'user_msg' => 'Selecione um profissional para continuar.'], 422);
    }

    $tipoUsuario = lower($auth['tipo_usuario'] ?? '');
    $modoSuporte = ($auth['modo_suporte'] ?? false) === true || (int)($auth['modo_suporte'] ?? 0) === 1;
    $idProfissionalProprio = 0;
    if ($tipoUsuario === 'super_admin') {
        if (!$modoSuporte) {
            out(['ok' => false, 'code' => 'SUPPORT_COMPANY_REQUIRED', 'user_msg' => 'Acesse uma empresa em modo suporte antes de administrar estas configurações.'], 403);
        }
    } else {
        $stmt = $conexao->prepare("SELECT pf.nome, p.id_profissional FROM empresa_usuario eu INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil INNER JOIN empresa e ON e.id_empresa=eu.id_empresa LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' AND pf.status='ativo' AND e.status='ativo' LIMIT 1");
        $stmt->bind_param('ii', $idEmpresaSessao, $idUsuarioSessao);
        $stmt->execute(); $stmt->bind_result($perfilSessao, $idProfissionalProprio); $vinculoOk = $stmt->fetch(); $stmt->close();
        $perfilNormalizado = lower($perfilSessao ?? '');
        $ehProprietario = in_array($perfilNormalizado, ['proprietário', 'proprietario'], true);
        $ehProfissionalProprio = in_array($perfilNormalizado, ['profissional', 'profissionais'], true)
            && (int)$idProfissionalProprio > 0
            && (int)$idProfissionalProprio === $idProfissionalSessao;
        if (!$vinculoOk || (!$ehProprietario && !$ehProfissionalProprio)) {
            out(['ok' => false, 'code' => 'ACCESS_DENIED', 'user_msg' => 'Você não possui permissão para administrar estas configurações.'], 403);
        }
    }

    $stmt = $conexao->prepare("SELECT p.id_profissional, u.nome, e.id_empresa, e.nome, e.status FROM profissional p INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario=p.id_usuario INNER JOIN empresa e ON e.id_empresa=eu.id_empresa WHERE p.id_profissional=? AND eu.id_empresa=? AND u.status='ativo' AND eu.status='ativo' AND e.status='ativo' LIMIT 1");
    $stmt->bind_param('ii', $idProfissionalSessao, $idEmpresaSessao);
    $stmt->execute(); $stmt->bind_result($idProfissionalValidado, $profissionalNome, $empresaIdDb, $empresaNomeDb, $empresaStatusDb); $profissionalEncontrado = $stmt->fetch(); $stmt->close();
    if (!$profissionalEncontrado) {
        out(['ok' => false, 'code' => 'PROFESSIONAL_ACCESS_DENIED', 'user_msg' => 'O profissional selecionado não está ativo ou não pertence à empresa acessada.'], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIGURAÇÃO GERAL DA EMPRESA
    |--------------------------------------------------------------------------
    */
    $stmt = $conexao->prepare("
        SELECT
            id_config,
            inicio_semana,
            intervalo_padrao_min,
            observacao_padrao,
            status,
            criado_em,
            atualizado_em
        FROM configuracao_geral_empresa
        WHERE id_empresa = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar configuração geral da empresa: ' . $conexao->error);
    }

    $stmt->bind_param("i", $idEmpresaSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar configuração geral da empresa: ' . $stmt->error);
    }

    $res = $stmt->get_result();
    $configEmpresa = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | CONFIGURAÇÃO GERAL DO PROFISSIONAL
    |--------------------------------------------------------------------------
    */
    $stmt = $conexao->prepare("
        SELECT
            id_config_profissional,
            intervalo_padrao_min,
            observacao_padrao,
            status,
            usa_padrao_empresa,
            criado_em,
            atualizado_em
        FROM configuracao_geral_profissional
        WHERE id_empresa = ?
          AND id_profissional = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar configuração geral profissional: ' . $conexao->error);
    }

    $stmt->bind_param("ii", $idEmpresaSessao, $idProfissionalSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar configuração geral profissional: ' . $stmt->error);
    }

    $res = $stmt->get_result();
    $configProfissional = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $usaPadraoGeral = $configProfissional ? (int)$configProfissional['usa_padrao_empresa'] : 1;

    if ($usaPadraoGeral === 0) {
        $idConfig = (int)$configProfissional['id_config_profissional'];
        $intervaloPadraoMin = (int)($configProfissional['intervalo_padrao_min'] ?? 10);
        $observacaoPadrao = trim((string)($configProfissional['observacao_padrao'] ?? ''));
        $statusConfig = (string)($configProfissional['status'] ?? 'ativo');
        $criadoEm = $configProfissional['criado_em'] ?? null;
        $atualizadoEm = $configProfissional['atualizado_em'] ?? null;
        $origemGeral = 'configuracao_geral_profissional';
    } else {
        $idConfig = isset($configEmpresa['id_config']) ? (int)$configEmpresa['id_config'] : null;
        $intervaloPadraoMin = (int)($configEmpresa['intervalo_padrao_min'] ?? 10);
        $observacaoPadrao = trim((string)($configEmpresa['observacao_padrao'] ?? ''));
        $statusConfig = (string)($configEmpresa['status'] ?? 'ativo');
        $criadoEm = $configEmpresa['criado_em'] ?? null;
        $atualizadoEm = $configEmpresa['atualizado_em'] ?? null;
        $origemGeral = $configEmpresa ? 'configuracao_geral_empresa' : 'padrao_sistema';
    }

    /*
    |--------------------------------------------------------------------------
    | HORÁRIOS - CONTROLE DO PROFISSIONAL
    |--------------------------------------------------------------------------
    */
    $stmt = $conexao->prepare("
        SELECT usa_padrao_empresa
        FROM horario_profissional
        WHERE id_empresa = ?
          AND id_profissional = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar controle de horário profissional: ' . $conexao->error);
    }

    $stmt->bind_param("ii", $idEmpresaSessao, $idProfissionalSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar controle de horário profissional: ' . $stmt->error);
    }

    $stmt->bind_result($usaPadraoHorarioDb);
    $temHorarioProfissional = $stmt->fetch();
    $stmt->close();

    $usaPadraoHorario = $temHorarioProfissional ? (int)$usaPadraoHorarioDb : 1;

    if ($usaPadraoHorario === 0) {
        $stmt = $conexao->prepare("
            SELECT
                id_horario_profissional AS id_horario,
                dia_semana,
                hora_inicio,
                hora_fim,
                almoco_inicio,
                almoco_fim,
                disponivel,
                status,
                usa_padrao_empresa,
                criado_em,
                atualizado_em
            FROM horario_profissional
            WHERE id_empresa = ?
              AND id_profissional = ?
            ORDER BY FIELD(
                dia_semana,
                'segunda','terca','quarta','quinta','sexta','sabado','domingo'
            )
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar horários profissionais: ' . $conexao->error);
        }

        $stmt->bind_param("ii", $idEmpresaSessao, $idProfissionalSessao);
        $origemHorarios = 'horario_profissional';
    } else {
        $stmt = $conexao->prepare("
            SELECT
                id_horario_empresa AS id_horario,
                dia_semana,
                hora_inicio,
                hora_fim,
                almoco_inicio,
                almoco_fim,
                disponivel,
                status,
                1 AS usa_padrao_empresa,
                criado_em,
                atualizado_em
            FROM horario_empresa
            WHERE id_empresa = ?
            ORDER BY FIELD(
                dia_semana,
                'segunda','terca','quarta','quinta','sexta','sabado','domingo'
            )
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar horários da empresa: ' . $conexao->error);
        }

        $stmt->bind_param("i", $idEmpresaSessao);
        $origemHorarios = 'horario_empresa';
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar busca dos horários: ' . $stmt->error);
    }

    $res = $stmt->get_result();

    $horarios = [];
    $diasSelecionados = [];
    $horarioBase = null;
    $inicioSemanaCalculado = null;

    $mapDias = [
        'segunda' => 'seg',
        'terca'   => 'ter',
        'quarta'  => 'qua',
        'quinta'  => 'qui',
        'sexta'   => 'sex',
        'sabado'  => 'sab',
        'domingo' => 'dom',
    ];

    while ($row = $res->fetch_assoc()) {
        $diaSemana = (string)($row['dia_semana'] ?? '');
        $diaCheckbox = $mapDias[$diaSemana] ?? '';

        $disponivel = (int)($row['disponivel'] ?? 0);
        $statusLinha = (string)($row['status'] ?? 'ativo');

        $item = [
            'id_horario' => isset($row['id_horario']) ? (int)$row['id_horario'] : null,
            'id_empresa' => $idEmpresaSessao,
            'id_profissional' => $idProfissionalSessao,
            'profissional_nome' => (string)$profissionalNome,
            'dia_semana' => $diaSemana,
            'dia_checkbox' => $diaCheckbox,
            'hora_inicio' => normalizarHora($row['hora_inicio'] ?? null),
            'hora_fim' => normalizarHora($row['hora_fim'] ?? null),
            'almoco_inicio' => normalizarHora($row['almoco_inicio'] ?? null),
            'almoco_fim' => normalizarHora($row['almoco_fim'] ?? null),
            'disponivel' => $disponivel,
            'status' => $statusLinha,
            'usa_padrao_empresa' => (int)($row['usa_padrao_empresa'] ?? 1),
            'criado_em' => $row['criado_em'] ?? null,
            'atualizado_em' => $row['atualizado_em'] ?? null,
        ];

        if ($diaCheckbox !== '') {
            $horarios[$diaCheckbox] = $item;
        }

        if ($diaCheckbox !== '' && $disponivel === 1 && lower($statusLinha) === 'ativo') {
            $diasSelecionados[] = $diaCheckbox;

            if ($horarioBase === null) {
                $horarioBase = $item;
            }
        }
    }

    $stmt->close();

    $diasSelecionados = array_values(array_unique($diasSelecionados));

    /*
    |--------------------------------------------------------------------------
    | INÍCIO DA SEMANA
    |--------------------------------------------------------------------------
    | Se os horários são personalizados, pega o primeiro dia disponível
    | de horario_profissional. Se usa padrão, pega configuracao_geral_empresa.
    |--------------------------------------------------------------------------
    */
    if ($usaPadraoHorario === 0) {
        $inicioSemana = 'segunda';

        foreach (ordemInicioSemana() as $diaSemana) {
            $sigla = $mapDias[$diaSemana] ?? '';

            if (
                $sigla !== ''
                && isset($horarios[$sigla])
                && (int)($horarios[$sigla]['disponivel'] ?? 0) === 1
                && lower((string)($horarios[$sigla]['status'] ?? '')) === 'ativo'
            ) {
                $inicioSemana = $diaSemana;
                break;
            }
        }
    } else {
        $inicioSemana = (string)($configEmpresa['inicio_semana'] ?? 'segunda');
    }

    $horaInicio = $horarioBase['hora_inicio'] ?? '08:00';
    $horaFim = $horarioBase['hora_fim'] ?? '18:00';
    $almocoInicio = $horarioBase['almoco_inicio'] ?? '';
    $almocoFim = $horarioBase['almoco_fim'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | WHATSAPP PROFISSIONAL
    |--------------------------------------------------------------------------
    */
    $stmt = $conexao->prepare("
        SELECT
            id_config_whatsapp_profissional,
            ddi_padrao,
            ddd_padrao,
            mensagem_padrao,
            status,
            usa_padrao_empresa,
            criado_em,
            atualizado_em
        FROM configuracao_whatsapp_profissional
        WHERE id_empresa = ?
          AND id_profissional = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar configuração WhatsApp profissional: ' . $conexao->error);
    }

    $stmt->bind_param("ii", $idEmpresaSessao, $idProfissionalSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar configuração WhatsApp profissional: ' . $stmt->error);
    }

    $res = $stmt->get_result();
    $whatsappProfissional = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $usaPadraoWhatsapp = $whatsappProfissional ? (int)$whatsappProfissional['usa_padrao_empresa'] : 1;

    if ($usaPadraoWhatsapp === 0) {
        $whatsapp = $whatsappProfissional;
        $origemWhatsapp = 'configuracao_whatsapp_profissional';
        $idConfigWhatsapp = (int)$whatsappProfissional['id_config_whatsapp_profissional'];
    } else {
        $stmt = $conexao->prepare("
            SELECT
                id_config_whatsapp,
                ddi_padrao,
                ddd_padrao,
                mensagem_padrao,
                status,
                criado_em,
                atualizado_em
            FROM configuracao_whatsapp_empresa
            WHERE id_empresa = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar configuração WhatsApp empresa: ' . $conexao->error);
        }

        $stmt->bind_param("i", $idEmpresaSessao);

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao executar configuração WhatsApp empresa: ' . $stmt->error);
        }

        $res = $stmt->get_result();
        $whatsapp = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $origemWhatsapp = $whatsapp ? 'configuracao_whatsapp_empresa' : 'padrao_sistema';
        $idConfigWhatsapp = isset($whatsapp['id_config_whatsapp']) ? (int)$whatsapp['id_config_whatsapp'] : null;
    }

    out([
        'ok' => true,
        'code' => 'CONFIG_PROFISSIONAL_LOADED',
        'user_msg' => 'Configurações da agenda carregadas com sucesso.',
        'data' => [
            'id_empresa' => $idEmpresaSessao,
            'id_usuario' => $idUsuarioSessao,
            'id_profissional' => $idProfissionalSessao,
            'profissional_nome' => (string)$profissionalNome,

            'id_config' => $idConfig,
            'id_config_whatsapp' => $idConfigWhatsapp,

            'inicio_semana' => $inicioSemana,
            'semana_inicio' => $inicioSemana,

            'intervalo_padrao_min' => $intervaloPadraoMin,
            'intervalo_padrao' => (string)$intervaloPadraoMin,

            'observacao_padrao' => $observacaoPadrao,

            'status' => $statusConfig,
            'criado_em' => $criadoEm,
            'atualizado_em' => $atualizadoEm,

            'hora_inicio' => $horaInicio,
            'hora_fim' => $horaFim,
            'almoco_inicio' => $almocoInicio,
            'almoco_fim' => $almocoFim,

            'dias' => $diasSelecionados,
            'horarios' => $horarios,

            'ddi_padrao' => trim((string)($whatsapp['ddi_padrao'] ?? '55')),
            'ddd_padrao' => trim((string)($whatsapp['ddd_padrao'] ?? '')),
            'mensagem_padrao' => trim((string)($whatsapp['mensagem_padrao'] ?? 'Olá {cliente}! Seu agendamento de {servico} está {status} para {data} às {hora}.')),
            'whatsapp_status' => (string)($whatsapp['status'] ?? 'ativo'),
            'whatsapp_criado_em' => $whatsapp['criado_em'] ?? null,
            'whatsapp_atualizado_em' => $whatsapp['atualizado_em'] ?? null,

            'usa_padrao_empresa' => [
                'geral' => $usaPadraoGeral,
                'horarios' => $usaPadraoHorario,
                'whatsapp' => $usaPadraoWhatsapp,
            ],

            'empresa' => [
                'id_empresa' => (int)$empresaIdDb,
                'nome' => (string)$empresaNomeDb,
                'status' => (string)$empresaStatusDb,
            ],

            'origem' => [
                'geral' => $origemGeral,
                'horarios' => !empty($horarios) ? $origemHorarios : 'padrao_sistema',
                'whatsapp' => $origemWhatsapp,
            ],
        ],
    ], 200);

} catch (Throwable $e) {
    error_log('[buscar_dados_modal_profissional_conf] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao buscar configurações da agenda.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}
