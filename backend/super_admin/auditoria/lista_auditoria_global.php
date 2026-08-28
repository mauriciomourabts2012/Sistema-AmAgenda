<?php
declare(strict_types=1);

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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','user_msg'=>'Método não permitido.'], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';
require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_regras/catalogo_auditoria.php';

const AUDITORIA_GLOBAL_PERIODO_PADRAO_DIAS = 7;
const AUDITORIA_GLOBAL_PERIODO_MAX_DIAS = 90;
const AUDITORIA_GLOBAL_BUSCA_MAX = 100;

function auditoriaGlobalBase64UrlEncode(string $valor): string
{
    return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
}

function auditoriaGlobalBase64UrlDecode(string $valor): ?string
{
    if ($valor === '' || preg_match('/^[A-Za-z0-9_-]+$/', $valor) !== 1) return null;
    $resto = strlen($valor) % 4;
    if ($resto > 0) $valor .= str_repeat('=', 4 - $resto);
    $decodificado = base64_decode(strtr($valor, '-_', '+/'), true);
    return is_string($decodificado) ? $decodificado : null;
}

function auditoriaGlobalLerCursor(string $cursor): ?array
{
    $json = auditoriaGlobalBase64UrlDecode($cursor);
    if ($json === null) return null;
    try { $dados = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
    catch (Throwable) { return null; }
    if (!is_array($dados) || !isset($dados['ocorrido_em'], $dados['id'])) return null;
    $data = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string)$dados['ocorrido_em']);
    $id = filter_var($dados['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$data || $id === false) return null;
    return ['ocorrido_em'=>$data->format('Y-m-d H:i:s.u'),'id'=>(int)$id];
}

function auditoriaGlobalCursor(array $linha): string
{
    return auditoriaGlobalBase64UrlEncode(json_encode([
        'ocorrido_em'=>(string)$linha['ocorrido_em'],
        'id'=>(int)$linha['id_auditoria'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function auditoriaGlobalData(string $valor, bool $fim): DateTimeImmutable
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $erros = DateTimeImmutable::getLastErrors();
    if (!$data || (is_array($erros) && (($erros['warning_count'] ?? 0) > 0 || ($erros['error_count'] ?? 0) > 0))) {
        throw new InvalidArgumentException('INVALID_DATE');
    }
    return $fim ? $data->setTime(23, 59, 59, 999999) : $data->setTime(0, 0, 0, 0);
}

try {
    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out(['ok'=>false,'code'=>'DB_CONN_ERROR','user_msg'=>'Falha ao conectar no banco.'], 500);
    }
    $conexao->set_charset('utf8mb4');
    $hoje = new DateTimeImmutable('today');
    $inicioRaw = trim((string)($_GET['inicio'] ?? $hoje->modify('-' . (AUDITORIA_GLOBAL_PERIODO_PADRAO_DIAS - 1) . ' days')->format('Y-m-d')));
    $fimRaw = trim((string)($_GET['fim'] ?? $hoje->format('Y-m-d')));
    $inicio = auditoriaGlobalData($inicioRaw, false);
    $fim = auditoriaGlobalData($fimRaw, true);
    if ($inicio > $fim) out(['ok'=>false,'code'=>'INVALID_PERIOD','user_msg'=>'A data inicial não pode ser posterior à final.'], 422);
    if ($inicio->diff($fim)->days + 1 > AUDITORIA_GLOBAL_PERIODO_MAX_DIAS) out(['ok'=>false,'code'=>'PERIOD_TOO_LONG','user_msg'=>'O período máximo para consulta é de 90 dias.'], 422);

    $catalogo = auditoriaCatalogo();
    $modulos = [];
    foreach ($catalogo as $definicao) $modulos[(string)$definicao['modulo']] = true;
    $empresaRaw = trim((string)($_GET['empresa_id'] ?? ''));
    $atorRaw = trim((string)($_GET['ator'] ?? ''));
    $modulo = trim((string)($_GET['modulo'] ?? ''));
    $evento = trim((string)($_GET['evento'] ?? ''));
    $origem = trim((string)($_GET['origem'] ?? ''));
    $busca = trim((string)($_GET['q'] ?? ''));
    $ordem = trim((string)($_GET['ordem'] ?? 'recentes'));
    $limiteRaw = trim((string)($_GET['limite'] ?? '20'));
    $cursorRaw = trim((string)($_GET['cursor'] ?? ''));

    $empresaId = $empresaRaw === '' ? null : filter_var($empresaRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($empresaRaw !== '' && $empresaId === false) out(['ok'=>false,'code'=>'INVALID_COMPANY','user_msg'=>'Empresa inválida.'], 422);
    if ($modulo !== '' && !isset($modulos[$modulo])) out(['ok'=>false,'code'=>'INVALID_MODULE','user_msg'=>'Módulo inválido.'], 422);
    if ($evento !== '' && !isset($catalogo[$evento])) out(['ok'=>false,'code'=>'INVALID_EVENT','user_msg'=>'Evento inválido.'], 422);
    if (!in_array($origem, ['', 'empresa', 'plataforma', 'modo_suporte', 'autenticacao'], true)) out(['ok'=>false,'code'=>'INVALID_ORIGIN','user_msg'=>'Origem inválida.'], 422);
    if (!in_array($ordem, ['recentes','antigos'], true)) out(['ok'=>false,'code'=>'INVALID_ORDER','user_msg'=>'Ordenação inválida.'], 422);
    if (!in_array($limiteRaw, ['20','50','100'], true)) out(['ok'=>false,'code'=>'INVALID_LIMIT','user_msg'=>'Quantidade por página inválida.'], 422);
    if (mb_strlen($busca, 'UTF-8') > AUDITORIA_GLOBAL_BUSCA_MAX) out(['ok'=>false,'code'=>'SEARCH_TOO_LONG','user_msg'=>'A pesquisa deve ter no máximo 100 caracteres.'], 422);
    $limite = (int)$limiteRaw;
    $cursor = $cursorRaw === '' ? null : auditoriaGlobalLerCursor($cursorRaw);
    if ($cursorRaw !== '' && $cursor === null) out(['ok'=>false,'code'=>'INVALID_CURSOR','user_msg'=>'Cursor inválido.'], 422);

    $where = ['a.ocorrido_em >= ?', 'a.ocorrido_em <= ?'];
    $tipos = 'ss';
    $parametros = [$inicio->format('Y-m-d H:i:s.u'), $fim->format('Y-m-d H:i:s.u')];
    if ($empresaId !== null) { $where[]='a.id_empresa=?'; $tipos.='i'; $parametros[]=(int)$empresaId; }
    if ($atorRaw !== '') {
        if ($atorRaw === 'nao_autenticado') $where[]="a.ator_tipo='nao_autenticado'";
        else {
            $atorId = filter_var($atorRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($atorId === false) out(['ok'=>false,'code'=>'INVALID_ACTOR','user_msg'=>'Ator inválido.'], 422);
            $where[]='a.id_ator=?'; $tipos.='i'; $parametros[]=(int)$atorId;
        }
    }
    if ($modulo !== '') { $where[]='a.modulo=?'; $tipos.='s'; $parametros[]=$modulo; }
    if ($evento !== '') { $where[]='a.evento_codigo=?'; $tipos.='s'; $parametros[]=$evento; }
    if ($origem !== '') { $where[]='a.origem=?'; $tipos.='s'; $parametros[]=$origem; }
    if ($busca !== '') {
        $termo = '%' . $busca . '%';
        $campos = ['e.nome','a.ator_nome','a.ator_perfil','a.entidade_rotulo','a.entidade_tipo','a.descricao','a.evento_codigo','a.modulo'];
        $where[] = '(' . implode(' OR ', array_map(static fn(string $campo): string => $campo . ' LIKE ?', $campos)) . ')';
        foreach ($campos as $_) { $tipos.='s'; $parametros[]=$termo; }
    }
    if ($cursor !== null) {
        $operador = $ordem === 'antigos' ? '>' : '<';
        $where[]="(a.ocorrido_em {$operador} ? OR (a.ocorrido_em=? AND a.id_auditoria {$operador} ?))";
        $tipos.='ssi'; array_push($parametros,$cursor['ocorrido_em'],$cursor['ocorrido_em'],$cursor['id']);
    }

    $direcao = $ordem === 'antigos' ? 'ASC' : 'DESC';
    $limiteConsulta = $limite + 1;
    $tipos .= 'i'; $parametros[]=$limiteConsulta;
    $sql = 'SELECT a.id_auditoria,a.id_empresa,e.nome AS empresa_nome,a.ator_tipo,a.id_ator,a.ator_nome,a.ator_perfil,a.modo_suporte,a.origem,a.evento_codigo,a.modulo,a.entidade_tipo,a.entidade_id,a.entidade_rotulo,a.descricao,a.alteracoes,a.contexto,a.ocorrido_em FROM auditoria a LEFT JOIN empresa e ON e.id_empresa=a.id_empresa WHERE '
        . implode(' AND ', $where) . " ORDER BY a.ocorrido_em {$direcao},a.id_auditoria {$direcao} LIMIT ?";
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar consulta global.');
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $linhas = [];
    while ($linha=$resultado->fetch_assoc()) $linhas[]=$linha;
    $stmt->close();
    $temMais = count($linhas) > $limite;
    if ($temMais) array_pop($linhas);

    $itens = [];
    foreach ($linhas as $linha) {
        $itens[] = [
            'id'=>(int)$linha['id_auditoria'],
            'ocorrido_em'=>str_replace(' ','T',(string)$linha['ocorrido_em']),
            'origem'=>(string)$linha['origem'],
            'empresa'=>$linha['id_empresa'] === null ? null : ['id'=>(int)$linha['id_empresa'],'nome'=>(string)($linha['empresa_nome'] ?? '')],
            'ator'=>['tipo'=>(string)$linha['ator_tipo'],'id'=>$linha['id_ator']===null?null:(int)$linha['id_ator'],'nome'=>(string)$linha['ator_nome'],'perfil'=>(string)$linha['ator_perfil'],'modo_suporte'=>(bool)$linha['modo_suporte']],
            'evento'=>['codigo'=>(string)$linha['evento_codigo'],'modulo'=>(string)$linha['modulo']],
            'entidade'=>['tipo'=>(string)$linha['entidade_tipo'],'id'=>$linha['entidade_id']===null?null:(int)$linha['entidade_id'],'rotulo'=>$linha['entidade_rotulo']],
            'descricao'=>(string)$linha['descricao'],
            'alteracoes'=>$linha['alteracoes']===null?null:json_decode((string)$linha['alteracoes'],true),
            'contexto'=>$linha['contexto']===null?null:json_decode((string)$linha['contexto'],true),
        ];
    }

    $empresas=[];
    $resEmpresas=$conexao->query('SELECT id_empresa,nome FROM empresa ORDER BY nome,id_empresa');
    while($linha=$resEmpresas->fetch_assoc()) $empresas[]=['id'=>(int)$linha['id_empresa'],'nome'=>(string)$linha['nome']];
    $atores=[];
    $resAtores=$conexao->query("SELECT id_ator,MAX(ator_nome) ator_nome FROM auditoria WHERE id_ator IS NOT NULL GROUP BY id_ator ORDER BY ator_nome,id_ator LIMIT 500");
    while($linha=$resAtores->fetch_assoc()) $atores[]=['id'=>(int)$linha['id_ator'],'nome'=>(string)$linha['ator_nome']];

    out(['ok'=>true,'code'=>'AUDIT_GLOBAL_LISTED','data'=>['items'=>$itens,'filters'=>['empresas'=>$empresas,'atores'=>$atores]],'meta'=>[
        'periodo'=>['inicio'=>$inicio->format('Y-m-d'),'fim'=>$fim->format('Y-m-d')],
        'ordem'=>$ordem,'limite'=>$limite,'tem_mais'=>$temMais,
        'proximo_cursor'=>$temMais && $linhas !== [] ? auditoriaGlobalCursor($linhas[array_key_last($linhas)]) : null,
    ]]);
} catch (InvalidArgumentException $e) {
    out(['ok'=>false,'code'=>'INVALID_DATE','user_msg'=>'Data de auditoria inválida.'], 422);
} catch (Throwable $e) {
    error_log('[auditoria_global] ' . $e->getMessage());
    out(['ok'=>false,'code'=>'AUDIT_GLOBAL_ERROR','user_msg'=>'Não foi possível consultar a auditoria global.'], 500);
}
