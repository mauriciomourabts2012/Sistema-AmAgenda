<?php
declare(strict_types=1);

/*
 * Testes do webhook de relatórios de entrega da Infobip (banco falso, sem rede).
 * Uso: php tools/tests/infobip-webhook.test.php
 */

$total = 0;
$falhas = [];
date_default_timezone_set('UTC');
require_once __DIR__ . '/sms-banco-falso.php';
define('INFOBIP_WEBHOOK_SOMENTE_FUNCOES', true);
require_once __DIR__ . '/../../backend/webhooks/webhook_infobip.php';

$config = [
    'habilitado' => true, 'ambiente' => 'teste', 'base_url' => 'https://abc123.api.infobip.com',
    'api_key' => 'chave-api-ficticia-0123456789abcdef', 'sender' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
    'hmac_key' => str_repeat('h', 40), 'webhook_secret' => str_repeat('w', 40),
    'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
];
$carga = ['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $config];
$mid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$outro = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';

function item(array $config, string $mid, string $grupo = 'DELIVERED', ?string $callback = null): array
{
    return [
        'messageId' => $mid, 'bulkId' => 'b1',
        'status' => ['id' => 5, 'groupName' => $grupo, 'name' => $grupo . '_X'],
        'error' => ['id' => 0, 'name' => 'NO_ERROR', 'permanent' => false],
        'price' => ['pricePerMessage' => 0.05, 'currency' => 'BRL'],
        'sentAt' => '2026-10-29T10:00:00.000+0000', 'doneAt' => '2026-10-29T10:00:05.000+0000', 'messageCount' => 1,
        'callbackData' => $callback ?? infobipCallbackData($config, $mid),
    ];
}
function corpo(array ...$itens): string
{
    return json_encode(['results' => $itens]);
}
function bancoComMensagem(): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT id_mensagem_envio, status/', [[
        'id_mensagem_envio' => 5, 'status' => 'aceita', 'ultimo_evento_hash' => null, 'ultimo_evento_em' => null,
    ]]);

    return $db;
}

$token = infobipTokenWebhook($config, $mid);

// Autenticação por mensagem.
falsoVerificar(infobipWebhookAutenticar($config, $mid, $token, infobipCallbackData($config, $mid)) === true, 'token + callbackData corretos autenticam');
falsoVerificar(infobipWebhookAutenticar($config, $mid, '', infobipCallbackData($config, $mid)) === false, 'sem token não autentica');
falsoVerificar(infobipWebhookAutenticar($config, $mid, str_repeat('0', 64), infobipCallbackData($config, $mid)) === false, 'token errado não autentica');
falsoVerificar(infobipWebhookAutenticar($config, $mid, $token, 'falso') === false, 'callbackData errado não autentica');
falsoVerificar(infobipWebhookAutenticar($config, $mid, $token, null) === false, 'sem callbackData não autentica');
falsoVerificar(infobipWebhookAutenticar($config, $mid, $token, 123) === false, 'callbackData não textual não autentica');
falsoVerificar(infobipWebhookAutenticar($config, $outro, $token, infobipCallbackData($config, $outro)) === false, 'token de outra mensagem não autentica');
falsoVerificar(infobipWebhookAutenticar($config, $outro, infobipTokenWebhook($config, $outro), infobipCallbackData($config, $mid)) === false, 'callbackData de outra mensagem não autentica');
$outraConfig = $config;
$outraConfig['webhook_secret'] = str_repeat('z', 40);
falsoVerificar(infobipWebhookAutenticar($outraConfig, $mid, $token, infobipCallbackData($config, $mid)) === false, 'token gerado com outro segredo não autentica');
$somenteHmac = $config;
$somenteHmac['hmac_key'] = str_repeat('y', 40);
falsoVerificar(infobipWebhookAutenticar($somenteHmac, $mid, $token, infobipCallbackData($config, $mid)) === false, 'as duas chaves são exigidas de forma independente');

