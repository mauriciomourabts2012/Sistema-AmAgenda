<?php
declare(strict_types=1);

/**
 * ==========================================================
 * AmAgenda - Entrada de Login
 * ----------------------------------------------------------
 * REGRA:
 * 1) Sem parâmetros                         -> login super admin
 * 2) Com ?empresa=ID&nome=nome-da-empresa  -> login da empresa
 *    ou ?empresa=ID&slug=nome-da-empresa
 *
 * VALIDAÇÃO:
 * - antes de redirecionar, verifica no banco:
 *   • empresa existe
 *   • empresa está ativa
 *   • slug/nome confere com o nome real
 * - em caso de erro, redireciona para página amigável
 * ==========================================================
 */

session_start();

function go(string $url): void {
    header("Location: {$url}");
    exit;
}

function goErro(string $motivo, int $empresaId = 0, string $slug = ''): void {
    $params = ['motivo' => $motivo];

    if ($empresaId > 0) {
        $params['empresa'] = $empresaId;
    }

    if ($slug !== '') {
        $params['slug'] = $slug;
    }

    $qs = http_build_query($params);

    go('/public/views/link-empresa-invalido.html' . ($qs ? '?' . $qs : ''));
}

function clearSessaoEmpresa(): void {
    unset($_SESSION['empresa_id']);
    unset($_SESSION['empresa_nome']);
    unset($_SESSION['empresa_slug']);
    unset($_SESSION['usuario_id']);
    unset($_SESSION['usuario_nome']);
    unset($_SESSION['usuario_email']);
    unset($_SESSION['usuario_tipo']);
}

function clearSessaoSuperAdmin(): void {
    unset($_SESSION['superadmin_id']);
    unset($_SESSION['superadmin_nome']);
    unset($_SESSION['superadmin_email']);
    unset($_SESSION['super']);
}

/**
 * Gera slug seguro e consistente:
 * - remove acentos
 * - converte para minúsculo
 * - troca separadores por hífen
 * - remove hífens duplicados/início/fim
 */
function normalizeSlug(string $text): string {

    $text = trim($text);

    if ($text === '') {
        return '';
    }

    // converte para minúsculo
    $text = mb_strtolower($text, 'UTF-8');

    // remove acentos manualmente (100% confiável)
    $map = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c',
        'ñ'=>'n'
    ];

    $text = strtr($text, $map);

    // troca qualquer coisa que não seja letra ou número por hífen
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

    // remove hífen do começo e fim
    $text = trim($text, '-');

    return $text;
}

function getEmpresaId(): int {
    if (!isset($_GET['empresa'])) {
        return 0;
    }

    $raw = trim((string)$_GET['empresa']);

    if ($raw === '' || !ctype_digit($raw)) {
        goErro('LINK_INVALIDO');
    }

    $id = (int)$raw;

    if ($id <= 0) {
        goErro('LINK_INVALIDO');
    }

    return $id;
}

function getEmpresaSlug(): string {
    $raw = '';

    if (isset($_GET['slug'])) {
        $raw = (string)$_GET['slug'];
    } elseif (isset($_GET['nome'])) {
        $raw = (string)$_GET['nome'];
    } else {
        return '';
    }

    return normalizeSlug($raw);
}

$empresaId   = getEmpresaId();
$empresaSlug = getEmpresaSlug();

/* ==========================================================
   CENÁRIO 1: LOGIN SUPER ADMIN
========================================================== */
if ($empresaId === 0 && $empresaSlug === '') {
    clearSessaoEmpresa();
    clearSessaoSuperAdmin();
    go('/public/views/login-superadmin.html');
}

/* ==========================================================
   CENÁRIO 2: LINK DE EMPRESA
========================================================== */
if ($empresaId <= 0 || $empresaSlug === '') {
    goErro('LINK_INVALIDO', $empresaId, $empresaSlug);
}

/* ==========================================================
   CONEXÃO
========================================================== */
$arquivoConexao = __DIR__ . '/../backend/_config/conexao.php';

if (!is_file($arquivoConexao)) {
    goErro('CONEXAO_INVALIDA', $empresaId, $empresaSlug);
}

require_once $arquivoConexao;

$con = null;

if (isset($ConexBD) && $ConexBD instanceof mysqli) {
    $con = $ConexBD;
} elseif (isset($conexao) && $conexao instanceof mysqli) {
    $con = $conexao;
}

if (!$con instanceof mysqli) {
    goErro('CONEXAO_INVALIDA', $empresaId, $empresaSlug);
}

if ($con->connect_errno) {
    goErro('CONEXAO_INVALIDA', $empresaId, $empresaSlug);
}

$con->set_charset('utf8mb4');

/* ==========================================================
   VALIDA EMPRESA
========================================================== */
$sql = "
    SELECT
        id_empresa,
        nome,
        status
    FROM empresa
    WHERE id_empresa = ?
    LIMIT 1
";

$stmt = $con->prepare($sql);

if (!$stmt) {
    goErro('ERRO_INTERNO', $empresaId, $empresaSlug);
}

$stmt->bind_param('i', $empresaId);
$stmt->execute();

$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;

$stmt->close();

if (!$row) {
    goErro('EMPRESA_NAO_ENCONTRADA', $empresaId, $empresaSlug);
}

$statusEmpresa = trim((string)($row['status'] ?? ''));
$nomeEmpresa   = trim((string)($row['nome'] ?? ''));
$slugReal      = normalizeSlug($nomeEmpresa);

if ($nomeEmpresa === '') {
    goErro('ERRO_INTERNO', $empresaId, $empresaSlug);
}

if (strtolower($statusEmpresa) !== 'ativo') {
    goErro('EMPRESA_INATIVA', $empresaId, $empresaSlug);
}

if ($slugReal === '') {
    goErro('ERRO_INTERNO', $empresaId, $empresaSlug);
}

if (!hash_equals($slugReal, $empresaSlug)) {
    goErro('SLUG_INVALIDO', $empresaId, $empresaSlug);
}

/* ==========================================================
   SESSÃO SEGURA
========================================================== */
clearSessaoEmpresa();
clearSessaoSuperAdmin();

$_SESSION['empresa_id']   = (int)$row['id_empresa'];
$_SESSION['empresa_nome'] = $nomeEmpresa;
$_SESSION['empresa_slug'] = $slugReal;

/* ==========================================================
   REDIRECIONA PARA LOGIN DA EMPRESA
========================================================== */
go('/public/views/login-cliente.php');