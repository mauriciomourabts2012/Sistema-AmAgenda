<?php
declare(strict_types=1);

/*
 * Teste estático e simulado: não carrega autenticação, conexão ou sessão real.
 * Confere a superfície exata liberada durante a regularização e o contrato do
 * nome devolvido pela sessão interna.
 */

function perfilRegularizacaoFalhar(string $mensagem): never
{
    fwrite(STDERR, "FAIL: {$mensagem}\n");
    exit(1);
}

function perfilRegularizacaoAssert(bool $condicao, string $mensagem): void
{
    if (!$condicao) perfilRegularizacaoFalhar($mensagem);
}

$raiz = dirname(__DIR__, 2);
$requireAuth = file_get_contents($raiz . '/backend/_auth/require_auth.php');
$sessao = file_get_contents($raiz . '/backend/_auth/sessao.php');
$perfil = file_get_contents($raiz . '/backend/perfil_usuario/buscar_foto_perfil.php');

if (!is_string($requireAuth) || !is_string($sessao) || !is_string($perfil)) {
    perfilRegularizacaoFalhar('não foi possível ler os arquivos da correção A.');
}

preg_match_all("/'perfil\\/buscar-foto'/", $requireAuth, $rotas);
perfilRegularizacaoAssert(
    count($rotas[0]) === 2,
    'perfil/buscar-foto deve estar nas duas listas exatas de regularização.'
);
perfilRegularizacaoAssert(
    !preg_match("/'perfil\\/'/", $requireAuth),
    'nenhum prefixo amplo de perfil pode ser autorizado.'
);
perfilRegularizacaoAssert(
    !preg_match("/'perfil\\/(?:alterar-foto|salvar|editar)'/", $requireAuth),
    'rotas de alteração de perfil não podem ser liberadas pela correção.'
);

perfilRegularizacaoAssert(
    str_contains($perfil, "strtoupper(\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'"),
    'a consulta de perfil deve continuar limitada a GET.'
);
perfilRegularizacaoAssert(
    str_contains($perfil, "\$idUsuario = (int)(\$auth['id_usuario'] ?? 0)")
        && !preg_match('/\\$_(?:GET|POST|REQUEST)\\s*\\[[^]]*id_usuario/', $perfil),
    'o usuário consultado deve vir exclusivamente da sessão.'
);

perfilRegularizacaoAssert(
    str_contains(
        $sessao,
        <<<'PHP'
'nome_completo' => (string)($auth['nome'] ?? $auth['nome_completo'] ?? '')
PHP
    ),
    'a sessão interna deve priorizar auth.nome e preservar o fallback legado nome_completo.'
);

