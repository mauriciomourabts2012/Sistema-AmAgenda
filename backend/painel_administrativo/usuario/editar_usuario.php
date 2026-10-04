<?php
declare(strict_types=1);

/**
 * ==========================================================
 * editar_usuario.php
 * Rota: painel/usuario/editar
 * Método: POST
 * ----------------------------------------------------------
 * Atualiza somente o vínculo da empresa ativa (perfil + status + especialidade).
 * Cria o cadastro profissional global apenas quando a mudança para o perfil
 * Profissional exige esse cadastro e a especialidade foi informada.
 *
 * Regras:
 * - id_usuario obrigatório
 * - perfil obrigatório
 * - status obrigatório
 * - dados globais e senha não são alterados por este endpoint
 * - edição limitada à empresa da sessão
 * - não permite editar usuário super_admin por este endpoint
 * ==========================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
date_default_timezone_set('America/Sao_Paulo');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('s')) {
    function s(mixed $v, int $max = 0): string
    {
        $v = trim((string)$v);
        if ($max > 0 && mb_strlen($v) > $max) {
            $v = mb_substr($v, 0, $max);
        }
        return $v;
    }
}

if (!function_exists('lower')) {
    function lower(mixed $v, int $max = 0): string
    {
        return mb_strtolower(s($v, $max), 'UTF-8');
    }
}

if (!function_exists('onlyDigits')) {
    function onlyDigits(?string $v): string
    {
        return preg_replace('/\D+/', '', (string)$v) ?? '';
    }
}

if (!function_exists('intPost')) {
    function intPost(string $key): ?int
    {
        $raw = $_POST[$key] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        $raw = trim((string)$raw);
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        $n = (int)$raw;
        return $n > 0 ? $n : null;
    }
}

if (!function_exists('sessionValue')) {
    function sessionValue(array $source, array $paths): mixed
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $value = $source;
            $ok = true;

            foreach ($segments as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                return $value;
            }
        }

        return null;
    }
}

/* ==========================================================
   MÉTODO
========================================================== */
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

/* ==========================================================
   SESSÃO
========================================================== */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../_auth/csrf.php';
csrfValidarSessao();

$idEmpresaSessao = sessionValue($_SESSION, [
    'auth.id_empresa',
    'id_empresa',
    'empresa_id',
    'empresa.id_empresa',
    'empresa.id',
]);

$idEmpresaSessao = is_numeric($idEmpresaSessao) ? (int)$idEmpresaSessao : 0;

if ($idEmpresaSessao <= 0) {
    out([
        'ok' => false,
        'code' => 'EMPRESA_SESSAO_INVALIDA',
        'user_msg' => 'Não foi possível identificar a empresa da sessão.',
    ], 401);
}

/* ==========================================================
   ENTRADAS
========================================================== */
$idUsuario     = intPost('id_usuario');
$idPerfil      = intPost('perfil');
$status        = lower($_POST['status'] ?? '', 20);
$especialidade = s($_POST['especialidade'] ?? '');

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

if ($idUsuario === null) {
    $erros['u_e_id'] = 'Usuário inválido.';
}

if ($idPerfil === null) {
    $erros['u_e_perfil'] = 'Selecione o perfil.';
}

$allowedStatus = ['ativo', 'inativo', 'bloqueado'];
if ($status === '') {
    $erros['u_status'] = 'Selecione o status.';
} elseif (!in_array($status, $allowedStatus, true)) {
    $erros['u_status'] = 'Status inválido.';
}

if (!empty($erros)) {
    out([
        'ok' => false,
        'code' => 'VALIDATION_ERROR',
        'user_msg' => 'Revise os campos destacados.',
        'fields' => $erros,
    ], 422);
}

/* ==========================================================
   DB
========================================================== */
require __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_regras/limites_plano.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_MISSING',
        'user_msg' => 'Conexão com banco não encontrada.',
    ], 500);
}

if ($conexao->connect_errno) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco de dados.',
    ], 500);
}

$conexao->set_charset('utf8mb4');

