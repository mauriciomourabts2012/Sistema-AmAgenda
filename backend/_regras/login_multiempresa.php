<?php
declare(strict_types=1);

/**
 * Regras compartilhadas do login interno multiempresa (Fase 8.2).
 *
 * Usado por backend/_auth/login.php e backend/_auth/selecionar_empresa.php para
 * que o login com uma única empresa e a seleção posterior usem exatamente as
 * mesmas validações e criem a sessão final com o mesmo formato.
 * Não altera banco: lê usuario/empresa_usuario/empresa/perfil e usa $_SESSION.
 */

const LOGIN_PENDENTE_CHAVE = 'login_pendente';
const LOGIN_PENDENTE_TTL_SEGUNDOS = 300;
const LOGIN_PENDENTE_MAX_TENTATIVAS = 5;

if (!function_exists('normalizaEmpresaNome')) {
    function normalizaEmpresaNome(string $nome): string {
        $nome = trim(mb_strtolower($nome, 'UTF-8'));

        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c',
            'ñ'=>'n'
        ];

        $nome = strtr($nome, $map);
        $nome = preg_replace('/[^a-z0-9]+/u', '-', $nome) ?? '';
        $nome = trim($nome, '-');

        return $nome;
    }
}

/** Remove qualquer contexto operacional (e a pré-sessão) da sessão atual. */
function loginMultiempresaLimparContexto(): void
{
    foreach ([
        'auth', 'superadmin_id', 'superadmin_nome', 'superadmin_email', 'super',
        'usuario_id', 'usuario_nome', 'usuario_email', 'usuario_tipo',
        'perfil_id', 'perfil_nome', 'modo_suporte',
        'empresa_id', 'empresa_nome', 'empresa_slug',
        LOGIN_PENDENTE_CHAVE,
    ] as $chave) {
        unset($_SESSION[$chave]);
    }
}

/** Gera um novo token CSRF (compatível com backend/_auth/csrf.php) e o devolve. */
function loginMultiempresaRotacionarCsrf(): string
{
    require_once __DIR__ . '/../_auth/csrf.php';
    unset($_SESSION['csrf_token']);
    return csrfTokenSessao();
}

/**
 * Lista os vínculos do usuário com empresa e perfil, sem filtrar por status:
 * a elegibilidade é decidida por loginMultiempresaAvaliarVinculo().
 *
 * @return array<int, array<string, mixed>>|null null em falha técnica
 */
function loginMultiempresaBuscarVinculos(mysqli $conexao, int $idUsuario, ?int $idEmpresa = null): ?array
{
    $sql = "
        SELECT
            eu.id_empresa,
            eu.id_perfil,
            p.nome AS perfil_nome,
            p.status AS perfil_status,
            eu.status AS status_vinculo,
            eu.bloqueado_plano,
            e.nome AS empresa_nome,
            e.status AS empresa_status
        FROM empresa_usuario eu
        INNER JOIN empresa e ON e.id_empresa = eu.id_empresa
        INNER JOIN perfil p ON p.id_perfil = eu.id_perfil
        WHERE eu.id_usuario = ?
    ";
    if ($idEmpresa !== null) $sql .= ' AND eu.id_empresa = ?';
    $sql .= ' ORDER BY e.nome ASC, eu.id_empresa ASC';

    $stmt = $conexao->prepare($sql);
    if (!$stmt) return null;
    if ($idEmpresa !== null) $stmt->bind_param('ii', $idUsuario, $idEmpresa);
    else $stmt->bind_param('i', $idUsuario);

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $linhas = [];
    while ($res && ($linha = $res->fetch_assoc())) $linhas[] = $linha;
    $stmt->close();
    return $linhas;
}

/**
 * Aplica as mesmas regras que o login por empresa já aplicava, na mesma ordem:
 * vínculo ativo, plano, empresa ativa, perfil, assinatura e perfil permitido.
 *
 * Retorno permitido: permitido=true + dados para consolidar a sessão.
 * Retorno negado: permitido=false + evento/motivo de auditoria (uso interno,
 * nunca devem ser repassados ao navegador).
 */
