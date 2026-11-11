<?php
declare(strict_types=1);

/*
 * Testes da unificação de contas duplicadas do cliente (C4.2): regras puras (contatos, elegibilidade,
 * prova na sessão, login recente) e verificações sobre o código-fonte (transação, bloqueios, chat,
 * notificações, auditoria, UI). Não acessam o banco nem enviam nada.
 * Uso: php tools/tests/cliente-unificacao.test.php
 */

$total = 0;
$falhas = [];
date_default_timezone_set('UTC');
require_once __DIR__ . '/sms-banco-falso.php';
require_once __DIR__ . '/../../backend/_regras/cliente_unificacao.php';

$raiz = realpath(__DIR__ . '/../..');
$ler = static fn (string $caminho): string => str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/' . $caminho));

/* ---------------------------------------------------------------- chaves e máscaras */
falsoVerificar(clienteUnifChaveTelefone('+5538998969407') === '38998969407', 'chave do celular ignora +55');
falsoVerificar(clienteUnifChaveTelefone('(38) 99896-9407') === '38998969407', 'chave do celular ignora máscara');
falsoVerificar(clienteUnifChaveTelefone('') === null && clienteUnifChaveTelefone(null) === null, 'celular vazio não tem chave');
falsoVerificar(clienteUnifChaveEmail('  Ana@Exemplo.TEST ') === 'ana@exemplo.test', 'chave do e-mail normalizada');
falsoVerificar(clienteUnifMascararTelefone('+5538998969407') === '(**) *****-9407', 'celular mascarado');
falsoVerificar(clienteUnifMascararEmail('ana@exemplo.test') === 'a***@exemplo.test', 'e-mail mascarado');
falsoVerificar(!str_contains(clienteUnifMascararEmail('ana@exemplo.test'), 'na@'), 'máscara não revela o e-mail');

/* ---------------------------------------------------------------- elegibilidade do par */
$conta = static fn (int $id, array $x = []): array => array_merge([
    'id_cliente' => $id, 'id_empresa' => 42, 'status' => 'ativo', 'id_cliente_unificado' => null,
], $x);
falsoVerificar(clienteUnifValidarPar($conta(1), $conta(2), 42) === null, 'par válido na mesma empresa');
falsoVerificar(clienteUnifValidarPar($conta(1), null, 42) === 'CONTA_INEXISTENTE', 'conta inexistente');
falsoVerificar(clienteUnifValidarPar($conta(1), $conta(2, ['id_empresa' => 43]), 42) === 'EMPRESA_DIFERENTE', 'bloqueia outra empresa');
falsoVerificar(clienteUnifValidarPar($conta(1, ['id_empresa' => 43]), $conta(2), 42) === 'EMPRESA_DIFERENTE', 'bloqueia conta atual de outra empresa');
falsoVerificar(clienteUnifValidarPar($conta(1), $conta(1), 42) === 'MESMA_CONTA', 'bloqueia auto-referência');
falsoVerificar(clienteUnifValidarPar($conta(1), $conta(2, ['status' => 'inativo']), 42) === 'CONTA_INATIVA', 'bloqueia conta inativa');
falsoVerificar(clienteUnifValidarPar($conta(1), $conta(2, ['status' => 'bloqueado']), 42) === 'CONTA_INATIVA', 'bloqueia conta bloqueada');
falsoVerificar(clienteUnifValidarPar($conta(1), $conta(2, ['id_cliente_unificado' => 9]), 42) === 'CONTA_JA_UNIFICADA', 'bloqueia conta já unificada (sem ciclo/cadeia)');
falsoVerificar(clienteUnifValidarPar($conta(1, ['id_cliente_unificado' => 2]), $conta(2), 42) === 'CONTA_JA_UNIFICADA', 'bloqueia ciclo A->B com B->A');

/* ---------------------------------------------------------------- login recente */
$agora = 1_800_000_000;
$em = static fn (int $seg): array => ['login_em' => gmdate('Y-m-d H:i:s', $agora - $seg)];
falsoVerificar(clienteUnifLoginRecente($em(60), $agora), 'login de 1 min é recente');
falsoVerificar(clienteUnifLoginRecente($em(900), $agora), 'login no limite de 15 min é aceito');
falsoVerificar(!clienteUnifLoginRecente($em(901), $agora), 'login antigo exige nova autenticação');
falsoVerificar(!clienteUnifLoginRecente([], $agora), 'sem login_em não vale');
falsoVerificar(!clienteUnifLoginRecente(['login_em' => 'lixo'], $agora), 'login_em inválido não vale');
falsoVerificar(!clienteUnifLoginRecente($em(-3600), $agora), 'login no futuro não vale');

