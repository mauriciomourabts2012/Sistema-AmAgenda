<?php
declare(strict_types=1);

/*
 * Testes do login do cliente com o canal e-mail (C3), com banco falso e transportes falsos.
 * Nenhum SMS, nenhum e-mail e nenhuma escrita real. Cobre as regras de backend/_regras/
 * cliente_login_email.php integradas aos serviços de OTP da C2.
 * Uso: php tools/tests/cliente-login-email.test.php
 */

$total = 0;
$falhas = [];
date_default_timezone_set('UTC');
require_once __DIR__ . '/sms-banco-falso.php';
require_once __DIR__ . '/../../backend/_regras/cliente_login_email.php';

$configInfobip = [
    'habilitado' => true, 'ambiente' => 'teste', 'base_url' => 'https://abc123.api.infobip.com',
    'api_key' => 'chave-api-ficticia-0123456789abcdef', 'sender' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
    'hmac_key' => str_repeat('h', 40), 'webhook_secret' => str_repeat('w', 40),
    'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
];
$carga = ['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $configInfobip];
smsOtpCarga($carga);
$gmailValida = gmailConfigValidar([
    'habilitado' => true, 'ambiente' => 'teste', 'host' => 'smtp.gmail.com', 'porta' => 465,
    'usuario' => 'suporte@exemplo.test', 'senha_app' => 'abcd efgh ijkl mnop',
    'remetente_email' => 'suporte@exemplo.test', 'remetente_nome' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
]);
emailOtpCarga(['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $gmailValida['config']]);
$_SESSION = [];

const TEL = '+5538998969407';
const TEL_OUTRO = '+5538991112222';
const EMAIL = 'cliente@exemplo.test';
const EMAIL_OUTRO = 'outro@exemplo.test';

/** Transportes falsos: contam chamadas, nunca saem da memória. */
$chamadasSms = 0;
$chamadasEmail = 0;
$transporteSms = static function (array $req) use (&$chamadasSms): array {
    $chamadasSms++;
    $corpo = json_decode($req['body'], true);

    return ['http_status' => 200, 'errno' => 0, 'body' => json_encode(['bulkId' => 'b', 'messages' => [[
        'messageId' => $corpo['messages'][0]['destinations'][0]['messageId'], 'status' => ['groupName' => 'PENDING', 'id' => 26, 'name' => 'X'],
    ]]])];
};
$transporteEmail = static function (array $req) use (&$chamadasEmail): array {
    $chamadasEmail++;

    return ['fase' => 'final', 'codigo' => 250, 'errno' => 0];
};
/** Um único callable serve aos dois canais: o canal do pedido escolhe o gateway. */
$transporte = static fn (array $req): array => isset($req['body']) ? $GLOBALS['transporteSms']($req) : $GLOBALS['transporteEmail']($req);

/**
 * Banco falso do cliente: $emailVerificado = o e-mail informado é o cadastrado E validado do cliente;
 * $emailEmUso = o e-mail pertence a algum cliente da empresa.
 */
function bancoCliente(bool $emailVerificado, bool $emailEmUso = false, array $extra = []): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT 1 FROM cliente WHERE id_cliente = \? AND id_empresa = \? AND status/', [['1' => 1]]);
    $db->regra('/LOWER\(email\) = \? AND email_verificado_em IS NOT NULL/', $emailVerificado ? [['1' => 1]] : []);
    $db->regra('/^SELECT 1 FROM cliente WHERE id_empresa = \? AND LOWER\(email\) = \?/', $emailEmUso ? [['1' => 1]] : []);
    $db->regra('/^SELECT COUNT\(\*\) AS n/', [['n' => 0]]);
    $db->regra('/^SELECT LAST_INSERT_ID/', [['id' => 42]]);
    $db->regra('/^SELECT status FROM otp_desafio/', [['status' => 'pendente_envio']]);
    foreach ($extra as [$regex, $resposta]) {
        $db->regra($regex, $resposta);
    }

    return $db;
}

$ctx = static fn (array $t = []): array => array_replace([
    'canal' => 'sms', 'email' => null, 'id_empresa' => 42, 'telefone' => TEL, 'cliente_ativo' => true,
    'id_cliente' => 123, 'finalidade' => 'acesso', 'ficticio_sem_envio' => false, 'ip' => '203.0.113.9',
], $t);

// ---- 1. Parâmetros: SMS idêntico ao anterior à C3 ---------------------------------------------
$pSms = clienteLoginParametrosSolicitacao($ctx());
falsoVerificar($pSms === [
    'principal_tipo' => 'cliente', 'finalidade' => 'acesso_cliente', 'id_empresa' => 42, 'id_cliente' => 123, 'id_usuario' => 0,
    'telefone' => TEL, 'ip' => '203.0.113.9', 'enviar' => true,
], 'cliente existente por SMS: parâmetros iguais aos anteriores (sem canal/e-mail)');
$pSmsNovo = clienteLoginParametrosSolicitacao($ctx(['cliente_ativo' => false, 'id_cliente' => 0]));
falsoVerificar($pSmsNovo['principal_tipo'] === 'ficticio' && $pSmsNovo['enviar'] === true && !isset($pSmsNovo['canal']), 'cliente novo por SMS: principal fictício com envio real, como antes');
$pRecSms = clienteLoginParametrosSolicitacao($ctx(['finalidade' => 'recuperacao_senha', 'ficticio_sem_envio' => true, 'cliente_ativo' => false, 'id_cliente' => 0]));
falsoVerificar($pRecSms['finalidade'] === 'recuperacao_cliente' && $pRecSms['enviar'] === false, 'recuperação fictícia por SMS não envia, como antes');

// ---- 2. Cliente novo por SMS: nenhum e-mail envolvido -----------------------------------------
$chamadasSms = $chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(false), $pSmsNovo, $transporte);
falsoVerificar($r['sucesso'] === true && $chamadasSms === 1 && $chamadasEmail === 0 && $r['dados']['canal'] === 'sms', 'cliente novo por SMS envia somente SMS');

// ---- 3. Cliente existente por SMS ---------------------------------------------------------------
$chamadasSms = $chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(false), $pSms, $transporte);
falsoVerificar($r['sucesso'] === true && $r['codigo'] === 'SMS_OTP_ENVIADO' && $chamadasSms === 1 && $chamadasEmail === 0, 'cliente existente por SMS continua igual (mesmo sem e-mail validado)');

