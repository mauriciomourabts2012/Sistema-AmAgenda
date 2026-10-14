<?php
declare(strict_types=1);

/**
 * CHAT — serviço do atendimento interno (Proprietário, Profissional e Recepção).
 *
 * Regras centrais:
 * - id_empresa, id_usuario, perfil e id_profissional vêm somente da sessão validada;
 * - Super Admin nunca acessa o Chat (normal ou em modo suporte);
 * - Profissional fica sempre restrito ao próprio id_profissional, mesmo que exista
 *   exceção individual liberando chat.visualizar_todos_profissionais;
 * - toda busca de conversa já inclui o escopo no mesmo WHERE (sem revelar existência);
 * - conteúdo é texto puro; quem exibe deve tratá-lo como texto (nunca HTML).
 *
 * A auditoria registra somente criação, encerramento e desvínculo estrutural da conversa.
 * Mensagens, leituras, polling e contadores não são auditados.
 */

require_once __DIR__ . '/../_regras/permissoes_usuario.php';
require_once __DIR__ . '/auditoria.php';

const CHAT_CONTEUDO_MAX = 2000;
const CHAT_LIMITE_PADRAO = 30;
const CHAT_LIMITE_MAX = 50;
const CHAT_PAGINA_MAX = 1000;
const CHAT_PERFIS_INTERNOS = ['proprietario', 'profissional', 'recepcionista'];
const CHAT_STATUS_AGENDAMENTO_INICIO = ['pendente', 'confirmado'];

/** Segunda barreira: usa somente a sessão, antes de qualquer consulta ao banco. */
function chatSessaoEhSuperAdmin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $tipoAuth = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
    $tipoLegado = mb_strtolower(trim((string)($_SESSION['usuario_tipo'] ?? '')), 'UTF-8');
    return $tipoAuth === 'super_admin'
        || $tipoLegado === 'super_admin'
        || (bool)($auth['modo_suporte'] ?? false)
        || (bool)($_SESSION['modo_suporte'] ?? false);
}

/**
 * Contexto autorizado do usuário interno. Retorna null quando o contexto não é válido
 * para o Chat (sem vínculo ativo, perfil fora dos três internos ou Profissional sem cadastro).
 * id_profissional_escopo: null = toda a empresa; > 0 = somente aquele profissional;
 * 0 = nenhuma conversa (sem escopo de empresa e sem profissional próprio).
 */
function chatContextoInterno(mysqli $conexao): ?array
{
    if (chatSessaoEhSuperAdmin()) return null;

    $ctx = permissoesContexto($conexao);
    if (!($ctx['valido'] ?? false) || ($ctx['super_admin_suporte'] ?? false)) return null;

    $idUsuario = (int)($ctx['id_usuario'] ?? 0);
    $idEmpresa = (int)($ctx['id_empresa'] ?? 0);
    $perfil = (string)($ctx['perfil'] ?? '');
    $idProfissional = (int)($ctx['id_profissional'] ?? 0);
    if ($idUsuario <= 0 || $idEmpresa <= 0 || !in_array($perfil, CHAT_PERFIS_INTERNOS, true)) return null;
    if ($perfil === 'profissional' && $idProfissional <= 0) return null;

    $pode = static fn(string $codigo): bool => usuarioTemPermissao($conexao, $codigo);
    $visualizar = $pode('chat.visualizar');

    // Profissional nunca ganha escopo de empresa, mesmo com exceção individual "Permitir".
    $todos = $perfil !== 'profissional' && $visualizar && $pode('chat.visualizar_todos_profissionais');
    $escopoProfissional = $todos ? null : max(0, $idProfissional);

    return [
        'id_usuario' => $idUsuario,
        'id_empresa' => $idEmpresa,
        'perfil' => $perfil,
        'id_profissional' => $idProfissional,
        'id_profissional_escopo' => $escopoProfissional,
        'pode_visualizar' => $visualizar,
        'pode_historico' => $visualizar && $pode('chat.visualizar_historico'),
        'pode_responder' => $visualizar && $pode('chat.responder'),
        'pode_encerrar' => $visualizar && $pode('chat.encerrar'),
    ];
}

/** Inteiro positivo estrito (texto só com dígitos, sem zero à esquerda). */
function chatInteiroPositivo(mixed $valor): ?int
{
    if (is_int($valor)) return $valor > 0 ? $valor : null;
    if (!is_string($valor)) return null;
    $valor = trim($valor);
    if (preg_match('/^[1-9]\d{0,17}$/', $valor) !== 1) return null;
    return (int)$valor;
}

/** Limite de paginação dentro de 1..CHAT_LIMITE_MAX. */
function chatLimite(mixed $valor): int
{
    if ($valor === null || $valor === '') return CHAT_LIMITE_PADRAO;
    $n = chatInteiroPositivo($valor);
    return $n === null ? CHAT_LIMITE_PADRAO : min($n, CHAT_LIMITE_MAX);
}

/** Conteúdo validado como texto; null quando inválido. */
function chatNormalizarConteudo(mixed $valor): ?string
{
    if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) return null;
    $texto = trim(str_replace(["\r\n", "\r"], "\n", $valor));
    if ($texto === '' || mb_strlen($texto, 'UTF-8') > CHAT_CONTEUDO_MAX) return null;
    // Somente texto: caracteres de controle (exceto quebra de linha e tabulação) são recusados.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $texto) === 1) return null;
    return $texto;
}

/** UUID (formato canônico 8-4-4-4-12), normalizado em minúsculas; null quando ausente/inválido. */
function chatNormalizarChaveIdempotencia(mixed $valor): ?string
{
    if (!is_string($valor)) return null;
    $valor = strtolower(trim($valor));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $valor) === 1 ? $valor : null;
}

/** Executa uma escrita devolvendo [sucesso, errno], independente do modo de relatório do mysqli. */
function chatExecutarEscrita(mysqli_stmt $stmt): array
{
    try {
        $ok = $stmt->execute();
        return [$ok, $ok ? 0 : (int)$stmt->errno];
    } catch (mysqli_sql_exception $e) {
        return [false, (int)$e->getCode()];
    }
}

function chatPreparar(mysqli $conexao, string $sql): mysqli_stmt
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar consulta do chat.');
    return $stmt;
}

/** Executa SELECT preparado e devolve todas as linhas. */
function chatConsultar(mysqli $conexao, string $sql, string $tipos = '', array $params = []): array
{
    $stmt = chatPreparar($conexao, $sql);
    if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta do chat.');
    }
    $res = $stmt->get_result();
    $linhas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $linhas;
}

/** Ator do cliente já autenticado e revalidado pelo handler; nenhum dado vem do frontend. */
function chatAtorAuditoriaCliente(array $ctx): array
{
    $idEmpresa = (int)($ctx['id_empresa'] ?? 0);
    $idCliente = (int)($ctx['id_cliente'] ?? 0);
    $nomeCliente = trim((string)($ctx['nome_cliente'] ?? ''));
    if ($idEmpresa <= 0 || $idCliente <= 0 || $nomeCliente === '') {
        throw new InvalidArgumentException('Contexto do cliente inválido para auditoria do Chat.');
    }

    return [
        'ator_tipo' => 'cliente',
        'id_ator' => $idCliente,
        'ator_nome' => $nomeCliente,
        'ator_perfil' => 'cliente',
        'id_empresa' => $idEmpresa,
        'modo_suporte' => false,
        'origem' => 'empresa',
    ];
}

function chatAuditarConversaCriada(
    mysqli $conexao,
    int $idConversa,
    int $idAgendamento,
    string $origem,
    ?array $ator = null
): void {
    $dados = [
        'entidade_id' => $idConversa,
        'alteracoes' => [
            'id_agendamento' => ['antes' => null, 'depois' => $idAgendamento],
            'status' => ['antes' => null, 'depois' => 'aberta'],
        ],
        'contexto' => ['origem' => $origem],
    ];
    if ($ator !== null) $dados['ator'] = $ator;
    auditoriaRegistrar($conexao, 'chat.conversa_criada', $dados);
}

function chatAuditarConversaEncerrada(mysqli $conexao, int $idConversa, ?int $idAgendamento, string $motivo): void
{
    $contexto = [
        'origem' => 'chat_interno',
        'motivo' => $motivo,
        'status_anterior' => 'aberta',
        'status_novo' => 'encerrada',
    ];
    if ($idAgendamento !== null && $idAgendamento > 0) $contexto['id_agendamento'] = $idAgendamento;

    auditoriaRegistrar($conexao, 'chat.conversa_encerrada', [
        'entidade_id' => $idConversa,
        'alteracoes' => ['status' => ['antes' => 'aberta', 'depois' => 'encerrada']],
        'contexto' => $contexto,
    ]);
}

function chatAuditarConversaDesvinculada(
    mysqli $conexao,
    int $idConversa,
    int $idAgendamento,
    string $statusAnterior,
    string $motivo
): void {
    $alteracoes = ['id_agendamento' => ['antes' => $idAgendamento, 'depois' => null]];
    $contexto = ['origem' => 'agenda', 'motivo' => $motivo];
    if ($statusAnterior === 'aberta') {
        $alteracoes['status'] = ['antes' => 'aberta', 'depois' => 'encerrada'];
        $contexto['status_anterior'] = 'aberta';
        $contexto['status_novo'] = 'encerrada';
    }

    auditoriaRegistrar($conexao, 'chat.conversa_desvinculada', [
        'entidade_id' => $idConversa,
        'alteracoes' => $alteracoes,
        'contexto' => $contexto,
    ]);
}

/**
 * URL pública da foto de perfil somente quando o caminho gravado segue o padrão dos
 * uploads atuais (/public/imagens/usuarios|clientes/arquivo) e o arquivo existe.
 * Caso contrário devolve null e a interface usa o avatar de iniciais.
 */
function chatFotoUrl(mixed $caminho, string $pasta): ?string
{
    static $existe = [];
    if (!is_string($caminho) || !in_array($pasta, ['usuarios', 'clientes'], true)) return null;
    $caminho = trim($caminho);
    if (preg_match('#^/public/imagens/' . $pasta . '/[A-Za-z0-9][A-Za-z0-9._-]{0,180}$#', $caminho) !== 1) return null;
    if (!array_key_exists($caminho, $existe)) {
        $existe[$caminho] = is_file(dirname(__DIR__, 2) . $caminho);
    }
    return $existe[$caminho] ? $caminho : null;
}

/**
 * Anexa remetente_foto_url às mensagens JÁ autorizadas. Os remetentes são sempre
 * participantes da conversa (cliente da conversa ou usuário que enviou nela).
 */
