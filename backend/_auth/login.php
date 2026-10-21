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

        // Senha recebida sem trim(), como nos demais fluxos de senha definitiva;
        // a política central decide sobre espaços e caracteres inválidos.
        $novaSenha = (string)($_POST['nova_senha'] ?? '');
        $confirmarSenha = (string)($_POST['confirmar_senha'] ?? '');

        $idUsuarioRecuperacao = (int)$desafio['id_usuario'];
        $stmt = $conexao->prepare("SELECT senha_hash, nome, email, telefone FROM usuario WHERE id_usuario = ? AND status = 'ativo' AND tipo_usuario = 'usuario' LIMIT 1");
        if (!$stmt) {
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_UNAVAILABLE', 'user_msg' => 'Não foi possível alterar a senha agora.'], 503);
        }
        $stmt->bind_param('i', $idUsuarioRecuperacao);
        $stmt->execute();
        $stmt->bind_result($senhaHashAtual, $nomeUsuarioRecuperacao, $emailUsuarioRecuperacao, $telefoneUsuarioRecuperacao);
        $usuarioValido = $stmt->fetch();
        $stmt->close();

        if (!$usuarioValido) {
            unset($_SESSION['usuario_recuperacao_senha']);
            out(['ok' => false, 'code' => 'PASSWORD_RECOVERY_INVALID', 'user_msg' => 'Sua recuperação expirou. Solicite um novo código.'], 422);
        }

        // Política central de senha definitiva (mesma dos demais fluxos):
        // tamanho, caracteres, caractere especial, senhas previsíveis,
        // dados pessoais e confirmação.
        require_once __DIR__ . '/../_regras/politica_senha.php';
        $erroPoliticaRecuperacao = politicaSenhaValidarDefinitiva($novaSenha, $confirmarSenha, [
            'nome'     => (string)($nomeUsuarioRecuperacao ?? ''),
            'email'    => (string)($emailUsuarioRecuperacao ?? ''),
            'telefone' => (string)($telefoneUsuarioRecuperacao ?? ''),
        ]);
        if ($erroPoliticaRecuperacao !== null) {
            out([
                'ok'       => false,
                'code'     => $erroPoliticaRecuperacao['code'],
                'user_msg' => $erroPoliticaRecuperacao['user_msg'],
                'field'    => $erroPoliticaRecuperacao['campo'] === 'confirmacao' ? 'confirmar_senha' : 'nova_senha',
            ], 422);
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
 * LIMITE DE TENTATIVAS DO LOGIN CENTRAL (staff e Super Admin)
 * ==========================================================
 * Dois contadores, ambos na tabela login_limite_conta_ip (criada manualmente):
 *
 * 1) CONTA (A5.1) — independente do IP, do domínio e do ambiente; sempre ativo.
 *    - Chave: SHA-256 (binário) de prefixo próprio + e-mail normalizado. E-mails
 *      inexistentes são contados da mesma forma (sem enumeração de contas).
 *    - 20 falhas em 15 minutos a partir da primeira falha -> bloqueio de 15 minutos.
 *
 * 2) CONTA + IP (Etapa 5.2) — inalterado.
 *    - Chave: SHA-256 (binário) de e-mail normalizado + IP (REMOTE_ADDR; IPv6 por
 *      prefixo /64). Cabeçalhos de proxy são ignorados.
 *    - 10 falhas em 15 minutos -> bloqueio de 5 minutos.
 *    - Somente com origem confiável: ambiente local, ou produção depois de confirmar
 *      a topologia de proxy/CDN (LOGIN_LIMITE_ATIVO_FORA_DO_LOCAL).
 *
 * Regras comuns:
 * - E-mail e IP não são gravados em texto.
 * - Verificado antes da busca do usuário e de password_verify; e-mail inexistente,
 *   conta sem hash e senha incorreta recebem o mesmo tratamento e a mesma mensagem.
 * - Tentativas durante um bloqueio não são contadas nem prolongam a penalidade.
 *   Terminado o bloqueio ou vencida a janela, a contagem recomeça.
 * - Login com senha correta apaga os contadores aplicáveis.
 * - Concorrência: um GET_LOCK por conta (e-mail) serializa leitura, gravação e
 *   exclusão dos dois contadores; liberado em todos os caminhos (inclusive no shutdown).
 * - Falha técnica do limitador: o login segue (fail-open) com mensagem genérica, e
 *   apenas um erro técnico sem e-mail, IP, senha ou hash é registrado.
 * - Não altera a recuperação de senha (tratada antes deste ponto).
 *
 * Marcador de liberação (A5.1-R2):
 * - Gravado somente depois de uma redefinição administrativa de senha concluída
 *   (Super Admin redefinindo outro Super Admin). Chave: SHA-256 (binário) de
 *   prefixo próprio + e-mail normalizado; o horário da liberação (T) fica em
 *   primeira_falha_em, com falhas = 0 e bloqueado_ate = NULL.
 * - Aplicado aqui somente com o GET_LOCK da conta e antes da leitura dos
 *   contadores: apaga apenas os contadores desta tentativa (conta e conta + IP
 *   da origem atual) cuja janela começou antes de T. Contadores iniciados depois
 *   de T (tentativas posteriores à liberação) são preservados.
 * - Não é consumido: vale por LOGIN_LIMITE_MARCADOR_VALIDADE_SEGUNDOS, para
 *   alcançar o contador conta + IP de qualquer origem sem conhecer o IP.
 *   Depois disso todo contador anterior a T já teria vencido; o marcador vencido
 *   é ignorado e removido (ou pela limpeza diária de registros antigos).
 */
