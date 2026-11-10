<?php
declare(strict_types=1);

/*
 * Testes da autenticação dupla do cliente (C3.1): contas só com celular, só com e-mail ou com os
 * dois, sessão, validação do segundo contato e regras de segurança. Banco falso e transportes
 * falsos: nenhuma mensagem real, nenhuma escrita em banco.
 * Uso: php tools/tests/cliente-contatos.test.php
 */

$total = 0;
$falhas = [];
date_default_timezone_set('UTC');
require_once __DIR__ . '/sms-banco-falso.php';
require_once __DIR__ . '/../../backend/_regras/cliente_login_email.php';
require_once __DIR__ . '/../../backend/cliente/_sessao_cliente.php';

$raiz = realpath(__DIR__ . '/../..');

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
const EMAIL = 'cliente@exemplo.test';

$chamadasSms = 0;
$chamadasEmail = 0;
$transporte = static function (array $req) use (&$chamadasSms, &$chamadasEmail): array {
    if (isset($req['body'])) {
        $chamadasSms++;
        $corpo = json_decode($req['body'], true);

        return ['http_status' => 200, 'errno' => 0, 'body' => json_encode(['bulkId' => 'b', 'messages' => [[
            'messageId' => $corpo['messages'][0]['destinations'][0]['messageId'], 'status' => ['groupName' => 'PENDING', 'id' => 26, 'name' => 'X'],
        ]]])];
    }
    $chamadasEmail++;

    return ['fase' => 'final', 'codigo' => 250, 'errno' => 0];
};

function bancoContatos(array $extra = []): BancoFalso
{
    $db = new BancoFalso();
    $db->regra('/^SELECT 1 FROM cliente WHERE id_cliente = \? AND id_empresa = \? AND status/', [['1' => 1]]);
    $db->regra('/^SELECT COUNT\(\*\) AS n/', [['n' => 0]]);
    $db->regra('/^SELECT LAST_INSERT_ID/', [['id' => 42]]);
    $db->regra('/^SELECT status FROM otp_desafio/', [['status' => 'pendente_envio']]);
    foreach ($extra as [$regex, $resposta]) {
        $db->regra($regex, $resposta);
    }

    return $db;
}

/** Executa exigirSessaoCliente() num processo próprio (a função encerra com out()). */
function sessaoCliente(array $auth, int $empresaSessao = 42): array
{
    global $raiz;
    // O marcador da credencial (A6.1) acompanha a sessão criada no login; sem cadastro/senha usa a credencial vazia.
    $script = 'session_save_path(sys_get_temp_dir()); require ' . var_export($raiz . '/backend/cliente/_sessao_cliente.php', true)
        . '; require_once ' . var_export($raiz . '/backend/_regras/login_multiempresa.php', true)
        . '; session_start(); $_SESSION = json_decode(base64_decode($argv[1]), true);'
        . ' $_SESSION[SESSAO_CREDENCIAL_CLIENTE] = sessaoCredencialImpressao(null);'
        . ' echo json_encode(exigirSessaoCliente());';
    $sessao = ['empresa_id' => $empresaSessao, 'cliente_auth' => $auth];
    $saida = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' ' . escapeshellarg(base64_encode((string)json_encode($sessao))) . ' 2>&1');
    $json = json_decode((string)$saida, true);

    return is_array($json) ? $json : ['saida' => $saida];
}

$base = ['id_cliente' => null, 'id_empresa' => 42, 'status' => 'ativo', 'tipo' => 'cliente', 'tipo_usuario' => 'cliente', 'modo_visualizacao' => true];

