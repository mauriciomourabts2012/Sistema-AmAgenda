<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SERVIÇO DE SMS - INFOBIP (mensagem_envio)
|--------------------------------------------------------------------------
|
| - UUID local usado como messageId e chave de idempotência determinística
|   por desafio OTP (uma mensagem por desafio);
| - o registro "enviando" é confirmado ANTES da chamada HTTP; nenhuma
|   transação fica aberta durante a rede;
| - timeout, 5xx ou resposta inesperada => resultado_incerto, sem reenvio
|   automático (um novo código exige um novo desafio, iniciado pelo usuário);
| - relatórios de entrega (webhook) são idempotentes e respeitam a ordem:
|   estados finais nunca são rebaixados;
| - logs e erros gravados nunca contêm telefone completo, código OTP,
|   API key nem o texto da mensagem.
|
*/

require_once __DIR__ . '/../_gateways/infobip.php';

const SMS_PROVEDOR = 'infobip';
const SMS_STATUS_FINAIS = ['entregue', 'nao_entregue', 'expirada', 'rejeitada', 'erro', 'cancelada'];

function smsUuidV4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);

    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}

/** Chave determinística: um desafio OTP gera no máximo uma mensagem. */
function smsChaveIdempotencia(int $idOtpDesafio): string
{
    $h = hash('sha256', 'otp-sms|' . $idOtpDesafio);

    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}

function smsTelefoneFinal4(string $telefoneE164): string
{
    return substr(preg_replace('/\D+/', '', $telefoneE164) ?? '', -4);
}

/** Log sanitizado: somente evento, ids e códigos fixos. */
function smsLog(string $evento, array $contexto = []): void
{
    $seguro = [];
    foreach ($contexto as $chave => $valor) {
        if (is_int($valor) || (is_string($valor) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $valor) === 1)) {
            $seguro[$chave] = $valor;
        }
    }
    error_log('[sms] ' . $evento . ' ' . json_encode($seguro, JSON_UNESCAPED_UNICODE));
}

/**
 * Ponto de substituição do acesso ao banco em testes isolados (sem MySQL). Não usado em produção.
 */
interface SmsBancoSubstituto
{
    /** @return list<array<string, mixed>> */
    public function consultarSubstituto(string $sql, array $valores): array;

    public function executarSubstituto(string $sql, array $valores): int;
}

/**
 * @return list<array<string, mixed>>
 */
function smsConsultar(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): array
{
    if ($conexao instanceof SmsBancoSubstituto) {
        return $conexao->consultarSubstituto($sql, $valores);
    }
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta de SMS.', (int)$conexao->errno);
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta de SMS.', $errno);
    }
    $resultado = $stmt->get_result();
    $linhas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $linhas;
}

/** Devolve as linhas afetadas. */
function smsExecutar(mysqli $conexao, string $sql, string $tipos = '', mixed ...$valores): int
{
    if ($conexao instanceof SmsBancoSubstituto) {
        return $conexao->executarSubstituto($sql, $valores);
    }
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar escrita de SMS.', (int)$conexao->errno);
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $stmt->close();
        throw new RuntimeException('Falha ao executar escrita de SMS.', $errno);
    }
    $afetadas = $stmt->affected_rows;
    $stmt->close();

    return (int)$afetadas;
}

/**
 * Envia o OTP de um desafio 'pendente_envio'. Nunca reenvia por conta própria.
 *
 * @param array{ok: bool, habilitado: bool, erros: list<string>, config: array<string, mixed>} $carga resultado de infobipConfigCarregar()
 * @param (callable(array): array)|null $transporte
 * @return array{sucesso: bool, estado: string, codigo: string}
 */
