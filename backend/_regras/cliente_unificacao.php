<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| UNIFICAÇÃO DE CONTAS DUPLICADAS DO CLIENTE (C4.2)
|--------------------------------------------------------------------------
|
| O mesmo cliente pode ter duas contas na mesma empresa (acessou por e-mail e depois por
| celular, ou foi cadastrado pelo profissional). A unificação é feita SOMENTE pelo próprio
| cliente, sem aprovação da empresa, e nunca é automática:
|
|  1. o cliente está autenticado há pouco tempo na conta atual;
|  2. informa um contato da OUTRA conta e comprova a posse dele por código (OTP). O celular
|     cadastrado pelo profissional NÃO é presumido verificado: o código é sempre exigido;
|  3. CPF e nome são apenas sinais auxiliares na prévia, jamais prova;
|  4. o cliente escolhe a conta DEFINITIVA, resolve contatos divergentes (nunca descartados
|     em silêncio) e confirma expressamente;
|  5. uma transação (com bloqueio das duas linhas) transfere TODOS os agendamentos, preserva o
|     histórico do chat (as conversas não são movidas: a autorização do chat enxerga o grupo de
|     contas, ver chat.php), incorpora contatos/perfil, inativa a conta secundária sem apagá-la
|     e grava a trilha de recuperação em cliente_unificacao + auditoria.
|
| Documentos legais (documento_legal_manifestacao), auditoria anterior e conversas permanecem
| nos registros originais; nada é excluído. Não usa foreign_key_checks nem altera FKs/CHECKs.
|
*/

require_once __DIR__ . '/../_servicos/sms_otp.php';
require_once __DIR__ . '/../_servicos/email_otp.php';
require_once __DIR__ . '/../_servicos/auditoria.php';

const CLIENTE_UNIF_SESSAO_PROVA = 'cliente_unificacao_prova';
const CLIENTE_UNIF_LOGIN_RECENTE_SEGUNDOS = 900;
const CLIENTE_UNIF_PROVA_VALIDADE_SEGUNDOS = 600;

/** A tabela de recuperação (SQL manual) existe? Sem ela a unificação fica indisponível. */
function clienteUnifTabelaDisponivel(mysqli $conexao): bool
{
    static $cache = [];
    $chave = spl_object_id($conexao);
    if (!array_key_exists($chave, $cache)) {
        $cache[$chave] = smsConsultar(
            $conexao,
            "SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'cliente_unificacao' LIMIT 1"
        ) !== [];
    }

    return $cache[$chave];
}

/** O login desta sessão é recente o bastante para uma operação sensível? */
function clienteUnifLoginRecente(array $auth, ?int $agora = null): bool
{
    $em = strtotime((string)($auth['login_em'] ?? ''));
    $agora ??= time();

    return $em !== false && $em <= $agora + 60 && ($agora - $em) <= CLIENTE_UNIF_LOGIN_RECENTE_SEGUNDOS;
}

/** Chave comparável do celular (somente dígitos nacionais; ignora +55 e máscara). */
function clienteUnifChaveTelefone(?string $valor): ?string
{
    $digitos = preg_replace('/\D+/', '', (string)$valor) ?? '';
    if ($digitos === '') {
        return null;
    }
    if (strlen($digitos) > 11 && str_starts_with($digitos, '55')) {
        $digitos = substr($digitos, 2);
    }

    return $digitos;
}

function clienteUnifChaveEmail(?string $valor): ?string
{
    $email = mb_strtolower(trim((string)$valor), 'UTF-8');

    return $email === '' ? null : $email;
}

function clienteUnifChaveContato(string $tipo, ?string $valor): ?string
{
    return $tipo === 'telefone' ? clienteUnifChaveTelefone($valor) : clienteUnifChaveEmail($valor);
}

function clienteUnifMascararTelefone(?string $valor): string
{
    $chave = clienteUnifChaveTelefone($valor);
    if ($chave === null) {
        return '';
    }

    return '(**) *****-' . substr($chave, -4);
}

function clienteUnifMascararEmail(?string $valor): string
{
    $email = clienteUnifChaveEmail($valor);
    if ($email === null || !str_contains($email, '@')) {
        return '';
    }
    [$local, $dominio] = explode('@', $email, 2);

    return mb_substr($local, 0, 1, 'UTF-8') . '***@' . $dominio;
}

