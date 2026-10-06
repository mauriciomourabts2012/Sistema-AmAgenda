<?php
declare(strict_types=1);

require_once __DIR__ . '/../_regras/permissoes_usuario.php';

const NOTIFICACAO_CODIGO_MAX = 100;
const NOTIFICACAO_CATEGORIA_MAX = 60;
const NOTIFICACAO_TITULO_MAX = 160;
const NOTIFICACAO_MENSAGEM_MAX_BYTES = 65535;
const NOTIFICACAO_ACAO_CODIGO_MAX = 100;
const NOTIFICACAO_CHAVE_DEDUPLICACAO_MAX = 190;
const NOTIFICACAO_CONTEXTO_MAX_BYTES = 65535;
const NOTIFICACAO_LISTAGEM_LIMITE_MAX = 100;

function notificacaoNormalizarChave(string $chave): string
{
    $chave = mb_strtolower(trim($chave), 'UTF-8');
    $transliterada = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $chave);
    $chave = is_string($transliterada) ? $transliterada : $chave;
    return preg_replace('/[^a-z0-9]+/', '_', $chave) ?? $chave;
}

function notificacaoChaveSensivel(string $chave): bool
{
    $normalizada = notificacaoNormalizarChave($chave);
    $padroes = [
        'senha', 'password', 'passwd', 'hash', 'token', 'cookie', 'session', 'sessao',
        'secret', 'segredo', 'credential', 'credencial', 'authorization',
        'private_key', 'chave_privada',
    ];

    foreach ($padroes as $padrao) {
        if (str_contains($normalizada, $padrao)) return true;
    }

    return false;
}

function notificacaoValidarAusenciaDadosSensiveis(mixed $valor, string $caminho = ''): void
{
    if (!is_array($valor)) return;

    foreach ($valor as $chave => $item) {
        $nome = (string)$chave;
        $atual = $caminho === '' ? $nome : $caminho . '.' . $nome;
        if (!is_int($chave) && notificacaoChaveSensivel($nome)) {
            throw new InvalidArgumentException('Campo sensível não permitido no contexto da notificação: ' . $atual);
        }
        notificacaoValidarAusenciaDadosSensiveis($item, $atual);
    }
}

function notificacaoInteiroPositivo(mixed $valor, string $campo, bool $aceitaNulo = false): ?int
{
    if ($valor === null && $aceitaNulo) return null;
    if (!is_int($valor) || $valor <= 0) {
        throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');
    }
    return $valor;
}

function notificacaoTextoObrigatorio(array $dados, string $campo, int $limite): string
{
    $valor = $dados[$campo] ?? null;
    if (!is_string($valor)) throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');
    $valor = trim($valor);
    if ($valor === '' || mb_strlen($valor, 'UTF-8') > $limite) {
        throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');
    }
    return $valor;
}

function notificacaoTextoOpcional(array $dados, string $campo, int $limite): ?string
{
    if (!array_key_exists($campo, $dados) || $dados[$campo] === null) return null;
    if (!is_string($dados[$campo])) throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');
    $valor = trim($dados[$campo]);
    if ($valor === '') return null;
    if (mb_strlen($valor, 'UTF-8') > $limite) {
        throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');
    }
    return $valor;
}

function notificacaoContextoJson(mixed $contexto): ?string
{
    if ($contexto === null) return null;
    if (!is_array($contexto) && !is_object($contexto)) {
        throw new InvalidArgumentException('Contexto inválido para a notificação.');
    }

    try {
        $json = json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $normalizado = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Contexto inválido para a notificação.');
    }

    notificacaoValidarAusenciaDadosSensiveis($normalizado);
    if (strlen($json) > NOTIFICACAO_CONTEXTO_MAX_BYTES) {
        throw new InvalidArgumentException('Contexto da notificação excede o limite permitido.');
    }
    return $json;
}

function notificacaoDataOpcional(mixed $valor, string $campo): ?string
{
    if ($valor === null || $valor === '') return null;
    if ($valor instanceof DateTimeInterface) return $valor->format('Y-m-d H:i:s');
    if (!is_string($valor)) throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');

    $valor = trim($valor);
    $data = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $valor);
    $erros = DateTimeImmutable::getLastErrors();
    if (!$data || (is_array($erros) && (($erros['warning_count'] ?? 0) > 0 || ($erros['error_count'] ?? 0) > 0))
        || $data->format('Y-m-d H:i:s') !== $valor) {
        throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');
    }
    return $valor;
}