function chatAnexarFotosMensagens(mysqli $conexao, array $linhas): array
{
    $usuarios = [];
    $clientes = [];
    foreach ($linhas as $linha) {
        if ((string)($linha['remetente_tipo'] ?? '') === 'usuario' && (int)($linha['id_usuario_remetente'] ?? 0) > 0) {
            $usuarios[(int)$linha['id_usuario_remetente']] = true;
        } elseif ((string)($linha['remetente_tipo'] ?? '') === 'cliente' && (int)($linha['id_cliente_remetente'] ?? 0) > 0) {
            $clientes[(int)$linha['id_cliente_remetente']] = true;
        }
    }

    $fotos = ['usuario' => [], 'cliente' => []];
    foreach ([['usuario', 'usuario', 'id_usuario', array_keys($usuarios)], ['cliente', 'cliente', 'id_cliente', array_keys($clientes)]] as [$tipo, $tabela, $coluna, $ids]) {
        if ($ids === []) continue;
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $resultado = chatConsultar(
            $conexao,
            "SELECT {$coluna} AS id, foto_perfil FROM {$tabela} WHERE {$coluna} IN ({$marcadores})",
            str_repeat('i', count($ids)),
            $ids
        );
        foreach ($resultado as $foto) {
            $fotos[$tipo][(int)$foto['id']] = chatFotoUrl($foto['foto_perfil'] ?? null, $tipo === 'usuario' ? 'usuarios' : 'clientes');
        }
    }

    foreach ($linhas as &$linha) {
        $tipo = (string)($linha['remetente_tipo'] ?? '');
        $id = $tipo === 'usuario' ? (int)($linha['id_usuario_remetente'] ?? 0) : (int)($linha['id_cliente_remetente'] ?? 0);
        $linha['remetente_foto_url'] = $fotos[$tipo][$id] ?? null;
    }
    unset($linha);
    return $linhas;
}

/** Trecho de escopo obrigatório: empresa da sessão e, quando aplicável, o profissional. */
function chatEscopoSql(array $ctx, string $alias = 'c'): array
{
    $sql = "{$alias}.id_empresa = ?";
    $tipos = 'i';
    $params = [(int)$ctx['id_empresa']];
    if ($ctx['id_profissional_escopo'] !== null) {
        $sql .= " AND {$alias}.id_profissional = ?";
        $tipos .= 'i';
        $params[] = (int)$ctx['id_profissional_escopo'];
    }
    return [$sql, $tipos, $params];
}

/**
 * Conversa autorizada pelo escopo do usuário, com nomes para exibição.
 * Retorna null para inexistente ou fora do escopo (mesma resposta para ambos).
 */
function chatBuscarConversaAutorizada(mysqli $conexao, array $ctx, int $idConversa, bool $paraAtualizar = false): ?array
{
    [$escopo, $tipos, $params] = chatEscopoSql($ctx);
    if ($paraAtualizar) {
        // Trava somente a linha da conversa (sem JOIN) para serializar envio/encerramento.
        $linhas = chatConsultar(
            $conexao,
            "SELECT c.* FROM chat_conversa c WHERE c.id_conversa = ? AND {$escopo} LIMIT 1 FOR UPDATE",
            'i' . $tipos,
            array_merge([$idConversa], $params)
        );
        return $linhas[0] ?? null;
    }

    $idUsuario = (int)$ctx['id_usuario'];
    $quantidade = chatSqlQuantidadeNaoLidasUsuario();
    $linhas = chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_profissional, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                c.status, c.criado_por_tipo, c.criado_em, c.id_ultima_mensagem, c.ultima_mensagem_em, c.encerrada_em,
                cl.nome_completo AS cliente_nome, up.nome AS profissional_nome, ue.nome AS encerrada_por_nome,
                cl.foto_perfil AS cliente_foto, up.foto_perfil AS profissional_foto,
                l.id_ultima_mensagem_lida, {$quantidade} AS quantidade_nao_lidas
           FROM chat_conversa c
           LEFT JOIN cliente cl ON cl.id_cliente = c.id_cliente AND cl.id_empresa = c.id_empresa
           LEFT JOIN profissional p ON p.id_profissional = c.id_profissional
           LEFT JOIN usuario up ON up.id_usuario = p.id_usuario
           LEFT JOIN usuario ue ON ue.id_usuario = c.encerrada_por_usuario
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
          WHERE c.id_conversa = ? AND {$escopo}
          LIMIT 1",
        'iii' . $tipos,
        array_merge([$idUsuario, $idUsuario, $idConversa], $params)
    );
    return $linhas[0] ?? null;
}

/**
 * Subconsulta correlacionada (aliases c = chat_conversa, l = chat_leitura do leitor):
 * mensagens após o marcador de leitura que não foram enviadas pelo próprio usuário.
 * Consome um parâmetro: id_usuario do leitor.
 */
// Mensagens do sistema nunca contam como não lidas para usuários internos (badge, lista,
// contadores e notificação interna); para o cliente elas contam normalmente.
function chatSqlQuantidadeNaoLidasUsuario(): string
{
    return "(SELECT COUNT(*) FROM chat_mensagem mn
              WHERE mn.id_conversa = c.id_conversa
                AND mn.id_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
                AND mn.remetente_tipo <> 'sistema' AND (mn.remetente_tipo <> 'usuario' OR mn.id_usuario_remetente <> ?))";
}

/** Mesma regra para o leitor cliente. Consome um parâmetro: id_cliente do leitor. */
function chatSqlQuantidadeNaoLidasCliente(): string
{
    return "(SELECT COUNT(*) FROM chat_mensagem mn
              WHERE mn.id_conversa = c.id_conversa
                AND mn.id_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
                AND (mn.remetente_tipo <> 'cliente' OR mn.id_cliente_remetente <> ?))";
}

/** Formato público de uma conversa (sem ids internos de cliente/usuário). */
function chatFormatarConversa(array $c, array $ctx): array
{
    $ultima = $c['id_ultima_mensagem'] === null ? null : (int)$c['id_ultima_mensagem'];
    $lida = $c['id_ultima_mensagem_lida'] === null ? 0 : (int)$c['id_ultima_mensagem_lida'];
    $aberta = (string)$c['status'] === 'aberta';
    $item = [
        'id_conversa' => (int)$c['id_conversa'],
        'status' => (string)$c['status'],
        'cliente_nome' => (string)($c['cliente_nome'] ?? ''),
        'id_profissional' => (int)$c['id_profissional'],
        'profissional_nome' => (string)($c['profissional_nome'] ?? ''),
        'id_agendamento' => $c['id_agendamento'] === null ? null : (int)$c['id_agendamento'],
        'agendamento_data' => (string)$c['agendamento_data'],
        'agendamento_hora_inicio' => (string)$c['agendamento_hora_inicio'],
        'ultima_mensagem_em' => (string)$c['ultima_mensagem_em'],
        'id_ultima_mensagem' => $ultima,
        'id_ultima_mensagem_lida' => $lida > 0 ? $lida : null,
        'nao_lida' => $ultima !== null && $ultima > $lida,
        'encerrada_em' => $c['encerrada_em'] === null ? null : (string)$c['encerrada_em'],
    ];
    if (array_key_exists('quantidade_nao_lidas', $c)) {
        $item['quantidade_nao_lidas'] = max(0, (int)$c['quantidade_nao_lidas']);
        $item['nao_lida'] = $item['quantidade_nao_lidas'] > 0;
    }
    if (array_key_exists('cliente_foto', $c)) {
        $item['cliente_foto_url'] = chatFotoUrl($c['cliente_foto'], 'clientes');
        $item['profissional_foto_url'] = chatFotoUrl($c['profissional_foto'] ?? null, 'usuarios');
    }
    if (array_key_exists('ultima_remetente_tipo', $c)) {
        $item['remetente_ultima_mensagem'] = $c['ultima_remetente_tipo'] === null ? null : [
            'tipo' => (string)$c['ultima_remetente_tipo'],
            'nome' => (string)($c['ultima_remetente_nome'] ?? ''),
            'propria' => (string)$c['ultima_remetente_tipo'] === 'usuario'
                && (int)($c['ultima_id_usuario_remetente'] ?? 0) === (int)$ctx['id_usuario'],
        ];
    }
    if (array_key_exists('encerrada_por_nome', $c)) {
        $item['encerrada_por_nome'] = $c['encerrada_por_nome'] === null ? null : (string)$c['encerrada_por_nome'];
        $item['criado_em'] = (string)$c['criado_em'];
        // Apenas orientação para a interface; o backend sempre revalida.
        $item['pode_responder'] = $aberta && $ctx['pode_responder'];
        $item['pode_encerrar'] = $aberta && $ctx['pode_encerrar'];
    }
    if (array_key_exists('ultima_mensagem_previa', $c)) {
        $item['ultima_mensagem_previa'] = $c['ultima_mensagem_previa'] === null ? null : (string)$c['ultima_mensagem_previa'];
    }
    return $item;
}

/** Lista conversas do escopo com filtros e paginação por página. */
function chatListarConversas(mysqli $conexao, array $ctx, string $status, ?int $filtroProfissional, bool $somenteNaoLidas, int $pagina, int $limite): array
{
    [$escopo, $tipos, $params] = chatEscopoSql($ctx);
    $where = "{$escopo} AND c.status = ?";
    $tipos .= 's';
    $params[] = $status;

    // O filtro só restringe dentro da empresa; nunca amplia o escopo do usuário.
    if ($filtroProfissional !== null && $ctx['id_profissional_escopo'] === null) {
        $where .= ' AND c.id_profissional = ?';
        $tipos .= 'i';
        $params[] = $filtroProfissional;
    }
    if ($somenteNaoLidas) {
        $where .= ' AND c.id_ultima_mensagem IS NOT NULL AND c.id_ultima_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0) AND '
            . chatSqlQuantidadeNaoLidasUsuario() . ' > 0';
        $tipos .= 'i';
        $params[] = (int)$ctx['id_usuario'];
    }

    $offset = ($pagina - 1) * $limite;
    $quantidade = chatSqlQuantidadeNaoLidasUsuario();
    $linhas = chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_profissional, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                c.status, c.id_ultima_mensagem, c.ultima_mensagem_em, c.encerrada_em,
                cl.nome_completo AS cliente_nome, up.nome AS profissional_nome,
                cl.foto_perfil AS cliente_foto, up.foto_perfil AS profissional_foto,
                l.id_ultima_mensagem_lida, LEFT(um.conteudo, 140) AS ultima_mensagem_previa,
                um.remetente_tipo AS ultima_remetente_tipo, um.remetente_nome AS ultima_remetente_nome,
                um.id_usuario_remetente AS ultima_id_usuario_remetente,
                {$quantidade} AS quantidade_nao_lidas
           FROM chat_conversa c
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
           LEFT JOIN cliente cl ON cl.id_cliente = c.id_cliente AND cl.id_empresa = c.id_empresa
           LEFT JOIN profissional p ON p.id_profissional = c.id_profissional
           LEFT JOIN usuario up ON up.id_usuario = p.id_usuario
           LEFT JOIN chat_mensagem um ON um.id_mensagem = c.id_ultima_mensagem AND um.id_conversa = c.id_conversa
          WHERE {$where}
          ORDER BY c.ultima_mensagem_em DESC, c.id_conversa DESC
          LIMIT ? OFFSET ?",
        'ii' . $tipos . 'ii',
        array_merge([(int)$ctx['id_usuario'], (int)$ctx['id_usuario']], $params, [$limite + 1, $offset])
    );

    $temMais = count($linhas) > $limite;
    if ($temMais) array_pop($linhas);
    return [
        'itens' => array_map(static fn(array $c): array => chatFormatarConversa($c, $ctx), $linhas),
        'pagina' => $pagina,
        'limite' => $limite,
        'tem_mais' => $temMais,
    ];
}