/**
 * Elegibilidade do par (atual, outra) na mesma empresa. Devolve null quando válido ou o código do
 * motivo. Impede conta de outra empresa, a mesma conta, conta inativa/bloqueada e conta que já foi
 * unificada em outra (evita ciclo e cadeia).
 *
 * @param array<string, mixed>|null $a
 * @param array<string, mixed>|null $b
 */
function clienteUnifValidarPar(?array $a, ?array $b, int $idEmpresa): ?string
{
    if ($a === null || $b === null) {
        return 'CONTA_INEXISTENTE';
    }
    if ((int)$a['id_empresa'] !== $idEmpresa || (int)$b['id_empresa'] !== $idEmpresa) {
        return 'EMPRESA_DIFERENTE';
    }
    if ((int)$a['id_cliente'] === (int)$b['id_cliente']) {
        return 'MESMA_CONTA';
    }
    if ((string)$a['status'] !== 'ativo' || (string)$b['status'] !== 'ativo') {
        return 'CONTA_INATIVA';
    }
    if ($a['id_cliente_unificado'] !== null || $b['id_cliente_unificado'] !== null) {
        return 'CONTA_JA_UNIFICADA';
    }

    return null;
}

/**
 * Localiza, na MESMA empresa, a outra conta ativa (ainda não unificada) que tem o contato informado.
 *
 * @return array<string, mixed>|null
 */
function clienteUnifLocalizarConta(mysqli $conexao, int $idEmpresa, int $idAtual, string $contato, string $valor): ?array
{
    $base = "SELECT * FROM cliente
              WHERE id_empresa = ? AND id_cliente <> ? AND status = 'ativo' AND id_cliente_unificado IS NULL AND ";

    if ($contato === 'telefone') {
        $e164 = smsOtpNormalizarTelefone($valor);
        if ($e164 === null) {
            return null;
        }
        $digitos = ltrim($e164, '+');
        $linhas = smsConsultar(
            $conexao,
            $base . "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp_celular, '+', ''), '(', ''), ')', ''), '-', ''), ' ', '') IN (?, ?) LIMIT 2",
            'iiss',
            $idEmpresa,
            $idAtual,
            $digitos,
            substr($digitos, 2)
        );
    } elseif ($contato === 'email') {
        $email = emailOtpNormalizar($valor);
        if ($email === null) {
            return null;
        }
        $linhas = smsConsultar($conexao, $base . 'LOWER(email) = ? LIMIT 2', 'iis', $idEmpresa, $idAtual, $email);
    } else {
        return null;
    }

    // Mais de um resultado é inconsistência: nunca escolhe por conta própria.
    return count($linhas) === 1 ? $linhas[0] : null;
}

/* ------------------------------------------------------------------ prova na sessão */

/** Guarda a prova (código aprovado) de que o cliente controla um contato da outra conta. */
function clienteUnifGuardarProva(int $idEmpresa, int $idAtual, int $idOutra, string $contato, string $valor): string
{
    $nonce = bin2hex(random_bytes(16));
    $_SESSION[CLIENTE_UNIF_SESSAO_PROVA] = [
        'id_empresa' => $idEmpresa,
        'id_atual' => $idAtual,
        'id_outra' => $idOutra,
        'contato' => $contato,
        'chave' => clienteUnifChaveContato($contato, $valor),
        'nonce' => $nonce,
        'provada_em' => time(),
    ];

    return $nonce;
}

/** @return array<string, mixed>|null prova válida para esta conta/empresa, não expirada */
function clienteUnifLerProva(int $idEmpresa, int $idAtual, ?int $agora = null): ?array
{
    $p = $_SESSION[CLIENTE_UNIF_SESSAO_PROVA] ?? null;
    $agora ??= time();
    if (
        !is_array($p)
        || (int)($p['id_empresa'] ?? 0) !== $idEmpresa
        || (int)($p['id_atual'] ?? 0) !== $idAtual
        || (int)($p['id_outra'] ?? 0) <= 0
        || !in_array((string)($p['contato'] ?? ''), ['telefone', 'email'], true)
        || !is_string($p['nonce'] ?? null)
        || ($agora - (int)($p['provada_em'] ?? 0)) > CLIENTE_UNIF_PROVA_VALIDADE_SEGUNDOS
    ) {
        unset($_SESSION[CLIENTE_UNIF_SESSAO_PROVA]);

        return null;
    }

    return $p;
}