function smsEnviarOtp(mysqli $conexao, array $carga, int $idOtpDesafio, string $telefoneE164, string $texto, ?callable $transporte = null): array
{
    $falha = static fn (string $codigo): array => ['sucesso' => false, 'estado' => 'falha', 'codigo' => $codigo];

    if (($carga['habilitado'] ?? false) !== true) {
        smsExecutar($conexao, "UPDATE otp_desafio SET status = 'erro_envio' WHERE id_otp_desafio = ? AND status = 'pendente_envio'", 'i', $idOtpDesafio);

        return $falha('SMS_DESABILITADO');
    }
    $config = $carga['config'];
    $final4 = smsTelefoneFinal4($telefoneE164);
    if (strlen($final4) !== 4) {
        smsExecutar($conexao, "UPDATE otp_desafio SET status = 'erro_envio' WHERE id_otp_desafio = ? AND status = 'pendente_envio'", 'i', $idOtpDesafio);

        return $falha('SMS_TELEFONE_INVALIDO');
    }

    $messageId = smsUuidV4();
    $chave = smsChaveIdempotencia($idOtpDesafio);
    $ambiente = (string)$config['ambiente'];

    $conexao->begin_transaction();
    try {
        $otp = smsConsultar($conexao, 'SELECT status FROM otp_desafio WHERE id_otp_desafio = ? FOR UPDATE', 'i', $idOtpDesafio);
        if ($otp === [] || (string)$otp[0]['status'] !== 'pendente_envio') {
            $conexao->rollback();

            return $falha('SMS_DESAFIO_INDISPONIVEL');
        }
        $existente = smsConsultar($conexao, 'SELECT status FROM mensagem_envio WHERE id_otp_desafio = ? OR chave_idempotencia = ? LIMIT 1', 'is', $idOtpDesafio, $chave);
        if ($existente !== []) {
            $conexao->rollback();

            return ['sucesso' => false, 'estado' => 'duplicado', 'codigo' => 'SMS_JA_REGISTRADO'];
        }
        smsExecutar(
            $conexao,
            "INSERT INTO mensagem_envio (id_otp_desafio, canal, provedor, ambiente, telefone_final4, chave_idempotencia, message_id, status, solicitado_em)
             VALUES (?, 'sms', 'infobip', ?, ?, ?, ?, 'enviando', NOW(6))",
            'issss',
            $idOtpDesafio,
            $ambiente,
            $final4,
            $chave,
            $messageId
        );
        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        smsLog('registro_envio_falhou', ['otp' => $idOtpDesafio]);

        return $falha('SMS_REGISTRO_INDISPONIVEL');
    }

    $resposta = infobipEnviarSms($config, $messageId, $telefoneE164, $texto, $transporte);
    $resultado = (string)$resposta['resultado'];

    $statusMsg = match ($resultado) {
        'aceita' => 'aceita',
        'incerto' => 'resultado_incerto',
        'falha_conexao' => 'erro',
        default => 'rejeitada',
    };
    $final = in_array($statusMsg, SMS_STATUS_FINAIS, true);
    $resumo = match ($resultado) {
        'aceita' => null,
        'incerto' => 'Resultado do envio incerto; aguardando relatório do provedor.',
        'limitada' => 'Envio recusado temporariamente pelo provedor (limite).',
        'falha_conexao' => 'Falha de conexão com o provedor; nada foi enviado.',
        default => 'Envio recusado pelo provedor.',
    };
    $erroCodigo = $resposta['erro_codigo'] !== null ? (string)$resposta['erro_codigo'] : null;
    $httpStatus = $resposta['http_status'] !== null ? (int)$resposta['http_status'] : null;
    $bulkId = $resposta['bulk_id'] !== null ? (string)$resposta['bulk_id'] : null;
    $grupo = $resposta['status_grupo'] !== null ? (string)$resposta['status_grupo'] : null;
    $statusId = $resposta['status_id'] !== null ? (int)$resposta['status_id'] : null;
    $statusNome = $resposta['status_nome'] !== null ? (string)$resposta['status_nome'] : null;

    try {
        $conexao->begin_transaction();
        // Guarda 'enviando': um relatório de entrega que chegou antes nunca é sobrescrito.
        $atualizadas = smsExecutar(
            $conexao,
            "UPDATE mensagem_envio
                SET status = ?, http_status = ?, erro_codigo = ?, erro_resumo = ?, bulk_id = ?,
                    status_externo_grupo = ?, status_externo_id = ?, status_externo_nome = ?,
                    aceito_em = IF(? = 'aceita', NOW(6), aceito_em),
                    concluido_em = IF(?, NOW(6), concluido_em)
              WHERE id_otp_desafio = ? AND status = 'enviando'",
            'sisssssissii',
            $statusMsg,
            $httpStatus,
            $erroCodigo,
            $resumo,
            $bulkId,
            $grupo,
            $statusId,
            $statusNome,
            $statusMsg,
            $final ? 1 : 0,
            $idOtpDesafio
        );

        if (in_array($resultado, ['aceita', 'incerto'], true)) {
            smsExecutar(
                $conexao,
                "UPDATE otp_desafio SET status = 'ativo', enviado_em = IF(?, NOW(6), NULL)
                  WHERE id_otp_desafio = ? AND status = 'pendente_envio'",
                'ii',
                $resultado === 'aceita' ? 1 : 0,
                $idOtpDesafio
            );
        } elseif ($atualizadas === 1) {
            smsExecutar($conexao, "UPDATE otp_desafio SET status = 'erro_envio' WHERE id_otp_desafio = ? AND status = 'pendente_envio'", 'i', $idOtpDesafio);
        }
        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        smsLog('conclusao_envio_falhou', ['otp' => $idOtpDesafio]);

        return ['sucesso' => false, 'estado' => 'incerto', 'codigo' => 'SMS_CONCLUSAO_INDISPONIVEL'];
    }

    smsLog('envio_' . $resultado, ['otp' => $idOtpDesafio, 'http' => $httpStatus ?? 0]);

    return [
        'sucesso' => in_array($resultado, ['aceita', 'incerto'], true),
        'estado' => $resultado,
        'codigo' => $erroCodigo ?? 'SMS_ACEITO',
    ];
}

