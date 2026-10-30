<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SMS OTP - SERVIÇO CENTRAL (INFOBIP + otp_desafio)
|--------------------------------------------------------------------------
|
| - código aleatório seguro (random_int); somente um hash HMAC com sal é
|   gravado (nunca o código);
| - validade de 5 minutos e tentativas persistentes (otp_desafio);
| - um desafio ativo por principal (slot_desafio_ativo): um novo pedido
|   invalida o anterior;
| - desafio vinculado à sessão por um segredo da própria sessão (sobrevive
|   a session_regenerate_id) e à empresa/principal reais;
| - consumo único em transação, com a linha bloqueada (FOR UPDATE);
| - principal 'ficticio' mantém resposta e contadores idênticos sem confirmar
|   a existência da conta (recuperação) e sem enviar SMS;
| - sem OTP fixo, sem bypass e sem Twilio.
|
*/

require_once __DIR__ . '/sms.php';

const SMS_OTP_VALIDADE_SEGUNDOS = 300;
const SMS_OTP_MAX_TENTATIVAS = 5;
const SMS_OTP_REENVIO_SEGUNDOS = 30;
const SMS_OTP_LIMITE_TELEFONE_HORA = 5;
const SMS_OTP_LIMITE_IP_HORA = 20;
// Primeiro acesso de telefones sem cadastro (SMS real sem conta): teto por empresa/hora contra abuso e fraude de custo.
const SMS_OTP_LIMITE_NOVOS_EMPRESA_HORA = 30;

function smsOtpResultado(
    bool $sucesso,
    string $codigo,
    string $mensagem = '',
    array $dados = []
): array {
    return [
        'sucesso' => $sucesso,
        'codigo' => $codigo,
        'mensagem' => $mensagem,
        'dados' => $dados,
    ];
}

/** Normaliza telefone brasileiro para E.164 (+55DDDNNNNNNNNN). */
function smsOtpNormalizarTelefone(string $telefone): ?string
{
    $digitos = preg_replace('/\D+/', '', trim($telefone)) ?? '';

    if ($digitos === '') {
        return null;
    }
    if (strlen($digitos) === 11) {
        $digitos = '55' . $digitos;
    }
    if (strlen($digitos) !== 13 || !str_starts_with($digitos, '55')) {
        return null;
    }

    return '+' . $digitos;
}

/**
 * Configuração Infobip carregada uma vez por requisição.
 *
 * @return array{ok: bool, habilitado: bool, erros: list<string>, config: array<string, mixed>}
 */
function smsOtpCarga(?array $substituir = null): array
{
    static $carga = null;
    if ($substituir !== null) {
        $carga = $substituir;
    }

    return $carga ??= infobipConfigCarregar();
}

/** SMS só é enviado com configuração válida E habilitada explicitamente. */
function smsOtpConfigurado(): bool
{
    return smsOtpCarga()['habilitado'] === true;
}

function smsOtpHmac(array $carga, string $contexto, string $valor): string
{
    return hash_hmac('sha256', $contexto . '|' . $valor, (string)$carga['config']['hmac_key']);
}

/** Segredo aleatório da sessão que prende o desafio ao navegador, sobrevivendo a regenerate_id. */
function smsOtpVinculoSessao(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
    $atual = $_SESSION['otp_vinculo'] ?? null;
    if (!is_string($atual) || preg_match('/^[0-9a-f]{64}$/', $atual) !== 1) {
        $atual = bin2hex(random_bytes(32));
        $_SESSION['otp_vinculo'] = $atual;
    }

    return $atual;
}

function smsOtpGerarCodigo(): string
{
    return (string)random_int(100000, 999999);
}

/** Formato h1$<sal>$<hmac>; o código nunca é gravado. */
function smsOtpCodigoHash(array $carga, string $codigo): string
{
    $sal = bin2hex(random_bytes(16));

    return 'h1$' . $sal . '$' . hash_hmac('sha256', $sal . '|' . $codigo, (string)$carga['config']['hmac_key']);
}

