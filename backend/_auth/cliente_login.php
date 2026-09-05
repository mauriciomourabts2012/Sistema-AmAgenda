<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LOGIN DO CLIENTE - TELEFONE + OTP
|--------------------------------------------------------------------------
|
| Responsabilidades:
| - receber enviar_codigo / validar_codigo;
| - obter id_empresa exclusivamente da sessão;
| - localizar cliente por id_empresa + whatsapp_celular;
| - coordenar cliente_auth_desafio;
| - criar cliente_auth após validação.
|
| Modos suportados:
|
| programmable_sms
|   DEV:
|   - AmAgenda gera e valida OTP;
|   - pode aceitar OTP fixo de homologação quando habilitado
|     explicitamente em backend/_config/twilio.php.
|
| verify
|   PRODUÇÃO/FUTURO: provedor gera e valida OTP.
|
| Este arquivo NÃO armazena credenciais da Twilio.
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
            'enviar_codigo',
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
 * Retorna modo configurado para OTP.
 */
function clienteLoginModoOtp(): string
{
    $config = smsOtpConfig();

    return strtolower(
        trim(
            (string)(
                $config['mode']
                ?? 'programmable_sms'
            )
        )
    );
}


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


/*
|--------------------------------------------------------------------------
| ENVIAR CÓDIGO
|--------------------------------------------------------------------------
*/

