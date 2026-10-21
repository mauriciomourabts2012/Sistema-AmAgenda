<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/usuario/editar-super
 * - Atualiza dados do usuário super admin na tabela `usuario`
 * - Compatível com API central
 * - Sempre retorna JSON válido
 * - Atualiza senha SOMENTE se enviada, validada pela política de senha PROVISÓRIA usada no
 *   cadastro de Super Admin (cadastrar_usuario_super.php): 6 a 72 bytes, somente ASCII
 *   imprimível, sem caracteres invisíveis e confirmação idêntica. A senha definitiva, com a
 *   política central forte (politica_senha.php), é exigida na troca obrigatória.
 * - A senha definida aqui é sempre TEMPORÁRIA (A5.1-R2): grava deve_alterar_senha = 1 e
 *   data_senha_temporaria = CURRENT_TIMESTAMP, cancela avisos pendentes anteriores e cria a
 *   notificação seguranca.senha_temporaria (troca obrigatória, prazo de 24 horas), tudo na
 *   mesma transação. Sem nova senha, o prazo não é renovado.
 * - Depois do commit da senha temporária, grava o marcador de liberação do login
 *   (A5.1-R2) para o e-mail atual e, se alterado, para o anterior. O login aplica o
 *   marcador sob o GET_LOCK da conta (backend/_auth/login.php). Uma falha aqui não
 *   desfaz a redefinição e é informada ao administrador.
 * - A própria senha não pode ser redefinida aqui (exige a senha atual no perfil)
 * - Email é obrigatório
 * - Nome é obrigatório
 * - Status é obrigatório
 * - id_usuario é obrigatório
 * - Telefone é opcional
 * - tipo_usuario NÃO é atualizado neste endpoint
 * - Aceita somente usuário com tipo_usuario = super_admin
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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

require __DIR__ . '/../../_auth/bloquear.php';
require_once __DIR__ . '/../../_auth/csrf.php';
csrfValidarSessao();

/* ==========================================================
   HELPERS
========================================================== */
function strv(string $k, int $max, bool $required = false): string
{
    $v = trim((string)($_POST[$k] ?? ''));

    if ($required && $v === '') {
        return '';
    }

    if ($v === '') {
        return '';
    }

    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }

    return $v;
}

function intOrNull(string $k): ?int
{
    $raw = $_POST[$k] ?? null;

    if ($raw === null || $raw === '') {
        return null;
    }

    if (is_int($raw)) {
        return ($raw > 0) ? $raw : null;
    }

    $raw = trim((string)$raw);

    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return null;
    }

    $n = (int)$raw;
    return ($n > 0) ? $n : null;
}

/**
 * Grava o marcador de liberação do login central para o e-mail informado.
 * Espelha a composição de chaves e o nome do lock do limitador de
 * backend/_auth/login.php (A5.1): qualquer mudança lá deve ser refletida aqui.
 * Somente grava o marcador (operação atômica); nenhum contador é apagado aqui.
 * O lock da conta é usado, quando obtido, para que a liberação não se misture a
 * uma tentativa de login em andamento. Nunca lança exceção.
 */
function liberarLoginContaSuperAdmin(mysqli $conexao, string $email): bool
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    if ($email === '') {
        return false;
    }

    $chaveConta = hash('sha256', "amagenda:login_central:conta\n" . $email, true);
    $marcador = hash('sha256', "amagenda:login_central:liberar_conta\n" . $email, true);
    $nomeLock = 'amagenda:login_conta:' . substr(bin2hex($chaveConta), 0, 40);
    $lockObtido = false;
    $gravado = false;

    try {
        $stmt = $conexao->prepare('SELECT GET_LOCK(?, 5) AS adquirido');
        if ($stmt) {
            $stmt->bind_param('s', $nomeLock);
            if ($stmt->execute()) {
                $resultado = $stmt->get_result();
                $linha = $resultado ? $resultado->fetch_assoc() : null;
                $lockObtido = (int)($linha['adquirido'] ?? 0) === 1;
            }
            $stmt->close();
        }

        $stmt = $conexao->prepare(
            'INSERT INTO login_limite_conta_ip (chave, falhas, primeira_falha_em, ultima_falha_em, bloqueado_ate)
             VALUES (?, 0, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6), NULL)
             ON DUPLICATE KEY UPDATE
                 falhas = 0,
                 primeira_falha_em = VALUES(primeira_falha_em),
                 ultima_falha_em = VALUES(ultima_falha_em),
                 bloqueado_ate = NULL'
        );
        if ($stmt) {
            $stmt->bind_param('s', $marcador);
            $gravado = $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        $gravado = false;
    }

    if ($lockObtido) {
        try {
            $stmt = $conexao->prepare('SELECT RELEASE_LOCK(?) AS liberado');
            if ($stmt) {
                $stmt->bind_param('s', $nomeLock);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable) {
            // O lock é liberado automaticamente quando a conexão termina.
        }
    }

    if (!$gravado) {
        error_log('[editar_usuario_super] Marcador de liberação do login não gravado.');
    }
    return $gravado;
}

