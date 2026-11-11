<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LOGIN DO CLIENTE - TELEFONE + SENHA OU OTP
|--------------------------------------------------------------------------
|
| Responsabilidades:
| - decidir entre senha e OTP sem enviar SMS antecipadamente;
| - autenticar por senha quando já houver credencial definitiva;
| - receber enviar_codigo / iniciar_recuperacao / validar_codigo;
| - obter id_empresa exclusivamente da sessão;
| - localizar cliente por id_empresa + whatsapp_celular;
| - coordenar cliente_auth_desafio;
| - criar cliente_auth após validação.
|
| OTP:
| - gerado, persistido (somente hash), enviado por SMS (Infobip) e validado
|   por backend/_servicos/sms_otp.php, na tabela otp_desafio;
| - expiração, tentativas, reenvio e consumo único são persistentes;
| - não existe código fixo nem bypass de homologação.
|
| Este arquivo NÃO armazena credenciais do provedor de SMS.
|
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| RESPOSTA JSON
|--------------------------------------------------------------------------
*/

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| CONTEXTO DA EMPRESA
|--------------------------------------------------------------------------
|
| id_empresa nunca pode vir do navegador.
|
*/

$idEmpresa = (int)(
    $_SESSION['empresa_id'] ?? 0
);

if ($idEmpresa <= 0) {
    out([
        'ok' => false,
        'code' => 'CLIENT_COMPANY_CONTEXT_INVALID',
        'user_msg' =>
            'Não foi possível iniciar o acesso. Tente novamente.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| MÉTODO
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    !== 'POST'
) {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.'
    ], 405);
}


/*
|--------------------------------------------------------------------------
| DEPENDÊNCIAS
|--------------------------------------------------------------------------
*/

require_once
    __DIR__ . '/../_config/conexao.php';

require_once
    __DIR__ . '/../_servicos/sms_otp.php';

// Canal e-mail (C3): regras de elegibilidade do e-mail no login do cliente.
require_once
    __DIR__ . '/../_regras/cliente_login_email.php';

// Marcador da credencial da sessão (A6.1).
require_once
    __DIR__ . '/../_regras/login_multiempresa.php';


/*
|--------------------------------------------------------------------------
| REVALIDAR EMPRESA DA SESSÃO
|--------------------------------------------------------------------------
*/

$stmtEmpresa =
    $conexao->prepare("
        SELECT id_empresa
        FROM empresa
        WHERE id_empresa = ?
          AND status = 'ativo'
        LIMIT 1
    ");

if (!$stmtEmpresa) {
    out([
        'ok' => false,
        'code' =>
            'CLIENT_COMPANY_CHECK_UNAVAILABLE',

        'user_msg' =>
            'Não foi possível validar a empresa agora.'
    ], 503);
}

$stmtEmpresa->bind_param(
    'i',
    $idEmpresa
);

if (!$stmtEmpresa->execute()) {
    $stmtEmpresa->close();

    out([
        'ok' => false,
        'code' =>
            'CLIENT_COMPANY_CHECK_UNAVAILABLE',

        'user_msg' =>
            'Não foi possível validar a empresa agora.'
    ], 503);
}

$resultadoEmpresa =
    $stmtEmpresa->get_result();

$empresaValida =
    $resultadoEmpresa
    &&
    $resultadoEmpresa->num_rows === 1;

$stmtEmpresa->close();

if (!$empresaValida) {
    unset(
        $_SESSION[
            'cliente_auth_desafio'
        ]
    );

    out([
        'ok' => false,
        'code' =>
            'CLIENT_COMPANY_CONTEXT_INVALID',

        'user_msg' =>
            'Não foi possível iniciar o acesso. Tente novamente.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| AÇÃO
|--------------------------------------------------------------------------
*/

$acao = trim(
    (string)($_POST['acao'] ?? '')
);

if (
    !in_array(
        $acao,
        [
            'verificar_telefone',
            'verificar_email',
            'login_senha',
            'enviar_codigo',
            'iniciar_recuperacao',
            'enviar_codigo_email',
            'iniciar_recuperacao_email',
            'validar_codigo'
        ],
        true
    )
) {
    out([
        'ok' => false,
        'code' => 'CLIENT_LOGIN_ACTION_INVALID',
        'user_msg' => 'Operação inválida.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/


/**
 * Normaliza telefone para E.164.
 */
function clienteLoginNormalizarTelefone(
    string $telefone
): ?string {
    return smsOtpNormalizarTelefone(
        $telefone
    );
}


/**
 * Cria variantes do telefone para compatibilidade
 * com registros já existentes.
 */
function clienteLoginVariantesTelefone(
    string $telefoneE164
): array {
    $digitos = preg_replace(
        '/\D+/',
        '',
        $telefoneE164
    ) ?? '';

    if ($digitos === '') {
        return [];
    }

    $variantes = [
        $digitos
    ];

    /*
     * Remove DDI 55 para comparar com registros
     * armazenados somente como DDD + telefone.
     */
    if (
        str_starts_with(
            $digitos,
            '55'
        )
        &&
        strlen($digitos) === 13
    ) {
        $variantes[] =
            substr($digitos, 2);
    }

    return array_values(
        array_unique($variantes)
    );
}


/**
 * Localiza um cliente pelo telefone confirmado
 * exclusivamente dentro da empresa atual.
 *
 * Retorna null quando o telefone ainda não possui cadastro.
 * Falhas de consulta ou resultados inconsistentes interrompem
 * o fluxo para evitar a escolha arbitrária de um registro.
 */
function clienteLoginBuscarCliente(
    mysqli $conexao,
    int $idEmpresa,
    string $telefoneE164
): ?array {
    $variantes =
        clienteLoginVariantesTelefone(
            $telefoneE164
        );

    if (!$variantes) {
        throw new RuntimeException(
            'Telefone confirmado inválido para consulta.'
        );
    }

    $telefone1 =
        $variantes[0];

    $telefone2 =
        $variantes[1]
        ?? $variantes[0];

    /*
     * Compatibilidade temporária com telefones
     * armazenados formatados.
     */
    $sql = "
        SELECT
            id_cliente,
            id_empresa,
            nome_completo,
            whatsapp_celular,
            cadastro_completo,
            senha_hash,
            status
        FROM cliente
        WHERE id_empresa = ?
          AND (
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    whatsapp_celular,
                                    '+',
                                    ''
                                ),
                                '(',
                                ''
                            ),
                            ')',
                            ''
                        ),
                        ' ',
                        ''
                    ),
                    '-',
                    ''
                ) = ?

                OR

                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    whatsapp_celular,
                                    '+',
                                    ''
                                ),
                                '(',
                                ''
                            ),
                            ')',
                            ''
                        ),
                        ' ',
                        ''
                    ),
                    '-',
                    ''
                ) = ?
          )
        LIMIT 2
    ";

    $stmt =
        $conexao->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Não foi possível preparar a consulta do cliente.'
        );
    }

    $stmt->bind_param(
        'iss',
        $idEmpresa,
        $telefone1,
        $telefone2
    );

    if (!$stmt->execute()) {
        $stmt->close();

        throw new RuntimeException(
            'Não foi possível consultar o cliente.'
        );
    }

    $result =
        $stmt->get_result();

    if (!$result) {
        $stmt->close();

        throw new RuntimeException(
            'Não foi possível obter o resultado da consulta do cliente.'
        );
    }

    $clientes = [];

    while (
        $row = $result->fetch_assoc()
    ) {
        $clientes[] = $row;
    }

    $stmt->close();

    /*
     * Mais de um resultado compatível indica inconsistência,
     * mesmo que os valores brutos estejam formatados de modo diferente.
     */
    if (count($clientes) > 1) {
        throw new RuntimeException(
            'Foram encontrados clientes duplicados para o telefone confirmado.'
        );
    }

    if (count($clientes) === 0) {
        return null;
    }

    return $clientes[0];
}


