<?php
declare(strict_types=1);

/*
 * Testes do OTP persistente (otp_desafio) com banco falso e transporte falso.
 * Nenhum SMS real, nenhuma escrita em banco. Uso: php tools/tests/sms-otp-persistencia.test.php
 */

$total = 0;
$falhas = [];
date_default_timezone_set('UTC');
require_once __DIR__ . '/sms-banco-falso.php';
require_once __DIR__ . '/../../backend/_servicos/sms_otp.php';

$config = [
    'habilitado' => true, 'ambiente' => 'teste', 'base_url' => 'https://abc123.api.infobip.com',
    'api_key' => 'chave-api-ficticia-0123456789abcdef', 'sender' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
    'hmac_key' => str_repeat('h', 40), 'webhook_secret' => str_repeat('w', 40),
    'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
];
$carga = ['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $config];
smsOtpCarga($carga);
$_SESSION = [];

const TEL = '+5538998969407';

/** Transporte que aceita e guarda o código enviado, para a validação posterior. */
function transporteCapturando(?string &$codigoEnviado, int &$chamadas): callable
{
    return static function (array $req) use (&$codigoEnviado, &$chamadas): array {
        $chamadas++;
        $corpo = json_decode($req['body'], true);
        $texto = $corpo['messages'][0]['content']['text'];
        preg_match('/\b(\d{6})\b/', $texto, $m);
        $codigoEnviado = $m[1] ?? null;
        $id = $corpo['messages'][0]['destinations'][0]['messageId'];

        return ['http_status' => 200, 'errno' => 0, 'body' => json_encode(['bulkId' => 'b', 'messages' => [[
            'messageId' => $id, 'status' => ['groupName' => 'PENDING', 'id' => 26, 'name' => 'PENDING_ACCEPTED'],
        ]]])];
    };
}

function bancoSolicitar(array $extra = []): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT 1 FROM cliente/', [['1' => 1]]);
    $db->regra('/^SELECT 1 FROM usuario/', [['1' => 1]]);
    $db->regra('/^SELECT COUNT\(\*\) AS n/', [['n' => 0]]);
    $db->regra('/^SELECT LAST_INSERT_ID/', [['id' => 42]]);
    $db->regra('/^SELECT status FROM otp_desafio/', [['status' => 'pendente_envio']]);
    foreach ($extra as [$regex, $resposta]) {
        $db->regra($regex, $resposta);
    }

    return $db;
}

$pCliente = [
    'principal_tipo' => 'cliente', 'finalidade' => 'acesso_cliente', 'id_empresa' => 7, 'id_cliente' => 123, 'id_usuario' => 0,
    'telefone' => TEL, 'ip' => '203.0.113.9', 'enviar' => true,
];

// Telefone.
falsoVerificar(smsOtpNormalizarTelefone('(38) 99896-9407') === TEL, 'normaliza DDD + número');
falsoVerificar(smsOtpNormalizarTelefone('+5538998969407') === TEL, 'mantém E.164');
falsoVerificar(smsOtpNormalizarTelefone('123') === null && smsOtpNormalizarTelefone('') === null, 'telefone inválido recusado');
falsoVerificar(smsOtpNormalizarTelefone('+447911123456') === null, 'fora do Brasil recusado');