// ---- 4. Cliente existente por e-mail verificado -------------------------------------------------
$pEmail = clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL]));
falsoVerificar($pEmail['canal'] === 'email' && $pEmail['email'] === EMAIL && $pEmail['principal_tipo'] === 'cliente' && $pEmail['enviar'] === true, 'existente por e-mail: principal cliente com envio');
$chamadasSms = $chamadasEmail = 0;
$db = bancoCliente(true);
$r = smsOtpSolicitar($db, $pEmail, $transporte);
falsoVerificar($r['sucesso'] === true && $r['codigo'] === 'SMS_OTP_ENVIADO' && $chamadasEmail === 1 && $chamadasSms === 0 && $r['dados']['decoy'] === false, 'e-mail verificado do próprio cliente recebe o código (e nenhum SMS)');
$ins = $db->chamadas('/^INSERT INTO otp_desafio/')[0];
falsoVerificar($ins['valores'][13] === 'email' && $ins['valores'][2] === 123 && $ins['valores'][0] === 42, 'desafio gravado: canal e-mail, cliente 123, empresa 42');
$consultaPermissao = array_values(array_filter($db->chamadas('/LOWER\(email\) = \?/'), static fn ($c) => str_contains($c['sql'], 'email_verificado_em IS NOT NULL')));
falsoVerificar($consultaPermissao !== [] && str_contains($consultaPermissao[0]['sql'], 'id_empresa = ?') && $consultaPermissao[0]['valores'] === [123, 42, EMAIL], 'elegibilidade consultada por cliente + empresa + e-mail');