/* ---------------------------------------------------------------- resolução de contatos */
$r = clienteUnifResolverContato('telefone', null, null, null, null, '', []);
falsoVerificar($r['ok'] && $r['valor'] === null, 'sem contatos nas duas contas: sem contato');

$r = clienteUnifResolverContato('telefone', null, '+5538998969407', null, null, '', []);
falsoVerificar($r['ok'] && $r['valor'] === '+5538998969407' && $r['origem'] === 'secundaria' && $r['verificado_em'] === null,
    'contato só da secundária é incorporado SEM presumir verificação (celular do profissional)');

$r = clienteUnifResolverContato('telefone', null, '+5538998969407', null, null, '', ['38998969407']);
falsoVerificar($r['ok'] && $r['verificado_em'] === 'AGORA', 'contato comprovado por código agora vale como verificado');

$r = clienteUnifResolverContato('telefone', '(38) 99896-9407', '+5538998969407', '2026-01-01 10:00:00', null, '', []);
falsoVerificar($r['ok'] && $r['origem'] === 'ambas' && $r['descartado'] === null && $r['verificado_em'] === '2026-01-01 10:00:00',
    'mesmo celular em formatos diferentes não diverge e preserva a verificação existente');

$r = clienteUnifResolverContato('email', 'a@x.test', 'b@x.test', null, null, '', []);
falsoVerificar(!$r['ok'] && $r['codigo'] === 'ESCOLHA_CONTATO_OBRIGATORIA', 'contato divergente exige escolha (nunca descarta em silêncio)');

$r = clienteUnifResolverContato('email', 'a@x.test', 'b@x.test', null, '2026-02-02 08:00:00', 'secundaria', []);
falsoVerificar($r['ok'] && $r['valor'] === 'b@x.test' && $r['descartado'] === 'a@x.test' && $r['verificado_em'] === '2026-02-02 08:00:00',
    'escolha da secundária mantém a verificação dela e registra o descartado');

$r = clienteUnifResolverContato('email', 'a@x.test', 'b@x.test', '2026-01-01 00:00:00', null, 'principal', []);
falsoVerificar($r['ok'] && $r['valor'] === 'a@x.test' && $r['descartado'] === 'b@x.test', 'escolha da principal registra o descartado');

$r = clienteUnifResolverContato('email', 'a@x.test', 'b@x.test', null, null, 'qualquer', []);
falsoVerificar(!$r['ok'], 'escolha inválida é recusada');

/* ---------------------------------------------------------------- prova na sessão */
$_SESSION = [];
falsoVerificar(clienteUnifLerProva(42, 1) === null, 'sem prova na sessão');
$nonce = clienteUnifGuardarProva(42, 1, 2, 'telefone', '+5538998969407');
falsoVerificar(strlen($nonce) === 32, 'nonce aleatório de 128 bits');
$p = clienteUnifLerProva(42, 1);
falsoVerificar($p !== null && $p['id_outra'] === 2 && $p['chave'] === '38998969407', 'prova válida guarda a outra conta e a chave do contato');
falsoVerificar(clienteUnifLerProva(43, 1) === null, 'prova não vale para outra empresa');
clienteUnifGuardarProva(42, 1, 2, 'telefone', '+5538998969407');
falsoVerificar(clienteUnifLerProva(42, 7) === null, 'prova não vale para outro cliente logado');
clienteUnifGuardarProva(42, 1, 2, 'email', 'b@x.test');
falsoVerificar(clienteUnifLerProva(42, 1, time() + CLIENTE_UNIF_PROVA_VALIDADE_SEGUNDOS + 5) === null, 'prova expira');
falsoVerificar(!isset($_SESSION[CLIENTE_UNIF_SESSAO_PROVA]), 'prova expirada é descartada da sessão');

clienteUnifGuardarProva(42, 1, 2, 'email', 'B@X.test');
$prova = clienteUnifLerProva(42, 1);
$comp = clienteUnifContatosComprovados(
    ['telefone_verificado' => true, 'telefone' => '+5538911112222', 'email_verificado' => false, 'email' => 'a@x.test'],
    $prova
);
falsoVerificar($comp['telefone'] === ['38911112222'] && $comp['email'] === ['b@x.test'],
    'comprovados = contato do login atual + contato da outra conta provado por código (flag falso não conta)');

