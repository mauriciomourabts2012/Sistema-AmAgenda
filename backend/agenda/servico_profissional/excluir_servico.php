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

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    $idProfissionalSessao = 0;

    if (isset($auth['id_profissional'])) {
        $idProfissionalSessao = (int)$auth['id_profissional'];
    } elseif (isset($_SESSION['id_profissional'])) {
        $idProfissionalSessao = (int)$_SESSION['id_profissional'];
    } elseif (isset($_SESSION['profissional_id'])) {
        $idProfissionalSessao = (int)$_SESSION['profissional_id'];
    } elseif (isset($_SESSION['profissional']['id_profissional'])) {
        $idProfissionalSessao = (int)$_SESSION['profissional']['id_profissional'];
    }

    if ($idProfissionalSessao <= 0) {
        $stmt = $conexao->prepare("
            SELECT p.id_profissional
            FROM profissional p
            INNER JOIN empresa_usuario eu
                    ON eu.id_usuario = p.id_usuario
            WHERE p.id_usuario = ?
              AND eu.id_empresa = ?
              AND eu.status = 'ativo'
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar busca do profissional: ' . $conexao->error);
        }

        $stmt->bind_param('ii', $idUsuarioSessao, $idEmpresaSessao);

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao executar busca do profissional: ' . $stmt->error);
        }

        $stmt->bind_result($idProfissionalDb);

        if ($stmt->fetch()) {
            $idProfissionalSessao = (int)$idProfissionalDb;
        }

        $stmt->close();
    }

    if ($idProfissionalSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'PROFESSIONAL_NOT_FOUND',
            'user_msg' => 'Seu usuário não possui profissional vinculado.'
        ], 404);
    }

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
        out([
            'ok' => false,
            'code' => 'SERVICE_NOT_FOUND',
            'user_msg' => 'Serviço não encontrado para o profissional logado.'
        ], 404);
    }

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