// Validação e autenticação.
$_SESSION = [];
$sessaoHex = smsOtpHmac($carga, 'sessao', smsOtpVinculoSessao());
$codigoOk = '482913';
$hashCodigo = smsOtpCodigoHash($carga, $codigoOk);
$hashEmail = smsOtpHmac($carga, 'email', EMAIL);
$linha = static fn (array $t = []): array => array_replace([
    'status' => 'ativo', 'codigo_hash' => $hashCodigo, 'tentativas_validacao' => 0, 'max_tentativas' => 5, 'otp_empresa' => 42,
    'sessao_hex' => $sessaoHex, 'expirado' => 0, 'cliente_empresa' => 42, 'cliente_status' => 'ativo', 'canal' => 'email', 'destino_hex' => $hashEmail,
], $t);
$validar = static function (array $l, array $troca = []) use ($pEmail, $codigoOk): array {
    $db = new BancoFalso();
    $db->regra('/^SELECT o\.status/', [$l]);

    return [smsOtpValidar($db, $troca + $pEmail + ['id_otp_desafio' => 42, 'codigo' => $codigoOk]), $db];
};
[$v] = $validar($linha());
falsoVerificar($v['sucesso'] === true && $v['dados']['canal'] === 'email', 'código por e-mail correto valida');
$clienteAtual = ['id_cliente' => 123, 'id_empresa' => 42, 'senha_hash' => null, 'status' => 'ativo'];
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(true), 42, $clienteAtual, 123, EMAIL) === true, 'autentica cliente existente com e-mail ainda verificado');

// ---- 5. Existente sem e-mail verificado ---------------------------------------------------------
$chamadasSms = $chamadasEmail = 0;
$db = bancoCliente(false);
$r = smsOtpSolicitar($db, $pEmail, $transporte);
falsoVerificar($r['sucesso'] === true && $r['codigo'] === 'SMS_OTP_CRIADO' && $r['dados']['decoy'] === true && $chamadasEmail === 0 && $chamadasSms === 0, 'sem e-mail verificado: nada é enviado e a resposta do serviço é de sucesso (sem revelar)');
// O handler só usa sucesso, expires_in e id_otp_desafio do serviço e responde de forma genérica no canal e-mail.
$rElegivel = smsOtpSolicitar(bancoCliente(true), $pEmail, $transporte);
falsoVerificar(
    $r['sucesso'] === $rElegivel['sucesso'] && $r['dados']['expires_in'] === $rElegivel['dados']['expires_in']
    && isset($r['dados']['id_otp_desafio'], $rElegivel['dados']['id_otp_desafio']),
    'com e sem e-mail verificado o serviço entrega ao handler os mesmos campos utilizados'
);
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(false), 42, $clienteAtual, 123, EMAIL) === false, 'e-mail deixou de estar verificado: não autentica');
falsoVerificar(clienteLoginEmailVerificadoDoCliente(bancoCliente(false), 42, 123, EMAIL) === false, 'consulta de verificação nega quando a linha não existe');

// E-mail do desafio diferente do cadastrado (e-mail de outra pessoa): decoy.
$chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(false), clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL_OUTRO])), $transporte);
falsoVerificar($r['dados']['decoy'] === true && $chamadasEmail === 0, 'e-mail de outra pessoa não recebe o código do cliente');