/* ---------------------------------------------------------------- código-fonte: regra de negócio */
$un = $ler('backend/_regras/cliente_unificacao.php');
falsoVerificar(str_contains($un, '$conexao->begin_transaction()') && str_contains($un, '$conexao->commit()') && str_contains($un, '$conexao->rollback()'), 'unificação em transação com rollback');
falsoVerificar(str_contains($un, 'ORDER BY id_cliente FOR UPDATE'), 'bloqueia as duas contas em ordem fixa (concorrência)');
falsoVerificar(str_contains($un, 'UNIQUE') || str_contains($un, 'cliente_unificacao'), 'trilha de recuperação dedicada');
falsoVerificar(str_contains($un, "'UPDATE agendamento SET id_cliente = ? WHERE id_cliente = ? AND id_empresa = ?',"), 'transfere TODOS os agendamentos, sem filtrar por status');
falsoVerificar(!preg_match('/SET\s+(SESSION\s+|GLOBAL\s+)?foreign_key_checks/i', $un), 'não usa SET foreign_key_checks');
falsoVerificar(!preg_match('/UPDATE\s+chat_|DELETE\s+FROM\s+chat_|INSERT\s+INTO\s+chat_/i', $un), 'não move nem altera linhas do chat (histórico preservado)');
falsoVerificar(!preg_match('/DELETE\s+FROM/i', $un), 'nada é excluído');
falsoVerificar(!preg_match('/UPDATE\s+documento_legal_manifestacao|DELETE\s+FROM\s+documento_legal/i', $un), 'aceites legais preservados');
falsoVerificar(str_contains($un, "status = 'inativo', id_cliente_unificado = ?, unificado_em = NOW()"), 'secundária inativada e vinculada (CHECK permite sem contato)');
falsoVerificar(str_contains($un, 'senha_hash = NULL'), 'credencial da secundária revogada (sessões antigas caem)');
falsoVerificar(str_contains($un, "UPDATE otp_desafio SET status = 'invalidado'"), 'códigos pendentes da secundária invalidados');
falsoVerificar(str_contains($un, "codigo = 'chat.conversa_nao_lida' AND cancelada_em IS NULL"), 'notificações de chat da secundária canceladas');
falsoVerificar(str_contains($un, 'UPDATE cliente SET id_cliente_unificado = ? WHERE id_empresa = ? AND id_cliente_unificado = ?'), 'grupo mantido em um nível (re-aponta secundárias)');
falsoVerificar(str_contains($un, "'cliente.unificado'") && str_contains($ler('backend/_regras/catalogo_auditoria.php'), "'cliente.unificado'"), 'auditoria com evento registrado no catálogo');
falsoVerificar(!str_contains($un, 'senha_hash_secundaria') && !str_contains($un, "'senha_hash' =>") && !str_contains($un, '$S[\'senha_hash\']'), 'nenhuma cópia de senha/hash na tabela de recuperação nem na auditoria');
falsoVerificar(str_contains($un, 'SEM_CONTATO_COMPROVADO') && str_contains($un, 'PROVA_INVALIDA'), 'exige contato comprovado e prova ainda válida');
falsoVerificar(str_contains($un, 'session_regenerate_id(true)'), 'sessão regenerada ao trocar de conta');
falsoVerificar(!str_contains($un, 'origem_cadastro = '), 'origem_cadastro histórica preservada');

/* ---------------------------------------------------------------- código-fonte: endpoint */
$ep = $ler('backend/cliente/contatos_cliente.php');
falsoVerificar(str_contains($ep, "'unificar_solicitar'") && str_contains($ep, "'unificar_previa'") && str_contains($ep, "'unificar_confirmar'"), 'ações da unificação no endpoint do perfil');
falsoVerificar(str_contains($ep, 'clienteUnifLoginRecente('), 'exige login recente');
falsoVerificar(str_contains($ep, 'hash_equals((string)$prova[\'nonce\']'), 'confirmação exige o nonce da prova');
falsoVerificar(str_contains($ep, "(string)(\$_POST['aceite'] ?? '') !== '1'"), 'confirmação exige aceite expresso');
falsoVerificar(str_contains($ep, '\'id_cliente\' => $real ? (int)$outra[\'id_cliente\'] : 0'),'código é solicitado para a OUTRA conta (principal real) ou desafio fictício');
falsoVerificar(substr_count($ep, 'csrfValidarSessao()') === 1 && strpos($ep, 'csrfValidarSessao()') < strpos($ep, "'unificar_solicitar'"), 'CSRF validado antes das ações');
$solic = substr($ep, (int)strpos($ep, 'function clienteUnifSolicitarCodigo('), 3200);
falsoVerificar(str_contains($solic, "'principal_tipo' => \$real ? 'cliente' : 'ficticio'") && str_contains($solic, "'recuperacao_cliente'")
    && str_contains($solic, "'telefone' => \$ehEmail ? '' : \$valor"), 'sem enumeração: desafio fictício (sem envio) passa pelos mesmos limites; telefone só no canal SMS');