// Combinações aceitas pelas constraints do banco.
$ok = static fn (array $p): bool => smsOtpCombinacaoValida($p);
falsoVerificar($ok($pCliente), 'cliente + acesso_cliente válido');
falsoVerificar($ok(['principal_tipo' => 'cliente', 'finalidade' => 'recuperacao_cliente', 'id_empresa' => 7, 'id_cliente' => 1]), 'cliente + recuperacao_cliente válido');
falsoVerificar(!$ok(['principal_tipo' => 'cliente', 'finalidade' => 'recuperacao_usuario', 'id_empresa' => 7, 'id_cliente' => 1]), 'cliente + recuperacao_usuario inválido');
falsoVerificar(!$ok(['principal_tipo' => 'cliente', 'finalidade' => 'acesso_cliente', 'id_empresa' => 0, 'id_cliente' => 1]), 'cliente sem empresa inválido');
falsoVerificar(!$ok(['principal_tipo' => 'cliente', 'finalidade' => 'acesso_cliente', 'id_empresa' => 7, 'id_cliente' => 0]), 'cliente sem id_cliente inválido');
falsoVerificar(!$ok(['principal_tipo' => 'cliente', 'finalidade' => 'acesso_cliente', 'id_empresa' => 7, 'id_cliente' => 1, 'id_usuario' => 9]), 'cliente com usuario inválido');
falsoVerificar($ok(['principal_tipo' => 'usuario', 'finalidade' => 'recuperacao_usuario', 'id_empresa' => 0, 'id_usuario' => 5]), 'usuario + recuperacao_usuario válido');
falsoVerificar(!$ok(['principal_tipo' => 'usuario', 'finalidade' => 'recuperacao_usuario', 'id_empresa' => 3, 'id_usuario' => 5]), 'usuario com empresa inválido');
falsoVerificar(!$ok(['principal_tipo' => 'usuario', 'finalidade' => 'acesso_cliente', 'id_empresa' => 0, 'id_usuario' => 5]), 'usuario + acesso_cliente inválido');
falsoVerificar($ok(['principal_tipo' => 'ficticio', 'finalidade' => 'acesso_cliente', 'id_empresa' => 7]), 'ficticio válido');
falsoVerificar(!$ok(['principal_tipo' => 'ficticio', 'finalidade' => 'acesso_cliente', 'id_cliente' => 4]), 'ficticio com cliente inválido');
falsoVerificar(!$ok(['principal_tipo' => 'xyz', 'finalidade' => 'acesso_cliente']), 'tipo desconhecido inválido');

// Chave do principal: isolamento entre empresas, finalidades e principais.
$chave = static fn (array $p, string $tel = TEL): string => smsOtpChavePrincipal($carga, $p, $tel);
$base = $chave($pCliente);
falsoVerificar(strlen($base) === 64 && $base === $chave($pCliente), 'chave determinística de 32 bytes (hex)');
falsoVerificar($base !== $chave(['id_empresa' => 8] + $pCliente), 'empresa diferente => chave diferente');
falsoVerificar($base !== $chave(['id_cliente' => 124] + $pCliente), 'cliente diferente => chave diferente');
falsoVerificar($base !== $chave(['finalidade' => 'recuperacao_cliente'] + $pCliente), 'finalidade diferente => chave diferente');
falsoVerificar($chave(['principal_tipo' => 'ficticio', 'id_cliente' => 0] + $pCliente) !== $base, 'ficticio ≠ cliente');
falsoVerificar($chave(['principal_tipo' => 'ficticio', 'id_cliente' => 0] + $pCliente) !== $chave(['principal_tipo' => 'ficticio', 'id_cliente' => 0] + $pCliente, '+5538911112222'), 'ficticio por telefone');
falsoVerificar(!str_contains($base, '5538998969407'), 'chave não contém o telefone');

// Hash do código.
$h = smsOtpCodigoHash($carga, '482913');
falsoVerificar(strlen($h) <= 255 && str_starts_with($h, 'h1$'), 'hash cabe em VARCHAR(255)');
falsoVerificar(!str_contains($h, '482913'), 'hash não contém o código');
falsoVerificar(smsOtpCodigoConfere($carga, '482913', $h) && !smsOtpCodigoConfere($carga, '482914', $h), 'hash confere só o código correto');
falsoVerificar($h !== smsOtpCodigoHash($carga, '482913'), 'sal aleatório por desafio');
$outraChave = $carga;
$outraChave['config']['hmac_key'] = str_repeat('q', 40);
falsoVerificar(!smsOtpCodigoConfere($outraChave, '482913', $h), 'hash depende da chave HMAC (pepper)');
falsoVerificar(!smsOtpCodigoConfere($carga, '482913', 'texto') && !smsOtpCodigoConfere($carga, '482913', '$2y$10$abc'), 'formato inválido não confere');
$faixa = true;
for ($i = 0; $i < 300; $i++) {
    $c = smsOtpGerarCodigo();
    $faixa = $faixa && preg_match('/^[1-9][0-9]{5}$/', $c) === 1;
}
falsoVerificar($faixa, 'código aleatório de 6 dígitos');
falsoVerificar(smsOtpGerarCodigo() !== smsOtpGerarCodigo() || smsOtpGerarCodigo() !== smsOtpGerarCodigo(), 'códigos variam');

