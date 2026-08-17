<?php
declare(strict_types=1);

/**
 * lista_plano.php — SUPERADMIN
 * REGRA:
 * - Se NÃO vier data nem status → retorna SOMENTE ativos
 * - Se vier data → aplica período
 * - Se vier status → aplica status
 * - Paginação inclusa
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
  function out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405);
}

// 🔒 bloqueio
$bloqueio = __DIR__ . '/../../_auth/bloquear.php';
if (is_file($bloqueio)) {
  require $bloqueio;
}

// DB
require __DIR__ . '/../../_config/conexao.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
  out(['ok' => false, 'code' => 'DB_CONN_MISSING'], 500);
}

$conexao->set_charset('utf8mb4');

// ==========================================================
// HELPERS
// ==========================================================
function s(?string $v): ?string {
  if ($v === null) return null;
  $v = trim($v);
  return $v === '' ? null : $v;
}

function clampInt($v, int $min, int $max, int $fallback): int {
  $n = filter_var($v, FILTER_VALIDATE_INT);
  if ($n === false) return $fallback;
  if ($n < $min) return $min;
  if ($n > $max) return $max;
  return $n;
}

function parseDate(?string $d): ?string {
  $d = s($d);
  if (!$d) return null;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  if (!$dt || $dt->format('Y-m-d') !== $d) return null;
  return $d;
}

function bindParams(mysqli_stmt $stmt, string $types, array $values): void {
  $refs = [];
  $refs[] = $types;
  foreach ($values as $k => $v) {
    $refs[] = &$values[$k];
  }
  call_user_func_array([$stmt, 'bind_param'], $refs);
}

// ==========================================================
// PARAMETROS
// ==========================================================
$status = s($_GET['status'] ?? null);
$inicio = s($_GET['inicio'] ?? null);
$fim    = s($_GET['fim'] ?? null);

$page   = clampInt($_GET['page'] ?? 1, 1, 999999, 1);
$limit  = clampInt($_GET['limit'] ?? 10, 1, 50, 10);
$offset = ($page - 1) * $limit;

$data_inicio = parseDate($inicio);
$data_fim    = parseDate($fim);

// valida status
if ($status !== null && !in_array($status, ['ativo', 'inativo', 'bloqueado'], true)) {
  out(['ok' => false, 'code' => 'INVALID_STATUS'], 400);
}

// ✅ robustez: se vier só uma ponta do período, usa a outra igual
if ($data_inicio && !$data_fim) $data_fim = $data_inicio;
if ($data_fim && !$data_inicio) $data_inicio = $data_fim;

// ==========================================================
// WHERE DINÂMICO
// ==========================================================
$where  = [];
$types  = '';
$params = [];

// 🔥 REGRA PRINCIPAL
if (!$data_inicio && !$data_fim && !$status) {
  // Se não veio nada → SOMENTE ATIVOS
  $where[] = "p.status = 'ativo'";
} else {

  if ($data_inicio && $data_fim) {
    $where[] = "p.criado_em BETWEEN ? AND ?";
    $params[] = $data_inicio . ' 00:00:00';
    $params[] = $data_fim . ' 23:59:59';
    $types .= 'ss';
  }

  if ($status) {
    $where[] = "p.status = ?";
    $params[] = $status;
    $types .= 's';
  }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ==========================================================
// TOTAL
// ==========================================================
$sqlTotal = "SELECT COUNT(*) AS total FROM plano p {$whereSql}";
$stmtT = $conexao->prepare($sqlTotal);

if (!$stmtT) {
  out(['ok' => false, 'code' => 'SQL_TOTAL_FAIL'], 500);
}

if ($params) {
  bindParams($stmtT, $types, $params);
}

$stmtT->execute();
$resT = $stmtT->get_result();
$rowT = $resT->fetch_assoc();
$total = (int)($rowT['total'] ?? 0);
$stmtT->close();

// ==========================================================
// LISTA
// ==========================================================
$sql = "
SELECT
  p.id_plano,
  p.nome,
  p.ref,
  p.preco_mensal,
  p.cobranca,
  p.limite_usuarios,
  p.limite_profissionais,
  p.limite_servicos,
  p.limite_agendamentos,
  p.destaque,
  p.status,
  p.descricao,
  p.observacao,
  DATE_FORMAT(p.criado_em, '%Y-%m-%d') AS criado_em,
  p.atualizado_em
FROM plano p
{$whereSql}
ORDER BY p.nome ASC
LIMIT ? OFFSET ?
";

$stmt = $conexao->prepare($sql);
if (!$stmt) out(['ok' => false, 'code' => 'SQL_LIST_FAIL'], 500);

$paramsList = $params;
$paramsList[] = $limit;
$paramsList[] = $offset;

$typesList = $types . 'ii';
bindParams($stmt, $typesList, $paramsList);

$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
  $row['id_plano'] = (int)$row['id_plano'];
  $row['preco_mensal'] = (string)$row['preco_mensal'];
  $row['destaque'] = (int)$row['destaque'];
  $data[] = $row;
}
$stmt->close();

out([
  'ok' => true,
  'meta' => [
    'page' => $page,
    'limit' => $limit,
    'total' => $total,
    'pages' => (int)ceil($total / max(1, $limit)),
  ],
  // ✅ o JS vai ler daqui
  'filtros' => [
    'status' => $status,
    'inicio' => $data_inicio,
    'fim' => $data_fim
  ],
  'data' => $data
]);