function notificacaoPreparar(mysqli $conexao, string $sql): mysqli_stmt
{
    try {
        $stmt = $conexao->prepare($sql);
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível preparar a operação de notificação.');
    }
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a operação de notificação.');
    return $stmt;
}

function notificacaoDestinatarioTipo(string $tipo): string
{
    $tipo = mb_strtolower(trim($tipo), 'UTF-8');
    if (!in_array($tipo, ['usuario', 'cliente', 'super_admin'], true)) {
        throw new InvalidArgumentException('Tipo de destinatário inválido.');
    }
    return $tipo;
}

function notificacaoValidarEscopoDestinatario(string $destinatarioTipo, ?int $idEmpresa): void
{
    if ($destinatarioTipo === 'cliente' && $idEmpresa === null) {
        throw new InvalidArgumentException('A empresa é obrigatória para notificações de clientes.');
    }
}

function notificacaoValidarDestinatario(
    mysqli $conexao,
    string $destinatarioTipo,
    int $destinatarioId,
    ?int $idEmpresa
): void {
    if ($destinatarioTipo === 'usuario' && $idEmpresa !== null) {
        // O vínculo exato preserva o isolamento mesmo quando uma operação
        // administrativa mantém ou altera o destinatário para inativo/bloqueado.
        $stmt = notificacaoPreparar($conexao, "SELECT 1 FROM usuario u INNER JOIN empresa_usuario eu ON eu.id_usuario=u.id_usuario AND eu.id_empresa=? WHERE u.id_usuario=? LIMIT 1");
        $stmt->bind_param('ii', $idEmpresa, $destinatarioId);
    } elseif ($destinatarioTipo === 'usuario') {
        $stmt = notificacaoPreparar($conexao, 'SELECT 1 FROM usuario WHERE id_usuario=? LIMIT 1');
        $stmt->bind_param('i', $destinatarioId);
    } elseif ($destinatarioTipo === 'cliente') {
        notificacaoValidarEscopoDestinatario($destinatarioTipo, $idEmpresa);
        $stmt = notificacaoPreparar($conexao, 'SELECT 1 FROM cliente WHERE id_cliente=? AND id_empresa=? LIMIT 1');
        $stmt->bind_param('ii', $destinatarioId, $idEmpresa);
    } else {
        $stmt = notificacaoPreparar($conexao, "SELECT 1 FROM usuario WHERE id_usuario=? AND tipo_usuario='super_admin' LIMIT 1");
        $stmt->bind_param('i', $destinatarioId);
    }

    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível validar o destinatário da notificação.');
        $stmt->store_result();
        $valido = $stmt->num_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível validar o destinatário da notificação.');
    } finally {
        $stmt->close();
    }

    if (!$valido) throw new InvalidArgumentException('Destinatário inexistente ou fora do contexto informado.');
}

/**
 * Persiste pela conexão recebida e nunca controla a transação do chamador.
 * Retorna criada=false quando uma repetição idempotente encontra a mesma chave.
 */