// 1.14.0 — Senha inicial: 24 horas a partir do primeiro login com ela; depois, troca obrigatória (backend e modal).
$login = (string)file_get_contents($raiz . '/backend/_auth/login.php');
$multi = (string)file_get_contents($raiz . '/backend/_regras/login_multiempresa.php');
$notificacoes = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/js/notificacoes/notificacoes.js'));
$mensagens = (string)file_get_contents($raiz . '/public/js/mensagem-universal.js');
$faturamento = (string)file_get_contents($raiz . '/public/js/painel-administrativo/faturamento/gerenciar-faturamento.js');
$pagamentoOnline = (string)file_get_contents($raiz . '/public/js/painel-administrativo/faturamento/pagamento-online.js');
perfilRegularizacaoAssert(
    str_contains($requireAuth, '$senhaTemporariaBloqueia = $senhaTemporariaVencida;')
        && !str_contains($requireAuth, "(\$tipoUsuario !== 'super_admin' && \$deveAlterarSenha)"),
    'o backend só deve impor a troca depois do prazo de 24 horas, igual para todos os usuários.'
);
// ATUALIZADO (pendências da senha temporária): o registro passou de um UPDATE único com CASE (dependia da ordem de
// avaliação das atribuições do MySQL) para duas atualizações separadas numa transação, com confirmação do estado.
$multiNormalizado = str_replace("\r\n", "\n", $multi);
perfilRegularizacaoAssert(
    preg_match('/UPDATE usuario SET data_senha_temporaria = NOW\(\)\s+WHERE id_usuario = \? AND deve_alterar_senha = 1\s+AND \(data_senha_temporaria IS NULL OR ultimo_login_em IS NULL OR ultimo_login_em < data_senha_temporaria\)/', $multiNormalizado) === 1
        && str_contains($multiNormalizado, "'UPDATE usuario SET ultimo_login_em = NOW() WHERE id_usuario = ?'")
        && preg_match('/\'prazo\' => "(.*?)",\n/s', $multiNormalizado, $sqlPrazo) === 1
        && !str_contains($sqlPrazo[1], 'ultimo_login_em = NOW'),
    'o prazo deve começar no primeiro login (condição reavaliada sob lock de linha) em atualização própria, sem ser a mesma que grava ultimo_login_em.'
);
perfilRegularizacaoAssert(
    strpos($multiNormalizado, '$conexao->begin_transaction()') < strpos($multiNormalizado, "'prazo' => \"UPDATE usuario SET data_senha_temporaria")
        && strpos($multiNormalizado, "'prazo' => \"UPDATE usuario SET data_senha_temporaria") < strpos($multiNormalizado, "'acesso' => 'UPDATE usuario SET ultimo_login_em")
        && strpos($multiNormalizado, "'acesso' => 'UPDATE usuario SET ultimo_login_em") < strpos($multiNormalizado, "throw \$falha('confirmacao_leitura');")
        && strpos($multiNormalizado, "throw \$falha('confirmacao_leitura');") < strpos($multiNormalizado, "throw \$falha('commit');")
        && str_contains($multiNormalizado, '$conexao->rollback();'),
    'transação: prazo, acesso e confirmação do estado antes do commit, com rollback em qualquer falha.'
);
perfilRegularizacaoAssert(
    substr_count($multiNormalizado, "out(['ok' => false, 'code' => 'LOGIN_TEMPORARIAMENTE_INDISPONIVEL', 'user_msg' => 'Não foi possível concluir o acesso agora. Tente novamente em instantes.'], 503);") === 1
        && strpos($multiNormalizado, "'LOGIN_TEMPORARIAMENTE_INDISPONIVEL'") < strpos($multiNormalizado, 'session_regenerate_id(true);', strpos($multiNormalizado, 'function loginMultiempresaConsolidarSessao('))
        && str_contains(str_replace("\r\n", "\n", $login), "'LOGIN_TEMPORARIAMENTE_INDISPONIVEL'"),
    'falha segura: sem o prazo confirmado o acesso é recusado (503 genérico) antes de qualquer sessão, no login central e no Super Admin.'
);
perfilRegularizacaoAssert(
    str_contains($multiNormalizado, 'n.prazo_em = DATE_ADD(u.data_senha_temporaria, INTERVAL 24 HOUR)') && str_contains($multiNormalizado, "n.codigo = 'seguranca.senha_temporaria'")
        && strpos($multiNormalizado, "throw \$falha('commit');") < strpos($multiNormalizado, 'UPDATE notificacao n'),
    'o aviso persistente do sino recebe o mesmo prazo (melhor esforço, depois do commit).'
);
perfilRegularizacaoAssert(
    substr_count($login . $multi, 'loginRegistrarAcessoComPrazoSenha(') === 3
        && !preg_match('/UPDATE usuario\s+SET ultimo_login_em = NOW\(\)/', $login)
        && !str_contains($multi, "'UPDATE usuario SET ultimo_login_em = NOW() WHERE id_usuario = ? LIMIT 1'"),
    'login do Super Admin e das empresas devem registrar o acesso pelo mesmo cálculo do prazo.'
);
perfilRegularizacaoAssert(
    str_contains($notificacoes, "if (senhaTemporariaVencida) {\n      fecharAvisoPrazoSenhaTemporaria();\n      ativarBloqueioSenha();")
        && !str_contains($notificacoes, 'exibirAvisoPrazoSenhaTemporaria') && str_contains($notificacoes, '"perfil.alterar_senha"'),
    'durante o prazo só o aviso do sino (sem toast duplicado); vencido, modal obrigatório.'
);
// Primeiro acesso: a mesma mensagem não é empilhada de novo (vários módulos com o mesmo 403).
perfilRegularizacaoAssert(
    str_contains($mensagens, '.ui-alert:not(.ui-alert--confirm):not(.is-leaving) .ui-alert__msg')
        && str_contains($mensagens, 'if (!opcoes.titulo && !opcoes.persistente) return repetida.closest(".ui-alert");')
        && str_contains($mensagens, 'repetida.closest(".ui-alert").remove();'),
    'o componente universal deve deduplicar mensagens iguais visíveis.'
);
// 1.14.0 — Cancelamento comercial: sucesso real com título próprio; o 403 do modo restrito não aparece como erro.
perfilRegularizacaoAssert(
    str_contains($faturamento, '{ titulo: "Assinatura cancelada com sucesso!", tempo: 7000 }')
        && str_contains($faturamento, 'Sua assinatura do AmAgenda foi cancelada. Você pode consultar as informações e o histórico no Faturamento.')
        && ($gravacao = strpos($faturamento, 'sessionStorage.setItem(CHAVE_AVISO_CANCELAMENTO, "1")')) !== false
        && strpos($faturamento, 'window.location.reload();', $gravacao) !== false,
    'o cancelamento imediato deve exibir o sucesso depois da recarga, com título e mensagem definidos.'
);
perfilRegularizacaoAssert(
    str_contains($pagamentoOnline, 'if (bloqueio?.code === "SESSION_REGULARIZATION_REQUIRED") {')
        && str_contains($requireAuth, "'A assinatura foi cancelada. Consulte o histórico financeiro ou contrate novamente no faturamento.'"),
    'a consulta do cartão em modo restrito deve ser silenciosa; o aviso do modo restrito permanece no backend.'
);

