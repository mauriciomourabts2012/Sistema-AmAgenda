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

// Parâmetro de cache oficial: vem da versão central, sem fixar número nas asserções.
$versaoCentral = (string)(json_decode((string)file_get_contents($raiz . '/backend/_config/app-version.json'), true)['version'] ?? '');
$cacheOficial = static function (string $html, string $arquivo) use ($versaoCentral): bool {
    return $versaoCentral !== '' && str_contains($html, $arquivo . '?v=' . $versaoCentral);
};

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

// ---- 9. Cadastro incompleto: notificação no lugar da abertura automática do modal -------------------
$jsCad = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/cliente/salvar-dados-cadastrais.js'));
$htmlPerfil = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/views/cliente-perfil.html'));
$cssCliente = (string)file_get_contents($raiz . '/public/css/agenda/cliente-web.css');
$iniciarJs = substr($jsCad, (int)strpos($jsCad, 'async function iniciar('), 900);
falsoVerificar(str_contains($iniciarJs, 'aplicarCadastroPendente(dados)') && str_contains($jsCad, 'definirBloqueio(!obrigatoriosCompletos);') && str_contains($jsCad, 'if (!obrigatoriosCompletos) abrirFormularioCadastro();') && str_contains($jsCad, 'modalCadastro.classList.toggle("cadastro-bloqueado", bloquear);') && str_contains($jsCad, 'document.body.classList.toggle("cadastro-cliente-pendente", bloquear);') && str_contains($jsCad, 'botaoFecharCadastro.hidden = bloquear;'), 'cadastro: obrigatórios pendentes (primeiro acesso) abrem o cadastro bloqueado até salvar (identidade antes do aceite legal)');
$jsSino = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/notificacoes/notificacoes-cliente.js'));
falsoVerificar(str_contains($jsCad, 'const opcionalPendente = obrigatoriosCompletos && possuiCampoOpcionalVazio(dados);') && str_contains($jsCad, 'definirCadastro?.({ pendente: opcionalPendente, obrigatorio: false })') && !str_contains($iniciarJs, 'abrirFormularioCadastro()'), 'cadastro: o sino recebe somente pendências opcionais; só opcionais nunca abrem o modal sozinho');
falsoVerificar(!str_contains($htmlPerfil, 'notificacaoCadastroIncompleto') && !str_contains($htmlPerfil, 'notificacao-cadastro') && !str_contains($cssCliente, '.notificacao-cadastro'), 'cadastro: a faixa do topo foi removida por completo');
falsoVerificar(str_contains($jsCad, 'const obrigatoriosCompletos = dados?.dados_obrigatorios_completos === true;') && str_contains($jsCad, '"email", "whatsapp_celular", "cpf", "data_nascimento", "cep",') && str_contains($cssCliente, 'body.cadastro-cliente-pendente .cliente-app-shell') && str_contains($htmlPerfil, 'if (modal.classList.contains("cadastro-bloqueado")) {') && str_contains($jsCad, 'window.setTimeout(() => window.location.reload(), 650);'), 'cadastro: critérios mantidos; bloqueio respeitado pelo fecharModal; salvar recarrega e o guard verifica o aceite legal');
$jsGuard = str_replace("
", "
", (string)file_get_contents($raiz . '/public/_auth/sessao.js'));
falsoVerificar(str_contains($jsGuard, 'const CODIGO_ACEITE_OBRIGATORIO = "LEGAL_MANIFESTATION_REQUIRED";') && str_contains($jsGuard, 'if (resposta.status === 428 && !ROTAS_RESOLUCAO_JURIDICA.has(rota)) {') && str_contains($jsGuard, 'dadosAceite?.code === CODIGO_ACEITE_OBRIGATORIO && encaminharAoAceite()'), 'guard: HTTP 428 LEGAL_MANIFESTATION_REQUIRED de qualquer API encaminha ao aceite obrigatório');
falsoVerificar(str_contains($jsGuard, 'if (estaNaPaginaDeAceite() || estaEmPaginaPublicaSemGuard()) return false;') && str_contains($jsGuard, 'window.__AMAGENDA_ACEITE_ENCAMINHADO__ = true;') && (bool)preg_match('/ROTAS_RESOLUCAO_JURIDICA = new Set\(\[[^\]]*"documentos-legais\/manifestar"/s', $jsGuard), 'guard: sem loop (rotas de resolução jurídica e a própria página de aceite excluídas; redireciona uma única vez)');
falsoVerificar(str_contains($jsGuard, 'const redirecionou = await verificarPendenciasLegais(auth);') && str_contains($jsGuard, 'documentos-legais/pendencias'), 'guard: verificação de pendências no carregamento preservada');
falsoVerificar(str_contains($jsGuard, 'if (redirecionou) return;
        if (exigirCadastroClienteConcluido(auth)) return;') && str_contains($jsGuard, 'const PAGINA_CADASTRO_CLIENTE = "/public/views/cliente-perfil.html";') && str_contains($jsGuard, 'if (Number.isInteger(idCliente) && idCliente > 0 && auth?.cadastro_completo === true) return false;') && str_contains($jsGuard, 'window.location.replace(PAGINA_CADASTRO_CLIENTE);'), 'guard: cliente sem cadastro concluído não acessa cliente-agendamento direto (vai ao cadastro obrigatório do perfil; aceite legal tem prioridade)');
falsoVerificar(str_contains($htmlPerfil, 'id="cadastroDicaContato"') && str_contains($htmlPerfil, 'Obrigatório: nome completo. Os demais dados são opcionais.') && !str_contains($htmlPerfil, 'Somente Nome e um contato') && str_contains($htmlPerfil, '<input id="cli_nome" name="nome" type="text" maxlength="140" autocomplete="name" placeholder=" " required>') && !preg_match('/id="cli_(email|whats)"[^>]*required/', $htmlPerfil), 'orientação: nome continua obrigatório e nenhum contato é exigido pela tela; aviso reescrito');
falsoVerificar(str_contains($jsCad, 'function atualizarDicaContato()') && str_contains($jsCad, 'if (temTelefone && !temEmail) {') && str_contains($jsCad, 'cadastre também um e-mail') && str_contains($jsCad, 'adicione também um celular') && str_contains($jsCad, 'dica.hidden = mensagem === "";') && str_contains($jsCad, 'atualizarDicaContato();
  }

  /* Orientação opcional'), 'orientação: SMS sugere e-mail, e-mail sugere celular e some com os dois cadastrados (interface apenas)');
$apiCentral = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/api/api_central.php'));
$posJuridico = (int)strpos($apiCentral, "'code' => 'LEGAL_MANIFESTATION_REQUIRED'");
$posSenha = (int)strpos($apiCentral, "'code' => 'CLIENT_PASSWORD_REQUIRED'");
$blocoLista = substr($apiCentral, (int)strpos($apiCentral, '$rotasRegularizacaoPrimeiroAcessoCliente = ['), 900);
$listaPermitidas = [];
preg_match_all("/^\s+'([a-z_\/-]+)',/m", substr($blocoLista, 0, (int)strpos($blocoLista, '];')), $mm);
$listaPermitidas = $mm[1];
sort($listaPermitidas);
$esperadas = ['_auth/logout', '_auth/session', 'cliente/perfil', 'cliente/perfil/alterar-senha', 'cliente/perfil/buscar-foto', 'cliente/perfil/contatos', 'cliente/perfil/salvar', 'documentos-legais/conteudo', 'documentos-legais/manifestar', 'documentos-legais/pendencias'];
sort($esperadas);
falsoVerificar($listaPermitidas === $esperadas, 'primeira senha: lista fechada e auditada de rotas permitidas durante a pendência (' . implode(', ', $listaPermitidas) . ')');
falsoVerificar($posJuridico > 0 && $posSenha > $posJuridico, 'primeira senha: o bloqueio da senha vem DEPOIS da verificação jurídica (aceite tem prioridade)');
falsoVerificar(str_contains($apiCentral, "'code' => 'CLIENT_PASSWORD_REQUIRED'") && str_contains($apiCentral, "'code' => 'CLIENT_PROFILE_REQUIRED'") && substr_count($apiCentral, "], 403);") >= 2 && !preg_match("/CLIENT_PASSWORD_REQUIRED'[^;]*\], 4(?!03)/s", substr($apiCentral, $posSenha, 300)), 'primeira senha: HTTP 403 com CLIENT_PASSWORD_REQUIRED (senha) e CLIENT_PROFILE_REQUIRED (sem cadastro)');
falsoVerificar(str_contains($apiCentral, "(int)(\$_SESSION['auth']['id_usuario'] ?? 0) <= 0") && str_contains($apiCentral, "if (\$idClienteFronteira <= 0) {") && str_contains($apiCentral, "SELECT senha_hash FROM cliente WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo' LIMIT 1") && !str_contains($apiCentral, "cliente_auth']['tem_senha"), 'primeira senha: só sessão de cliente (usuário interno intocado); sem id_cliente é bloqueado; estado lido do banco com isolamento por empresa');
falsoVerificar(str_contains($apiCentral, "&& !in_array(\$rota, \$rotasPublicasSemBloqueioJuridico, true)") || str_contains($apiCentral, "if (!in_array(\$rota, \$rotasPublicasSemBloqueioJuridico, true)\n    && !in_array(\$rota, \$rotasRegularizacaoPrimeiroAcessoCliente, true)) {"), 'primeira senha: rotas públicas de login/OTP ficam fora do bloqueio');
falsoVerificar(str_contains($jsGuard, 'const CODIGOS_PRIMEIRO_ACESSO_CLIENTE = new Set(["CLIENT_PROFILE_REQUIRED", "CLIENT_PASSWORD_REQUIRED"]);') && str_contains($jsGuard, 'if (resposta.status === 403) {') && str_contains($jsGuard, 'encaminharAoPerfilDoCliente();') && str_contains($jsGuard, 'window.__AMAGENDA_PERFIL_ENCAMINHADO__ = true;') && str_contains($jsGuard, 'amagenda:cliente-senha-atualizada'), 'guard: 403 de primeiro acesso leva ao perfil uma única vez (sem loop) e recarrega ao criar a primeira senha');
$epContatos = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/backend/cliente/contatos_cliente.php'));
$posUnif = (int)strpos($epContatos, "if (in_array(\$acao, ['unificar_solicitar', 'unificar_previa', 'unificar_confirmar'], true)) {");
$posSenhaUnif = (int)strpos($epContatos, "'code' => 'CLIENT_UNIFY_PASSWORD_REQUIRED'");
$posReauth = (int)strpos($epContatos, "'code' => 'CLIENT_UNIFY_REAUTH_REQUIRED'");
$posCsrf = (int)strpos($epContatos, 'csrfValidarSessao();');
falsoVerificar($posUnif > 0 && $posSenhaUnif > $posUnif && $posSenhaUnif < $posReauth && $posCsrf < $posUnif && str_contains($epContatos, "if (trim((string)(\$cliente['senha_hash'] ?? '')) === '') {") && (bool)preg_match("/CLIENT_UNIFY_PASSWORD_REQUIRED'[^;]*\], 403\);/s", $epContatos), 'unificação: sem senha cadastrada as ações unificar_* respondem 403 CLIENT_UNIFY_PASSWORD_REQUIRED no backend (depois do CSRF, antes da autenticação recente, OTP, nonce e consentimento)');
falsoVerificar(substr_count($epContatos, 'CLIENT_UNIFY_PASSWORD_REQUIRED') === 1 && $posSenhaUnif < (int)strpos($epContatos, "if (\$acao === 'unificar_solicitar') {") && str_contains($epContatos, "if (\$acao === 'solicitar_verificacao') {") && str_contains($epContatos, "if (\$acao === 'solicitar_adicao_telefone') {") && str_contains($epContatos, "if (\$acao === 'validar') {") && str_contains($epContatos, "'CLIENT_UNIFY_PROOF_REQUIRED'") && str_contains($epContatos, "'CLIENT_UNIFY_CONSENT_REQUIRED'") && str_contains($epContatos, 'clienteUnifLoginRecente('), 'unificação: restrição só nas ações unificar_*; verificação/adição de contatos e controles de OTP, nonce, consentimento e login recente preservados');
falsoVerificar(str_contains($jsSino, 'titulo: "Complete seu cadastro"') && str_contains($jsSino, 'mensagem: "Algumas informações do seu perfil ainda estão pendentes."') && str_contains($jsSino, '"Completar cadastro"'), 'sino: notificação "Complete seu cadastro" com a ação Completar cadastro');
falsoVerificar(str_contains($jsSino, 'const cadastro = itemCadastro();') && str_contains($jsSino, 'return cadastro ? [cadastro, ...estado.itens] : estado.itens;') && str_contains($jsSino, 'const itens = itensExibidos();') && str_contains($jsSino, 'if (itens.length === 0) {'), 'sino: a notificação do cadastro entra na lista e "Nenhuma notificação" só aparece sem nenhuma');
falsoVerificar(str_contains($jsSino, 'if (item.local === true) {') && str_contains($jsSino, 'window.AbrirCadastroCliente') && str_contains($jsCad, 'window.AbrirCadastroCliente = abrirFormularioCadastro;'), 'sino: o clique abre o formulário cadastral existente, sem marcar como lida');
falsoVerificar(str_contains($jsSino, "if (!clienteCompleto()) {\n      estado.itens = [];\n      renderizar();\n      return;\n    }") && str_contains($jsSino, '!ehCliente()'), 'sino: cadastro obrigatório pendente mostra só a notificação local, sem chamar as rotas de notificações (sem 403)');
falsoVerificar(str_contains($jsSino, 'id_notificacao: "cadastro-pendente"') && !str_contains($jsSino, 'cliente/notificacoes/criar'), 'sino: notificação local única, não gravada (sem duplicidade ao atualizar ou logar de novo)');
falsoVerificar(str_contains($cssCliente, '.notificacao-item.notificacao-item--cadastro-obrigatorio'), 'sino: destaque quando faltam dados obrigatórios');
falsoVerificar($cacheOficial($htmlPerfil, 'salvar-dados-cadastrais.js') && $cacheOficial($htmlPerfil, 'notificacoes-cliente.js') && $cacheOficial($htmlPerfil, 'cliente-web.css'), 'cadastro: parâmetros de cache dos arquivos alterados atualizados');
$comumAg = (string)file_get_contents($raiz . '/backend/cliente_agendamento/_comum.php');
$confirmarAg = (string)file_get_contents($raiz . '/backend/cliente_agendamento/confirmar-agendamento.php');
falsoVerificar(str_contains($comumAg, "(int)(\$cliente['cadastro_completo'] ?? 0) !== 1") && str_contains($confirmarAg, 'clienteAgendamentoContexto($conexao, true)'), 'cadastro: agendar continua exigindo cadastro completo no backend (não depende do modal)');

// ---- 10. Meu Perfil: informações pessoais e segurança da conta ---------------------------------------
$phpPerfil = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/backend/cliente_perfil/buscar_foto_perfil.php'));
$jsPerfil = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/cliente_perfil/buscar_foto_perfil.js'));
foreach (['perfilCpfMascarado', 'perfilTelefoneExibicao'] as $funcao) {
    if (preg_match('/^function ' . $funcao . '\(.*?^\}$/ms', $phpPerfil, $m) === 1 && !function_exists($funcao)) {
        eval($m[0]);
    }
}
falsoVerificar(function_exists('perfilCpfMascarado') && perfilCpfMascarado('123.456.789-09') === '***.***.***-09' && perfilCpfMascarado('') === '' && perfilCpfMascarado('123') === '', 'perfil: CPF só sai mascarado (dois últimos dígitos); vazio ou inválido não é exibido');
falsoVerificar(function_exists('perfilTelefoneExibicao') && perfilTelefoneExibicao('+5538998969407') === '(38) 99896-9407' && perfilTelefoneExibicao('3833221100') === '(38) 3322-1100' && perfilTelefoneExibicao('') === '', 'perfil: telefone exibido no formato brasileiro');
$selectPerfil = substr($phpPerfil, (int)strpos($phpPerfil, 'SELECT'), 520);
falsoVerificar(str_contains($phpPerfil, '$sessao = exigirSessaoCliente();') && str_contains($phpPerfil, 'buscarClienteDaSessao($conexao, $sessao)')
    && str_contains($selectPerfil, 'WHERE id_cliente = ?') && str_contains($selectPerfil, 'AND id_empresa = ?') && !str_contains($phpPerfil, '$_GET') && !str_contains($phpPerfil, '$_POST'), 'perfil: dados só do cliente da sessão e da empresa da sessão (nenhum identificador vindo do frontend)');
$blocoInfo = substr($phpPerfil, (int)strpos($phpPerfil, "'informacoes' => ["), 900);
falsoVerificar(!str_contains($blocoInfo, 'senha') && !str_contains($blocoInfo, 'hash') && !str_contains($blocoInfo, "'cpf' => trim") && str_contains($blocoInfo, "perfilCpfMascarado("), 'perfil: sem senha, hash ou CPF completo na resposta');
falsoVerificar(str_contains($jsPerfil, 'preencherInformacoes(dados.informacoes);') && str_contains($jsPerfil, '"Não informado"') && str_contains($jsPerfil, 'querySelectorAll("[data-perfil-info]")'), 'perfil: campos vazios exibem "Não informado"; JS compartilhado segue seguro em páginas sem esses campos');
falsoVerificar(str_contains($htmlPerfil, 'Informações pessoais') && str_contains($htmlPerfil, 'Segurança da conta')
    && str_contains($htmlPerfil, 'data-perfil-info="nome"') && str_contains($htmlPerfil, 'data-perfil-info="email"') && str_contains($htmlPerfil, 'data-perfil-info="telefone"'), 'perfil: seções Informações pessoais e Segurança da conta com nome, e-mail e telefone');
falsoVerificar(str_contains($htmlPerfil, 'id="perfilNomeUsuario"') && str_contains($htmlPerfil, 'for="perfil_foto"') && str_contains($htmlPerfil, 'id="perfil_foto"') && str_contains($htmlPerfil, 'accept="image/jpeg,image/png,image/webp"'), 'perfil: foto, nome do cabeçalho e Alterar foto preservados');
falsoVerificar(substr_count($htmlPerfil, 'id="formAlterarSenha"') === 1 && str_contains($htmlPerfil, 'id="senha_atual"') && str_contains($htmlPerfil, 'id="nova_senha"') && str_contains($htmlPerfil, 'id="confirmar_senha"')
    && str_contains($htmlPerfil, 'id="campoSenhaAtual"') && str_contains($htmlPerfil, '<button type="submit" class="botao-geral">'), 'perfil: formulário de senha, campos e botão Salvar preservados');
falsoVerificar(substr_count($htmlPerfil, 'id="btnSair"') === 1 && strpos($htmlPerfil, 'id="btnSair"') > strpos($htmlPerfil, 'id="formAlterarSenha"'), 'perfil: botão Sair preservado (mesmo id usado por deslogar.js)');
$cssAgenda = (string)file_get_contents($raiz . '/public/css/agenda/agenda-web.css');
falsoVerificar(str_contains($cssAgenda, '.perfil-info-lista') && str_contains($cssAgenda, '@media (max-width: 560px)') && str_contains($cssAgenda, '.perfil-cartao-topo') && str_contains($cssAgenda, '.perfil-senha-olho') && str_contains($cssAgenda, '.perfil-acesso') && !str_contains($cssCliente, '.perfil-info-lista'), 'perfil: estilos responsivos das seções no CSS compartilhado por todas as páginas (sem duplicar)');
falsoVerificar($cacheOficial($htmlPerfil, 'buscar_foto_perfil.js') && $cacheOficial($htmlPerfil, 'cliente-web.css'), 'perfil: parâmetros de cache atualizados');

// ---- 11. Meu Perfil global: todas as páginas, cada tipo de sessão com o seu endpoint -------------------
$paginasPerfil = [
    'public/views/agenda.html' => 'interno',
    'public/views/chat/chat-interno.html' => 'interno',
    'public/views/painel-administrativo/painel-administrativo.html' => 'interno',
    'public/views/super-admin/painel-super-admin.html' => 'interno',
    'public/views/cliente-perfil.html' => 'cliente',
    'public/views/cliente-agendamento.html' => 'cliente',
];
$phpInterno = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/backend/perfil_usuario/buscar_foto_perfil.php'));
$jsInterno = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/perfil-usuario/buscar_foto_perfil.js'));
$bloco = static function (string $php): string { return substr($php, (int)strpos($php, "'informacoes' => ["), 900); };
foreach ($paginasPerfil as $arq => $tipo) {
    $h = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/' . $arq));
    $nome = basename($arq);
    falsoVerificar(substr_count($h, 'id="modalPerfilUsuario"') === 1 && str_contains($h, 'Informações pessoais') && str_contains($h, 'Segurança da conta') && str_contains($h, 'data-perfil-info="perfil"'), "perfil global {$nome}: identificação com perfil de acesso e seções Informações pessoais e Segurança da conta");
    falsoVerificar(substr_count($h, 'id="formAlterarSenha"') === 1 && str_contains($h, 'id="senha_atual"') && str_contains($h, 'id="nova_senha"') && str_contains($h, 'id="confirmar_senha"') && str_contains($h, 'id="perfil_foto"') && substr_count($h, 'id="btnSair"') === 1 && strpos($h, 'id="btnSair"') > strpos($h, 'id="formAlterarSenha"') && strpos($h, 'id="btnSair"') < strpos($h, '</form>', (int)strpos($h, 'id="formAlterarSenha"')), "perfil global {$nome}: senha, foto e Sair preservados (Sair na linha de ações do formulário, como no original)");
    falsoVerificar(($cacheOficial($h, 'agenda-web.css') || ($nome === 'chat-interno.html' && str_contains($h, 'agenda-web.css?v=20261110_9'))), "perfil global {$nome}: CSS compartilhado com parâmetro de cache novo");
    preg_match_all('/data-perfil-info="([a-z_]+)"/', $h, $chaves);
    $fonte = $bloco($tipo === 'interno' ? $phpInterno : $phpPerfil);
    $faltando = array_filter(array_unique($chaves[1]), static fn(string $k): bool => !str_contains($fonte, "'{$k}' =>"));
    falsoVerificar($faltando === [], "perfil global {$nome}: todo campo da tela existe na resposta do endpoint da sessão (" . ($tipo === 'interno' ? 'interno' : 'cliente') . ')');
    $jsPag = $tipo === 'interno' ? 'perfil-usuario/buscar_foto_perfil.js' : 'cliente_perfil/buscar_foto_perfil.js';
    falsoVerificar($cacheOficial($h, $jsPag) || ($nome === 'chat-interno.html' && str_contains($h, $jsPag . '?v=20261110_2')), "perfil global {$nome}: JS compartilhado do tipo de sessão com parâmetro de cache novo");
}
falsoVerificar(str_contains($jsInterno, 'applyInformacoes(json?.data?.informacoes);') && str_contains($jsInterno, 'applyInformacoes(null, true);') && str_contains($jsInterno, 'data-perfil-info-opcional'), 'perfil interno: JS preenche as informações, trata falha e esconde itens opcionais');
falsoVerificar(str_contains($phpInterno, "\$auth = \$_SESSION['auth'] ?? null;") && !str_contains($phpInterno, '$_GET') && !str_contains($phpInterno, '$_POST') && str_contains($phpInterno, 'WHERE id_usuario = ?'), 'perfil interno: usuário somente da sessão autenticada (nenhum identificador do frontend)');
$infoInterno = $bloco($phpInterno);
falsoVerificar(!str_contains($infoInterno, 'senha') && !str_contains($infoInterno, 'hash') && !str_contains($infoInterno, 'token'), 'perfil interno: sem senha, hash ou token nas informações');
falsoVerificar(str_contains($phpInterno, "'Proprietário'") && str_contains($phpInterno, "'Profissional'") && str_contains($phpInterno, "'Recepção'") && str_contains($phpInterno, "=== 'super_admin'") && str_contains($phpInterno, "'Super Admin'") && str_contains($phpPerfil, "'perfil' => 'Cliente'"), 'perfil: Proprietário, Profissional, Recepção, Super Admin e Cliente identificados pela sessão (não pela especialidade)');
falsoVerificar(!str_contains($infoInterno, 'especialidade') && !str_contains($phpInterno, 'empresa_usuario.especialidade'), 'perfil interno: perfil de acesso nunca confundido com especialidade ou cargo');

// ---- 12. Refinamento visual do Meu Perfil (ações, requisitos recolhíveis, responsividade) -------------
$indJs = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/componentes/indicador-senha.js'));
$indCss = (string)file_get_contents($raiz . '/public/css/componentes/indicador-senha.css');
$senhaIntJs = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/perfil-usuario/alterar_senha_perfil.js'));
foreach ($paginasPerfil as $arq => $tipo) {
    $h = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/' . $arq));
    $nome = basename($arq);
    $form = substr($h, (int)strpos($h, 'id="formAlterarSenha"'), 2600);
    $form = substr($form, 0, (int)strpos($form, '</form>'));
    falsoVerificar(str_contains($form, 'class="perfil-acoes"') && str_contains($form, 'type="submit" class="botao-geral"') && str_contains($form, 'class="botao-geral destaque" type="button" title="Sair do sistema" id="btnSair"') && !str_contains($h, 'perfil-rodape'), "perfil visual {$nome}: Salvar e Sair na mesma linha de ações, dentro do formulário (Sair não fica isolado)");
}
falsoVerificar(str_contains($indJs, 'recolhivel: o.recolhivel === true') && str_contains($indJs, 'aria-expanded') && str_contains($indJs, 'aria-controls') && str_contains($indJs, '"Requisitos da senha"'), 'indicador: modo recolhível opt-in (padrão desligado) com botão acessível');
falsoVerificar(str_contains($indJs, 'if (this.alternar && !vazio && !this.manual && !this.aberto) this.definirAberto(true);') && str_contains($indJs, 'if (this.alternar) { this.manual = false; this.definirAberto(false); }'), 'indicador: abre ao começar a digitar, respeita o recolhimento manual e recolhe ao limpar');
falsoVerificar(str_contains($indJs, 'var MIN_CARACTERES = 8;') && str_contains($indJs, 'var MAX_BYTES = 72;') && str_contains($indJs, '/[\p{P}\p{S}]/u'), 'indicador: regras de senha inalteradas');
falsoVerificar(str_contains($senhaIntJs, 'recolhivel: true,') && !str_contains($senhaIntJs, 'opcional: true'), 'perfil interno: só o Meu Perfil liga o modo recolhível');
$outros = 0;
foreach (['public/js', 'public/views'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $arqObj) {
        if (!in_array($arqObj->getExtension(), ['js', 'html', 'php'], true) || str_contains($arqObj->getPathname(), 'indicador-senha.js') || str_contains($arqObj->getPathname(), 'perfil-usuario' . DIRECTORY_SEPARATOR . 'alterar_senha_perfil.js')) continue;
        if (str_contains((string)file_get_contents($arqObj->getPathname()), 'recolhivel')) $outros++;
    }
}
falsoVerificar($outros === 0, 'indicador: nenhum outro uso do componente foi alterado (cadastros e edições seguem iguais)');
falsoVerificar(str_contains($indCss, '.ind-senha__alternar') && str_contains($indCss, '.ind-senha__conteudo[hidden]') && str_contains($indCss, 'aria-expanded="true"'), 'indicador: estilos do botão e do bloco recolhível');
$cssAg = (string)file_get_contents($raiz . '/public/css/agenda/agenda-web.css');
falsoVerificar(str_contains($cssAg, '#modalPerfilUsuario .perfil-nome-usuario') && str_contains($cssAg, '-webkit-line-clamp: 2') && str_contains($cssAg, 'overflow-wrap: anywhere') && str_contains($cssAg, 'grid-template-columns: repeat(2, minmax(0, 1fr))') && str_contains($cssAg, '.perfil-acoes {') && str_contains($cssAg, 'justify-content: flex-end') && str_contains($cssAg, '.perfil-avatar-camera') && str_contains($cssAg, '.perfil-info-icone'), 'perfil visual: nome longo em até 2 linhas, grade de 2 colunas e ações alinhadas (desktop)');
falsoVerificar(str_contains($cssAg, '.perfil-info-lista { grid-template-columns: minmax(0, 1fr); }') && str_contains($cssAg, '.perfil-acoes { flex-direction: column; align-items: stretch; }') && str_contains($cssAg, 'min-height: 46px'), 'perfil visual: uma coluna e botões largos no mobile');
falsoVerificar(str_contains($cssAg, '.modal-geral .modal-topo {') && str_contains($cssAg, 'overflow-y: auto;') && str_contains($cssAg, 'overflow-x: hidden;'), 'perfil visual: cabeçalho do modal fixo e rolagem só no corpo, sem barra horizontal');

// ---- 13. Meu Perfil no padrão do modelo visual aprovado (todas as páginas) -----------------------------
$senhaCliJs = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/cliente_perfil/alterar_senha_perfil.js'));
foreach ($paginasPerfil as $arq => $tipo) {
    $h = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/' . $arq));
    $nome = basename($arq);
    falsoVerificar(str_contains($h, '<h2 id="tituloPerfilUsuario"><i class="fa-solid fa-user"') && str_contains($h, 'class="perfil-cabecalho"') && str_contains($h, 'class="perfil-avatar-camera"') && str_contains($h, 'Conta vinculada à empresa:') && str_contains($h, 'data-perfil-grupo'), "perfil modelo {$nome}: título com ícone e identificação (foto com câmera, nome, perfil de acesso e empresa)");
    falsoVerificar(substr_count($h, 'class="perfil-cartao perfil-cartao--') === 2 && str_contains($h, 'class="perfil-info-icone"') && str_contains($h, 'fa-shield-halved') && !str_contains($h, 'Salvar</button>x'), "perfil modelo {$nome}: cartões Informações pessoais e Segurança da conta com ícones");
    falsoVerificar(str_contains($h, 'placeholder="Digite sua senha atual"') && str_contains($h, 'placeholder="Digite a nova senha"') && str_contains($h, 'placeholder="Confirme a nova senha"') && str_contains($h, 'name="senha_atual"') && str_contains($h, 'name="confirmar_senha"'), "perfil modelo {$nome}: campos de senha com rótulo acima e texto de ajuda (nomes e ids preservados)");
    falsoVerificar(strpos($h, 'perfil-acoes') > strpos($h, 'id="nova_senha"') && strpos($h, 'type="submit" class="botao-geral"') < strpos($h, 'id="btnSair"'), "perfil modelo {$nome}: Salvar nova senha e Sair juntos, à direita");
}
foreach (['perfil interno' => $senhaIntJs, 'perfil cliente' => $senhaCliJs] as $rotulo => $codigo) {
    falsoVerificar(str_contains($codigo, 'function instalarOlhoDeSenha()') && str_contains($codigo, 'aria-pressed') && str_contains($codigo, 'campo.type = mostrar ? "text" : "password"') && str_contains($codigo, 'addEventListener("reset"'), "{$rotulo}: botão de mostrar/ocultar senha acessível, só visual, que volta a ocultar no reset");
}
falsoVerificar(str_contains($phpPerfil, "'empresa' => \$empresaNome") && str_contains($phpPerfil, 'SELECT nome FROM empresa WHERE id_empresa = ?'), 'perfil cliente: empresa da sessão (somente o nome) para "Conta vinculada à empresa"');
falsoVerificar(str_contains($indJs, 'ind-senha__chave') && str_contains($indJs, 'TEXTOS.requisitosSenha') && str_contains($indCss, '.ind-senha__chave'), 'indicador: cabeçalho "Requisitos da senha" com ícone e seta');
falsoVerificar(str_contains($cssAg, '#modalPerfilUsuario .modal-corpo { padding: 22px 24px 24px; display: flex; flex-direction: column; gap: 18px; }') && str_contains($cssAg, '#modalPerfilUsuario .modal-corpo > * { flex: 0 0 auto; }'), 'perfil modelo: blocos do corpo não encolhem (o corpo rola até os botões, também no mobile)');
falsoVerificar(str_contains($cssAg, 'zoom: .85;') && str_contains($cssAg, '@media (min-width: 861px)') && str_contains($cssAg, 'width: min(1180px, calc((100vw - 48px) / .85));') && str_contains($cssAg, 'max-height: calc((100dvh - 40px) / .85);') && str_contains($cssAg, '#modalPerfilUsuario .modal-conteudo { width: min(100%, 720px); max-height: calc(100dvh - 16px); }'), 'perfil modelo: desktop em escala de 85% (largura e altura compensadas) e, em telas pequenas, modal na largura útil');
falsoVerificar(str_contains($cssAg, '#modalPerfilUsuario .modal-conteudo { width: min(1180px, calc(100vw - 48px)); }') && str_contains($cssAg, '#modalPerfilUsuario #formAlterarSenha:has(.ind-senha)') && str_contains($cssAg, '@media (max-width: 860px)'), 'perfil modelo: modal largo, campos e requisitos lado a lado e recolhimento para uma coluna em telas menores');

// ---- 14. Modo de definição/redefinição obrigatória de senha (apresentação; regras e bloqueio inalterados) --------
$menuJs = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/menu-lateral/menu-lateral.js'));
$notifJs = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/notificacoes/notificacoes.js'));
foreach ($paginasPerfil as $arq => $tipo) {
    $h = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/' . $arq));
    $nome = basename($arq);
    falsoVerificar(str_contains($h, '<span class="perfil-titulo-texto">Meu Perfil</span>') && str_contains($h, 'class="perfil-modo-obrigatorio"') && str_contains($h, 'data-perfil-modo-motivo') && str_contains($h, 'perfil-cartao--informacoes') && str_contains($h, 'perfil-cartao--seguranca'), "senha obrigatória {$nome}: título contextual, motivo e ganchos para ocultar o que não é da operação");
    falsoVerificar(strpos($h, 'menu-lateral/menu-lateral.js', (int)strpos($h, '</section>')) !== false || str_contains($h, 'menu-lateral/menu-lateral.js?v=20261110_1'), "senha obrigatória {$nome}: componente que aplica o modo carregado com parâmetro novo");
}
falsoVerificar(str_contains($menuJs, 'window.PerfilModoSenha = Object.freeze({') && str_contains($menuJs, 'modal.dataset.modoSenha = "obrigatorio";') && str_contains($menuJs, 'delete modal.dataset.modoSenha;') && str_contains($menuJs, 'amagenda:perfil-modo-senha'), 'senha obrigatória: PerfilModoSenha só alterna atributo, título e motivo (sem tocar em regras de senha)');
falsoVerificar(!str_contains(substr($menuJs, (int)strpos($menuJs, 'window.PerfilModoSenha'), 1400), 'fetch(') && !str_contains(substr($menuJs, (int)strpos($menuJs, 'window.PerfilModoSenha'), 1400), 'localStorage'), 'senha obrigatória: o modo não depende de URL, armazenamento local nem requisição própria');
falsoVerificar(str_contains($notifJs, 'window.PerfilModoSenha?.definir(true, {') && str_contains($notifJs, 'titulo: "Atualizar senha"') && str_contains($notifJs, 'window.PerfilModoSenha?.definir(false);') && str_contains($notifJs, 'document.body.classList.add("senha-temporaria-bloqueada");') && str_contains($notifJs, 'amagenda:senha-temporaria-expirada'), 'senha obrigatória (telas internas): modo ativado só pelo bloqueio já vindo do backend (senha temporária expirada) e removido ao liberar');
falsoVerificar(str_contains($senhaCliJs, 'titulo: "Definir nova senha",') && substr_count($senhaCliJs, 'titulo: "Definir nova senha",') === 2 && str_contains($senhaCliJs, 'window.PerfilModoSenha.definir(') && str_contains($senhaCliJs, 'configuracao.bloquearModal') && str_contains($senhaCliJs, 'exigirSenhaAtual: false,') && str_contains($senhaCliJs, 'exigirSenhaAtual: true,'), 'senha obrigatória (cliente): primeira senha e recuperação em modo obrigatório; alteração voluntária continua normal; exigência da senha atual preservada');
falsoVerificar(str_contains($senhaCliJs, 'dados.recuperacao_senha_autorizada === true') && str_contains($senhaCliJs, 'dados.tem_senha === false'), 'senha obrigatória (cliente): modo vem do estado seguro do perfil (backend), não de parâmetro de URL');
falsoVerificar(str_contains($senhaIntJs, 'amagenda:perfil-modo-senha') && str_contains($senhaIntJs, 'indicadorSenha?.expandir?.()') && str_contains($indJs, 'expandir: function ()'), 'senha obrigatória (interno): requisitos abertos ao entrar no modo');
foreach ([
    '#modalPerfilUsuario[data-modo-senha="obrigatorio"] .perfil-cabecalho,',
    '#modalPerfilUsuario[data-modo-senha="obrigatorio"] .perfil-cartao--informacoes,',
    '#modalPerfilUsuario[data-modo-senha="obrigatorio"] .perfil-cartao--seguranca > .perfil-cartao-topo,',
    '#modalPerfilUsuario[data-modo-senha="obrigatorio"] .modal-fechar { display: none; }',
    '.perfil-cartao--seguranca { border: 2px solid var(--am-marca, #1163dd); }',
    '#modalPerfilUsuario[data-modo-senha="obrigatorio"] .perfil-acoes #btnSair {',
    '.perfil-acoes button[type="submit"] {',
] as $trecho) {
    falsoVerificar(str_contains($cssAg, $trecho), 'senha obrigatória CSS: ' . substr($trecho, 0, 90));
}
falsoVerificar(str_contains($cssAg, '#modalPerfilUsuario[data-modo-senha="obrigatorio"] .modal-conteudo { width: min(620px, calc(100vw - 48px)); }') && str_contains($cssAg, '#modalPerfilUsuario[data-modo-senha="obrigatorio"] .perfil-acoes { flex-direction: column; align-items: stretch; }'), 'senha obrigatória CSS: modal compacto no desktop e uma coluna com botões largos no mobile');
$backInterno = (string)file_get_contents($raiz . '/backend/perfil_usuario/alterar_senha_perfil.php');
$backCliente = (string)file_get_contents($raiz . '/backend/cliente_perfil/alterar_senha_perfil.php');
falsoVerificar(str_contains($backInterno, 'password_verify($senhaAtual, $senhaHashBanco)') && str_contains($backInterno, 'csrfValidarSessao();') && str_contains($backCliente, '$exigirSenhaAtual = $temSenha && !$recuperacaoAutorizada;') && str_contains($backCliente, 'csrfValidarSessao();'), 'senha obrigatória: regras do backend intactas (senha atual no interno; senha atual quando há senha e não há recuperação no cliente; CSRF)');

// ---- 15. Campos do modo obrigatório: Senha atual oculta de verdade, rótulo "Senha temporária", autocomplete -----
foreach ($paginasPerfil as $arq => $tipo) {
    $h = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/' . $arq));
    $nome = basename($arq);
    falsoVerificar(str_contains($h, 'id="senha_atual" placeholder="Digite sua senha atual" autocomplete="current-password"') && str_contains($h, 'id="nova_senha" placeholder="Digite a nova senha" autocomplete="new-password"') && str_contains($h, 'id="confirmar_senha" placeholder="Confirme a nova senha" autocomplete="new-password"'), "campos de senha {$nome}: autocomplete correto (senha atual = current-password; nova e confirmação = new-password)");
}
falsoVerificar(str_contains($cssAg, '#modalPerfilUsuario .modal-campo[hidden] { display: none; }'), 'causa da Senha atual visível: .modal-campo { display: flex } vencia o atributo hidden; regra do Meu Perfil faz o hidden valer');
falsoVerificar(str_contains($senhaCliJs, 'senhaAtual.disabled =') && str_contains($senhaCliJs, '!exigirSenhaAtual;') && str_contains($senhaCliJs, "senhaAtual.value = \"\";") && str_contains($senhaCliJs, 'blocoSenhaAtual.hidden =') && str_contains($senhaCliJs, 'senhaAtual.required =') , 'cliente: sem exigência, a Senha atual fica oculta, desabilitada, vazia e não obrigatória; com exigência volta a aparecer');
falsoVerificar(str_contains($senhaCliJs, "senha_atual:\n        exigirSenhaAtual\n          ? atual\n          : \"\",") && str_contains($senhaCliJs, 'exigirSenhaAtual &&'), 'cliente: campo oculto não entra na validação nem é enviado (payload só leva a senha atual quando exigida)');
falsoVerificar(str_contains($menuJs, 'rotuloSenhaAtual = ""') && str_contains($menuJs, 'label[for="senha_atual"]') && str_contains($menuJs, ': "Senha atual";') && str_contains($menuJs, ': "Digite sua senha atual";'), 'modo obrigatório: rótulo e texto de ajuda da senha atual trocam só dentro do modo e voltam ao padrão ao sair');
falsoVerificar(str_contains($notifJs, 'rotuloSenhaAtual: "Senha temporária"') && str_contains($notifJs, 'placeholderSenhaAtual: "Digite a senha temporária recebida"') && str_contains($notifJs, 'informe no campo “Senha temporária” a senha temporária que você recebeu'), 'senha temporária expirada (interno): campo exigido pelo backend mantido, com rótulo "Senha temporária" e explicação');
$bloqueioInterno = substr($notifJs, (int)strpos($notifJs, 'function ativarBloqueioSenha()'), 1300);
falsoVerificar(!str_contains($bloqueioInterno, 'senha_atual') || !str_contains($bloqueioInterno, 'hidden'), 'senha temporária expirada (interno): nenhum código oculta ou dispensa a senha atual');

falsoRelatorio();