const CHAT_NAO_LIDAS_DETALHE_MAX = 200;

/**
 * Contadores do leitor interno sem carregar mensagens. Uma conversa é "não lida" quando
 * existe mensagem de OUTRA pessoa após o marcador de leitura do próprio usuário.
 * Mantém conversas_nao_lidas/conversas_abertas (contrato anterior) e acrescenta o detalhe.
 */
function chatContarNaoLidas(mysqli $conexao, array $ctx): array
{
    [$escopo, $tipos, $params] = chatEscopoSql($ctx);
    $status = $ctx['pode_historico'] ? "c.status IN ('aberta','encerrada')" : "c.status = 'aberta'";
    $idUsuario = (int)$ctx['id_usuario'];
    $linhas = chatConsultar(
        $conexao,
        "SELECT
             COUNT(CASE WHEN c.id_ultima_mensagem IS NOT NULL
                         AND c.id_ultima_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
                         AND EXISTS (SELECT 1 FROM chat_mensagem mx
                                      WHERE mx.id_conversa = c.id_conversa
                                        AND mx.id_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
                                        AND mx.remetente_tipo <> 'sistema' AND (mx.remetente_tipo <> 'usuario' OR mx.id_usuario_remetente <> ?))
                        THEN 1 END) AS conversas_nao_lidas,
             COUNT(CASE WHEN c.status = 'aberta' THEN 1 END) AS conversas_abertas
           FROM chat_conversa c
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
          WHERE {$escopo} AND {$status}",
        'ii' . $tipos,
        array_merge([$idUsuario, $idUsuario], $params)
    );

    $detalhe = chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_agendamento, COUNT(m.id_mensagem) AS quantidade_nao_lidas
           FROM chat_conversa c
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
           INNER JOIN chat_mensagem m
                   ON m.id_conversa = c.id_conversa
                  AND m.id_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
                  AND m.remetente_tipo <> 'sistema' AND (m.remetente_tipo <> 'usuario' OR m.id_usuario_remetente <> ?)
          WHERE {$escopo} AND {$status}
            AND c.id_ultima_mensagem IS NOT NULL
            AND c.id_ultima_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
          GROUP BY c.id_conversa, c.id_agendamento
          ORDER BY MAX(m.id_mensagem) DESC
          LIMIT " . CHAT_NAO_LIDAS_DETALHE_MAX,
        'ii' . $tipos,
        array_merge([$idUsuario, $idUsuario], $params)
    );

    $conversas = array_map(static fn(array $linha): array => [
        'id_conversa' => (int)$linha['id_conversa'],
        'id_agendamento' => $linha['id_agendamento'] === null ? null : (int)$linha['id_agendamento'],
        'quantidade_nao_lidas' => (int)$linha['quantidade_nao_lidas'],
    ], $detalhe);

    return [
        'conversas_nao_lidas' => (int)($linhas[0]['conversas_nao_lidas'] ?? 0),
        'conversas_abertas' => (int)($linhas[0]['conversas_abertas'] ?? 0),
        'mensagens_nao_lidas' => array_sum(array_column($conversas, 'quantidade_nao_lidas')),
        'conversas' => $conversas,
    ];
}

/**
 * Conversas ABERTAS com mensagens não lidas do usuário interno, já no escopo do contexto,
 * com os dados mínimos para projetar a notificação da central (cliente, agendamento,
 * última mensagem). Usada somente pela sincronização de notificações.
 */
function chatConversasNaoLidasParaNotificacao(mysqli $conexao, array $ctx, int $limite = 50): array
{
    [$escopo, $tipos, $params] = chatEscopoSql($ctx);
    $idUsuario = (int)$ctx['id_usuario'];
    $limite = max(1, min($limite, CHAT_NAO_LIDAS_DETALHE_MAX));
    return chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                c.id_ultima_mensagem, c.ultima_mensagem_em, cl.nome_completo AS cliente_nome,
                COUNT(m.id_mensagem) AS quantidade_nao_lidas,
                MAX(m.id_mensagem) AS id_ultima_nao_lida
           FROM chat_conversa c
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
           INNER JOIN chat_mensagem m
                   ON m.id_conversa = c.id_conversa
                  AND m.id_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
                  AND m.remetente_tipo <> 'sistema' AND (m.remetente_tipo <> 'usuario' OR m.id_usuario_remetente <> ?)
           LEFT JOIN cliente cl ON cl.id_cliente = c.id_cliente AND cl.id_empresa = c.id_empresa
          WHERE {$escopo} AND c.status = 'aberta'
            AND c.id_ultima_mensagem IS NOT NULL
            AND c.id_ultima_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
          GROUP BY c.id_conversa, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                   c.id_ultima_mensagem, c.ultima_mensagem_em, cl.nome_completo
          ORDER BY c.ultima_mensagem_em DESC, c.id_conversa DESC
          LIMIT {$limite}",
        'ii' . $tipos,
        array_merge([$idUsuario, $idUsuario], $params)
    );
}

/** Ids, entre os informados, que estão dentro do escopo do usuário (qualquer status). */
function chatFiltrarConversasNoEscopo(mysqli $conexao, array $ctx, array $idsConversa): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsConversa), static fn(int $id): bool => $id > 0)));
    if ($ids === []) return [];
    [$escopo, $tipos, $params] = chatEscopoSql($ctx);
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $linhas = chatConsultar(
        $conexao,
        "SELECT c.id_conversa FROM chat_conversa c WHERE c.id_conversa IN ({$marcadores}) AND {$escopo}",
        str_repeat('i', count($ids)) . $tipos,
        array_merge($ids, $params)
    );
    return array_map(static fn(array $linha): int => (int)$linha['id_conversa'], $linhas);
}

/** Quantidade de mensagens de outras pessoas ainda não lidas pelo usuário em uma conversa. */
function chatQuantidadeNaoLidasConversaUsuario(mysqli $conexao, int $idConversa, int $idUsuario): int
{
    $linhas = chatConsultar(
        $conexao,
        "SELECT COUNT(*) AS total
           FROM chat_mensagem m
           LEFT JOIN chat_leitura l ON l.id_conversa = m.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
          WHERE m.id_conversa = ?
            AND m.id_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
            AND m.remetente_tipo <> 'sistema' AND (m.remetente_tipo <> 'usuario' OR m.id_usuario_remetente <> ?)",
        'iii',
        [$idUsuario, $idConversa, $idUsuario]
    );
    return (int)($linhas[0]['total'] ?? 0);
}

function chatFormatarMensagem(array $m, array $ctx): array
{
    return [
        'id_mensagem' => (int)$m['id_mensagem'],
        'remetente_tipo' => (string)$m['remetente_tipo'],
        'remetente_nome' => (string)$m['remetente_nome'],
        'remetente_perfil' => (string)$m['remetente_perfil'],
        'conteudo' => (string)$m['conteudo'],
        'criado_em' => (string)$m['criado_em'],
        'propria' => (string)$m['remetente_tipo'] === 'usuario'
            && (int)($m['id_usuario_remetente'] ?? 0) === (int)$ctx['id_usuario'],
        'remetente_foto_url' => $m['remetente_foto_url'] ?? null,
    ];
}

/**
 * Mensagens de uma conversa JÁ autorizada, sempre em id_mensagem ASC.
 * after_id: incremental (polling); before_id: páginas anteriores; nenhum: últimas mensagens.
 */
function chatListarMensagens(mysqli $conexao, array $ctx, int $idConversa, ?int $afterId, ?int $beforeId, int $limite): array
{
    $campos = 'id_mensagem, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, criado_em';
    if ($afterId !== null) {
        $linhas = chatConsultar($conexao,
            "SELECT {$campos} FROM chat_mensagem WHERE id_conversa = ? AND id_mensagem > ? ORDER BY id_mensagem ASC LIMIT ?",
            'iii', [$idConversa, $afterId, $limite + 1]);
        $temMais = count($linhas) > $limite;
        if ($temMais) array_pop($linhas);
    } else {
        if ($beforeId !== null) {
            $linhas = chatConsultar($conexao,
                "SELECT {$campos} FROM chat_mensagem WHERE id_conversa = ? AND id_mensagem < ? ORDER BY id_mensagem DESC LIMIT ?",
                'iii', [$idConversa, $beforeId, $limite + 1]);
        } else {
            $linhas = chatConsultar($conexao,
                "SELECT {$campos} FROM chat_mensagem WHERE id_conversa = ? ORDER BY id_mensagem DESC LIMIT ?",
                'ii', [$idConversa, $limite + 1]);
        }
        $temMais = count($linhas) > $limite;
        if ($temMais) array_pop($linhas);
        $linhas = array_reverse($linhas);
    }
    return [
        'itens' => array_map(static fn(array $m): array => chatFormatarMensagem($m, $ctx), chatAnexarFotosMensagens($conexao, $linhas)),
        'tem_mais' => $temMais,
    ];
}