// ---------------------------------------------------------------------------------------------------------------
// Teste FUNCIONAL da regra de 24 horas: executa a função real de registro do login e a expressão real de vencimento
// (a mesma em login, seleção de empresa, sessão e require_auth) com relógio simulado. SQLite em memória; o adaptador
// traduz só NOW()/CURRENT_TIMESTAMP (relógio do teste), DATE_ADD(..., INTERVAL 24 HOUR), "LIMIT 1" em UPDATE e o
// UPDATE...JOIN da notificação para a forma equivalente do SQLite.
// ---------------------------------------------------------------------------------------------------------------
$EXPRESSAO_VENCIMENTO = 'deve_alterar_senha = 1 AND data_senha_temporaria IS NOT NULL AND CURRENT_TIMESTAMP >= DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR)';
foreach (['require_auth.php', 'sessao.php', 'login.php', 'selecionar_empresa.php'] as $arquivoAuth) {
    perfilRegularizacaoAssert(
        str_contains((string)preg_replace('/\s+/', ' ', (string)file_get_contents($raiz . '/backend/_auth/' . $arquivoAuth)), $EXPRESSAO_VENCIMENTO),
        "{$arquivoAuth} deve calcular o vencimento com a mesma expressão do registro do login."
    );
}
$GLOBALS['relogio'] = '2026-11-01 10:00:00';
$GLOBALS['pdoSenha'] = new PDO('sqlite::memory:');
$GLOBALS['pdoSenha']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdoSenha']->sqliteCreateFunction('AGORA', fn() => $GLOBALS['relogio'], 0);
$GLOBALS['pdoSenha']->sqliteCreateFunction('MAIS24H', fn($d) => $d === null ? null : date('Y-m-d H:i:s', strtotime((string)$d) + 86400), 1);
$GLOBALS['pdoSenha']->exec('CREATE TABLE usuario (id_usuario INTEGER PRIMARY KEY, deve_alterar_senha INT, data_senha_temporaria TEXT, ultimo_login_em TEXT)');
$GLOBALS['pdoSenha']->exec('CREATE TABLE notificacao (id_notificacao INTEGER PRIMARY KEY, id_empresa INT, destinatario_tipo TEXT, destinatario_id INT, codigo TEXT, prazo_em TEXT, concluida_em TEXT, cancelada_em TEXT, chave_deduplicacao TEXT UNIQUE)');
// Injeção de falhas do teste: trecho do SQL cuja preparação/execução/leitura/commit deve falhar (null = sem falha).
$GLOBALS['falhaSenha'] = ['prepare' => null, 'execute' => null, 'commit' => false];
ini_set('error_log', sys_get_temp_dir() . '/amagenda_teste_senha_temporaria.log');
@unlink(sys_get_temp_dir() . '/amagenda_teste_senha_temporaria.log');
final class SenhaStmtFalso
{
    private array $vars = [];
    private ?PDOStatement $st = null;
    private ?array $linhas = null;
    public int $num_rows = 0;
    public function __construct(private string $sql) {}
    public function bind_param(string $tipos, mixed &...$vars): bool { $this->vars = &$vars; return true; }
    public function store_result(): bool { $this->linhas = $this->st->fetchAll(PDO::FETCH_ASSOC); $this->num_rows = count($this->linhas); return true; }
    public function execute(): bool
    {
        $alvo = $GLOBALS['falhaSenha']['execute'];
        if ($alvo !== null && str_contains($this->sql, $alvo)) return false;
        $sql = str_replace(['CURRENT_TIMESTAMP', 'NOW()'], 'AGORA()', $this->sql);
        $sql = (string)preg_replace('/DATE_ADD\((.+?), INTERVAL 24 HOUR\)/', 'MAIS24H($1)', $sql);
        if (str_starts_with(ltrim($sql), 'UPDATE')) $sql = (string)preg_replace('/\s+LIMIT 1\s*$/', '', rtrim($sql));
        if (str_contains($sql, 'UPDATE notificacao n')) {
            // Mesma regra do UPDATE...JOIN do MySQL, na forma do SQLite.
            $sql = "UPDATE notificacao SET prazo_em = (SELECT MAIS24H(u.data_senha_temporaria) FROM usuario u WHERE u.id_usuario = notificacao.destinatario_id)
                     WHERE destinatario_id = ? AND destinatario_tipo IN ('usuario', 'super_admin') AND codigo = 'seguranca.senha_temporaria'
                       AND concluida_em IS NULL AND cancelada_em IS NULL
                       AND EXISTS (SELECT 1 FROM usuario u WHERE u.id_usuario = notificacao.destinatario_id AND u.deve_alterar_senha = 1 AND u.data_senha_temporaria IS NOT NULL)";
        }
        $this->st = $GLOBALS['pdoSenha']->prepare($sql);

        return $this->st->execute(array_values($this->vars));
    }
    public function get_result(): object
    {
        $st = $this->st;
        $linhas = $this->linhas ?? $st->fetchAll(PDO::FETCH_ASSOC);
        return new class($linhas) { public function __construct(private array $l) {} public function fetch_assoc(): ?array { return array_shift($this->l); } };
    }
    public function close(): bool { return true; }
}
final class SenhaBancoFalso extends mysqli
{
    public function __construct() {}
    public function prepare(string $query): SenhaStmtFalso|false
    {
        $alvo = $GLOBALS['falhaSenha']['prepare'];
        return $alvo !== null && str_contains($query, $alvo) ? false : new SenhaStmtFalso($query);
    }
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { $GLOBALS['pdoSenha']->beginTransaction(); return true; }
    public function commit(int $flags = 0, ?string $name = null): bool
    {
        if ($GLOBALS['falhaSenha']['commit']) return false;
        $GLOBALS['pdoSenha']->commit();
        return true;
    }
    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        if ($GLOBALS['pdoSenha']->inTransaction()) $GLOBALS['pdoSenha']->rollBack();
        return true;
    }
}
$fonteMulti = str_replace("\r\n", "\n", $multi);
preg_match('/^function loginRegistrarAcessoComPrazoSenha\(.*?\n}\n/ms', $fonteMulti, $funcaoRegistro);
perfilRegularizacaoAssert(isset($funcaoRegistro[0]), 'a função de registro do login deve existir.');
eval($funcaoRegistro[0]);
$bd = new SenhaBancoFalso();
$pdoS = $GLOBALS['pdoSenha'];
$usuario = fn(int $id) => $pdoS->query("SELECT * FROM usuario WHERE id_usuario = {$id}")->fetch(PDO::FETCH_ASSOC);
$vencidaAgora = function (int $id) use ($pdoS, $EXPRESSAO_VENCIMENTO): bool {
    // A expressão REAL do backend, traduzida pelas mesmas regras do adaptador.
    $sql = (string)preg_replace('/DATE_ADD\((.+?), INTERVAL 24 HOUR\)/', 'MAIS24H($1)', str_replace('CURRENT_TIMESTAMP', 'AGORA()', $EXPRESSAO_VENCIMENTO));
    return (int)$pdoS->query("SELECT ({$sql}) FROM usuario WHERE id_usuario = {$id}")->fetchColumn() === 1;
};
$prazoSino = fn(int $id) => $pdoS->query("SELECT prazo_em FROM notificacao WHERE destinatario_id = {$id} AND concluida_em IS NULL")->fetchColumn();

