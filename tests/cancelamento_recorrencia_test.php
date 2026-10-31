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
    motor_recorrencia TEXT, status TEXT, status_externo TEXT, assinatura_externa_id TEXT, ambiente TEXT, ultimo_erro_codigo TEXT)'); // ATUALIZADO (F37c): coluna real usada pelo alerta de duplicidade.
$GLOBALS['pdo']->exec('CREATE TABLE assinatura (id_assinatura INTEGER PRIMARY KEY, id_empresa INT)');
$GLOBALS['pdo']->exec('INSERT INTO assinatura VALUES (9, 5)');
$GLOBALS['pdo']->exec('CREATE TABLE operacao_assinatura_gateway (id_operacao INTEGER PRIMARY KEY, id_assinatura_pagamento INT, status_processamento TEXT)');
$GLOBALS['stub'] = ['vinculo' => null, 'solicitacoes' => 0, 'solicitar' => null, 'atores' => []];
// Simulador do Mercado Pago: tudo que seria enviado ou lido fica registrado em 'envios'.
$GLOBALS['mp'] = ['modo' => 'confirma', 'sync_cancelado' => false, 'get_cancelado' => false, 'envios' => [], 'habilitado' => true, 'revertidos' => 0, 'origens' => [], 'sync_ok' => true];
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
function assinaturaServicoPreapprovalReverterCancelamentoRecusado(object $db, int $empresa, int $idAspr, int $idOperacao, string $statusOrigem = ''): bool
{
    // Espelha o serviço real: só 'ativa' e 'pendente' são restaurados; origem desconhecida nunca é presumida 'ativa'.
    $GLOBALS['mp']['origens'][] = $statusOrigem;
    if (!in_array($statusOrigem, ['ativa', 'pendente'], true)) return false;
    $GLOBALS['mp']['revertidos']++;
    $GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status='{$statusOrigem}' WHERE status='cancelamento_pendente'");

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
    if (!($GLOBALS['mp']['sync_ok'] ?? true)) return ['resultado' => 'erro'];
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
        $bloqueado($r, 'CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO') && $r['msg'] === 'Para cancelar esta assinatura, primeiro cancele o pagamento automático.' && $c->chamadas === []);
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
verificar('EMPRESA 37 cancelamento comercial: com a criação incerta aberta segue bloqueado, com orientação (não "tente em instantes"); encerrada a operação pela resolução administrativa (vínculo pendente, sem id externo, sem operação aberta), libera sem apagar nada',
    rodar($c)['msg'] === CANCELAMENTO_RECORRENCIA_MSG_INCERTA && !str_contains(CANCELAMENTO_RECORRENCIA_MSG_INCERTA, 'em instantes') && str_contains(CANCELAMENTO_RECORRENCIA_MSG_INCERTA, 'suporte')
    && rodar(new Cenario('pendente', null, null)) === ['ok' => true, 'recorrencia' => false]);
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
verificar('somente o botão específico (e a ação do Super Admin) solicitam o cancelamento externo, pelo mesmo serviço compartilhado',
    str_contains($corpo($po, 'pagamentoCartaoCancelarAutomatico'), 'cancelamentoRecorrenciaCancelarPreapproval(')
    && str_contains($corpo(file_get_contents(__DIR__ . '/../backend/_servicos/cancelamento_recorrencia.php'), 'cancelamentoRecorrenciaCancelarPreapproval'), 'mpSubOperacaoProcessar('));
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
    $GLOBALS['mp'] = ['modo' => 'confirma', 'sync_cancelado' => false, 'get_cancelado' => false, 'envios' => [], 'habilitado' => true, 'revertidos' => 0, 'origens' => [], 'sync_ok' => true];
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
verificar('listagem: ativa é elegível', ($resumo()[9] ?? null) === ['situacao' => 'ativa', 'pode_cancelar' => true, 'status_vinculo' => 'ativa', 'alerta' => null]); // ATUALIZADO (F37c): campo alerta, null sem sinal.
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
verificar('listagem: criação incerta é sinalizada sem oferecer cancelamento', ($resumo()[9] ?? null) === ['situacao' => 'incerta', 'pode_cancelar' => false, 'status_vinculo' => 'pendente', 'alerta' => null]); // ATUALIZADO (F37c): campo alerta.
$GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET ultimo_erro_codigo = 'PREAPPROVAL_ANTERIOR_ENCONTRADA'");
verificar('F37c listagem: tentativa bloqueada por Preapproval anterior aparece com o alerta de duplicidade (Super Admin)',
    ($resumo()[9] ?? null) === ['situacao' => 'incerta', 'pode_cancelar' => false, 'status_vinculo' => 'pendente', 'alerta' => 'PREAPPROVAL_ANTERIOR_ENCONTRADA']);
$GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET ultimo_erro_codigo = 'OUTRO_ERRO'");
verificar('F37c listagem: outros erros não viram alerta de duplicidade', array_key_exists('alerta', $resumo()[9] ?? []) && $resumo()[9]['alerta'] === null);
$GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET ultimo_erro_codigo = NULL");
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

// ---------------------------------------------------------------------------------------------------------------
// Fase 1: vínculo 'pendente' + 'authorized' (ex.: COBRANCA_EXTERNA_ANTECIPADA) e reversão pelo estado de origem.
// ---------------------------------------------------------------------------------------------------------------
$contarPut = fn(): int => count(array_filter($GLOBALS['mp']['envios'], fn($e) => $e === 'PUT canceled'));
semear('pendente');
$r = $cancelar();
verificar('F1 pendente+authorized: cancela pelo serviço da primeira cobrança, revalidando antes (GET sync) e confirmando só com canceled',
    $r['desfecho'] === 'cancelado' && $GLOBALS['mp']['envios'][0] === 'GET sync' && $contarPut() === 1 && statusVinculo() === 'cancelada' && $GLOBALS['stub']['solicitacoes'] === 0);
verificar('F1 pendente: auditoria identifica o Super Admin e o pedido não passa por "ativa"', $r['estado'] === 'cancelada');

semear('pendente'); $GLOBALS['mp']['modo'] = 'recusa';
$r = $cancelar();
verificar('F1 recusa com origem pendente: restaura "pendente", nunca "ativa" nem "cancelada"',
    $r['desfecho'] === 'recusado' && $r['http'] === 422 && statusVinculo() === 'pendente' && $GLOBALS['mp']['origens'] === ['pendente']);
verificar('F1 recusa com origem pendente: o sinal é recalculado por nova sincronização depois da reversão', count(array_filter($GLOBALS['mp']['envios'], fn($e) => $e === 'GET sync')) === 3);

semear('ativa'); $GLOBALS['mp']['modo'] = 'recusa';
$cancelar();
verificar('F1 recusa com origem ativa: continua voltando a "ativa" (comportamento preservado)', statusVinculo() === 'ativa' && $GLOBALS['mp']['origens'] === ['ativa']);

semear('cancelamento_pendente'); $GLOBALS['mp']['modo'] = 'recusa';
$r = $cancelar();
verificar('F1 retomada de cancelamento_pendente recusada: origem desconhecida não é presumida ativa; fica em cancelamento_pendente',
    $r['desfecho'] === 'recusado' && statusVinculo() === 'cancelamento_pendente' && $GLOBALS['mp']['revertidos'] === 0);
semear('suspensa'); $GLOBALS['mp']['modo'] = 'recusa';
$cancelar();
verificar('F1 recusa com origem suspensa: não é revertida para ativa', statusVinculo() === 'cancelamento_pendente' && $GLOBALS['mp']['revertidos'] === 0);

semear('pendente'); $GLOBALS['mp']['modo'] = 'falha';
$r = $cancelar();
verificar('F1 falha de comunicação com origem pendente: pendente de confirmação, estado conciliável e nunca cancelada', $r['desfecho'] === 'pendente' && statusVinculo() === 'cancelamento_pendente');
semear('pendente'); $GLOBALS['mp']['sync_ok'] = false;
$r = $cancelar();
verificar('F1 sem revalidar o estado externo: indisponível e nada registrado nem enviado', $r['desfecho'] === 'indisponivel' && $r['http'] === 503 && $contarPut() === 0 && statusVinculo() === 'pendente');
semear('pendente', 'PRE-1', 'pending');
$r = cancelamentoRecorrenciaCancelarPreapproval($bd, $gw, 5, 9, null, false);
verificar('F1 proprietário: pendente com checkout `pending` (não authorized) não é cancelado por esta rota', $r['code'] === 'PAGAMENTO_AUTOMATICO_NAO_AUTORIZADO' && $contarPut() === 0);
semear('pendente', 'PRE-1', 'pending');
verificar('F1 Super Admin: pode encerrar o checkout `pending` para não travar o cancelamento comercial', cancelamentoRecorrenciaCancelarPreapproval($bd, $gw, 5, 9, $ator, true)['desfecho'] === 'cancelado');
semear('pendente', 'id inválido!', 'authorized');
verificar('F1 identificador externo inválido: não cancela', $cancelar()['code'] === 'PAGAMENTO_AUTOMATICO_INEXISTENTE' && $GLOBALS['mp']['envios'] === []);

// Análise estática dos caminhos de cancelamento e reversão.
$as = $ler('backend/_servicos/assinatura.php');
$rv = $corpo($as, 'assinaturaServicoPreapprovalReverterCancelamentoRecusado');
verificar('reversão: pendente restaura "pendente" sem slot e sem data de cobrança, e nunca grava "ativa" nessa ramificação',
    str_contains($rv, "v.status = 'pendente', v.slot_ativo = NULL, v.cobrar_a_partir_de = NULL") && substr_count($rv, "SET v.status = 'ativa'") === 1);
verificar('reversão: origem desconhecida não reverte', str_contains($rv, "string \$statusOrigem = ''") && str_contains($rv, 'return false;'));
verificar('reversão: só após PROVEDOR_REJEITOU e sem operação aberta', substr_count($rv, 'PROVEDOR_REJEITOU') >= 1 && str_contains($rv, "IN ('pendente', 'processando', 'conciliacao')"));
$btn = $corpo($po, 'pagamentoCartaoCancelarAutomatico');
verificar('botão do proprietário: delega ao serviço compartilhado e aceita pendente+authorized sem tocar em "ativa"',
    str_contains($btn, 'cancelamentoRecorrenciaCancelarPreapproval(') && str_contains($btn, '$pendenteAutorizada') && !str_contains($btn, "SET status = 'ativa'") && !str_contains($btn, 'mpSubOperacaoProcessar'));
verificar('botão do proprietário: preserva trava por empresa e a verificação de cobrança em andamento', str_contains($btn, 'GET_LOCK') && str_contains($btn, 'pagamentoCartaoParcelaEmAndamento('));
$rota = substr($po, (int)strpos($po, "cartao/cancelar-automatico' && \$metodo === 'POST'"), 400);
verificar('rota cancelar-automatico: CSRF e permissão preservados', str_contains($rota, 'exigirPermissao(') && str_contains($rota, 'csrfValidarSessao()'));
$dp = $corpo($po, 'pagamentoCartaoDadosPreapproval');
verificar('permissão calculada no servidor para pendente+authorized com id externo válido', str_contains($dp, '$podeCancelarPendente') && str_contains($dp, "=== 'authorized'") && str_contains($dp, 'pode_cancelar_automatico'));
verificar('Alterar cartão não foi liberado para pendente (continua exigindo vínculo ativa)', str_contains($dp, '$configurado = $atual && $status === \'ativa\''));
verificar('Super Admin usa o mesmo serviço compartilhado, com o ator auditável', str_contains($fnSa, 'cancelamentoRecorrenciaCancelarPreapproval($conexao, $gateway, $idEmpresa, $idAssinatura, $ator, true)'));
verificar('Cancelar assinatura continua sem enviar nada ao Mercado Pago (proprietário e Super Admin)',
    !str_contains($cancelFat, 'cancelamentoRecorrenciaCancelarPreapproval') && !str_contains($cancelSa, 'cancelamentoRecorrenciaCancelarPreapproval'));
verificar('UI: botão no estado pendente+authorized junto de Atualizar situação, com destaque localizável',
    str_contains($jsPo, 'cancelarAutomaticoPendente}${botaoVerificar}') && str_contains($jsPo, 'document.querySelector("[data-cancelar-automatico]")'));
verificar('UI: texto de confirmação aprovado', str_contains($jsPo, 'Cancelar pagamento automático? As próximas cobranças automáticas serão interrompidas. Sua assinatura e o período já pago serão mantidos.'));
$ra = $ler('backend/_auth/require_auth.php');
verificar('empresa suspensa: somente a rota de cancelar-automatico foi liberada na regularização (e não na assinatura cancelada)',
    substr_count($ra, 'cartao/cancelar-automatico') === 1 && strpos($ra, 'cartao/cancelar-automatico') < strpos($ra, '$rotasPermitidasRegularizacao = [', strpos($ra, 'cartao/cancelar-automatico')) && !str_contains($ra, "'painel/faturamento/pagamento/cartao/trocar'"));

// ---------------------------------------------------------------------------------------------------------------
// Fase 2: antecipação controlada da cobrança externa. As funções REAIS (assinatura.php e webhook) são extraídas do código-fonte
// e executadas aqui, sem banco real, sem Mercado Pago e sem tocar nos stubs acima.
// ---------------------------------------------------------------------------------------------------------------
date_default_timezone_set('America/Sao_Paulo');
$extrair = static function (string $arquivo, array $funcoes, array $constantes = []): void {
    $src = str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/../' . $arquivo));
    foreach ($constantes as $c) {
        if (!preg_match('/^const ' . preg_quote($c, '/') . ' = .*?;\n/ms', $src, $m)) throw new RuntimeException("const {$c} ausente");
        if (!defined($c)) eval($m[0]);
    }
    foreach ($funcoes as $f) {
        if (!preg_match('/^function ' . preg_quote($f, '/') . '\(.*?\n}\n/ms', $src, $m)) throw new RuntimeException("função {$f} ausente");
        if (!function_exists($f)) eval($m[0]);
    }
};
$extrair('backend/_servicos/assinatura.php', [
    'assinaturaServicoPeriodicidadeDiaria', 'assinaturaServicoPreapprovalDataExterna', 'assinaturaServicoPreapprovalDataNaoAntecipada',
    'assinaturaServicoPreapprovalDataDentroAntecipacao', 'assinaturaServicoPreapprovalAntecipacaoDias',
    'assinaturaServicoPreapprovalAntecipacaoDiasContrato', 'assinaturaServicoPreapprovalConferirInicioExterno',
], ['ASSINATURA_SERVICO_PREAPPROVAL_FUSO', 'ASSINATURA_SERVICO_PREAPPROVAL_ANTECIPACAO_DIAS_MAXIMO', 'ASSINATURA_SERVICO_PERIODICIDADES']);
$extrair('backend/webhooks/webhook_mercado_pago.php', [
    'mpSubClassificarFatura', 'mpSubCompetenciasAntecipaveis', 'mpSubDebitoAntecipaProximaCompetencia', 'mpSubCompetenciaAntecipadaElegivel',
    'mpSubExataQuitadaCedeAAntecipacao',
]);

// Cenário lógico da empresa 35: competência paga 29/10–30/10; próxima 31/10–01/11; start_date 31/10 00:00; next_payment_date 30/10 16:31.
$fusoSp = new DateTimeZone('America/Sao_Paulo');
$inicioProx = new DateTimeImmutable('2026-10-31 00:00:00', $fusoSp);
$preap = fn(array $over = []) => array_replace_recursive([
    'status' => 'authorized', 'next_payment_date' => '2026-10-30T16:31:00.000-03:00',
    'auto_recurring' => ['start_date' => '2026-10-31T00:00:00.000-03:00', 'frequency' => 2, 'frequency_type' => 'days'],
], $over);
verificar('F2 sem antecipação (comportamento anterior): a cobrança de 30/10 continua COBRANCA_EXTERNA_ANTECIPADA', assinaturaServicoPreapprovalConferirInicioExterno($preap(), $inicioProx) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F2 empresa 35: diária com janela de 1 dia aceita a cobrança de 30/10 16:31 (start_date respeita o período pago)', assinaturaServicoPreapprovalConferirInicioExterno($preap(), $inicioProx, false, 1) === null);
verificar('F2 a janela não é irrestrita: 29/10 (2 dias antes) segue antecipada', assinaturaServicoPreapprovalConferirInicioExterno($preap(['next_payment_date' => '2026-10-29T10:00:00.000-03:00']), $inicioProx, false, 1) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F2 start_date dentro do período pago continua barrado mesmo com janela', assinaturaServicoPreapprovalConferirInicioExterno($preap(['auto_recurring' => ['start_date' => '2026-10-30T00:00:00.000-03:00']]), $inicioProx, false, 1) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F2 sem start_date a antecipação não é presumida', assinaturaServicoPreapprovalConferirInicioExterno(['status' => 'authorized', 'next_payment_date' => '2026-10-30T16:31:00.000-03:00', 'auto_recurring' => []], $inicioProx, false, 1) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F2 só vale para Preapproval authorized (pending usa o start_date)', assinaturaServicoPreapprovalConferirInicioExterno($preap(['status' => 'pending', 'auto_recurring' => ['start_date' => '2026-10-30T10:00:00.000-03:00']]), $inicioProx, false, 1) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F2 UTC equivalente ao dia civil de São Paulo (30/10 19:31Z) é aceito; 31/10 00:30Z (30/10 21:30 SP) também', assinaturaServicoPreapprovalConferirInicioExterno($preap(['next_payment_date' => '2026-10-30T19:31:00.000Z']), $inicioProx, false, 1) === null
    && assinaturaServicoPreapprovalConferirInicioExterno($preap(['next_payment_date' => '2026-10-31T00:30:00.000Z']), $inicioProx, false, 1) === null);
verificar('F2 cobrança no dia da competência (31/10) ou depois nunca é antecipada', assinaturaServicoPreapprovalConferirInicioExterno($preap(['next_payment_date' => '2026-10-31T09:00:00.000-03:00']), $inicioProx) === null);
// ATUALIZADO na Fase 4 (política universal): a janela de 1 dia vale para todas as periodicidades, não só a diária de 2 dias.
verificar('F2/F4 janela por periodicidade: diária (1 e 2 dias), mensal, trimestral, semestral e anual = 1 dia; desconhecida = 0',
    assinaturaServicoPreapprovalAntecipacaoDias('diaria', 2) === 1 && assinaturaServicoPreapprovalAntecipacaoDias('diaria', 1) === 1
    && assinaturaServicoPreapprovalAntecipacaoDias('diaria') === 1
    && array_sum(array_map(fn($p) => assinaturaServicoPreapprovalAntecipacaoDias($p, 1), ['mensal', 'trimestral', 'semestral', 'anual'])) === 4
    && assinaturaServicoPreapprovalAntecipacaoDias('quinzenal') === 0 && assinaturaServicoPreapprovalAntecipacaoDias('') === 0);
verificar('F2/F4 mensal com a janela de 1 dia aceita a cobrança na véspera do início (cenário da empresa 34, agora coberto)', assinaturaServicoPreapprovalConferirInicioExterno($preap(['next_payment_date' => '2026-10-30T16:31:00.000-03:00']), $inicioProx, false, assinaturaServicoPreapprovalAntecipacaoDias('mensal', 1)) === null);
verificar('F2 primeira cobrança e teste grátis não herdam a janela',
    assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => ['id_cobranca' => 1], 'teste' => null]) === 0
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => null, 'teste' => ['expira_em' => 'x']]) === 0
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => null, 'teste' => null]) === 1
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 1, 'primeira_cobranca' => null, 'teste' => null]) === 1
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'mensal', 'frequencia_tipo' => 'months', 'frequencia_meses' => 1, 'primeira_cobranca' => null, 'teste' => null]) === 1);

