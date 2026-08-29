<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_regras/permissoes_usuario.php';
require_once __DIR__ . '/../../_regras/catalogo_auditoria.php';

const AUDITORIA_LISTA_PERIODO_PADRAO_DIAS = 7;
const AUDITORIA_LISTA_PERIODO_MAXIMO_DIAS = 90;
const AUDITORIA_LISTA_BUSCA_MAX = 100;

function auditoriaListaData(string $valor, bool $fimDoDia = false): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) return null;
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $erros = DateTimeImmutable::getLastErrors();
    if (!$data || (is_array($erros) && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))) return null;
    return $fimDoDia ? $data->setTime(23, 59, 59, 999999) : $data->setTime(0, 0, 0, 0);
}

function auditoriaListaBase64UrlEncode(string $valor): string
{
    return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
}

function auditoriaListaBase64UrlDecode(string $valor): ?string
{
    if ($valor === '' || strlen($valor) > 300 || !preg_match('/^[A-Za-z0-9_-]+$/', $valor)) return null;
    $resto = strlen($valor) % 4;
    if ($resto > 0) $valor .= str_repeat('=', 4 - $resto);
    $decodificado = base64_decode(strtr($valor, '-_', '+/'), true);
    return is_string($decodificado) ? $decodificado : null;
}

function auditoriaListaCriarCursor(string $ocorridoEm, int $id): string
{
    return auditoriaListaBase64UrlEncode(json_encode(
        ['ocorrido_em' => $ocorridoEm, 'id' => $id],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));
}

