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

const NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO = 'agenda.agendamento_finalizacao_pendente';
const NOTIFICACAO_AGENDAMENTO_FINALIZACAO_JANELA_DIAS = 7;
const NOTIFICACAO_AGENDAMENTO_FINALIZACAO_LIMITE_NOVAS = 20;

function notificacaoPodeCriarNovaFinalizacao(int $criadas): bool
{
    return $criadas < NOTIFICACAO_AGENDAMENTO_FINALIZACAO_LIMITE_NOVAS;
}

function notificacaoPodeReativarFinalizacao(array $registro): bool
{
    return ($registro['concluida_em'] ?? null) === null
        && ($registro['cancelada_em'] ?? null) !== null;
}

function notificacaoFinalizacaoDeveConcluirRegistro(array $registro): bool
{
    return ($registro['concluida_em'] ?? null) === null;
}

function notificacaoChaveAgendamentoFinalizacao(int $idEmpresa, int $idAgendamento, int $idUsuario): string
{
    return NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO . ':empresa:' . $idEmpresa
        . ':agendamento:' . $idAgendamento
        . ':usuario:' . $idUsuario;
}

function notificacaoPrefixoAgendamentoFinalizacao(int $idEmpresa, int $idAgendamento): string
{
    return NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO . ':empresa:' . $idEmpresa
        . ':agendamento:' . $idAgendamento
        . ':usuario:';
}

/**
 * Regra temporal pura do fluxo de finalização. A janela limita somente a
 * criação de uma nova linha; uma notificação existente continua reconciliada.
 */
function notificacaoAvaliarAgendamentoFinalizacao(array $agendamento, DateTimeImmutable $agoraSaoPaulo): array
{
    $status = (string)($agendamento['status'] ?? '');
    if (in_array($status, ['concluido', 'cancelado', 'faltou'], true)) {
        return ['acao' => 'concluir', 'permite_nova' => false, 'prazo_em' => null];
    }
    if ($status !== 'confirmado') {
        return ['acao' => 'cancelar', 'permite_nova' => false, 'prazo_em' => null];
    }

    $data = (string)($agendamento['data_agendamento'] ?? '');
    $horaFim = (string)($agendamento['hora_fim'] ?? '');
    $prazo = $data . ' ' . $horaFim;
    $fim = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $prazo,
        new DateTimeZone('America/Sao_Paulo')
    );
    $erros = DateTimeImmutable::getLastErrors();
    if (!$fim
        || (is_array($erros) && (($erros['warning_count'] ?? 0) > 0 || ($erros['error_count'] ?? 0) > 0))
        || $fim->format('Y-m-d H:i:s') !== $prazo
        || $fim >= $agoraSaoPaulo) {
        return ['acao' => 'cancelar', 'permite_nova' => false, 'prazo_em' => null];
    }

    $inicioJanela = $agoraSaoPaulo->modify('-' . NOTIFICACAO_AGENDAMENTO_FINALIZACAO_JANELA_DIAS . ' days');
    return [
        'acao' => 'manter',
        'permite_nova' => $fim >= $inicioJanela,
        'prazo_em' => $fim->format('Y-m-d H:i:s'),
    ];
}

function notificacaoRegistrosAgendamentoFinalizacao(mysqli $conexao, int $idEmpresa, int $idAgendamento): array
{
    $prefixo = addcslashes(notificacaoPrefixoAgendamentoFinalizacao($idEmpresa, $idAgendamento), '\\%_') . '%';
    $codigo = NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO;
    $stmt = notificacaoPreparar($conexao, "SELECT id_notificacao,destinatario_id,chave_deduplicacao,lida_em,concluida_em,cancelada_em
          FROM notificacao
         WHERE id_empresa = ?
           AND destinatario_tipo = 'usuario'
           AND codigo = ?
           AND chave_deduplicacao LIKE ?
         FOR UPDATE");
    $stmt->bind_param('iss', $idEmpresa, $codigo, $prefixo);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar as notificações de finalização.');
        $resultado = $stmt->get_result();
        $registros = [];
        while ($resultado && ($linha = $resultado->fetch_assoc())) {
            $registros[] = [
                'id_notificacao' => (int)$linha['id_notificacao'],
                'destinatario_id' => (int)$linha['destinatario_id'],
                'chave_deduplicacao' => (string)$linha['chave_deduplicacao'],
                'lida_em' => $linha['lida_em'],
                'concluida_em' => $linha['concluida_em'],
                'cancelada_em' => $linha['cancelada_em'],
            ];
        }
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar as notificações de finalização.');
    } finally {
        $stmt->close();
    }
    return $registros;
}

/** Estados finais prevalecem inclusive sobre um cancelamento temporário anterior. */
function notificacaoConcluirRegistroAgendamentoFinalizacao(
    mysqli $conexao,
    int $idNotificacao,
    int $idEmpresa,
    int $idUsuario
): bool {
    $codigo = NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO;
    $stmt = notificacaoPreparar($conexao, "UPDATE notificacao
           SET concluida_em=CURRENT_TIMESTAMP,cancelada_em=NULL
         WHERE id_notificacao=? AND id_empresa=?
           AND destinatario_tipo='usuario' AND destinatario_id=? AND codigo=?
           AND concluida_em IS NULL");
    $stmt->bind_param('iiis', $idNotificacao, $idEmpresa, $idUsuario, $codigo);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível concluir a notificação de finalização.');
        return $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível concluir a notificação de finalização.');
    } finally {
        $stmt->close();
    }
}

