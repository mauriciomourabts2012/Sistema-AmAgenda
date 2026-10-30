<?php
declare(strict_types=1);

/*
 * Testes do serviço de SMS (mensagem_envio) com banco falso e transporte falso.
 * Nenhum SMS real, nenhuma escrita em banco. Uso: php tools/tests/sms-servico.test.php
 */

$total = 0;
$falhas = [];
date_default_timezone_set('UTC');
require_once __DIR__ . '/sms-banco-falso.php';

const CHAVE_API = 'chave-api-ficticia-0123456789abcdef';
const TELEFONE = '+5538998969407';
const CODIGO = '482913';
const TEXTO = 'AmAgenda: seu código de acesso é 482913. Ele expira em 5 minutos.';

$config = [
    'habilitado' => true, 'ambiente' => 'teste', 'base_url' => 'https://abc123.api.infobip.com', 'api_key' => CHAVE_API,
    'sender' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
    'hmac_key' => str_repeat('h', 40), 'webhook_secret' => str_repeat('w', 40),
    'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
];
$carga = ['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $config];

function transporteFalso(array $resposta, int &$chamadas, ?array &$ultima = null): callable
{
    return static function (array $req) use ($resposta, &$chamadas, &$ultima): array {
        $chamadas++;
        $ultima = $req;

        return $resposta;
    };
}

function bancoPronto(string $statusOtp = 'pendente_envio', array $mensagemExistente = []): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT status FROM otp_desafio/', [['status' => $statusOtp]]);
    $db->regra('/^SELECT status FROM mensagem_envio WHERE id_otp_desafio/', $mensagemExistente);

    return $db;
}

function aceitaJson(string $messageId): string
{
    return json_encode(['bulkId' => 'bulk-1', 'messages' => [[
        'messageId' => $messageId, 'status' => ['groupName' => 'PENDING', 'id' => 26, 'name' => 'PENDING_ACCEPTED'],
    ]]]);
}

/** Resposta aceita que devolve o messageId realmente enviado na requisição. */
function transporteAceita(int &$chamadas, ?array &$ultima = null): callable
{
    return static function (array $req) use (&$chamadas, &$ultima): array {
        $chamadas++;
        $ultima = $req;
        $id = json_decode($req['body'], true)['messages'][0]['destinations'][0]['messageId'];

        return ['http_status' => 200, 'body' => aceitaJson($id), 'errno' => 0];
    };
}

// UUID e idempotência.
$uuid = smsUuidV4();
falsoVerificar(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1, 'UUID v4 válido');
falsoVerificar(smsUuidV4() !== smsUuidV4(), 'UUIDs diferentes entre chamadas');
falsoVerificar(smsChaveIdempotencia(10) === smsChaveIdempotencia(10), 'chave de idempotência determinística');
falsoVerificar(smsChaveIdempotencia(10) !== smsChaveIdempotencia(11), 'chave de idempotência por desafio');
falsoVerificar(strlen(smsChaveIdempotencia(10)) === 36, 'chave de idempotência cabe em CHAR(36)');
falsoVerificar(smsTelefoneFinal4(TELEFONE) === '9407', 'últimos 4 dígitos');

