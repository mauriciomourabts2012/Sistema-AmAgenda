<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| E-MAIL OTP - ENVIO PELO GMAIL (mensagem_envio, canal 'email')
|--------------------------------------------------------------------------
|
| Espelha smsEnviarOtp(): o registro "enviando" é confirmado ANTES do SMTP, nenhuma
| transação fica aberta durante a rede, e um desafio gera no máximo uma mensagem.
| Timeout ou resposta ausente depois do conteúdo => resultado_incerto, sem reenvio
| automático. Logs e erros gravados nunca contêm e-mail completo, código ou texto.
|
| A criação/validação do desafio continua em sms_otp.php (otp_desafio).
|
*/

require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/../_gateways/gmail.php';

const EMAIL_PROVEDOR = 'gmail';

/** Carga do Gmail uma vez por requisição (substituível em testes). */
function emailOtpCarga(?array $substituir = null): array
{
    static $carga = null;
    if ($substituir !== null) {
        $carga = $substituir;
    }

    return $carga ??= gmailConfigCarregar();
}

function emailOtpConfigurado(): bool
{
    return emailOtpCarga()['habilitado'] === true;
}

/** Minúsculas, sem espaços; null se inválido. */
function emailOtpNormalizar(string $email): ?string
{
    $email = strtolower(trim($email));

    return gmailEnderecoValido($email) ? $email : null;
}

/** m***@g***.com: suficiente para suporte, sem revelar o endereço. */
function emailOtpMascarar(string $email): string
{
    $partes = explode('@', $email, 2);
    if (count($partes) !== 2) {
        return '***';
    }
    $dominio = explode('.', $partes[1], 2);

    return substr($partes[0], 0, 1) . '***@' . substr($dominio[0], 0, 1) . '***' . (isset($dominio[1]) ? '.' . $dominio[1] : '');
}

/** Chave determinística, distinta da do SMS: um desafio gera no máximo uma mensagem. */
function emailOtpChaveIdempotencia(int $idOtpDesafio): string
{
    $h = hash('sha256', 'otp-email|' . $idOtpDesafio);

    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}

/**
 * Envia o OTP de um desafio 'pendente_envio' por e-mail. Nunca reenvia por conta própria.
 *
 * @param array{ok: bool, habilitado: bool, erros: list<string>, config: array<string, mixed>} $carga resultado de gmailConfigCarregar()
 * @param (callable(array): array)|null $transporte
 * @return array{sucesso: bool, estado: string, codigo: string}
 */