/** Grava (sem regredir) o marcador de leitura do usuário interno. */
function chatGravarLeituraUsuario(mysqli $conexao, int $idConversa, int $idUsuario, int $idMensagem): void
{
    // lida_em é avaliado antes do GREATEST para comparar com o valor anterior.
    $stmt = chatPreparar($conexao,
        "INSERT INTO chat_leitura (id_conversa, leitor_tipo, id_usuario, id_cliente, id_ultima_mensagem_lida, lida_em)
         VALUES (?, 'usuario', ?, NULL, ?, NOW())
         ON DUPLICATE KEY UPDATE
             lida_em = IF(VALUES(id_ultima_mensagem_lida) > id_ultima_mensagem_lida, VALUES(lida_em), lida_em),
             id_ultima_mensagem_lida = GREATEST(id_ultima_mensagem_lida, VALUES(id_ultima_mensagem_lida))");
    $stmt->bind_param('iii', $idConversa, $idUsuario, $idMensagem);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new RuntimeException('Falha ao registrar leitura do chat.');
}

/** Marca leitura até uma mensagem que pertença à conversa autorizada. */
function chatMarcarLeitura(mysqli $conexao, array $ctx, int $idConversa, int $idMensagem): array
{
    $existe = chatConsultar($conexao,
        'SELECT 1 FROM chat_mensagem WHERE id_mensagem = ? AND id_conversa = ? LIMIT 1',
        'ii', [$idMensagem, $idConversa]);
    if ($existe === []) return ['ok' => false];

    chatGravarLeituraUsuario($conexao, $idConversa, (int)$ctx['id_usuario'], $idMensagem);
    $atual = chatConsultar($conexao,
        "SELECT id_ultima_mensagem_lida FROM chat_leitura WHERE id_conversa = ? AND leitor_tipo = 'usuario' AND id_usuario = ? LIMIT 1",
        'ii', [$idConversa, (int)$ctx['id_usuario']]);
    return ['ok' => true, 'id_ultima_mensagem_lida' => (int)($atual[0]['id_ultima_mensagem_lida'] ?? $idMensagem)];
}

/** Mensagem já gravada com a mesma chave na mesma conversa (idempotência). */
function chatBuscarPorChave(mysqli $conexao, int $idConversa, string $chave): ?array
{
    $linhas = chatConsultar($conexao,
        'SELECT id_mensagem, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, criado_em
           FROM chat_mensagem WHERE id_conversa = ? AND chave_idempotencia = ? LIMIT 1',
        'is', [$idConversa, $chave]);
    return $linhas[0] ?? null;
}

/**
 * Envio atômico de mensagem interna. Resultados:
 * ['resultado' => 'enviada'|'idempotente'|'nao_encontrada'|'encerrada'|'chave_em_uso', 'mensagem' => ?array]
 */
function chatEnviarMensagemInterna(mysqli $conexao, array $ctx, int $idConversa, string $conteudo, ?string $chave): array
{
    $stmt = chatPreparar($conexao, "SELECT nome FROM usuario WHERE id_usuario = ? AND status = 'ativo' LIMIT 1");
    $idUsuario = (int)$ctx['id_usuario'];
    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();
    $stmt->bind_result($nomeBanco);
    $temUsuario = $stmt->fetch();
    $stmt->close();
    $nome = trim((string)($temUsuario ? $nomeBanco : ''));
    if ($nome === '') throw new RuntimeException('Remetente do chat sem nome válido.');
    $nome = mb_substr($nome, 0, 150, 'UTF-8');
    $perfil = (string)$ctx['perfil'];
    if (!in_array($perfil, CHAT_PERFIS_INTERNOS, true)) throw new RuntimeException('Perfil de remetente inválido.');

    $conexao->begin_transaction();
    try {
        $conversa = chatBuscarConversaAutorizada($conexao, $ctx, $idConversa, true);
        if ($conversa === null) {
            $conexao->rollback();
            return ['resultado' => 'nao_encontrada', 'mensagem' => null];
        }

        if ($chave !== null) {
            $existente = chatBuscarPorChave($conexao, $idConversa, $chave);
            if ($existente !== null) {
                $conexao->rollback();
                return chatResultadoIdempotente($existente, $ctx);
            }
        }

        if ((string)$conversa['status'] !== 'aberta') {
            $conexao->rollback();
            return ['resultado' => 'encerrada', 'mensagem' => null];
        }

        $stmt = chatPreparar($conexao,
            "INSERT INTO chat_mensagem
                 (id_conversa, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, chave_idempotencia)
             VALUES (?, 'usuario', ?, NULL, ?, ?, ?, ?)");
        $stmt->bind_param('iissss', $idConversa, $idUsuario, $nome, $perfil, $conteudo, $chave);
        [$inseriu, $erro] = chatExecutarEscrita($stmt);
        $idMensagem = (int)$stmt->insert_id;
        $stmt->close();

        if (!$inseriu) {
            $conexao->rollback();
            // 1062: a mesma chave foi gravada em paralelo; devolve a mensagem existente.
            if ($erro === 1062 && $chave !== null) {
                $existente = chatBuscarPorChave($conexao, $idConversa, $chave);
                if ($existente !== null) return chatResultadoIdempotente($existente, $ctx);
            }
            throw new RuntimeException('Falha ao gravar mensagem do chat (errno ' . $erro . ').');
        }

        $stmt = chatPreparar($conexao,
            'UPDATE chat_conversa SET id_ultima_mensagem = ?, ultima_mensagem_em = NOW() WHERE id_conversa = ?');
        $stmt->bind_param('ii', $idMensagem, $idConversa);
        $atualizou = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$atualizou) throw new RuntimeException('Falha ao atualizar a conversa do chat.');

        // Quem envia já leu a própria mensagem.
        chatGravarLeituraUsuario($conexao, $idConversa, $idUsuario, $idMensagem);

        $mensagem = chatConsultar($conexao,
            'SELECT id_mensagem, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, criado_em
               FROM chat_mensagem WHERE id_mensagem = ? LIMIT 1',
            'i', [$idMensagem]);
        $conexao->commit();
        // Foto anexada após o COMMIT: é apenas apresentação e não participa da transação do envio.
        $mensagem = chatAnexarFotosMensagens($conexao, $mensagem);
        return ['resultado' => 'enviada', 'mensagem' => chatFormatarMensagem($mensagem[0], $ctx)];
    } catch (Throwable $e) {
        try { $conexao->rollback(); } catch (Throwable) {}
        throw $e;
    }
}

function chatResultadoIdempotente(array $existente, array $ctx): array
{
    $mesmoRemetente = (string)$existente['remetente_tipo'] === 'usuario'
        && (int)$existente['id_usuario_remetente'] === (int)$ctx['id_usuario'];
    return $mesmoRemetente
        ? ['resultado' => 'idempotente', 'mensagem' => chatFormatarMensagem($existente, $ctx)]
        : ['resultado' => 'chave_em_uso', 'mensagem' => null];
}

/**
 * Conversa autorizada para o cliente autenticado. A empresa e o cliente
 * pertencem sempre ao contexto de sessão já validado pelo handler.
 */
function chatClienteBuscarConversaAutorizada(mysqli $conexao, array $ctx, int $idConversa, bool $paraAtualizar = false): ?array
{
    $idEmpresa = (int)$ctx['id_empresa'];
    $idCliente = (int)$ctx['id_cliente'];

    if ($paraAtualizar) {
        $linhas = chatConsultar(
            $conexao,
            'SELECT c.* FROM chat_conversa c
              WHERE c.id_conversa = ? AND c.id_empresa = ? AND c.id_cliente = ?
              LIMIT 1 FOR UPDATE',
            'iii',
            [$idConversa, $idEmpresa, $idCliente]
        );
        return $linhas[0] ?? null;
    }

    $quantidade = chatSqlQuantidadeNaoLidasCliente();
    $linhas = chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_profissional, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                c.status, c.criado_em, c.id_ultima_mensagem, c.ultima_mensagem_em, c.encerrada_em,
                up.nome AS profissional_nome, up.foto_perfil AS profissional_foto, l.id_ultima_mensagem_lida,
                {$quantidade} AS quantidade_nao_lidas
           FROM chat_conversa c
           LEFT JOIN profissional p ON p.id_profissional = c.id_profissional
           LEFT JOIN usuario up ON up.id_usuario = p.id_usuario
           LEFT JOIN chat_leitura l
             ON l.id_conversa = c.id_conversa
            AND l.leitor_tipo = 'cliente'
            AND l.id_cliente = ?
          WHERE c.id_conversa = ? AND c.id_empresa = ? AND c.id_cliente = ?
          LIMIT 1",
        'iiiii',
        [$idCliente, $idCliente, $idConversa, $idEmpresa, $idCliente]
    );

    return $linhas[0] ?? null;
}

/** Formato público da conversa para a Área do Cliente, sem ids de terceiros. */
function chatClienteFormatarConversa(array $conversa): array
{
    $ultima = $conversa['id_ultima_mensagem'] === null ? null : (int)$conversa['id_ultima_mensagem'];
    $lida = $conversa['id_ultima_mensagem_lida'] === null ? 0 : (int)$conversa['id_ultima_mensagem_lida'];
    $aberta = (string)$conversa['status'] === 'aberta';

    return [
        'id_conversa' => (int)$conversa['id_conversa'],
        'id_agendamento' => $conversa['id_agendamento'] === null ? null : (int)$conversa['id_agendamento'],
        'status' => (string)$conversa['status'],
        'profissional_nome' => (string)($conversa['profissional_nome'] ?? ''),
        'agendamento_data' => (string)$conversa['agendamento_data'],
        'agendamento_hora_inicio' => (string)$conversa['agendamento_hora_inicio'],
        'id_ultima_mensagem' => $ultima,
        'id_ultima_mensagem_lida' => $lida > 0 ? $lida : null,
        'nao_lida' => $ultima !== null && $ultima > $lida,
        'encerrada_em' => $conversa['encerrada_em'] === null ? null : (string)$conversa['encerrada_em'],
        'pode_responder' => $aberta,
        'quantidade_nao_lidas' => max(0, (int)($conversa['quantidade_nao_lidas'] ?? 0)),
        'profissional_foto_url' => chatFotoUrl($conversa['profissional_foto'] ?? null, 'usuarios'),
    ];
}

/**
 * Não lidas do cliente da sessão (empresa + cliente vêm somente do contexto validado).
 * Conversas desvinculadas do agendamento (id_agendamento NULL) ficam fora: a Área do
 * Cliente só acessa o Chat pelo card do agendamento.
 */
function chatClienteContarNaoLidas(mysqli $conexao, array $ctx): array
{
    $idEmpresa = (int)$ctx['id_empresa'];
    $idCliente = (int)$ctx['id_cliente'];
    $linhas = chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_agendamento, COUNT(m.id_mensagem) AS quantidade_nao_lidas
           FROM chat_conversa c
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'cliente' AND l.id_cliente = ?
           INNER JOIN chat_mensagem m
                   ON m.id_conversa = c.id_conversa
                  AND m.id_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
                  AND (m.remetente_tipo <> 'cliente' OR m.id_cliente_remetente <> ?)
          WHERE c.id_empresa = ? AND c.id_cliente = ?
            AND c.id_agendamento IS NOT NULL
            AND c.id_ultima_mensagem IS NOT NULL
            AND c.id_ultima_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)
          GROUP BY c.id_conversa, c.id_agendamento
          ORDER BY MAX(m.id_mensagem) DESC
          LIMIT " . CHAT_NAO_LIDAS_DETALHE_MAX,
        'iiii',
        [$idCliente, $idCliente, $idEmpresa, $idCliente]
    );

    $conversas = array_map(static fn(array $linha): array => [
        'id_conversa' => (int)$linha['id_conversa'],
        'id_agendamento' => (int)$linha['id_agendamento'],
        'quantidade_nao_lidas' => (int)$linha['quantidade_nao_lidas'],
    ], $linhas);

    return [
        'total_conversas_nao_lidas' => count($conversas),
        'total_mensagens_nao_lidas' => array_sum(array_column($conversas, 'quantidade_nao_lidas')),
        'conversas' => $conversas,
    ];
}

function chatClienteFormatarMensagem(array $mensagem, array $ctx): array
{
    return [
        'id_mensagem' => (int)$mensagem['id_mensagem'],
        'remetente_tipo' => (string)$mensagem['remetente_tipo'],
        'remetente_nome' => (string)$mensagem['remetente_nome'],
        'remetente_perfil' => $mensagem['remetente_perfil'] === null ? null : (string)$mensagem['remetente_perfil'],
        'conteudo' => (string)$mensagem['conteudo'],
        'criado_em' => (string)$mensagem['criado_em'],
        'propria' => (string)$mensagem['remetente_tipo'] === 'cliente'
            && (int)($mensagem['id_cliente_remetente'] ?? 0) === (int)$ctx['id_cliente'],
        'remetente_foto_url' => $mensagem['remetente_foto_url'] ?? null,
    ];
}