// Competência do débito (função pura do webhook).
$cob = fn(int $id, string $ini, string $fim, string $st = 'pendente', string $fin = 'regular') => ['id_cobranca' => $id, 'id_empresa' => 5, 'periodo_inicio' => $ini, 'periodo_fim' => $fim, 'status' => $st, 'finalidade' => $fin];
$paga = $cob(1, '2026-10-29', '2026-10-30', 'paga');
$prox = $cob(2, '2026-10-31', '2026-11-01');
verificar('F2 débito 30/10: a competência candidata é a que COMEÇA em 31/10, nunca a paga 29–30', array_column(mpSubCompetenciasAntecipaveis([$paga, $prox], '2026-10-30', '2026-10-31'), 'id_cobranca') === [2]);
verificar('F2 débito 30/10 sem competência futura existente: nada a antecipar (será criada com as datas normais)', mpSubCompetenciasAntecipaveis([$paga], '2026-10-30', '2026-10-31') === []);
verificar('F2 duas competências no alcance: ambiguidade preservada', count(mpSubCompetenciasAntecipaveis([$paga, $prox, $cob(3, '2026-10-31', '2026-10-31')], '2026-10-30', '2026-10-31')) === 2);
verificar('F2 competência cancelada ou de outra finalidade não conta', mpSubCompetenciasAntecipaveis([$cob(4, '2026-10-31', '2026-11-01', 'cancelada'), $cob(5, '2026-10-31', '2026-11-01', 'pendente', 'upgrade')], '2026-10-30', '2026-10-31') === []);
verificar('F2 criação antecipada: só a próxima da sequência, a 1 dia do débito, com competência anterior e janela > 0',
    mpSubDebitoAntecipaProximaCompetencia(1, true, 1) && !mpSubDebitoAntecipaProximaCompetencia(2, true, 1) && !mpSubDebitoAntecipaProximaCompetencia(1, false, 1)
    && !mpSubDebitoAntecipaProximaCompetencia(1, true, 0) && !mpSubDebitoAntecipaProximaCompetencia(0, true, 1) && !mpSubDebitoAntecipaProximaCompetencia(-1, true, 1));

// Elegibilidade da competência (consultas reais sobre SQLite): sem PIX concorrente, sem quitação prévia, sem suposição.
$GLOBALS['pdo']->exec('ALTER TABLE transacao_pagamento ADD COLUMN id_cobranca INT');
$GLOBALS['pdo']->exec('ALTER TABLE transacao_pagamento ADD COLUMN metodo TEXT');
$GLOBALS['pdo']->exec('CREATE TABLE pagamento (id_pagamento INTEGER PRIMARY KEY, id_empresa INT, id_cobranca INT, status TEXT)');
$limpar = fn() => $GLOBALS['pdo']->exec('DELETE FROM transacao_pagamento; DELETE FROM pagamento;');
$limpar();
verificar('F2 competência pendente livre é elegível', mpSubCompetenciaAntecipadaElegivel($bd, $prox) === ['cobranca' => $prox, 'criada' => false]);
verificar('F2 competência já paga não recebe o débito (nada é quitado em duplicidade)', mpSubCompetenciaAntecipadaElegivel($bd, $cob(2, '2026-10-31', '2026-11-01', 'paga')) === 'COMPETENCIA_ANTECIPADA_NAO_PENDENTE');
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_empresa, id_cobranca, metodo, status) VALUES (5, 2, 'pix', 'aguardando')");
verificar('F2 PIX em andamento na mesma competência bloqueia o débito do cartão', mpSubCompetenciaAntecipadaElegivel($bd, $prox) === 'COMPETENCIA_ANTECIPADA_COM_PIX');
$limpar();
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_empresa, id_cobranca, metodo, status) VALUES (5, 2, 'pix', 'confirmada')");
verificar('F2 PIX confirmado na mesma competência bloqueia o débito do cartão', mpSubCompetenciaAntecipadaElegivel($bd, $prox) === 'COMPETENCIA_ANTECIPADA_COM_PIX');
$limpar();
$GLOBALS['pdo']->exec("INSERT INTO pagamento (id_empresa, id_cobranca, status) VALUES (5, 2, 'confirmado')");
verificar('F2 pagamento confirmado prévio na competência bloqueia o débito do cartão', mpSubCompetenciaAntecipadaElegivel($bd, $prox) === 'COMPETENCIA_ANTECIPADA_JA_QUITADA');
$limpar();
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_empresa, id_cobranca, metodo, status) VALUES (5, 2, 'cartao_recorrente', 'processando')");
verificar('F2 tentativa anterior do próprio cartão (recycling) não impede nova fatura da mesma competência', mpSubCompetenciaAntecipadaElegivel($bd, $prox) === ['cobranca' => $prox, 'criada' => false]);
$limpar();
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_empresa, id_cobranca, metodo, status) VALUES (6, 2, 'pix', 'aguardando')");
verificar('F2 isolamento por empresa: PIX de outra empresa não interfere', mpSubCompetenciaAntecipadaElegivel($bd, $prox) === ['cobranca' => $prox, 'criada' => false]);
$limpar();

// Estados da fatura/pagamento: pagamento só com approved + accredited + processed; sem duplicidade nos demais.
$fat = fn(string $status, ?string $pag = null) => ['status' => $status, 'pagamento_id' => $pag];
$pg = fn(string $status, ?string $det = null, int $reemb = 0) => ['status' => $status, 'detalhe' => $det, 'valor_centavos' => 1000, 'reembolsado_centavos' => $reemb];
verificar('F2 scheduled sem pagamento: aguardando, nada quitado', mpSubClassificarFatura($fat('scheduled'), null)['classe'] === 'normal' && mpSubClassificarFatura($fat('scheduled'), null)['status'] === 'aguardando');
verificar('F2 recycling com pagamento rejeitado (cc_rejected_high_risk): continua processando, nada quitado', (function () use ($fat, $pg) {
    $c = mpSubClassificarFatura($fat('recycling', 'P1'), $pg('rejected', 'cc_rejected_high_risk'));
    return $c['status'] === 'processando' && $c['classe'] === 'normal' && $c['detalhe'] === 'cc_rejected_high_risk';
})());
verificar('F2 processed + approved + accredited é a única confirmação de pagamento', mpSubClassificarFatura($fat('processed', 'P1'), $pg('approved', 'accredited'))['classe'] === 'confirmada');
verificar('F2 approved sem accredited ou com fatura não processed vai a conciliação, sem quitar',
    mpSubClassificarFatura($fat('processed', 'P1'), $pg('approved', 'pending_contingency'))['classe'] === 'conciliacao'
    && mpSubClassificarFatura($fat('scheduled', 'P1'), $pg('approved', 'accredited'))['classe'] === 'conciliacao');
verificar('F2 rejected definitivo: recusada, nada quitado', mpSubClassificarFatura($fat('processed', 'P1'), $pg('rejected', 'cc_rejected_other_reason'))['status'] === 'recusada');
verificar('F2 reembolso total é estorno; parcial e chargeback vão a conciliação',
    mpSubClassificarFatura($fat('processed', 'P1'), $pg('refunded', null, 1000))['classe'] === 'estorno_total'
    && mpSubClassificarFatura($fat('processed', 'P1'), $pg('refunded', null, 400))['classe'] === 'conciliacao'
    && mpSubClassificarFatura($fat('processed', 'P1'), $pg('charged_back'))['classe'] === 'conciliacao');
verificar('F2 fatura com pagamento que não pôde ser verificado vai a conciliação', mpSubClassificarFatura($fat('processed', 'P1'), null)['classe'] === 'conciliacao');

// Garantias estáticas do fluxo.
$wh = $ler('backend/webhooks/webhook_mercado_pago.php');
$resolver = $corpo($wh, 'mpSubResolverCobranca');
verificar('F2 a fatura só é localizada/criada pelo débito da fatura autoritativa (debit_date), nunca por next_payment_date',
    str_contains($resolver, '$debito') && !str_contains($resolver, 'next_payment_date') && str_contains($corpo($wh, 'mpSubNormalizarFatura'), "'debito' => assinaturaServicoPreapprovalDataExterna(\$d['debit_date']"));
verificar('F2 a ativação do vínculo usa a janela só para período pago (nunca teste) e não cria fatura nem pagamento',
    str_contains($corpo($wh, 'mpSubCobrancaAntecipadaNaAtivacao'), 'assinaturaServicoPreapprovalAntecipacaoDias(') && !str_contains($corpo($wh, 'mpSubCobrancaAntecipadaNaAtivacao'), 'INSERT'));
verificar('F2 competência antecipada sem elegibilidade preserva o pagamento em conciliação com o código do impedimento',
    str_contains($resolver, 'mpSubCompetenciaAntecipadaElegivel($conexao, $antecipadas[0])') && str_contains($corpo($wh, 'mpSubAplicarFatura'), 'mpSubRegistrarFaturaOrfa('));
verificar('F2 idempotência preservada: fatura já conhecida usa a transação existente (fatura_externa_id), sem novo resolvedor',
    str_contains($corpo($wh, 'mpSubAplicarFatura'), 'fatura_externa_id = ?') && str_contains($corpo($wh, 'mpSubAplicarFatura'), '$existente !== null'));
verificar('F2 PIX segue bloqueado enquanto a Preapproval autorizada puder cobrar (fallback desligado)',
    str_contains($ler('backend/_servicos/assinatura.php'), 'const ASSINATURA_SERVICO_PREAPPROVAL_FALLBACK_PIX_AUTORIZADO = false;'));

// ---------------------------------------------------------------------------------------------------------------
// Fase 3: apresentação por estado (leitura do código; a validação visual é do responsável).
// ---------------------------------------------------------------------------------------------------------------
$aut = $corpo($jsPo, 'renderizarAutorizacao');
$ramoAtivo = substr($aut, (int)strpos($aut, 'if (atual.configurado) {'), 1900);
verificar('F3 ativa+authorized: Alterar cartão, Cancelar pagamento automático e Atualizar situação, cada um pela permissão do servidor',
    str_contains($ramoAtivo, 'atual.pode_trocar_cartao') && str_contains($ramoAtivo, 'atual.pode_cancelar_automatico') && str_contains($ramoAtivo, '${trocar}${cancelarAutomatico}${botaoVerificar}'));
$ramoPendente = substr($aut, (int)strpos($aut, 'Autorização em conferência') - 1200, 1800);
verificar('F3 pendente+authorized: cancelar e atualizar, sem Alterar cartão', str_contains($ramoPendente, 'cancelarAutomaticoPendente}${botaoVerificar}') && !str_contains($ramoPendente, 'data-trocar-cartao'));
$ramoCancelando = substr($aut, (int)strpos($aut, 'cancelamento_pendente: ['), 330);
verificar('F3 cancelamento_pendente: informa que aguarda o Mercado Pago, sem declarar cancelado', str_contains($ramoCancelando, 'aguarda a confirmação do Mercado Pago') && !str_contains($ramoCancelando, 'foi cancelado'));
verificar('F3 cancelamento_pendente: sem Alterar cartão nem novo cancelamento (o servidor não oferece e a rota recusa troca fora de ativa)',
    !str_contains($ramoCancelando, 'data-trocar-cartao') && !str_contains($ramoCancelando, 'data-cancelar-automatico')
    && str_contains($ler('backend/_servicos/assinatura.php'), "(string)\$vinculo['status'] !== 'ativa' || (\$vinculo['status_externo'] ?? '') !== 'authorized'"));
$ramoCancelada = substr($aut, (int)strpos($aut, 'const encerrada ='), 2300);
verificar('F3 cancelada/encerrada: situação real, sem botões de cartão anterior e sem reativação automática',
    str_contains($ramoCancelada, 'O pagamento automático foi cancelado no Mercado Pago') && !str_contains($ramoCancelada, 'data-trocar-cartao') && !str_contains($ramoCancelada, 'data-cancelar-automatico')
    && str_contains($ramoCancelada, 'atual.pode_configurar ? botaoAutorizar('));
verificar('F3 erro/incerteza: não declara cancelado e mantém Atualizar situação', str_contains($aut, 'erro: ["fa-solid fa-triangle-exclamation", "Não foi possível concluir"') && str_contains($aut, '${atual.pode_configurar ? botaoAutorizar("Cadastrar cartão") : ""}${botaoVerificar}'));
verificar('F3 Alterar cartão: mesma Preapproval (PUT card_token_id), sem POST de nova Preapproval, preservando trava e confirmação externa',
    str_contains($corpo($ler('backend/_servicos/assinatura.php'), 'assinaturaServicoPreapprovalTrocarCartao'), "atualizarPreapproval(\$preapprovalId, ['card_token_id' => \$tokenCartao])")
    && !str_contains($corpo($ler('backend/_servicos/assinatura.php'), 'assinaturaServicoPreapprovalTrocarCartao'), 'criarPreapproval')
    && str_contains($corpo($po, 'pagamentoCartaoPreapprovalTrocarCartao'), 'GET_LOCK') && str_contains($corpo($po, 'pagamentoCartaoPreapprovalTrocarCartao'), 'pagamentoCartaoParcelaEmAndamento('));
verificar('F3 Cancelar assinatura: mensagem aprovada e bloqueio sem enviar cancelamento ao Mercado Pago',
    CANCELAMENTO_RECORRENCIA_MSG_PRIMEIRO === 'Para cancelar esta assinatura, primeiro cancele o pagamento automático.'
    && !str_contains($cancelFat, 'mpSubOperacaoProcessar') && str_contains($js, 'document.dispatchEvent(new CustomEvent("amagenda:destacar-cancelar-automatico"))'));
verificar('F3 destaque de 6 s do botão do proprietário', str_contains($jsPo, '"botao-destacado-temporario"), 6000)') && str_contains($jsPo, 'botao-destacado-temporario'));
verificar('F3 Super Admin coerente: ação em Ações, bloqueio comercial e destaque de 6 s', str_contains($jsSa, 'data-acao="cancelar_recorrencia"') && str_contains($jsSa, '}, 6000);') && str_contains($sa, "'CANCELAR_PAGAMENTO_AUTOMATICO_PRIMEIRO'"));

// ---------------------------------------------------------------------------------------------------------------
// Cadastro de cartão interrompido (empresa 37): referência pesquisável, conciliação, estados e proteção do teste grátis.
// ---------------------------------------------------------------------------------------------------------------
ini_set('error_log', sys_get_temp_dir() . '/amagenda_teste_f37.log');
require_once __DIR__ . '/../backend/_gateways/mercado_pago.php';
$extrair('backend/_servicos/assinatura.php', [
    'assinaturaServicoPreapprovalEstadoBusca', 'assinaturaServicoPreapprovalVarrerStatus', 'assinaturaServicoPreapprovalLocalizar', 'assinaturaServicoDataValida', 'assinaturaServicoPreapprovalInicioTeste',
    'assinaturaServicoPreapprovalEvidenciaExterna', 'assinaturaServicoPreapprovalAvaliarEvidencia', 'assinaturaServicoNovaContratacaoResultado',
    'assinaturaServicoPreapprovalReferenciaBase', 'assinaturaServicoPreapprovalReferenciaRotacionada', 'assinaturaServicoPreapprovalAtestadoMotivo', 'assinaturaServicoPreapprovalResumoEvidencia',
], ['ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_LIMITE', 'ASSINATURA_SERVICO_PREAPPROVAL_BUSCA_PAGINAS', 'ASSINATURA_SERVICO_PREAPPROVAL_REFERENCIA_DIARIA_48H',
    'ASSINATURA_SERVICO_PREAPPROVAL_STATUS_BUSCA', 'ASSINATURA_SERVICO_PREAPPROVAL_EVIDENCIA_PAGINAS', 'ASSINATURA_SERVICO_PREAPPROVAL_EVIDENCIA_MARGEM_SEGUNDOS', 'ASSINATURA_SERVICO_PREAPPROVAL_ATESTADO_MINIMO',
    'ASSINATURA_SERVICO_PREAPPROVAL_FONTES_EVIDENCIA', 'ASSINATURA_SERVICO_PREAPPROVAL_FUSO']);
$extrair('backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php', ['pagamentoCartaoEstadoCriacao', 'pagamentoCartaoCriacaoExigeSuporte'],
    ['PAGAMENTO_CARTAO_INCERTO_SUPORTE_SEGUNDOS']); // ATUALIZADO (empresa 37): limite de tempo da existência não confirmada.
$assSrc = str_replace("\r\n", "\n", $ler('backend/_servicos/assinatura.php'));
$gwSrc = $ler('backend/_gateways/mercado_pago.php');

$ref = 'amg_' . str_repeat('ab12', 8);
$rota = new ReflectionMethod(MercadoPagoGateway::class, 'rotaBuscaPreapprovals');
$busca = fn(mixed $r) => $rota->invoke(null, ['external_reference' => $r, 'limit' => 50, 'offset' => 0]);
verificar('F37 referência tradicional (amg_ + 32 hex) é pesquisável', $busca($ref) !== null && str_contains($busca($ref)[1], 'external_reference=' . $ref . '&'));
verificar('F37 referência terminada em .d2 é pesquisável (mesma gerada na criação diária de 2 dias)', $busca($ref . '.d2') !== null && str_contains($busca($ref . '.d2')[1], 'external_reference=' . $ref . '.d2&'));
verificar('F37 a referência realmente gerada (mensal e diária) passa na regra única do gateway',
    MercadoPagoGateway::referenciaExternaValida(pagamentoGatewayReferenciaInterna())
    && MercadoPagoGateway::referenciaExternaValida(pagamentoGatewayReferenciaInterna() . ASSINATURA_SERVICO_PREAPPROVAL_REFERENCIA_DIARIA_48H));
$invalidas = ['', 'amg_', 'amg_zz', $ref . '.d3', $ref . '.d2x', $ref . '.', $ref . '..d2', $ref . '.d2.d2', 'AMG_' . str_repeat('ab12', 8), 'amg_' . strtoupper(str_repeat('ab12', 8)),
    'amg_' . str_repeat('ab12', 9), $ref . ' ', ' ' . $ref, $ref . "\n", $ref . ';', '../' . $ref, 'x' . $ref, $ref . '&status=authorized', 'DEV-SIMULADO'];
$aceitas = 0;
foreach ($invalidas as $i) { if ($busca($i) !== null || MercadoPagoGateway::referenciaExternaValida($i)) $aceitas++; }
verificar('F37 referências inválidas (formato, sufixo, tamanho, espaço, quebra, injeção de parâmetro) são rejeitadas antes de qualquer rede', $aceitas === 0
    && !MercadoPagoGateway::referenciaExternaValida(null) && !MercadoPagoGateway::referenciaExternaValida(123) && $busca(null) === null);
verificar('F37 a validação não foi removida: filtros desconhecidos e busca sem filtro continuam recusados',
    $rota->invoke(null, ['external_reference' => $ref, 'foo' => 'bar']) === null && $rota->invoke(null, ['limit' => 50]) === null && $rota->invoke(null, []) === null);
