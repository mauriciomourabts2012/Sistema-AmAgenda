<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| GATEWAY INFOBIP - SMS API v3
|--------------------------------------------------------------------------
|
| Transporte privado: POST /sms/3/messages com "Authorization: App <chave>"
| e messageId local gerado previamente. Aceite da API NÃO é entrega ao celular:
| a entrega só é confirmada pelo relatório (webhook).
|
| Este arquivo nunca registra, devolve ou expõe a API key, o telefone completo
| ou o texto da mensagem. Sem reenvio automático.
|
*/

const INFOBIP_ENDPOINT_ENVIO = '/sms/3/messages';

/**
 * Valida a configuração privada. Devolve apenas códigos de erro, nunca valores.
 *
 * @param array<string, mixed> $bruta
 * @return array{ok: bool, erros: list<string>, config: array<string, mixed>}
 */
function infobipConfigValidar(array $bruta): array
{
    $erros = [];

    $texto = static fn (string $chave): string => is_string($bruta[$chave] ?? null) ? $bruta[$chave] : '';

    $base = $texto('base_url');
    $partes = $base !== '' ? parse_url($base) : false;
    $host = is_array($partes) ? strtolower((string)($partes['host'] ?? '')) : '';
    if (!is_array($partes)
        || strtolower((string)($partes['scheme'] ?? '')) !== 'https'
        || isset($partes['user']) || isset($partes['pass']) || isset($partes['query']) || isset($partes['fragment'])
        || (isset($partes['port']) && (int)$partes['port'] !== 443)
        || (($partes['path'] ?? '') !== '' && ($partes['path'] ?? '') !== '/')) {
        $erros[] = 'BASE_URL_INVALIDA';
    } elseif (preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*api\.infobip\.com$/', $host) !== 1
        || str_contains($host, 'seu-subdominio')) {
        $erros[] = 'HOST_NAO_INFOBIP';
    }

    $chaveApi = $texto('api_key');
    if (strlen($chaveApi) < 20 || trim($chaveApi) !== $chaveApi || preg_match('/[\x00-\x20\x7F]/', $chaveApi) === 1) {
        $erros[] = 'API_KEY_INVALIDA';
    }

    $sender = $texto('sender');
    if (preg_match('/^[A-Za-z0-9]{3,11}$/', $sender) !== 1 && preg_match('/^[0-9]{3,16}$/', $sender) !== 1) {
        $erros[] = 'SENDER_INVALIDO';
    }

    $ambiente = $texto('ambiente');
    if (!in_array($ambiente, ['teste', 'producao'], true)) {
        $erros[] = 'AMBIENTE_INVALIDO';
    }

    $timeoutConexao = $bruta['timeout_conexao'] ?? null;
    $timeoutTotal = $bruta['timeout_total'] ?? null;
    if (!is_int($timeoutConexao) || $timeoutConexao < 1 || $timeoutConexao > 10) {
        $erros[] = 'TIMEOUT_CONEXAO_INVALIDO';
    }
    if (!is_int($timeoutTotal) || $timeoutTotal < 2 || $timeoutTotal > 30
        || (is_int($timeoutConexao) && $timeoutTotal < $timeoutConexao)) {
        $erros[] = 'TIMEOUT_TOTAL_INVALIDO';
    }

    $hmac = $texto('hmac_key');
    $segredo = $texto('webhook_secret');
    if (strlen($hmac) < 32) {
        $erros[] = 'HMAC_KEY_FRACA';
    }
    if (strlen($segredo) < 32) {
        $erros[] = 'WEBHOOK_SECRET_FRACO';
    }
    if ($hmac !== '' && hash_equals($hmac, $segredo)) {
        $erros[] = 'CHAVES_NAO_INDEPENDENTES';
    }

    $webhook = $texto('webhook_url');
    $partesWebhook = $webhook !== '' ? parse_url($webhook) : false;
    if (!is_array($partesWebhook)
        || strtolower((string)($partesWebhook['scheme'] ?? '')) !== 'https'
        || ($partesWebhook['host'] ?? '') === ''
        || isset($partesWebhook['user']) || isset($partesWebhook['fragment'])
        || str_contains(strtolower((string)$partesWebhook['host']), 'seu-dominio')
        || preg_match('/[&?]t=/', $webhook) === 1) {
        $erros[] = 'WEBHOOK_URL_INVALIDA';
    }

    return [
        'ok' => $erros === [],
        'erros' => $erros,
        'config' => [
            'habilitado' => ($bruta['habilitado'] ?? false) === true,
            'ambiente' => $ambiente,
            'base_url' => rtrim($base, '/'),
            'api_key' => $chaveApi,
            'sender' => $sender,
            'timeout_conexao' => is_int($timeoutConexao) ? $timeoutConexao : 5,
            'timeout_total' => is_int($timeoutTotal) ? $timeoutTotal : 15,
            'hmac_key' => $hmac,
            'webhook_secret' => $segredo,
            'webhook_url' => $webhook,
        ],
    ];
}