function notificacaoCriar(mysqli $conexao, array $dados): array
{
    $destinatarioTipo = notificacaoDestinatarioTipo((string)($dados['destinatario_tipo'] ?? ''));
    $destinatarioId = notificacaoInteiroPositivo($dados['destinatario_id'] ?? null, 'destinatario_id');
    $idEmpresa = notificacaoInteiroPositivo($dados['id_empresa'] ?? null, 'id_empresa', true);

    $origemTipo = mb_strtolower(trim((string)($dados['origem_tipo'] ?? '')), 'UTF-8');
    if (!in_array($origemTipo, ['sistema', 'super_admin', 'usuario'], true)) {
        throw new InvalidArgumentException('Tipo de origem inválido.');
    }
    $origemId = notificacaoInteiroPositivo($dados['origem_id'] ?? null, 'origem_id', true);

    $prioridade = mb_strtolower(trim((string)($dados['prioridade'] ?? '')), 'UTF-8');
    if (!in_array($prioridade, ['baixa', 'normal', 'alta', 'critica'], true)) {
        throw new InvalidArgumentException('Prioridade inválida.');
    }
    if (!array_key_exists('obrigatoria', $dados) || !is_bool($dados['obrigatoria'])) {
        throw new InvalidArgumentException('O campo obrigatoria deve ser booleano.');
    }

    $codigo = notificacaoTextoObrigatorio($dados, 'codigo', NOTIFICACAO_CODIGO_MAX);
    $categoria = notificacaoTextoObrigatorio($dados, 'categoria', NOTIFICACAO_CATEGORIA_MAX);
    $titulo = notificacaoTextoObrigatorio($dados, 'titulo', NOTIFICACAO_TITULO_MAX);
    $mensagem = notificacaoTextoObrigatorio($dados, 'mensagem', NOTIFICACAO_MENSAGEM_MAX_BYTES);
    if (strlen($mensagem) > NOTIFICACAO_MENSAGEM_MAX_BYTES) {
        throw new InvalidArgumentException('A mensagem da notificação excede o limite permitido.');
    }
    $acaoCodigo = notificacaoTextoOpcional($dados, 'acao_codigo', NOTIFICACAO_ACAO_CODIGO_MAX);
    $chaveDeduplicacao = notificacaoTextoOpcional($dados, 'chave_deduplicacao', NOTIFICACAO_CHAVE_DEDUPLICACAO_MAX);
    $contextoJson = notificacaoContextoJson($dados['contexto'] ?? null);
    $prazoEm = notificacaoDataOpcional($dados['prazo_em'] ?? null, 'prazo_em');
    $obrigatoria = $dados['obrigatoria'] ? 1 : 0;

    notificacaoValidarDestinatario($conexao, $destinatarioTipo, $destinatarioId, $idEmpresa);

    $stmt = notificacaoPreparar($conexao, 'INSERT INTO notificacao (id_empresa,destinatario_tipo,destinatario_id,origem_tipo,origem_id,codigo,categoria,titulo,mensagem,prioridade,obrigatoria,acao_codigo,contexto,prazo_em,chave_deduplicacao) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param(
        'isisisssssissss',
        $idEmpresa,
        $destinatarioTipo,
        $destinatarioId,
        $origemTipo,
        $origemId,
        $codigo,
        $categoria,
        $titulo,
        $mensagem,
        $prioridade,
        $obrigatoria,
        $acaoCodigo,
        $contextoJson,
        $prazoEm,
        $chaveDeduplicacao
    );

    $errno = 0;
    try {
        $executou = $stmt->execute();
        $errno = (int)$stmt->errno;
        $idNotificacao = $executou ? (int)$conexao->insert_id : 0;
    } catch (mysqli_sql_exception $e) {
        $executou = false;
        $errno = (int)$e->getCode();
        $idNotificacao = 0;
    } finally {
        $stmt->close();
    }

    if ($executou && $idNotificacao > 0) {
        return ['id_notificacao' => $idNotificacao, 'criada' => true, 'ja_existia' => false];
    }

    if ($errno !== 1062 || $chaveDeduplicacao === null) {
        throw new RuntimeException('Não foi possível criar a notificação.');
    }

    $stmt = notificacaoPreparar($conexao, 'SELECT id_notificacao,id_empresa,destinatario_tipo,destinatario_id,codigo FROM notificacao WHERE chave_deduplicacao=? LIMIT 1');
    $stmt->bind_param('s', $chaveDeduplicacao);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível confirmar a notificação existente.');
        $resultado = $stmt->get_result();
        $existente = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível confirmar a notificação existente.');
    } finally {
        $stmt->close();
    }

    $empresaExistente = $existente === null || $existente['id_empresa'] === null
        ? null
        : (int)$existente['id_empresa'];
    $mesmoContexto = is_array($existente)
        && $empresaExistente === $idEmpresa
        && (string)$existente['destinatario_tipo'] === $destinatarioTipo
        && (int)$existente['destinatario_id'] === $destinatarioId
        && (string)$existente['codigo'] === $codigo;
    if (!$mesmoContexto) {
        throw new RuntimeException('A chave de deduplicação já está vinculada a outro contexto.');
    }

    return ['id_notificacao' => (int)$existente['id_notificacao'], 'criada' => false, 'ja_existia' => true];
}

const NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO = 'agenda.agendamento_pendente';

function notificacaoChaveAgendamentoPendente(int $idEmpresa, int $idAgendamento, int $idUsuario): string
{
    return NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO . ':empresa:' . $idEmpresa
        . ':agendamento:' . $idAgendamento
        . ':usuario:' . $idUsuario;
}

/**
 * Acesso real do destinatário à Agenda, pela mesma regra de permissões do sistema
 * (empresa/usuário/vínculo/perfil ativos, sem bloqueio de plano, agenda.visualizar
 * com as exceções por empresa). Sem acesso, não há pendência operacional ativa.
 */