try {
    /* ==========================================================
       EMPRESA DA SESSÃO EXISTE?
    ========================================================== */
    $sqlEmpresa = "SELECT id_empresa, status FROM empresa WHERE id_empresa = ? LIMIT 1";
    $st = $conexao->prepare($sqlEmpresa);

    if (!$st) {
        throw new Exception('Falha ao preparar validação da empresa.');
    }

    $st->bind_param('i', $idEmpresaSessao);
    $st->execute();
    $resEmpresa = $st->get_result();
    $empresa = $resEmpresa ? $resEmpresa->fetch_assoc() : null;
    $st->close();

    if (!$empresa) {
        out([
            'ok' => false,
            'code' => 'EMPRESA_NAO_ENCONTRADA',
            'user_msg' => 'Empresa da sessão não encontrada.',
        ], 404);
    }

    $statusEmpresa = (string)($empresa['status'] ?? '');
    if ($statusEmpresa !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INATIVA',
            'user_msg' => 'A empresa da sessão não está ativa.',
        ], 403);
    }

    /* ==========================================================
       USUÁRIO + VÍNCULO DA EMPRESA
    ========================================================== */
    $sqlUsuario = "
        SELECT
            u.id_usuario,
            u.nome,
            u.email,
            u.telefone,
            u.status AS status_usuario,
            u.tipo_usuario,
            eu.id_empresa_usuario,
            eu.id_empresa,
            eu.id_perfil,
            eu.status AS status_vinculo,
            eu.especialidade_profissional AS especialidade_anterior,
            perfil_atual.nome AS perfil_anterior,
            prof.id_profissional
        FROM usuario u
        INNER JOIN empresa_usuario eu
            ON eu.id_usuario = u.id_usuario
           AND eu.id_empresa = ?
        INNER JOIN perfil perfil_atual
            ON perfil_atual.id_perfil = eu.id_perfil
        LEFT JOIN profissional prof
            ON prof.id_usuario = u.id_usuario
        WHERE u.id_usuario = ?
        LIMIT 1
    ";

    $st = $conexao->prepare($sqlUsuario);

    if (!$st) {
        throw new Exception('Falha ao preparar consulta do usuário.');
    }

    $st->bind_param('ii', $idEmpresaSessao, $idUsuario);
    $st->execute();
    $resUsuario = $st->get_result();
    $usuario = $resUsuario ? $resUsuario->fetch_assoc() : null;
    $st->close();

    if (!$usuario) {
        out([
            'ok' => false,
            'code' => 'USUARIO_NAO_ENCONTRADO',
            'user_msg' => 'Usuário não encontrado para a empresa da sessão.',
            'fields' => [
                'u_e_id' => 'Usuário não localizado.',
            ],
        ], 404);
    }

    if (($usuario['tipo_usuario'] ?? '') === 'super_admin') {
        out([
            'ok' => false,
            'code' => 'USUARIO_NAO_PERMITIDO',
            'user_msg' => 'Este usuário não pode ser editado por este painel.',
        ], 403);
    }

    $idEmpresaUsuario = (int)($usuario['id_empresa_usuario'] ?? 0);
    if ($idEmpresaUsuario <= 0) {
        throw new Exception('Vínculo do usuário inválido.');
    }

    /* ==========================================================
       PERFIL
    ========================================================== */
    $sqlPerfil = "
        SELECT id_perfil, nome, status
        FROM perfil
        WHERE id_perfil = ?
        LIMIT 1
    ";
    $st = $conexao->prepare($sqlPerfil);

    if (!$st) {
        throw new Exception('Falha ao preparar consulta do perfil.');
    }

    $st->bind_param('i', $idPerfil);
    $st->execute();
    $resPerfil = $st->get_result();
    $perfil = $resPerfil ? $resPerfil->fetch_assoc() : null;
    $st->close();

    if (!$perfil) {
        out([
            'ok' => false,
            'code' => 'PERFIL_NAO_ENCONTRADO',
            'user_msg' => 'Perfil não encontrado.',
            'fields' => [
                'u_e_perfil' => 'Perfil inválido.',
            ],
        ], 404);
    }

    if (($perfil['status'] ?? '') !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'PERFIL_INATIVO',
            'user_msg' => 'O perfil selecionado está inativo.',
            'fields' => [
                'u_e_perfil' => 'Selecione um perfil ativo.',
            ],
        ], 422);
    }

    $nomePerfilNormalizado = mb_strtolower(trim((string)($perfil['nome'] ?? '')), 'UTF-8');
    if (in_array($nomePerfilNormalizado, ['super admin', 'super_admin', 'superadmin'], true)) {
        out([
            'ok' => false,
            'code' => 'PERFIL_NAO_PERMITIDO',
            'user_msg' => 'Super Admin não pode ser usado como perfil de empresa.',
            'fields' => ['u_e_perfil' => 'Perfil não permitido.'],
        ], 403);
    }
    $isProfissional = ($nomePerfilNormalizado === 'profissional');
    $temCadastroProfissional = (int)($usuario['id_profissional'] ?? 0) > 0;

    if ($isProfissional) {
        if ($especialidade === '') {
            out([
                'ok' => false,
                'code' => 'PROFESSIONAL_DATA_REQUIRED',
                'user_msg' => 'Informe a especialidade para concluir o cadastro profissional.',
                'fields' => [
                    'u_e_especialidade' => 'Informe a especialidade do profissional.',
                ],
            ], 422);
        }

        if (mb_strlen($especialidade) > 120) {
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Revise os campos destacados.',
                'fields' => [
                    'u_e_especialidade' => 'A especialidade deve ter no máximo 120 caracteres.',
                ],
            ], 422);
        }
    }

    /* ==========================================================
       TRANSAÇÃO
    ========================================================== */
    $conexao->begin_transaction();

    $resultadoPlano = limitesPlanoBloquearEmpresa($conexao, $idEmpresaSessao);
    limitesPlanoAbortarSeNegado($conexao, $resultadoPlano);

    $usuarioGlobalConta = limitesPlanoStatusConta((string)($usuario['status_usuario'] ?? ''));
    $statusAnteriorPlano = $usuarioGlobalConta ? (string)$usuario['status_vinculo'] : 'inativo';
    $statusNovoPlano = $usuarioGlobalConta ? $status : 'inativo';
    $resultadoLimites = limitesPlanoVerificarTransicaoPerfil(
        $conexao,
        $resultadoPlano['plano'],
        $idEmpresaSessao,
        (string)($usuario['perfil_anterior'] ?? ''),
        $statusAnteriorPlano,
        (string)($perfil['nome'] ?? ''),
        $statusNovoPlano
    );
    limitesPlanoAbortarSeNegado($conexao, $resultadoLimites);

    $nome = (string)$usuario['nome'];
    $email = (string)$usuario['email'];
    $telefone = $usuario['telefone'] !== null ? (string)$usuario['telefone'] : null;
    $affectedUsuario = 0;

    /* ==========================================================
       UPDATE empresa_usuario
    ========================================================== */
    $sqlUpdateVinculo = "
        UPDATE empresa_usuario
           SET id_perfil = ?,
               status = ?,
               especialidade_profissional = CASE
                   WHEN ? = 1 THEN ?
                   ELSE especialidade_profissional
               END
         WHERE id_empresa_usuario = ?
         LIMIT 1
    ";
    $stmt = $conexao->prepare($sqlUpdateVinculo);

    if (!$stmt) {
        throw new Exception('Falha ao preparar atualização do vínculo.');
    }

    $stmt->bind_param(
        'isisi',
        $idPerfil,
        $status,
        $isProfissional,
        $especialidade,
        $idEmpresaUsuario
    );

    if (!$stmt->execute()) {
        $err = '[' . $stmt->errno . '] ' . $stmt->error;
        $stmt->close();
        throw new Exception('Erro ao atualizar vínculo ' . $err);
    }

    $affectedVinculo = (int)$stmt->affected_rows;
    $stmt->close();

    /* ==========================================================
       TABELA profissional
    ========================================================== */
    $affectedProfissional = 0;

    if ($isProfissional && !$temCadastroProfissional) {
            $descricao = null;

            $sqlProfissionalInsert = "
                INSERT INTO profissional (id_usuario, especialidade, descricao)
                VALUES (?, ?, ?)
            ";
            $stmt = $conexao->prepare($sqlProfissionalInsert);

            if (!$stmt) {
                throw new Exception('Falha ao preparar insert de profissional.');
            }

            $stmt->bind_param('iss', $idUsuario, $especialidade, $descricao);

            if (!$stmt->execute()) {
                $err = '[' . $stmt->errno . '] ' . $stmt->error;
                $stmt->close();
                throw new Exception('Erro ao inserir profissional ' . $err);
            }

            $affectedProfissional = (int)$stmt->affected_rows;
            $stmt->close();
    }

    // Monta diferenças a partir do snapshot empresarial carregado antes da operação.
    $especialidadeAuditada = $isProfissional
        ? $especialidade
        : $usuario['especialidade_anterior'];
    $valoresAuditaveis = [
        'perfil' => [(string)$usuario['perfil_anterior'], (string)$perfil['nome']],
        'status_vinculo' => [(string)$usuario['status_vinculo'], $status],
        'especialidade' => [$usuario['especialidade_anterior'], $especialidadeAuditada],
    ];
    $diferencas=[];foreach($valoresAuditaveis as $campo=>[$antes,$depois])if(!auditoriaValoresIguais($antes,$depois))$diferencas[$campo]=['antes'=>$antes,'depois'=>$depois];
    if($diferencas!==[])auditoriaRegistrar($conexao,'usuario.editado',['entidade_id'=>$idUsuario,'entidade_rotulo'=>$nome,'descricao'=>'Alterou o usuário '.$nome.'.','alteracoes'=>$diferencas,'contexto'=>['origem'=>'painel_administrativo']]);
    $conexao->commit();

    $houveAlteracao = (
        $affectedUsuario > 0 ||
        $affectedVinculo > 0 ||
        $affectedProfissional > 0
    );

    out([
        'ok' => true,
        'code' => 'USUARIO_ATUALIZADO',
        'user_msg' => $houveAlteracao
            ? 'Vínculo do usuário atualizado com sucesso.'
            : 'Nenhuma alteração foi realizada.',
        'data' => [
            'id_usuario'       => $idUsuario,
            'id_empresa'       => $idEmpresaSessao,
            'nome'             => $nome,
            'email'            => $email,
            'telefone'         => $telefone,
            'perfil'           => [
                'id_perfil' => $idPerfil,
                'nome'      => $perfil['nome'],
            ],
            'status'           => $status,
            'especialidade'    => $isProfissional ? $especialidade : null,
            'senha_alterada'   => false,
        ],
    ], 200);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
            // ignora
        }
    }

    error_log('[painel/usuario/editar] ' . $e->getMessage());

    $msg = $e->getMessage();

    if (str_contains($msg, '1062')) {
        out([
            'ok' => false,
            'code' => 'DUPLICATE_KEY',
            'user_msg' => 'Já existe um registro com os dados informados.',
        ], 409);
    }

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao atualizar o usuário.',
    ], 500);
}