/**
 * Resposta deliberadamente genérica.
 *
 * Não revela se o telefone está cadastrado.
 */
function clienteLoginRespostaEnvioGenerica(
    int $expiresIn = 300,
    ?string $canal = null
): void {
    $dados = [
        'expires_in' =>
            $expiresIn,
        'request_state' => 'generic',
    ];

    // Canal do código vigente, informado só quando o chamador sabe (campo aditivo).
    if ($canal === 'email' || $canal === 'sms') {
        $dados['canal'] = $canal;
    }

    out([
        'ok' => true,
        'code' =>
            'CLIENT_OTP_REQUEST_ACCEPTED',

        'data' => $dados
    ]);
}


/**
 * Desafio OTP da sessão ainda utilizável para esta empresa e telefone
 * (e, quando informada, para esta finalidade). Somente estado da sessão:
 * a decisão final continua em smsOtpValidar().
 */
function clienteLoginDesafioSessaoAtivo(
    mixed $desafio,
    int $idEmpresa,
    string $telefoneE164,
    ?string $finalidade = null,
    ?string $email = null
): bool {
    // Sem telefone (conta só com e-mail), o desafio pertence ao mesmo e-mail.
    $mesmoContato = $telefoneE164 !== ''
        || ($email !== null && $email !== ''
            && is_array($desafio) && (string)($desafio['email'] ?? '') === $email);

    return is_array($desafio)
        && (int)($desafio['id_empresa'] ?? 0) === $idEmpresa
        && (string)($desafio['telefone'] ?? '') === $telefoneE164
        && $mesmoContato
        && (int)($desafio['id_otp_desafio'] ?? 0) > 0
        && (int)($desafio['expira_em'] ?? 0) > time()
        && ($finalidade === null || (string)($desafio['finalidade'] ?? '') === $finalidade);
}


/** Guarda somente o vínculo do desafio; o código OTP nunca entra na sessão. */
function clienteLoginGuardarDesafioSessao(
    int $idEmpresa,
    string $telefoneE164,
    string $finalidade,
    ?int $idCliente,
    int $idOtpDesafio,
    int $expiresIn,
    string $estadoEnvio,
    string $canal = 'sms',
    ?string $email = null
): void {
    $_SESSION['cliente_auth_desafio'] = [
        'id_empresa' => $idEmpresa,
        'telefone' => $telefoneE164,
        'finalidade' => $finalidade,
        'canal' => $canal === 'email' ? 'email' : 'sms',
        // E-mail informado neste pedido (somente servidor): a validação exige o mesmo endereço.
        'email' => $canal === 'email' ? (string)$email : null,
        'id_cliente' => $idCliente,
        'id_otp_desafio' => $idOtpDesafio,
        'estado_envio' => $estadoEnvio,
        'enviado_em' => time(),
        'expira_em' => time() + max(1, $expiresIn),
    ];
}


/**
 * Confirma que um cliente localizado pode autenticar-se.
 */
function clienteLoginClienteAtivo(?array $cliente): bool
{
    return is_array($cliente)
        && strtolower(trim((string)($cliente['status'] ?? ''))) === 'ativo';
}


/**
 * Cria cliente_auth no mesmo formato para senha e OTP.
 * Uma autorização de recuperação nunca é criada a partir do navegador:
 * ela depende de um desafio OTP de recuperação aprovado neste handler.
 */
