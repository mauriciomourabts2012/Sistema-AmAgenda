<?php
declare(strict_types=1);

/**
 * HANDLER PÚBLICO: POST cadastro-publico/empresa
 *
 * Orquestrador do cadastro público (onboarding) de empresa + Proprietário em trial.
 * Executado pelo api_central.php (sem sessão obrigatória; esta rota não cria sessão).
 *
 * SUBFASE 6.6 (esta versão):
 *   - valida o contrato de entrada (JSON estrito, allowlist de campos, tamanhos, formatos);
 *   - valida plano (existe, ativo, limite_proprietarios >= 1) e resolve o perfil Proprietário no backend;
 *   - confere a versão/hash dos documentos legais exibidos ao visitante;
 *   - para identidade global existente, exige conta ativa do tipo usuário e prova de senha;
 *   - serializa por e-mail (GET_LOCK) e usa uma transação única;
 *   - cria empresa, usuário global quando novo, vínculo Proprietário, assinatura trial de 30 dias
 *     e configurações iniciais;
 *   - registra as manifestações legais definitivas na mesma transação;
 *   - limita tentativas por IP e registra o resultado do onboarding na auditoria central;
 *   - reconhece reenvio recente já concluído sem duplicar empresa, trial ou manifestações;
 *   - não cria sessão nem cobrança.
 *
 * Segurança: aceita somente application/json; nunca confia em id_empresa/perfil/status/modalidade/datas/
 * valores vindos do frontend; nunca devolve stack, SQL, debug, hash ou segredos.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
}

const CADASTRO_PUBLICO_TAMANHO_MAX_CORPO = 16384;
const CADASTRO_PUBLICO_SENHA_MIN = 8;
const CADASTRO_PUBLICO_SENHA_MAX_BYTES = 72; // limite efetivo do bcrypt (PASSWORD_DEFAULT)
// Bloqueio progressivo por IP, baseado somente em recusas sensíveis já auditadas.
// Faixas [mínimo de erros na janela, minutos de bloqueio contados a partir do último erro], da maior para a menor.
const CADASTRO_PUBLICO_JANELA_ERROS_MINUTOS = 60;
const CADASTRO_PUBLICO_FAIXAS_BLOQUEIO = [[20, 60], [15, 30], [10, 5]];
// Lista positiva: somente esta recusa indica abuso persistente para fins do limitador.
const CADASTRO_PUBLICO_MOTIVO_ABUSO = 'dados_nao_aceitos';
const CADASTRO_PUBLICO_JANELA_REENVIO_MINUTOS = 5;

/* ==========================================================
   HELPERS LOCAIS (normalização e validação)
========================================================== */

/**
 * Ambiente local de desenvolvimento, decidido SOMENTE por dados do servidor (nunca por query, JSON ou cookie):
 * IP remoto ou do servidor em faixa local/privada, host amagenda.local/localhost e nenhum proxy reverso.
 * Qualquer dúvida => não é local (limite ativo).
 */
function cadastroPublicoAmbienteLocal(): bool
{
    $ehIpLocalOuPrivado = static function (string $ip): bool {
        $ip = strtolower(trim($ip));
        if ($ip === '::1') {
            return true;
        }
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $binario = inet_pton($ip);
        if ($binario === false || strlen($binario) !== 4) {
            return false;
        }
        $octetos = unpack('C4', $binario);

        return is_array($octetos) && (
            $octetos[1] === 127
            || $octetos[1] === 10
            || ($octetos[1] === 172 && $octetos[2] >= 16 && $octetos[2] <= 31)
            || ($octetos[1] === 192 && $octetos[2] === 168)
        );
    };

    $ipRemotoLocal = $ehIpLocalOuPrivado((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $ipServidorLocal = $ehIpLocalOuPrivado((string)($_SERVER['SERVER_ADDR'] ?? ''));
    if (!$ipRemotoLocal && !$ipServidorLocal) {
        return false;
    }

    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_VIA'] as $cabecalho) {
        if (isset($_SERVER[$cabecalho])) {
            return false;
        }
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = preg_replace('/:[0-9]{1,5}$/', '', $host) ?? '';

    return in_array($host, ['amagenda.local', 'localhost'], true);
}

/** Resposta única para situações sensíveis (conta/CNPJ existentes, limite de abuso). */
function cadastroPublicoRecusar(int $status = 409): void
{
    out([
        'ok' => false,
        'code' => 'SIGNUP_REFUSED',
        'user_msg' => $status === 429
            ? 'Muitas tentativas de cadastro. Aguarde alguns minutos e tente novamente.'
            : 'Não foi possível concluir o cadastro com os dados informados.',
    ], $status);
}

function cadastroPublicoErroValidacao(array $campos): void
{
    out([
        'ok' => false,
        'code' => 'VALIDATION_ERROR',
        'user_msg' => 'Revise os campos informados.',
        'fields' => $campos,
    ], 422);
}

/** Texto livre: remove controles, colapsa espaços e aplica trim. Retorna null se não for string. */
function cadastroPublicoTexto(mixed $valor): ?string
{
    if (!is_string($valor)) {
        return null;
    }
    $limpo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor);
    if ($limpo === null) {
        return null; // UTF-8 inválido
    }
    $limpo = preg_replace('/\s+/u', ' ', $limpo);

    return $limpo === null ? null : trim($limpo);
}

function cadastroPublicoApenasDigitos(string $valor): string
{
    return preg_replace('/\D+/', '', $valor) ?? '';
}

function cadastroPublicoCnpjValido(string $digitos): bool
{
    if (strlen($digitos) !== 14 || preg_match('/^(\d)\1{13}$/', $digitos) === 1) {
        return false;
    }
    $calcular = static function (string $base, array $pesos): int {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += ((int)$base[$i]) * $peso;
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    };
    $d1 = $calcular(substr($digitos, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    $d2 = $calcular(substr($digitos, 0, 12) . $d1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

    return $digitos === substr($digitos, 0, 12) . $d1 . $d2;
}

/** Mesmo formato persistido pelo cadastro do Super Admin (UNIQUE uq_empresa_cnpj sobre o texto formatado). */
function cadastroPublicoCnpjFormatar(string $digitos): string
{
    return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3)
        . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
}

/** Valida e normaliza um bloco; chaves fora da allowlist invalidam a requisição inteira. */
function cadastroPublicoBlocoExato(mixed $bloco, array $permitidas): ?array
{
    if (!is_array($bloco) || array_is_list($bloco) && $bloco !== []) {
        return null;
    }
    foreach (array_keys($bloco) as $chave) {
        if (!in_array((string)$chave, $permitidas, true)) {
            return null;
        }
    }

    return $bloco;
}

/** Consulta preparada simples. Lança exceção em qualquer falha (mysqli_report está desligado). */
function cadastroPublicoConsultar(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): array
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta do cadastro público.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta do cadastro público.');
    }
    $resultado = $stmt->get_result();
    $linhas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $linhas;
}

/* ==========================================================
   ORIGEM E FORMATO DA REQUISIÇÃO
========================================================== */

// O projeto não emite cabeçalhos CORS. Se o navegador informar Origin, ele deve ser o próprio host da API.
$origem = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origem !== '') {
    $hostOrigem = parse_url($origem, PHP_URL_HOST);
    $portaOrigem = parse_url($origem, PHP_URL_PORT);
    $hostRequisicao = (string)($_SERVER['HTTP_HOST'] ?? '');
    $hostOrigemCompleto = is_string($hostOrigem) ? mb_strtolower($hostOrigem . ($portaOrigem !== null && $portaOrigem !== false ? ':' . $portaOrigem : ''), 'UTF-8') : '';
    if ($hostOrigemCompleto === '' || $hostOrigemCompleto !== mb_strtolower($hostRequisicao, 'UTF-8')) {
        cadastroPublicoRecusar(403);
    }
}

