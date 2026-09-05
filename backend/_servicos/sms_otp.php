<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SMS OTP - SERVIÇO CENTRAL
|--------------------------------------------------------------------------
|
| Ambiente atual:
| DEV / Twilio Programmable Messaging
|
| Responsabilidades:
| - carregar configuração privada da Twilio;
| - enviar SMS pelo backend;
| - padronizar retorno interno;
| - não expor credenciais nem payload bruto ao frontend.
|
| Futuramente:
| - este mesmo serviço poderá ser alterado para Twilio Verify;
| - cliente_login.php não deve precisar conhecer detalhes do provedor.
|
*/

$configPath = __DIR__ . '/../_config/twilio.php';

if (!is_file($configPath)) {
    throw new RuntimeException(
        'Configuração privada da Twilio não encontrada.'
    );
}

$configTwilio = require $configPath;

if (!is_array($configTwilio)) {
    throw new RuntimeException(
        'Configuração privada da Twilio inválida.'
    );
}


/**
 * Retorno interno padronizado.
 */
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


/**
 * Retorna configuração privada da Twilio.
 */
function smsOtpConfig(): array
{
    global $configTwilio;

    return $configTwilio;
}


/**
 * Normaliza telefone brasileiro para E.164.
 *
 * Exemplos:
 * 38998969407
 * 5538998969407
 * +5538998969407
 *
 * Resultado:
 * +5538998969407
 */
function smsOtpNormalizarTelefone(
    string $telefone
): ?string {
    $digitos = preg_replace(
        '/\D+/',
        '',
        trim($telefone)
    ) ?? '';

    if ($digitos === '') {
        return null;
    }

    /*
     * DDD + número brasileiro.
     */
    if (strlen($digitos) === 11) {
        $digitos = '55' . $digitos;
    }

    /*
     * Brasil:
     * 55 + DDD + número = 13 dígitos.
     */
    if (
        strlen($digitos) !== 13 ||
        !str_starts_with($digitos, '55')
    ) {
        return null;
    }

    return '+' . $digitos;
}


/**
 * Verifica se as configurações mínimas do modo atual estão preenchidas.
 */
function smsOtpConfigurado(): bool
{
    $config = smsOtpConfig();

    $accountSid = trim(
        (string)($config['account_sid'] ?? '')
    );

    $authToken = trim(
        (string)($config['auth_token'] ?? '')
    );

    $fromNumber = trim(
        (string)($config['from_number'] ?? '')
    );

    $mode = trim(
        (string)($config['mode'] ?? '')
    );

    return
        $accountSid !== '' &&
        $authToken !== '' &&
        $fromNumber !== '' &&
        $mode === 'programmable_sms';
}


/**
 * Executa requisição HTTP autenticada para a Twilio.
 */