/** Senha provisória: mesmas regras de caracteres do cadastro de Super Admin. */
function senhaProvisoriaTemInvisiveis(string $v): bool
{
    return preg_match('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', $v) === 1;
}

function senhaProvisoriaAsciiImprimivel(string $v): bool
{
    return preg_match('/^[\x20-\x7E]+$/', $v) === 1;
}

function onlyDigits(?string $v): string
{
    return preg_replace('/\D+/', '', (string)$v) ?? '';
}

/* ==========================================================
   ENTRADAS
========================================================== */
$idUsuario   = intOrNull('id_usuario');
$nome        = strv('nome', 140, true);
$email       = mb_strtolower(strv('email', 160, true));
$telefoneRaw = strv('telefone', 20, false);
$status      = mb_strtolower(strv('status', 20, true));
// As senhas não são aparadas nem normalizadas.
$senha       = (string)($_POST['senha'] ?? '');
$senha2      = (string)($_POST['senha2'] ?? '');

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

if ($idUsuario === null) {
    $erros['id_usuario'] = 'Super Admin inválido.';
}

if ($nome === '') {
    $erros['nome'] = 'Informe o nome.';
} elseif (mb_strlen($nome) < 3) {
    $erros['nome'] = 'O nome deve ter no mínimo 3 caracteres.';
}

if ($email === '') {
    $erros['email'] = 'Informe o e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros['email'] = 'Informe um e-mail válido.';
}

$telefoneDigits = onlyDigits($telefoneRaw);
$telefone = null;

if ($telefoneRaw !== '') {
    if (strlen($telefoneDigits) < 10 || strlen($telefoneDigits) > 11) {
        $erros['telefone'] = 'Informe um telefone válido com DDD.';
    } else {
        $telefone = $telefoneRaw;
    }
}

$allowedStatus = ['ativo', 'inativo', 'bloqueado'];
if ($status === '') {
    $erros['status'] = 'Selecione o status.';
} elseif (!in_array($status, $allowedStatus, true)) {
    $erros['status'] = 'Status inválido.';
}

$alterarSenha = false;
$senhaHash = null;

// Redefinição administrativa da própria senha não é permitida: o fluxo
// Alterar senha do perfil exige a senha atual. Os demais dados continuam editáveis.
$editandoPropriaConta = $idUsuario !== null && $idUsuario === (int)$idUsuarioSuperAdmin;
if ($editandoPropriaConta && ($senha !== '' || $senha2 !== '')) {
    out([
        'ok' => false,
        'code' => 'PROPRIA_SENHA_NAO_PERMITIDA',
        'user_msg' => 'Para alterar sua própria senha, utilize a opção Alterar senha no seu perfil.',
        'fields' => [
            'senha' => 'Para alterar sua própria senha, utilize a opção Alterar senha no seu perfil.',
        ],
    ], 403);
}