// Vínculo da sessão.
$_SESSION = [];
$v1 = smsOtpVinculoSessao();
falsoVerificar(preg_match('/^[0-9a-f]{64}$/', $v1) === 1 && $v1 === smsOtpVinculoSessao(), 'vínculo de sessão estável');
$_SESSION = [];
falsoVerificar(smsOtpVinculoSessao() !== $v1, 'outra sessão => outro vínculo');
$_SESSION = [];

// Solicitação: fluxo normal.
$db = bancoSolicitar();
$n = 0;
$codigo = null;
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['sucesso'] === true && $r['dados']['id_otp_desafio'] === 42 && $r['dados']['expires_in'] === 300, 'solicitação aceita com validade de 5 minutos');
falsoVerificar($n === 1 && is_string($codigo) && strlen($codigo) === 6, 'um SMS enviado com código de 6 dígitos');
$ins = $db->chamadas('/^INSERT INTO otp_desafio/');
falsoVerificar(count($ins) === 1, 'um desafio criado');
$v = $ins[0]['valores'];
falsoVerificar($v[0] === 7 && $v[2] === 123 && $v[1] === null, 'empresa e cliente reais gravados; usuário nulo');
falsoVerificar($v[3] === 'cliente' && $v[4] === 'acesso_cliente', 'tipo e finalidade gravados');
falsoVerificar($v[10] === 'pendente_envio' && $v[11] === SMS_OTP_MAX_TENTATIVAS && $v[12] === 300, 'status inicial, tentativas e 300 segundos');
falsoVerificar(str_contains($ins[0]['sql'], 'NOW(6) + INTERVAL ? SECOND'), 'expiração calculada pelo relógio do banco');
$tudo = $db->todosOsValores() . implode('|', $db->eventos);
falsoVerificar(!str_contains($tudo, (string)$codigo), 'código OTP nunca chega ao banco');
falsoVerificar(!str_contains($tudo, '5538998969407') && !str_contains($tudo, '203.0.113.9'), 'telefone e IP só como hash');
falsoVerificar(preg_match('/^[0-9a-f]{64}$/', (string)$v[6]) === 1 && preg_match('/^[0-9a-f]{64}$/', (string)$v[8]) === 1, 'hashes de telefone e sessão em hex');
falsoVerificar(smsOtpCodigoConfere($carga, (string)$codigo, (string)$v[9]), 'hash gravado confere com o código enviado');

// Ordem: invalida o anterior ANTES de inserir o novo.
$posUpd = array_search(true, array_map(static fn ($e) => str_starts_with($e, "UPDATE otp_desafio SET status = 'invalidado'"), $db->eventos), true);
$posIns = array_search(true, array_map(static fn ($e) => str_starts_with($e, 'INSERT INTO otp_desafio'), $db->eventos), true);
falsoVerificar($posUpd !== false && $posIns !== false && $posUpd < $posIns, 'desafio anterior invalidado antes da criação do novo');
falsoVerificar(count($db->chamadas('/FOR UPDATE$/')) >= 1, 'último desafio do principal bloqueado (FOR UPDATE)');

// Reenvio < 30 s.
$db = bancoSolicitar([['/^SELECT TIMESTAMPDIFF/', [['idade' => 10]]]]);
$n = 0;
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['sucesso'] === false && $r['codigo'] === 'SMS_OTP_REENVIO_AGUARDAR' && $r['dados']['retry_after'] === 20, 'reenvio antes de 30 s é recusado com retry_after');
falsoVerificar($n === 0 && $db->chamadas('/^INSERT INTO otp_desafio/') === [], 'recusa de reenvio não cria desafio nem SMS');
$db = bancoSolicitar([['/^SELECT TIMESTAMPDIFF/', [['idade' => 31]]]]);
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['sucesso'] === true, 'reenvio após 30 s permitido');

