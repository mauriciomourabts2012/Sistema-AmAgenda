<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TESTE DE INTEGRAÇÃO COM O MYSQL REAL - EXECUÇÃO MANUAL (NÃO AUTOMÁTICA)
|--------------------------------------------------------------------------
|
| Exercita smsOtpSolicitar/smsOtpValidar/smsEnviarOtp/smsAplicarRelatorio contra
| as tabelas reais otp_desafio, mensagem_envio e cliente, SEM Infobip: o transporte
| é falso e nenhum SMS é enviado. Não usa infobip.php nem credenciais reais
| (chaves HMAC aleatórias só da execução).
|
| ESCRITAS (somente nesta execução, todas com registros fictícios):
|   - 3 clientes fictícios (nome "TESTE-SMS-<marca>") nas empresas A e B informadas;
|   - linhas em otp_desafio e mensagem_envio geradas pelo próprio teste.
| Ao final (inclusive em falha), o teste apaga SOMENTE os ids que ele criou
| (mensagem_envio -> otp_desafio -> cliente). Nenhuma outra linha é tocada.
|
| COMO EXECUTAR (Ubuntu, na raiz do projeto, como o responsável):
|   AMAGENDA_TESTE_MYSQL_REAL=SIM_REGISTROS_FICTICIOS \
|   AMAGENDA_TESTE_EMPRESA_A=34 AMAGENDA_TESTE_EMPRESA_B=35 \
|   php tools/tests/sms-otp-mysql-real.manual.php
|
| Sem a variável de confirmação, o script apenas imprime este aviso e sai.
|
*/

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (getenv('AMAGENDA_TESTE_MYSQL_REAL') !== 'SIM_REGISTROS_FICTICIOS') {
    echo "Teste manual contra o MySQL REAL (escreve e apaga registros fictícios).\n"
        . "Para executar, defina AMAGENDA_TESTE_MYSQL_REAL=SIM_REGISTROS_FICTICIOS,\n"
        . "AMAGENDA_TESTE_EMPRESA_A e AMAGENDA_TESTE_EMPRESA_B (empresas ativas e distintas).\n"
        . "Nada foi executado.\n";
    exit(2);
}

$empresaA = (int)getenv('AMAGENDA_TESTE_EMPRESA_A');
$empresaB = (int)getenv('AMAGENDA_TESTE_EMPRESA_B');
if ($empresaA <= 0 || $empresaB <= 0 || $empresaA === $empresaB) {
    fwrite(STDERR, "Informe duas empresas ativas e distintas.\n");
    exit(2);
}

$total = 0;
$falhas = [];
require_once __DIR__ . '/../../backend/_config/conexao.php';
require_once __DIR__ . '/../../backend/_servicos/sms_otp.php';

/** @var mysqli $conexao */
$conexaoLock = new mysqli($dbServidor, $dbUsuario, $dbSenha, $dbBanco, $dbPorta);
$conexaoLock->set_charset('utf8mb4');

function t(bool $condicao, string $descricao): void
{
    global $total, $falhas;
    $total++;
    echo ($condicao ? '  ok  ' : ' FALHA ') . $descricao . "\n";
    if (!$condicao) {
        $falhas[] = $descricao;
    }
}

function q(mysqli $c, string $sql, string $tipos = '', mixed ...$v): array
{
    return smsConsultar($c, $sql, $tipos, ...$v);
}

function w(mysqli $c, string $sql, string $tipos = '', mixed ...$v): int
{
    return smsExecutar($c, $sql, $tipos, ...$v);
}

$marca = 'TESTE-SMS-' . bin2hex(random_bytes(4));
$idsClientes = [];
$idsDesafios = [];