// Usuário novo (Proprietário/Profissional/Recepção: tipo 'usuario'): senha emitida às 08:00, nunca logou.
$pdoS->exec("INSERT INTO usuario VALUES (1, 1, '2026-11-01 08:00:00', NULL)");
$pdoS->exec("INSERT INTO notificacao (id_notificacao, id_empresa, destinatario_tipo, destinatario_id, codigo, prazo_em) VALUES (10, 1, 'usuario', 1, 'seguranca.senha_temporaria', '2026-11-02 08:00:00')");
$GLOBALS['relogio'] = '2026-11-01 10:00:00';
$v1 = loginRegistrarAcessoComPrazoSenha($bd, 1);
perfilRegularizacaoAssert($v1 === false && $usuario(1)['data_senha_temporaria'] === '2026-11-01 10:00:00' && $usuario(1)['ultimo_login_em'] === '2026-11-01 10:00:00',
    'o primeiro login com a senha inicial deve iniciar o prazo neste acesso e persistir no banco.');
perfilRegularizacaoAssert($prazoSino(1) === '2026-11-02 10:00:00', 'o sino deve receber o mesmo prazo do backend (primeiro login + 24h).');
// Login simultâneo / repetido no mesmo instante e novos logins depois: o prazo nunca reinicia.
loginRegistrarAcessoComPrazoSenha($bd, 1);
$GLOBALS['relogio'] = '2026-11-01 18:30:00';
loginRegistrarAcessoComPrazoSenha($bd, 1);
perfilRegularizacaoAssert($usuario(1)['data_senha_temporaria'] === '2026-11-01 10:00:00' && $prazoSino(1) === '2026-11-02 10:00:00',
    'logins simultâneos, novo login, outro navegador ou atualização não reiniciam o prazo.');
// Limites: 23h59 libera; 24h exatas e 24h01 vencem (sessão aberta é bloqueada na próxima requisição).
$GLOBALS['relogio'] = '2026-11-02 09:59:00';
perfilRegularizacaoAssert($vencidaAgora(1) === false, 'com 23h59 o acesso continua normal.');
$GLOBALS['relogio'] = '2026-11-02 10:00:00';
perfilRegularizacaoAssert($vencidaAgora(1) === true, 'com 24h exatas a troca passa a ser obrigatória.');
$GLOBALS['relogio'] = '2026-11-02 10:01:00';
perfilRegularizacaoAssert($vencidaAgora(1) === true && loginRegistrarAcessoComPrazoSenha($bd, 1) === true,
    'com 24h01 continua obrigatória, inclusive em novo login.');
// Troca confirmada: obrigação removida imediatamente; login seguinte não recria prazo.
$pdoS->exec("UPDATE usuario SET deve_alterar_senha = 0, data_senha_temporaria = NULL WHERE id_usuario = 1");
perfilRegularizacaoAssert($vencidaAgora(1) === false && loginRegistrarAcessoComPrazoSenha($bd, 1) === false && $usuario(1)['data_senha_temporaria'] === null,
    'depois da troca a obrigação some imediatamente e não volta com novo login.');
// Usuário EXISTENTE que já entrou depois da emissão: não ganha novo prazo (vale a emissão, como antes).
$pdoS->exec("INSERT INTO usuario VALUES (2, 1, '2026-10-30 08:00:00', '2026-10-30 09:00:00')");
$GLOBALS['relogio'] = '2026-11-01 10:00:00';
perfilRegularizacaoAssert(loginRegistrarAcessoComPrazoSenha($bd, 2) === true && $usuario(2)['data_senha_temporaria'] === '2026-10-30 08:00:00',
    'usuário existente que já entrou com a senha temporária não recebe automaticamente novo prazo.');
// Nova senha temporária emitida depois (Super Admin/reset): o próximo login inicia um novo prazo.
$pdoS->exec("INSERT INTO usuario VALUES (3, 1, '2026-11-01 09:00:00', '2026-10-20 12:00:00')");
$GLOBALS['relogio'] = '2026-11-01 11:00:00';
perfilRegularizacaoAssert(loginRegistrarAcessoComPrazoSenha($bd, 3) === false && $usuario(3)['data_senha_temporaria'] === '2026-11-01 11:00:00',
    'senha temporária reemitida: o primeiro login depois dela inicia o prazo.');
