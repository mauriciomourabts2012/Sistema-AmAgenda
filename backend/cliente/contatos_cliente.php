<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PERFIL DO CLIENTE - CONTATOS (CELULAR E E-MAIL) E VALIDAÇÃO DO SEGUNDO CONTATO
|--------------------------------------------------------------------------
|
| A conta pode ter só celular, só e-mail ou os dois. Cada contato só vale como
| credencial depois de comprovada a posse por código (SMS Infobip ou Gmail).
| Este endpoint, sempre com o cliente autenticado (empresa e cliente vêm da
| sessão), permite:
|
| - solicitar_verificacao: validar o contato JÁ cadastrado e ainda não validado
|   (celular por SMS, e-mail por Gmail), sem exigir os dois ao mesmo tempo;
| - solicitar_adicao_telefone: conta sem celular informa um número; ele só é
|   gravado DEPOIS de o código enviado a esse número ser confirmado (nunca há
|   telefone fictício nem número de terceiros gravado sem posse comprovada);
| - validar: confirma o código do pedido anterior (consumo único, expiração e
|   tentativas do serviço OTP) e só então marca/grava o contato.
|
| Alterar o e-mail continua em salvar_dados_cadastrais.php (invalida a
| verificação); remover o último contato validado é recusado lá.
|
*/

require_once __DIR__ . '/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_servicos/sms_otp.php';
require_once __DIR__ . '/../_regras/cliente_login_email.php';

const CLIENTE_CONTATO_SESSAO = 'cliente_contato_desafio';

/** O celular (normalizado) já pertence a outro cliente da empresa? */
function clienteContatoTelefoneEmUso(mysqli $conexao, int $idEmpresa, string $telefoneE164, int $idClienteAtual): bool
{
    $digitos = ltrim($telefoneE164, '+');
    $semPais = substr($digitos, 2);

    return smsConsultar(
        $conexao,
        "SELECT 1 FROM cliente
          WHERE id_empresa = ? AND id_cliente <> ?
            AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp_celular, '+', ''), '(', ''), ')', ''), '-', ''), ' ', '') IN (?, ?)
          LIMIT 1",
        'iiss',
        $idEmpresa,
        $idClienteAtual,
        $digitos,
        $semPais
    ) !== [];
}

/** Resposta de erro do serviço OTP (autenticado: mensagens específicas não revelam terceiros). */
function clienteContatoRecusarSolicitacao(array $resultado): void
{
    $codigo = (string)($resultado['codigo'] ?? '');
    $retry = max(0, (int)($resultado['dados']['retry_after'] ?? 0));

    if (in_array($codigo, ['SMS_OTP_REENVIO_AGUARDAR', 'SMS_OTP_CONCORRENCIA'], true)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_OTP_RESEND_LIMIT',
            'user_msg' => 'Aguarde alguns segundos antes de solicitar outro código.',
            'data' => ['retry_after' => max(1, $retry ?: 30)],
        ], 429);
    }
    if ($codigo === 'SMS_OTP_LIMITE') {
        out([
            'ok' => false,
            'code' => 'CLIENT_OTP_HOURLY_LIMIT',
            'user_msg' => 'Você atingiu o limite de solicitações de código. Tente novamente mais tarde.',
            'data' => ['retry_after' => max(1, $retry ?: 600)],
        ], 429);
    }
    if ($codigo === 'EMAIL_INDISPONIVEL') {
        out([
            'ok' => false,
            'code' => 'CLIENT_EMAIL_UNAVAILABLE',
            'user_msg' => 'O envio por e-mail está indisponível no momento. Tente novamente mais tarde.',
        ], 503);
    }
    if (in_array($codigo, ['EMAIL_INVALIDO', 'TELEFONE_INVALIDO'], true)) {
        out([
            'ok' => false,
            'code' => 'CLIENT_CONTACT_INVALID',
            'user_msg' => 'Informe um contato válido.',
        ], 422);
    }

    out([
        'ok' => false,
        'code' => 'CLIENT_CONTACT_SEND_FAILED',
        'user_msg' => 'Não foi possível enviar o código agora. Tente novamente.',
        'data' => ['retry_after' => max(1, $retry ?: 30)],
    ], 503);
}

