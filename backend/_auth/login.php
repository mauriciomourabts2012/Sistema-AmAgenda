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

/* ==========================================================
   RECUPERAÇÃO CENTRAL DE SENHA
========================================================== */
$acaoLogin = trim((string)($_POST['acao'] ?? 'login'));

if (str_starts_with($acaoLogin, 'recuperacao_')) {
    require_once __DIR__ . '/csrf.php';
    require_once __DIR__ . '/../_servicos/sms_otp.php';
    csrfValidarSessao();

    $mensagemSolicitacao = 'Se os dados estiverem corretos, enviaremos um código de recuperação.';
    $agora = time();

    if ($acaoLogin === 'recuperacao_solicitar') {
        $emailRecuperacao = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
        $desafioAnterior = $_SESSION['usuario_recuperacao_senha'] ?? null;

        if (is_array($desafioAnterior)) {
            $ultimoEnvio = (int)($desafioAnterior['enviado_em'] ?? 0);
            if ($ultimoEnvio > 0 && ($agora - $ultimoEnvio) < 30) {
                out([
                    'ok' => false,
                    'code' => 'PASSWORD_RECOVERY_RESEND_LIMIT',
                    'user_msg' => 'Aguarde alguns segundos antes de solicitar outro código.',
                    'data' => ['retry_after' => 30 - ($agora - $ultimoEnvio)],
                ], 429);
            }
        }

        $usuarioRecuperacao = null;
        if ($emailRecuperacao !== '' && filter_var($emailRecuperacao, FILTER_VALIDATE_EMAIL)) {
            $stmt = $conexao->prepare(
                "SELECT id_usuario, telefone, status, tipo_usuario
                   FROM usuario
                  WHERE LOWER(email) = ?
                  LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $emailRecuperacao);
                if ($stmt->execute()) {
                    $resultado = $stmt->get_result();
                    $candidato = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
                    if (is_array($candidato)
                        && mb_strtolower(trim((string)($candidato['status'] ?? '')), 'UTF-8') === 'ativo'
                        && mb_strtolower(trim((string)($candidato['tipo_usuario'] ?? '')), 'UTF-8') === 'usuario') {
                        $telefoneNormalizado = smsOtpNormalizarTelefone((string)($candidato['telefone'] ?? ''));
                        if ($telefoneNormalizado !== null) {
                            $usuarioRecuperacao = [
                                'id_usuario' => (int)$candidato['id_usuario'],
                                'telefone' => $telefoneNormalizado,
                            ];
                        }
                    }
                }
                $stmt->close();
            }
        }

        $configOtp = smsOtpConfig();
        $modoOtp = mb_strtolower(trim((string)($configOtp['mode'] ?? 'programmable_sms')), 'UTF-8');
        $expiresIn = 300;
        $otpHash = password_hash((string)random_int(100000, 999999), PASSWORD_DEFAULT);
        $referencia = '';
        $envioAceito = false;

        if ($usuarioRecuperacao !== null && is_string($otpHash) && $otpHash !== '') {
            if ($modoOtp === 'programmable_sms') {
                $resultadoEnvio = smsOtpEnviar(
                    (string)$usuarioRecuperacao['telefone'],
                    'AmAgenda: código de recuperação de acesso.'
                );
                $envioAceito = is_array($resultadoEnvio) && ($resultadoEnvio['sucesso'] ?? false) === true;
                $referencia = (string)($resultadoEnvio['dados']['sid'] ?? '');

                // OTP temporário para ambiente de teste da integração SMS.
                $otpTesteAtivo = ($configOtp['dev_fixed_otp_enabled'] ?? false) === true
                    && preg_match('/^\d{6}$/', (string)($configOtp['dev_fixed_otp_code'] ?? '')) === 1;
                if ($otpTesteAtivo) {
                    $envioAceito = true;
                }
            } elseif ($modoOtp === 'verify') {
                $resultadoEnvio = smsOtpEnviar((string)$usuarioRecuperacao['telefone']);
                $envioAceito = is_array($resultadoEnvio) && ($resultadoEnvio['sucesso'] ?? false) === true;
                $referencia = (string)($resultadoEnvio['dados']['referencia'] ?? $resultadoEnvio['dados']['sid'] ?? '');
            }
        }

        /* Desafios fictícios mantêm resposta, expiração e tentativas idênticas
           sem permitir que a API confirme a existência de uma conta. */
        $desafioReal = $usuarioRecuperacao !== null && $envioAceito;
        $_SESSION['usuario_recuperacao_senha'] = [
            'id_usuario' => $desafioReal ? (int)$usuarioRecuperacao['id_usuario'] : 0,
            'telefone' => $desafioReal ? (string)$usuarioRecuperacao['telefone'] : '',
            'modo' => $desafioReal ? $modoOtp : 'decoy',
            'referencia' => $desafioReal ? $referencia : '',
            'otp_hash' => $otpHash,
            'etapa' => 'otp',
            'criado_em' => $agora,
            'enviado_em' => $agora,
            'expira_em' => $agora + $expiresIn,
            'tentativas' => 0,
            'max_tentativas' => 5,
        ];

        loginMultiempresaLimparContexto();
        unset(
            $_SESSION['cliente_auth'],
            $_SESSION['cliente_auth_desafio'],
            $_SESSION['cliente_recuperacao_senha']
        );
        session_regenerate_id(true);

        out([
            'ok' => true,
            'code' => 'PASSWORD_RECOVERY_REQUEST_ACCEPTED',
            'user_msg' => $mensagemSolicitacao,
            'data' => ['expires_in' => $expiresIn],
        ]);
    }

    $desafio = $_SESSION['usuario_recuperacao_senha'] ?? null;
    if (!is_array($desafio) || (int)($desafio['expira_em'] ?? 0) <= $agora) {
        unset($_SESSION['usuario_recuperacao_senha']);
        out([
            'ok' => false,
            'code' => 'PASSWORD_RECOVERY_EXPIRED',
            'user_msg' => 'Código inválido ou expirado.',
        ], 422);
    }

    if ($acaoLogin === 'recuperacao_validar_codigo') {
        if (($desafio['etapa'] ?? '') !== 'otp') {
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_INVALID', 'user_msg' => 'Código inválido ou expirado.'], 422);
        }

        $tentativas = (int)($desafio['tentativas'] ?? 0);
        $maxTentativas = (int)($desafio['max_tentativas'] ?? 5);
        if ($tentativas >= $maxTentativas) {
            unset($_SESSION['usuario_recuperacao_senha']);
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_ATTEMPTS_EXCEEDED', 'user_msg' => 'Código inválido ou expirado.'], 429);
        }

        $codigo = preg_replace('/\D+/', '', (string)($_POST['codigo'] ?? '')) ?? '';
        $_SESSION['usuario_recuperacao_senha']['tentativas'] = $tentativas + 1;
        $otpAprovado = false;

        if (strlen($codigo) === 6 && (int)($desafio['id_usuario'] ?? 0) > 0) {
            if (($desafio['modo'] ?? '') === 'programmable_sms') {
                $otpAprovado = password_verify($codigo, (string)($desafio['otp_hash'] ?? ''));
                $configOtp = smsOtpConfig();
                $codigoTeste = preg_replace('/\D+/', '', (string)($configOtp['dev_fixed_otp_code'] ?? '')) ?? '';
                if (($configOtp['dev_fixed_otp_enabled'] ?? false) === true && strlen($codigoTeste) === 6) {
                    $otpAprovado = $otpAprovado || hash_equals($codigoTeste, $codigo);
                }
            } elseif (($desafio['modo'] ?? '') === 'verify') {
                $resultadoValidacao = smsOtpValidar(
                    (string)($desafio['telefone'] ?? ''),
                    $codigo,
                    (string)($desafio['referencia'] ?? '') ?: null
                );
                $otpAprovado = is_array($resultadoValidacao) && ($resultadoValidacao['sucesso'] ?? false) === true;
            }
        }

        if (!$otpAprovado) {
            if (($tentativas + 1) >= $maxTentativas) {
                unset($_SESSION['usuario_recuperacao_senha']);
            }
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_CODE_INVALID', 'user_msg' => 'Código inválido ou expirado.'], 422);
        }

        session_regenerate_id(true);
        $_SESSION['usuario_recuperacao_senha']['etapa'] = 'senha';
        $_SESSION['usuario_recuperacao_senha']['otp_hash'] = null;
        $_SESSION['usuario_recuperacao_senha']['referencia'] = '';
        $_SESSION['usuario_recuperacao_senha']['autorizado_em'] = $agora;
        $_SESSION['usuario_recuperacao_senha']['expira_em'] = $agora + 600;
        unset($_SESSION['csrf_token']);
        $csrfRenovado = csrfTokenSessao();

        out([
            'ok' => true,
            'code' => 'PASSWORD_RECOVERY_CODE_ACCEPTED',
            'user_msg' => 'Código validado.',
            'data' => ['csrf_token' => $csrfRenovado, 'expires_in' => 600],
        ]);
    }

    if ($acaoLogin === 'recuperacao_redefinir_senha') {
        if (($desafio['etapa'] ?? '') !== 'senha' || (int)($desafio['id_usuario'] ?? 0) <= 0) {
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_INVALID', 'user_msg' => 'Sua recuperação expirou. Solicite um novo código.'], 422);
        }

        $novaSenha = trim((string)($_POST['nova_senha'] ?? ''));
        $confirmarSenha = trim((string)($_POST['confirmar_senha'] ?? ''));
        if (mb_strlen($novaSenha) < 6 || mb_strlen($novaSenha) > 72) {
            out(['ok' => false, 'code' => 'NEW_PASSWORD_INVALID_LENGTH', 'user_msg' => 'A nova senha deve ter entre 6 e 72 caracteres.'], 422);
        }
        if ($novaSenha !== $confirmarSenha) {
            out(['ok' => false, 'code' => 'PASSWORD_CONFIRMATION_MISMATCH', 'user_msg' => 'A confirmação da nova senha não confere.'], 422);
        }

        $idUsuarioRecuperacao = (int)$desafio['id_usuario'];
        $stmt = $conexao->prepare("SELECT senha_hash FROM usuario WHERE id_usuario = ? AND status = 'ativo' AND tipo_usuario = 'usuario' LIMIT 1");
        if (!$stmt) {
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_UNAVAILABLE', 'user_msg' => 'Não foi possível alterar a senha agora.'], 503);
        }
        $stmt->bind_param('i', $idUsuarioRecuperacao);
        $stmt->execute();
        $stmt->bind_result($senhaHashAtual);
        $usuarioValido = $stmt->fetch();
        $stmt->close();

        if (!$usuarioValido) {
            unset($_SESSION['usuario_recuperacao_senha']);
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_INVALID', 'user_msg' => 'Sua recuperação expirou. Solicite um novo código.'], 422);
        }
        if ((string)$senhaHashAtual !== '' && password_verify($novaSenha, (string)$senhaHashAtual)) {
            out(['ok' => false, 'code' => 'PASSWORD_SAME_AS_CURRENT', 'user_msg' => 'A nova senha deve ser diferente da senha atual.'], 422);
        }

        $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        if (!is_string($novoHash) || $novoHash === '') {
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_UNAVAILABLE', 'user_msg' => 'Não foi possível alterar a senha agora.'], 503);
        }

        $stmt = $conexao->prepare('UPDATE usuario SET senha_hash = ?, deve_alterar_senha = 0, data_senha_temporaria = NULL WHERE id_usuario = ? LIMIT 1');
        if (!$stmt) {
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_UNAVAILABLE', 'user_msg' => 'Não foi possível alterar a senha agora.'], 503);
        }
        $stmt->bind_param('si', $novoHash, $idUsuarioRecuperacao);
        $atualizado = $stmt->execute();
        $stmt->close();
        if (!$atualizado) {
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_UNAVAILABLE', 'user_msg' => 'Não foi possível alterar a senha agora.'], 503);
        }

        unset($_SESSION['usuario_recuperacao_senha'], $_SESSION['csrf_token']);
        session_regenerate_id(true);
        out([
            'ok' => true,
            'code' => 'PASSWORD_RECOVERY_COMPLETED',
            'user_msg' => 'Senha alterada com sucesso. Faça login novamente.',
        ]);
    }

    out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_ACTION_INVALID', 'user_msg' => 'Operação inválida.'], 422);
}

