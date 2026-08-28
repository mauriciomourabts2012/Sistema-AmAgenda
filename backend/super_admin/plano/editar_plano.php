<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/plano/editar
 * - Atualiza plano na tabela `plano`
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

  if (str_contains($raw, ',') && str_contains($raw, '.')) {
    $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    return $raw;
  }

  if (str_contains($raw, ',') && !str_contains($raw, '.')) {
    $raw = str_replace(',', '.', $raw);
    return $raw;
  }

  return $raw;
}

// =====================
// ENTRADAS
// =====================
$id_plano = intv('id_plano', 0);

$nome = strv('nome', 80, true);
$ref  = strv('ref', 40, false);
$preco_raw = strv('preco', 20, true);
$cobranca  = strv('cobranca', 20, true);

$limite_usuarios      = intv('limite_usuarios', 0);
$limite_profissionais = intv('limite_profissionais', 0);
$limite_servicos      = intv('limite_servicos', 0);
$limite_agendamentos  = intv('limite_agendamentos', 0);

$destaque = intv('destaque', 0);
$status   = strv('status', 20, true);

$descricao = strv('descricao', 300, false);
$obs       = strv('obs', 300, false);

// =====================
// VALIDAÇÕES
// =====================
$erros = [];

if ($id_plano < 1) $erros['e_plano_id'] = 'Plano inválido.';
if ($nome === '') $erros['e_nome'] = 'Informe o nome do plano.';
if ($preco_raw === '') $erros['e_preco'] = 'Informe o preço mensal.';
if ($cobranca === '') $erros['e_cobranca'] = 'Selecione a cobrança.';
if ($limite_usuarios < 1) $erros['e_limite_usuarios'] = 'O limite de usuários deve ser no mínimo 1.';

$allowedCobranca = ['mensal','trimestral','semestral','anual'];
if ($cobranca !== '' && !in_array($cobranca, $allowedCobranca, true)) {
  $erros['e_cobranca'] = 'Cobrança inválida.';
}

$allowedStatus = ['ativo','inativo','bloqueado'];
if ($status !== '' && !in_array($status, $allowedStatus, true)) {
  $erros['e_status'] = 'Status inválido.';
}

if ($destaque !== 0 && $destaque !== 1) {
  $erros['e_destaque'] = 'Valor de destaque inválido.';
}

if ($limite_profissionais < 0) $erros['e_limite_profissionais'] = 'Não pode ser negativo.';
if ($limite_servicos < 0) $erros['e_limite_servicos'] = 'Não pode ser negativo.';
if ($limite_agendamentos < 0) $erros['e_limite_agendamentos'] = 'Não pode ser negativo.';

// Preço
$preco_str = moneyToDecimalString($preco_raw);
if ($preco_str === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $preco_str)) {
  $erros['e_preco'] = 'Preço inválido. Ex: 49,90';
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
  out([
    'ok' => false,
    'code' => 'DB_CONN_MISSING',
    'user_msg' => 'Conexão com banco não encontrada.'
  ], 500);
}

if ($conexao->connect_errno) {
  out([
    'ok' => false,
    'code' => 'DB_CONN_ERROR',
    'user_msg' => 'Falha ao conectar no banco.'
  ], 500);
}

$conexao->set_charset('utf8mb4');