verificar('F37 a criação continua exigindo external_reference e a regra tem comprimento máximo 39', str_contains($gwSrc, "!array_key_exists('external_reference', \$corpo)") && strlen($ref . '.d2') === 39);

// Pesquisa pela referência original (Localizar) com um Mercado Pago simulado: honra o filtro `status`, IGNORA `external_reference`
// (comportamento real observado) e pagina por limit/offset, devolvendo paging.total.
final class GatewayBusca
{
    /** @var list<array<string, mixed>> */
    public array $conta = [];
    public array $chamadas = [];
    public ?array $falha = null;
    public bool $honraStatus = true;
    public function buscarPreapprovals(array $filtros): array
    {
        $this->chamadas[] = $filtros;
        if ($this->falha !== null) {
            return $this->falha;
        }
        $norm = fn($s) => $s === 'cancelled' ? 'canceled' : (string)$s;
        $itens = $this->conta;
        if ($this->honraStatus && isset($filtros['status'])) {
            $itens = array_values(array_filter($itens, fn($i) => $norm($i['status'] ?? '') === $norm($filtros['status'])));
        }
        $offset = (int)($filtros['offset'] ?? 0);
        $limite = (int)($filtros['limit'] ?? 50);

        return ['sucesso' => true, 'codigo' => 'ok', 'dados' => ['results' => array_slice($itens, $offset, $limite),
            'paging' => ['total' => count($itens), 'limit' => $limite, 'offset' => $offset]]];
    }
}
$item = fn(string $id, string $r, string $status = 'pending', string $criada = '2026-10-29T15:31:27.000-04:00') => ['id' => $id, 'external_reference' => $r, 'status' => $status, 'date_created' => $criada];
$outraEmpresa = 'amg_' . str_repeat('cd34', 8) . '.d2';
$refD2 = $ref . '.d2';
$conta = function (array $itens, ?array $falha = null) {
    $g = new GatewayBusca();
    $g->conta = $itens;
    $g->falha = $falha;

    return $g;
};

$g = $conta([$item('PRE-1', $refD2)]);
verificar('F37 operação incerta com Preapproval encontrada: id único devolvido; uma consulta por status (pending, authorized, paused), referência .d2 enviada intacta',
    assinaturaServicoPreapprovalLocalizar($g, $refD2) === ['estado' => 'encontrada', 'id' => 'PRE-1'] && count($g->chamadas) === 3
    && $g->chamadas[0]['external_reference'] === $refD2 && array_column($g->chamadas, 'status') === ['pending', 'authorized', 'paused']);
$g = $conta([$item('PRE-9', $outraEmpresa), $item('PRE-1', $refD2)]);
verificar('F37 isolamento: resultado de outra referência/empresa é ignorado mesmo vindo na mesma página', assinaturaServicoPreapprovalLocalizar($g, $refD2) === ['estado' => 'encontrada', 'id' => 'PRE-1']);
$g = $conta([$item('PRE-9', $outraEmpresa)]);
verificar('F37 isolamento: só correspondência de outra empresa não vincula nada (não encontrada)', assinaturaServicoPreapprovalLocalizar($g, $refD2) === ['estado' => 'nao_encontrada', 'id' => null]);
$g = $conta([$item('PRE-1', $refD2), $item('PRE-2', $refD2, 'authorized')]);
verificar('F37 múltiplas Preapprovals com a mesma referência (inclusive em status diferentes): bloqueio para análise manual', assinaturaServicoPreapprovalLocalizar($g, $refD2) === ['estado' => 'multiplas', 'id' => null]);
$g = $conta([$item('PRE-1', $refD2), $item('PRE-1', $refD2)]);
verificar('F37 o mesmo id repetido não é duplicidade', assinaturaServicoPreapprovalLocalizar($g, $refD2)['estado'] === 'encontrada');
$g = $conta([]);
verificar('F37 sem resultado em pesquisa completa: não encontrada (não prova inexistência; nada é criado por isso)', assinaturaServicoPreapprovalLocalizar($g, $refD2) === ['estado' => 'nao_encontrada', 'id' => null]);
$g = $conta([], ['sucesso' => false, 'codigo' => 'falha_transporte']);
verificar('F37 falha de comunicação preserva o estado incerto (indisponível)', assinaturaServicoPreapprovalLocalizar($g, $refD2)['estado'] === 'indisponivel');
$g = $conta([], ['sucesso' => false, 'codigo' => 'resposta_invalida', 'http_status' => 200]);
verificar('F37 resposta inválida do provedor não prova nada (indisponível)', assinaturaServicoPreapprovalLocalizar($g, $refD2)['estado'] === 'indisponivel');
foreach (['operacao_invalida', 'requisicao_invalida', 'provedor_rejeitou'] as $codigoRecusa) {
    $g = $conta([], ['sucesso' => false, 'codigo' => $codigoRecusa, 'http_status' => 400]);
    verificar("F37 recusa ($codigoRecusa) não é mais tratada como indisponibilidade transitória", assinaturaServicoPreapprovalLocalizar($g, $refD2)['estado'] === 'recusada');
}
$cheia = array_map(fn($n) => $item('X' . $n, 'amg_' . str_pad((string)$n, 32, '0', STR_PAD_LEFT)), range(1, 260));
$g = $conta($cheia);
verificar('F37 pesquisa incompleta (páginas esgotadas sem fim) nunca conclui "não encontrada"', assinaturaServicoPreapprovalLocalizar($g, $refD2) === ['estado' => 'indisponivel', 'id' => null] && count($g->chamadas) === 4 + 1 + 1);
$g = $conta(array_merge(array_map(fn($n) => $item('Y' . $n, 'amg_' . str_pad((string)$n, 32, '0', STR_PAD_LEFT), 'cancelled'), range(1, 600)), [$item('PRE-1', $refD2)]));
verificar('F37 conta grande: as Preapprovals canceladas (histórico) não entram na pesquisa por status, então a recuperação não depende do tamanho da conta',
    assinaturaServicoPreapprovalLocalizar($g, $refD2) === ['estado' => 'encontrada', 'id' => 'PRE-1'] && count($g->chamadas) === 3);
verificar('F37 classificação da busca: ok, recusada e indisponível',
    assinaturaServicoPreapprovalEstadoBusca(['sucesso' => true, 'codigo' => 'ok', 'dados' => ['results' => []]]) === 'ok' && assinaturaServicoPreapprovalEstadoBusca(['sucesso' => false, 'codigo' => 'operacao_invalida']) === 'recusada'
    && assinaturaServicoPreapprovalEstadoBusca(['sucesso' => false, 'codigo' => 'falha_transporte']) === 'indisponivel' && assinaturaServicoPreapprovalEstadoBusca(['sucesso' => true, 'dados' => []]) === 'indisponivel');

// Recuperação sem criação duplicada (garantias estruturais do serviço real).
$esc = $corpo($assSrc, 'assinaturaServicoPreapprovalEsclarecer');
$conc = $corpo($assSrc, 'assinaturaServicoPreapprovalConciliar');
$loc = $corpo($assSrc, 'assinaturaServicoPreapprovalLocalizar');
$vinc = $corpo($assSrc, 'assinaturaServicoPreapprovalVincularConfirmada');
$resv = $corpo($assSrc, 'assinaturaServicoPreapprovalReservar');
$env = $corpo($assSrc, 'assinaturaServicoPreapprovalEnviar');
verificar('F37 recuperação nunca cria: criarPreapproval existe uma única vez no serviço, dentro de Enviar, e nunca em Esclarecer, Conciliar, Localizar ou Vincular',
    substr_count($assSrc, '->criarPreapproval(') === 1 && str_contains($env, '->criarPreapproval(') && !str_contains($esc . $conc . $loc . $vinc, 'criarPreapproval'));
verificar('F37 conciliação reserva com $permiteCriar=false: sem operação aberta devolve SEM_CONCILIACAO_PENDENTE, nunca POST',
    str_contains($conc, 'assinaturaServicoPreapprovalReservar($conexao, $gateway->ambiente(), $idEmpresa, null, null, false)') && str_contains($conc, "'sem_pendencia' =>"));
verificar('F37 operação incerta com envio marcado só concilia (jamais novo POST); só a interrompida antes do envio é reencerrada',
    str_contains($resv, 'Pendente, interrompida após o envio ou em conciliação: só conciliação, jamais novo POST.') && str_contains($resv, 'TENTATIVA_NAO_ENVIADA'));
verificar('F37 concorrência: a reserva bloqueia a operação (FOR UPDATE) e a segunda solicitação recebe OPERACAO_EM_ANDAMENTO',
    str_contains($resv, 'FOR UPDATE') && str_contains($resv, "'em_andamento'") && str_contains($env, 'erro_codigo IS NULL') && str_contains($env, 'OPERACAO_EM_ANDAMENTO'));
verificar('F37 a Preapproval encontrada só é vinculada após validar referência, ambiente, valor, periodicidade e início, com unicidade do id',
    str_contains($vinc, 'assinaturaServicoPreapprovalValidarExterna(') && str_contains($vinc, "'referencia' => (string)\$vinculo['referencia_externa_assinatura']")
    && str_contains($vinc, "'ambiente' => (string)\$vinculo['ambiente']") && str_contains($assSrc, "return 'conflito'; // assinatura_externa_id já pertence a outro vínculo."));
verificar('F37 busca incompleta, recusada ou sem resultado mantém a operação em conciliação com código próprio (sem presumir inexistência)',
    str_contains($esc, "'recusada' => ['CONCILIACAO_CONSULTA_RECUSADA'") && str_contains($esc, "'nao_encontrada' => ['EXISTENCIA_NAO_CONFIRMADA'") && str_contains($esc, "'indisponivel' => ['CONCILIACAO_INDISPONIVEL'")
    && str_contains($esc, "'multiplas' => ['MULTIPLAS_ASSINATURAS_EXTERNAS'") && !str_contains($esc, "'erro'") && !str_contains($esc, 'Reservar('));
verificar('F37 pesquisa recusada deixa rastro (código e HTTP, sem corpo nem referência)', str_contains($corpo($assSrc, 'assinaturaServicoPreapprovalVarrerStatus'), "error_log('[assinatura_preapproval] busca_'") && !str_contains($loc, "'referencia'")); // ATUALIZADO: o rastro agora vive na varredura por status.

// Estado da criação (função real) e habilitação das ações.
$aberta = fn(string $st, ?string $erro, int $idade) => ['status' => $st, 'erro_codigo' => $erro, 'idade_segundos' => $idade];
$vp = ['assinatura_externa_id' => null, 'status' => 'pendente'];
verificar('F37 sem operação externa iniciada (vínculo novo ou falha comprovada): nao_criada → Cadastrar/Tentar novamente seguro',
    pagamentoCartaoEstadoCriacao($vp, null) === 'nao_criada' && pagamentoCartaoEstadoCriacao(['assinatura_externa_id' => null, 'status' => 'erro'], null) === 'nao_criada');
verificar('F37 operação em processamento recente: em_andamento', pagamentoCartaoEstadoCriacao($vp, $aberta('processando', 'ENVIO_INICIADO', 20)) === 'em_andamento' && pagamentoCartaoEstadoCriacao($vp, $aberta('pendente', null, 3)) === 'em_andamento');
verificar('F37 operação em conciliação ou interrompida: incerta (não oferece nova criação)',
    pagamentoCartaoEstadoCriacao($vp, $aberta('conciliacao', 'CONCILIACAO_INDISPONIVEL', 5)) === 'incerta' && pagamentoCartaoEstadoCriacao($vp, $aberta('processando', 'ENVIO_INICIADO', 4000)) === 'incerta');
verificar('F37 com id externo, ativa ou sem vínculo: não se aplica',
    pagamentoCartaoEstadoCriacao(['assinatura_externa_id' => 'PRE-1', 'status' => 'pendente'], null) === 'nao_aplica' && pagamentoCartaoEstadoCriacao(['assinatura_externa_id' => null, 'status' => 'ativa'], null) === 'nao_aplica'
    && pagamentoCartaoEstadoCriacao(null, null) === 'nao_aplica');
verificar('F37 suporte exigido só para múltiplas correspondências ou consulta recusada',
    pagamentoCartaoCriacaoExigeSuporte($aberta('conciliacao', 'MULTIPLAS_ASSINATURAS_EXTERNAS', 1)) && pagamentoCartaoCriacaoExigeSuporte($aberta('conciliacao', 'CONCILIACAO_CONSULTA_RECUSADA', 1))
    && !pagamentoCartaoCriacaoExigeSuporte($aberta('conciliacao', 'CONCILIACAO_INDISPONIVEL', 1)) && !pagamentoCartaoCriacaoExigeSuporte(null));
verificar('EMPRESA 37 existência não confirmada: na primeira hora segue "Atualizar situação"; depois (idade desde a CRIAÇÃO, não do último clique) o proprietário é orientado ao suporte, sem liberar nova criação',
    !pagamentoCartaoCriacaoExigeSuporte(['idade_criacao_segundos' => 3599] + $aberta('conciliacao', 'EXISTENCIA_NAO_CONFIRMADA', 5))
    && pagamentoCartaoCriacaoExigeSuporte(['idade_criacao_segundos' => 3600] + $aberta('conciliacao', 'EXISTENCIA_NAO_CONFIRMADA', 5))
    && !pagamentoCartaoCriacaoExigeSuporte(['idade_criacao_segundos' => 90000] + $aberta('conciliacao', 'CONCILIACAO_INDISPONIVEL', 5))
    && pagamentoCartaoEstadoCriacao($vp, ['idade_criacao_segundos' => 90000] + $aberta('conciliacao', 'EXISTENCIA_NAO_CONFIRMADA', 5)) === 'incerta'
    && str_contains($corpo($po, 'pagamentoCartaoCriacaoOperacao'), "TIMESTAMPDIFF(SECOND, criado_em, NOW(6)) AS idade_criacao"));
$dpNovo = $corpo($po, 'pagamentoCartaoDadosPreapproval');
verificar('F37 Atualizar situação habilitado pelo servidor sem preapproval_id quando há criação em andamento/incerta; Cadastrar cartão nunca nesses estados',
    str_contains($dpNovo, "'pode_verificar' => \$habilitado && (\$criada || in_array(\$estadoCriacao, ['em_andamento', 'incerta'], true))")
    && str_contains($dpNovo, "'criacao_estado' => \$estadoCriacao") && str_contains($dpNovo, '$podeConfigurar = $habilitado && $elegivel && ($novo || $retomavel) && !$configurado;')
    && str_contains($assSrc, "return ['motivo' => 'OPERACAO_EXTERNA_PENDENTE'"));
verificar('F37 Tentar novamente só sem operação aberta: elegibilidade vem de RECORRENCIA_EXISTENTE, calculada no servidor e revalidada na rota',
    str_contains($dpNovo, "(\$status === 'pendente' && (!\$criada || (\$v['status_externo'] ?? '') === 'pending'))") && str_contains($dpNovo, "(\$status === 'erro' && !\$criada)")
    && str_contains($po, "if (!\$cadastroCartao['elegivel'] && \$cadastroCartao['motivo'] !== 'RECORRENCIA_EXISTENTE')"));
$consultar = $corpo($po, 'pagamentoCartaoPreapprovalConsultar');
verificar('F37 Atualizar situação sem id externo usa a conciliação (sem criar) e não consulta o provedor em rajada',
    str_contains($consultar, 'assinaturaServicoPreapprovalConciliar(') && str_contains($consultar, "\$operacaoCriacao['idade_segundos'] < 15") && !str_contains($consultar, 'Iniciar('));
$tg = $corpo($po, 'pagamentoCartaoTesteGratis');
verificar('F37 teste grátis: sem Preapproval criada nunca vira "aguardando_autorizacao" (cadastro_incerto/cadastro_nao_criado vêm antes)',
    strpos($tg, "=> 'cadastro_incerto'") !== false && strpos($tg, "=> 'cadastro_incerto'") < strpos($tg, "=> 'aguardando_autorizacao'") && strpos($tg, "=> 'cadastro_nao_criado'") < strpos($tg, "=> 'aguardando_autorizacao'")
    && str_contains($tg, "!in_array(\$situacao, ['processando', 'cadastro_incerto', 'cadastro_nao_criado'], true)")); // ATUALIZADO: sem remover também em cadastro não criado.

// Frontend (leitura do código; a validação visual é do responsável).
verificar('F37 UI: estado incerto explica, oferece Atualizar situação e orienta o suporte; nenhum botão de nova criação',
    str_contains($jsPo, 'cadastro_incerto: ["fa-solid fa-triangle-exclamation", "Não foi possível confirmar a abertura do cadastro do cartão", TEXTO_CADASTRO_INCERTO]')
    && str_contains($jsPo, 'fale com o suporte') && str_contains($jsPo, 'TEXTO_SUPORTE_CADASTRO') && str_contains($jsPo, '${autorizar}${trocar}${remover}${botaoVerificar}'));
verificar('F37 UI: "Tentar novamente" só onde o servidor liberou pode_configurar e a Preapproval não foi criada',
    substr_count($jsPo, '"Tentar novamente"') === 2 && str_contains($jsPo, '(t.situacao === "cadastro_nao_criado" ? "Tentar novamente" : "Cadastrar cartão")')
    && str_contains($jsPo, 'atual.pode_configurar ? botaoAutorizar(criada ? "Continuar no Mercado Pago" : "Tentar novamente")'));
$ini = $corpo($jsPo, 'iniciarAutorizacaoAssinatura');
verificar('F37 clique: estado de processamento, sem clique duplicado, URL validada, sem window.open e sem sucesso por inferência',
    str_contains($ini, 'cartao.redirecionando') && str_contains($ini, 'Abrindo o Mercado Pago') && str_contains($ini, 'aria-busy') && str_contains($ini, 'if (initPointValido(dados.init_point))')
    && str_contains($ini, 'window.location.assign(dados.init_point)') && !str_contains($ini, 'window.open') && strpos($ini, 'initPointValido') < strpos($ini, 'window.location.assign'));
verificar('F37 clique: erro do servidor não vira "Aguardando sua autorização" — a tela é recarregada com o estado real',
    str_contains($ini, 'recarregar = true') && str_contains($ini, 'if (recarregar) await carregarConfiguracaoCartao();') && str_contains($ini, 'avisar("erro", erro.message)'));
verificar('F37 UI usa só o link validado (https, host Mercado Pago, /subscriptions/checkout, parâmetros conhecidos)',
    str_contains($jsPo, 'u.pathname !== "/subscriptions/checkout"') && str_contains($jsPo, '["www.mercadopago.com.br", "mercadopago.com.br"]'));

// Cancelamento/desistência da tentativa.
$desiste = $corpo($assSrc, 'assinaturaServicoPreapprovalDesistirPrimeiraCobranca');
verificar('F37 desistir com criação incerta: nada é cancelado nem excluído; a mensagem orienta Atualizar situação e preserva o contrato',
    str_contains($desiste, "'CRIACAO_EM_CONCILIACAO'") && str_contains($desiste, 'Sua assinatura e o teste grátis não são afetados')
    && strpos($desiste, 'CRIACAO_EM_CONCILIACAO') < strpos($desiste, 'assinaturaServicoPreapprovalSolicitarCancelamento(') && !str_contains($desiste, 'DELETE ') && !str_contains($desiste, 'UPDATE assinatura '));
