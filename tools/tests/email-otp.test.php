<?php
declare(strict_types=1);

/*
 * Testes do OTP por e-mail (Gmail) com banco falso e transporte falso.
 * Nenhum e-mail real, nenhuma conexão SMTP, nenhuma escrita em banco.
 * Uso: php tools/tests/email-otp.test.php
 */

$total = 0;
$falhas = [];
date_default_timezone_set('UTC');
require_once __DIR__ . '/sms-banco-falso.php';
require_once __DIR__ . '/../../backend/_servicos/sms_otp.php';

$configInfobip = [
    'habilitado' => true, 'ambiente' => 'teste', 'base_url' => 'https://abc123.api.infobip.com',
    'api_key' => 'chave-api-ficticia-0123456789abcdef', 'sender' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
    'hmac_key' => str_repeat('h', 40), 'webhook_secret' => str_repeat('w', 40),
    'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
];
$carga = ['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $configInfobip];
smsOtpCarga($carga);
$_SESSION = [];

$gmailBruto = [
    'habilitado' => true, 'ambiente' => 'teste', 'host' => 'smtp.gmail.com', 'porta' => 465,
    'usuario' => 'suporte@exemplo.test', 'senha_app' => 'abcd efgh ijkl mnop',
    'remetente_email' => 'suporte@exemplo.test', 'remetente_nome' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
];
$gmailValida = gmailConfigValidar($gmailBruto);
$gmailCarga = ['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $gmailValida['config']];

const TEL = '+5538998969407';
const EMAIL = 'cliente@exemplo.test';

// ---- Configuração -------------------------------------------------------------------------
falsoVerificar($gmailValida['ok'] === true && $gmailValida['config']['senha_app'] === 'abcdefghijklmnop', 'config válida; senha de app normalizada sem espaços');
// O arquivo real (privado) precisa começar exatamente por "<?php": qualquer byte antes causa erro
// fatal de strict_types no require. Verificado sem carregar nem expor os valores.
$caminhoReal = __DIR__ . '/../../backend/_config/gmail.php';
if (is_file($caminhoReal)) {
    falsoVerificar(str_starts_with((string)file_get_contents($caminhoReal, false, null, 0, 5), '<?php'), 'gmail.php começa por <?php (sem byte antes)');
}
falsoVerificar(gmailConfigCarregar(__DIR__ . '/nao-existe.php')['erros'] === ['CONFIG_AUSENTE'], 'config ausente => indisponível');
foreach ([
    ['host', 'smtp.exemplo.com', 'HOST_INVALIDO'], ['porta', 25, 'PORTA_INVALIDA'], ['usuario', 'sem-arroba', 'USUARIO_INVALIDO'],
    ['senha_app', 'curta', 'SENHA_APP_INVALIDA'], ['senha_app', '', 'SENHA_APP_INVALIDA'], ['remetente_email', '', 'REMETENTE_INVALIDO'],
    ['remetente_nome', "AmAgenda\r\nBcc: x@y.z", 'REMETENTE_NOME_INVALIDO'], ['ambiente', 'x', 'AMBIENTE_INVALIDO'],
] as [$campo, $valor, $erro]) {
    $v = gmailConfigValidar([$campo => $valor] + $gmailBruto);
    falsoVerificar($v['ok'] === false && in_array($erro, $v['erros'], true), 'config recusa ' . $campo);
}
$desligada = gmailConfigValidar(['habilitado' => false] + $gmailBruto);
falsoVerificar($desligada['ok'] === true && $desligada['config']['habilitado'] === false, 'habilitado=false mantém desligado');
falsoVerificar(!str_contains(json_encode($v['erros']), 'abcd'), 'erros de config não carregam valores');

// ---- Endereço, máscara, mensagem ----------------------------------------------------------
falsoVerificar(emailOtpNormalizar('  Cliente@Exemplo.TEST ') === EMAIL, 'e-mail normalizado em minúsculas');
foreach (["a@b.c\r\nBcc: x@y.z", 'sem-arroba', 'a b@c.de', '<a@b.cd>', str_repeat('a', 170) . '@b.cd', ''] as $ruim) {
    falsoVerificar(emailOtpNormalizar($ruim) === null, 'e-mail inválido/injeção recusado');
}
falsoVerificar(emailOtpMascarar(EMAIL) === 'c***@e***.test', 'máscara não revela o endereço');
$msg = gmailMontarMensagem($gmailValida['config'], smsUuidV4(), EMAIL, 'AmAgenda - seu código', 'Código 123456');
falsoVerificar(!str_contains(explode("\r\n\r\n", $msg)[0], '123456') && str_contains($msg, 'Content-Transfer-Encoding: base64'), 'código só no corpo codificado');
falsoVerificar(substr_count(explode("\r\n\r\n", $msg)[0], "\r\n") === 8, 'cabeçalhos fixos, sem linha extra');
falsoVerificar(base64_decode(explode("\r\n\r\n", $msg)[1]) === 'Código 123456', 'corpo UTF-8 íntegro');

// ---- Classificação do transporte ----------------------------------------------------------
$c = static fn (string $fase, int $codigo): string => gmailClassificarResposta(['fase' => $fase, 'codigo' => $codigo, 'errno' => 0])['resultado'];
falsoVerificar($c('final', 250) === 'aceita', '250 após DATA => aceita');
falsoVerificar($c('final', 0) === 'incerto', 'sem resposta final => incerto');
falsoVerificar($c('final', 554) === 'rejeitada' && $c('final', 451) === 'rejeitada', 'recusa após DATA => rejeitada');
falsoVerificar($c('auth', 535) === 'rejeitada' && $c('destinatario', 550) === 'rejeitada', 'auth/destinatário recusados');
falsoVerificar($c('conexao', 0) === 'falha_conexao' && $c('data', 0) === 'falha_conexao' && $c('remetente', 421) === 'falha_conexao', 'antes do conteúdo => nada enviado');

// ---- Envio direto (emailEnviarOtp) --------------------------------------------------------
function transporteGmail(array &$capturado, int &$chamadas, array $resposta = ['fase' => 'final', 'codigo' => 250, 'errno' => 0]): callable
{
    return static function (array $req) use (&$capturado, &$chamadas, $resposta): array {
        $chamadas++;
        $capturado = $req;

        return $resposta;
    };
}
function bancoEmail(array $extra = []): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT 1 FROM cliente WHERE id_cliente/', [['1' => 1]]);
    $db->regra('/^SELECT 1 FROM cliente WHERE id_empresa/', []);
    $db->regra('/^SELECT COUNT\(\*\) AS n/', [['n' => 0]]);
    $db->regra('/^SELECT LAST_INSERT_ID/', [['id' => 42]]);
    $db->regra('/^SELECT status FROM otp_desafio/', [['status' => 'pendente_envio']]);
    foreach ($extra as [$regex, $resposta]) {
        $db->regra($regex, $resposta);
    }

    return $db;
}

$cap = [];
$n = 0;
$db = bancoEmail();
$r = emailEnviarOtp($db, $gmailCarga, 42, EMAIL, 'Assunto', 'Texto 123456', transporteGmail($cap, $n));
falsoVerificar($r['sucesso'] === true && $r['estado'] === 'aceita' && $n === 1, 'envio aceito');
$ins = $db->chamadas('/^INSERT INTO mensagem_envio/')[0];
falsoVerificar(str_contains($ins['sql'], "'email', 'gmail'") && str_contains($ins['sql'], 'NULL') && $ins['valores'][2] === 'c***@e***.test', 'registro: canal email, provedor gmail, sem telefone, destino mascarado');
falsoVerificar(!str_contains($db->todosOsValores(), 'cliente@exemplo') && !str_contains($db->todosOsValores(), '123456'), 'banco não recebe e-mail completo nem código');
falsoVerificar(str_contains(json_encode($db->eventos), 'BEGIN') && array_search('INSERT', array_map(static fn ($e) => substr($e, 0, 6), $db->eventos)) !== false, 'registro confirmado antes do envio');
$posCommit = array_search('COMMIT', $db->eventos, true);
falsoVerificar($posCommit !== false && $cap !== [], 'transação de registro fechada antes da rede');
falsoVerificar(count($db->chamadas("/^UPDATE otp_desafio SET status = 'ativo'/")) === 1, 'desafio vira ativo após aceite');

$n = 0;
$r = emailEnviarOtp(bancoEmail(), $gmailCarga, 42, EMAIL, 'A', 'T', transporteGmail($cap, $n, ['fase' => 'final', 'codigo' => 0, 'errno' => 0]));
falsoVerificar($r['sucesso'] === false && $r['estado'] === 'incerto', 'sem resposta final => incerto, sem reenvio automático');
$db = bancoEmail();
$r = emailEnviarOtp($db, $gmailCarga, 42, EMAIL, 'A', 'T', transporteGmail($cap, $n, ['fase' => 'auth', 'codigo' => 535, 'errno' => 0]));
falsoVerificar($r['sucesso'] === false && $r['estado'] === 'rejeitada' && count($db->chamadas("/status = 'erro_envio'/")) === 1, 'recusa definitiva => erro_envio');
falsoVerificar(!str_contains(json_encode($db->log), 'abcdefghijklmnop'), 'senha de app nunca chega ao banco');
$n = 0;
$r = emailEnviarOtp(bancoEmail(), ['habilitado' => false] + $gmailCarga, 42, EMAIL, 'A', 'T', transporteGmail($cap, $n));
falsoVerificar($r['codigo'] === 'EMAIL_DESABILITADO' && $n === 0, 'desabilitado não envia');
$r = emailEnviarOtp(bancoEmail([['/^SELECT status FROM mensagem_envio/', [['status' => 'aceita']]]]), $gmailCarga, 42, EMAIL, 'A', 'T', transporteGmail($cap, $n));
falsoVerificar($r['estado'] === 'duplicado' && $n === 0, 'um desafio gera no máximo uma mensagem');

// ---- Solicitação por e-mail (smsOtpSolicitar) ---------------------------------------------
emailOtpCarga($gmailCarga);
$pCliente = [
    'principal_tipo' => 'cliente', 'finalidade' => 'acesso_cliente', 'id_empresa' => 7, 'id_cliente' => 123, 'id_usuario' => 0,
    'telefone' => TEL, 'ip' => '203.0.113.9', 'enviar' => true, 'canal' => 'email', 'email' => EMAIL,
];
$pFicticio = ['principal_tipo' => 'ficticio', 'id_cliente' => 0] + $pCliente;

$n = 0;
$db = bancoEmail();
$r = smsOtpSolicitar($db, $pCliente, transporteGmail($cap, $n));
falsoVerificar($r['sucesso'] === true && $r['codigo'] === 'SMS_OTP_ENVIADO' && $n === 1 && $r['dados']['canal'] === 'email', 'cliente com e-mail validado recebe o código');
$consulta = $db->chamadas('/^SELECT 1 FROM cliente WHERE id_cliente/');
falsoVerificar(count($consulta) >= 1 && str_contains(end($consulta)['sql'], 'email_verificado_em IS NOT NULL'), 'só envia para e-mail validado do próprio cliente/empresa');
$ins = $db->chamadas('/^INSERT INTO otp_desafio/')[0];
falsoVerificar($ins['valores'][13] === 'email' && is_string($ins['valores'][14]) && strlen($ins['valores'][14]) === 64 && $ins['valores'][14] !== $ins['valores'][6], 'canal e destino_hash gravados; hash do e-mail ≠ hash do telefone');
falsoVerificar($ins['valores'][5] === smsOtpChavePrincipal($carga, $pCliente, TEL), 'chave do principal igual à do SMS (mesmo slot ativo entre canais)');
preg_match('/\b(\d{6})\b/', (string)($cap['mensagem'] ?? ''), $x);
$corpoDecodificado = base64_decode(explode("\r\n\r\n", (string)$cap['mensagem'])[1]);
falsoVerificar(preg_match('/\b\d{6}\b/', $corpoDecodificado) === 1 && !str_contains($db->todosOsValores(), $corpoDecodificado), 'código no e-mail, nunca persistido em claro');
falsoVerificar(count($db->chamadas("/status IN \('pendente_envio', 'ativo', 'validado'\)/")) === 1, 'novo desafio invalida o ativo anterior (qualquer canal)');
falsoVerificar(!str_contains(json_encode($r), 'cliente@exemplo'), 'resposta não expõe o e-mail');

// Mesma chave para SMS: trocar de canal não cria segundo slot.
$pSms = ['canal' => 'sms'] + $pCliente;
falsoVerificar(smsOtpChavePrincipal($carga, $pSms, TEL) === smsOtpChavePrincipal($carga, $pCliente, TEL), 'chave independe do canal');

// E-mail não validado, ou de outro cliente: desafio decoy, sem envio, mesma resposta.
$n = 0;
$db = bancoEmail([['/^SELECT 1 FROM cliente WHERE id_cliente = \? AND id_empresa = \? AND LOWER\(email\)/', []]]);
$r = smsOtpSolicitar($db, $pCliente, transporteGmail($cap, $n));
falsoVerificar($r['sucesso'] === true && $r['codigo'] === 'SMS_OTP_CRIADO' && $r['dados']['decoy'] === true && $n === 0, 'e-mail não validado/diferente: sem envio e sem erro revelador');
$ins = $db->chamadas('/^INSERT INTO otp_desafio/')[0];
falsoVerificar($ins['valores'][10] === 'ativo', 'decoy nasce ativo (mesmo contrato)');

// Primeiro acesso: e-mail livre envia; e-mail já cadastrado na empresa vira decoy.
$n = 0;
$db = bancoEmail();
$r = smsOtpSolicitar($db, $pFicticio, transporteGmail($cap, $n));
falsoVerificar($r['sucesso'] === true && $r['codigo'] === 'SMS_OTP_ENVIADO' && $n === 1, 'primeiro acesso por e-mail novo envia');
$n = 0;
$db = bancoEmail([['/^SELECT 1 FROM cliente WHERE id_empresa = \? AND LOWER\(email\)/', [['1' => 1]]]]);
$r = smsOtpSolicitar($db, $pFicticio, transporteGmail($cap, $n));
falsoVerificar($r['sucesso'] === true && $r['dados']['decoy'] === true && $n === 0, 'e-mail já cadastrado na empresa não gera conta/associação nova');
$pRec = ['finalidade' => 'recuperacao_cliente'] + $pFicticio;
$n = 0;
$r = smsOtpSolicitar(bancoEmail(), $pRec, transporteGmail($cap, $n));
falsoVerificar($r['dados']['decoy'] === true && $n === 0, 'recuperação fictícia nunca envia e-mail');

// Entradas e disponibilidade.
$n = 0;
foreach ([['email' => 'invalido'], ['email' => ''], ['email' => "a@b.cd\r\nBcc: x@y.zz"]] as $troca) {
    $db = bancoEmail();
    $r = smsOtpSolicitar($db, $troca + $pCliente, transporteGmail($cap, $n));
    falsoVerificar($r['sucesso'] === false && $r['codigo'] === 'EMAIL_INVALIDO' && $db->log === [] && $n === 0, 'e-mail inválido recusado antes do banco');
}
falsoVerificar(smsOtpSolicitar(bancoEmail(), ['canal' => 'whatsapp'] + $pCliente)['codigo'] === 'OTP_CANAL_INVALIDO', 'canal desconhecido recusado');
emailOtpCarga(['habilitado' => false] + $gmailCarga);
$db = bancoEmail();
$r = smsOtpSolicitar($db, $pCliente, transporteGmail($cap, $n));
falsoVerificar($r['codigo'] === 'EMAIL_INDISPONIVEL' && $db->log === [] && $n === 0, 'canal desligado: nada é criado nem enviado');
emailOtpCarga($gmailCarga);
$r = smsOtpSolicitar(bancoEmail(), ['principal_tipo' => 'usuario', 'finalidade' => 'recuperacao_usuario', 'id_empresa' => 0, 'id_cliente' => 0, 'id_usuario' => 5] + $pCliente);
falsoVerificar($r['codigo'] === 'EMAIL_INDISPONIVEL', 'recuperação de usuário interno não usa e-mail');

// Limites: e-mail, telefone e IP contam entre canais.
$db = bancoEmail([['/^SELECT COUNT\(\*\) AS n FROM otp_desafio WHERE destino_hash/', [['n' => 5]]], ['/^SELECT GREATEST/', [['espera' => 900]]]]);
$n = 0;
$r = smsOtpSolicitar($db, $pCliente, transporteGmail($cap, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_LIMITE' && $r['dados']['motivo'] === 'email' && $r['dados']['retry_after'] === 900 && $n === 0 && $db->chamadas('/^INSERT INTO otp_desafio/') === [], 'limite por e-mail/hora recusa sem criar desafio');
$db = bancoEmail([['/^SELECT COUNT\(\*\) AS n FROM otp_desafio WHERE telefone_hash/', [['n' => 5]]]]);
$r = smsOtpSolicitar($db, $pCliente, transporteGmail($cap, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_LIMITE' && $r['dados']['motivo'] === 'telefone', 'limite do telefone vale também para o canal e-mail');

// Reenvio 30 s compartilhado entre canais (mesma chave).
$db = bancoEmail([['/^SELECT TIMESTAMPDIFF/', [['idade' => 10]]]]);
$r = smsOtpSolicitar($db, $pCliente, transporteGmail($cap, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_REENVIO_AGUARDAR' && $db->chamadas('/^INSERT INTO otp_desafio/') === [], 'reenvio < 30 s recusado também por e-mail');

// Falha definitiva restaura o desafio anterior, como no SMS.
$db = bancoEmail([['/^SELECT id_otp_desafio FROM otp_desafio/', [['id_otp_desafio' => 41]]]]);
$r = smsOtpSolicitar($db, $pCliente, transporteGmail($cap, $n, ['fase' => 'destinatario', 'codigo' => 550, 'errno' => 0]));
falsoVerificar($r['codigo'] === 'SMS_OTP_ENVIO_FALHOU' && $r['dados']['desafio_anterior_preservado'] === true, 'falha definitiva preserva o código anterior');
$r = smsOtpSolicitar(bancoEmail(), $pCliente, transporteGmail($cap, $n, ['fase' => 'final', 'codigo' => 0, 'errno' => 0]));
falsoVerificar($r['codigo'] === 'SMS_OTP_ENVIO_INCERTO', 'envio incerto usa o mesmo contrato do SMS');

// SMS segue igual: não consulta e-mail, não usa Gmail.
$n = 0;
$transporteInfobip = static function (array $req) use (&$n): array {
    $n++;
    $corpo = json_decode($req['body'], true);

    return ['http_status' => 200, 'errno' => 0, 'body' => json_encode(['bulkId' => 'b', 'messages' => [[
        'messageId' => $corpo['messages'][0]['destinations'][0]['messageId'], 'status' => ['groupName' => 'PENDING', 'id' => 26, 'name' => 'X'],
    ]]])];
};
$db = bancoEmail();
$r = smsOtpSolicitar($db, ['canal' => 'sms', 'email' => EMAIL] + $pCliente, $transporteInfobip);
falsoVerificar($r['sucesso'] === true && $n === 1 && $r['dados']['canal'] === 'sms', 'canal sms continua usando a Infobip');
$ins = $db->chamadas('/^INSERT INTO otp_desafio/')[0];
falsoVerificar($ins['valores'][13] === 'sms' && $ins['valores'][14] === null, 'SMS grava canal sms e destino_hash nulo');
falsoVerificar($db->chamadas('/^INSERT INTO mensagem_envio/')[0]['valores'][1] !== null && str_contains($db->chamadas('/^INSERT INTO mensagem_envio/')[0]['sql'], "'sms', 'infobip'"), 'mensagem_envio do SMS inalterada');
$db = bancoEmail();
$r = smsOtpSolicitar($db, array_diff_key($pCliente, ['canal' => 1, 'email' => 1]), $transporteInfobip);
falsoVerificar($r['sucesso'] === true && $r['dados']['canal'] === 'sms', 'sem canal informado o padrão é SMS (compatível com cliente_login.php atual)');

// ---- Validação ----------------------------------------------------------------------------
$_SESSION = [];
$sessaoHex = smsOtpHmac($carga, 'sessao', smsOtpVinculoSessao());
$codigoOk = '482913';
$hashCodigo = smsOtpCodigoHash($carga, $codigoOk);
$hashEmail = smsOtpHmac($carga, 'email', EMAIL);
$linha = static fn (array $t = []): array => array_replace([
    'status' => 'ativo', 'codigo_hash' => $hashCodigo, 'tentativas_validacao' => 0, 'max_tentativas' => 5, 'otp_empresa' => 7,
    'sessao_hex' => $sessaoHex, 'expirado' => 0, 'cliente_empresa' => 7, 'cliente_status' => 'ativo', 'canal' => 'email', 'destino_hex' => $hashEmail,
], $t);
$bancoVal = static function (array $l): BancoFalso {
    $db = new BancoFalso();
    $db->regra('/^SELECT o\.status/', [$l]);

    return $db;
};
$pVal = $pCliente + ['id_otp_desafio' => 42, 'codigo' => $codigoOk];

$r = smsOtpValidar($bancoVal($linha()), $pVal);
falsoVerificar($r['sucesso'] === true && $r['dados']['canal'] === 'email', 'código por e-mail valida e informa o canal');
$db = $bancoVal($linha());
smsOtpValidar($db, ['email' => 'outro@exemplo.test'] + $pVal);
falsoVerificar($db->chamadas('/^UPDATE/') === [] && smsOtpValidar($bancoVal($linha()), ['email' => 'outro@exemplo.test'] + $pVal)['sucesso'] === false, 'e-mail diferente do pedido não valida e não gasta tentativa');
falsoVerificar(smsOtpValidar($bancoVal($linha()), array_diff_key($pVal, ['email' => 1]))['sucesso'] === false, 'desafio por e-mail exige o e-mail na validação');
falsoVerificar(smsOtpValidar($bancoVal($linha(['status' => 'consumido'])), $pVal)['sucesso'] === false, 'consumo único');
falsoVerificar(smsOtpValidar($bancoVal($linha(['sessao_hex' => str_repeat('0', 64)])), $pVal)['sucesso'] === false, 'vínculo de sessão preservado');
falsoVerificar(smsOtpValidar($bancoVal($linha(['canal' => 'sms', 'destino_hex' => null])), array_diff_key($pVal, ['email' => 1]))['sucesso'] === true, 'desafio SMS valida sem e-mail (fluxo atual intacto)');
$db = $bancoVal($linha(['tentativas_validacao' => 4]));
$r = smsOtpValidar($db, ['codigo' => '000000'] + $pVal);
falsoVerificar($r['codigo'] === 'SMS_OTP_TENTATIVAS' && count($db->chamadas("/status = 'bloqueado'/")) === 1, 'tentativas persistentes valem para e-mail');

// ---- Marcação de e-mail validado ----------------------------------------------------------
$db = new BancoFalso();
$db->regra('/^UPDATE cliente SET email_verificado_em/', 1);
falsoVerificar(emailOtpMarcarVerificado($db, 7, 123, 'Cliente@Exemplo.test') === true, 'marca e-mail validado');
$u = $db->chamadas('/^UPDATE cliente SET email_verificado_em/')[0];
falsoVerificar($u['valores'] === [123, 7, EMAIL] && str_contains($u['sql'], 'id_empresa = ?') && str_contains($u['sql'], 'LOWER(email) = ?'), 'só atualiza cliente+empresa+e-mail cadastrado (nunca grava e-mail novo)');
$db = new BancoFalso();
falsoVerificar(emailOtpMarcarVerificado($db, 0, 123, EMAIL) === false && emailOtpMarcarVerificado($db, 7, 0, EMAIL) === false && emailOtpMarcarVerificado($db, 7, 1, 'ruim') === false && $db->log === [], 'parâmetros inválidos não chegam ao banco');

falsoRelatorio();