function registrarFalhaLogin(mysqli $conexao, string $evento, string $motivo, int $idEmpresa = 0, string $loginTentado = ''): void
{
    try {
        auditoriaRegistrarFalhaAutenticacao($conexao, $evento, $motivo, $idEmpresa > 0 ? $idEmpresa : null, $loginTentado);
    } catch (Throwable) {
        error_log('[auditoria_login] Não foi possível registrar uma falha de autenticação.');
    }
}

function empresaAuditoriaDaSessao(mysqli $conexao, int $idEmpresa, string $nomeEmpresa): int
{
    if ($idEmpresa <= 0 || $nomeEmpresa === '') return 0;
    $stmt = $conexao->prepare("SELECT 1 FROM empresa WHERE id_empresa=? AND nome=? AND status='ativo' LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('is', $idEmpresa, $nomeEmpresa);
    $stmt->execute();
    $stmt->store_result();
    $valida = $stmt->num_rows === 1;
    $stmt->close();
    return $valida ? $idEmpresa : 0;
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
$empresaAuditoriaId = empresaAuditoriaDaSessao($conexao, $empresaSessaoId, $empresaSessaoNome);

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
        tipo_usuario,
        deve_alterar_senha,
        (
            deve_alterar_senha = 1
            AND data_senha_temporaria IS NOT NULL
            AND CURRENT_TIMESTAMP >= DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR)
        ) AS senha_temporaria_vencida
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
/*
 * Hash fictício usado só para equalizar o tempo de resposta quando o e-mail não
 * existe ou não há hash, evitando diferença mensurável entre os casos.
 */
const LOGIN_HASH_FICTICIO = '$2y$10$4lvhgkfyLAx9RxqAr6VwLu3BX8lIl5/n5uMF8Uuk7q4x.G5Duk.Da';

if (!$user) {
    password_verify($senha, LOGIN_HASH_FICTICIO);
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_invalidas', $empresaAuditoriaId, $email);
    out([
        'ok' => false,
        'step' => 'credentials',
        'code' => 'LOGIN_INVALID_CREDENTIALS',
        'user_msg' => 'Usuário ou senha inválidos.'
    ], 401);
}

$hash = (string)($user['senha_hash'] ?? '');

if ($hash === '') {
    password_verify($senha, LOGIN_HASH_FICTICIO);
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_indisponiveis', $empresaAuditoriaId, $email);
    out([
        'ok' => false,
        'step' => 'credentials',
        'code' => 'LOGIN_INVALID_CREDENTIALS',
        'user_msg' => 'Usuário ou senha inválidos.'
    ], 401);
}

if (!password_verify($senha, $hash)) {
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_invalidas', $empresaAuditoriaId, $email);
    out([
        'ok' => false,
        'step' => 'credentials',
        'code' => 'LOGIN_INVALID_CREDENTIALS',
        'user_msg' => 'Usuário ou senha inválidos.'
    ], 401);
}

/*
 * O status do usuário só é avaliado DEPOIS da senha válida: quem não conhece a
 * senha recebe sempre a mesma resposta, seja o e-mail inexistente, inativo ou
 * bloqueado (sem enumeração de e-mail).
 */
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

$tipoUsuario = mb_strtolower(trim((string)($user['tipo_usuario'] ?? 'usuario')), 'UTF-8');
$deveAlterarSenha = (int)($user['deve_alterar_senha'] ?? 0) === 1;
$senhaTemporariaVencida = (int)($user['senha_temporaria_vencida'] ?? 0) === 1;

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
        'deve_alterar_senha' => $deveAlterarSenha,
        'senha_temporaria_vencida' => $senhaTemporariaVencida,
    ];

    $_SESSION['usuario_id']    = (int)$user['id_usuario'];
    $_SESSION['usuario_nome']  = (string)$user['nome'];
    $_SESSION['usuario_email'] = (string)$user['email'];
    $_SESSION['usuario_tipo']  = $tipoUsuario;

    $_SESSION['superadmin_id']    = (int)$user['id_usuario'];
    $_SESSION['superadmin_nome']  = (string)$user['nome'];
    $_SESSION['superadmin_email'] = (string)$user['email'];
    $_SESSION['super']            = true;

    if ((bool)($_SESSION['auth']['modo_suporte'] ?? false)) {
        try {
            auditoriaRegistrar($conexao, 'suporte.iniciado', [
                'entidade_rotulo' => 'Modo suporte',
                'descricao' => 'Iniciou o modo suporte.',
                'contexto' => ['origem' => 'login'],
            ]);
        } catch (Throwable $e) {
            $_SESSION = [];
            error_log('[auditoria_suporte] Não foi possível registrar o início do modo suporte.');
            out([
                'ok' => false,
                'step' => 'audit',
                'code' => 'SUPPORT_AUDIT_ERROR',
                'user_msg' => 'Não foi possível iniciar o modo suporte.',
            ], 500);
        }
    }

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
            'deve_alterar_senha' => $deveAlterarSenha,
            'senha_temporaria_vencida' => $senhaTemporariaVencida,
        ]
    ], 200);
}

