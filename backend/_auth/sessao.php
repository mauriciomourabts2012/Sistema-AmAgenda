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

/*
 * Sincroniza o perfil da empresa com o banco em toda verificação de sessão.
 * Isso corrige sessões antigas e evita autorizações baseadas em IDs fixos.
 */
$tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
$idUsuario = (int)($auth['id_usuario'] ?? 0);
$idEmpresa = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? 0);

if ($tipoUsuario !== 'super_admin' && $idUsuario > 0 && $idEmpresa > 0) {
  require __DIR__ . '/../_config/conexao.php';

  $stmt = $conexao->prepare("SELECT eu.id_perfil, p.nome FROM empresa_usuario eu INNER JOIN perfil p ON p.id_perfil = eu.id_perfil INNER JOIN empresa e ON e.id_empresa = eu.id_empresa WHERE eu.id_usuario = ? AND eu.id_empresa = ? AND eu.status = 'ativo' AND p.status = 'ativo' AND e.status = 'ativo' LIMIT 1");
  if (!$stmt) {
    out(['ok' => false, 'code' => 'SESSION_PROFILE_CHECK_ERROR', 'user_msg' => 'Não foi possível validar o perfil da sessão.'], 500);
  }

  $stmt->bind_param('ii', $idUsuario, $idEmpresa);
  $stmt->execute();
  $res = $stmt->get_result();
  $vinculo = $res ? ($res->fetch_assoc() ?: null) : null;
  $stmt->close();

  if (!$vinculo) {
    out(['ok' => false, 'code' => 'SESSION_COMPANY_LINK_INVALID', 'user_msg' => 'Seu vínculo com a empresa não está ativo. Faça login novamente.'], 403);
  }

  $perfilNomeDb = mb_strtolower(trim((string)$vinculo['nome']), 'UTF-8');
  $perfilNome = match ($perfilNomeDb) {
    'proprietário', 'proprietario' => 'proprietario',
    'profissional' => 'profissional',
    'recepção', 'recepcao', 'recepcionista' => 'recepcionista',
    default => $perfilNomeDb,
  };

  $_SESSION['perfil_id'] = (int)$vinculo['id_perfil'];
  $_SESSION['perfil_nome'] = $perfilNome;
  $_SESSION['auth']['perfil_id'] = (int)$vinculo['id_perfil'];
  $_SESSION['auth']['perfil_nome'] = $perfilNome;
  $auth = $_SESSION['auth'];
}

if (!isset($conexao) || !($conexao instanceof mysqli)) require __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_regras/permissoes_usuario.php';
$permissoesEfetivas = obterPermissoesEfetivas($conexao);

out([
  'ok' => true,
  'code' => 'AUTHENTICATED',
  'data' => [
    'user' => [
      'id_usuario'    => (int)($auth['id_usuario'] ?? 0),
      'id_empresa'    => (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? 0),
      'empresa_id'    => (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? 0),
      'empresa_nome'  => (string)($auth['empresa_nome'] ?? ''),
      'nome_completo' => (string)($auth['nome_completo'] ?? ''),
      'email'         => (string)($auth['email'] ?? ''),
      'telefone'      => (string)($auth['telefone'] ?? ''),
      'perfil'        => (string)($auth['perfil_nome'] ?? $auth['perfil'] ?? ''),
      'perfil_id'     => (int)($auth['perfil_id'] ?? 0),
      'perfil_nome'   => (string)($auth['perfil_nome'] ?? $auth['perfil'] ?? ''),
      'tipo_usuario'  => (string)($auth['tipo_usuario'] ?? ''),
      'modo_suporte'  => (bool)($auth['modo_suporte'] ?? false),
      'status'        => (string)($auth['status'] ?? ''),
      'login_em'      => (string)($auth['login_em'] ?? ''),
      'permissoes'    => $permissoesEfetivas,
    ]
  ]
]);