verificar('F37 o botão de remover cartão não aparece com criação incerta (decisão do servidor)',
    str_contains($tg, "'pode_remover_cartao' => \$viva && \$status !== 'cancelamento_pendente' && !in_array(\$situacao, ['processando', 'cadastro_incerto', 'cadastro_nao_criado'], true)")); // ATUALIZADO: inclui cadastro_nao_criado.
verificar('F37 Preapproval existente segue pelo tratamento próprio do outbox (cancelamento externo confirmado), sem tratar pending como authorized',
    str_contains($desiste, 'assinaturaServicoPreapprovalCancelarPrimeiraExterna(') && !str_contains($desiste, 'cancelamentoRecorrenciaCancelarPreapproval('));

// Proteção dos 30 dias gratuitos.
$semEscrita = $esc . $conc . $loc . $vinc;
verificar('F37 teste grátis: recuperação/vinculação não altera assinatura (modalidade, teste_expira_em, status), cobrança nem pagamento',
    !preg_match('/UPDATE assinatura\s/', $semEscrita) && !preg_match('/INSERT INTO (cobranca|pagamento|transacao_pagamento)\b/', $semEscrita)
    && !preg_match("/teste_expira_em\s*=|modalidade\s*=\s*'paga'|data_inicio\s*=/", $semEscrita));
$fimTeste = '2026-11-28 22:44:34';
$inicioTeste = assinaturaServicoPreapprovalInicioTeste($fimTeste);
verificar('F37 início da cobrança recorrente = fim real do teste (arredondado ao minuto seguinte, nunca antes)',
    $inicioTeste->format('Y-m-d H:i') === '2026-11-28 22:45' && $inicioTeste >= new DateTimeImmutable($fimTeste, new DateTimeZone(date_default_timezone_get())));
$preTeste = fn(string $proxima) => ['status' => 'authorized', 'next_payment_date' => $proxima, 'auto_recurring' => ['start_date' => '2026-11-28T22:45:00.000-03:00', 'frequency' => 2, 'frequency_type' => 'days']];
verificar('F37 teste grátis: cobrança externa antes do fim do teste é barrada (sem janela de antecipação); no fim do teste é aceita',
    assinaturaServicoPreapprovalConferirInicioExterno($preTeste('2026-11-20T10:00:00.000-03:00'), $inicioTeste, false, 0) === 'COBRANCA_EXTERNA_ANTECIPADA'
    && assinaturaServicoPreapprovalConferirInicioExterno($preTeste('2026-11-28T22:45:00.000-03:00'), $inicioTeste, false, 0) === null
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => null, 'teste' => ['expira_em' => $fimTeste]]) === 0);
verificar('F37 competência de 2 dias e demais periodicidades preservadas (sufixo .d2 só no ciclo diário de 2 dias)',
    str_contains($assSrc, "if (\$diasDiaria === 2) {\n            \$referencia .= ASSINATURA_SERVICO_PREAPPROVAL_REFERENCIA_DIARIA_48H;") && assinaturaServicoPreapprovalAntecipacaoDias('diaria', 2) === 1
    && array_sum(array_map(fn($p) => assinaturaServicoPreapprovalAntecipacaoDias($p, 2), ['mensal', 'trimestral', 'semestral', 'anual'])) === 4); // ATUALIZADO na Fase 4: janela universal de 1 dia.
verificar('F37 cartão autorizado não quita nada: nenhuma gravação de pagamento na recuperação; só o webhook autoritativo de fatura quita',
    !str_contains($semEscrita, 'INSERT INTO pagamento') && str_contains($ler('backend/webhooks/webhook_mercado_pago.php'), 'mpSubClassificarFatura'));

// ---------------------------------------------------------------------------------------------------------------
// Fase 4: política universal de antecipação de 1 dia civil (todas as periodicidades), sem exceção por empresa.
// ---------------------------------------------------------------------------------------------------------------
$extrair('backend/_servicos/assinatura.php', ['assinaturaServicoPreapprovalInicioCobranca', 'assinaturaServicoPreapprovalValidarExterna']);
$wh4 = str_replace("\r\n", "\n", $ler('backend/webhooks/webhook_mercado_pago.php'));

/** Cenário genérico: período pago até $fimPago (Y-m-d); Preapproval authorized com start_date = primeiro dia não pago. */
$cenario = function (string $fimPago, string $proximaCobranca, ?string $startDate = null, string $status = 'authorized') {
    $inicio = assinaturaServicoPreapprovalInicioCobranca($fimPago);

    return [$inicio, ['status' => $status, 'next_payment_date' => $proximaCobranca,
        'auto_recurring' => ['start_date' => $startDate ?? $inicio->format('Y-m-d\T00:00:00.000P'), 'frequency' => 1, 'frequency_type' => 'months']]];
};
$confere = function (string $periodicidade, string $fimPago, string $proxima, ?string $start = null, int $ciclo = 1) use ($cenario) {
    [$inicio, $dados] = $cenario($fimPago, $proxima, $start);

    return assinaturaServicoPreapprovalConferirInicioExterno($dados, $inicio, false, assinaturaServicoPreapprovalAntecipacaoDias($periodicidade, $ciclo));
};

// 1-6) Cada periodicidade aceita a cobrança na véspera do primeiro dia não pago (1 dia) e recusa 2 dias.
$casos = [
    'mensal' => ['2026-11-28', '2026-11-28T12:35:09.000-03:00', '2026-11-26T09:00:00.000-03:00'],
    'trimestral' => ['2027-01-28', '2027-01-28T08:00:00.000-03:00', '2027-01-26T08:00:00.000-03:00'],
    'semestral' => ['2027-04-28', '2027-04-28T23:59:00.000-03:00', '2027-04-26T00:00:00.000-03:00'],
    'anual' => ['2027-10-28', '2027-10-28T00:00:00.000-03:00', '2027-10-27T10:00:00.000-03:00'],
];
foreach ($casos as $periodicidade => [$fimPago, $vespera, $doisDias]) {
    verificar("F4 {$periodicidade}: cobrança na véspera do primeiro dia não pago é reconhecida (1 dia civil)", $confere($periodicidade, $fimPago, $vespera) === null);
    verificar("F4 {$periodicidade}: antecipação de 2 dias é recusada", $confere($periodicidade, $fimPago, $doisDias) === 'COBRANCA_EXTERNA_ANTECIPADA');
    verificar("F4 {$periodicidade}: cobrança no primeiro dia não pago (ou depois) segue aceita", $confere($periodicidade, $fimPago, (new DateTimeImmutable($fimPago, $fusoSp))->modify('+1 day')->format('Y-m-d\T10:00:00.000P')) === null);
}
verificar('F4 diário legado (1 dia): a véspera é reconhecida, 2 dias recusados', $confere('diaria', '2026-10-30', '2026-10-30T16:31:00.000-03:00', null, 1) === null
    && $confere('diaria', '2026-10-30', '2026-10-29T10:00:00.000-03:00', null, 1) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F4 diário de 2 dias preservado (empresa 35): 30/10 16:31 reconhecida, 29/10 recusada',
    $confere('diaria', '2026-10-30', '2026-10-30T16:31:00.000-03:00', null, 2) === null && $confere('diaria', '2026-10-30', '2026-10-29T10:00:00.000-03:00', null, 2) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F4 a política é única: mesmo máximo (1) para todas as periodicidades e nenhuma referência a empresa na decisão',
    ASSINATURA_SERVICO_PREAPPROVAL_ANTECIPACAO_DIAS_MAXIMO === 1 && !preg_match('/id_empresa\s*[=!]==?\s*\d+/', $corpo($assSrc, 'assinaturaServicoPreapprovalAntecipacaoDias') . $corpo($assSrc, 'assinaturaServicoPreapprovalConferirInicioExterno')));

// 21) Cenário real da empresa 34 (mensal, vínculo 22): next_payment_date 28/11 12:35:09, start_date 29/11, Preapproval authorized.
$inicio34 = assinaturaServicoPreapprovalInicioCobranca('2026-11-28');
$dados34 = ['id' => 'f7179e6914b74304824c3e6f58c3bb41', 'external_reference' => 'amg_9165c25f55b59a5443e5a6fe4dadf55e', 'status' => 'authorized', 'live_mode' => true,
    'next_payment_date' => '2026-11-28T12:35:09.000-03:00',
    'auto_recurring' => ['currency_id' => 'BRL', 'transaction_amount' => 4.99, 'frequency' => 1, 'frequency_type' => 'months', 'start_date' => '2026-11-29T00:00:00.000-03:00']];
$esp34 = ['id' => 'f7179e6914b74304824c3e6f58c3bb41', 'referencia' => 'amg_9165c25f55b59a5443e5a6fe4dadf55e', 'ambiente' => 'producao', 'valor_centavos' => 499,
    'frequencia_meses' => 1, 'frequencia_tipo' => 'months', 'inicio' => $inicio34, 'antecipacao_dias' => assinaturaServicoPreapprovalAntecipacaoDias('mensal', 1), 'status_permitidos' => ['pending', 'authorized']];
verificar('F4 empresa 34: sem a política (comportamento de 1.9.6) a Preapproval ficava pendente com COBRANCA_EXTERNA_ANTECIPADA', assinaturaServicoPreapprovalValidarExterna($dados34, ['antecipacao_dias' => 0] + $esp34) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F4 empresa 34: com a política, a conferência contratual completa devolve null (vínculo pode ir a "ativa" = autorização válida, não pagamento)', assinaturaServicoPreapprovalValidarExterna($dados34, $esp34) === null);

// Demais validações preservadas mesmo com a antecipação.
$mut = fn(array $troca, array $espTroca = []) => assinaturaServicoPreapprovalValidarExterna(array_replace_recursive($dados34, $troca), $espTroca + $esp34);
verificar('F4 validações preservadas: valor, moeda, frequência, referência, id, ambiente e status continuam divergindo',
    $mut(['auto_recurring' => ['transaction_amount' => 5.99]]) === 'VALOR_EXTERNO_DIVERGENTE' && $mut(['auto_recurring' => ['currency_id' => 'USD']]) === 'MOEDA_EXTERNA_DIVERGENTE'
    && $mut(['auto_recurring' => ['frequency' => 2]]) === 'PERIODICIDADE_EXTERNA_DIVERGENTE' && $mut(['auto_recurring' => ['frequency_type' => 'days']]) === 'PERIODICIDADE_EXTERNA_DIVERGENTE'
    && $mut(['external_reference' => 'amg_outraempresa']) === 'REFERENCIA_EXTERNA_DIVERGENTE' && $mut(['id' => 'OUTRO-ID']) === 'ID_EXTERNO_DIVERGENTE'
    && $mut(['live_mode' => false]) === 'AMBIENTE_EXTERNO_DIVERGENTE' && $mut(['status' => 'cancelled']) === 'STATUS_EXTERNO_INESPERADO');
verificar('F4 período pago preservado: start_date dentro do período já pago continua barrado mesmo com a véspera',
    $mut(['auto_recurring' => ['start_date' => '2026-11-28T00:00:00.000-03:00']]) === 'COBRANCA_EXTERNA_ANTECIPADA'
    && $mut(['next_payment_date' => '2026-11-27T12:00:00.000-03:00']) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F4 a ativação não exige pagamento: nenhuma gravação de pagamento/cobrança em mpSubCobrancaAntecipadaNaAtivacao',
    !preg_match('/INSERT|UPDATE/', $corpo($wh4, 'mpSubCobrancaAntecipadaNaAtivacao')) && str_contains($corpo($wh4, 'mpSubCobrancaAntecipadaNaAtivacao'), 'assinaturaServicoPreapprovalAntecipacaoDias('));

// 9-10) Teste grátis: autorização pode ocorrer, primeira cobrança nunca antes do fim real do teste.
$fimTeste4 = assinaturaServicoPreapprovalInicioTeste('2026-11-28 22:44:34'); // 22:45 em SP
$dadosTeste = fn(string $proxima, string $status = 'authorized') => ['status' => $status, 'next_payment_date' => $proxima,
    'auto_recurring' => ['start_date' => '2026-11-28T22:45:00.000-03:00', 'frequency' => 1, 'frequency_type' => 'months']];
verificar('F4 teste: contrato em teste nunca recebe janela de antecipação (autorizar cartão ≠ antecipar cobrança)',
    assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'mensal', 'frequencia_tipo' => 'months', 'frequencia_meses' => 1, 'primeira_cobranca' => null, 'teste' => ['expira_em' => '2026-11-28 22:44:34']]) === 0
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => null, 'teste' => ['expira_em' => '2026-11-28 22:44:34']]) === 0);
verificar('F4 teste: next_payment_date na véspera do fim do teste (dentro da gratuidade) → diagnóstico específico, nunca aceito',
    assinaturaServicoPreapprovalConferirInicioExterno($dadosTeste('2026-11-27T10:00:00.000-03:00'), $fimTeste4, false, 0, true) === 'COBRANCA_EXTERNA_DENTRO_DO_TESTE');
verificar('F4 teste: mesmo no dia civil do fim, horário anterior ao fim real da gratuidade é diagnosticado (12:35 < 22:45)',
    assinaturaServicoPreapprovalConferirInicioExterno($dadosTeste('2026-11-28T12:35:09.000-03:00'), $fimTeste4, false, 0, true) === 'COBRANCA_EXTERNA_DENTRO_DO_TESTE');
verificar('F4 teste: a janela de 1 dia, se fosse passada, não neutraliza o diagnóstico do teste',
    assinaturaServicoPreapprovalConferirInicioExterno($dadosTeste('2026-11-27T23:59:00.000-03:00'), $fimTeste4, false, 1, true) === 'COBRANCA_EXTERNA_DENTRO_DO_TESTE');
verificar('F4 teste: primeira cobrança no instante do fim do teste (ou depois) é aceita; Preapproval pending não usa o diagnóstico',
    assinaturaServicoPreapprovalConferirInicioExterno($dadosTeste('2026-11-28T22:45:00.000-03:00'), $fimTeste4, false, 0, true) === null
    && assinaturaServicoPreapprovalConferirInicioExterno($dadosTeste('2026-11-29T00:00:00.000-03:00'), $fimTeste4, false, 0, true) === null
    && assinaturaServicoPreapprovalConferirInicioExterno($dadosTeste('2026-11-20T00:00:00.000-03:00', 'pending'), $fimTeste4, false, 0, true) !== 'COBRANCA_EXTERNA_DENTRO_DO_TESTE');
verificar('F4 teste: sem o contexto de teste o comportamento anterior (por dia civil) é preservado para contratos pagos',
    assinaturaServicoPreapprovalConferirInicioExterno($dadosTeste('2026-11-28T12:35:09.000-03:00'), $fimTeste4, false, 0) === null);
verificar('F4 teste: ativação e validações por contrato repassam o contexto de teste; nada é convertido nem corrigido (sem PUT, sem UPDATE de assinatura)',
    str_contains($wh4, "assinaturaServicoPreapprovalInicioTeste((string)\$assinatura['teste_expira_em']), false, 0, true)")
    && str_contains($assSrc, "'em_teste' => (\$c['teste'] ?? null) !== null") && str_contains($assSrc, "'em_teste' => (\$contrato['teste'] ?? null) !== null")
    && !preg_match("/modalidade\s*=\s*'paga'|atualizarPreapproval/", $corpo($wh4, 'mpSubCobrancaAntecipadaNaAtivacao')));
verificar('F4 primeira cobrança após o teste: débito antes do dia do fim do teste não vira competência (COBRANCA_EXTERNA_ANTECIPADA); nunca cobrança regular no teste',
    str_contains($corpo($wh4, 'mpSubResolverCobrancaConversaoTeste'), "if (\$atraso < 0) {\n        return 'COBRANCA_EXTERNA_ANTECIPADA';")
    && str_contains($corpo($wh4, 'mpSubResolverCobrancaConversaoTeste'), "Contrato em teste não tem cobrança regular.")
    && str_contains($corpo($wh4, 'mpSubResolverCobranca'), "return mpSubResolverCobrancaConversaoTeste("));

// 8, 11) Competência correta: o débito de véspera só vai à competência que COMEÇA no dia seguinte.
$mensalPaga = $cob(10, '2026-10-29', '2026-11-28', 'paga');
$mensalProx = $cob(11, '2026-11-29', '2026-12-28');
verificar('F4 mensal: débito de 28/11 → candidata é a competência que começa em 29/11; a anterior (paga, 29/10–28/11) nunca é candidata',
    array_column(mpSubCompetenciasAntecipaveis([$mensalPaga, $mensalProx], '2026-11-28', '2026-11-29'), 'id_cobranca') === [11]);
verificar('F4 mensal: competência anterior quitada (paga) não recebe outro pagamento e a seguinte já paga também não',
    mpSubCompetenciaAntecipadaElegivel($bd, $cob(10, '2026-10-29', '2026-11-28', 'paga')) === 'COMPETENCIA_ANTECIPADA_NAO_PENDENTE'
    && mpSubCompetenciaAntecipadaElegivel($bd, $cob(11, '2026-11-29', '2026-12-28', 'paga')) === 'COMPETENCIA_ANTECIPADA_NAO_PENDENTE');
verificar('F4 antecipação fora da competência correta recusada: débito 2 dias antes não alcança a competência seguinte e não cria competência',
    mpSubCompetenciasAntecipaveis([$mensalPaga, $mensalProx], '2026-11-27', '2026-11-28') === [] && !mpSubDebitoAntecipaProximaCompetencia(2, true, 1)
    && mpSubDebitoAntecipaProximaCompetencia(1, true, 1) && !mpSubDebitoAntecipaProximaCompetencia(1, false, 1));
verificar('F4 competência inexistente (mensal) é criada com as datas normais da sequência, nunca deslocadas para o dia do débito',
    str_contains($corpo($wh4, 'mpSubResolverCobranca'), "\$esperado->format('Y-m-d'),\n            \$fim->format('Y-m-d'),") && str_contains($corpo($wh4, 'mpSubResolverCobranca'), '$antecipada = mpSubDebitoAntecipaProximaCompetencia('));

// Ciclo diário legado: a competência "exata" quitada cede à próxima; nas demais periodicidades/ciclos jamais cede.
$dia1 = $cob(20, '2026-10-30', '2026-10-30', 'paga');
verificar('F4 diário legado: o débito no único dia da competência quitada segue para a próxima; mensal, trimestral e diário de 2 dias nunca cedem',
    mpSubExataQuitadaCedeAAntecipacao($dia1, 'diaria', 1, 1) === true && mpSubExataQuitadaCedeAAntecipacao($dia1, 'diaria', 2, 1) === false
    && mpSubExataQuitadaCedeAAntecipacao($dia1, 'mensal', 1, 1) === false && mpSubExataQuitadaCedeAAntecipacao($dia1, 'anual', 1, 1) === false
    && mpSubExataQuitadaCedeAAntecipacao($cob(21, '2026-10-30', '2026-10-30', 'pendente'), 'diaria', 1, 1) === false
    && mpSubExataQuitadaCedeAAntecipacao($dia1, 'diaria', 1, 0) === false);

