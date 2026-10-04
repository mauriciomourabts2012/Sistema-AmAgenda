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
require_once __DIR__ . '/../_regras/acesso_assinatura.php';
require_once __DIR__ . '/../_regras/login_multiempresa.php';
require_once __DIR__ . '/csrf.php';

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out([
        'ok' => false,
        'step' => 'db',
        'code' => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco.'
    ], 500);
}

$conexao->set_charset('utf8mb4');

/**
 * Encerra a pré-sessão (sem criar sessão operacional) e exige novo login.
 */
function selecaoEncerrarPreSessao(mysqli $conexao, string $codigo, string $motivoAuditoria, int $status): void
{
    unset($_SESSION[LOGIN_PENDENTE_CHAVE]);
    loginMultiempresaAuditarFalha($conexao, 'autenticacao.acesso_negado', $motivoAuditoria);
    out([
        'ok' => false,
        'step' => 'pre_sessao',
        'code' => $codigo,
        'user_msg' => 'Sua tentativa de acesso expirou. Faça login novamente.'
    ], $status);
}

/**
 * Conta uma tentativa de seleção inválida. Ao atingir o limite, a pré-sessão é
 * inutilizada e o usuário precisa se autenticar de novo.
 */
function selecaoRegistrarTentativaInvalida(mysqli $conexao, string $codigo, string $mensagem, int $status): void
{
    $tentativas = (int)($_SESSION[LOGIN_PENDENTE_CHAVE]['tentativas'] ?? 0) + 1;
    $_SESSION[LOGIN_PENDENTE_CHAVE]['tentativas'] = $tentativas;

    if ($tentativas >= LOGIN_PENDENTE_MAX_TENTATIVAS) {
        selecaoEncerrarPreSessao($conexao, 'LOGIN_PENDING_ATTEMPTS_EXCEEDED', 'pre_sessao_tentativas_excedidas', 429);
    }

    out([
        'ok' => false,
        'step' => 'empresa_selecao',
        'code' => $codigo,
        'user_msg' => $mensagem
    ], $status);
}

/**
 * ==========================================================
 * 1) PRÉ-SESSÃO OBRIGATÓRIA
 * ==========================================================
 * Esta rota não tem sessão operacional; quem controla o acesso é a pré-sessão
 * criada por login.php após senha válida.
 */
$pendente = $_SESSION[LOGIN_PENDENTE_CHAVE] ?? null;

if (!is_array($pendente)
    || (int)($pendente['id_usuario'] ?? 0) <= 0
    || (int)($pendente['expira_em'] ?? 0) <= 0
) {
    unset($_SESSION[LOGIN_PENDENTE_CHAVE]);
    out([
        'ok' => false,
        'step' => 'pre_sessao',
        'code' => 'LOGIN_PENDING_REQUIRED',
        'user_msg' => 'Faça login novamente para continuar.'
    ], 401);
}

// Uma sessão operacional nunca coexiste com uma pré-sessão legítima.
if ((int)($_SESSION['auth']['id_usuario'] ?? 0) > 0) {
    unset($_SESSION[LOGIN_PENDENTE_CHAVE]);
    out([
        'ok' => false,
        'step' => 'pre_sessao',
        'code' => 'LOGIN_PENDING_REQUIRED',
        'user_msg' => 'Faça login novamente para continuar.'
    ], 401);
}

if (time() >= (int)$pendente['expira_em']) {
    selecaoEncerrarPreSessao($conexao, 'LOGIN_PENDING_EXPIRED', 'pre_sessao_expirada', 401);
}

if ((int)($pendente['tentativas'] ?? 0) >= LOGIN_PENDENTE_MAX_TENTATIVAS) {
    selecaoEncerrarPreSessao($conexao, 'LOGIN_PENDING_ATTEMPTS_EXCEEDED', 'pre_sessao_tentativas_excedidas', 429);
}

// Mesmo padrão das APIs autenticadas (X-CSRF-Token); o token da pré-sessão foi
// entregue pelo login e é rotacionado de novo na consolidação.
csrfValidarSessao();

$idUsuario = (int)$pendente['id_usuario'];

/**
 * ==========================================================
 * 2) ENTRADA: id_empresa É SOMENTE UMA INTENÇÃO DO NAVEGADOR
 * ==========================================================
 */
$idEmpresaTexto = trim((string)($_POST['id_empresa'] ?? ''));