if ($senha !== '' || $senha2 !== '') {
    // Senha PROVISÓRIA (sempre temporária nesta tela): mesmas regras e mensagens do
    // cadastro de Super Admin. Sem trim; confirmação exata (hash_equals). A política
    // forte de senha definitiva é aplicada quando o usuário fizer a troca obrigatória.
    $erroSenhaProvisoria = null;
    if ($senha === '') {
        $erroSenhaProvisoria = 'Informe a senha.';
    } elseif (strlen($senha) < 6) {
        $erroSenhaProvisoria = 'A senha deve ter no mínimo 6 caracteres.';
    } elseif (strlen($senha) > 72) {
        $erroSenhaProvisoria = 'A senha deve ter no máximo 72 caracteres.';
    } elseif (senhaProvisoriaTemInvisiveis($senha)) {
        $erroSenhaProvisoria = 'A senha contém caracteres invisíveis inválidos.';
    } elseif (!senhaProvisoriaAsciiImprimivel($senha)) {
        $erroSenhaProvisoria = 'A senha contém caracteres inválidos.';
    }

    if ($erroSenhaProvisoria !== null) {
        $erros['senha'] = $erroSenhaProvisoria;
    }

    if ($senha2 === '') {
        $erros['senha2'] = 'Repita a senha no campo de confirmação.';
    } elseif (!hash_equals($senha, $senha2)) {
        $erros['senha2'] = 'As senhas informadas não coincidem.';
    }

    if ($erroSenhaProvisoria === null && !isset($erros['senha2'])) {
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
        'user_msg' => 'Falha ao conectar no banco.',
    ], 500);
}

$conexao->set_charset('utf8mb4');

