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

/* ==========================================================
   CREDENCIAL DA SESSÃO (A6.1) — revogação após troca de senha
   ----------------------------------------------------------
   A sessão guarda, somente no servidor, a impressão da credencial validada:
   hash('sha256', (string)senha_hash). Toda troca de senha altera senha_hash e,
   com isso, invalida as sessões antigas. A impressão nunca é enviada ao
   frontend; a comparação usa hash_equals(). Sessões sem marcador ou com marcador
   divergente recebem 401 SESSION_REAUTH_REQUIRED. Falha técnica de consulta
   continua sendo erro técnico (500), nunca 401.
   ========================================================== */
const SESSAO_CREDENCIAL_INTERNA = 'auth_credencial';
const SESSAO_CREDENCIAL_CLIENTE = 'cliente_credencial';
const SESSAO_LOGIN_INTERNO_URL = '/public/views/login-empresa.php';
const SESSAO_LOGIN_CLIENTE_URL = '/public/views/login-cliente.php';

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
        LOGIN_PENDENTE_CHAVE, SESSAO_CREDENCIAL_INTERNA,
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
function loginMultiempresaCriarPreSessao(int $idUsuario, string $credencialImpressao = ''): string
{
    session_regenerate_id(true);
    loginMultiempresaLimparContexto();

    $agora = time();
    $_SESSION[LOGIN_PENDENTE_CHAVE] = [
        'id_usuario' => $idUsuario,
        'criado_em' => $agora,
        'expira_em' => $agora + LOGIN_PENDENTE_TTL_SEGUNDOS,
        'tentativas' => 0,
        // Credencial validada no login (A6.1): a seleção só consolida a sessão
        // se a senha não tiver mudado desde então.
        'credencial' => $credencialImpressao,
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

    // Marcador da credencial validada (A6.1); somente no servidor.
    $_SESSION[SESSAO_CREDENCIAL_INTERNA] = sessaoCredencialImpressao(
        array_key_exists('senha_hash', $user) ? (string)$user['senha_hash'] : null
    );

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
            ? 'O faturamento está disponível em modo restrito.'
            : 'Login realizado com sucesso.',
        'data' => $data,
    ], 200);
}

/* ==========================================================
   INATIVIDADE DA SESSÃO — política autoritativa do servidor
   ----------------------------------------------------------
   Prazo de inatividade (relógio do servidor, time()):
   - funcionários e profissionais: 2700 s; clientes: 2700 s;
   - Super Admin, inclusive em Modo Suporte: 1800 s (a identidade real
     tipo_usuario='super_admin' prevalece sobre o contexto assumido).
   O marcador fica somente em $_SESSION, separado por escopo (interno/cliente),
   vinculado à identidade e ao id da sessão. Vencido o prazo, a identidade do
   escopo é revogada e a resposta é 401, ainda que o arquivo da sessão exista
   (não depende do garbage collector do PHP).
   Renovação (o servidor não detecta atividade humana):
   - escrita autenticada (POST/PUT/PATCH/DELETE) com o token CSRF da sessão,
     exceto rotas de escrita automática (marcação de leitura do chat);
   - GET _auth/session (carregamento de página e retorno/interação do
     usuário), salvo quando chamado com renovar=0 (somente verificação).
   Consultas GET (polling de chat, notificações, resumos) nunca renovam.
   Sem marcador válido (login recém-concluído, regeneração explícita do id da
   sessão ou sessão anterior a esta política), o prazo começa na requisição
   atual; isso só ocorre após login, troca de senha ou entrada/saída do Modo
   Suporte, sempre depois de o guard ter validado o prazo anterior.
   Não há duração absoluta: o prazo é exclusivamente de inatividade.
   ========================================================== */
const SESSAO_INATIVIDADE_CHAVE = 'sessao_inatividade';
const SESSAO_INATIVIDADE_INTERNO_SEGUNDOS = 2700;
const SESSAO_INATIVIDADE_SUPER_ADMIN_SEGUNDOS = 1800;
const SESSAO_INATIVIDADE_CLIENTE_SEGUNDOS = 2700;
const SESSAO_INATIVIDADE_ROTAS_ESCRITA_AUTOMATICA = [
    'chat/leitura/marcar',
    'cliente/chat/leitura/marcar',
];