/** Estado local correspondente ao grupo do relatório de entrega (null = ignorar). */
function smsStatusDeRelatorio(string $grupo): ?string
{
    return match (strtoupper($grupo)) {
        'DELIVERED' => 'entregue',
        'UNDELIVERABLE' => 'nao_entregue',
        'REJECTED' => 'rejeitada',
        'EXPIRED' => 'expirada',
        'CANCELED', 'CANCELLED' => 'cancelada',
        'PENDING', 'ACCEPTED', 'SENT' => 'aceita',
        default => null,
    };
}

function smsDataRelatorio(mixed $valor): ?string
{
    if (!is_string($valor) || $valor === '') {
        return null;
    }
    try {
        $data = new DateTimeImmutable($valor);
    } catch (Throwable) {
        return null;
    }

    return $data->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s.u');
}

/**
 * Aplica um item de relatório de entrega (results[n]). Idempotente e sem rebaixar estado final.
 *
 * @param array<string, mixed> $resultado
 * @return array{aplicado: bool, motivo: string}
 */
function smsAplicarRelatorio(mysqli $conexao, string $ambiente, array $resultado): array
{
    $messageId = is_string($resultado['messageId'] ?? null) ? $resultado['messageId'] : '';
    $grupo = is_array($resultado['status'] ?? null) ? (string)($resultado['status']['groupName'] ?? '') : '';
    $novo = smsStatusDeRelatorio($grupo);
    if ($messageId === '' || strlen($messageId) > 150 || $novo === null) {
        return ['aplicado' => false, 'motivo' => 'invalido'];
    }

    $status = $resultado['status'];
    $erro = is_array($resultado['error'] ?? null) ? $resultado['error'] : [];
    $preco = is_array($resultado['price'] ?? null) ? $resultado['price'] : [];
    $eventoEm = smsDataRelatorio($resultado['doneAt'] ?? null) ?? smsDataRelatorio($resultado['sentAt'] ?? null);
    $hash = hash('sha256', json_encode([
        $messageId, $status['id'] ?? null, $grupo, $erro['id'] ?? null,
        $resultado['sentAt'] ?? null, $resultado['doneAt'] ?? null, $resultado['messageCount'] ?? null,
    ], JSON_UNESCAPED_UNICODE), true);

    $conexao->begin_transaction();
    try {
        $linhas = smsConsultar(
            $conexao,
            "SELECT id_mensagem_envio, status, ultimo_evento_hash, ultimo_evento_em
               FROM mensagem_envio WHERE provedor = 'infobip' AND ambiente = ? AND message_id = ? FOR UPDATE",
            'ss',
            $ambiente,
            $messageId
        );
        if ($linhas === []) {
            $conexao->rollback();

            return ['aplicado' => false, 'motivo' => 'desconhecida'];
        }
        $atual = $linhas[0];
        if ($atual['ultimo_evento_hash'] !== null && hash_equals((string)$atual['ultimo_evento_hash'], $hash)) {
            $conexao->rollback();

            return ['aplicado' => false, 'motivo' => 'duplicado'];
        }

        $atualFinal = in_array((string)$atual['status'], SMS_STATUS_FINAIS, true);
        $novoFinal = in_array($novo, SMS_STATUS_FINAIS, true);
        if ($atualFinal) {
            $conexao->rollback();

            return ['aplicado' => false, 'motivo' => 'estado_final_preservado'];
        }
        if (!$novoFinal && $eventoEm !== null && $atual['ultimo_evento_em'] !== null
            && strcmp($eventoEm, (string)$atual['ultimo_evento_em']) < 0) {
            $conexao->rollback();

            return ['aplicado' => false, 'motivo' => 'fora_de_ordem'];
        }
        if ($novo === 'aceita' && (string)$atual['status'] === 'aceita' && $eventoEm === null) {
            $conexao->rollback();

            return ['aplicado' => false, 'motivo' => 'sem_mudanca'];
        }

        $erroNome = isset($erro['name']) && is_string($erro['name']) ? substr($erro['name'], 0, 100) : null;
        $erroId = isset($erro['id']) ? (int)$erro['id'] : null;
        $permanente = isset($erro['permanent']) ? ((bool)$erro['permanent'] ? 1 : 0) : null;
        $resumo = $novo === 'entregue' || $novo === 'aceita' ? null : 'Relatório do provedor: ' . substr(strtolower($grupo), 0, 40) . '.';
        $segmentos = isset($resultado['messageCount']) && (int)$resultado['messageCount'] >= 1 ? (int)$resultado['messageCount'] : null;
        $valorPreco = isset($preco['pricePerMessage']) && is_numeric($preco['pricePerMessage']) && (float)$preco['pricePerMessage'] >= 0
            ? number_format((float)$preco['pricePerMessage'], 6, '.', '') : null;
        $moeda = isset($preco['currency']) && is_string($preco['currency']) && preg_match('/^[A-Z]{3}$/', $preco['currency']) === 1
            ? $preco['currency'] : null;

        smsExecutar(
            $conexao,
            "UPDATE mensagem_envio
                SET status = ?, status_externo_grupo = ?, status_externo_id = ?, status_externo_nome = ?,
                    erro_externo_id = ?, erro_externo_nome = ?, erro_permanente = ?, erro_resumo = ?,
                    numero_segmentos = ?, preco = ?, moeda = ?, ultimo_evento_hash = UNHEX(?), ultimo_evento_em = ?,
                    entregue_em = IF(? = 'entregue', COALESCE(?, NOW(6)), entregue_em),
                    concluido_em = IF(?, COALESCE(?, NOW(6)), concluido_em)
              WHERE id_mensagem_envio = ?",
            'ssisisisissssssisi',
            $novo,
            substr(strtoupper($grupo), 0, 50),
            isset($status['id']) ? (int)$status['id'] : null,
            isset($status['name']) && is_string($status['name']) ? substr($status['name'], 0, 100) : null,
            $erroId,
            $erroNome,
            $permanente,
            $resumo,
            $segmentos,
            $valorPreco,
            $moeda,
            bin2hex($hash),
            $eventoEm,
            $novo,
            $eventoEm,
            $novoFinal ? 1 : 0,
            $eventoEm,
            (int)$atual['id_mensagem_envio']
        );
        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        smsLog('relatorio_falhou', []);

        throw new RuntimeException('Falha ao aplicar relatório de SMS.');
    }

    return ['aplicado' => true, 'motivo' => 'aplicado'];
}