// 12-13, 19) PIX aprovado/em andamento e isolamento entre empresas (competência mensal seguinte).
$limpar();
verificar('F4 mensal: competência seguinte livre é elegível', mpSubCompetenciaAntecipadaElegivel($bd, $mensalProx) === ['cobranca' => $mensalProx, 'criada' => false]);
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_empresa, id_cobranca, metodo, status) VALUES (5, 11, 'pix', 'confirmada')");
verificar('F4 PIX já aprovado na competência seguinte bloqueia o débito do cartão (conciliação, sem quitar duas vezes)', mpSubCompetenciaAntecipadaElegivel($bd, $mensalProx) === 'COMPETENCIA_ANTECIPADA_COM_PIX');
$limpar();
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_empresa, id_cobranca, metodo, status) VALUES (5, 11, 'pix', 'aguardando')");
verificar('F4 PIX em andamento na competência seguinte bloqueia o débito do cartão', mpSubCompetenciaAntecipadaElegivel($bd, $mensalProx) === 'COMPETENCIA_ANTECIPADA_COM_PIX');
$limpar();
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_empresa, id_cobranca, metodo, status) VALUES (6, 11, 'pix', 'confirmada')");
$GLOBALS['pdo']->exec("INSERT INTO pagamento (id_empresa, id_cobranca, status) VALUES (6, 11, 'confirmado')");
verificar('F4 isolamento entre empresas: PIX e pagamento de outra empresa não interferem na competência desta', mpSubCompetenciaAntecipadaElegivel($bd, $mensalProx) === ['cobranca' => $mensalProx, 'criada' => false]);
$limpar();
verificar('F4 consultas do resolvedor sempre escopadas por empresa (assinatura + empresa; transação e pagamento por id_empresa)',
    str_contains($corpo($wh4, 'mpSubResolverCobranca'), 'WHERE id_assinatura = ? AND id_empresa = ? ORDER BY periodo_inicio')
    && str_contains($corpo($wh4, 'mpSubCompetenciaAntecipadaElegivel'), 'WHERE id_empresa = ? AND id_cobranca = ?'));

// 14-18) Estados da fatura e do pagamento (nada é quitado sem fatura processada + pagamento approved/accredited).
$f4 = fn(string $status, ?string $pag = null) => ['status' => $status, 'pagamento_id' => $pag];
$p4 = fn(string $status, ?string $det = null) => ['status' => $status, 'detalhe' => $det, 'valor_centavos' => 499, 'reembolsado_centavos' => 0];
verificar('F4 pagamento do cartão recusado: recusada, nada quitado', mpSubClassificarFatura($f4('processed', 'P1'), $p4('rejected', 'cc_rejected_insufficient_amount'))['status'] === 'recusada'
    && mpSubClassificarFatura($f4('processed', 'P1'), $p4('rejected', 'cc_rejected_insufficient_amount'))['classe'] !== 'confirmada');
verificar('F4 pagamento aprovado porém não acreditado: conciliação, nada quitado', mpSubClassificarFatura($f4('processed', 'P1'), $p4('approved', 'pending_contingency'))['classe'] === 'conciliacao');
verificar('F4 fatura em processamento (scheduled, sem pagamento): aguardando, nada quitado', mpSubClassificarFatura($f4('scheduled'), null)['classe'] === 'normal' && mpSubClassificarFatura($f4('scheduled'), null)['status'] === 'aguardando');
verificar('F4 retentativa recycling: continua processando sem quitar e sem nova competência', (function () use ($f4, $p4) {
    $c = mpSubClassificarFatura($f4('recycling', 'P1'), $p4('rejected', 'cc_rejected_high_risk'));
    return $c['status'] === 'processando' && $c['classe'] === 'normal';
})());
verificar('F4 única confirmação: processed + approved + accredited', mpSubClassificarFatura($f4('processed', 'P1'), $p4('approved', 'accredited'))['classe'] === 'confirmada');
$aplicar4 = $corpo($wh4, 'mpSubAplicarFatura');
verificar('F4 webhook duplicado: fatura já conhecida usa a transação existente (fatura_externa_id) e o resolvedor só roda para fatura nova; nenhum pagamento artificial é criado na ativação',
    str_contains($aplicar4, 'fatura_externa_id = ?') && str_contains($aplicar4, '$existente !== null') && str_contains($aplicar4, 'mpSubResolverCobranca($conexao, $assinatura, $fatura[\'debito\'], $idAspr)')
    && !preg_match('/INSERT INTO pagamento/', $corpo($wh4, 'mpSubCobrancaAntecipadaNaAtivacao')));
verificar('F4 débito identificado pelo debit_date da fatura autoritativa, nunca por next_payment_date',
    !str_contains($corpo($wh4, 'mpSubResolverCobranca'), 'next_payment_date') && str_contains($corpo($wh4, 'mpSubNormalizarFatura'), "'debito' => assinaturaServicoPreapprovalDataExterna(\$d['debit_date']"));

// 22) Cancelamento automático: serviço compartilhado preservado e elegibilidade do novo estado "ativa".
$cr4 = $ler('backend/_servicos/cancelamento_recorrencia.php');
$btn4 = $corpo($po, 'pagamentoCartaoCancelarAutomatico');
verificar('F4 cancelamento: proteções e mensagens do serviço compartilhado preservadas (nenhuma alteração nesta fase)',
    CANCELAMENTO_RECORRENCIA_MSG_PRIMEIRO === 'Para cancelar esta assinatura, primeiro cancele o pagamento automático.' && str_contains($cr4, 'cancelamentoRecorrenciaVerificarBloqueio(')
    && str_contains($cr4, 'cancelamentoRecorrenciaCancelarPreapproval(') && str_contains($cr4, 'cancelamentoRecorrenciaParcelaEmAndamento('));
verificar('F4 cancelamento: o vínculo "ativa"+authorized+slot 1 passa a ser elegível pelo caminho já existente (configurado), sem relaxar as regras do serviço',
    str_contains($dp, "\$configurado = \$atual && \$status === 'ativa' && (\$v['status_externo'] ?? '') === 'authorized' && (int)(\$v['slot_ativo'] ?? 0) === 1;")
    && str_contains($btn4, 'cancelamentoRecorrenciaCancelarPreapproval(') && !str_contains($btn4, 'cancelamentoRecorrenciaVerificarBloqueio'));

// 23) Correção da empresa 37 preservada e compatível.
verificar('F4 correção da empresa 37 preservada: referência .d2 pesquisável, estados da criação incerta e recuperação sem criar',
    MercadoPagoGateway::referenciaExternaValida($ref . '.d2') && function_exists('pagamentoCartaoEstadoCriacao') && function_exists('assinaturaServicoPreapprovalEstadoBusca')
    && substr_count($assSrc, '->criarPreapproval(') === 1 && str_contains($jsPo, 'TEXTO_CADASTRO_INCERTO'));
verificar('F4 compatibilidade 37 × 34/35: o resolvedor de busca e a política de antecipação são independentes (sem tocar no outbox)',
    !str_contains($corpo($assSrc, 'assinaturaServicoPreapprovalAntecipacaoDias'), 'operacao_assinatura_gateway') && !str_contains($corpo($wh4, 'mpSubExataQuitadaCedeAAntecipacao'), 'operacao_assinatura_gateway'));

// ---------------------------------------------------------------------------------------------------------------
// Complemento da recuperação do cadastro de cartão: tentativa, checkout aberto e cartão autorizado são coisas distintas.
// ---------------------------------------------------------------------------------------------------------------
$op = fn(string $st, ?string $erro, int $idade) => ['status' => $st, 'erro_codigo' => $erro, 'idade_segundos' => $idade];
verificar('F37b falha ANTES do envio: operação interrompida sem a marca ENVIO_INICIADO prova que o POST não saiu → nao_criada (Tentar novamente seguro)',
    pagamentoCartaoEstadoCriacao($vp, $op('processando', null, 4000)) === 'nao_criada');
verificar('F37b falha comprovada e já encerrada (sem operação aberta) → nao_criada', pagamentoCartaoEstadoCriacao($vp, null) === 'nao_criada');
verificar('F37b operação RECENTE sem marca ainda é "em andamento": nada é liberado enquanto ela pode estar em execução', pagamentoCartaoEstadoCriacao($vp, $op('processando', null, 30)) === 'em_andamento');
verificar('F37b criação ENVIADA com resultado incerto (marca ENVIO_INICIADO ou conciliação): nunca libera nova criação',
    pagamentoCartaoEstadoCriacao($vp, $op('processando', 'ENVIO_INICIADO', 4000)) === 'incerta' && pagamentoCartaoEstadoCriacao($vp, $op('conciliacao', 'RESULTADO_INCERTO', 4000)) === 'incerta'
    && pagamentoCartaoEstadoCriacao($vp, $op('conciliacao', 'EXISTENCIA_NAO_CONFIRMADA', 90000)) === 'incerta' && pagamentoCartaoEstadoCriacao($vp, $op('conciliacao', 'CONCILIACAO_INDISPONIVEL', 400)) === 'incerta');
verificar('F37b buscas vazias ou tempo decorrido não são prova: EXISTENCIA_NAO_CONFIRMADA após dias continua incerta (resultados tardios continuam sendo procurados pelo cron)',
    pagamentoCartaoEstadoCriacao($vp, $op('conciliacao', 'EXISTENCIA_NAO_CONFIRMADA', 86400 * 30)) === 'incerta'
    && str_contains($corpo($assSrc, 'assinaturaServicoPreapprovalEncerrarOperacao'), 'WHEN tentativas_processamento <= 15 THEN 720') && str_contains($corpo($assSrc, 'assinaturaServicoPreapprovalEncerrarOperacao'), 'ELSE 1440 END MINUTE'));
$motivoSql = $corpo($assSrc, 'assinaturaServicoPreapprovalCadastroCartaoMotivo');
verificar('F37b o servidor libera a nova tentativa pela mesma regra: operação interrompida antes do envio não conta como "operação externa pendente"',
    str_contains($motivoSql, "AND NOT (o.status_processamento = 'processando' AND o.erro_codigo IS NULL")
    && str_contains($motivoSql, 'ASSINATURA_SERVICO_PREAPPROVAL_OPERACAO_INTERROMPIDA_SEGUNDOS') && str_contains($assSrc, 'const ASSINATURA_SERVICO_PREAPPROVAL_OPERACAO_INTERROMPIDA_SEGUNDOS = 180;'));
verificar('F37b o estado do servidor e a reserva usam o mesmo prazo (180 s) e a mesma regra de "não enviada"',
    str_contains($resv, "\$aberta['erro_codigo'] === null") && str_contains($resv, "'TENTATIVA_NAO_ENVIADA'") && str_contains($resv, '> ASSINATURA_SERVICO_PREAPPROVAL_OPERACAO_INTERROMPIDA_SEGUNDOS')
    && str_contains($corpo($po, 'pagamentoCartaoEstadoCriacao'), '<= 180'));
verificar('F37b a nova tentativa depois de falha antes do envio reaproveita o vínculo e a referência (sem segundo vínculo) e só então faz um único POST',
    str_contains($resv, "\$acao = 'criar';") && substr_count($assSrc, '->criarPreapproval(') === 1 && str_contains($env, 'ENVIO_INICIADO')
    && str_contains($consultar, 'assinaturaServicoPreapprovalConciliar(') && !str_contains($consultar, '->criarPreapproval('));

// Autorização confirmada x checkout aberto x tentativa.
verificar('F37b payload distingue checkout aberto (pending) de cartão autorizado (authorized); vínculo "pendente" sozinho não é cartão',
    str_contains($dpNovo, "'checkout_aberto' => \$criada && (\$v['status_externo'] ?? null) === 'pending'") && str_contains($dpNovo, "'autorizacao_confirmada' => \$criada && (\$v['status_externo'] ?? null) === 'authorized'")
    && str_contains($tg, "'autorizacao_confirmada' => \$externa && (\$v['status_externo'] ?? null) === 'authorized'"));
verificar('F37b "Cancelar cartão autorizado" só aparece com autorização confirmada; sem ela o botão é "Desistir do cadastro do cartão"',
    str_contains($jsPo, 't.autorizacao_confirmada === true ? "Cancelar cartão autorizado" : "Desistir do cadastro do cartão"')
    && substr_count($jsPo, '"Cancelar cartão autorizado"') === 2 && str_contains($jsPo, 'teste.em_teste && teste.autorizacao_confirmada !== true'));
verificar('F37b o diálogo de desistir informa que não há cartão autorizado e que o teste grátis continua',
    str_contains($jsPo, 'não há cartão autorizado') && str_contains($jsPo, 'Seu teste grátis continua até o fim'));
verificar('F37b checkout recuperável: pending encontrado vira "Continuar no Mercado Pago" com a mesma Preapproval (sem novo POST); authorized sincroniza',
    str_contains($jsPo, 'criada ? "Continuar no Mercado Pago"') && str_contains($vinc, 'assinaturaServicoPreapprovalValidarExterna(') && str_contains($po, "\$statusExterno !== '' && \$statusExterno !== 'pending'")
    && str_contains($po, 'JA_VINCULADA') === str_contains($po, 'JA_VINCULADA') && str_contains($assSrc, "'JA_VINCULADA'"));

// Interface: sem ações enganosas quando a recuperação não é segura.
$tentRender = substr($jsPo, (int)strpos($jsPo, 'cadastro_incerto: ['), 600);
verificar('F37b interface incerta: explica que a tentativa anterior está sendo verificada e informa como falar com o suporte',
    str_contains($jsPo, 'A tentativa anterior de cadastrar o cartão está sendo verificada no Mercado Pago') && str_contains($jsPo, 'suporteamagenda@gmail.com') && str_contains($jsPo, '(38) 99896-9407')
    && str_contains($tentRender, 'TEXTO_CADASTRO_INCERTO'));
verificar('F37b interface incerta: só Atualizar situação (pode_configurar e pode_remover_cartao são decididos pelo servidor e ficam falsos)',
    str_contains($dpNovo, "'pode_verificar' => \$habilitado && (\$criada || in_array(\$estadoCriacao, ['em_andamento', 'incerta'], true))")
    && str_contains($tg, "!in_array(\$situacao, ['processando', 'cadastro_incerto', 'cadastro_nao_criado'], true)") && str_contains($assSrc, "return ['motivo' => 'OPERACAO_EXTERNA_PENDENTE'"));
verificar('F37b "Tentar novamente" só em cadastro_nao_criado/pendente sem Preapproval (servidor libera pode_configurar)',
    str_contains($jsPo, '(t.situacao === "cadastro_nao_criado" ? "Tentar novamente" : "Cadastrar cartão")') && substr_count($jsPo, '"Tentar novamente"') === 2);

// Recuperação administrativa: não existe reset forçado; permissões das rotas existentes.
$api4 = (string)file_get_contents(__DIR__ . '/../public/api/api_central.php');
verificar('F37b não existe rota de liberação/reset de operação externa incerta (nenhum reset forçado foi introduzido)',
    !preg_match("/'[^']*(cartao|pagamento|assinatura|operacao|mercado)[^']*(liberar|resetar|forcar|descartar|encerrar|reabrir)[^']*'\s*=>/i", $api4)
    && preg_match("/'superadmin\/integracoes\/mercado-pago' => \[\s*'GET' =>/", $api4) === 1);
verificar('F37b o diagnóstico do Super Admin é somente leitura (apenas GET) e as rotas do cartão do proprietário exigem permissão e CSRF',
    !preg_match("/'superadmin\/integracoes\/mercado-pago' => \[[^\]]*'POST'/s", $api4)
    && str_contains(str_replace("\r\n", "\n", $po), "if (\$rota === 'painel/faturamento/pagamento/cartao/autorizar' && \$metodo === 'POST') {\n        exigirPermissao(\$conexao, 'faturamento.pagar', \$contexto);\n        csrfValidarSessao();"));
verificar('F37b conciliação externa continua somente leitura (GET) e preserva vínculo e operação histórica (nenhum DELETE de vínculo/operação)',
    !preg_match('/DELETE FROM (assinatura_pagamento_recorrente|operacao_assinatura_gateway)/', $assSrc . $po . $wh4) && !str_contains($loc, '->criarPreapproval(') && !str_contains($loc, 'atualizarPreapproval'));
verificar('F37b teste grátis protegido: nenhuma das mudanças deste complemento grava em assinatura (vigência, modalidade ou datas do teste)',
    !preg_match('/UPDATE assinatura\s/', $corpo($po, 'pagamentoCartaoEstadoCriacao') . $corpo($po, 'pagamentoCartaoCriacaoOperacao') . $corpo($po, 'pagamentoCartaoTesteGratis') . $corpo($po, 'pagamentoCartaoDadosPreapproval')));

// ---------------------------------------------------------------------------------------------------------------
// Operação incerta (empresa 37): evidência externa somente leitura, pré-verificação antes de criar e resolução administrativa.
// ---------------------------------------------------------------------------------------------------------------
$GLOBALS['pdo']->exec('ALTER TABLE operacao_assinatura_gateway ADD COLUMN id_empresa INT');
$GLOBALS['f5'] = ['esclarecer' => 0, 'marcar' => [], 'encerrar' => [], 'erro' => []];
function assinaturaServicoPreapprovalEsclarecer(object $conexao, object $gateway, array $reserva, ?string $email): array
{
    $GLOBALS['f5']['esclarecer']++;

    return assinaturaServicoNovaContratacaoResultado(true, 'PREAPPROVAL_VINCULADA', 'vinculada', ['estado' => 'vinculada']);
}
function assinaturaServicoPreapprovalMarcarConciliacao(object $conexao, array $vinculo, int $idOperacao, string $codigo, string $motivo): void
{
    $GLOBALS['f5']['marcar'][] = $codigo;
}
function assinaturaServicoPreapprovalEncerrarOperacao(object $conexao, int $idOperacao, string $status, ?string $codigo, ?string $motivo): bool
{
    $GLOBALS['f5']['encerrar'][] = [$status, $codigo];

    return true;
}
function assinaturaServicoPreapprovalRegistrarErro(object $conexao, array $vinculo, string $codigo, string $motivo, bool $definitivo): void
{
    $GLOBALS['f5']['erro'][] = [$codigo, $definitivo];
}
$extrair('backend/_servicos/assinatura.php', ['assinaturaServicoPreapprovalConferirAntesDeCriar']);

$tentativa = new DateTimeImmutable('2026-10-29 22:46:28', new DateTimeZone('America/Sao_Paulo'));
$ctaReal = array_merge(
    array_map(fn($n) => $item('C' . $n, 'amg_' . str_pad((string)$n, 32, '0', STR_PAD_LEFT), 'cancelled', '2026-10-27T12:00:00.000-04:00'), range(1, 18)),
    [$item('P1', 'amg_' . str_repeat('1', 32), 'pending', '2026-10-27T19:36:24.000-04:00'), $item('P2', 'amg_' . str_repeat('2', 32), 'pending', '2026-10-27T21:01:19.000-04:00'),
        $item('P3', 'amg_' . str_repeat('3', 32), 'pending', '2026-10-28T21:01:18.000-04:00'),
        $item('A1', 'amg_' . str_repeat('4', 32) . '.d2', 'authorized', '2026-10-29T15:31:27.000-04:00'), $item('A2', 'amg_' . str_repeat('5', 32), 'authorized', '2026-10-29T11:35:09.000-04:00')]
);
$ev = assinaturaServicoPreapprovalEvidenciaExterna($conta($ctaReal), $refD2, $tentativa);
verificar('F5 evidência da conta real (23 itens, nenhum com a referência, mais recente ANTES da tentativa): enumeração completa, sem correspondência, sem marca d\'água',
    $ev['estado'] === 'ok' && $ev['completa'] === true && $ev['itens'] === 23 && $ev['ids'] === [] && $ev['status_honrado'] === true && $ev['marca_dagua'] === false);
