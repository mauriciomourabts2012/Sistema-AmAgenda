<?php
declare(strict_types=1);

/*
 * Testes simulados sem banco e sem rede. A validação de dados é pura; a classe
 * do gateway é inspecionada por reflexão sem instanciar cliente ou fazer POST.
 */

$raiz = dirname(__DIR__, 2);
require_once $raiz . '/backend/_servicos/assinatura.php';
require_once $raiz . '/backend/_gateways/mercado_pago.php';

function payerFalhar(string $mensagem): never
{
    fwrite(STDERR, "FAIL: {$mensagem}\n");
    exit(1);
}

function payerAssertSame(mixed $esperado, mixed $atual, string $mensagem): void
{
    if ($esperado !== $atual) {
        payerFalhar($mensagem . ' Esperado: ' . var_export($esperado, true)
            . '; atual: ' . var_export($atual, true) . '.');
    }
}

if (!function_exists('assinaturaServicoPreapprovalIdentidadePagadorValidarDados')) {
    payerFalhar('o validador puro da identidade do pagador ainda não existe.');
}

function payerValidar(array $dados, int $empresa, int $usuario, string $emailSessao): array
{
    try {
        $email = assinaturaServicoPreapprovalIdentidadePagadorValidarDados(
            $dados,
            $empresa,
            $usuario,
            $emailSessao
        );
        return ['ok' => true, 'email' => $email, 'codigo' => null, 'http' => null];
    } catch (AssinaturaServicoConversaoErro $erro) {
        return [
            'ok' => false,
            'email' => null,
            'codigo' => $erro->getMessage(),
            'http' => $erro->httpStatus,
            'mensagem' => $erro->mensagemUsuario,
        ];
    }
}

$valido = [
    'id_usuario' => 41,
    'id_empresa' => 26,
    'usuario_status' => 'ativo',
    'empresa_status' => 'ativo',
    'vinculo_status' => 'ativo',
    'perfil_status' => 'ativo',
    'perfil_nome' => 'Proprietário',
    'email' => 'Atual@Example.com',
];

$resultado = payerValidar($valido, 26, 41, 'atual@example.com');
payerAssertSame(true, $resultado['ok'], 'identidade válida deve ser aceita');
payerAssertSame('Atual@Example.com', $resultado['email'], 'o e-mail retornado deve ser o atual do banco');

foreach ([
    'usuario inativo' => ['usuario_status' => 'inativo'],
    'empresa inativa' => ['empresa_status' => 'inativa'],
    'vinculo inativo' => ['vinculo_status' => 'inativo'],
    'perfil inativo' => ['perfil_status' => 'inativo'],
    'perfil não proprietário' => ['perfil_nome' => 'Profissional'],
    'email vazio' => ['email' => ''],
    'email inválido' => ['email' => 'invalido'],
    'id de usuário divergente' => ['id_usuario' => 99],
    'id de empresa divergente' => ['id_empresa' => 99],
] as $nome => $mudanca) {
    $resultado = payerValidar(array_replace($valido, $mudanca), 26, 41, 'atual@example.com');
    payerAssertSame(false, $resultado['ok'], $nome . ' deve bloquear');
    payerAssertSame('SESSION_REAUTH_REQUIRED', $resultado['codigo'], $nome . ' deve exigir autenticação');
    payerAssertSame(401, $resultado['http'], $nome . ' deve responder 401');
}

foreach (['', 'invalido', 'outro@example.com'] as $emailSessao) {
    $resultado = payerValidar($valido, 26, 41, $emailSessao);
    payerAssertSame(false, $resultado['ok'], 'sessão ausente, inválida ou divergente deve bloquear');
    payerAssertSame('SESSION_REAUTH_REQUIRED', $resultado['codigo'], 'divergência deve exigir autenticação');
    payerAssertSame(false, str_contains($resultado['mensagem'], 'Atual@Example.com'), 'erro não deve expor e-mail do banco');
    payerAssertSame(false, $emailSessao !== '' && str_contains($resultado['mensagem'], $emailSessao), 'erro não deve expor e-mail da sessão');
}

$reflexao = new ReflectionClass(MercadoPagoGateway::class);
$validarCorpo = $reflexao->getMethod('corpoSubscriptionsValido');
$validarCorpo->setAccessible(true);
$corpo = [
    'external_reference' => 'DEV-SIMULADO',
    'payer_email' => 'atual@example.com',
    'status' => 'pending',
    'auto_recurring' => [
        'frequency' => 1,
        'frequency_type' => 'months',
        'transaction_amount' => 10.0,
        'currency_id' => 'BRL',
    ],
];
payerAssertSame(true, $validarCorpo->invoke(null, 'criar_preapproval', $corpo), 'corpo válido deve ser aceito');

$semEmail = $corpo;
unset($semEmail['payer_email']);
payerAssertSame(false, $validarCorpo->invoke(null, 'criar_preapproval', $semEmail), 'criação sem payer_email deve ser rejeitada');

foreach (['', 'invalido'] as $email) {
    $caso = $corpo;
    $caso['payer_email'] = $email;
    payerAssertSame(false, $validarCorpo->invoke(null, 'criar_preapproval', $caso), 'payer_email vazio ou inválido deve ser rejeitado');
}

payerAssertSame(true, $validarCorpo->invoke(null, 'atualizar_preapproval', ['status' => 'paused']), 'atualização sem payer_email deve permanecer válida');

$corpoMontado = assinaturaServicoPreapprovalMontarCorpo(
    ['plano_nome' => 'Teste', 'valor_centavos' => 1000, 'frequencia_meses' => 1],
    'DEV-SIMULADO',
    'Atual@Example.com',
    null,
    null
);
payerAssertSame('Atual@Example.com', $corpoMontado['payer_email'] ?? null, 'payload deve usar o e-mail validado sem substituição');

$fonte = file_get_contents($raiz . '/backend/_servicos/assinatura.php');
if (!is_string($fonte)) payerFalhar('não foi possível conferir o fluxo da Preapproval.');
payerAssertSame(
    1,
    preg_match('/if\\s*\\(\\$reserva\\[.acao.\\]\\s*===\\s*.criar.\\)[\\s\\S]{0,1200}assinaturaServicoPreapprovalIdentidadePagador/', $fonte),
    'a identidade deve ser consultada somente no ramo criar'
);
payerAssertSame(
    1,
    preg_match('/assinaturaServicoPreapprovalIdentidadePagador[\\s\\S]{0,2000}return\\s+\\$reserva;/', $fonte),
    'a identidade deve ser validada dentro da função transacional antes de devolver a reserva'
);

fwrite(STDOUT, "PASS: identidade do novo pagador, payload e obrigatoriedade do gateway validados.\n");