/**
 * ==========================================================
 * 4) USUÁRIO COMUM: DESCOBRE AS EMPRESAS ACESSÍVEIS
 * ==========================================================
 * Com a senha válida, consulta todos os vínculos do usuário e aplica a cada um
 * as mesmas regras de acesso do login por empresa. O link da empresa
 * ($_SESSION['empresa_id']) é apenas uma preferência de seleção: nunca
 * autoriza nada por si só.
 */
$idUsuario = (int)$user['id_usuario'];
$vinculos = loginMultiempresaBuscarVinculos($conexao, $idUsuario);

if ($vinculos === null) {
    out([
        'ok' => false,
        'step' => 'empresa_exec',
        'code' => 'DB_EXEC_EMPRESA_FAIL',
        'user_msg' => 'Erro interno ao localizar a empresa do usuário.'
    ], 500);
}

$acessiveis = [];
$negados = [];
$erroTecnicoAcesso = false;

foreach ($vinculos as $vinculo) {
    $avaliacao = loginMultiempresaAvaliarVinculo($conexao, $vinculo);
    if ($avaliacao['permitido']) {
        $acessiveis[] = $avaliacao;
        continue;
    }
    $negados[] = $avaliacao;
    if (!empty($avaliacao['erro_tecnico'])) $erroTecnicoAcesso = true;
}