/** Pede o código ao serviço OTP para o contato do próprio cliente e guarda o vínculo na sessão. */
function clienteContatoEnviarCodigo(
    mysqli $conexao,
    int $idEmpresa,
    int $idCliente,
    string $tipo,
    string $contato,
    string $valor,
    string $telefoneParaLimite
): void {
    $ehEmail = $contato === 'email';

    $resultado = smsOtpSolicitar($conexao, [
        'principal_tipo' => 'cliente',
        // O enum de finalidade não tem valor próprio; o desafio fica preso ao cliente e à sessão e é
        // validado só por este endpoint (chave de sessão própria), nunca pelo login.
        'finalidade' => 'acesso_cliente',
        'id_empresa' => $idEmpresa,
        'id_cliente' => $idCliente,
        'id_usuario' => 0,
        'telefone' => $ehEmail ? $telefoneParaLimite : $valor,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'enviar' => true,
        'canal' => $ehEmail ? 'email' : 'sms',
        'email' => $ehEmail ? $valor : null,
        // Somente aqui (cliente autenticado): confirmar a posse do e-mail cadastrado ainda não validado.
        'verificar_contato' => true,
    ]);

    $codigo = (string)($resultado['codigo'] ?? '');
    $idDesafio = (int)($resultado['dados']['id_otp_desafio'] ?? 0);
    $expiresIn = max(1, (int)($resultado['dados']['expires_in'] ?? 300));

    // Desafio sem envio (e-mail que deixou de ser o cadastrado, por exemplo): nada foi enviado.
    if (($resultado['sucesso'] ?? false) === true && ($resultado['dados']['decoy'] ?? false) === true) {
        unset($_SESSION[CLIENTE_CONTATO_SESSAO]);
        out([
            'ok' => false,
            'code' => 'CLIENT_CONTACT_NOT_AVAILABLE',
            'user_msg' => 'Não foi possível validar este contato. Atualize a página e tente novamente.',
        ], 409);
    }

    // O envio incerto mantém o novo desafio como o único utilizável (igual ao login).
    if (($resultado['sucesso'] ?? false) !== true && $codigo !== 'SMS_OTP_ENVIO_INCERTO') {
        clienteContatoRecusarSolicitacao($resultado);
    }

    $_SESSION[CLIENTE_CONTATO_SESSAO] = [
        'tipo' => $tipo,
        'contato' => $contato,
        'valor' => $valor,
        'id_empresa' => $idEmpresa,
        'id_cliente' => $idCliente,
        'id_otp_desafio' => $idDesafio,
        'enviado_em' => time(),
        'expira_em' => time() + $expiresIn,
    ];

    if ($codigo === 'SMS_OTP_ENVIO_INCERTO') {
        out([
            'ok' => false,
            'code' => 'CLIENT_OTP_SEND_UNCERTAIN',
            'user_msg' => 'Não foi possível confirmar o envio do código. Aguarde antes de tentar novamente.',
            'data' => ['codigo_pendente' => true, 'expires_in' => $expiresIn, 'retry_after' => 30],
        ], 503);
    }

    out([
        'ok' => true,
        'code' => 'CLIENT_CONTACT_CODE_SENT',
        'user_msg' => $ehEmail
            ? 'Enviamos um código para o seu e-mail.'
            : 'Enviamos um código por SMS para o seu celular.',
        'data' => [
            'contato' => $contato,
            'tipo' => $tipo,
            'expires_in' => $expiresIn,
            'retry_after' => 30,
        ],
    ]);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }

    // Cliente e empresa vêm sempre da sessão validada (isolamento multiempresa).
    $sessao = exigirSessaoCliente();
    require_once __DIR__ . '/../_auth/csrf.php';
    csrfValidarSessao();
    $cliente = buscarClienteDaSessao($conexao, $sessao);

    if ($cliente === null) {
        out([
            'ok' => false,
            'code' => 'CLIENT_PROFILE_REQUIRED',
            'user_msg' => 'Salve seus dados cadastrais antes de validar contatos.',
        ], 422);
    }

    $idEmpresa = (int)$sessao['id_empresa'];
    $idCliente = (int)$cliente['id_cliente'];
    $telefoneCadastrado = trim((string)($cliente['whatsapp_celular'] ?? ''));
    $telefoneCadastradoE164 = $telefoneCadastrado !== '' ? smsOtpNormalizarTelefone($telefoneCadastrado) : null;
    $emailCadastrado = mb_strtolower(trim((string)($cliente['email'] ?? '')), 'UTF-8');

    $acao = trim((string)($_POST['acao'] ?? ''));

    /* ---------------------------------------------------------------- validar o contato cadastrado */
    if ($acao === 'solicitar_verificacao') {
        $contato = trim((string)($_POST['contato'] ?? ''));

        if ($contato === 'email') {
            if ($emailCadastrado === '') {
                out(['ok' => false, 'code' => 'CLIENT_CONTACT_MISSING', 'user_msg' => 'Salve um e-mail antes de validá-lo.'], 422);
            }
            if (($cliente['email_verificado_em'] ?? null) !== null) {
                out(['ok' => false, 'code' => 'CLIENT_CONTACT_ALREADY_VERIFIED', 'user_msg' => 'Este e-mail já está validado.'], 409);
            }
            clienteContatoEnviarCodigo(
                $conexao, $idEmpresa, $idCliente, 'verificar', 'email', $emailCadastrado,
                $telefoneCadastradoE164 ?? ''
            );
        }

        if ($contato === 'telefone') {
            if ($telefoneCadastradoE164 === null) {
                out(['ok' => false, 'code' => 'CLIENT_CONTACT_MISSING', 'user_msg' => 'Adicione um celular antes de validá-lo.'], 422);
            }
            if (($cliente['telefone_verificado_em'] ?? null) !== null) {
                out(['ok' => false, 'code' => 'CLIENT_CONTACT_ALREADY_VERIFIED', 'user_msg' => 'Este celular já está validado.'], 409);
            }
            clienteContatoEnviarCodigo(
                $conexao, $idEmpresa, $idCliente, 'verificar', 'telefone', $telefoneCadastradoE164, ''
            );
        }

        out(['ok' => false, 'code' => 'CLIENT_CONTACT_INVALID', 'user_msg' => 'Contato inválido.'], 422);
    }

    /* ---------------------------------------------------------------- adicionar celular (conta sem celular) */
    if ($acao === 'solicitar_adicao_telefone') {
        if ($telefoneCadastrado !== '') {
            out([
                'ok' => false,
                'code' => 'CLIENT_PHONE_ALREADY_SET',
                'user_msg' => 'Sua conta já possui um celular cadastrado.',
            ], 409);
        }

        $novoTelefone = smsOtpNormalizarTelefone((string)($_POST['telefone'] ?? ''));
        if ($novoTelefone === null) {
            out([
                'ok' => false,
                'code' => 'CLIENT_PHONE_INVALID',
                'user_msg' => 'Informe um telefone válido.',
                'fields' => ['cli_whats_novo' => 'Informe DDD + número.'],
            ], 422);
        }

        // Nunca associa o número de outro cliente da empresa por coincidência.
        if (clienteContatoTelefoneEmUso($conexao, $idEmpresa, $novoTelefone, $idCliente)) {
            out([
                'ok' => false,
                'code' => 'CLIENT_PHONE_ALREADY_EXISTS',
                'user_msg' => 'Este celular já está cadastrado nesta empresa.',
                'fields' => ['cli_whats_novo' => 'Celular já cadastrado.'],
            ], 409);
        }

        clienteContatoEnviarCodigo(
            $conexao, $idEmpresa, $idCliente, 'adicionar_telefone', 'telefone', $novoTelefone, ''
        );
    }

    /* ---------------------------------------------------------------- validar o código */
    if ($acao === 'validar') {
        $desafio = $_SESSION[CLIENTE_CONTATO_SESSAO] ?? null;
        $codigo = preg_replace('/\D+/', '', (string)($_POST['codigo'] ?? '')) ?? '';

        if (
            !is_array($desafio)
            || (int)($desafio['id_empresa'] ?? 0) !== $idEmpresa
            || (int)($desafio['id_cliente'] ?? 0) !== $idCliente
            || (int)($desafio['id_otp_desafio'] ?? 0) <= 0
            || !in_array((string)($desafio['contato'] ?? ''), ['telefone', 'email'], true)
            || !in_array((string)($desafio['tipo'] ?? ''), ['verificar', 'adicionar_telefone'], true)
        ) {
            unset($_SESSION[CLIENTE_CONTATO_SESSAO]);
            out(['ok' => false, 'code' => 'CLIENT_OTP_CHALLENGE_NOT_FOUND', 'user_msg' => 'Solicite um novo código para continuar.'], 422);
        }
        if (strlen($codigo) !== 6) {
            out(['ok' => false, 'code' => 'CLIENT_OTP_CODE_INVALID', 'user_msg' => 'Código inválido.'], 422);
        }

        $contato = (string)$desafio['contato'];
        $tipo = (string)$desafio['tipo'];
        $valor = (string)($desafio['valor'] ?? '');

        $parametros = [
            'principal_tipo' => 'cliente',
            'finalidade' => 'acesso_cliente',
            'id_empresa' => $idEmpresa,
            'id_cliente' => $idCliente,
            'id_usuario' => 0,
            'telefone' => $contato === 'telefone' ? $valor : '',
            'id_otp_desafio' => (int)$desafio['id_otp_desafio'],
            'codigo' => $codigo,
        ];
        if ($contato === 'email') {
            $parametros['canal'] = 'email';
            $parametros['email'] = $valor;
        }

        $validacao = smsOtpValidar($conexao, $parametros);

        if (($validacao['sucesso'] ?? false) !== true) {
            $codigoValidacao = (string)($validacao['codigo'] ?? '');
            if (in_array($codigoValidacao, ['SMS_OTP_INDISPONIVEL', 'SMS_CONFIG_INVALIDA'], true)) {
                out(['ok' => false, 'code' => 'CLIENT_OTP_UNAVAILABLE', 'user_msg' => 'Não foi possível validar o código agora.'], 503);
            }
            if ($codigoValidacao === 'SMS_OTP_EXPIRADO') {
                unset($_SESSION[CLIENTE_CONTATO_SESSAO]);
                out(['ok' => false, 'code' => 'CLIENT_OTP_EXPIRED', 'user_msg' => 'O código expirou. Solicite um novo.'], 422);
            }
            if ($codigoValidacao === 'SMS_OTP_TENTATIVAS') {
                unset($_SESSION[CLIENTE_CONTATO_SESSAO]);
                out(['ok' => false, 'code' => 'CLIENT_OTP_ATTEMPTS_EXCEEDED', 'user_msg' => 'Solicite um novo código para continuar.'], 429);
            }
            out(['ok' => false, 'code' => 'CLIENT_OTP_NOT_APPROVED', 'user_msg' => 'Código inválido ou expirado.'], 422);
        }

        // Código aprovado (consumido): agora, e só agora, o contato é marcado ou gravado.
        unset($_SESSION[CLIENTE_CONTATO_SESSAO]);

        if ($tipo === 'adicionar_telefone') {
            $conexao->begin_transaction();
            try {
                // O número só é gravado se a conta continua sem celular e ele não é de outro cliente.
                if (clienteContatoTelefoneEmUso($conexao, $idEmpresa, $valor, $idCliente)) {
                    $conexao->rollback();
                    out([
                        'ok' => false,
                        'code' => 'CLIENT_PHONE_ALREADY_EXISTS',
                        'user_msg' => 'Este celular já está cadastrado nesta empresa.',
                    ], 409);
                }
                $gravado = smsExecutar(
                    $conexao,
                    "UPDATE cliente SET whatsapp_celular = ?
                      WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo'
                        AND (whatsapp_celular IS NULL OR TRIM(whatsapp_celular) = '')",
                    'sii',
                    $valor,
                    $idCliente,
                    $idEmpresa
                );
                // Em duas etapas para a verificação nunca ser desfeita pela regra que invalida o
                // contato quando ele é alterado (trigger do banco).
                $marcado = $gravado === 1 && smsExecutar(
                    $conexao,
                    'UPDATE cliente SET telefone_verificado_em = NOW()
                      WHERE id_cliente = ? AND id_empresa = ? AND whatsapp_celular = ?',
                    'iis',
                    $idCliente,
                    $idEmpresa,
                    $valor
                ) === 1;
                if (!$marcado) {
                    $conexao->rollback();
                    out([
                        'ok' => false,
                        'code' => 'CLIENT_PHONE_NOT_SAVED',
                        'user_msg' => 'Não foi possível adicionar o celular agora. Tente novamente.',
                    ], 409);
                }
                $conexao->commit();
            } catch (Throwable $e) {
                $conexao->rollback();
                if ((int)$e->getCode() === 1062) {
                    out([
                        'ok' => false,
                        'code' => 'CLIENT_PHONE_ALREADY_EXISTS',
                        'user_msg' => 'Este celular já está cadastrado nesta empresa.',
                    ], 409);
                }
                throw $e;
            }
        } elseif ($contato === 'telefone') {
            clienteLoginTelefoneMarcarVerificado($conexao, $idEmpresa, $idCliente, $valor);
        } else {
            emailOtpMarcarVerificado($conexao, $idEmpresa, $idCliente, $valor);
        }

        out([
            'ok' => true,
            'code' => 'CLIENT_CONTACT_VERIFIED',
            'user_msg' => $contato === 'email'
                ? 'E-mail validado com sucesso.'
                : 'Celular validado com sucesso.',
            'data' => ['contato' => $contato, 'tipo' => $tipo],
        ]);
    }

    out(['ok' => false, 'code' => 'CLIENT_CONTACT_ACTION_INVALID', 'user_msg' => 'Operação inválida.'], 422);
} catch (Throwable $e) {
    error_log('[cliente_contatos] ' . get_class($e));
    out([
        'ok' => false,
        'code' => 'CLIENT_CONTACT_ERROR',
        'user_msg' => 'Não foi possível concluir a operação agora.',
    ], 500);
}
