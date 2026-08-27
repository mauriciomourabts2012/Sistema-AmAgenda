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
    require_once __DIR__ . '/../../_regras/limites_plano.php';
    require_once __DIR__ . '/../../_servicos/auditoria.php';

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

    $tipoUsuario = lower($auth['tipo_usuario'] ?? '');
    $modoSuporte = ($auth['modo_suporte'] ?? false) === true || (int)($auth['modo_suporte'] ?? 0) === 1;
    if ($tipoUsuario === 'super_admin') {
        if (!$modoSuporte) out(['ok'=>false,'code'=>'SUPPORT_COMPANY_REQUIRED','user_msg'=>'Acesse uma empresa em modo suporte antes de administrar os serviços.'],403);
    } else {
        $stmt=$conexao->prepare("SELECT pf.nome FROM empresa_usuario eu INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' AND pf.status='ativo' LIMIT 1");
        $stmt->bind_param('ii',$idEmpresaSessao,$idUsuarioSessao); $stmt->execute(); $stmt->bind_result($perfilSessao); $vinculoOk=$stmt->fetch(); $stmt->close();
        if (!$vinculoOk || !in_array(lower($perfilSessao),['proprietário','proprietario'],true)) out(['ok'=>false,'code'=>'ACCESS_DENIED','user_msg'=>'Você não possui permissão para cadastrar serviços para este profissional.'],403);
    }

    $idProfissional = filter_input(INPUT_POST,'id_profissional',FILTER_VALIDATE_INT)
        ?: (is_numeric($_POST['id_profissional'] ?? null) ? (int)$_POST['id_profissional'] : 0);
    if ($idProfissional<=0) out(['ok'=>false,'code'=>'PROFESSIONAL_REQUIRED','user_msg'=>'Selecione um profissional para continuar.'],422);
    $stmt=$conexao->prepare("SELECT p.id_profissional,u.nome FROM profissional p INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario=p.id_usuario WHERE p.id_profissional=? AND eu.id_empresa=? AND u.status='ativo' AND eu.status='ativo' LIMIT 1");
    $stmt->bind_param('ii',$idProfissional,$idEmpresaSessao); $stmt->execute(); $stmt->bind_result($profissionalIdDb,$profissionalNome); $profissionalOk=$stmt->fetch(); $stmt->close();
    if (!$profissionalOk) out(['ok'=>false,'code'=>'PROFESSIONAL_ACCESS_DENIED','user_msg'=>'O profissional selecionado não está ativo ou não pertence à empresa acessada.'],403);
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

    $stmt->bind_param("iis", $idEmpresaSessao, $idProfissional, $nomeNormalizado);

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
            $conexao->rollback();
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
    auditoriaRegistrar($conexao,'servico.criado',['entidade_id'=>$idServico,'entidade_rotulo'=>$nome,'descricao'=>'Criou o serviço '.$nome.'.','alteracoes'=>['nome'=>['antes'=>null,'depois'=>$nome],'descricao'=>['antes'=>null,'depois'=>$descricao],'profissional'=>['antes'=>null,'depois'=>['id'=>$idProfissional,'rotulo'=>$profissionalNome]],'duracao_min'=>['antes'=>null,'depois'=>$duracaoMin],'valor'=>['antes'=>null,'depois'=>$valorDb],'status'=>['antes'=>null,'depois'=>$status],'origem'=>['antes'=>null,'depois'=>'configuracao_servicos']],'contexto'=>['origem'=>'configuracao_servicos']]);
    $conexao->commit();

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
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $ignorado) {
        }
    }
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