falsoVerificar(str_contains($solic, '$comunsAosDoisCasos') && str_contains($solic, "'SMS_OTP_LIMITE'") && !str_contains($solic, 'CLIENT_OTP_SEND_UNCERTAIN')
    && str_contains($solic, "'user_msg' => \$ehEmail\n            ? 'Se houver uma conta com este e-mail"), 'sem enumeração: limites iguais nos dois casos e falha do envio real vira a mesma resposta');
falsoVerificar(str_contains($ep, "if (\$tipo === 'unificar') {\n                out(['ok' => false, 'code' => 'CLIENT_OTP_NOT_APPROVED'"), 'sem enumeração: validação com a mesma resposta (errado, expirado, esgotado ou fictício)');
falsoVerificar(str_contains($ep, "'CLIENT_UNIFY_UNAVAILABLE'") && str_contains($ep, 'clienteUnifTabelaDisponivel'), 'recurso indisponível sem a tabela de recuperação');
falsoVerificar(!str_contains($ep, 'whatsapp_celular') || !preg_match("/'data'\s*=>\s*\[[^\]]*whatsapp_celular/", $ep), 'resposta não expõe contato completo da outra conta');

/* ---------------------------------------------------------------- chat e notificações por grupo */
$chat = $ler('backend/_servicos/chat.php');
falsoVerificar(str_contains($chat, 'function chatClienteIdsGrupo(') && str_contains($chat, 'id_cliente_unificado = ?'), 'chat: grupo de contas por empresa');
falsoVerificar(str_contains($chat, 'c.id_cliente IN ({$in})'), 'chat: conversas das contas do grupo autorizadas');
falsoVerificar(str_contains($chat, '$idCliente = (int)$conversa[\'id_cliente\'];'), 'chat: mensagem em conversa herdada usa o dono (respeita FK/CHECK)');
falsoVerificar(str_contains($chat, 'COALESCE(clp.nome_completo, cl.nome_completo)'), 'chat: equipe vê o nome da conta definitiva');
$not = $ler('backend/_servicos/notificacao.php');
falsoVerificar(substr_count($not, 'SELECT g.id_cliente FROM cliente g WHERE g.id_empresa = ? AND (g.id_cliente = ? OR g.id_cliente_unificado = ?)') >= 2, 'notificações de chat consideram o grupo de contas');
falsoVerificar(str_contains($ler('backend/chat/chat_cliente.php'), "'ids_cliente' => chatClienteIdsGrupo("), 'endpoint do chat injeta o grupo de contas');

/* ---------------------------------------------------------------- chat: conversas históricas (somente leitura) */
falsoVerificar(str_contains($chat, 'function chatSqlConversaHistorica(') && str_contains($chat, 'ch.id_cliente_unificado IS NOT NULL'), 'chat: histórica = dono unificado em outra conta');
falsoVerificar(substr_count($chat, "chatConversaEhHistorica(\$conexao, \$conversa)") >= 2
    && substr_count($chat, "return ['resultado' => 'historica', 'mensagem' => null];") === 2, 'chat: backend recusa envio do cliente e da empresa em conversa histórica');
falsoVerificar(str_contains($chat, "chatConversaEhHistorica(\$conexao, ['id_empresa' => \$idEmpresa, 'id_cliente' => \$conversa['id_cliente']])"), 'chat: mensagens do sistema não entram no histórico');
falsoVerificar(substr_count($chat, "AND NOT \" . chatSqlConversaHistorica('c')") >= 4
    && str_contains($not, 'ch.id_cliente_unificado IS NOT NULL'), 'chat: histórico não gera não lidas nem notificações (cliente e empresa)');