function notificacaoUsuarioAcessaAgenda(mysqli $conexao, int $idEmpresa, int $idUsuario): bool
{
    return usuarioTemPermissao($conexao, 'agenda.visualizar', ['id_usuario' => $idUsuario, 'id_empresa' => $idEmpresa]);
}

function notificacaoPrefixoAgendamentoPendente(int $idEmpresa, int $idAgendamento): string
{
    return NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO . ':empresa:' . $idEmpresa
        . ':agendamento:' . $idAgendamento
        . ':usuario:';
}

/**
 * Cria ou atualiza a MESMA notificação (mesma chave de deduplicação) de um
 * agendamento pendente. Se ela estava concluída/cancelada, é reativada como
 * não lida; se estava ativa, preserva lida_em e atualiza título, mensagem,
 * prioridade e prazo somente quando algo mudou.
 */
function notificacaoProjetarAgendamentoPendente(
    mysqli $conexao,
    int $idEmpresa,
    int $idAgendamento,
    int $idUsuario,
    string $prazoEm,
    DateTimeImmutable $agoraSaoPaulo
): array {
    $inicio = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $prazoEm, new DateTimeZone('America/Sao_Paulo'));
    if ($inicio === false) {
        throw new InvalidArgumentException('Prazo inválido para a notificação do agendamento.');
    }
    $atrasado = $inicio < $agoraSaoPaulo;
    $titulo = $atrasado ? 'Agendamento pendente atrasado' : 'Agendamento pendente';
    $mensagem = $atrasado
        ? 'Um agendamento pendente ultrapassou o horário de início e precisa de revisão.'
        : 'Um agendamento está pendente e precisa de revisão.';
    $prioridade = 'alta';

    $projecao = notificacaoCriar($conexao, [
        'id_empresa' => $idEmpresa,
        'destinatario_tipo' => 'usuario',
        'destinatario_id' => $idUsuario,
        'origem_tipo' => 'sistema',
        'origem_id' => null,
        'codigo' => NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO,
        'categoria' => 'agenda',
        'titulo' => $titulo,
        'mensagem' => $mensagem,
        'prioridade' => $prioridade,
        'obrigatoria' => false,
        'acao_codigo' => 'agenda.abrir_agendamento',
        'contexto' => ['id_agendamento' => $idAgendamento],
        'prazo_em' => $prazoEm,
        'chave_deduplicacao' => notificacaoChaveAgendamentoPendente($idEmpresa, $idAgendamento, $idUsuario),
    ]);
    if ($projecao['criada']) {
        return $projecao + ['atualizada' => false];
    }

    // lida_em é avaliado antes de limpar concluida_em/cancelada_em (ordem do SET).
    $stmt = notificacaoPreparar($conexao, "UPDATE notificacao
           SET lida_em = IF(concluida_em IS NULL AND cancelada_em IS NULL, lida_em, NULL),
               concluida_em = NULL,
               cancelada_em = NULL,
               titulo = ?,
               mensagem = ?,
               prioridade = ?,
               prazo_em = ?
         WHERE id_notificacao = ?
           AND id_empresa = ?
           AND destinatario_tipo = 'usuario'
           AND destinatario_id = ?
           AND codigo = ?
           AND (concluida_em IS NOT NULL
                OR cancelada_em IS NOT NULL
                OR NOT (titulo <=> ?)
                OR NOT (mensagem <=> ?)
                OR NOT (prioridade <=> ?)
                OR NOT (prazo_em <=> ?))");
    $idNotificacao = (int)$projecao['id_notificacao'];
    $codigo = NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO;
    $stmt->bind_param(
        'ssssiiisssss',
        $titulo,
        $mensagem,
        $prioridade,
        $prazoEm,
        $idNotificacao,
        $idEmpresa,
        $idUsuario,
        $codigo,
        $titulo,
        $mensagem,
        $prioridade,
        $prazoEm
    );
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível atualizar a notificação do agendamento.');
        $atualizada = $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível atualizar a notificação do agendamento.');
    } finally {
        $stmt->close();
    }

    return $projecao + ['atualizada' => $atualizada];
}

/**
 * Notificações ativas (não concluídas e não canceladas) de um agendamento,
 * de qualquer destinatário da mesma empresa.
 */