// ---- 1. Sessão: celular, e-mail ou ambos ---------------------------------------------------------
$s = sessaoCliente($base + ['telefone' => TEL, 'telefone_verificado' => true]);
falsoVerificar(($s['telefone'] ?? '') === TEL && ($s['email'] ?? 'x') === '' && ($s['identificador'] ?? '') === TEL, 'sessão por celular: continua válida; identificador = celular (sessões antigas inclusive)');
$s = sessaoCliente($base + ['telefone' => '', 'email' => EMAIL, 'email_verificado' => true]);
falsoVerificar(($s['email'] ?? '') === EMAIL && ($s['telefone'] ?? 'x') === '' && ($s['identificador'] ?? '') === 'email:' . EMAIL, 'sessão só com e-mail: válida, sem telefone, identificador "email:..."');
$s = sessaoCliente($base + ['telefone' => '', 'email' => EMAIL, 'email_verificado' => true, 'identificador' => 'email:' . EMAIL]);
falsoVerificar(($s['identificador'] ?? '') === 'email:' . EMAIL, 'identificador da sessão é respeitado');
$s = sessaoCliente($base + ['telefone' => TEL, 'telefone_verificado' => false, 'email' => '', 'email_verificado' => false]);
falsoVerificar(($s['code'] ?? '') === 'CLIENT_NOT_AUTHENTICATED', 'nenhum contato comprovado: 401');
$s = sessaoCliente($base + ['telefone' => 'sem-formato', 'telefone_verificado' => true]);
falsoVerificar(($s['code'] ?? '') === 'CLIENT_NOT_AUTHENTICATED', 'celular comprovado mas inválido: 401');
$s = sessaoCliente($base + ['telefone' => '', 'email' => 'nao-e-email', 'email_verificado' => true]);
falsoVerificar(($s['code'] ?? '') === 'CLIENT_NOT_AUTHENTICATED', 'e-mail comprovado mas inválido: 401');
$s = sessaoCliente($base + ['telefone' => '', 'email' => EMAIL, 'email_verificado' => false]);
falsoVerificar(($s['code'] ?? '') === 'CLIENT_NOT_AUTHENTICATED', 'e-mail não comprovado nesta sessão: 401');
$s = sessaoCliente(['id_empresa' => 43] + $base + ['telefone' => '', 'email' => EMAIL, 'email_verificado' => true]);
falsoVerificar(($s['code'] ?? '') === 'CLIENT_NOT_AUTHENTICATED', 'empresa da sessão diferente da empresa do contexto: 401 (isolamento)');
$s = sessaoCliente(['status' => 'bloqueado'] + $base + ['telefone' => '', 'email' => EMAIL, 'email_verificado' => true]);
falsoVerificar(($s['code'] ?? '') === 'CLIENT_NOT_AUTHENTICATED', 'cliente não ativo: 401');
$s = sessaoCliente($base + ['telefone' => TEL, 'telefone_verificado' => true, 'email' => EMAIL, 'email_verificado' => true]);
falsoVerificar(($s['telefone'] ?? '') === TEL && ($s['email'] ?? '') === EMAIL && ($s['identificador'] ?? '') === TEL, 'celular e e-mail comprovados: ambos na sessão');

// Sessão só com e-mail e sem cadastro nunca é associada a um cliente existente pelo e-mail.
$dbNulo = new BancoFalso();
$semTelefone = ['id_empresa' => 42, 'id_cliente' => null, 'telefone' => '', 'email' => EMAIL, 'identificador' => 'email:' . EMAIL];
falsoVerificar(buscarClienteDaSessao($dbNulo, $semTelefone) === null && $dbNulo->log === [], 'sessão só com e-mail sem cadastro: nenhuma consulta e nenhuma associação automática');
$dbCliente = new BancoFalso();
// buscarClienteDaSessao usa prepare() real; com id_cliente o caminho é por chave primária + empresa.
$fonteSessao = (string)file_get_contents($raiz . '/backend/cliente/_sessao_cliente.php');
falsoVerificar(str_contains($fonteSessao, 'WHERE id_cliente = ? AND id_empresa = ?'), 'cliente da sessão é buscado por id + empresa');