function emailEnviarOtp(mysqli $conexao, array $carga, int $idOtpDesafio, string $destino, string $assunto, string $texto, ?callable $transporte = null): array
{
    $falha = static fn (string $codigo): array => ['sucesso' => false, 'estado' => 'falha', 'codigo' => $codigo];
    $marcarErro = static function () use ($conexao, $idOtpDesafio): void {
        smsExecutar($conexao, "UPDATE otp_desafio SET status = 'erro_envio' WHERE id_otp_desafio = ? AND status = 'pendente_envio'", 'i', $idOtpDesafio);
    };

    if (($carga['habilitado'] ?? false) !== true) {
        $marcarErro();

        return $falha('EMAIL_DESABILITADO');
    }
    $destino = emailOtpNormalizar($destino) ?? '';
    if ($destino === '') {
        $marcarErro();

        return $falha('EMAIL_DESTINO_INVALIDO');
    }

    $config = $carga['config'];
    $messageId = smsUuidV4();
    $chave = emailOtpChaveIdempotencia($idOtpDesafio);
    $ambiente = (string)$config['ambiente'];

    $conexao->begin_transaction();
    try {
        $otp = smsConsultar($conexao, 'SELECT status FROM otp_desafio WHERE id_otp_desafio = ? FOR UPDATE', 'i', $idOtpDesafio);
        if ($otp === [] || (string)$otp[0]['status'] !== 'pendente_envio') {
            $conexao->rollback();

            return $falha('EMAIL_DESAFIO_INDISPONIVEL');
        }
        $existente = smsConsultar($conexao, 'SELECT status FROM mensagem_envio WHERE id_otp_desafio = ? OR chave_idempotencia = ? LIMIT 1', 'is', $idOtpDesafio, $chave);
        if ($existente !== []) {
            $conexao->rollback();

            return ['sucesso' => false, 'estado' => 'duplicado', 'codigo' => 'EMAIL_JA_REGISTRADO'];
        }
        smsExecutar(
            $conexao,
            "INSERT INTO mensagem_envio (id_otp_desafio, canal, provedor, ambiente, telefone_final4, destino_mascarado, chave_idempotencia, message_id, status, solicitado_em)
             VALUES (?, 'email', 'gmail', ?, NULL, ?, ?, ?, 'enviando', NOW(6))",
            'issss',
            $idOtpDesafio,
            $ambiente,
            emailOtpMascarar($destino),
            $chave,
            $messageId
        );
        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        try {
            $marcarErro();
        } catch (Throwable) {
            smsLog('email_registro_slot_falhou', ['otp' => $idOtpDesafio]);
        }
        smsLog('email_registro_falhou', ['otp' => $idOtpDesafio]);

        return $falha('EMAIL_REGISTRO_INDISPONIVEL');
    }

    $resposta = gmailEnviar($config, $messageId, $destino, $assunto, $texto, $transporte);
    $resultado = (string)$resposta['resultado'];

    $statusMsg = match ($resultado) {
        'aceita' => 'aceita',
        'incerto' => 'resultado_incerto',
        'falha_conexao' => 'erro',
        default => 'rejeitada',
    };
    $final = in_array($statusMsg, SMS_STATUS_FINAIS, true);
    $resumo = match ($resultado) {
        'aceita' => null,
        'incerto' => 'Resultado do envio incerto; sem confirmação final do servidor de e-mail.',
        'falha_conexao' => 'Falha antes do envio; nada foi enviado.',
        default => 'Envio recusado pelo servidor de e-mail.',
    };
    $erroCodigo = $resposta['erro_codigo'] !== null ? (string)$resposta['erro_codigo'] : null;
    $smtp = $resposta['smtp_codigo'] !== null ? (int)$resposta['smtp_codigo'] : null;

    try {
        $conexao->begin_transaction();
        $atualizadas = smsExecutar(
            $conexao,
            "UPDATE mensagem_envio
                SET status = ?, erro_codigo = ?, erro_resumo = ?, status_externo_id = ?,
                    aceito_em = IF(? = 'aceita', NOW(6), aceito_em),
                    concluido_em = IF(?, NOW(6), concluido_em)
              WHERE id_otp_desafio = ? AND status = 'enviando'",
            'sssisii',
            $statusMsg,
            $erroCodigo,
            $resumo,
            $smtp,
            $statusMsg,
            $final ? 1 : 0,
            $idOtpDesafio
        );

        if (in_array($resultado, ['aceita', 'incerto'], true)) {
            smsExecutar(
                $conexao,
                "UPDATE otp_desafio SET status = 'ativo', enviado_em = IF(?, NOW(6), NULL)
                  WHERE id_otp_desafio = ? AND status = 'pendente_envio'",
                'ii',
                $resultado === 'aceita' ? 1 : 0,
                $idOtpDesafio
            );
        } elseif ($atualizadas === 1) {
            $marcarErro();
        }
        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        smsLog('email_conclusao_falhou', ['otp' => $idOtpDesafio]);

        return ['sucesso' => false, 'estado' => 'incerto', 'codigo' => 'EMAIL_CONCLUSAO_INDISPONIVEL'];
    }

    smsLog('email_envio_' . $resultado, [
        'otp' => $idOtpDesafio,
        'message_id' => $messageId,
        'smtp' => $smtp ?? 0,
        'codigo' => $erroCodigo ?? 'EMAIL_ACEITO',
    ]);

    return [
        'sucesso' => $resultado === 'aceita',
        'estado' => $resultado,
        'codigo' => $erroCodigo ?? 'EMAIL_ACEITO',
    ];
}

/**
 * Marca o e-mail do cliente como validado SOMENTE quando o endereço confirmado pelo OTP é
 * exatamente o cadastrado nesse cliente e empresa. Nunca grava um e-mail novo nem troca o de
 * outro cliente. Chamada pelo handler depois de smsOtpValidar() bem-sucedido por e-mail.
 */
function emailOtpMarcarVerificado(mysqli $conexao, int $idEmpresa, int $idCliente, string $email): bool
{
    $email = emailOtpNormalizar($email);
    if ($email === null || $idEmpresa <= 0 || $idCliente <= 0) {
        return false;
    }

    return smsExecutar(
        $conexao,
        'UPDATE cliente SET email_verificado_em = COALESCE(email_verificado_em, NOW())
          WHERE id_cliente = ? AND id_empresa = ? AND LOWER(email) = ? AND email_verificado_em IS NULL',
        'iis',
        $idCliente,
        $idEmpresa,
        $email
    ) === 1;
}
