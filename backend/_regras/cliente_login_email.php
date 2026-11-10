<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LOGIN DO CLIENTE - REGRAS DOS CONTATOS CELULAR E E-MAIL (C3 / C3.1)
|--------------------------------------------------------------------------
|
| Regras puras (sem out()/exit) usadas por backend/_auth/cliente_login.php e
| testáveis com banco falso. O desafio, os limites e o envio continuam em
| backend/_servicos/sms_otp.php e email_otp.php; aqui ficam somente as decisões
| do fluxo do cliente:
|
| - a conta pode ter só celular, só e-mail ou os dois. Cada contato é uma
|   credencial independente (SMS Infobip e Gmail), e cada um só vale depois de
|   comprovada a posse;
|
| - cliente existente: o código por e-mail só é enviado para o e-mail
|   cadastrado E já validado (email_verificado_em); caso contrário nenhum
|   e-mail sai e a resposta pública é idêntica (sem enumeração);
|
| - primeiro acesso por e-mail: permitido somente com e-mail que não pertence a
|   nenhum cliente da empresa; a conta nasce só com e-mail (sem telefone
|   fictício) e nunca é associada a um cadastro existente por coincidência;
|
| - a validação do código reconfere, no momento do login, que o e-mail ainda é
|   o cadastrado e validado (alterar o e-mail invalida a verificação).
|
*/

require_once __DIR__ . '/../_servicos/sms_otp.php';

/**
 * Primeiro acesso (sem cadastro) pelo canal e-mail. Liberado na C3.1 porque a conta definitiva pode
 * existir só com e-mail. Com false, o pedido por e-mail sem cadastro volta a gerar apenas um desafio
 * sem envio (mesma resposta pública) e nunca autentica (chave de desligamento).
 */
const CLIENTE_LOGIN_EMAIL_PRIMEIRO_ACESSO = true;

function clienteLoginEmailNormalizar(string $email): ?string
{
    return emailOtpNormalizar($email);
}

/**
 * Parâmetros de smsOtpSolicitar() para o login do cliente. O canal sms mantém exatamente os
 * parâmetros anteriores à C3.
 *
 * @param array{canal: string, email: ?string, id_empresa: int, telefone: string, cliente_ativo: bool,
 *              id_cliente: int, finalidade: string, ficticio_sem_envio: bool, ip: string} $c
 * @return array<string, mixed>
 */
function clienteLoginParametrosSolicitacao(array $c): array
{
    $ehEmail = $c['canal'] === 'email';
    $enviar = !$c['ficticio_sem_envio'];

    // Telefone sem cadastro por e-mail: nada é enviado enquanto o primeiro acesso por e-mail
    // estiver bloqueado.
    if ($ehEmail && !$c['cliente_ativo'] && !CLIENTE_LOGIN_EMAIL_PRIMEIRO_ACESSO) {
        $enviar = false;
    }

    $p = [
        'principal_tipo' => $c['cliente_ativo'] ? 'cliente' : 'ficticio',
        'finalidade' => $c['finalidade'] === 'recuperacao_senha' ? 'recuperacao_cliente' : 'acesso_cliente',
        'id_empresa' => $c['id_empresa'],
        'id_cliente' => $c['cliente_ativo'] ? $c['id_cliente'] : 0,
        'id_usuario' => 0,
        'telefone' => $c['telefone'],
        'ip' => $c['ip'],
        'enviar' => $enviar,
    ];
    if ($ehEmail) {
        $p['canal'] = 'email';
        $p['email'] = (string)$c['email'];
    }

    return $p;
}

/**
 * O e-mail do desafio continua sendo o cadastrado e validado do cliente?
 */
function clienteLoginEmailVerificadoDoCliente(mysqli $conexao, int $idEmpresa, int $idCliente, string $email): bool
{
    $email = emailOtpNormalizar($email);
    if ($email === null || $idEmpresa <= 0 || $idCliente <= 0) {
        return false;
    }

    return smsConsultar(
        $conexao,
        "SELECT 1 FROM cliente
          WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo'
            AND LOWER(email) = ? AND email_verificado_em IS NOT NULL LIMIT 1",
        'iis',
        $idCliente,
        $idEmpresa,
        $email
    ) !== [];
}

