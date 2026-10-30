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
            'login_senha',
            'enviar_codigo',
            'iniciar_recuperacao',
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
    int $expiresIn = 300
): void {
    out([
        'ok' => true,
        'code' =>
            'CLIENT_OTP_REQUEST_ACCEPTED',

        'data' => [
            'expires_in' =>
                $expiresIn
        ]
    ]);
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
    bool $autorizarRecuperacao = false
): void {
    $clienteExistente = is_array($cliente);
    $idCliente = $clienteExistente
        ? (int)($cliente['id_cliente'] ?? 0)
        : null;

    if ($idCliente !== null && $idCliente <= 0) {
        throw new RuntimeException('Cliente inválido para autenticação.');
    }

    $cadastroCompleto = $clienteExistente
        && (int)($cliente['cadastro_completo'] ?? 0) === 1;

    session_regenerate_id(true);

    $_SESSION['cliente_auth'] = [
        'id_cliente' => $idCliente,
        'id_empresa' => $idEmpresa,
        'telefone' => $telefoneE164,
        'nome_completo' => $clienteExistente
            ? (string)($cliente['nome_completo'] ?? '')
            : '',
        'telefone_verificado' => true,
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
            'autorizado_em' => time(),
            'expira_em' => time() + 600,
        ];
    } else {
        unset($_SESSION['cliente_recuperacao_senha']);
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

    unset(
        $_SESSION['cliente_auth_desafio'],
        $_SESSION['cliente_recuperacao_senha']
    );

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
| LOGIN POR SENHA
|--------------------------------------------------------------------------
*/

if ($acao === 'login_senha') {
    $telefoneE164 = clienteLoginNormalizarTelefone(
        (string)($_POST['telefone'] ?? '')
    );
    $senha = (string)($_POST['senha'] ?? '');

    if ($telefoneE164 === null || $senha === '') {
        out([
            'ok' => false,
            'code' => 'CLIENT_CREDENTIALS_INVALID',
            'user_msg' => 'Telefone ou senha inválidos.',
        ], 401);
    }

    // A4: limite persistente de tentativas (conta e conta + IP), verificado
    // antes da busca do cliente e de password_verify. Afeta somente o login
    // por senha; OTP e recuperação por código não passam por aqui.
    require_once __DIR__ . '/../_regras/limite_tentativas.php';
    $limiteSenhaCliente = limiteTentativasClienteIniciar(
        $conexao,
        $idEmpresa,
        $telefoneE164
    );
    // Garante a liberação do lock em qualquer saída (out() encerra com exit).
    register_shutdown_function(
        static function () use ($conexao, &$limiteSenhaCliente): void {
            limiteTentativasLiberarLock($conexao, $limiteSenhaCliente);
        }
    );

    try {
        $cliente = clienteLoginBuscarCliente(
            $conexao,
            $idEmpresa,
            $telefoneE164
        );
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

    if (
        !clienteLoginClienteAtivo($cliente)
        || $hash === ''
        || !password_verify($senha, $hash)
    ) {
        // Conta a falha; ao atingir o limite responde 429 (bloqueio temporário).
        limiteTentativasClienteRegistrarFalha($conexao, $limiteSenhaCliente);

        out([
            'ok' => false,
            'code' => 'CLIENT_CREDENTIALS_INVALID',
            'user_msg' => 'Telefone ou senha inválidos.',
        ], 401);
    }

    // Senha correta: zera os contadores da conta antes de criar a sessão.
    limiteTentativasClienteRegistrarSucesso($conexao, $limiteSenhaCliente);

    clienteLoginCriarSessao(
        $conexao,
        $idEmpresa,
        $telefoneE164,
        $cliente
    );
}


/*
|--------------------------------------------------------------------------
| ENVIAR CÓDIGO
|--------------------------------------------------------------------------
*/

if (in_array($acao, ['enviar_codigo', 'iniciar_recuperacao'], true)) {

    $telefoneInformado =
        (string)(
            $_POST['telefone'] ?? ''
        );

    $telefoneE164 =
        clienteLoginNormalizarTelefone(
            $telefoneInformado
        );

    if ($telefoneE164 === null) {
        out([
            'ok' => false,
            'code' =>
                'CLIENT_PHONE_INVALID',

            'user_msg' =>
                'Informe um telefone válido.'
        ], 422);
    }

    try {
        $clienteOtp = clienteLoginBuscarCliente(
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

    // Recuperação de senha nunca revela se a conta existe: o caso indisponível segue adiante
    // como desafio fictício (sem SMS) e com a mesma resposta pública.
    if ($acao !== 'iniciar_recuperacao' && is_array($clienteOtp) && !clienteLoginClienteAtivo($clienteOtp)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_AVAILABLE',
            'user_msg' => 'Não foi possível concluir o acesso.',
        ], 403);
    }

    $hashClienteOtp = is_array($clienteOtp)
        ? trim((string)($clienteOtp['senha_hash'] ?? ''))
        : '';

    $finalidadeOtp = $acao === 'iniciar_recuperacao'
        ? 'recuperacao_senha'
        : 'acesso';

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

    $resultadoOtp = smsOtpSolicitar($conexao, [
        'principal_tipo' => $clienteOtpAtivo ? 'cliente' : 'ficticio',
        'finalidade' => $finalidadeOtp === 'recuperacao_senha'
            ? 'recuperacao_cliente'
            : 'acesso_cliente',
        'id_empresa' => $idEmpresa,
        'id_cliente' => $clienteOtpAtivo ? (int)$clienteOtp['id_cliente'] : 0,
        'id_usuario' => 0,
        'telefone' => $telefoneE164,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'enviar' => !$recuperacaoFicticia,
    ]);

    if (($resultadoOtp['sucesso'] ?? false) !== true) {
        $codigoOtp = (string)($resultadoOtp['codigo'] ?? '');

        if (in_array($codigoOtp, ['SMS_OTP_REENVIO_AGUARDAR', 'SMS_OTP_CONCORRENCIA', 'SMS_OTP_LIMITE'], true)) {
            out([
                'ok' => false,
                'code' => 'CLIENT_OTP_RESEND_LIMIT',
                'user_msg' =>
                    'Aguarde alguns segundos antes de solicitar outro código.',
                'data' => [
                    'retry_after' =>
                        (int)($resultadoOtp['dados']['retry_after'] ?? 30)
                ]
            ], 429);
        }

        // Na recuperação, falha de envio não distingue conta real de inexistente.
        if ($finalidadeOtp !== 'recuperacao_senha') {
            out([
                'ok' => false,
                'code' => 'CLIENT_OTP_UNAVAILABLE',
                'user_msg' =>
                    'Não foi possível enviar o código agora. Tente novamente.'
            ], 503);
        }

        $clienteOtpAtivo = false;
    }

    $expiresIn = (int)($resultadoOtp['dados']['expires_in'] ?? 300);

    // Somente identificadores: o código nunca é guardado na sessão.
    $_SESSION[
        'cliente_auth_desafio'
    ] = [
        'id_empresa' => $idEmpresa,
        'telefone' => $telefoneE164,
        'finalidade' => $finalidadeOtp,
        'id_cliente' => $clienteOtpAtivo
            ? (int)$clienteOtp['id_cliente']
            : null,
        'id_otp_desafio' =>
            (int)($resultadoOtp['dados']['id_otp_desafio'] ?? 0),
        'enviado_em' => time(),
        'expira_em' => time() + $expiresIn,
    ];

    clienteLoginRespostaEnvioGenerica(
        $expiresIn
    );
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

if (
    $desafioEmpresa !== $idEmpresa
    ||
    $desafioTelefone === ''
    ||
    $desafioIdOtp <= 0
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
$validacaoOtp = smsOtpValidar($conexao, [
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
]);

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
    $clienteAtual =
        clienteLoginBuscarCliente(
            $conexao,
            $idEmpresa,
            $desafioTelefone
        );
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

clienteLoginCriarSessao(
    $conexao,
    $idEmpresa,
    $desafioTelefone,
    $clienteAtual,
    $autorizarRecuperacao
);
