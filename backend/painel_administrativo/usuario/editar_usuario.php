<?php
declare(strict_types=1);

/**
 * ==========================================================
 * editar_usuario.php
 * Rota: painel/usuario/editar
 * Método: POST
 * ----------------------------------------------------------
 * Atualiza:
 * - tabela usuario
 * - tabela empresa_usuario (perfil + status)
 * - tabela profissional (quando perfil = profissional)
 *
 * Regras:
 * - id_usuario obrigatório
 * - nome obrigatório
 * - email obrigatório e único
 * - perfil obrigatório
 * - status obrigatório
 * - telefone opcional
 * - senha opcional
 * - especialidade obrigatória somente para perfil "Profissional"
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
$nome          = s($_POST['nome'] ?? '', 140);
$email         = lower($_POST['email'] ?? '', 160);
$telefoneRaw   = s($_POST['telefone'] ?? '', 20);
$idPerfil      = intPost('perfil');
$status        = lower($_POST['status'] ?? '', 20);
$especialidade = s($_POST['especialidade'] ?? '', 120);
$senha         = trim((string)($_POST['senha'] ?? ''));
$senha2        = trim((string)($_POST['senha2'] ?? ''));

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

if ($idUsuario === null) {
    $erros['u_e_id'] = 'Usuário inválido.';
}

if ($nome === '') {
    $erros['u_e_nome'] = 'Informe o nome do usuário.';
} elseif (mb_strlen($nome) < 3) {
    $erros['u_e_nome'] = 'O nome deve ter no mínimo 3 caracteres.';
}

if ($email === '') {
    $erros['u_e_email'] = 'Informe o e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros['u_e_email'] = 'Informe um e-mail válido.';
}

$telefone = null;
if ($telefoneRaw !== '') {
    $digits = onlyDigits($telefoneRaw);
    if (strlen($digits) < 10 || strlen($digits) > 11) {
        $erros['u_e_tel'] = 'Informe um telefone válido com DDD.';
    } else {
        $telefone = $telefoneRaw;
    }
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

$alterarSenha = false;
$senhaHash = null;

if ($senha !== '' || $senha2 !== '') {
    if ($senha === '') {
        $erros['u_e_senha'] = 'Informe a nova senha.';
    } elseif (mb_strlen($senha) < 6) {
        $erros['u_e_senha'] = 'A senha deve ter no mínimo 6 caracteres.';
    }

    if ($senha2 === '') {
        $erros['u_e_senha2'] = 'Confirme a nova senha.';
    } elseif (mb_strlen($senha2) < 6) {
        $erros['u_e_senha2'] = 'A confirmação deve ter no mínimo 6 caracteres.';
    }

    if ($senha !== '' && $senha2 !== '' && $senha !== $senha2) {
        $erros['u_e_senha2'] = 'As senhas não conferem.';
    }

    if (!isset($erros['u_e_senha']) && !isset($erros['u_e_senha2'])) {
        $alterarSenha = true;
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
    }
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
require_once __DIR__ . '/../../_servicos/notificacao.php';

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
            perfil_atual.nome AS perfil_anterior,
            prof.especialidade AS especialidade_anterior
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
       EMAIL DUPLICADO
    ========================================================== */
    $sqlEmail = "SELECT id_usuario FROM usuario WHERE email = ? AND id_usuario <> ? LIMIT 1";
    $st = $conexao->prepare($sqlEmail);

    if (!$st) {
        throw new Exception('Falha ao preparar validação de e-mail.');
    }

    $st->bind_param('si', $email, $idUsuario);
    $st->execute();
    $st->store_result();

    if ($st->num_rows > 0) {
        $st->close();

        out([
            'ok' => false,
            'code' => 'EMAIL_DUPLICADO',
            'user_msg' => 'Já existe outro usuário com este e-mail.',
            'fields' => [
                'u_e_email' => 'Este e-mail já está em uso.',
            ],
        ], 409);
    }

    $st->close();

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

    if ($isProfissional && $especialidade === '') {
        out([
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'user_msg' => 'Revise os campos destacados.',
            'fields' => [
                'u_e_especialidade' => 'Informe a especialidade do profissional.',
            ],
        ], 422);
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

    /* ==========================================================
       UPDATE usuario
    ========================================================== */
    if ($alterarSenha) {
        $sqlUpdateUsuario = "
            UPDATE usuario
               SET nome = ?,
                   email = ?,
                   telefone = ?,
                   senha_hash = ?,
                   deve_alterar_senha = 1,
                   data_senha_temporaria = CURRENT_TIMESTAMP
             WHERE id_usuario = ?
             LIMIT 1
        ";
        $stmt = $conexao->prepare($sqlUpdateUsuario);

        if (!$stmt) {
            throw new Exception('Falha ao preparar atualização do usuário com senha.');
        }

        $stmt->bind_param(
            'ssssi',
            $nome,
            $email,
            $telefone,
            $senhaHash,
            $idUsuario
        );
    } else {
        $sqlUpdateUsuario = "
            UPDATE usuario
               SET nome = ?,
                   email = ?,
                   telefone = ?
             WHERE id_usuario = ?
             LIMIT 1
        ";
        $stmt = $conexao->prepare($sqlUpdateUsuario);

        if (!$stmt) {
            throw new Exception('Falha ao preparar atualização do usuário.');
        }

        $stmt->bind_param(
            'sssi',
            $nome,
            $email,
            $telefone,
            $idUsuario
        );
    }

    if (!$stmt->execute()) {
        $err = '[' . $stmt->errno . '] ' . $stmt->error;
        $stmt->close();
        throw new Exception('Erro ao atualizar usuário ' . $err);
    }

    $affectedUsuario = (int)$stmt->affected_rows;
    $stmt->close();

    /* ==========================================================
       UPDATE empresa_usuario
    ========================================================== */
    $sqlUpdateVinculo = "
        UPDATE empresa_usuario
           SET id_perfil = ?,
               status = ?
         WHERE id_empresa_usuario = ?
         LIMIT 1
    ";
    $stmt = $conexao->prepare($sqlUpdateVinculo);

    if (!$stmt) {
        throw new Exception('Falha ao preparar atualização do vínculo.');
    }

    $stmt->bind_param(
        'isi',
        $idPerfil,
        $status,
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

    if ($isProfissional) {
        $sqlProfissionalExiste = "
            SELECT id_profissional
            FROM profissional
            WHERE id_usuario = ?
            LIMIT 1
        ";
        $stmt = $conexao->prepare($sqlProfissionalExiste);

        if (!$stmt) {
            throw new Exception('Falha ao preparar consulta de profissional.');
        }

        $stmt->bind_param('i', $idUsuario);
        $stmt->execute();
        $resProf = $stmt->get_result();
        $profissional = $resProf ? $resProf->fetch_assoc() : null;
        $stmt->close();

        if ($profissional) {
            $sqlProfissionalUpdate = "
                UPDATE profissional
                   SET especialidade = ?
                 WHERE id_usuario = ?
                 LIMIT 1
            ";
            $stmt = $conexao->prepare($sqlProfissionalUpdate);

            if (!$stmt) {
                throw new Exception('Falha ao preparar update de profissional.');
            }

            $stmt->bind_param('si', $especialidade, $idUsuario);

            if (!$stmt->execute()) {
                $err = '[' . $stmt->errno . '] ' . $stmt->error;
                $stmt->close();
                throw new Exception('Erro ao atualizar profissional ' . $err);
            }

            $affectedProfissional = (int)$stmt->affected_rows;
            $stmt->close();
        } else {
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
    } else {
        $sqlDeleteProfissional = "
            DELETE FROM profissional
            WHERE id_usuario = ?
            LIMIT 1
        ";
        $stmt = $conexao->prepare($sqlDeleteProfissional);

        if (!$stmt) {
            throw new Exception('Falha ao preparar exclusão de profissional.');
        }

        $stmt->bind_param('i', $idUsuario);

        if (!$stmt->execute()) {
            $err = '[' . $stmt->errno . '] ' . $stmt->error;
            $stmt->close();
            throw new Exception('Erro ao remover profissional ' . $err);
        }

        $affectedProfissional = (int)$stmt->affected_rows;
        $stmt->close();
    }

    if ($alterarSenha) {
        // Resolve novamente o executor pelo contexto autenticado e validado do backend.
        $atorOperacao = auditoriaResolverAtorSessao($conexao);
        $origemTipoNotificacao = (string)$atorOperacao['ator_tipo'];
        $origemIdNotificacao = (int)$atorOperacao['id_ator'];

        $stmt = $conexao->prepare(
            "SELECT id_notificacao
               FROM notificacao
              WHERE destinatario_tipo = 'usuario'
                AND destinatario_id = ?
                AND id_empresa = ?
                AND codigo = 'seguranca.senha_temporaria'
                AND concluida_em IS NULL
                AND cancelada_em IS NULL
              FOR UPDATE"
        );
        if (!$stmt) {
            throw new Exception('Falha ao preparar busca da notificação de senha anterior.');
        }
        $stmt->bind_param('ii', $idUsuario, $idEmpresaSessao);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Falha ao buscar a notificação de senha anterior.');
        }
        $resNotificacoes = $stmt->get_result();
        $notificacoesAnteriores = [];
        while ($resNotificacoes && ($notificacaoAnterior = $resNotificacoes->fetch_assoc())) {
            $notificacoesAnteriores[] = (int)$notificacaoAnterior['id_notificacao'];
        }
        $stmt->close();

        foreach ($notificacoesAnteriores as $idNotificacaoAnterior) {
            notificacaoCancelar(
                $conexao,
                $idNotificacaoAnterior,
                'usuario',
                $idUsuario,
                $idEmpresaSessao
            );
        }

        $stmt = $conexao->prepare(
            'SELECT data_senha_temporaria, DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR) AS prazo_em FROM usuario WHERE id_usuario = ? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            throw new Exception('Falha ao preparar o prazo da nova senha temporária.');
        }
        $stmt->bind_param('i', $idUsuario);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Falha ao calcular o prazo da nova senha temporária.');
        }
        $resPrazo = $stmt->get_result();
        $prazoSenha = $resPrazo ? ($resPrazo->fetch_assoc() ?: null) : null;
        $stmt->close();
        if (!$prazoSenha || trim((string)($prazoSenha['prazo_em'] ?? '')) === '') {
            throw new Exception('Prazo da nova senha temporária indisponível.');
        }

        $chaveOcorrencia = 'seguranca.senha_temporaria:redefinicao:'
            . $idEmpresaSessao . ':' . $idUsuario . ':' . bin2hex(random_bytes(16));
        notificacaoCriar($conexao, [
            'id_empresa' => $idEmpresaSessao,
            'destinatario_tipo' => 'usuario',
            'destinatario_id' => $idUsuario,
            'origem_tipo' => $origemTipoNotificacao,
            'origem_id' => $origemIdNotificacao,
            'codigo' => 'seguranca.senha_temporaria',
            'categoria' => 'seguranca',
            'titulo' => 'Altere sua senha temporária',
            'mensagem' => 'Sua senha atual é temporária. Altere-a dentro de 24 horas.',
            'prioridade' => 'alta',
            'obrigatoria' => true,
            'acao_codigo' => 'perfil.alterar_senha',
            'contexto' => null,
            'prazo_em' => (string)$prazoSenha['prazo_em'],
            'chave_deduplicacao' => $chaveOcorrencia,
        ]);
    }

    // Monta diferenças a partir do snapshot empresarial carregado antes da operação.
    $valoresAuditaveis = [
        'nome' => [(string)$usuario['nome'], $nome],
        'email' => [(string)$usuario['email'], $email],
        'telefone' => [$usuario['telefone'], $telefone],
        'perfil' => [(string)$usuario['perfil_anterior'], (string)$perfil['nome']],
        'status_vinculo' => [(string)$usuario['status_vinculo'], $status],
        'especialidade' => [$usuario['especialidade_anterior'], $isProfissional ? $especialidade : null],
    ];
    $diferencas=[];foreach($valoresAuditaveis as $campo=>[$antes,$depois])if(!auditoriaValoresIguais($antes,$depois))$diferencas[$campo]=['antes'=>$antes,'depois'=>$depois];
    if($diferencas!==[])auditoriaRegistrar($conexao,'usuario.editado',['entidade_id'=>$idUsuario,'entidade_rotulo'=>$nome,'descricao'=>'Alterou o usuário '.$nome.'.','alteracoes'=>$diferencas,'contexto'=>['origem'=>'painel_administrativo']]);
    // A redefinição administrativa registra somente o fato, sem qualquer característica da senha.
    if($alterarSenha)auditoriaRegistrar($conexao,'usuario.senha_redefinida',['entidade_id'=>$idUsuario,'entidade_rotulo'=>$nome,'descricao'=>'Redefiniu a senha do usuário '.$nome.'.','alteracoes'=>['senha_alterada'=>['antes'=>false,'depois'=>true]],'contexto'=>['origem'=>'painel_administrativo']]);
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
            ? ($alterarSenha ? 'Usuário atualizado com sucesso e senha alterada.' : 'Usuário atualizado com sucesso.')
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
            'senha_alterada'   => $alterarSenha,
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
