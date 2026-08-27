<?php
declare(strict_types=1);

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
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

    $idServico = (int)($_POST['id_servico'] ?? $_POST['id'] ?? 0);

    if ($idServico <= 0) {
        out([
            'ok' => false,
            'code' => 'INVALID_SERVICE_ID',
            'user_msg' => 'Serviço inválido.'
        ], 422);
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

    $normalizar=static fn(mixed $v):string=>mb_strtolower(trim((string)$v),'UTF-8');
    $tipoUsuario=$normalizar($auth['tipo_usuario']??'');
    $modoSuporte=($auth['modo_suporte']??false)===true||(int)($auth['modo_suporte']??0)===1;
    if($tipoUsuario==='super_admin'){
        if(!$modoSuporte) out(['ok'=>false,'code'=>'SUPPORT_COMPANY_REQUIRED','user_msg'=>'Acesse uma empresa em modo suporte antes de administrar os serviços.'],403);
    }else{
        $stmt=$conexao->prepare("SELECT pf.nome FROM empresa_usuario eu INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil INNER JOIN empresa e ON e.id_empresa=eu.id_empresa WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' AND pf.status='ativo' AND e.status='ativo' LIMIT 1");
        $stmt->bind_param('ii',$idEmpresaSessao,$idUsuarioSessao);$stmt->execute();$stmt->bind_result($perfilSessao);$vinculoOk=$stmt->fetch();$stmt->close();
        if(!$vinculoOk||!in_array($normalizar($perfilSessao),['proprietário','proprietario'],true)) out(['ok'=>false,'code'=>'ACCESS_DENIED','user_msg'=>'Você não possui permissão para excluir serviços deste profissional.'],403);
    }

    $idProfissionalSessao=filter_input(INPUT_POST,'id_profissional',FILTER_VALIDATE_INT)
        ?: (is_numeric($_POST['id_profissional'] ?? null) ? (int)$_POST['id_profissional'] : 0);
    if($idProfissionalSessao<=0) out(['ok'=>false,'code'=>'PROFESSIONAL_REQUIRED','user_msg'=>'Selecione um profissional para continuar.'],422);
    $stmt=$conexao->prepare("SELECT p.id_profissional FROM profissional p INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eu ON eu.id_usuario=p.id_usuario WHERE p.id_profissional=? AND eu.id_empresa=? AND u.status='ativo' AND eu.status='ativo' LIMIT 1");
    $stmt->bind_param('ii',$idProfissionalSessao,$idEmpresaSessao);$stmt->execute();$stmt->store_result();$profissionalOk=$stmt->num_rows===1;$stmt->close();
    if(!$profissionalOk) out(['ok'=>false,'code'=>'PROFESSIONAL_ACCESS_DENIED','user_msg'=>'O profissional selecionado não está ativo ou não pertence à empresa acessada.'],403);

    $conexao->begin_transaction();
    // Snapshot mínimo anterior à exclusão, isolado por empresa e profissional.
    $stmt=$conexao->prepare("SELECT s.nome,s.descricao,s.duracao_min,s.valor,s.status,u.nome FROM servico s INNER JOIN profissional p ON p.id_profissional=s.id_profissional INNER JOIN usuario u ON u.id_usuario=p.id_usuario WHERE s.id_servico=? AND s.id_empresa=? AND s.id_profissional=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('iii',$idServico,$idEmpresaSessao,$idProfissionalSessao);$stmt->execute();$stmt->bind_result($servicoNome,$servicoDescricao,$servicoDuracao,$servicoValor,$servicoStatus,$profissionalNome);$servicoEncontrado=$stmt->fetch();$stmt->close();
    if(!$servicoEncontrado){$conexao->rollback();out(['ok'=>false,'code'=>'SERVICE_NOT_FOUND','user_msg'=>'Serviço não encontrado para o profissional logado.'],404);}

    $stmt = $conexao->prepare("
        DELETE FROM servico
        WHERE id_servico = ?
          AND id_empresa = ?
          AND id_profissional = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar exclusão do serviço: ' . $conexao->error);
    }

    $stmt->bind_param('iii', $idServico, $idEmpresaSessao, $idProfissionalSessao);

    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        if ($errno === 1451) {
            $conexao->rollback();
            out([
                'ok' => false,
                'code' => 'SERVICE_IN_USE',
                'user_msg' => 'Este serviço não pode ser excluído porque já está vinculado a outros registros.'
            ], 409);
        }

        throw new RuntimeException('Erro ao executar exclusão do serviço: ' . $error);
    }

    $apagados = max(0, $stmt->affected_rows);
    $stmt->close();

    if ($apagados <= 0) {
        $conexao->rollback();
        out([
            'ok' => false,
            'code' => 'SERVICE_NOT_FOUND',
            'user_msg' => 'Serviço não encontrado para o profissional logado.'
        ], 404);
    }

    auditoriaRegistrar($conexao,'servico.excluido',['entidade_id'=>$idServico,'entidade_rotulo'=>$servicoNome,'descricao'=>'Excluiu o serviço '.$servicoNome.'.','alteracoes'=>['nome'=>['antes'=>$servicoNome,'depois'=>null],'descricao'=>['antes'=>$servicoDescricao,'depois'=>null],'profissional'=>['antes'=>['id'=>$idProfissionalSessao,'rotulo'=>$profissionalNome],'depois'=>null],'duracao_min'=>['antes'=>(int)$servicoDuracao,'depois'=>null],'valor'=>['antes'=>$servicoValor,'depois'=>null],'status'=>['antes'=>$servicoStatus,'depois'=>null],'origem'=>['antes'=>'configuracao_servicos','depois'=>null]],'contexto'=>['origem'=>'configuracao_servicos']]);
    $conexao->commit();

    out([
        'ok' => true,
        'code' => 'SERVICE_DELETED',
        'user_msg' => 'Serviço excluído com sucesso.',
        'data' => [
            'id_servico' => $idServico,
            'id_empresa' => $idEmpresaSessao,
            'id_profissional' => $idProfissionalSessao
        ]
    ], 200);

} catch (Throwable $e) {
    try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    error_log('[excluir_servico] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao excluir serviço.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}