// Limites por telefone/IP.
$db = bancoSolicitar([['/telefone_hash = UNHEX/', [['n' => SMS_OTP_LIMITE_TELEFONE_HORA]]]]);
$n = 0;
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_LIMITE' && $n === 0, 'limite por telefone na hora');
$db = bancoSolicitar([['/ip_hash = UNHEX/', [['n' => SMS_OTP_LIMITE_IP_HORA]]]]);
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_LIMITE' && $n === 0, 'limite por IP na hora');

// Empresa real do cliente: não confia só nas chaves estrangeiras.
$db = bancoSolicitar([['/^SELECT 1 FROM cliente/', []]]);
$n = 0;
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['sucesso'] === false && $r['codigo'] === 'SMS_OTP_PRINCIPAL_INVALIDO' && $n === 0, 'cliente de outra empresa/inativo não gera desafio');
falsoVerificar($db->chamadas('/^INSERT/') === [], 'nenhum desafio criado para principal inconsistente');
$consulta = $db->chamadas('/^SELECT 1 FROM cliente/')[0] ?? null;
falsoVerificar($consulta !== null && str_contains($consulta['sql'], 'id_cliente = ? AND id_empresa = ?') && $consulta['valores'] === [123, 7], 'consulta valida cliente e empresa juntos');

