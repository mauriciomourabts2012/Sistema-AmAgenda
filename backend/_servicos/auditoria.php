<?php
declare(strict_types=1);

require_once __DIR__ . '/../_regras/catalogo_auditoria.php';
require_once __DIR__ . '/../_regras/contexto_auditoria.php';

const AUDITORIA_TEXTO_VALOR_MAX = 1000;
const AUDITORIA_DESCRICAO_MAX = 500;
const AUDITORIA_ENTIDADE_ROTULO_MAX = 190;
const AUDITORIA_USER_AGENT_MAX = 500;
const AUDITORIA_JSON_MAX_BYTES = 65535;

function auditoriaChaveNormalizada(string $chave): string
{
    $chave = mb_strtolower(trim($chave), 'UTF-8');
    $transliterada = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $chave);
    $chave = is_string($transliterada) ? $transliterada : $chave;
    return preg_replace('/[^a-z0-9]+/', '_', $chave) ?? $chave;
}

function auditoriaChaveSensivel(string $chave): bool
{
    $normalizada = auditoriaChaveNormalizada($chave);
    // Marcador booleano previsto no catálogo; não transporta valor nem característica da senha.
    if ($normalizada === 'senha_alterada') return false;
    $padroes = [
        'senha', 'password', 'passwd', 'hash', 'salt', 'token', 'cookie', 'session', 'sessao',
        'secret', 'segredo', 'credential', 'credencial', 'authorization', 'private_key',
        'chave_privada', 'api_key', 'apikey', 'certificado', 'php_auth_pw', 'codigo_recuperacao',
    ];

    foreach ($padroes as $padrao) {
        if (str_contains($normalizada, $padrao)) return true;
    }

    return false;
}

function auditoriaValidarAusenciaDadosSensiveis(mixed $valor, string $caminho = ''): void
{
    if (!is_array($valor)) return;

    foreach ($valor as $chave => $item) {
        $nome = (string)$chave;
        $atual = $caminho === '' ? $nome : $caminho . '.' . $nome;
        if (!is_int($chave) && auditoriaChaveSensivel($nome)) {
            throw new InvalidArgumentException('Campo sensível não permitido na auditoria: ' . $atual);
        }
        auditoriaValidarAusenciaDadosSensiveis($item, $atual);
    }
}

function auditoriaNormalizarValor(mixed $valor, int $profundidade = 0): mixed
{
    if ($profundidade > 6) throw new InvalidArgumentException('Estrutura de auditoria profunda demais.');
    if ($valor === null || is_bool($valor) || is_int($valor)) return $valor;
    if (is_float($valor)) return number_format($valor, 2, '.', '');
    if (is_string($valor)) return auditoriaLimitarTexto($valor, AUDITORIA_TEXTO_VALOR_MAX);

    if (is_array($valor)) {
        $resultado = [];
        foreach ($valor as $chave => $item) {
            if (!is_int($chave) && auditoriaChaveSensivel((string)$chave)) {
                throw new InvalidArgumentException('Campo sensível não permitido na auditoria: ' . (string)$chave);
            }
            $resultado[$chave] = auditoriaNormalizarValor($item, $profundidade + 1);
        }
        return $resultado;
    }

    throw new InvalidArgumentException('Tipo de valor não suportado na auditoria.');
}