falsoVerificar(str_contains($chat, "'pode_responder' => \$aberta && !\$historica,") && str_contains($chat, '$aberta && !$historica && $ctx[\'pode_responder\']'), 'chat: pode_responder falso para histórico nos dois lados');
falsoVerificar(!preg_match('/UPDATE\s+chat_conversa\s+SET\s+id_agendamento|UPDATE\s+chat_mensagem|DELETE\s+FROM\s+chat_/i', $chat), 'chat: nada de mensagens ou vínculos antigos é alterado');
$agHist = substr($chat, (int)strpos($chat, 'function chatAgendamentoDaConversaHistorica('), 700);
falsoVerificar(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $agHist), 'chat: localizar o agendamento da histórica só consulta (não altera nada)');
falsoVerificar(str_contains($agHist, 'id_agendamento IS NOT NULL') && str_contains($agHist, 'c.id_empresa = ?') && str_contains($agHist, "chatSqlConversaHistorica('c')"), 'chat: só histórica da própria empresa com agendamento');
foreach (['backend/chat/chat_cliente.php', 'backend/chat/chat_interno.php'] as $h) {
    $src = $ler($h);
    falsoVerificar(str_contains($src, 'chatAgendamentoDaConversaHistorica(') && str_contains($src, "'CHAT_HISTORICAL_CONVERSATION'") && str_contains($src, "'CHAT_ACTIVE_UNAVAILABLE'"), basename($h) . ': ação e bloqueio da conversa histórica');
}
foreach (['chat-modal-cliente', 'chat-modal-agenda', 'chat-interno'] as $j) {
    $src = $ler("public/js/chat/{$j}.js");
    falsoVerificar(str_contains($src, 'Histórico de conta unificada') && str_contains($src, 'Abrir conversa ativa') && str_contains($src, 'id_conversa_historica') && !str_contains($src, 'innerHTML'), "{$j}.js: rótulo, ação e sem innerHTML");
}

/* chat ativo após a unificação (índice único agendamento + cliente) */
falsoVerificar(str_contains($chat, "id_cliente = ? LIMIT 1',\n            'iii',\n            [\$idAgendamento, \$idEmpresa, \$idCliente]") && str_contains($chat, '$buscarAtiva()'), 'chat: cliente só reutiliza a conversa ATIVA da conta atual (nunca a histórica)');
falsoVerificar(substr_count($chat, '$buscarAtiva()') >= 2 && str_contains($chat, 'Criação concorrente da mesma conversa ativa'), 'chat: duplicidade (1062) relê a conversa ativa pelo índice composto');
falsoVerificar(str_contains($chat, "chatConversaDoAgendamento(\$conexao, \$ctx, \$idAgendamento, (int)\$agendamento['id_cliente'])"), 'chat: equipe localiza a ativa pelo cliente atual do agendamento');
falsoVerificar(str_contains($chat, 'function chatBuscarConversasVinculadasAgendamentoParaAtualizar(') && !str_contains($chat, 'chatBuscarConversaVinculadaAgendamentoParaAtualizar(') && substr_count($chat, 'foreach (chatBuscarConversasVinculadasAgendamentoParaAtualizar(') === 2, 'chat: edição e exclusão tratam todas as conversas do agendamento (sem LIMIT 1 arbitrário)');
falsoVerificar(str_contains($chat, "AND id_cliente = ?\n              LIMIT 1 FOR UPDATE"), 'chat: eventos automáticos só na conversa ativa do cliente do agendamento');
falsoVerificar(str_contains($chat, 'function chatClienteHistoricosDaConversa(') && str_contains($chat, 'function chatHistoricosDaConversa('), 'chat: históricos do agendamento acessíveis conforme a autorização de cada lado');
foreach (['backend/chat/chat_cliente.php', 'backend/chat/chat_interno.php'] as $h) {
    $src = $ler($h);
    falsoVerificar(str_contains($src, "'historicos'") || str_contains($src, 'ComHistoricos'), basename($h) . ': devolve os históricos do agendamento');
    falsoVerificar(str_contains($src, '$idHistoricaOrigem') && str_contains($src, "(int)\$resultado['id_conversa'] === \$idHistoricaOrigem"), basename($h) . ': nunca devolve a histórica como se fosse a ativa');
}
foreach (['chat-modal-cliente', 'chat-modal-agenda', 'chat-interno'] as $j) {
    falsoVerificar(str_contains($ler("public/js/chat/{$j}.js"), 'Ver histórico anterior'), "{$j}.js: botão Ver histórico anterior");
}
// O aviso padrão da tela da empresa fica dentro do formulário (oculto sem permissão de responder):
// a ação do histórico usa um bloco próprio, antes do formulário, e nunca o #chatAviso.
$htmlInterno = $ler('public/views/chat/chat-interno.html');
falsoVerificar(strpos($htmlInterno, 'id="chatAvisoHistorico"') !== false
    && strpos($htmlInterno, 'id="chatAvisoHistorico"') < strpos($htmlInterno, 'id="chatFormularioMensagem"'), 'chat interno: bloco do histórico fora (antes) do formulário oculto');