// Usuário interno.
$pUsuario = ['principal_tipo' => 'usuario', 'finalidade' => 'recuperacao_usuario', 'id_empresa' => 0, 'id_cliente' => 0, 'id_usuario' => 5, 'telefone' => TEL, 'ip' => '203.0.113.9', 'enviar' => true];
$db = bancoSolicitar();
$n = 0;
$r = smsOtpSolicitar($db, $pUsuario, transporteCapturando($codigo, $n));
$ins = $db->chamadas('/^INSERT INTO otp_desafio/');
falsoVerificar($r['sucesso'] === true && $ins[0]['valores'][0] === null && $ins[0]['valores'][1] === 5, 'usuário interno: empresa nula e id_usuario gravado');
$db = bancoSolicitar([['/^SELECT 1 FROM usuario/', []]]);
$r = smsOtpSolicitar($db, $pUsuario, transporteCapturando($codigo, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_PRINCIPAL_INVALIDO', 'usuário inativo/inexistente não gera desafio');

// Desafio fictício de recuperação: sem SMS, resposta igual.
$pFicticio = ['principal_tipo' => 'ficticio', 'finalidade' => 'recuperacao_usuario', 'id_empresa' => 0, 'id_cliente' => 0, 'id_usuario' => 0, 'telefone' => '+5500123456789', 'ip' => '203.0.113.9', 'enviar' => false];
$db = bancoSolicitar();
$n = 0;
$r = smsOtpSolicitar($db, $pFicticio, transporteCapturando($codigo, $n));
falsoVerificar($r['sucesso'] === true && $r['dados']['decoy'] === true && $n === 0, 'recuperação fictícia: nenhum SMS');
$ins = $db->chamadas('/^INSERT INTO otp_desafio/');
falsoVerificar($ins[0]['valores'][10] === 'ativo' && $ins[0]['valores'][3] === 'ficticio', 'desafio fictício nasce ativo, com as mesmas regras');
$pFicticioForcado = ['enviar' => true] + $pFicticio;
$n = 0;
smsOtpSolicitar(bancoSolicitar(), $pFicticioForcado, transporteCapturando($codigo, $n));
falsoVerificar($n === 0, 'fictício de recuperação nunca envia, mesmo com enviar=true');
// Primeiro acesso de telefone sem cadastro: envia de verdade.
$pNovo = ['principal_tipo' => 'ficticio', 'finalidade' => 'acesso_cliente', 'id_empresa' => 7, 'id_cliente' => 0, 'id_usuario' => 0, 'telefone' => TEL, 'ip' => '1.1.1.1', 'enviar' => true];
$n = 0;
$r = smsOtpSolicitar(bancoSolicitar(), $pNovo, transporteCapturando($codigo, $n));
falsoVerificar($r['sucesso'] === true && $n === 1 && $r['dados']['decoy'] === false, 'primeiro acesso sem cadastro envia o código');

// Falhas de envio e de configuração.
$n = 0;
$r = smsOtpSolicitar(bancoSolicitar(), $pCliente, static function (array $req) use (&$n): array {
    $n++;

    return ['http_status' => 400, 'body' => '{}', 'errno' => 0];
});
falsoVerificar($r['sucesso'] === false && $r['codigo'] === 'SMS_OTP_ENVIO_FALHOU' && $n === 1, 'rejeição do provedor => falha sem reenvio');
$r = smsOtpSolicitar(bancoSolicitar(), $pCliente, static fn (array $req): array => ['http_status' => 0, 'body' => '', 'errno' => 28]);
falsoVerificar($r['sucesso'] === true && $r['dados']['estado_envio'] === 'incerto', 'timeout: resposta genérica de sucesso (o SMS pode chegar), sem reenvio');
smsOtpCarga(['ok' => false, 'habilitado' => false, 'erros' => ['CONFIG_AUSENTE'], 'config' => []]);
$r = smsOtpSolicitar(bancoSolicitar(), $pCliente);
falsoVerificar($r['codigo'] === 'SMS_CONFIG_INVALIDA', 'sem configuração válida não cria desafio');
smsOtpCarga(['ok' => true, 'habilitado' => false, 'erros' => [], 'config' => $config]);
$n = 0;
$r = smsOtpSolicitar(bancoSolicitar(), $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['sucesso'] === false && $n === 0, 'envio desabilitado: nenhum SMS, nenhum bypass');
smsOtpCarga($carga);
$r = smsOtpSolicitar(bancoSolicitar(), ['telefone' => 'x'] + $pCliente);
falsoVerificar($r['codigo'] === 'TELEFONE_INVALIDO', 'telefone inválido recusado');
$r = smsOtpSolicitar(bancoSolicitar(), ['finalidade' => 'recuperacao_usuario'] + $pCliente);
falsoVerificar($r['codigo'] === 'SMS_OTP_PRINCIPAL_INVALIDO', 'combinação inválida recusada antes do banco');

// Concorrência: chave única do slot ativo.
$db = bancoSolicitar([['/^INSERT INTO otp_desafio/', new RuntimeException('Duplicate entry', 1062)]]);
$n = 0;
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_CONCORRENCIA' && $n === 0 && $db->rollbacks === 1, 'violação do slot ativo (1062) => rollback, sem SMS');
$db = bancoSolicitar([['/^INSERT INTO otp_desafio/', new RuntimeException('Deadlock', 1213)]]);
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_CONCORRENCIA', 'deadlock => resposta de concorrência');

// Validação.
$_SESSION = [];
$sessaoHex = smsOtpHmac($carga, 'sessao', smsOtpVinculoSessao());
$hashCodigo = smsOtpCodigoHash($carga, '482913');
function linhaOtp(array $troca = []): array
{
    global $sessaoHex, $hashCodigo;

    return array_replace([
        'status' => 'ativo', 'codigo_hash' => $hashCodigo, 'tentativas_validacao' => 0, 'max_tentativas' => 5, 'otp_empresa' => 7,
        'sessao_hex' => $sessaoHex, 'expirado' => 0, 'cliente_empresa' => 7, 'cliente_status' => 'ativo',
    ], $troca);
}
function bancoValidar(array $linha): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT o\.status/', [$linha]);

    return $db;
}
$pVal = $pCliente + ['id_otp_desafio' => 42, 'codigo' => '482913'];

// Sucesso: consumo único.
$db = bancoValidar(linhaOtp());
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['sucesso'] === true && $r['dados']['id_otp_desafio'] === 42, 'código correto valida');
falsoVerificar(count($db->chamadas('/status = \'consumido\'/')) === 1, 'desafio consumido (uso único)');
falsoVerificar(str_contains($db->chamadas('/^SELECT o\.status/')[0]['sql'], 'FOR UPDATE OF o'), 'desafio bloqueado durante a validação');
falsoVerificar($db->commits === 1 && $db->inicios === 1, 'transação confirmada');
falsoVerificar(str_contains($db->chamadas('/^SELECT o\.status/')[0]['sql'], 'LEFT JOIN cliente c ON c.id_cliente = o.id_cliente'), 'empresa do cliente conferida pelo JOIN');
falsoVerificar(preg_match('/WHERE o\.id_otp_desafio = \? AND o\.chave_principal = UNHEX\(\?\)/', $db->chamadas('/^SELECT o\.status/')[0]['sql']) === 1, 'desafio selecionado por id E chave do principal');