// Envio aceito.
$db = bancoPronto();
$n = 0;
$req = null;
$r = smsEnviarOtp($db, $carga, 77, TELEFONE, TEXTO, transporteAceita($n, $req));
falsoVerificar($r['sucesso'] === true && $r['estado'] === 'aceita', 'envio aceito => estado aceita');
falsoVerificar($n === 1, 'uma única chamada ao provedor');
$ins = $db->chamadas('/^INSERT INTO mensagem_envio/');
falsoVerificar(count($ins) === 1, 'uma mensagem_envio criada');
falsoVerificar(($ins[0]['valores'][0] ?? 0) === 77 && ($ins[0]['valores'][2] ?? '') === '9407', 'correlaciona o desafio e grava só os 4 últimos dígitos');
falsoVerificar(($ins[0]['valores'][3] ?? '') === smsChaveIdempotencia(77), 'grava a chave de idempotência');
falsoVerificar(preg_match('/^[0-9a-f-]{36}$/', (string)($ins[0]['valores'][4] ?? '')) === 1, 'messageId local gerado antes do envio');
falsoVerificar(str_contains($ins[0]['sql'], "'enviando'"), 'registro nasce como enviando');
$ordem = array_keys(array_filter($db->eventos, static fn (string $e): bool => $e === 'COMMIT'));
$posInsert = array_search(true, array_map(static fn (string $e): bool => str_starts_with($e, 'INSERT INTO mensagem_envio'), $db->eventos), true);
$posPrimeiroCommit = array_search('COMMIT', $db->eventos, true);
falsoVerificar($posInsert !== false && $posPrimeiroCommit !== false && $posInsert < $posPrimeiroCommit, 'registro confirmado (COMMIT) antes da chamada de rede');
falsoVerificar(count($db->chamadas('/^UPDATE otp_desafio SET status = \'ativo\'/')) === 1, 'desafio fica ativo após aceite');
$upd = $db->chamadas('/^UPDATE mensagem_envio/');
falsoVerificar(count($upd) === 1 && str_contains($upd[0]['sql'], "AND status = 'enviando'"), 'atualização guardada por status enviando');
falsoVerificar($upd[0]['valores'][0] === 'aceita', 'status gravado: aceita (não entregue)');
falsoVerificar($upd[0]['valores'][4] === 'bulk-1', 'bulkId gravado');
$todos = $db->todosOsValores() . implode('|', $db->eventos);
falsoVerificar(!str_contains($todos, '5538998969407') && !str_contains($todos, CODIGO) && !str_contains($todos, CHAVE_API) && !str_contains($todos, 'seu código de acesso'),
    'banco nunca recebe telefone completo, código, API key ou texto');
falsoVerificar($db->inicios === $db->commits + $db->rollbacks, 'toda transação é encerrada');

// Desabilitado: nada vai ao provedor nem cria mensagem.
$db = bancoPronto();
$n = 0;
$r = smsEnviarOtp($db, ['ok' => true, 'habilitado' => false, 'erros' => [], 'config' => $config], 78, TELEFONE, TEXTO, transporteAceita($n));
falsoVerificar($r['sucesso'] === false && $r['codigo'] === 'SMS_DESABILITADO' && $n === 0, 'envio desabilitado não chama o provedor');
falsoVerificar($db->chamadas('/^INSERT INTO mensagem_envio/') === [], 'envio desabilitado não cria mensagem');
falsoVerificar(count($db->chamadas("/erro_envio/")) === 1, 'desafio marcado erro_envio');

// Timeout: incerto, sem reenvio, desafio continua utilizável.
$db = bancoPronto();
$n = 0;
$r = smsEnviarOtp($db, $carga, 79, TELEFONE, TEXTO, transporteFalso(['http_status' => 0, 'body' => '', 'errno' => 28], $n));
falsoVerificar($r['estado'] === 'incerto' && $r['sucesso'] === true && $n === 1, 'timeout => incerto, uma só tentativa');
$upd = $db->chamadas('/^UPDATE mensagem_envio/');
falsoVerificar($upd[0]['valores'][0] === 'resultado_incerto', 'status resultado_incerto gravado');
falsoVerificar(count($db->chamadas('/^UPDATE otp_desafio SET status = \'ativo\'/')) === 1, 'timeout mantém o desafio ativo (o SMS pode chegar)');
falsoVerificar($db->chamadas('/erro_envio/') === [], 'timeout não marca erro_envio');

// 5xx incerto; 400 rejeitada; 429 limitada; conexão => erro.
foreach ([[500, 0, 'resultado_incerto', true], [503, 0, 'resultado_incerto', true], [400, 0, 'rejeitada', false], [429, 0, 'rejeitada', false], [0, 7, 'erro', false]] as [$http, $errno, $esperado, $segue]) {
    $db = bancoPronto();
    $n = 0;
    $r = smsEnviarOtp($db, $carga, 80, TELEFONE, TEXTO, transporteFalso(['http_status' => $http, 'body' => '', 'errno' => $errno], $n));
    $upd = $db->chamadas('/^UPDATE mensagem_envio/');
    falsoVerificar(($upd[0]['valores'][0] ?? '') === $esperado, "http $http/errno $errno => $esperado");
    falsoVerificar($r['sucesso'] === $segue, "http $http/errno $errno: sucesso=" . var_export($segue, true));
    falsoVerificar($n === 1, "http $http/errno $errno: sem reenvio automático");
    falsoVerificar($segue || count($db->chamadas('/erro_envio/')) === 1, "http $http/errno $errno: falha definitiva marca erro_envio");
}

