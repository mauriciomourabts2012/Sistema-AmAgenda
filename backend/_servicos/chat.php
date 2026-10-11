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
 * Auditoria específica do Chat: pendente (auditoria.chk_auditoria_modulo ainda não aceita 'chat').
 */

require_once __DIR__ . '/../_regras/permissoes_usuario.php';

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

    $linhas = chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_profissional, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                c.status, c.criado_por_tipo, c.criado_em, c.id_ultima_mensagem, c.ultima_mensagem_em, c.encerrada_em,
                cl.nome_completo AS cliente_nome, up.nome AS profissional_nome, ue.nome AS encerrada_por_nome,
                l.id_ultima_mensagem_lida
           FROM chat_conversa c
           LEFT JOIN cliente cl ON cl.id_cliente = c.id_cliente AND cl.id_empresa = c.id_empresa
           LEFT JOIN profissional p ON p.id_profissional = c.id_profissional
           LEFT JOIN usuario up ON up.id_usuario = p.id_usuario
           LEFT JOIN usuario ue ON ue.id_usuario = c.encerrada_por_usuario
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
          WHERE c.id_conversa = ? AND {$escopo}
          LIMIT 1",
        'ii' . $tipos,
        array_merge([(int)$ctx['id_usuario'], $idConversa], $params)
    );
    return $linhas[0] ?? null;
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
        $where .= ' AND c.id_ultima_mensagem IS NOT NULL AND c.id_ultima_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0)';
    }

    $offset = ($pagina - 1) * $limite;
    $linhas = chatConsultar(
        $conexao,
        "SELECT c.id_conversa, c.id_profissional, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                c.status, c.id_ultima_mensagem, c.ultima_mensagem_em, c.encerrada_em,
                cl.nome_completo AS cliente_nome, up.nome AS profissional_nome,
                l.id_ultima_mensagem_lida, LEFT(um.conteudo, 140) AS ultima_mensagem_previa
           FROM chat_conversa c
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
           LEFT JOIN cliente cl ON cl.id_cliente = c.id_cliente AND cl.id_empresa = c.id_empresa
           LEFT JOIN profissional p ON p.id_profissional = c.id_profissional
           LEFT JOIN usuario up ON up.id_usuario = p.id_usuario
           LEFT JOIN chat_mensagem um ON um.id_mensagem = c.id_ultima_mensagem AND um.id_conversa = c.id_conversa
          WHERE {$where}
          ORDER BY c.ultima_mensagem_em DESC, c.id_conversa DESC
          LIMIT ? OFFSET ?",
        'i' . $tipos . 'ii',
        array_merge([(int)$ctx['id_usuario']], $params, [$limite + 1, $offset])
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

/** Contadores sem carregar mensagens: compara id_ultima_mensagem com a leitura do próprio usuário. */
function chatContarNaoLidas(mysqli $conexao, array $ctx): array
{
    [$escopo, $tipos, $params] = chatEscopoSql($ctx);
    $status = $ctx['pode_historico'] ? "c.status IN ('aberta','encerrada')" : "c.status = 'aberta'";
    $linhas = chatConsultar(
        $conexao,
        "SELECT
             COUNT(CASE WHEN c.id_ultima_mensagem IS NOT NULL
                         AND c.id_ultima_mensagem > COALESCE(l.id_ultima_mensagem_lida, 0) THEN 1 END) AS conversas_nao_lidas,
             COUNT(CASE WHEN c.status = 'aberta' THEN 1 END) AS conversas_abertas
           FROM chat_conversa c
           LEFT JOIN chat_leitura l ON l.id_conversa = c.id_conversa AND l.leitor_tipo = 'usuario' AND l.id_usuario = ?
          WHERE {$escopo} AND {$status}",
        'i' . $tipos,
        array_merge([(int)$ctx['id_usuario']], $params)
    );
    return [
        'conversas_nao_lidas' => (int)($linhas[0]['conversas_nao_lidas'] ?? 0),
        'conversas_abertas' => (int)($linhas[0]['conversas_abertas'] ?? 0),
    ];
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
    ];
}

/**
 * Mensagens de uma conversa JÁ autorizada, sempre em id_mensagem ASC.
 * after_id: incremental (polling); before_id: páginas anteriores; nenhum: últimas mensagens.
 */
function chatListarMensagens(mysqli $conexao, array $ctx, int $idConversa, ?int $afterId, ?int $beforeId, int $limite): array
{
    $campos = 'id_mensagem, remetente_tipo, id_usuario_remetente, remetente_nome, remetente_perfil, conteudo, criado_em';
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
        'itens' => array_map(static fn(array $m): array => chatFormatarMensagem($m, $ctx), $linhas),
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
        'SELECT id_mensagem, remetente_tipo, id_usuario_remetente, remetente_nome, remetente_perfil, conteudo, criado_em
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
            'SELECT id_mensagem, remetente_tipo, id_usuario_remetente, remetente_nome, remetente_perfil, conteudo, criado_em
               FROM chat_mensagem WHERE id_mensagem = ? LIMIT 1',
            'i', [$idMensagem]);
        $conexao->commit();
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
 * Encerra conversa aberta dentro do escopo. Resultados: 'encerrada'|'ja_encerrada'|'nao_encontrada'.
 */
function chatEncerrarConversa(mysqli $conexao, array $ctx, int $idConversa): string
{
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
    if (!$ok) throw new RuntimeException('Falha ao encerrar a conversa do chat.');
    if ($afetadas === 1) return 'encerrada';

    $conversa = chatBuscarConversaAutorizada($conexao, $ctx, $idConversa);
    return $conversa !== null && (string)$conversa['status'] === 'encerrada' ? 'ja_encerrada' : 'nao_encontrada';
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

    if ($inseriu) return ['resultado' => 'criada', 'id_conversa' => $idConversa];

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
        $stmt->close();
        if (!$ok) throw new RuntimeException('Falha ao encerrar e desvincular a conversa do chat.');
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
    if ($conversa === null || (string)$conversa['status'] !== 'aberta') return;

    $idConversa = (int)$conversa['id_conversa'];
    $stmt = chatPreparar($conexao,
        "UPDATE chat_conversa
            SET status = 'encerrada', encerrada_em = NOW(), encerrada_por_usuario = ?
          WHERE id_conversa = ? AND id_empresa = ? AND id_agendamento = ? AND status = 'aberta'
          LIMIT 1");
    $stmt->bind_param('iiii', $idUsuarioEncerramento, $idConversa, $idEmpresa, $idAgendamento);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new RuntimeException('Falha ao encerrar a conversa antes da exclusão do agendamento.');
}
