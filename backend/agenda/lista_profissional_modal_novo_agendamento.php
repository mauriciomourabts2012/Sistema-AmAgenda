<?php
declare(strict_types=1);

/*
|----------------------------------------------------------------------
| LISTAR PROFISSIONAIS - MODAL NOVO AGENDAMENTO
|----------------------------------------------------------------------
| Retorna os profissionais vinculados à empresa da sessão.
|
| Rota:
| GET /public/api/api_central.php?path=agenda/profissional-modal-novo-agendamento/listar
|
| Retorno:
| {
|   "ok": true,
|   "data": [
|     {
|       "id_profissional": 1,
|       "id_usuario": 5,
|       "nome": "Maria Silva",
|       "telefone": "(38) 99999-9999",
|       "email": "maria@email.com",
|       "especialidade": "Cabeleireira",
|       "descricao": "Especialista em cortes",
|       "status": "ativo"
|     }
|   ]
| }
*/

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|----------------------------------------------------------------------
| CONEXÃO
|----------------------------------------------------------------------
| Ajuste o caminho se no seu projeto a conexão estiver em outro local.
*/
$possiveisConexoes = [
    __DIR__ . '/../conexao.php',
    __DIR__ . '/../../backend/conexao.php',
    __DIR__ . '/../../conexao.php',
    __DIR__ . '/../_config/conexao.php',
    __DIR__ . '/../../backend/_config/conexao.php',
];

$conexaoCarregada = false;

foreach ($possiveisConexoes as $arquivoConexao) {
    if (file_exists($arquivoConexao)) {
        require_once $arquivoConexao;
        $conexaoCarregada = true;
        break;
    }
}