/**
 * Contatos comprovados nesta operação (chaves comparáveis): o da sessão atual (comprovado no login)
 * e o da outra conta (comprovado por código agora).
 *
 * @param array<string, mixed> $auth   $_SESSION['cliente_auth']
 * @param array<string, mixed> $prova  retorno de clienteUnifLerProva()
 * @return array{telefone: list<string>, email: list<string>}
 */
function clienteUnifContatosComprovados(array $auth, array $prova): array
{
    $r = ['telefone' => [], 'email' => []];
    if (($auth['telefone_verificado'] ?? false) === true && ($c = clienteUnifChaveTelefone((string)($auth['telefone'] ?? ''))) !== null) {
        $r['telefone'][] = $c;
    }
    if (($auth['email_verificado'] ?? false) === true && ($c = clienteUnifChaveEmail((string)($auth['email'] ?? ''))) !== null) {
        $r['email'][] = $c;
    }
    $tipo = (string)($prova['contato'] ?? '');
    if (isset($r[$tipo]) && is_string($prova['chave'] ?? null) && $prova['chave'] !== '') {
        $r[$tipo][] = $prova['chave'];
    }

    return $r;
}

/* ------------------------------------------------------------------ contatos */

/**
 * Decide o contato final de um tipo (celular ou e-mail) entre a conta definitiva (P) e a secundária (S).
 * Contato divergente exige escolha explícita ('principal' | 'secundaria'): nada é descartado em silêncio.
 * Uma verificação já existente é preservada; contato comprovado agora vale como verificado; nenhum
 * outro é presumido verificado.
 *
 * @param list<string> $comprovados chaves comprovadas neste fluxo para o tipo
 * @return array{ok: bool, codigo?: string, valor?: ?string, origem?: string, verificado_em?: ?string, descartado?: ?string}
 */
function clienteUnifResolverContato(
    string $tipo,
    ?string $valP,
    ?string $valS,
    ?string $verP,
    ?string $verS,
    string $escolha,
    array $comprovados
): array {
    $valP = ($valP !== null && trim($valP) !== '') ? $valP : null;
    $valS = ($valS !== null && trim($valS) !== '') ? $valS : null;
    $cP = clienteUnifChaveContato($tipo, $valP);
    $cS = clienteUnifChaveContato($tipo, $valS);

    if ($valP === null && $valS === null) {
        return ['ok' => true, 'valor' => null, 'origem' => 'nenhuma', 'verificado_em' => null, 'descartado' => null];
    }

    if ($valP !== null && $valS !== null && $cP !== $cS) {
        if (!in_array($escolha, ['principal', 'secundaria'], true)) {
            return ['ok' => false, 'codigo' => 'ESCOLHA_CONTATO_OBRIGATORIA'];
        }
        $origem = $escolha;
        $descartado = $escolha === 'principal' ? $valS : $valP;
    } else {
        $origem = $valP !== null ? ($valS !== null ? 'ambas' : 'principal') : 'secundaria';
        $descartado = null;
    }

    $valor = $origem === 'secundaria' ? $valS : $valP;
    $ver = $origem === 'secundaria' ? $verS : ($origem === 'ambas' ? ($verP ?? $verS) : $verP);
    $chave = $origem === 'secundaria' ? $cS : $cP;
    $verificado = ($ver !== null && $ver !== '') ? $ver : null;
    if ($verificado === null && $chave !== null && in_array($chave, $comprovados, true)) {
        $verificado = 'AGORA';
    }

    return ['ok' => true, 'valor' => $valor, 'origem' => $origem, 'verificado_em' => $verificado, 'descartado' => $descartado];
}

/* ------------------------------------------------------------------ prévia */

/**
 * Resumo para o cliente conferir antes de confirmar (contatos mascarados; ids nunca expostos).
 * CPF e nome aparecem só como sinais auxiliares.
 *
 * @param array<string, mixed> $a conta atual
 * @param array<string, mixed> $b outra conta
 * @param array{telefone: list<string>, email: list<string>} $comprovados
 * @return array<string, mixed>
 */