function limpar(): void
{
    global $conexao, $idsClientes, $idsDesafios;
    if ($idsDesafios !== []) {
        $in = implode(',', array_map('intval', array_unique($idsDesafios)));
        w($conexao, "DELETE FROM mensagem_envio WHERE id_otp_desafio IN ($in)");
        w($conexao, "DELETE FROM otp_desafio WHERE id_otp_desafio IN ($in)");
    }
    if ($idsClientes !== []) {
        $in = implode(',', array_map('intval', $idsClientes));
        // Desafios criados por este teste para estes clientes, caso algum id não tenha sido capturado.
        w($conexao, "DELETE m FROM mensagem_envio m JOIN otp_desafio o ON o.id_otp_desafio = m.id_otp_desafio WHERE o.id_cliente IN ($in)");
        w($conexao, "DELETE FROM otp_desafio WHERE id_cliente IN ($in)");
        w($conexao, "DELETE FROM cliente WHERE id_cliente IN ($in) AND nome_completo = '" . $conexao->real_escape_string($GLOBALS['marca']) . "'");
    }
}
register_shutdown_function('limpar');

// Configuração isolada do teste: chaves aleatórias, transporte falso, nenhum segredo real.
$config = [
    'habilitado' => true, 'ambiente' => 'teste', 'base_url' => 'https://teste.api.infobip.com',
    'api_key' => bin2hex(random_bytes(16)), 'sender' => 'AmAgenda', 'timeout_conexao' => 5, 'timeout_total' => 15,
    'hmac_key' => bin2hex(random_bytes(24)), 'webhook_secret' => bin2hex(random_bytes(24)),
    'webhook_url' => 'https://exemplo.test/public/api/api_central.php?path=webhooks/infobip',
];
$carga = ['ok' => true, 'habilitado' => true, 'erros' => [], 'config' => $config];
smsOtpCarga($carga);
$_SESSION = [];

$ultimoCodigo = null;
$chamadasTransporte = 0;
$transporte = static function (array $req) use (&$ultimoCodigo, &$chamadasTransporte): array {
    $chamadasTransporte++;
    $corpo = json_decode($req['body'], true);
    preg_match('/\b(\d{6})\b/', (string)$corpo['messages'][0]['content']['text'], $m);
    $ultimoCodigo = $m[1] ?? null;

    return ['http_status' => 200, 'errno' => 0, 'body' => json_encode(['bulkId' => 'b', 'messages' => [[
        'messageId' => $corpo['messages'][0]['destinations'][0]['messageId'],
        'status' => ['groupName' => 'PENDING', 'id' => 26, 'name' => 'PENDING_ACCEPTED'],
    ]]])];
};

echo "== Pré-condições\n";
$emp = q($conexao, 'SELECT id_empresa FROM empresa WHERE id_empresa IN (?, ?) AND status = \'ativo\'', 'ii', $empresaA, $empresaB);
t(count($emp) === 2, 'empresas A e B existem e estão ativas');
if (count($emp) !== 2) {
    exit(1);
}
foreach (['otp_desafio', 'mensagem_envio'] as $tabela) {
    t(q($conexao, 'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', $tabela)[0]['n'] == 1, "tabela $tabela existe");
}

// Semente fictícia.
$sem = static function (int $empresa, string $fone) use ($conexao, $marca, &$idsClientes): int {
    w($conexao, "INSERT INTO cliente (id_empresa, nome_completo, whatsapp_celular, status) VALUES (?, ?, ?, 'ativo')", 'iss', $empresa, $marca, $fone);
    $id = (int)q($conexao, 'SELECT LAST_INSERT_ID() AS id')[0]['id'];
    $idsClientes[] = $id;

    return $id;
};
$telA1 = '+5500999990001';
$telA2 = '+5500999990002';
$telB1 = '+5500999990003';
$cA1 = $sem($empresaA, '5500999990001');
$cA2 = $sem($empresaA, '5500999990002');
$cB1 = $sem($empresaB, '5500999990003');

