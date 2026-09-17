<?php
declare(strict_types=1);

/**
 * Contrato interno de pagamentos, independente do provedor.
 * A camada de negócio deve persistir e reutilizar a mesma chave de idempotência
 * em uma tentativa repetida; nunca gerar outra chave para um retry incerto.
 */

function pagamentoGatewayAmbiente(string $ambiente): string
{
    if ($ambiente !== 'teste' && $ambiente !== 'producao') {
        throw new InvalidArgumentException('Ambiente de pagamento inválido.');
    }

    return $ambiente;
}

function pagamentoGatewayChaveIdempotencia(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-'
        . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}

function pagamentoGatewayReferenciaInterna(): string
{
    return 'amg_' . bin2hex(random_bytes(16));
}

/** Aceita a representação decimal de DECIMAL(10,2), sem conversão por float. */
function pagamentoGatewayValorCentavos(string $valor): int
{
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $valor, $partes)) {
        throw new InvalidArgumentException('Valor monetário inválido.');
    }

    $centavos = str_pad($partes[2] ?? '', 2, '0');

    return ((int) $partes[1] * 100) + (int) $centavos;
}

function pagamentoGatewayValorDecimal(int $centavos): string
{
    if ($centavos < 0 || $centavos > 9999999999) {
        throw new InvalidArgumentException('Valor monetário inválido.');
    }

    return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
}

/** Dados de sucesso são internos e precisam ser filtrados antes de qualquer resposta pública. */
function pagamentoGatewayResultado(
    bool $sucesso,
    string $codigo,
    string $mensagem,
    ?int $httpStatus = null,
    array $dados = []
): array {
    return [
        'sucesso' => $sucesso,
        'codigo' => $codigo,
        'mensagem' => $mensagem,
        'http_status' => $httpStatus,
        'dados' => $dados,
    ];
}