function notificacaoProjetarAgendamentoFinalizacao(
    mysqli $conexao,
    int $idEmpresa,
    int $idAgendamento,
    int $idUsuario,
    string $prazoEm,
    bool $permitirNova,
    array $registros
): array {
    $chave = notificacaoChaveAgendamentoFinalizacao($idEmpresa, $idAgendamento, $idUsuario);
    $existente = null;
    foreach ($registros as $registro) {
        if ((string)$registro['chave_deduplicacao'] === $chave) {
            $existente = $registro;
            break;
        }
    }

    $titulo = 'Agendamento aguardando finalização';
    $mensagem = 'O horário previsto do atendimento terminou. Registre o resultado do agendamento.';
    if ($existente !== null) {
        // Estados finais são definitivos para esta chave; somente canceladas podem
        // voltar quando o mesmo agendamento torna-se legitimamente elegível.
        if ($existente['concluida_em'] !== null) {
            return ['criada' => false, 'reativada' => false, 'existente_concluida' => true];
        }

        $stmt = notificacaoPreparar($conexao, "UPDATE notificacao
               SET lida_em = IF(cancelada_em IS NULL, lida_em, NULL),
                   cancelada_em = NULL,
                   titulo = ?,
                   mensagem = ?,
                   prioridade = 'normal',
                   acao_codigo = 'agenda.abrir_agendamento',
                   contexto = ?,
                   prazo_em = ?
             WHERE id_notificacao = ?
               AND id_empresa = ?
               AND destinatario_tipo = 'usuario'
               AND destinatario_id = ?
               AND codigo = ?
               AND concluida_em IS NULL");
        $contexto = notificacaoContextoJson(['id_agendamento' => $idAgendamento]);
        $idNotificacao = (int)$existente['id_notificacao'];
        $codigo = NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO;
        $stmt->bind_param('ssssiiis', $titulo, $mensagem, $contexto, $prazoEm, $idNotificacao, $idEmpresa, $idUsuario, $codigo);
        try {
            if (!$stmt->execute()) throw new RuntimeException('Não foi possível atualizar a notificação de finalização.');
            $alterada = $stmt->affected_rows === 1;
        } catch (mysqli_sql_exception) {
            throw new RuntimeException('Não foi possível atualizar a notificação de finalização.');
        } finally {
            $stmt->close();
        }
        return [
            'criada' => false,
            'reativada' => notificacaoPodeReativarFinalizacao($existente) && $alterada,
            'existente_concluida' => false,
        ];
    }

    if (!$permitirNova) {
        return ['criada' => false, 'reativada' => false, 'existente_concluida' => false, 'limitada' => true];
    }

    $projecao = notificacaoCriar($conexao, [
        'id_empresa' => $idEmpresa,
        'destinatario_tipo' => 'usuario',
        'destinatario_id' => $idUsuario,
        'origem_tipo' => 'sistema',
        'origem_id' => null,
        'codigo' => NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO,
        'categoria' => 'agenda',
        'titulo' => $titulo,
        'mensagem' => $mensagem,
        'prioridade' => 'normal',
        'obrigatoria' => false,
        'acao_codigo' => 'agenda.abrir_agendamento',
        'contexto' => ['id_agendamento' => $idAgendamento],
        'prazo_em' => $prazoEm,
        'chave_deduplicacao' => $chave,
    ]);
    return [
        'criada' => (bool)$projecao['criada'],
        'reativada' => false,
        'existente_concluida' => false,
    ];
}

/**
 * Reconcilia uma ocorrência usando a transação do chamador. A ordem de lock é
 * agendamento -> notificações, igual aos fluxos de edição, exclusão e atraso.
 */
function notificacaoReconciliarAgendamentoFinalizacao(
    mysqli $conexao,
    int $idEmpresa,
    int $idAgendamento,
    bool $permitirNova = true,
    ?DateTimeImmutable $agoraSaoPaulo = null
): array {
    notificacaoInteiroPositivo($idEmpresa, 'id_empresa');
    notificacaoInteiroPositivo($idAgendamento, 'id_agendamento');
    $agoraSaoPaulo ??= new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    $stmt = notificacaoPreparar($conexao, "SELECT a.status,
                   DATE_FORMAT(a.data_agendamento, '%Y-%m-%d') AS data_agendamento,
                   TIME_FORMAT(a.hora_fim, '%H:%i:%s') AS hora_fim,
                   (SELECT u.id_usuario
                      FROM profissional p
                INNER JOIN usuario u ON u.id_usuario=p.id_usuario AND u.status='ativo'
                INNER JOIN empresa_usuario eu ON eu.id_usuario=u.id_usuario AND eu.id_empresa=a.id_empresa
                                             AND eu.status='ativo' AND eu.bloqueado_plano=0
                INNER JOIN empresa e ON e.id_empresa=eu.id_empresa AND e.status='ativo'
                INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil AND pf.status='ativo'
                     WHERE p.id_profissional=a.id_profissional
                       AND LOWER(TRIM(pf.nome)) IN ('profissional','profissionais')
                     LIMIT 1) AS id_usuario_destino
              FROM agendamento a
             WHERE a.id_agendamento=? AND a.id_empresa=?
             LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ii', $idAgendamento, $idEmpresa);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar o agendamento para finalização.');
        $resultado = $stmt->get_result();
        $agendamento = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar o agendamento para finalização.');
    } finally {
        $stmt->close();
    }

    $registros = notificacaoRegistrosAgendamentoFinalizacao($conexao, $idEmpresa, $idAgendamento);
    $retorno = ['criadas'=>0,'reativadas'=>0,'concluidas'=>0,'canceladas'=>0,'limitadas'=>0];
    $atualizarAtivas = static function (string $acao) use ($conexao, $idEmpresa, $registros, &$retorno): void {
        foreach ($registros as $registro) {
            if ($acao === 'concluir') {
                if (notificacaoFinalizacaoDeveConcluirRegistro($registro)
                    && notificacaoConcluirRegistroAgendamentoFinalizacao(
                        $conexao,
                        $registro['id_notificacao'],
                        $idEmpresa,
                        $registro['destinatario_id']
                    )) {
                    $retorno['concluidas']++;
                }
                continue;
            }
            if ($registro['concluida_em'] !== null || $registro['cancelada_em'] !== null) continue;
            if (notificacaoCancelar($conexao, $registro['id_notificacao'], 'usuario', $registro['destinatario_id'], $idEmpresa)['alterada']) {
                $retorno['canceladas']++;
            }
        }
    };

    if ($agendamento === null) {
        $atualizarAtivas('cancelar');
        return $retorno;
    }

    $avaliacao = notificacaoAvaliarAgendamentoFinalizacao($agendamento, $agoraSaoPaulo);
    if ($avaliacao['acao'] === 'concluir') {
        $atualizarAtivas('concluir');
        return $retorno;
    }
    if ($avaliacao['acao'] === 'cancelar') {
        $atualizarAtivas('cancelar');
        return $retorno;
    }

    $idUsuarioDestino = (int)($agendamento['id_usuario_destino'] ?? 0);
    $destinatarioAutorizado = $idUsuarioDestino > 0
        && notificacaoUsuarioAcessaAgenda($conexao, $idEmpresa, $idUsuarioDestino)
        && usuarioTemPermissao($conexao, 'agenda.editar_agendamento', ['id_usuario'=>$idUsuarioDestino,'id_empresa'=>$idEmpresa]);
    if (!$destinatarioAutorizado) {
        $atualizarAtivas('cancelar');
        return $retorno;
    }

    foreach ($registros as $registro) {
        if ($registro['destinatario_id'] === $idUsuarioDestino
            || $registro['concluida_em'] !== null
            || $registro['cancelada_em'] !== null) continue;
        if (notificacaoCancelar($conexao, $registro['id_notificacao'], 'usuario', $registro['destinatario_id'], $idEmpresa)['alterada']) {
            $retorno['canceladas']++;
        }
    }

    $projecao = notificacaoProjetarAgendamentoFinalizacao(
        $conexao,
        $idEmpresa,
        $idAgendamento,
        $idUsuarioDestino,
        (string)$avaliacao['prazo_em'],
        $permitirNova && (bool)$avaliacao['permite_nova'],
        $registros
    );
    if ($projecao['criada']) $retorno['criadas']++;
    if ($projecao['reativada']) $retorno['reativadas']++;
    if (($projecao['limitada'] ?? false) === true) $retorno['limitadas']++;
    return $retorno;
}