// Uso único: segunda tentativa já encontra consumido.
foreach (['consumido', 'invalidado', 'bloqueado', 'erro_envio', 'pendente_envio', 'validado'] as $status) {
    $db = bancoValidar(linhaOtp(['status' => $status]));
    $r = smsOtpValidar($db, $pVal);
    falsoVerificar($r['sucesso'] === false && $db->chamadas('/^UPDATE/') === [], "desafio $status não valida nem muda");
}
$db = bancoValidar(linhaOtp());
$db->regra('/status = \'consumido\'/', 0);
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['sucesso'] === false, 'consumo concorrente (0 linhas) não valida duas vezes');

// Código errado: tentativa persistida.
$db = bancoValidar(linhaOtp());
$r = smsOtpValidar($db, ['codigo' => '000000'] + $pVal);
falsoVerificar($r['sucesso'] === false && $r['codigo'] === 'SMS_OTP_CODIGO_INVALIDO', 'código errado recusado');
falsoVerificar(count($db->chamadas('/tentativas_validacao = tentativas_validacao \+ 1/')) === 1 && $db->commits === 1 && $db->rollbacks === 0, 'tentativa persistida (COMMIT) mesmo com erro');
falsoVerificar($db->chamadas('/consumido/') === [], 'código errado não consome');
$r = smsOtpValidar(bancoValidar(linhaOtp()), ['codigo' => '482914'] + $pVal);
falsoVerificar($r['mensagem'] === 'Código inválido ou expirado.', 'mensagem genérica');

// Última tentativa bloqueia.
$db = bancoValidar(linhaOtp(['tentativas_validacao' => 4]));
$r = smsOtpValidar($db, ['codigo' => '000000'] + $pVal);
falsoVerificar($r['codigo'] === 'SMS_OTP_TENTATIVAS' && count($db->chamadas("/status = 'bloqueado'/")) === 1, '5ª falha bloqueia o desafio');
$db = bancoValidar(linhaOtp(['tentativas_validacao' => 5]));
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['codigo'] === 'SMS_OTP_TENTATIVAS' && $db->chamadas('/consumido/') === [], 'tentativas esgotadas: nem o código certo vale');
$db = bancoValidar(linhaOtp(['tentativas_validacao' => 4]));
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['sucesso'] === true, 'acerto na última tentativa ainda vale');

// Expiração.
$db = bancoValidar(linhaOtp(['expirado' => 1]));
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['codigo'] === 'SMS_OTP_EXPIRADO' && $db->chamadas('/consumido/') === [] && count($db->chamadas("/status = 'invalidado'/")) === 1, 'código expirado não valida e invalida o desafio');

// Sessão diferente.
$db = bancoValidar(linhaOtp(['sessao_hex' => str_repeat('0', 64)]));
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['sucesso'] === false && $db->chamadas('/^UPDATE/') === [], 'desafio de outra sessão não valida e não gasta tentativas');