if (preg_match('/^[1-9][0-9]{0,17}$/', $idEmpresaTexto) !== 1) {
    selecaoRegistrarTentativaInvalida($conexao, 'EMPRESA_SELECTION_INVALID', 'Selecione uma empresa válida.', 422);
}

$idEmpresaSolicitada = (int)$idEmpresaTexto;

/**
 * ==========================================================
 * 3) REVALIDA USUÁRIO NO BANCO
 * ==========================================================
 */
$stmt = $conexao->prepare("
    SELECT
        id_usuario,
        nome,
        email,
        status,
        tipo_usuario,
        deve_alterar_senha,
        (
            deve_alterar_senha = 1
            AND data_senha_temporaria IS NOT NULL
            AND CURRENT_TIMESTAMP >= DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR)
        ) AS senha_temporaria_vencida
    FROM usuario
    WHERE id_usuario = ?
    LIMIT 1
");

if (!$stmt) {
    out(['ok' => false, 'step' => 'query_user_prepare', 'code' => 'DB_PREPARE_FAIL', 'user_msg' => 'Erro interno ao processar o login.'], 500);
}

$stmt->bind_param('i', $idUsuario);

if (!$stmt->execute()) {
    $stmt->close();
    out(['ok' => false, 'step' => 'query_user_exec', 'code' => 'DB_EXEC_FAIL', 'user_msg' => 'Erro interno ao processar o login.'], 500);
}

$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

$statusUsuario = mb_strtolower(trim((string)($user['status'] ?? '')), 'UTF-8');
$tipoUsuario = mb_strtolower(trim((string)($user['tipo_usuario'] ?? '')), 'UTF-8');

// Super Admin nunca usa o seletor comum; usuário inativo/removido perde a pré-sessão.
if (!$user || $statusUsuario !== 'ativo' || $tipoUsuario === 'super_admin') {
    selecaoEncerrarPreSessao(
        $conexao,
        'LOGIN_ACCESS_DENIED',
        !$user ? 'pre_sessao_usuario_inexistente' : ($tipoUsuario === 'super_admin' ? 'pre_sessao_usuario_nao_permitido' : 'usuario_inativo'),
        403
    );
}

/**
 * ==========================================================
 * 4) REVALIDA VÍNCULO USUÁRIO × EMPRESA NO BANCO
 * ==========================================================
 * A lista enviada antes ao navegador não é considerada: a consulta usa o
 * usuário da pré-sessão e a empresa solicitada.
 */
$vinculos = loginMultiempresaBuscarVinculos($conexao, $idUsuario, $idEmpresaSolicitada);

if ($vinculos === null) {
    out(['ok' => false, 'step' => 'empresa_exec', 'code' => 'DB_EXEC_EMPRESA_FAIL', 'user_msg' => 'Erro interno ao localizar a empresa do usuário.'], 500);
}

if ($vinculos === []) {
    loginMultiempresaAuditarFalha($conexao, 'autenticacao.acesso_negado', 'selecao_empresa_nao_pertence', $idEmpresaSolicitada);
    selecaoRegistrarTentativaInvalida($conexao, 'EMPRESA_SELECTION_DENIED', 'Não foi possível acessar a empresa selecionada.', 403);
}

$acesso = loginMultiempresaAvaliarVinculo($conexao, $vinculos[0]);

if (!$acesso['permitido']) {
    if (!empty($acesso['erro_tecnico'])) {
        loginMultiempresaAuditarFalha($conexao, (string)$acesso['evento'], (string)$acesso['motivo'], $idEmpresaSolicitada);
        out(['ok' => false, 'step' => 'assinatura', 'code' => 'LOGIN_SUBSCRIPTION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar o acesso agora. Tente novamente.'], 500);
    }

    loginMultiempresaAuditarFalha($conexao, (string)$acesso['evento'], (string)$acesso['motivo'], $idEmpresaSolicitada);
    selecaoRegistrarTentativaInvalida($conexao, 'EMPRESA_SELECTION_DENIED', 'Não foi possível acessar a empresa selecionada.', 403);
}

/**
 * ==========================================================
 * 5) CONSOLIDA A SESSÃO OPERACIONAL E APAGA A PRÉ-SESSÃO
 * ==========================================================
 */
$dadosLogin = loginMultiempresaConsolidarSessao($conexao, $user, $acesso);
loginMultiempresaResponderLoginOk($dadosLogin);