/** Sincronização sob demanda, sempre limitada à empresa e ao profissional autenticado. */
function notificacaoSincronizarAgendamentosFinalizacaoProfissional(mysqli $conexao, array $contextoAutorizado): array
{
    if (!($contextoAutorizado['valido'] ?? false)
        || ($contextoAutorizado['super_admin_suporte'] ?? false)
        || ($contextoAutorizado['perfil'] ?? '') !== 'profissional') {
        return ['reconciliadas'=>0,'criadas'=>0,'reativadas'=>0,'limitadas'=>0];
    }

    $idEmpresa = (int)($contextoAutorizado['id_empresa'] ?? 0);
    $idUsuario = (int)($contextoAutorizado['id_usuario'] ?? 0);
    $idProfissional = (int)($contextoAutorizado['id_profissional'] ?? 0);
    if ($idEmpresa <= 0 || $idUsuario <= 0 || $idProfissional <= 0) {
        throw new InvalidArgumentException('Contexto profissional inválido para sincronizar finalizações.');
    }

    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $podeOperar = notificacaoUsuarioAcessaAgenda($conexao, $idEmpresa, $idUsuario)
        && usuarioTemPermissao($conexao, 'agenda.editar_agendamento', ['id_usuario'=>$idUsuario,'id_empresa'=>$idEmpresa]);
    $codigo = NOTIFICACAO_AGENDAMENTO_FINALIZACAO_CODIGO;
    $padrao = '/^' . preg_quote($codigo, '/') . ':empresa:' . $idEmpresa . ':agendamento:(\d+):usuario:' . $idUsuario . '$/';

    $conexao->begin_transaction();
    try {
        // Ativas antigas não dependem da janela de criação e sempre são reconciliadas.
        $stmt = notificacaoPreparar($conexao, "SELECT chave_deduplicacao
              FROM notificacao
             WHERE id_empresa=? AND destinatario_tipo='usuario' AND destinatario_id=? AND codigo=?
               AND concluida_em IS NULL AND cancelada_em IS NULL");
        $stmt->bind_param('iis', $idEmpresa, $idUsuario, $codigo);
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar finalizações existentes.');
        $resultado = $stmt->get_result();
        $ids = [];
        while ($resultado && ($linha = $resultado->fetch_assoc())) {
            if (preg_match($padrao, (string)$linha['chave_deduplicacao'], $m) === 1) $ids[(int)$m[1]] = true;
        }
        $stmt->close();

        if ($podeOperar) {
            $inicioJanela = $agora->modify('-' . NOTIFICACAO_AGENDAMENTO_FINALIZACAO_JANELA_DIAS . ' days')->format('Y-m-d H:i:s');
            $agoraSql = $agora->format('Y-m-d H:i:s');
            $stmt = notificacaoPreparar($conexao, "SELECT a.id_agendamento
                  FROM agendamento a
            INNER JOIN profissional p ON p.id_profissional=a.id_profissional AND p.id_usuario=?
                 WHERE a.id_empresa=? AND a.id_profissional=? AND a.status='confirmado'
                   AND TIMESTAMP(a.data_agendamento,a.hora_fim) >= ?
                   AND TIMESTAMP(a.data_agendamento,a.hora_fim) < ?
              ORDER BY a.data_agendamento DESC,a.hora_fim DESC,a.id_agendamento DESC");
            $stmt->bind_param('iiiss', $idUsuario, $idEmpresa, $idProfissional, $inicioJanela, $agoraSql);
            if (!$stmt->execute()) throw new RuntimeException('Não foi possível identificar agendamentos aguardando finalização.');
            $resultado = $stmt->get_result();
            while ($resultado && ($linha = $resultado->fetch_assoc())) $ids[(int)$linha['id_agendamento']] = true;
            $stmt->close();
        }

        $idsOrdenados = array_keys($ids);
        sort($idsOrdenados, SORT_NUMERIC);
        $resumo = ['reconciliadas'=>0,'criadas'=>0,'reativadas'=>0,'limitadas'=>0];
        foreach ($idsOrdenados as $idAgendamento) {
            $resultado = notificacaoReconciliarAgendamentoFinalizacao(
                $conexao,
                $idEmpresa,
                (int)$idAgendamento,
                notificacaoPodeCriarNovaFinalizacao($resumo['criadas']),
                $agora
            );
            $resumo['reconciliadas']++;
            $resumo['criadas'] += (int)$resultado['criadas'];
            $resumo['reativadas'] += (int)$resultado['reativadas'];
            $resumo['limitadas'] += (int)$resultado['limitadas'];
        }
        $conexao->commit();
        return $resumo;
    } catch (Throwable $e) {
        try { $conexao->rollback(); } catch (Throwable) {}
        throw $e;
    }
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

/* ==========================================================
   CHAT — projeção de conversas não lidas na central existente
   ----------------------------------------------------------
   Uma notificação por conversa + destinatário (chave determinística),
   reativada quando chega nova mensagem e concluída quando a conversa
   é lida. A projeção roda sob demanda em notificacoes/listar, fora da
   transação de envio do Chat (falha aqui nunca afeta o envio).
   ========================================================== */
const NOTIFICACAO_CHAT_CODIGO = 'chat.conversa_nao_lida';
const NOTIFICACAO_CHAT_ACAO = 'chat.abrir_conversa';
const NOTIFICACAO_CHAT_LIMITE = 100;

function notificacaoChaveChatConversa(int $idEmpresa, int $idConversa, int $idUsuario): string
{
    return NOTIFICACAO_CHAT_CODIGO . ':empresa:' . $idEmpresa
        . ':conversa:' . $idConversa
        . ':usuario:' . $idUsuario;
}

/** Conclui a notificação de Chat de uma conversa para o usuário (conversa lida). */
function notificacaoConcluirChatConversa(mysqli $conexao, int $idEmpresa, int $idConversa, int $idUsuario): bool
{
    notificacaoInteiroPositivo($idEmpresa, 'id_empresa');
    notificacaoInteiroPositivo($idConversa, 'id_conversa');
    notificacaoInteiroPositivo($idUsuario, 'id_usuario');

    $chave = notificacaoChaveChatConversa($idEmpresa, $idConversa, $idUsuario);
    $codigo = NOTIFICACAO_CHAT_CODIGO;
    $stmt = notificacaoPreparar($conexao, "UPDATE notificacao
           SET concluida_em = CURRENT_TIMESTAMP
         WHERE chave_deduplicacao = ?
           AND id_empresa = ?
           AND destinatario_tipo = 'usuario'
           AND destinatario_id = ?
           AND codigo = ?
           AND concluida_em IS NULL
           AND cancelada_em IS NULL");
    $stmt->bind_param('siis', $chave, $idEmpresa, $idUsuario, $codigo);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível concluir a notificação do chat.');
        return $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível concluir a notificação do chat.');
    } finally {
        $stmt->close();
    }
}

/** Texto curto e sem conteúdo da mensagem (a prévia fica restrita ao Chat). */
function notificacaoTextoChatConversa(array $conversa, string $remetente): array
{
    $quantidade = max(1, (int)($conversa['quantidade_nao_lidas'] ?? 1));
    $cliente = trim((string)($conversa['cliente_nome'] ?? '')) ?: 'Cliente';
    $remetente = trim($remetente) ?: $cliente;
    $titulo = $quantidade === 1 ? 'Nova mensagem no Chat' : 'Novas mensagens no Chat';
    $mensagem = $quantidade === 1
        ? $remetente . ' enviou uma nova mensagem.'
        : $quantidade . ' novas mensagens na conversa com ' . $cliente . '.';

    $data = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($conversa['agendamento_data'] ?? ''));
    $hora = substr((string)($conversa['agendamento_hora_inicio'] ?? ''), 0, 5);
    if ($data !== false && preg_match('/^\d{2}:\d{2}$/', $hora) === 1) {
        $mensagem .= ' Agendamento de ' . $data->format('d/m/Y') . ' às ' . $hora . '.';
    }

    return [$titulo, mb_substr($mensagem, 0, 1000, 'UTF-8')];
}

/**
 * Cria ou atualiza a MESMA notificação da conversa. Quando a última mensagem não lida
 * mudou (ou a notificação estava concluída/cancelada), volta a ficar não lida.
 */
function notificacaoProjetarChatConversa(mysqli $conexao, int $idEmpresa, int $idUsuario, array $conversa, string $remetente): array
{
    $idConversa = (int)$conversa['id_conversa'];
    $idUltimaNaoLida = (int)$conversa['id_ultima_nao_lida'];
    [$titulo, $mensagem] = notificacaoTextoChatConversa($conversa, $remetente);
    $prazoEm = (string)($conversa['ultima_mensagem_em'] ?? '');
    if (DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $prazoEm) === false) $prazoEm = null;
    $contexto = ['id_conversa' => $idConversa, 'id_ultima_mensagem' => $idUltimaNaoLida];

    $projecao = notificacaoCriar($conexao, [
        'id_empresa' => $idEmpresa,
        'destinatario_tipo' => 'usuario',
        'destinatario_id' => $idUsuario,
        'origem_tipo' => 'sistema',
        'origem_id' => null,
        'codigo' => NOTIFICACAO_CHAT_CODIGO,
        'categoria' => 'chat',
        'titulo' => $titulo,
        'mensagem' => $mensagem,
        'prioridade' => 'normal',
        'obrigatoria' => false,
        'acao_codigo' => NOTIFICACAO_CHAT_ACAO,
        'contexto' => $contexto,
        'prazo_em' => $prazoEm,
        'chave_deduplicacao' => notificacaoChaveChatConversa($idEmpresa, $idConversa, $idUsuario),
    ]);
    if ($projecao['criada']) return $projecao + ['atualizada' => false];

    $idNotificacao = (int)$projecao['id_notificacao'];
    $stmt = notificacaoPreparar($conexao, "SELECT contexto, titulo, mensagem, prazo_em, concluida_em, cancelada_em
          FROM notificacao
         WHERE id_notificacao = ? AND id_empresa = ? AND destinatario_tipo = 'usuario' AND destinatario_id = ?
         LIMIT 1");
    $stmt->bind_param('iii', $idNotificacao, $idEmpresa, $idUsuario);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar a notificação do chat.');
        $resultado = $stmt->get_result();
        $atual = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar a notificação do chat.');
    } finally {
        $stmt->close();
    }
    if ($atual === null) return $projecao + ['atualizada' => false];

    $contextoAtual = json_decode((string)($atual['contexto'] ?? ''), true);
    $mesmaMensagem = is_array($contextoAtual) && (int)($contextoAtual['id_ultima_mensagem'] ?? 0) === $idUltimaNaoLida;
    $ativa = $atual['concluida_em'] === null && $atual['cancelada_em'] === null;
    $semMudanca = $ativa && $mesmaMensagem
        && (string)$atual['titulo'] === $titulo
        && (string)$atual['mensagem'] === $mensagem
        && ($atual['prazo_em'] === null ? null : (string)$atual['prazo_em']) === $prazoEm;
    if ($semMudanca) return $projecao + ['atualizada' => false];

    $manterLida = ($ativa && $mesmaMensagem) ? 1 : 0;
    $contextoJson = notificacaoContextoJson($contexto);
    $codigo = NOTIFICACAO_CHAT_CODIGO;
    $stmt = notificacaoPreparar($conexao, "UPDATE notificacao
           SET lida_em = IF(? = 1, lida_em, NULL),
               concluida_em = NULL,
               cancelada_em = NULL,
               titulo = ?,
               mensagem = ?,
               contexto = ?,
               prazo_em = ?
         WHERE id_notificacao = ?
           AND id_empresa = ?
           AND destinatario_tipo = 'usuario'
           AND destinatario_id = ?
           AND codigo = ?");
    $stmt->bind_param('issssiiis', $manterLida, $titulo, $mensagem, $contextoJson, $prazoEm, $idNotificacao, $idEmpresa, $idUsuario, $codigo);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível atualizar a notificação do chat.');
        $atualizada = $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível atualizar a notificação do chat.');
    } finally {
        $stmt->close();
    }
    return $projecao + ['atualizada' => $atualizada];
}

/**
 * Sincroniza as notificações de Chat do usuário autenticado com o estado real das conversas.
 * $chatCtx deve vir de chatContextoInterno() (null para Super Admin/suporte ou sem acesso).
 * Elegível: chat.visualizar + chat.responder, dentro do escopo real (Profissional somente
 * as próprias conversas). Sem elegibilidade, notificações ativas de Chat são canceladas.
 */
function notificacaoSincronizarChatUsuario(mysqli $conexao, ?array $chatCtx, int $idEmpresa, int $idUsuario): array
{
    notificacaoInteiroPositivo($idEmpresa, 'id_empresa');
    notificacaoInteiroPositivo($idUsuario, 'id_usuario');
    require_once __DIR__ . '/chat.php';

    $elegivel = is_array($chatCtx)
        && (int)($chatCtx['id_usuario'] ?? 0) === $idUsuario
        && (int)($chatCtx['id_empresa'] ?? 0) === $idEmpresa
        && ($chatCtx['pode_visualizar'] ?? false) === true
        && ($chatCtx['pode_responder'] ?? false) === true;

    $retorno = ['projetadas' => 0, 'concluidas' => 0, 'canceladas' => 0];
    $projetadas = [];
    if ($elegivel) {
        foreach (chatConversasNaoLidasParaNotificacao($conexao, $chatCtx, NOTIFICACAO_CHAT_LIMITE) as $conversa) {
            $idConversa = (int)$conversa['id_conversa'];
            $remetente = chatConsultar(
                $conexao,
                'SELECT remetente_nome FROM chat_mensagem WHERE id_mensagem = ? AND id_conversa = ? LIMIT 1',
                'ii',
                [(int)$conversa['id_ultima_nao_lida'], $idConversa]
            )[0]['remetente_nome'] ?? '';
            notificacaoProjetarChatConversa($conexao, $idEmpresa, $idUsuario, $conversa, (string)$remetente);
            $projetadas[$idConversa] = true;
            $retorno['projetadas']++;
        }
    }

    $codigo = NOTIFICACAO_CHAT_CODIGO;
    $stmt = notificacaoPreparar($conexao, "SELECT id_notificacao, chave_deduplicacao
          FROM notificacao
         WHERE id_empresa = ?
           AND destinatario_tipo = 'usuario'
           AND destinatario_id = ?
           AND codigo = ?
           AND concluida_em IS NULL
           AND cancelada_em IS NULL");
    $stmt->bind_param('iis', $idEmpresa, $idUsuario, $codigo);
    $orfas = [];
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível conferir as notificações do chat.');
        $resultado = $stmt->get_result();
        $padrao = '/^' . preg_quote(NOTIFICACAO_CHAT_CODIGO, '/') . ':empresa:' . $idEmpresa . ':conversa:(\d+):usuario:' . $idUsuario . '$/';
        while ($resultado && ($linha = $resultado->fetch_assoc())) {
            if (preg_match($padrao, (string)$linha['chave_deduplicacao'], $m) !== 1) continue;
            $idConversa = (int)$m[1];
            if (!isset($projetadas[$idConversa])) $orfas[$idConversa] = (int)$linha['id_notificacao'];
        }
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível conferir as notificações do chat.');
    } finally {
        $stmt->close();
    }
    if ($orfas === []) return $retorno;

    // Ainda no escopo: a conversa foi lida/encerrada (concluir). Fora do escopo: cancelar.
    $noEscopo = $elegivel ? array_flip(chatFiltrarConversasNoEscopo($conexao, $chatCtx, array_keys($orfas))) : [];
    foreach ($orfas as $idConversa => $idNotificacao) {
        if (isset($noEscopo[$idConversa])) {
            if (notificacaoConcluir($conexao, $idNotificacao, 'usuario', $idUsuario, $idEmpresa)['alterada']) $retorno['concluidas']++;
        } elseif (notificacaoCancelar($conexao, $idNotificacao, 'usuario', $idUsuario, $idEmpresa)['alterada']) {
            $retorno['canceladas']++;
        }
    }
    return $retorno;
}

/* ==========================================================
   CHAT — projeção de respostas não lidas na central do CLIENTE
   ----------------------------------------------------------
   Uma notificação por conversa + cliente (chave determinística),
   reativada quando chega nova resposta e concluída quando a conversa
   é lida pelo cliente. Roda sob demanda em cliente/notificacoes/listar,
   fora da transação de envio do Chat (falha aqui nunca afeta o envio).
   Empresa e cliente vêm sempre da sessão validada pelo handler.
   ========================================================== */
const NOTIFICACAO_CHAT_ACAO_CLIENTE = 'cliente.chat.abrir_conversa';

function notificacaoChaveChatCliente(int $idEmpresa, int $idConversa, int $idCliente): string
{
    return NOTIFICACAO_CHAT_CODIGO . ':empresa:' . $idEmpresa
        . ':conversa:' . $idConversa
        . ':cliente:' . $idCliente;
}

/**
 * Texto da notificação do cliente. Resposta de pessoa: texto curto, sem o conteúdo da mensagem
 * (a leitura fica restrita ao Chat). Mensagem do SISTEMA (última não lida): título próprio e o
 * próprio texto do evento, que é gerado pelo backend — nunca "{empresa} respondeu".
 */
function notificacaoTextoChatCliente(array $conversa, string $remetente, string $remetenteTipo = 'usuario', string $conteudoSistema = ''): array
{
    if ($remetenteTipo === 'sistema') {
        $texto = trim(preg_replace('/\s+/u', ' ', $conteudoSistema) ?? '');
        if ($texto === '') $texto = 'Há uma atualização sobre o seu agendamento no Chat.';
        return ['Atualização do seu agendamento', mb_substr($texto, 0, 1000, 'UTF-8')];
    }

    // O texto conta só respostas de pessoas; mensagens do sistema não entram na contagem
    // (o contador geral de não lidas do Chat continua incluindo-as).
    $quantidade = max(1, (int)($conversa['quantidade_humanas'] ?? $conversa['quantidade_nao_lidas'] ?? 1));
    $remetente = trim($remetente) ?: 'O profissional';
    $mensagem = $quantidade === 1
        ? $remetente . ' respondeu sua conversa'
        : $remetente . ' enviou ' . $quantidade . ' novas mensagens na sua conversa';

    $data = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($conversa['agendamento_data'] ?? ''));
    $hora = substr((string)($conversa['agendamento_hora_inicio'] ?? ''), 0, 5);
    if ($data !== false && preg_match('/^\d{2}:\d{2}$/', $hora) === 1) {
        $mensagem .= ' sobre o agendamento de ' . $data->format('d/m/Y') . ' às ' . $hora;
    }

    return ['Nova resposta no Chat', mb_substr($mensagem . '.', 0, 1000, 'UTF-8')];
}

