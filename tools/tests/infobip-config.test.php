<?php
declare(strict_types=1);

/*
 * Testes da configuração Infobip. Sem rede, sem banco, sem segredos reais.
 * Uso: php tools/tests/infobip-config.test.php
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

/** Valores fictícios, sem relação com credenciais reais. */
function configValida(array $troca = []): array
{
    return array_replace([
        'habilitado' => true,
        'ambiente' => 'teste',
        'base_url' => 'https://abc123.api.infobip.com',
        'api_key' => str_repeat('k', 32),
        'sender' => 'AmAgenda',
        'timeout_conexao' => 5,
        'timeout_total' => 15,
        'hmac_key' => str_repeat('h', 40),
        'webhook_secret' => str_repeat('w', 40),
        'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
    ], $troca);
}

function erros(array $troca): array
{
    return infobipConfigValidar(configValida($troca))['erros'];
}

// Configuração válida.
$valida = infobipConfigValidar(configValida());
verificar($valida['ok'] === true && $valida['erros'] === [], 'configuração válida aceita');
verificar($valida['config']['habilitado'] === true, 'habilitado=true preservado');

// Habilitado só com true estrito.
foreach (['true', 1, '1', 'sim', null] as $valor) {
    verificar(infobipConfigValidar(configValida(['habilitado' => $valor]))['config']['habilitado'] === false, 'habilitado não-booleano permanece desligado: ' . var_export($valor, true));
}

// HTTPS e host Infobip.
verificar(in_array('BASE_URL_INVALIDA', erros(['base_url' => 'http://abc123.api.infobip.com']), true), 'HTTP recusado');
verificar(in_array('BASE_URL_INVALIDA', erros(['base_url' => '']), true), 'base_url vazia recusada');
verificar(in_array('BASE_URL_INVALIDA', erros(['base_url' => 'https://usuario:senha@abc123.api.infobip.com']), true), 'credenciais embutidas na URL recusadas');
verificar(in_array('BASE_URL_INVALIDA', erros(['base_url' => 'https://abc123.api.infobip.com/outra/rota']), true), 'caminho extra recusado');
verificar(in_array('BASE_URL_INVALIDA', erros(['base_url' => 'https://abc123.api.infobip.com:8443']), true), 'porta diferente de 443 recusada');
verificar(in_array('HOST_NAO_INFOBIP', erros(['base_url' => 'https://api.infobip.com.malicioso.test']), true), 'sufixo enganoso recusado');
verificar(in_array('HOST_NAO_INFOBIP', erros(['base_url' => 'https://malicioso.test']), true), 'host externo recusado');
verificar(in_array('HOST_NAO_INFOBIP', erros(['base_url' => 'https://SEU-SUBDOMINIO.api.infobip.com']), true), 'placeholder do modelo recusado');
verificar(erros(['base_url' => 'https://api.infobip.com']) === [], 'host api.infobip.com aceito');
verificar(erros(['base_url' => 'https://abc123.api.infobip.com/']) === [], 'barra final tolerada');

// API key.
verificar(in_array('API_KEY_INVALIDA', erros(['api_key' => '']), true), 'API key vazia recusada');
verificar(in_array('API_KEY_INVALIDA', erros(['api_key' => 'curta']), true), 'API key curta recusada');
verificar(in_array('API_KEY_INVALIDA', erros(['api_key' => str_repeat('k', 30) . " \n"]), true), 'API key com espaço/quebra recusada');

// Sender.
verificar(in_array('SENDER_INVALIDO', erros(['sender' => '']), true), 'sender vazio recusado');
verificar(in_array('SENDER_INVALIDO', erros(['sender' => 'Am Agenda']), true), 'sender com espaço recusado');
verificar(in_array('SENDER_INVALIDO', erros(['sender' => 'NomeMuitoLongoDemais']), true), 'sender longo recusado');
verificar(erros(['sender' => '5538998969407']) === [], 'sender numérico aceito');

// Ambiente.
verificar(in_array('AMBIENTE_INVALIDO', erros(['ambiente' => 'dev']), true), 'ambiente desconhecido recusado');
verificar(erros(['ambiente' => 'producao']) === [], 'ambiente producao aceito');

