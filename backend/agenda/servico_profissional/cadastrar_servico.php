<?php
declare(strict_types=1);

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
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
            'user_msg' => 'Não foi possível identificar a empresa da sessão.'
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

    function s(mixed $v): string {
        return trim((string)$v);
    }

    function lower(mixed $v): string {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }

    function dinheiroParaDecimal(mixed $v): ?string {
        $raw = trim((string)$v);

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

    $nomeRaw      = s($_POST['nome'] ?? '');
    $descricaoRaw = s($_POST['descricao'] ?? '');
    $duracaoRaw   = s($_POST['duracao_min'] ?? ($_POST['duracao'] ?? ''));
    $valorRaw     = s($_POST['valor'] ?? '');
    $statusRaw    = lower($_POST['status'] ?? 'ativo');

    $nome       = $nomeRaw;
    $descricao  = $descricaoRaw !== '' ? $descricaoRaw : null;
    $duracaoMin = (int)$duracaoRaw;
    $valor      = dinheiroParaDecimal($valorRaw);
    $valorDb    = $valor !== null ? (float)$valor : 0.00;
    $status     = $statusRaw !== '' ? $statusRaw : 'ativo';

    $fields = [];

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
        SELECT id_empresa, nome, status
        FROM empresa
        WHERE id_empresa = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação da empresa: ' . $conexao->error);
    }

    $stmt->bind_param("i", $idEmpresaSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação da empresa: ' . $stmt->error);
    }

    $stmt->bind_result($empresaIdDb, $empresaNomeDb, $empresaStatusDb);
    $empresaEncontrada = $stmt->fetch();
    $stmt->close();

    if (!$empresaEncontrada) {
        out([
            'ok' => false,
            'code' => 'EMPRESA_NOT_FOUND',
            'user_msg' => 'Empresa da sessão não encontrada.'
        ], 422);
    }

    if (lower((string)$empresaStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INACTIVE',
            'user_msg' => 'A empresa vinculada à sessão está inativa.'
        ], 403);
    }

    $stmt = $conexao->prepare("
        SELECT
            p.id_profissional,
            pf.nome AS perfil_nome,
            pf.status AS perfil_status,
            eu.status AS vinculo_status
        FROM empresa_usuario eu
        INNER JOIN perfil pf
                ON pf.id_perfil = eu.id_perfil
        LEFT JOIN profissional p
               ON p.id_usuario = eu.id_usuario
        WHERE eu.id_empresa = ?
          AND eu.id_usuario = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação do perfil profissional: ' . $conexao->error);
    }

    $stmt->bind_param("ii", $idEmpresaSessao, $idUsuarioSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação do perfil profissional: ' . $stmt->error);
    }

    $stmt->bind_result($idProfissionalDb, $perfilNomeDb, $perfilStatusDb, $vinculoStatusDb);
    $vinculoEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$vinculoEncontrado) {
        out([
            'ok' => false,
            'code' => 'USER_COMPANY_LINK_NOT_FOUND',
            'user_msg' => 'Seu usuário não possui vínculo com esta empresa.'
        ], 403);
    }

    if (lower((string)$vinculoStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'USER_COMPANY_LINK_INACTIVE',
            'user_msg' => 'Seu vínculo com esta empresa está inativo.'
        ], 403);
    }

    if (lower((string)$perfilStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'PROFILE_INACTIVE',
            'user_msg' => 'O perfil vinculado ao seu usuário está inativo.'
        ], 403);
    }

    $perfilNomeNormalizado = lower((string)$perfilNomeDb);

    if ($perfilNomeNormalizado !== 'profissional') {
        out([
            'ok' => false,
            'code' => 'USER_NOT_PROFESSIONAL_PROFILE',
            'user_msg' => 'Apenas usuários com perfil Profissional podem cadastrar serviços. Seu perfil atual é ' . (string)$perfilNomeDb . '.'
        ], 403);
    }

    if ((int)$idProfissionalDb <= 0) {
        out([
            'ok' => false,
            'code' => 'PROFESSIONAL_RECORD_NOT_FOUND',
            'user_msg' => 'Seu usuário tem perfil Profissional, mas ainda não possui cadastro profissional vinculado. Procure o administrador.'
        ], 403);
    }

    $idProfissional = (int)$idProfissionalDb;
    $nomeNormalizado = lower($nome);

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

    $stmt->bind_param("iis", $idEmpresaSessao, $idProfissional, $nomeNormalizado);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação de duplicidade do serviço: ' . $stmt->error);
    }

    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();

        out([
            'ok' => false,
            'code' => 'SERVICE_ALREADY_EXISTS',
            'user_msg' => 'Você já possui um serviço com este nome.',
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
        "iissids",
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
            out([
                'ok' => false,
                'code' => 'DUPLICATE_SERVICE',
                'user_msg' => 'Você já possui um serviço com este nome.',
                'fields' => [
                    'cfg_servico_nome' => 'Serviço já cadastrado para este profissional.'
                ]
            ], 409);
        }

        throw new RuntimeException('Erro ao executar cadastro do serviço: ' . $error);
    }

    $idServico = (int)$stmt->insert_id;
    $stmt->close();

    out([
        'ok' => true,
        'code' => 'SERVICE_CREATED',
        'user_msg' => 'Serviço cadastrado com sucesso.',
        'data' => [
            'id_servico'      => $idServico,
            'id_empresa'      => $idEmpresaSessao,
            'empresa_nome'    => (string)$empresaNomeDb,
            'id_profissional' => $idProfissional,
            'nome'            => $nome,
            'descricao'       => $descricao,
            'duracao_min'     => $duracaoMin,
            'valor'           => $valor,
            'status'          => $status
        ]
    ], 201);

} catch (Throwable $e) {
    error_log('[cadastrar_servico] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao cadastrar serviço.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}