/** Prazo do escopo. Interno: Super Admin (inclusive em suporte) 1800 s; demais 2700 s. */
function sessaoInatividadeLimite(string $escopo): int
{
    if ($escopo === 'cliente') {
        return SESSAO_INATIVIDADE_CLIENTE_SEGUNDOS;
    }
    $tipo = mb_strtolower(trim((string)($_SESSION['auth']['tipo_usuario'] ?? '')), 'UTF-8');

    return $tipo === 'super_admin'
        ? SESSAO_INATIVIDADE_SUPER_ADMIN_SEGUNDOS
        : SESSAO_INATIVIDADE_INTERNO_SEGUNDOS;
}

/** Identidade do escopo; o contexto empresarial assumido (suporte) não participa. */
function sessaoInatividadeIdentidade(string $escopo): string
{
    if ($escopo === 'cliente') {
        $cliente = is_array($_SESSION['cliente_auth'] ?? null) ? $_SESSION['cliente_auth'] : [];
        return 'cliente:' . (int)($cliente['id_empresa'] ?? 0) . ':' . (string)($cliente['telefone'] ?? '');
    }
    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];

    return 'interno:'
        . mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8')
        . ':' . (int)($auth['id_usuario'] ?? 0);
}

/** Decide, somente com dados do servidor e da rota, se esta requisição renova o prazo. */
function sessaoInatividadeRequisicaoRenova(): bool
{
    $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $rota = trim((string)($_GET['path'] ?? ''), "/ \t\n\r\0\x0B");

    if ($rota === '_auth/session') {
        return $metodo === 'GET' && (string)($_GET['renovar'] ?? '1') !== '0';
    }
    if (in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return false;
    }
    if (in_array($rota, SESSAO_INATIVIDADE_ROTAS_ESCRITA_AUTOMATICA, true)) {
        return false;
    }

    // Somente escrita originada pela própria aplicação (mesmo token CSRF da sessão).
    $recebido = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $esperado = (string)($_SESSION['csrf_token'] ?? '');

    return $esperado !== '' && $recebido !== '' && hash_equals($esperado, $recebido);
}

/** @return array{ultima_atividade:int}|null marcador válido do escopo, se houver */
function sessaoInatividadeMarcador(string $escopo): ?array
{
    $marcadores = is_array($_SESSION[SESSAO_INATIVIDADE_CHAVE] ?? null) ? $_SESSION[SESSAO_INATIVIDADE_CHAVE] : [];
    $marcador = $marcadores[$escopo] ?? null;
    if (!is_array($marcador)
        || !is_int($marcador['ultima_atividade'] ?? null)
        || !is_string($marcador['sid'] ?? null)
        || !is_string($marcador['identidade'] ?? null)
        || !hash_equals($marcador['sid'], (string)session_id())
        || !hash_equals($marcador['identidade'], sessaoInatividadeIdentidade($escopo))) {
        return null;
    }

    return $marcador;
}

/** Segundos restantes do escopo, sem renovar (0 quando ausente ou vencido). */
function sessaoInatividadeRestante(string $escopo): int
{
    $marcador = sessaoInatividadeMarcador($escopo);
    if ($marcador === null) {
        return 0;
    }
    $decorrido = max(0, time() - $marcador['ultima_atividade']);

    return max(0, sessaoInatividadeLimite($escopo) - $decorrido);
}

/**
 * Exige que o escopo ('interno' ou 'cliente') esteja dentro do prazo de
 * inatividade. Vencido: revoga a identidade do escopo e responde 401.
 * Dentro do prazo: renova somente quando a requisição é renovadora.
 * A sessão PHP permanece bloqueada durante a requisição, então verificação
 * e renovação ocorrem juntas; requisições concorrentes não renovam um prazo vencido.
 */