function clienteLoginCriarSessao(
    mysqli $conexao,
    int $idEmpresa,
    string $telefoneE164,
    ?array $cliente,
    bool $autorizarRecuperacao = false,
    string $metodoVerificacao = 'sms',
    ?string $email = null
): void {
    // C3.1: $telefoneE164 e $email são os identificadores COMPROVADOS neste login (um dos dois pode
    // ser vazio). Conta só com e-mail não tem telefone na sessão (nunca há telefone fictício).
    $emailSessao = mb_strtolower(trim((string)$email), 'UTF-8');
    $identificadorSessao = $telefoneE164 !== '' ? $telefoneE164 : 'email:' . $emailSessao;

    $clienteExistente = is_array($cliente);
    $idCliente = $clienteExistente
        ? (int)($cliente['id_cliente'] ?? 0)
        : null;

    if ($idCliente !== null && $idCliente <= 0) {
        throw new RuntimeException('Cliente inválido para autenticação.');
    }

    $cadastroCompleto = $clienteExistente
        && (int)($cliente['cadastro_completo'] ?? 0) === 1;

    // Sem sessão mista: o login do cliente encerra qualquer identidade interna (funcionário/Super Admin,
    // inclusive Modo Suporte) desta sessão, como o login interno já faz com a do cliente. Somente o
    // contexto público da empresa deste login (Agenda Online) é mantido.
    $contextoPublicoEmpresa = [
        'empresa_nome' => $_SESSION['empresa_nome'] ?? null,
        'empresa_slug' => $_SESSION['empresa_slug'] ?? null,
    ];
    loginMultiempresaLimparContexto();
    unset($_SESSION['id_empresa'], $_SESSION['modo_suporte_iniciado_em']);
    if (is_array($_SESSION[SESSAO_INATIVIDADE_CHAVE] ?? null)) {
        unset($_SESSION[SESSAO_INATIVIDADE_CHAVE]['interno']);
    }
    $_SESSION['empresa_id'] = $idEmpresa;
    foreach ($contextoPublicoEmpresa as $chave => $valor) {
        if ($valor !== null) {
            $_SESSION[$chave] = $valor;
        }
    }

    session_regenerate_id(true);

    $_SESSION['cliente_auth'] = [
        'id_cliente' => $idCliente,
        'id_empresa' => $idEmpresa,
        'telefone' => $telefoneE164,
        'email' => $emailSessao,
        'identificador' => $identificadorSessao,
        'nome_completo' => $clienteExistente
            ? (string)($cliente['nome_completo'] ?? '')
            : '',
        // Os flags significam "comprovado NESTE login" (SMS, código por e-mail ou senha do
        // identificador usado), não o estado do banco (telefone_verificado_em/email_verificado_em).
        'telefone_verificado' => $telefoneE164 !== '',
        'email_verificado' => $emailSessao !== '',
        'metodo_verificacao' => in_array($metodoVerificacao, ['sms', 'email', 'senha'], true)
            ? $metodoVerificacao
            : 'sms',
        'cadastro_completo' => $cadastroCompleto,
        'status' => 'ativo',
        'tipo' => 'cliente',
        'tipo_usuario' => 'cliente',
        'modo_visualizacao' => true,
        'login_em' => date('Y-m-d H:i:s'),
    ];

    // Marcador da credencial validada (A6.1), somente no servidor. Cliente sem
    // cadastro ou sem senha usa a credencial vazia.
    $_SESSION[SESSAO_CREDENCIAL_CLIENTE] = sessaoCredencialImpressao(
        $clienteExistente && ($cliente['senha_hash'] ?? null) !== null
            ? (string)$cliente['senha_hash']
            : null
    );

    unset($_SESSION['cliente_auth_desafio']);

    if ($autorizarRecuperacao && $idCliente !== null) {
        $_SESSION['cliente_recuperacao_senha'] = [
            'id_empresa' => $idEmpresa,
            'id_cliente' => $idCliente,
            'telefone' => $telefoneE164,
            'identificador' => $identificadorSessao,
            'autorizado_em' => time(),
            'expira_em' => time() + 600,
        ];
    } else {
        unset($_SESSION['cliente_recuperacao_senha']);
    }

    // Código por SMS aprovado para um cliente existente: o celular cadastrado passa a constar como
    // validado (só quando o número comprovado é o cadastrado). Falha não interrompe o login.
    if ($idCliente !== null && $metodoVerificacao === 'sms' && $telefoneE164 !== '') {
        try {
            clienteLoginTelefoneMarcarVerificado($conexao, $idEmpresa, $idCliente, $telefoneE164);
        } catch (Throwable $e) {
            error_log('[cliente_login] telefone_verificado_em não atualizado');
        }
    }

    if ($idCliente !== null) {
        $stmt = $conexao->prepare(
            "UPDATE cliente
             SET ultimo_login_em = CURRENT_TIMESTAMP
             WHERE id_cliente = ?
               AND id_empresa = ?"
        );

        if ($stmt) {
            $stmt->bind_param('ii', $idCliente, $idEmpresa);
            $stmt->execute();
            $stmt->close();
        }
    }

    out([
        'ok' => true,
        'code' => $autorizarRecuperacao
            ? 'CLIENT_PASSWORD_RECOVERY_AUTHORIZED'
            : 'CLIENT_AUTHENTICATED',
        'data' => [
            'cadastro_completo' => $cadastroCompleto,
            'redirect' => '/public/views/cliente-perfil.html',
        ],
    ]);
}


/*
|--------------------------------------------------------------------------
| VERIFICAR TELEFONE / DECIDIR PRÓXIMO PASSO
|--------------------------------------------------------------------------
*/