if (!$conexaoCarregada || !isset($conexao) || !($conexao instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'CONEXAO_NAO_ENCONTRADA',
        'mensagem' => 'Não foi possível carregar a conexão com o banco de dados.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conexao->set_charset('utf8mb4');

/*
|----------------------------------------------------------------------
| FUNÇÕES
|----------------------------------------------------------------------
*/
function responder_json(int $httpCode, array $payload): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function obter_id_empresa_sessao(): ?int
{
    if (isset($_SESSION['auth']) && is_array($_SESSION['auth'])) {
        $auth = $_SESSION['auth'];

        foreach (['id_empresa', 'empresa_id'] as $chave) {
            if (isset($auth[$chave]) && is_numeric($auth[$chave]) && (int)$auth[$chave] > 0) {
                return (int)$auth[$chave];
            }
        }
    }

    $possiveisChaves = [
        'id_empresa',
        'empresa_id',
        'empresa',
        'empresa_atual',
        'id_empresa_atual',
        'suporte_id_empresa'
    ];

    foreach ($possiveisChaves as $chave) {
        if (isset($_SESSION[$chave]) && is_numeric($_SESSION[$chave]) && (int)$_SESSION[$chave] > 0) {
            return (int)$_SESSION[$chave];
        }
    }

    if (isset($_SESSION['empresa']) && is_array($_SESSION['empresa'])) {
        $empresaSessao = $_SESSION['empresa'];

        foreach (['id_empresa', 'empresa_id', 'id'] as $chave) {
            if (isset($empresaSessao[$chave]) && is_numeric($empresaSessao[$chave]) && (int)$empresaSessao[$chave] > 0) {
                return (int)$empresaSessao[$chave];
            }
        }
    }

    return null;
}

function obter_id_usuario_sessao(): int
{
    if (isset($_SESSION['auth']) && is_array($_SESSION['auth'])) {
        $auth = $_SESSION['auth'];

        foreach (['id_usuario', 'usuario_id', 'id'] as $chave) {
            if (isset($auth[$chave]) && is_numeric($auth[$chave]) && (int)$auth[$chave] > 0) {
                return (int)$auth[$chave];
            }
        }
    }

    foreach (['id_usuario', 'usuario_id'] as $chave) {
        if (isset($_SESSION[$chave]) && is_numeric($_SESSION[$chave]) && (int)$_SESSION[$chave] > 0) {
            return (int)$_SESSION[$chave];
        }
    }

    return 0;
}

function normalizar_texto(string $texto): string
{
    $texto = trim($texto);

    if ($texto === '') {
        return '';
    }

    return mb_strtolower($texto, 'UTF-8');
}

function normalizar_status(?string $status): string
{
    $status = trim((string)$status);
    return $status !== '' ? $status : 'ativo';
}

/*
|----------------------------------------------------------------------
| VALIDA SESSÃO
|----------------------------------------------------------------------
*/
$idEmpresa = obter_id_empresa_sessao();
$idUsuarioSessao = obter_id_usuario_sessao();

if (!$idEmpresa) {
    responder_json(401, [
        'ok' => false,
        'erro' => 'EMPRESA_NAO_IDENTIFICADA',
        'mensagem' => 'Empresa não identificada na sessão.'
    ]);
}

$profissionalLogado = [
    'eh_profissional' => false,
    'id_usuario' => $idUsuarioSessao,
    'id_profissional' => 0,
    'perfil' => '',
];

if ($idUsuarioSessao > 0) {
    $stmtProfissionalLogado = $conexao->prepare("
        SELECT
            eu.id_usuario,
            pf.nome AS perfil_nome,
            p.id_profissional
        FROM empresa_usuario eu
        LEFT JOIN perfil pf
               ON pf.id_perfil = eu.id_perfil
        LEFT JOIN profissional p
               ON p.id_usuario = eu.id_usuario
        WHERE eu.id_empresa = ?
          AND eu.id_usuario = ?
          AND eu.status = 'ativo'
        LIMIT 1
    ");

    if ($stmtProfissionalLogado) {
        $stmtProfissionalLogado->bind_param('ii', $idEmpresa, $idUsuarioSessao);

        if ($stmtProfissionalLogado->execute()) {
            $resProfissionalLogado = $stmtProfissionalLogado->get_result();
            $rowProfissionalLogado = $resProfissionalLogado ? $resProfissionalLogado->fetch_assoc() : null;

            if ($rowProfissionalLogado) {
                $perfilLogado = (string)($rowProfissionalLogado['perfil_nome'] ?? '');
                $perfilNormalizado = normalizar_texto($perfilLogado);
                $ehProfissional = in_array($perfilNormalizado, ['profissional', 'profissionais'], true);

                $profissionalLogado = [
                    'eh_profissional' => $ehProfissional,
                    'id_usuario' => (int)$rowProfissionalLogado['id_usuario'],
                    'id_profissional' => (int)($rowProfissionalLogado['id_profissional'] ?? 0),
                    'perfil' => $perfilLogado,
                ];
            }
        }

        $stmtProfissionalLogado->close();
    }
}

/*
|----------------------------------------------------------------------
| FILTROS
|----------------------------------------------------------------------
*/
$q = trim((string)($_GET['q'] ?? ''));
$status = normalizar_status($_GET['status'] ?? 'ativo');

$pagina = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$limite = isset($_GET['limite']) && is_numeric($_GET['limite']) ? (int)$_GET['limite'] : 200;

if ($limite < 1) {
    $limite = 200;
}

if ($limite > 500) {
    $limite = 500;
}

$offset = ($pagina - 1) * $limite;

/*
|----------------------------------------------------------------------
| CONSULTA
|----------------------------------------------------------------------
| Observação:
| A tabela profissional informada por você NÃO possui id_empresa.
| Por isso, o filtro da empresa é feito por empresa_usuario.
|
| usuario.tipo_usuario não identifica profissional.
| O profissional é identificado pela tabela profissional e pelo perfil.
*/
$sql = "
    SELECT
        p.id_profissional,
        p.id_usuario,
        p.especialidade,
        p.descricao,

        u.nome,
        u.email,
        u.telefone,
        u.foto_perfil,
        u.status,

        eu.id_empresa,
        eu.id_perfil,

        pf.nome AS perfil_nome

    FROM profissional p

    INNER JOIN usuario u
        ON u.id_usuario = p.id_usuario

    INNER JOIN empresa_usuario eu
        ON eu.id_usuario = u.id_usuario
        AND eu.id_empresa = ?

    LEFT JOIN perfil pf
        ON pf.id_perfil = eu.id_perfil

    WHERE u.status = ?
      AND eu.status = 'ativo'
      AND (
            pf.nome IS NULL
            OR LOWER(pf.nome) IN ('profissional', 'profissionais')
          )
";

$tipos = 'is';
$params = [$idEmpresa, $status];

/*
|----------------------------------------------------------------------
| RESTRIÇÃO PARA O PERFIL PROFISSIONAL
|----------------------------------------------------------------------
| Profissional visualiza somente o próprio cadastro no Novo Agendamento.
| Proprietário, recepção e demais perfis mantêm a listagem completa da
| empresa. A regra fica no backend para não depender de filtro visual no JS.
*/
if (($profissionalLogado['eh_profissional'] ?? false) === true) {
    $sql .= " AND p.id_usuario = ? ";
    $tipos .= 'i';
    $params[] = $idUsuarioSessao;
}

if ($q !== '') {
    $sql .= "
        AND (
            u.nome LIKE ?
            OR u.email LIKE ?
            OR u.telefone LIKE ?
            OR p.especialidade LIKE ?
        )
    ";

    $like = '%' . $q . '%';

    $tipos .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= "
    ORDER BY u.nome ASC
    LIMIT ? OFFSET ?
";

$tipos .= 'ii';
$params[] = $limite;
$params[] = $offset;

$stmt = $conexao->prepare($sql);

if (!$stmt) {
    responder_json(500, [
        'ok' => false,
        'erro' => 'ERRO_PREPARE',
        'mensagem' => 'Erro ao preparar consulta de profissionais.',
        'detalhe' => $conexao->error
    ]);
}

$stmt->bind_param($tipos, ...$params);

if (!$stmt->execute()) {
    responder_json(500, [
        'ok' => false,
        'erro' => 'ERRO_EXECUTE',
        'mensagem' => 'Erro ao executar consulta de profissionais.',
        'detalhe' => $stmt->error
    ]);
}

$result = $stmt->get_result();

$dados = [];

while ($row = $result->fetch_assoc()) {
    $dados[] = [
        'id_profissional' => (int)$row['id_profissional'],
        'id_usuario' => (int)$row['id_usuario'],
        'nome' => $row['nome'],
        'email' => $row['email'],
        'telefone' => $row['telefone'],
        'foto_perfil' => $row['foto_perfil'],
        'especialidade' => $row['especialidade'],
        'descricao' => $row['descricao'],
        'status' => $row['status'],
        'perfil' => $row['perfil_nome'] ?? 'Profissional',
    ];
}

$stmt->close();

responder_json(200, [
    'ok' => true,
    'pagina' => $pagina,
    'limite' => $limite,
    'total_retornado' => count($dados),
    'usuario_logado' => [
        'id_usuario' => $idUsuarioSessao,
    ],
    'profissional_logado' => $profissionalLogado,
    'data' => $dados
]);