// Super Admin segue a mesma regra (mesma função no login).
perfilRegularizacaoAssert(str_contains($login, "\$senhaTemporariaVencida = loginRegistrarAcessoComPrazoSenha(\$conexao, (int)\$user['id_usuario']);"),
    'o Super Admin deve registrar o login pela mesma regra.');
// Bloqueio só da senha vencida; logout, sessão e recuperação fora do bloqueio; troca liberada.
$api = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/api/api_central.php'));
$loginSrc = str_replace("\r\n", "\n", $login);
perfilRegularizacaoAssert(
    str_contains($api, "if (\$rota === 'perfil/alterar-senha') {\n    define('AUTH_PERMITIR_SENHA_TEMPORARIA_VENCIDA', true);")
        && preg_match("/'_auth\\/session',\\s*'_auth\\/logout',\\s*'perfil\\/alterar-senha',/", $api) === 1
        && str_contains($loginSrc, "'PASSWORD_RECOVERY_COMPLETED'"),
    'logout, sessão, alteração de senha e recuperação de acesso devem continuar disponíveis.'
);
perfilRegularizacaoAssert(
    str_contains($loginSrc, "UPDATE notificacao SET concluida_em = NOW()") && strpos($loginSrc, "UPDATE notificacao SET concluida_em = NOW()") > strpos($loginSrc, 'deve_alterar_senha = 0, data_senha_temporaria = NULL'),
    'a recuperação de acesso também deve encerrar o aviso de senha temporária do sino.'
);
$perfilSenha = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/backend/perfil_usuario/alterar_senha_perfil.php'));
perfilRegularizacaoAssert(
    str_contains($perfilSenha, 'deve_alterar_senha = 0, data_senha_temporaria = NULL')
        && strpos($perfilSenha, "WHERE destinatario_tipo = ? AND destinatario_id = ? AND codigo = 'seguranca.senha_temporaria'") < strpos($perfilSenha, '$conexao->commit();', strpos($perfilSenha, 'notificacaoConcluir('))
        && str_contains($perfilSenha, "\$_SESSION['auth']['deve_alterar_senha'] = false;"),
    'a troca pelo perfil remove a obrigação, a sessão e os avisos do sino (todas as empresas) na mesma transação.'
);