// Fluxo completo: aceito.
$db = bancoComMensagem();
$r = infobipWebhookProcessar($db, $carga, corpo(item($config, $mid)), $token);
falsoVerificar($r['status'] === 200 && $r['aplicados'] === 1, 'callback autêntico é aplicado');
falsoVerificar(count($db->chamadas('/^UPDATE mensagem_envio/')) === 1, 'estado da mensagem atualizado');

// Falso: sem/errado token, sem callbackData, nada é gravado.
foreach ([
    'sem token' => ['', corpo(item($config, $mid))],
    'token inválido' => [str_repeat('a', 64), corpo(item($config, $mid))],
    'callbackData falso' => [$token, corpo(item($config, $mid, 'DELIVERED', 'forjado'))],
] as $nome => [$t, $c]) {
    $db = bancoComMensagem();
    $r = infobipWebhookProcessar($db, $carga, $c, $t);
    falsoVerificar($r['status'] === 401 && $db->log === [], "callback falso ($nome) => 401 sem tocar no banco");
}

// Lote misto: um item não autêntico impede TODOS.
$db = bancoComMensagem();
$r = infobipWebhookProcessar($db, $carga, corpo(item($config, $mid), item($config, $outro)), $token);
falsoVerificar($r['status'] === 401 && $db->log === [], 'lote com item não autenticado não grava nada');

// Corpo inválido.
$db = bancoComMensagem();
foreach ([
    ['json inválido', '{nao-json', 400],
    ['sem results', '{"x":1}', 400],
    ['results vazio', '{"results":[]}', 400],
    ['results não-lista', '{"results":{"a":1}}', 400],
    ['corpo vazio', '', 413],
    ['corpo gigante', str_repeat('x', INFOBIP_WEBHOOK_CORPO_MAX_BYTES + 1), 413],
] as [$nome, $c, $esperado]) {
    $r = infobipWebhookProcessar($db, $carga, $c, $token);
    falsoVerificar($r['status'] === $esperado, "$nome => $esperado");
}
$muitos = array_fill(0, INFOBIP_WEBHOOK_MAX_RESULTADOS + 1, item($config, $mid));
falsoVerificar(infobipWebhookProcessar($db, $carga, corpo(...$muitos), $token)['status'] === 400, 'lote acima do limite recusado');
falsoVerificar(infobipWebhookProcessar($db, $carga, json_encode(['results' => ['texto']]), $token)['status'] === 401, 'item não-objeto não autentica');
falsoVerificar($db->log === [], 'corpos inválidos não tocam no banco');

// Configuração indisponível.
$r = infobipWebhookProcessar(bancoComMensagem(), ['ok' => false, 'habilitado' => false, 'erros' => ['CONFIG_AUSENTE'], 'config' => []], corpo(item($config, $mid)), $token);
falsoVerificar($r['status'] === 503, 'configuração ausente => 503, nunca aceita');

// Envio desabilitado mas config válida ainda recebe relatórios de mensagens existentes.
$desligada = $carga;
$desligada['habilitado'] = false;
$r = infobipWebhookProcessar(bancoComMensagem(), $desligada, corpo(item($config, $mid)), $token);
falsoVerificar($r['status'] === 200, 'relatórios continuam processados com envio desabilitado');

// Replay/duplicado: segunda entrega com o mesmo hash não altera.
$hash = hash('sha256', json_encode([$mid, 5, 'DELIVERED', 0, '2026-10-29T10:00:00.000+0000', '2026-10-29T10:00:05.000+0000', 1], JSON_UNESCAPED_UNICODE));
$db = new BancoFalso();
$db->regra('/^SELECT id_mensagem_envio, status/', [[
    'id_mensagem_envio' => 5, 'status' => 'aceita', 'ultimo_evento_hash' => hex2bin($hash), 'ultimo_evento_em' => null,
]]);
$r = infobipWebhookProcessar($db, $carga, corpo(item($config, $mid)), $token);
falsoVerificar($r['status'] === 200 && $r['aplicados'] === 0 && $db->chamadas('/^UPDATE/') === [], 'replay idempotente: 200 sem nova gravação');

