<?php
declare(strict_types=1);

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