// ---------------------------------------------------------------------------------------------------------------
// Pendências da senha temporária: falha segura, atomicidade, concorrência, usuários existentes, perfis e aviso
// multiempresa. Funções reais executadas em SQLite com relógio simulado (NÃO é MySQL real).
// ---------------------------------------------------------------------------------------------------------------
$limparFalhas = function (): void { $GLOBALS['falhaSenha'] = ['prepare' => null, 'execute' => null, 'commit' => false]; };
$tentar = function (int $id) use ($bd): ?string {
    try {
        loginRegistrarAcessoComPrazoSenha($bd, $id);
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$GLOBALS['relogio'] = '2026-11-03 09:00:00';
$pdoS->exec("INSERT INTO usuario VALUES (20, 1, '2026-11-03 08:00:00', NULL)");
$intactoNoUsuario20 = fn() => $usuario(20)['data_senha_temporaria'] === '2026-11-03 08:00:00' && $usuario(20)['ultimo_login_em'] === null && !$pdoS->inTransaction();
$falhas = [
    'gravação do acesso depois do prazo (rollback do prazo já gravado)' => ['execute', 'SET ultimo_login_em = NOW()'],
    'gravação do início do prazo' => ['execute', 'SET data_senha_temporaria = NOW()'],
    'preparo da gravação do acesso' => ['prepare', 'SET ultimo_login_em = NOW()'],
    'leitura de confirmação do estado' => ['execute', 'SELECT deve_alterar_senha'],
    'commit' => ['commit', true],
];
foreach ($falhas as $descricao => [$tipo, $alvo]) {
    $limparFalhas();
    $GLOBALS['falhaSenha'][$tipo] = $alvo;
    $resultado = $tentar(20);
    $limparFalhas();
    perfilRegularizacaoAssert($resultado === 'LOGIN_PRAZO_SENHA_NAO_REGISTRADO' && $intactoNoUsuario20(),
        "falha segura ({$descricao}): o login não prossegue e nada fica gravado (sem prazo parcial, sem acesso registrado).");
}
perfilRegularizacaoAssert($tentar(20) === null && $usuario(20)['data_senha_temporaria'] === '2026-11-03 09:00:00' && $usuario(20)['ultimo_login_em'] === '2026-11-03 09:00:00',
    'erro transitório não bloqueia a conta: a nova tentativa funciona e inicia o prazo uma única vez.');
perfilRegularizacaoAssert($tentar(999) === 'LOGIN_PRAZO_SENHA_NAO_REGISTRADO', 'usuário inexistente: o estado não é confirmado e o acesso não é concedido.');
$limparFalhas();
$GLOBALS['falhaSenha']['execute'] = 'UPDATE notificacao n';
perfilRegularizacaoAssert($tentar(20) === null, 'a sincronização do prazo do aviso é melhor esforço: sua falha não impede o login já confirmado.');
$limparFalhas();
$logPrazo = (string)@file_get_contents(sys_get_temp_dir() . '/amagenda_teste_senha_temporaria.log');
$linhasLog = array_values(array_filter(array_map('trim', explode("\n", $logPrazo))));
perfilRegularizacaoAssert($linhasLog !== [] && count(array_filter($linhasLog, fn($l) => preg_match('/\[login_prazo_senha\] falha na etapa: [a-z_]+$/', $l) !== 1)) === 0
        && !preg_match('/UPDATE|SELECT|id_usuario|senha_hash|@/i', preg_replace('/falha na etapa: [a-z_]+/', '', $logPrazo)),
    'o log registra só a etapa, sem SQL, dados do usuário ou mensagem do banco (e a resposta ao usuário é genérica).');
// Usuário sem senha temporária: só o acesso é registrado (data permanece nula).
$pdoS->exec("INSERT INTO usuario VALUES (21, 0, NULL, '2026-10-01 10:00:00')");
perfilRegularizacaoAssert($tentar(21) === null && $usuario(21)['data_senha_temporaria'] === null && $usuario(21)['ultimo_login_em'] === '2026-11-03 09:00:00',
    'usuário com senha definitiva: apenas o acesso é registrado; nenhum prazo é criado.');
// Inconsistência legada (deve_alterar_senha = 1 sem data): o prazo passa a existir no login, em vez de ficar sem controle.
$pdoS->exec("INSERT INTO usuario VALUES (22, 1, NULL, NULL)");
perfilRegularizacaoAssert($tentar(22) === null && $usuario(22)['data_senha_temporaria'] === '2026-11-03 09:00:00',
    'senha temporária sem data (legado): o login inicia o prazo, sem deixar a conta sem controle.');
$GLOBALS['relogio'] = '2026-11-03 13:00:00';
$tentar(22);
perfilRegularizacaoAssert($usuario(22)['data_senha_temporaria'] === '2026-11-03 09:00:00', 'o prazo curado não é reiniciado nos logins seguintes.');
// Concorrência (modelo serializado pelo bloqueio de linha do InnoDB): A e B no mesmo segundo e C no segundo seguinte.
$pdoS->exec("INSERT INTO usuario VALUES (23, 1, '2026-11-03 08:00:00', NULL)");
$GLOBALS['relogio'] = '2026-11-03 14:00:00';
$tentar(23);
$tentar(23);
$GLOBALS['relogio'] = '2026-11-03 14:00:01';
$tentar(23);
perfilRegularizacaoAssert($usuario(23)['data_senha_temporaria'] === '2026-11-03 14:00:00' && $usuario(23)['ultimo_login_em'] === '2026-11-03 14:00:01',
    'dois logins simultâneos e um terceiro depois: o início do prazo continua sendo o do primeiro.');
// Quatro perfis internos (Proprietário, Profissional, Recepção, Super Admin): a regra não depende do perfil.
foreach ([30 => 'proprietario', 31 => 'profissional', 32 => 'recepcao', 33 => 'super_admin'] as $idPerfil => $nomePerfil) {
    $pdoS->exec("INSERT INTO usuario VALUES ({$idPerfil}, 1, '2026-11-03 08:00:00', NULL)");
    $GLOBALS['relogio'] = '2026-11-03 15:00:00';
    $tentar($idPerfil);
    $GLOBALS['relogio'] = '2026-11-04 14:59:00';
    $emPrazo = !$vencidaAgora($idPerfil);
    $GLOBALS['relogio'] = '2026-11-04 15:00:00';
    perfilRegularizacaoAssert($emPrazo && $vencidaAgora($idPerfil) && $usuario($idPerfil)['data_senha_temporaria'] === '2026-11-03 15:00:00',
        "perfil {$nomePerfil}: 24 horas a partir do primeiro login e bloqueio exatamente em 24h.");
}
// Aviso multiempresa: funções REAIS de notificacao.php sobre o mesmo banco simulado.
$fonteNotificacao = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/backend/_servicos/notificacao.php'));
foreach (['notificacaoInteiroPositivo', 'notificacaoPrazoSenhaTemporaria', 'notificacaoGarantirSenhaTemporaria'] as $funcaoNotificacao) {
    perfilRegularizacaoAssert(preg_match('/^function ' . $funcaoNotificacao . '\(.*?\n}\n/ms', $fonteNotificacao, $mf) === 1, "função {$funcaoNotificacao} deve existir.");
    eval($mf[0]);
}
perfilRegularizacaoAssert(preg_match("/^const NOTIFICACAO_SENHA_TEMPORARIA_CODIGO = '[^']+';/m", $fonteNotificacao, $mc) === 1, 'constante do código do aviso deve existir.');
eval($mc[0]);
function notificacaoPreparar(mysqli $conexao, string $sql): SenhaStmtFalso
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('preparo');
    return $stmt;
}
$GLOBALS['criadasSenha'] = [];
// Contrato de notificacaoCriar (criada/ja_existia pela chave de deduplicação), sem as validações do serviço real.
function notificacaoCriar(mysqli $conexao, array $d): array
{
    try {
        $st = $GLOBALS['pdoSenha']->prepare('INSERT INTO notificacao (id_empresa,destinatario_tipo,destinatario_id,codigo,prazo_em,chave_deduplicacao) VALUES (?,?,?,?,?,?)');
        $st->execute([$d['id_empresa'], $d['destinatario_tipo'], $d['destinatario_id'], $d['codigo'], $d['prazo_em'], $d['chave_deduplicacao']]);
        $GLOBALS['criadasSenha'][] = $d;
        return ['id_notificacao' => (int)$GLOBALS['pdoSenha']->lastInsertId(), 'criada' => true, 'ja_existia' => false];
    } catch (PDOException) {
        return ['id_notificacao' => 0, 'criada' => false, 'ja_existia' => true];
    }
}
$contagem = fn(int $usuarioId, int $empresaId) => (int)$pdoS->query("SELECT COUNT(*) FROM notificacao WHERE destinatario_id = {$usuarioId} AND id_empresa = {$empresaId} AND codigo = 'seguranca.senha_temporaria' AND concluida_em IS NULL")->fetchColumn();
// Usuário 40 em duas empresas: aviso criado na empresa 1 (no cadastro), nenhum na empresa 2; prazo iniciado no 1º login.
$pdoS->exec("INSERT INTO usuario VALUES (40, 1, '2026-11-03 10:00:00', '2026-11-03 10:00:00')");
$pdoS->exec("INSERT INTO notificacao (id_empresa, destinatario_tipo, destinatario_id, codigo, prazo_em, chave_deduplicacao) VALUES (1, 'usuario', 40, 'seguranca.senha_temporaria', '2026-11-04 08:00:00', 'seguranca.senha_temporaria:empresa:1:usuario:40:ocorrencia:abc')");
$pdoS->exec("INSERT INTO notificacao (id_empresa, destinatario_tipo, destinatario_id, codigo, prazo_em, chave_deduplicacao) VALUES (2, 'usuario', 42, 'agenda.agendamento_pendente', NULL, 'agenda:x')");
$g2 = notificacaoGarantirSenhaTemporaria($bd, 2, 40);
$c = $GLOBALS['criadasSenha'][0] ?? [];
perfilRegularizacaoAssert($g2 === ['criada' => true, 'motivo' => 'criada'] && $contagem(40, 2) === 1
        && ($c['titulo'] ?? '') === 'Altere sua senha temporária' && ($c['mensagem'] ?? '') === 'Sua senha atual é temporária. Altere-a dentro de 24 horas.'
        && ($c['prioridade'] ?? '') === 'alta' && ($c['obrigatoria'] ?? null) === true && ($c['acao_codigo'] ?? '') === 'perfil.alterar_senha'
        && ($c['categoria'] ?? '') === 'seguranca' && ($c['prazo_em'] ?? '') === '2026-11-04 10:00:00',
    'usuário em várias empresas: o aviso aparece também na empresa 2, com a mesma mensagem e o prazo do backend (início + 24h).');
perfilRegularizacaoAssert(notificacaoGarantirSenhaTemporaria($bd, 2, 40)['motivo'] === 'ja_ativa' && notificacaoGarantirSenhaTemporaria($bd, 1, 40)['motivo'] === 'ja_ativa'
        && $contagem(40, 1) === 1 && $contagem(40, 2) === 1,
    'listagens repetidas e a empresa de origem não geram avisos duplicados.');
$chaves = $pdoS->query("SELECT chave_deduplicacao FROM notificacao WHERE destinatario_id = 40 AND id_empresa = 2")->fetchAll(PDO::FETCH_COLUMN);
perfilRegularizacaoAssert(count($chaves) === 1 && str_contains($chaves[0], ':empresa:2:usuario:40:') && !str_contains($chaves[0], ':empresa:1:'),
    'a chave de deduplicação é por empresa e usuário (sem cruzar empresas).');
perfilRegularizacaoAssert((int)$pdoS->query("SELECT COUNT(*) FROM notificacao WHERE destinatario_id = 42")->fetchColumn() === 1
        && notificacaoGarantirSenhaTemporaria($bd, 2, 21)['motivo'] === 'sem_senha_temporaria' && $contagem(21, 2) === 0,
    'só quem ainda deve trocar a senha recebe o aviso; notificações empresariais de outros usuários e tipos ficam intactas.');
perfilRegularizacaoAssert(notificacaoPrazoSenhaTemporaria($bd, 40) === ['inicio' => '2026-11-03 10:00:00', 'prazo' => '2026-11-04 10:00:00']
        && $pdoS->query("SELECT prazo_em FROM notificacao WHERE destinatario_id = 40 AND id_empresa = 1")->fetchColumn() === '2026-11-04 08:00:00',
    'o prazo exibido vem de usuario.data_senha_temporaria + 24h, mesmo que o prazo gravado no aviso esteja defasado.');
// Troca da senha encerra todos; nova senha temporária (reemissão) volta a ter aviso, com chave nova.
$pdoS->exec("UPDATE usuario SET deve_alterar_senha = 0, data_senha_temporaria = NULL WHERE id_usuario = 40");
$pdoS->exec("UPDATE notificacao SET concluida_em = '2026-11-03 12:00:00' WHERE destinatario_id = 40");
perfilRegularizacaoAssert(notificacaoGarantirSenhaTemporaria($bd, 2, 40)['motivo'] === 'sem_senha_temporaria' && $contagem(40, 1) + $contagem(40, 2) === 0,
    'depois da troca de senha nenhum aviso é recriado.');
$pdoS->exec("UPDATE usuario SET deve_alterar_senha = 1, data_senha_temporaria = '2026-11-10 09:00:00' WHERE id_usuario = 40");
perfilRegularizacaoAssert(notificacaoGarantirSenhaTemporaria($bd, 2, 40)['criada'] === true && $contagem(40, 2) === 1
        && (int)$pdoS->query("SELECT COUNT(DISTINCT chave_deduplicacao) FROM notificacao WHERE destinatario_id = 40 AND id_empresa = 2")->fetchColumn() === 2,
    'nova senha temporária (reemissão) recebe um novo aviso, sem colidir com o encerrado.');
// Integração do aviso na listagem e no sino (estática: o handler não é executável sem o servidor).
$apiNotificacoes = str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/public/api/api_central.php'));
perfilRegularizacaoAssert(
    str_contains($apiNotificacoes, "if (\$destinatarioTipo === 'usuario' && !\$modoSuporteNotificacoes\n            && (\$authNotificacoes['deve_alterar_senha'] ?? false) === true) {")
        && str_contains($apiNotificacoes, 'notificacaoGarantirSenhaTemporaria($conexao, $idEmpresaNotificacoes, $idDestinatario);')
        && strpos($apiNotificacoes, 'notificacaoGarantirSenhaTemporaria(') < strpos($apiNotificacoes, '$pendentes = notificacaoListarPendentes(')
        && str_contains($apiNotificacoes, "\$prazoSenhaTemporaria = notificacaoPrazoSenhaTemporaria(\$conexao, \$idDestinatario)['prazo'] ?? null;")
        && str_contains($apiNotificacoes, "(string)\$item['codigo'] === NOTIFICACAO_SENHA_TEMPORARIA_CODIGO && \$prazoSenhaTemporaria !== null"),
    'a listagem garante o aviso na empresa atual só para quem deve trocar a senha e exibe sempre o prazo do backend.'
);
foreach (['/backend/painel_administrativo/usuario/cadastrar_usuario.php', '/backend/_servicos/notificacao.php'] as $arquivoTexto) {
    $textoAviso = (string)file_get_contents($raiz . $arquivoTexto);
    perfilRegularizacaoAssert(str_contains($textoAviso, "'Altere sua senha temporária'") && str_contains($textoAviso, "'Sua senha atual é temporária. Altere-a dentro de 24 horas.'"),
        "a mensagem original do aviso foi preservada em {$arquivoTexto}.");
}
$cssSino = (string)file_get_contents($raiz . '/public/css/menu-lateral/menu-lateral-web.css');
perfilRegularizacaoAssert(
    str_contains($notificacoes, 'prazo.textContent = `Prazo para alteração: ${prazoSenha}.`;')
        && str_contains($notificacoes, 'item.codigo === "seguranca.senha_temporaria" ? formatarPrazoNotificacao(item.prazo_em) : ""')
        && str_contains($notificacoes, '`${partes[3]}/${partes[2]}/${partes[1]} às ${partes[4]}:${partes[5]}`')
        && !str_contains($notificacoes, 'Date.now() + ') && str_contains($cssSino, '.notificacao-item-prazo {'),
    'o sino acrescenta "Prazo para alteração" com o prazo do backend, sem recalcular a partir da abertura.'
);

// Logout no modo de regularização: mesmo com contexto empresarial parcial, o
// destino seguro é o login central; a tela legada do Super Admin não participa.
$codigoLogoutRegularizacao = <<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
session_start();
$_SESSION = [
    'empresa_id' => 123,
    'auth' => ['modo_regularizacao' => true],
];
require $argv[1];
PHP;
$processoLogoutRegularizacao = proc_open(
    [PHP_BINARY, '-r', $codigoLogoutRegularizacao, $raiz . '/backend/_auth/logout.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipesLogoutRegularizacao
);
perfilRegularizacaoAssert(is_resource($processoLogoutRegularizacao), 'não foi possível iniciar o teste do logout.');
fclose($pipesLogoutRegularizacao[0]);
$saidaLogoutRegularizacao = stream_get_contents($pipesLogoutRegularizacao[1]);
$erroLogoutRegularizacao = stream_get_contents($pipesLogoutRegularizacao[2]);
fclose($pipesLogoutRegularizacao[1]);
fclose($pipesLogoutRegularizacao[2]);
$statusLogoutRegularizacao = proc_close($processoLogoutRegularizacao);
$respostaLogoutRegularizacao = json_decode((string)$saidaLogoutRegularizacao, true);
perfilRegularizacaoAssert(
    $statusLogoutRegularizacao === 0
        && $erroLogoutRegularizacao === ''
        && is_array($respostaLogoutRegularizacao)
        && ($respostaLogoutRegularizacao['code'] ?? '') === 'LOGOUT_OK'
        && ($respostaLogoutRegularizacao['redirect_url'] ?? '') === '/public/views/login-empresa.php',
    'o logout no modo de regularização deve encerrar a sessão e redirecionar ao login central das empresas.'
);

fwrite(STDOUT, "PASS: perfil próprio liberado de forma exata e nome da sessão compatível.\n");
fwrite(STDOUT, "PASS: senha inicial com prazo de 24h desde o primeiro login, avisos sem duplicidade e cancelamento comercial com sucesso real.\n");
fwrite(STDOUT, "PASS: prazo funcional (primeiro login, 23h59/24h/24h01, sem reinício, existentes, reemissão, troca, recuperação).\n");
fwrite(STDOUT, "PASS: falha segura e atomicidade do registro (rollback), concorrência modelada, usuários existentes, quatro perfis e aviso multiempresa (simulados em SQLite).\n");
fwrite(STDOUT, "PASS: logout no modo de regularização encerra a sessão e retorna ao login central.\n");

