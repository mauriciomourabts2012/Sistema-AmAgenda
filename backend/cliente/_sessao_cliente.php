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
    $email = mb_strtolower(trim((string)($auth['email'] ?? '')), 'UTF-8');
    $tipo = (string)($auth['tipo_usuario'] ?? $auth['tipo'] ?? '');

    // C3.1: a sessão vale quando ao menos UM contato foi comprovado neste login (celular por SMS/senha
    // ou e-mail por código/senha). Contas só com e-mail não têm telefone na sessão.
    $telefoneComprovado = ($auth['telefone_verificado'] ?? false) === true
        && preg_match('/^\+55\d{11}$/', $telefone) === 1;
    $emailComprovado = ($auth['email_verificado'] ?? false) === true
        && mb_strlen($email, 'UTF-8') <= 160
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

    if (
        $idEmpresa <= 0
        || $idEmpresa !== $empresaSessao
        || $tipo !== 'cliente'
        || ($auth['status'] ?? '') !== 'ativo'
        || (!$telefoneComprovado && !$emailComprovado)
    ) {
        unset($_SESSION['cliente_auth']);
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.',
        ], 401);
    }

    // A6.1: a sessão precisa corresponder à credencial atual do cliente.
    require_once __DIR__ . '/../_regras/login_multiempresa.php';
    $conexaoSessaoCliente = null;
    if ($idCliente !== null) {
        $conexaoSessaoCliente = $GLOBALS['conexao'] ?? null;
        if (!($conexaoSessaoCliente instanceof mysqli)) {
            require __DIR__ . '/../_config/conexao.php';
            $conexaoSessaoCliente = isset($conexao) && $conexao instanceof mysqli ? $conexao : null;
        }
    }
    sessaoCredencialExigirCliente($conexaoSessaoCliente, $idEmpresa, $idCliente);

    // Conta inativa, bloqueada ou unificada em outra (secundária) não mantém sessão, mesmo sem senha
    // (o marcador da credencial só muda quando há senha). A sessão é revogada, nunca reaproveitada para
    // criar um novo cadastro. Sessão sem id_cliente (primeiro acesso, ainda sem cadastro) não passa por aqui.
    if ($idCliente !== null) {
        $linhaStatus = null;
        $stmtStatus = $conexaoSessaoCliente instanceof mysqli
            ? $conexaoSessaoCliente->prepare('SELECT status, id_cliente_unificado FROM cliente WHERE id_cliente = ? AND id_empresa = ? LIMIT 1')
            : false;
        if (!$stmtStatus) {
            out(['ok' => false, 'code' => 'CLIENT_SESSION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $stmtStatus->bind_param('ii', $idCliente, $idEmpresa);
        if (!$stmtStatus->execute()) {
            $stmtStatus->close();
            out(['ok' => false, 'code' => 'CLIENT_SESSION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $resultadoStatus = $stmtStatus->get_result();
        $linhaStatus = $resultadoStatus ? ($resultadoStatus->fetch_assoc() ?: null) : null;
        $stmtStatus->close();
        if ($linhaStatus === null || (string)$linhaStatus['status'] !== 'ativo' || $linhaStatus['id_cliente_unificado'] !== null) {
            sessaoCredencialRevogarCliente();
            sessaoCredencialResponderReautenticacao(SESSAO_LOGIN_CLIENTE_URL);
        }
    }

    // Identificador do login (telefone ou "email:<endereço>"): usado na recuperação de senha e no
    // limite de tentativas. Sessões anteriores à C3.1 só têm o telefone.
    $identificador = trim((string)($auth['identificador'] ?? ''));
    if ($identificador === '') {
        $identificador = $telefoneComprovado ? $telefone : 'email:' . $email;
    }

    return [
        'id_empresa' => $idEmpresa,
        'id_cliente' => $idCliente,
        'telefone' => $telefoneComprovado ? $telefone : '',
        'email' => $emailComprovado ? $email : '',
        'telefone_comprovado' => $telefoneComprovado,
        'email_comprovado' => $emailComprovado,
        'identificador' => $identificador,
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
    } elseif ($telefone === '') {
        // Sessão só com e-mail e ainda sem cadastro: nunca associa a um cliente existente pelo
        // e-mail (o cadastro novo nasce em salvar_dados_cadastrais.php; e-mail já usado é recusado).
        return null;
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

