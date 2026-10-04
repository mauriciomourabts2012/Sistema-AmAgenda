<?php
//php para sessao do alterar perfil do modal perfil
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$auth = $_SESSION['auth'] ?? null;

$idUsuario = (int)($auth['id_usuario'] ?? 0);
$statusUsuario = (string)($auth['status'] ?? '');

if ($idUsuario <= 0) {
    out([
        'ok' => false,
        'code' => 'NOT_AUTHENTICATED',
        'user_msg' => 'Sessão expirada. Faça login novamente.'
    ], 401);
}

if ($statusUsuario !== '' && $statusUsuario !== 'ativo') {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_INACTIVE',
        'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
    ], 403);
}

$tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
$idEmpresa = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? 0);

require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_regras/acesso_assinatura.php';

if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_CHECK_ERROR',
        'user_msg' => 'Não foi possível validar sua sessão.'
    ], 500);
}

$stmt = $conexao->prepare(
    "SELECT status, deve_alterar_senha,
            (deve_alterar_senha = 1
             AND data_senha_temporaria IS NOT NULL
             AND CURRENT_TIMESTAMP >= DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR)) AS senha_temporaria_vencida
       FROM usuario
      WHERE id_usuario = ?
      LIMIT 1"
);
if (!$stmt) {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_CHECK_ERROR',
        'user_msg' => 'Não foi possível validar sua sessão.'
    ], 500);
}

$stmt->bind_param('i', $idUsuario);
if (!$stmt->execute()) {
    $stmt->close();
    out([
        'ok' => false,
        'code' => 'SESSION_USER_CHECK_ERROR',
        'user_msg' => 'Não foi possível validar sua sessão.'
    ], 500);
}
$resultadoUsuario = $stmt->get_result();
$usuarioAtual = $resultadoUsuario ? ($resultadoUsuario->fetch_assoc() ?: null) : null;
$stmt->close();

if (!$usuarioAtual || mb_strtolower(trim((string)($usuarioAtual['status'] ?? '')), 'UTF-8') !== 'ativo') {
    out([
        'ok' => false,
        'code' => 'SESSION_USER_INACTIVE',
        'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
    ], 403);
}

$deveAlterarSenha = (int)($usuarioAtual['deve_alterar_senha'] ?? 0) === 1;
$senhaTemporariaVencida = (int)($usuarioAtual['senha_temporaria_vencida'] ?? 0) === 1;
$_SESSION['auth']['deve_alterar_senha'] = $deveAlterarSenha;
$_SESSION['auth']['senha_temporaria_vencida'] = $senhaTemporariaVencida;

if ($senhaTemporariaVencida && !defined('AUTH_PERMITIR_SENHA_TEMPORARIA_VENCIDA')) {
    out([
        'ok' => false,
        'code' => 'SENHA_TEMPORARIA_EXPIRADA',
        'user_msg' => 'Altere sua senha temporária para continuar utilizando o AmAgenda.'
    ], 403);
}

/* A API também revalida o contrato, pois uma chamada direta não depende do
 * guard visual da sessão. Super Admin não depende de assinatura empresarial. */
if ($tipoUsuario !== 'super_admin') {
    $stmt = $conexao->prepare(
        "SELECT eu.bloqueado_plano, p.nome
           FROM empresa_usuario eu
           INNER JOIN perfil p ON p.id_perfil = eu.id_perfil
           INNER JOIN empresa e ON e.id_empresa = eu.id_empresa
          WHERE eu.id_usuario = ?
            AND eu.id_empresa = ?
            AND eu.status = 'ativo'
            AND p.status = 'ativo'
            AND e.status = 'ativo'
          LIMIT 1"
    );
    if (!$stmt) {
        out([
            'ok' => false,
            'code' => 'SESSION_PLAN_CHECK_ERROR',
            'user_msg' => 'Não foi possível validar sua sessão.'
        ], 500);
    }

    $stmt->bind_param('ii', $idUsuario, $idEmpresa);
    if (!$stmt->execute()) {
        $stmt->close();
        out([
            'ok' => false,
            'code' => 'SESSION_PLAN_CHECK_ERROR',
            'user_msg' => 'Não foi possível validar sua sessão.'
        ], 500);
    }
    $stmt->bind_result($bloqueadoPlano, $perfilNomeVinculo);
    $vinculoEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$vinculoEncontrado) {
        out([
            'ok' => false,
            'code' => 'SESSION_COMPANY_LINK_INVALID',
            'user_msg' => 'Seu vínculo com a empresa não está ativo. Faça login novamente.'
        ], 403);
    }

    // O bloqueio do plano prevalece enquanto o vínculo autenticado estiver bloqueado.
    if ($vinculoEncontrado && (int)$bloqueadoPlano === 1) {
        out([
            'ok' => false,
            'code' => 'SESSION_ACCESS_DENIED',
            'user_msg' => 'Acesso indisponível para o plano atual.'
        ], 403);
    }

    $acessoAssinatura = acessoAssinaturaValidar($conexao, $idEmpresa, (string)$perfilNomeVinculo);
    if (!($acessoAssinatura['permitido'] ?? false)) {
        $erroTecnicoAssinatura = (bool)($acessoAssinatura['erro_tecnico'] ?? false);
        out([
            'ok' => false,
            'code' => $erroTecnicoAssinatura ? 'SESSION_SUBSCRIPTION_CHECK_ERROR' : 'SESSION_ACCESS_DENIED',
            'user_msg' => (string)$acessoAssinatura['user_msg']
        ], $erroTecnicoAssinatura ? 500 : 403);
    }

    $modoRegularizacao = (bool)($acessoAssinatura['modo_regularizacao'] ?? false);
    $_SESSION['auth']['modo_regularizacao'] = $modoRegularizacao;

    if ($modoRegularizacao) {
        // A sessão suspensa nunca recebe acesso por prefixo: somente rotas exatas de regularização e aceite legal.
        $rotasPermitidasRegularizacao = [
            '_auth/session',
            '_auth/logout',
            'painel/faturamento/resumo',
            'painel/faturamento/cobrancas',
            'painel/faturamento/pagamentos',
            'painel/faturamento/regularizacao/cobranca',
            'painel/faturamento/pagamento/pix/iniciar',
            'painel/faturamento/pagamento/transacao',
            'painel/faturamento/pagamento/cartao/configuracao',
            'painel/faturamento/pagamento/cartao/autorizar',
            'painel/faturamento/pagamento/cartao/status',
            'documentos-legais/pendencias',
            'documentos-legais/conteudo',
            'documentos-legais/manifestar',
        ];
        $rotaAutenticada = isset($rota) && is_string($rota) ? $rota : '';

        if (!in_array($rotaAutenticada, $rotasPermitidasRegularizacao, true)) {
            out([
                'ok' => false,
                'code' => 'SESSION_REGULARIZATION_REQUIRED',
                'user_msg' => 'A assinatura está suspensa. Regularize o faturamento para continuar usando o sistema.'
            ], 403);
        }
    }
}