function notificacaoAtivasAgendamentoPendente(mysqli $conexao, int $idEmpresa, int $idAgendamento): array
{
    $prefixo = addcslashes(notificacaoPrefixoAgendamentoPendente($idEmpresa, $idAgendamento), '\\%_') . '%';
    $codigo = NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO;
    $stmt = notificacaoPreparar($conexao, "SELECT id_notificacao, destinatario_id
          FROM notificacao
         WHERE id_empresa = ?
           AND destinatario_tipo = 'usuario'
           AND codigo = ?
           AND chave_deduplicacao LIKE ?
           AND concluida_em IS NULL
           AND cancelada_em IS NULL
         FOR UPDATE");
    $stmt->bind_param('iss', $idEmpresa, $codigo, $prefixo);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar as notificações do agendamento.');
        $resultado = $stmt->get_result();
        $ativas = [];
        while ($resultado && ($linha = $resultado->fetch_assoc())) {
            $ativas[] = ['id_notificacao' => (int)$linha['id_notificacao'], 'destinatario_id' => (int)$linha['destinatario_id']];
        }
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar as notificações do agendamento.');
    } finally {
        $stmt->close();
    }
    return $ativas;
}

/**
 * Reflete na central o estado atual (fonte da verdade) de um agendamento:
 * - pendente: mantém/reativa/atualiza a notificação do profissional elegível
 *   e cancela a de destinatários que deixaram de ser responsáveis;
 * - outro status: conclui as notificações ativas;
 * - inexistente na empresa: cancela as notificações ativas.
 * Usa a conexão/transação do chamador e lança exceção em qualquer falha.
 */
function notificacaoReconciliarAgendamentoPendente(mysqli $conexao, int $idEmpresa, int $idAgendamento): array
{
    notificacaoInteiroPositivo($idEmpresa, 'id_empresa');
    notificacaoInteiroPositivo($idAgendamento, 'id_agendamento');

    $stmt = notificacaoPreparar($conexao, "SELECT a.status,
                   DATE_FORMAT(a.data_agendamento, '%Y-%m-%d') AS data_agendamento,
                   TIME_FORMAT(a.hora_inicio, '%H:%i:%s') AS hora_inicio,
                   (SELECT u.id_usuario
                      FROM profissional p
                INNER JOIN usuario u
                        ON u.id_usuario = p.id_usuario
                       AND u.status = 'ativo'
                INNER JOIN empresa_usuario eu
                        ON eu.id_usuario = u.id_usuario
                       AND eu.status = 'ativo'
                       AND eu.bloqueado_plano = 0
                INNER JOIN empresa e
                        ON e.id_empresa = eu.id_empresa
                       AND e.status = 'ativo'
                INNER JOIN perfil pf
                        ON pf.id_perfil = eu.id_perfil
                       AND pf.status = 'ativo'
                     WHERE p.id_profissional = a.id_profissional
                       AND eu.id_empresa = a.id_empresa
                       AND LOWER(TRIM(pf.nome)) IN ('profissional', 'profissionais')
                     LIMIT 1) AS id_usuario_destino
              FROM agendamento a
             WHERE a.id_agendamento = ?
               AND a.id_empresa = ?
             LIMIT 1");
    $stmt->bind_param('ii', $idAgendamento, $idEmpresa);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar o agendamento da notificação.');
        $resultado = $stmt->get_result();
        $agendamento = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar o agendamento da notificação.');
    } finally {
        $stmt->close();
    }

    $ativas = notificacaoAtivasAgendamentoPendente($conexao, $idEmpresa, $idAgendamento);
    $retorno = ['concluidas' => 0, 'canceladas' => 0, 'projetada' => false];

    if ($agendamento === null) {
        foreach ($ativas as $ativa) {
            if (notificacaoCancelar($conexao, $ativa['id_notificacao'], 'usuario', $ativa['destinatario_id'], $idEmpresa)['alterada']) {
                $retorno['canceladas']++;
            }
        }
        return $retorno;
    }

    if ((string)$agendamento['status'] !== 'pendente') {
        foreach ($ativas as $ativa) {
            if (notificacaoConcluir($conexao, $ativa['id_notificacao'], 'usuario', $ativa['destinatario_id'], $idEmpresa)['alterada']) {
                $retorno['concluidas']++;
            }
        }
        return $retorno;
    }

    $idUsuarioDestino = (int)($agendamento['id_usuario_destino'] ?? 0);
    if ($idUsuarioDestino > 0 && !notificacaoUsuarioAcessaAgenda($conexao, $idEmpresa, $idUsuarioDestino)) {
        $idUsuarioDestino = 0;
    }
    foreach ($ativas as $ativa) {
        if ($ativa['destinatario_id'] === $idUsuarioDestino) continue;
        if (notificacaoCancelar($conexao, $ativa['id_notificacao'], 'usuario', $ativa['destinatario_id'], $idEmpresa)['alterada']) {
            $retorno['canceladas']++;
        }
    }

    if ($idUsuarioDestino > 0) {
        notificacaoProjetarAgendamentoPendente(
            $conexao,
            $idEmpresa,
            $idAgendamento,
            $idUsuarioDestino,
            (string)$agendamento['data_agendamento'] . ' ' . (string)$agendamento['hora_inicio'],
            new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'))
        );
        $retorno['projetada'] = true;
    }

    return $retorno;
}