if ($acao === 'verificar_telefone') {
    $telefoneE164 = clienteLoginNormalizarTelefone(
        (string)($_POST['telefone'] ?? '')
    );

    if ($telefoneE164 === null) {
        out([
            'ok' => false,
            'code' => 'CLIENT_PHONE_INVALID',
            'user_msg' => 'Informe um telefone válido.',
        ], 422);
    }

    try {
        $cliente = clienteLoginBuscarCliente(
            $conexao,
            $idEmpresa,
            $telefoneE164
        );
    } catch (Throwable $e) {
        out([
            'ok' => false,
            'code' => 'CLIENT_LOOKUP_UNAVAILABLE',
            'user_msg' => 'Não foi possível continuar agora.',
        ], 503);
    }

    if (is_array($cliente) && !clienteLoginClienteAtivo($cliente)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_AVAILABLE',
            'user_msg' => 'Não foi possível concluir o acesso.',
        ], 403);
    }

    // Desafio de outro telefone/empresa é descartado. O do MESMO telefone é mantido: se o
    // reenvio for recusado pelo intervalo/limite, o código já enviado continua utilizável.
    $desafioSessao = $_SESSION['cliente_auth_desafio'] ?? null;
    if (!clienteLoginDesafioSessaoAtivo($desafioSessao, $idEmpresa, $telefoneE164)) {
        unset($_SESSION['cliente_auth_desafio']);
    }
    unset($_SESSION['cliente_recuperacao_senha']);

    $temSenha = is_array($cliente)
        && trim((string)($cliente['senha_hash'] ?? '')) !== '';

    out([
        'ok' => true,
        'code' => 'CLIENT_LOGIN_NEXT_STEP',
        'data' => [
            'proximo_passo' => $temSenha ? 'senha' : 'otp',
        ],
    ]);
}


/*
|--------------------------------------------------------------------------
| VERIFICAR E-MAIL / DECIDIR PRÓXIMO PASSO (C3.1)
|--------------------------------------------------------------------------
|
| Identificação por e-mail (conta só com e-mail, ou cliente que prefere o e-mail).
| Só um e-mail já validado do cliente pode seguir para a senha; qualquer outro
| caso segue o fluxo de código (que responde de forma genérica, sem revelar cadastro).
|
*/

if ($acao === 'verificar_email') {
    $emailVerificar = clienteLoginEmailNormalizar(
        (string)($_POST['email'] ?? '')
    );

    if ($emailVerificar === null) {
        out([
            'ok' => false,
            'code' => 'CLIENT_EMAIL_INVALID',
            'user_msg' => 'Informe um e-mail válido.',
        ], 422);
    }

    try {
        $cliente = clienteLoginBuscarClientePorEmail(
            $conexao,
            $idEmpresa,
            $emailVerificar
        );
    } catch (Throwable $e) {
        out([
            'ok' => false,
            'code' => 'CLIENT_LOOKUP_UNAVAILABLE',
            'user_msg' => 'Não foi possível continuar agora.',
        ], 503);
    }

    if (is_array($cliente) && !clienteLoginClienteAtivo($cliente)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_AVAILABLE',
            'user_msg' => 'Não foi possível concluir o acesso.',
        ], 403);
    }

    // Desafio de outro e-mail/empresa é descartado; o do mesmo e-mail é mantido.
    $desafioSessao = $_SESSION['cliente_auth_desafio'] ?? null;
    if (!clienteLoginDesafioSessaoAtivo($desafioSessao, $idEmpresa, '', null, $emailVerificar)) {
        unset($_SESSION['cliente_auth_desafio']);
    }
    unset($_SESSION['cliente_recuperacao_senha']);

    $senhaPorEmail = is_array($cliente)
        && trim((string)($cliente['senha_hash'] ?? '')) !== ''
        && ($cliente['email_verificado_em'] ?? null) !== null;

    out([
        'ok' => true,
        'code' => 'CLIENT_LOGIN_NEXT_STEP',
        'data' => [
            'proximo_passo' => $senhaPorEmail ? 'senha' : 'otp',
        ],
    ]);
}


/*
|--------------------------------------------------------------------------
| LOGIN POR SENHA
|--------------------------------------------------------------------------
*/

