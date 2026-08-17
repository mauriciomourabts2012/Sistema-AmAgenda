<?php
declare(strict_types=1);

// ✅ NÃO defina header aqui (api_central já define)
// ✅ NÃO redefina out() se já existir
if (!function_exists('out')) {
  function out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

// Sessão
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$auth = $_SESSION['auth'] ?? null;

if (!$auth || empty($auth['id_usuario'])) {
  out([
    'ok' => false,
    'code' => 'NOT_AUTHENTICATED',
    'user_msg' => 'Sessão expirada. Faça login novamente.'
  ], 401);
}

// (Opcional) Bloqueia usuário inativo mesmo com sessão antiga
$status = (string)($auth['status'] ?? '');
if ($status !== '' && $status !== 'ativo') {
  out([
    'ok' => false,
    'code' => 'SESSION_USER_INACTIVE',
    'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
  ], 403);
}

out([
  'ok' => true,
  'code' => 'AUTHENTICATED',
  'data' => [
    'user' => [
      'id_usuario'    => (int)($auth['id_usuario'] ?? 0),
      'id_empresa'    => isset($auth['id_empresa']) ? (int)$auth['id_empresa'] : 0,
      'empresa_nome'  => (string)($auth['empresa_nome'] ?? ''),
      'nome_completo' => (string)($auth['nome_completo'] ?? ''),
      'email'         => (string)($auth['email'] ?? ''),
      'telefone'      => (string)($auth['telefone'] ?? ''),
      'perfil'        => (string)($auth['perfil'] ?? ''),
      'status'        => (string)($auth['status'] ?? ''),
      'login_em'      => (string)($auth['login_em'] ?? ''),
    ]
  ]
]);