/**
 * Projeta os agendamentos pendentes do profissional autenticado na
 * central existente. O contexto deve ter sido obtido por permissoesContexto().
 */
function notificacaoSincronizarAgendamentosPendentesProfissional(
    mysqli $conexao,
    array $contextoAutorizado
): array {
    if (!($contextoAutorizado['valido'] ?? false)
        || ($contextoAutorizado['super_admin_suporte'] ?? false)
        || ($contextoAutorizado['perfil'] ?? '') !== 'profissional') {
        return ['elegiveis' => 0, 'criadas' => 0, 'existentes' => 0];
    }

    $idEmpresa = (int)($contextoAutorizado['id_empresa'] ?? 0);
    $idUsuario = (int)($contextoAutorizado['id_usuario'] ?? 0);
    $idProfissional = (int)($contextoAutorizado['id_profissional'] ?? 0);
    if ($idEmpresa <= 0 || $idUsuario <= 0 || $idProfissional <= 0) {
        throw new InvalidArgumentException('Contexto profissional inválido para sincronizar notificações.');
    }

    $agoraSaoPaulo = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    // Sem acesso à Agenda: nada é projetado e as ativas são reconciliadas (canceladas) abaixo.
    $acessaAgenda = notificacaoUsuarioAcessaAgenda($conexao, $idEmpresa, $idUsuario);
    $sql = "SELECT a.id_agendamento,
                   DATE_FORMAT(a.data_agendamento, '%Y-%m-%d') AS data_agendamento,
                   TIME_FORMAT(a.hora_inicio, '%H:%i:%s') AS hora_inicio
              FROM agendamento a
        INNER JOIN profissional p
                ON p.id_profissional = a.id_profissional
               AND p.id_usuario = ?
        INNER JOIN usuario u
                ON u.id_usuario = p.id_usuario
               AND u.status = 'ativo'
        INNER JOIN empresa e
                ON e.id_empresa = a.id_empresa
               AND e.status = 'ativo'
        INNER JOIN empresa_usuario eu
                ON eu.id_empresa = a.id_empresa
               AND eu.id_usuario = u.id_usuario
               AND eu.status = 'ativo'
               AND eu.bloqueado_plano = 0
        INNER JOIN perfil pf
                ON pf.id_perfil = eu.id_perfil
               AND pf.status = 'ativo'
             WHERE a.id_empresa = ?
               AND a.id_profissional = ?
               AND a.status = 'pendente'
               AND LOWER(TRIM(pf.nome)) IN ('profissional', 'profissionais')
          ORDER BY a.data_agendamento ASC, a.hora_inicio ASC, a.id_agendamento ASC";
    $agendamentos = [];
    if ($acessaAgenda) {
        $stmt = notificacaoPreparar($conexao, $sql);
        $stmt->bind_param('iii', $idUsuario, $idEmpresa, $idProfissional);

        try {
            if (!$stmt->execute()) {
                throw new RuntimeException('Não foi possível identificar os agendamentos pendentes.');
            }
            $resultado = $stmt->get_result();
            while ($resultado && ($linha = $resultado->fetch_assoc())) {
                $agendamentos[] = [
                    'id_agendamento' => (int)$linha['id_agendamento'],
                    'prazo_em' => (string)$linha['data_agendamento'] . ' ' . (string)$linha['hora_inicio'],
                ];
            }
        } catch (mysqli_sql_exception) {
            throw new RuntimeException('Não foi possível identificar os agendamentos pendentes.');
        } finally {
            $stmt->close();
        }
    }

    $criadas = 0;
    $existentes = 0;
    $idsPendentes = [];
    foreach ($agendamentos as $agendamento) {
        $idsPendentes[$agendamento['id_agendamento']] = true;
        // Reativa/atualiza a mesma linha e promove para atrasado quando o horário passou.
        $projecao = notificacaoProjetarAgendamentoPendente(
            $conexao,
            $idEmpresa,
            $agendamento['id_agendamento'],
            $idUsuario,
            $agendamento['prazo_em'],
            $agoraSaoPaulo
        );
        if ($projecao['criada']) {
            $criadas++;
        } else {
            $existentes++;
        }
    }

    // Notificações ativas deste usuário cujo agendamento não está mais pendente
    // para ele (alterado por outro fluxo): reconcilia com o estado real.
    $codigo = NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO;
    $stmt = notificacaoPreparar($conexao, "SELECT chave_deduplicacao
          FROM notificacao
         WHERE id_empresa = ?
           AND destinatario_tipo = 'usuario'
           AND destinatario_id = ?
           AND codigo = ?
           AND concluida_em IS NULL
           AND cancelada_em IS NULL");
    $stmt->bind_param('iis', $idEmpresa, $idUsuario, $codigo);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível conferir as notificações pendentes.');
        $resultado = $stmt->get_result();
        $orfas = [];
        $padrao = '/^' . preg_quote(NOTIFICACAO_AGENDAMENTO_PENDENTE_CODIGO, '/') . ':empresa:' . $idEmpresa . ':agendamento:(\d+):usuario:' . $idUsuario . '$/';
        while ($resultado && ($linha = $resultado->fetch_assoc())) {
            if (preg_match($padrao, (string)$linha['chave_deduplicacao'], $m) !== 1) continue;
            $idAgendamento = (int)$m[1];
            if ($idAgendamento > 0 && !isset($idsPendentes[$idAgendamento])) {
                $orfas[$idAgendamento] = true;
            }
        }
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível conferir as notificações pendentes.');
    } finally {
        $stmt->close();
    }

    foreach (array_keys($orfas) as $idAgendamento) {
        notificacaoReconciliarAgendamentoPendente($conexao, $idEmpresa, (int)$idAgendamento);
    }

    return ['elegiveis' => count($agendamentos), 'criadas' => $criadas, 'existentes' => $existentes];
}