/** Mensagens da conversa já autorizada para o cliente, da mais antiga para a mais recente. */
function chatClienteListarMensagens(mysqli $conexao, array $ctx, int $idConversa, ?int $afterId, ?int $beforeId, int $limite): array
{
    $campos = 'id_mensagem, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, criado_em';
    if ($afterId !== null) {
        $linhas = chatConsultar(
            $conexao,
            "SELECT {$campos} FROM chat_mensagem WHERE id_conversa = ? AND id_mensagem > ? ORDER BY id_mensagem ASC LIMIT ?",
            'iii',
            [$idConversa, $afterId, $limite + 1]
        );
        $temMais = count($linhas) > $limite;
        if ($temMais) array_pop($linhas);
    } else {
        if ($beforeId !== null) {
            $linhas = chatConsultar(
                $conexao,
                "SELECT {$campos} FROM chat_mensagem WHERE id_conversa = ? AND id_mensagem < ? ORDER BY id_mensagem DESC LIMIT ?",
                'iii',
                [$idConversa, $beforeId, $limite + 1]
            );
        } else {
            $linhas = chatConsultar(
                $conexao,
                "SELECT {$campos} FROM chat_mensagem WHERE id_conversa = ? ORDER BY id_mensagem DESC LIMIT ?",
                'ii',
                [$idConversa, $limite + 1]
            );
        }
        $temMais = count($linhas) > $limite;
        if ($temMais) array_pop($linhas);
        $linhas = array_reverse($linhas);
    }

    return [
        'itens' => array_map(static fn(array $mensagem): array => chatClienteFormatarMensagem($mensagem, $ctx), chatAnexarFotosMensagens($conexao, $linhas)),
        'tem_mais' => $temMais,
    ];
}

/** Grava a leitura do próprio cliente sem regredir o marcador existente. */
function chatClienteGravarLeitura(mysqli $conexao, int $idConversa, int $idCliente, int $idMensagem): void
{
    $stmt = chatPreparar(
        $conexao,
        "INSERT INTO chat_leitura (id_conversa, leitor_tipo, id_usuario, id_cliente, id_ultima_mensagem_lida, lida_em)
         VALUES (?, 'cliente', NULL, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
             lida_em = IF(VALUES(id_ultima_mensagem_lida) > id_ultima_mensagem_lida, VALUES(lida_em), lida_em),
             id_ultima_mensagem_lida = GREATEST(id_ultima_mensagem_lida, VALUES(id_ultima_mensagem_lida))"
    );
    $stmt->bind_param('iii', $idConversa, $idCliente, $idMensagem);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new RuntimeException('Falha ao registrar leitura do chat.');
}

function chatClienteMarcarLeitura(mysqli $conexao, array $ctx, int $idConversa, int $idMensagem): array
{
    $existe = chatConsultar(
        $conexao,
        'SELECT 1 FROM chat_mensagem WHERE id_mensagem = ? AND id_conversa = ? LIMIT 1',
        'ii',
        [$idMensagem, $idConversa]
    );
    if ($existe === []) return ['ok' => false];

    $idCliente = (int)$ctx['id_cliente'];
    chatClienteGravarLeitura($conexao, $idConversa, $idCliente, $idMensagem);
    $atual = chatConsultar(
        $conexao,
        "SELECT id_ultima_mensagem_lida FROM chat_leitura
          WHERE id_conversa = ? AND leitor_tipo = 'cliente' AND id_cliente = ? LIMIT 1",
        'ii',
        [$idConversa, $idCliente]
    );

    return ['ok' => true, 'id_ultima_mensagem_lida' => (int)($atual[0]['id_ultima_mensagem_lida'] ?? $idMensagem)];
}

function chatClienteBuscarPorChave(mysqli $conexao, int $idConversa, string $chave): ?array
{
    $linhas = chatConsultar(
        $conexao,
        'SELECT id_mensagem, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, criado_em
           FROM chat_mensagem WHERE id_conversa = ? AND chave_idempotencia = ? LIMIT 1',
        'is',
        [$idConversa, $chave]
    );
    return $linhas[0] ?? null;
}

function chatClienteResultadoIdempotente(array $existente, array $ctx): array
{
    $mesmoRemetente = (string)$existente['remetente_tipo'] === 'cliente'
        && (int)($existente['id_cliente_remetente'] ?? 0) === (int)$ctx['id_cliente'];

    return $mesmoRemetente
        ? ['resultado' => 'idempotente', 'mensagem' => chatClienteFormatarMensagem($existente, $ctx)]
        : ['resultado' => 'chave_em_uso', 'mensagem' => null];
}

/** Envio transacional do cliente para conversa previamente vinculada ao seu agendamento. */
function chatClienteEnviarMensagem(mysqli $conexao, array $ctx, int $idConversa, string $conteudo, ?string $chave): array
{
    $nome = trim((string)($ctx['nome_cliente'] ?? ''));
    if ($nome === '') throw new RuntimeException('Remetente do chat sem nome válido.');
    $nome = mb_substr($nome, 0, 150, 'UTF-8');
    $idCliente = (int)$ctx['id_cliente'];

    $conexao->begin_transaction();
    try {
        $conversa = chatClienteBuscarConversaAutorizada($conexao, $ctx, $idConversa, true);
        if ($conversa === null) {
            $conexao->rollback();
            return ['resultado' => 'nao_encontrada', 'mensagem' => null];
        }

        if ($chave !== null) {
            $existente = chatClienteBuscarPorChave($conexao, $idConversa, $chave);
            if ($existente !== null) {
                $conexao->rollback();
                return chatClienteResultadoIdempotente($existente, $ctx);
            }
        }

        if ((string)$conversa['status'] !== 'aberta') {
            $conexao->rollback();
            return ['resultado' => 'encerrada', 'mensagem' => null];
        }

        $stmt = chatPreparar(
            $conexao,
            "INSERT INTO chat_mensagem
                 (id_conversa, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, chave_idempotencia)
             VALUES (?, 'cliente', NULL, ?, ?, 'cliente', ?, ?)"
        );
        $stmt->bind_param('iisss', $idConversa, $idCliente, $nome, $conteudo, $chave);
        [$inseriu, $erro] = chatExecutarEscrita($stmt);
        $idMensagem = (int)$stmt->insert_id;
        $stmt->close();

        if (!$inseriu) {
            $conexao->rollback();
            if ($erro === 1062 && $chave !== null) {
                $existente = chatClienteBuscarPorChave($conexao, $idConversa, $chave);
                if ($existente !== null) return chatClienteResultadoIdempotente($existente, $ctx);
            }
            throw new RuntimeException('Falha ao gravar mensagem do chat (errno ' . $erro . ').');
        }

        $stmt = chatPreparar($conexao, 'UPDATE chat_conversa SET id_ultima_mensagem = ?, ultima_mensagem_em = NOW() WHERE id_conversa = ?');
        $stmt->bind_param('ii', $idMensagem, $idConversa);
        $atualizou = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$atualizou) throw new RuntimeException('Falha ao atualizar a conversa do chat.');

        chatClienteGravarLeitura($conexao, $idConversa, $idCliente, $idMensagem);
        $mensagem = chatConsultar(
            $conexao,
            'SELECT id_mensagem, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, criado_em
               FROM chat_mensagem WHERE id_mensagem = ? LIMIT 1',
            'i',
            [$idMensagem]
        );
        $conexao->commit();
        // Foto anexada após o COMMIT: é apenas apresentação e não participa da transação do envio.
        $mensagem = chatAnexarFotosMensagens($conexao, $mensagem);

        return ['resultado' => 'enviada', 'mensagem' => chatClienteFormatarMensagem($mensagem[0], $ctx)];
    } catch (Throwable $e) {
        try { $conexao->rollback(); } catch (Throwable) {}
        throw $e;
    }
}

/**
 * Cria ou recupera uma conversa para um agendamento pertencente ao cliente
 * da sessão. O chamador abre e confirma a transação.
 */
function chatClienteIniciarConversaPorAgendamento(mysqli $conexao, array $ctx, int $idAgendamento): array
{
    $idEmpresa = (int)$ctx['id_empresa'];
    $idCliente = (int)$ctx['id_cliente'];
    $agendamento = chatConsultar(
        $conexao,
        'SELECT id_agendamento, id_cliente, id_profissional, data_agendamento, hora_inicio, status
           FROM agendamento
          WHERE id_agendamento = ? AND id_empresa = ? AND id_cliente = ?
          LIMIT 1 FOR UPDATE',
        'iii',
        [$idAgendamento, $idEmpresa, $idCliente]
    )[0] ?? null;
    if ($agendamento === null) return ['resultado' => 'nao_encontrado', 'id_conversa' => null];

    $existente = chatConsultar(
        $conexao,
        'SELECT id_conversa FROM chat_conversa
          WHERE id_agendamento = ? AND id_empresa = ? AND id_cliente = ? LIMIT 1',
        'iii',
        [$idAgendamento, $idEmpresa, $idCliente]
    )[0] ?? null;
    if ($existente !== null) return ['resultado' => 'existente', 'id_conversa' => (int)$existente['id_conversa']];

    if (!in_array((string)$agendamento['status'], CHAT_STATUS_AGENDAMENTO_INICIO, true)) {
        return ['resultado' => 'status_invalido', 'id_conversa' => null];
    }

    $stmt = chatPreparar(
        $conexao,
        "INSERT INTO chat_conversa
             (id_empresa, id_cliente, id_profissional, id_agendamento, agendamento_data, agendamento_hora_inicio,
              status, criado_por_tipo, criado_por_usuario, ultima_mensagem_em)
         VALUES (?, ?, ?, ?, ?, ?, 'aberta', 'cliente', NULL, NOW())"
    );
    $idProfissional = (int)$agendamento['id_profissional'];
    $data = (string)$agendamento['data_agendamento'];
    $hora = (string)$agendamento['hora_inicio'];
    $stmt->bind_param('iiiiss', $idEmpresa, $idCliente, $idProfissional, $idAgendamento, $data, $hora);
    [$inseriu, $erro] = chatExecutarEscrita($stmt);
    $idConversa = (int)$stmt->insert_id;
    $stmt->close();

    if ($inseriu) {
        chatAuditarConversaCriada(
            $conexao,
            $idConversa,
            $idAgendamento,
            'chat_cliente',
            chatAtorAuditoriaCliente($ctx)
        );
        return ['resultado' => 'criada', 'id_conversa' => $idConversa];
    }

    if ($erro === 1062) {
        $existente = chatConsultar(
            $conexao,
            'SELECT id_conversa FROM chat_conversa
              WHERE id_agendamento = ? AND id_empresa = ? AND id_cliente = ? LIMIT 1',
            'iii',
            [$idAgendamento, $idEmpresa, $idCliente]
        )[0] ?? null;
        if ($existente !== null) return ['resultado' => 'existente', 'id_conversa' => (int)$existente['id_conversa']];
        return ['resultado' => 'nao_encontrado', 'id_conversa' => null];
    }

    throw new RuntimeException('Falha ao criar a conversa do chat (errno ' . $erro . ').');
}