// ATUALIZADO (F37c): a conferência do Super Admin é estruturada (fonte, data/hora, identificação da evidência); texto livre sozinho não vale.
$agoraF5 = new DateTimeImmutable('2026-10-30 12:00:00', new DateTimeZone('America/Sao_Paulo'));
$conf = fn(string $descricao = 'Painel MP > Assinaturas, filtro pelo e-mail do pagador: nenhuma da referência.', string $quando = '2026-10-30 09:15', string $fonte = 'painel_mercado_pago')
    => ['fonte' => $fonte, 'conferido_em' => $quando, 'descricao' => $descricao];
$avaliar = fn(array $e, ?array $a) => assinaturaServicoPreapprovalAvaliarEvidencia($e, $a, $tentativa, $agoraF5);
// Empresa 37 (operação 30, EXISTENCIA_NAO_CONFIRMADA): a conta real sem correspondência e a conferência do responsável no
// painel do Mercado Pago bastam para a resolução administrativa; sem a conferência, ou com qualquer correspondência, não.
$evCancelada = assinaturaServicoPreapprovalEvidenciaExterna($conta(array_merge($ctaReal, [$item('C37', $refD2, 'cancelled')])), $refD2, $tentativa);
verificar('EMPRESA 37 conclusões: conta sem correspondência + conferência no painel = aprovada; sem conferência = indica a evidência faltante; Preapproval da tentativa (mesmo cancelada) = impedida; provedor fora = preservada',
    $avaliar($ev, $conf())['codigo'] === 'EVIDENCIA_COM_ATESTADO' && $avaliar($ev, $conf())['liberar'] === true
    && $avaliar($ev, null) === ['liberar' => false, 'codigo' => 'ATESTADO_NECESSARIO', 'atestado_necessario' => true, 'atestado_motivo' => 'ATESTADO_AUSENTE']
    && $evCancelada['ids'] === ['C37'] && $avaliar($evCancelada, $conf())['codigo'] === 'PREAPPROVAL_EXTERNA_ENCONTRADA'
    && $avaliar(assinaturaServicoPreapprovalEvidenciaExterna($conta([], ['sucesso' => false, 'codigo' => 'falha_transporte']), $refD2, $tentativa), $conf())['codigo'] === 'EVIDENCIA_INDISPONIVEL'
    && assinaturaServicoPreapprovalReferenciaRotacionada($refD2) === $ref . '.r2.d2');
verificar('EMPRESA 37 a operação incerta criada pelo cadastro (tipo conciliar) é exatamente a que a resolução encerra; a nova tentativa reaproveita a referência rotacionada do vínculo',
    (function () use ($corpo, $assSrc): bool {
        $reservar = $corpo($assSrc, 'assinaturaServicoPreapprovalReservar');
        $resolver = $corpo($assSrc, 'assinaturaServicoPreapprovalResolverCriacaoIncerta');
        $novo = strpos($reservar, 'if ($intencaoNova) {');
        $inserir = strpos($reservar, 'assinaturaServicoPreapprovalInserirIntencao(');

        return str_contains($reservar, "VALUES (?, ?, 'conciliar', ?, 'processando', 1, NOW(6))")
            && str_contains($resolver, "tipo_operacao = 'conciliar'") && str_contains($resolver, "status_processamento IN ('pendente', 'processando', 'conciliacao') LIMIT 1")
            // Só um vínculo novo ganha referência nova; o vínculo existente (resolvido) segue com a referência rotacionada.
            && substr_count($reservar, 'assinaturaServicoPreapprovalInserirIntencao(') === 1 && $novo !== false && $inserir > $novo
            && $inserir < strpos($reservar, '} else {', $novo);
    })());
verificar('F5 sem marca d\'água e sem atestado: NÃO libera (resultado vazio de pesquisa não é prova)', $avaliar($ev, null) === ['liberar' => false, 'codigo' => 'ATESTADO_NECESSARIO', 'atestado_necessario' => true, 'atestado_motivo' => 'ATESTADO_AUSENTE']);
verificar('F5 atestado curto não vale; atestado com a conferência no painel (≥ 20 caracteres) vale e fica registrado',
    $avaliar($ev, $conf('ok'))['liberar'] === false && $avaliar($ev, $conf('   curto   '))['atestado_motivo'] === 'ATESTADO_DESCRICAO_INVALIDA'
    && $avaliar($ev, $conf()) === ['liberar' => true, 'codigo' => 'EVIDENCIA_COM_ATESTADO', 'atestado_necessario' => false, 'atestado_motivo' => null]);
verificar('F37c texto livre de 20+ caracteres, isoladamente, não é prova: sem fonte válida ou sem data/hora da conferência não libera',
    $avaliar($ev, ['descricao' => 'Conferido no painel do Mercado Pago: nenhuma assinatura.'])['atestado_motivo'] === 'ATESTADO_FONTE_INVALIDA'
    && $avaliar($ev, $conf(fonte: 'whatsapp'))['atestado_motivo'] === 'ATESTADO_FONTE_INVALIDA'
    && $avaliar($ev, ['fonte' => 'painel_mercado_pago', 'descricao' => 'Conferido no painel do Mercado Pago: nenhuma assinatura.'])['atestado_motivo'] === 'ATESTADO_DATA_INVALIDA');
verificar('F37c data da conferência: antes da tentativa, no futuro ou mal formatada não vale; logo depois da tentativa e via suporte vale',
    $avaliar($ev, $conf(quando: '2026-10-29 20:00'))['atestado_motivo'] === 'ATESTADO_DATA_INVALIDA' && $avaliar($ev, $conf(quando: '2026-10-31 09:00'))['atestado_motivo'] === 'ATESTADO_DATA_INVALIDA'
    && $avaliar($ev, $conf(quando: '30/10/2026 09:15'))['atestado_motivo'] === 'ATESTADO_DATA_INVALIDA' && $avaliar($ev, $conf(quando: '2026-10-30 25:99'))['atestado_motivo'] === 'ATESTADO_DATA_INVALIDA'
    && $avaliar($ev, $conf(quando: '2026-10-29 22:50', fonte: 'suporte_mercado_pago'))['liberar'] === true);
$res37 = assinaturaServicoPreapprovalResumoEvidencia($ev, $avaliar($ev, null), '2026-10-29 22:46:28');
verificar('F37c resumo separa os quatro estados: consulta completa, referência não localizada, existência externa NÃO CONFIRMADA (nunca "inexistente") e evidência ainda insuficiente',
    $res37['consulta_completa'] === true && $res37['referencia_localizada'] === false && $res37['existencia_externa'] === 'nao_confirmada' && $res37['evidencia_suficiente'] === false
    && $res37['itens_varridos'] === 23 && $res37['cobertura_indice'] === false);
$gTudoLeitura = $conta($ctaReal);
assinaturaServicoPreapprovalEvidenciaExterna($gTudoLeitura, $refD2, $tentativa);
verificar('F5 a evidência é SOMENTE LEITURA e independente do filtro de referência: só consultas de busca por status, sem external_reference',
    count($gTudoLeitura->chamadas) >= 4 && array_unique(array_column($gTudoLeitura->chamadas, 'status')) === ['pending', 'authorized', 'paused', 'cancelled'] && !in_array(true, array_map(fn($c) => isset($c['external_reference']), $gTudoLeitura->chamadas), true));
$posterior = array_merge($ctaReal, [$item('N1', 'amg_' . str_repeat('9', 32), 'pending', '2026-10-30T09:00:00.000-04:00')]);
$evMarca = assinaturaServicoPreapprovalEvidenciaExterna($conta($posterior), $refD2, $tentativa);
// ATUALIZADO (F37c): a marca d'água só indica cobertura do índice; sozinha NUNCA libera (antes liberava sem atestado).
verificar('F5 existe Preapproval mais recente que a tentativa (margem de 10 min): cobertura do índice registrada, mas sem a conferência continua bloqueado',
    $evMarca['marca_dagua'] === true && $avaliar($evMarca, null)['codigo'] === 'ATESTADO_NECESSARIO' && $avaliar($evMarca, null)['liberar'] === false
    && $avaliar($evMarca, $conf())['liberar'] === true && assinaturaServicoPreapprovalResumoEvidencia($evMarca, $avaliar($evMarca, null), '2026-10-29 22:46:28')['cobertura_indice'] === true);
$logoApos = array_merge($ctaReal, [$item('N2', 'amg_' . str_repeat('8', 32), 'pending', '2026-10-29T21:50:00.000-04:00')]); // 22:50 em SP: 4 min após a tentativa
verificar('F5 Preapproval só alguns minutos depois da tentativa (dentro da margem) não prova cobertura do índice', assinaturaServicoPreapprovalEvidenciaExterna($conta($logoApos), $refD2, $tentativa)['marca_dagua'] === false);
foreach (['mesma base com outro sufixo' => 'amg_ba8bcfaaed88d60a4fbfd485a191550f', 'mesma referência' => 'amg_ba8bcfaaed88d60a4fbfd485a191550f.d2'] as $nome => $refExterna) {
    $com = array_merge($ctaReal, [$item('LATE-1', $refExterna, 'pending', '2026-10-29T21:47:00.000-04:00')]);
    $evx = assinaturaServicoPreapprovalEvidenciaExterna($conta($com), 'amg_ba8bcfaaed88d60a4fbfd485a191550f.d2', $tentativa);
    verificar("F5 criação tardia encontrada ($nome): nunca libera nova criação; a recuperação é pela conciliação", $evx['ids'] === ['LATE-1'] && $avaliar($evx, $conf())['codigo'] === 'PREAPPROVAL_EXTERNA_ENCONTRADA');
}
$orf = array_merge($ctaReal, [$item('LATE-2', 'amg_ba8bcfaaed88d60a4fbfd485a191550f.d2', 'cancelled', '2026-10-29T21:47:00.000-04:00')]);
verificar('F5 até uma Preapproval tardia já cancelada é achada e impede a liberação (a tentativa existiu)', $avaliar(assinaturaServicoPreapprovalEvidenciaExterna($conta($orf), 'amg_ba8bcfaaed88d60a4fbfd485a191550f.d2', $tentativa), $conf())['codigo'] === 'PREAPPROVAL_EXTERNA_ENCONTRADA');
$muitas = array_map(fn($n) => $item('M' . $n, 'amg_' . str_pad((string)$n, 32, '0', STR_PAD_LEFT)), range(1, 1100));
$evInc = assinaturaServicoPreapprovalEvidenciaExterna($conta($muitas), $refD2, $tentativa);
verificar('F5 enumeração incompleta (mais itens que o limite de páginas): nunca libera, nem com atestado', $evInc['completa'] === false && $avaliar($evInc, $conf())['codigo'] === 'EVIDENCIA_INCOMPLETA');
$gSemStatus = $conta($ctaReal);
$gSemStatus->honraStatus = false;
verificar('F5 se o provedor deixar de honrar o filtro de status, a varredura não é enumeração: nunca libera', $avaliar(assinaturaServicoPreapprovalEvidenciaExterna($gSemStatus, $refD2, $tentativa), $conf())['codigo'] === 'EVIDENCIA_INCOMPLETA');
verificar('F5 consulta indisponível ou recusada nunca libera',
    $avaliar(assinaturaServicoPreapprovalEvidenciaExterna($conta([], ['sucesso' => false, 'codigo' => 'falha_transporte']), $refD2, $tentativa), $conf())['codigo'] === 'EVIDENCIA_INDISPONIVEL'
    && $avaliar(assinaturaServicoPreapprovalEvidenciaExterna($conta([], ['sucesso' => false, 'codigo' => 'provedor_rejeitou', 'http_status' => 400]), $refD2, $tentativa), $conf())['codigo'] === 'CONSULTA_RECUSADA');
verificar('F5 conta vazia com filtro honrado é só "pesquisa vazia": exige atestado, jamais libera sozinha', $avaliar(assinaturaServicoPreapprovalEvidenciaExterna($conta([]), $refD2, $tentativa), null)['codigo'] === 'ATESTADO_NECESSARIO');

// Pré-verificação antes de uma nova criação (vínculo com tentativa anterior): nunca cria às cegas.
$GLOBALS['pdo']->exec("INSERT INTO operacao_assinatura_gateway (id_operacao, id_assinatura_pagamento, status_processamento, id_empresa) VALUES (100, 24, 'cancelada', 37), (101, 24, 'processando', 37), (200, 25, 'processando', 37)");
$reservaPrev = fn(int $vinc, int $op) => ['vinculo' => ['id_assinatura_pagamento' => $vinc, 'id_empresa' => 37, 'referencia_externa_assinatura' => $refD2], 'operacao' => ['id_operacao' => $op]];
$zerar = function () { $GLOBALS['f5'] = ['esclarecer' => 0, 'marcar' => [], 'encerrar' => [], 'erro' => []]; };
$zerar();
$g = $conta([$item('X', 'amg_' . str_repeat('7', 32))]);
verificar('F5 primeira criação do vínculo (sem tentativa anterior): sem consulta prévia, segue para o POST', assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaPrev(25, 200), 'a@b.co') === null && $g->chamadas === []);
$g = $conta([$item('X', 'amg_' . str_repeat('7', 32))]);
verificar('F5 nova criação após tentativa anterior: pesquisa completa sem correspondência → segue para o POST (única criação)', assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaPrev(24, 101), 'a@b.co') === null && count($g->chamadas) === 3);
$g = $conta([$item('PRE-9', $refD2)]);
$r = assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaPrev(24, 101), 'a@b.co');
verificar('F5 criação tardia encontrada antes de criar: vincula/recupera (Esclarecer) e NÃO segue para o POST', $r !== null && $r['ok'] === true && $GLOBALS['f5']['esclarecer'] === 1);
$zerar();
$g = $conta([$item('PRE-1', $refD2), $item('PRE-2', $refD2, 'authorized')]);
$r = assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaPrev(24, 101), 'a@b.co');
verificar('F5 duplicidade externa comprovada: bloqueia apenas a nova criação, em conciliação, com o motivo', $r !== null && $r['ok'] === false && $r['codigo'] === 'MULTIPLAS_ASSINATURAS_EXTERNAS' && $GLOBALS['f5']['marcar'] === ['MULTIPLAS_ASSINATURAS_EXTERNAS']);
$zerar();
$r = assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $conta([], ['sucesso' => false, 'codigo' => 'falha_transporte']), $reservaPrev(24, 101), 'a@b.co');
verificar('F5 pesquisa indisponível antes de criar: nada é criado (503), só esta tentativa é encerrada para repetir depois',
    $r !== null && $r['ok'] === false && $r['codigo'] === 'CONSULTA_PREVIA_INDISPONIVEL' && $r['http_status'] === 503 && $GLOBALS['f5']['encerrar'] === [['erro', 'CONSULTA_PREVIA_INDISPONIVEL']] && $GLOBALS['f5']['erro'] === [['CONSULTA_PREVIA_INDISPONIVEL', false]]);
$zerar();
$r = assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $conta([], ['sucesso' => false, 'codigo' => 'operacao_invalida']), $reservaPrev(24, 101), 'a@b.co');
verificar('F5 pesquisa recusada antes de criar: nada é criado', $r !== null && $r['codigo'] === 'CONSULTA_PREVIA_RECUSADA' && $GLOBALS['f5']['esclarecer'] === 0);
$ini5 = $corpo($assSrc, 'assinaturaServicoPreapprovalIniciar');
verificar('F5 Iniciar: a pré-verificação roda depois da reserva e ANTES do único POST', strpos($ini5, 'assinaturaServicoPreapprovalConferirAntesDeCriar(') !== false
    && strpos($ini5, 'assinaturaServicoPreapprovalConferirAntesDeCriar(') < strpos($ini5, 'assinaturaServicoPreapprovalEnviar(') && substr_count($assSrc, '->criarPreapproval(') === 1);

// Resolução administrativa: permissões, trava, auditoria e preservação do histórico.
$res = $corpo($assSrc, 'assinaturaServicoPreapprovalResolverCriacaoIncerta');
verificar('F5 resolução: duas fases — sem confirmação nada é gravado; a evidência é calculada antes de qualquer transação',
    strpos($res, "'CONFIRMACAO_OBRIGATORIA'") !== false && strpos($res, "'CONFIRMACAO_OBRIGATORIA'") < strpos($res, 'assinaturaServicoPreapprovalTransacao(')
    && strpos($res, 'assinaturaServicoPreapprovalAvaliarEvidencia(') < strpos($res, "'CONFIRMACAO_OBRIGATORIA'") && str_contains($res, '$ator === null'));
verificar('F5 resolução: só opera sobre vínculo sem id externo e operação em conciliação; operação em andamento ou inexistente é recusada',
    str_contains($res, "\$vinculo['assinatura_externa_id'] !== null") && str_contains($res, "(string)\$operacao['status_processamento'] !== 'conciliacao'") && str_contains($res, 'OPERACAO_EM_ANDAMENTO') && str_contains($res, 'SEM_CRIACAO_INCERTA'));
verificar('F5 resolução: dentro da transação, relê sob FOR UPDATE e aborta se algo mudou (cron, vínculo criado, nova tentativa) — sem corrida',
    str_contains($res, 'FOR UPDATE') && str_contains($res, "'ESTADO_ALTERADO'") && str_contains($res, "(int)\$atual['tentativas_processamento'] !== (int)\$operacao['tentativas_processamento']") && str_contains($res, "AND status_processamento = 'conciliacao'"));
verificar('F5 resolução: preserva a operação histórica (status cancelada, motivo e erro registrados), o vínculo (só o sinal do último erro) e o contrato; nada é apagado',
    str_contains($res, "SET status_processamento = 'cancelada', erro_codigo = 'ENCERRADA_SEM_PREAPPROVAL_EXTERNA'") && !preg_match('/DELETE\s+FROM/', $res)
    && !preg_match('/UPDATE assinatura\s/', $res) && !preg_match('/UPDATE assinatura_pagamento_recorrente\s+SET status\b/', $res) && !str_contains($res, 'chave_operacao')
    // ATUALIZADO (F37c): a referência da PRÓXIMA tentativa é rotacionada (mesma base), só com o vínculo sem id externo e com a
    // referência antiga ainda gravada; a antiga fica na operação histórica (erro_motivo) e na auditoria (antes/depois).
    && str_contains($res, "SET referencia_externa_assinatura = ?, ultimo_erro_codigo = 'TENTATIVA_ENCERRADA_PELO_SUPORTE'")
    && str_contains($res, 'AND assinatura_externa_id IS NULL AND referencia_externa_assinatura = ?') && str_contains($res, "Referência da tentativa: ' . \$referenciaAnterior"));
verificar('F5 resolução: não cria nem cancela nada no Mercado Pago, não gera cobrança nem pagamento', !str_contains($res, 'criarPreapproval') && !str_contains($res, 'atualizarPreapproval') && !preg_match('/INSERT INTO (cobranca|pagamento|transacao_pagamento)\b/', $res));
verificar('F5 resolução: auditoria crítica do ator Super Admin, com a evidência e o atestado (contexto), na mesma transação',
    str_contains($res, "auditoriaRegistrar(\$conexao, 'assinatura.criacao_cartao_encerrada'") && str_contains($res, "'ator' => \$ator")
    && str_contains($res, "'motivo' => \$conferencia['descricao']") // ATUALIZADO (F37c): descrição da conferência estruturada
    && strpos($res, 'auditoriaRegistrar(') > strpos($res, 'assinaturaServicoPreapprovalTransacao('));