function notificacaoListarPendentes(
    mysqli $conexao,
    string $destinatarioTipo,
    int $destinatarioId,
    ?int $idEmpresa,
    int $limite = 20
): array {
    $destinatarioTipo = notificacaoDestinatarioTipo($destinatarioTipo);
    notificacaoInteiroPositivo($destinatarioId, 'destinatario_id');
    notificacaoInteiroPositivo($idEmpresa, 'id_empresa', true);
    notificacaoValidarEscopoDestinatario($destinatarioTipo, $idEmpresa);
    if ($limite <= 0 || $limite > NOTIFICACAO_LISTAGEM_LIMITE_MAX) {
        throw new InvalidArgumentException('Limite inválido para a listagem de notificações.');
    }

    $campos = 'id_notificacao,id_empresa,destinatario_tipo,destinatario_id,origem_tipo,origem_id,codigo,categoria,titulo,mensagem,prioridade,obrigatoria,acao_codigo,contexto,prazo_em,lida_em,concluida_em,cancelada_em,criado_em,atualizado_em';
    if ($idEmpresa === null) {
        $stmt = notificacaoPreparar($conexao, "SELECT {$campos} FROM notificacao WHERE destinatario_tipo=? AND destinatario_id=? AND id_empresa IS NULL AND concluida_em IS NULL AND cancelada_em IS NULL ORDER BY CASE prioridade WHEN 'critica' THEN 4 WHEN 'alta' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END DESC,criado_em DESC LIMIT ?");
        $stmt->bind_param('sii', $destinatarioTipo, $destinatarioId, $limite);
    } else {
        $stmt = notificacaoPreparar($conexao, "SELECT {$campos} FROM notificacao WHERE destinatario_tipo=? AND destinatario_id=? AND id_empresa=? AND concluida_em IS NULL AND cancelada_em IS NULL ORDER BY CASE prioridade WHEN 'critica' THEN 4 WHEN 'alta' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END DESC,criado_em DESC LIMIT ?");
        $stmt->bind_param('siii', $destinatarioTipo, $destinatarioId, $idEmpresa, $limite);
    }

    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível listar as notificações.');
        $resultado = $stmt->get_result();
        $itens = [];
        while ($resultado && ($linha = $resultado->fetch_assoc())) {
            $linha['id_notificacao'] = (int)$linha['id_notificacao'];
            $linha['id_empresa'] = $linha['id_empresa'] === null ? null : (int)$linha['id_empresa'];
            $linha['destinatario_id'] = (int)$linha['destinatario_id'];
            $linha['origem_id'] = $linha['origem_id'] === null ? null : (int)$linha['origem_id'];
            $linha['obrigatoria'] = (bool)$linha['obrigatoria'];
            $linha['contexto'] = $linha['contexto'] === null
                ? null
                : json_decode((string)$linha['contexto'], true, 32, JSON_THROW_ON_ERROR);
            $itens[] = $linha;
        }
    } catch (JsonException|mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível listar as notificações.');
    } finally {
        $stmt->close();
    }

    return $itens;
}