try {
    $nome  = trim($nome);
    $email = trim($email);

    /* ==========================================================
       VALIDA SE USUÁRIO EXISTE E SE É SUPER ADMIN
    ========================================================== */
    $sqlUsuario = "
        SELECT id_usuario, nome, email, telefone, status, tipo_usuario
        FROM usuario
        WHERE id_usuario = ?
        LIMIT 1
    ";
    $st = $conexao->prepare($sqlUsuario);

    if (!$st) {
        throw new Exception('Prepare check usuario falhou.');
    }

    $st->bind_param('i', $idUsuario);
    $st->execute();
    $resultUsuario = $st->get_result();
    $usuario = $resultUsuario ? $resultUsuario->fetch_assoc() : null;
    $st->close();

    if (!$usuario) {
        out([
            'ok' => false,
            'code' => 'USUARIO_NAO_ENCONTRADO',
            'user_msg' => 'Super Admin não encontrado.',
            'fields' => [
                'id_usuario' => 'O usuário informado não existe.',
            ],
        ], 404);
    }

    $tipoUsuario = (string)($usuario['tipo_usuario'] ?? '');

    if ($tipoUsuario !== 'super_admin') {
        out([
            'ok' => false,
            'code' => 'USUARIO_INVALIDO',
            'user_msg' => 'O usuário informado não é um Super Admin.',
            'fields' => [
                'id_usuario' => 'O registro selecionado não pertence a um Super Admin.',
            ],
        ], 422);
    }

    /* ==========================================================
       VALIDA DUPLICIDADE DE EMAIL
    ========================================================== */
    $sqlCheckEmail = "
        SELECT id_usuario
        FROM usuario
        WHERE email = ?
          AND id_usuario <> ?
        LIMIT 1
    ";
    $st = $conexao->prepare($sqlCheckEmail);

    if (!$st) {
        throw new Exception('Prepare check email falhou.');
    }

    $st->bind_param('si', $email, $idUsuario);
    $st->execute();
    $st->store_result();

    if ($st->num_rows > 0) {
        $st->close();

        out([
            'ok' => false,
            'code' => 'EMAIL_DUPLICADO',
            'user_msg' => 'Já existe outro usuário cadastrado com este e-mail.',
            'fields' => [
                'email' => 'Este e-mail já está em uso.',
            ],
        ], 409);
    }

    $st->close();

    /* ==========================================================
       TRANSAÇÃO
    ========================================================== */
    $conexao->begin_transaction();

    /* ==========================================================
       UPDATE usuario
    ========================================================== */
    if ($alterarSenha) {
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?,
                status = ?,
                senha_hash = ?,
                deve_alterar_senha = 1,
                data_senha_temporaria = CURRENT_TIMESTAMP
            WHERE id_usuario = ?
              AND tipo_usuario = 'super_admin'
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario super com senha falhou.');
        }

        $stmt->bind_param(
            'sssssi',
            $nome,
            $email,
            $telefone,
            $status,
            $senhaHash,
            $idUsuario
        );
    } else {
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?,
                status = ?
            WHERE id_usuario = ?
              AND tipo_usuario = 'super_admin'
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario super falhou.');
        }

        $stmt->bind_param(
            'ssssi',
            $nome,
            $email,
            $telefone,
            $status,
            $idUsuario
        );
    }

    $ok = $stmt->execute();

    if (!$ok) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        throw new Exception("Erro ao atualizar super admin [{$errno}] {$error}");
    }

    $affected = (int)$stmt->affected_rows;
    $stmt->close();

    /* ==========================================================
       SENHA TEMPORÁRIA (A5.1-R2) — mesma transação
       ----------------------------------------------------------
       - Cancela avisos de senha temporária ainda pendentes (evita dois prazos).
       - Cria o aviso seguranca.senha_temporaria com o prazo de 24 horas
         calculado pelo banco, como na criação do Super Admin.
    ========================================================== */
    if ($alterarSenha) {
        $stmtPendentes = $conexao->prepare(
            "SELECT id_notificacao
               FROM notificacao
              WHERE destinatario_tipo = 'super_admin'
                AND destinatario_id = ?
                AND id_empresa IS NULL
                AND codigo = 'seguranca.senha_temporaria'
                AND concluida_em IS NULL
                AND cancelada_em IS NULL
              FOR UPDATE"
        );
        if (!$stmtPendentes) {
            throw new RuntimeException('Prepare dos avisos de senha temporária pendentes falhou.');
        }
        $stmtPendentes->bind_param('i', $idUsuario);
        if (!$stmtPendentes->execute()) {
            $stmtPendentes->close();
            throw new RuntimeException('Consulta dos avisos de senha temporária pendentes falhou.');
        }
        $resultadoPendentes = $stmtPendentes->get_result();
        $idsPendentes = [];
        while ($resultadoPendentes && ($linhaPendente = $resultadoPendentes->fetch_assoc())) {
            $idsPendentes[] = (int)$linhaPendente['id_notificacao'];
        }
        $stmtPendentes->close();
        foreach ($idsPendentes as $idPendente) {
            notificacaoCancelar($conexao, $idPendente, 'super_admin', (int)$idUsuario, null);
        }

        $stmtPrazo = $conexao->prepare(
            'SELECT data_senha_temporaria,
                    DATE_ADD(data_senha_temporaria, INTERVAL 24 HOUR)
               FROM usuario
              WHERE id_usuario = ?
                AND tipo_usuario = \'super_admin\'
                AND deve_alterar_senha = 1
              LIMIT 1
              FOR UPDATE'
        );
        if (!$stmtPrazo) {
            throw new RuntimeException('Prepare do prazo da senha temporária falhou.');
        }
        $stmtPrazo->bind_param('i', $idUsuario);
        if (!$stmtPrazo->execute()) {
            $stmtPrazo->close();
            throw new RuntimeException('Consulta do prazo da senha temporária falhou.');
        }
        $stmtPrazo->bind_result($dataSenhaTemporaria, $prazoSenhaTemporaria);
        $prazoEncontrado = $stmtPrazo->fetch();
        $stmtPrazo->close();
        if (!$prazoEncontrado || trim((string)$dataSenhaTemporaria) === '' || trim((string)$prazoSenhaTemporaria) === '') {
            throw new RuntimeException('Os dados da senha temporária do Super Admin não foram gravados corretamente.');
        }

        $prefixoDeduplicacao = 'super_admin:' . $idUsuario . ':redefinicao:' . bin2hex(random_bytes(16));
        notificacaoCriar($conexao, [
            'id_empresa' => null,
            'destinatario_tipo' => 'super_admin',
            'destinatario_id' => $idUsuario,
            'origem_tipo' => 'sistema',
            'origem_id' => null,
            'codigo' => 'seguranca.senha_temporaria',
            'categoria' => 'seguranca',
            'titulo' => 'Altere sua senha temporária',
            'mensagem' => 'Sua senha atual é temporária. Altere-a dentro de 24 horas.',
            'prioridade' => 'alta',
            'obrigatoria' => true,
            'acao_codigo' => 'perfil.alterar_senha',
            'contexto' => null,
            'prazo_em' => (string)$prazoSenhaTemporaria,
            'chave_deduplicacao' => 'seguranca.senha_temporaria:' . $prefixoDeduplicacao,
        ]);
    }

    $depois = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'status'=>$status];
    $antes = ['nome'=>$usuario['nome'],'email'=>$usuario['email'],'telefone'=>$usuario['telefone'],'status'=>$usuario['status']];
    $alteracoes = [];
    foreach ($depois as $campo=>$valor) if (!auditoriaValoresIguais($antes[$campo] ?? null,$valor)) $alteracoes[$campo]=['antes'=>$antes[$campo] ?? null,'depois'=>$valor];
    if ($alterarSenha) $alteracoes['senha_alterada']=['antes'=>false,'depois'=>true];
    if ($alteracoes !== []) auditoriaRegistrar($conexao, 'super_admin.editado', [
        'ator'=>auditoriaResolverAtorSuperAdmin($conexao),
        'entidade_id'=>$idUsuario,'entidade_rotulo'=>$nome,
        'descricao'=>$alterarSenha
            ? 'Alterou o Super Admin ' . $nome . ' e definiu uma senha temporária (troca obrigatória em até 24 horas).'
            : 'Alterou o Super Admin ' . $nome . '.',
        'alteracoes'=>$alteracoes,
        'contexto'=>$alterarSenha
            ? ['origem'=>'painel_super_admin','motivo'=>'redefinicao_administrativa_temporaria']
            : ['origem'=>'painel_super_admin'],
    ]);

    $conexao->commit();

    /* ==========================================================
       LIBERAÇÃO DO LOGIN (A5.1-R2) — somente após o commit da
       senha temporária. Não desfaz a redefinição em caso de falha.
    ========================================================== */
    $loginDesbloqueado = null;
    if ($alterarSenha) {
        $emailsLiberacao = array_values(array_unique(array_filter([
            mb_strtolower(trim($email), 'UTF-8'),
            mb_strtolower(trim((string)($usuario['email'] ?? '')), 'UTF-8'),
        ], static fn(string $valor): bool => $valor !== '')));
        $loginDesbloqueado = $emailsLiberacao !== [];
        foreach ($emailsLiberacao as $emailLiberacao) {
            if (!liberarLoginContaSuperAdmin($conexao, $emailLiberacao)) {
                $loginDesbloqueado = false;
            }
        }
    }

    out([
        'ok' => true,
        'code' => 'UPDATED',
        'user_msg' => $affected > 0
            ? ($alterarSenha
                ? 'Super Admin atualizado. A senha definida é temporária: no próximo acesso, o usuário deverá alterá-la (prazo de 24 horas).'
                    . ($loginDesbloqueado
                        ? ' O bloqueio de tentativas de login desta conta foi liberado.'
                        : ' Atenção: não foi possível liberar agora o bloqueio de tentativas de login desta conta. Se ele estiver ativo, expira em até 15 minutos.')
                : 'Super Admin atualizado com sucesso.')
            : 'Nenhuma alteração foi realizada.',
        'data' => [
            'id_usuario'     => $idUsuario,
            'nome'           => $nome,
            'email'          => $email,
            'telefone'       => $telefone,
            'status'         => $status,
            'senha_alterada' => $alterarSenha,
            'senha_temporaria' => $alterarSenha,
            'login_desbloqueado' => $loginDesbloqueado,
            'tipo_usuario'   => $tipoUsuario,
        ],
    ], 200);

} catch (Throwable $e) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
            // ignora rollback error
        }
    }

    error_log('[editar_usuario_super.php] ' . $e->getMessage());

    $msg = $e->getMessage();
    if (str_contains($msg, '1062')) {
        out([
            'ok' => false,
            'code' => 'DUPLICATE_KEY',
            'user_msg' => 'Já existe um registro duplicado para os dados informados.',
        ], 409);
    }

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao atualizar o Super Admin.',
    ], 500);
}