verificar('F37c auditoria da resolução registra operador (ator), fonte, data/hora da conferência, evidência automática e as referências antiga e nova',
    str_contains($res, "'fonte_evidencia' => ['antes' => null, 'depois' => \$conferencia['fonte']]") && str_contains($res, "'conferido_em' => ['antes' => null, 'depois' => \$conferencia['conferido_em']]")
    && str_contains($res, "'evidencia' => ['antes' => null, 'depois' => \$resumo]") && str_contains($res, "'referencia_externa_assinatura' => ['antes' => \$referenciaAnterior, 'depois' => \$referenciaNova]")
    && preg_match("/'assinatura\.criacao_cartao_encerrada'.*'referencia_externa_assinatura',\s*'fonte_evidencia', 'conferido_em', 'evidencia'/s", $ler('backend/_regras/catalogo_auditoria.php')) === 1);
verificar('F37c resolução: libera só com a avaliação aprovada (conferência estruturada); sem rotação disponível recusa; a referência é relida sob FOR UPDATE',
    strpos($res, "if (!\$avaliacao['liberar'])") < strpos($res, "'CONFIRMACAO_OBRIGATORIA'") && str_contains($res, "'REFERENCIA_ESGOTADA'")
    && str_contains($res, 'v.referencia_externa_assinatura, o.status_processamento') && str_contains($res, "(string)\$atual['referencia_externa_assinatura'] !== \$referenciaAnterior")
    && !str_contains($res, 'marca_dagua\'] ?? false) === true) {'));
$cat = $ler('backend/_regras/catalogo_auditoria.php');
verificar('F5 o evento de auditoria existe no catálogo, como crítico e com campos permitidos', str_contains($cat, "'assinatura.criacao_cartao_encerrada' => auditoriaDefinicaoEvento('financeiro', 'assinatura', 'critica'"));
$saSrc = str_replace("\r\n", "\n", $ler('backend/super_admin/assinatura/gerenciar_assinatura.php'));
$saFn = $corpo($saSrc, 'assinaturaResolverCriacaoCartao');
$saAlt = $corpo($saSrc, 'assinaturaAlterarStatus');
verificar('F5 permissão: ação exclusiva do Super Admin (rota e guard do painel), com CSRF antes de qualquer ação, ator auditável e trava por empresa',
    str_contains($saAlt, 'csrfValidarSessao();') && strpos($saAlt, 'csrfValidarSessao();') < strpos($saAlt, "if (\$acao === 'resolver_criacao_cartao')") && str_contains($saSrc, "'cancelar_recorrencia', 'resolver_criacao_cartao'")
    && str_contains($saFn, 'auditoriaResolverAtorSuperAdmin(') && str_contains($saFn, 'cancelamentoRecorrenciaObterTrava(') && str_contains($saFn, 'cancelamentoRecorrenciaLiberarTrava(') && str_contains($saSrc, 'bloquear.php'));
verificar('F5 confirmação explícita: o número da empresa digitado e confirmo=1 são exigidos na segunda fase; a assinatura precisa pertencer à empresa',
    str_contains($saFn, "(\$_POST['confirmo'] ?? '') === '1'") && str_contains($saFn, "assinaturaIdEntrada(\$_POST, 'confirmar_empresa') !== \$idEmpresa") && str_contains($saFn, "(int)\$dono['id_empresa'] !== \$idEmpresa")
    && str_contains($saFn, 'strlen($atestado) > 300'));
$consultar5 = $corpo($po, 'pagamentoCartaoPreapprovalConsultar');
verificar('F5 o proprietário (e as rotas do cartão) nunca resolvem criação incerta: nenhuma chamada à resolução fora do painel do Super Admin',
    !str_contains($po . $consultar5, 'ResolverCriacaoIncerta') && !str_contains($po, 'resolver_criacao_cartao') && substr_count($saSrc, 'assinaturaServicoPreapprovalResolverCriacaoIncerta(') === 1);
$jsSa5 = $ler('public/js/super-admin/assinatura/gerenciar-assinatura.js');
verificar('F5 painel do Super Admin: item de menu só para criação incerta; fase 1 mostra a evidência, fase 2 pede a conferência no painel do Mercado Pago (quando necessária) e o número da empresa',
    str_contains($jsPo, 'TEXTO_CADASTRO_INCERTO') && str_contains($jsSa5, 'item.recorrencia?.situacao === "incerta"') && str_contains($jsSa5, 'data-acao="resolver_criacao_cartao"')
    && str_contains($jsSa5, 'erro.codigo === "ATESTADO_NECESSARIO"') && str_contains($jsSa5, 'erro.codigo === "CONFIRMACAO_OBRIGATORIA"') && str_contains($jsSa5, 'digite o número da empresa')
    && str_contains($jsSa5, '{ confirmo: "1", confirmar_empresa: idEmpresa'));
verificar('F5 depois da resolução o proprietário volta ao caminho normal e seguro: sem operação aberta o estado é "nao_criada" (Tentar novamente) e a nova criação passa pela pré-verificação',
    pagamentoCartaoEstadoCriacao($vp, null) === 'nao_criada' && str_contains($corpo($assSrc, 'assinaturaServicoPreapprovalReservar'), "\$acao = 'criar';"));
// F37c: rotação segura da referência, Preapproval tardia de tentativa anterior e alerta ao Super Admin.
verificar('F37c rotação: mesma base, sufixo .rN antes do .d2; esgota em .r9; referência inválida não rotaciona',
    assinaturaServicoPreapprovalReferenciaRotacionada($ref) === $ref . '.r2' && assinaturaServicoPreapprovalReferenciaRotacionada($refD2) === $ref . '.r2.d2'
    && assinaturaServicoPreapprovalReferenciaRotacionada($ref . '.r2.d2') === $ref . '.r3.d2' && assinaturaServicoPreapprovalReferenciaRotacionada($ref . '.r9') === null
    && assinaturaServicoPreapprovalReferenciaRotacionada('amg_zz') === null && assinaturaServicoPreapprovalReferenciaBase($ref . '.r3.d2') === $ref && assinaturaServicoPreapprovalReferenciaBase($ref) === $ref);
$rotacionadas = [$ref . '.r2', $ref . '.r9.d2', $ref . '.r2.d2'];
$naoRotacoes = [$ref . '.r1', $ref . '.r10', $ref . '.d2.r2', $ref . '.r2.r3', $ref . '.r'];
verificar('F37c o gateway aceita as referências rotacionadas (até 42 caracteres) e continua recusando sufixos fora da regra',
    count(array_filter($rotacionadas, [MercadoPagoGateway::class, 'referenciaExternaValida'])) === 3 && array_filter($naoRotacoes, [MercadoPagoGateway::class, 'referenciaExternaValida']) === []
    && strlen($ref . '.r9.d2') === 42 && $busca($ref . '.r2.d2') !== null);
$refRot = $ref . '.r2.d2';
$reservaRot = fn(int $vinc, int $op) => ['vinculo' => ['id_assinatura_pagamento' => $vinc, 'id_empresa' => 37, 'referencia_externa_assinatura' => $refRot], 'operacao' => ['id_operacao' => $op]];
$zerar();
$g = $conta([$item('PRE-ANT', $refD2)]);
$r = assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaRot(24, 101), 'a@b.co');
verificar('F37c Preapproval ativa da tentativa ANTERIOR (mesma base) aparece antes de criar: bloqueia o POST em conciliação, sem vincular a antiga',
    $r !== null && $r['ok'] === false && $r['codigo'] === 'PREAPPROVAL_ANTERIOR_ENCONTRADA' && $GLOBALS['f5']['marcar'] === ['PREAPPROVAL_ANTERIOR_ENCONTRADA']
    && $GLOBALS['f5']['esclarecer'] === 0 && count($g->chamadas) === 3);
$zerar();
$g = $conta([$item('PRE-ANT', $refD2, 'canceled'), $item('X', $outraEmpresa)]);
verificar('F37c tentativa anterior já cancelada no provedor não cobra e não bloqueia; outra empresa também não',
    assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaRot(24, 101), 'a@b.co') === null && $GLOBALS['f5']['marcar'] === []);
$zerar();
$g = $conta([$item('PRE-NOVA', $refRot)]);
$r = assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaRot(24, 101), 'a@b.co');
verificar('F37c a nova referência encontrada é a própria tentativa (Esclarecer), não duplicidade', $r !== null && $r['ok'] === true && $GLOBALS['f5']['esclarecer'] === 1 && $GLOBALS['f5']['marcar'] === []);
$zerar();
$g = $conta([$item('PRE-NOVA', $refRot), $item('PRE-ANT', $refD2, 'authorized')]);
$r = assinaturaServicoPreapprovalConferirAntesDeCriar(new BancoFalso(), $g, $reservaRot(24, 101), 'a@b.co');
verificar('F37c nova e antiga ao mesmo tempo: nada é vinculado automaticamente; bloqueio para análise manual', $r !== null && $r['ok'] === false && $GLOBALS['f5']['esclarecer'] === 0 && $GLOBALS['f5']['marcar'] !== []);
$zerar();
$whNr = $corpo(str_replace("\r\n", "\n", $wh), 'mpSubNaoReconhecido');
verificar('F37c webhook: Preapproval/fatura de referência antiga do mesmo contrato não é ignorada; sinaliza duplicidade no vínculo e mantém o evento em erro (sem vincular nem registrar pagamento)',
    str_contains($whNr, 'assinaturaServicoPreapprovalReferenciaBase($referencia)') && str_contains($whNr, 'LEFT(referencia_externa_assinatura, ?) = ?') && str_contains($whNr, "'PREAPPROVAL_EXTERNA_DUPLICADA'")
    && str_contains($whNr, "mpSubResultado('erro', 'preapproval_externa_duplicada')") && strpos($whNr, "'vinculo_pendente'") < strpos($whNr, 'PREAPPROVAL_EXTERNA_DUPLICADA')
    && !preg_match('/INSERT INTO|assinatura_externa_id\s*=|DELETE\s+FROM/', $whNr)
    && str_contains($wh, "mpSubNaoReconhecido(\$conexao, \$ambiente, \$fatura['referencia'], (string)\$fatura['preapproval_id'])") && str_contains($wh, ', $preapprovalId);'));
verificar('F37c Super Admin: controlador lê fonte, data/hora e descrição da conferência; o painel coleta as três e exibe o alerta de duplicidade',
    str_contains($saFn, "\$_POST['fonte_evidencia']") && str_contains($saFn, "\$_POST['conferido_em']") && str_contains($saFn, "'atestado_motivo'")
    && str_contains($jsSa5, 'async function coletarConferencia(') && str_contains($jsSa5, 'fonte_evidencia: FONTES_EVIDENCIA[') && str_contains($jsSa5, 'ALERTAS_RECORRENCIA[item.recorrencia.alerta]')
    && str_contains($cat, "'assinatura.criacao_cartao_encerrada'"));
verificar('F37c a resolução não reinicia nem encurta o teste grátis: não grava em assinatura, cobrança, pagamento ou transação e não apaga histórico',
    !preg_match('/UPDATE assinatura\s|INSERT INTO|DELETE\s+FROM|data_fim|data_inicio|transacao_pagamento/', $res));

verificar('F5 teste grátis, contrato e histórico financeiro intocados pelo conjunto de mudanças (Localizar, evidência, pré-verificação e resolução não gravam em assinatura, cobrança ou pagamento)',
    !preg_match('/UPDATE assinatura\s|INSERT INTO (cobranca|pagamento)\b|DELETE\s+FROM/', $corpo($assSrc, 'assinaturaServicoPreapprovalLocalizar') . $corpo($assSrc, 'assinaturaServicoPreapprovalEvidenciaExterna') . $corpo($assSrc, 'assinaturaServicoPreapprovalVarrerStatus') . $corpo($assSrc, 'assinaturaServicoPreapprovalConferirAntesDeCriar') . $res));

// Status das cobranças no cartão recorrente (Preapproval) e bloqueio do PIX concorrente (Proprietário e Super Admin).
// Banco próprio (SQLite) com as colunas reais usadas pela regra; as duas expressões só do MySQL viram equivalentes neutros.
final class PdoMysqlCompat extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(
            ['TIMESTAMPDIFF(SECOND, ultima_sincronizacao_em, NOW(6))', "DATE_FORMAT(ultima_consulta_em, '%Y-%m-%d %H:%i:%s')",
                "DATE_FORMAT(proxima_tentativa_em, '%Y-%m-%d %H:%i:%s')"],
            ['NULL', 'ultima_consulta_em', 'proxima_tentativa_em'],
            $query
        ), $options);
    }
}
$pdoAnterior = $GLOBALS['pdo'];
$GLOBALS['pdo'] = new PdoMysqlCompat('sqlite::memory:');
$GLOBALS['pdo']->exec('CREATE TABLE cobranca (id_cobranca INTEGER PRIMARY KEY, id_empresa INT, id_assinatura INT, finalidade TEXT, status TEXT, periodo_inicio TEXT, motor_cobranca TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE transacao_pagamento (id_transacao INTEGER PRIMARY KEY, id_empresa INT, id_cobranca INT, tipo_operacao TEXT, metodo TEXT, status TEXT,
    status_externo TEXT, detalhe_status_externo TEXT, erro_codigo TEXT, requer_conciliacao INT DEFAULT 0, numero_tentativa INT, ultima_consulta_em TEXT,
    proxima_tentativa_em TEXT)'); // ATUALIZADO (validação final): erro_codigo real e a coluna opcional da próxima tentativa.
// Coluna opcional (SQL manual): os testes alternam entre banco sem e com a coluna.
$GLOBALS['coluna_proxima'] = false;
function assinaturaServicoColunaExiste(object $conexao, string $tabela, string $coluna): bool
{
    return $tabela === 'transacao_pagamento' && $coluna === 'proxima_tentativa_em' && $GLOBALS['coluna_proxima'];
}
$GLOBALS['pdo']->exec('CREATE TABLE assinatura (id_assinatura INTEGER PRIMARY KEY, id_empresa INT, status TEXT, motivo_suspensao TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE assinatura_pagamento_recorrente (id_assinatura_pagamento INTEGER PRIMARY KEY, id_empresa INT, id_assinatura INT, motor_recorrencia TEXT,
    assinatura_externa_id TEXT, slot_ativo INT, status TEXT, status_externo TEXT, cobrar_a_partir_de TEXT, ultima_sincronizacao_em TEXT)');
$extrair('backend/_servicos/assinatura.php', ['assinaturaServicoPreapprovalBloqueioPix', 'assinaturaServicoPreapprovalMotivoRecusa',
    'assinaturaServicoPreapprovalSituacaoPagamento', 'assinaturaServicoPreapprovalSituacoesCobrancas'],
    ['ASSINATURA_SERVICO_PREAPPROVAL_FALLBACK_PIX_AUTORIZADO', 'ASSINATURA_SERVICO_PREAPPROVAL_SINCRONIA_MAX_SEGUNDOS', 'ASSINATURA_SERVICO_PREAPPROVAL_MOTIVOS_RECUSA',
        'ASSINATURA_SERVICO_PREAPPROVAL_DETALHES_NAO_MOTIVO', 'ASSINATURA_SERVICO_PREAPPROVAL_SITUACOES_PAGAMENTO']);
// ATUALIZADO (validação final): cenário real da empresa 35 — pagamento rejeitado (cc_rejected_insufficient_amount) e a fatura
// REAGENDADA como 'scheduled' (billing_status 'delayed' na Preapproval), não em 'recycling'.
$f35 = mpSubClassificarFatura($fat('scheduled', 'P1'), $pg('rejected', 'cc_rejected_insufficient_amount'));
verificar('COB empresa 35: fatura scheduled com pagamento rejeitado é nova tentativa (processando, motivo real), não recusa definitiva nem confirmação',
    $f35['status'] === 'processando' && $f35['classe'] === 'normal' && $f35['detalhe'] === 'cc_rejected_insufficient_amount'
    && mpSubClassificarFatura($fat('scheduled', 'P1'), $pg('rejected', null))['detalhe'] === 'retry'
    && mpSubClassificarFatura($fat('processed', 'P1'), $pg('rejected', 'cc_rejected_insufficient_amount'))['status'] === 'recusada'
    && mpSubClassificarFatura($fat('cancelled', 'P1'), $pg('rejected', 'cc_rejected_insufficient_amount'))['status'] === 'recusada');
$GLOBALS['pdo']->exec("INSERT INTO assinatura VALUES (50, 35, 'ativa', NULL)");
$GLOBALS['pdo']->exec("INSERT INTO assinatura_pagamento_recorrente VALUES (30, 35, 50, 'preapproval', 'PRE-35', 1, 'ativa', 'authorized', '2026-10-31', NULL)");
$GLOBALS['pdo']->exec("INSERT INTO cobranca VALUES (700, 35, 50, 'regular', 'pendente', '2026-10-31', 'preapproval')");
// Exatamente o que mpSubAplicarFatura grava para esse resultado: processando, motivo em erro_codigo, status externo 'scheduled'.
$GLOBALS['pdo']->exec("INSERT INTO transacao_pagamento (id_transacao, id_empresa, id_cobranca, tipo_operacao, metodo, status, status_externo, detalhe_status_externo,
    erro_codigo, requer_conciliacao, numero_tentativa, ultima_consulta_em, proxima_tentativa_em)
    VALUES (900, 35, 700, 'assinatura_preapproval', 'cartao_recorrente', 'processando', 'scheduled', 'cc_rejected_insufficient_amount',
    'cc_rejected_insufficient_amount', 0, 1, '2026-10-30 16:40:00', '2026-11-01 16:31:00')");
$cob35 = ['id_cobranca' => 700, 'id_empresa' => 35, 'id_assinatura' => 50, 'status' => 'pendente'];
$sit = fn() => assinaturaServicoPreapprovalSituacoesCobrancas(new BancoFalso(), [$cob35])[700];
$s = $sit();
verificar('COB empresa 35: cc_rejected_insufficient_amount com fatura scheduled = recusado com nova tentativa programada (sem exigir recycling), em aberto e motivo amigável',
    $s['situacao'] === 'recusado_retentativa' && $s['motivo_codigo'] === 'cc_rejected_insufficient_amount'
    && $s['motivo'] === 'Pagamento recusado pelo banco. O cartão pode estar sem saldo ou limite disponível suficiente.' && str_contains($s['mensagem'], 'continua em aberto'));
verificar('COB próxima tentativa: sem a coluna opcional nada é exibido nem estimado; com ela, a data informada pelo provedor aparece',
    $s['proxima_tentativa_em'] === null
    && (function () use ($sit) { $GLOBALS['coluna_proxima'] = true; $d = $sit()['proxima_tentativa_em']; $GLOBALS['coluna_proxima'] = false; return $d === '2026-11-01 16:31:00'; })());
verificar('COB empresa 35: PIX concorrente bloqueado no backend durante a retentativa, com orientação de cancelar o pagamento automático ou aguardar a próxima tentativa',
    $s['pix_bloqueado'] === true && $s['pix_codigo'] === 'COBRANCA_PREAPPROVAL_EM_ANDAMENTO'
    && assinaturaServicoPreapprovalBloqueioPix(new BancoFalso(), 35, 700)['codigo'] === 'COBRANCA_PREAPPROVAL_EM_ANDAMENTO'
    && str_contains($s['pix_mensagem'], 'cancele o pagamento automático') && str_contains($s['pix_mensagem'], 'próxima tentativa'));