if ($acao === 'enviar_codigo') {

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


    /*
    |--------------------------------------------------------------------------
    | CONTROLE DE REENVIO
    |--------------------------------------------------------------------------
    */

    $desafioAnterior =
        $_SESSION[
            'cliente_auth_desafio'
        ] ?? null;

    if (
        is_array($desafioAnterior)
        &&
        (int)(
            $desafioAnterior[
                'id_empresa'
            ] ?? 0
        ) === $idEmpresa
    ) {
        $ultimoEnvio =
            (int)(
                $desafioAnterior[
                    'enviado_em'
                ] ?? 0
            );

        $tempoDesdeEnvio =
            time() - $ultimoEnvio;

        /*
         * 30 segundos entre envios.
         */
        if (
            $ultimoEnvio > 0
            &&
            $tempoDesdeEnvio < 30
        ) {
            $retryAfter =
                30 - $tempoDesdeEnvio;

            out([
                'ok' => false,
                'code' =>
                    'CLIENT_OTP_RESEND_LIMIT',

                'user_msg' =>
                    'Aguarde alguns segundos antes de solicitar outro código.',

                'data' => [
                    'retry_after' =>
                        $retryAfter
                ]
            ], 429);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | MODO OTP
    |--------------------------------------------------------------------------
    */

    $modoOtp =
        clienteLoginModoOtp();

    $expiresIn = 300;

    $referencia = '';

    $otpHash = null;


    /*
    |--------------------------------------------------------------------------
    | DEV - PROGRAMMABLE SMS
    |--------------------------------------------------------------------------
    |
    | Neste modo o AmAgenda continua gerando seu OTP aleatório.
    |
    | O código em texto puro NÃO é salvo na sessão.
    |
    | Enquanto a Twilio Trial não entregar conteúdo personalizado,
    | sms_otp.php utiliza o template permitido pelo Trial.
    |
    */

    if ($modoOtp === 'programmable_sms') {

        try {
            $codigoOtp =
                (string)random_int(
                    100000,
                    999999
                );
        } catch (Throwable $e) {
            out([
                'ok' => false,
                'code' =>
                    'CLIENT_OTP_GENERATION_ERROR',

                'user_msg' =>
                    'Não foi possível enviar o código agora. Tente novamente.'
            ], 500);
        }

        $otpHash =
            password_hash(
                $codigoOtp,
                PASSWORD_DEFAULT
            );

        if (
            !is_string($otpHash)
            ||
            $otpHash === ''
        ) {
            out([
                'ok' => false,
                'code' =>
                    'CLIENT_OTP_GENERATION_ERROR',

                'user_msg' =>
                    'Não foi possível enviar o código agora. Tente novamente.'
            ], 500);
        }

        /*
         * A mensagem personalizada continua sendo montada
         * para compatibilidade com o fluxo futuro.
         *
         * No Trial atual, sms_otp.php ignora este conteúdo e
         * utiliza dev_message_body da configuração privada.
         */
        $mensagem =
            'AmAgenda: seu código de acesso é ' .
            $codigoOtp .
            '. Ele expira em 5 minutos.';

        $resultadoOtp =
            smsOtpEnviar(
                $telefoneE164,
                $mensagem
            );
    }


    /*
    |--------------------------------------------------------------------------
    | TWILIO VERIFY
    |--------------------------------------------------------------------------
    */

    elseif ($modoOtp === 'verify') {

        $resultadoOtp =
            smsOtpEnviar(
                $telefoneE164
            );
    }


    /*
    |--------------------------------------------------------------------------
    | MODO INVÁLIDO
    |--------------------------------------------------------------------------
    */

    else {
        out([
            'ok' => false,
            'code' =>
                'CLIENT_OTP_MODE_INVALID',

            'user_msg' =>
                'Serviço de verificação temporariamente indisponível.'
        ], 503);
    }


    /*
    |--------------------------------------------------------------------------
    | RESULTADO DO ENVIO
    |--------------------------------------------------------------------------
    */

    if (
        !is_array($resultadoOtp)
        ||
        (
            $resultadoOtp['sucesso']
            ?? false
        ) !== true
    ) {
        out([
            'ok' => false,
            'code' =>
                'CLIENT_OTP_UNAVAILABLE',

            'user_msg' =>
                'Não foi possível enviar o código agora. Tente novamente.'
        ], 503);
    }


    $expiresProvider =
        (int)(
            $resultadoOtp[
                'dados'
            ]['expires_in']
            ?? 0
        );

    if (
        $expiresProvider > 0
        &&
        $expiresProvider <= 900
    ) {
        $expiresIn =
            $expiresProvider;
    }


    $referencia =
        (string)(
            $resultadoOtp[
                'dados'
            ]['referencia']
            ??
            $resultadoOtp[
                'dados'
            ]['sid']
            ??
            ''
        );


    /*
    |--------------------------------------------------------------------------
    | DESAFIO
    |--------------------------------------------------------------------------
    |
    | Somente agora, após envio aceito pelo provedor,
    | o desafio é criado.
    |
    */

    $_SESSION[
        'cliente_auth_desafio'
    ] = [
        'id_empresa' =>
            $idEmpresa,

        'telefone' =>
            $telefoneE164,

        'modo' =>
            $modoOtp,

        'referencia' =>
            $referencia,

        /*
         * Somente DEV programmable_sms.
         * Nunca armazenar código em texto puro.
         */
        'otp_hash' =>
            $otpHash,

        'criado_em' =>
            time(),

        'enviado_em' =>
            time(),

        'expira_em' =>
            time() + $expiresIn,

        'tentativas' =>
            0,

        'max_tentativas' =>
            5
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


$desafioEmpresa =
    (int)(
        $desafio[
            'id_empresa'
        ] ?? 0
    );

$desafioTelefone =
    (string)(
        $desafio[
            'telefone'
        ] ?? ''
    );

$desafioModo =
    (string)(
        $desafio[
            'modo'
        ] ?? ''
    );

$desafioReferencia =
    (string)(
        $desafio[
            'referencia'
        ] ?? ''
    );

$expiraEm =
    (int)(
        $desafio[
            'expira_em'
        ] ?? 0
    );

$tentativas =
    (int)(
        $desafio[
            'tentativas'
        ] ?? 0
    );

$maxTentativas =
    (int)(
        $desafio[
            'max_tentativas'
        ] ?? 5
    );


/*
|--------------------------------------------------------------------------
| VALIDAÇÃO DO DESAFIO
|--------------------------------------------------------------------------
*/

if (
    $desafioEmpresa !== $idEmpresa
    ||
    $desafioTelefone === ''
) {
    unset(
        $_SESSION[
            'cliente_auth_desafio'
        ]
    );

    out([
        'ok' => false,
        'code' =>
            'CLIENT_OTP_CHALLENGE_INVALID',

        'user_msg' =>
            'Solicite um novo código para continuar.'
    ], 422);
}


if ($expiraEm <= time()) {

    unset(
        $_SESSION[
            'cliente_auth_desafio'
        ]
    );

    out([
        'ok' => false,
        'code' =>
            'CLIENT_OTP_EXPIRED',

        'user_msg' =>
            'O código expirou. Solicite um novo.'
    ], 422);
}


if (
    $tentativas >=
    $maxTentativas
) {
    unset(
        $_SESSION[
            'cliente_auth_desafio'
        ]
    );

    out([
        'ok' => false,
        'code' =>
            'CLIENT_OTP_ATTEMPTS_EXCEEDED',

        'user_msg' =>
            'Solicite um novo código para continuar.'
    ], 429);
}


/*
|--------------------------------------------------------------------------
| CÓDIGO
|--------------------------------------------------------------------------
*/

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
 * Incrementa tentativa antes de validar.
 *
 * O OTP fixo de DEV continua respeitando exatamente
 * o mesmo limite de tentativas do OTP normal.
 */
$_SESSION[
    'cliente_auth_desafio'
]['tentativas'] =
    $tentativas + 1;


/*
|--------------------------------------------------------------------------
| DEV - VALIDAÇÃO LOCAL
|--------------------------------------------------------------------------
|
| No modo programmable_sms existem duas possibilidades:
|
| 1. OTP aleatório normalmente gerado pelo AmAgenda;
| 2. OTP fixo de homologação, SOMENTE quando habilitado
|    explicitamente no twilio.php.
|
| Expiração e limite de tentativas continuam sendo aplicados
| antes de chegar neste ponto.
|
*/

if (
    $desafioModo ===
    'programmable_sms'
) {
    $otpHash =
        (string)(
            $desafio[
                'otp_hash'
            ] ?? ''
        );


    /*
    |--------------------------------------------------------------------------
    | OTP ALEATÓRIO NORMAL
    |--------------------------------------------------------------------------
    */

    $otpNormalAprovado =
        $otpHash !== ''
        &&
        password_verify(
            $codigo,
            $otpHash
        );


    /*
    |--------------------------------------------------------------------------
    | OTP FIXO - SOMENTE DEV
    |--------------------------------------------------------------------------
    |
    | A configuração fica exclusivamente em:
    |
    | backend/_config/twilio.php
    |
    | Exemplo:
    |
    | 'dev_fixed_otp_enabled' => true,
    | 'dev_fixed_otp_code' => '135794',
    |
    | Ao desabilitar dev_fixed_otp_enabled, o código fixo deixa
    | imediatamente de ser aceito.
    |
    */

    $configOtp =
        smsOtpConfig();

    $devFixedOtpEnabled =
        (
            $configOtp[
                'dev_fixed_otp_enabled'
            ] ?? false
        ) === true;

    $devFixedOtpCode =
        preg_replace(
            '/\D+/',
            '',
            (string)(
                $configOtp[
                    'dev_fixed_otp_code'
                ] ?? ''
            )
        ) ?? '';


    /*
     * Só considera a configuração válida quando
     * houver exatamente 6 dígitos.
     */
    $devFixedOtpValido =
        $devFixedOtpEnabled
        &&
        strlen(
            $devFixedOtpCode
        ) === 6;


    $otpFixoAprovado =
        $devFixedOtpValido
        &&
        hash_equals(
            $devFixedOtpCode,
            $codigo
        );


    /*
     * Mantém o OTP normal funcionando.
     *
     * O OTP fixo é apenas uma alternativa temporária
     * para homologação no ambiente DEV.
     */
    $otpAprovado =
        $otpNormalAprovado
        ||
        $otpFixoAprovado;
}


/*
|--------------------------------------------------------------------------
| TWILIO VERIFY
|--------------------------------------------------------------------------
*/

elseif (
    $desafioModo === 'verify'
) {
    $resultadoOtp =
        smsOtpValidar(
            $desafioTelefone,
            $codigo,
            $desafioReferencia !== ''
                ? $desafioReferencia
                : null
        );

    $otpAprovado =
        is_array($resultadoOtp)
        &&
        (
            $resultadoOtp[
                'sucesso'
            ] ?? false
        ) === true;
}


/*
|--------------------------------------------------------------------------
| MODO INVÁLIDO
|--------------------------------------------------------------------------
*/

else {
    $otpAprovado = false;
}


/*
|--------------------------------------------------------------------------
| OTP NÃO APROVADO
|--------------------------------------------------------------------------
*/

if (!$otpAprovado) {

    $tentativasAtuais =
        (int)(
            $_SESSION[
                'cliente_auth_desafio'
            ]['tentativas']
            ?? 0
        );

    if (
        $tentativasAtuais >=
        $maxTentativas
    ) {
        unset(
            $_SESSION[
                'cliente_auth_desafio'
            ]
        );
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

if (
    $clienteExistente
    &&
    strtolower(
        trim(
            (string)(
                $clienteAtual['status']
                ?? ''
            )
        )
    ) !== 'ativo'
) {
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

$idCliente =
    $clienteExistente
        ? (int)$clienteAtual['id_cliente']
        : null;

$cadastroCompleto =
    $clienteExistente
    &&
    (int)(
        $clienteAtual['cadastro_completo']
        ?? 0
    ) === 1;


/*
|--------------------------------------------------------------------------
| CRIAR SESSÃO AUTENTICADA
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);


$_SESSION['cliente_auth'] = [

    'id_cliente' =>
        $idCliente,

    'id_empresa' =>
        $idEmpresa,

    'telefone' =>
        $desafioTelefone,

    'nome_completo' =>
        $clienteExistente
            ? (string)(
                $clienteAtual[
                    'nome_completo'
                ] ?? ''
            )
            : '',

    'telefone_verificado' =>
        true,

    'cadastro_completo' =>
        $cadastroCompleto,

    'status' =>
        'ativo',

    'tipo' =>
        'cliente',

    'tipo_usuario' =>
        'cliente',

    'modo_visualizacao' =>
        true,

    'login_em' =>
        date('Y-m-d H:i:s')
];


unset(
    $_SESSION[
        'cliente_auth_desafio'
    ]
);


/*
|--------------------------------------------------------------------------
| ÚLTIMO LOGIN
|--------------------------------------------------------------------------
*/

if ($idCliente !== null) {
    $stmt =
        $conexao->prepare("
            UPDATE cliente
            SET ultimo_login_em =
                CURRENT_TIMESTAMP
            WHERE id_cliente = ?
              AND id_empresa = ?
        ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $idCliente,
            $idEmpresa
        );

        $stmt->execute();

        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| SUCESSO
|--------------------------------------------------------------------------
*/

out([
    'ok' => true,
    'code' =>
        'CLIENT_AUTHENTICATED',

    'data' => [
        'cadastro_completo' =>
            $cadastroCompleto,

        'redirect' =>
            '/public/views/cliente-perfil.html'
    ]
]);