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
require_once __DIR__ . '/../_regras/cliente_unificacao.php';

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

/**
 * Pedido de código para um contato da OUTRA conta (unificação). Sem enumeração: quando não existe outra
 * conta, o serviço OTP cria um desafio fictício (sem envio) que passa pelos MESMOS limites (celular, e-mail,
 * IP), prazo de reenvio e expiração do desafio real. Limites e indisponibilidade respondem igual nos dois
 * casos; qualquer outra falha do envio real (só ocorre com conta existente) vira a mesma resposta de sucesso.
 */
function clienteUnifSolicitarCodigo(mysqli $conexao, int $idEmpresa, int $idCliente, string $contato, string $valor, ?array $outra): void
{
    $ehEmail = $contato === 'email';
    $real = $outra !== null;

    $resultado = smsOtpSolicitar($conexao, [
        'principal_tipo' => $real ? 'cliente' : 'ficticio',
        // Fictício usa a finalidade de recuperação, que nunca envia. O telefone só entra no canal SMS (nos dois casos).
        'finalidade' => $real ? 'acesso_cliente' : 'recuperacao_cliente',
        'id_empresa' => $idEmpresa,
        'id_cliente' => $real ? (int)$outra['id_cliente'] : 0,
        'id_usuario' => 0,
        'telefone' => $ehEmail ? '' : $valor,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'enviar' => true,
        'canal' => $ehEmail ? 'email' : 'sms',
        'email' => $ehEmail ? $valor : null,
        'verificar_contato' => true,
    ]);

    $codigo = (string)($resultado['codigo'] ?? '');
    $comunsAosDoisCasos = [
        'SMS_OTP_REENVIO_AGUARDAR', 'SMS_OTP_CONCORRENCIA', 'SMS_OTP_LIMITE', 'EMAIL_INDISPONIVEL',
        'SMS_CONFIG_INVALIDA', 'EMAIL_INVALIDO', 'TELEFONE_INVALIDO', 'SMS_OTP_INDISPONIVEL',
    ];
    if (($resultado['sucesso'] ?? false) !== true && in_array($codigo, $comunsAosDoisCasos, true)) {
        clienteContatoRecusarSolicitacao($resultado);
    }

    $idDesafio = (int)($resultado['dados']['id_otp_desafio'] ?? 0);
    $_SESSION[CLIENTE_CONTATO_SESSAO] = [
        'tipo' => 'unificar',
        'contato' => $contato,
        'valor' => $valor,
        'id_empresa' => $idEmpresa,
        'id_cliente' => $idCliente,
        'id_otp_desafio' => $idDesafio > 0 ? $idDesafio : 2147483647,
        'id_cliente_otp' => $real ? (int)$outra['id_cliente'] : $idCliente,
        'enviado_em' => time(),
        'expira_em' => time() + SMS_OTP_VALIDADE_SEGUNDOS,
    ];

    out([
        'ok' => true,
        'code' => 'CLIENT_CONTACT_CODE_SENT',
        'user_msg' => $ehEmail
            ? 'Se houver uma conta com este e-mail, enviamos um código para ele.'
            : 'Se houver uma conta com este celular, enviamos um código por SMS.',
        'data' => ['contato' => $contato, 'tipo' => 'unificar', 'expires_in' => SMS_OTP_VALIDADE_SEGUNDOS, 'retry_after' => SMS_OTP_REENVIO_SEGUNDOS],
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
                'user_msg' => 'Este celular já está vinculado a outra conta desta empresa. Se for sua, entre com este celular na tela de acesso.',
                'fields' => ['cli_whats_novo' => 'Celular já vinculado a outra conta.'],
                'data' => ['oferecer_unificacao' => true, 'contato' => 'telefone'],
            ], 409);
        }

        clienteContatoEnviarCodigo(
            $conexao, $idEmpresa, $idCliente, 'adicionar_telefone', 'telefone', $novoTelefone, ''
        );
    }

    /* ---------------------------------------------------------------- unificar contas (C4.2) */
    if (in_array($acao, ['unificar_solicitar', 'unificar_previa', 'unificar_confirmar'], true)) {
        // Primeiro acesso: a unificação só é liberada depois que a conta tem senha (OTP -> cadastro ->
        // aceite -> primeira senha). A verificação é do servidor, sobre a linha atual do cliente.
        if (trim((string)($cliente['senha_hash'] ?? '')) === '') {
            out([
                'ok' => false,
                'code' => 'CLIENT_UNIFY_PASSWORD_REQUIRED',
                'user_msg' => 'Crie sua senha de acesso antes de unificar suas contas.',
            ], 403);
        }
        if (!clienteUnifTabelaDisponivel($conexao)) {
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_UNAVAILABLE', 'user_msg' => 'A unificação de contas ainda não está disponível.'], 503);
        }
        // Operação sensível: exige login recente (ou nova autenticação).
        if (!clienteUnifLoginRecente((array)($_SESSION['cliente_auth'] ?? []))) {
            out([
                'ok' => false,
                'code' => 'CLIENT_UNIFY_REAUTH_REQUIRED',
                'user_msg' => 'Por segurança, entre novamente para unificar suas contas.',
            ], 403);
        }
        if ($cliente['id_cliente_unificado'] !== null) {
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_ALREADY', 'user_msg' => 'Esta conta já foi unificada.'], 409);
        }
    }

    if ($acao === 'unificar_solicitar') {
        $contato = trim((string)($_POST['contato'] ?? ''));
        $bruto = trim((string)($_POST['valor'] ?? ''));
        $valorNorm = $contato === 'telefone' ? smsOtpNormalizarTelefone($bruto) : ($contato === 'email' ? emailOtpNormalizar($bruto) : null);
        if ($valorNorm === null) {
            out(['ok' => false, 'code' => 'CLIENT_CONTACT_INVALID', 'user_msg' => 'Informe um contato válido.'], 422);
        }
        $chaveNorm = clienteUnifChaveContato($contato, $valorNorm);
        if ($chaveNorm === clienteUnifChaveContato($contato, $contato === 'telefone' ? $telefoneCadastrado : $emailCadastrado)) {
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_OWN_CONTACT', 'user_msg' => 'Informe um contato da OUTRA conta.'], 422);
        }

        $outra = clienteUnifLocalizarConta($conexao, $idEmpresa, $idCliente, $contato, $valorNorm);
        if ($outra !== null && clienteUnifValidarPar($cliente, $outra, $idEmpresa) !== null) {
            $outra = null;
        }
        // Existindo ou não outra conta, o fluxo, os limites e as respostas são os mesmos (sem enumeração).
        clienteUnifSolicitarCodigo($conexao, $idEmpresa, $idCliente, $contato, $valorNorm, $outra);
    }

    if ($acao === 'unificar_previa' || $acao === 'unificar_confirmar') {
        $prova = clienteUnifLerProva($idEmpresa, $idCliente);
        if ($prova === null) {
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_PROOF_REQUIRED', 'user_msg' => 'Confirme o contato da outra conta para continuar.'], 422);
        }
        $idOutra = (int)$prova['id_outra'];
        $outraLinhas = smsConsultar($conexao, 'SELECT * FROM cliente WHERE id_cliente = ? AND id_empresa = ? LIMIT 1', 'ii', $idOutra, $idEmpresa);
        $outra = $outraLinhas[0] ?? null;
        if ($outra === null || clienteUnifValidarPar($cliente, $outra, $idEmpresa) !== null) {
            unset($_SESSION[CLIENTE_UNIF_SESSAO_PROVA]);
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_NOT_ELIGIBLE', 'user_msg' => 'Não foi possível unificar estas contas.'], 409);
        }
        $comprovados = clienteUnifContatosComprovados((array)($_SESSION['cliente_auth'] ?? []), $prova);

        if ($acao === 'unificar_previa') {
            out([
                'ok' => true,
                'code' => 'CLIENT_UNIFY_PREVIEW',
                'user_msg' => 'Revise as informações antes de unificar.',
                'data' => ['nonce' => (string)$prova['nonce'], 'previa' => clienteUnifPrevia($conexao, $cliente, $outra, $comprovados)],
            ]);
        }

        // Confirmação expressa: nonce da prova + aceite + conta definitiva escolhida pelo cliente.
        $nonceEnviado = (string)($_POST['nonce'] ?? '');
        if ($nonceEnviado === '' || !hash_equals((string)$prova['nonce'], $nonceEnviado)) {
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_PROOF_REQUIRED', 'user_msg' => 'Confirme o contato da outra conta para continuar.'], 422);
        }
        if ((string)($_POST['aceite'] ?? '') !== '1') {
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_CONSENT_REQUIRED', 'user_msg' => 'Confirme que deseja unificar as contas.'], 422);
        }
        $principal = trim((string)($_POST['principal'] ?? ''));
        if (!in_array($principal, ['atual', 'outra'], true)) {
            out(['ok' => false, 'code' => 'CLIENT_UNIFY_CHOICE_REQUIRED', 'user_msg' => 'Escolha a conta que será mantida.'], 422);
        }

        // As escolhas do formulário (atual/outra) viram principal/secundaria conforme a conta mantida.
        $traduz = static function (string $v) use ($principal): string {
            if (!in_array($v, ['atual', 'outra'], true)) {
                return '';
            }

            return $v === $principal ? 'principal' : 'secundaria';
        };
        $resultado = clienteUnifExecutar(
            $conexao, $idEmpresa, $idCliente, $idOutra, $principal,
            [
                'telefone' => $traduz(trim((string)($_POST['escolha_telefone'] ?? ''))),
                'email' => $traduz(trim((string)($_POST['escolha_email'] ?? ''))),
            ],
            $comprovados,
            ['contato_prova' => (string)$prova['contato'], 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')]
        );

        if (($resultado['ok'] ?? false) !== true) {
            $mapa = [
                'ESCOLHA_CONTATO_OBRIGATORIA' => ['CLIENT_UNIFY_CHOICE_REQUIRED', 'Escolha qual celular e e-mail manter.', 422],
                'SEM_CONTATO_COMPROVADO' => ['CLIENT_UNIFY_CONTACT_UNPROVEN', 'Mantenha ao menos um contato que você acabou de confirmar.', 422],
                'PROVA_INVALIDA' => ['CLIENT_UNIFY_PROOF_REQUIRED', 'Confirme o contato da outra conta para continuar.', 422],
                'RECURSO_INDISPONIVEL' => ['CLIENT_UNIFY_UNAVAILABLE', 'A unificação de contas ainda não está disponível.', 503],
            ];
            $m = $mapa[(string)($resultado['codigo'] ?? '')]
                ?? ['CLIENT_UNIFY_NOT_ELIGIBLE', 'Não foi possível unificar estas contas.', 409];
            out(['ok' => false, 'code' => $m[0], 'user_msg' => $m[1]], $m[2]);
        }

        $linhaPrincipal = smsConsultar(
            $conexao,
            "SELECT * FROM cliente WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo' LIMIT 1",
            'ii',
            (int)$resultado['id_principal'],
            $idEmpresa
        )[0] ?? null;
        if ($linhaPrincipal === null) {
            throw new RuntimeException('Conta definitiva não encontrada após a unificação.');
        }
        clienteUnifAtualizarSessao($linhaPrincipal, $comprovados);

        out([
            'ok' => true,
            'code' => 'CLIENT_UNIFY_DONE',
            'user_msg' => 'Contas unificadas com sucesso.',
            'data' => [
                'agendamentos' => (int)$resultado['agendamentos'],
                'telefone' => clienteUnifMascararTelefone($resultado['telefone']),
                'email' => clienteUnifMascararEmail($resultado['email']),
            ],
        ]);
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
            || !in_array((string)($desafio['tipo'] ?? ''), ['verificar', 'adicionar_telefone', 'unificar'], true)
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
            'id_cliente' => $tipo === 'unificar' ? (int)($desafio['id_cliente_otp'] ?? $idCliente) : $idCliente,
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
            // Unificação: mesma resposta para código errado, expirado, esgotado ou desafio fictício.
            if ($tipo === 'unificar') {
                out(['ok' => false, 'code' => 'CLIENT_OTP_NOT_APPROVED', 'user_msg' => 'Código inválido ou expirado.'], 422);
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

        // Unificação: o código aprovado prova a posse do contato da OUTRA conta. Nada é unificado
        // aqui; só guarda a prova e devolve a prévia para a escolha e a confirmação expressa.
        if ($tipo === 'unificar') {
            $idOutraOtp = (int)($desafio['id_cliente_otp'] ?? 0);
            $outra = $idOutraOtp > 0 && $idOutraOtp !== $idCliente
                ? clienteUnifLocalizarConta($conexao, $idEmpresa, $idCliente, $contato, $valor)
                : null;
            if (
                $outra === null
                || (int)$outra['id_cliente'] !== $idOutraOtp
                || clienteUnifValidarPar($cliente, $outra, $idEmpresa) !== null
            ) {
                out(['ok' => false, 'code' => 'CLIENT_OTP_NOT_APPROVED', 'user_msg' => 'Código inválido ou expirado.'], 422);
            }
            $nonce = clienteUnifGuardarProva($idEmpresa, $idCliente, $idOutraOtp, $contato, $valor);
            $prova = clienteUnifLerProva($idEmpresa, $idCliente);
            $comprovados = clienteUnifContatosComprovados((array)($_SESSION['cliente_auth'] ?? []), (array)$prova);
            out([
                'ok' => true,
                'code' => 'CLIENT_UNIFY_PROOF_OK',
                'user_msg' => 'Contato confirmado. Revise as informações antes de unificar.',
                'data' => ['nonce' => $nonce, 'previa' => clienteUnifPrevia($conexao, $cliente, $outra, $comprovados)],
            ]);
        }

        if ($tipo === 'adicionar_telefone') {
            $conexao->begin_transaction();
            try {
                // O número só é gravado se a conta continua sem celular e ele não é de outro cliente.
                if (clienteContatoTelefoneEmUso($conexao, $idEmpresa, $valor, $idCliente)) {
                    $conexao->rollback();
                    out([
                        'ok' => false,
                        'code' => 'CLIENT_PHONE_ALREADY_EXISTS',
                        'user_msg' => 'Este celular já está vinculado a outra conta desta empresa. Se for sua, entre com este celular na tela de acesso.',
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
                        'user_msg' => 'Este celular já está vinculado a outra conta desta empresa. Se for sua, entre com este celular na tela de acesso.',
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