// Idempotência: mensagem já existente para o desafio.
$db = bancoPronto('pendente_envio', [['status' => 'aceita']]);
$n = 0;
$r = smsEnviarOtp($db, $carga, 81, TELEFONE, TEXTO, transporteAceita($n));
falsoVerificar($r['sucesso'] === false && $r['estado'] === 'duplicado' && $n === 0, 'mensagem já registrada não é reenviada');
falsoVerificar($db->chamadas('/^INSERT INTO mensagem_envio/') === [], 'sem segunda mensagem para o mesmo desafio');

// Desafio que não está pendente de envio.
foreach (['ativo', 'invalidado', 'consumido', 'erro_envio'] as $status) {
    $db = bancoPronto($status);
    $n = 0;
    $r = smsEnviarOtp($db, $carga, 82, TELEFONE, TEXTO, transporteAceita($n));
    falsoVerificar($r['sucesso'] === false && $n === 0, "desafio $status não envia");
}
$db = new BancoFalso();
$n = 0;
$r = smsEnviarOtp($db, $carga, 83, TELEFONE, TEXTO, transporteAceita($n));
falsoVerificar($r['sucesso'] === false && $n === 0, 'desafio inexistente não envia');

// Telefone inválido.
$db = bancoPronto();
$n = 0;
$r = smsEnviarOtp($db, $carga, 84, '+55', TEXTO, transporteAceita($n));
falsoVerificar($r['sucesso'] === false && $n === 0, 'telefone inválido não envia');

// Falha ao registrar (banco indisponível) não chega à rede.
$db = bancoPronto();
$db->regra('/^INSERT INTO mensagem_envio/', new RuntimeException('banco fora'));
$n = 0;
$r = smsEnviarOtp($db, $carga, 85, TELEFONE, TEXTO, transporteAceita($n));
falsoVerificar($r['sucesso'] === false && $n === 0 && $db->rollbacks === 1, 'falha ao registrar => rollback e nada enviado');

// Relatórios de entrega.
function relatorio(string $messageId, string $grupo, array $extra = []): array
{
    return array_replace_recursive([
        'messageId' => $messageId,
        'status' => ['id' => 5, 'groupName' => $grupo, 'name' => $grupo . '_X'],
        'error' => ['id' => 0, 'name' => 'NO_ERROR', 'permanent' => false],
        'price' => ['pricePerMessage' => 0.05, 'currency' => 'BRL'],
        'sentAt' => '2026-10-29T10:00:00.000+0000',
        'doneAt' => '2026-10-29T10:00:05.000+0000',
        'messageCount' => 1,
    ], $extra);
}
function bancoComMensagem(string $status, ?string $hashHex = null, ?string $eventoEm = null): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT id_mensagem_envio, status/', [[
        'id_mensagem_envio' => 5, 'status' => $status,
        'ultimo_evento_hash' => $hashHex !== null ? hex2bin($hashHex) : null, 'ultimo_evento_em' => $eventoEm,
    ]]);

    return $db;
}
$mid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

$db = bancoComMensagem('aceita');
$r = smsAplicarRelatorio($db, 'teste', relatorio($mid, 'DELIVERED'));
falsoVerificar($r['aplicado'] === true, 'relatório DELIVERED aplicado');
$u = $db->chamadas('/^UPDATE mensagem_envio/');
falsoVerificar(count($u) === 1 && $u[0]['valores'][0] === 'entregue', 'status entregue gravado só pelo relatório');
falsoVerificar($u[0]['valores'][10] === 'BRL' && $u[0]['valores'][9] === '0.050000', 'preço e moeda gravados');
falsoVerificar(str_contains($u[0]['sql'], 'WHERE id_mensagem_envio = ?'), 'atualização pela chave primária bloqueada (FOR UPDATE)');
falsoVerificar(count($db->chamadas('/FOR UPDATE$/')) === 1, 'consulta do estado atual com FOR UPDATE');