if ($acao === 'login_senha') {
    $telefoneE164 = clienteLoginNormalizarTelefone(
        (string)($_POST['telefone'] ?? '')
    );
    // C3.1: o identificador pode ser o celular OU o e-mail validado da conta.
    $emailSenha = $telefoneE164 === null
        ? clienteLoginEmailNormalizar((string)($_POST['email'] ?? ''))
        : null;
    $senha = (string)($_POST['senha'] ?? '');
    $mensagemCredenciais = $emailSenha !== null
        ? 'E-mail ou senha inválidos.'
        : 'Telefone ou senha inválidos.';

    if (($telefoneE164 === null && $emailSenha === null) || $senha === '') {
        out([
            'ok' => false,
            'code' => 'CLIENT_CREDENTIALS_INVALID',
            'user_msg' => $mensagemCredenciais,
        ], 401);
    }

    // A4: limite persistente de tentativas (conta e conta + IP), verificado
    // antes da busca do cliente e de password_verify. Afeta somente o login
    // por senha; OTP e recuperação por código não passam por aqui.
    // A chave usa o identificador do login (telefone E.164 ou "email:<endereço>").
    require_once __DIR__ . '/../_regras/limite_tentativas.php';
    $identificadorLimite = $telefoneE164 ?? ('email:' . $emailSenha);
    $limiteSenhaCliente = limiteTentativasClienteIniciar(
        $conexao,
        $idEmpresa,
        $identificadorLimite
    );
    // Garante a liberação do lock em qualquer saída (out() encerra com exit).
    register_shutdown_function(
        static function () use ($conexao, &$limiteSenhaCliente): void {
            limiteTentativasLiberarLock($conexao, $limiteSenhaCliente);
        }
    );

    try {
        $cliente = $telefoneE164 !== null
            ? clienteLoginBuscarCliente($conexao, $idEmpresa, $telefoneE164)
            : clienteLoginBuscarClientePorEmail($conexao, $idEmpresa, (string)$emailSenha);
    } catch (Throwable $e) {
        out([
            'ok' => false,
            'code' => 'CLIENT_LOGIN_UNAVAILABLE',
            'user_msg' => 'Não foi possível concluir o acesso agora.',
        ], 503);
    }

    $hash = is_array($cliente)
        ? trim((string)($cliente['senha_hash'] ?? ''))
        : '';

    // E-mail só é credencial depois de validado (posse comprovada); antes disso a resposta é a mesma
    // de credenciais inválidas.
    $emailNaoValidado = $emailSenha !== null
        && (!is_array($cliente) || ($cliente['email_verificado_em'] ?? null) === null);

    if (
        !clienteLoginClienteAtivo($cliente)
        || $emailNaoValidado
        || $hash === ''
        || !password_verify($senha, $hash)
    ) {
        // Conta a falha; ao atingir o limite responde 429 (bloqueio temporário).
        limiteTentativasClienteRegistrarFalha($conexao, $limiteSenhaCliente);

        out([
            'ok' => false,
            'code' => 'CLIENT_CREDENTIALS_INVALID',
            'user_msg' => $mensagemCredenciais,
        ], 401);
    }

    // Senha correta: zera os contadores da conta antes de criar a sessão.
    limiteTentativasClienteRegistrarSucesso($conexao, $limiteSenhaCliente);

    clienteLoginCriarSessao(
        $conexao,
        $idEmpresa,
        $telefoneE164 ?? '',
        $cliente,
        false,
        'senha',
        $emailSenha
    );
}


/*
|--------------------------------------------------------------------------
| ENVIAR CÓDIGO
|--------------------------------------------------------------------------
*/