/** Conversas do cliente com mensagens de terceiros ainda não lidas por ele (mesma regra do contador do Chat). */
function notificacaoConversasNaoLidasCliente(mysqli $conexao, int $idEmpresa, int $idCliente, int $limite): array
{
    $limite = max(1, min($limite, NOTIFICACAO_CHAT_LIMITE));
    $stmt = notificacaoPreparar($conexao, "SELECT c.id_conversa, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio,
                c.ultima_mensagem_em, COUNT(m.id_mensagem) AS quantidade_nao_lidas,
                SUM(CASE WHEN m.remetente_tipo <> 'sistema' THEN 1 ELSE 0 END) AS quantidade_humanas,
                MAX(m.id_mensagem) AS id_ultima_nao_lida
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
          GROUP BY c.id_conversa, c.id_agendamento, c.agendamento_data, c.agendamento_hora_inicio, c.ultima_mensagem_em
          ORDER BY MAX(m.id_mensagem) DESC
          LIMIT ?");
    $stmt->bind_param('iiiii', $idCliente, $idCliente, $idEmpresa, $idCliente, $limite);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar as conversas não lidas do cliente.');
        $resultado = $stmt->get_result();
        $linhas = [];
        while ($resultado && ($linha = $resultado->fetch_assoc())) $linhas[] = $linha;
        return $linhas;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar as conversas não lidas do cliente.');
    } finally {
        $stmt->close();
    }
}

