<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('POST');
$entrada = documentosLegaisEntradaPost();
$contexto = documentosLegaisContextoAutenticado($conexao);
csrfValidarSessao();

$documentosEnviados = $entrada['documentos'] ?? null;
if (!is_array($documentosEnviados)) {
    out(['ok' => false, 'code' => 'LEGAL_DOCUMENTS_REQUIRED', 'user_msg' => 'Selecione todos os documentos obrigatórios.'], 422);
}
$codigosEnviados = [];
foreach ($documentosEnviados as $codigo) {
    if (!is_string($codigo) || preg_match('/^[a-z][a-z0-9_]{2,59}$/', $codigo) !== 1) {
        out(['ok' => false, 'code' => 'LEGAL_DOCUMENTS_INVALID', 'user_msg' => 'A seleção de documentos é inválida.'], 422);
    }
    $codigosEnviados[] = $codigo;
}
$codigosEnviados = array_values(array_unique($codigosEnviados));
sort($codigosEnviados);
$regras = documentosLegaisObrigatorios((string)$contexto['tipo_manifestante']);
$codigosObrigatorios = array_column($regras, 'codigo');
sort($codigosObrigatorios);
if ($codigosEnviados !== $codigosObrigatorios) {
    out(['ok' => false, 'code' => 'LEGAL_DOCUMENTS_INCOMPLETE', 'user_msg' => 'É necessário manifestar todos os documentos obrigatórios.'], 422);
}

$declaracaoMaioridade = $entrada['declaracao_maioridade'] ?? 0;
if ($contexto['tipo_manifestante'] === 'cliente'
    && !($declaracaoMaioridade === 1 || $declaracaoMaioridade === '1')) {
    out(['ok' => false, 'code' => 'ADULT_DECLARATION_REQUIRED', 'user_msg' => 'Confirme que possui 18 anos ou mais para continuar.'], 422);
}
$declaracaoMaioridade = $contexto['tipo_manifestante'] === 'cliente' ? 1 : 0;
$requestId = auditoriaRequestId();
$ip = auditoriaNormalizarIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgent = auditoriaLimitarTexto((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
$userAgent = $userAgent === '' ? null : $userAgent;
$novas = [];
$todas = [];
$documentoComFalha = null;

try {
    if (!$conexao->begin_transaction()) {
        throw new RuntimeException('Falha ao iniciar a transação.');
    }

    $resultado = documentosLegaisRegistrarManifestacoes(
        $conexao,
        $contexto,
        $regras,
        $declaracaoMaioridade,
        $ip,
        $userAgent,
        $requestId
    );
    $novas = $resultado['novas'];
    $todas = $resultado['todas'];

    if (!$conexao->commit()) {
        throw new RuntimeException('Falha ao confirmar a transação.');
    }
} catch (Throwable $e) {
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
    if ($e instanceof DocumentosLegaisIntegridadeException) {
        $documentoComFalha = $e->documento();
    }
    if ($documentoComFalha !== null) {
        documentosLegaisAuditarIntegridade($conexao, $contexto, $documentoComFalha);
        out(['ok' => false, 'code' => 'DOCUMENT_INTEGRITY_ERROR', 'user_msg' => 'Não foi possível validar os documentos legais.'], 503);
    }
    if ($e instanceof DomainException && $e->getMessage() === 'DOCUMENT_NOT_AVAILABLE') {
        out(['ok' => false, 'code' => 'DOCUMENT_NOT_AVAILABLE', 'user_msg' => 'Os documentos obrigatórios ainda não estão disponíveis.'], 409);
    }
    if ($e instanceof DomainException && $e->getMessage() === 'DOCUMENT_NOT_APPLICABLE') {
        out(['ok' => false, 'code' => 'DOCUMENT_NOT_APPLICABLE', 'user_msg' => 'Documento não disponível para este acesso.'], 403);
    }
    error_log('[documentos_legais] Falha ao registrar manifestação: ' . $e->getMessage());
    out(['ok' => false, 'code' => 'LEGAL_MANIFESTATION_ERROR', 'user_msg' => 'Não foi possível registrar sua manifestação.'], 500);
}

out([
    'ok' => true,
    'code' => $novas === [] ? 'LEGAL_MANIFESTATION_ALREADY_REGISTERED' : 'LEGAL_MANIFESTATION_REGISTERED',
    'data' => [
        'registrada' => $novas !== [],
        'idempotente' => $novas === [],
        'manifestacoes' => $todas,
        'request_id' => $requestId,
    ],
]);