function smsOtpCodigoConfere(array $carga, string $codigo, string $armazenado): bool
{
    $partes = explode('$', $armazenado);
    if (count($partes) !== 3 || $partes[0] !== 'h1') {
        return false;
    }

    return hash_equals($partes[2], hash_hmac('sha256', $partes[1] . '|' . $codigo, (string)$carga['config']['hmac_key']));
}

/** Combinações aceitas pelas constraints ck_otp_principal e ck_otp_finalidade. */
function smsOtpCombinacaoValida(array $p): bool
{
    $tipo = (string)($p['principal_tipo'] ?? '');
    $finalidade = (string)($p['finalidade'] ?? '');
    $empresa = (int)($p['id_empresa'] ?? 0);
    $cliente = (int)($p['id_cliente'] ?? 0);
    $usuario = (int)($p['id_usuario'] ?? 0);

    return match ($tipo) {
        'cliente' => in_array($finalidade, ['acesso_cliente', 'recuperacao_cliente'], true)
            && $empresa > 0 && $cliente > 0 && $usuario === 0,
        'usuario' => $finalidade === 'recuperacao_usuario' && $empresa === 0 && $usuario > 0 && $cliente === 0,
        'ficticio' => in_array($finalidade, ['acesso_cliente', 'recuperacao_cliente', 'recuperacao_usuario'], true)
            && $usuario === 0 && $cliente === 0,
        default => false,
    };
}

function smsOtpChavePrincipal(array $carga, array $p, string $telefoneE164): string
{
    $identidade = match ((string)$p['principal_tipo']) {
        'cliente' => 'c' . (int)$p['id_cliente'],
        'usuario' => 'u' . (int)$p['id_usuario'],
        default => 'f' . smsOtpHmac($carga, 'telefone', $telefoneE164),
    };

    return smsOtpHmac($carga, 'principal', implode('|', [
        (string)$p['principal_tipo'], (string)$p['finalidade'], (int)($p['id_empresa'] ?? 0), $identidade,
    ]));
}

/**
 * O desafio só vale para o principal real: empresa do cliente confere com a linha de cliente
 * e usuário interno ativo. Não confia apenas nas chaves estrangeiras individuais.
 */
function smsOtpPrincipalConfere(mysqli $conexao, array $p): bool
{
    $tipo = (string)$p['principal_tipo'];
    if ($tipo === 'cliente') {
        return smsConsultar(
            $conexao,
            "SELECT 1 FROM cliente WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo' LIMIT 1",
            'ii',
            (int)$p['id_cliente'],
            (int)$p['id_empresa']
        ) !== [];
    }
    if ($tipo === 'usuario') {
        return smsConsultar(
            $conexao,
            "SELECT 1 FROM usuario WHERE id_usuario = ? AND status = 'ativo' AND tipo_usuario = 'usuario' LIMIT 1",
            'i',
            (int)$p['id_usuario']
        ) !== [];
    }

    return true;
}

/**
 * Cria um desafio (invalidando o anterior do mesmo principal) e, quando permitido, envia o SMS.
 *
 * $p: principal_tipo, finalidade, id_empresa, id_cliente, id_usuario, telefone (E.164), ip, enviar (bool).
 * Desafios 'ficticio' de recuperação nunca enviam SMS.
 *
 * @param (callable(array): array)|null $transporte
 */