function clienteUnifPrevia(mysqli $conexao, array $a, array $b, array $comprovados): array
{
    $resumo = static function (array $c) use ($conexao, $comprovados): array {
        $id = (int)$c['id_cliente'];
        $idEmpresa = (int)$c['id_empresa'];
        $ag = smsConsultar($conexao, 'SELECT COUNT(*) AS n FROM agendamento WHERE id_cliente = ? AND id_empresa = ?', 'ii', $id, $idEmpresa);
        $cv = smsConsultar($conexao, 'SELECT COUNT(*) AS n FROM chat_conversa WHERE id_cliente = ? AND id_empresa = ?', 'ii', $id, $idEmpresa);
        $cT = clienteUnifChaveTelefone((string)($c['whatsapp_celular'] ?? ''));
        $cE = clienteUnifChaveEmail((string)($c['email'] ?? ''));

        return [
            'nome' => (string)($c['nome_completo'] ?? ''),
            'telefone' => clienteUnifMascararTelefone((string)($c['whatsapp_celular'] ?? '')),
            'email' => clienteUnifMascararEmail((string)($c['email'] ?? '')),
            'telefone_validado' => ($c['telefone_verificado_em'] ?? null) !== null || ($cT !== null && in_array($cT, $comprovados['telefone'], true)),
            'email_validado' => ($c['email_verificado_em'] ?? null) !== null || ($cE !== null && in_array($cE, $comprovados['email'], true)),
            'agendamentos' => (int)($ag[0]['n'] ?? 0),
            'conversas' => (int)($cv[0]['n'] ?? 0),
            'origem_cadastro' => $c['origem_cadastro'] ?? null,
            'criada_em' => (string)($c['criado_em'] ?? ''),
        ];
    };

    $cpfA = preg_replace('/\D+/', '', (string)($a['cpf'] ?? '')) ?? '';
    $cpfB = preg_replace('/\D+/', '', (string)($b['cpf'] ?? '')) ?? '';
    $nomeA = mb_strtolower(trim((string)($a['nome_completo'] ?? '')), 'UTF-8');
    $nomeB = mb_strtolower(trim((string)($b['nome_completo'] ?? '')), 'UTF-8');

    $divT = clienteUnifChaveTelefone((string)($a['whatsapp_celular'] ?? '')) !== null
        && clienteUnifChaveTelefone((string)($b['whatsapp_celular'] ?? '')) !== null
        && clienteUnifChaveTelefone((string)$a['whatsapp_celular']) !== clienteUnifChaveTelefone((string)$b['whatsapp_celular']);
    $divE = clienteUnifChaveEmail((string)($a['email'] ?? '')) !== null
        && clienteUnifChaveEmail((string)($b['email'] ?? '')) !== null
        && clienteUnifChaveEmail((string)$a['email']) !== clienteUnifChaveEmail((string)$b['email']);

    return [
        'atual' => $resumo($a),
        'outra' => $resumo($b),
        'sinais' => [
            'cpf_igual' => $cpfA !== '' && $cpfA === $cpfB,
            'nome_igual' => $nomeA !== '' && $nomeA === $nomeB,
        ],
        'divergencias' => ['telefone' => $divT, 'email' => $divE],
    ];
}

/* ------------------------------------------------------------------ execução */

/** Menor/maior de datas texto (NULL ignorado). */
function clienteUnifExtremo(?string $x, ?string $y, bool $maior): ?string
{
    if ($x === null || $x === '') {
        return $y === '' ? null : $y;
    }
    if ($y === null || $y === '') {
        return $x;
    }

    return ($maior ? $x >= $y : $x <= $y) ? $x : $y;
}

/**
 * Executa a unificação em UMA transação. O chamador já exigiu: sessão autenticada recente, prova
 * válida (código aprovado), aceite expresso e CSRF.
 *
 * @param string $principalEscolhido 'atual' | 'outra'  (conta definitiva)
 * @param array{telefone?: string, email?: string} $escolhas 'principal' | 'secundaria' quando divergentes
 * @param array{telefone: list<string>, email: list<string>} $comprovados
 * @param array<string, mixed> $meta ['contato_prova' => 'telefone'|'email', 'ip' => string]
 * @return array<string, mixed> ['ok' => bool, 'codigo' => string, ...]
 */
