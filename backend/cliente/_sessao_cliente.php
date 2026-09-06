<?php
declare(strict_types=1);

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function exigirSessaoCliente(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = is_array($_SESSION['cliente_auth'] ?? null)
        ? $_SESSION['cliente_auth']
        : [];

    $idEmpresa = (int)($auth['id_empresa'] ?? 0);
    $empresaSessao = (int)($_SESSION['empresa_id'] ?? 0);
    $idClienteRaw = $auth['id_cliente'] ?? null;
    $idCliente = is_numeric($idClienteRaw) && (int)$idClienteRaw > 0
        ? (int)$idClienteRaw
        : null;
    $telefone = trim((string)($auth['telefone'] ?? ''));
    $tipo = (string)($auth['tipo_usuario'] ?? $auth['tipo'] ?? '');

    if (
        $idEmpresa <= 0
        || $idEmpresa !== $empresaSessao
        || $tipo !== 'cliente'
        || ($auth['telefone_verificado'] ?? false) !== true
        || ($auth['status'] ?? '') !== 'ativo'
        || preg_match('/^\+55\d{11}$/', $telefone) !== 1
    ) {
        unset($_SESSION['cliente_auth']);
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.',
        ], 401);
    }

    return [
        'id_empresa' => $idEmpresa,
        'id_cliente' => $idCliente,
        'telefone' => $telefone,
    ];
}

function buscarClienteDaSessao(mysqli $conexao, array $sessao): ?array
{
    $idEmpresa = (int)$sessao['id_empresa'];
    $idCliente = $sessao['id_cliente'];
    $telefone = (string)$sessao['telefone'];

    if ($idCliente !== null) {
        $stmt = $conexao->prepare(
            "SELECT * FROM cliente WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo' LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a consulta do cliente.');
        }
        $stmt->bind_param('ii', $idCliente, $idEmpresa);
    } else {
        $digitos = preg_replace('/\D+/', '', $telefone) ?? '';
        $semPais = str_starts_with($digitos, '55') ? substr($digitos, 2) : $digitos;
        $stmt = $conexao->prepare(
            "SELECT *
               FROM cliente
              WHERE id_empresa = ?
                AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp_celular, '+', ''), '(', ''), ')', ''), '-', ''), ' ', '') IN (?, ?)
                AND status = 'ativo'
              LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a consulta do cliente.');
        }
        $stmt->bind_param('iss', $idEmpresa, $digitos, $semPais);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Não foi possível consultar o cliente.');
    }

    $resultado = $stmt->get_result();
    $cliente = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    $stmt->close();

    return $cliente;
}