function auditoriaValoresIguais(mixed $antes, mixed $depois): bool
{
    return json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        === json_encode($depois, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function auditoriaSanitizarAlteracoes(string $eventoCodigo, array $alteracoes): array
{
    $evento = auditoriaObterEvento($eventoCodigo);
    $permitidos = array_fill_keys($evento['campos_auditaveis'], true);
    $resultado = [];

    auditoriaValidarAusenciaDadosSensiveis($alteracoes);

    foreach ($alteracoes as $campo => $mudanca) {
        $campo = (string)$campo;
        if (!isset($permitidos[$campo])) continue;
        if (!is_array($mudanca) || !array_key_exists('antes', $mudanca) || !array_key_exists('depois', $mudanca)) {
            throw new InvalidArgumentException('Diferença inválida para o campo: ' . $campo);
        }

        $antes = auditoriaNormalizarValor($mudanca['antes']);
        $depois = auditoriaNormalizarValor($mudanca['depois']);
        if (auditoriaValoresIguais($antes, $depois)) continue;

        $resultado[$campo] = ['antes' => $antes, 'depois' => $depois];
    }

    return $resultado;
}

function auditoriaSanitizarContexto(array $contexto): array
{
    auditoriaValidarAusenciaDadosSensiveis($contexto);
    $permitidos = ['origem', 'aba', 'recorrencia', 'quantidade_afetada', 'escopo', 'grupo_recorrencia', 'data_referencia', 'motivo', 'versao'];
    $resultado = [];
    foreach ($permitidos as $campo) {
        if (array_key_exists($campo, $contexto)) $resultado[$campo] = auditoriaNormalizarValor($contexto[$campo]);
    }
    return $resultado;
}

function auditoriaJson(?array $dados): ?string
{
    if ($dados === null || $dados === []) return null;
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) > AUDITORIA_JSON_MAX_BYTES) throw new InvalidArgumentException('Dados estruturados da auditoria excedem o limite permitido.');
    return $json;
}

function auditoriaNormalizarIp(?string $ip): ?string
{
    $ip = trim((string)$ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return null;
    return $ip;
}

function auditoriaGerarUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function auditoriaRequestId(): string
{
    static $requestId = null;
    if (is_string($requestId)) return $requestId;

    $recebido = mb_strtolower(trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '')), 'UTF-8');
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $recebido)) {
        return $requestId = $recebido;
    }

    return $requestId = auditoriaGerarUuidV4();
}

/**
 * Persiste usando a conexão recebida. Não inicia, confirma ou desfaz transações.
 * Qualquer falha lança exceção para que o caso de uso decida pelo rollback.
 */