if (in_array($acao, ['enviar_codigo', 'iniciar_recuperacao', 'enviar_codigo_email', 'iniciar_recuperacao_email'], true)) {

    // Canal e-mail (C3): mesmas regras do SMS; a ação base define acesso x recuperação.
    $ehEmail = str_ends_with($acao, '_email');
    $acaoBase = $ehEmail ? substr($acao, 0, -strlen('_email')) : $acao;
    $canalOtp = $ehEmail ? 'email' : 'sms';

    $telefoneInformado =
        (string)(
            $_POST['telefone'] ?? ''
        );

    $telefoneE164 =
        clienteLoginNormalizarTelefone(
            $telefoneInformado
        );

    // C3.1: no canal e-mail o telefone é opcional (conta só com e-mail); quando informado, precisa ser
    // válido. No canal SMS continua obrigatório.
    if ($telefoneE164 === null && (!$ehEmail || trim($telefoneInformado) !== '')) {
        out([
            'ok' => false,
            'code' =>
                'CLIENT_PHONE_INVALID',

            'user_msg' =>
                'Informe um telefone válido.'
        ], 422);
    }
    $telefoneE164 = (string)$telefoneE164;

    $emailInformado = null;

    if ($ehEmail) {
        $emailInformado = clienteLoginEmailNormalizar(
            (string)($_POST['email'] ?? '')
        );

        if ($emailInformado === null) {
            out([
                'ok' => false,
                'code' => 'CLIENT_EMAIL_INVALID',
                'user_msg' => 'Informe um e-mail válido.',
            ], 422);
        }
    }

    try {
        // Identidade: o telefone informado; sem telefone (conta só com e-mail), o próprio e-mail.
        $clienteOtp = $telefoneE164 !== ''
            ? clienteLoginBuscarCliente($conexao, $idEmpresa, $telefoneE164)
            : clienteLoginBuscarClientePorEmail($conexao, $idEmpresa, (string)$emailInformado);
    } catch (Throwable $e) {
        out([
            'ok' => false,
            'code' => 'CLIENT_LOOKUP_UNAVAILABLE',
            'user_msg' => 'Não foi possível continuar agora.',
        ], 503);
    }

    // Recuperação de senha nunca revela se a conta existe: o caso indisponível segue adiante
    // como desafio fictício (sem SMS) e com a mesma resposta pública.
    if ($acaoBase !== 'iniciar_recuperacao' && is_array($clienteOtp) && !clienteLoginClienteAtivo($clienteOtp)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_AVAILABLE',
            'user_msg' => 'Não foi possível concluir o acesso.',
        ], 403);
    }

    $hashClienteOtp = is_array($clienteOtp)
        ? trim((string)($clienteOtp['senha_hash'] ?? ''))
        : '';

    $finalidadeOtp = $acaoBase === 'iniciar_recuperacao'
        ? 'recuperacao_senha'
        : 'acesso';

    // Recuperação e todo pedido por e-mail respondem de forma genérica: a elegibilidade do
    // e-mail (cadastrado e validado) nunca é revelada pela resposta.
    $respostaGenerica = $finalidadeOtp === 'recuperacao_senha' || $ehEmail;

    $recuperacaoFicticia = false;

    if ($finalidadeOtp === 'recuperacao_senha') {
        // Conta inexistente, inativa ou sem senha: nenhum SMS e resposta genérica.
        $recuperacaoFicticia = !clienteLoginClienteAtivo($clienteOtp) || $hashClienteOtp === '';
    } elseif ($hashClienteOtp !== '') {
        out([
            'ok' => false,
            'code' => 'CLIENT_PASSWORD_REQUIRED',
            'user_msg' => 'Informe sua senha para continuar.',
        ], 409);
    }


    /*
    |--------------------------------------------------------------------------
    | DESAFIO PERSISTENTE E ENVIO
    |--------------------------------------------------------------------------
    |
    | Reenvio (30 s), limites por telefone/IP, invalidação do desafio anterior
    | e vínculo à sessão são aplicados em smsOtpSolicitar() (otp_desafio).
    | Telefone sem cadastro usa principal 'ficticio' com envio real, pois o
    | primeiro acesso precisa confirmar o telefone antes do cadastro.
    |
    */
    $clienteOtpAtivo = is_array($clienteOtp)
        && clienteLoginClienteAtivo($clienteOtp)
        && !$recuperacaoFicticia;

    $resultadoOtp = smsOtpSolicitar($conexao, clienteLoginParametrosSolicitacao([
        'canal' => $canalOtp,
        'email' => $emailInformado,
        'id_empresa' => $idEmpresa,
        'telefone' => $telefoneE164,
        'cliente_ativo' => $clienteOtpAtivo,
        'id_cliente' => $clienteOtpAtivo ? (int)$clienteOtp['id_cliente'] : 0,
        'finalidade' => $finalidadeOtp,
        'ficticio_sem_envio' => $recuperacaoFicticia,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ]));

    $codigoOtp = (string)($resultadoOtp['codigo'] ?? '');
    $expiresIn = max(1, (int)($resultadoOtp['dados']['expires_in'] ?? 300));

    if (($resultadoOtp['sucesso'] ?? false) !== true) {

        // Canal e-mail desligado (configuração) ou e-mail malformado: independe do cadastro,
        // então pode ser informado sem revelar nada sobre a conta.
        if ($codigoOtp === 'EMAIL_INDISPONIVEL') {
            out([
                'ok' => false,
                'code' => 'CLIENT_EMAIL_UNAVAILABLE',
                'user_msg' => 'O envio por e-mail está indisponível no momento. Use o código por SMS.',
            ], 503);
        }

        if (in_array($codigoOtp, ['EMAIL_INVALIDO', 'OTP_CANAL_INVALIDO'], true)) {
            out([
                'ok' => false,
                'code' => 'CLIENT_EMAIL_INVALID',
                'user_msg' => 'Informe um e-mail válido.',
            ], 422);
        }

        $retryAfterOtp = max(
            0,
            (int)($resultadoOtp['dados']['retry_after'] ?? 0)
        );

        // Recusa não cria desafio: o código anterior desta sessão (mesmo telefone,
        // empresa e finalidade), se ainda válido, continua sendo o código a digitar.
        $desafioPendente = $_SESSION['cliente_auth_desafio'] ?? null;
        $dadosRecusa = [
            'retry_after' => $retryAfterOtp,
        ];
        if (clienteLoginDesafioSessaoAtivo($desafioPendente, $idEmpresa, $telefoneE164, $finalidadeOtp, $emailInformado)) {
            $dadosRecusa['codigo_pendente'] = true;
            $dadosRecusa['expires_in'] = max(1, (int)$desafioPendente['expira_em'] - time());
            // Canal do código pendente (pode ser diferente do canal pedido agora).
            $dadosRecusa['canal'] = (string)($desafioPendente['canal'] ?? 'sms') === 'email' ? 'email' : 'sms';
        }

        // Intervalo mínimo entre solicitações (30 s) ou pedidos simultâneos.
        if (in_array($codigoOtp, ['SMS_OTP_REENVIO_AGUARDAR', 'SMS_OTP_CONCORRENCIA'], true)) {
            $dadosRecusa['retry_after'] = max(1, $retryAfterOtp ?: 30);

            out([
                'ok' => false,
                'code' => 'CLIENT_OTP_RESEND_LIMIT',
                'user_msg' =>
                    'Aguarde alguns segundos antes de solicitar outro código.',
                'data' => $dadosRecusa,
            ], 429);
        }

        // Limite por hora (telefone, IP ou primeiros acessos da empresa): prazo real
        // calculado no banco; o motivo interno não é exposto.
        if ($codigoOtp === 'SMS_OTP_LIMITE') {
            $dadosRecusa['retry_after'] = max(1, $retryAfterOtp ?: 600);

            out([
                'ok' => false,
                'code' => 'CLIENT_OTP_HOURLY_LIMIT',
                'user_msg' =>
                    'Você atingiu o limite de solicitações de código. Tente novamente mais tarde.',
                'data' => $dadosRecusa,
            ], 429);
        }

        // O gateway pode ter aceitado a mensagem. O novo desafio é o único
        // utilizável, mas o resultado não é apresentado como sucesso operacional.
        if ($codigoOtp === 'SMS_OTP_ENVIO_INCERTO') {
            clienteLoginGuardarDesafioSessao(
                $idEmpresa,
                $telefoneE164,
                $finalidadeOtp,
                $clienteOtpAtivo ? (int)$clienteOtp['id_cliente'] : null,
                (int)($resultadoOtp['dados']['id_otp_desafio'] ?? 0),
                $expiresIn,
                'uncertain',
                $canalOtp,
                $emailInformado
            );

            // A recuperação e o canal e-mail mantêm o mesmo contrato para conta real e fictícia.
            if ($respostaGenerica) {
                clienteLoginRespostaEnvioGenerica($expiresIn, $ehEmail ? 'email' : null);
            }

            out([
                'ok' => false,
                'code' => 'CLIENT_OTP_SEND_UNCERTAIN',
                'user_msg' => 'Não foi possível confirmar o envio do código. Aguarde antes de tentar novamente.',
                'data' => [
                    'codigo_pendente' => true,
                    'expires_in' => $expiresIn,
                    'retry_after' => max(1, $retryAfterOtp ?: 30),
                ],
            ], 503);
        }

        if (
            $codigoOtp === 'SMS_OTP_ENVIO_FALHOU'
            && ($resultadoOtp['dados']['desafio_anterior_preservado'] ?? false) !== true
        ) {
            unset($_SESSION['cliente_auth_desafio']);
            unset($dadosRecusa['codigo_pendente'], $dadosRecusa['expires_in']);
        }

        // Falha definitiva: smsOtpSolicitar() já restaurou o desafio anterior
        // válido, quando havia um. A sessão antiga é mantida e informada ao cliente.
        if (!$respostaGenerica) {
            out([
                'ok' => false,
                'code' => 'CLIENT_OTP_SEND_FAILED',
                'user_msg' => 'Não foi possível enviar o código agora. Tente novamente.',
                'data' => $dadosRecusa,
            ], 503);
        }

        // A recuperação e o canal e-mail não revelam se havia conta (ou e-mail validado) nem
        // se uma mensagem real foi tentada.
        if (isset($dadosRecusa['expires_in'])) {
            clienteLoginRespostaEnvioGenerica((int)$dadosRecusa['expires_in'], $dadosRecusa['canal'] ?? null);
        }
        unset($_SESSION['cliente_auth_desafio']);
        clienteLoginRespostaEnvioGenerica(300, $ehEmail ? 'email' : null);
    }

    clienteLoginGuardarDesafioSessao(
        $idEmpresa,
        $telefoneE164,
        $finalidadeOtp,
        $clienteOtpAtivo ? (int)$clienteOtp['id_cliente'] : null,
        (int)($resultadoOtp['dados']['id_otp_desafio'] ?? 0),
        $expiresIn,
        $respostaGenerica ? 'generic' : 'accepted',
        $canalOtp,
        $emailInformado
    );

    if ($respostaGenerica) {
        clienteLoginRespostaEnvioGenerica($expiresIn, $ehEmail ? 'email' : null);
    }

    out([
        'ok' => true,
        'code' => 'CLIENT_OTP_SEND_ACCEPTED',
        'data' => [
            'expires_in' => $expiresIn,
            'request_state' => 'accepted',
        ],
    ]);
}