/**
 * Carrega backend/_config/infobip.php. Ausente, inválido ou desabilitado
 * resulta em configuração indisponível (envio desligado).
 *
 * @return array{ok: bool, habilitado: bool, erros: list<string>, config: array<string, mixed>}
 */
function infobipConfigCarregar(?string $caminho = null): array
{
    $caminho ??= __DIR__ . '/../_config/infobip.php';
    $bruta = is_file($caminho) ? require $caminho : null;
    if (!is_array($bruta)) {
        return ['ok' => false, 'habilitado' => false, 'erros' => ['CONFIG_AUSENTE'], 'config' => []];
    }

    $validada = infobipConfigValidar($bruta);

    return [
        'ok' => $validada['ok'],
        'habilitado' => $validada['ok'] && $validada['config']['habilitado'] === true,
        'erros' => $validada['erros'],
        'config' => $validada['config'],
    ];
}

/** Token por mensagem exigido na URL do callback (derivado do webhook_secret). */
function infobipTokenWebhook(array $config, string $messageId): string
{
    return hash_hmac('sha256', 'webhook|' . $messageId, (string)$config['webhook_secret']);
}

/** callbackData devolvido pela Infobip no relatório (derivado de hmac_key, independente do token). */
function infobipCallbackData(array $config, string $messageId): string
{
    return hash_hmac('sha256', 'callback|' . $messageId, (string)$config['hmac_key']);
}

function infobipUrlCallback(array $config, string $messageId): string
{
    $url = (string)$config['webhook_url'];

    return $url . (str_contains($url, '?') ? '&' : '?') . 't=' . infobipTokenWebhook($config, $messageId);
}

/** Corpo do POST /sms/3/messages. */
function infobipMontarCorpo(array $config, string $messageId, string $telefoneE164, string $texto): array
{
    return [
        'messages' => [[
            'sender' => (string)$config['sender'],
            'destinations' => [[
                'to' => ltrim($telefoneE164, '+'),
                'messageId' => $messageId,
            ]],
            'content' => ['text' => $texto],
            'webhooks' => [
                'delivery' => ['url' => infobipUrlCallback($config, $messageId)],
                'contentType' => 'application/json',
                'callbackData' => infobipCallbackData($config, $messageId),
            ],
        ]],
    ];
}

/**
 * Transporte cURL padrão. Devolve somente http_status, body bruto e erro do cURL.
 *
 * @param array{url: string, headers: list<string>, body: string, timeout_conexao: int, timeout_total: int} $req
 * @return array{http_status: int, body: string, errno: int}
 */