// ---- 6. Cliente novo por e-mail (C3.1: a conta pode nascer só com e-mail) -----------------------
falsoVerificar(CLIENTE_LOGIN_EMAIL_PRIMEIRO_ACESSO === true, 'primeiro acesso por e-mail está liberado');
$pNovoEmail = clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL, 'cliente_ativo' => false, 'id_cliente' => 0]));
falsoVerificar($pNovoEmail['principal_tipo'] === 'ficticio' && $pNovoEmail['enviar'] === true, 'sem cadastro por e-mail: principal fictício com envio real');
$chamadasEmail = $chamadasSms = 0;
$db = bancoCliente(false);
$r = smsOtpSolicitar($db, $pNovoEmail, $transporte);
falsoVerificar($r['sucesso'] === true && $r['dados']['decoy'] === false && $chamadasEmail === 1 && $chamadasSms === 0, 'cliente novo por e-mail recebe só o e-mail');
// Sem telefone algum (conta só com e-mail): nenhum telefone fictício, hash de telefone nulo.
$pSoEmail = clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL, 'telefone' => '', 'cliente_ativo' => false, 'id_cliente' => 0]));
$chamadasEmail = 0;
$db = bancoCliente(false);
$r = smsOtpSolicitar($db, $pSoEmail, $transporte);
$ins = $db->chamadas('/^INSERT INTO otp_desafio/')[0];
falsoVerificar($r['sucesso'] === true && $chamadasEmail === 1 && $ins['valores'][6] === null && $ins['valores'][14] !== null, 'só com e-mail: telefone_hash nulo e destino_hash gravado (sem telefone fictício)');
falsoVerificar($db->chamadas('/telefone_hash = UNHEX/') === [] || array_filter($db->chamadas('/telefone_hash = UNHEX/'), static fn ($c) => $c['valores'] === [null]) === [], 'limite por telefone não é consultado sem telefone');
falsoVerificar(
    smsOtpChavePrincipal($carga, $pSoEmail, '', EMAIL) !== smsOtpChavePrincipal($carga, $pSoEmail, '', EMAIL_OUTRO)
    && smsOtpChavePrincipal($carga, $pSoEmail, '', EMAIL) === smsOtpChavePrincipal($carga, $pSoEmail, '', EMAIL),
    'sem telefone, o slot do primeiro acesso é por e-mail (estável e distinto por endereço)'
);
// Autenticação do primeiro acesso: só com e-mail ainda livre e desafio sem cliente.
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(true, false), 42, null, null, EMAIL) === true, 'primeiro acesso por e-mail livre autentica (sem cliente)');
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(true, true), 42, null, null, EMAIL) === false, 'e-mail que passou a pertencer a um cliente não autentica o primeiro acesso');
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(true, false), 42, null, 123, EMAIL) === false, 'desafio de cliente existente não autentica como primeiro acesso');
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(true), 42, $clienteAtual, null, EMAIL) === false, 'desafio sem cliente principal não autentica cliente que surgiu depois');
// Telefone nunca é inventado nem reutilizado: celular e e-mail não se misturam.
$pSmsSemTelefone = ['canal' => 'sms', 'telefone' => ''] + clienteLoginParametrosSolicitacao($ctx());
falsoVerificar(smsOtpSolicitar(bancoCliente(false), $pSmsSemTelefone, $transporte)['codigo'] === 'TELEFONE_INVALIDO', 'canal SMS continua exigindo telefone válido');

// ---- 7. Prevenção de associação indevida e duplicidade ------------------------------------------
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(true), 42, $clienteAtual, 999, EMAIL) === false, 'desafio de outro cliente não autentica este');
falsoVerificar(clienteLoginEmailPodeAutenticar(bancoCliente(true), 42, ['id_empresa' => 43] + $clienteAtual, 123, EMAIL) === false, 'cliente de outra empresa não autentica (isolamento)');
$db = bancoCliente(true);
clienteLoginEmailPodeAutenticar($db, 42, $clienteAtual, 123, EMAIL);
$q = $db->chamadas('/LOWER\(email\) = \?/')[0];
falsoVerificar($q['valores'] === [123, 42, EMAIL] && str_contains($q['sql'], "status = 'ativo'") && str_contains($q['sql'], 'id_empresa = ?'), 'verificação final por cliente + empresa + e-mail + ativo');
// E-mail verificado de A usado com o telefone de B: a consulta é por id_cliente do telefone informado.
$db = bancoCliente(false);
$r = smsOtpSolicitar($db, clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL, 'id_cliente' => 124, 'telefone' => TEL_OUTRO])), $transporte);
falsoVerificar($r['dados']['decoy'] === true && $db->chamadas('/LOWER\(email\) = \?/') !== [], 'e-mail verificado de outro cliente não vale para o telefone informado');
// Primeiro acesso: e-mail já cadastrado na empresa nunca gera nova conta/associação (mesmo liberado).
$pLiberado = clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL, 'cliente_ativo' => false, 'id_cliente' => 0]));
$pLiberado['enviar'] = true; // simula a futura liberação do primeiro acesso por e-mail
$chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(false, true), $pLiberado, $transporte);
falsoVerificar($r['dados']['decoy'] === true && $chamadasEmail === 0, 'mesmo liberado, e-mail já cadastrado na empresa não recebe código de primeiro acesso');
$chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(false, false), $pLiberado, $transporte);
falsoVerificar($r['dados']['decoy'] === false && $chamadasEmail === 1, 'mesmo liberado, e-mail livre na empresa seria elegível (decisão fica na constante)');

