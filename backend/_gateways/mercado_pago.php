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
    private static ?array $configuracaoCentral = null;

    private function __construct(string $ambiente, string $accessToken)
    {
        $this->ambiente = pagamentoGatewayAmbiente($ambiente);
        $this->accessToken = $accessToken;
    }

    /** Lê exclusivamente a configuração privada do servidor, nunca do navegador. */
    public static function daConfiguracao(): self
    {
        $ativa = self::configuracaoAtiva();
        $ambiente = $ativa['ambiente'];
        $token = $ativa['dados']['access_token'] ?? null;
        if (!is_string($token) || $token === '' || trim($token) !== $token
            || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new RuntimeException('Configuração de pagamento indisponível.');
        }

        return new self($ambiente, $token);
    }

    /** Somente a chave pública validada do ambiente ativo pode sair para o MercadoPago.js. */
    public static function chavePublicaAtiva(): string
    {
        $ativa = self::configuracaoAtiva();
        $chave = $ativa['dados']['public_key'] ?? null;
        if (!is_string($chave) || strlen($chave) < 20 || strlen($chave) > 200
            || trim($chave) !== $chave || preg_match('/[\x00-\x20\x7F]/', $chave)) {
            throw new RuntimeException('Configuração pública de pagamento indisponível.');
        }

        return $chave;
    }

    /** Retorna somente estados seguros; credenciais nunca integram o diagnóstico administrativo. */
    public static function diagnosticoConfiguracaoSeguro(): array
    {
        $configuracao = self::carregarConfiguracaoCentral();
        $ambienteRaw = $configuracao['ambiente'] ?? null;
        $ambiente = is_string($ambienteRaw) && in_array($ambienteRaw, ['teste', 'producao'], true)
            ? $ambienteRaw
            : null;

        $publicKeyConfigurada = false;
        $accessTokenConfigurado = false;
        $webhookSecretConfigurado = false;

        if ($ambiente !== null && is_array($configuracao[$ambiente] ?? null)) {
            $dados = $configuracao[$ambiente];
            $publicKeyConfigurada = self::chavePublicaConfigurada(
                $dados['public_key'] ?? null
            );
            $accessTokenConfigurado = self::credencialConfigurada(
                $dados['access_token'] ?? null,
                1,
                512
            );
            $webhookSecretConfigurado = self::credencialConfigurada(
                $dados['webhook_secret'] ?? null,
                16,
                512
            );
        }

        $urlPublicaRaw = $configuracao['url_publica'] ?? null;
        $urlPublica = self::urlPublicaValida($urlPublicaRaw) ? (string)$urlPublicaRaw : null;
        $webhookUrl = $urlPublica === null
            ? null
            : rtrim($urlPublica, '/') . '/api/api_central.php?path=webhooks/mercado-pago';
        $pronto = $ambiente !== null
            && $publicKeyConfigurada
            && $accessTokenConfigurado
            && $webhookSecretConfigurado
            && $urlPublica !== null;

        return [
            'ambiente' => $ambiente,
            'ambiente_configurado' => $ambiente !== null,
            'public_key_configurada' => $publicKeyConfigurada,
            'access_token_configurado' => $accessTokenConfigurado,
            'webhook_secret_configurado' => $webhookSecretConfigurado,
            'url_publica_configurada' => $urlPublica !== null,
            'webhook_url' => $webhookUrl,
            'pronto' => $pronto,
            'status_geral' => $pronto ? 'pronto' : 'configuracao_incompleta',
        ];
    }

    /**
     * Chave mestra do motor Subscriptions (/preapproval), lida da configuração privada
     * (`subscriptions.habilitado`). Somente o booleano `true` habilita; ausência, valor
     * diferente ou falha de leitura mantêm o motor desligado.
     */
    public static function subscriptionsHabilitado(): bool
    {
        try {
            $configuracao = self::carregarConfiguracaoCentral();
        } catch (Throwable $e) {
            return false;
        }

        $bloco = $configuracao['subscriptions'] ?? null;

        return is_array($bloco) && ($bloco['habilitado'] ?? false) === true;
    }

    /** URL pública HTTPS validada (sem credenciais), usada como back_url da Preapproval; null se ausente. */
    public static function urlPublicaConfigurada(): ?string
    {
        try {
            $valor = self::carregarConfiguracaoCentral()['url_publica'] ?? null;
        } catch (Throwable $e) {
            return null;
        }

        return self::urlPublicaValida($valor) ? rtrim((string) $valor, '/') : null;
    }

    /** Retorna o segredo apenas ao processamento interno do webhook. */
    public static function segredoWebhook(): string
    {
        $ativa = self::configuracaoAtiva();
        $secret = $ativa['dados']['webhook_secret'] ?? null;
        if (!self::credencialConfigurada($secret, 16, 512)) {
            throw new RuntimeException('Configuração do webhook indisponível.');
        }

        return $secret;
    }

    /** @return array<string,mixed> */
    private static function carregarConfiguracaoCentral(): array
    {
        if (self::$configuracaoCentral !== null) {
            return self::$configuracaoCentral;
        }

        $arquivo = __DIR__ . '/../_config/mercado_pago.php';
        if (!is_file($arquivo)) {
            throw new RuntimeException('Configuração de pagamento indisponível.');
        }
        $configuracao = require $arquivo;
        if (!is_array($configuracao)) {
            throw new RuntimeException('Configuração de pagamento indisponível.');
        }

        self::$configuracaoCentral = $configuracao;

        return $configuracao;
    }

    /** @return array{ambiente:string,dados:array<string,mixed>} */
    private static function configuracaoAtiva(): array
    {
        $configuracao = self::carregarConfiguracaoCentral();
        $ambiente = $configuracao['ambiente'] ?? null;
        if (!is_string($ambiente) || !in_array($ambiente, ['teste', 'producao'], true)
            || !is_array($configuracao[$ambiente] ?? null)) {
            throw new RuntimeException('Configuração de pagamento indisponível.');
        }

        return ['ambiente' => $ambiente, 'dados' => $configuracao[$ambiente]];
    }

    private static function chavePublicaConfigurada(mixed $valor): bool
    {
        return is_string($valor)
            && strlen($valor) >= 20
            && strlen($valor) <= 200
            && trim($valor) === $valor
            && preg_match('/[\x00-\x20\x7F]/', $valor) !== 1;
    }

    private static function credencialConfigurada(mixed $valor, int $minimo, int $maximo): bool
    {
        return is_string($valor)
            && strlen($valor) >= $minimo
            && strlen($valor) <= $maximo
            && trim($valor) === $valor
            && preg_match('/[\x00-\x1F\x7F]/', $valor) !== 1;
    }

    private static function urlPublicaValida(mixed $valor): bool
    {
        if (!is_string($valor) || $valor === '' || trim($valor) !== $valor
            || filter_var($valor, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $partes = parse_url($valor);

        return is_array($partes)
            && ($partes['scheme'] ?? '') === 'https'
            && is_string($partes['host'] ?? null)
            && $partes['host'] !== ''
            && !isset($partes['user'])
            && !isset($partes['pass'])
            && !isset($partes['query'])
            && !isset($partes['fragment']);
    }

    public function ambiente(): string
    {
        return $this->ambiente;
    }

    /** Impede que var_dump/print_r/logs de depuração exponham o Access Token. */
    public function __debugInfo(): array
    {
        return ['ambiente' => $this->ambiente];
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

            return pagamentoGatewayResultado(false, $codigo, $mensagem, $httpStatus, $dados);
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

    /*
     * ---------------------------------------------------------------------
     * Subscriptions (/preapproval): motor isolado e desativado por padrão.
     *
     * - Não compartilha rotas, validações nem a chave idempotente das Orders;
     *   `requisitar()` continua rejeitando estas operações (operacao_invalida).
     * - Todo método abaixo exige `subscriptions.habilitado === true` na
     *   configuração privada; desligado, nenhuma requisição de rede é feita.
     * - /preapproval NÃO recebe X-Idempotency-Key: o contrato de idempotência
     *   deste recurso não é presumido igual ao das Orders.
     * - `resultado_incerto` (POST/PUT sem confirmação do provedor) nunca deve ser
     *   repetido às cegas: a camada de negócio precisa reconciliar o estado
     *   (consultar_preapproval / external_reference) antes de nova tentativa.
     * ---------------------------------------------------------------------
     */

    private const SUBSCRIPTIONS_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,149}$/D';
    private const SUBSCRIPTIONS_ID_PAGAMENTO = '/^[0-9]{1,20}$/D';
    private const SUBSCRIPTIONS_CORPO_MAX_BYTES = 65536;
    private const SUBSCRIPTIONS_RESPOSTA_MAX_BYTES = 1048576;
    /** Erros cURL em que a requisição certamente não chegou ao provedor (DNS, conexão, TLS). */
    private const SUBSCRIPTIONS_ERROS_ANTES_DO_ENVIO = [3, 5, 6, 7, 35, 51, 58, 59, 60, 64, 66, 77, 83, 90, 91];

    /** POST /preapproval. */
    public function criarPreapproval(array $corpo): array
    {
        return $this->executarSubscriptions('criar_preapproval', [], $corpo);
    }

    /** GET /preapproval/{id}. */
    public function consultarPreapproval(string $preapprovalId): array
    {
        return $this->executarSubscriptions('consultar_preapproval', ['id' => $preapprovalId], null);
    }

    /**
     * GET /preapproval/search. Pesquisa documentada, mas sem garantia de filtro exato:
     * a camada de negócio deve conferir `external_reference` de cada resultado.
     *
     * @param array{external_reference?:string,status?:string,limit?:int,offset?:int} $filtros
     */
    public function buscarPreapprovals(array $filtros): array
    {
        return $this->executarSubscriptions('buscar_preapprovals', $filtros, null);
    }

    /**
     * GET /authorized_payments/search (faturas de uma Preapproval). Somente leitura; a camada de
     * negócio confere `preapproval_id` e `payment.id` de cada resultado. O endpoint não aceita
     * `limit`/`offset` (HTTP 400 "Invalid value for limit"): a consulta devolve uma única página.
     *
     * @param array{preapproval_id?:string,payment_id?:string} $filtros
     */
    public function buscarFaturasAutorizadas(array $filtros): array
    {
        return $this->executarSubscriptions('buscar_faturas_autorizadas', $filtros, null);
    }

    /** PUT /preapproval/{id}. */
    public function atualizarPreapproval(string $preapprovalId, array $corpo): array
    {
        return $this->executarSubscriptions('atualizar_preapproval', ['id' => $preapprovalId], $corpo);
    }

    /** GET /authorized_payments/{id}. */
    public function consultarFaturaAutorizada(string|int $faturaId): array
    {
        return $this->executarSubscriptions('consultar_fatura_autorizada', ['id' => (string) $faturaId], null);
    }

    /** GET /v1/payments/{id}. Não havia consulta de Payment no gateway; esta é somente leitura. */
    public function consultarPagamento(string|int $pagamentoId): array
    {
        return $this->executarSubscriptions('consultar_pagamento', ['id' => (string) $pagamentoId], null);
    }

    private function executarSubscriptions(string $operacao, array $identificadores, ?array $corpo): array
    {
        $bloqueio = $this->bloqueioSubscriptions();
        if ($bloqueio !== null) {
            return $bloqueio;
        }

        $rota = self::rotaSubscriptions($operacao, $identificadores);
        if ($rota === null) {
            return pagamentoGatewayResultado(false, 'operacao_invalida', 'Operação de pagamento inválida.');
        }

        [$metodo, $caminho, $idEsperado] = $rota;
        $mutante = $metodo !== 'GET';
        if ($mutante
            ? ($corpo === null || !self::corpoSubscriptionsValido($operacao, $corpo))
            : $corpo !== null) {
            return pagamentoGatewayResultado(false, 'requisicao_invalida', 'Dados de pagamento inválidos.');
        }

        try {
            $json = $corpo === null ? null : json_encode($corpo, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return pagamentoGatewayResultado(false, 'requisicao_invalida', 'Dados de pagamento inválidos.');
        }
        if ($json !== null && strlen($json) > self::SUBSCRIPTIONS_CORPO_MAX_BYTES) {
            return pagamentoGatewayResultado(false, 'requisicao_invalida', 'Dados de pagamento inválidos.');
        }

        if (!function_exists('curl_init')) {
            return pagamentoGatewayResultado(false, 'transporte_indisponivel', 'Pagamento temporariamente indisponível.');
        }

        $envio = $this->enviarSubscriptions($metodo, $caminho, $json);
        if ($envio === null) {
            return pagamentoGatewayResultado(false, 'transporte_indisponivel', 'Pagamento temporariamente indisponível.');
        }

        return self::interpretarRespostaSubscriptions($operacao, $mutante, $envio[0], $envio[1], $envio[2], $idEsperado);
    }

    /** Chave mestra desligada ou ambiente divergente da configuração ativa: nada sai para a rede. */
    private function bloqueioSubscriptions(): ?array
    {
        if (!self::subscriptionsHabilitado()) {
            return pagamentoGatewayResultado(false, 'subscriptions_desabilitado', 'Assinatura recorrente indisponível.');
        }

        try {
            $ativa = self::configuracaoAtiva();
        } catch (Throwable $e) {
            return pagamentoGatewayResultado(false, 'configuracao_indisponivel', 'Configuração de pagamento indisponível.');
        }
        if ($ativa['ambiente'] !== $this->ambiente) {
            return pagamentoGatewayResultado(false, 'ambiente_invalido', 'Configuração de pagamento indisponível.');
        }

        return null;
    }

    /** @return array{0:string,1:string,2:?string}|null método, caminho e id esperado na resposta */
    private static function rotaSubscriptions(string $operacao, array $identificadores): ?array
    {
        if ($operacao === 'buscar_preapprovals') {
            return self::rotaBuscaPreapprovals($identificadores);
        }
        if ($operacao === 'buscar_faturas_autorizadas') {
            return self::rotaBuscaFaturasAutorizadas($identificadores);
        }

        $rotas = [
            'criar_preapproval' => ['POST', '/preapproval', [], null],
            'consultar_preapproval' => ['GET', '/preapproval/{id}', ['id'], self::SUBSCRIPTIONS_ID],
            'atualizar_preapproval' => ['PUT', '/preapproval/{id}', ['id'], self::SUBSCRIPTIONS_ID],
            'consultar_fatura_autorizada' => ['GET', '/authorized_payments/{id}', ['id'], self::SUBSCRIPTIONS_ID],
            'consultar_pagamento' => ['GET', '/v1/payments/{id}', ['id'], self::SUBSCRIPTIONS_ID_PAGAMENTO],
        ];
        if (!isset($rotas[$operacao])) {
            return null;
        }

        [$metodo, $caminho, $chaves, $padraoId] = $rotas[$operacao];
        if (array_keys($identificadores) !== $chaves) {
            return null;
        }

        $idEsperado = null;
        if ($chaves !== []) {
            $id = $identificadores['id'];
            if (!is_string($id) || !preg_match($padraoId, $id)) {
                return null;
            }
            $caminho = str_replace('{id}', rawurlencode($id), $caminho);
            $idEsperado = $id;
        }

        return [$metodo, $caminho, $idEsperado];
    }

    /**
     * @return array{0:string,1:string,2:?string}|null exige preapproval_id ou payment_id; filtros em ordem fixa.
     * `limit`/`offset` são recusados aqui: o provedor os rejeita, e ignorá-los faria uma "página N" devolver a primeira.
     * `status` também: não é filtro documentado do endpoint; o status de cada fatura é conferido pela camada de negócio.
     */
    private static function rotaBuscaFaturasAutorizadas(array $filtros): ?array
    {
        if ($filtros === [] || array_is_list($filtros)
            || array_diff(array_keys($filtros), ['preapproval_id', 'payment_id']) !== []) {
            return null;
        }

        $consulta = [];
        if (array_key_exists('preapproval_id', $filtros)) {
            $id = $filtros['preapproval_id'];
            if (!is_string($id) || !preg_match(self::SUBSCRIPTIONS_ID, $id)) return null;
            $consulta['preapproval_id'] = $id;
        }
        if (array_key_exists('payment_id', $filtros)) {
            $id = $filtros['payment_id'];
            if (!is_string($id) || !preg_match(self::SUBSCRIPTIONS_ID_PAGAMENTO, $id)) return null;
            $consulta['payment_id'] = $id;
        }
        if ($consulta === []) {
            return null; // Nunca varre a conta inteira sem filtro.
        }

        return ['GET', '/authorized_payments/search?' . http_build_query($consulta, '', '&', PHP_QUERY_RFC3986), null];
    }

    /** @return array{0:string,1:string,2:?string}|null somente filtros conhecidos e válidos, em ordem fixa */
    private static function rotaBuscaPreapprovals(array $filtros): ?array
    {
        if ($filtros === [] || array_is_list($filtros)
            || array_diff(array_keys($filtros), ['external_reference', 'status', 'limit', 'offset']) !== []) {
            return null;
        }

        $consulta = [];
        if (array_key_exists('external_reference', $filtros)) {
            $ref = $filtros['external_reference'];
            if (!is_string($ref) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $ref)) return null;
            $consulta['external_reference'] = $ref;
        }
        if (array_key_exists('status', $filtros)) {
            $status = $filtros['status'];
            if (!is_string($status) || !in_array($status, ['pending', 'authorized', 'paused', 'canceled', 'cancelled'], true)) return null;
            $consulta['status'] = $status;
        }
        if ($consulta === []) {
            return null; // Nunca varre a conta inteira sem filtro.
        }
        $limite = $filtros['limit'] ?? 50;
        $deslocamento = $filtros['offset'] ?? 0;
        if (!is_int($limite) || $limite < 1 || $limite > 50 || !is_int($deslocamento)
            || $deslocamento < 0 || $deslocamento > 10000) {
            return null;
        }
        $consulta['limit'] = $limite;
        $consulta['offset'] = $deslocamento;

        return ['GET', '/preapproval/search?' . http_build_query($consulta, '', '&', PHP_QUERY_RFC3986), null];
    }

    /** Somente campos documentados do /preapproval são validados; os demais seguem ao provedor. */
    private static function corpoSubscriptionsValido(string $operacao, array $corpo): bool
    {
        if ($corpo === [] || array_is_list($corpo)) {
            return false;
        }

        // Política interna: a conciliação multiempresa depende de external_reference.
        if ($operacao === 'criar_preapproval' && !array_key_exists('external_reference', $corpo)) {
            return false;
        }

        if (array_key_exists('external_reference', $corpo)) {
            $ref = $corpo['external_reference'];
            if (!is_string($ref) || $ref === '' || strlen($ref) > 256 || trim($ref) !== $ref
                || preg_match('/[\x00-\x1F\x7F]/', $ref)) {
                return false;
            }
        }

        // A criação sempre exige o e-mail do pagador (validado antes pelo serviço); nas demais operações ele é opcional.
        if ($operacao === 'criar_preapproval' || array_key_exists('payer_email', $corpo)) {
            $email = $corpo['payer_email'] ?? null;
            if (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
        }

        if (array_key_exists('status', $corpo)) {
            $permitidos = $operacao === 'criar_preapproval'
                ? ['pending', 'authorized']
                : ['authorized', 'paused', 'canceled'];
            if (!is_string($corpo['status']) || !in_array($corpo['status'], $permitidos, true)) {
                return false;
            }
        }

        if (array_key_exists('auto_recurring', $corpo)) {
            $recorrencia = $corpo['auto_recurring'];
            if (!is_array($recorrencia) || $recorrencia === [] || array_is_list($recorrencia)) {
                return false;
            }
            if (array_key_exists('transaction_amount', $recorrencia)) {
                $valor = $recorrencia['transaction_amount'];
                if (!(is_int($valor) || is_float($valor)) || !is_finite((float) $valor) || $valor <= 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private function cabecalhosSubscriptions(bool $comCorpo): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];
        if ($comCorpo) {
            $headers[] = 'Content-Type: application/json';
        }

        return $headers;
    }

    /** @return array{0:string|bool,1:int,2:int}|null resposta, status HTTP e errno do cURL; null se o cliente não pôde ser montado */
    private function enviarSubscriptions(string $metodo, string $caminho, ?string $json): ?array
    {
        $curl = curl_init(self::BASE_URL . $caminho);
        if ($curl === false) {
            return null;
        }

        $configurado = curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $this->cabecalhosSubscriptions($json !== null),
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

            return null;
        }

        $resposta = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);

        return [$resposta, $httpStatus, $errno];
    }

    /**
     * Classifica a resposta sem depender de rede. Em POST/PUT, qualquer desfecho que
     * não prove aceitação nem rejeição vira `resultado_incerto`.
     */
    private static function interpretarRespostaSubscriptions(
        string $operacao,
        bool $mutante,
        string|bool $resposta,
        int $httpStatus,
        int $errno,
        ?string $idEsperado
    ): array {
        $status = $httpStatus > 0 ? $httpStatus : null;
        $incerto = pagamentoGatewayResultado(false, 'resultado_incerto', 'Não foi possível confirmar a operação de pagamento.', $status);
        $invalida = $mutante
            ? $incerto
            : pagamentoGatewayResultado(false, 'resposta_invalida', 'Resposta de pagamento indisponível.', $status);

        if (!is_string($resposta)) {
            if ($mutante && !in_array($errno, self::SUBSCRIPTIONS_ERROS_ANTES_DO_ENVIO, true)) {
                return $incerto;
            }

            return pagamentoGatewayResultado(false, 'falha_transporte', 'Pagamento temporariamente indisponível.');
        }

        if (strlen($resposta) > self::SUBSCRIPTIONS_RESPOSTA_MAX_BYTES) {
            return $invalida;
        }

        try {
            $dados = json_decode($resposta, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $dados = null;
        }
        $objeto = is_array($dados) && !array_is_list($dados);

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $valida = $objeto && (in_array($operacao, ['buscar_preapprovals', 'buscar_faturas_autorizadas'], true)
                ? self::respostaBuscaPreapprovalsValida($dados)
                : self::respostaSubscriptionsValida($dados, $idEsperado));
            if (!$valida) {
                return $invalida;
            }

            return pagamentoGatewayResultado(true, 'ok', 'Operação concluída.', $httpStatus, $dados);
        }

        if ($httpStatus === 429) {
            return pagamentoGatewayResultado(false, 'provedor_indisponivel', 'Pagamento temporariamente indisponível.', $httpStatus);
        }
        if ($httpStatus >= 500 || $httpStatus === 408 || $httpStatus < 200) {
            return $mutante
                ? $incerto
                : pagamentoGatewayResultado(false, 'provedor_indisponivel', 'Pagamento temporariamente indisponível.', $status);
        }
        if ($httpStatus >= 300 && $httpStatus < 400) {
            return $invalida;
        }

        return pagamentoGatewayResultado(
            false,
            'provedor_rejeitou',
            'Não foi possível processar o pagamento.',
            $httpStatus,
            $objeto ? self::erroSubscriptionsSeguro($dados) : []
        );
    }

    /** Resultado da pesquisa: lista de objetos com id válido; `paging` é opcional e só aceito se bem formado. */
    private static function respostaBuscaPreapprovalsValida(array $dados): bool
    {
        $resultados = $dados['results'] ?? null;
        if (!is_array($resultados) || !array_is_list($resultados) || count($resultados) > 100) {
            return false;
        }
        foreach ($resultados as $item) {
            if (!is_array($item) || array_is_list($item) || !self::respostaSubscriptionsValida($item, null)) {
                return false;
            }
        }
        if (array_key_exists('paging', $dados)) {
            $paginacao = $dados['paging'];
            if (!is_array($paginacao) || array_is_list($paginacao)) return false;
            foreach (['total', 'limit', 'offset'] as $campo) {
                if (array_key_exists($campo, $paginacao) && !is_int($paginacao[$campo])) return false;
            }
        }

        return true;
    }

    private static function respostaSubscriptionsValida(array $dados, ?string $idEsperado): bool
    {
        $id = $dados['id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return false;
        }
        $id = (string) $id;
        if (!preg_match(self::SUBSCRIPTIONS_ID, $id) || ($idEsperado !== null && $id !== $idEsperado)) {
            return false;
        }

        return !array_key_exists('status', $dados) || is_string($dados['status']);
    }

    /** Mantém só o diagnóstico do erro; nunca devolve o corpo bruto do provedor. */
    private static function erroSubscriptionsSeguro(array $dados): array
    {
        $seguro = [];
        foreach (['error', 'message'] as $campo) {
            if (isset($dados[$campo]) && is_string($dados[$campo])) {
                $seguro[$campo] = self::textoCurtoSubscriptions($dados[$campo]);
            }
        }
        if (isset($dados['status']) && is_int($dados['status'])) {
            $seguro['status'] = $dados['status'];
        }
        if (isset($dados['cause']) && is_array($dados['cause']) && array_is_list($dados['cause'])) {
            foreach (array_slice($dados['cause'], 0, 5) as $causa) {
                if (!is_array($causa)) continue;
                $item = [];
                if (isset($causa['code']) && (is_int($causa['code']) || is_string($causa['code']))) {
                    $item['code'] = self::textoCurtoSubscriptions((string) $causa['code']);
                }
                if (isset($causa['description']) && is_string($causa['description'])) {
                    $item['description'] = self::textoCurtoSubscriptions($causa['description']);
                }
                if ($item !== []) $seguro['cause'][] = $item;
            }
        }

        return $seguro;
    }

    private static function textoCurtoSubscriptions(string $texto): string
    {
        $limpo = preg_replace('/[\x00-\x1F\x7F]/', ' ', $texto);
        if (!is_string($limpo) || preg_match('/^.{0,200}/su', $limpo, $parte) !== 1) {
            return '';
        }

        return $parte[0];
    }
}