// ---- 2. Funções de regras (banco falso) -----------------------------------------------------------
$db = new BancoFalso();
$db->regra('/^SELECT id_cliente, id_empresa, nome_completo/', [['id_cliente' => 5, 'id_empresa' => 42, 'email' => EMAIL, 'email_verificado_em' => null, 'senha_hash' => null, 'status' => 'ativo']]);
$c = clienteLoginBuscarClientePorEmail($db, 42, ' Cliente@Exemplo.TEST ');
$q = $db->chamadas('/^SELECT id_cliente/')[0];
falsoVerificar($c !== null && $q['valores'] === [42, EMAIL] && str_contains($q['sql'], 'id_empresa = ?'), 'busca por e-mail: normalizada e sempre dentro da empresa');
$db = new BancoFalso();
$db->regra('/^SELECT id_cliente/', [['id_cliente' => 5], ['id_cliente' => 6]]);
$erro = null;
try {
    clienteLoginBuscarClientePorEmail($db, 42, EMAIL);
} catch (RuntimeException $e) {
    $erro = $e;
}
falsoVerificar($erro !== null, 'e-mails duplicados interrompem o fluxo (nunca escolhe um registro)');
falsoVerificar(clienteLoginBuscarClientePorEmail(new BancoFalso(), 42, EMAIL) === null, 'e-mail sem cadastro devolve null');
$erro = null;
try {
    clienteLoginBuscarClientePorEmail(new BancoFalso(), 42, 'invalido');
} catch (RuntimeException $e) {
    $erro = $e;
}
falsoVerificar($erro !== null, 'e-mail inválido não chega ao banco');
falsoVerificar(clienteLoginEmailLivreNaEmpresa(new BancoFalso(), 42, EMAIL) === true, 'e-mail sem cadastro está livre na empresa');
$db = new BancoFalso();
$db->regra('/^SELECT 1 FROM cliente WHERE id_empresa = \? AND LOWER\(email\)/', [['1' => 1]]);
falsoVerificar(clienteLoginEmailLivreNaEmpresa($db, 42, EMAIL) === false, 'e-mail cadastrado na empresa não está livre');

// Marcação do celular validado: só o número cadastrado, só do cliente e da empresa.
$db = new BancoFalso();
$db->regra('/^UPDATE cliente SET telefone_verificado_em/', 1);
falsoVerificar(clienteLoginTelefoneMarcarVerificado($db, 42, 5, TEL) === true, 'celular cadastrado marcado como validado');
$u = $db->chamadas('/^UPDATE cliente SET telefone_verificado_em/')[0];
falsoVerificar($u['valores'] === [5, 42, '5538998969407', '38998969407'] && str_contains($u['sql'], 'id_empresa = ?') && str_contains($u['sql'], 'telefone_verificado_em IS NULL'), 'compara o número normalizado (com e sem 55), por cliente + empresa, sem regravar');
$db = new BancoFalso();
falsoVerificar(clienteLoginTelefoneMarcarVerificado($db, 0, 5, TEL) === false && clienteLoginTelefoneMarcarVerificado($db, 42, 0, TEL) === false && clienteLoginTelefoneMarcarVerificado($db, 42, 5, '123') === false && $db->log === [], 'parâmetros inválidos não chegam ao banco');

// ---- 3. Conta só com e-mail e conta só com celular no serviço OTP ---------------------------------
$pEmailSo = ['principal_tipo' => 'ficticio', 'finalidade' => 'acesso_cliente', 'id_empresa' => 42, 'id_cliente' => 0, 'id_usuario' => 0,
    'telefone' => '', 'ip' => '203.0.113.9', 'enviar' => true, 'canal' => 'email', 'email' => EMAIL];