function infobipTransporteCurl(array $req): array
{
    $curl = curl_init();
    if ($curl === false) {
        return ['http_status' => 0, 'body' => '', 'errno' => 2];
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $req['url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $req['body'],
        CURLOPT_HTTPHEADER => $req['headers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $req['timeout_conexao'],
        CURLOPT_TIMEOUT => $req['timeout_total'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resposta = curl_exec($curl);
    $errno = curl_errno($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return [
        'http_status' => $status,
        'body' => is_string($resposta) ? $resposta : '',
        'errno' => $errno,
    ];
}

/**
 * Classifica a resposta da API sem confundir aceite com entrega.
 *
 * Resultados:
 * - aceita: API aceitou (2xx com messageId); entrega ainda desconhecida;
 * - rejeitada: recusa definitiva (4xx ou status REJECTED);
 * - limitada: HTTP 429, nada foi enviado;
 * - falha_conexao: não chegou a conectar, nada foi enviado;
 * - incerto: timeout, 5xx, JSON inválido ou resposta inesperada — a mensagem pode ter sido aceita.
 *
 * @param array{http_status: int, body: string, errno: int} $transporte
 * @return array<string, mixed>
 */
function infobipClassificarResposta(array $transporte, string $messageIdLocal): array
{
    $status = (int)$transporte['http_status'];
    $errno = (int)$transporte['errno'];
    $base = ['http_status' => $status > 0 ? $status : null, 'bulk_id' => null, 'message_id' => null,
        'status_grupo' => null, 'status_id' => null, 'status_nome' => null];

    if ($errno !== 0) {
        // 28 = timeout: a requisição pode ter chegado. 6/7/35/51/58/60 = falha antes de enviar o corpo.
        if (in_array($errno, [6, 7, 35, 51, 58, 60], true)) {
            return ['resultado' => 'falha_conexao', 'erro_codigo' => 'INFOBIP_CONEXAO'] + $base;
        }

        return ['resultado' => 'incerto', 'erro_codigo' => $errno === 28 ? 'INFOBIP_TIMEOUT' : 'INFOBIP_TRANSPORTE'] + $base;
    }

    if ($status === 429) {
        return ['resultado' => 'limitada', 'erro_codigo' => 'INFOBIP_HTTP_429'] + $base;
    }
    if ($status >= 500) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'INFOBIP_HTTP_5XX'] + $base;
    }
    if ($status >= 400) {
        return ['resultado' => 'rejeitada', 'erro_codigo' => 'INFOBIP_HTTP_' . $status] + $base;
    }
    if ($status < 200 || $status >= 300) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'INFOBIP_RESPOSTA_INESPERADA'] + $base;
    }

    $json = json_decode($transporte['body'], true);
    if (!is_array($json)) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'INFOBIP_JSON_INVALIDO'] + $base;
    }
    $mensagem = $json['messages'][0] ?? null;
    if (!is_array($mensagem) || !is_string($mensagem['messageId'] ?? null) || $mensagem['messageId'] === ''
        || !is_array($mensagem['status'] ?? null)) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'INFOBIP_RESPOSTA_INESPERADA'] + $base;
    }
    if (!hash_equals($messageIdLocal, $mensagem['messageId'])) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'INFOBIP_MESSAGE_ID_DIVERGENTE'] + $base;
    }

    $grupo = strtoupper((string)($mensagem['status']['groupName'] ?? ''));
    $dados = [
        'http_status' => $status,
        'bulk_id' => is_string($json['bulkId'] ?? null) ? substr($json['bulkId'], 0, 150) : null,
        'message_id' => $mensagem['messageId'],
        'status_grupo' => $grupo !== '' ? substr($grupo, 0, 50) : null,
        'status_id' => isset($mensagem['status']['id']) ? (int)$mensagem['status']['id'] : null,
        'status_nome' => is_string($mensagem['status']['name'] ?? null) ? substr($mensagem['status']['name'], 0, 100) : null,
    ];

    if ($grupo === 'REJECTED') {
        return ['resultado' => 'rejeitada', 'erro_codigo' => 'INFOBIP_STATUS_REJEITADO'] + $dados;
    }
    if (!in_array($grupo, ['PENDING', 'ACCEPTED', 'SENT', 'DELIVERED'], true)) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'INFOBIP_STATUS_DESCONHECIDO'] + $dados;
    }

    return ['resultado' => 'aceita', 'erro_codigo' => null] + $dados;
}

/**
 * Envia um SMS. $transporte permite substituir o cURL em testes.
 *
 * @param array<string, mixed> $config configuração validada
 * @param (callable(array): array)|null $transporte
 * @return array<string, mixed>
 */
function infobipEnviarSms(array $config, string $messageId, string $telefoneE164, string $texto, ?callable $transporte = null): array
{
    if (preg_match('/^\+55[0-9]{11}$/', $telefoneE164) !== 1
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $messageId) !== 1
        || $texto === '') {
        return ['resultado' => 'rejeitada', 'erro_codigo' => 'INFOBIP_PARAMETROS_INVALIDOS', 'http_status' => null,
            'bulk_id' => null, 'message_id' => null, 'status_grupo' => null, 'status_id' => null, 'status_nome' => null];
    }

    $corpo = json_encode(
        infobipMontarCorpo($config, $messageId, $telefoneE164, $texto),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($corpo)) {
        return ['resultado' => 'rejeitada', 'erro_codigo' => 'INFOBIP_PARAMETROS_INVALIDOS', 'http_status' => null,
            'bulk_id' => null, 'message_id' => null, 'status_grupo' => null, 'status_id' => null, 'status_nome' => null];
    }

    $requisicao = [
        'url' => rtrim((string)$config['base_url'], '/') . INFOBIP_ENDPOINT_ENVIO,
        'headers' => [
            'Authorization: App ' . $config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        'body' => $corpo,
        'timeout_conexao' => (int)$config['timeout_conexao'],
        'timeout_total' => (int)$config['timeout_total'],
    ];

    try {
        $resposta = ($transporte ?? 'infobipTransporteCurl')($requisicao);
    } catch (Throwable) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'INFOBIP_TRANSPORTE', 'http_status' => null,
            'bulk_id' => null, 'message_id' => null, 'status_grupo' => null, 'status_id' => null, 'status_nome' => null];
    }

    return infobipClassificarResposta([
        'http_status' => (int)($resposta['http_status'] ?? 0),
        'body' => (string)($resposta['body'] ?? ''),
        'errno' => (int)($resposta['errno'] ?? 0),
    ], $messageId);
}