function smsOtpTwilioRequest(
    string $url,
    array $campos
): array {
    $config = smsOtpConfig();

    $accountSid = trim(
        (string)($config['account_sid'] ?? '')
    );

    $authToken = trim(
        (string)($config['auth_token'] ?? '')
    );

    $timeout = (int)(
        $config['timeout_seconds'] ?? 15
    );

    if ($timeout <= 0 || $timeout > 60) {
        $timeout = 15;
    }

    $curl = curl_init();

    if ($curl === false) {
        return smsOtpResultado(
            false,
            'CURL_INIT_ERROR',
            'Serviço de SMS temporariamente indisponível.'
        );
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(
            $campos,
            '',
            '&',
            PHP_QUERY_RFC3986
        ),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $response = curl_exec($curl);

    $curlError = curl_error($curl);

    $httpStatus = (int)curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close($curl);

    if ($response === false) {
        return smsOtpResultado(
            false,
            'TWILIO_NETWORK_ERROR',
            'Serviço de SMS temporariamente indisponível.',
            [
                'http_status' => $httpStatus,
            ]
        );
    }

    $json = json_decode(
        (string)$response,
        true
    );

    if (!is_array($json)) {
        return smsOtpResultado(
            false,
            'TWILIO_INVALID_RESPONSE',
            'Serviço de SMS temporariamente indisponível.',
            [
                'http_status' => $httpStatus,
            ]
        );
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return smsOtpResultado(
            false,
            'TWILIO_REQUEST_ERROR',
            'Não foi possível enviar o SMS agora.',
            [
                'http_status' => $httpStatus,
                'twilio_code' => isset($json['code'])
                    ? (string)$json['code']
                    : '',
            ]
        );
    }

    $messageSid = trim(
        (string)($json['sid'] ?? '')
    );

    if ($messageSid === '') {
        return smsOtpResultado(
            false,
            'TWILIO_INVALID_RESPONSE',
            'Serviço de SMS temporariamente indisponível.',
            [
                'http_status' => $httpStatus,
            ]
        );
    }

    return smsOtpResultado(
        true,
        'TWILIO_SMS_SENT',
        '',
        [
            'http_status' => $httpStatus,
            'sid' => $messageSid,
            'status' => (string)($json['status'] ?? ''),
        ]
    );
}


/**
 * Envia SMS pelo Twilio Programmable Messaging.
 *
 * No Trial, a Twilio pode restringir:
 * - destinatário;
 * - país;
 * - conteúdo;
 * - quantidade de mensagens.
 */
function smsOtpEnviar(
    string $telefone,
    ?string $mensagem = null
): array {
    $telefoneNormalizado = smsOtpNormalizarTelefone(
        $telefone
    );

    if ($telefoneNormalizado === null) {
        return smsOtpResultado(
            false,
            'TELEFONE_INVALIDO',
            'Telefone inválido.'
        );
    }

    if (!smsOtpConfigurado()) {
        return smsOtpResultado(
            false,
            'TWILIO_NAO_CONFIGURADA',
            'Serviço de SMS temporariamente indisponível.'
        );
    }

    $config = smsOtpConfig();

    $accountSid = trim(
        (string)$config['account_sid']
    );

    $fromNumber = trim(
        (string)$config['from_number']
    );

    /*
     * No DEV Trial, a Twilio aceita o template previamente aprovado.
     * A mensagem personalizada recebida do fluxo OTP é deliberadamente
     * ignorada sem alterar a assinatura usada por cliente_login.php.
     */
    $body = trim(
        (string)(
            $config['dev_message_body']
            ?? 'sms_appointment_reminders'
        )
    );

    if ($body === '') {
        $body = 'sms_appointment_reminders';
    }

    $url =
        'https://api.twilio.com/2010-04-01/Accounts/' .
        rawurlencode($accountSid) .
        '/Messages.json';

    return smsOtpTwilioRequest(
        $url,
        [
            'To' => $telefoneNormalizado,
            'From' => $fromNumber,
            'Body' => $body,
        ]
    );
}


/**
 * Validação OTP.
 *
 * No modo DEV atual, usando Programmable Messaging,
 * a Twilio apenas envia a mensagem.
 *
 * Ela NÃO gera nem valida OTP.
 *
 * Portanto esta função não aprova autenticação.
 * Ela fica aqui apenas para preservar o contrato interno
 * até a migração para Twilio Verify.
 */
function smsOtpValidar(
    string $telefone,
    string $codigo,
    ?string $referencia = null
): array {
    $telefoneNormalizado = smsOtpNormalizarTelefone(
        $telefone
    );

    if ($telefoneNormalizado === null) {
        return smsOtpResultado(
            false,
            'TELEFONE_INVALIDO',
            'Código inválido.'
        );
    }

    $codigo = preg_replace(
        '/\D+/',
        '',
        trim($codigo)
    ) ?? '';

    if ($codigo === '') {
        return smsOtpResultado(
            false,
            'CODIGO_INVALIDO',
            'Código inválido.'
        );
    }

    return smsOtpResultado(
        false,
        'OTP_VALIDACAO_NAO_DISPONIVEL_NO_MODO_DEV',
        'A validação real do código ainda não está disponível neste ambiente.'
    );
}