$chamadasSms = $chamadasEmail = 0;
$db = bancoContatos();
$r = smsOtpSolicitar($db, $pEmailSo, $transporte);
falsoVerificar($r['sucesso'] === true && $chamadasEmail === 1 && $chamadasSms === 0, 'cliente novo só com e-mail recebe o código no e-mail');
$pSmsSo = ['canal' => 'sms', 'telefone' => TEL, 'email' => null] + $pEmailSo;
$chamadasSms = $chamadasEmail = 0;
$r = smsOtpSolicitar(bancoContatos(), $pSmsSo, $transporte);
falsoVerificar($r['sucesso'] === true && $chamadasSms === 1 && $chamadasEmail === 0, 'cliente novo só com celular recebe SMS (Infobip inalterada)');
falsoVerificar(
    smsOtpChavePrincipal($carga, $pSmsSo, TEL) !== smsOtpChavePrincipal($carga, $pEmailSo, '', EMAIL),
    'primeiro acesso por celular e por e-mail são identidades distintas (sem telefone fictício)'
);

// Validação sem telefone: usa a identidade do e-mail e exige o mesmo e-mail.
$_SESSION = [];
$sessaoHex = smsOtpHmac($carga, 'sessao', smsOtpVinculoSessao());
$hashCodigo = smsOtpCodigoHash($carga, '482913');
$linha = static fn (array $t = []): array => array_replace([
    'status' => 'ativo', 'codigo_hash' => $hashCodigo, 'tentativas_validacao' => 0, 'max_tentativas' => 5, 'otp_empresa' => 42,
    'sessao_hex' => $sessaoHex, 'expirado' => 0, 'cliente_empresa' => null, 'cliente_status' => null,
    'canal' => 'email', 'destino_hex' => smsOtpHmac($carga, 'email', EMAIL),
], $t);
$validar = static function (array $l, array $p) {
    $db = new BancoFalso();
    $db->regra('/^SELECT o\.status/', [$l]);

    return [smsOtpValidar($db, $p), $db];
};
$pVal = ['id_otp_desafio' => 42, 'codigo' => '482913'] + $pEmailSo;
[$v, $dbv] = $validar($linha(), $pVal);
falsoVerificar($v['sucesso'] === true, 'primeiro acesso só com e-mail valida o código sem telefone');
$chaveEsperada = smsOtpChavePrincipal($carga, $pEmailSo, '', EMAIL);
falsoVerificar($dbv->chamadas('/^SELECT o\.status/')[0]['valores'][1] === $chaveEsperada, 'desafio localizado pela chave do e-mail');
[$v] = $validar($linha(), ['email' => 'outro@exemplo.test'] + $pVal);
falsoVerificar($v['sucesso'] === false, 'e-mail diferente não encontra o desafio do primeiro acesso');
[$v] = $validar($linha(), ['email' => '', 'telefone' => ''] + $pVal);
falsoVerificar($v['sucesso'] === false && $v['codigo'] === 'SMS_OTP_CODIGO_INVALIDO', 'sem telefone e sem e-mail a validação é recusada antes do banco');

// ---- 4. Validar o segundo contato no perfil (verificar_contato) -----------------------------------
$pVerificarEmail = ['principal_tipo' => 'cliente', 'finalidade' => 'acesso_cliente', 'id_empresa' => 42, 'id_cliente' => 7, 'id_usuario' => 0,
    'telefone' => TEL, 'ip' => '203.0.113.9', 'enviar' => true, 'canal' => 'email', 'email' => EMAIL, 'verificar_contato' => true];
