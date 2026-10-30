<?php
declare(strict_types=1);

/*
 * Testes do gateway Infobip com transporte falso. Nenhuma rede, nenhum SMS real.
 * Uso: php tools/tests/infobip-gateway.test.php
 */

require_once __DIR__ . '/../../backend/_gateways/infobip.php';

$total = 0;
$falhas = [];
function verificar(bool $condicao, string $descricao): void
{
    global $total, $falhas;
    $total++;
    if (!$condicao) {
        $falhas[] = $descricao;
    }
}

const CHAVE_API = 'chave-api-ficticia-0123456789abcdef';
const TELEFONE = '+5538998969407';
const TEXTO = 'AmAgenda: seu código de acesso é 482913. Ele expira em 5 minutos.';
$messageId = '3f2b8c1e-5d4a-4b6f-9a7e-1c2d3e4f5a6b';

$config = [
    'habilitado' => true,
    'ambiente' => 'teste',
    'base_url' => 'https://abc123.api.infobip.com',
    'api_key' => CHAVE_API,
    'sender' => 'AmAgenda',
    'timeout_conexao' => 5,
    'timeout_total' => 15,
    'hmac_key' => str_repeat('h', 40),
    'webhook_secret' => str_repeat('w', 40),
    'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
];

/** Transporte falso: registra as chamadas e devolve a resposta combinada. */
function transporte(array $resposta, array &$chamadas): callable
{
    return static function (array $req) use ($resposta, &$chamadas): array {
        $chamadas[] = $req;
        if (isset($resposta['lancar'])) {
            throw new RuntimeException('falha simulada com segredo ' . CHAVE_API);
        }

        return $resposta;
    };
}

function aceita(string $messageId, string $grupo = 'PENDING', string $nome = 'PENDING_ACCEPTED'): string
{
    return json_encode([
        'bulkId' => 'bulk-xyz-1',
        'messages' => [[
            'messageId' => $messageId,
            'status' => ['groupId' => 1, 'groupName' => $grupo, 'id' => 26, 'name' => $nome, 'description' => 'x'],
            'destination' => '5538998969407',
        ]],
    ]);
}

function enviar(array $config, string $messageId, array $resposta, array &$chamadas): array
{
    return infobipEnviarSms($config, $messageId, TELEFONE, TEXTO, transporte($resposta, $chamadas));
}

// Requisição aceita.
$c = [];
$r = enviar($config, $messageId, ['http_status' => 200, 'body' => aceita($messageId), 'errno' => 0], $c);
verificar($r['resultado'] === 'aceita', 'HTTP 200 com messageId => aceita');
verificar($r['bulk_id'] === 'bulk-xyz-1' && $r['message_id'] === $messageId, 'bulkId e messageId extraídos');
verificar($r['status_grupo'] === 'PENDING', 'grupo PENDING preservado (aceite não é entrega)');
verificar(!in_array($r['resultado'], ['entregue', 'delivered'], true), 'gateway nunca declara entrega');
verificar(count($c) === 1, 'uma única chamada de rede');

// Estrutura da requisição.
$req = $c[0];
verificar($req['url'] === 'https://abc123.api.infobip.com/sms/3/messages', 'endpoint POST /sms/3/messages');
verificar(in_array('Authorization: App ' . CHAVE_API, $req['headers'], true), 'Authorization: App <chave>');
verificar(in_array('Content-Type: application/json', $req['headers'], true), 'Content-Type JSON');
$corpo = json_decode($req['body'], true);
$msg = $corpo['messages'][0] ?? [];
verificar(($msg['destinations'][0]['messageId'] ?? '') === $messageId, 'messageId local enviado no destino');
verificar(($msg['destinations'][0]['to'] ?? '') === '5538998969407', 'telefone sem "+" no destino');
verificar(($msg['sender'] ?? '') === 'AmAgenda', 'sender configurado');
verificar(($msg['content']['text'] ?? '') === TEXTO, 'texto enviado ao provedor');
verificar(($msg['webhooks']['callbackData'] ?? '') === infobipCallbackData($config, $messageId), 'callbackData HMAC por mensagem');
verificar(str_contains((string)($msg['webhooks']['delivery']['url'] ?? ''), '&t=' . infobipTokenWebhook($config, $messageId)), 'token por mensagem na URL do callback');
verificar(($msg['webhooks']['contentType'] ?? '') === 'application/json', 'callback em JSON');
verificar(infobipCallbackData($config, $messageId) !== infobipTokenWebhook($config, $messageId), 'token e callbackData usam chaves independentes');
verificar($req['timeout_conexao'] === 5 && $req['timeout_total'] === 15, 'timeouts aplicados');