// Empresa/cliente inconsistentes.
$db = bancoValidar(linhaOtp(['cliente_empresa' => 8]));
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['sucesso'] === false && $db->chamadas('/consumido/') === [] && count($db->chamadas("/status = 'invalidado'/")) === 1, 'otp_desafio.id_empresa ≠ empresa real do cliente => invalida');
$db = bancoValidar(linhaOtp(['cliente_status' => 'inativo']));
falsoVerificar(smsOtpValidar($db, $pVal)['sucesso'] === false, 'cliente inativo não valida');
$db = bancoValidar(linhaOtp(['cliente_empresa' => null]));
falsoVerificar(smsOtpValidar($db, $pVal)['sucesso'] === false, 'cliente inexistente não valida');

// Principal/empresa diferentes na chave: outro cliente da mesma empresa ou empresa diferente não encontra o desafio.
$db = new BancoFalso();
$r = smsOtpValidar($db, ['id_empresa' => 8] + $pVal);
falsoVerificar($r['sucesso'] === false && count($db->chamadas('/^SELECT o\.status/')) === 1, 'desafio inexistente para a chave do principal');
$chaveBuscada = $db->chamadas('/^SELECT o\.status/')[0]['valores'][1];
falsoVerificar($chaveBuscada !== smsOtpChavePrincipal($carga, $pCliente, TEL), 'empresa diferente consulta outra chave (isolamento)');

// Entradas inválidas nunca chegam ao banco.
foreach ([['codigo' => '12345'], ['codigo' => 'abcdef'], ['codigo' => ''], ['id_otp_desafio' => 0], ['telefone' => '12']] as $troca) {
    $db = bancoValidar(linhaOtp());
    $r = smsOtpValidar($db, $troca + $pVal);
    falsoVerificar($r['sucesso'] === false && $db->log === [], 'entrada inválida recusada sem consulta: ' . json_encode($troca));
}

// Falha de banco na validação.
$db = bancoValidar(linhaOtp());
$db->regra('/^SELECT o\.status/', new RuntimeException('banco fora'));
$r = smsOtpValidar($db, $pVal);
falsoVerificar($r['sucesso'] === false && $r['codigo'] === 'SMS_OTP_INDISPONIVEL' && $db->rollbacks === 1, 'falha de banco => rollback e recusa');

// Desafio fictício nunca valida para recuperação (código desconhecido).
$hashAleatorio = smsOtpCodigoHash($carga, smsOtpGerarCodigo());
$db = bancoValidar(linhaOtp(['codigo_hash' => $hashAleatorio, 'otp_empresa' => null, 'cliente_empresa' => null, 'cliente_status' => null]));
$r = smsOtpValidar($db, $pFicticio + ['id_otp_desafio' => 43, 'codigo' => '482913']);
falsoVerificar($r['sucesso'] === false, 'desafio fictício rejeita o código');
falsoVerificar(count($db->chamadas('/tentativas_validacao = tentativas_validacao \+ 1/')) === 1, 'desafio fictício também conta tentativas');

// Remoção de Twilio e de OTP fixo.
$raiz = realpath(__DIR__ . '/../..');
$arquivos = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/backend', FilesystemIterator::SKIP_DOTS));
foreach ($iter as $arq) {
    if ($arq->isFile() && $arq->getExtension() === 'php' && !str_ends_with($arq->getFilename(), 'twilio.php')) {
        $arquivos[] = $arq->getPathname();
    }
}
$acertos = [];
foreach ($arquivos as $arq) {
    $conteudo = (string)file_get_contents($arq);
    if (preg_match('/dev_fixed_otp|dev_message_body|account_sid|api\.twilio\.com|smsOtpTwilio|TWILIO_|programmable_sms/i', $conteudo) === 1) {
        $acertos[] = basename($arq);
    }
}
falsoVerificar($acertos === [], 'nenhuma dependência operacional de Twilio/OTP fixo no backend: ' . implode(',', $acertos));
$cl = (string)file_get_contents($raiz . '/backend/_auth/cliente_login.php');
$lg = (string)file_get_contents($raiz . '/backend/_auth/login.php');
falsoVerificar(!str_contains($cl, 'otp_hash') && !str_contains($lg, 'otp_hash'), 'sessão não guarda hash de OTP');
falsoVerificar(!str_contains($cl, 'password_verify($codigo') && !str_contains($lg, 'password_verify($codigo'), 'sem validação local do código nos fluxos');
falsoVerificar(substr_count($cl, 'smsOtpSolicitar($conexao') === 1 && substr_count($cl, 'smsOtpValidar($conexao') === 1, 'cliente_login usa o serviço persistente');
falsoVerificar(substr_count($lg, 'smsOtpSolicitar(') === 1 && substr_count($lg, 'smsOtpValidar(') === 1, 'login (usuários internos) usa o serviço persistente');
falsoVerificar(!preg_match('/random_int\(100000, 999999\)/', $cl . $lg), 'fluxos não geram código por conta própria');

