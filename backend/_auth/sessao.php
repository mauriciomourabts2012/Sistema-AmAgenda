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

$clienteAuth = is_array($_SESSION['cliente_auth'] ?? null)
  ? $_SESSION['cliente_auth']
  : [];

if ($clienteAuth) {
  $clienteIdRaw = $clienteAuth['id_cliente'] ?? null;
  $clienteId = is_numeric($clienteIdRaw) && (int)$clienteIdRaw > 0
    ? (int)$clienteIdRaw
    : null;
  $clienteEmpresaId = (int)($clienteAuth['id_empresa'] ?? 0);
  $empresaSessaoId = (int)($_SESSION['empresa_id'] ?? 0);
  $clienteTelefone = (string)($clienteAuth['telefone'] ?? '');
  $clienteTipo = (string)($clienteAuth['tipo_usuario'] ?? $clienteAuth['tipo'] ?? '');
  $clienteStatus = (string)($clienteAuth['status'] ?? '');
  $modoVisualizacao = ($clienteAuth['modo_visualizacao'] ?? false) === true;
  $telefoneVerificado = ($clienteAuth['telefone_verificado'] ?? false) === true;
  $cadastroCompleto = ($clienteAuth['cadastro_completo'] ?? false) === true;
  $nomeCompleto = (string)($clienteAuth['nome_completo'] ?? '');

  if (
    $clienteEmpresaId > 0
    && $clienteEmpresaId === $empresaSessaoId
    && preg_match('/^\+55\d{11}$/', $clienteTelefone) === 1
    && $telefoneVerificado
    && (!$cadastroCompleto || $clienteId !== null)
    && $clienteTipo === 'cliente'
    && $clienteStatus === 'ativo'
    && $modoVisualizacao
  ) {
    out([
      'ok' => true,
      'code' => 'CLIENT_AUTHENTICATED',
      'data' => [
        'user' => [
          'id_cliente' => $clienteId,
          'id_empresa' => $clienteEmpresaId,
          'empresa_id' => $clienteEmpresaId,
          'telefone' => $clienteTelefone,
          'telefone_verificado' => true,
          'cadastro_completo' => $cadastroCompleto,
          'nome_completo' => $nomeCompleto,
          'perfil' => 'cliente',
          'perfil_nome' => 'cliente',
          'tipo_usuario' => 'cliente',
          'status' => 'ativo',
          'modo_visualizacao' => true,
        ]
      ]
    ]);
  }

  unset($_SESSION['cliente_auth']);
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

  $stmt = $conexao->prepare("SELECT eu.id_perfil, p.nome, eu.bloqueado_plano FROM empresa_usuario eu INNER JOIN perfil p ON p.id_perfil = eu.id_perfil INNER JOIN empresa e ON e.id_empresa = eu.id_empresa WHERE eu.id_usuario = ? AND eu.id_empresa = ? AND eu.status = 'ativo' AND p.status = 'ativo' AND e.status = 'ativo' LIMIT 1");
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

  // Permissões não podem manter ativa uma sessão bloqueada pelo plano.
  if ((int)($vinculo['bloqueado_plano'] ?? 0) === 1) {
    out(['ok' => false, 'code' => 'SESSION_ACCESS_DENIED', 'user_msg' => 'Acesso indisponível para o plano atual.'], 403);
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

$stmt = $conexao->prepare(
  "SELECT deve_alterar_senha,
          (deve_alterar_senha = 1
           AND data_senha_temporaria IS NOT NULL
           AND CURRENT_TIMESTAMP >= DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR)) AS senha_temporaria_vencida
     FROM usuario
    WHERE id_usuario = ? AND status = 'ativo'
    LIMIT 1"
);
if (!$stmt) {
  out(['ok' => false, 'code' => 'SESSION_USER_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
}
$stmt->bind_param('i', $idUsuario);
if (!$stmt->execute()) {
  $stmt->close();
  out(['ok' => false, 'code' => 'SESSION_USER_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
}
$resultadoUsuario = $stmt->get_result();
$usuarioAtual = $resultadoUsuario ? ($resultadoUsuario->fetch_assoc() ?: null) : null;
$stmt->close();

if (!$usuarioAtual) {
  out(['ok' => false, 'code' => 'SESSION_USER_INACTIVE', 'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'], 403);
}

$deveAlterarSenha = (int)($usuarioAtual['deve_alterar_senha'] ?? 0) === 1;
$senhaTemporariaVencida = (int)($usuarioAtual['senha_temporaria_vencida'] ?? 0) === 1;
$_SESSION['auth']['deve_alterar_senha'] = $deveAlterarSenha;
$_SESSION['auth']['senha_temporaria_vencida'] = $senhaTemporariaVencida;
$auth = $_SESSION['auth'];

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
      'deve_alterar_senha' => $deveAlterarSenha,
      'senha_temporaria_vencida' => $senhaTemporariaVencida,
      'permissoes'    => $permissoesEfetivas,
    ]
  ]
]);