// Timeouts.
verificar(in_array('TIMEOUT_CONEXAO_INVALIDO', erros(['timeout_conexao' => 0]), true), 'timeout de conexão zero recusado');
verificar(in_array('TIMEOUT_CONEXAO_INVALIDO', erros(['timeout_conexao' => '5']), true), 'timeout em texto recusado');
verificar(in_array('TIMEOUT_TOTAL_INVALIDO', erros(['timeout_total' => 120]), true), 'timeout total excessivo recusado');
verificar(in_array('TIMEOUT_TOTAL_INVALIDO', erros(['timeout_conexao' => 8, 'timeout_total' => 4]), true), 'total menor que conexão recusado');

// Chaves independentes.
verificar(in_array('HMAC_KEY_FRACA', erros(['hmac_key' => 'curta']), true), 'HMAC curta recusada');
verificar(in_array('WEBHOOK_SECRET_FRACO', erros(['webhook_secret' => 'curto']), true), 'segredo do webhook curto recusado');
$igual = str_repeat('x', 40);
verificar(in_array('CHAVES_NAO_INDEPENDENTES', erros(['hmac_key' => $igual, 'webhook_secret' => $igual]), true), 'HMAC e webhook iguais recusados');

// URL do webhook.
verificar(in_array('WEBHOOK_URL_INVALIDA', erros(['webhook_url' => 'http://exemplo.test/x']), true), 'webhook HTTP recusado');
verificar(in_array('WEBHOOK_URL_INVALIDA', erros(['webhook_url' => 'https://SEU-DOMINIO/x']), true), 'placeholder do webhook recusado');
verificar(in_array('WEBHOOK_URL_INVALIDA', erros(['webhook_url' => 'https://exemplo.test/x?path=webhooks/infobip&t=abc']), true), 'token já presente na URL base recusado');

// Erros nunca devolvem valores.
$res = infobipConfigValidar(configValida(['api_key' => 'segredo-de-teste-curto', 'hmac_key' => 'k1']));
verificar(!str_contains(json_encode($res['erros']), 'segredo-de-teste-curto'), 'erros não vazam valores');

// Carregamento.
$ausente = infobipConfigCarregar(__DIR__ . '/arquivo-inexistente.php');
verificar($ausente['ok'] === false && $ausente['habilitado'] === false && $ausente['erros'] === ['CONFIG_AUSENTE'], 'configuração ausente => desligada');

$exemplo = __DIR__ . '/../../backend/_config/infobip.example.php';
$modelo = infobipConfigCarregar($exemplo);
verificar($modelo['habilitado'] === false && $modelo['ok'] === false, 'modelo de exemplo nunca habilita o envio');
$bruto = require $exemplo;
verificar(($bruto['habilitado'] ?? null) === false, 'exemplo: envio desabilitado por padrão');
verificar(($bruto['api_key'] ?? 'x') === '' && ($bruto['hmac_key'] ?? 'x') === '' && ($bruto['webhook_secret'] ?? 'x') === '', 'exemplo: sem segredos');

$tmp = sys_get_temp_dir() . '/infobip-teste-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tmp, '<?php return ' . var_export(configValida(), true) . ';');
$carregada = infobipConfigCarregar($tmp);
unlink($tmp);
verificar($carregada['ok'] === true && $carregada['habilitado'] === true, 'arquivo privado válido é carregado');

$tmp = sys_get_temp_dir() . '/infobip-teste-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tmp, '<?php return ' . var_export(configValida(['habilitado' => false]), true) . ';');
$desligada = infobipConfigCarregar($tmp);
unlink($tmp);
verificar($desligada['ok'] === true && $desligada['habilitado'] === false, 'habilitado=false mantém o envio desligado');

$tmp = sys_get_temp_dir() . '/infobip-teste-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tmp, '<?php return "texto";');
$invalida = infobipConfigCarregar($tmp);
unlink($tmp);
verificar($invalida['ok'] === false && $invalida['habilitado'] === false, 'arquivo que não retorna array é recusado');

// Arquivo privado ignorado pelo Git e sem segredo versionado.
$gitignore = (string)file_get_contents(__DIR__ . '/../../.gitignore');
verificar(preg_match('#^/backend/_config/infobip\.php\s*$#m', $gitignore) === 1, '.gitignore ignora backend/_config/infobip.php');
$conteudoExemplo = (string)file_get_contents($exemplo);
verificar(preg_match('/[A-Fa-f0-9]{32,}/', $conteudoExemplo) !== 1, 'exemplo sem sequências longas que pareçam chaves');

echo $total . ' verificações, ' . count($falhas) . " falha(s)\n";
foreach ($falhas as $falha) {
    echo "FALHA: " . $falha . "\n";
}
exit($falhas === [] ? 0 : 1);