// Rejeição HTTP.
foreach ([400, 401, 403, 404, 422] as $codigo) {
    $c = [];
    $r = enviar($config, $messageId, ['http_status' => $codigo, 'body' => '{"requestError":{"serviceException":{"messageId":"X","text":"Texto com 5538998969407"}}}', 'errno' => 0], $c);
    verificar($r['resultado'] === 'rejeitada' && $r['erro_codigo'] === 'INFOBIP_HTTP_' . $codigo, "HTTP $codigo => rejeitada");
}

// HTTP 429.
$c = [];
$r = enviar($config, $messageId, ['http_status' => 429, 'body' => '', 'errno' => 0], $c);
verificar($r['resultado'] === 'limitada' && $r['erro_codigo'] === 'INFOBIP_HTTP_429', 'HTTP 429 => limitada');
verificar(count($c) === 1, '429: sem retentativa automática');

// Timeout (resultado incerto).
$c = [];
$r = enviar($config, $messageId, ['http_status' => 0, 'body' => '', 'errno' => 28], $c);
verificar($r['resultado'] === 'incerto' && $r['erro_codigo'] === 'INFOBIP_TIMEOUT', 'timeout => incerto');
verificar(count($c) === 1, 'timeout: sem reenvio automático');

// Falha de conexão (nada foi enviado).
foreach ([6, 7, 35, 60] as $errno) {
    $c = [];
    $r = enviar($config, $messageId, ['http_status' => 0, 'body' => '', 'errno' => $errno], $c);
    verificar($r['resultado'] === 'falha_conexao', "cURL errno $errno => falha_conexao");
}
$c = [];
$r = enviar($config, $messageId, ['http_status' => 0, 'body' => '', 'errno' => 56], $c);
verificar($r['resultado'] === 'incerto', 'cURL errno 56 (recepção) => incerto');

// HTTP 5xx.
foreach ([500, 502, 503, 504] as $codigo) {
    $c = [];
    $r = enviar($config, $messageId, ['http_status' => $codigo, 'body' => 'erro', 'errno' => 0], $c);
    verificar($r['resultado'] === 'incerto' && $r['erro_codigo'] === 'INFOBIP_HTTP_5XX', "HTTP $codigo => incerto");
    verificar(count($c) === 1, "HTTP $codigo: sem reenvio automático");
}

// JSON inválido e resposta inesperada com 2xx (podem ter sido aceitos).
$c = [];
$r = enviar($config, $messageId, ['http_status' => 200, 'body' => '<html>nao json', 'errno' => 0], $c);
verificar($r['resultado'] === 'incerto' && $r['erro_codigo'] === 'INFOBIP_JSON_INVALIDO', 'JSON inválido => incerto');
$c = [];
$r = enviar($config, $messageId, ['http_status' => 200, 'body' => '{}', 'errno' => 0], $c);
verificar($r['resultado'] === 'incerto' && $r['erro_codigo'] === 'INFOBIP_RESPOSTA_INESPERADA', 'JSON sem messages => incerto');
$c = [];
$r = enviar($config, $messageId, ['http_status' => 200, 'body' => '{"messages":[{"messageId":"","status":{}}]}', 'errno' => 0], $c);
verificar($r['resultado'] === 'incerto', 'messageId vazio => incerto');
$c = [];
$r = enviar($config, $messageId, ['http_status' => 200, 'body' => aceita('outro-id'), 'errno' => 0], $c);
verificar($r['resultado'] === 'incerto' && $r['erro_codigo'] === 'INFOBIP_MESSAGE_ID_DIVERGENTE', 'messageId divergente => incerto');
$c = [];
$r = enviar($config, $messageId, ['http_status' => 200, 'body' => aceita($messageId, 'XPTO', 'DESCONHECIDO'), 'errno' => 0], $c);
verificar($r['resultado'] === 'incerto' && $r['erro_codigo'] === 'INFOBIP_STATUS_DESCONHECIDO', 'grupo desconhecido => incerto');
$c = [];
$r = enviar($config, $messageId, ['http_status' => 200, 'body' => aceita($messageId, 'REJECTED', 'REJECTED_NOT_ENOUGH_CREDITS'), 'errno' => 0], $c);
verificar($r['resultado'] === 'rejeitada' && $r['erro_codigo'] === 'INFOBIP_STATUS_REJEITADO', '2xx com grupo REJECTED => rejeitada');
$c = [];
$r = enviar($config, $messageId, ['http_status' => 302, 'body' => '', 'errno' => 0], $c);
verificar($r['resultado'] === 'incerto', 'redirecionamento não é seguido nem aceito');