/**
 * Remetente da última mensagem não lida (somente dentro da conversa já filtrada por empresa + cliente).
 * Retorna ['tipo' => string, 'nome' => string, 'conteudo' => string]; o conteúdo só é usado
 * quando o remetente é o sistema (texto gerado pelo próprio backend).
 */
function notificacaoRemetenteMensagemChat(mysqli $conexao, int $idEmpresa, int $idCliente, int $idConversa, int $idMensagem): array
{
    $stmt = notificacaoPreparar($conexao, "SELECT m.remetente_tipo, m.remetente_nome, m.conteudo
          FROM chat_mensagem m
         INNER JOIN chat_conversa c ON c.id_conversa = m.id_conversa
         WHERE m.id_mensagem = ? AND m.id_conversa = ? AND c.id_empresa = ? AND c.id_cliente = ?
         LIMIT 1");
    $stmt->bind_param('iiii', $idMensagem, $idConversa, $idEmpresa, $idCliente);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar o remetente da mensagem.');
        $resultado = $stmt->get_result();
        $linha = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
        $tipo = (string)($linha['remetente_tipo'] ?? '');
        return [
            'tipo' => $tipo,
            'nome' => trim((string)($linha['remetente_nome'] ?? '')),
            'conteudo' => $tipo === 'sistema' ? (string)($linha['conteudo'] ?? '') : '',
        ];
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar o remetente da mensagem.');
    } finally {
        $stmt->close();
    }
}

