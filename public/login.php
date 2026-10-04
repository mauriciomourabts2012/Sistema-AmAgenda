<?php
declare(strict_types=1);

/**
 * ==========================================================
 * AmAgenda - Entrada de Login
 * ----------------------------------------------------------
 * REGRA:
 * 1) Sem parâmetros                         -> login super admin
 * 2) Com ?slug=slug-persistido             -> Agenda Online da empresa
 * 3) Com ?empresa=ID&nome=nome-da-empresa  -> entrada tecnica legada
 *    ou ?empresa=ID&slug=nome-da-empresa
 *
 * VALIDAÇÃO:
 * - antes de redirecionar, verifica no banco:
 *   • empresa existe
 *   • empresa está ativa
 *   • slug persistido identifica a empresa
 *   • entrada legada continua validando ID e nome/slug
 * - em caso de erro, redireciona para página amigável
 * ==========================================================
 */

session_start();

require_once __DIR__ . '/../backend/_servicos/empresa.php';
require_once __DIR__ . '/../backend/_regras/acesso_assinatura.php';

function go(string $url): void {
    header("Location: {$url}");
    exit;
}

function goErro(string $motivo, int $empresaId = 0, string $slug = ''): void {
    $params = ['motivo' => $motivo];
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
    unset($_SESSION['cliente_auth']);
    unset($_SESSION['cliente_agendamento_csrf']);
}

function clearSessaoSuperAdmin(): void {
    unset($_SESSION['superadmin_id']);
    unset($_SESSION['superadmin_nome']);
    unset($_SESSION['superadmin_email']);
    unset($_SESSION['super']);
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

    return trim($raw);
}

function getRotaPublicaSlug(): ?string {
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path)
        || preg_match('~(?:^|/)agendar/([^/]+)/?$~D', $path, $matches) !== 1) {
        return null;
    }

    return rawurldecode((string)$matches[1]);
}

$slugRotaPublica = getRotaPublicaSlug();
$rotaPublica = $slugRotaPublica !== null;
$empresaId = $rotaPublica ? 0 : getEmpresaId();
$empresaSlug = $rotaPublica ? $slugRotaPublica : getEmpresaSlug();
$entradaLegada = !$rotaPublica && $empresaId > 0;

if ($entradaLegada) {
    $empresaSlug = empresaSlugNormalizar($empresaSlug);
}

/* ==========================================================
   CENÁRIO 1: LOGIN SUPER ADMIN
========================================================== */
if ($empresaId === 0 && $empresaSlug === '') {
    clearSessaoEmpresa();
    clearSessaoSuperAdmin();
    go('/public/views/login-super-admin.html');
}

/* ==========================================================
   CENÁRIO 2: LINK DE EMPRESA
========================================================== */
if ($empresaSlug === '' || !empresaSlugEntradaValida($empresaSlug)) {
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
$sql = $entradaLegada
    ? "SELECT id_empresa, nome, slug, status FROM empresa WHERE id_empresa = ? LIMIT 1"
    : "SELECT id_empresa, nome, slug, status FROM empresa WHERE slug = ? LIMIT 1";

$stmt = $con->prepare($sql);

if (!$stmt) {
    goErro('ERRO_INTERNO', $empresaId, $empresaSlug);
}

if ($entradaLegada) {
    $stmt->bind_param('i', $empresaId);
} else {
    $stmt->bind_param('s', $empresaSlug);
}
$stmt->execute();

$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;

$stmt->close();

if (!$row) {
    goErro('EMPRESA_NAO_ENCONTRADA', $empresaId, $empresaSlug);
}

$statusEmpresa = trim((string)($row['status'] ?? ''));
$nomeEmpresa   = trim((string)($row['nome'] ?? ''));
$slugReal      = trim((string)($row['slug'] ?? ''));

if ($nomeEmpresa === '') {
    goErro('ERRO_INTERNO', $empresaId, $empresaSlug);
}

if (strtolower($statusEmpresa) !== 'ativo') {
    clearSessaoEmpresa();
    clearSessaoSuperAdmin();
    goErro('AGENDA_ONLINE_INDISPONIVEL');
}

if (!empresaSlugEntradaValida($slugReal)) {
    goErro('ERRO_INTERNO', $empresaId, $empresaSlug);
}

$slugLegado = empresaSlugNormalizar($nomeEmpresa);
if ($entradaLegada
    && !hash_equals($slugReal, $empresaSlug)
    && ($slugLegado === '' || !hash_equals($slugLegado, $empresaSlug))) {
    goErro('SLUG_INVALIDO', $empresaId, $empresaSlug);
}

if (!acessoAssinaturaAgendaOnlineDisponivel($con, (int)$row['id_empresa'])) {
    clearSessaoEmpresa();
    clearSessaoSuperAdmin();
    goErro('AGENDA_ONLINE_INDISPONIVEL');
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