$tipoConteudo = mb_strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]), 'UTF-8');
if ($tipoConteudo !== 'application/json') {
    out(['ok' => false, 'code' => 'CONTENT_TYPE_UNSUPPORTED', 'user_msg' => 'Formato de conteúdo não suportado.'], 415);
}

$corpo = (string)file_get_contents('php://input', false, null, 0, CADASTRO_PUBLICO_TAMANHO_MAX_CORPO + 1);
if ($corpo === '' || strlen($corpo) > CADASTRO_PUBLICO_TAMANHO_MAX_CORPO) {
    cadastroPublicoErroValidacao(['_geral' => 'Os dados enviados são inválidos.']);
}
try {
    $entrada = json_decode($corpo, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    cadastroPublicoErroValidacao(['_geral' => 'Os dados enviados são inválidos.']);
}

/* ==========================================================
   VALIDAÇÃO DE ENTRADA (sem acesso ao banco)
========================================================== */

$raiz = cadastroPublicoBlocoExato($entrada, ['plano_id', 'empresa', 'proprietario', 'documentos']);
$blocoEmpresa = $raiz === null ? null : cadastroPublicoBlocoExato($raiz['empresa'] ?? null, ['nome', 'cnpj', 'telefone', 'email']);
$blocoProprietario = $raiz === null ? null : cadastroPublicoBlocoExato($raiz['proprietario'] ?? null, ['nome', 'email', 'telefone', 'senha', 'senha2']);
if ($raiz === null || $blocoEmpresa === null || $blocoProprietario === null
    || !is_array($raiz['documentos'] ?? null) || !array_is_list($raiz['documentos'])) {
    // Inclui campos não permitidos (id_empresa, perfil, status, modalidade, datas, valores etc.).
    cadastroPublicoErroValidacao(['_geral' => 'Os dados enviados são inválidos.']);
}

$erros = [];

$planoBruto = $raiz['plano_id'] ?? null;
$idPlano = 0;
if (is_int($planoBruto) && $planoBruto > 0) {
    $idPlano = $planoBruto;
} elseif (is_string($planoBruto) && preg_match('/^[1-9][0-9]{0,9}$/D', $planoBruto) === 1) {
    $idPlano = (int)$planoBruto;
}
if ($idPlano <= 0 || $idPlano > 4294967295) {
    $erros['plano_id'] = 'Selecione um plano.';
}

// Empresa
$empresaNome = cadastroPublicoTexto($blocoEmpresa['nome'] ?? null);
if ($empresaNome === null || mb_strlen($empresaNome) < 3) {
    $erros['empresa.nome'] = 'Informe o nome da empresa (mínimo de 3 caracteres).';
} elseif (mb_strlen($empresaNome) > 140) {
    $erros['empresa.nome'] = 'O nome da empresa deve ter no máximo 140 caracteres.';
}

$empresaCnpj = null; // obrigatório no cadastro público; só é preenchido após validação completa
$cnpjBruto = $blocoEmpresa['cnpj'] ?? null;
$cnpjTexto = cadastroPublicoTexto($cnpjBruto);
$cnpjDigitos = $cnpjTexto === null ? '' : cadastroPublicoApenasDigitos($cnpjTexto);
if ($cnpjTexto === null || $cnpjTexto === '') {
    $erros['empresa.cnpj'] = 'Informe o CNPJ da empresa.';
} elseif (mb_strlen($cnpjTexto) > 30 || !cadastroPublicoCnpjValido($cnpjDigitos)) {
    $erros['empresa.cnpj'] = 'Informe um CNPJ válido.';
} else {
    $empresaCnpj = cadastroPublicoCnpjFormatar($cnpjDigitos);
}

$validarTelefone = static function (mixed $valor, string $campo) use (&$erros): string {
    $telefone = cadastroPublicoTexto($valor);
    $digitos = $telefone === null ? '' : cadastroPublicoApenasDigitos($telefone);
    if ($telefone === null || mb_strlen($telefone) > 20
        || preg_match('/^[0-9()+\-.\s]+$/D', $telefone) !== 1
        || strlen($digitos) < 10 || strlen($digitos) > 11) {
        $erros[$campo] = 'Informe um telefone válido com DDD.';

        return '';
    }

    return $telefone;
};
$validarEmail = static function (mixed $valor, string $campo) use (&$erros): string {
    $email = cadastroPublicoTexto($valor);
    $email = $email === null ? null : mb_strtolower($email, 'UTF-8');
    if ($email === null || $email === '' || mb_strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $erros[$campo] = 'Informe um e-mail válido.';

        return '';
    }

    return $email;
};

$empresaTelefone = $validarTelefone($blocoEmpresa['telefone'] ?? null, 'empresa.telefone');
$empresaEmail = $validarEmail($blocoEmpresa['email'] ?? null, 'empresa.email');

// Proprietário
$proprietarioNome = cadastroPublicoTexto($blocoProprietario['nome'] ?? null);
if ($proprietarioNome === null || mb_strlen($proprietarioNome) < 3) {
    $erros['proprietario.nome'] = 'Informe o nome do responsável (mínimo de 3 caracteres).';
} elseif (mb_strlen($proprietarioNome) > 140) {
    $erros['proprietario.nome'] = 'O nome deve ter no máximo 140 caracteres.';
}
$proprietarioEmail = $validarEmail($blocoProprietario['email'] ?? null, 'proprietario.email');
$proprietarioTelefone = $validarTelefone($blocoProprietario['telefone'] ?? null, 'proprietario.telefone');

// A senha não é aparada nem normalizada.
$senha = $blocoProprietario['senha'] ?? null;
$senha2 = $blocoProprietario['senha2'] ?? null;
if (!is_string($senha) || !is_string($senha2)) {
    $erros['proprietario.senha'] = 'Informe a senha.';
} elseif (str_contains($senha, "\0") || !mb_check_encoding($senha, 'UTF-8')) {
    $erros['proprietario.senha'] = 'A senha contém caracteres inválidos.';
} elseif (mb_strlen($senha) < CADASTRO_PUBLICO_SENHA_MIN) {
    $erros['proprietario.senha'] = 'A senha deve ter no mínimo ' . CADASTRO_PUBLICO_SENHA_MIN . ' caracteres.';
} elseif (strlen($senha) > CADASTRO_PUBLICO_SENHA_MAX_BYTES) {
    $erros['proprietario.senha'] = 'A senha deve ter no máximo ' . CADASTRO_PUBLICO_SENHA_MAX_BYTES . ' caracteres.';
} elseif (!hash_equals($senha, $senha2)) {
    $erros['proprietario.senha2'] = 'As senhas não coincidem.';
}

// Documentos: lista de {codigo, versao, hash_sha256}, sem repetição.
$documentosInformados = [];
foreach ($raiz['documentos'] as $item) {
    $bloco = cadastroPublicoBlocoExato($item, ['codigo', 'versao', 'hash_sha256']);
    $codigo = $bloco === null ? null : ($bloco['codigo'] ?? null);
    $versao = $bloco === null ? null : ($bloco['versao'] ?? null);
    $hash = $bloco === null ? null : ($bloco['hash_sha256'] ?? null);
    if (!is_string($codigo) || preg_match('/^[a-z][a-z0-9_]{2,59}$/D', $codigo) !== 1
        || !is_string($versao) || preg_match('/^[A-Za-z0-9._-]{1,20}$/D', $versao) !== 1
        || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/Di', $hash) !== 1
        || isset($documentosInformados[$codigo])) {
        $erros['documentos'] = 'Selecione e aceite todos os documentos obrigatórios.';
        break;
    }
    $documentosInformados[$codigo] = ['versao' => $versao, 'hash_sha256' => mb_strtolower($hash, 'UTF-8')];
}
if ($erros !== []) {
    cadastroPublicoErroValidacao($erros);
}

/* ==========================================================
   BANCO E SERVIÇOS (contratos reutilizados nas subfases seguintes)
========================================================== */

require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../documentos_legais/_comum.php';
require_once __DIR__ . '/../_regras/limites_plano.php';
require_once __DIR__ . '/../_servicos/empresa.php';
require_once __DIR__ . '/../_servicos/usuario_empresa.php';
require_once __DIR__ . '/../_servicos/assinatura.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out(['ok' => false, 'code' => 'SERVER_ERROR', 'user_msg' => 'Não foi possível processar o cadastro agora.'], 500);
}
$conexao->set_charset('utf8mb4');

