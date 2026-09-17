<?php
declare(strict_types=1);

require_once __DIR__ . '/../_servicos/pagamento_gateway.php';

/** Transporte privado do Mercado Pago; não cria cobranças nem expõe rotas. */
final class MercadoPagoGateway
{
    private const BASE_URL = 'https://api.mercadopago.com';
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 15;

    private string $ambiente;
    private string $accessToken;

    private function __construct(string $ambiente, string $accessToken)
    {
        $this->ambiente = pagamentoGatewayAmbiente($ambiente);
        $this->accessToken = $accessToken;
    }

    /** Lê exclusivamente a configuração privada do servidor, nunca do navegador. */
    public static function daConfiguracao(): self
    {
        $ambiente = getenv('MERCADO_PAGO_AMBIENTE');
        if (!is_string($ambiente) || !in_array($ambiente, ['teste', 'producao'], true)) {
            throw new RuntimeException('Configuração de pagamento indisponível.');
        }

        $variavelToken = $ambiente === 'teste'
            ? 'MERCADO_PAGO_ACCESS_TOKEN_TESTE'
            : 'MERCADO_PAGO_ACCESS_TOKEN_PRODUCAO';
        $token = getenv($variavelToken);
        if (!is_string($token) || $token === '' || trim($token) !== $token
            || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new RuntimeException('Configuração de pagamento indisponível.');
        }

        return new self($ambiente, $token);
    }

    /** Somente a chave pública de teste pode sair do backend para o MercadoPago.js. */
    public static function chavePublicaTeste(): string
    {
        if (getenv('MERCADO_PAGO_AMBIENTE') !== 'teste') {
            throw new RuntimeException('Configuração pública de pagamento indisponível.');
        }
        $chave = getenv('MERCADO_PAGO_PUBLIC_KEY_TESTE');
        if (!is_string($chave) || strlen($chave) < 20 || strlen($chave) > 200
            || trim($chave) !== $chave || preg_match('/[\x00-\x20\x7F]/', $chave)) {
            throw new RuntimeException('Configuração pública de pagamento indisponível.');
        }

        return $chave;
    }

    public function ambiente(): string
    {
        return $this->ambiente;
    }

    /**
     * Somente operações e caminhos internos conhecidos. IDs são componentes de
     * caminho, nunca URLs. A chave idempotente é recebida da camada de negócio.
     */
    public function requisitar(
        string $operacao,
        array $identificadores = [],
        ?array $corpo = null,
        ?string $chaveIdempotencia = null
    ): array {
        $rota = $this->rotaPermitida($operacao, $identificadores);
        if ($rota === null) {
            return pagamentoGatewayResultado(false, 'operacao_invalida', 'Operação de pagamento inválida.');
        }

        [$metodo, $caminho] = $rota;
        $exigeIdempotencia = in_array($operacao, ['criar_order', 'criar_perfil'], true);
        if (($metodo === 'POST' && ($corpo === null || array_is_list($corpo)
                || ($exigeIdempotencia && $chaveIdempotencia === null)))
            || ($metodo === 'GET' && ($corpo !== null || $chaveIdempotencia !== null))) {
            return pagamentoGatewayResultado(false, 'requisicao_invalida', 'Dados de pagamento inválidos.');
        }

        if ($chaveIdempotencia !== null && !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $chaveIdempotencia
        )) {
            return pagamentoGatewayResultado(false, 'idempotencia_invalida', 'Dados de pagamento inválidos.');
        }

        try {
            $json = $corpo === null ? null : json_encode($corpo, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return pagamentoGatewayResultado(false, 'requisicao_invalida', 'Dados de pagamento inválidos.');
        }

        if (!function_exists('curl_init')) {
            return pagamentoGatewayResultado(false, 'transporte_indisponivel', 'Pagamento temporariamente indisponível.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            if ($chaveIdempotencia !== null) $headers[] = 'X-Idempotency-Key: ' . $chaveIdempotencia;
        }

        $curl = curl_init(self::BASE_URL . $caminho);
        if ($curl === false) {
            return pagamentoGatewayResultado(false, 'transporte_indisponivel', 'Pagamento temporariamente indisponível.');
        }

        $configurado = curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($configurado && $json !== null) {
            $configurado = curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }
        if (!$configurado) {
            curl_close($curl);

            return pagamentoGatewayResultado(false, 'transporte_indisponivel', 'Pagamento temporariamente indisponível.');
        }

        $resposta = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($resposta === false) {
            return pagamentoGatewayResultado(false, 'falha_transporte', 'Pagamento temporariamente indisponível.');
        }

        try {
            $dados = json_decode($resposta, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return pagamentoGatewayResultado(false, 'resposta_invalida', 'Resposta de pagamento indisponível.', $httpStatus);
        }

        if (!is_array($dados) || array_is_list($dados)) {
            return pagamentoGatewayResultado(false, 'resposta_invalida', 'Resposta de pagamento indisponível.', $httpStatus);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $codigo = $httpStatus >= 500 ? 'provedor_indisponivel' : 'provedor_rejeitou';
            $mensagem = $httpStatus >= 500
                ? 'Pagamento temporariamente indisponível.'
                : 'Não foi possível processar o pagamento.';

            return pagamentoGatewayResultado(false, $codigo, $mensagem, $httpStatus);
        }

        return pagamentoGatewayResultado(true, 'ok', 'Operação concluída.', $httpStatus, $dados);
    }

    private function rotaPermitida(string $operacao, array $identificadores): ?array
    {
        if ($operacao === 'buscar_cliente') {
            if (array_keys($identificadores) !== ['email']) return null;
            $email = $identificadores['email'];
            if (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

            return ['GET', '/v1/customers/search?email=' . rawurlencode($email)];
        }

        $rotas = [
            'criar_order' => ['POST', '/v1/orders', []],
            'consultar_order' => ['GET', '/v1/orders/{order_id}', ['order_id']],
            'criar_cliente' => ['POST', '/v1/customers', []],
            'criar_perfil' => ['POST', '/v1/customers/{customer_id}/payment-profiles', ['customer_id']],
            'listar_perfis' => ['GET', '/v1/customers/{customer_id}/payment-profiles?limit=50', ['customer_id']],
            'consultar_perfil' => ['GET', '/v1/customers/{customer_id}/payment-profiles/{profile_id}', ['customer_id', 'profile_id']],
        ];

        if (!isset($rotas[$operacao])) {
            return null;
        }

        [$metodo, $caminho, $chaves] = $rotas[$operacao];
        if (array_keys($identificadores) !== $chaves) {
            return null;
        }

        foreach ($chaves as $chave) {
            $id = $identificadores[$chave];
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $id)) {
                return null;
            }

            $caminho = str_replace('{' . $chave . '}', rawurlencode($id), $caminho);
        }

        return [$metodo, $caminho];
    }
}