/**
 * Encerra conversa aberta dentro do escopo. O chamador controla a transação.
 * Resultados: 'encerrada'|'ja_encerrada'|'nao_encontrada'.
 */
function chatEncerrarConversa(mysqli $conexao, array $ctx, int $idConversa): string
{
    $conversa = chatBuscarConversaAutorizada($conexao, $ctx, $idConversa, true);
    if ($conversa === null) return 'nao_encontrada';
    if ((string)$conversa['status'] === 'encerrada') return 'ja_encerrada';

    [$escopo, $tipos, $params] = chatEscopoSql($ctx, 'chat_conversa');
    $stmt = chatPreparar($conexao,
        "UPDATE chat_conversa
            SET status = 'encerrada', encerrada_em = NOW(), encerrada_por_usuario = ?
          WHERE id_conversa = ? AND {$escopo} AND status = 'aberta'
          LIMIT 1");
    $idUsuario = (int)$ctx['id_usuario'];
    $valores = array_merge([$idUsuario, $idConversa], $params);
    $stmt->bind_param('ii' . $tipos, ...$valores);
    $ok = $stmt->execute();
    $afetadas = $stmt->affected_rows;
    $stmt->close();
    if (!$ok || $afetadas !== 1) throw new RuntimeException('Falha ao encerrar a conversa do chat.');
    if ($afetadas === 1) {
        $idAgendamento = isset($conversa['id_agendamento']) ? (int)$conversa['id_agendamento'] : null;
        chatAuditarConversaEncerrada($conexao, $idConversa, $idAgendamento, 'encerramento_manual');
        return 'encerrada';
    }

    throw new RuntimeException('Estado inesperado ao encerrar a conversa do Chat.');
}

/**
 * Obtém ou cria a conversa de um agendamento da empresa da sessão.
 * O chamador deve ter iniciado a transação: o agendamento é travado até que
 * ele faça commit/rollback, serializando esta criação com edição e exclusão.
 * Dados de cliente/profissional/data vêm sempre do agendamento travado.
 * Resultados: ['resultado' => 'criada'|'existente'|'nao_encontrado'|'status_invalido', 'id_conversa' => ?int]
 */
function chatIniciarConversaPorAgendamento(mysqli $conexao, array $ctx, int $idAgendamento): array
{
    $sql = 'SELECT id_agendamento, id_cliente, id_profissional, data_agendamento, hora_inicio, status
              FROM agendamento WHERE id_agendamento = ? AND id_empresa = ?';
    $tipos = 'ii';
    $params = [$idAgendamento, (int)$ctx['id_empresa']];
    // Profissional (ou usuário sem escopo de empresa) trava somente o próprio agendamento.
    if ($ctx['id_profissional_escopo'] !== null) {
        $sql .= ' AND id_profissional = ?';
        $tipos .= 'i';
        $params[] = (int)$ctx['id_profissional_escopo'];
    }
    $agendamento = chatConsultar($conexao, $sql . ' LIMIT 1 FOR UPDATE', $tipos, $params)[0] ?? null;
    if ($agendamento === null) return ['resultado' => 'nao_encontrado', 'id_conversa' => null];

    $existente = chatConversaDoAgendamento($conexao, $ctx, $idAgendamento);
    if ($existente !== false) {
        return $existente === null
            ? ['resultado' => 'nao_encontrado', 'id_conversa' => null]
            : ['resultado' => 'existente', 'id_conversa' => $existente];
    }

    if (!in_array((string)$agendamento['status'], CHAT_STATUS_AGENDAMENTO_INICIO, true)) {
        return ['resultado' => 'status_invalido', 'id_conversa' => null];
    }

    $stmt = chatPreparar($conexao,
        "INSERT INTO chat_conversa
             (id_empresa, id_cliente, id_profissional, id_agendamento, agendamento_data, agendamento_hora_inicio,
              status, criado_por_tipo, criado_por_usuario, ultima_mensagem_em)
         VALUES (?, ?, ?, ?, ?, ?, 'aberta', 'usuario', ?, NOW())");
    $idEmpresa = (int)$ctx['id_empresa'];
    $idCliente = (int)$agendamento['id_cliente'];
    $idProfissional = (int)$agendamento['id_profissional'];
    $data = (string)$agendamento['data_agendamento'];
    $hora = (string)$agendamento['hora_inicio'];
    $idUsuario = (int)$ctx['id_usuario'];
    $stmt->bind_param('iiiissi', $idEmpresa, $idCliente, $idProfissional, $idAgendamento, $data, $hora, $idUsuario);
    [$inseriu, $erro] = chatExecutarEscrita($stmt);
    $idConversa = (int)$stmt->insert_id;
    $stmt->close();

    if ($inseriu) {
        chatAuditarConversaCriada($conexao, $idConversa, $idAgendamento, 'chat_interno');
        return ['resultado' => 'criada', 'id_conversa' => $idConversa];
    }

    // 1062: outra requisição criou a conversa deste agendamento ao mesmo tempo (UNIQUE id_agendamento).
    if ($erro === 1062) {
        $existente = chatConversaDoAgendamento($conexao, $ctx, $idAgendamento);
        if (is_int($existente)) return ['resultado' => 'existente', 'id_conversa' => $existente];
        return ['resultado' => 'nao_encontrado', 'id_conversa' => null];
    }
    throw new RuntimeException('Falha ao criar a conversa do chat (errno ' . $erro . ').');
}

/**
 * Conversa já existente para o agendamento: false = não existe; null = existe fora do escopo;
 * int = id da conversa autorizada.
 */
function chatConversaDoAgendamento(mysqli $conexao, array $ctx, int $idAgendamento): int|false|null
{
    $linha = chatConsultar($conexao,
        'SELECT id_conversa FROM chat_conversa WHERE id_agendamento = ? AND id_empresa = ? LIMIT 1',
        'ii', [$idAgendamento, (int)$ctx['id_empresa']])[0] ?? null;
    if ($linha === null) return false;
    $idConversa = (int)$linha['id_conversa'];
    return chatBuscarConversaAutorizada($conexao, $ctx, $idConversa) !== null ? $idConversa : null;
}

/**
 * Busca e trava a conversa técnica vinculada ao agendamento dentro da empresa.
 * O chamador deve já estar em transação e ser responsável por commit/rollback.
 * Não aplica autorização de leitura do Chat nem retorna mensagens.
 */
function chatBuscarConversaVinculadaAgendamentoParaAtualizar(mysqli $conexao, int $idEmpresa, int $idAgendamento): ?array
{
    $stmt = chatPreparar($conexao,
        'SELECT id_conversa, id_cliente, id_profissional, agendamento_data, agendamento_hora_inicio, status
           FROM chat_conversa
          WHERE id_empresa = ? AND id_agendamento = ?
          LIMIT 1 FOR UPDATE');
    $stmt->bind_param('ii', $idEmpresa, $idAgendamento);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $conversa = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();
    return $conversa ?: null;
}

/**
 * Mantém o histórico da conversa consistente com uma edição de agendamento.
 * Cliente/profissional divergente encerra e desvincula a conversa antiga; se
 * ambos permanecem, sincroniza apenas o snapshot de data/hora que tiver mudado.
 * Esta função não inicia nem finaliza transação.
 */
function chatPrepararConversaParaEdicaoAgendamento(
    mysqli $conexao,
    int $idEmpresa,
    int $idAgendamento,
    int $idClienteNovo,
    int $idProfissionalNovo,
    string $dataAgendamentoNova,
    string $horaInicioNova,
    int $idUsuarioEncerramento
): void {
    $conversa = chatBuscarConversaVinculadaAgendamentoParaAtualizar($conexao, $idEmpresa, $idAgendamento);
    if ($conversa === null) return;

    $idConversa = (int)$conversa['id_conversa'];
    $vinculoAlterado = (int)$conversa['id_cliente'] !== $idClienteNovo
        || (int)$conversa['id_profissional'] !== $idProfissionalNovo;

    if ($vinculoAlterado) {
        // A ordem preserva os dados de encerramento já históricos antes de fixar o status.
        $stmt = chatPreparar($conexao,
            "UPDATE chat_conversa
                SET encerrada_em = IF(status = 'aberta', NOW(), encerrada_em),
                    encerrada_por_usuario = IF(status = 'aberta', ?, encerrada_por_usuario),
                    status = 'encerrada',
                    id_agendamento = NULL
              WHERE id_conversa = ? AND id_empresa = ? AND id_agendamento = ?
              LIMIT 1");
        $stmt->bind_param('iiii', $idUsuarioEncerramento, $idConversa, $idEmpresa, $idAgendamento);
        $ok = $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $afetadas !== 1) throw new RuntimeException('Falha ao encerrar e desvincular a conversa do chat.');
        chatAuditarConversaDesvinculada(
            $conexao,
            $idConversa,
            $idAgendamento,
            (string)$conversa['status'],
            'vinculo_agendamento_alterado'
        );
        return;
    }

    if ((string)$conversa['agendamento_data'] === $dataAgendamentoNova
        && (string)$conversa['agendamento_hora_inicio'] === $horaInicioNova) {
        return;
    }

    $stmt = chatPreparar($conexao,
        'UPDATE chat_conversa
            SET agendamento_data = ?, agendamento_hora_inicio = ?
          WHERE id_conversa = ? AND id_empresa = ? AND id_agendamento = ?
          LIMIT 1');
    $stmt->bind_param('ssiii', $dataAgendamentoNova, $horaInicioNova, $idConversa, $idEmpresa, $idAgendamento);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new RuntimeException('Falha ao sincronizar o snapshot do agendamento no chat.');
}

/**
 * Encerra uma conversa aberta antes de o agendamento ser excluído. A FK do
 * agendamento faz o desvínculo; conversas já encerradas mantêm seu encerramento.
 * Esta função não inicia nem finaliza transação.
 */
function chatPrepararConversaParaExclusaoAgendamento(
    mysqli $conexao,
    int $idEmpresa,
    int $idAgendamento,
    int $idUsuarioEncerramento
): void {
    $conversa = chatBuscarConversaVinculadaAgendamentoParaAtualizar($conexao, $idEmpresa, $idAgendamento);
    if ($conversa === null) return;

    $idConversa = (int)$conversa['id_conversa'];
    $statusAnterior = (string)$conversa['status'];
    if ($statusAnterior === 'aberta') {
        $stmt = chatPreparar($conexao,
            "UPDATE chat_conversa
                SET status = 'encerrada', encerrada_em = NOW(), encerrada_por_usuario = ?
              WHERE id_conversa = ? AND id_empresa = ? AND id_agendamento = ? AND status = 'aberta'
              LIMIT 1");
        $stmt->bind_param('iiii', $idUsuarioEncerramento, $idConversa, $idEmpresa, $idAgendamento);
        $ok = $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $afetadas !== 1) throw new RuntimeException('Falha ao encerrar a conversa antes da exclusão do agendamento.');
    }

    chatAuditarConversaDesvinculada(
        $conexao,
        $idConversa,
        $idAgendamento,
        $statusAnterior,
        'agendamento_excluido'
    );
}

/* ==========================================================
   MENSAGENS DO SISTEMA (infraestrutura — Fase 5B-2)
   ----------------------------------------------------------
   Somente o backend cria mensagens de sistema: não há rota para isso e os
   endpoints de envio continuam gravando literalmente 'usuario'/'cliente'.
   Formato no banco: remetente_tipo='sistema', id_usuario_remetente=NULL,
   id_cliente_remetente=NULL, remetente_perfil='sistema' e remetente_nome
   com o nome exibido da empresa (snapshot). Nenhum evento é disparado aqui.
   ========================================================== */
const CHAT_SISTEMA_PERFIL = 'sistema';

/**
 * Chave idempotente determinística em formato UUID (36 caracteres) para eventos do sistema.
 * Usa a versão "0", fora do intervalo [1-8] aceito por chatNormalizarChaveIdempotencia():
 * nenhuma chave enviada pelo navegador consegue colidir com (ou bloquear) a do sistema.
 * Muda quando data/hora do agendamento mudam; é estável para o mesmo evento.
 */
function chatChaveIdempotenciaSistema(string $evento, int $idEmpresa, int $idAgendamento, string $data, string $hora): string
{
    $evento = trim($evento);
    if (preg_match('/^[a-z0-9_.-]{1,60}$/', $evento) !== 1) {
        throw new InvalidArgumentException('Evento do sistema inválido para a chave idempotente.');
    }
    $dataNormalizada = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    $horaNormalizada = substr(trim($hora), 0, 5);
    if ($idEmpresa <= 0 || $idAgendamento <= 0 || $dataNormalizada === false
        || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaNormalizada) !== 1) {
        throw new InvalidArgumentException('Dados do agendamento inválidos para a chave idempotente.');
    }

    $hex = substr(hash('sha256', 'amagenda:chat:sistema:' . $evento
        . ':empresa:' . $idEmpresa
        . ':agendamento:' . $idAgendamento
        . ':' . $dataNormalizada->format('Y-m-d') . ':' . $horaNormalizada), 0, 32);
    $hex[12] = '0'; // versão reservada ao sistema
    $hex[16] = '8'; // variante RFC 4122
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/** Chave gerada por chatChaveIdempotenciaSistema() (versão 0); qualquer outra é recusada. */
function chatChaveSistemaValida(string $chave): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-0[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $chave) === 1;
}

/**
 * Nome exibido nas mensagens do sistema: configuracao_geral_empresa.nome_exibicao,
 * com fallback oficial "AmAgenda" (mesma regra da Identidade Visual).
 */
function chatNomeExibicaoSistema(mysqli $conexao, int $idEmpresa): string
{
    require_once __DIR__ . '/../_config/identidade_visual.php';
    try {
        $linha = identidadeBuscar($conexao, $idEmpresa);
    } catch (Throwable $e) {
        error_log('[chat/sistema] Identidade visual indisponível: ' . $e->getMessage());
        $linha = [];
    }
    $nome = trim((string)(identidadeFallback($linha)['nome_exibicao'] ?? ''));
    if ($nome === '') $nome = AMAGENDA_NOME_PADRAO;
    return mb_substr($nome, 0, 150, 'UTF-8');
}

/**
 * Trava o agendamento da empresa (FOR UPDATE). O chamador deve estar em transação.
 */
function chatBuscarAgendamentoParaAtualizar(mysqli $conexao, int $idEmpresa, int $idAgendamento): ?array
{
    return chatConsultar(
        $conexao,
        'SELECT id_agendamento, id_cliente, id_profissional, data_agendamento, hora_inicio, status
           FROM agendamento
          WHERE id_agendamento = ? AND id_empresa = ?
          LIMIT 1 FOR UPDATE',
        'ii',
        [$idAgendamento, $idEmpresa]
    )[0] ?? null;
}

/**
 * Obtém ou cria a conversa de um agendamento JÁ TRAVADO pelo chamador (FOR UPDATE, em transação).
 * Uma conversa por agendamento (uk_chat_conversa_agendamento); concorrência tratada pelo 1062.
 * $criador registra quem originou a conversa — nunca 'sistema' (chat_conversa aceita só
 * cliente/usuario): ['tipo' => 'cliente'] ou ['tipo' => 'usuario', 'id_usuario' => int].
 * Não aplica autorização de leitura do Chat; não inicia nem finaliza transação.
 * Resultados: ['resultado' => 'criada'|'existente'|'status_invalido'|'divergente', 'id_conversa' => ?int, 'status' => ?string]
 */
function chatObterOuCriarConversaAgendamento(mysqli $conexao, int $idEmpresa, array $agendamento, array $criador): array
{
    $tipoCriador = (string)($criador['tipo'] ?? '');
    $idUsuarioCriador = null;
    if ($tipoCriador === 'usuario') {
        $idUsuarioCriador = chatInteiroPositivo($criador['id_usuario'] ?? null);
        if ($idUsuarioCriador === null) throw new InvalidArgumentException('Usuário criador da conversa inválido.');
    } elseif ($tipoCriador !== 'cliente') {
        throw new InvalidArgumentException('Criador da conversa inválido.');
    }

    $idAgendamento = (int)($agendamento['id_agendamento'] ?? 0);
    $idCliente = (int)($agendamento['id_cliente'] ?? 0);
    $idProfissional = (int)($agendamento['id_profissional'] ?? 0);
    if ($idEmpresa <= 0 || $idAgendamento <= 0 || $idCliente <= 0 || $idProfissional <= 0) {
        throw new InvalidArgumentException('Agendamento inválido para a conversa.');
    }

    $buscarExistente = static function () use ($conexao, $idEmpresa, $idAgendamento): ?array {
        return chatConsultar(
            $conexao,
            'SELECT id_conversa, id_cliente, id_profissional, status
               FROM chat_conversa
              WHERE id_agendamento = ? AND id_empresa = ?
              LIMIT 1 FOR UPDATE',
            'ii',
            [$idAgendamento, $idEmpresa]
        )[0] ?? null;
    };
    $resultadoExistente = static function (array $conversa) use ($idCliente, $idProfissional): array {
        // Defesa extra: a edição de agendamento desvincula a conversa ao trocar cliente/profissional.
        $mesmoVinculo = (int)$conversa['id_cliente'] === $idCliente && (int)$conversa['id_profissional'] === $idProfissional;
        return [
            'resultado' => $mesmoVinculo ? 'existente' : 'divergente',
            'id_conversa' => $mesmoVinculo ? (int)$conversa['id_conversa'] : null,
            'status' => $mesmoVinculo ? (string)$conversa['status'] : null,
        ];
    };

    $existente = $buscarExistente();
    if ($existente !== null) return $resultadoExistente($existente);

    if (!in_array((string)($agendamento['status'] ?? ''), CHAT_STATUS_AGENDAMENTO_INICIO, true)) {
        return ['resultado' => 'status_invalido', 'id_conversa' => null, 'status' => null];
    }

    $data = (string)$agendamento['data_agendamento'];
    $hora = (string)$agendamento['hora_inicio'];
    $stmt = chatPreparar($conexao,
        "INSERT INTO chat_conversa
             (id_empresa, id_cliente, id_profissional, id_agendamento, agendamento_data, agendamento_hora_inicio,
              status, criado_por_tipo, criado_por_usuario, ultima_mensagem_em)
         VALUES (?, ?, ?, ?, ?, ?, 'aberta', ?, ?, NOW())");
    $stmt->bind_param('iiiisssi', $idEmpresa, $idCliente, $idProfissional, $idAgendamento, $data, $hora, $tipoCriador, $idUsuarioCriador);
    [$inseriu, $erro] = chatExecutarEscrita($stmt);
    $idConversa = (int)$stmt->insert_id;
    $stmt->close();

    if ($inseriu) return ['resultado' => 'criada', 'id_conversa' => $idConversa, 'status' => 'aberta'];

    // 1062: outra requisição criou a conversa deste agendamento ao mesmo tempo.
    if ($erro === 1062) {
        $existente = $buscarExistente();
        if ($existente !== null) return $resultadoExistente($existente);
    }
    throw new RuntimeException('Falha ao criar a conversa do chat (errno ' . $erro . ').');
}

/**
 * Grava uma mensagem do SISTEMA (uso exclusivo do backend). O chamador deve estar em
 * transação e decide se a conversa pode receber o evento (inclusive se encerrada).
 * - idempotente pela chave determinística no índice existente (id_conversa, chave_idempotencia);
 * - atualiza id_ultima_mensagem/ultima_mensagem_em da conversa;
 * - não grava leitura de usuários internos (sistema não conta como não lida para eles);
 * - $lidaPeloCliente = true registra o cliente da conversa como já tendo lido (eventos que
 *   o próprio cliente originou), somente se ele já tinha lido tudo antes desta mensagem:
 *   o marcador nunca "pula" respostas anteriores ainda não lidas.
 * Resultados: ['resultado' => 'gravada'|'idempotente'|'conversa_invalida', 'id_mensagem' => ?int]
 */
function chatRegistrarMensagemSistema(
    mysqli $conexao,
    int $idEmpresa,
    int $idConversa,
    string $conteudo,
    string $nomeExibicao,
    string $chave,
    bool $lidaPeloCliente = false
): array {
    $texto = chatNormalizarConteudo($conteudo);
    if ($texto === null) throw new InvalidArgumentException('Conteúdo da mensagem do sistema inválido.');
    $nome = mb_substr(trim($nomeExibicao), 0, 150, 'UTF-8');
    if ($nome === '') throw new InvalidArgumentException('Nome exibido da mensagem do sistema inválido.');
    $chave = strtolower(trim($chave));
    if (!chatChaveSistemaValida($chave)) throw new InvalidArgumentException('Chave idempotente do sistema inválida.');

    $conversa = chatConsultar(
        $conexao,
        'SELECT id_conversa, id_cliente, id_ultima_mensagem FROM chat_conversa WHERE id_conversa = ? AND id_empresa = ? LIMIT 1 FOR UPDATE',
        'ii',
        [$idConversa, $idEmpresa]
    )[0] ?? null;
    if ($conversa === null) return ['resultado' => 'conversa_invalida', 'id_mensagem' => null];

    $resultadoExistente = static function (array $existente): array {
        if ((string)$existente['remetente_tipo'] !== 'sistema') {
            // Inalcançável pelos endpoints (versão 0 recusada); nunca sobrescreve mensagem de pessoa.
            throw new RuntimeException('Chave idempotente do sistema já vinculada a outra mensagem.');
        }
        return ['resultado' => 'idempotente', 'id_mensagem' => (int)$existente['id_mensagem']];
    };

    $existente = chatBuscarPorChave($conexao, $idConversa, $chave);
    if ($existente !== null) return $resultadoExistente($existente);

    $perfil = CHAT_SISTEMA_PERFIL;
    $stmt = chatPreparar($conexao,
        "INSERT INTO chat_mensagem
             (id_conversa, remetente_tipo, id_usuario_remetente, id_cliente_remetente, remetente_nome, remetente_perfil, conteudo, chave_idempotencia)
         VALUES (?, 'sistema', NULL, NULL, ?, ?, ?, ?)");
    $stmt->bind_param('issss', $idConversa, $nome, $perfil, $texto, $chave);
    [$inseriu, $erro] = chatExecutarEscrita($stmt);
    $idMensagem = (int)$stmt->insert_id;
    $stmt->close();

    if (!$inseriu) {
        // 1062: a mesma chave foi gravada em paralelo; o evento já está registrado.
        if ($erro === 1062) {
            $existente = chatBuscarPorChave($conexao, $idConversa, $chave);
            if ($existente !== null) return $resultadoExistente($existente);
        }
        throw new RuntimeException('Falha ao gravar mensagem do sistema (errno ' . $erro . ').');
    }

    $stmt = chatPreparar($conexao,
        'UPDATE chat_conversa SET id_ultima_mensagem = ?, ultima_mensagem_em = NOW() WHERE id_conversa = ? AND id_empresa = ?');
    $stmt->bind_param('iii', $idMensagem, $idConversa, $idEmpresa);
    $atualizou = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$atualizou) throw new RuntimeException('Falha ao atualizar a conversa após mensagem do sistema.');

    if ($lidaPeloCliente) {
        $idCliente = (int)$conversa['id_cliente'];
        $ultimaAnterior = (int)($conversa['id_ultima_mensagem'] ?? 0);
        $marcador = (int)(chatConsultar(
            $conexao,
            "SELECT id_ultima_mensagem_lida FROM chat_leitura WHERE id_conversa = ? AND leitor_tipo = 'cliente' AND id_cliente = ? LIMIT 1",
            'ii',
            [$idConversa, $idCliente]
        )[0]['id_ultima_mensagem_lida'] ?? 0);
        if ($ultimaAnterior === 0 || $marcador >= $ultimaAnterior) {
            chatClienteGravarLeitura($conexao, $idConversa, $idCliente, $idMensagem);
        }
    }

    return ['resultado' => 'gravada', 'id_mensagem' => $idMensagem];
}

/**
 * Evento automático "agendamento_confirmado" (uso exclusivo do backend, SEMPRE após o COMMIT
 * da edição do agendamento). Abre a própria transação curta, relê e trava o agendamento
 * (empresa + id) e só grava se o estado ATUAL ainda for "confirmado" com cliente e profissional
 * válidos — não confia no status mantido em memória pelo chamador.
 * - conversa existente é reutilizada; nova conversa usa o usuário autenticado como criador;
 * - mensagem de sistema idempotente (evento + empresa + agendamento + data + hora);
 * - fica NÃO LIDA para o cliente; não conta como não lida para usuários internos.
 * Nunca lança: qualquer falha é registrada em log (sem dados pessoais) e retornada como resultado.
 * Resultados: ['resultado' => 'gravada'|'idempotente'|'ignorado'|'erro', 'motivo' => ?string]
 */
function chatRegistrarEventoAgendamentoConfirmado(mysqli $conexao, int $idEmpresa, int $idAgendamento, int $idUsuarioConfirmou): array
{
    $emTransacao = false;
    try {
        if ($idEmpresa <= 0 || $idAgendamento <= 0 || $idUsuarioConfirmou <= 0) {
            throw new InvalidArgumentException('Parâmetros inválidos para o evento agendamento_confirmado.');
        }

        $conexao->begin_transaction();
        $emTransacao = true;

        $agendamento = chatBuscarAgendamentoParaAtualizar($conexao, $idEmpresa, $idAgendamento);
        if ($agendamento === null) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'agendamento_nao_encontrado'];
        }
        if ((string)$agendamento['status'] !== 'confirmado') {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'status_atual_diferente'];
        }

        $idCliente = (int)$agendamento['id_cliente'];
        $idProfissional = (int)$agendamento['id_profissional'];
        $cliente = $idCliente > 0 ? chatConsultar(
            $conexao,
            'SELECT id_cliente FROM cliente WHERE id_cliente = ? AND id_empresa = ? LIMIT 1',
            'ii',
            [$idCliente, $idEmpresa]
        )[0] ?? null : null;
        $profissional = $idProfissional > 0 ? chatConsultar(
            $conexao,
            'SELECT u.nome
               FROM profissional p
              INNER JOIN usuario u ON u.id_usuario = p.id_usuario
              INNER JOIN empresa_usuario eu ON eu.id_usuario = p.id_usuario AND eu.id_empresa = ?
              WHERE p.id_profissional = ?
              LIMIT 1',
            'ii',
            [$idEmpresa, $idProfissional]
        )[0] ?? null : null;
        $nomeProfissional = trim((string)($profissional['nome'] ?? ''));
        if ($cliente === null || $profissional === null || $nomeProfissional === '') {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'cliente_ou_profissional_invalido'];
        }

        $dataTexto = (string)$agendamento['data_agendamento'];
        $horaTexto = substr((string)$agendamento['hora_inicio'], 0, 5);
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $dataTexto);
        if ($data === false || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaTexto) !== 1) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'data_hora_invalida'];
        }

        $conversa = chatObterOuCriarConversaAgendamento(
            $conexao,
            $idEmpresa,
            $agendamento,
            ['tipo' => 'usuario', 'id_usuario' => $idUsuarioConfirmou]
        );
        if (!in_array($conversa['resultado'], ['criada', 'existente'], true) || empty($conversa['id_conversa'])) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'conversa_' . $conversa['resultado']];
        }
        if ($conversa['resultado'] === 'criada') {
            chatAuditarConversaCriada(
                $conexao,
                (int)$conversa['id_conversa'],
                $idAgendamento,
                'agendamento_confirmado'
            );
        }

        $conteudo = 'Seu agendamento para ' . $data->format('d/m/Y') . ' às ' . $horaTexto
            . ' com ' . $nomeProfissional . ' foi confirmado.';
        $chave = chatChaveIdempotenciaSistema('agendamento_confirmado', $idEmpresa, $idAgendamento, $dataTexto, $horaTexto);
        $gravacao = chatRegistrarMensagemSistema(
            $conexao,
            $idEmpresa,
            (int)$conversa['id_conversa'],
            $conteudo,
            chatNomeExibicaoSistema($conexao, $idEmpresa),
            $chave,
            false // a confirmação deve ficar NÃO LIDA para o cliente
        );
        if (!in_array($gravacao['resultado'], ['gravada', 'idempotente'], true)) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'conversa_invalida'];
        }

        $conexao->commit();
        $emTransacao = false;
        return ['resultado' => (string)$gravacao['resultado'], 'motivo' => null];
    } catch (Throwable $e) {
        if ($emTransacao) {
            try { $conexao->rollback(); } catch (Throwable $ignorado) {}
        }
        error_log('[chat/sistema] evento=agendamento_confirmado empresa=' . $idEmpresa . ' agendamento=' . $idAgendamento
            . ' erro=' . get_class($e) . ': ' . $e->getMessage());
        return ['resultado' => 'erro', 'motivo' => 'excecao'];
    }
}

