<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PESQUISAR AGENDAMENTOS
|--------------------------------------------------------------------------
| Busca paginada em toda a agenda da empresa. O perfil Profissional acessa
| somente os próprios registros. O resultado serve como localizador para a
| semana, data e ocorrência exatas na tela da agenda.
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $auth = $_SESSION['auth'] ?? [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $idEmpresa = (int)($auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? $_SESSION['id_empresa'] ?? $_SESSION['empresa']['id_empresa'] ?? 0);
    $termo = trim((string)($_GET['q'] ?? ''));
    $pagina = max(1, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1);
    $limite = min(20, max(5, filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT) ?: 10));
    $offset = ($pagina - 1) * $limite;

    if ($idUsuario <= 0) out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
    if ($idEmpresa <= 0) out(['ok' => false, 'code' => 'SESSION_WITHOUT_COMPANY', 'user_msg' => 'Empresa da sessão não identificada.'], 403);
    if ($termo !== '' && mb_strlen($termo) < 2) out(['ok' => false, 'code' => 'SEARCH_TERM_TOO_SHORT', 'user_msg' => 'Digite pelo menos 2 caracteres para pesquisar.'], 422);
    if (mb_strlen($termo) > 100) out(['ok' => false, 'code' => 'SEARCH_TERM_TOO_LONG', 'user_msg' => 'A pesquisa deve ter no máximo 100 caracteres.'], 422);

    require __DIR__ . '/../_config/conexao.php';
    $conexao->set_charset('utf8mb4');

    $stmt = $conexao->prepare("SELECT pf.nome,p.id_profissional FROM empresa_usuario eu INNER JOIN empresa e ON e.id_empresa=eu.id_empresa INNER JOIN perfil pf ON pf.id_perfil=eu.id_perfil LEFT JOIN profissional p ON p.id_usuario=eu.id_usuario WHERE eu.id_empresa=? AND eu.id_usuario=? AND eu.status='ativo' AND e.status='ativo' AND pf.status='ativo' LIMIT 1");
    $stmt->bind_param('ii', $idEmpresa, $idUsuario);
    $stmt->execute();
    $stmt->bind_result($perfilNome, $idProfissionalSessao);
    $vinculo = $stmt->fetch();
    $stmt->close();
    if (!$vinculo) out(['ok' => false, 'code' => 'COMPANY_ACCESS_DENIED', 'user_msg' => 'Acesso à empresa não autorizado.'], 403);

    $somenteProprio = in_array(mb_strtolower(trim((string)$perfilNome)), ['profissional', 'profissionais'], true);
    if ($somenteProprio && (int)$idProfissionalSessao <= 0) out(['ok' => false, 'code' => 'PROFESSIONAL_NOT_LINKED', 'user_msg' => 'Seu usuário não possui cadastro profissional vinculado.'], 403);

    $joins = " FROM agendamento a INNER JOIN cliente c ON c.id_cliente=a.id_cliente AND c.id_empresa=a.id_empresa INNER JOIN profissional p ON p.id_profissional=a.id_profissional INNER JOIN usuario u ON u.id_usuario=p.id_usuario INNER JOIN empresa_usuario eup ON eup.id_usuario=p.id_usuario AND eup.id_empresa=a.id_empresa AND eup.status='ativo' INNER JOIN servico s ON s.id_servico=a.id_servico AND s.id_profissional=a.id_profissional AND s.id_empresa=a.id_empresa ";
    $where = ' WHERE a.id_empresa=? ';
    $tipos = 'i';
    $parametros = [$idEmpresa];
    if ($termo !== '') {
        $where .= " AND (CAST(a.id_agendamento AS CHAR) LIKE ? OR c.nome_completo LIKE ? OR COALESCE(c.whatsapp_celular,'') LIKE ? OR u.nome LIKE ? OR s.nome LIKE ? OR a.status LIKE ? OR COALESCE(a.observacao,'') LIKE ? OR DATE_FORMAT(a.data_agendamento,'%d/%m/%Y') LIKE ? OR DATE_FORMAT(a.data_agendamento,'%Y-%m-%d') LIKE ? OR DATE_FORMAT(a.hora_inicio,'%H:%i') LIKE ?) ";
        $like = '%' . $termo . '%';
        $tipos .= 'ssssssssss';
        array_push($parametros, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($somenteProprio) {
        $where .= ' AND a.id_profissional=? ';
        $tipos .= 'i';
        $parametros[] = (int)$idProfissionalSessao;
    }

    $stmt = $conexao->prepare('SELECT COUNT(*)' . $joins . $where);
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $stmt->bind_result($total);
    $stmt->fetch();
    $stmt->close();

    // A abertura sem termo ordena o conjunto na fonte; buscas digitadas mantêm
    // a ordem cronológica já utilizada pelo localizador de ocorrências.
    $ordenacao = $termo === ''
        ? ' ORDER BY c.nome_completo ASC,a.data_agendamento DESC,a.hora_inicio ASC,a.id_agendamento DESC '
        : ' ORDER BY a.data_agendamento DESC,a.hora_inicio ASC,a.id_agendamento DESC ';
    $sql = "SELECT a.id_agendamento,DATE_FORMAT(a.data_agendamento,'%Y-%m-%d') data_agendamento,DATE_FORMAT(a.hora_inicio,'%H:%i') hora_inicio,DATE_FORMAT(a.hora_fim,'%H:%i') hora_fim,a.status,c.nome_completo cliente_nome,c.whatsapp_celular cliente_telefone,u.nome profissional_nome,s.nome servico_nome" . $joins . $where . $ordenacao . 'LIMIT ? OFFSET ?';
    $tiposLista = $tipos . 'ii';
    $parametrosLista = [...$parametros, $limite, $offset];
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($tiposLista, ...$parametrosLista);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $itens = [];
    while ($row = $resultado->fetch_assoc()) {
        $itens[] = [
            'id_agendamento' => (int)$row['id_agendamento'],
            'data_agendamento' => (string)$row['data_agendamento'],
            'hora_inicio' => (string)$row['hora_inicio'],
            'hora_fim' => (string)$row['hora_fim'],
            'status' => (string)$row['status'],
            'cliente_nome' => (string)$row['cliente_nome'],
            'cliente_telefone' => (string)($row['cliente_telefone'] ?? ''),
            'profissional_nome' => (string)$row['profissional_nome'],
            'servico_nome' => (string)$row['servico_nome'],
        ];
    }
    $stmt->close();

    out(['ok' => true, 'code' => 'APPOINTMENTS_FOUND', 'data' => ['items' => $itens], 'meta' => ['total' => (int)$total, 'pagina' => $pagina, 'limite' => $limite, 'total_paginas' => max(1, (int)ceil((int)$total / $limite))]]);
} catch (Throwable $e) {
    error_log('[pesquisar_agendamento] ' . $e->getMessage());
    out(['ok' => false, 'code' => 'INTERNAL_ERROR', 'user_msg' => 'Não foi possível pesquisar os agendamentos.'], 500);
}