$principal = static fn (int $empresa, int $cliente, string $tel, string $fin = 'acesso_cliente'): array => [
    'principal_tipo' => 'cliente', 'finalidade' => $fin, 'id_empresa' => $empresa, 'id_cliente' => $cliente, 'id_usuario' => 0,
    'telefone' => $tel, 'ip' => '198.51.100.7',
];
$solicitar = static function (array $p) use ($conexao, $transporte, &$idsDesafios): array {
    $r = smsOtpSolicitar($conexao, $p + ['enviar' => true], $transporte);
    if (isset($r['dados']['id_otp_desafio'])) {
        $idsDesafios[] = (int)$r['dados']['id_otp_desafio'];
    }

    return $r;
};
$validar = static fn (array $p, int $id, string $codigo, ?mysqli $c = null): array => smsOtpValidar($c ?? $GLOBALS['conexao'], $p + ['id_otp_desafio' => $id, 'codigo' => $codigo]);
$envelhecer = static fn (int $id) => w($conexao, 'UPDATE otp_desafio SET criado_em = criado_em - INTERVAL 40 SECOND WHERE id_otp_desafio = ?', 'i', $id);
$linha = static fn (int $id): array => q($conexao, 'SELECT * FROM otp_desafio WHERE id_otp_desafio = ?', 'i', $id)[0];

echo "== 1. Criação\n";
$p1 = $principal($empresaA, $cA1, $telA1);
$r = $solicitar($p1);
t($r['sucesso'] === true, 'solicitação aceita');
$id1 = (int)$r['dados']['id_otp_desafio'];
$o = $linha($id1);
t($o['status'] === 'ativo' && $o['principal_tipo'] === 'cliente' && (int)$o['id_empresa'] === $empresaA && (int)$o['id_cliente'] === $cA1, 'desafio ativo, empresa e cliente corretos');
t((int)q($conexao, 'SELECT TIMESTAMPDIFF(SECOND, NOW(6), expira_em) AS s FROM otp_desafio WHERE id_otp_desafio = ?', 'i', $id1)[0]['s'] >= 295, 'expira em ~5 minutos (relógio do banco)');
t(str_starts_with((string)$o['codigo_hash'], 'h1$') && !str_contains((string)$o['codigo_hash'], (string)$ultimoCodigo), 'somente hash do código gravado');
t(strlen((string)$o['chave_principal']) === 32 && strlen((string)$o['telefone_hash']) === 32 && strlen((string)$o['sessao_hash']) === 32, 'hashes binários de 32 bytes');
t((int)$o['max_tentativas'] === 5 && (int)$o['tentativas_validacao'] === 0, 'máximo de 5 tentativas, zero usadas');
$m = q($conexao, 'SELECT * FROM mensagem_envio WHERE id_otp_desafio = ?', 'i', $id1);
t(count($m) === 1 && $m[0]['status'] === 'aceita' && $m[0]['telefone_final4'] === '0001', 'mensagem_envio correlacionada, aceita (não entregue), só 4 últimos dígitos');
t($m[0]['chave_idempotencia'] === smsChaveIdempotencia($id1) && $m[0]['ambiente'] === 'teste', 'chave de idempotência e ambiente');
$codigo1 = (string)$ultimoCodigo;
$msgId1 = (string)$m[0]['message_id'];