/**
 * Evento automático "agendamento_atraso" (Fase 5D.4A). Uso exclusivo do backend, SEMPRE após
 * o COMMIT da aplicação do atraso, uma chamada por agendamento realmente deslocado.
 * Transação própria e curta: relê e trava o agendamento e só grava se ele ainda estiver
 * pendente/confirmado exatamente no horário aplicado ($horaAplicada, H:i) — o texto informa
 * sempre o horário efetivamente persistido e o deslocamento real daquele cliente.
 * - conversa existente é reutilizada; nova conversa usa o usuário que informou o atraso;
 * - idempotente por evento + empresa + agendamento + data + novo horário (um novo atraso que
 *   mude o horário de novo gera nova mensagem);
 * - fica NÃO LIDA para o cliente; não conta como não lida para usuários internos.
 * Nunca lança: falha é registrada em log (sem dados pessoais) e devolvida como resultado.
 * Resultados: ['resultado' => 'gravada'|'idempotente'|'ignorado'|'erro', 'motivo' => ?string]
 */
function chatRegistrarEventoAgendamentoAtraso(mysqli $conexao, int $idEmpresa, int $idAgendamento, int $idUsuarioInformou, string $horaAplicada, int $deslocamentoMin): array
{
    $emTransacao = false;
    try {
        $horaAplicada = substr(trim($horaAplicada), 0, 5);
        if ($idEmpresa <= 0 || $idAgendamento <= 0 || $idUsuarioInformou <= 0 || $deslocamentoMin <= 0
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaAplicada) !== 1) {
            throw new InvalidArgumentException('Parâmetros inválidos para o evento agendamento_atraso.');
        }

        $conexao->begin_transaction();
        $emTransacao = true;

        $agendamento = chatBuscarAgendamentoParaAtualizar($conexao, $idEmpresa, $idAgendamento);
        if ($agendamento === null
            || !in_array((string)$agendamento['status'], ['pendente', 'confirmado'], true)
            || substr((string)$agendamento['hora_inicio'], 0, 5) !== $horaAplicada) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'agendamento_alterado'];
        }
        $dataTexto = (string)$agendamento['data_agendamento'];
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $dataTexto) === false) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'data_invalida'];
        }

        $conversa = chatObterOuCriarConversaAgendamento(
            $conexao,
            $idEmpresa,
            $agendamento,
            ['tipo' => 'usuario', 'id_usuario' => $idUsuarioInformou]
        );
        if (!in_array($conversa['resultado'], ['criada', 'existente'], true) || empty($conversa['id_conversa'])) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'conversa_' . $conversa['resultado']];
        }
        if ($conversa['resultado'] === 'criada') {
            chatAuditarConversaCriada(
                $conexao,
                (int)$conversa['id_conversa'],
                $idAgendamento,
                'agendamento_atraso'
            );
        }

        $conteudo = 'Seu atendimento sofreu um atraso estimado de ' . $deslocamentoMin
            . ($deslocamentoMin === 1 ? ' minuto' : ' minutos') . '. Novo horário previsto: ' . $horaAplicada . '.';
        $gravacao = chatRegistrarMensagemSistema(
            $conexao,
            $idEmpresa,
            (int)$conversa['id_conversa'],
            $conteudo,
            chatNomeExibicaoSistema($conexao, $idEmpresa),
            chatChaveIdempotenciaSistema('agendamento_atraso', $idEmpresa, $idAgendamento, $dataTexto, $horaAplicada),
            false // informação nova: deve ficar NÃO LIDA para o cliente
        );
        if (!in_array($gravacao['resultado'], ['gravada', 'idempotente'], true)) {
            $conexao->rollback();
            return ['resultado' => 'ignorado', 'motivo' => 'conversa_invalida'];
        }

        $conexao->commit();
        $emTransacao = false;
        return ['resultado' => (string)$gravacao['resultado'], 'motivo' => null];
    } catch (Throwable $e) {
        if ($emTransacao) {
            try { $conexao->rollback(); } catch (Throwable $ignorado) {}
        }
        error_log('[chat/sistema] evento=agendamento_atraso empresa=' . $idEmpresa . ' agendamento=' . $idAgendamento
            . ' erro=' . get_class($e) . ': ' . $e->getMessage());
        return ['resultado' => 'erro', 'motivo' => 'excecao'];
    }
}