verificar('COB isolamento: a mesma cobrança vista por outra empresa não traz situação nem bloqueio de terceiros',
    assinaturaServicoPreapprovalSituacoesCobrancas(new BancoFalso(), [['id_cobranca' => 700, 'id_empresa' => 99, 'id_assinatura' => 50, 'status' => 'pendente']])[700] === null);
// Recusa seguida de retentativa aprovada (mesma fatura): a classificação autoritativa muda a transação para confirmada.
$fRec = mpSubClassificarFatura($fat('recycling', 'P1'), $pg('rejected', 'cc_rejected_insufficient_amount'));
$fAprov = mpSubClassificarFatura($fat('processed', 'P2'), $pg('approved', 'accredited'));
verificar('COB recusa em recycling segue em processamento com o motivo real; a retentativa aprovada e creditada é a única confirmação',
    $fRec['status'] === 'processando' && $fRec['detalhe'] === 'cc_rejected_insufficient_amount' && $fAprov['classe'] === 'confirmada');
$GLOBALS['pdo']->exec("UPDATE transacao_pagamento SET status = 'confirmada', status_externo = 'processed', detalhe_status_externo = 'accredited', erro_codigo = NULL, numero_tentativa = 2 WHERE id_transacao = 900");
$GLOBALS['pdo']->exec("UPDATE cobranca SET status = 'paga' WHERE id_cobranca = 700");
$s = assinaturaServicoPreapprovalSituacoesCobrancas(new BancoFalso(), [['status' => 'paga'] + $cob35])[700];
verificar('COB retentativa aprovada: pagamento aprovado e confirmado, sem motivo de recusa residual, tentativa 2, sem bloqueio exibido',
    $s['situacao'] === 'aprovado' && $s['motivo_codigo'] === null && $s['motivo'] === null && $s['tentativa'] === 2 && $s['pix_bloqueado'] === false);
// Tentativas encerradas sem aprovação, com a Preapproval ainda ativa: recusa final, PIX segue bloqueado (risco de débito).
$GLOBALS['pdo']->exec("UPDATE cobranca SET status = 'pendente' WHERE id_cobranca = 700");
$GLOBALS['pdo']->exec("UPDATE transacao_pagamento SET status = 'recusada', status_externo = 'processed', detalhe_status_externo = 'cc_rejected_insufficient_amount', erro_codigo = 'cc_rejected_insufficient_amount' WHERE id_transacao = 900");
$s = $sit();
verificar('COB tentativas encerradas com Preapproval ativa: recusado (não aprovado, mesmo com a Preapproval authorized) e PIX ainda bloqueado',
    $s['situacao'] === 'recusado' && $s['motivo_codigo'] === 'cc_rejected_insufficient_amount' && $s['pix_bloqueado'] === true && $s['pix_codigo'] === 'COBRANCA_GOVERNADA_PREAPPROVAL');
$GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status = 'cancelamento_pendente' WHERE id_assinatura_pagamento = 30");
$s = $sit();
verificar('COB cancelamento solicitado e ainda não confirmado: PIX continua bloqueado e o aviso de cancelamento pendente aparece', $s['pix_bloqueado'] === true && $s['cancelamento_pendente'] === true);
// Cancelamento externo confirmado (vínculo cancelado, slot liberado) e nenhuma cobrança no cartão em andamento: PIX liberável.
$GLOBALS['pdo']->exec("UPDATE assinatura_pagamento_recorrente SET status = 'cancelada', slot_ativo = NULL, status_externo = 'cancelled' WHERE id_assinatura_pagamento = 30");
$GLOBALS['pdo']->exec("UPDATE transacao_pagamento SET status = 'processando', status_externo = 'recycling' WHERE id_transacao = 900");
verificar('COB cancelamento confirmado, mas a fatura em retentativa ainda não foi conciliada: PIX segue bloqueado (sem cobrança concorrente)',
    assinaturaServicoPreapprovalBloqueioPix(new BancoFalso(), 35, 700)['codigo'] === 'COBRANCA_PREAPPROVAL_EM_ANDAMENTO');
$GLOBALS['pdo']->exec("UPDATE transacao_pagamento SET status = 'cancelada', status_externo = 'cancelled' WHERE id_transacao = 900");
$s = $sit();
verificar('COB cancelamento confirmado e fatura encerrada pela conciliação: recorrência cancelada e PIX liberável no backend',
    assinaturaServicoPreapprovalBloqueioPix(new BancoFalso(), 35, 700) === null && $s['situacao'] === 'recorrencia_cancelada' && $s['pix_bloqueado'] === false);
// Função pura: motivo desconhecido, divergência externa x local, conferência e cobrança sem cartão.
$tx = fn(array $o = []) => $o + ['status' => 'recusada', 'status_externo' => 'processed', 'detalhe_status_externo' => 'cc_rejected_motivo_novo', 'erro_codigo' => null, 'requer_conciliacao' => 0, 'numero_tentativa' => 1, 'ultima_consulta_em' => null];
verificar('COB retentativa identificada pela recusa registrada, qualquer que seja o status externo e mesmo com código fora de cc_rejected_* (sem mensagem inventada)',
    assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'processando', 'status_externo' => 'scheduled', 'detalhe_status_externo' => 'rejected_by_bank', 'erro_codigo' => 'rejected_by_bank']), ['status' => 'ativa', 'status_externo' => 'authorized'], null)
        === array_replace(assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'processando', 'status_externo' => 'scheduled', 'detalhe_status_externo' => 'rejected_by_bank', 'erro_codigo' => 'rejected_by_bank']), ['status' => 'ativa', 'status_externo' => 'authorized'], null), ['situacao' => 'recusado_retentativa', 'motivo_codigo' => 'rejected_by_bank', 'motivo' => null])
    && assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'processando', 'status_externo' => 'scheduled', 'detalhe_status_externo' => 'rejected_by_bank', 'erro_codigo' => 'rejected_by_bank']), ['status' => 'ativa', 'status_externo' => 'authorized'], null)['situacao'] === 'recusado_retentativa'
    && assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'processando', 'status_externo' => 'recycling', 'detalhe_status_externo' => 'pending_contingency']), ['status' => 'ativa', 'status_externo' => 'authorized'], null)['situacao'] === 'processando');
$vAtiva = ['status' => 'ativa', 'status_externo' => 'authorized'];
$s = assinaturaServicoPreapprovalSituacaoPagamento($tx(), $vAtiva, null);
verificar('COB recusa com motivo desconhecido: não inventa motivo (mensagem nula), mas preserva o código técnico para diagnóstico',
    $s['situacao'] === 'recusado' && $s['motivo'] === null && $s['motivo_codigo'] === 'cc_rejected_motivo_novo');
verificar('COB recusa sem status_detail: nenhum motivo nem código inventado', assinaturaServicoPreapprovalSituacaoPagamento($tx(['detalhe_status_externo' => null]), $vAtiva, null)['motivo_codigo'] === null
    && assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'processando', 'status_externo' => 'recycling', 'detalhe_status_externo' => 'retry']), $vAtiva, null)['situacao'] === 'recusado_retentativa'
    && assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'processando', 'status_externo' => 'recycling', 'detalhe_status_externo' => 'retry']), $vAtiva, null)['motivo_codigo'] === null);
verificar('COB status externo divergente do local: Preapproval authorized nunca vira pagamento aprovado; só a transação confirmada local',
    assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'processando', 'status_externo' => 'scheduled', 'detalhe_status_externo' => 'pending_contingency']), $vAtiva, null)['situacao'] === 'processando'
    && assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'aguardando', 'status_externo' => 'scheduled', 'detalhe_status_externo' => null]), $vAtiva, null)['situacao'] === 'aguardando_processamento'
    && assinaturaServicoPreapprovalSituacaoPagamento(null, $vAtiva, null) === null);
verificar('COB pagamento divergente em conferência (requer_conciliacao) nunca aparece como aprovado',
    assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'confirmada', 'detalhe_status_externo' => 'accredited', 'requer_conciliacao' => 1]), $vAtiva, null)['situacao'] === 'conciliacao'
    && assinaturaServicoPreapprovalSituacaoPagamento($tx(['status' => 'conciliacao']), $vAtiva, null)['situacao'] === 'conciliacao');
verificar('COB competência governada sem fatura ainda: aguardando processamento e PIX bloqueado',
    assinaturaServicoPreapprovalSituacaoPagamento(null, $vAtiva, ['codigo' => 'COBRANCA_GOVERNADA_PREAPPROVAL', 'mensagem' => 'x'])['situacao'] === 'aguardando_processamento');
$assCob = str_replace("\r\n", "\n", $ler('backend/_servicos/assinatura.php'));
$situacoesSrc = $corpo($assCob, 'assinaturaServicoPreapprovalSituacoesCobrancas') . $corpo($assCob, 'assinaturaServicoPreapprovalSituacaoPagamento');
verificar('COB a situação é somente leitura: não grava, não quita e não altera A receber, Recebido ou Vencido',
    !preg_match('/INSERT\s+INTO|UPDATE\s|DELETE\s+FROM|FOR UPDATE/', $situacoesSrc) && !str_contains($situacoesSrc, 'proxima_cobranca_em'));
$sincSrc = $corpo(str_replace("\r\n", "\n", $wh), 'mpSubSincronizarPreapproval');
verificar('COB a mudança de next_payment_date (sincronização da Preapproval) nunca registra pagamento nem confirma cobrança',
    $sincSrc !== '' && !preg_match('/INSERT INTO pagamento|status\s*=\s*\'confirmada\'|UPDATE cobranca SET status/', $sincSrc));
$aplSrc = $corpo(str_replace("\r\n", "\n", $wh), 'mpSubAplicarFatura');
verificar('COB webhook repetido: a fatura reutiliza a transação pelo id externo e o pagamento pelo Payment ID (sem duplicar pagamento nem transação)',
    str_contains($aplSrc, 'WHERE provedor = ? AND ambiente = ? AND fatura_externa_id = ? LIMIT 1') && str_contains($aplSrc, 'FROM pagamento WHERE provedor = ? AND referencia_externa = ? FOR UPDATE')
    && str_contains($aplSrc, "if (\$existentePag !== null) {") && str_contains($aplSrc, "'Payment ID já associado a outra transação local.'"));
$faturamentoSrc = str_replace("\r\n", "\n", $ler('backend/painel_administrativo/faturamento/gerenciar_faturamento.php'));
$cobrancaSaSrc = str_replace("\r\n", "\n", $ler('backend/super_admin/cobranca/gerenciar_cobranca.php'));
$pixOnlineSrc = str_replace("\r\n", "\n", $ler('backend/painel_administrativo/faturamento/gerenciar_pagamento_online.php'));
verificar('COB Proprietário e Super Admin: as duas listagens (e os detalhes do Super Admin) usam a mesma situação; PIX do Proprietário e baixa do Super Admin usam o mesmo bloqueio no backend',
    str_contains($corpo($faturamentoSrc, 'faturamentoCobrancas'), 'assinaturaServicoPreapprovalSituacoesCobrancas(') && substr_count($cobrancaSaSrc, 'assinaturaServicoPreapprovalSituacoesCobrancas(') === 2
    && str_contains($pixOnlineSrc, '$governada = assinaturaServicoPreapprovalBloqueioPix($db, $empresa, $cobranca);')
    && str_contains($corpo($cobrancaSaSrc, 'pagamentoBloqueioOnline'), 'assinaturaServicoPreapprovalBloqueioPix($conexao, $idEmpresa, $idCobranca)'));
$jsFat = $ler('public/js/painel-administrativo/faturamento/gerenciar-faturamento.js');
$jsCob = $ler('public/js/super-admin/cobranca/gerenciar-cobranca.js');
verificar('COB painéis: o Proprietário não recebe o botão de PIX quando bloqueado e vê o motivo amigável (ou "não informou"); o Super Admin vê também o código técnico',
    str_contains($jsFat, '&& !pixBloqueado') && str_contains($jsFat, 'O Mercado Pago não informou o motivo detalhado da recusa.') && !str_contains($jsFat, 'motivo_codigo')
    && str_contains($jsCob, '<code>${esc(pa.motivo_codigo)}</code>') && str_contains($jsCob, 'item.pagamento_automatico?.pix_bloqueado !== true'));
$whFinal = str_replace("\r\n", "\n", $wh);
$aplFinal = $corpo($whFinal, 'mpSubAplicarFatura');
verificar('COB próxima tentativa: next_retry_date normalizado da fatura e gravado só com a coluna opcional, só para recusa em nova tentativa (nunca estimado de next_payment_date)',
    str_contains($corpo($whFinal, 'mpSubNormalizarFatura'), "'proxima_tentativa' => assinaturaServicoPreapprovalDataExterna(\$d['next_retry_date'] ?? null)")
    && str_contains($aplFinal, "if (assinaturaServicoColunaExiste(\$conexao, 'transacao_pagamento', 'proxima_tentativa_em')) {")
    && str_contains($aplFinal, "\$recusa && \$classe['status'] === 'processando' && \$fatura['proxima_tentativa'] !== null")
    && !str_contains($aplFinal, 'proxima_tentativa_em = proxima_cobranca_em'));
verificar('COB cancelamento externo por falta de pagamento reconhece também a recusa que aguardava nova tentativa',
    str_contains($whFinal, "SELECT status, erro_codigo FROM transacao_pagamento") && str_contains($whFinal, "=== 'processando' && (\$ultimaTentativa['erro_codigo'] ?? null) !== null"));
// Nenhuma rota cria PIX ou registra pagamento manual sem passar pelo mesmo bloqueio do cartão recorrente.
$todos = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../backend', FilesystemIterator::SKIP_DOTS)) as $arq) {
    if ($arq->getExtension() === 'php') $todos .= str_replace("\r\n", "\n", (string)file_get_contents($arq->getPathname()));
}
$iniciar = $pixOnlineSrc;
$posBloqueio = strpos($iniciar, '$governada = assinaturaServicoPreapprovalBloqueioPix($db, $empresa, $cobranca);');
$posPix = strpos($iniciar, "VALUES (?,?,'mercado_pago',?,'pix','pix',?,'BRL','criada',?,?)");
$registrar = $corpo($cobrancaSaSrc, 'registrarPagamento');
verificar('COB rotas: o único INSERT de transação PIX do sistema vem depois do bloqueio; a única baixa manual (Super Admin) passa pelo bloqueio online antes do INSERT',
    substr_count($todos, "'pix','pix'") === 1 && $posBloqueio !== false && $posPix !== false && $posBloqueio < $posPix
    && strpos($registrar, 'pagamentoBloqueioOnline(') !== false && strpos($registrar, 'pagamentoBloqueioOnline(') < strpos($registrar, 'INSERT INTO pagamento')
    && preg_match_all("/INSERT INTO pagamento\b/", $todos) === 3
    && !str_contains($ler('backend/processos/processar_cobrancas_recorrentes.php'), "'pix'"));
// Compatibilidade sem o SQL manual: banco sem a coluna proxima_tentativa_em.
$GLOBALS['pdo']->exec('ALTER TABLE transacao_pagamento DROP COLUMN proxima_tentativa_em');
$GLOBALS['coluna_proxima'] = false;
$semColuna = (function () use ($sit) { try { return $sit(); } catch (Throwable $e) { return $e; } })();
$guarda = strpos($aplFinal, "if (assinaturaServicoColunaExiste(\$conexao, 'transacao_pagamento', 'proxima_tentativa_em')) {");
verificar('COB sem a coluna opcional: a listagem funciona (nenhuma consulta à coluna), o webhook só a grava dentro da verificação e as telas aceitam a data ausente',
    is_array($semColuna) && $semColuna['proxima_tentativa_em'] === null
    // (operacao_assinatura_gateway tem coluna homônima própria, já existente; aqui só importa transacao_pagamento)
    && substr_count($aplFinal, 'proxima_tentativa_em') === 2 && substr_count($whFinal, 'UPDATE transacao_pagamento SET proxima_tentativa_em') === 1
    && $guarda !== false && strpos($aplFinal, 'SET proxima_tentativa_em = ?') > $guarda
    && !str_contains($faturamentoSrc . $cobrancaSaSrc . $pixOnlineSrc, 'proxima_tentativa_em')
    && str_contains($jsFat, 'pa.proxima_tentativa_em ?') && str_contains($jsCob, 'pa.proxima_tentativa_em ?'));
// Regra dos 5 dias: carência entre o vencimento e a suspensão automática, independente do estado do pagamento no cartão.
$fnCarencia = ['assinaturaServicoPendenciaFinanceira', 'assinaturaServicoListarCandidatasInadimplencia', 'assinaturaServicoListarCandidatasRegularizacao',
    'assinaturaServicoSuspenderPorInadimplencia', 'assinaturaServicoRegularizarInadimplencia', 'assinaturaServicoBloqueioReativacao'];
$srcCarencia = implode("\n", array_map(fn($f) => $corpo($assCob, $f), $fnCarencia));
$pend = $corpo($assCob, 'assinaturaServicoPendenciaFinanceira');
$susp = $corpo($assCob, 'assinaturaServicoSuspenderPorInadimplencia');
verificar('5 DIAS: carência de 5 dias corridos (D+1..D+5; D+6 é o primeiro dia de suspensão), sem alterar vencimento nem status da cobrança',
    preg_match('/^const ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS = 5;$/m', $assCob) === 1 && str_contains($assCob, 'Vencimento em D: D+1..D+5 são a carência; D+6 é o primeiro dia elegível.')
    && substr_count($assCob, '= ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS;') === 2 && substr_count($assCob, ', ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS)') === 2);
verificar('5 DIAS: candidatas à suspensão e à regularização usam a mesma carência sobre cobrança pendente vencida',
    substr_count($corpo($assCob, 'assinaturaServicoListarCandidatasInadimplencia') . $corpo($assCob, 'assinaturaServicoListarCandidatasRegularizacao'),
        "c.data_vencimento < DATE_SUB(CURDATE(), INTERVAL {\$carencia} DAY)") === 2
    && str_contains($susp, 'assinaturaServicoPendenciaFinanceira($conexao, $idEmpresa, false, ASSINATURA_SERVICO_CARENCIA_INADIMPLENCIA_DIAS)'));
verificar('5 DIAS: suspensão só por dívida comprovada (vencida além da carência e com saldo); pagamento em andamento, recusa ou retentativa não suspendem antes nem adiam depois',
    strpos($susp, "if (\$pendencia['tipo'] !== 'divida') {") !== false && strpos($susp, "return \$resultado('pagamento_incerto');") < strpos($susp, "UPDATE assinatura SET status = 'suspensa'")
    && strpos($pend, "'tipo' => 'divida'") < strpos($pend, 'FROM transacao_pagamento t')
    && !preg_match('/recusada|recycling|scheduled|proxima_tentativa|status_externo|detalhe_status_externo/', $srcCarencia));
verificar('5 DIAS: reativação após pagamento segue a mesma regra (dívida ou pagamento ainda em andamento bloqueiam; quitação confirmada libera)',
    str_contains($corpo($assCob, 'assinaturaServicoBloqueioReativacao'), 'assinaturaServicoPendenciaFinanceira(')
    && str_contains($pend, "t.status IN ('criada', 'enviando', 'aguardando', 'processando', 'conciliacao')"));
$GLOBALS['pdo'] = $pdoAnterior;

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
