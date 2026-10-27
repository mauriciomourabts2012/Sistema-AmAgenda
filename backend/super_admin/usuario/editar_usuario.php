<?php
declare(strict_types=1);

/**
 * HANDLER: superadmin/usuario/editar
 * - Atualiza dados globais do usuário na tabela `usuario`
 * - Inativa ou reativa vínculos existentes em `empresa_usuario` (perfil não é alterado)
 * - Cria, opcionalmente, um novo vínculo com o perfil Proprietário (nunca exclui vínculos)
 * - Compatível com API central
 * - Sempre retorna JSON válido
 * - Atualiza senha SOMENTE se enviada (política central: backend/_regras/politica_senha.php)
 * - Email é obrigatório
 * - Nome é obrigatório
 * - id_usuario é obrigatório
 * - Telefone é opcional
 * - tipo_usuario NÃO é atualizado neste endpoint
 * - Super Admin NÃO é editado por esta rota (usar superadmin/usuario/editar-super)
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
require_once __DIR__ . '/../../_regras/politica_senha.php';

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

function onlyDigits(?string $v): string
{
    return preg_replace('/\D+/', '', (string)$v) ?? '';
}

function jsonPost(string $k): ?array
{
    $raw = trim((string)($_POST[$k] ?? ''));

    if ($raw === '') {
        return null;
    }

    $dados = json_decode($raw, true);
    return is_array($dados) ? $dados : null;
}

function inteiroPositivo(mixed $v): ?int
{
    if (is_int($v)) {
        return ($v > 0) ? $v : null;
    }

    $v = trim((string)$v);
    return preg_match('/^[1-9]\d*$/', $v) ? (int)$v : null;
}

/* ==========================================================
   ENTRADAS
========================================================== */
$idUsuario   = intOrNull('id_usuario');
$nome        = strv('nome', 140, true);
$email       = mb_strtolower(strv('email', 160, true));
$telefoneRaw = strv('telefone', 20, false);
// As senhas não são aparadas nem normalizadas.
$senha       = (string)($_POST['senha'] ?? '');
$senha2      = (string)($_POST['senha2'] ?? '');

/* ==========================================================
   VALIDAÇÕES
========================================================== */
$erros = [];

if ($idUsuario === null) {
    $erros['id_usuario'] = 'Usuário inválido.';
}