$chamadasEmail = 0;
$db = bancoContatos([['/LOWER\(email\) = \?/', [['1' => 1]]]]);
$r = smsOtpSolicitar($db, $pVerificarEmail, $transporte);
$consulta = array_values(array_filter($db->chamadas('/LOWER\(email\) = \?/'), static fn ($c) => !str_contains($c['sql'], 'id_empresa = ? AND id_cliente')));
$permissao = array_values(array_filter($db->chamadas('/LOWER\(email\) = \?/'), static fn ($c) => str_contains($c['sql'], 'WHERE id_cliente = ?')))[0] ?? null;
falsoVerificar($r['sucesso'] === true && $chamadasEmail === 1 && $r['dados']['decoy'] === false, 'cliente autenticado valida o e-mail cadastrado e ainda não validado');
falsoVerificar($permissao !== null && !str_contains($permissao['sql'], 'email_verificado_em IS NOT NULL') && $permissao['valores'] === [7, 42, EMAIL], 'com verificar_contato exige só o e-mail cadastrado do próprio cliente e empresa');
// O login nunca define verificar_contato: e-mail não validado continua sem receber código.
$chamadasEmail = 0;
$db = bancoContatos([['/LOWER\(email\) = \?/', []]]);
$r = smsOtpSolicitar($db, array_diff_key($pVerificarEmail, ['verificar_contato' => 1]), $transporte);
falsoVerificar($r['dados']['decoy'] === true && $chamadasEmail === 0, 'sem verificar_contato (login), e-mail não validado não recebe código');
// E-mail diferente do cadastrado, mesmo com verificar_contato: sem envio.
$chamadasEmail = 0;
$db = bancoContatos([['/LOWER\(email\) = \?/', []]]);
$r = smsOtpSolicitar($db, $pVerificarEmail, $transporte);
falsoVerificar($r['dados']['decoy'] === true && $chamadasEmail === 0, 'e-mail que não é o cadastrado do cliente não recebe código');
// Celular cadastrado validado por SMS (principal cliente), e mesmo slot de acesso.
$chamadasSms = 0;
$r = smsOtpSolicitar(bancoContatos(), ['canal' => 'sms', 'email' => null, 'verificar_contato' => true] + $pVerificarEmail, $transporte);
falsoVerificar($r['sucesso'] === true && $chamadasSms === 1, 'validar o celular cadastrado envia SMS pela Infobip');
falsoVerificar(
    smsOtpChavePrincipal($carga, $pVerificarEmail, TEL) === smsOtpChavePrincipal($carga, ['canal' => 'sms'] + $pVerificarEmail, TEL),
    'validar celular e e-mail do mesmo cliente compartilham um único slot ativo'
);
$db = bancoContatos([['/^SELECT TIMESTAMPDIFF/', [['idade' => 5]]]]);
$r = smsOtpSolicitar($db, $pVerificarEmail, $transporte);
falsoVerificar($r['codigo'] === 'SMS_OTP_REENVIO_AGUARDAR' && $db->chamadas('/^INSERT INTO otp_desafio/') === [], 'reenvio antes de 30 s é recusado também na validação de contato');

// ---- 5. Contrato do endpoint de contatos (fonte) ---------------------------------------------------
$f = (string)file_get_contents($raiz . '/backend/cliente/contatos_cliente.php');
falsoVerificar(str_contains($f, 'exigirSessaoCliente()') && str_contains($f, 'csrfValidarSessao()'), 'contatos: sessão do cliente e CSRF obrigatórios');
falsoVerificar(str_contains($f, "'verificar_contato' => true"), 'contatos: só este endpoint autenticado pede verificar_contato');
$posValidar = strpos($f, '$validacao = smsOtpValidar(');
$posGravarTel = strpos($f, 'UPDATE cliente SET whatsapp_celular = ?');
$posMarcarTel = strpos($f, 'clienteLoginTelefoneMarcarVerificado($conexao');
$posMarcarEmail = strpos($f, 'emailOtpMarcarVerificado($conexao');
falsoVerificar($posValidar !== false && $posGravarTel !== false && $posValidar < $posGravarTel && $posValidar < $posMarcarTel && $posValidar < $posMarcarEmail, 'contatos: celular só é gravado/marcado depois do código validado');
falsoVerificar(str_contains($f, 'clienteContatoTelefoneEmUso($conexao, $idEmpresa, $novoTelefone, $idCliente)'), 'contatos: celular de outro cliente da empresa nunca é associado');
falsoVerificar(str_contains($f, "AND (whatsapp_celular IS NULL OR TRIM(whatsapp_celular) = '')"), 'contatos: celular só é adicionado a conta sem celular');
falsoVerificar(str_contains($f, "'id_cliente' => \$idCliente") && !str_contains($f, "\$_POST['id_cliente']") && !str_contains($f, "\$_POST['id_empresa']"), 'contatos: cliente e empresa vêm só da sessão');
falsoVerificar(substr_count($f, 'telefone_verificado_em = NOW()') === 1, 'contatos: telefone_verificado_em gravado em um único ponto, após a gravação do número');