/*
|--------------------------------------------------------------------------
| VALIDAR CÓDIGO
|--------------------------------------------------------------------------
*/

$desafio =
    $_SESSION[
        'cliente_auth_desafio'
    ] ?? null;

if (!is_array($desafio)) {
    out([
        'ok' => false,
        'code' =>
            'CLIENT_OTP_CHALLENGE_NOT_FOUND',

        'user_msg' =>
            'Solicite um novo código para continuar.'
    ], 422);
}

$desafioEmpresa = (int)($desafio['id_empresa'] ?? 0);
$desafioTelefone = (string)($desafio['telefone'] ?? '');
$desafioFinalidade = (string)($desafio['finalidade'] ?? 'acesso');
$desafioIdCliente = isset($desafio['id_cliente'])
    ? (int)$desafio['id_cliente']
    : null;
$desafioIdOtp = (int)($desafio['id_otp_desafio'] ?? 0);
// Canal do desafio (C3). Sessões anteriores à C3 não têm o campo e são SMS.
$desafioCanal = (string)($desafio['canal'] ?? 'sms') === 'email' ? 'email' : 'sms';
$desafioEmail = $desafioCanal === 'email'
    ? (clienteLoginEmailNormalizar((string)($desafio['email'] ?? '')) ?? '')
    : '';

if (
    $desafioEmpresa !== $idEmpresa
    ||
    // C3.1: conta só com e-mail não tem telefone; o desafio precisa de ao menos um dos dois.
    ($desafioTelefone === '' && $desafioEmail === '')
    ||
    $desafioIdOtp <= 0
    ||
    ($desafioCanal === 'email' && $desafioEmail === '')
    ||
    !in_array(
        $desafioFinalidade,
        ['acesso', 'recuperacao_senha'],
        true
    )
) {
    unset($_SESSION['cliente_auth_desafio']);

    out([
        'ok' => false,
        'code' =>
            'CLIENT_OTP_CHALLENGE_INVALID',

        'user_msg' =>
            'Solicite um novo código para continuar.'
    ], 422);
}

$codigo =
    preg_replace(
        '/\D+/',
        '',
        (string)(
            $_POST['codigo'] ?? ''
        )
    ) ?? '';

if (strlen($codigo) !== 6) {
    out([
        'ok' => false,
        'code' =>
            'CLIENT_OTP_CODE_INVALID',

        'user_msg' =>
            'Código inválido.'
    ], 422);
}

/*
 * Expiração, tentativas (persistentes), vínculo com a sessão, empresa real
 * do cliente e consumo único são decididos em smsOtpValidar().
 */
$parametrosValidacao = [
    'principal_tipo' => ($desafioIdCliente ?? 0) > 0 ? 'cliente' : 'ficticio',
    'finalidade' => $desafioFinalidade === 'recuperacao_senha'
        ? 'recuperacao_cliente'
        : 'acesso_cliente',
    'id_empresa' => $idEmpresa,
    'id_cliente' => $desafioIdCliente ?? 0,
    'id_usuario' => 0,
    'telefone' => $desafioTelefone,
    'id_otp_desafio' => $desafioIdOtp,
    'codigo' => $codigo,
];
if ($desafioCanal === 'email') {
    // Desafio por e-mail só valida com o mesmo endereço que o originou.
    $parametrosValidacao['canal'] = 'email';
    $parametrosValidacao['email'] = $desafioEmail;
}