// ---- 8. Recuperação de acesso -------------------------------------------------------------------
$pRecEmail = clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL, 'finalidade' => 'recuperacao_senha']));
$chamadasEmail = 0;
$db = bancoCliente(true);
$r = smsOtpSolicitar($db, $pRecEmail, $transporte);
falsoVerificar($pRecEmail['finalidade'] === 'recuperacao_cliente' && $r['codigo'] === 'SMS_OTP_ENVIADO' && $chamadasEmail === 1, 'recuperação por e-mail verificado envia o código');
falsoVerificar($db->chamadas('/^INSERT INTO otp_desafio/')[0]['valores'][4] === 'recuperacao_cliente', 'desafio de recuperação gravado com a finalidade correta');
$pRecFic = clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => EMAIL, 'finalidade' => 'recuperacao_senha', 'ficticio_sem_envio' => true, 'cliente_ativo' => false, 'id_cliente' => 0]));
$chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(true), $pRecFic, $transporte);
falsoVerificar($r['sucesso'] === true && $chamadasEmail === 0 && $r['dados']['decoy'] === true, 'recuperação sem senha/conta: desafio fictício sem e-mail');
$chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(false), $pRecEmail, $transporte);
falsoVerificar($r['dados']['decoy'] === true && $chamadasEmail === 0, 'recuperação com e-mail não verificado: sem envio');

// ---- 9. SMS não recebido e escolha de e-mail / troca de canal -----------------------------------
falsoVerificar(
    smsOtpChavePrincipal($carga, $pSms, TEL) === smsOtpChavePrincipal($carga, $pEmail, TEL),
    'SMS e e-mail do mesmo cliente/finalidade compartilham o slot ativo'
);
$db = bancoCliente(true, false, [['/^SELECT id_otp_desafio FROM otp_desafio/', [['id_otp_desafio' => 41]]]]);
$r = smsOtpSolicitar($db, $pEmail, $transporte);
$invalidou = $db->chamadas("/^UPDATE otp_desafio SET status = 'invalidado'/");
falsoVerificar($r['sucesso'] === true && count($invalidou) === 1 && $invalidou[0]['valores'][0] === smsOtpChavePrincipal($carga, $pEmail, TEL), 'escolher e-mail invalida o SMS ativo (um só código utilizável)');
$db = bancoCliente(true, false, [['/^SELECT TIMESTAMPDIFF/', [['idade' => 12]]]]);
$r = smsOtpSolicitar($db, $pEmail, $transporte);
falsoVerificar($r['codigo'] === 'SMS_OTP_REENVIO_AGUARDAR' && $r['dados']['retry_after'] === 18 && $db->chamadas('/^INSERT INTO otp_desafio/') === [], 'trocar de canal antes de 30 s é recusado com o prazo real e não cria desafio');
$chamadasSms = $chamadasEmail = 0;
$r = smsOtpSolicitar(bancoCliente(true), $pSms, $transporte);
falsoVerificar($r['sucesso'] === true && $chamadasSms === 1 && $chamadasEmail === 0, 'voltar do e-mail para o SMS usa a Infobip normalmente');
$db = bancoCliente(true);
$db->regra('/^SELECT COUNT\(\*\) AS n FROM otp_desafio WHERE telefone_hash/', [['n' => 5]]);
$r = smsOtpSolicitar($db, $pEmail, $transporte);
falsoVerificar($r['codigo'] === 'SMS_OTP_LIMITE' && $r['dados']['motivo'] === 'telefone', 'limite por hora do telefone vale para os dois canais');
$db = bancoCliente(true);
$db->regra('/^SELECT COUNT\(\*\) AS n FROM otp_desafio WHERE destino_hash/', [['n' => 5]]);
$r = smsOtpSolicitar($db, $pEmail, $transporte);
falsoVerificar($r['codigo'] === 'SMS_OTP_LIMITE' && $r['dados']['motivo'] === 'email', 'limite por hora do e-mail');