// Exceção no transporte: incerta e sem vazar a mensagem.
$c = [];
$r = enviar($config, $messageId, ['lancar' => true], $c);
verificar($r['resultado'] === 'incerto', 'exceção do transporte => incerto');
verificar(!str_contains(json_encode($r), CHAVE_API), 'exceção não vaza a API key');

// Parâmetros inválidos nunca chegam à rede.
$c = [];
$r = infobipEnviarSms($config, $messageId, '5538998969407', TEXTO, transporte(['http_status' => 200, 'body' => '', 'errno' => 0], $c));
verificar($r['resultado'] === 'rejeitada' && $c === [], 'telefone fora do formato E.164 não é enviado');
$r = infobipEnviarSms($config, 'nao-e-uuid', TELEFONE, TEXTO, transporte(['http_status' => 200, 'body' => '', 'errno' => 0], $c));
verificar($r['resultado'] === 'rejeitada' && $c === [], 'messageId fora do formato UUID v4 não é enviado');
$r = infobipEnviarSms($config, $messageId, TELEFONE, '', transporte(['http_status' => 200, 'body' => '', 'errno' => 0], $c));
verificar($r['resultado'] === 'rejeitada' && $c === [], 'texto vazio não é enviado');

// Nenhum resultado contém API key, telefone completo, texto ou código.
$amostras = [
    enviar($config, $messageId, ['http_status' => 200, 'body' => aceita($messageId), 'errno' => 0], $c),
    enviar($config, $messageId, ['http_status' => 400, 'body' => 'Texto 5538998969407 482913', 'errno' => 0], $c),
    enviar($config, $messageId, ['http_status' => 500, 'body' => '482913', 'errno' => 0], $c),
    enviar($config, $messageId, ['http_status' => 0, 'body' => '', 'errno' => 28], $c),
];
foreach ($amostras as $i => $amostra) {
    $json = json_encode($amostra);
    verificar(!str_contains($json, CHAVE_API) && !str_contains($json, '5538998969407') && !str_contains($json, '482913') && !str_contains($json, 'AmAgenda: seu'),
        "resultado $i sem dados sensíveis");
}

// O arquivo do gateway não registra nada em log.
$fonte = (string)file_get_contents(__DIR__ . '/../../backend/_gateways/infobip.php');
verificar(!preg_match('/error_log|var_dump|print_r|echo\s/', $fonte), 'gateway não grava logs nem imprime');
verificar(str_contains($fonte, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($fonte, 'CURLPROTO_HTTPS'), 'cURL com verificação TLS e somente HTTPS');
verificar(str_contains($fonte, 'CURLOPT_FOLLOWLOCATION => false'), 'cURL sem seguir redirecionamentos');

echo $total . ' verificações, ' . count($falhas) . " falha(s)\n";
foreach ($falhas as $falha) {
    echo "FALHA: " . $falha . "\n";
}
exit($falhas === [] ? 0 : 1);
