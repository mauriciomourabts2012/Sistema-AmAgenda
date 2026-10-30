<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| WEBHOOK INFOBIP - RELATÓRIOS DE ENTREGA DE SMS
|--------------------------------------------------------------------------
|
| Contrato (Infobip SMS API v3, webhooks.delivery): POST application/json com
| { "results": [ { messageId, bulkId, status{id,groupName,name}, error{...},
| price{...}, sentAt, doneAt, messageCount, callbackData, ... } ] }.
|
| AUTENTICIDADE (conferido na documentação pública da Infobip em 29/10/2026): os
| webhooks por requisição (webhooks.delivery inline) NÃO têm autenticação nativa
| documentada; Basic/HMAC/OAuth/mTLS existem apenas nas assinaturas (Subscriptions),
| sem nomes de cabeçalho confirmados. Por isso NENHUM cabeçalho de assinatura é
| presumido. Retentativas da Infobip: até 20, em ~41 h; depois disso o evento se perde. O callback só é aceito quando:
|   1) a URL enviada por nós carrega o token por mensagem (?t=), derivado de
|      webhook_secret e do messageId; e
|   2) o callbackData devolvido pela Infobip é o HMAC do messageId derivado de
|      hmac_key (chave independente);
| e o messageId existe em mensagem_envio. O token na URL pode aparecer em logs de acesso
| do servidor web, mas sozinho não autentica: o callbackData (corpo, nunca logado) também
| é exigido. Este arquivo não registra URL, token, corpo nem callbackData. Se a conta passar a oferecer uma
| autenticação nativa (Basic/assinatura), ela deve ser somada a estas.
|
| Idempotente e tolerante a ordem: ver smsAplicarRelatorio().
|
*/

const INFOBIP_WEBHOOK_CORPO_MAX_BYTES = 262144;
const INFOBIP_WEBHOOK_MAX_RESULTADOS = 100;

require_once __DIR__ . '/../_servicos/sms.php';

function infobipWebhookResponder(int $httpStatus, string $codigo, bool $ok = false): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok' => $ok, 'code' => $codigo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Autentica o callback de UMA mensagem. Comparação em tempo constante.
 *
 * @param array<string, mixed> $config
 */
function infobipWebhookAutenticar(array $config, string $messageId, string $tokenRecebido, mixed $callbackData): bool
{
    // Formatos fixos (HMAC-SHA256 em hex) são checados antes de qualquer comparação.
    if ($messageId === '' || strlen($messageId) > 150
        || preg_match('/^[0-9a-f]{64}$/', $tokenRecebido) !== 1
        || !is_string($callbackData) || preg_match('/^[0-9a-f]{64}$/', $callbackData) !== 1) {
        return false;
    }

    return hash_equals(infobipTokenWebhook($config, $messageId), $tokenRecebido)
        && hash_equals(infobipCallbackData($config, $messageId), $callbackData);
}

/**
 * Núcleo testável: devolve o status HTTP e um código fixo (sem dados do corpo).
 *
 * @param array{ok: bool, habilitado: bool, erros: list<string>, config: array<string, mixed>} $carga
 * @return array{status: int, codigo: string, aplicados: int}
 */
function infobipWebhookProcessar(mysqli $conexao, array $carga, string $corpo, string $token): array
{
    if ($carga['ok'] !== true) {
        return ['status' => 503, 'codigo' => 'WEBHOOK_INDISPONIVEL', 'aplicados' => 0];
    }
    if (strlen($corpo) > INFOBIP_WEBHOOK_CORPO_MAX_BYTES || $corpo === '') {
        return ['status' => 413, 'codigo' => 'WEBHOOK_CORPO_INVALIDO', 'aplicados' => 0];
    }
    $json = json_decode($corpo, true);
    $resultados = is_array($json) ? ($json['results'] ?? null) : null;
    if (!is_array($resultados) || $resultados === [] || count($resultados) > INFOBIP_WEBHOOK_MAX_RESULTADOS || !array_is_list($resultados)) {
        return ['status' => 400, 'codigo' => 'WEBHOOK_PAYLOAD_INVALIDO', 'aplicados' => 0];
    }

    $config = $carga['config'];

    // Autentica TODOS os itens antes de gravar qualquer um.
    foreach ($resultados as $item) {
        if (!is_array($item)
            || !infobipWebhookAutenticar($config, (string)($item['messageId'] ?? ''), $token, $item['callbackData'] ?? null)) {
            return ['status' => 401, 'codigo' => 'WEBHOOK_NAO_AUTENTICADO', 'aplicados' => 0];
        }
    }

    $aplicados = 0;
    foreach ($resultados as $item) {
        try {
            $resultado = smsAplicarRelatorio($conexao, (string)$config['ambiente'], $item);
        } catch (Throwable) {
            // 500 faz a Infobip tentar novamente; o relatório é idempotente.
            return ['status' => 500, 'codigo' => 'WEBHOOK_FALHA_INTERNA', 'aplicados' => $aplicados];
        }
        if ($resultado['aplicado']) {
            $aplicados++;
        }
    }

    return ['status' => 200, 'codigo' => 'WEBHOOK_PROCESSADO', 'aplicados' => $aplicados];
}

if (defined('INFOBIP_WEBHOOK_SOMENTE_FUNCOES')) {
    return;
}

date_default_timezone_set('America/Sao_Paulo');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    infobipWebhookResponder(405, 'WEBHOOK_METODO_NAO_PERMITIDO');
}

$tipoConteudo = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($tipoConteudo, 'application/json')) {
    infobipWebhookResponder(415, 'WEBHOOK_TIPO_NAO_SUPORTADO');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > INFOBIP_WEBHOOK_CORPO_MAX_BYTES) {
    infobipWebhookResponder(413, 'WEBHOOK_CORPO_INVALIDO');
}

$corpoBruto = (string)file_get_contents('php://input', false, null, 0, INFOBIP_WEBHOOK_CORPO_MAX_BYTES + 1);

require_once __DIR__ . '/../_config/conexao.php';

$tokenRecebido = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$resposta = infobipWebhookProcessar($conexao, infobipConfigCarregar(), $corpoBruto, $tokenRecebido);

infobipWebhookResponder($resposta['status'], $resposta['codigo'], $resposta['status'] === 200);
