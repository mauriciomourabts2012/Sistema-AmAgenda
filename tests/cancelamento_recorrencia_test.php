<?php

declare(strict_types=1);

/*
 * Testes isolados da regra "cancele o pagamento automático antes de cancelar a assinatura".
 * Sem MySQL, sem Mercado Pago e sem escrita: a decisão roda com portas simuladas, as consultas de cobrança rodam contra
 * SQLite em memória e as funções do serviço financeiro são substituídas por stubs que registram as chamadas.
 * Execução: php tests/cancelamento_recorrencia_test.php
 */

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->exec('CREATE TABLE transacao_pagamento (id_transacao INTEGER PRIMARY KEY, id_empresa INT, id_assinatura_pagamento INT,
    tipo_operacao TEXT, status TEXT, erro_codigo TEXT, detalhe_status_externo TEXT, requer_conciliacao INT DEFAULT 0)');
$GLOBALS['pdo']->exec('CREATE TABLE assinatura_pagamento_recorrente (id_assinatura_pagamento INTEGER PRIMARY KEY, id_empresa INT, id_assinatura INT,
    motor_recorrencia TEXT, status TEXT, status_externo TEXT, assinatura_externa_id TEXT, ambiente TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE assinatura (id_assinatura INTEGER PRIMARY KEY, id_empresa INT)');
$GLOBALS['pdo']->exec('INSERT INTO assinatura VALUES (9, 5)');
$GLOBALS['pdo']->exec('CREATE TABLE operacao_assinatura_gateway (id_operacao INTEGER PRIMARY KEY, id_assinatura_pagamento INT, status_processamento TEXT)');
$GLOBALS['stub'] = ['vinculo' => null, 'solicitacoes' => 0, 'solicitar' => null, 'atores' => []];
// Simulador do Mercado Pago: tudo que seria enviado ou lido fica registrado em 'envios'.
$GLOBALS['mp'] = ['modo' => 'confirma', 'sync_cancelado' => false, 'get_cancelado' => false, 'envios' => [], 'habilitado' => true, 'revertidos' => 0];
function assinaturaServicoConsultaFinanceira(object $db, string $sql, string $tipos, mixed ...$params): array
{
    $st = $GLOBALS['pdo']->prepare(str_replace(' FOR UPDATE', '', $sql));
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function assinaturaServicoPreapprovalVinculoDoContrato(object $db, int $empresa, int $assinatura, ?int $idAspr = null): ?array
{
    if ($GLOBALS['stub']['vinculo'] === 'db') {
        return assinaturaServicoConsultaFinanceira($db, 'SELECT * FROM assinatura_pagamento_recorrente WHERE id_empresa = ? AND id_assinatura = ? ORDER BY id_assinatura_pagamento DESC LIMIT 1', 'ii', $empresa, $assinatura)[0] ?? null;
    }

    return $GLOBALS['stub']['vinculo'];
}
function assinaturaServicoPreapprovalSolicitarCancelamento(object $db, int $empresa, int $assinatura, ?array $ator = null): array
{
    $GLOBALS['stub']['solicitacoes']++;
    $GLOBALS['stub']['atores'][] = $ator;
    if ($GLOBALS['stub']['solicitar'] !== null) {
        if ($GLOBALS['stub']['solicitar']['situacao'] === 'solicitado') {
            $GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status='cancelamento_pendente' WHERE status IN ('ativa','atualizacao_necessaria','suspensa')");
        }
        return $GLOBALS['stub']['solicitar'];
    }

    return ['aplicavel' => true, 'situacao' => 'sem_assinatura_externa'];
}
function assinaturaServicoPreapprovalCancelarPrimeiraExterna(object $db, array $vinculo, ?array $ator = null): ?array
{
    $GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status='cancelamento_pendente' WHERE status='pendente'");

    return ['aplicavel' => true, 'situacao' => 'solicitado', 'id_operacao' => 7];
}
function assinaturaServicoPreapprovalReverterCancelamentoRecusado(object $db, int $empresa, int $idAspr, int $idOperacao): bool
{
    $GLOBALS['mp']['revertidos']++;
    $GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status='ativa' WHERE status='cancelamento_pendente'");

    return true;
}
function mpSubOperacaoProcessar(object $db, object $gateway, int $idOperacao, ?int $empresa = null): array
{
    $GLOBALS['mp']['envios'][] = 'PUT canceled';
    return match ($GLOBALS['mp']['modo']) {
        'confirma' => (function (): array { $GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status='cancelada', status_externo='canceled'"); return ['resultado' => 'concluida']; })(),
        'recusa' => ['resultado' => 'erro', 'codigo' => 'PROVEDOR_REJEITOU'],
        'falha' => throw new RuntimeException('timeout'),
        'incerto' => ['resultado' => 'conciliacao', 'codigo' => 'RESULTADO_INCERTO'],
    };
}
function mpSubSincronizarPreapproval(object $db, object $gateway, string $ambiente, string $id, ?int $evento, ?int $empresa = null): array
{
    $GLOBALS['mp']['envios'][] = 'GET sync';
    if ($GLOBALS['mp']['sync_cancelado']) $GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status='cancelada', status_externo='canceled'");

    return ['resultado' => 'processado'];
}
function mpSubConfirmarCancelamentoExterno(object $db, object $gateway, array $vinculo, ?int $empresa = null): bool
{
    $GLOBALS['mp']['envios'][] = 'GET confirmar';
    if ($GLOBALS['mp']['get_cancelado']) $GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status='cancelada', status_externo='canceled'");

    return $GLOBALS['mp']['get_cancelado'];
}
final class GatewayFalso
{
    public static function subscriptionsHabilitado(): bool { return $GLOBALS['mp']['habilitado']; }
    public function ambiente(): string { return 'teste'; }
}
final class BancoFalso extends mysqli
{
    public function __construct() {}
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { return true; }
    public function commit(int $flags = 0, ?string $name = null): bool { return true; }
    public function rollback(int $flags = 0, ?string $name = null): bool { return true; }
}
require __DIR__ . '/../backend/_servicos/cancelamento_recorrencia.php';

$falhas = 0;
$total = 0;
function verificar(string $nome, bool $ok): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) $falhas++;
    echo ($ok ? 'ok   ' : 'FALHA') . " - {$nome}\n";
}

/** Vínculo simulado + registro de tudo que seria enviado ao Mercado Pago (deve ficar vazio). */
final class Cenario
{
    public array $chamadas = [];
    public ?array $v;
    public bool $operacaoAberta = false;
    public bool $getMostraCanceled = false;
    public bool $getFalha = false;

    public function __construct(?string $status, ?string $externa = 'PRE-1', ?string $statusExterno = 'authorized')
    {
        $this->v = $status === null ? null : ['id_assinatura_pagamento' => 1, 'status' => $status, 'status_externo' => $statusExterno, 'assinatura_externa_id' => $externa];
    }

    public function portas(): array
    {
        return [
            'vinculo' => fn(): ?array => $this->v,
            'operacao_aberta' => fn(array $v): bool => $this->operacaoAberta,
            'confirmar' => function (array $v): void {
                $this->chamadas[] = 'GET';
                if ($this->getFalha) throw new RuntimeException('timeout');
                if ($this->getMostraCanceled) $this->v['status'] = 'cancelada'; // sincronização do GET encerra o vínculo
            },
        ];
    }
}
function rodar(Cenario $c): array
{
    return cancelamentoRecorrenciaVerificarBloqueio($c->portas());
}
$bloqueado = fn(array $r, string $code): bool => $r['ok'] === false && $r['code'] === $code && $r['http'] === 409;

// A "porta" de cancelamento não existe: a verificação não consegue enviar PUT. Só o GET de confirmação é possível.
verificar('a verificação só expõe portas de leitura (vinculo, operacao_aberta, confirmar)',
    array_keys((new Cenario('ativa'))->portas()) === ['vinculo', 'operacao_aberta', 'confirmar']);

// Recorrência ativa e demais estados vivos: bloqueia com a mensagem definitiva, sem GET nem PUT.
foreach (['ativa', 'pendente', 'atualizacao_necessaria', 'suspensa'] as $status) {
    $c = new Cenario($status);
    $r = rodar($c);
    verificar("{$status}: bloqueia com a mensagem pedida, sem tocar o provedor",
        $bloqueado($r, 'CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO') && $r['msg'] === 'Para cancelar sua assinatura, primeiro cancele o pagamento automático do cartão.' && $c->chamadas === []);
}

// Cancelamento pendente.
$c = new Cenario('cancelamento_pendente');
$r = rodar($c);
verificar('cancelamento_pendente sem canceled: bloqueia pedindo para aguardar, sem sucesso', $bloqueado($r, 'CANCELAMENTO_RECORRENCIA_AGUARDANDO') && $r['msg'] === CANCELAMENTO_RECORRENCIA_MSG_AGUARDANDO);
verificar('cancelamento_pendente: só consulta (GET) o provedor', $c->chamadas === ['GET']);
$c = new Cenario('cancelamento_pendente'); $c->getMostraCanceled = true;
$r = rodar($c);
verificar('cancelamento_pendente com canceled confirmado: libera', $r['ok'] === true && $r['recorrencia'] === true);
$c = new Cenario('cancelamento_pendente'); $c->getFalha = true;
verificar('falha externa ao confirmar: continua bloqueado (falha fechada)', $bloqueado(rodar($c), 'CANCELAMENTO_RECORRENCIA_AGUARDANDO'));

// Confirmada, inexistente e nunca teve Preapproval.
$c = new Cenario('cancelada');
verificar('cancelada: libera', rodar($c)['ok'] === true && $c->chamadas === []);
verificar('encerrada: libera', rodar(new Cenario('encerrada'))['ok'] === true);
$r = rodar(new Cenario(null));
verificar('sem vínculo Preapproval: libera, sem bloqueio permanente', $r['ok'] === true && $r['recorrencia'] === false);
$r = rodar(new Cenario('pendente', null, null));
verificar('vínculo sem Preapproval externa e sem operação: libera', $r['ok'] === true && $r['recorrencia'] === false);
$r = rodar(new Cenario('erro', null, null));
verificar('erro sem Preapproval externa: libera', $r['ok'] === true);

// Estado externo incerto.
$c = new Cenario('pendente', null, null); $c->operacaoAberta = true;
verificar('criação incerta (operação aberta sem id externo): bloqueia', $bloqueado(rodar($c), 'CANCELAMENTO_RECORRENCIA_INCERTA'));
verificar('erro com Preapproval externa não confirmada: bloqueia', $bloqueado(rodar(new Cenario('erro')), 'CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO'));
verificar('erro com estado externo canceled: libera', rodar(new Cenario('erro', 'PRE-1', 'canceled'))['ok'] === true);

// Conclusão local.
$GLOBALS['stub']['solicitacoes'] = 0;
$db = mysqli_init();
$base = ['motor_recorrencia' => 'preapproval', 'id_assinatura_pagamento' => 1];
$GLOBALS['stub']['vinculo'] = null;
verificar('finalizar: sem vínculo conclui', cancelamentoRecorrenciaFinalizarLocal($db, 1, 1) === true);
$GLOBALS['stub']['vinculo'] = $base + ['status' => 'cancelada', 'assinatura_externa_id' => 'P', 'status_externo' => 'canceled'];
verificar('finalizar: vínculo cancelado conclui sem registrar nada', cancelamentoRecorrenciaFinalizarLocal($db, 1, 1) === true && $GLOBALS['stub']['solicitacoes'] === 0);
$GLOBALS['stub']['vinculo'] = $base + ['status' => 'ativa', 'assinatura_externa_id' => 'P', 'status_externo' => 'authorized'];
verificar('finalizar: recorrência viva surgida no meio recusa e não registra cancelamento externo', cancelamentoRecorrenciaFinalizarLocal($db, 1, 1) === false && $GLOBALS['stub']['solicitacoes'] === 0);
$GLOBALS['stub']['vinculo'] = $base + ['status' => 'pendente', 'assinatura_externa_id' => null, 'status_externo' => null];
verificar('finalizar: vínculo sem Preapproval é descartado localmente', cancelamentoRecorrenciaFinalizarLocal($db, 1, 1) === true && $GLOBALS['stub']['solicitacoes'] === 1);

// Verificação de cobrança em andamento (SQL real em SQLite), usada pelo botão do pagamento automático.
function inserir(string $status, ?string $erro = null, ?string $detalhe = null, int $manual = 0): void
{
    $GLOBALS['pdo']->exec('DELETE FROM transacao_pagamento');
    $GLOBALS['pdo']->prepare("INSERT INTO transacao_pagamento (id_empresa,id_assinatura_pagamento,tipo_operacao,status,erro_codigo,detalhe_status_externo,requer_conciliacao)
        VALUES (5,1,'assinatura_preapproval',?,?,?,?)")->execute([$status, $erro, $detalhe, $manual]);
}
$pend = fn(bool $manual): bool => cancelamentoRecorrenciaParcelaEmAndamento($db, 5, 1, $manual);
inserir('aguardando');
verificar('cobrança aguardando bloqueia', $pend(false));
inserir('processando', null, 'retry');
verificar('recusa em reciclagem (retry) não bloqueia', !$pend(false) && !$pend(true));
inserir('falhou', null, null, 1);
verificar('conciliação manual só bloqueia onde já bloqueava (botão de cartão)', !$pend(false) && $pend(true));

// Garantias estáticas dos dois painéis: mesma regra, e só o botão específico inicia o cancelamento externo.
$ler = fn(string $a): string => file_get_contents(__DIR__ . '/../' . $a);
$corpo = function (string $src, string $fn): string {
    $ini = (int)strpos($src, 'function ' . $fn);
    $fim = strpos($src, "\nfunction ", $ini + 10);
    return substr($src, $ini, $fim === false ? null : $fim - $ini);
};
$fat = $ler('backend/painel_administrativo/faturamento/gerenciar_faturamento.php');
$sa = $ler('backend/super_admin/assinatura/gerenciar_assinatura.php');
$po = $ler('backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php');
$cancelFat = $corpo($fat, 'faturamentoCancelarContratoExecutar');
$cancelSa = $corpo($sa, 'assinaturaAlterarStatus');
foreach (['Proprietário' => $cancelFat, 'Super Admin' => $cancelSa] as $nome => $src) {
    verificar("{$nome}: usa a verificação de bloqueio antes da transação do contrato",
        ($a = strpos($src, 'cancelamentoRecorrenciaVerificarBloqueio(')) !== false && $a < strpos($src, 'begin_transaction()') && strpos($src, 'begin_transaction()') < strpos($src, 'UPDATE assinatura'));
    verificar("{$nome}: não solicita nem processa cancelamento no Mercado Pago",
        !str_contains($src, 'assinaturaServicoPreapprovalSolicitarCancelamento') && !str_contains($src, 'mpSubOperacaoProcessar') && !str_contains($src, 'atualizarPreapproval'));
}
verificar('Super Admin e proprietário usam a mesma trava por empresa', str_contains($fat, 'cancelamentoRecorrenciaObterTrava') && str_contains($sa, 'cancelamentoRecorrenciaObterTrava'));
verificar('Super Admin: falha ao verificar não cancela (503, falha fechada)', str_contains($sa, "'CANCELAMENTO_RECORRENCIA_INDISPONIVEL'") && str_contains($sa, "'http' => 503"));
verificar('somente o botão específico solicita o cancelamento externo (cancelar-automatico)',
    str_contains($corpo($po, 'pagamentoCartaoCancelarAutomatico'), 'assinaturaServicoPreapprovalSolicitarCancelamento')
    && str_contains($corpo($po, 'pagamentoCartaoCancelarAutomatico'), 'mpSubOperacaoProcessar'));
$js = $ler('public/js/painel-administrativo/faturamento/gerenciar-faturamento.js');
$jsPo = $ler('public/js/painel-administrativo/faturamento/pagamento-online.js');
verificar('destaque só dispara para CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO e é temporário',
    str_contains($js, 'erro.codigo === "CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO"') && str_contains($jsPo, 'classList.remove("botao-destacado-temporario")'));
$jsSa = $ler('public/js/super-admin/assinatura/gerenciar-assinatura.js');
verificar('JS do Super Admin trata 409/422/503 (JSON ausente e bloqueio) sem declarar sucesso',
    str_contains($jsSa, 'resposta.json().catch') && str_contains($jsSa, 'json.ok !== true') && str_contains($jsSa, 'erro.bloqueioRecorrencia'));

// ---------------------------------------------------------------------------------------------------------------
// Ação "Cancelar pagamento automático" do Super Admin (mesmo serviço e outbox; contrato nunca alterado).
// ---------------------------------------------------------------------------------------------------------------
function semear(string $status, ?string $externa = 'PRE-1', string $statusExterno = 'authorized', int $empresa = 5): void
{
    $GLOBALS['pdo']->exec('DELETE FROM assinatura_pagamento_recorrente');
    $GLOBALS['pdo']->exec('DELETE FROM operacao_assinatura_gateway');
    $GLOBALS['pdo']->prepare("INSERT INTO assinatura_pagamento_recorrente (id_assinatura_pagamento,id_empresa,id_assinatura,motor_recorrencia,status,status_externo,assinatura_externa_id,ambiente)
        VALUES (1,?,9,'preapproval',?,?,?,'teste')")->execute([$empresa, $status, $statusExterno, $externa]);
    $GLOBALS['stub'] = ['vinculo' => 'db', 'solicitacoes' => 0, 'solicitar' => ['aplicavel' => true, 'situacao' => 'solicitado', 'id_operacao' => 10], 'atores' => []];
    $GLOBALS['mp'] = ['modo' => 'confirma', 'sync_cancelado' => false, 'get_cancelado' => false, 'envios' => [], 'habilitado' => true, 'revertidos' => 0];
}
function statusVinculo(): string
{
    return (string)$GLOBALS['pdo']->query('SELECT status FROM assinatura_pagamento_recorrente WHERE id_assinatura_pagamento = 1')->fetchColumn();
}
$bd = new BancoFalso();
$gw = new GatewayFalso();
$ator = ['tipo' => 'super_admin', 'id_usuario' => 99];
$cancelar = fn(int $empresa = 5): array => cancelamentoRecorrenciaCancelarPreapproval($bd, $gw, $empresa, 9, $ator);

semear('ativa');
$r = $cancelar();
verificar('SA sucesso: cancelado só com o canceled confirmado, 200 e mensagem pedida',
    $r['desfecho'] === 'cancelado' && $r['http'] === 200 && $r['msg'] === 'Pagamento automático cancelado com sucesso. A assinatura continua ativa.' && statusVinculo() === 'cancelada');
verificar('SA sucesso: auditoria recebe o Super Admin como ator e um único PUT é enviado', $GLOBALS['stub']['atores'] === [$ator] && $GLOBALS['mp']['envios'] === ['PUT canceled']);

semear('ativa'); $GLOBALS['mp']['modo'] = 'recusa';
$r = $cancelar();
verificar('SA recusa: 422, mensagem de falha, vínculo volta a ativa e nunca fica cancelado',
    $r['desfecho'] === 'recusado' && $r['http'] === 422 && $r['msg'] === CANCELAMENTO_RECORRENCIA_MSG_FALHA_ADMIN && statusVinculo() === 'ativa' && $GLOBALS['mp']['revertidos'] === 1);
semear('ativa'); $GLOBALS['mp']['modo'] = 'recusa'; $GLOBALS['mp']['sync_cancelado'] = true;
verificar('SA recusa, mas o provedor já estava canceled: confirma pelo GET', $cancelar()['desfecho'] === 'cancelado' && $GLOBALS['mp']['revertidos'] === 0);

semear('ativa'); $GLOBALS['mp']['modo'] = 'falha';
$r = $cancelar();
verificar('SA falha de comunicação: pendente (202), sem sucesso e vínculo segue em cancelamento_pendente',
    $r['desfecho'] === 'pendente' && $r['http'] === 202 && $r['msg'] === 'O cancelamento foi solicitado. Aguarde a confirmação do Mercado Pago.' && statusVinculo() === 'cancelamento_pendente');
semear('ativa'); $GLOBALS['mp']['modo'] = 'incerto';
verificar('SA resultado incerto: pendente, nunca cancelado', $cancelar()['desfecho'] === 'pendente' && statusVinculo() === 'cancelamento_pendente');
// Repetição: o serviço devolve a mesma operação aberta e o processamento conclui.
$GLOBALS['mp']['modo'] = 'confirma';
$r = $cancelar();
verificar('SA repetição após pendência: retoma a operação e confirma', $r['desfecho'] === 'cancelado' && statusVinculo() === 'cancelada');
$envios = count($GLOBALS['mp']['envios']);
$r = $cancelar();
verificar('SA repetição após confirmação: 409 "já cancelado" e nada é enviado', $r['code'] === 'PAGAMENTO_AUTOMATICO_JA_CANCELADO' && count($GLOBALS['mp']['envios']) === $envios);

semear('cancelada', 'PRE-1', 'canceled');
verificar('SA vínculo cancelado: não reenvia', $cancelar()['http'] === 409 && $GLOBALS['mp']['envios'] === []);
semear('ativa', null, 'x');
verificar('SA sem Preapproval externa: 409 e nada enviado', $cancelar()['code'] === 'PAGAMENTO_AUTOMATICO_INEXISTENTE' && $GLOBALS['mp']['envios'] === []);
semear('ativa', 'PRE-1', 'authorized', 6);
verificar('SA empresa errada: vínculo de outra empresa não é tocado', $cancelar(5)['code'] === 'PAGAMENTO_AUTOMATICO_INEXISTENTE' && statusVinculo() === 'ativa' && $GLOBALS['mp']['envios'] === []);
semear('ativa'); $GLOBALS['mp']['habilitado'] = false;
$r = $cancelar();
verificar('SA gateway desabilitado: 503, nada registrado nem enviado', $r['http'] === 503 && $GLOBALS['stub']['solicitacoes'] === 0 && $GLOBALS['mp']['envios'] === []);
semear('erro');
$r = $cancelar();
verificar('SA estado de erro: só consulta (GET), sem PUT, e pede verificação', $r['code'] === 'PAGAMENTO_AUTOMATICO_REQUER_VERIFICACAO' && $GLOBALS['mp']['envios'] === ['GET confirmar'] && statusVinculo() === 'erro');
semear('erro'); $GLOBALS['mp']['get_cancelado'] = true;
verificar('SA estado de erro com canceled no provedor: confirma', $cancelar()['desfecho'] === 'cancelado');
semear('pendente');
$r = $cancelar();
verificar('SA checkout pendente: usa o serviço da primeira cobrança e processa', $r['desfecho'] === 'cancelado' && $GLOBALS['stub']['solicitacoes'] === 0);
semear('ativa'); $GLOBALS['stub']['solicitar'] = ['aplicavel' => true, 'situacao' => 'ja_cancelada'];
verificar('SA serviço sem intenção nova: não envia PUT e não declara sucesso', $cancelar()['desfecho'] === 'recusado' && $GLOBALS['mp']['envios'] === []);

// Elegibilidade na listagem: a ação só aparece quando há recorrência externa elegível.
$resumo = fn(): array => cancelamentoRecorrenciaResumoPorAssinaturas($bd, [9]);
semear('ativa');
verificar('listagem: ativa é elegível', ($resumo()[9] ?? null) === ['situacao' => 'ativa', 'pode_cancelar' => true, 'status_vinculo' => 'ativa']);
semear('cancelamento_pendente');
verificar('listagem: cancelamento_pendente é elegível (retomada)', ($resumo()[9]['situacao'] ?? '') === 'cancelamento_pendente' && $resumo()[9]['pode_cancelar'] === true);
semear('cancelada', 'PRE-1', 'canceled');
verificar('listagem: cancelada confirmada não oferece a ação', $resumo() === []);
semear('encerrada', 'PRE-1', 'canceled');
verificar('listagem: encerrada não oferece a ação', $resumo() === []);
semear('erro', 'PRE-1', 'canceled');
verificar('listagem: erro com canceled no provedor não oferece a ação', $resumo() === []);
semear('erro');
verificar('listagem: erro não confirmado mostra o estado real (verificação)', ($resumo()[9]['situacao'] ?? '') === 'verificacao');
semear('pendente', null, 'x');
$GLOBALS['pdo']->exec('INSERT INTO operacao_assinatura_gateway (id_assinatura_pagamento,status_processamento) VALUES (1,"conciliacao")');
verificar('listagem: criação incerta é sinalizada sem oferecer cancelamento', ($resumo()[9] ?? null) === ['situacao' => 'incerta', 'pode_cancelar' => false, 'status_vinculo' => 'pendente']);
semear('pendente', null, 'x');
verificar('listagem: vínculo sem Preapproval nunca bloqueia nem oferece ação', $resumo() === []);

// Segurança e interface do Super Admin (leitura do código).
$fnSa = $corpo($sa, 'assinaturaCancelarRecorrencia');
verificar('SA endpoint: restrito ao Super Admin fora do modo suporte e com ator auditável', str_contains($fnSa, 'auditoriaResolverAtorSuperAdmin(') && str_contains($sa, "require __DIR__ . '/../../_auth/bloquear.php'"));
verificar('SA endpoint: valida que a assinatura pertence à empresa antes de qualquer operação', strpos($fnSa, 'ASSINATURA_NAO_ENCONTRADA') < strpos($fnSa, 'cancelamentoRecorrenciaCancelarPreapproval('));
verificar('SA endpoint: trava por empresa antes de cancelar e CSRF validado antes da ação', strpos($fnSa, 'cancelamentoRecorrenciaObterTrava') < strpos($fnSa, 'cancelamentoRecorrenciaCancelarPreapproval(')
    && strpos($corpo($sa, 'assinaturaAlterarStatus'), 'csrfValidarSessao()') < strpos($corpo($sa, 'assinaturaAlterarStatus'), "assinaturaCancelarRecorrencia("));
verificar('SA endpoint: mensagens do bloqueio comercial do Super Admin',
    str_contains($sa, 'Para cancelar esta assinatura, primeiro cancele o pagamento automático.') && str_contains($sa, 'Aguarde a confirmação do cancelamento do pagamento automático para continuar.'));
verificar('SA endpoint: a ação não toca contrato, cobranças nem pagamentos', !preg_match('/UPDATE\s+(assinatura|cobranca|pagamento)\b/i', $fnSa));
$nucleo = $ler('backend/_servicos/cancelamento_recorrencia.php');
$cp = $corpo($nucleo, 'cancelamentoRecorrenciaCancelarPreapproval');
verificar('núcleo: reaproveita o serviço de Preapproval e o processador do outbox (sem novo cliente do Mercado Pago)',
    str_contains($cp, 'assinaturaServicoPreapprovalSolicitarCancelamento(') && str_contains($cp, 'mpSubOperacaoProcessar(') && !str_contains($cp, 'atualizarPreapproval'));
verificar('JS SA: confirmação com título, texto e botões pedidos',
    str_contains($jsSa, 'Cancelar pagamento automático?') && str_contains($jsSa, 'As próximas cobranças automáticas desta empresa serão interrompidas. A assinatura continuará ativa.')
    && str_contains($jsSa, 'textoCancelar: "Voltar"') && str_contains($jsSa, 'textoConfirmar: "Confirmar cancelamento"'));
verificar('JS SA: sucesso só com PAGAMENTO_AUTOMATICO_CANCELADO; pendência é aviso', str_contains($jsSa, 'resultado.code === "PAGAMENTO_AUTOMATICO_CANCELADO"') && str_contains($jsSa, 'avisar("aviso", resultado.user_msg'));
verificar('JS SA: ação só com recorrência elegível e destaque de 6 s abrindo o menu Ações',
    str_contains($jsSa, 'item.recorrencia?.pode_cancelar === true') && str_contains($jsSa, '}, 6000);') && str_contains($jsSa, 'menuCtrl.toggle(toggle)')
    && str_contains($jsSa, 'destacarCancelarRecorrencia(idBloqueado)') && str_contains($jsSa, 'erro.codigo === "CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO"'));
verificar('JS SA: "Cancelar assinatura" continua no menu', str_contains($jsSa, 'Cancelar assinatura') && str_contains($jsSa, 'data-acao="cancelar"'));

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