// ---- 6. Perfil: regras do último contato (fonte) ---------------------------------------------------
$s = (string)file_get_contents($raiz . '/backend/cliente/salvar_dados_cadastrais.php');
falsoVerificar(str_contains($s, 'CLIENT_LAST_VERIFIED_CONTACT') && str_contains($s, 'CLIENT_CONTACT_REQUIRED'), 'perfil: bloqueia alterar/remover o último contato validado e conta sem contato');
falsoVerificar(str_contains($s, '$telefoneConta = $sessao[\'telefone\'] !== \'\' ? $sessao[\'telefone\'] : null;') && !preg_match('/whatsapp_celular\s*=\s*\'\+?5500/', $s), 'perfil: sem celular comprovado, whatsapp_celular fica NULL (nunca fictício)');
falsoVerificar(str_contains($s, 'IF(? = 1, NOW(), NULL), IF(? = 1, NOW(), NULL)') && str_contains($s, "'issssssssssssii'"), 'perfil: conta nova grava a validação só do contato comprovado no login');
falsoVerificar(str_contains($s, '$email = (string)$sessao[\'email\'];'), 'perfil: conta criada por e-mail usa o e-mail comprovado (não o digitado)');
falsoVerificar(str_contains($s, 'email_verificado_em = IF(LOWER(email) <=> ?, email_verificado_em, NULL)'), 'perfil: alterar o e-mail invalida a verificação anterior');

// ---- 7. Login: handler (fonte) ----------------------------------------------------------------------
$l = (string)file_get_contents($raiz . '/backend/_auth/cliente_login.php');
falsoVerificar(str_contains($l, "'verificar_email'") && str_contains($l, 'clienteLoginBuscarClientePorEmail('), 'login: identificação por e-mail disponível');
falsoVerificar(str_contains($l, "!is_array(\$cliente) || (\$cliente['email_verificado_em'] ?? null) === null"), 'login por senha com e-mail exige e-mail validado');
falsoVerificar(str_contains($l, "\$desafioCanal === 'email' ? '' : \$desafioTelefone"), 'código por e-mail: a sessão não recebe telefone que não foi comprovado');
falsoVerificar(str_contains($l, 'clienteLoginTelefoneMarcarVerificado('), 'login por SMS marca o celular cadastrado como validado');
falsoVerificar(str_contains($l, "'identificador' => \$identificadorSessao"), 'sessão e recuperação guardam o identificador do login');

// ---- 8. Dependentes: recuperação por qualquer identificador ------------------------------------------
foreach (['alterar_senha_perfil', 'buscar_foto_perfil'] as $arquivo) {
    $d = (string)file_get_contents($raiz . '/backend/cliente_perfil/' . $arquivo . '.php');
    falsoVerificar(str_contains($d, "\$sessao['identificador']") && str_contains($d, "\$recuperacao['identificador']"), $arquivo . ': autorização de recuperação por identificador (celular ou e-mail)');
}
$rota = (string)file_get_contents($raiz . '/public/api/api_central.php');
falsoVerificar(str_contains($rota, "'cliente/perfil/contatos' => ["), 'rota do perfil de contatos registrada (CSRF central vale: não está nas exceções)');
falsoVerificar(!str_contains((string)preg_replace('/\s+/', ' ', substr($rota, (int)strpos($rota, '$rotasExcecaoCsrfCentral'), 400)), 'cliente/perfil/contatos'), 'rota de contatos não é exceção de CSRF');

falsoRelatorio();