// Final preservado contra callback posterior (fora de ordem).
$db = new BancoFalso();
$db->regra('/^SELECT id_mensagem_envio, status/', [['id_mensagem_envio' => 5, 'status' => 'entregue', 'ultimo_evento_hash' => null, 'ultimo_evento_em' => null]]);
$r = infobipWebhookProcessar($db, $carga, corpo(item($config, $mid, 'PENDING')), $token);
falsoVerificar($r['status'] === 200 && $r['aplicados'] === 0 && $db->chamadas('/^UPDATE/') === [], 'callback atrasado não rebaixa entregue');

// Mensagem desconhecida, mas autêntica: 200 sem efeito (evita tempestade de retentativas).
$db = new BancoFalso();
$r = infobipWebhookProcessar($db, $carga, corpo(item($config, $mid)), $token);
falsoVerificar($r['status'] === 200 && $r['aplicados'] === 0 && $db->chamadas('/^UPDATE/') === [], 'mensagem desconhecida: sem efeito');

// Falha de banco: 500 para retentativa.
$db = bancoComMensagem();
$db->regra('/^UPDATE mensagem_envio/', new RuntimeException('banco indisponível'));
$r = infobipWebhookProcessar($db, $carga, corpo(item($config, $mid)), $token);
falsoVerificar($r['status'] === 500, 'falha de banco => 500 (Infobip tenta novamente)');

// Respostas nunca ecoam o conteúdo recebido.
foreach ([infobipWebhookProcessar(new BancoFalso(), $carga, '{"results":[{"messageId":"SEGREDO-123"}]}', 'x')] as $resp) {
    falsoVerificar(!str_contains(json_encode($resp), 'SEGREDO-123'), 'resposta não ecoa o corpo recebido');
}

// Estrutura do arquivo: rota registrada e exceção de CSRF/sessão coerentes.
$api = (string)file_get_contents(__DIR__ . '/../../public/api/api_central.php');
falsoVerificar(str_contains($api, "'webhooks/infobip' => [") && str_contains($api, 'webhook_infobip.php'), 'rota webhooks/infobip registrada');
falsoVerificar(substr_count($api, "'webhooks/infobip',") === 3, 'rota nas 3 listas públicas (jurídico, senha temporária, CSRF)');
$fonte = (string)file_get_contents(__DIR__ . '/../../backend/webhooks/webhook_infobip.php');
falsoVerificar(!str_contains($fonte, 'X-Hub-Signature') && !str_contains($fonte, 'HTTP_X_SIGNATURE'), 'nenhum cabeçalho de assinatura presumido');
falsoVerificar(str_contains($fonte, "'POST'") && str_contains($fonte, 'application/json'), 'somente POST JSON');

// Fase 3: token/callbackData precisam ter o formato HMAC-SHA256 em hex.
falsoVerificar(infobipWebhookAutenticar($config, $mid, strtoupper($token), infobipCallbackData($config, $mid)) === false, 'token fora do formato hex minúsculo recusado');
falsoVerificar(infobipWebhookAutenticar($config, $mid, $token . 'x', infobipCallbackData($config, $mid)) === false, 'token com tamanho errado recusado');
falsoVerificar(infobipWebhookAutenticar($config, $mid, $token, 'AAAA') === false, 'callbackData fora do formato recusado');
$fonteWebhook = (string)file_get_contents(__DIR__ . '/../../backend/webhooks/webhook_infobip.php');
$codigoWebhook = preg_replace('#/\*.*?\*/#s', '', $fonteWebhook);
falsoVerificar(!preg_match('/error_log|syslog|QUERY_STRING|REQUEST_URI|var_dump|print_r/', (string)$codigoWebhook), 'webhook não registra URL, token nem corpo');
$fonteSmsWebhook = (string)file_get_contents(__DIR__ . '/../../backend/_servicos/sms.php');
falsoVerificar(!preg_match('/smsLog\([^)]*(token|callbackData|\$corpo|\$resultado\[)/i', $fonteSmsWebhook), 'logs de SMS nunca recebem token, callbackData ou corpo');

falsoRelatorio();