// ---- 10. Código incorreto, expirado, e-mail trocado, consumo único ------------------------------
[$v, $dbv] = $validar($linha(), ['codigo' => '000000']);
falsoVerificar($v['sucesso'] === false && $v['codigo'] === 'SMS_OTP_CODIGO_INVALIDO' && count($dbv->chamadas('/tentativas_validacao = tentativas_validacao \+ 1/')) === 1, 'código incorreto por e-mail: recusa e conta a tentativa');
[$v] = $validar($linha(['expirado' => 1]));
falsoVerificar($v['codigo'] === 'SMS_OTP_EXPIRADO', 'código por e-mail expirado');
[$v, $dbv] = $validar($linha(['tentativas_validacao' => 4]), ['codigo' => '000000']);
falsoVerificar($v['codigo'] === 'SMS_OTP_TENTATIVAS' && count($dbv->chamadas("/status = 'bloqueado'/")) === 1, '5ª tentativa errada bloqueia o desafio por e-mail');
[$v, $dbv] = $validar($linha(), ['email' => EMAIL_OUTRO]);
falsoVerificar($v['sucesso'] === false && $dbv->chamadas('/^UPDATE/') === [], 'e-mail diferente do pedido não valida nem gasta tentativa');
[$v] = $validar($linha(['status' => 'consumido']));
falsoVerificar($v['sucesso'] === false, 'consumo único: código já usado não valida');
[$v] = $validar($linha(['sessao_hex' => str_repeat('0', 64)]));
falsoVerificar($v['sucesso'] === false, 'desafio de outra sessão não valida');
[$v] = $validar($linha(['cliente_empresa' => 43]));
falsoVerificar($v['sucesso'] === false, 'empresa do cliente diferente da do desafio: invalida');
// Desafio SMS da mesma sessão não exige e-mail (fluxo atual preservado).
$dbs = new BancoFalso();
$dbs->regra('/^SELECT o\.status/', [$linha(['canal' => 'sms', 'destino_hex' => null])]);
falsoVerificar(smsOtpValidar($dbs, array_diff_key($pSms, ['x' => 1]) + ['id_otp_desafio' => 42, 'codigo' => $codigoOk])['sucesso'] === true, 'código por SMS continua validando sem e-mail');

// ---- 11. E-mail inválido e canal desligado ------------------------------------------------------
$r = smsOtpSolicitar(bancoCliente(true), clienteLoginParametrosSolicitacao($ctx(['canal' => 'email', 'email' => 'invalido'])), $transporte);
falsoVerificar($r['codigo'] === 'EMAIL_INVALIDO', 'e-mail inválido recusado pelo serviço');
falsoVerificar(clienteLoginEmailNormalizar(' Cliente@Exemplo.TEST ') === EMAIL && clienteLoginEmailNormalizar("a@b.cd\r\nBcc: x@y.zz") === null, 'normalização e proteção contra injeção de cabeçalho');
emailOtpCarga(['ok' => true, 'habilitado' => false, 'erros' => [], 'config' => $gmailValida['config']]);
$db = bancoCliente(true);
$r = smsOtpSolicitar($db, $pEmail, $transporte);
falsoVerificar($r['codigo'] === 'EMAIL_INDISPONIVEL' && $db->chamadas('/^INSERT/') === [], 'Gmail desligado: não cria desafio nem envia');

// ---- 12. Invalidação da verificação ao alterar o e-mail (perfil) -------------------------------
$fonte = (string)file_get_contents(__DIR__ . '/../../backend/cliente/salvar_dados_cadastrais.php');
$posVerif = strpos($fonte, 'email_verificado_em = IF(LOWER(email) <=> ?, email_verificado_em, NULL)');
$posEmail = strpos($fonte, 'nome_completo = ?, email = ?');
falsoVerificar($posVerif !== false && $posEmail !== false && $posVerif < $posEmail, 'perfil: email_verificado_em é zerado ao mudar o e-mail, avaliado antes da atribuição do novo e-mail');
falsoVerificar(str_contains($fonte, "'ssssssssssssii'"), 'perfil: tipos do bind_param conferem com os 14 parâmetros');
falsoVerificar(substr_count((string)preg_replace('/\s+/', ' ', substr($fonte, (int)strpos($fonte, 'UPDATE cliente'), 700)), '?') === 14, 'perfil: UPDATE tem 14 marcadores');

falsoRelatorio();