if ($nome === '') {
    $erros['nome'] = 'Informe o nome do usuário.';
} elseif (mb_strlen($nome) < 3) {
    $erros['nome'] = 'O nome do usuário deve ter no mínimo 3 caracteres.';
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

$vinculosEnviados = [];
$vinculosRaw = jsonPost('vinculos');

if ($vinculosRaw === null || $vinculosRaw === []) {
    $erros['vinculos'] = 'O usuário deve possuir ao menos um vínculo empresarial.';
} else {
    foreach ($vinculosRaw as $item) {
        $item = is_array($item) ? $item : [];
        $idEU = inteiroPositivo($item['id_empresa_usuario'] ?? null);
        $idEmp = inteiroPositivo($item['id_empresa'] ?? null);
        $acao = mb_strtolower(trim((string)($item['acao'] ?? '')));

        if ($idEU === null || $idEmp === null
            || !in_array($acao, ['', 'desvincular', 'reativar'], true)
            || isset($vinculosEnviados[$idEU])) {
            $erros['vinculos'] = 'Revise os vínculos empresariais.';
            break;
        }

        $vinculosEnviados[$idEU] = [
            'id_empresa_usuario' => $idEU,
            'id_empresa' => $idEmp,
            'acao' => $acao,
            'status' => '',
        ];
    }
}

$novoVinculo = null;
if (trim((string)($_POST['novo_vinculo'] ?? '')) !== '') {
    $novoRaw = jsonPost('novo_vinculo') ?? [];
    $idEmpNovo = inteiroPositivo($novoRaw['id_empresa'] ?? null);

    if ($idEmpNovo === null) {
        $erros['novo_vinculo'] = 'Selecione a empresa para criar o novo vínculo.';
    } else {
        $novoVinculo = ['id_empresa' => $idEmpNovo, 'id_perfil' => 0];
    }
}

$alterarSenha = false;
$senhaHash = null;

if ($senha !== '' || $senha2 !== '') {
    // Senha definitiva redefinida pelo Super Admin: política central, sem trim,
    // confirmação exata. Dados pessoais considerados: os informados nesta edição.
    $erroSenha = politicaSenhaValidarDefinitiva($senha, $senha2, [
        'nome'     => $nome,
        'email'    => $email,
        'telefone' => $telefoneRaw,
    ]);

    if ($erroSenha !== null) {
        $erros[$erroSenha['campo'] === 'confirmacao' ? 'senha2' : 'senha'] = $erroSenha['user_msg'];
    } else {
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
require_once __DIR__ . '/../../_servicos/usuario_empresa.php';

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

/**
 * Vínculos do usuário indexados por id_empresa_usuario. Sempre filtrados por id_usuario,
 * para que um id de vínculo de outro usuário nunca seja alterado por esta rota.
 */
function carregarVinculosUsuario(mysqli $conexao, int $idUsuario, bool $bloquear): array
{
    $sql = "
        SELECT eu.id_empresa_usuario, eu.id_empresa, eu.id_perfil, eu.status, pf.nome AS perfil_nome
        FROM empresa_usuario eu
        INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
        WHERE eu.id_usuario = ?
        ORDER BY eu.id_empresa ASC, eu.id_empresa_usuario ASC
    " . ($bloquear ? ' FOR UPDATE' : '');

    $st = $conexao->prepare($sql);
    if (!$st) {
        throw new Exception('Prepare vínculos do usuário falhou.');
    }

    $st->bind_param('i', $idUsuario);
    $st->execute();
    $res = $st->get_result();
    $vinculos = [];
    while ($res && ($linha = $res->fetch_assoc())) {
        $vinculos[(int)$linha['id_empresa_usuario']] = $linha;
    }
    $st->close();

    return $vinculos;
}

/**
 * Perfil Proprietário ativo. Só é aceito se houver exatamente um, para nunca vincular um perfil ambíguo.
 */
function carregarPerfilProprietario(mysqli $conexao): ?array
{
    $res = $conexao->query("SELECT id_perfil, nome FROM perfil WHERE status = 'ativo'");
    if (!$res) {
        throw new Exception('Consulta de perfis falhou.');
    }

    $encontrados = [];
    while ($perfil = $res->fetch_assoc()) {
        if (limitesPlanoNormalizarPerfil((string)$perfil['nome']) === 'proprietarios') {
            $encontrados[] = $perfil;
        }
    }

    return count($encontrados) === 1 ? $encontrados[0] : null;
}

try {
    $nome  = trim($nome);
    $email = trim($email);

    /* ==========================================================
       VALIDA SE USUÁRIO EXISTE
    ========================================================== */
    $sqlUsuario = "SELECT id_usuario, nome, email, telefone, tipo_usuario, status FROM usuario WHERE id_usuario = ? LIMIT 1";
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
            'user_msg' => 'Usuário não encontrado.',
            'fields' => [
                'id_usuario' => 'O usuário informado não existe.',
            ],
        ], 404);
    }

    $tipoUsuario = (string)($usuario['tipo_usuario'] ?? '');
    $statusUsuarioGlobal = (string)($usuario['status'] ?? '');

    // Super Admins têm rota própria (editar-super), com regras e auditoria específicas.
    if ($tipoUsuario === 'super_admin') {
        out([
            'ok' => false,
            'code' => 'SUPER_ADMIN_ROTA_INVALIDA',
            'user_msg' => 'Este cadastro é de um Super Admin. Para editá-lo, utilize a área de Super Admins.',
            'fields' => [
                'id_usuario' => 'Este cadastro deve ser editado na área de Super Admins.',
            ],
        ], 403);
    }

    /* ==========================================================
       VALIDA DUPLICIDADE DE EMAIL
    ========================================================== */
    $sqlCheckEmail = "SELECT id_usuario FROM usuario WHERE email = ? AND id_usuario <> ? LIMIT 1";
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
       VÍNCULOS ENVIADOS x VÍNCULOS REAIS
       - cada vínculo enviado deve pertencer a este usuário e à empresa informada
       - perfis são revalidados no servidor (ativos e nunca Super Admin)
    ========================================================== */
    $vinculosAtuais = carregarVinculosUsuario($conexao, (int)$idUsuario, false);

    if (!$vinculosAtuais) {
        out([
            'ok' => false,
            'code' => 'VINCULO_NAO_ENCONTRADO',
            'user_msg' => 'Vínculo do usuário com a empresa não encontrado.',
            'fields' => ['vinculos' => 'Não foi encontrado vínculo para este usuário.'],
        ], 404);
    }

    foreach ($vinculosEnviados as $idEU => $enviado) {
        $real = $vinculosAtuais[$idEU] ?? null;
        if ($real === null || (int)$real['id_empresa'] !== $enviado['id_empresa']) {
            out([
                'ok' => false,
                'code' => 'VINCULO_INVALIDO',
                'user_msg' => 'Os vínculos enviados não correspondem ao usuário. Reabra o cadastro e tente novamente.',
                'fields' => ['vinculos' => 'Vínculo inválido para este usuário.'],
            ], 422);
        }
    }

    // A ação do cliente vira status no servidor; o perfil do vínculo existente nunca é alterado por esta rota.
    foreach ($vinculosEnviados as $idEU => $enviado) {
        $statusAtual = mb_strtolower((string)$vinculosAtuais[$idEU]['status']);
        $vinculosEnviados[$idEU]['status'] = match ($enviado['acao']) {
            'desvincular' => 'inativo',
            'reativar' => 'ativo',
            default => $statusAtual,
        };
    }

    $empresaNovaNome = '';
    $perfilNovoNome = '';
    if ($novoVinculo !== null) {
        // Empresa com vínculo existente (inclusive inativo) é reativada no vínculo, não duplicada.
        foreach ($vinculosAtuais as $real) {
            if ((int)$real['id_empresa'] === $novoVinculo['id_empresa']) {
                out([
                    'ok' => false,
                    'code' => 'USER_ALREADY_LINKED',
                    'user_msg' => 'Este usuário já possui vínculo com a empresa selecionada. Reative o vínculo existente.',
                    'fields' => ['novo_vinculo' => 'Empresa já vinculada a este usuário.'],
                ], 409);
            }
        }

        $st = $conexao->prepare('SELECT nome, status FROM empresa WHERE id_empresa = ? LIMIT 1');
        if (!$st) {
            throw new Exception('Prepare check empresa falhou.');
        }
        $st->bind_param('i', $novoVinculo['id_empresa']);
        $st->execute();
        $resEmpresa = $st->get_result();
        $empresaNova = $resEmpresa ? $resEmpresa->fetch_assoc() : null;
        $st->close();

        if (!$empresaNova || mb_strtolower((string)$empresaNova['status']) !== 'ativo') {
            out([
                'ok' => false,
                'code' => 'EMPRESA_INVALIDA',
                'user_msg' => 'Selecione uma empresa ativa para o novo vínculo.',
                'fields' => ['novo_vinculo' => 'Empresa inválida ou inativa.'],
            ], 422);
        }
        $empresaNovaNome = (string)$empresaNova['nome'];

        // O identificador do perfil Proprietário é sempre resolvido aqui; o cliente não o informa.
        $perfilProprietario = carregarPerfilProprietario($conexao);
        if ($perfilProprietario === null) {
            out([
                'ok' => false,
                'code' => 'PERFIL_PROPRIETARIO_INDISPONIVEL',
                'user_msg' => 'O perfil Proprietário não está disponível para o novo vínculo.',
                'fields' => ['novo_vinculo' => 'Perfil Proprietário indisponível.'],
            ], 422);
        }
        $novoVinculo['id_perfil'] = (int)$perfilProprietario['id_perfil'];
        $perfilNovoNome = (string)$perfilProprietario['nome'];
    }

    /* ==========================================================
       TRANSAÇÃO
       - empresas afetadas são bloqueadas em ordem crescente de id, para que
         edições concorrentes de usuários com empresas em comum não gerem deadlock
    ========================================================== */
    $conexao->begin_transaction();

    $empresasAfetadas = [];
    foreach ($vinculosEnviados as $enviado) {
        $real = $vinculosAtuais[$enviado['id_empresa_usuario']];
        if ($enviado['status'] !== mb_strtolower((string)$real['status'])) {
            $empresasAfetadas[$enviado['id_empresa']] = true;
        }
    }
    if ($novoVinculo !== null) {
        $empresasAfetadas[$novoVinculo['id_empresa']] = true;
    }
    $idsEmpresasAfetadas = array_keys($empresasAfetadas);
    sort($idsEmpresasAfetadas);

    $planos = [];
    foreach ($idsEmpresasAfetadas as $idEmpresaAfetada) {
        $resultadoPlano = limitesPlanoBloquearEmpresa($conexao, (int)$idEmpresaAfetada);
        limitesPlanoAbortarSeNegado($conexao, $resultadoPlano);
        $planos[$idEmpresaAfetada] = $resultadoPlano['plano'];
    }

    // Estado definitivo dos vínculos, lido já com as empresas bloqueadas.
    $vinculosAtuais = carregarVinculosUsuario($conexao, (int)$idUsuario, true);

    $usuarioGlobalConta = limitesPlanoStatusConta($statusUsuarioGlobal);
    $alteracoesPorEmpresa = [];

    foreach ($vinculosEnviados as $enviado) {
        $real = $vinculosAtuais[$enviado['id_empresa_usuario']] ?? null;
        if ($real === null || (int)$real['id_empresa'] !== $enviado['id_empresa']) {
            throw new Exception('Vínculo alterado durante a edição.');
        }

        $perfilAnteriorNome = (string)$real['perfil_nome'];
        $statusAnterior = mb_strtolower((string)$real['status']);
        $statusNovo = $enviado['status'];

        if ($statusNovo === $statusAnterior) {
            continue;
        }

        $resultadoLimites = limitesPlanoVerificarTransicaoPerfil(
            $conexao,
            $planos[$enviado['id_empresa']],
            $enviado['id_empresa'],
            $perfilAnteriorNome,
            $usuarioGlobalConta ? $statusAnterior : 'inativo',
            $perfilAnteriorNome,
            $usuarioGlobalConta ? $statusNovo : 'inativo'
        );
        limitesPlanoAbortarSeNegado($conexao, $resultadoLimites);

        // Inativar nunca exclui a linha: o histórico do vínculo é preservado e a reativação reaproveita o mesmo registro.
        $stVinculo = $conexao->prepare("
            UPDATE empresa_usuario
            SET status = ?
            WHERE id_empresa_usuario = ?
              AND id_usuario = ?
              AND id_empresa = ?
            LIMIT 1
        ");
        if (!$stVinculo) {
            throw new Exception('Prepare update vínculo falhou.');
        }
        $stVinculo->bind_param(
            'siii',
            $statusNovo,
            $enviado['id_empresa_usuario'],
            $idUsuario,
            $enviado['id_empresa']
        );
        if (!$stVinculo->execute()) {
            $errno = (int)$stVinculo->errno;
            $error = (string)$stVinculo->error;
            $stVinculo->close();
            throw new Exception("Erro ao atualizar vínculo [{$errno}] {$error}");
        }
        $stVinculo->close();

        $alteracoesPorEmpresa[$enviado['id_empresa']] = [
            'status_vinculo' => ['antes' => $statusAnterior, 'depois' => $statusNovo],
        ];
    }

    /* ==========================================================
       UPDATE usuario
    ========================================================== */
    if ($alterarSenha) {
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?,
                senha_hash = ?
            WHERE id_usuario = ?
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario com senha falhou.');
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
        $sql = "
            UPDATE usuario
            SET nome = ?,
                email = ?,
                telefone = ?
            WHERE id_usuario = ?
            LIMIT 1
        ";

        $stmt = $conexao->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare update usuario falhou.');
        }

        $stmt->bind_param(
            'sssi',
            $nome,
            $email,
            $telefone,
            $idUsuario
        );
    }

    $ok = $stmt->execute();

    if (!$ok) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        throw new Exception("Erro ao atualizar tabela usuario [{$errno}] {$error}");
    }

    $affectedUsuario = (int)$stmt->affected_rows;
    $stmt->close();

    /* ==========================================================
       NOVO VÍNCULO
       - reutiliza o serviço de vínculo (plano, perfil e duplicidade) na mesma transação
    ========================================================== */
    $idEmpresaUsuarioNovo = null;

    if ($novoVinculo !== null) {
        try {
            $resultadoVinculo = usuarioEmpresaServicoCriarOuVincular($conexao, [
                'id_empresa' => $novoVinculo['id_empresa'],
                'id_perfil' => $novoVinculo['id_perfil'],
                'nome' => $nome,
                'email' => $email,
                'telefone' => (string)$telefone,
                'status' => 'ativo',
                'autorizar_vinculo_existente' => true,
                'perfil_esperado_normalizado' => 'proprietarios',
            ]);
        } catch (UsuarioEmpresaServicoErro $erroServico) {
            $conexao->rollback();
            $detalhes = $erroServico->dados();
            $statusHttp = (int)($detalhes['http_status'] ?? 409);
            unset($detalhes['http_status']);

            out($detalhes !== [] ? $detalhes : [
                'ok' => false,
                'code' => $erroServico->codigo(),
                'user_msg' => $erroServico->getMessage(),
                'fields' => ['novo_vinculo' => $erroServico->getMessage()],
            ], $statusHttp);
        }

        if ((int)$resultadoVinculo['id_usuario'] !== (int)$idUsuario) {
            throw new Exception('Novo vínculo resolvido para outro usuário.');
        }
        $idEmpresaUsuarioNovo = (int)$resultadoVinculo['id_empresa_usuario'];
    }

    /* ==========================================================
       AUDITORIA
       - registrada na empresa de cada vínculo alterado, sem cruzar empresas
    ========================================================== */
    $antes = [
        'nome' => (string)($usuario['nome'] ?? ''),
        'email' => (string)($usuario['email'] ?? ''),
        'telefone' => $usuario['telefone'] ?? null,
    ];
    $depois = ['nome' => $nome, 'email' => $email, 'telefone' => $telefone];
    $alteracoesUsuario = [];
    foreach ($depois as $campo => $valor) {
        if (!auditoriaValoresIguais($antes[$campo] ?? null, $valor)) {
            $alteracoesUsuario[$campo] = ['antes' => $antes[$campo] ?? null, 'depois' => $valor];
        }
    }

    // Dados globais do usuário não pertencem a uma empresa: o evento fica na primeira empresa vinculada.
    $idEmpresaReferencia = (int)reset($vinculosAtuais)['id_empresa'];

    if ($alteracoesUsuario !== []) {
        auditoriaRegistrar($conexao, 'usuario.editado', [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresaReferencia),
            'entidade_id' => (int)$idUsuario,
            'entidade_rotulo' => $nome,
            'descricao' => 'Alterou o usuário ' . $nome . '.',
            'alteracoes' => $alteracoesUsuario,
            'contexto' => ['origem' => 'painel_super_admin'],
        ]);
    }

    foreach ($alteracoesPorEmpresa as $idEmpresaAuditoria => $alteracoes) {
        auditoriaRegistrar($conexao, 'usuario.editado', [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, (int)$idEmpresaAuditoria),
            'entidade_id' => (int)$idUsuario,
            'entidade_rotulo' => $nome,
            'descricao' => 'Alterou o vínculo do usuário ' . $nome . '.',
            'alteracoes' => $alteracoes,
            'contexto' => ['origem' => 'painel_super_admin', 'id_empresa' => (int)$idEmpresaAuditoria],
        ]);
    }

    if ($novoVinculo !== null) {
        auditoriaRegistrar($conexao, 'usuario.vinculado_empresa', [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, $novoVinculo['id_empresa']),
            'entidade_id' => (int)$idUsuario,
            'entidade_rotulo' => $nome,
            'descricao' => 'Vinculou o usuário ' . $nome . ' a uma empresa.',
            'alteracoes' => [
                'perfil' => ['antes' => null, 'depois' => $perfilNovoNome],
                'status_vinculo' => ['antes' => null, 'depois' => 'ativo'],
            ],
            'contexto' => ['origem' => 'painel_super_admin', 'id_empresa' => $novoVinculo['id_empresa']],
        ]);
    }

    if ($alterarSenha) {
        auditoriaRegistrar($conexao, 'usuario.senha_redefinida', [
            'ator' => auditoriaResolverAtorSuperAdmin($conexao, $idEmpresaReferencia),
            'entidade_id' => (int)$idUsuario,
            'entidade_rotulo' => $nome,
            'descricao' => 'Redefiniu a senha do usuário ' . $nome . '.',
            'alteracoes' => ['senha_alterada' => ['antes' => false, 'depois' => true]],
            'contexto' => ['origem' => 'painel_super_admin'],
        ]);
    }

    $conexao->commit();

    $houveAlteracao = ($affectedUsuario > 0) || $alteracoesPorEmpresa !== [] || $idEmpresaUsuarioNovo !== null;

    out([
        'ok' => true,
        'code' => 'UPDATED',
        'user_msg' => $houveAlteracao
            ? ($alterarSenha ? 'Usuário e senha atualizados com sucesso.' : 'Usuário atualizado com sucesso.')
            : 'Nenhuma alteração foi realizada.',
        'data' => [
            'id_usuario'         => $idUsuario,
            'nome'               => $nome,
            'email'              => $email,
            'telefone'           => $telefone,
            'senha_alterada'     => $alterarSenha,
            'tipo_usuario'       => $tipoUsuario,
            'vinculos_alterados' => count($alteracoesPorEmpresa),
            'novo_vinculo'       => $idEmpresaUsuarioNovo,
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

    error_log('[editar_usuario.php] ' . $e->getMessage());

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
        'user_msg' => 'Erro interno ao atualizar o usuário.',
    ], 500);
}