const LOGIN_LIMITE_MAX_FALHAS = 10;
const LOGIN_LIMITE_JANELA_SEGUNDOS = 900;
const LOGIN_LIMITE_BLOQUEIO_SEGUNDOS = 300;
const LOGIN_LIMITE_CONTA_MAX_FALHAS = 20;
const LOGIN_LIMITE_CONTA_BLOQUEIO_SEGUNDOS = 900;
const LOGIN_LIMITE_ESPERA_LOCK_SEGUNDOS = 5;
const LOGIN_LIMITE_RETRY_CONTENCAO_SEGUNDOS = 60;
// Janela (15 min) + margem para uma requisição em andamento no instante da liberação.
const LOGIN_LIMITE_MARCADOR_VALIDADE_SEGUNDOS = 960;
// Conta + IP fora do ambiente local: só ativar depois de confirmar a topologia de
// proxy/CDN da produção. O contador por conta não depende desta constante.
const LOGIN_LIMITE_ATIVO_FORA_DO_LOCAL = false;

/**
 * Ambiente local, decidido somente por dados do servidor (mesmo critério do cadastro público):
 * IP remoto ou do servidor em faixa local/privada, nenhum cabeçalho de proxy e host
 * amagenda.local/localhost. Aqui o resultado só ATIVA o limite; nunca o desativa.
 */
function loginAmbienteLocal(): bool
{
    $ehIpLocalOuPrivado = static function (string $ip): bool {
        $ip = strtolower(trim($ip));
        if ($ip === '::1') {
            return true;
        }
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $binario = inet_pton($ip);
        if ($binario === false || strlen($binario) !== 4) {
            return false;
        }
        $octetos = unpack('C4', $binario);
        return is_array($octetos) && (
            $octetos[1] === 127
            || $octetos[1] === 10
            || ($octetos[1] === 172 && $octetos[2] >= 16 && $octetos[2] <= 31)
            || ($octetos[1] === 192 && $octetos[2] === 168)
        );
    };

    if (!$ehIpLocalOuPrivado((string)($_SERVER['REMOTE_ADDR'] ?? ''))
        && !$ehIpLocalOuPrivado((string)($_SERVER['SERVER_ADDR'] ?? ''))) {
        return false;
    }
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_VIA'] as $cabecalho) {
        if (isset($_SERVER[$cabecalho])) {
            return false;
        }
    }
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = preg_replace('/:[0-9]{1,5}$/', '', $host) ?? '';
    return in_array($host, ['amagenda.local', 'localhost'], true);
}

/** Executa uma consulta preparada do limitador; devolve as linhas (SELECT) e lança exceção em falha. */
function loginLimiteConsultar(mysqli $conexao, string $sql, string $tipos, mixed ...$valores): array
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta do limite de login.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta do limite de login.');
    }
    $resultado = $stmt->get_result();
    $linhas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $linhas;
}