/**
 * Cria ou atualiza a MESMA notificação da conversa para o cliente. Quando a última mensagem
 * não lida mudou (ou a notificação estava concluída/cancelada), volta a ficar não lida.
 */
function notificacaoProjetarChatCliente(mysqli $conexao, int $idEmpresa, int $idCliente, array $conversa, array $remetente): array
{
    $idConversa = (int)$conversa['id_conversa'];
    $idAgendamento = (int)$conversa['id_agendamento'];
    $idUltimaNaoLida = (int)$conversa['id_ultima_nao_lida'];
    [$titulo, $mensagem] = notificacaoTextoChatCliente(
        $conversa,
        (string)($remetente['nome'] ?? ''),
        (string)($remetente['tipo'] ?? 'usuario'),
        (string)($remetente['conteudo'] ?? '')
    );
    $prazoEm = (string)($conversa['ultima_mensagem_em'] ?? '');
    if (DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $prazoEm) === false) $prazoEm = null;
    $contexto = ['id_conversa' => $idConversa, 'id_agendamento' => $idAgendamento, 'id_ultima_mensagem' => $idUltimaNaoLida];

    $projecao = notificacaoCriar($conexao, [
        'id_empresa' => $idEmpresa,
        'destinatario_tipo' => 'cliente',
        'destinatario_id' => $idCliente,
        'origem_tipo' => 'sistema',
        'origem_id' => null,
        'codigo' => NOTIFICACAO_CHAT_CODIGO,
        'categoria' => 'chat',
        'titulo' => $titulo,
        'mensagem' => $mensagem,
        'prioridade' => 'normal',
        'obrigatoria' => false,
        'acao_codigo' => NOTIFICACAO_CHAT_ACAO_CLIENTE,
        'contexto' => $contexto,
        'prazo_em' => $prazoEm,
        'chave_deduplicacao' => notificacaoChaveChatCliente($idEmpresa, $idConversa, $idCliente),
    ]);
    if ($projecao['criada']) return $projecao + ['atualizada' => false];

    $idNotificacao = (int)$projecao['id_notificacao'];
    $stmt = notificacaoPreparar($conexao, "SELECT contexto, titulo, mensagem, prazo_em, concluida_em, cancelada_em
          FROM notificacao
         WHERE id_notificacao = ? AND id_empresa = ? AND destinatario_tipo = 'cliente' AND destinatario_id = ?
         LIMIT 1");
    $stmt->bind_param('iii', $idNotificacao, $idEmpresa, $idCliente);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível consultar a notificação do chat.');
        $resultado = $stmt->get_result();
        $atual = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível consultar a notificação do chat.');
    } finally {
        $stmt->close();
    }
    if ($atual === null) return $projecao + ['atualizada' => false];

    $contextoAtual = json_decode((string)($atual['contexto'] ?? ''), true);
    $mesmaMensagem = is_array($contextoAtual) && (int)($contextoAtual['id_ultima_mensagem'] ?? 0) === $idUltimaNaoLida;
    $ativa = $atual['concluida_em'] === null && $atual['cancelada_em'] === null;
    $semMudanca = $ativa && $mesmaMensagem
        && (string)$atual['titulo'] === $titulo
        && (string)$atual['mensagem'] === $mensagem
        && ($atual['prazo_em'] === null ? null : (string)$atual['prazo_em']) === $prazoEm;
    if ($semMudanca) return $projecao + ['atualizada' => false];

    $manterLida = ($ativa && $mesmaMensagem) ? 1 : 0;
    $contextoJson = notificacaoContextoJson($contexto);
    $codigo = NOTIFICACAO_CHAT_CODIGO;
    $stmt = notificacaoPreparar($conexao, "UPDATE notificacao
           SET lida_em = IF(? = 1, lida_em, NULL),
               concluida_em = NULL,
               cancelada_em = NULL,
               titulo = ?,
               mensagem = ?,
               contexto = ?,
               prazo_em = ?
         WHERE id_notificacao = ?
           AND id_empresa = ?
           AND destinatario_tipo = 'cliente'
           AND destinatario_id = ?
           AND codigo = ?");
    $stmt->bind_param('issssiiis', $manterLida, $titulo, $mensagem, $contextoJson, $prazoEm, $idNotificacao, $idEmpresa, $idCliente, $codigo);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível atualizar a notificação do chat.');
        $atualizada = $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível atualizar a notificação do chat.');
    } finally {
        $stmt->close();
    }
    return $projecao + ['atualizada' => $atualizada];
}