$validacaoOtp = smsOtpValidar($conexao, $parametrosValidacao);

if (($validacaoOtp['sucesso'] ?? false) !== true) {
    $codigoValidacao = (string)($validacaoOtp['codigo'] ?? '');

    if (in_array($codigoValidacao, ['SMS_OTP_INDISPONIVEL', 'SMS_CONFIG_INVALIDA'], true)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_OTP_UNAVAILABLE',
            'user_msg' => 'Não foi possível validar o código agora.'
        ], 503);
    }

    if ($codigoValidacao === 'SMS_OTP_EXPIRADO') {
        unset($_SESSION['cliente_auth_desafio']);

        out([
            'ok' => false,
            'code' => 'CLIENT_OTP_EXPIRED',
            'user_msg' => 'O código expirou. Solicite um novo.'
        ], 422);
    }

    if ($codigoValidacao === 'SMS_OTP_TENTATIVAS') {
        unset($_SESSION['cliente_auth_desafio']);

        out([
            'ok' => false,
            'code' => 'CLIENT_OTP_ATTEMPTS_EXCEEDED',
            'user_msg' => 'Solicite um novo código para continuar.'
        ], 429);
    }

    out([
        'ok' => false,
        'code' =>
            'CLIENT_OTP_NOT_APPROVED',

        'user_msg' =>
            'Código inválido ou expirado.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| LOCALIZAR CLIENTE APÓS O OTP
|--------------------------------------------------------------------------
|
| O telefone já foi confirmado. Agora o cadastro é recuperado dentro
| da empresa atual. A ausência de cadastro não impede a autenticação.
|
*/

try {
    // Mesma identidade do pedido: o telefone, ou o e-mail quando não há telefone.
    $clienteAtual = $desafioTelefone !== ''
        ? clienteLoginBuscarCliente($conexao, $idEmpresa, $desafioTelefone)
        : clienteLoginBuscarClientePorEmail($conexao, $idEmpresa, $desafioEmail);
} catch (Throwable $e) {
    unset(
        $_SESSION[
            'cliente_auth_desafio'
        ]
    );

    out([
        'ok' => false,
        'code' =>
            'CLIENT_LOOKUP_UNAVAILABLE',

        'user_msg' =>
            'Não foi possível concluir o acesso agora.'
    ], 503);
}

$clienteExistente =
    is_array($clienteAtual);

if ($clienteExistente && !clienteLoginClienteAtivo($clienteAtual)) {
    unset(
        $_SESSION[
            'cliente_auth_desafio'
        ]
    );

    out([
        'ok' => false,
        'code' =>
            'CLIENT_NOT_AVAILABLE',

        'user_msg' =>
            'Não foi possível concluir o acesso.'
    ], 403);
}

/*
 * Código aprovado por e-mail: o endereço comprova controle do e-mail, não a identidade de
 * um cadastro existente por coincidência. Cliente existente só autentica se for o principal do
 * desafio e o e-mail continuar cadastrado e validado agora (alterar o e-mail invalida a
 * verificação). Primeiro acesso só autentica com e-mail ainda livre na empresa, e a conta
 * nasce só com e-mail (sem telefone fictício) no cadastro do perfil.
 */
if (
    $desafioCanal === 'email'
    && !clienteLoginEmailPodeAutenticar(
        $conexao,
        $idEmpresa,
        $clienteExistente ? $clienteAtual : null,
        $desafioIdCliente,
        $desafioEmail
    )
) {
    unset($_SESSION['cliente_auth_desafio']);

    out([
        'ok' => false,
        'code' => 'CLIENT_OTP_NOT_APPROVED',
        'user_msg' => 'Código inválido ou expirado.',
    ], 422);
}

$autorizarRecuperacao =
    $desafioFinalidade === 'recuperacao_senha';

if ($autorizarRecuperacao) {
    $idClienteAtual = $clienteExistente
        ? (int)($clienteAtual['id_cliente'] ?? 0)
        : 0;
    $hashClienteAtual = $clienteExistente
        ? trim((string)($clienteAtual['senha_hash'] ?? ''))
        : '';

    if (
        !$clienteExistente
        || $idClienteAtual <= 0
        || $desafioIdCliente !== $idClienteAtual
        || $hashClienteAtual === ''
    ) {
        unset($_SESSION['cliente_auth_desafio']);

        out([
            'ok' => false,
            'code' => 'CLIENT_PASSWORD_RECOVERY_INVALID',
            'user_msg' => 'Solicite um novo código para continuar.',
        ], 422);
    }
} elseif (
    $clienteExistente
    && trim((string)($clienteAtual['senha_hash'] ?? '')) !== ''
) {
    unset($_SESSION['cliente_auth_desafio']);

    out([
        'ok' => false,
        'code' => 'CLIENT_PASSWORD_REQUIRED',
        'user_msg' => 'Informe sua senha para continuar.',
    ], 409);
}

// A sessão guarda apenas o contato COMPROVADO por este código: o celular (SMS) ou o e-mail. Conta
// só com e-mail fica sem telefone na sessão (nunca há telefone fictício).
clienteLoginCriarSessao(
    $conexao,
    $idEmpresa,
    $desafioCanal === 'email' ? '' : $desafioTelefone,
    $clienteAtual,
    $autorizarRecuperacao,
    $desafioCanal,
    $desafioCanal === 'email' ? $desafioEmail : null
);