function auditoriaRegistrar(mysqli $conexao, string $eventoCodigo, array $dados = []): int
{
    $evento = auditoriaObterEvento($eventoCodigo);
    if (array_key_exists('id_empresa', $dados)) {
        throw new InvalidArgumentException('A empresa da auditoria não pode ser informada no payload do evento.');
    }

    $ator = $dados['ator'] ?? auditoriaResolverAtorSessao($conexao);
    if (!is_array($ator)) throw new InvalidArgumentException('Ator inválido para auditoria.');
    auditoriaValidarAtor($ator);

    $alteracoes = auditoriaSanitizarAlteracoes($eventoCodigo, is_array($dados['alteracoes'] ?? null) ? $dados['alteracoes'] : []);
    $contexto = auditoriaSanitizarContexto(is_array($dados['contexto'] ?? null) ? $dados['contexto'] : []);
    $alteracoesJson = auditoriaJson($alteracoes);
    $contextoJson = auditoriaJson($contexto);

    $idEmpresa = $ator['id_empresa'] === null ? null : (int)$ator['id_empresa'];
    $atorTipo = (string)$ator['ator_tipo'];
    $idAtor = $ator['id_ator'] === null ? null : (int)$ator['id_ator'];
    $atorNome = auditoriaLimitarTexto((string)$ator['ator_nome'], 150);
    $atorPerfil = auditoriaLimitarTexto((string)$ator['ator_perfil'], 50);
    $modoSuporte = (bool)$ator['modo_suporte'] ? 1 : 0;
    $origem = (string)$ator['origem'];
    $modulo = (string)$evento['modulo'];
    $entidadeTipo = (string)$evento['entidade'];
    $entidadeId = isset($dados['entidade_id']) ? (int)$dados['entidade_id'] : null;
    if ($entidadeId !== null && $entidadeId <= 0) $entidadeId = null;
    $entidadeRotulo = isset($dados['entidade_rotulo']) ? auditoriaLimitarTexto((string)$dados['entidade_rotulo'], AUDITORIA_ENTIDADE_ROTULO_MAX) : null;
    if ($entidadeRotulo === '') $entidadeRotulo = null;
    $descricao = auditoriaLimitarTexto((string)($dados['descricao'] ?? $evento['descricao_padrao']), AUDITORIA_DESCRICAO_MAX);
    if ($descricao === '') $descricao = (string)$evento['descricao_padrao'];
    $ip = auditoriaNormalizarIp((string)($dados['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    $userAgent = auditoriaLimitarTexto((string)($dados['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''), AUDITORIA_USER_AGENT_MAX);
    if ($userAgent === '') $userAgent = null;
    $requestId = auditoriaRequestId();

    $sql = "INSERT INTO auditoria (id_empresa,ator_tipo,id_ator,ator_nome,ator_perfil,modo_suporte,origem,evento_codigo,modulo,entidade_tipo,entidade_id,entidade_rotulo,descricao,alteracoes,contexto,ip,user_agent,request_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,INET6_ATON(?),?,?)";
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar o registro de auditoria.');

    $stmt->bind_param(
        'isississssisssssss',
        $idEmpresa, $atorTipo, $idAtor, $atorNome, $atorPerfil, $modoSuporte,
        $origem, $eventoCodigo, $modulo, $entidadeTipo, $entidadeId, $entidadeRotulo,
        $descricao, $alteracoesJson, $contextoJson, $ip, $userAgent, $requestId
    );

    try {
        if (!$stmt->execute()) throw new RuntimeException('Falha ao registrar a auditoria.');
        $id = (int)$conexao->insert_id;
    } finally {
        $stmt->close();
    }

    if ($id <= 0) throw new RuntimeException('O registro de auditoria não retornou identificador.');
    return $id;
}

function auditoriaValidarAtor(array $ator): void
{
    $obrigatorios = ['ator_tipo', 'id_ator', 'ator_nome', 'ator_perfil', 'id_empresa', 'modo_suporte', 'origem'];
    foreach ($obrigatorios as $campo) {
        if (!array_key_exists($campo, $ator)) throw new InvalidArgumentException('Contrato do ator incompleto.');
    }

    $tipo = (string)$ator['ator_tipo'];
    $id = $ator['id_ator'];
    $perfil = (string)$ator['ator_perfil'];
    $suporte = (bool)$ator['modo_suporte'];
    $origem = (string)$ator['origem'];
    $idEmpresa = $ator['id_empresa'] === null ? null : (int)$ator['id_empresa'];
    if (trim((string)$ator['ator_nome']) === '') throw new InvalidArgumentException('Ator sem nome válido.');

    $valido = ($origem === 'empresa' && $idEmpresa !== null && $idEmpresa > 0 && !$suporte
            && (($tipo === 'usuario' && (int)$id > 0) || ($tipo === 'sistema' && $id === null && $perfil === 'sistema')))
        || ($origem === 'modo_suporte' && $idEmpresa !== null && $idEmpresa > 0
            && $tipo === 'super_admin' && (int)$id > 0 && $perfil === 'super_admin' && $suporte)
        || ($origem === 'plataforma' && $tipo === 'super_admin' && (int)$id > 0 && $perfil === 'super_admin' && !$suporte)
        || ($origem === 'autenticacao' && $tipo === 'nao_autenticado' && $id === null
            && $perfil === 'nao_autenticado' && !$suporte);
    if (!$valido) throw new InvalidArgumentException('Combinação inválida no contrato do ator.');
}

function auditoriaRegistrarFalhaAutenticacao(mysqli $conexao, string $eventoCodigo, string $motivo, ?int $idEmpresa = null, string $loginTentado = ''): int
{
    $ip = auditoriaNormalizarIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $loginTentado = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($loginTentado)) ?? '';
    $loginTentado = auditoriaLimitarTexto($loginTentado, 190);
    $credenciaisInvalidas = $eventoCodigo === 'autenticacao.credenciais_invalidas';

    return auditoriaRegistrar($conexao, $eventoCodigo, [
        'ator' => auditoriaResolverAtorNaoAutenticado($conexao, $idEmpresa),
        'descricao' => 'Falha de autenticação registrada.',
        'alteracoes' => $credenciaisInvalidas && $loginTentado !== '' ? ['login_tentado' => ['antes' => null, 'depois' => $loginTentado]] : [],
        'contexto' => ['origem' => 'login', 'motivo' => $motivo],
        'ip' => $ip,
    ]);
}
