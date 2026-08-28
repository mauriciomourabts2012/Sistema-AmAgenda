<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('normalizaEmpresaNome')) {
    function normalizaEmpresaNome(string $nome): string {
        $nome = trim(mb_strtolower($nome, 'UTF-8'));

        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c',
            'ñ'=>'n'
        ];

        $nome = strtr($nome, $map);
        $nome = preg_replace('/[^a-z0-9]+/u', '-', $nome) ?? '';
        $nome = trim($nome, '-');

        return $nome;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out([
        'ok' => false,
        'step' => 'method',
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.'
    ], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_servicos/auditoria.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out([
        'ok' => false,
        'step' => 'db',
        'code' => 'DB_CONN_MISSING',
        'user_msg' => 'Conexão do banco indisponível.'
    ], 500);
}

if ($conexao->connect_errno) {
    out([
        'ok' => false,
        'step' => 'db',
        'code' => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco.'
    ], 500);
}

$conexao->set_charset('utf8mb4');

function registrarFalhaLogin(mysqli $conexao, string $evento, string $motivo, int $idEmpresa = 0): void
{
    try {
        auditoriaRegistrarFalhaAutenticacao($conexao, $evento, $motivo, $idEmpresa > 0 ? $idEmpresa : null);
    } catch (Throwable) {
        error_log('[auditoria_login] Não foi possível registrar uma falha de autenticação.');
    }
}

$email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
$senha = (string)($_POST['password'] ?? '');

/**
 * ==========================================================
 * DADOS DA EMPRESA VINDOS DA SESSÃO/URL DE ENTRADA
 * ==========================================================
 */
$empresaSessaoId   = (int)($_SESSION['empresa_id'] ?? 0);
$empresaSessaoNome = trim((string)($_SESSION['empresa_nome'] ?? ''));

/**
 * ==========================================================
 * VALIDA INPUT
 * ==========================================================
 */
if ($email === '') {
    out([
        'ok' => false,
        'step' => 'input',
        'code' => 'EMAIL_REQUIRED',
        'user_msg' => 'Informe seu e-mail.'
    ], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    out([
        'ok' => false,
        'step' => 'input',
        'code' => 'EMAIL_INVALID',
        'user_msg' => 'Informe um e-mail válido.'
    ], 422);
}

if ($senha === '') {
    out([
        'ok' => false,
        'step' => 'input',
        'code' => 'PASSWORD_REQUIRED',
        'user_msg' => 'Informe sua senha.'
    ], 422);
}

/**
 * ==========================================================
 * 1) BUSCA USUÁRIO
 * ==========================================================
 */
$sqlUsuario = "
    SELECT
        id_usuario,
        nome,
        email,
        telefone,
        senha_hash,
        status,
        tipo_usuario
    FROM usuario
    WHERE LOWER(email) = LOWER(?)
    LIMIT 1
";

$stmt = $conexao->prepare($sqlUsuario);

if (!$stmt) {
    out([
        'ok' => false,
        'step' => 'query_user_prepare',
        'code' => 'DB_PREPARE_FAIL',
        'user_msg' => 'Erro interno ao processar o login.'
    ], 500);
}

$stmt->bind_param('s', $email);

if (!$stmt->execute()) {
    $stmt->close();

    out([
        'ok' => false,
        'step' => 'query_user_exec',
        'code' => 'DB_EXEC_FAIL',
        'user_msg' => 'Erro interno ao processar o login.'
    ], 500);
}

$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

/**
 * ==========================================================
 * 2) VALIDA USUÁRIO
 * ==========================================================
 */
if (!$user) {
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_invalidas', $empresaSessaoId);
    out([
        'ok' => false,
        'step' => 'user',
        'code' => 'LOGIN_INVALID_CREDENTIALS',
        'user_msg' => 'E-mail ou senha inválidos.'
    ], 401);
}

$statusUsuario = mb_strtolower(trim((string)($user['status'] ?? '')), 'UTF-8');

if ($statusUsuario !== 'ativo') {
    registrarFalhaLogin($conexao, 'autenticacao.usuario_inativo', $statusUsuario === 'bloqueado' ? 'usuario_bloqueado' : 'usuario_inativo', $empresaSessaoId);
    out([
        'ok' => false,
        'step' => 'user_status',
        'code' => 'LOGIN_ACCESS_DENIED',
        'user_msg' => 'Não foi possível realizar o acesso.'
    ], 403);
}

$hash = (string)($user['senha_hash'] ?? '');

if ($hash === '') {
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_indisponiveis', $empresaSessaoId);
    out([
        'ok' => false,
        'step' => 'password',
        'code' => 'LOGIN_INVALID_CREDENTIALS',
        'user_msg' => 'E-mail ou senha inválidos.'
    ], 401);
}

if (!password_verify($senha, $hash)) {
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_invalidas', $empresaSessaoId);
    out([
        'ok' => false,
        'step' => 'password',
        'code' => 'LOGIN_INVALID_CREDENTIALS',
        'user_msg' => 'E-mail ou senha inválidos.'
    ], 401);
}

$tipoUsuario = mb_strtolower(trim((string)($user['tipo_usuario'] ?? 'usuario')), 'UTF-8');

/**
 * ==========================================================
 * 3) SUPER ADMIN
 * ==========================================================
 * Super admin PODE logar com contexto de empresa na sessão
 * para suportes e manutenções.
 *
 * AJUSTE:
 * - Se existir empresa_id e empresa_nome na sessão:
 *   redirect => /views/painel-administrativo/painel-administrativo.html
 * - Senão:
 *   redirect => /views/super-admin/painel-super-admin.html
 */
if ($tipoUsuario === 'super_admin') {
    $sqlUpdateLogin = "
        UPDATE usuario
           SET ultimo_login_em = NOW()
         WHERE id_usuario = ?
         LIMIT 1
    ";
    $stmtUpdate = $conexao->prepare($sqlUpdateLogin);

    if ($stmtUpdate) {
        $idUsuarioUpdate = (int)$user['id_usuario'];
        $stmtUpdate->bind_param('i', $idUsuarioUpdate);
        $stmtUpdate->execute();
        $stmtUpdate->close();
    }

    session_regenerate_id(true);

    unset($_SESSION['auth']);
    unset($_SESSION['superadmin_id']);
    unset($_SESSION['superadmin_nome']);
    unset($_SESSION['superadmin_email']);
    unset($_SESSION['super']);
    unset($_SESSION['usuario_id']);
    unset($_SESSION['usuario_nome']);
    unset($_SESSION['usuario_email']);
    unset($_SESSION['usuario_tipo']);
    unset($_SESSION['perfil_id']);
    unset($_SESSION['perfil_nome']);
    unset($_SESSION['modo_suporte']);

    $redirect = '/views/super-admin/painel-super-admin.html';

    /**
     * Mantém contexto de empresa se vier da sessão/link.
     * Isso é útil para suporte/manutenção.
     */
    if ($empresaSessaoId > 0 && $empresaSessaoNome !== '') {
        $_SESSION['empresa_id']   = $empresaSessaoId;
        $_SESSION['empresa_nome'] = $empresaSessaoNome;
        $_SESSION['modo_suporte'] = true;

        /**
         * AJUSTE DO REDIRECT:
         * Se o super admin entrou com empresa na sessão,
         * redireciona para o painel administrativo.
         */
        // Mantém a identidade real do usuário: modo suporte não o transforma em proprietário.
        $_SESSION['perfil_id']   = 0;
        $_SESSION['perfil_nome'] = 'super_admin';
        $redirect = '/views/painel-administrativo/painel-administrativo.html';
    } else {
        unset($_SESSION['empresa_id']);
        unset($_SESSION['empresa_nome']);
        $_SESSION['modo_suporte'] = false;
    }

    $_SESSION['auth'] = [
        'logado'       => true,
        'id_usuario'   => (int)$user['id_usuario'],
        'nome'         => (string)$user['nome'],
        'email'        => (string)$user['email'],
        'tipo_usuario' => $tipoUsuario,
        'status'       => $statusUsuario,
        'empresa_id'   => (int)($_SESSION['empresa_id'] ?? 0),
        'empresa_nome' => (string)($_SESSION['empresa_nome'] ?? ''),
        'perfil_id'    => (int)($_SESSION['perfil_id'] ?? 0),
        'perfil_nome'  => (string)($_SESSION['perfil_nome'] ?? ''),
        'modo_suporte' => (bool)($_SESSION['modo_suporte'] ?? false),
    ];

    $_SESSION['usuario_id']    = (int)$user['id_usuario'];
    $_SESSION['usuario_nome']  = (string)$user['nome'];
    $_SESSION['usuario_email'] = (string)$user['email'];
    $_SESSION['usuario_tipo']  = $tipoUsuario;

    $_SESSION['superadmin_id']    = (int)$user['id_usuario'];
    $_SESSION['superadmin_nome']  = (string)$user['nome'];
    $_SESSION['superadmin_email'] = (string)$user['email'];
    $_SESSION['super']            = true;

    out([
        'ok' => true,
        'step' => 'done',
        'code' => 'LOGIN_OK',
        'user_msg' => 'Login realizado com sucesso.',
        'data' => [
            'redirect'     => $redirect,
            'empresa_id'   => (int)($_SESSION['empresa_id'] ?? 0),
            'empresa_nome' => (string)($_SESSION['empresa_nome'] ?? ''),
            'perfil_id'    => (int)($_SESSION['perfil_id'] ?? 0),
            'perfil_nome'  => (string)($_SESSION['perfil_nome'] ?? ''),
            'modo_suporte' => (bool)($_SESSION['modo_suporte'] ?? false),
        ]
    ], 200);
}

/**
 * ==========================================================
 * 4) USUÁRIO COMUM PRECISA ENTRAR PELO LINK DA EMPRESA
 * ==========================================================
 */
if ($empresaSessaoId <= 0 || $empresaSessaoNome === '') {
    registrarFalhaLogin($conexao, 'autenticacao.acesso_negado', 'contexto_empresa_ausente');
    out([
        'ok' => false,
        'step' => 'empresa_session',
        'code' => 'EMPRESA_SESSION_REQUIRED',
        'user_msg' => 'Acesse pelo link da sua empresa.'
    ], 403);
}

/**
 * ==========================================================
 * 5) BUSCA VÍNCULO DO USUÁRIO COM A EMPRESA DA SESSÃO
 * ==========================================================
 */
$sqlEmpresaUsuario = "
    SELECT
        eu.id_empresa,
        eu.id_perfil,
        p.nome AS perfil_nome,
        p.status AS perfil_status,
        eu.status AS status_vinculo,
        e.nome AS empresa_nome,
        e.status AS empresa_status
    FROM empresa_usuario eu
    INNER JOIN empresa e
        ON e.id_empresa = eu.id_empresa
    INNER JOIN perfil p
        ON p.id_perfil = eu.id_perfil
    WHERE eu.id_usuario = ?
      AND eu.id_empresa = ?
    LIMIT 1
";

$stmtEmp = $conexao->prepare($sqlEmpresaUsuario);

if (!$stmtEmp) {
    out([
        'ok' => false,
        'step' => 'empresa_prepare',
        'code' => 'DB_PREPARE_EMPRESA_FAIL',
        'user_msg' => 'Erro interno ao localizar a empresa do usuário.'
    ], 500);
}

$idUsuario = (int)$user['id_usuario'];
$stmtEmp->bind_param('ii', $idUsuario, $empresaSessaoId);

if (!$stmtEmp->execute()) {
    $stmtEmp->close();

    out([
        'ok' => false,
        'step' => 'empresa_exec',
        'code' => 'DB_EXEC_EMPRESA_FAIL',
        'user_msg' => 'Erro interno ao localizar a empresa do usuário.'
    ], 500);
}

$resEmp = $stmtEmp->get_result();
$empresaRow = $resEmp ? $resEmp->fetch_assoc() : null;
$stmtEmp->close();

if (!$empresaRow) {
    registrarFalhaLogin($conexao, 'autenticacao.acesso_negado', 'vinculo_nao_encontrado', $empresaSessaoId);
    out([
        'ok' => false,
        'step' => 'empresa',
        'code' => 'LOGIN_ACCESS_DENIED',
        'user_msg' => 'Não foi possível realizar o acesso.'
    ], 403);
}

$empresaId     = (int)($empresaRow['id_empresa'] ?? 0);
$perfilId      = (int)($empresaRow['id_perfil'] ?? 0);
$perfilNomeDb  = trim((string)($empresaRow['perfil_nome'] ?? ''));
$perfilStatus  = mb_strtolower(trim((string)($empresaRow['perfil_status'] ?? '')), 'UTF-8');
$statusVinculo = mb_strtolower(trim((string)($empresaRow['status_vinculo'] ?? '')), 'UTF-8');
$statusEmpresa = mb_strtolower(trim((string)($empresaRow['empresa_status'] ?? '')), 'UTF-8');
$empresaNomeBd = trim((string)($empresaRow['empresa_nome'] ?? ''));

if ($empresaId <= 0) {
    registrarFalhaLogin($conexao, 'autenticacao.acesso_negado', 'vinculo_invalido', $empresaSessaoId);
    out([
        'ok' => false,
        'step' => 'empresa',
        'code' => 'INVALID_EMPRESA_LINK',
        'user_msg' => 'Vínculo da empresa inválido.'
    ], 403);
}

if ($statusVinculo !== 'ativo') {
    registrarFalhaLogin($conexao, 'autenticacao.vinculo_inativo', $statusVinculo === 'bloqueado' ? 'vinculo_bloqueado' : 'vinculo_inativo', $empresaId);
    out([
        'ok' => false,
        'step' => 'empresa_status',
        'code' => 'LOGIN_ACCESS_DENIED',
        'user_msg' => 'Não foi possível realizar o acesso.'
    ], 403);
}

if ($statusEmpresa !== 'ativo') {
    registrarFalhaLogin($conexao, 'autenticacao.empresa_inativa', $statusEmpresa === 'bloqueado' ? 'empresa_bloqueada' : 'empresa_inativa', $empresaId);
    out([
        'ok' => false,
        'step' => 'empresa_status',
        'code' => 'LOGIN_ACCESS_DENIED',
        'user_msg' => 'Não foi possível realizar o acesso.'
    ], 403);
}

if ($perfilId <= 0) {
    registrarFalhaLogin($conexao, 'autenticacao.acesso_negado', 'perfil_ausente', $empresaId);
    out([
        'ok' => false,
        'step' => 'perfil',
        'code' => 'USER_WITHOUT_PERFIL',
        'user_msg' => 'Usuário sem perfil vinculado à empresa.'
    ], 403);
}

if ($perfilStatus !== 'ativo') {
    registrarFalhaLogin($conexao, 'autenticacao.acesso_negado', $perfilStatus === 'bloqueado' ? 'perfil_bloqueado' : 'perfil_inativo', $empresaId);
    out([
        'ok' => false,
        'step' => 'perfil',
        'code' => 'USER_PROFILE_INACTIVE',
        'user_msg' => 'O perfil vinculado ao usuário não está ativo.'
    ], 403);
}

/**
 * ==========================================================
 * 6) CONFERE O NOME DA EMPRESA DA SESSÃO
 * ==========================================================
 */
$empresaSessaoNomeNormalizado = normalizaEmpresaNome($empresaSessaoNome);
$empresaNomeBdNormalizado     = normalizaEmpresaNome($empresaNomeBd);

if (
    $empresaSessaoNomeNormalizado === '' ||
    $empresaNomeBdNormalizado === '' ||
    $empresaSessaoNomeNormalizado !== $empresaNomeBdNormalizado
) {
    registrarFalhaLogin($conexao, 'autenticacao.acesso_negado', 'contexto_empresa_incompativel', $empresaId);
    out([
        'ok' => false,
        'step' => 'empresa_nome',
        'code' => 'EMPRESA_NAME_MISMATCH',
        'user_msg' => 'O link da empresa é inválido ou não corresponde ao cadastro.'
    ], 403);
}

/**
 * ==========================================================
 * 7) ATUALIZA ÚLTIMO LOGIN
 * ==========================================================
 */
$sqlUpdateLogin = "
    UPDATE usuario
       SET ultimo_login_em = NOW()
     WHERE id_usuario = ?
     LIMIT 1
";
$stmtUpdate = $conexao->prepare($sqlUpdateLogin);

if ($stmtUpdate) {
    $idUsuarioUpdate = (int)$user['id_usuario'];
    $stmtUpdate->bind_param('i', $idUsuarioUpdate);
    $stmtUpdate->execute();
    $stmtUpdate->close();
}

/**
 * ==========================================================
 * 8) REGENERA SESSÃO E LIMPA RESÍDUOS
 * ==========================================================
 */
session_regenerate_id(true);

unset($_SESSION['auth']);
unset($_SESSION['superadmin_id']);
unset($_SESSION['superadmin_nome']);
unset($_SESSION['superadmin_email']);
unset($_SESSION['super']);
unset($_SESSION['usuario_id']);
unset($_SESSION['usuario_nome']);
unset($_SESSION['usuario_email']);
unset($_SESSION['usuario_tipo']);
unset($_SESSION['perfil_id']);
unset($_SESSION['perfil_nome']);
unset($_SESSION['modo_suporte']);

/**
 * Mantém empresa validada na sessão
 */
$_SESSION['empresa_id']   = $empresaId;
$_SESSION['empresa_nome'] = $empresaNomeBd;

/**
 * ==========================================================
 * 9) SESSÃO BASE
 * ==========================================================
 */
$_SESSION['auth'] = [
    'logado'       => true,
    'id_usuario'   => (int)$user['id_usuario'],
    'nome'         => (string)$user['nome'],
    'email'        => (string)$user['email'],
    'tipo_usuario' => $tipoUsuario,
    'status'       => $statusUsuario,
    'empresa_id'   => $empresaId,
    'empresa_nome' => $empresaNomeBd,
    'perfil_id'    => $perfilId,
    'modo_suporte' => false,
];

$_SESSION['usuario_id']    = (int)$user['id_usuario'];
$_SESSION['usuario_nome']  = (string)$user['nome'];
$_SESSION['usuario_email'] = (string)$user['email'];
$_SESSION['usuario_tipo']  = $tipoUsuario;
$_SESSION['perfil_id']     = $perfilId;

/**
 * ==========================================================
 * 10) DEFINE PERFIL E REDIRECT
 * ==========================================================
 */
$perfilNome = '';
$redirect = '';
$perfilNomeNormalizado = normalizaEmpresaNome($perfilNomeDb);

switch ($perfilNomeNormalizado) {
    case 'proprietario':
        $perfilNome = 'proprietario';
        $redirect = '/views/painel-administrativo/painel-administrativo.html';
        break;

    case 'profissional':
        $perfilNome = 'profissional';
        $redirect = '/views/agenda.html';
        break;

    case 'recepcao':
    case 'recepcionista':
        $perfilNome = 'recepcionista';
        $redirect = '/views/agenda.html';
        break;

    default:
        registrarFalhaLogin($conexao, 'autenticacao.acesso_negado', 'perfil_nao_permitido', $empresaId);
        out([
            'ok' => false,
            'step' => 'perfil',
            'code' => 'USER_PROFILE_NOT_ALLOWED',
            'user_msg' => 'Perfil de usuário não permitido para este acesso.',
            'data' => [
                'perfil_id' => $perfilId
            ]
        ], 403);
}

$_SESSION['perfil_nome'] = $perfilNome;
$_SESSION['auth']['perfil_nome'] = $perfilNome;

/**
 * ==========================================================
 * 11) LOGIN OK USUÁRIO COMUM
 * ==========================================================
 */
out([
    'ok' => true,
    'step' => 'done',
    'code' => 'LOGIN_OK',
    'user_msg' => 'Login realizado com sucesso.',
    'data' => [
        'redirect'     => $redirect,
        'empresa_id'   => $empresaId,
        'empresa_nome' => $empresaNomeBd,
        'perfil_id'    => $perfilId,
        'perfil_nome'  => $perfilNome
    ]
], 200);
