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

if (!function_exists('s')) {
    function s(mixed $v): string
    {
        return trim((string)$v);
    }
}

if (!function_exists('lower')) {
    function lower(mixed $v): string
    {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }
}

if (!function_exists('readInput')) {
    function readInput(): array
    {
        $contentType = lower($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw ?: '', true);
            return is_array($data) ? $data : [];
        }

        return $_POST ?? [];
    }
}

if (!function_exists('dinheiroParaDecimal')) {
    function dinheiroParaDecimal(mixed $v): ?string
    {
        $raw = s($v);

        if ($raw === '') {
            return null;
        }

        $raw = str_replace(['R$', ' '], '', $raw);

        if (!preg_match('/^\d{1,8}([,.]\d{1,2})?$/', $raw)) {
            return null;
        }

        $raw = str_replace(',', '.', $raw);
        $num = (float)$raw;

        if ($num < 0 || $num > 99999999.99) {
            return null;
        }

        return number_format($num, 2, '.', '');
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
    $statusSessao = lower($auth['status'] ?? '');
    $superAdminSuporte = lower($auth['tipo_usuario'] ?? '') === 'super_admin'
        && (bool)($auth['modo_suporte'] ?? false);

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
            'user_msg' => 'Não foi possível identificar a empresa da sessão.'
        ], 403);
    }

    require __DIR__ . '/../_config/conexao.php';
    require_once __DIR__ . '/../_regras/limites_plano.php';

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    $in = readInput();

    $idProfissional = (int)($in['id_profissional'] ?? $in['profissional_id'] ?? 0);
    $nome = s($in['nome'] ?? $in['servico_nome'] ?? '');
    $descricaoRaw = s($in['descricao'] ?? '');
    $duracaoRaw = s($in['duracao_min'] ?? $in['duracao'] ?? '');
    $valorRaw = s($in['valor'] ?? '');
    $status = lower($in['status'] ?? 'ativo');

    $descricao = $descricaoRaw !== '' ? $descricaoRaw : null;
    $duracaoMin = (int)$duracaoRaw;
    $valor = dinheiroParaDecimal($valorRaw);
    $valorDb = $valor !== null ? (float)$valor : 0.00;
    $status = $status !== '' ? $status : 'ativo';

    $fields = [];

    if ($idProfissional <= 0) {
        $fields['id_profissional'] = 'Selecione um profissional antes de cadastrar o serviço.';
    }

    if ($nome === '') {
        $fields['cfg_servico_nome'] = 'Informe o nome do serviço.';
    } elseif (mb_strlen($nome) < 2) {
        $fields['cfg_servico_nome'] = 'O nome deve ter no mínimo 2 caracteres.';
    } elseif (mb_strlen($nome) > 120) {
        $fields['cfg_servico_nome'] = 'O nome deve ter no máximo 120 caracteres.';
    }

    if ($descricao !== null && mb_strlen($descricao) > 220) {
        $fields['cfg_servico_descricao'] = 'A descrição deve ter no máximo 220 caracteres.';
    }

    if ($duracaoMin <= 0) {
        $fields['cfg_servico_duracao'] = 'Selecione a duração do serviço.';
    } elseif ($duracaoMin > 1440) {
        $fields['cfg_servico_duracao'] = 'A duração não pode passar de 24 horas.';
    }

    if ($valor === null) {
        $fields['cfg_servico_valor'] = 'Informe um valor válido. Use apenas números, vírgula ou ponto.';
    } elseif ((float)$valor <= 0) {
        $fields['cfg_servico_valor'] = 'O valor deve ser maior que zero.';
    } elseif ((float)$valor > 99999999.99) {
        $fields['cfg_servico_valor'] = 'O valor informado é muito alto.';
    }

    if (!in_array($status, ['ativo', 'inativo'], true)) {
        $fields['cfg_servico_status'] = 'Status inválido.';
    }

    if (!empty($fields)) {
        out([
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'user_msg' => 'Revise os campos destacados.',
            'fields' => $fields
        ], 422);
    }

    $stmt = $conexao->prepare("
        SELECT
            e.id_empresa,
            e.nome,
            e.status,
            eu.status AS vinculo_status,
            pf.nome AS perfil_solicitante
        FROM empresa e
        LEFT JOIN empresa_usuario eu
               ON eu.id_empresa = e.id_empresa
              AND eu.id_usuario = ?
        LEFT JOIN perfil pf ON pf.id_perfil = eu.id_perfil
        WHERE e.id_empresa = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação da empresa: ' . $conexao->error);
    }

    $stmt->bind_param('ii', $idUsuarioSessao, $idEmpresaSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação da empresa: ' . $stmt->error);
    }

    $stmt->bind_result($empresaIdDb, $empresaNomeDb, $empresaStatusDb, $vinculoStatusDb, $perfilSolicitanteDb);
    $empresaEncontrada = $stmt->fetch();
    $stmt->close();

    if (!$empresaEncontrada || (!$superAdminSuporte && $vinculoStatusDb === null)) {
        out([
            'ok' => false,
            'code' => 'USER_COMPANY_LINK_NOT_FOUND',
            'user_msg' => 'Seu usuário não possui vínculo com esta empresa.'
        ], 403);
    }

    if (lower($empresaStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INACTIVE',
            'user_msg' => 'A empresa vinculada à sessão está inativa.'
        ], 403);
    }

    if (!$superAdminSuporte && lower($vinculoStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'USER_COMPANY_LINK_INACTIVE',
            'user_msg' => 'Seu vínculo com esta empresa está inativo.'
        ], 403);
    }

    $perfilSolicitante = lower($perfilSolicitanteDb ?? '');
    if (!$superAdminSuporte && in_array($perfilSolicitante, ['recepção', 'recepcao', 'recepcionista'], true)) {
        out([
            'ok' => false,
            'code' => 'SERVICE_CREATE_ACCESS_DENIED',
            'user_msg' => 'Acesso negado. O perfil Recepcionista não pode cadastrar novos serviços.'
        ], 403);
    }

    $stmt = $conexao->prepare("
        SELECT
            p.id_profissional,
            p.id_usuario,
            p.especialidade,
            p.descricao,
            u.nome,
            u.email,
            u.telefone,
            u.status,
            eu.status AS vinculo_status,
            pf.nome AS perfil_nome
        FROM profissional p
        INNER JOIN usuario u
                ON u.id_usuario = p.id_usuario
        INNER JOIN empresa_usuario eu
                ON eu.id_usuario = u.id_usuario
               AND eu.id_empresa = ?
        LEFT JOIN perfil pf
               ON pf.id_perfil = eu.id_perfil
        WHERE p.id_profissional = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação do profissional: ' . $conexao->error);
    }

    $stmt->bind_param('ii', $idEmpresaSessao, $idProfissional);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação do profissional: ' . $stmt->error);
    }

    $stmt->bind_result(
        $profissionalIdDb,
        $profissionalUsuarioIdDb,
        $profissionalEspecialidadeDb,
        $profissionalDescricaoDb,
        $profissionalNomeDb,
        $profissionalEmailDb,
        $profissionalTelefoneDb,
        $profissionalStatusDb,
        $profissionalVinculoStatusDb,
        $profissionalPerfilNomeDb
    );

    $profissionalEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$profissionalEncontrado) {
        out([
            'ok' => false,
            'code' => 'PROFESSIONAL_NOT_FOUND_FOR_COMPANY',
            'user_msg' => 'Profissional não encontrado para a empresa da sessão.',
            'fields' => [
                'id_profissional' => 'Profissional inválido.'
            ]
        ], 404);
    }

    if (lower($profissionalStatusDb) !== 'ativo' || lower($profissionalVinculoStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'PROFESSIONAL_INACTIVE',
            'user_msg' => 'O profissional selecionado não está ativo.'
        ], 422);
    }

    $perfilProfissional = lower((string)$profissionalPerfilNomeDb);

    if ($perfilProfissional !== '' && !in_array($perfilProfissional, ['profissional', 'profissionais'], true)) {
        out([
            'ok' => false,
            'code' => 'SELECTED_USER_NOT_PROFESSIONAL',
            'user_msg' => 'O usuário selecionado não possui perfil Profissional nesta empresa.'
        ], 422);
    }

    $nomeNormalizado = lower($nome);

    $conexao->begin_transaction();
    $resultadoPlano = limitesPlanoBloquearEmpresa($conexao, $idEmpresaSessao);
    limitesPlanoAbortarSeNegado($conexao, $resultadoPlano);
    $resultadoLimiteServico = limitesPlanoVerificarServico(
        $conexao,
        $resultadoPlano['plano'],
        $idEmpresaSessao,
        $status
    );
    limitesPlanoAbortarSeNegado($conexao, $resultadoLimiteServico);

    $stmt = $conexao->prepare("
        SELECT id_servico
        FROM servico
        WHERE id_empresa = ?
          AND id_profissional = ?
          AND LOWER(nome) = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação de duplicidade do serviço: ' . $conexao->error);
    }

    $stmt->bind_param('iis', $idEmpresaSessao, $idProfissional, $nomeNormalizado);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação de duplicidade do serviço: ' . $stmt->error);
    }

    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        $conexao->rollback();

        out([
            'ok' => false,
            'code' => 'SERVICE_ALREADY_EXISTS',
            'user_msg' => 'Este profissional já possui um serviço com este nome.',
            'fields' => [
                'cfg_servico_nome' => 'Serviço já cadastrado para este profissional.'
            ]
        ], 409);
    }

    $stmt->close();

    $stmt = $conexao->prepare("
        INSERT INTO servico
            (id_empresa, id_profissional, nome, descricao, duracao_min, valor, status)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar cadastro do serviço: ' . $conexao->error);
    }

    $stmt->bind_param(
        'iissids',
        $idEmpresaSessao,
        $idProfissional,
        $nome,
        $descricao,
        $duracaoMin,
        $valorDb,
        $status
    );

    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        if ($errno === 1062) {
            $conexao->rollback();
            out([
                'ok' => false,
                'code' => 'DUPLICATE_SERVICE',
                'user_msg' => 'Este profissional já possui um serviço com este nome.',
                'fields' => [
                    'cfg_servico_nome' => 'Serviço já cadastrado para este profissional.'
                ]
            ], 409);
        }

        throw new RuntimeException('Erro ao executar cadastro do serviço: ' . $error);
    }

    $idServico = (int)$stmt->insert_id;
    $stmt->close();
    $conexao->commit();

    out([
        'ok' => true,
        'code' => 'SCHEDULE_SERVICE_CREATED',
        'user_msg' => 'Serviço cadastrado com sucesso.',
        'data' => [
            'id_servico' => $idServico,
            'id_empresa' => $idEmpresaSessao,
            'empresa_nome' => (string)$empresaNomeDb,
            'id_profissional' => $idProfissional,
            'profissional' => [
                'id_profissional' => $idProfissional,
                'id_usuario' => (int)$profissionalUsuarioIdDb,
                'nome' => (string)$profissionalNomeDb,
                'email' => (string)$profissionalEmailDb,
                'telefone' => (string)$profissionalTelefoneDb,
                'especialidade' => $profissionalEspecialidadeDb,
                'descricao' => $profissionalDescricaoDb,
                'status' => (string)$profissionalStatusDb,
            ],
            'servico' => [
                'id_servico' => $idServico,
                'id_empresa' => $idEmpresaSessao,
                'id_profissional' => $idProfissional,
                'nome' => $nome,
                'descricao' => $descricao,
                'duracao_min' => $duracaoMin,
                'valor' => $valor,
                'status' => $status,
            ]
        ]
    ], 201);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $ignorado) {
        }
    }
    error_log('[cadastrar_servico_agendamento] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao cadastrar serviço.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}