function notificacaoAtualizarEstado(
    mysqli $conexao,
    int $idNotificacao,
    string $destinatarioTipo,
    int $destinatarioId,
    ?int $idEmpresa,
    string $estado
): array {
    notificacaoInteiroPositivo($idNotificacao, 'id_notificacao');
    $destinatarioTipo = notificacaoDestinatarioTipo($destinatarioTipo);
    notificacaoInteiroPositivo($destinatarioId, 'destinatario_id');
    notificacaoInteiroPositivo($idEmpresa, 'id_empresa', true);
    notificacaoValidarEscopoDestinatario($destinatarioTipo, $idEmpresa);

    [$campo, $restricao] = match ($estado) {
        'lida' => ['lida_em', ''],
        'concluida' => ['concluida_em', ' AND cancelada_em IS NULL'],
        'cancelada' => ['cancelada_em', ' AND concluida_em IS NULL'],
        default => throw new InvalidArgumentException('Estado de notificação inválido.'),
    };

    if ($idEmpresa === null) {
        $stmt = notificacaoPreparar($conexao, "UPDATE notificacao SET {$campo}=CURRENT_TIMESTAMP WHERE id_notificacao=? AND destinatario_tipo=? AND destinatario_id=? AND id_empresa IS NULL AND {$campo} IS NULL{$restricao}");
        $stmt->bind_param('isi', $idNotificacao, $destinatarioTipo, $destinatarioId);
    } else {
        $stmt = notificacaoPreparar($conexao, "UPDATE notificacao SET {$campo}=CURRENT_TIMESTAMP WHERE id_notificacao=? AND destinatario_tipo=? AND destinatario_id=? AND id_empresa=? AND {$campo} IS NULL{$restricao}");
        $stmt->bind_param('isii', $idNotificacao, $destinatarioTipo, $destinatarioId, $idEmpresa);
    }

    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível atualizar a notificação.');
        $alterada = $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível atualizar a notificação.');
    } finally {
        $stmt->close();
    }

    if ($alterada) return ['encontrada' => true, 'alterada' => true];

    if ($idEmpresa === null) {
        $stmt = notificacaoPreparar($conexao, 'SELECT 1 FROM notificacao WHERE id_notificacao=? AND destinatario_tipo=? AND destinatario_id=? AND id_empresa IS NULL LIMIT 1');
        $stmt->bind_param('isi', $idNotificacao, $destinatarioTipo, $destinatarioId);
    } else {
        $stmt = notificacaoPreparar($conexao, 'SELECT 1 FROM notificacao WHERE id_notificacao=? AND destinatario_tipo=? AND destinatario_id=? AND id_empresa=? LIMIT 1');
        $stmt->bind_param('isii', $idNotificacao, $destinatarioTipo, $destinatarioId, $idEmpresa);
    }

    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível confirmar a notificação.');
        $stmt->store_result();
        $encontrada = $stmt->num_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível confirmar a notificação.');
    } finally {
        $stmt->close();
    }

    return ['encontrada' => $encontrada, 'alterada' => false];
}

function notificacaoMarcarComoLida(
    mysqli $conexao,
    int $idNotificacao,
    string $destinatarioTipo,
    int $destinatarioId,
    ?int $idEmpresa
): array {
    return notificacaoAtualizarEstado($conexao, $idNotificacao, $destinatarioTipo, $destinatarioId, $idEmpresa, 'lida');
}

function notificacaoConcluir(
    mysqli $conexao,
    int $idNotificacao,
    string $destinatarioTipo,
    int $destinatarioId,
    ?int $idEmpresa
): array {
    return notificacaoAtualizarEstado($conexao, $idNotificacao, $destinatarioTipo, $destinatarioId, $idEmpresa, 'concluida');
}

function notificacaoCancelar(
    mysqli $conexao,
    int $idNotificacao,
    string $destinatarioTipo,
    int $destinatarioId,
    ?int $idEmpresa
): array {
    return notificacaoAtualizarEstado($conexao, $idNotificacao, $destinatarioTipo, $destinatarioId, $idEmpresa, 'cancelada');
}