function sessaoInatividadeExigir(string $escopo): void
{
    $escopo = $escopo === 'cliente' ? 'cliente' : 'interno';
    $agora = time();
    $marcador = sessaoInatividadeMarcador($escopo);

    if ($marcador !== null) {
        // Relógio do servidor recuado não amplia o prazo.
        $ultima = min($marcador['ultima_atividade'], $agora);
        if ($agora - $ultima >= sessaoInatividadeLimite($escopo)) {
            $marcadores = is_array($_SESSION[SESSAO_INATIVIDADE_CHAVE] ?? null) ? $_SESSION[SESSAO_INATIVIDADE_CHAVE] : [];
            unset($marcadores[$escopo]);
            $_SESSION[SESSAO_INATIVIDADE_CHAVE] = $marcadores;

            if ($escopo === 'cliente') {
                sessaoCredencialRevogarCliente();
                out([
                    'ok' => false,
                    'code' => 'CLIENT_NOT_AUTHENTICATED',
                    'user_msg' => 'Sua sessão expirou por inatividade. Faça login novamente.',
                    'data' => ['motivo' => 'inatividade'],
                ], 401);
            }

            sessaoCredencialRevogarInterna();
            out([
                'ok' => false,
                'code' => 'NOT_AUTHENTICATED',
                'user_msg' => 'Sua sessão expirou por inatividade. Faça login novamente.',
                'data' => ['motivo' => 'inatividade'],
            ], 401);
        }

        if (!sessaoInatividadeRequisicaoRenova()) {
            return;
        }
    }

    $marcadores = is_array($_SESSION[SESSAO_INATIVIDADE_CHAVE] ?? null) ? $_SESSION[SESSAO_INATIVIDADE_CHAVE] : [];
    $marcadores[$escopo] = [
        'ultima_atividade' => $agora,
        'sid' => (string)session_id(),
        'identidade' => sessaoInatividadeIdentidade($escopo),
    ];
    $_SESSION[SESSAO_INATIVIDADE_CHAVE] = $marcadores;
}

/* ==========================================================
   CREDENCIAL DA SESSÃO (A6.1) — funções
   ========================================================== */

/** Impressão da credencial: somente no servidor, nunca enviada ao frontend. */
function sessaoCredencialImpressao(?string $senhaHash): string
{
    return hash('sha256', (string)$senhaHash);
}

/** Confere o marcador da sessão com a credencial atual (sem marcador = não confere). */
function sessaoCredencialConfere(string $chaveSessao, ?string $senhaHashAtual): bool
{
    $marcador = $_SESSION[$chaveSessao] ?? null;
    if (!is_string($marcador) || preg_match('/^[a-f0-9]{64}$/', $marcador) !== 1) {
        return false;
    }
    return hash_equals($marcador, sessaoCredencialImpressao($senhaHashAtual));
}

/** Resposta única de revogação, tratada pelo sessao.js (sem repetir avisos). */
function sessaoCredencialResponderReautenticacao(string $destino): void
{
    out([
        'ok' => false,
        'code' => 'SESSION_REAUTH_REQUIRED',
        'user_msg' => 'Sua sessão foi encerrada por segurança. Faça login novamente.',
        'data' => ['redirect_url' => $destino],
    ], 401);
}

/**
 * Revoga a identidade interna (funcionário/Super Admin) e o contexto
 * privilegiado, inclusive Modo Suporte. Uma sessão de cliente que coexista na
 * mesma sessão PHP é preservada, com o contexto público da empresa que ela usa.
 */
function sessaoCredencialRevogarInterna(): void
{
    $clienteAtivo = is_array($_SESSION['cliente_auth'] ?? null) && $_SESSION['cliente_auth'] !== [];
    $contextoPublico = [];
    if ($clienteAtivo) {
        foreach (['empresa_id', 'empresa_nome', 'empresa_slug'] as $chave) {
            if (array_key_exists($chave, $_SESSION)) {
                $contextoPublico[$chave] = $_SESSION[$chave];
            }
        }
    }

    loginMultiempresaLimparContexto();
    unset(
        $_SESSION[SESSAO_CREDENCIAL_INTERNA],
        $_SESSION['id_empresa'],
        $_SESSION['modo_suporte_iniciado_em']
    );

    foreach ($contextoPublico as $chave => $valor) {
        $_SESSION[$chave] = $valor;
    }
}

/**
 * Revoga a identidade do cliente. Preserva somente o contexto público da
 * empresa (empresa_id, empresa_nome, empresa_slug) para o retorno ao login da
 * Agenda Online dessa empresa.
 */
function sessaoCredencialRevogarCliente(): void
{
    unset(
        $_SESSION['cliente_auth'],
        $_SESSION['cliente_auth_desafio'],
        $_SESSION['cliente_recuperacao_senha'],
        $_SESSION[SESSAO_CREDENCIAL_CLIENTE]
    );
}