function smsOtpSolicitar(mysqli $conexao, array $p, ?callable $transporte = null): array
{
    $carga = smsOtpCarga();
    if ($carga['ok'] !== true) {
        return smsOtpResultado(false, 'SMS_CONFIG_INVALIDA', 'Serviço de SMS temporariamente indisponível.');
    }
    $telefone = smsOtpNormalizarTelefone((string)($p['telefone'] ?? ''));
    if ($telefone === null) {
        return smsOtpResultado(false, 'TELEFONE_INVALIDO', 'Telefone inválido.');
    }
    if (!smsOtpCombinacaoValida($p)) {
        return smsOtpResultado(false, 'SMS_OTP_PRINCIPAL_INVALIDO', 'Não foi possível enviar o código agora.');
    }

    $tipo = (string)$p['principal_tipo'];
    $finalidade = (string)$p['finalidade'];
    $decoy = $tipo === 'ficticio' && $finalidade !== 'acesso_cliente';
    $enviar = ($p['enviar'] ?? true) === true && !$decoy;

    try {
        if (!smsOtpPrincipalConfere($conexao, $p)) {
            return smsOtpResultado(false, 'SMS_OTP_PRINCIPAL_INVALIDO', 'Não foi possível enviar o código agora.');
        }

        $chave = smsOtpChavePrincipal($carga, $p, $telefone);
        $telefoneHash = smsOtpHmac($carga, 'telefone', $telefone);
        $ipHash = smsOtpHmac($carga, 'ip', (string)($p['ip'] ?? ''));
        $sessaoHash = smsOtpHmac($carga, 'sessao', smsOtpVinculoSessao());
        $empresa = (int)($p['id_empresa'] ?? 0) > 0 ? (int)$p['id_empresa'] : null;
        $cliente = (int)($p['id_cliente'] ?? 0) > 0 ? (int)$p['id_cliente'] : null;
        $usuario = (int)($p['id_usuario'] ?? 0) > 0 ? (int)$p['id_usuario'] : null;

        $porTelefone = smsConsultar(
            $conexao,
            'SELECT COUNT(*) AS n FROM otp_desafio WHERE telefone_hash = UNHEX(?) AND criado_em > (NOW(6) - INTERVAL 1 HOUR)',
            's',
            $telefoneHash
        );
        $porIp = smsConsultar(
            $conexao,
            'SELECT COUNT(*) AS n FROM otp_desafio WHERE ip_hash = UNHEX(?) AND criado_em > (NOW(6) - INTERVAL 1 HOUR)',
            's',
            $ipHash
        );
        $excedeuNovos = false;
        if ($enviar && $tipo === 'ficticio' && $empresa !== null) {
            $porEmpresa = smsConsultar(
                $conexao,
                "SELECT COUNT(*) AS n FROM otp_desafio
                  WHERE id_empresa = ? AND principal_tipo = 'ficticio' AND finalidade = 'acesso_cliente'
                    AND criado_em > (NOW(6) - INTERVAL 1 HOUR)",
                'i',
                $empresa
            );
            $excedeuNovos = (int)$porEmpresa[0]['n'] >= SMS_OTP_LIMITE_NOVOS_EMPRESA_HORA;
        }
        if ($excedeuNovos || (int)$porTelefone[0]['n'] >= SMS_OTP_LIMITE_TELEFONE_HORA || (int)$porIp[0]['n'] >= SMS_OTP_LIMITE_IP_HORA) {
            return smsOtpResultado(false, 'SMS_OTP_LIMITE', 'Aguarde alguns minutos antes de solicitar outro código.', ['retry_after' => 600]);
        }

        $codigo = smsOtpGerarCodigo();
        $hashCodigo = smsOtpCodigoHash($carga, $codigo);

        $conexao->begin_transaction();
        try {
            $anterior = smsConsultar(
                $conexao,
                'SELECT TIMESTAMPDIFF(SECOND, criado_em, NOW(6)) AS idade FROM otp_desafio
                  WHERE chave_principal = UNHEX(?) ORDER BY id_otp_desafio DESC LIMIT 1 FOR UPDATE',
                's',
                $chave
            );
            if ($anterior !== [] && (int)$anterior[0]['idade'] < SMS_OTP_REENVIO_SEGUNDOS) {
                $conexao->rollback();

                return smsOtpResultado(false, 'SMS_OTP_REENVIO_AGUARDAR', 'Aguarde alguns segundos antes de solicitar outro código.', [
                    'retry_after' => SMS_OTP_REENVIO_SEGUNDOS - (int)$anterior[0]['idade'],
                ]);
            }
            smsExecutar(
                $conexao,
                "UPDATE otp_desafio SET status = 'invalidado', invalidado_em = NOW(6)
                  WHERE chave_principal = UNHEX(?) AND status IN ('pendente_envio', 'ativo', 'validado')",
                's',
                $chave
            );
            smsExecutar(
                $conexao,
                "INSERT INTO otp_desafio
                    (id_empresa, id_usuario, id_cliente, principal_tipo, finalidade, chave_principal, telefone_hash, ip_hash,
                     sessao_hash, codigo_hash, status, max_tentativas, expira_em)
                 VALUES (?, ?, ?, ?, ?, UNHEX(?), UNHEX(?), UNHEX(?), UNHEX(?), ?, ?, ?, NOW(6) + INTERVAL ? SECOND)",
                'iiissssssssii',
                $empresa,
                $usuario,
                $cliente,
                $tipo,
                $finalidade,
                $chave,
                $telefoneHash,
                $ipHash,
                $sessaoHash,
                $hashCodigo,
                $enviar ? 'pendente_envio' : 'ativo',
                SMS_OTP_MAX_TENTATIVAS,
                SMS_OTP_VALIDADE_SEGUNDOS
            );
            $idDesafio = (int)smsConsultar($conexao, 'SELECT LAST_INSERT_ID() AS id')[0]['id'];
            $conexao->commit();
        } catch (Throwable $e) {
            $conexao->rollback();
            if (in_array((int)$e->getCode(), [1062, 1213, 1205], true)) {
                return smsOtpResultado(false, 'SMS_OTP_CONCORRENCIA', 'Aguarde alguns segundos antes de solicitar outro código.', ['retry_after' => SMS_OTP_REENVIO_SEGUNDOS]);
            }
            throw $e;
        }
    } catch (Throwable $e) {
        smsLog('otp_solicitar_falhou', []);

        return smsOtpResultado(false, 'SMS_OTP_INDISPONIVEL', 'Não foi possível enviar o código agora. Tente novamente.');
    }

    $dados = ['id_otp_desafio' => $idDesafio, 'expires_in' => SMS_OTP_VALIDADE_SEGUNDOS, 'decoy' => $decoy];
    if (!$enviar) {
        return smsOtpResultado(true, 'SMS_OTP_CRIADO', '', $dados);
    }

    $texto = $finalidade === 'acesso_cliente'
        ? 'AmAgenda: seu código de acesso é ' . $codigo . '. Ele expira em 5 minutos.'
        : 'AmAgenda: seu código de recuperação é ' . $codigo . '. Ele expira em 5 minutos.';
    $envio = smsEnviarOtp($conexao, $carga, $idDesafio, $telefone, $texto, $transporte);
    unset($texto, $codigo);

    if ($envio['sucesso'] !== true) {
        return smsOtpResultado(false, 'SMS_OTP_ENVIO_FALHOU', 'Não foi possível enviar o código agora. Tente novamente.', $dados + ['estado_envio' => $envio['estado']]);
    }

    return smsOtpResultado(true, 'SMS_OTP_ENVIADO', '', $dados + ['estado_envio' => $envio['estado']]);
}