/**
 * CASO C: nenhuma empresa acessível. O motivo real vai só para a auditoria.
 */
if (!$acessiveis) {
    if ($vinculos === []) {
        loginMultiempresaAuditarFalha($conexao, 'autenticacao.acesso_negado', 'sem_vinculo_empresa');
    }
    foreach ($negados as $negado) {
        loginMultiempresaAuditarFalha($conexao, (string)$negado['evento'], (string)$negado['motivo'], (int)$negado['empresa_id']);
    }

    if ($erroTecnicoAcesso) {
        out([
            'ok' => false,
            'step' => 'assinatura',
            'code' => 'LOGIN_SUBSCRIPTION_CHECK_ERROR',
            'user_msg' => 'Não foi possível validar o acesso agora. Tente novamente.'
        ], 500);
    }

    out([
        'ok' => false,
        'step' => 'empresa',
        'code' => 'LOGIN_ACCESS_DENIED',
        'user_msg' => 'Não foi possível realizar o acesso.'
    ], 403);
}

/**
 * ==========================================================
 * 5) ESCOLHA DA EMPRESA
 * ==========================================================
 * - Uma única empresa acessível: seleção automática (CASO A).
 * - Várias: se o link da empresa apontar para uma delas, ela é usada
 *   (comportamento anterior preservado); senão exige seleção (CASO B).
 */