// Fase 3: teto de SMS para telefones sem cadastro (primeiro acesso), por empresa.
$db = bancoSolicitar([['/principal_tipo = \'ficticio\' AND finalidade = \'acesso_cliente\'/', [['n' => SMS_OTP_LIMITE_NOVOS_EMPRESA_HORA]]]]);
$n = 0;
$r = smsOtpSolicitar($db, $pNovo, transporteCapturando($codigo, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_LIMITE' && $n === 0 && $db->chamadas('/^INSERT/') === [], 'teto por empresa para primeiros acessos sem cadastro');
$db = bancoSolicitar();
smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($db->chamadas('/principal_tipo = \'ficticio\' AND finalidade = \'acesso_cliente\'/') === [], 'cliente cadastrado não consome o teto de primeiros acessos');
$db = bancoSolicitar();
smsOtpSolicitar($db, $pFicticio, transporteCapturando($codigo, $n));
falsoVerificar($db->chamadas('/principal_tipo = \'ficticio\' AND finalidade = \'acesso_cliente\'/') === [], 'recuperação fictícia não consome o teto');

// Fase 3: erros reais do MySQL (mysqli sem exceções) chegam como código no RuntimeException.
$fonteSms = (string)file_get_contents(__DIR__ . '/../../backend/_servicos/sms.php');
falsoVerificar(substr_count($fonteSms, '$stmt->errno') >= 2 && str_contains($fonteSms, '(int)$conexao->errno'), 'errno do MySQL preservado no RuntimeException (conexao.php desliga as exceções do mysqli)');
$db = bancoSolicitar([['/^INSERT INTO otp_desafio/', new RuntimeException('Lock wait timeout', 1205)]]);
$r = smsOtpSolicitar($db, $pCliente, transporteCapturando($codigo, $n));
falsoVerificar($r['codigo'] === 'SMS_OTP_CONCORRENCIA', 'lock wait timeout (1205) tratado como concorrência');

// Fase 3: regra do primeiro acesso x recuperação no cliente_login.php.
$cl = (string)file_get_contents($raiz . '/backend/_auth/cliente_login.php');
falsoVerificar(str_contains($cl, '$recuperacaoFicticia = !clienteLoginClienteAtivo($clienteOtp) || $hashClienteOtp === \'\';'), 'recuperação de conta inexistente/inativa/sem senha vira desafio fictício');
falsoVerificar(str_contains($cl, "'enviar' => !\$recuperacaoFicticia,"), 'recuperação fictícia nunca envia SMS');
falsoVerificar(!str_contains($cl, 'CLIENT_PASSWORD_RECOVERY_UNAVAILABLE'), 'recuperação não devolve erro que revele a conta');
falsoVerificar(str_contains($cl, "\$acao !== 'iniciar_recuperacao' && is_array(\$clienteOtp)"), 'recuperação não devolve CLIENT_NOT_AVAILABLE');
falsoVerificar(str_contains($cl, "'principal_tipo' => \$clienteOtpAtivo ? 'cliente' : 'ficticio',"), 'sem cadastro: principal fictício (primeiro acesso com SMS real)');
falsoVerificar(preg_match('/principal_tipo.*?ficticio.*?acesso_cliente/s', (string)file_get_contents($raiz . '/backend/_servicos/sms_otp.php')) === 1, 'serviço distingue decoy (recuperação) de primeiro acesso (acesso_cliente)');

falsoRelatorio();