$jsInterno = $ler('public/js/chat/chat-interno.js');
$defHist = substr($jsInterno, (int)strpos($jsInterno, 'function definirAvisoHistorico('), 900);
falsoVerificar(str_contains($defHist, 'ui.avisoHistorico') && !str_contains($defHist, 'ui.aviso.') && str_contains($defHist, 'Abrir conversa ativa'), 'chat interno: "Abrir conversa ativa" renderizada no bloco visível');
falsoVerificar(str_contains($jsInterno, 'renderizarAcaoHistoricoAnterior();') && str_contains($jsInterno, 'definirAvisoHistorico("");'), 'chat interno: ação refeita a cada renderização (polling não a remove)');
/* correções finais da auditoria */
$sess = $ler('backend/cliente/_sessao_cliente.php');
falsoVerificar(str_contains($sess, 'SELECT status, id_cliente_unificado FROM cliente WHERE id_cliente = ? AND id_empresa = ? LIMIT 1')
    && str_contains($sess, "(string)\$linhaStatus['status'] !== 'ativo' || \$linhaStatus['id_cliente_unificado'] !== null")
    && str_contains($sess, 'sessaoCredencialRevogarCliente();'), 'sessão: conta inativa, bloqueada ou unificada tem a sessão revogada (mesmo sem senha)');
falsoVerificar(strpos($sess, 'sessaoCredencialExigirCliente(') < strpos($sess, 'SELECT status, id_cliente_unificado') && str_contains($sess, 'if ($idCliente !== null) {'), 'sessão: primeiro acesso (sem id_cliente) segue sem a checagem');
$salvar = $ler('backend/cliente/salvar_dados_cadastrais.php');
falsoVerificar(strpos($salvar, "\$cliente === null && \$sessao['id_cliente'] !== null") !== false
    && strpos($salvar, "\$cliente === null && \$sessao['id_cliente'] !== null") < strpos($salvar, 'INSERT INTO cliente'), 'cadastro: sessão com id_cliente nunca chega ao INSERT de conta nova');
// Escopo: as rotas das Configurações da Empresa NÃO são alteradas (padrão usado na restauração dos profissionais).
$central = $ler('public/api/api_central.php');
falsoVerificar(!str_contains($central, 'rotasSomenteSuporte')
    && str_contains($central, "'painel/configuracao-geral-buscar' => 'empresa.visualizar_configuracoes'")
    && str_contains($central, "'painel/configuracao-geral-salvar' => 'empresa.editar_configuracoes'"), 'configurações da empresa: rotas e permissões preservadas (sem gate novo)');
/* ---------------------------------------------------------------- cadastro e UI */
falsoVerificar(str_contains($ler('backend/cliente/salvar_dados_cadastrais.php'), "'oferecer_unificacao' => true"), 'cadastro oferece a unificação ao detectar e-mail de outra conta');
$html = $ler('public/views/cliente-perfil.html');
falsoVerificar(str_contains($html, 'id="unificacaoBox"') && str_contains($html, 'name="unif_principal"') && str_contains($html, 'id="unif_aceite"'), 'html: bloco de unificação com escolha da conta e aceite');
falsoVerificar(!preg_match('/name="unif_principal"[^>]*checked/', $html), 'html: nenhuma conta pré-selecionada');
$js = $ler('public/js/cliente/salvar-dados-cadastrais.js');
falsoVerificar(str_contains($js, 'acao: "unificar_confirmar"') && str_contains($js, 'CLIENT_UNIFY_PROOF_OK'), 'js: fluxo solicitar, provar e confirmar');
falsoVerificar(!str_contains($js, 'innerHTML'), 'js: sem innerHTML (texto sempre via textContent)');
falsoVerificar(str_contains($js, 'un.confirmar.disabled = !(escolhido("unif_principal") && un.aceite?.checked'), 'js: confirmar só com conta escolhida e aceite');

falsoRelatorio();