$requestId = auditoriaRequestId();
$ipCadastro = auditoriaNormalizarIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgentCadastro = auditoriaLimitarTexto((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
$userAgentCadastro = $userAgentCadastro === '' ? null : $userAgentCadastro;

$auditarCadastroPublico = static function (string $evento, string $motivo, ?int $idEmpresa = null) use (
    $conexao,
    $ipCadastro,
    $userAgentCadastro
): void {
    auditoriaRegistrar($conexao, $evento, [
        'ator' => auditoriaResolverAtorNaoAutenticado($conexao, $idEmpresa),
        'entidade_id' => $idEmpresa,
        'entidade_rotulo' => $idEmpresa === null ? 'Cadastro público' : 'Empresa #' . $idEmpresa,
        'contexto' => [
            'origem' => 'cadastro_publico',
            'motivo' => $motivo,
        ],
        'ip' => $ipCadastro,
        'user_agent' => $userAgentCadastro,
    ]);
};

if ($ipCadastro === null) {
    try {
        $auditarCadastroPublico('cadastro_publico.falha_tecnica', 'ip_indisponivel');
    } catch (Throwable) {
        error_log('[auditoria_cadastro_publico] Não foi possível registrar IP indisponível.');
    }
    out(['ok' => false, 'code' => 'SERVER_ERROR', 'user_msg' => 'Não foi possível processar o cadastro agora.'], 500);
}

// A contagem de erros e o registro da tentativa formam uma seção crítica curta por IP.
$nomeLockIp = 'amagenda:cad_ip:' . substr(hash('sha256', $ipCadastro), 0, 48);
$lockIpAdquirido = false;
$lockIpLiberado = false;
$limiteIpExcedido = false;
$falhaLimiteIp = false;
try {
    $lockIp = cadastroPublicoConsultar($conexao, 'SELECT GET_LOCK(?, 5) AS adquirido', 's', $nomeLockIp);
    if ((int)($lockIp[0]['adquirido'] ?? 0) !== 1) {
        throw new RuntimeException('Não foi possível serializar o limite por IP.');
    }
    $lockIpAdquirido = true;

    // Conta apenas recusas sensíveis deste IP. Tentativas, falhas técnicas, sucessos e demais recusas
    // permanecem na auditoria, mas não alimentam o limitador. O tempo decorre do último erro contabilizado.
    $sqlErrosIp = "SELECT COUNT(*) AS total,
                          TIMESTAMPDIFF(SECOND, MAX(ocorrido_em), CURRENT_TIMESTAMP(6)) AS segundos_ultimo
                     FROM auditoria FORCE INDEX (idx_auditoria_auth_ip)
                    WHERE origem = 'autenticacao'
                      AND ip = INET6_ATON(?)
                      AND ocorrido_em >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL "
        . CADASTRO_PUBLICO_JANELA_ERROS_MINUTOS
        . " MINUTE)
                      AND evento_codigo = 'cadastro_publico.recusado'
                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(contexto, '$.motivo')), '') = ?";
    $errosIp = cadastroPublicoConsultar(
        $conexao,
        $sqlErrosIp,
        'ss',
        $ipCadastro,
        CADASTRO_PUBLICO_MOTIVO_ABUSO
    );
    $totalErrosIp = (int)($errosIp[0]['total'] ?? 0);
    $segundosDesdeUltimoErro = $errosIp[0]['segundos_ultimo'] ?? null;
    // Em desenvolvimento local a auditoria e a contagem continuam, mas não há bloqueio temporal.
    if (!cadastroPublicoAmbienteLocal() && $totalErrosIp > 0 && $segundosDesdeUltimoErro !== null) {
        foreach (CADASTRO_PUBLICO_FAIXAS_BLOQUEIO as [$minimoErros, $minutosBloqueio]) {
            if ($totalErrosIp >= $minimoErros) {
                $limiteIpExcedido = (int)$segundosDesdeUltimoErro < $minutosBloqueio * 60;
                break;
            }
        }
    }

    $auditarCadastroPublico('cadastro_publico.tentativa', 'solicitacao_recebida');
} catch (Throwable $e) {
    error_log('[cadastro_publico] ' . get_class($e) . ': falha no limite por IP.');
    $falhaLimiteIp = true;
} finally {
    if ($lockIpAdquirido) {
        try {
            $liberacaoIp = cadastroPublicoConsultar($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', $nomeLockIp);
            $lockIpLiberado = (int)($liberacaoIp[0]['liberado'] ?? 0) === 1;
        } catch (Throwable) {
        }
    }
}

if ($falhaLimiteIp || !$lockIpLiberado) {
    try {
        $auditarCadastroPublico('cadastro_publico.falha_tecnica', 'limite_ip_indisponivel');
    } catch (Throwable) {
        error_log('[auditoria_cadastro_publico] Não foi possível registrar falha do limite por IP.');
    }
    out(['ok' => false, 'code' => 'SERVER_ERROR', 'user_msg' => 'Não foi possível processar o cadastro agora.'], 500);
}

if ($limiteIpExcedido) {
    try {
        $auditarCadastroPublico('cadastro_publico.recusado', 'limite_ip_excedido');
    } catch (Throwable) {
        error_log('[auditoria_cadastro_publico] Não foi possível registrar recusa por limite de IP.');
    }
    cadastroPublicoRecusar(429);
}

$nomeLock = 'amagenda:cad:email:' . substr(hash('sha256', $proprietarioEmail), 0, 40);
$lockAdquirido = false;
$transacaoAberta = false;

/** Libera o lock e desfaz qualquer transação pendente antes de responder (exit não executa finally). */
$encerrar = static function (array $payload, int $status) use (
    $conexao,
    $nomeLock,
    &$lockAdquirido,
    &$transacaoAberta,
    $auditarCadastroPublico
): void {
    $rollbackConcluido = true;
    if ($transacaoAberta) {
        try {
            $rollbackConcluido = $conexao->rollback();
        } catch (Throwable) {
            $rollbackConcluido = false;
        }
        $transacaoAberta = false;
    }
    if ($lockAdquirido) {
        try {
            cadastroPublicoConsultar($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', $nomeLock);
        } catch (Throwable) {
        }
        $lockAdquirido = false;
    }

    if ($status >= 400 && $rollbackConcluido) {
        $codigo = (string)($payload['code'] ?? 'SERVER_ERROR');
        $evento = $status >= 500 ? 'cadastro_publico.falha_tecnica' : 'cadastro_publico.recusado';
        $motivo = match ($codigo) {
            'PLAN_UNAVAILABLE' => 'plano_indisponivel',
            'VALIDATION_ERROR' => 'dados_invalidos',
            'DOCUMENT_NOT_AVAILABLE' => 'documento_indisponivel',
            'DOCUMENT_NOT_APPLICABLE' => 'documento_nao_aplicavel',
            'DOCUMENT_VERSION_CHANGED' => 'versao_documento_alterada',
            'DOCUMENT_INTEGRITY_ERROR' => 'integridade_documento',
            'SIGNUP_BUSY' => 'concorrencia_email',
            'COMPANY_ALREADY_EXISTS' => 'empresa_ja_cadastrada',
            'SIGNUP_REFUSED' => $status === 429 ? 'solicitacao_limitada' : 'dados_nao_aceitos',
            default => 'falha_interna',
        };
        try {
            $auditarCadastroPublico($evento, $motivo);
        } catch (Throwable) {
            error_log('[auditoria_cadastro_publico] Não foi possível registrar o encerramento do onboarding.');
        }
    } elseif ($status >= 400) {
        error_log('[auditoria_cadastro_publico] Encerramento não auditado porque o rollback não foi confirmado.');
    }
    out($payload, $status);
};

try {
    // Serializa tentativas simultâneas para o mesmo e-mail de Proprietário (duplo clique / reenvio).
    $lock = cadastroPublicoConsultar($conexao, 'SELECT GET_LOCK(?, 5) AS adquirido', 's', $nomeLock);
    $resultadoLockEmail = $lock[0]['adquirido'] ?? null;
    if ($resultadoLockEmail === 0 || $resultadoLockEmail === '0') {
        $encerrar(['ok' => false, 'code' => 'SIGNUP_BUSY', 'user_msg' => 'Não foi possível concluir o cadastro agora. Tente novamente em instantes.'], 409);
    }
    if ($resultadoLockEmail === null || ($resultadoLockEmail !== 1 && $resultadoLockEmail !== '1')) {
        error_log('[cadastro_publico] Falha técnica ao adquirir o lock por e-mail.');
        $encerrar(['ok' => false, 'code' => 'SERVER_ERROR', 'user_msg' => 'Não foi possível processar o cadastro agora.'], 500);
    }
    $lockAdquirido = true;

    // Validação preliminar do plano; a decisão autoritativa é repetida sob lock na transação.
    $planos = cadastroPublicoConsultar(
        $conexao,
        'SELECT id_plano, status, limite_usuarios, limite_proprietarios, preco_mensal, cobranca, disponivel_cadastro_publico
           FROM plano WHERE id_plano = ? LIMIT 1',
        'i',
        $idPlano
    );
    $plano = $planos[0] ?? null;
    if ($plano === null
        || (string)$plano['status'] !== 'ativo'
        || (int)$plano['disponivel_cadastro_publico'] !== 1
        || (int)$plano['limite_proprietarios'] < 1
        || (int)$plano['limite_usuarios'] < 1) {
        $encerrar([
            'ok' => false,
            'code' => 'PLAN_UNAVAILABLE',
            'user_msg' => 'O plano selecionado não está disponível.',
            'fields' => ['plano_id' => 'Selecione um plano disponível.'],
        ], 422);
    }

    // Perfil Proprietário: resolvido aqui, nunca recebido do frontend.
    $idPerfilProprietario = 0;
    foreach (cadastroPublicoConsultar($conexao, "SELECT id_perfil, nome FROM perfil WHERE status = 'ativo'") as $perfil) {
        if (limitesPlanoNormalizarPerfil((string)$perfil['nome']) === 'proprietarios') {
            if ($idPerfilProprietario !== 0) {
                throw new RuntimeException('Mais de um perfil Proprietário ativo.');
            }
            $idPerfilProprietario = (int)$perfil['id_perfil'];
        }
    }
    if ($idPerfilProprietario <= 0) {
        throw new RuntimeException('Perfil Proprietário ativo não encontrado.');
    }

    // Documentos obrigatórios do Proprietário: conjunto exato, versão e hash vigentes e íntegros.
    $regrasDocumentos = documentosLegaisObrigatorios('representante_empresa');
    $codigosObrigatorios = array_column($regrasDocumentos, 'codigo');
    $codigosInformados = array_keys($documentosInformados);
    sort($codigosObrigatorios);
    sort($codigosInformados);
    if ($codigosInformados !== $codigosObrigatorios) {
        $encerrar([
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'user_msg' => 'Revise os campos informados.',
            'fields' => ['documentos' => 'Selecione e aceite todos os documentos obrigatórios.'],
        ], 422);
    }
    foreach ($regrasDocumentos as $regra) {
        $codigo = (string)$regra['codigo'];
        $publicado = documentosLegaisBuscarPublicado($conexao, $codigo);
        if ($publicado === null || !documentosLegaisEscopoAplicavel('representante_empresa', (string)$publicado['escopo'])) {
            $encerrar(['ok' => false, 'code' => 'DOCUMENT_NOT_AVAILABLE', 'user_msg' => 'Os documentos obrigatórios ainda não estão disponíveis.'], 409);
        }
        if (!documentosLegaisHashValido($publicado)) {
            documentosLegaisAuditarIntegridade($conexao, ['ator_auditoria' => auditoriaResolverAtorNaoAutenticado($conexao)], $publicado);
            $encerrar(['ok' => false, 'code' => 'DOCUMENT_INTEGRITY_ERROR', 'user_msg' => 'Não foi possível validar os documentos legais.'], 503);
        }
        $enviado = $documentosInformados[$codigo];
        if ($enviado['versao'] !== (string)$publicado['versao']
            || !hash_equals(mb_strtolower((string)$publicado['hash_sha256'], 'UTF-8'), $enviado['hash_sha256'])) {
            $encerrar([
                'ok' => false,
                'code' => 'DOCUMENT_VERSION_CHANGED',
                'user_msg' => 'Os documentos foram atualizados. Leia a versão atual e confirme novamente.',
            ], 409);
        }
    }

    // Localização preliminar; a decisão autoritativa ocorre sob FOR UPDATE dentro da transação.
    $identidadeLocalizadaAntesTransacao = cadastroPublicoConsultar(
        $conexao,
        'SELECT id_usuario FROM usuario WHERE LOWER(email) = ? LIMIT 1',
        's',
        $proprietarioEmail
    ) !== [];

    // Transação única, no mysqli compartilhado com os serviços (que não fazem commit/rollback próprios).
    if (!$conexao->begin_transaction()) {
        throw new RuntimeException('Falha ao iniciar a transação do cadastro público.');
    }
    $transacaoAberta = true;

    $planos = cadastroPublicoConsultar(
        $conexao,
        'SELECT id_plano, status, limite_usuarios, limite_proprietarios, preco_mensal, cobranca, disponivel_cadastro_publico
           FROM plano WHERE id_plano = ? LIMIT 1 FOR UPDATE',
        'i',
        $idPlano
    );
    $plano = $planos[0] ?? null;
    if ($plano === null
        || (string)$plano['status'] !== 'ativo'
        || (int)$plano['disponivel_cadastro_publico'] !== 1
        || (int)$plano['limite_proprietarios'] < 1
        || (int)$plano['limite_usuarios'] < 1) {
        $encerrar([
            'ok' => false,
            'code' => 'PLAN_UNAVAILABLE',
            'user_msg' => 'O plano selecionado não está disponível.',
            'fields' => ['plano_id' => 'Selecione um plano disponível.'],
        ], 422);
    }

    $identidades = cadastroPublicoConsultar(
        $conexao,
        'SELECT id_usuario, senha_hash, status, tipo_usuario
           FROM usuario
          WHERE LOWER(email) = ?
          LIMIT 1 FOR UPDATE',
        's',
        $proprietarioEmail
    );
    $identidadeExistente = $identidades[0] ?? null;
    if ($identidadeLocalizadaAntesTransacao && $identidadeExistente === null) {
        $encerrar([
            'ok' => false,
            'code' => 'SIGNUP_REFUSED',
            'user_msg' => 'Não foi possível concluir o cadastro com os dados informados.',
        ], 409);
    }

    $autorizarVinculoExistente = false;
    if ($identidadeExistente !== null) {
        $senhaIdentidadeValida = password_verify($senha, (string)$identidadeExistente['senha_hash']);
        $identidadeValida = (string)$identidadeExistente['status'] === 'ativo'
            && (string)$identidadeExistente['tipo_usuario'] === 'usuario'
            && $senhaIdentidadeValida;
        if (!$identidadeValida) {
            $encerrar([
                'ok' => false,
                'code' => 'SIGNUP_REFUSED',
                'user_msg' => 'Não foi possível concluir o cadastro com os dados informados.',
            ], 409);
        }
        // A autorização só existe nesta execução, depois da prova de posse da identidade bloqueada.
        $autorizarVinculoExistente = true;

        /*
         * O lock por e-mail serializa os reenvios. A identidade e a senha já foram confirmadas;
         * somente então um onboarding anterior pode ser revelado e reutilizado.
         */
        $sqlReenvio = "SELECT e.id_empresa, e.nome AS empresa_nome, e.cnpj, e.status AS empresa_status,
                              e.plano_id, eu.status AS vinculo_status, eu.bloqueado_plano,
                              a.id_assinatura, a.id_plano AS assinatura_plano_id,
                              a.status AS assinatura_status, a.modalidade,
                              DATE_FORMAT(a.teste_iniciado_em, '%Y-%m-%d %H:%i:%s') AS teste_iniciado_em,
                              DATE_FORMAT(a.teste_expira_em, '%Y-%m-%d %H:%i:%s') AS teste_expira_em,
                              (a.criado_em >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL "
            . CADASTRO_PUBLICO_JANELA_REENVIO_MINUTOS
            . " MINUTE)) AS assinatura_recente,
                              EXISTS(
                                  SELECT 1
                                    FROM auditoria au
                                   WHERE au.id_empresa = e.id_empresa
                                     AND au.origem = 'autenticacao'
                                     AND au.evento_codigo = 'cadastro_publico.concluido'
                                     AND au.entidade_tipo = 'cadastro_publico'
                                     AND au.entidade_id = e.id_empresa
                                     AND au.ocorrido_em >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL "
            . CADASTRO_PUBLICO_JANELA_REENVIO_MINUTOS
            . " MINUTE)
                              ) AS onboarding_confirmado
                         FROM empresa_usuario eu
                         JOIN empresa e ON e.id_empresa = eu.id_empresa
                         JOIN perfil p ON p.id_perfil = eu.id_perfil
                    LEFT JOIN assinatura a ON a.id_empresa = e.id_empresa
                        WHERE eu.id_usuario = ?
                          AND eu.id_perfil = ?
                          AND p.status = 'ativo'
                          AND e.nome = ?
                          AND e.criado_em >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL "
            . CADASTRO_PUBLICO_JANELA_REENVIO_MINUTOS
            . " MINUTE)
                          AND (? IS NULL OR e.cnpj = ?)
                     ORDER BY e.id_empresa DESC, a.id_assinatura DESC
                     FOR UPDATE";
        $linhasReenvio = cadastroPublicoConsultar(
            $conexao,
            $sqlReenvio,
            'iisss',
            (int)$identidadeExistente['id_usuario'],
            $idPerfilProprietario,
            $empresaNome,
            $empresaCnpj,
            $empresaCnpj
        );

        if ($linhasReenvio !== []) {
            $empresasReenvio = [];
            foreach ($linhasReenvio as $linhaReenvio) {
                $idEmpresaReenvio = (int)$linhaReenvio['id_empresa'];
                $empresasReenvio[$idEmpresaReenvio][] = $linhaReenvio;
            }
            if (count($empresasReenvio) !== 1) {
                throw new RuntimeException('Mais de um onboarding recente equivalente foi localizado.');
            }

            $linhasEmpresaReenvio = reset($empresasReenvio);
            $cadastroAnterior = $linhasEmpresaReenvio[0];
            $assinaturasReenvio = array_values(array_filter(
                $linhasEmpresaReenvio,
                static fn (array $linha): bool => $linha['id_assinatura'] !== null
            ));
            $trialAnteriorValido = count($assinaturasReenvio) === 1
                && (int)$cadastroAnterior['plano_id'] === $idPlano
                && (string)$cadastroAnterior['empresa_status'] === 'ativo'
                && (string)$cadastroAnterior['vinculo_status'] === 'ativo'
                && (int)$cadastroAnterior['bloqueado_plano'] === 0
                && (int)$cadastroAnterior['onboarding_confirmado'] === 1
                && (int)$assinaturasReenvio[0]['assinatura_plano_id'] === $idPlano
                && (string)$assinaturasReenvio[0]['assinatura_status'] === 'ativa'
                && (string)$assinaturasReenvio[0]['modalidade'] === 'teste'
                && (int)$assinaturasReenvio[0]['assinatura_recente'] === 1
                && (string)$assinaturasReenvio[0]['teste_iniciado_em'] !== ''
                && (string)$assinaturasReenvio[0]['teste_expira_em'] !== ''
                && (string)$assinaturasReenvio[0]['teste_expira_em'] > (string)$assinaturasReenvio[0]['teste_iniciado_em'];
            if (!$trialAnteriorValido) {
                throw new RuntimeException('Onboarding recente equivalente está em estado inconsistente.');
            }

            if (!$conexao->commit()) {
                throw new RuntimeException('Falha ao confirmar a leitura idempotente do cadastro público.');
            }
            $transacaoAberta = false;
            $encerrar([
                'ok' => true,
                'code' => 'SIGNUP_COMPLETED',
                'data' => [
                    'id_empresa' => (int)$cadastroAnterior['id_empresa'],
                    'empresa_nome' => (string)$cadastroAnterior['empresa_nome'],
                    'trial_expira_em' => (string)$assinaturasReenvio[0]['teste_expira_em'],
                    'request_id' => $requestId,
                ],
            ], 200);
        }
    }

    // Só após excluir um reenvio válido uma colisão de CNPJ representa nova tentativa recusada.
    // A consulta pelo CNPJ informado confirma a empresa existente; nenhum dado dela é devolvido.
    if ($empresaCnpj !== null
        && cadastroPublicoConsultar($conexao, 'SELECT id_empresa FROM empresa WHERE cnpj = ? LIMIT 1', 's', $empresaCnpj) !== []) {
        $encerrar(['ok' => false, 'code' => 'COMPANY_ALREADY_EXISTS', 'user_msg' => 'Esta empresa já possui cadastro no AmAgenda.'], 409);
    }
    // empresa.email pode repetir entre empresas: nenhuma checagem de duplicidade.

    try {
        $empresaCriada = empresaServicoCriar($conexao, [
            'nome' => $empresaNome,
            'cnpj' => $empresaCnpj,
            'email' => $empresaEmail,
            'telefone' => $empresaTelefone,
            'id_plano' => $idPlano,
            'status' => 'ativo',
            'endereco' => '',
            'observacao' => '',
        ]);
    } catch (RuntimeException $e) {
        // O serviço não expõe qual registro colidiu; atualmente o único UNIQUE de empresa é o CNPJ.
        if ($e->getMessage() === 'Registro duplicado ao inserir empresa.') {
            $encerrar([
                'ok' => false,
                'code' => 'SIGNUP_REFUSED',
                'user_msg' => 'Não foi possível concluir o cadastro com os dados informados.',
            ], 409);
        }
        throw $e;
    }
    $idEmpresa = (int)$empresaCriada['id_empresa'];

    try {
        $usuarioVinculo = usuarioEmpresaServicoCriarOuVincular($conexao, [
            'id_empresa' => $idEmpresa,
            'id_perfil' => $idPerfilProprietario,
            'nome' => $proprietarioNome,
            'email' => $proprietarioEmail,
            'telefone' => $proprietarioTelefone,
            'senha' => $senha,
            'status' => 'ativo',
            'deve_alterar_senha' => false,
            'autorizar_vinculo_existente' => $autorizarVinculoExistente,
            'perfil_esperado_normalizado' => 'proprietarios',
        ]);
    } catch (UsuarioEmpresaServicoErro $e) {
        if (in_array($e->codigo(), ['EMAIL_EXISTS', 'INVALID_LINK_SUPERADMIN', 'USER_ALREADY_LINKED'], true)) {
            $encerrar([
                'ok' => false,
                'code' => 'SIGNUP_REFUSED',
                'user_msg' => 'Não foi possível concluir o cadastro com os dados informados.',
            ], 409);
        }
        throw $e;
    }
    $esperavaUsuarioNovo = $identidadeExistente === null;
    if (($usuarioVinculo['vinculo_criado'] ?? false) !== true
        || (bool)($usuarioVinculo['usuario_novo'] ?? false) !== $esperavaUsuarioNovo
        || (!$esperavaUsuarioNovo
            && (int)($usuarioVinculo['id_usuario'] ?? 0) !== (int)$identidadeExistente['id_usuario'])) {
        $encerrar([
            'ok' => false,
            'code' => 'SIGNUP_REFUSED',
            'user_msg' => 'Não foi possível concluir o cadastro com os dados informados.',
        ], 409);
    }

    // As três datas derivam do mesmo CURRENT_TIMESTAMP do banco, sem alterar o fuso global.
    $instantes = cadastroPublicoConsultar(
        $conexao,
        "SELECT DATE_FORMAT(CURRENT_TIMESTAMP, '%Y-%m-%d %H:%i:%s') AS teste_iniciado_em,
                DATE_FORMAT(DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 DAY), '%Y-%m-%d %H:%i:%s') AS teste_expira_em,
                DATE_FORMAT(CURRENT_TIMESTAMP, '%Y-%m-%d') AS data_inicio"
    );
    $instanteTrial = $instantes[0] ?? null;
    if ($instanteTrial === null) {
        throw new RuntimeException('Não foi possível determinar o período do trial.');
    }

    $assinaturaCriada = assinaturaServicoCriar($conexao, [
        'id_empresa' => $idEmpresa,
        'id_plano' => $idPlano,
        'valor_contratado' => (float)$plano['preco_mensal'],
        'periodicidade' => (string)$plano['cobranca'],
        'dia_vencimento' => 10,
        'data_inicio' => (string)$instanteTrial['data_inicio'],
        'status' => 'ativa',
        'modalidade' => 'teste',
        'teste_iniciado_em' => (string)$instanteTrial['teste_iniciado_em'],
        'teste_expira_em' => (string)$instanteTrial['teste_expira_em'],
    ]);

    empresaServicoCriarConfiguracoesIniciais($conexao, $idEmpresa, [
        'observacao_padrao' => 'Defina as configurações gerais da agenda da empresa, como dias de funcionamento, horários de trabalho e intervalos (ex: almoço). Essas regras servem como base, podendo ser ajustadas por cada profissional.',
        'ddi_padrao' => '55',
        'ddd_padrao' => null,
        'mensagem_padrao' => 'Olá {cliente}! Seu agendamento de {servico} está {status} para {data} às {hora}.',
    ]);

    $idUsuarioManifestante = (int)($usuarioVinculo['id_usuario'] ?? 0);
    $nomeManifestante = (string)($usuarioVinculo['nome'] ?? '');
    $emailManifestante = (string)($usuarioVinculo['email'] ?? '');
    if ($idUsuarioManifestante <= 0 || $nomeManifestante === '' || $emailManifestante === '') {
        throw new RuntimeException('Contexto jurídico do Proprietário inválido.');
    }

    $ipManifestacao = auditoriaNormalizarIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $userAgentManifestacao = auditoriaLimitarTexto((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
    $userAgentManifestacao = $userAgentManifestacao === '' ? null : $userAgentManifestacao;
    $contextoManifestacao = [
        'tipo_manifestante' => 'representante_empresa',
        'id_usuario' => $idUsuarioManifestante,
        'id_cliente' => null,
        'id_empresa' => $idEmpresa,
        'papel' => 'proprietario',
        'nome' => $nomeManifestante,
        'identificador' => $emailManifestante,
        'empresa_nome' => $empresaNome,
        'origem_manifestacao' => 'api',
        'ator_auditoria' => [
            'ator_tipo' => 'usuario',
            'id_ator' => $idUsuarioManifestante,
            'ator_nome' => $nomeManifestante,
            'ator_perfil' => 'proprietario',
            'id_empresa' => $idEmpresa,
            'modo_suporte' => false,
            'origem' => 'empresa',
        ],
    ];

    try {
        // Revalida e bloqueia as versões apresentadas antes de registrar qualquer manifestação.
        foreach ($regrasDocumentos as $regra) {
            $codigo = (string)$regra['codigo'];
            $publicadoAtual = documentosLegaisBuscarPublicado($conexao, $codigo, true);
            if ($publicadoAtual === null) {
                throw new DomainException('DOCUMENT_NOT_AVAILABLE');
            }
            if (!documentosLegaisEscopoAplicavel('representante_empresa', (string)$publicadoAtual['escopo'])) {
                throw new DomainException('DOCUMENT_NOT_APPLICABLE');
            }
            if (!documentosLegaisHashValido($publicadoAtual)) {
                throw new DocumentosLegaisIntegridadeException($publicadoAtual);
            }

            $documentoEnviado = $documentosInformados[$codigo];
            if ($documentoEnviado['versao'] !== (string)$publicadoAtual['versao']
                || !hash_equals(
                    mb_strtolower((string)$publicadoAtual['hash_sha256'], 'UTF-8'),
                    $documentoEnviado['hash_sha256']
                )) {
                throw new DomainException('DOCUMENT_VERSION_CHANGED');
            }
        }

        documentosLegaisRegistrarManifestacoes(
            $conexao,
            $contextoManifestacao,
            $regrasDocumentos,
            0,
            $ipManifestacao,
            $userAgentManifestacao,
            $requestId
        );
    } catch (DocumentosLegaisIntegridadeException $e) {
        // A auditoria da falha precisa sobreviver ao rollback do onboarding ainda não confirmado.
        $rollbackConcluido = false;
        try {
            $rollbackConcluido = $conexao->rollback();
        } catch (Throwable) {
        }
        if (!$rollbackConcluido) {
            $encerrar([
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'user_msg' => 'Não foi possível processar o cadastro agora.',
            ], 500);
        }
        $transacaoAberta = false;
        documentosLegaisAuditarIntegridade(
            $conexao,
            ['ator_auditoria' => auditoriaResolverAtorNaoAutenticado($conexao)],
            $e->documento()
        );
        $encerrar([
            'ok' => false,
            'code' => 'DOCUMENT_INTEGRITY_ERROR',
            'user_msg' => 'Não foi possível validar os documentos legais.',
        ], 503);
    } catch (DomainException $e) {
        if ($e->getMessage() === 'DOCUMENT_VERSION_CHANGED') {
            $encerrar([
                'ok' => false,
                'code' => 'DOCUMENT_VERSION_CHANGED',
                'user_msg' => 'Os documentos foram atualizados. Leia a versão atual e confirme novamente.',
            ], 409);
        }
        if ($e->getMessage() === 'DOCUMENT_NOT_AVAILABLE') {
            $encerrar([
                'ok' => false,
                'code' => 'DOCUMENT_NOT_AVAILABLE',
                'user_msg' => 'Os documentos obrigatórios ainda não estão disponíveis.',
            ], 409);
        }
        if ($e->getMessage() === 'DOCUMENT_NOT_APPLICABLE') {
            $encerrar([
                'ok' => false,
                'code' => 'DOCUMENT_NOT_APPLICABLE',
                'user_msg' => 'Documento não disponível para este acesso.',
            ], 403);
        }
        throw $e;
    }

    // O sucesso integra a mesma transação: um rollback remove também este evento.
    $auditarCadastroPublico('cadastro_publico.concluido', 'onboarding_concluido', $idEmpresa);

    if (!$conexao->commit()) {
        throw new RuntimeException('Falha ao confirmar a transação do cadastro público.');
    }
    $transacaoAberta = false;

    $encerrar([
        'ok' => true,
        'code' => 'SIGNUP_COMPLETED',
        'data' => [
            'id_empresa' => $idEmpresa,
            'empresa_nome' => $empresaNome,
            'trial_expira_em' => (string)$assinaturaCriada['teste_expira_em'],
            'request_id' => $requestId,
        ],
    ], 201);
} catch (Throwable $e) {
    error_log('[cadastro_publico] ' . get_class($e) . ': ' . $e->getMessage());
    $encerrar(['ok' => false, 'code' => 'SERVER_ERROR', 'user_msg' => 'Não foi possível processar o cadastro agora.'], 500);
}