$escolhida = null;

if (count($acessiveis) === 1) {
    $escolhida = $acessiveis[0];
} elseif ($empresaSessaoId > 0 && $empresaSessaoNome !== '') {
    $dicaNormalizada = normalizaEmpresaNome($empresaSessaoNome);
    foreach ($acessiveis as $candidata) {
        if ((int)$candidata['empresa_id'] === $empresaSessaoId
            && $dicaNormalizada !== ''
            && $dicaNormalizada === normalizaEmpresaNome((string)$candidata['empresa_nome'])) {
            $escolhida = $candidata;
            break;
        }
    }
}

if ($escolhida !== null) {
    $dadosLogin = loginMultiempresaConsolidarSessao($conexao, $user, $escolhida);
    loginMultiempresaResponderLoginOk($dadosLogin);
}

/**
 * CASO B: pré-sessão temporária. Nenhuma sessão operacional é criada.
 */
$csrfSelecao = loginMultiempresaCriarPreSessao($idUsuario);

$empresasSelecao = [];
foreach ($acessiveis as $acessivel) {
    $empresasSelecao[] = [
        'id_empresa' => (int)$acessivel['empresa_id'],
        'nome' => (string)$acessivel['empresa_nome'],
        'perfil_nome' => (string)$acessivel['perfil_nome_exibicao'],
    ];
}

out([
    'ok' => true,
    'step' => 'empresa_selecao',
    'code' => 'EMPRESA_SELECTION_REQUIRED',
    'user_msg' => 'Selecione a empresa para continuar.',
    'data' => [
        'empresas' => $empresasSelecao,
        'csrf_token' => $csrfSelecao,
        'expira_em_segundos' => LOGIN_PENDENTE_TTL_SEGUNDOS,
    ]
], 200);