/**
 * Sincroniza as notificações de Chat do cliente autenticado com o estado real das conversas.
 * Conversas com respostas não lidas viram (ou voltam a ser) notificações ativas; notificações
 * ativas de conversas já lidas são concluídas. Nunca grava mensagens nem marcadores de leitura.
 */
function notificacaoSincronizarChatCliente(mysqli $conexao, int $idEmpresa, int $idCliente): array
{
    notificacaoInteiroPositivo($idEmpresa, 'id_empresa');
    notificacaoInteiroPositivo($idCliente, 'id_cliente');

    $retorno = ['projetadas' => 0, 'concluidas' => 0];
    $projetadas = [];
    foreach (notificacaoConversasNaoLidasCliente($conexao, $idEmpresa, $idCliente, NOTIFICACAO_CHAT_LIMITE) as $conversa) {
        $idConversa = (int)$conversa['id_conversa'];
        $remetente = notificacaoRemetenteMensagemChat($conexao, $idEmpresa, $idCliente, $idConversa, (int)$conversa['id_ultima_nao_lida']);
        notificacaoProjetarChatCliente($conexao, $idEmpresa, $idCliente, $conversa, $remetente);
        $projetadas[$idConversa] = true;
        $retorno['projetadas']++;
    }

    $codigo = NOTIFICACAO_CHAT_CODIGO;
    $stmt = notificacaoPreparar($conexao, "SELECT id_notificacao, chave_deduplicacao
          FROM notificacao
         WHERE id_empresa = ?
           AND destinatario_tipo = 'cliente'
           AND destinatario_id = ?
           AND codigo = ?
           AND concluida_em IS NULL
           AND cancelada_em IS NULL");
    $stmt->bind_param('iis', $idEmpresa, $idCliente, $codigo);
    $orfas = [];
    try {
        if (!$stmt->execute()) throw new RuntimeException('Não foi possível conferir as notificações do chat.');
        $resultado = $stmt->get_result();
        $padrao = '/^' . preg_quote(NOTIFICACAO_CHAT_CODIGO, '/') . ':empresa:' . $idEmpresa . ':conversa:(\d+):cliente:' . $idCliente . '$/';
        while ($resultado && ($linha = $resultado->fetch_assoc())) {
            if (preg_match($padrao, (string)$linha['chave_deduplicacao'], $m) !== 1) continue;
            if (!isset($projetadas[(int)$m[1]])) $orfas[] = (int)$linha['id_notificacao'];
        }
    } catch (mysqli_sql_exception) {
        throw new RuntimeException('Não foi possível conferir as notificações do chat.');
    } finally {
        $stmt->close();
    }

    // Conversa lida (ou sem pendência para o cliente): a notificação deixa de ser pendente.
    foreach ($orfas as $idNotificacao) {
        if (notificacaoConcluir($conexao, $idNotificacao, 'cliente', $idCliente, $idEmpresa)['alterada']) $retorno['concluidas']++;
    }
    return $retorno;
}

// ============================================================
// AGENDA — NOTIFICAÇÃO DE ATRASO PARA O CLIENTE
// ============================================================
const NOTIFICACAO_AGENDAMENTO_ATRASO_CODIGO = 'agendamento_atraso';
const NOTIFICACAO_AGENDAMENTO_ATRASO_ACAO_CLIENTE = 'cliente.chat.abrir_conversa';

function notificacaoChaveAgendamentoAtraso(int $idEmpresa, int $idAgendamento, string $data, string $hora): string
{
    return NOTIFICACAO_AGENDAMENTO_ATRASO_CODIGO . ':empresa:' . $idEmpresa
        . ':agendamento:' . $idAgendamento
        . ':data:' . $data
        . ':hora:' . $hora;
}

function notificacaoTextoAgendamentoAtraso(int $deslocamentoMin, string $hora): array
{
    $unidade = $deslocamentoMin === 1 ? 'minuto' : 'minutos';
    return [
        'Alteração no horário do seu agendamento',
        'Seu atendimento sofreu um atraso estimado de ' . $deslocamentoMin . ' ' . $unidade . '. Novo horário previsto: ' . $hora . '.',
    ];
}

/**
 * Evento pós-commit do atraso. Relê o agendamento e só notifica o cliente que
 * continua vinculado ao horário aplicado; falhas são devolvidas ao orquestrador
 * sem interferir na agenda já persistida.
 */
function notificacaoRegistrarEventoAgendamentoAtraso(
    mysqli $conexao,
    int $idEmpresa,
    int $idAgendamento,
    string $horaAplicada,
    int $deslocamentoMin
): array {
    $emTransacao = false;
    try {
        $horaAplicada = substr(trim($horaAplicada), 0, 5);
        if ($idEmpresa <= 0 || $idAgendamento <= 0 || $deslocamentoMin <= 0
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaAplicada) !== 1) {
            throw new InvalidArgumentException('Parâmetros inválidos para a notificação de atraso.');
        }

        $conexao->begin_transaction();
        $emTransacao = true;
        $stmt = notificacaoPreparar($conexao, "SELECT id_cliente, status,
                    DATE_FORMAT(data_agendamento, '%Y-%m-%d') AS data_agendamento,
                    TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio
               FROM agendamento
              WHERE id_agendamento = ? AND id_empresa = ?
              LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii', $idAgendamento, $idEmpresa);
        try {
            if (!$stmt->execute()) throw new RuntimeException('Não foi possível reler o agendamento para notificação.');
            $resultado = $stmt->get_result();
            $agendamento = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
        } finally {
            $stmt->close();
        }

        if ($agendamento === null
            || (int)($agendamento['id_cliente'] ?? 0) <= 0
            || !in_array((string)($agendamento['status'] ?? ''), ['pendente', 'confirmado'], true)
            || (string)($agendamento['hora_inicio'] ?? '') !== $horaAplicada
            || DateTimeImmutable::createFromFormat('!Y-m-d', (string)($agendamento['data_agendamento'] ?? '')) === false) {
            $conexao->rollback();
            $emTransacao = false;
            return ['resultado' => 'ignorado', 'motivo' => 'agendamento_alterado'];
        }

        [$titulo, $mensagem] = notificacaoTextoAgendamentoAtraso($deslocamentoMin, $horaAplicada);
        $gravacao = notificacaoCriar($conexao, [
            'id_empresa' => $idEmpresa,
            'destinatario_tipo' => 'cliente',
            'destinatario_id' => (int)$agendamento['id_cliente'],
            'origem_tipo' => 'sistema',
            'origem_id' => null,
            'codigo' => NOTIFICACAO_AGENDAMENTO_ATRASO_CODIGO,
            'categoria' => 'agenda',
            'titulo' => $titulo,
            'mensagem' => $mensagem,
            'prioridade' => 'normal',
            'obrigatoria' => false,
            'acao_codigo' => NOTIFICACAO_AGENDAMENTO_ATRASO_ACAO_CLIENTE,
            'contexto' => ['id_agendamento' => $idAgendamento],
            'prazo_em' => null,
            'chave_deduplicacao' => notificacaoChaveAgendamentoAtraso(
                $idEmpresa,
                $idAgendamento,
                (string)$agendamento['data_agendamento'],
                $horaAplicada
            ),
        ]);
        $conexao->commit();
        $emTransacao = false;
        return [
            'resultado' => $gravacao['criada'] ? 'gravada' : 'idempotente',
            'motivo' => null,
        ];
    } catch (Throwable $e) {
        if ($emTransacao) {
            try { $conexao->rollback(); } catch (Throwable $ignorado) {}
        }
        error_log('[notificacao/agenda] evento=agendamento_atraso empresa=' . $idEmpresa
            . ' agendamento=' . $idAgendamento . ' erro=' . get_class($e) . ': ' . $e->getMessage());
        return ['resultado' => 'erro', 'motivo' => 'excecao'];
    }
}