/**
 * Valida e consome (uso único) o desafio. $p: os mesmos campos de smsOtpSolicitar + id_otp_desafio + codigo.
 * A tentativa é contada e persistida antes da decisão, inclusive em caso de erro.
 */
function smsOtpValidar(mysqli $conexao, array $p): array
{
    $carga = smsOtpCarga();
    $generico = static fn (string $codigo): array => smsOtpResultado(false, $codigo, 'Código inválido ou expirado.');
    if ($carga['ok'] !== true) {
        return smsOtpResultado(false, 'SMS_CONFIG_INVALIDA', 'Serviço de SMS temporariamente indisponível.');
    }
    $telefone = smsOtpNormalizarTelefone((string)($p['telefone'] ?? ''));
    $codigo = preg_replace('/\D+/', '', (string)($p['codigo'] ?? '')) ?? '';
    $idDesafio = (int)($p['id_otp_desafio'] ?? 0);
    if ($telefone === null || strlen($codigo) !== 6 || $idDesafio <= 0 || !smsOtpCombinacaoValida($p)) {
        return $generico('SMS_OTP_CODIGO_INVALIDO');
    }

    $chave = smsOtpChavePrincipal($carga, $p, $telefone);
    $sessaoHash = smsOtpHmac($carga, 'sessao', smsOtpVinculoSessao());

    try {
        $conexao->begin_transaction();
        $linhas = smsConsultar(
            $conexao,
            "SELECT o.status, o.codigo_hash, o.tentativas_validacao, o.max_tentativas, o.id_empresa AS otp_empresa,
                    LOWER(HEX(o.sessao_hash)) AS sessao_hex, (o.expira_em <= NOW(6)) AS expirado,
                    c.id_empresa AS cliente_empresa, c.status AS cliente_status
               FROM otp_desafio o
               LEFT JOIN cliente c ON c.id_cliente = o.id_cliente
              WHERE o.id_otp_desafio = ? AND o.chave_principal = UNHEX(?)
              FOR UPDATE OF o",
            'is',
            $idDesafio,
            $chave
        );
        if ($linhas === []) {
            $conexao->rollback();

            return $generico('SMS_OTP_NAO_ENCONTRADO');
        }
        $o = $linhas[0];

        if ((string)$o['status'] !== 'ativo' || !hash_equals((string)$o['sessao_hex'], $sessaoHash)) {
            $conexao->rollback();

            return $generico('SMS_OTP_NAO_ENCONTRADO');
        }
        if ((string)$p['principal_tipo'] === 'cliente'
            && ((int)$o['cliente_empresa'] !== (int)$o['otp_empresa'] || (string)$o['cliente_status'] !== 'ativo')) {
            smsExecutar($conexao, "UPDATE otp_desafio SET status = 'invalidado', invalidado_em = NOW(6) WHERE id_otp_desafio = ? AND status = 'ativo'", 'i', $idDesafio);
            $conexao->commit();

            return $generico('SMS_OTP_PRINCIPAL_INVALIDO');
        }
        if ((int)$o['expirado'] === 1) {
            smsExecutar($conexao, "UPDATE otp_desafio SET status = 'invalidado', invalidado_em = NOW(6) WHERE id_otp_desafio = ? AND status = 'ativo'", 'i', $idDesafio);
            $conexao->commit();

            return smsOtpResultado(false, 'SMS_OTP_EXPIRADO', 'O código expirou. Solicite um novo.');
        }
        $tentativas = (int)$o['tentativas_validacao'];
        $maximo = (int)$o['max_tentativas'];
        if ($tentativas >= $maximo) {
            smsExecutar($conexao, "UPDATE otp_desafio SET status = 'bloqueado', invalidado_em = NOW(6) WHERE id_otp_desafio = ? AND status = 'ativo'", 'i', $idDesafio);
            $conexao->commit();

            return smsOtpResultado(false, 'SMS_OTP_TENTATIVAS', 'Solicite um novo código para continuar.');
        }

        $confere = smsOtpCodigoConfere($carga, $codigo, (string)$o['codigo_hash']);
        // A tentativa é gravada antes da decisão e permanece mesmo quando o código está errado.
        smsExecutar($conexao, 'UPDATE otp_desafio SET tentativas_validacao = tentativas_validacao + 1 WHERE id_otp_desafio = ?', 'i', $idDesafio);

        if ($confere) {
            $consumidas = smsExecutar(
                $conexao,
                "UPDATE otp_desafio SET status = 'consumido', validado_em = NOW(6), consumido_em = NOW(6)
                  WHERE id_otp_desafio = ? AND status = 'ativo'",
                'i',
                $idDesafio
            );
            $conexao->commit();

            return $consumidas === 1
                ? smsOtpResultado(true, 'SMS_OTP_VALIDADO', '', ['id_otp_desafio' => $idDesafio])
                : $generico('SMS_OTP_NAO_ENCONTRADO');
        }

        if ($tentativas + 1 >= $maximo) {
            smsExecutar($conexao, "UPDATE otp_desafio SET status = 'bloqueado', invalidado_em = NOW(6) WHERE id_otp_desafio = ? AND status = 'ativo'", 'i', $idDesafio);
        }
        $conexao->commit();

        return $generico($tentativas + 1 >= $maximo ? 'SMS_OTP_TENTATIVAS' : 'SMS_OTP_CODIGO_INVALIDO');
    } catch (Throwable $e) {
        $conexao->rollback();
        smsLog('otp_validar_falhou', []);

        return smsOtpResultado(false, 'SMS_OTP_INDISPONIVEL', 'Não foi possível validar o código agora.');
    }
}