function loginMultiempresaAvaliarVinculo(mysqli $conexao, array $v): array
{
    $empresaId = (int)($v['id_empresa'] ?? 0);
    $perfilId = (int)($v['id_perfil'] ?? 0);
    $perfilNomeDb = trim((string)($v['perfil_nome'] ?? ''));
    $perfilStatus = mb_strtolower(trim((string)($v['perfil_status'] ?? '')), 'UTF-8');
    $statusVinculo = mb_strtolower(trim((string)($v['status_vinculo'] ?? '')), 'UTF-8');
    $statusEmpresa = mb_strtolower(trim((string)($v['empresa_status'] ?? '')), 'UTF-8');
    $empresaNome = trim((string)($v['empresa_nome'] ?? ''));

    $negar = static fn(string $evento, string $motivo, bool $erroTecnico = false): array => [
        'permitido' => false,
        'empresa_id' => $empresaId,
        'evento' => $evento,
        'motivo' => $motivo,
        'erro_tecnico' => $erroTecnico,
    ];

    if ($empresaId <= 0) return $negar('autenticacao.acesso_negado', 'vinculo_invalido');

    if ($statusVinculo !== 'ativo') {
        return $negar('autenticacao.vinculo_inativo', $statusVinculo === 'bloqueado' ? 'vinculo_bloqueado' : 'vinculo_inativo');
    }
    if ((int)($v['bloqueado_plano'] ?? 0) === 1) {
        return $negar('autenticacao.acesso_negado', 'acesso_indisponivel_plano');
    }
    if ($statusEmpresa !== 'ativo') {
        return $negar('autenticacao.empresa_inativa', $statusEmpresa === 'bloqueado' ? 'empresa_bloqueada' : 'empresa_inativa');
    }
    if ($perfilId <= 0) return $negar('autenticacao.acesso_negado', 'perfil_ausente');
    if ($perfilStatus !== 'ativo') {
        return $negar('autenticacao.acesso_negado', $perfilStatus === 'bloqueado' ? 'perfil_bloqueado' : 'perfil_inativo');
    }

    $acessoAssinatura = acessoAssinaturaValidar($conexao, $empresaId, $perfilNomeDb);
    if (!($acessoAssinatura['permitido'] ?? false)) {
        return $negar(
            'autenticacao.acesso_negado',
            (string)($acessoAssinatura['motivo'] ?? 'sem_contrato_ativo'),
            (bool)($acessoAssinatura['erro_tecnico'] ?? false)
        );
    }
    $modoRegularizacao = (bool)($acessoAssinatura['modo_regularizacao'] ?? false);

    switch (normalizaEmpresaNome($perfilNomeDb)) {
        case 'proprietario':
            $perfilNome = 'proprietario';
            $redirect = '/views/painel-administrativo/painel-administrativo.html';
            break;
        case 'profissional':
            $perfilNome = 'profissional';
            $redirect = '/views/agenda.html';
            break;
        case 'recepcao':
        case 'recepcionista':
            $perfilNome = 'recepcionista';
            $redirect = '/views/agenda.html';
            break;
        default:
            return $negar('autenticacao.acesso_negado', 'perfil_nao_permitido');
    }

    if ($modoRegularizacao) {
        $redirect = '/views/painel-administrativo/painel-administrativo.html';
    }

    return [
        'permitido' => true,
        'empresa_id' => $empresaId,
        'empresa_nome' => $empresaNome,
        'perfil_id' => $perfilId,
        'perfil_nome' => $perfilNome,
        'perfil_nome_exibicao' => $perfilNomeDb,
        'redirect' => $redirect,
        'modo_regularizacao' => $modoRegularizacao,
    ];
}

/** Registra a falha de autenticação sem nunca interromper o fluxo. */
function loginMultiempresaAuditarFalha(mysqli $conexao, string $evento, string $motivo, int $idEmpresa = 0): void
{
    try {
        auditoriaRegistrarFalhaAutenticacao($conexao, $evento, $motivo, $idEmpresa > 0 ? $idEmpresa : null, '');
    } catch (Throwable) {
        error_log('[auditoria_login] Não foi possível registrar uma falha de autenticação.');
    }
}

/**
 * Cria a pré-sessão de seleção. Não grava nenhuma chave que sessao.php,
 * require_auth.php ou os handlers reconheçam como usuário autenticado.
 */