/** IP usado na chave: IPv4 completo (inclusive IPv4 mapeado em IPv6) ou prefixo /64 do IPv6. */
function loginLimiteIpChave(string $ip): string
{
    $binario = inet_pton($ip);
    if ($binario === false) {
        return $ip;
    }
    if (strlen($binario) === 16) {
        if (substr($binario, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return (string)inet_ntop(substr($binario, 12));
        }
        return (string)inet_ntop(substr($binario, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return (string)inet_ntop($binario);
}

/** Texto do tempo de espera, arredondado para cima em minutos. */
function loginLimiteMensagemBloqueio(int $segundos): string
{
    $minutos = max(1, (int)ceil($segundos / 60));
    return 'Muitas tentativas de acesso. Aguarde ' . $minutos . ($minutos === 1 ? ' minuto' : ' minutos') . ' e tente novamente.';
}

/** Libera o lock da combinação, se houver (idempotente; o MySQL também libera ao fim da conexão). */
function loginLimiteLiberarLock(mysqli $conexao, array &$limite): void
{
    $nomeLock = $limite['lock'] ?? null;
    if ($nomeLock === null) {
        return;
    }
    $limite['lock'] = null;
    try {
        loginLimiteConsultar($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', $nomeLock);
    } catch (Throwable) {
        // O lock é liberado automaticamente quando a conexão termina.
    }
}

/** Resposta 429 de bloqueio, com Retry-After em segundos. */
function loginLimiteResponderBloqueio(mysqli $conexao, array &$limite, int $segundos): void
{
    loginLimiteLiberarLock($conexao, $limite);
    $segundos = max(1, $segundos);
    header('Retry-After: ' . $segundos);
    out([
        'ok' => false,
        'step' => 'rate_limit',
        'code' => 'LOGIN_TOO_MANY_ATTEMPTS',
        'user_msg' => loginLimiteMensagemBloqueio($segundos),
        'data' => ['retry_after' => $segundos],
    ], 429);
}

/** Lê um contador: falhas válidas na janela, se a janela é nova e segundos de bloqueio vigente. */
function loginLimiteLerContador(mysqli $conexao, string $chave): array
{
    $linhas = loginLimiteConsultar(
        $conexao,
        'SELECT falhas,
                TIMESTAMPDIFF(SECOND, primeira_falha_em, CURRENT_TIMESTAMP(6)) AS idade_janela,
                IF(bloqueado_ate IS NULL, NULL,
                   CEIL(TIMESTAMPDIFF(MICROSECOND, CURRENT_TIMESTAMP(6), bloqueado_ate) / 1000000)) AS segundos_bloqueio
           FROM login_limite_conta_ip
          WHERE chave = ?
          LIMIT 1',
        's',
        $chave
    );
    $registro = $linhas[0] ?? null;

    $estado = ['falhas' => 0, 'nova_janela' => true, 'segundos_bloqueio' => 0];
    if ($registro === null) {
        return $estado;
    }
    if ($registro['segundos_bloqueio'] !== null) {
        if ((int)$registro['segundos_bloqueio'] > 0) {
            $estado['segundos_bloqueio'] = (int)$registro['segundos_bloqueio'];
        }
        // Bloqueio encerrado: a contagem recomeça do zero.
        return $estado;
    }
    if ((int)$registro['idade_janela'] < LOGIN_LIMITE_JANELA_SEGUNDOS) {
        $estado['falhas'] = max(0, (int)$registro['falhas']);
        $estado['nova_janela'] = false;
    }
    return $estado;
}

/** Grava uma falha num contador; devolve os segundos de bloqueio aplicados (0 = sem bloqueio). */
function loginLimiteGravarFalha(mysqli $conexao, string $chave, array $estado, int $maxFalhas, int $bloqueioSegundos): int
{
    $novasFalhas = (int)$estado['falhas'] + 1;
    $bloquear = $novasFalhas >= $maxFalhas ? 1 : 0;
    $novaJanela = ($estado['nova_janela'] ?? true) ? 1 : 0;

    loginLimiteConsultar(
        $conexao,
        'INSERT INTO login_limite_conta_ip (chave, falhas, primeira_falha_em, ultima_falha_em, bloqueado_ate)
         VALUES (?, ?, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6),
                 IF(? = 1, DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL ? SECOND), NULL))
         ON DUPLICATE KEY UPDATE
             falhas = VALUES(falhas),
             primeira_falha_em = IF(? = 1, VALUES(primeira_falha_em), primeira_falha_em),
             ultima_falha_em = VALUES(ultima_falha_em),
             bloqueado_ate = VALUES(bloqueado_ate)',
        'siiii',
        $chave,
        $novasFalhas,
        $bloquear,
        $bloqueioSegundos,
        $novaJanela
    );

    return $bloquear === 1 ? $bloqueioSegundos : 0;
}

/**
 * Aplica o marcador de liberação da conta, se houver. Chamar somente com o lock da
 * conta e antes da leitura dos contadores: apaga, entre as chaves informadas, apenas
 * os contadores cuja janela começou antes do horário da liberação.
 */
function loginLimiteAplicarMarcador(mysqli $conexao, string $marcador, array $chaves): void
{
    $linhas = loginLimiteConsultar(
        $conexao,
        'SELECT DATE_FORMAT(primeira_falha_em, \'%Y-%m-%d %H:%i:%s.%f\') AS liberado_em,
                TIMESTAMPDIFF(SECOND, primeira_falha_em, CURRENT_TIMESTAMP(6)) AS idade
           FROM login_limite_conta_ip
          WHERE chave = ?
          LIMIT 1',
        's',
        $marcador
    );
    $registro = $linhas[0] ?? null;
    if ($registro === null || $registro['liberado_em'] === null) {
        return;
    }
    if ((int)$registro['idade'] >= LOGIN_LIMITE_MARCADOR_VALIDADE_SEGUNDOS) {
        // Vencido: todo contador anterior à liberação já expirou.
        loginLimiteConsultar($conexao, 'DELETE FROM login_limite_conta_ip WHERE chave = ?', 's', $marcador);
        return;
    }
    foreach ($chaves as $chave) {
        loginLimiteConsultar(
            $conexao,
            'DELETE FROM login_limite_conta_ip WHERE chave = ? AND primeira_falha_em < ?',
            'ss',
            (string)$chave,
            (string)$registro['liberado_em']
        );
    }
}

/**
 * Registra uma falha de credenciais nos contadores ativos e responde. Usado nos três
 * pontos de credenciais inválidas (e-mail inexistente, conta sem hash e senha
 * incorreta), com a mesma mensagem para todos.
 */
function loginLimiteResponderFalha(mysqli $conexao, array &$limite): void
{
    $restantes = null;

    if (($limite['ativo'] ?? false) === true && ($limite['lock'] ?? null) !== null) {
        try {
            $segundosBloqueio = 0;
            $restantesContadores = [];
            foreach ($limite['contadores'] as $contador) {
                $segundosBloqueio = max($segundosBloqueio, loginLimiteGravarFalha(
                    $conexao,
                    (string)$contador['chave'],
                    (array)$contador['estado'],
                    (int)$contador['max_falhas'],
                    (int)$contador['bloqueio_segundos']
                ));
                $restantesContadores[] = (int)$contador['max_falhas'] - ((int)$contador['estado']['falhas'] + 1);
            }

            // Limpeza ocasional de combinações antigas e sem bloqueio vigente.
            if (random_int(1, 100) === 1) {
                try {
                    loginLimiteConsultar(
                        $conexao,
                        'DELETE FROM login_limite_conta_ip
                          WHERE ultima_falha_em < DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 DAY)
                            AND (bloqueado_ate IS NULL OR bloqueado_ate < CURRENT_TIMESTAMP(6))
                          LIMIT 500',
                        ''
                    );
                } catch (Throwable) {
                    error_log('[login_limite] Limpeza de registros antigos não concluída.');
                }
            }

            if ($segundosBloqueio > 0) {
                loginLimiteResponderBloqueio($conexao, $limite, $segundosBloqueio);
            }
            $restantes = $restantesContadores ? max(1, min($restantesContadores)) : null;
        } catch (Throwable $e) {
            error_log('[login_limite] ' . get_class($e) . ': falha não contabilizada; login segue sem limite nesta requisição.');
            $restantes = null;
        }
    }

    loginLimiteLiberarLock($conexao, $limite);

    $mensagem = 'E-mail ou senha inválidos.';
    $resposta = [
        'ok' => false,
        'step' => 'credentials',
        'code' => 'LOGIN_INVALID_CREDENTIALS',
    ];
    if ($restantes !== null) {
        $mensagem .= $restantes === 1 ? ' Resta 1 tentativa.' : ' Restam ' . $restantes . ' tentativas.';
        $resposta['data'] = ['tentativas_restantes' => $restantes];
    }
    $resposta['user_msg'] = $mensagem;

    out($resposta, 401);
}

/** Senha correta: apaga os contadores aplicáveis (com o lock da conta) e libera o lock. */
function loginLimiteRegistrarSucesso(mysqli $conexao, array &$limite): void
{
    if (($limite['ativo'] ?? false) === true && ($limite['lock'] ?? null) !== null) {
        try {
            foreach ($limite['contadores'] as $contador) {
                loginLimiteConsultar($conexao, 'DELETE FROM login_limite_conta_ip WHERE chave = ?', 's', (string)$contador['chave']);
            }
        } catch (Throwable $e) {
            error_log('[login_limite] ' . get_class($e) . ': contagem não zerada após login válido.');
        }
    }
    loginLimiteLiberarLock($conexao, $limite);
}

$loginLimite = ['ativo' => false, 'lock' => null, 'contadores' => []];
$ipLogin = auditoriaNormalizarIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
// Conta + IP só com origem confiável; o contador por conta independe do IP e do ambiente.
$loginLimiteIpConfiavel = $ipLogin !== null && (LOGIN_LIMITE_ATIVO_FORA_DO_LOCAL || loginAmbienteLocal());

// Garante a liberação do lock em qualquer saída (out() encerra com exit).
register_shutdown_function(static function () use ($conexao, &$loginLimite): void {
    loginLimiteLiberarLock($conexao, $loginLimite);
});

$segundosBloqueio = 0;
try {
    $chaveConta = hash('sha256', "amagenda:login_central:conta\n" . $email, true);
    $nomeLock = 'amagenda:login_conta:' . substr(bin2hex($chaveConta), 0, 40);
    $lock = loginLimiteConsultar(
        $conexao,
        'SELECT GET_LOCK(?, ' . LOGIN_LIMITE_ESPERA_LOCK_SEGUNDOS . ') AS adquirido',
        's',
        $nomeLock
    );
    $adquirido = $lock[0]['adquirido'] ?? null;
    if ($adquirido === null) {
        throw new RuntimeException('GET_LOCK indisponível.');
    }

    if ((int)$adquirido !== 1) {
        // Muitas tentativas simultâneas da mesma conta aguardando a vez.
        $segundosBloqueio = LOGIN_LIMITE_RETRY_CONTENCAO_SEGUNDOS;
    } else {
        $loginLimite['lock'] = $nomeLock;

        $contadores = [[
            'chave' => $chaveConta,
            'max_falhas' => LOGIN_LIMITE_CONTA_MAX_FALHAS,
            'bloqueio_segundos' => LOGIN_LIMITE_CONTA_BLOQUEIO_SEGUNDOS,
        ]];
        if ($loginLimiteIpConfiavel) {
            $contadores[] = [
                'chave' => hash('sha256', $email . "\n" . loginLimiteIpChave((string)$ipLogin), true),
                'max_falhas' => LOGIN_LIMITE_MAX_FALHAS,
                'bloqueio_segundos' => LOGIN_LIMITE_BLOQUEIO_SEGUNDOS,
            ];
        }

        // Liberação administrativa pendente (A5.1-R2): sob o lock, antes da leitura.
        loginLimiteAplicarMarcador(
            $conexao,
            hash('sha256', "amagenda:login_central:liberar_conta\n" . $email, true),
            array_column($contadores, 'chave')
        );

        foreach ($contadores as $indice => $contador) {
            $estado = loginLimiteLerContador($conexao, (string)$contador['chave']);
            $contadores[$indice]['estado'] = $estado;
            $segundosBloqueio = max($segundosBloqueio, (int)$estado['segundos_bloqueio']);
        }

        $loginLimite['ativo'] = true;
        $loginLimite['contadores'] = $contadores;
    }
} catch (Throwable $e) {
    error_log('[login_limite] ' . get_class($e) . ': limitador indisponível; login segue sem limite nesta requisição.');
    loginLimiteLiberarLock($conexao, $loginLimite);
    $loginLimite['ativo'] = false;
    $loginLimite['contadores'] = [];
    $segundosBloqueio = 0;
}

if ($segundosBloqueio > 0) {
    // Bloqueio vigente: nada é contado, e a penalidade não é prolongada.
    loginLimiteResponderBloqueio($conexao, $loginLimite, $segundosBloqueio);
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
    loginLimiteResponderFalha($conexao, $loginLimite);
}

$hash = (string)($user['senha_hash'] ?? '');

if ($hash === '') {
    password_verify($senha, LOGIN_HASH_FICTICIO);
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_indisponiveis', $empresaAuditoriaId, $email);
    loginLimiteResponderFalha($conexao, $loginLimite);
}

if (!password_verify($senha, $hash)) {
    registrarFalhaLogin($conexao, 'autenticacao.credenciais_invalidas', 'credenciais_invalidas', $empresaAuditoriaId, $email);
    loginLimiteResponderFalha($conexao, $loginLimite);
}

// Senha válida: zera a contagem da combinação conta + IP e libera o lock.
loginLimiteRegistrarSucesso($conexao, $loginLimite);

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

    // Marcador da credencial validada neste login (A6.1); somente no servidor.
    $_SESSION[SESSAO_CREDENCIAL_INTERNA] = sessaoCredencialImpressao((string)$user['senha_hash']);

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
// A pré-sessão guarda a credencial validada (A6.1): a seleção não consolida a
// sessão se a senha mudar antes da escolha da empresa.
$csrfSelecao = loginMultiempresaCriarPreSessao($idUsuario, sessaoCredencialImpressao((string)$user['senha_hash']));

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