/**
 * Localiza o cliente da empresa pelo e-mail (normalizado). Mais de um resultado indica inconsistência
 * (o índice único uq_cliente_empresa_email impede) e interrompe o fluxo.
 *
 * @return array<string, mixed>|null
 */
function clienteLoginBuscarClientePorEmail(mysqli $conexao, int $idEmpresa, string $email): ?array
{
    $email = emailOtpNormalizar($email);
    if ($email === null || $idEmpresa <= 0) {
        throw new RuntimeException('E-mail inválido para consulta.');
    }

    $linhas = smsConsultar(
        $conexao,
        'SELECT id_cliente, id_empresa, nome_completo, whatsapp_celular, email, email_verificado_em, telefone_verificado_em,
                cadastro_completo, senha_hash, status
           FROM cliente
          WHERE id_empresa = ? AND LOWER(email) = ?
          LIMIT 2',
        'is',
        $idEmpresa,
        $email
    );
    if (count($linhas) > 1) {
        throw new RuntimeException('Clientes duplicados para o e-mail.');
    }

    return $linhas[0] ?? null;
}

/** O e-mail não pertence a nenhum cliente da empresa (condição do primeiro acesso por e-mail). */
function clienteLoginEmailLivreNaEmpresa(mysqli $conexao, int $idEmpresa, string $email): bool
{
    $email = emailOtpNormalizar($email);
    if ($email === null || $idEmpresa <= 0) {
        return false;
    }

    return smsConsultar(
        $conexao,
        'SELECT 1 FROM cliente WHERE id_empresa = ? AND LOWER(email) = ? LIMIT 1',
        'is',
        $idEmpresa,
        $email
    ) === [];
}

/**
 * Marca o celular do cliente como validado SOMENTE quando o número comprovado por SMS é o cadastrado
 * nesse cliente e empresa (compara pelo formato normalizado). Não grava número novo.
 */
function clienteLoginTelefoneMarcarVerificado(mysqli $conexao, int $idEmpresa, int $idCliente, string $telefoneE164): bool
{
    $telefone = smsOtpNormalizarTelefone($telefoneE164);
    if ($telefone === null || $idEmpresa <= 0 || $idCliente <= 0) {
        return false;
    }
    $digitos = ltrim($telefone, '+');
    $semPais = substr($digitos, 2);

    return smsExecutar(
        $conexao,
        "UPDATE cliente SET telefone_verificado_em = NOW()
          WHERE id_cliente = ? AND id_empresa = ? AND telefone_verificado_em IS NULL
            AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp_celular, '+', ''), '(', ''), ')', ''), '-', ''), ' ', '') IN (?, ?)",
        'iiss',
        $idCliente,
        $idEmpresa,
        $digitos,
        $semPais
    ) === 1;
}

/**
 * Depois de um código por e-mail aprovado:
 * - cliente existente: só autentica se for o principal do desafio e o e-mail ainda estiver cadastrado
 *   e validado nele (alterar o e-mail invalida a verificação);
 * - sem cadastro (primeiro acesso): só autentica se o desafio não tinha cliente e o e-mail continua
 *   livre na empresa. A conta é criada depois, só com e-mail, pelo cadastro do perfil.
 *
 * @param array<string, mixed>|null $clienteAtual
 */
function clienteLoginEmailPodeAutenticar(
    mysqli $conexao,
    int $idEmpresa,
    ?array $clienteAtual,
    ?int $desafioIdCliente,
    string $email
): bool {
    if (!is_array($clienteAtual)) {
        // Sem cadastro: só vale se o desafio também era de primeiro acesso.
        return CLIENTE_LOGIN_EMAIL_PRIMEIRO_ACESSO
            && ($desafioIdCliente === null || $desafioIdCliente === 0)
            && clienteLoginEmailLivreNaEmpresa($conexao, $idEmpresa, $email);
    }
    $idCliente = (int)($clienteAtual['id_cliente'] ?? 0);
    if ($idCliente <= 0 || $desafioIdCliente === null || $desafioIdCliente !== $idCliente) {
        return false;
    }
    if ((int)($clienteAtual['id_empresa'] ?? $idEmpresa) !== $idEmpresa) {
        return false;
    }

    return clienteLoginEmailVerificadoDoCliente($conexao, $idEmpresa, $idCliente, $email);
}