function clienteUnifExecutar(
    mysqli $conexao,
    int $idEmpresa,
    int $idAtual,
    int $idOutra,
    string $principalEscolhido,
    array $escolhas,
    array $comprovados,
    array $meta
): array {
    if (!in_array($principalEscolhido, ['atual', 'outra'], true)) {
        return ['ok' => false, 'codigo' => 'PRINCIPAL_INVALIDO'];
    }
    if (!clienteUnifTabelaDisponivel($conexao)) {
        return ['ok' => false, 'codigo' => 'RECURSO_INDISPONIVEL'];
    }
    $idPrincipal = $principalEscolhido === 'atual' ? $idAtual : $idOutra;
    $idSecundaria = $principalEscolhido === 'atual' ? $idOutra : $idAtual;

    $conexao->begin_transaction();
    try {
        // Bloqueio das duas linhas em ordem fixa (evita deadlock e operação concorrente).
        $linhas = smsConsultar(
            $conexao,
            'SELECT * FROM cliente WHERE id_empresa = ? AND id_cliente IN (?, ?) ORDER BY id_cliente FOR UPDATE',
            'iii',
            $idEmpresa,
            $idAtual,
            $idOutra
        );
        $porId = [];
        foreach ($linhas as $l) {
            $porId[(int)$l['id_cliente']] = $l;
        }

        $erro = clienteUnifValidarPar($porId[$idAtual] ?? null, $porId[$idOutra] ?? null, $idEmpresa);
        if ($erro !== null) {
            $conexao->rollback();

            return ['ok' => false, 'codigo' => $erro];
        }
        $P = $porId[$idPrincipal];
        $S = $porId[$idSecundaria];

        // O contato comprovado ainda pertence à outra conta (não foi trocado depois da prova).
        $tipoProva = (string)($meta['contato_prova'] ?? '');
        $outra = $porId[$idOutra];
        $campoProva = $tipoProva === 'telefone' ? 'whatsapp_celular' : 'email';
        $chaveProva = clienteUnifChaveContato($tipoProva, (string)($outra[$campoProva] ?? ''));
        if ($chaveProva === null || !in_array($chaveProva, $comprovados[$tipoProva] ?? [], true)) {
            $conexao->rollback();

            return ['ok' => false, 'codigo' => 'PROVA_INVALIDA'];
        }

        $tel = clienteUnifResolverContato(
            'telefone', $P['whatsapp_celular'], $S['whatsapp_celular'], $P['telefone_verificado_em'], $S['telefone_verificado_em'],
            (string)($escolhas['telefone'] ?? ''), $comprovados['telefone']
        );
        $mail = clienteUnifResolverContato(
            'email', $P['email'], $S['email'], $P['email_verificado_em'], $S['email_verificado_em'],
            (string)($escolhas['email'] ?? ''), $comprovados['email']
        );
        if (!$tel['ok'] || !$mail['ok']) {
            $conexao->rollback();

            return ['ok' => false, 'codigo' => 'ESCOLHA_CONTATO_OBRIGATORIA'];
        }
        // A conta definitiva precisa manter ao menos um contato comprovado nesta operação (a sessão
        // continua válida e a conta nunca fica sem forma de acesso verificada).
        $temComprovado = ($tel['valor'] !== null && in_array(clienteUnifChaveTelefone($tel['valor']), $comprovados['telefone'], true))
            || ($mail['valor'] !== null && in_array(clienteUnifChaveEmail($mail['valor']), $comprovados['email'], true));
        if (!$temComprovado) {
            $conexao->rollback();

            return ['ok' => false, 'codigo' => 'SEM_CONTATO_COMPROVADO'];
        }

        // Trilha de recuperação (garante a idempotência: UNIQUE por empresa + conta secundária).
        $agendamentos = smsConsultar(
            $conexao,
            'SELECT id_agendamento FROM agendamento WHERE id_cliente = ? AND id_empresa = ? ORDER BY id_agendamento FOR UPDATE',
            'ii',
            $idSecundaria,
            $idEmpresa
        );
        $idsAgendamento = array_map(static fn(array $l): int => (int)$l['id_agendamento'], $agendamentos);
        $notificacoes = smsConsultar(
            $conexao,
            "SELECT id_notificacao, codigo FROM notificacao
              WHERE id_empresa = ? AND destinatario_tipo = 'cliente' AND destinatario_id = ?",
            'ii',
            $idEmpresa,
            $idSecundaria
        );
        $secundariasRepontadas = array_map(
            static fn(array $l): int => (int)$l['id_cliente'],
            smsConsultar($conexao, 'SELECT id_cliente FROM cliente WHERE id_empresa = ? AND id_cliente_unificado = ? FOR UPDATE', 'ii', $idEmpresa, $idSecundaria)
        );

        $camposPerfil = ['nome_completo', 'cpf', 'data_nascimento', 'cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf', 'complemento', 'foto_perfil'];
        $perfilAntes = [];
        $perfilNovo = [];
        foreach ($camposPerfil as $campo) {
            $perfilAntes[$campo] = ['principal' => $P[$campo], 'secundaria' => $S[$campo]];
            if (($P[$campo] === null || trim((string)$P[$campo]) === '') && $S[$campo] !== null && trim((string)$S[$campo]) !== '') {
                $perfilNovo[$campo] = $S[$campo];
            }
        }

        $recuperacao = [
            'contatos_principal' => ['telefone' => $P['whatsapp_celular'], 'email' => $P['email'],
                'telefone_verificado_em' => $P['telefone_verificado_em'], 'email_verificado_em' => $P['email_verificado_em']],
            'contatos_secundaria' => ['telefone' => $S['whatsapp_celular'], 'email' => $S['email'],
                'telefone_verificado_em' => $S['telefone_verificado_em'], 'email_verificado_em' => $S['email_verificado_em'],
                'status' => $S['status'], 'origem_cadastro' => $S['origem_cadastro']],
            'contatos_finais' => [
                'telefone' => ['valor' => $tel['valor'], 'origem' => $tel['origem'], 'descartado' => $tel['descartado']],
                'email' => ['valor' => $mail['valor'], 'origem' => $mail['origem'], 'descartado' => $mail['descartado']],
            ],
            'perfil' => $perfilAntes,
            'perfil_incorporado' => array_keys($perfilNovo),
            'contadores_principal' => [
                'total_agendamentos' => $P['total_agendamentos'], 'ultimo_agendamento_em' => $P['ultimo_agendamento_em'],
                'ultimo_atendimento_em' => $P['ultimo_atendimento_em'], 'ultima_movimentacao_em' => $P['ultima_movimentacao_em'],
            ],
            'contadores_secundaria' => [
                'total_agendamentos' => $S['total_agendamentos'], 'ultimo_agendamento_em' => $S['ultimo_agendamento_em'],
                'ultimo_atendimento_em' => $S['ultimo_atendimento_em'], 'ultima_movimentacao_em' => $S['ultima_movimentacao_em'],
            ],
        ];
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $contatoProva = in_array($tipoProva, ['telefone', 'email'], true) ? $tipoProva : 'telefone';

        smsExecutar(
            $conexao,
            'INSERT INTO cliente_unificacao
                (id_empresa, id_cliente_principal, id_cliente_secundario, contato_prova, ip, escolhas, agendamentos_ids,
                 notificacoes_ids, secundarias_repontadas, recuperacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiisssssss',
            $idEmpresa,
            $idPrincipal,
            $idSecundaria,
            $contatoProva,
            substr((string)($meta['ip'] ?? ''), 0, 45),
            json_encode(['principal' => $principalEscolhido, 'telefone' => $escolhas['telefone'] ?? null, 'email' => $escolhas['email'] ?? null], $flags),
            json_encode($idsAgendamento, $flags),
            json_encode(array_map(static fn(array $l): array => [(int)$l['id_notificacao'], (string)$l['codigo']], $notificacoes), $flags),
            json_encode($secundariasRepontadas, $flags),
            json_encode($recuperacao, $flags)
        );

        // 1) Agendamentos: TODOS, qualquer status.
        $transferidos = smsExecutar(
            $conexao,
            'UPDATE agendamento SET id_cliente = ? WHERE id_cliente = ? AND id_empresa = ?',
            'iii',
            $idPrincipal,
            $idSecundaria,
            $idEmpresa
        );

        // 2) Notificações: as de chat da secundária são canceladas (o chat do grupo é reprojetado na
        //    principal); as demais passam para a principal quando a chave de deduplicação permite.
        smsExecutar(
            $conexao,
            "UPDATE notificacao SET cancelada_em = NOW()
              WHERE id_empresa = ? AND destinatario_tipo = 'cliente' AND destinatario_id = ?
                AND codigo = 'chat.conversa_nao_lida' AND cancelada_em IS NULL AND concluida_em IS NULL",
            'ii',
            $idEmpresa,
            $idSecundaria
        );
        smsExecutar(
            $conexao,
            "UPDATE IGNORE notificacao SET destinatario_id = ?
              WHERE id_empresa = ? AND destinatario_tipo = 'cliente' AND destinatario_id = ?
                AND codigo <> 'chat.conversa_nao_lida'",
            'iii',
            $idPrincipal,
            $idEmpresa,
            $idSecundaria
        );

        // 3) Códigos pendentes da secundária deixam de valer.
        smsExecutar(
            $conexao,
            "UPDATE otp_desafio SET status = 'invalidado', invalidado_em = NOW(6)
              WHERE id_empresa = ? AND id_cliente = ? AND status IN ('pendente_envio', 'ativo', 'validado')",
            'ii',
            $idEmpresa,
            $idSecundaria
        );

        // 4) Contas já unificadas na secundária passam a apontar para a definitiva (grupo de um nível).
        smsExecutar(
            $conexao,
            'UPDATE cliente SET id_cliente_unificado = ? WHERE id_empresa = ? AND id_cliente_unificado = ?',
            'iii',
            $idPrincipal,
            $idEmpresa,
            $idSecundaria
        );

        // 5) Secundária: libera os contatos (índices únicos), perde a credencial (revoga sessões),
        //    fica inativa e aponta para a definitiva. O CHECK permite sem contato por estar unificada.
        $inativada = smsExecutar(
            $conexao,
            "UPDATE cliente
                SET whatsapp_celular = NULL, email = NULL, telefone_verificado_em = NULL, email_verificado_em = NULL,
                    senha_hash = NULL, status = 'inativo', id_cliente_unificado = ?, unificado_em = NOW()
              WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo' AND id_cliente_unificado IS NULL",
            'iii',
            $idPrincipal,
            $idSecundaria,
            $idEmpresa
        );
        if ($inativada !== 1) {
            throw new RuntimeException('Conta secundária não pôde ser unificada.');
        }

        // 6) Definitiva: contatos finais em duas etapas (a regra do banco invalida a verificação
        //    quando o contato muda) e depois as verificações calculadas.
        smsExecutar(
            $conexao,
            'UPDATE cliente SET whatsapp_celular = ?, email = ? WHERE id_cliente = ? AND id_empresa = ?',
            'ssii',
            $tel['valor'],
            $mail['valor'],
            $idPrincipal,
            $idEmpresa
        );
        $agora = date('Y-m-d H:i:s');
        $verTel = $tel['valor'] === null ? null : ($tel['verificado_em'] === 'AGORA' ? $agora : $tel['verificado_em']);
        $verMail = $mail['valor'] === null ? null : ($mail['verificado_em'] === 'AGORA' ? $agora : $mail['verificado_em']);

        $sets = ['telefone_verificado_em = ?', 'email_verificado_em = ?', 'total_agendamentos = ?', 'ultimo_agendamento_em = ?',
            'ultimo_atendimento_em = ?', 'ultima_movimentacao_em = ?', 'ultimo_login_em = ?'];
        $tipos = 'ssissss';
        $vals = [
            $verTel, $verMail,
            (int)$P['total_agendamentos'] + (int)$S['total_agendamentos'],
            clienteUnifExtremo($P['ultimo_agendamento_em'], $S['ultimo_agendamento_em'], true),
            clienteUnifExtremo($P['ultimo_atendimento_em'], $S['ultimo_atendimento_em'], true),
            $agora,
            clienteUnifExtremo($P['ultimo_login_em'], $S['ultimo_login_em'], true),
        ];
        foreach ($perfilNovo as $campo => $valor) {
            $sets[] = $campo . ' = ?';
            $tipos .= 's';
            $vals[] = $valor;
        }
        $tipos .= 'ii';
        $vals[] = $idPrincipal;
        $vals[] = $idEmpresa;
        smsExecutar(
            $conexao,
            'UPDATE cliente SET ' . implode(', ', $sets) . ' WHERE id_cliente = ? AND id_empresa = ?',
            $tipos,
            ...$vals
        );

        // 7) Auditoria (sem dados pessoais: o detalhe fica em cliente_unificacao).
        $atorNome = trim((string)($porId[$idAtual]['nome_completo'] ?? '')) ?: 'Cliente';
        auditoriaRegistrar($conexao, 'cliente.unificado', [
            'ator' => [
                'ator_tipo' => 'cliente', 'id_ator' => $idAtual, 'ator_nome' => $atorNome, 'ator_perfil' => 'cliente',
                'id_empresa' => $idEmpresa, 'modo_suporte' => false, 'origem' => 'empresa',
            ],
            'entidade_id' => $idSecundaria,
            'entidade_rotulo' => $atorNome,
            'descricao' => 'O cliente unificou duas contas.',
            'alteracoes' => [
                'id_cliente_unificado' => ['antes' => null, 'depois' => $idPrincipal],
                'status' => ['antes' => 'ativo', 'depois' => 'inativo'],
                'agendamentos_transferidos' => ['antes' => 0, 'depois' => $transferidos],
                'contatos_descartados' => ['antes' => 0, 'depois' => (int)($tel['descartado'] !== null) + (int)($mail['descartado'] !== null)],
            ],
            'contexto' => ['origem' => 'perfil_cliente', 'motivo' => 'unificacao_contas'],
        ]);

        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        if ((int)$e->getCode() === 1062) {
            // Já unificada (UNIQUE) ou contato em conflito: nada foi gravado.
            return ['ok' => false, 'codigo' => 'CONFLITO'];
        }
        throw $e;
    }

    return [
        'ok' => true,
        'codigo' => 'UNIFICADA',
        'id_principal' => $idPrincipal,
        'id_secundaria' => $idSecundaria,
        'agendamentos' => $transferidos,
        'telefone' => $tel['valor'],
        'email' => $mail['valor'],
        'telefone_verificado' => $verTel !== null,
        'email_verificado' => $verMail !== null,
    ];
}

/**
 * Passa a sessão para a conta definitiva depois do commit. Só mantém como "comprovado neste login"
 * o contato que de fato foi comprovado; a credencial da sessão acompanha a senha da definitiva.
 *
 * @param array<string, mixed> $principal linha atual da conta definitiva
 * @param array{telefone: list<string>, email: list<string>} $comprovados
 */
function clienteUnifAtualizarSessao(array $principal, array $comprovados): void
{
    $auth = is_array($_SESSION['cliente_auth'] ?? null) ? $_SESSION['cliente_auth'] : [];

    $tel = smsOtpNormalizarTelefone((string)($principal['whatsapp_celular'] ?? ''));
    $telOk = $tel !== null && in_array(clienteUnifChaveTelefone($tel), $comprovados['telefone'], true);
    $email = clienteUnifChaveEmail((string)($principal['email'] ?? ''));
    $emailOk = $email !== null && in_array($email, $comprovados['email'], true);

    session_regenerate_id(true);
    $_SESSION['cliente_auth'] = array_merge($auth, [
        'id_cliente' => (int)$principal['id_cliente'],
        'telefone' => $telOk ? $tel : '',
        'email' => $emailOk ? $email : '',
        'identificador' => $telOk ? $tel : 'email:' . $email,
        'nome_completo' => (string)($principal['nome_completo'] ?? ''),
        'telefone_verificado' => $telOk,
        'email_verificado' => $emailOk,
        'cadastro_completo' => (int)($principal['cadastro_completo'] ?? 0) === 1,
        'login_em' => date('Y-m-d H:i:s'),
    ]);
    if (defined('SESSAO_CREDENCIAL_CLIENTE')) {
        $_SESSION[SESSAO_CREDENCIAL_CLIENTE] = sessaoCredencialImpressao(
            ($principal['senha_hash'] ?? null) !== null ? (string)$principal['senha_hash'] : null
        );
    }
    unset($_SESSION[CLIENTE_UNIF_SESSAO_PROVA], $_SESSION['cliente_contato_desafio']);
}
