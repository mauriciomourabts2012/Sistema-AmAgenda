<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/plano/cadastrar
 * - Insere plano na tabela `plano`
 * - Retorna JSON padrão
 */

if (!function_exists('out')) {
  function out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  out([
    'ok' => false,
    'code' => 'METHOD_NOT_ALLOWED',
    'user_msg' => 'Método não permitido.',
  ], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
date_default_timezone_set('America/Sao_Paulo');

ob_start();

function strv(string $k, int $max, bool $required = false): string {
  $v = trim((string)($_POST[$k] ?? ''));
  if ($required && $v === '') return '';
  if ($v === '') return '';
  if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
  return $v;
}
function intv(string $k, int $default = 0): int {
  $raw = $_POST[$k] ?? null;
  if ($raw === null || $raw === '') return $default;
  if (is_int($raw)) return $raw;
  $raw = trim((string)$raw);
  if ($raw === '') return $default;
  if (!preg_match('/^-?\d+$/', $raw)) return $default;
  return (int)$raw;
}

/**
 * Converte "R$ 1.234,56" ou "1234.56" para "1234.56"
 */
function moneyToDecimalString(string $raw): string {
  $raw = trim($raw);
  if ($raw === '') return '';
  $raw = preg_replace('/[^\d,\.]/', '', $raw) ?? '';
  // Se tiver vírgula e ponto, assume formato BR (1.234,56)
  if (str_contains($raw, ',') && str_contains($raw, '.')) {
    $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    return $raw;
  }
  // Se só vírgula, vira ponto
  if (str_contains($raw, ',') && !str_contains($raw, '.')) {
    $raw = str_replace(',', '.', $raw);
    return $raw;
  }
  return $raw;
}

// =====================
// ENTRADAS
// =====================
$nome = strv('nome', 80, true);
$ref  = strv('ref', 40, false);
$preco_raw = strv('preco', 20, true);
$cobranca  = strv('cobranca', 20, true);
$limite_usuarios       = intv('limite_usuarios', 0);
$limite_profissionais  = intv('limite_profissionais', 0);
$limite_servicos       = intv('limite_servicos', 0);
$limite_agendamentos   = intv('limite_agendamentos', 0);
$destaque = intv('destaque', 0);
$status   = strv('status', 20, true);
$descricao = strv('descricao', 300, false);
$obs       = strv('obs', 300, false);

// =====================
// VALIDAÇÕES
// =====================
$erros = [];

if ($nome === '') $erros['p_nome'] = 'Informe o nome do plano.';
if ($preco_raw === '') $erros['p_preco'] = 'Informe o preço mensal.';
if ($cobranca === '') $erros['p_cobranca'] = 'Selecione a cobrança.';
if ($limite_usuarios < 1) $erros['p_limite_usuarios'] = 'O limite de usuários deve ser no mínimo 1.';

$allowedCobranca = ['mensal','trimestral','semestral','anual'];
if ($cobranca !== '' && !in_array($cobranca, $allowedCobranca, true)) {
  $erros['p_cobranca'] = 'Cobrança inválida.';
}

$allowedStatus = ['ativo','inativo','bloqueado'];
if ($status !== '' && !in_array($status, $allowedStatus, true)) {
  $erros['p_status'] = 'Status inválido.';
}

if ($destaque !== 0 && $destaque !== 1) {
  $erros['p_destaque'] = 'Valor de destaque inválido.';
}

if ($limite_profissionais < 0) $erros['p_limite_profissionais'] = 'Não pode ser negativo.';
if ($limite_servicos < 0) $erros['p_limite_servicos'] = 'Não pode ser negativo.';
if ($limite_agendamentos < 0) $erros['p_limite_agendamentos'] = 'Não pode ser negativo.';

// Preço
$preco_str = moneyToDecimalString($preco_raw);
if ($preco_str === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $preco_str)) {
  $erros['p_preco'] = 'Preço inválido. Ex: 49,90';
}

if (!empty($erros)) {
  out([
    'ok' => false,
    'code' => 'VALIDATION_ERROR',
    'user_msg' => 'Revise os campos destacados.',
    'fields' => $erros,
  ], 422);
}

// =====================
// DB
// =====================
require __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';
if (!isset($conexao) || !($conexao instanceof mysqli)) {
  out(['ok'=>false,'code'=>'DB_CONN_MISSING','user_msg'=>'Conexão com banco não encontrada.'], 500);
}
if ($conexao->connect_errno) {
  out(['ok'=>false,'code'=>'DB_CONN_ERROR','user_msg'=>'Falha ao conectar no banco.'], 500);
}
$conexao->set_charset('utf8mb4');

try {
  // Normalizações
  $nome = trim($nome);
  $ref = ($ref === '') ? null : $ref;
  $descricao = ($descricao === '') ? null : $descricao;
  $obs = ($obs === '') ? null : $obs;

  // Duplicidade por nome/ref (fica amigável pro front)
  // Nome
  $sqlCheckNome = "SELECT id_plano FROM plano WHERE nome = ? LIMIT 1";
  $st = $conexao->prepare($sqlCheckNome);
  if (!$st) throw new Exception('Prepare check nome falhou.');
  $st->bind_param('s', $nome);
  $st->execute();
  $st->store_result();
  if ($st->num_rows > 0) {
    $st->close();
    out([
      'ok' => false,
      'code' => 'DUPLICATE_NOME',
      'user_msg' => 'Já existe um plano com esse nome.',
      'fields' => ['p_nome' => 'Nome já cadastrado.'],
    ], 409);
  }
  $st->close();

  // Ref (só se veio)
  if ($ref !== null) {
    $sqlCheckRef = "SELECT id_plano FROM plano WHERE ref = ? LIMIT 1";
    $st = $conexao->prepare($sqlCheckRef);
    if (!$st) throw new Exception('Prepare check ref falhou.');
    $st->bind_param('s', $ref);
    $st->execute();
    $st->store_result();
    if ($st->num_rows > 0) {
      $st->close();
      out([
        'ok' => false,
        'code' => 'DUPLICATE_REF',
        'user_msg' => 'Já existe um plano com esse código (ref).',
        'fields' => ['p_ref' => 'Código (ref) já cadastrado.'],
      ], 409);
    }
    $st->close();
  }

  $conexao->begin_transaction();

  // Insert
  $sql = "
    INSERT INTO plano (
      nome, ref, preco_mensal, cobranca,
      limite_usuarios, limite_profissionais, limite_servicos, limite_agendamentos,
      destaque, status, descricao, observacao
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
  ";

  $stmt = $conexao->prepare($sql);
  if (!$stmt) throw new Exception('Prepare insert falhou.');

  $preco = (float)$preco_str;
  $stmt->bind_param(
    'ssdssssiiiss',
    $nome,
    $ref,
    $preco,
    $cobranca,
    $limite_usuarios,
    $limite_profissionais,
    $limite_servicos,
    $limite_agendamentos,
    $destaque,
    $status,
    $descricao,
    $obs
  );

  // ⚠️ bind types acima: usamos s/d/i, mas limites são int -> melhor bind como i.
  // Como o mysqli exige string de tipos correta, vamos ajustar corretamente aqui:
  $stmt->close();

  $stmt = $conexao->prepare($sql);
  if (!$stmt) throw new Exception('Prepare insert falhou.');

  // tipos corretos:
  // nome(s) ref(s) preco(d) cobranca(s)
  // limites(i i i i)
  // destaque(i) status(s) descricao(s) obs(s)
  $stmt->bind_param(
    'ssdsiiiiisss',
    $nome,
    $ref,
    $preco,
    $cobranca,
    $limite_usuarios,
    $limite_profissionais,
    $limite_servicos,
    $limite_agendamentos,
    $destaque,
    $status,
    $descricao,
    $obs
  );

  $ok = $stmt->execute();
  if (!$ok) {
    $errno = (int)$stmt->errno;
    // 1062 = duplicate key
    if ($errno === 1062) {
      out([
        'ok' => false,
        'code' => 'DUPLICATE_KEY',
        'user_msg' => 'Plano duplicado (nome/ref).',
      ], 409);
    }
    out([
      'ok' => false,
      'code' => 'DB_INSERT_ERROR',
      'user_msg' => 'Não foi possível cadastrar o plano.',
      'debug' => ['errno' => $errno],
    ], 500);
  }

  $id = (int)$stmt->insert_id;
  $stmt->close();

  auditoriaRegistrar($conexao, 'plano.criado', [
    'ator' => auditoriaResolverAtorSuperAdmin($conexao),
    'entidade_id' => $id, 'entidade_rotulo' => $nome,
    'descricao' => 'Criou o plano ' . $nome . '.',
    'alteracoes' => ['depois' => ['antes'=>null,'depois'=>[
      'nome'=>$nome,'ref'=>$ref,'preco_mensal'=>number_format($preco,2,'.',''),'cobranca'=>$cobranca,
      'limite_usuarios'=>$limite_usuarios,'limite_profissionais'=>$limite_profissionais,
      'limite_servicos'=>$limite_servicos,'limite_agendamentos'=>$limite_agendamentos,
      'destaque'=>$destaque,'status'=>$status,'descricao'=>$descricao,'observacao'=>$obs,
    ]]],
    'contexto' => ['origem'=>'painel_super_admin'],
  ]);
  $conexao->commit();

  out([
    'ok' => true,
    'code' => 'CREATED',
    'user_msg' => 'Plano cadastrado com sucesso.',
    'data' => [
      'id_plano' => $id,
      'nome' => $nome,
      'ref' => $ref,
      'preco_mensal' => number_format($preco, 2, '.', ''),
      'cobranca' => $cobranca,
      'limite_usuarios' => $limite_usuarios,
      'limite_profissionais' => $limite_profissionais,
      'limite_servicos' => $limite_servicos,
      'limite_agendamentos' => $limite_agendamentos,
      'destaque' => $destaque,
      'status' => $status,
    ],
  ], 201);

} catch (Throwable $e) {
  if (isset($conexao) && $conexao instanceof mysqli) { try { $conexao->rollback(); } catch (Throwable) {} }
  out([
    'ok' => false,
    'code' => 'SERVER_ERROR',
    'user_msg' => 'Erro interno ao cadastrar o plano.',
  ], 500);
}