echo "== 2. Reenvio < 30 s e unicidade\n";
$antes = $chamadasTransporte;
$r = $solicitar($p1);
t($r['codigo'] === 'SMS_OTP_REENVIO_AGUARDAR' && $chamadasTransporte === $antes, 'reenvio imediato recusado, sem SMS');
$chave = (string)$o['chave_principal'];
$hex = bin2hex($chave);
$dup = false;
$stmt = $conexao->prepare("INSERT INTO otp_desafio (id_empresa, id_cliente, principal_tipo, finalidade, chave_principal, telefone_hash, ip_hash, sessao_hash, codigo_hash, status, expira_em)
    VALUES (?, ?, 'cliente', 'acesso_cliente', UNHEX(?), UNHEX(REPEAT('0', 64)), UNHEX(REPEAT('0', 64)), UNHEX(REPEAT('0', 64)), 'x', 'ativo', NOW(6) + INTERVAL 5 MINUTE)");
$stmt->bind_param('iis', $empresaA, $cA1, $hex);
$ok = $stmt->execute();
$dup = !$ok && $stmt->errno === 1062;
if ($ok) {
    $idsDesafios[] = (int)$conexao->insert_id;
}
$stmt->close();
t($dup, 'segundo desafio ativo do mesmo principal viola uq_otp_desafio_ativo_principal (1062)');
$stmt = $conexao->prepare("INSERT INTO mensagem_envio (id_otp_desafio, ambiente, telefone_final4, chave_idempotencia, message_id, status) VALUES (?, 'teste', '0001', ?, ?, 'criada')");
$chaveOutra = smsChaveIdempotencia(999999991);
$msgOutro = smsUuidV4();
$stmt->bind_param('iss', $id1, $chaveOutra, $msgOutro);
t(!$stmt->execute() && $stmt->errno === 1062, 'segunda mensagem para o mesmo desafio viola uq_msg_otp_desafio (1062)');
$stmt->close();

echo "== 3. Invalidação do desafio anterior\n";
$envelhecer($id1);
$r = $solicitar($p1);
$id2 = (int)$r['dados']['id_otp_desafio'];
t($r['sucesso'] === true && $id2 !== $id1, 'novo desafio criado após 30 s');
t($linha($id1)['status'] === 'invalidado', 'desafio anterior invalidado');
t((int)q($conexao, "SELECT COUNT(*) AS n FROM otp_desafio WHERE chave_principal = UNHEX(?) AND status IN ('pendente_envio','ativo','validado')", 's', $hex)[0]['n'] === 1, 'exatamente um desafio ativo por principal');
t($validar($p1, $id1, $codigo1)['sucesso'] === false, 'código do desafio invalidado não valida');
$codigo2 = (string)$ultimoCodigo;

echo "== 4. Isolamento entre empresas e clientes\n";
t($validar($principal($empresaB, $cB1, $telB1), $id2, $codigo2)['sucesso'] === false, 'cliente da empresa B não valida desafio da empresa A');
t($validar($principal($empresaB, $cA1, $telA1), $id2, $codigo2)['sucesso'] === false, 'mesmo cliente com empresa trocada não valida');
t($validar($principal($empresaA, $cA2, $telA2), $id2, $codigo2)['sucesso'] === false, 'outro cliente da mesma empresa não valida');
t($validar($p1, $id2, $codigo2, null)['sucesso'] === true && $linha($id2)['status'] === 'consumido', 'o principal correto valida e consome');
t($linha($id2)['tentativas_validacao'] == 1, 'só a tentativa do principal correto foi contada (os demais nem localizam o desafio)');

echo "== 5. Consumo único\n";
t($validar($p1, $id2, $codigo2)['sucesso'] === false, 'segundo uso do mesmo código recusado');
$envelhecer($id2);
$r = $solicitar($p1);
$id3 = (int)$r['dados']['id_otp_desafio'];
$codigo3 = (string)$ultimoCodigo;
t($r['sucesso'] === true, 'consumo libera o slot: novo desafio permitido');

echo "== 6. Sessão diferente\n";
$vinculoOriginal = $_SESSION['otp_vinculo'];
$_SESSION['otp_vinculo'] = bin2hex(random_bytes(32));
t($validar($p1, $id3, $codigo3)['sucesso'] === false, 'desafio de outra sessão não valida');
t((int)$linha($id3)['tentativas_validacao'] === 0 && $linha($id3)['status'] === 'ativo', 'e não consome tentativas nem altera o desafio');
$_SESSION['otp_vinculo'] = $vinculoOriginal;

echo "== 7. Tentativas e bloqueio\n";
$errado = $codigo3 === '111111' ? '222222' : '111111';
$ultimo = null;
for ($i = 1; $i <= 5; $i++) {
    $ultimo = $validar($p1, $id3, $errado);
}
$o3 = $linha($id3);
t($ultimo['codigo'] === 'SMS_OTP_TENTATIVAS' && $o3['status'] === 'bloqueado' && (int)$o3['tentativas_validacao'] === 5, '5 erros bloqueiam o desafio e persistem as tentativas');
t($validar($p1, $id3, $codigo3)['sucesso'] === false, 'depois de bloqueado, nem o código correto vale');

echo "== 8. Expiração\n";
$envelhecer($id3);
$r = $solicitar($p1);
$id4 = (int)$r['dados']['id_otp_desafio'];
$codigo4 = (string)$ultimoCodigo;
w($conexao, 'UPDATE otp_desafio SET expira_em = criado_em + INTERVAL 1 SECOND WHERE id_otp_desafio = ?', 'i', $id4);
sleep(2);
$r = $validar($p1, $id4, $codigo4);
t($r['codigo'] === 'SMS_OTP_EXPIRADO' && $linha($id4)['status'] === 'invalidado', 'código expirado não valida e o desafio é invalidado');

echo "== 9. Empresa do desafio divergente da empresa real do cliente\n";
$envelhecer($id4);
$r = $solicitar($p1);
$id5 = (int)$r['dados']['id_otp_desafio'];
$codigo5 = (string)$ultimoCodigo;
w($conexao, 'UPDATE otp_desafio SET id_empresa = ? WHERE id_otp_desafio = ?', 'ii', $empresaB, $id5);
$r = $validar($principal($empresaB, $cA1, $telA1), $id5, $codigo5);
t($r['sucesso'] === false, 'otp_desafio.id_empresa diferente da empresa do cliente não valida');
$o5 = q($conexao, 'SELECT status FROM otp_desafio WHERE id_otp_desafio = ?', 'i', $id5)[0];
t(in_array($o5['status'], ['ativo', 'invalidado'], true) && $o5['status'] !== 'consumido', 'desafio inconsistente nunca é consumido');
$r = $validar($p1, $id5, $codigo5);
t($r['sucesso'] === false && q($conexao, 'SELECT status FROM otp_desafio WHERE id_otp_desafio = ?', 'i', $id5)[0]['status'] !== 'consumido', 'mesmo com a chave original, a divergência de empresa invalida/recusa');

echo "== 10. Cliente inativo/inexistente\n";
w($conexao, "UPDATE cliente SET status = 'inativo' WHERE id_cliente = ?", 'i', $cA2);
$r = $solicitar($principal($empresaA, $cA2, $telA2));
t($r['codigo'] === 'SMS_OTP_PRINCIPAL_INVALIDO', 'cliente inativo não recebe desafio');
$r = $solicitar($principal($empresaB, $cA1, $telA1));
t($r['codigo'] === 'SMS_OTP_PRINCIPAL_INVALIDO', 'id_cliente de outra empresa não recebe desafio (não confia só nas FKs)');
$r = $solicitar($principal($empresaA, 999999999, $telA1));
t($r['codigo'] === 'SMS_OTP_PRINCIPAL_INVALIDO', 'cliente inexistente recusado');

echo "== 11. Limite por telefone (5/hora)\n";
$limitou = false;
for ($i = 0; $i < 8 && !$limitou; $i++) {
    $ultimoId = (int)q($conexao, 'SELECT MAX(id_otp_desafio) AS id FROM otp_desafio WHERE chave_principal = UNHEX(?)', 's', $hex)[0]['id'];
    $envelhecer($ultimoId);
    $r = $solicitar($p1);
    $limitou = $r['codigo'] === 'SMS_OTP_LIMITE';
}
t($limitou, 'limite por telefone/hora aplicado');
t((int)q($conexao, 'SELECT COUNT(*) AS n FROM otp_desafio WHERE telefone_hash = UNHEX(?) AND criado_em > (NOW(6) - INTERVAL 1 HOUR)', 's', bin2hex((string)$o['telefone_hash']))[0]['n'] <= SMS_OTP_LIMITE_TELEFONE_HORA, 'não ultrapassou o limite configurado');

echo "== 12. Concorrência: linha bloqueada por outra conexão\n";
$pB = $principal($empresaB, $cB1, $telB1);
$r = $solicitar($pB);
$idB = (int)$r['dados']['id_otp_desafio'];
$codigoB = (string)$ultimoCodigo;
$conexaoLock->begin_transaction();
$conexaoLock->query("SELECT status FROM otp_desafio WHERE id_otp_desafio = $idB FOR UPDATE");
$conexao->query('SET SESSION innodb_lock_wait_timeout = 1');
$r = $validar($pB, $idB, $codigoB);
t($r['sucesso'] === false && $r['codigo'] === 'SMS_OTP_INDISPONIVEL', 'validação concorrente espera o lock, expira e falha fechada');
$conexaoLock->rollback();
$conexao->query('SET SESSION innodb_lock_wait_timeout = 50');
t($linha($idB)['status'] === 'ativo' && (int)$linha($idB)['tentativas_validacao'] === 0, 'nada foi consumido nem contado durante o lock');
t($validar($pB, $idB, $codigoB)['sucesso'] === true, 'após liberar o lock, a validação correta funciona');
$conexaoLock->begin_transaction();
$r2 = $validar($pB, $idB, $codigoB, $conexaoLock);
$conexaoLock->commit();
t($r2['sucesso'] === false, 'consumo único também entre conexões diferentes');

echo "== 13. Relatório de entrega (correlação, duplicado, ordem, ambiente, fuso)\n";
$agora = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vO');
$rel = [
    'messageId' => $msgId1, 'status' => ['id' => 5, 'groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET'],
    'error' => ['id' => 0, 'name' => 'NO_ERROR', 'permanent' => false], 'price' => ['pricePerMessage' => 0.05, 'currency' => 'BRL'],
    'sentAt' => $agora, 'doneAt' => $agora, 'messageCount' => 1,
];
t(smsAplicarRelatorio($conexao, 'producao', $rel)['motivo'] === 'desconhecida', 'mesmo messageId em outro ambiente não correlaciona');
t(smsAplicarRelatorio($conexao, 'teste', ['messageId' => smsUuidV4()] + $rel)['motivo'] === 'desconhecida', 'messageId desconhecido ignorado');
t(smsAplicarRelatorio($conexao, 'teste', $rel)['aplicado'] === true, 'relatório DELIVERED aplicado');
$m = q($conexao, 'SELECT status, entregue_em, concluido_em, preco, moeda, ABS(TIMESTAMPDIFF(SECOND, entregue_em, NOW(6))) AS dif FROM mensagem_envio WHERE message_id = ?', 's', $msgId1)[0];
t($m['status'] === 'entregue' && $m['entregue_em'] !== null && $m['moeda'] === 'BRL', 'mensagem marcada entregue pelo relatório');
t((int)$m['dif'] <= 5, 'fuso: horário do relatório (UTC) convertido coincide com o relógio do banco (diferença ≤ 5 s)');
t(smsAplicarRelatorio($conexao, 'teste', $rel)['motivo'] === 'duplicado', 'relatório repetido é ignorado');
$pend = ['status' => ['id' => 26, 'groupName' => 'PENDING', 'name' => 'PENDING_ACCEPTED']] + $rel;
t(smsAplicarRelatorio($conexao, 'teste', $pend)['aplicado'] === false, 'relatório atrasado não rebaixa o estado final');
t(q($conexao, 'SELECT status FROM mensagem_envio WHERE message_id = ?', 's', $msgId1)[0]['status'] === 'entregue', 'estado final preservado');

echo "\n" . $total . ' verificações, ' . count($falhas) . " falha(s)\n";
foreach ($falhas as $f) {
    echo 'FALHA: ' . $f . "\n";
}
limpar();
$idsDesafios = [];
$idsClientes = [];
$restos = (int)q($conexao, 'SELECT COUNT(*) AS n FROM cliente WHERE nome_completo = ?', 's', $marca)[0]['n'];
echo 'Limpeza: clientes fictícios restantes = ' . $restos . "\n";
exit($falhas === [] && $restos === 0 ? 0 : 1);
