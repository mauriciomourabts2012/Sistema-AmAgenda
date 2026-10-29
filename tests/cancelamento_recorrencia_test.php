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
], ['ASSINATURA_SERVICO_PREAPPROVAL_FUSO', 'ASSINATURA_SERVICO_PREAPPROVAL_ANTECIPACAO_DIAS_DIARIA']);
$extrair('backend/webhooks/webhook_mercado_pago.php', [
    'mpSubClassificarFatura', 'mpSubCompetenciasAntecipaveis', 'mpSubDebitoAntecipaProximaCompetencia', 'mpSubCompetenciaAntecipadaElegivel',
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
verificar('F2 janela por periodicidade e ciclo: diária de 2 dias = 1; diária legada de 1 dia, mensal, trimestral, semestral e anual = 0',
    assinaturaServicoPreapprovalAntecipacaoDias('diaria', 2) === 1 && assinaturaServicoPreapprovalAntecipacaoDias('diaria', 1) === 0
    && assinaturaServicoPreapprovalAntecipacaoDias('diaria') === 0
    && array_sum(array_map(fn($p) => assinaturaServicoPreapprovalAntecipacaoDias($p, 2), ['mensal', 'trimestral', 'semestral', 'anual'])) === 0);
verificar('F2 mensal (empresa 34) continua sem antecipação', assinaturaServicoPreapprovalConferirInicioExterno($preap(['next_payment_date' => '2026-10-30T16:31:00.000-03:00']), $inicioProx, false, assinaturaServicoPreapprovalAntecipacaoDias('mensal', 1)) === 'COBRANCA_EXTERNA_ANTECIPADA');
verificar('F2 primeira cobrança e teste grátis não herdam a janela',
    assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => ['id_cobranca' => 1], 'teste' => null]) === 0
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => null, 'teste' => ['expira_em' => 'x']]) === 0
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 2, 'primeira_cobranca' => null, 'teste' => null]) === 1
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'diaria', 'frequencia_tipo' => 'days', 'frequencia_meses' => 1, 'primeira_cobranca' => null, 'teste' => null]) === 0
    && assinaturaServicoPreapprovalAntecipacaoDiasContrato(['periodicidade' => 'mensal', 'frequencia_tipo' => 'months', 'frequencia_meses' => 1, 'primeira_cobranca' => null, 'teste' => null]) === 0);

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

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