function loginMultiempresaCriarPreSessao(int $idUsuario): string
{
    session_regenerate_id(true);
    loginMultiempresaLimparContexto();

    $agora = time();
    $_SESSION[LOGIN_PENDENTE_CHAVE] = [
        'id_usuario' => $idUsuario,
        'criado_em' => $agora,
        'expira_em' => $agora + LOGIN_PENDENTE_TTL_SEGUNDOS,
        'tentativas' => 0,
    ];

    return loginMultiempresaRotacionarCsrf();
}

/**
 * Cria a sessão operacional final no formato já esperado por sessao.php,
 * require_auth.php, permissoes_usuario.php e sessao.js. Só deve ser chamada
 * depois de loginMultiempresaAvaliarVinculo() retornar permitido=true.
 *
 * @return array<string, mixed> bloco "data" da resposta LOGIN_OK
 */
function loginMultiempresaConsolidarSessao(mysqli $conexao, array $user, array $acesso): array
{
    $idUsuario = (int)$user['id_usuario'];
    $tipoUsuario = mb_strtolower(trim((string)($user['tipo_usuario'] ?? 'usuario')), 'UTF-8');
    $statusUsuario = mb_strtolower(trim((string)($user['status'] ?? '')), 'UTF-8');
    $deveAlterarSenha = (int)($user['deve_alterar_senha'] ?? 0) === 1;
    $senhaTemporariaVencida = (int)($user['senha_temporaria_vencida'] ?? 0) === 1;
    $empresaId = (int)$acesso['empresa_id'];
    $empresaNome = (string)$acesso['empresa_nome'];
    $perfilId = (int)$acesso['perfil_id'];
    $perfilNome = (string)$acesso['perfil_nome'];
    $modoRegularizacao = (bool)$acesso['modo_regularizacao'];

    $stmt = $conexao->prepare('UPDATE usuario SET ultimo_login_em = NOW() WHERE id_usuario = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $idUsuario);
        $stmt->execute();
        $stmt->close();
    }

    session_regenerate_id(true);
    loginMultiempresaLimparContexto();

    $_SESSION['empresa_id'] = $empresaId;
    $_SESSION['empresa_nome'] = $empresaNome;

    $_SESSION['auth'] = [
        'logado' => true,
        'id_usuario' => $idUsuario,
        'nome' => (string)$user['nome'],
        'email' => (string)$user['email'],
        'tipo_usuario' => $tipoUsuario,
        'status' => $statusUsuario,
        'empresa_id' => $empresaId,
        'empresa_nome' => $empresaNome,
        'perfil_id' => $perfilId,
        'perfil_nome' => $perfilNome,
        'modo_suporte' => false,
        'modo_regularizacao' => $modoRegularizacao,
        'deve_alterar_senha' => $deveAlterarSenha,
        'senha_temporaria_vencida' => $senhaTemporariaVencida,
    ];

    $_SESSION['usuario_id'] = $idUsuario;
    $_SESSION['usuario_nome'] = (string)$user['nome'];
    $_SESSION['usuario_email'] = (string)$user['email'];
    $_SESSION['usuario_tipo'] = $tipoUsuario;
    $_SESSION['perfil_id'] = $perfilId;
    $_SESSION['perfil_nome'] = $perfilNome;

    loginMultiempresaRotacionarCsrf();

    return [
        'redirect' => (string)$acesso['redirect'],
        'empresa_id' => $empresaId,
        'empresa_nome' => $empresaNome,
        'perfil_id' => $perfilId,
        'perfil_nome' => $perfilNome,
        'modo_regularizacao' => $modoRegularizacao,
        'deve_alterar_senha' => $deveAlterarSenha,
        'senha_temporaria_vencida' => $senhaTemporariaVencida,
    ];
}

/** Resposta LOGIN_OK idêntica à anterior (usada por login.php e pela seleção). */
function loginMultiempresaResponderLoginOk(array $data): void
{
    out([
        'ok' => true,
        'step' => 'done',
        'code' => 'LOGIN_OK',
        'user_msg' => !empty($data['modo_regularizacao'])
            ? 'A assinatura está suspensa. Regularize o faturamento para continuar usando o sistema.'
            : 'Login realizado com sucesso.',
        'data' => $data,
    ], 200);
}