// Duplicado: mesmo hash do último evento.
$hashEvento = hash('sha256', json_encode([
    $mid, 5, 'DELIVERED', 0, '2026-10-29T10:00:00.000+0000', '2026-10-29T10:00:05.000+0000', 1,
], JSON_UNESCAPED_UNICODE));
$db = bancoComMensagem('aceita', $hashEvento);
$r = smsAplicarRelatorio($db, 'teste', relatorio($mid, 'DELIVERED'));
falsoVerificar($r['aplicado'] === false && $r['motivo'] === 'duplicado' && $db->chamadas('/^UPDATE/') === [], 'callback duplicado é ignorado');

// Estado final nunca é rebaixado ou trocado.
foreach (['entregue', 'nao_entregue', 'expirada', 'rejeitada', 'cancelada', 'erro'] as $final) {
    foreach (['PENDING', 'DELIVERED', 'UNDELIVERABLE'] as $novo) {
        $db = bancoComMensagem($final);
        $r = smsAplicarRelatorio($db, 'teste', relatorio($mid, $novo));
        falsoVerificar($r['aplicado'] === false && $db->chamadas('/^UPDATE/') === [], "final $final preservado contra $novo");
    }
}

// Fora de ordem: intermediário mais antigo que o último evento.
$db = bancoComMensagem('aceita', null, '2026-10-29 10:00:10.000000');
$r = smsAplicarRelatorio($db, 'teste', relatorio($mid, 'PENDING', ['doneAt' => '2026-10-29T10:00:01.000+0000']));
falsoVerificar($r['aplicado'] === false && $r['motivo'] === 'fora_de_ordem', 'relatório intermediário antigo é ignorado');

// Final atrasado ainda aplica sobre intermediário.
$db = bancoComMensagem('aceita', null, '2026-10-29 10:00:10.000000');
$r = smsAplicarRelatorio($db, 'teste', relatorio($mid, 'UNDELIVERABLE', ['doneAt' => '2026-10-29T10:00:01.000+0000']));
falsoVerificar($r['aplicado'] === true, 'estado final sobrepõe intermediário mesmo com timestamp anterior');

// Incerto resolvido pelo relatório.
$db = bancoComMensagem('resultado_incerto');
$r = smsAplicarRelatorio($db, 'teste', relatorio($mid, 'DELIVERED'));
falsoVerificar($r['aplicado'] === true, 'resultado_incerto resolvido por relatório de entrega');

// Desconhecida, inválida.
$db = new BancoFalso();
$r = smsAplicarRelatorio($db, 'teste', relatorio($mid, 'DELIVERED'));
falsoVerificar($r['aplicado'] === false && $r['motivo'] === 'desconhecida' && $db->rollbacks === 1, 'mensagem desconhecida não altera nada');
$r = smsAplicarRelatorio(new BancoFalso(), 'teste', relatorio($mid, 'XPTO'));
falsoVerificar($r['motivo'] === 'invalido', 'grupo desconhecido ignorado');
$r = smsAplicarRelatorio(new BancoFalso(), 'teste', ['status' => ['groupName' => 'DELIVERED']]);
falsoVerificar($r['motivo'] === 'invalido', 'sem messageId ignorado');

// Erro de banco no relatório propaga (webhook responde 5xx e a Infobip tenta de novo).
$db = bancoComMensagem('aceita');
$db->regra('/^UPDATE mensagem_envio/', new RuntimeException('falha'));
$lancou = false;
try {
    smsAplicarRelatorio($db, 'teste', relatorio($mid, 'DELIVERED'));
} catch (RuntimeException) {
    $lancou = true;
}
falsoVerificar($lancou && $db->rollbacks === 1, 'falha de banco no relatório => rollback e exceção');

falsoRelatorio();