function auditoriaListaLerCursor(string $cursor): ?array
{
    $json = auditoriaListaBase64UrlDecode($cursor);
    if ($json === null) return null;

    try {
        $dados = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    if (!is_array($dados) || array_keys($dados) !== ['ocorrido_em', 'id']) return null;
    $data = (string)($dados['ocorrido_em'] ?? '');
    $id = filter_var($dados['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $objetoData = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $data);
    $erros = DateTimeImmutable::getLastErrors();
    if (!$objetoData || (is_array($erros) && ($erros['warning_count'] > 0 || $erros['error_count'] > 0)) || $id === false) return null;

    return ['ocorrido_em' => $objetoData->format('Y-m-d H:i:s.u'), 'id' => (int)$id];
}

function auditoriaListaJsonObjeto(mixed $valor, int $idAuditoria, string $campo): array
{
    if ($valor === null || $valor === '') return [];
    try {
        $dados = json_decode((string)$valor, true, 32, JSON_THROW_ON_ERROR);
        return is_array($dados) ? $dados : [];
    } catch (JsonException $e) {
        // Um registro inconsistente não pode derrubar a timeline inteira nem expor o erro ao navegador.
        error_log("[lista_auditoria] JSON inválido em {$campo}, auditoria {$idAuditoria}: " . $e->getMessage());
        return [];
    }
}

function auditoriaListaBind(mysqli_stmt $stmt, string $tipos, array &$valores): void
{
    if ($tipos === '') return;
    $argumentos = [$tipos];
    foreach ($valores as $indice => &$valor) $argumentos[] = &$valor;
    if (!call_user_func_array([$stmt, 'bind_param'], $argumentos)) {
        throw new RuntimeException('Falha ao vincular os filtros da auditoria.');
    }
}

function auditoriaListaBuscaSemAcentos(string $valor): string
{
    $valor = mb_strtolower(trim($valor), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    return is_string($ascii) ? $ascii : $valor;
}

function auditoriaListaDataBusca(string $valor): ?DateTimeImmutable
{
    foreach (['!d/m/Y', '!Y-m-d'] as $formato) {
        $data = DateTimeImmutable::createFromFormat($formato, $valor);
        $erros = DateTimeImmutable::getLastErrors();
        if ($data && (!is_array($erros) || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))) return $data;
    }
    return null;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','user_msg'=>'Método não permitido.'], 405);
    }

    /* A rota central já exige a permissão, e a repetição aqui protege também
       uma eventual chamada direta ao handler. O frontend nunca é autoridade. */
    $ctx = permissoesContexto($conexao);
    if (!($ctx['valido'] ?? false)) {
        out(['ok'=>false,'code'=>'COMPANY_ACCESS_DENIED','user_msg'=>'Contexto empresarial não autorizado.'], 403);
    }
    exigirPermissao($conexao, 'auditoria.visualizar');
    $idEmpresa = (int)($ctx['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) {
        out(['ok'=>false,'code'=>'SESSION_WITHOUT_COMPANY','user_msg'=>'Empresa da sessão não identificada.'], 403);
    }

    $agora = new DateTimeImmutable('now');
    $inicioRaw = trim((string)($_GET['inicio'] ?? ''));
    $fimRaw = trim((string)($_GET['fim'] ?? ''));
    $inicio = $inicioRaw === ''
        ? $agora->modify('-' . (AUDITORIA_LISTA_PERIODO_PADRAO_DIAS - 1) . ' days')->setTime(0, 0)
        : auditoriaListaData($inicioRaw);
    $fim = $fimRaw === '' ? $agora : auditoriaListaData($fimRaw, true);
    if (!$inicio || !$fim) {
        out(['ok'=>false,'code'=>'INVALID_DATE_FILTER','user_msg'=>'Informe datas válidas no formato AAAA-MM-DD.'], 422);
    }
    if ($inicio > $fim) {
        out(['ok'=>false,'code'=>'INVALID_DATE_RANGE','user_msg'=>'A data inicial não pode ser posterior à data final.'], 422);
    }
    if ($inicio->diff($fim)->days > AUDITORIA_LISTA_PERIODO_MAXIMO_DIAS) {
        out(['ok'=>false,'code'=>'DATE_RANGE_TOO_LARGE','user_msg'=>'O período da auditoria pode abranger no máximo 90 dias.'], 422);
    }

    $catalogo = auditoriaCatalogo();
    $modulos = [];
    $entidades = [];
    foreach ($catalogo as $definicao) {
        $modulos[(string)$definicao['modulo']] = true;
        $entidades[(string)$definicao['entidade']] = true;
    }

    $modulo = trim((string)($_GET['modulo'] ?? ''));
    $evento = trim((string)($_GET['evento'] ?? ''));
    $entidade = trim((string)($_GET['entidade'] ?? ''));
    $atorIdRaw = trim((string)($_GET['ator_id'] ?? ''));
    $idAuditoriaRaw = trim((string)($_GET['id_auditoria'] ?? ''));
    $busca = trim((string)($_GET['q'] ?? $_GET['busca'] ?? ''));
    $ordem = trim((string)($_GET['ordem'] ?? 'recentes'));
    $limiteRaw = trim((string)($_GET['limite'] ?? '20'));
    $cursorRaw = trim((string)($_GET['cursor'] ?? ''));

    if ($modulo !== '' && !isset($modulos[$modulo])) out(['ok'=>false,'code'=>'INVALID_MODULE','user_msg'=>'Módulo de auditoria inválido.'], 422);
    if ($evento !== '' && !isset($catalogo[$evento])) out(['ok'=>false,'code'=>'INVALID_EVENT','user_msg'=>'Evento de auditoria inválido.'], 422);
    if ($entidade !== '' && !isset($entidades[$entidade])) out(['ok'=>false,'code'=>'INVALID_ENTITY','user_msg'=>'Entidade de auditoria inválida.'], 422);
    if (mb_strlen($busca, 'UTF-8') > AUDITORIA_LISTA_BUSCA_MAX) out(['ok'=>false,'code'=>'SEARCH_TOO_LONG','user_msg'=>'A pesquisa deve ter no máximo 100 caracteres.'], 422);

    $atorId = null;
    if ($atorIdRaw !== '') {
        $atorId = filter_var($atorIdRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($atorId === false) out(['ok'=>false,'code'=>'INVALID_ACTOR','user_msg'=>'Usuário do filtro inválido.'], 422);
        $atorId = (int)$atorId;
    }
    $idAuditoria = null;
    if ($idAuditoriaRaw !== '') {
        $idAuditoria = filter_var($idAuditoriaRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($idAuditoria === false) out(['ok'=>false,'code'=>'INVALID_AUDIT_ID','user_msg'=>'Identificador da auditoria inválido.'], 422);
        $idAuditoria = (int)$idAuditoria;
    }
    if (!in_array($ordem, ['recentes', 'antigos'], true)) out(['ok'=>false,'code'=>'INVALID_ORDER','user_msg'=>'Ordenação da auditoria inválida.'], 422);
    if ($limiteRaw !== '20') out(['ok'=>false,'code'=>'INVALID_LIMIT','user_msg'=>'A auditoria exibe 20 registros por página.'], 422);
    $limite = (int)$limiteRaw;
    $cursor = $cursorRaw === '' ? null : auditoriaListaLerCursor($cursorRaw);
    if ($cursorRaw !== '' && $cursor === null) out(['ok'=>false,'code'=>'INVALID_CURSOR','user_msg'=>'Cursor de paginação inválido.'], 422);

    /* id_empresa é sempre o primeiro predicado e provém exclusivamente do
       contexto autenticado. Nenhum parâmetro pode trocar a empresa consultada. */
    $where = ['id_empresa = ?', "origem <> 'autenticacao'", 'ocorrido_em >= ?', 'ocorrido_em <= ?'];
    $tipos = 'iss';
    $parametros = [$idEmpresa, $inicio->format('Y-m-d H:i:s.u'), $fim->format('Y-m-d H:i:s.u')];

    if ($idAuditoria !== null) { $where[] = 'id_auditoria = ?'; $tipos .= 'i'; $parametros[] = $idAuditoria; }
    if ($atorId !== null) { $where[] = 'id_ator = ?'; $tipos .= 'i'; $parametros[] = $atorId; }
    if ($modulo !== '') { $where[] = 'modulo = ?'; $tipos .= 's'; $parametros[] = $modulo; }
    if ($evento !== '') { $where[] = 'evento_codigo = ?'; $tipos .= 's'; $parametros[] = $evento; }
    if ($entidade !== '') { $where[] = 'entidade_tipo = ?'; $tipos .= 's'; $parametros[] = $entidade; }
    if ($busca !== '') {
        $camposBusca = ['ator_nome', 'ator_perfil', 'ator_tipo', 'entidade_rotulo', 'entidade_tipo', 'descricao', 'modulo', 'evento_codigo'];
        $partesBusca = array_map(static fn(string $campo): string => "{$campo} LIKE ?", $camposBusca);
        $termo = '%' . $busca . '%';
        foreach ($camposBusca as $_) { $tipos .= 's'; $parametros[] = $termo; }

        if (ctype_digit($busca)) {
            $partesBusca[] = 'entidade_id = ?';
            $tipos .= 'i';
            $parametros[] = (int)$busca;
        }

        $dataBusca = auditoriaListaDataBusca($busca);
        if ($dataBusca) {
            $partesBusca[] = '(ocorrido_em >= ? AND ocorrido_em < ?)';
            $tipos .= 'ss';
            $parametros[] = $dataBusca->format('Y-m-d 00:00:00.000000');
            $parametros[] = $dataBusca->modify('+1 day')->format('Y-m-d 00:00:00.000000');
        }

        // Rótulos exibidos são convertidos em códigos do catálogo sem alterar o contrato da rota.
        $buscaNormalizada = auditoriaListaBuscaSemAcentos($busca);
        foreach ($catalogo as $codigo => $definicao) {
            $rotulos = [
                auditoriaListaBuscaSemAcentos((string)$codigo),
                auditoriaListaBuscaSemAcentos(str_replace(['.', '_'], ' ', (string)$codigo)),
                auditoriaListaBuscaSemAcentos((string)$definicao['modulo']),
                auditoriaListaBuscaSemAcentos((string)$definicao['entidade']),
                auditoriaListaBuscaSemAcentos((string)$definicao['descricao_padrao']),
            ];
            if (array_filter($rotulos, static fn(string $rotulo): bool => str_contains($rotulo, $buscaNormalizada))) {
                $partesBusca[] = 'evento_codigo = ?';
                $tipos .= 's';
                $parametros[] = (string)$codigo;
            }
        }
        $where[] = '(' . implode(' OR ', $partesBusca) . ')';
    }
    if ($cursor !== null) {
        // A dupla data+ID mantém ordem determinística mesmo quando eventos compartilham o timestamp.
        $operadorCursor = $ordem === 'antigos' ? '>' : '<';
        $where[] = "(ocorrido_em {$operadorCursor} ? OR (ocorrido_em = ? AND id_auditoria {$operadorCursor} ?))";
        $tipos .= 'ssi';
        array_push($parametros, $cursor['ocorrido_em'], $cursor['ocorrido_em'], $cursor['id']);
    }

    $limiteConsulta = $limite + 1;
    $tipos .= 'i';
    $parametros[] = $limiteConsulta;
    $direcaoSql = $ordem === 'antigos' ? 'ASC' : 'DESC';
    $sql = 'SELECT id_auditoria,ator_tipo,id_ator,ator_nome,ator_perfil,modo_suporte,evento_codigo,modulo,entidade_tipo,entidade_id,entidade_rotulo,descricao,alteracoes,contexto,ocorrido_em FROM auditoria WHERE '
        . implode(' AND ', $where)
        . " ORDER BY ocorrido_em {$direcaoSql}, id_auditoria {$direcaoSql} LIMIT ?";
    // A direção aceita somente os dois valores validados acima; nomes de coluna permanecem fixos.

    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar a consulta da auditoria.');
    auditoriaListaBind($stmt, $tipos, $parametros);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $linhas = [];
    while ($linha = $resultado->fetch_assoc()) $linhas[] = $linha;
    $stmt->close();

    $temMais = count($linhas) > $limite;
    if ($temMais) array_pop($linhas);
    $itens = [];
    foreach ($linhas as $linha) {
        $id = (int)$linha['id_auditoria'];
        $ocorrido = (string)$linha['ocorrido_em'];
        $itens[] = [
            'id' => $id,
            'ocorrido_em' => str_replace(' ', 'T', $ocorrido),
            'ator' => [
                'tipo' => (string)$linha['ator_tipo'],
                'id' => $linha['id_ator'] === null ? null : (int)$linha['id_ator'],
                'nome' => (string)$linha['ator_nome'],
                'perfil' => (string)$linha['ator_perfil'],
                'modo_suporte' => (bool)$linha['modo_suporte'],
            ],
            'evento' => ['codigo'=>(string)$linha['evento_codigo'],'modulo'=>(string)$linha['modulo']],
            'entidade' => [
                'tipo' => (string)$linha['entidade_tipo'],
                'id' => $linha['entidade_id'] === null ? null : (int)$linha['entidade_id'],
                'rotulo' => $linha['entidade_rotulo'] === null ? null : (string)$linha['entidade_rotulo'],
            ],
            'descricao' => (string)$linha['descricao'],
            'alteracoes' => auditoriaListaJsonObjeto($linha['alteracoes'], $id, 'alteracoes'),
            'contexto' => auditoriaListaJsonObjeto($linha['contexto'], $id, 'contexto'),
        ];
    }

    $proximoCursor = null;
    if ($temMais && $linhas !== []) {
        $ultima = $linhas[array_key_last($linhas)];
        $proximoCursor = auditoriaListaCriarCursor((string)$ultima['ocorrido_em'], (int)$ultima['id_auditoria']);
    }

    out([
        'ok'=>true,
        'code'=>'AUDITORIA_LISTADA',
        'data'=>['items'=>$itens],
        'meta'=>[
            'periodo'=>['inicio'=>$inicio->format('Y-m-d'),'fim'=>$fim->format('Y-m-d')],
            'ordem'=>$ordem,
            'limite'=>$limite,
            'tem_mais'=>$temMais,
            'proximo_cursor'=>$proximoCursor,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[lista_auditoria] ' . $e->getMessage());
    out(['ok'=>false,'code'=>'AUDITORIA_LIST_ERROR','user_msg'=>'Não foi possível consultar a auditoria.'], 500);
}