/**
 * Exige que a sessão interna corresponda à credencial atual do usuário.
 * - $senhaHashAtual já lido (require_auth) ou null para consultar aqui.
 * - Divergência ou ausência de marcador: revoga e responde 401 SESSION_REAUTH_REQUIRED.
 * - Falha técnica: 500 SESSION_USER_CHECK_ERROR (nunca 401).
 */
function sessaoCredencialExigirInterna(?mysqli $conexao, int $idUsuario, bool $senhaJaLida = false, ?string $senhaHashAtual = null): void
{
    // Inatividade antes de qualquer consulta: sessão vencida não chega à credencial.
    sessaoInatividadeExigir('interno');

    if (!$senhaJaLida) {
        if (!($conexao instanceof mysqli) || $conexao->connect_errno) {
            out(['ok' => false, 'code' => 'SESSION_USER_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $stmt = $conexao->prepare('SELECT senha_hash FROM usuario WHERE id_usuario = ? LIMIT 1');
        if (!$stmt) {
            out(['ok' => false, 'code' => 'SESSION_USER_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $stmt->bind_param('i', $idUsuario);
        if (!$stmt->execute()) {
            $stmt->close();
            out(['ok' => false, 'code' => 'SESSION_USER_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $resultado = $stmt->get_result();
        $linha = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
        $stmt->close();
        if ($linha === null) {
            sessaoCredencialRevogarInterna();
            sessaoCredencialResponderReautenticacao(SESSAO_LOGIN_INTERNO_URL);
        }
        $senhaHashAtual = $linha['senha_hash'] === null ? null : (string)$linha['senha_hash'];
    }

    if (!sessaoCredencialConfere(SESSAO_CREDENCIAL_INTERNA, $senhaHashAtual)) {
        sessaoCredencialRevogarInterna();
        sessaoCredencialResponderReautenticacao(SESSAO_LOGIN_INTERNO_URL);
    }

    if (!defined('AUTH_CREDENCIAL_VALIDADA')) {
        define('AUTH_CREDENCIAL_VALIDADA', true);
    }
}

/**
 * Exige que a sessão do cliente corresponda à credencial atual.
 * - Sem id_cliente (cadastro ainda não criado): credencial vazia.
 * - Cliente inexistente, divergência ou ausência de marcador: revoga e responde
 *   401 SESSION_REAUTH_REQUIRED, com retorno ao login da empresa.
 * - Falha técnica: 500 CLIENT_SESSION_CHECK_ERROR (nunca 401).
 */
function sessaoCredencialExigirCliente(?mysqli $conexao, int $idEmpresa, ?int $idCliente): void
{
    // Inatividade antes de qualquer consulta: sessão vencida não chega à credencial.
    sessaoInatividadeExigir('cliente');

    $senhaHashAtual = null;
    if ($idCliente !== null && $idCliente > 0) {
        if (!($conexao instanceof mysqli) || $conexao->connect_errno) {
            out(['ok' => false, 'code' => 'CLIENT_SESSION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $stmt = $conexao->prepare('SELECT senha_hash FROM cliente WHERE id_cliente = ? AND id_empresa = ? LIMIT 1');
        if (!$stmt) {
            out(['ok' => false, 'code' => 'CLIENT_SESSION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $stmt->bind_param('ii', $idCliente, $idEmpresa);
        if (!$stmt->execute()) {
            $stmt->close();
            out(['ok' => false, 'code' => 'CLIENT_SESSION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar sua sessão.'], 500);
        }
        $resultado = $stmt->get_result();
        $linha = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
        $stmt->close();
        if ($linha === null) {
            sessaoCredencialRevogarCliente();
            sessaoCredencialResponderReautenticacao(SESSAO_LOGIN_CLIENTE_URL);
        }
        $senhaHashAtual = $linha['senha_hash'] === null ? null : (string)$linha['senha_hash'];
    }

    if (!sessaoCredencialConfere(SESSAO_CREDENCIAL_CLIENTE, $senhaHashAtual)) {
        sessaoCredencialRevogarCliente();
        sessaoCredencialResponderReautenticacao(SESSAO_LOGIN_CLIENTE_URL);
    }
}