try {
  // Normalizações
  $nome = trim($nome);
  $ref = ($ref === '') ? null : $ref;
  $descricao = ($descricao === '') ? null : $descricao;
  $obs = ($obs === '') ? null : $obs;
  $preco = (float)$preco_str;

  // Verifica se plano existe
  $sqlExiste = "SELECT nome,ref,preco_mensal,cobranca,limite_usuarios,limite_profissionais,limite_servicos,limite_agendamentos,destaque,status,descricao,observacao FROM plano WHERE id_plano = ? LIMIT 1";
  $st = $conexao->prepare($sqlExiste);
  if (!$st) throw new Exception('Prepare check plano falhou.');

  $st->bind_param('i', $id_plano);
  $st->execute();
  $resultadoAnterior = $st->get_result();
  $planoAnterior = $resultadoAnterior ? $resultadoAnterior->fetch_assoc() : null;

  if (!$planoAnterior) {
    $st->close();
    out([
      'ok' => false,
      'code' => 'NOT_FOUND',
      'user_msg' => 'Plano não encontrado.',
    ], 404);
  }
  $st->close();

  // Duplicidade por nome (exceto o próprio ID)
  $sqlCheckNome = "SELECT id_plano FROM plano WHERE nome = ? AND id_plano <> ? LIMIT 1";
  $st = $conexao->prepare($sqlCheckNome);
  if (!$st) throw new Exception('Prepare check nome falhou.');

  $st->bind_param('si', $nome, $id_plano);
  $st->execute();
  $st->store_result();

  if ($st->num_rows > 0) {
    $st->close();
    out([
      'ok' => false,
      'code' => 'DUPLICATE_NOME',
      'user_msg' => 'Já existe outro plano com esse nome.',
      'fields' => ['e_nome' => 'Nome já cadastrado.'],
    ], 409);
  }
  $st->close();

  // Duplicidade por ref (exceto o próprio ID)
  if ($ref !== null) {
    $sqlCheckRef = "SELECT id_plano FROM plano WHERE ref = ? AND id_plano <> ? LIMIT 1";
    $st = $conexao->prepare($sqlCheckRef);
    if (!$st) throw new Exception('Prepare check ref falhou.');

    $st->bind_param('si', $ref, $id_plano);
    $st->execute();
    $st->store_result();

    if ($st->num_rows > 0) {
      $st->close();
      out([
        'ok' => false,
        'code' => 'DUPLICATE_REF',
        'user_msg' => 'Já existe outro plano com esse código (ref).',
        'fields' => ['e_ref' => 'Código (ref) já cadastrado.'],
      ], 409);
    }
    $st->close();
  }

  $conexao->begin_transaction();

  // UPDATE
  $sql = "
    UPDATE plano
       SET nome = ?,
           ref = ?,
           preco_mensal = ?,
           cobranca = ?,
           limite_usuarios = ?,
           limite_profissionais = ?,
           limite_servicos = ?,
           limite_agendamentos = ?,
           destaque = ?,
           status = ?,
           descricao = ?,
           observacao = ?
     WHERE id_plano = ?
     LIMIT 1
  ";

  $stmt = $conexao->prepare($sql);
  if (!$stmt) throw new Exception('Prepare update falhou.');

  $stmt->bind_param(
    'ssdsiiiiisssi',
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
    $obs,
    $id_plano
  );

  $ok = $stmt->execute();
  if (!$ok) {
    $errno = (int)$stmt->errno;

    if ($errno === 1062) {
      $stmt->close();
      out([
        'ok' => false,
        'code' => 'DUPLICATE_KEY',
        'user_msg' => 'Plano duplicado (nome/ref).',
      ], 409);
    }

    $stmt->close();
    out([
      'ok' => false,
      'code' => 'DB_UPDATE_ERROR',
      'user_msg' => 'Não foi possível atualizar o plano.',
      'debug' => ['errno' => $errno],
    ], 500);
  }

  $stmt->close();

  $depois = ['nome'=>$nome,'ref'=>$ref,'preco_mensal'=>number_format($preco,2,'.',''),'cobranca'=>$cobranca,'limite_usuarios'=>$limite_usuarios,'limite_profissionais'=>$limite_profissionais,'limite_servicos'=>$limite_servicos,'limite_agendamentos'=>$limite_agendamentos,'destaque'=>$destaque,'status'=>$status,'descricao'=>$descricao,'observacao'=>$obs];
  $antes = ['nome'=>$planoAnterior['nome'],'ref'=>$planoAnterior['ref'],'preco_mensal'=>number_format((float)$planoAnterior['preco_mensal'],2,'.',''),'cobranca'=>$planoAnterior['cobranca'],'limite_usuarios'=>(int)$planoAnterior['limite_usuarios'],'limite_profissionais'=>(int)$planoAnterior['limite_profissionais'],'limite_servicos'=>(int)$planoAnterior['limite_servicos'],'limite_agendamentos'=>(int)$planoAnterior['limite_agendamentos'],'destaque'=>(int)$planoAnterior['destaque'],'status'=>$planoAnterior['status'],'descricao'=>$planoAnterior['descricao'],'observacao'=>$planoAnterior['observacao']];
  $alteracoes = [];
  foreach ($depois as $campo=>$valor) if (!auditoriaValoresIguais($antes[$campo] ?? null,$valor)) $alteracoes[$campo]=['antes'=>$antes[$campo] ?? null,'depois'=>$valor];
  if ($alteracoes !== []) auditoriaRegistrar($conexao, 'plano.editado', [
    'ator'=>auditoriaResolverAtorSuperAdmin($conexao),'entidade_id'=>$id_plano,'entidade_rotulo'=>$nome,
    'descricao'=>'Alterou o plano ' . $nome . '.','alteracoes'=>$alteracoes,
    'contexto'=>['origem'=>'painel_super_admin'],
  ]);
  $conexao->commit();

  out([
    'ok' => true,
    'code' => 'UPDATED',
    'user_msg' => 'Plano atualizado com sucesso.',
    'data' => [
      'id_plano' => $id_plano,
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
      'descricao' => $descricao,
      'obs' => $obs,
    ],
  ], 200);

} catch (Throwable $e) {
  if (isset($conexao) && $conexao instanceof mysqli) { try { $conexao->rollback(); } catch (Throwable) {} }
  out([
    'ok' => false,
    'code' => 'SERVER_ERROR',
    'user_msg' => 'Erro interno ao atualizar o plano.',
  ], 500);
}
